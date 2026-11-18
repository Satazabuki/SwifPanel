<?php
require_once 'guard.php';
require_once '../config.php';

$serverId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare("SELECT s.*, u.username AS owner_name, b.name AS box_name, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token FROM servers s JOIN users u ON s.user_id = u.id JOIN boxes b ON s.box_id = b.id WHERE s.id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch();
if (!$server) { header("Location: client_servers.php"); exit; }

function callSpringsAdmin($server, $endpoint, $payload = []) {
    $port = (int)($server['api_port'] ?: 8085);
    $payload['screen_name'] = $server['screen_name'];
    $payload['install_path'] = $server['install_path'];
    $ch = curl_init("http://{$server['node_ip']}:{$port}{$endpoint}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $server['api_token']],
        CURLOPT_TIMEOUT => 10
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp ?: '{}', true) ?: [];
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $act = $_POST['action'];
        if ($act === 'start') {
            callSpringsAdmin($server, '/api/servers/start', [
                'game' => $server['game'],
                'ip_address' => $server['ip_address'],
                'port' => (int)$server['port'],
                'max_players' => (int)($server['max_players'] ?? 32),
                'slots' => (int)($server['max_players'] ?? 32),
                'ram' => (int)($server['ram_mb'] ?? 2048),
                'ram_mb' => (int)($server['ram_mb'] ?? 2048),
                'start_map' => $server['start_map_or_world'] ?? 'de_dust2',
                'start_map_or_world' => $server['start_map_or_world'] ?? 'de_dust2'
            ]);
            $msg = 'Start signal sent.';
        } elseif ($act === 'stop') {
            callSpringsAdmin($server, '/api/servers/stop');
            $msg = 'Stop signal sent.';
        } elseif ($act === 'restart') {
            callSpringsAdmin($server, '/api/servers/stop');
            sleep(1);
            callSpringsAdmin($server, '/api/servers/start', [
                'game' => $server['game'],
                'ip_address' => $server['ip_address'],
                'port' => (int)$server['port'],
                'max_players' => (int)($server['max_players'] ?? 32),
                'slots' => (int)($server['max_players'] ?? 32),
                'ram' => (int)($server['ram_mb'] ?? 2048),
                'ram_mb' => (int)($server['ram_mb'] ?? 2048),
                'start_map' => $server['start_map_or_world'] ?? 'de_dust2',
                'start_map_or_world' => $server['start_map_or_world'] ?? 'de_dust2'
            ]);
            $msg = 'Server restarted.';
        }
    } elseif (!empty($_POST['rcon_cmd'])) {
        callSpringsAdmin($server, '/api/servers/command', ['command' => trim($_POST['rcon_cmd'])]);
        $msg = 'Command sent.';
    }
}

$status = callSpringsAdmin($server, '/api/servers/status');
$isRunning = !empty($status['running']);
$console = callSpringsAdmin($server, '/api/servers/console', ['lines' => 45]);
$logText = $console['log'] ?? 'No console output available.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Console - <?php echo htmlspecialchars($server['name']); ?></title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
    <style>
        .container { max-width: 1180px; margin: 24px auto; padding: 0 18px; }
        .console-box { background:#0d1117; color:#c9d1d9; padding:14px; border-radius:4px; font-family:Consolas, monospace; font-size:13px; white-space:pre-wrap; max-height:420px; overflow-y:auto; border:1px solid #30363d; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#e74c3c;">ADMIN CONTROL</small></div>
        <div class="user-info">Admin: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> | <a href="login.php?logout=1">Logout</a></div>
    </div>
    <div id="navbar">
        <ul>
            <li><a href="nodes.php">Remote Nodes</a></li>
            <li><a href="client_servers.php" class="active">Client Servers</a></li>
            <li><a href="create_server.php">Create Server</a></li>
            <li><a href="users.php">Users</a></li>
            <li><a href="orders.php">Orders</a></li>
            <li><a href="tickets.php">Support Tickets</a></li>
            <li><a href="smtp.php">SMTP / Email</a></li>
        </ul>
    </div>
    <div class="container">
        <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
        <div class="box">
            <div class="box-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span>Server: <strong><?php echo htmlspecialchars($server['name']); ?></strong> (Owner: <?php echo htmlspecialchars($server['owner_name']); ?> | <?php echo htmlspecialchars($server['ip_address'] . ':' . $server['port']); ?>)</span>
                <a href="client_servers.php" class="btn" style="padding:4px 10px;font-size:12px;">&larr; All Client Servers</a>
            </div>
            <div class="box-content">
                <p>Status: <strong style="color:<?php echo $isRunning ? '#2ecc71' : '#e74c3c'; ?>;"><?php echo $isRunning ? 'ONLINE' : 'OFFLINE'; ?></strong></p>
                <form method="POST" style="display:flex;gap:8px;margin-bottom:14px;">
                    <button type="submit" name="action" value="start" class="btn btn-green">Start</button>
                    <button type="submit" name="action" value="restart" class="btn">Restart</button>
                    <button type="submit" name="action" value="stop" class="btn btn-red">Stop</button>
                </form>
                <div class="console-box"><?php echo htmlspecialchars($logText); ?></div>
                <form method="POST" style="display:flex;gap:8px;margin-top:10px;">
                    <input type="text" name="rcon_cmd" placeholder="Enter server console command..." style="flex:1;padding:8px;">
                    <button type="submit" class="btn btn-green">Send Command</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
