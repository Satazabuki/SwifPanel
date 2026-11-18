<?php
require_once __DIR__ . '/guard.php';
require_once '../config.php';

$notice = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_server'], $_POST['server_id'])) {
    $editId = (int)$_POST['server_id'];
    $name = trim($_POST['name'] ?? '');
    $ipAddress = trim($_POST['ip_address'] ?? '');
    $port = (int)($_POST['port'] ?? 27015);
    $maxPlayers = max(1, (int)($_POST['max_players'] ?? 32));
    $ramMb = max(128, (int)($_POST['ram_mb'] ?? 2048));
    $startMap = trim($_POST['start_map_or_world'] ?? '');
    $userId = (int)($_POST['user_id'] ?? 0);
    $restartAfter = !empty($_POST['restart_after']);

    $sStmt = $pdo->prepare("
        SELECT s.*, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token
        FROM servers s
        LEFT JOIN boxes b ON s.box_id = b.id
        WHERE s.id = ?
    ");
    $sStmt->execute([$editId]);
    $srv = $sStmt->fetch();

    if (!$srv) {
        $errorMsg = "Server #{$editId} not found.";
    } elseif ($name === '' || !filter_var($ipAddress, FILTER_VALIDATE_IP) || $port < 1024 || $port > 65535) {
        $errorMsg = "Invalid server name, IP address, or port (must be 1024-65535).";
    } else {
        $chkStmt = $pdo->prepare("SELECT id, name FROM servers WHERE ip_address = ? AND port = ? AND id != ?");
        $chkStmt->execute([$ipAddress, $port, $editId]);
        $conflict = $chkStmt->fetch();

        if ($conflict) {
            $errorMsg = "Port {$port} on {$ipAddress} is already used by Server #{$conflict['id']} ({$conflict['name']}).";
        } else {
            $newOwner = $userId > 0 ? $userId : (int)$srv['user_id'];
            $upd = $pdo->prepare("
                UPDATE servers
                SET name = ?, user_id = ?, ip_address = ?, port = ?, max_players = ?, ram_mb = ?, start_map_or_world = ?
                WHERE id = ?
            ");
            $upd->execute([$name, $newOwner, $ipAddress, $port, $maxPlayers, $ramMb, $startMap, $editId]);
            $notice = "Server #{$editId} ({$name}) settings updated.";

            if ($restartAfter && !empty($srv['node_ip'])) {
                $apiPort = (int)($srv['api_port'] ?: 8085);
                $baseUrl = "http://{$srv['node_ip']}:{$apiPort}";
                $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . ($srv['api_token'] ?? '')];

                $ch = curl_init($baseUrl . '/api/servers/stop');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['screen_name' => $srv['screen_name'], 'install_path' => $srv['install_path']]),
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_TIMEOUT => 10
                ]);
                curl_exec($ch);
                curl_close($ch);

                sleep(1);

                $ch = curl_init($baseUrl . '/api/servers/start');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode([
                        'screen_name' => $srv['screen_name'],
                        'install_path' => $srv['install_path'],
                        'game' => $srv['game'],
                        'ip_address' => $ipAddress,
                        'port' => $port,
                        'max_players' => $maxPlayers,
                        'slots' => $maxPlayers,
                        'ram' => $ramMb,
                        'ram_mb' => $ramMb,
                        'start_map' => $startMap,
                        'start_map_or_world' => $startMap
                    ]),
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_TIMEOUT => 10
                ]);
                curl_exec($ch);
                curl_close($ch);
                $notice .= " Restart signal sent to apply changes.";
            }
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_server'], $_POST['server_id'])) {
    $delId = (int)$_POST['server_id'];
    $wipeFiles = !empty($_POST['wipe_files']);

    $sStmt = $pdo->prepare("
        SELECT s.*, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token
        FROM servers s
        LEFT JOIN boxes b ON s.box_id = b.id
        WHERE s.id = ?
    ");
    $sStmt->execute([$delId]);
    $delSrv = $sStmt->fetch();

    if ($delSrv) {
        if (!empty($delSrv['node_ip'])) {
            $port = (int)($delSrv['api_port'] ?: 8085);
            $baseUrl = "http://{$delSrv['node_ip']}:{$port}";
            $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . ($delSrv['api_token'] ?? '')];
            $payload = json_encode([
                'id' => $delSrv['id'],
                'screen_name' => $delSrv['screen_name'] ?? '',
                'game' => $delSrv['game'],
                'install_path' => $delSrv['install_path']
            ]);

            $endpoints = $wipeFiles ? ['/api/servers/stop', '/api/servers/wipe'] : ['/api/servers/stop'];
            foreach ($endpoints as $ep) {
                $ch = curl_init($baseUrl . $ep);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_TIMEOUT => 10
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        }

        $pdo->prepare("DELETE FROM servers WHERE id = ?")->execute([$delId]);
        $notice = "Server #{$delId} ({$delSrv['name']}) deleted" . ($wipeFiles ? " and volume files wiped." : ".");
    } else {
        $errorMsg = "Server #{$delId} not found.";
    }
}

$showAll = isset($_GET['all']) && $_GET['all'] === '1';
$whereClause = $showAll ? "" : "WHERE (u.role IS NULL OR u.role != 'admin')";

$servers = $pdo->query("
    SELECT s.*, b.name AS box_name, b.ip_address AS node_primary_ip, b.extra_ips AS node_extra_ips, u.username AS owner_name, u.role AS owner_role
    FROM servers s
    LEFT JOIN boxes b ON s.box_id = b.id
    LEFT JOIN users u ON s.user_id = u.id
    {$whereClause}
    ORDER BY s.id DESC
")->fetchAll();

$allUsers = $pdo->query("SELECT id, username, role FROM users ORDER BY username ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Client Servers - Admin Panel</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
    <style>
        .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.72); z-index: 9999; align-items: center; justify-content: center; }
        .modal-backdrop.open { display: flex; }
        .modal-card { background: #161b22; border: 1px solid #30363d; border-radius: 8px; width: 100%; max-width: 480px; padding: 20px; box-shadow: 0 12px 32px rgba(0,0,0,0.6); }
        .modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .modal-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
        .modal-field label { font-size: 12px; color: #94a3b8; }
        .modal-field input, .modal-field select { padding: 7px 9px; background: #0d1117; border: 1px solid #30363d; border-radius: 5px; color: #f1f5f9; font-size: 13px; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#e74c3c;">ADMIN CONTROL</small></div>
        <div class="user-info">
            Logged in as: <strong><?php echo htmlspecialchars($_SESSION["username"] ?? "admin"); ?></strong> |
            <a href="login.php?logout=1">Logout</a>
        </div>
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
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($errorMsg): ?><div class="alert alert-error"><?php echo htmlspecialchars($errorMsg); ?></div><?php endif; ?>

        <div class="box">
            <div class="box-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span><?php echo $showAll ? 'All Deployed Servers' : 'Client Game Servers'; ?> (<?php echo count($servers); ?>)</span>
                <div style="display:flex;gap:8px;align-items:center;">
                    <?php if ($showAll): ?>
                        <a href="client_servers.php" class="btn" style="padding:3px 9px;font-size:11px;">Show Clients Only</a>
                    <?php else: ?>
                        <a href="client_servers.php?all=1" class="btn" style="padding:3px 9px;font-size:11px;">Show Admin Servers Too</a>
                    <?php endif; ?>
                    <a href="create_server.php" class="btn btn-green" style="padding:3px 10px;font-size:11px;">+ Deploy Server</a>
                </div>
            </div>
            <div class="box-content">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Server Name</th>
                            <th>Client (Owner)</th>
                            <th>Game</th>
                            <th>Node</th>
                            <th>Endpoint</th>
                            <th>Slots / RAM</th>
                            <th>Start Map</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($servers)): ?>
                            <tr><td colspan="9" style="text-align:center;color:#888;">No client servers found.</td></tr>
                        <?php else: foreach ($servers as $srv): ?>
                            <tr>
                                <td>#<?php echo (int)$srv['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($srv['name']); ?></strong><br>
                                    <small style="color:#64748b;"><?php echo htmlspecialchars($srv['install_path']); ?></small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($srv['owner_name'] ?? ('User #' . $srv['user_id'])); ?>
                                    <?php if (!empty($srv['owner_role'])): ?>
                                        <small style="color:#888;">(<?php echo htmlspecialchars($srv['owner_role']); ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo strtoupper(htmlspecialchars($srv['game'])); ?></td>
                                <td><?php echo htmlspecialchars($srv['box_name'] ?? ('Node #' . $srv['box_id'])); ?></td>
                                <td><code><?php echo htmlspecialchars($srv['ip_address'] . ':' . $srv['port']); ?></code></td>
                                <td>
                                    <span><?php echo (int)($srv['max_players'] ?? 32); ?> slots</span><br>
                                    <small style="color:#94a3b8;"><?php echo (int)($srv['ram_mb'] ?? 2048); ?> MB</small>
                                </td>
                                <td><code><?php echo htmlspecialchars($srv['start_map_or_world'] ?? '-'); ?></code></td>
                                <td>
                                    <button type="button" class="btn btn-blue" style="padding:3px 8px;font-size:11px;"
                                        onclick='openEditModal(<?php echo json_encode([
                                            "id" => (int)$srv["id"],
                                            "name" => $srv["name"],
                                            "user_id" => (int)$srv["user_id"],
                                            "ip_address" => $srv["ip_address"],
                                            "port" => (int)$srv["port"],
                                            "max_players" => (int)($srv["max_players"] ?? 32),
                                            "ram_mb" => (int)($srv["ram_mb"] ?? 2048),
                                            "start_map_or_world" => $srv["start_map_or_world"] ?? "",
                                            "node_primary_ip" => $srv["node_primary_ip"] ?? $srv["ip_address"],
                                            "node_extra_ips" => $srv["node_extra_ips"] ?? ""
                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                        Edit
                                    </button>
                                    <a href="manage_server.php?id=<?php echo (int)$srv['id']; ?>" class="btn" style="padding:3px 8px;font-size:11px;">Console</a>
                                    <a href="../files.php?id=<?php echo (int)$srv['id']; ?>" class="btn" style="padding:3px 8px;font-size:11px;">Files</a>
                                    <form method="POST" style="display:inline-flex;align-items:center;gap:5px;margin-left:6px;" onsubmit="return confirm('Delete server #<?php echo (int)$srv['id']; ?> permanently?');">
                                        <input type="hidden" name="server_id" value="<?php echo (int)$srv['id']; ?>">
                                        <label style="font-size:11px;color:#ccc;display:inline-flex;align-items:center;gap:3px;cursor:pointer;">
                                            <input type="checkbox" name="wipe_files" value="1"> Wipe files
                                        </label>
                                        <button type="submit" name="delete_server" value="1" class="btn btn-red" style="padding:3px 8px;font-size:11px;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div id="editServerModal" class="modal-backdrop" onclick="if(event.target===this) closeEditModal();">
        <div class="modal-card">
            <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #30363d;padding-bottom:10px;margin-bottom:14px;">
                <strong style="font-size:15px;color:#fff;">Edit Server <span id="editModalTitleId" style="color:var(--neon);"></span></strong>
                <button type="button" onclick="closeEditModal()" style="background:none;border:none;color:#94a3b8;font-size:18px;cursor:pointer;">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="edit_server" value="1">
                <input type="hidden" name="server_id" id="edit_server_id">

                <div class="modal-field">
                    <label>Server Display Name</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>

                <div class="modal-field">
                    <label>Assigned Client (Owner)</label>
                    <select name="user_id" id="edit_user_id">
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>">
                                <?php echo htmlspecialchars($u['username'] . ' (' . $u['role'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="modal-grid">
                    <div class="modal-field">
                        <label>IP Address</label>
                        <input type="text" name="ip_address" id="edit_ip_address" list="node_ip_suggestions" required>
                        <datalist id="node_ip_suggestions"></datalist>
                    </div>
                    <div class="modal-field">
                        <label>Game Port</label>
                        <input type="number" name="port" id="edit_port" min="1024" max="65535" required>
                    </div>
                </div>

                <div class="modal-grid">
                    <div class="modal-field">
                        <label>Max Players (Slots)</label>
                        <input type="number" name="max_players" id="edit_max_players" min="1" max="512" required>
                    </div>
                    <div class="modal-field">
                        <label>RAM Allocation (MB)</label>
                        <input type="number" name="ram_mb" id="edit_ram_mb" min="128" step="128" required>
                    </div>
                </div>

                <div class="modal-field">
                    <label>Default Start Map / World</label>
                    <input type="text" name="start_map_or_world" id="edit_start_map" placeholder="e.g. de_dust2 or world">
                </div>

                <div style="margin-bottom:14px;">
                    <label style="font-size:12px;color:#cbd5e1;display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="restart_after" value="1" checked>
                        Restart server immediately to apply new port/slots/RAM
                    </label>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" class="btn" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-green">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div id="footer">Powered by Standalone SwiftPanel &bull; Springs Docker Engine</div>

    <script>
    function openEditModal(srv) {
        document.getElementById('editModalTitleId').textContent = '#' + srv.id;
        document.getElementById('edit_server_id').value = srv.id;
        document.getElementById('edit_name').value = srv.name;
        document.getElementById('edit_user_id').value = srv.user_id;
        document.getElementById('edit_ip_address').value = srv.ip_address;
        document.getElementById('edit_port').value = srv.port;
        document.getElementById('edit_max_players').value = srv.max_players;
        document.getElementById('edit_ram_mb').value = srv.ram_mb;
        document.getElementById('edit_start_map').value = srv.start_map_or_world;

        const dl = document.getElementById('node_ip_suggestions');
        dl.innerHTML = '';
        const ips = new Set();
        if (srv.node_primary_ip) ips.add(srv.node_primary_ip.trim());
        if (srv.ip_address) ips.add(srv.ip_address.trim());
        if (srv.node_extra_ips) {
            srv.node_extra_ips.split(/[\s,]+/).forEach(ip => {
                if (ip.trim()) ips.add(ip.trim());
            });
        }
        ips.forEach(ip => {
            const opt = document.createElement('option');
            opt.value = ip;
            dl.appendChild(opt);
        });

        document.getElementById('editServerModal').classList.add('open');
    }

    function closeEditModal() {
        document.getElementById('editServerModal').classList.remove('open');
    }
    </script>
</body>
</html>
