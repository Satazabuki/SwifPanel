<?php
require_once 'auth.php';
require_once 'config.php';
require_once 'ServerManager.php';

header('Content-Type: application/json');

$serverId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$serverId) {
    echo json_encode(['status' => 'error', 'log' => 'No server selected.']);
    exit;
}

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
if ($isAdmin) {
    $stmt = $pdo->prepare("
        SELECT s.*, b.ip_address AS ssh_ip, b.ssh_port, b.ssh_user, b.ssh_password
        FROM servers s
        JOIN boxes b ON s.box_id = b.id
        WHERE s.id = ?
    ");
    $stmt->execute([$serverId]);
} else {
    $stmt = $pdo->prepare("
        SELECT s.*, b.ip_address AS ssh_ip, b.ssh_port, b.ssh_user, b.ssh_password
        FROM servers s
        JOIN boxes b ON s.box_id = b.id
        WHERE s.id = ? AND s.user_id = ?
    ");
    $stmt->execute([$serverId, (int)$_SESSION['user_id']]);
}

$server = $stmt->fetch();
if (!$server) {
    echo json_encode(['status' => 'error', 'log' => 'Access denied or server not found.']);
    exit;
}

try {
    $mgr = new ServerManager();
    $mgr->connect($server['ssh_ip'], $server['ssh_port'], $server['ssh_user'], $server['ssh_password']);
    $log = $mgr->getConsoleLog($server['install_path'], 35);
    $running = $mgr->isRunning($server['screen_name']);

    echo json_encode([
        'status' => 'ok',
        'running' => $running,
        'log' => $log ?: '[System] Waiting for server start or installation logs...'
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'log' => '[SSH Error] ' . $e->getMessage()]);
}
