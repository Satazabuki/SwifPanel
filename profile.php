<?php
require_once 'auth.php';
require_once 'config.php';

$userId = (int)$_SESSION['user_id'];
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$notice = '';
$errorMsg = '';

$stmt = $pdo->prepare("SELECT id, username, password_hash, role, created_at FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: logout.php");
    exit;
}

$srvStmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE user_id = ?");
$srvStmt->execute([$userId]);
$serverCount = (int)$srvStmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPass = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if ($currentPass === '' || $newPass === '' || $confirmPass === '') {
        $errorMsg = "Please fill in all password fields.";
    } elseif (!password_verify($currentPass, $user['password_hash'])) {
        $errorMsg = "Current password is incorrect.";
    } elseif (strlen($newPass) < 6) {
        $errorMsg = "New password must be at least 6 characters long.";
    } elseif ($newPass !== $confirmPass) {
        $errorMsg = "New password and confirmation do not match.";
    } else {
        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $upd->execute([$newHash, $userId]);
        $user['password_hash'] = $newHash;
        $notice = "Your account password has been updated successfully.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - SwiftPanel</title>
    <link rel="stylesheet" href="assets/style.css?v=3">
</head>
<body>

    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size: 11px; font-weight: normal; color: #aaa;">Springs Docker Edition</small></div>
        <div class="user-info">
            Logged in as: <strong><?php echo htmlspecialchars($_SESSION["username"] ?? "user"); ?></strong> | Database: <span style="color: #2ecc71;">Connected</span> | 
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div id="navbar">
        <ul>
            <li><a href="dashboard.php">Game Servers</a></li>
            <li><a href="tickets.php">Support Tickets</a></li>
            <li><a href="profile.php" class="active">My Profile</a></li>
        </ul>
    </div>

    <div class="container">
        <?php if ($notice): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($errorMsg); ?></div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px;">
            <div class="box">
                <div class="box-header">Account Details</div>
                <div class="box-content">
                    <table class="data-table">
                        <tbody>
                            <tr>
                                <th style="width:140px;">Account ID</th>
                                <td>#<?php echo (int)$user['id']; ?></td>
                            </tr>
                            <tr>
                                <th>Username</th>
                                <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                            </tr>
                            <tr>
                                <th>Role</th>
                                <td><?php echo $isAdmin ? 'Administrator' : 'Client'; ?></td>
                            </tr>
                            <tr>
                                <th>Assigned Servers</th>
                                <td><?php echo $serverCount; ?> server(s)</td>
                            </tr>
                            <?php if (!empty($user['created_at'])): ?>
                            <tr>
                                <th>Created</th>
                                <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="box">
                <div class="box-header">Change Password</div>
                <div class="box-content">
                    <form method="POST">
                        <div class="form-group" style="margin-bottom:12px;">
                            <label>Current Password</label>
                            <input type="password" name="current_password" required style="width:100%;">
                        </div>
                        <div class="form-group" style="margin-bottom:12px;">
                            <label>New Password (min. 6 characters)</label>
                            <input type="password" name="new_password" minlength="6" required style="width:100%;">
                        </div>
                        <div class="form-group" style="margin-bottom:16px;">
                            <label>Confirm New Password</label>
                            <input type="password" name="confirm_password" minlength="6" required style="width:100%;">
                        </div>
                        <button type="submit" name="change_password" value="1" class="btn btn-green">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
