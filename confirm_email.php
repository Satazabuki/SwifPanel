<?php
// Opened from the link in the confirmation e-mail sent to a NEW address; makes the e-mail change final.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/twofa_lib.php';
twofaEnsureSchema($pdo);
initMailSchema($pdo);

$token = trim((string)($_GET['token'] ?? ''));
$ok = false;
$msg = 'This confirmation link is invalid or has expired. Request the change again from your profile.';
if (preg_match('/^[a-f0-9]{48}$/', $token)) {
    $st = $pdo->prepare("SELECT id, username, pending_email FROM users WHERE pending_email_hash = ? AND pending_email_expires > ? LIMIT 1");
    $st->execute([hash('sha256', $token), gmdate('Y-m-d H:i:s')]);
    $u = $st->fetch();
    if ($u && $u['pending_email'] !== null && $u['pending_email'] !== '') {
        if (emailInUse($pdo, (string)$u['pending_email'], (int)$u['id'])) {
            $msg = 'That e-mail address is now used by another account, so it could not be applied.';
        } else {
            $pdo->prepare("UPDATE users SET email = ?, email_verified = 1, pending_email = NULL, pending_email_hash = NULL, pending_email_expires = NULL WHERE id = ?")
                ->execute([$u['pending_email'], (int)$u['id']]);
            $ok = true;
            $msg = 'Your e-mail address is now ' . $u['pending_email'] . '.';
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
    <title>E-mail change - SwiftPanel</title>
    <link rel="stylesheet" href="assets/style.css?v=3">
</head>
<body>
    <div style="max-width:480px;margin:80px auto;padding:0 16px;">
        <div class="box">
            <div class="box-header">E-mail address change</div>
            <div class="box-content">
                <div class="<?php echo $ok ? 'alert alert-success' : 'alert alert-error'; ?>"><?php echo htmlspecialchars($msg); ?></div>
                <a href="profile.php" class="btn btn-green">Go to my profile</a>
            </div>
        </div>
    </div>
<?php require_once __DIR__ . '/legal_lib.php'; echo swiftFooterHtml(); ?>
</body>
</html>
