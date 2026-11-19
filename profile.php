<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/twofa_lib.php';

$profileAdmin = !empty($profileAdmin);              // set by admin/account.php, which shows this same page inside the admin area
$base = $profileAdmin ? '../' : '';
$self = basename($_SERVER['SCRIPT_NAME'] ?? 'profile.php');
$userId = (int)$_SESSION['user_id'];
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
initMailSchema($pdo);
twofaEnsureSchema($pdo);

if (empty($_SESSION['profile_csrf'])) {
    $_SESSION['profile_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['profile_csrf'];

function profileUser(PDO $pdo, int $id)
{
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch();
}

$user = profileUser($pdo, $userId);
if (!$user) {
    header("Location: " . $base . "logout.php");
    exit;
}

function profileFlash(string $type, string $msg, array $extra = []): void
{
    $_SESSION['profile_flash'] = ['type' => $type, 'msg' => $msg] + $extra;
    header('Location: ' . basename($_SERVER['SCRIPT_NAME']) . (isset($extra['open']) ? '#' . $extra['open'] : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        profileFlash('error', 'Security check failed. Please reload the page and try again.');
    }
    $do = (string)($_POST['do'] ?? '');
    $pwOk = fn() => password_verify((string)($_POST['current_password'] ?? ''), (string)$user['password_hash']);

    if ($do === 'password') {
        $new = (string)($_POST['new_password'] ?? '');
        if (($_POST['current_password'] ?? '') === '' || $new === '' || ($_POST['confirm_password'] ?? '') === '') {
            profileFlash('error', 'Please fill in all password fields.', ['open' => 'password']);
        } elseif (!$pwOk()) {
            profileFlash('error', 'Current password is incorrect.', ['open' => 'password']);
        } elseif (strlen($new) < 6) {
            profileFlash('error', 'New password must be at least 6 characters long.', ['open' => 'password']);
        } elseif ($new !== (string)$_POST['confirm_password']) {
            profileFlash('error', 'New password and confirmation do not match.', ['open' => 'password']);
        }
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
        profileFlash('ok', 'Your password has been updated.', ['open' => 'password']);
    }

    if ($do === 'email') {
        $new = trim((string)($_POST['new_email'] ?? ''));
        if (!$pwOk()) {
            profileFlash('error', 'Your current password is incorrect.', ['open' => 'email']);
        } elseif (!filter_var($new, FILTER_VALIDATE_EMAIL) || strlen($new) > 100) {
            profileFlash('error', 'Please enter a valid e-mail address.', ['open' => 'email']);
        } elseif (strcasecmp($new, (string)$user['email']) === 0) {
            profileFlash('error', 'That is already your e-mail address.', ['open' => 'email']);
        } elseif (emailInUse($pdo, $new, $userId)) {
            profileFlash('error', 'That e-mail address is already used by another account.', ['open' => 'email']);
        }
        if (!emailSmtpReady($pdo)) {
            // no mail server configured, so there is nothing to confirm with: the password check is the protection
            $pdo->prepare("UPDATE users SET email = ?, pending_email = NULL, pending_email_hash = NULL, pending_email_expires = NULL WHERE id = ?")->execute([$new, $userId]);
            profileFlash('ok', 'Your e-mail address is now ' . $new . '.', ['open' => 'email']);
        }
        $tok = bin2hex(random_bytes(24));
        $pdo->prepare("UPDATE users SET pending_email = ?, pending_email_hash = ?, pending_email_expires = ? WHERE id = ?")
            ->execute([$new, hash('sha256', $tok), gmdate('Y-m-d H:i:s', time() + 86400), $userId]);
        $link = rtrim(SWIFT_SITE_URL, '/') . '/confirm_email.php?token=' . $tok;
        $html = wrapPanelMail('Confirm your new e-mail address', "<p>Hello <strong>" . htmlspecialchars($user['username']) . "</strong>,</p><p>Please confirm that this address should be used for your SwiftPanel account. The link works for 24 hours:</p><p><a href='{$link}' style='display:inline-block;padding:10px 18px;background:#2ecc71;color:#000;font-weight:bold;text-decoration:none;border-radius:5px;'>Confirm e-mail address</a></p><p style='color:#888;font-size:12px;'>If you did not ask for this, ignore this message: nothing changes.</p>");
        $err = null;
        if (!sendSmtpMail($pdo, $new, 'Confirm your new SwiftPanel e-mail address', $html, $err)) {
            $pdo->prepare("UPDATE users SET pending_email = NULL, pending_email_hash = NULL, pending_email_expires = NULL WHERE id = ?")->execute([$userId]);
            profileFlash('error', 'The confirmation e-mail could not be sent (' . ($err ?: 'SMTP error') . '). Your address was not changed.', ['open' => 'email']);
        }
        $old = (string)$user['email'];
        if (filter_var($old, FILTER_VALIDATE_EMAIL) && !preg_match('/@client\.local$/i', $old)) {
            $nh = wrapPanelMail('E-mail change requested', "<p>Hello <strong>" . htmlspecialchars($user['username']) . "</strong>,</p><p>Someone asked to change the e-mail address of your SwiftPanel account to <strong>" . htmlspecialchars($new) . "</strong>. The change only happens if that new address is confirmed.</p><p>If this was not you, please change your password now.</p>");
            sendSmtpMail($pdo, $old, 'E-mail change requested on your SwiftPanel account', $nh);
        }
        profileFlash('ok', 'We sent a confirmation link to ' . $new . '. Your e-mail address stays the same until you open that link.', ['open' => 'email']);
    }

    if ($do === 'email_cancel') {
        $pdo->prepare("UPDATE users SET pending_email = NULL, pending_email_hash = NULL, pending_email_expires = NULL WHERE id = ?")->execute([$userId]);
        profileFlash('ok', 'The pending e-mail change was cancelled.', ['open' => 'email']);
    }

    if ($do === '2fa_start') {
        if (twofaIsEnabled($user)) {
            profileFlash('error', 'Two-factor authentication is already on.', ['open' => '2fa']);
        }
        $_SESSION['tfa_setup'] = ['secret' => twofaGenerateSecret(), 'tries' => 0];
        header('Location: ' . $self . '#2fa');
        exit;
    }
    if ($do === '2fa_cancel') {
        unset($_SESSION['tfa_setup']);
        header('Location: ' . $self . '#2fa');
        exit;
    }
    if ($do === '2fa_confirm') {
        $setup = $_SESSION['tfa_setup'] ?? null;
        if (!is_array($setup) || twofaIsEnabled($user)) {
            profileFlash('error', 'The setup session expired. Start again.', ['open' => '2fa']);
        }
        $_SESSION['tfa_setup']['tries']++;
        if ($_SESSION['tfa_setup']['tries'] > 10) {
            unset($_SESSION['tfa_setup']);
            profileFlash('error', 'Too many wrong codes. Start the setup again.', ['open' => '2fa']);
        }
        $step = twofaMatchStep($setup['secret'], (string)($_POST['code'] ?? ''), 0);
        if ($step === null) {
            profileFlash('error', 'That code is not correct. Check the time on your phone and try the newest code.', ['open' => '2fa']);
        }
        $pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_step = ?, totp_fails = 0, totp_locked_until = NULL WHERE id = ?")
            ->execute([$setup['secret'], $step, $userId]);
        unset($_SESSION['tfa_setup']);
        profileFlash('ok', 'Two-factor authentication is now on.', ['open' => '2fa', 'codes' => twofaNewRecoveryCodes($pdo, $userId)]);
    }
    if ($do === '2fa_disable' || $do === '2fa_regen') {
        if (!twofaIsEnabled($user)) {
            profileFlash('error', 'Two-factor authentication is not on.', ['open' => '2fa']);
        }
        if (!$pwOk()) {
            profileFlash('error', 'Your current password is incorrect.', ['open' => '2fa']);
        }
        $res = twofaCheckLogin($pdo, $user, (string)($_POST['code'] ?? ''));
        if ($res === 'locked') {
            profileFlash('error', 'Too many wrong codes. Try again in a few minutes.', ['open' => '2fa']);
        } elseif ($res !== 'ok') {
            profileFlash('error', 'That code is not correct.', ['open' => '2fa']);
        }
        if ($do === '2fa_disable') {
            $pdo->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0, totp_fails = 0, totp_locked_until = NULL WHERE id = ?")->execute([$userId]);
            $pdo->prepare("DELETE FROM user_recovery_codes WHERE user_id = ?")->execute([$userId]);
            profileFlash('ok', 'Two-factor authentication is now off.', ['open' => '2fa']);
        }
        profileFlash('ok', 'New recovery codes created. The old ones no longer work.', ['open' => '2fa', 'codes' => twofaNewRecoveryCodes($pdo, $userId)]);
    }
    profileFlash('error', 'Unknown action.');
}

// ---- view data
$flash = $_SESSION['profile_flash'] ?? null;
unset($_SESSION['profile_flash']);
$newCodes = is_array($flash['codes'] ?? null) ? $flash['codes'] : null;

$serverCount = 0;
if (!$profileAdmin) {
    $srvStmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE user_id = ?");
    $srvStmt->execute([$userId]);
    $serverCount = (int)$srvStmt->fetchColumn();
}
$tfaOn = twofaIsEnabled($user);
$setup = (!$tfaOn && is_array($_SESSION['tfa_setup'] ?? null)) ? $_SESSION['tfa_setup'] : null;
$emailRaw = (string)$user['email'];
$noEmail = ($emailRaw === '' || preg_match('/@client\.local$/i', $emailRaw));
$pendingEmail = (!empty($user['pending_email']) && !empty($user['pending_email_expires']) && strtotime($user['pending_email_expires'] . ' UTC') > time()) ? (string)$user['pending_email'] : '';
$verified = !isset($user['email_verified']) || (int)$user['email_verified'] === 1;
$recoveryLeft = $tfaOn ? twofaRecoveryLeft($pdo, $userId) : 0;
$since = !empty($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : '-';
$initial = strtoupper(substr((string)$user['username'], 0, 1)) ?: '?';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?>
<?php require_once __DIR__ . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - SwiftPanel</title>
    <link rel="stylesheet" href="<?php echo $base; ?>assets/style.css?v=3">
    <style>
        .pf { max-width: 1100px; margin: 24px auto; padding: 0 18px; }
        .hero { display:flex; flex-wrap:wrap; align-items:center; gap:18px; background:var(--bg-card); border:1px solid var(--border-subtle); border-radius:14px; padding:20px 22px; margin-bottom:16px; }
        .avatar { width:62px; height:62px; border-radius:50%; background:linear-gradient(135deg,#1f3b2a,#0f2a1b); border:2px solid var(--border-glow); color:var(--neon); font-size:26px; font-weight:700; display:flex; align-items:center; justify-content:center; flex:none; }
        .hero h1 { margin:0; font-size:21px; color:#fff; }
        .hero .sub { color:var(--text-secondary); font-size:13px; margin-top:3px; }
        .chips { display:flex; flex-wrap:wrap; gap:8px; margin-left:auto; }
        .chip { display:inline-flex; align-items:center; gap:6px; font-size:12px; padding:5px 11px; border-radius:99px; border:1px solid var(--border-subtle); background:#0d1218; color:var(--text-secondary); }
        .chip.good { color:var(--neon); border-color:var(--border-glow); background:var(--neon-dim); }
        .chip.warn { color:#fbbf24; border-color:rgba(251,191,36,.4); background:rgba(251,191,36,.1); }
        .chip.bad { color:#f87171; border-color:rgba(239,68,68,.4); background:var(--danger-dim); }
        .toast { padding:11px 14px; border-radius:9px; font-size:13px; margin-bottom:14px; border:1px solid; }
        .toast.ok { background:var(--neon-dim); border-color:var(--border-glow); color:#c7ffb8; }
        .toast.error { background:var(--danger-dim); border-color:rgba(239,68,68,.45); color:#fecaca; }
        .cards { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; align-items:start; }
        @media (max-width: 860px) { .cards { grid-template-columns: 1fr; } }
        .card { background:var(--bg-card); border:1px solid var(--border-subtle); border-radius:14px; padding:20px 22px; scroll-margin-top:16px; }
        .card.wide { grid-column: 1 / -1; }
        .card h2 { margin:0 0 4px; font-size:16px; color:#fff; display:flex; align-items:center; gap:10px; }
        .card .lead { color:var(--text-secondary); font-size:13px; line-height:1.55; margin:0 0 16px; }
        .fld { margin-bottom:13px; }
        .fld label { display:block; font-size:12px; color:var(--text-secondary); margin-bottom:5px; }
        .fld input[type=text], .fld input[type=password], .fld input[type=email] { width:100%; padding:10px 12px; background:var(--bg-input); border:1px solid var(--border-subtle); border-radius:8px; color:#fff; font-size:14px; }
        .fld input:focus { outline:none; border-color:var(--neon); box-shadow:0 0 0 3px var(--neon-dim); }
        .fld .hint { font-size:12px; color:var(--text-muted); margin-top:5px; }
        .kv { width:100%; border-collapse:collapse; font-size:13px; }
        .kv th { text-align:left; color:var(--text-muted); font-weight:500; padding:9px 0; width:130px; border-bottom:1px solid #151c26; }
        .kv td { padding:9px 0; color:var(--text-primary); border-bottom:1px solid #151c26; }
        .kv tr:last-child th, .kv tr:last-child td { border-bottom:0; }
        .pending { background:rgba(251,191,36,.08); border:1px solid rgba(251,191,36,.35); border-radius:9px; padding:12px 14px; font-size:13px; color:#fde68a; margin-bottom:14px; display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap; }
        .steps { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:22px; }
        @media (max-width: 860px) { .steps { grid-template-columns: 1fr; } }
        .step { display:flex; gap:12px; margin-bottom:16px; }
        .step .n { flex:none; width:26px; height:26px; border-radius:50%; background:var(--neon-dim); border:1px solid var(--border-glow); color:var(--neon); font-weight:700; font-size:13px; display:flex; align-items:center; justify-content:center; }
        .step b { color:#fff; font-size:14px; }
        .step p { margin:3px 0 0; color:var(--text-secondary); font-size:13px; line-height:1.5; }
        .qrbox { background:#fff; border-radius:12px; padding:6px; display:inline-block; line-height:0; }
        .qrbox svg { display:block; max-width:100%; height:auto; }
        .secret { font-family:Consolas,Monaco,monospace; font-size:15px; letter-spacing:.08em; color:#fff; background:var(--bg-input); border:1px dashed #2b3647; border-radius:8px; padding:9px 12px; word-break:break-all; user-select:all; }
        .codes { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:8px; margin:12px 0; }
        .codes code { background:var(--bg-input); border:1px solid var(--border-subtle); border-radius:7px; padding:9px 10px; text-align:center; font-size:14px; color:#fff; letter-spacing:.05em; }
        .warnbox { background:rgba(251,191,36,.08); border:1px solid rgba(251,191,36,.35); border-radius:9px; padding:12px 14px; font-size:13px; color:#fde68a; }
        .row-btns { display:flex; flex-wrap:wrap; gap:8px; }
        .btn-quiet { background:#141b25; color:var(--text-primary); border:1px solid var(--border-subtle); }
        .btn-danger { background:var(--danger-dim); color:#fca5a5; border:1px solid rgba(239,68,68,.45); }
        details.more { border-top:1px solid #151c26; margin-top:16px; padding-top:12px; }
        details.more summary { cursor:pointer; color:var(--text-secondary); font-size:13px; padding:4px 0; }
        details.more[open] summary { color:#fff; margin-bottom:10px; }
        .twocol { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:18px; }
        @media (max-width: 860px) { .twocol { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;font-weight:normal;color:<?php echo $profileAdmin ? '#e74c3c' : '#aaa'; ?>;"><?php echo $profileAdmin ? 'ADMIN CONTROL' : 'Springs Docker Edition'; ?></small></div>
        <div class="user-info">
            Logged in as: <strong><?php echo $h($_SESSION['username'] ?? 'user'); ?></strong> |
            <a href="<?php echo $profileAdmin ? 'login.php?logout=1' : 'logout.php'; ?>">Logout</a>
        </div>
    </div>
<?php if ($profileAdmin): ?>
    <?php $adminNavActive = 'account'; include __DIR__ . '/admin/nav.php'; ?>
<?php else: ?>
    <div id="navbar">
        <ul>
            <li><a href="dashboard.php">Game Servers</a></li>
            <li><a href="dashboard.php?page=order">Order Server</a></li>
            <li><a href="dashboard.php?page=invoices">Invoices</a></li>
            <li><a href="mods/index.php">Mods &amp; Plugins</a></li>
            <li><a href="tickets.php">Support Tickets</a></li>
            <li><a href="profile.php" class="active">My Profile</a></li>
        </ul>
    </div>
<?php endif; ?>

<div class="pf">
    <?php if ($flash): ?><div class="toast <?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>"><?php echo $h($flash['msg']); ?></div><?php endif; ?>

    <div class="hero">
        <div class="avatar"><?php echo $h($initial); ?></div>
        <div>
            <h1><?php echo $h($user['username']); ?></h1>
            <div class="sub"><?php echo $isAdmin ? 'Administrator' : 'Client'; ?> &middot; member since <?php echo $h($since); ?><?php if (!$profileAdmin): ?> &middot; <?php echo $serverCount; ?> server<?php echo $serverCount === 1 ? '' : 's'; ?><?php endif; ?></div>
        </div>
        <div class="chips">
            <span class="chip <?php echo $tfaOn ? 'good' : 'warn'; ?>"><?php echo $tfaOn ? '&#10003; 2FA on' : '&#9888; 2FA off'; ?></span>
            <span class="chip <?php echo $noEmail ? 'bad' : ($pendingEmail ? 'warn' : ($verified ? 'good' : 'warn')); ?>"><?php echo $noEmail ? 'No e-mail set' : ($pendingEmail ? 'E-mail change pending' : ($verified ? '&#10003; E-mail verified' : 'E-mail not verified')); ?></span>
        </div>
    </div>

    <div class="cards">
        <!-- E-mail -->
        <div class="card" id="email">
            <h2>E-mail address</h2>
            <p class="lead">Used for invoices, order and support notifications and for signing in.</p>
            <table class="kv">
                <tr><th>Current</th><td><?php echo $noEmail ? '<span style="color:#f87171;">none set</span>' : $h($emailRaw); ?></td></tr>
            </table>
            <?php if ($pendingEmail): ?>
                <div class="pending" style="margin-top:14px;">
                    <span>Waiting for you to confirm <strong><?php echo $h($pendingEmail); ?></strong>. Open the link we e-mailed there.</span>
                    <form method="POST" style="margin:0;"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="do" value="email_cancel"><button class="btn btn-quiet" style="padding:6px 12px;font-size:12px;">Cancel change</button></form>
                </div>
            <?php endif; ?>
            <form method="POST" style="margin-top:16px;" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="do" value="email">
                <div class="fld"><label for="new_email">New e-mail address</label><input type="email" id="new_email" name="new_email" required maxlength="100"></div>
                <div class="fld"><label for="em_pw">Current password</label><input type="password" id="em_pw" name="current_password" required autocomplete="current-password">
                    <div class="hint"><?php echo emailSmtpReady($pdo) ? 'We send a confirmation link to the new address. Nothing changes until you open it.' : 'Your password confirms the change.'; ?></div></div>
                <button type="submit" class="btn btn-green">Change e-mail</button>
            </form>
        </div>

        <!-- Password -->
        <div class="card" id="password">
            <h2>Password</h2>
            <p class="lead">Use a long password you do not use anywhere else.</p>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="do" value="password">
                <div class="fld"><label for="pw_cur">Current password</label><input type="password" id="pw_cur" name="current_password" required autocomplete="current-password"></div>
                <div class="fld"><label for="pw_new">New password (at least 6 characters)</label><input type="password" id="pw_new" name="new_password" minlength="6" required autocomplete="new-password"></div>
                <div class="fld"><label for="pw_new2">Confirm new password</label><input type="password" id="pw_new2" name="confirm_password" minlength="6" required autocomplete="new-password"></div>
                <button type="submit" class="btn btn-green">Update password</button>
            </form>
        </div>

        <!-- Two-factor authentication -->
        <div class="card wide" id="2fa">
            <h2>Two-factor authentication
                <span class="chip <?php echo $tfaOn ? 'good' : ''; ?>" style="font-size:11px;"><?php echo $tfaOn ? 'ON' : 'OFF'; ?></span></h2>
            <p class="lead">Adds a 6-digit code from an authenticator app to your sign-in. Even if someone learns your password, they cannot get in without your phone.</p>

<?php if ($newCodes): ?>
            <div class="warnbox"><strong>Save your recovery codes now.</strong> Each code works once if you lose your phone. They are shown only this time, so store them somewhere safe, such as a password manager.</div>
            <div class="codes" id="codes"><?php foreach ($newCodes as $c): ?><code><?php echo $h($c); ?></code><?php endforeach; ?></div>
            <div class="row-btns">
                <button type="button" class="btn btn-quiet" id="copyCodes">Copy codes</button>
                <button type="button" class="btn btn-quiet" id="dlCodes">Download .txt</button>
            </div>

<?php elseif ($tfaOn): ?>
            <table class="kv" style="max-width:520px;">
                <tr><th>Status</th><td>Active (authenticator app)</td></tr>
                <tr><th>Recovery codes</th><td><?php echo $recoveryLeft; ?> left<?php if ($recoveryLeft <= 2): ?> <span style="color:#fbbf24;">&mdash; create new ones soon</span><?php endif; ?></td></tr>
            </table>
            <details class="more">
                <summary>Create new recovery codes</summary>
                <form method="POST" autocomplete="off" style="max-width:420px;">
                    <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="do" value="2fa_regen">
                    <div class="fld"><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
                    <div class="fld"><label>Code from your authenticator app</label><input type="text" name="code" inputmode="numeric" maxlength="14" required autocomplete="off" placeholder="123456"></div>
                    <button class="btn btn-quiet">Create new codes</button>
                </form>
            </details>
            <details class="more">
                <summary>Turn off two-factor authentication</summary>
                <form method="POST" autocomplete="off" style="max-width:420px;" onsubmit="return confirm('Turn off two-factor authentication? Your account will be protected by the password only.');">
                    <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="do" value="2fa_disable">
                    <div class="fld"><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
                    <div class="fld"><label>Code from your authenticator app (or a recovery code)</label><input type="text" name="code" maxlength="14" required autocomplete="off"></div>
                    <button class="btn btn-danger">Turn off 2FA</button>
                </form>
            </details>

<?php elseif ($setup): ?>
            <?php $uri = twofaUri((string)$user['username'], $setup['secret']); ?>
            <div class="steps">
                <div>
                    <div class="step"><div class="n">1</div><div><b>Get an authenticator app</b><p>Google Authenticator, Microsoft Authenticator, Authy, 1Password or Bitwarden all work.</p></div></div>
                    <div class="step"><div class="n">2</div><div><b>Add SwiftPanel</b><p>Scan the QR code with the app, or choose "enter a setup key" and type this key (time based):</p>
                        <div class="secret" style="margin-top:8px;"><?php echo $h(twofaGroupSecret($setup['secret'])); ?></div>
                        <p style="margin-top:8px;"><a href="<?php echo $h($uri); ?>" style="color:var(--neon);">Open in authenticator app</a> (on this phone)</p></div></div>
                    <div class="step"><div class="n">3</div><div style="flex:1;"><b>Enter the 6-digit code</b><p>This proves the app is set up correctly.</p>
                        <form method="POST" autocomplete="off" style="margin-top:10px;max-width:320px;">
                            <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="do" value="2fa_confirm">
                            <div class="fld"><input type="text" name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code" placeholder="123456" style="font-size:20px;letter-spacing:.3em;text-align:center;" autofocus></div>
                            <div class="row-btns"><button class="btn btn-green">Turn on 2FA</button></div>
                        </form>
                        <form method="POST" style="margin-top:8px;"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="do" value="2fa_cancel"><button class="btn btn-quiet" style="padding:6px 12px;font-size:12px;">Cancel setup</button></form>
                    </div></div>
                </div>
                <div style="text-align:center;">
                    <div class="qrbox" id="qr"></div>
                    <noscript><p style="color:var(--text-secondary);font-size:13px;">Turn on JavaScript to see the QR code, or use the key on the left.</p></noscript>
                    <p style="color:var(--text-muted);font-size:12px;margin-top:10px;">The QR code is created in your browser; the key never leaves this page.</p>
                </div>
            </div>
            <script src="<?php echo $base; ?>assets/qrcode.js?v=1"></script>
            <script>
            (function () {
                try { document.getElementById('qr').innerHTML = SwiftQR.svg(<?php echo json_encode($uri, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, 5); }
                catch (e) { document.getElementById('qr').outerHTML = '<p style="color:#f87171;font-size:13px;">Could not draw the QR code. Use the setup key instead.</p>'; }
            })();
            </script>

<?php else: ?>
            <div class="row-btns">
                <form method="POST"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="do" value="2fa_start"><button class="btn btn-green">Set up two-factor authentication</button></form>
            </div>
            <p style="color:var(--text-muted);font-size:12px;margin:12px 0 0;">It takes about a minute. You will also get 8 one-time recovery codes in case you lose your phone.</p>
<?php endif; ?>
        </div>
    </div>
</div>

<?php if ($newCodes): ?>
<script>
(function () {
    var codes = <?php echo json_encode(array_values($newCodes)); ?>;
    var text = "SwiftPanel recovery codes for <?php echo $h($user['username']); ?>\nEach code works once.\n\n" + codes.join("\n") + "\n";
    document.getElementById('copyCodes').addEventListener('click', function () {
        var b = this;
        (navigator.clipboard ? navigator.clipboard.writeText(codes.join("\n")) : Promise.reject()).then(function () { b.textContent = 'Copied'; }, function () {
            var r = document.createRange(); r.selectNodeContents(document.getElementById('codes')); var s = getSelection(); s.removeAllRanges(); s.addRange(r); b.textContent = 'Selected, press Ctrl+C';
        });
    });
    document.getElementById('dlCodes').addEventListener('click', function () {
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
        a.download = 'swiftpanel-recovery-codes.txt';
        document.body.appendChild(a); a.click(); a.remove();
    });
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/legal_lib.php'; echo swiftFooterHtml(); ?>
</body>
</html>
