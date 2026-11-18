<?php
require_once __DIR__ . '/guard.php';
require_once '../config.php';

require_once '../ServerManager.php';

$notice = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['server_id'], $_POST['action'])) {
    $sid = (int)$_POST['server_id'];
    $action = $_POST['action'];

    $stmt = $pdo->prepare("
        SELECT s.*, b.ip_address AS ssh_ip, b.ssh_port, b.ssh_user, b.ssh_password
        FROM servers s
        LEFT JOIN boxes b ON s.box_id = b.id
        WHERE s.id = ?
    ");
    $stmt->execute([$sid]);
    $srv = $stmt->fetch();

    if ($srv) {
        try {
            $mgr = new ServerManager();
            $mgr->connect($srv['ssh_ip'], $srv['ssh_port'], $srv['ssh_user'], $srv['ssh_password']);

            if ($action === 'start') {
                $notice = $mgr->startServer($srv);
            } elseif ($action === 'stop') {
                $notice = $mgr->stopServer($srv);
            } elseif ($action === 'restart') {
                $mgr->stopServer($srv);
                sleep(1);
                $notice = $mgr->startServer($srv);
            } elseif ($action === 'delete') {
                try {
                    $mgr->stopServer($srv);
                    if (!empty($_POST['wipe_files'])) {
                        $port = (int)($srv['ssh_port'] ?: 8085);
                        $ch = curl_init("http://{$srv['ssh_ip']}:{$port}/api/servers/wipe");
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => json_encode(["id" => $srv['id'], "game" => $srv['game'], "install_path" => $srv['install_path']]),
                            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Authorization: Bearer " . $srv['ssh_password']],
                            CURLOPT_TIMEOUT => 10
                        ]);
                        curl_exec($ch);
                        curl_close($ch);
                    }
                } catch (Exception $e) {}
                $pdo->prepare("DELETE FROM servers WHERE id = ?")->execute([$sid]);
                $notice = "Server #{$sid} ({$srv['name']}) removed from panel.";
            }
        } catch (Exception $e) {
            $errorMsg = "Springs Daemon Error: " . $e->getMessage();
        }
    }
}

$servers = $pdo->query("
    SELECT s.*, b.name AS box_name, u.username AS owner_name
    FROM servers s
    LEFT JOIN boxes b ON s.box_id = b.id
    LEFT JOIN users u ON s.user_id = u.id
    ORDER BY s.id DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>All Servers - Admin Panel</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="topbar">
    <div class="brand">SwiftPanel <span>Admin Area</span></div>
    <div class="nav">
        <a href="nodes.php">Nodes</a>
        <a href="servers.php" class="active">All Servers</a>
        <a href="create_server.php">Deploy Server</a>
        <a href="users.php">Users</a>
        <a href="login.php?logout=1" class="logout">Logout</a>
    </div>
</div>

<div class="container">
    <?php if ($notice): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($errorMsg); ?></div>
    <?php endif; ?>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <h2 style="margin:0;">All Hosted Game Servers (<?php echo count($servers); ?>)</h2>
            <a href="create_server.php" class="btn btn-green">+ Deploy New Server</a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Server Name</th>
                        <th>Owner</th>
                        <th>Game</th>
                        <th>Node</th>
                        <th>Endpoint</th>
                        <th>RAM</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($servers)): ?>
                    <tr><td colspan="8" style="text-align:center;opacity:0.7;">No game servers deployed yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($servers as $s): ?>
                    <tr>
                        <td>#<?php echo (int)$s['id']; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($s['name']); ?></strong><br>
                            <small style="font-size:11px;color:#e74c3c;">ADMIN CONTROL</small>
                        </td>
                        <td><?php echo htmlspecialchars($s['owner_name']); ?></td>
                        <td><span class="badge"><?php echo strtoupper(htmlspecialchars($s['game'])); ?></span></td>
                        <td><?php echo htmlspecialchars($s['box_name']); ?></td>
                        <td><code><?php echo htmlspecialchars($s['ip_address'] . ':' . $s['port']); ?></code></td>
                        <td><?php echo (int)$s['ram_mb']; ?> MB</td>
                        <td>
                            <form method="POST" style="display:inline-flex;gap:5px;align-items:center;flex-wrap:wrap;">
                                <input type="hidden" name="server_id" value="<?php echo (int)$s['id']; ?>">
                                <button type="submit" name="action" value="start" class="btn btn-green">Start</button>
                                <button type="submit" name="action" value="restart" class="btn">Restart</button>
                                <button type="submit" name="action" value="stop" class="btn btn-red">Stop</button>
                                <label style="font-size:11px;display:inline-flex;align-items:center;gap:3px;margin-left:4px;">
                                    <input type="checkbox" name="wipe_files" value="1"> Wipe files
                                </label>
                                <button type="submit" name="action" value="delete" class="btn btn-red" onclick="return confirm('Delete this server permanently?');">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
