<?php
require_once 'auth.php';
require_once 'config.php';
require_once 'ServerManager.php';

header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['status' => 'error']);
    exit;
}

$nodeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare("SELECT * FROM boxes WHERE id = ?");
$stmt->execute([$nodeId]);
$node = $stmt->fetch();

if (!$node) {
    echo json_encode(['status' => 'error']);
    exit;
}

try {
    $mgr = new ServerManager();
    $mgr->connect($node['ip_address'], $node['ssh_port'], $node['ssh_user'], $node['ssh_password']);
    $stats = $mgr->getSystemStats();
    if ($stats) {
        echo json_encode(['status' => 'ok', 'stats' => $stats]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Telemetry parse failed']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'offline', 'message' => $e->getMessage()]);
}
