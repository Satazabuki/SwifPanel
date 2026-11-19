<?php
require_once 'guard.php';
require_once '../config.php';
require_once '../mailer.php';

initMailSchema($pdo);
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_smtp'])) {
        $enabled = !empty($_POST['enabled']) ? 1 : 0;
        $host = trim($_POST['host'] ?? '');
        $port = (int)($_POST['port'] ?: 587);
        $enc = in_array($_POST['encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $_POST['encryption'] : 'tls';
        $user = trim($_POST['username'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        $fromEmail = trim($_POST['from_email'] ?? '');
        $fromName = trim($_POST['from_name'] ?: 'SwiftPanel');

        $cur = $pdo->query("SELECT password FROM smtp_settings WHERE id = 1")->fetch();
        $finalPass = ($pass !== '') ? $pass : ($cur['password'] ?? '');

        $st = $pdo->prepare("UPDATE smtp_settings SET enabled=?, host=?, port=?, encryption=?, username=?, password=?, from_email=?, from_name=? WHERE id=1");
        $st->execute([$enabled, $host, $port, $enc, $user, $finalPass, $fromEmail, $fromName]);
        $notice = 'SMTP settings saved successfully.';
    } elseif (isset($_POST['test_smtp'])) {
        $testTo = trim($_POST['test_email'] ?? '');
        $err = null;
        $html = wrapPanelMail('SMTP Test Successful', '<p>Your SwiftPanel SMTP integration is working properly.</p>');
        if (sendSmtpMail($pdo, $testTo, 'SwiftPanel SMTP Test Email', $html, $err)) {
            $notice = "Test email sent successfully to {$testTo}!";
        } else {
            $error = "Failed to send test email: {$err}";
        }
    }
}

$cfg = $pdo->query("SELECT * FROM smtp_settings WHERE id = 1")->fetch();
?>
<?php require_once dirname(__DIR__) . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SMTP Settings - Admin Panel</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
    <style>
        .container { max-width: 960px; margin: 24px auto; padding: 0 18px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-input { width: 100%; box-sizing: border-box; background: #0d1117; color: #fff; border: 1px solid #30363d; border-radius: 4px; padding: 9px 11px; font-size: 13px; }
        label { display: block; font-size: 12px; color: #94a3b8; margin-bottom: 4px; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#e74c3c;">ADMIN CONTROL</small></div>
        <div class="user-info">Admin: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> | <a href="login.php?logout=1">Logout</a></div>
    </div>
    <?php $adminNavActive = 'smtp'; include __DIR__ . '/nav.php'; ?>
    <div class="container">
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="box" style="margin-bottom:20px;">
            <div class="box-header">SMTP Mail Server Configuration</div>
            <div class="box-content">
                <form method="POST">
                    <div style="margin-bottom:14px;">
                        <label style="display:inline-flex;align-items:center;gap:8px;color:#fff;cursor:pointer;">
                            <input type="checkbox" name="enabled" value="1" <?php echo !empty($cfg['enabled']) ? 'checked' : ''; ?>>
                            Enable Automated Emails (Registration Verification, Order Confirmation &amp; Server Activation)
                        </label>
                    </div>
                    <div class="form-grid">
                        <div>
                            <label>SMTP Host</label>
                            <input type="text" name="host" class="form-input" value="<?php echo htmlspecialchars($cfg['host'] ?? ''); ?>" placeholder="://gmail.com or ://yourdomain.com" required>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                            <div>
                                <label>SMTP Port</label>
                                <input type="number" name="port" class="form-input" value="<?php echo (int)($cfg['port'] ?: 587); ?>" required>
                            </div>
                            <div>
                                <label>Encryption</label>
                                <select name="encryption" class="form-input">
                                    <option value="tls" <?php echo ($cfg['encryption'] ?? '') === 'tls' ? 'selected' : ''; ?>>STARTTLS (587)</option>
                                    <option value="ssl" <?php echo ($cfg['encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL/TLS (465)</option>
                                    <option value="none" <?php echo ($cfg['encryption'] ?? '') === 'none' ? 'selected' : ''; ?>>None (25)</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label>SMTP Username</label>
                            <input type="text" name="username" class="form-input" value="<?php echo htmlspecialchars($cfg['username'] ?? ''); ?>" placeholder="noreply@yourdomain.com">
                        </div>
                        <div>
                            <label>SMTP Password (leave blank to keep existing)</label>
                            <input type="password" name="password" class="form-input" placeholder="••••••••••••">
                        </div>
                        <div>
                            <label>Sender Email (From Address)</label>
                            <input type="email" name="from_email" class="form-input" value="<?php echo htmlspecialchars($cfg['from_email'] ?? ''); ?>" placeholder="noreply@yourdomain.com" required>
                        </div>
                        <div>
                            <label>Sender Display Name</label>
                            <input type="text" name="from_name" class="form-input" value="<?php echo htmlspecialchars($cfg['from_name'] ?? 'SwiftPanel'); ?>">
                        </div>
                    </div>
                    <button type="submit" name="save_smtp" value="1" class="btn btn-green" style="margin-top:14px;">Save SMTP Settings</button>
                </form>
            </div>
        </div>

        <div class="box">
            <div class="box-header">Send Test Email</div>
            <div class="box-content">
                <form method="POST" style="display:flex;gap:10px;align-items:center;margin:0;">
                    <input type="email" name="test_email" class="form-input" placeholder="your-email@example.com" style="max-width:340px;" required>
                    <button type="submit" name="test_smtp" value="1" class="btn btn-blue">Send Test Email</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
