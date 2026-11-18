<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

if (($_SESSION['role'] ?? '') === 'admin' && strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== 0 && strpos($_SERVER['SCRIPT_NAME'] ?? '', '_ajax.php') === false) { header('Location: /admin/nodes.php'); exit; }
