<?php
// Second login step for accounts with two-factor authentication. Reached only after the password was accepted.
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/twofa_lib.php';
twofaEnsureSchema($pdo);

$c = $_SESSION['tfa'] ?? null;
if (isset($_GET['cancel'])) {
    $adminBack = is_array($c) && ($c['role'] ?? '') === 'admin';
    unset($_SESSION['tfa']);
    header('Location: ' . ($adminBack ? 'admin/login.php' : 'index.php'));
    exit;
}
if (!is_array($c) || (int)$c['exp'] < time()) {
    unset($_SESSION['tfa']);
    header('Location: index.php');
    exit;
}
$loginUrl = ($c['role'] === 'admin') ? 'admin/login.php' : 'index.php';
if (empty($_SESSION['tfa_csrf'])) {
    $_SESSION['tfa_csrf'] = bin2hex(random_bytes(16));
}
$error = '';
$dead = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)$_SESSION['tfa_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Security check failed. Please try again.';
    } else {
        $st = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $st->execute([(int)$c['uid']]);
        $row = $st->fetch();
        if (!$row || !twofaIsEnabled($row)) {
            unset($_SESSION['tfa']);
            header('Location: ' . $loginUrl);
            exit;
        }
        $res = twofaCheckLogin($pdo, $row, (string)($_POST['code'] ?? ''));
        if ($res === 'ok') {
            $dest = twofaFinishLogin();
            header('Location: ' . $dest);
            exit;
        }
        $_SESSION['tfa']['tries']++;
        if ($res === 'locked') {
            unset($_SESSION['tfa']);
            $dead = true;
            $error = 'Too many wrong codes. This account is locked for ' . (int)ceil(TWOFA_LOCK_SECONDS / 60) . ' minutes.';
        } elseif ($_SESSION['tfa']['tries'] >= TWOFA_MAX_TRIES) {
            unset($_SESSION['tfa']);
            $dead = true;
            $error = 'Too many wrong codes. Please sign in again.';
        } else {
            $error = 'That code is not correct. Check the code in your authenticator app and try again.';
        }
    }
}
?>
<?php require_once __DIR__ . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-factor verification - SwiftPanel</title>
    <link rel="stylesheet" href="assets/style.css?v=3">
    <style>
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; background:#0b0e14; padding:16px; }
        .card { width:100%; max-width:400px; background:#141820; border:1px solid #262c3a; border-radius:12px; padding:28px; box-shadow:0 12px 32px rgba(0,0,0,.45); }
        .card h1 { margin:0 0 6px; color:#fff; font-size:20px; }
        .card p { margin:0 0 18px; color:#8b949e; font-size:13px; line-height:1.5; }
        .code { width:100%; box-sizing:border-box; text-align:center; font-size:26px; letter-spacing:.35em; padding:12px; background:#0d1117; border:1px solid #30363d; border-radius:8px; color:#fff; font-family:Consolas,Monaco,monospace; }
        .code:focus { outline:none; border-color:var(--neon,#39ff14); }
        .go { width:100%; margin-top:14px; padding:11px; font-size:14px; }
        .alt { margin-top:16px; font-size:12px; color:#64748b; text-align:center; line-height:1.6; }
        .alt a { color:#94a3b8; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Two-factor verification</h1>
        <?php if ($dead): ?>
            <div class="alert alert-error" style="margin-bottom:14px;"><?php echo htmlspecialchars($error); ?></div>
            <a class="btn btn-green go" style="display:block;text-align:center;text-decoration:none;" href="<?php echo htmlspecialchars($loginUrl); ?>">Back to sign in</a>
        <?php else: ?>
            <p>Hello <strong style="color:#fff;"><?php echo htmlspecialchars($c['username']); ?></strong>. Open your authenticator app and enter the 6-digit code for SwiftPanel.</p>
            <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:14px;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['tfa_csrf']); ?>">
                <input class="code" type="text" name="code" inputmode="text" autocomplete="one-time-code" placeholder="123 456" maxlength="14" autofocus required>
                <button type="submit" class="btn btn-green go">Verify and sign in</button>
            </form>
            <div class="alt">Lost your phone? Enter one of your <strong>recovery codes</strong> instead (like <code>abcde-fghjk</code>).<br><a href="login_2fa.php?cancel=1">Cancel and go back</a></div>
        <?php endif; ?>
    </div>
<?php require_once __DIR__ . '/legal_lib.php'; echo swiftFooterHtml(); ?>
</body>
</html>
