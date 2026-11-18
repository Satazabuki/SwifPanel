<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config.php';

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin') {
    header('Location: nodes.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter your admin username and password.';
    } else {
        $stmt = $pdo->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if (($user['role'] ?? '') !== 'admin') {
                $error = 'Access denied. Client accounts must sign in through the Client Portal.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = 'admin';
                header('Location: nodes.php');
                exit;
            }
        } else {
            $error = 'Invalid admin credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login - SwiftPanel</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
    <style>
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; background:#0b0e14; }
        .admin-login-card { width:100%; max-width:400px; background:#141820; border:1px solid #262c3a; border-radius:8px; padding:28px; box-shadow:0 12px 32px rgba(0,0,0,0.45); }
        .admin-badge { display:inline-block; background:rgba(231,76,60,0.15); color:#e74c3c; border:1px solid rgba(231,76,60,0.35); font-size:11px; font-weight:700; padding:3px 8px; border-radius:4px; letter-spacing:0.6px; margin-bottom:10px; }
        .form-group { margin-bottom:14px; }
        .form-group label { display:block; font-size:12px; color:#94a3b8; margin-bottom:6px; }
        .form-group input { width:100%; box-sizing:border-box; padding:10px 12px; background:#0d1117; border:1px solid #30363d; border-radius:5px; color:#fff; font-size:14px; }
        .btn-admin { width:100%; padding:10px; background:#e74c3c; color:#fff; border:none; border-radius:5px; font-weight:600; font-size:14px; cursor:pointer; }
        .btn-admin:hover { background:#c0392b; }
    </style>
</head>
<body>
    <div class="admin-login-card">
        <div class="admin-badge">ADMINISTRATION PORTAL</div>
        <h2 style="margin:0 0 6px;color:#fff;font-size:20px;">Swift<span>Panel</span> Admin</h2>
        <p style="margin:0 0 18px;color:#8b949e;font-size:13px;">Sign in with an administrator account to manage nodes and servers.</p>
        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom:14px;"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Admin Username</label>
                <input type="text" name="username" required autofocus>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn-admin">Sign In to Admin Panel</button>
        </form>
    </div>
</body>
</html>
