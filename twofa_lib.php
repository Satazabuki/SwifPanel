<?php
/**
 * Two-factor authentication (TOTP, RFC 6238) and account e-mail change helpers.
 * Works with Google Authenticator, Microsoft Authenticator, Authy, 1Password, Bitwarden and any other TOTP app.
 */

if (!defined('SWIFT_SITE_URL')) {
    define('SWIFT_SITE_URL', 'https://gp.marinkovic.biz');     // used in links inside e-mails
}
const TWOFA_ISSUER = 'SwiftPanel';
const TWOFA_CHALLENGE_TTL = 300;       // seconds the second login step stays open
const TWOFA_MAX_TRIES = 5;             // wrong codes per login attempt
const TWOFA_LOCK_AFTER = 10;           // wrong codes in a row (all logins together) before the account is locked for a while
const TWOFA_LOCK_SECONDS = 900;

function twofaEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $cols = [
        'totp_secret' => 'VARCHAR(64) NULL',
        'totp_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'totp_last_step' => 'BIGINT NOT NULL DEFAULT 0',
        'totp_fails' => 'INT NOT NULL DEFAULT 0',
        'totp_locked_until' => 'DATETIME NULL',
        'pending_email' => 'VARCHAR(100) NULL',
        'pending_email_hash' => 'VARCHAR(64) NULL',
        'pending_email_expires' => 'DATETIME NULL',
    ];
    foreach ($cols as $name => $def) {
        try {
            $pdo->query("SELECT $name FROM users LIMIT 0");
        } catch (Throwable $e) {
            $pdo->exec("ALTER TABLE users ADD COLUMN $name $def");
        }
    }
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_recovery_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code_hash TEXT NOT NULL, used_at TEXT NULL)");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_recovery_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            code_hash CHAR(64) NOT NULL,
            used_at DATETIME NULL,
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $done = true;
}

// ---------------------------------------------------------------------------------------------- base32 / TOTP

function twofaBase32Encode(string $bin): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $ch) {
        $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alpha[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function twofaBase32Decode(string $s): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = strtoupper(preg_replace('/[\s=-]+/', '', $s));
    $bits = '';
    foreach (str_split($s) as $ch) {
        $p = strpos($alpha, $ch);
        if ($p === false) {
            return '';
        }
        $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function twofaGenerateSecret(): string
{
    return twofaBase32Encode(random_bytes(20));      // 160 bits, the size RFC 4226 recommends
}

function twofaCode(string $secretB32, int $step, int $digits = 6): string
{
    $key = twofaBase32Decode($secretB32);
    $msg = pack('N2', $step >> 32 & 0xFFFFFFFF, $step & 0xFFFFFFFF);
    $h = hash_hmac('sha1', $msg, $key, true);
    $o = ord($h[19]) & 0x0F;
    $v = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($v % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** Returns the matching time step (so it can be remembered against replay) or null. Accepts one step either side for clock drift. */
function twofaMatchStep(string $secretB32, string $code, int $minStepExclusive = 0, ?int $now = null): ?int
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code) || twofaBase32Decode($secretB32) === '') {
        return null;
    }
    $cur = intdiv($now ?? time(), 30);
    for ($d = -1; $d <= 1; $d++) {
        $step = $cur + $d;
        if ($step > $minStepExclusive && hash_equals(twofaCode($secretB32, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function twofaUri(string $username, string $secretB32): string
{
    $label = rawurlencode(TWOFA_ISSUER) . ':' . rawurlencode($username);
    return 'otpauth://totp/' . $label . '?secret=' . $secretB32 . '&issuer=' . rawurlencode(TWOFA_ISSUER);
}

function twofaGroupSecret(string $secret): string
{
    return trim(chunk_split($secret, 4, ' '));
}

// ---------------------------------------------------------------------------------------------- recovery codes

function twofaNewRecoveryCodes(PDO $pdo, int $userId, int $count = 8): array
{
    twofaEnsureSchema($pdo);
    $pdo->prepare("DELETE FROM user_recovery_codes WHERE user_id = ?")->execute([$userId]);
    $alpha = 'abcdefghjkmnpqrstuvwxyz23456789';      // no look-alike characters
    $ins = $pdo->prepare("INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (?, ?)");
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $c = '';
        for ($k = 0; $k < 10; $k++) {
            $c .= $alpha[random_int(0, strlen($alpha) - 1)];
        }
        $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
        $ins->execute([$userId, hash('sha256', $c)]);
    }
    return $codes;
}

function twofaNormalizeRecovery(string $code): string
{
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $code));
}

function twofaUseRecoveryCode(PDO $pdo, int $userId, string $code): bool
{
    $n = twofaNormalizeRecovery($code);
    if (strlen($n) !== 10) {
        return false;
    }
    $st = $pdo->prepare("SELECT id FROM user_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL LIMIT 1");
    $st->execute([$userId, hash('sha256', $n)]);
    $id = $st->fetchColumn();
    if (!$id) {
        return false;
    }
    $u = $pdo->prepare("UPDATE user_recovery_codes SET used_at = ? WHERE id = ? AND used_at IS NULL");
    $u->execute([gmdate('Y-m-d H:i:s'), (int)$id]);
    return $u->rowCount() === 1;
}

function twofaRecoveryLeft(PDO $pdo, int $userId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL");
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

// ---------------------------------------------------------------------------------------------- lockout + verification for a stored account

function twofaIsEnabled(array $row): bool
{
    return !empty($row['totp_enabled']) && !empty($row['totp_secret']);
}

function twofaLockedSeconds(array $row): int
{
    if (empty($row['totp_locked_until'])) {
        return 0;
    }
    $t = strtotime($row['totp_locked_until'] . ' UTC');
    return $t && $t > time() ? $t - time() : 0;
}

/**
 * Checks an authenticator code or a recovery code for a user row (needs totp_* columns) and keeps the failure counter.
 * Returns 'ok', 'bad' or 'locked'.
 */
function twofaCheckLogin(PDO $pdo, array $row, string $input): string
{
    $uid = (int)$row['id'];
    if (twofaLockedSeconds($row) > 0) {
        return 'locked';
    }
    $ok = false;
    $step = twofaMatchStep((string)$row['totp_secret'], $input, (int)$row['totp_last_step']);
    if ($step !== null) {
        // the "AND totp_last_step < ?" makes the update atomic: one code, one use, even with two parallel requests
        $u = $pdo->prepare("UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?");
        $u->execute([$step, $uid, $step]);
        $ok = $u->rowCount() === 1;
    } elseif (strlen(twofaNormalizeRecovery($input)) === 10) {
        $ok = twofaUseRecoveryCode($pdo, $uid, $input);
    }
    if ($ok) {
        $pdo->prepare("UPDATE users SET totp_fails = 0, totp_locked_until = NULL WHERE id = ?")->execute([$uid]);
        return 'ok';
    }
    $fails = (int)$row['totp_fails'] + 1;
    if ($fails >= TWOFA_LOCK_AFTER) {
        $pdo->prepare("UPDATE users SET totp_fails = 0, totp_locked_until = ? WHERE id = ?")
            ->execute([gmdate('Y-m-d H:i:s', time() + TWOFA_LOCK_SECONDS), $uid]);
        return 'locked';
    }
    $pdo->prepare("UPDATE users SET totp_fails = ? WHERE id = ?")->execute([$fails, $uid]);
    return 'bad';
}

// ---------------------------------------------------------------------------------------------- login second step

/** Called after the password was verified for an account with 2FA on. The user is NOT logged in yet. */
function twofaStartChallenge(array $row, string $dest): void
{
    $_SESSION['tfa'] = [
        'uid' => (int)$row['id'], 'username' => (string)$row['username'], 'role' => (string)($row['role'] ?? 'client'),
        'exp' => time() + TWOFA_CHALLENGE_TTL, 'tries' => 0, 'dest' => $dest,
    ];
    unset($_SESSION['user_id'], $_SESSION['role']);
}

function twofaFinishLogin(): string
{
    $c = $_SESSION['tfa'];
    unset($_SESSION['tfa']);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$c['uid'];
    $_SESSION['username'] = $c['username'];
    $_SESSION['role'] = $c['role'];
    return (string)$c['dest'];
}

// ---------------------------------------------------------------------------------------------- e-mail change

function emailSmtpReady(PDO $pdo): bool
{
    try {
        $c = $pdo->query("SELECT enabled, host FROM smtp_settings WHERE id = 1")->fetch();
        return $c && !empty($c['enabled']) && !empty($c['host']);
    } catch (Throwable $e) {
        return false;
    }
}

function emailInUse(PDO $pdo, string $email, int $exceptUserId): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(?) AND id <> ?");
    $st->execute([$email, $exceptUserId]);
    if ((int)$st->fetchColumn() > 0) {
        return true;
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(pending_email) = LOWER(?) AND id <> ? AND pending_email_expires > ?");
    $st->execute([$email, $exceptUserId, gmdate('Y-m-d H:i:s')]);
    return (int)$st->fetchColumn() > 0;
}
