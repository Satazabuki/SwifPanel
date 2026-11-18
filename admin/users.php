<?php require_once __DIR__ . '/guard.php'; ?>
<?php
require_once '../auth.php';
require_once '../config.php';

$notice = '';
$errorMsg = '';
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$currentUserId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_user_id'])) {
    $vid = (int)$_POST['verify_user_id'];
    $pdo->prepare("UPDATE users SET email_verified = 1, verify_token = NULL WHERE id = ?")->execute([$vid]);
    $notice = "User #{$vid} marked as email verified.";
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (strlen($newPass) < 6) {
        $errorMsg = "Password must be at least 6 characters long.";
    } elseif ($newPass !== $confirmPass) {
        $errorMsg = "Passwords do not match.";
    } else {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $currentUserId]);
        $notice = "Your password has been updated in MySQL.";
    }
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = ($_POST['role'] === 'admin') ? 'admin' : 'client';

    if ($username && $email && strlen($password) >= 6) {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, email, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $hash, $email, $role]);
            $notice = "User '{$username}' ({$role}) created in MySQL.";
        } catch (PDOException $e) {
            $errorMsg = "Could not create user (username may already exist).";
        }
    } else {
        $errorMsg = "Fill all fields and ensure password is at least 6 characters.";
    }
}

if ($isAdmin && isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    if ($delId === $currentUserId) {
        $errorMsg = "You cannot delete your own active admin account.";
    } else {
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$delId]);
        $notice = "User deleted from MySQL.";
    }
}

$allUsers = $isAdmin ? $pdo->query("SELECT id, username, email, role, created_at FROM users ORDER BY id ASC")->fetchAll() : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users &amp; Account - SwiftPanel</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
</head>
<body>

    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#e74c3c;">ADMIN CONTROL</small></div>
        <div class="user-info">
            Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> (<?php echo htmlspecialchars($_SESSION['role']); ?>) | <a href="login.php?logout=1">Logout</a>
        </div>
    </div>
    <div id="navbar">
        <ul>
            <li><a href="nodes.php">Remote Nodes</a></li>
            <li><a href="client_servers.php">Client Servers</a></li>
            <li><a href="create_server.php">Create Server</a></li>
            <li><a href="users.php" class="active">Users</a></li>
        </ul>
    </div>

    <div id="wrapper">
        
        <div id="sidebar">
            <div class="box">
                <div class="box-header">Server Navigation</div>
                <div class="box-content">
                    <ul class="sidebar-menu">
                        
                        <?php if ($isAdmin): ?>
                            <li><a href="nodes.php">&raquo; Manage Remote Nodes</a></li>
                            <li><a href="create_server.php">&raquo; Create Game Server</a></li>
                        <?php endif; ?>
                        <li><a href="users.php">&raquo; My Account &amp; Users</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div id="content">
            <?php if ($notice): ?>
                <div class="alert-info"><strong>System Message:</strong> <?php echo htmlspecialchars($notice); ?></div>
            <?php elseif ($errorMsg): ?>
                <div class="alert-info" style="background-color: #f2dede; border-color: #ebccd1; color: #a94442;">
                    <strong>Error:</strong> <?php echo htmlspecialchars($errorMsg); ?>
                </div>
            <?php endif; ?>

            <div class="box">
                <div class="box-header">Change My Password</div>
                <div class="box-content">
                    <form method="POST" style="max-width: 400px;">
                        <div class="form-row">
                            <label>New Password (min 6 characters):</label>
                            <input type="password" name="new_password" style="width: 100%; padding: 5px; border: 1px solid #bbbbbb;" required>
                        </div>
                        <div class="form-row">
                            <label>Confirm New Password:</label>
                            <input type="password" name="confirm_password" style="width: 100%; padding: 5px; border: 1px solid #bbbbbb;" required>
                        </div>
                        <button type="submit" name="change_password" value="1" class="btn btn-blue">Update Password</button>
                    </form>
                </div>
            </div>

            <?php if ($isAdmin): ?>
            <div class="box">
                <div class="box-header">MySQL Panel Users</div>
                <div class="box-content" style="padding: 0;">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                            <th>Email Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allUsers as $u): ?>
                            <tr>
                                <td>#<?php echo (int)$u['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
                                <td><?php echo htmlspecialchars($u['email']); ?></td>
                                <td>
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="badge badge-online">Admin</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#e8f4fd;color:#1a5a96;border:1px solid #b6d9f7;">Client</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                                <td>
                                    <?php if ((int)$u['id'] !== $currentUserId): ?>
                                        <a href="users.php?delete=<?php echo (int)$u['id']; ?>" class="btn btn-red" onclick="return confirm('Delete this user and their servers?');">Delete</a>
                                    <?php else: ?>
                                        <span style="color:#888;font-size:11px;">Current Session</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="box">
                <div class="box-header">Create New User Account</div>
                <div class="box-content">
                    <form method="POST">
                        <div class="control-grid">
                            <div>
                                <div class="form-row">
                                    <label>Username:</label>
                                    <input type="text" name="username" required>
                                </div>
                                <div class="form-row">
                                    <label>Email Address:</label>
                                    <input type="text" name="email" required>
                                </div>
                            </div>
                            <div>
                                <div class="form-row">
                                    <label>Password:</label>
                                    <input type="password" name="password" style="width: 100%; padding: 5px; border: 1px solid #bbbbbb;" required>
                                </div>
                                <div class="form-row">
                                    <label>Account Role:</label>
                                    <select name="role">
                                        <option value="client">Client (Manages only assigned servers)</option>
                                        <option value="admin">Admin (Full access to nodes, users &amp; all servers)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <button type="submit" name="create_user" value="1" class="btn btn-green">Create MySQL User</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="footer">
        Powered by Standalone SwiftPanel &bull; Direct SSH &amp; Screen Control
    </div>

</body>
</html>
