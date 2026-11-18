<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: /admin/login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: /dashboard.php');
    exit;
}
