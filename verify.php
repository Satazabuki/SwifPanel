<?php
require_once 'config.php';
require_once 'mailer.php';
initMailSchema($pdo);

$token = trim($_GET['token'] ?? '');
$msg = 'Invalid or expired verification link.';
$ok = false;

if ($token !== '') {
    $st = $pdo->prepare("SELECT id, username FROM users WHERE verify_token = ? LIMIT 1");
    $st->execute([$token]);
    $u = $st->fetch();
    if ($u) {
        $pdo->prepare("UPDATE users SET email_verified = 1, verify_token = NULL WHERE id = ?")->execute([(int)$u['id']]);
        $ok = true;
        $msg = "Email verified for {$u['username']}! You can now log in to your Client Area.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Email Verification - SwiftPanel</title>
    <link rel="stylesheet" href="assets/style.css?v=3">
</head>
<body>
    <div style="max-width:480px;margin:80px auto;padding:0 16px;">
        <div class="box">
            <div class="box-header">Account Email Verification</div>
            <div class="box-content">
                <div class="<?php echo $ok ? 'alert alert-success' : 'alert alert-error'; ?>"><?php echo htmlspecialchars($msg); ?></div>
                <a href="index.php" class="btn btn-green">Go to Client Login</a>
            </div>
        </div>
    </div>
</body>
</html>
