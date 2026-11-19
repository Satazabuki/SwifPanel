<?php
require_once __DIR__ . '/guard.php';
require_once '../config.php';
require_once '../games.php';

$notice = '';
$errorMsg = '';

if (empty($_SESSION['cs_csrf'])) {
    $_SESSION['cs_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['cs_csrf'];
$csrfOk = hash_equals($csrf, (string)($_POST['csrf'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['edit_server']) || isset($_POST['delete_server'])) && !$csrfOk) {
    unset($_POST['edit_server'], $_POST['delete_server']);
    $errorMsg = 'Security check failed. Please reload the page and try again.';
}

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

            $endpoints = $wipeFiles ? ['/api/servers/stop', '/api/servers/wipe', '/api/backups/purge'] : ['/api/servers/stop'];   // wiping also removes the server's backups
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

$catalog = function_exists('swiftGames') ? swiftGames() : [];
$totalSlots = 0;
$totalRam = 0;
$owners = [];
$gamesPresent = [];
$nodesPresent = [];
foreach ($servers as $s_) {
    $totalSlots += (int)($s_['max_players'] ?? 0);
    $totalRam += (int)($s_['ram_mb'] ?? 0);
    $owners[(int)$s_['user_id']] = true;
    $gamesPresent[$s_['game']] = ($gamesPresent[$s_['game']] ?? 0) + 1;
    $nodesPresent[$s_['box_name'] ?? ('Node #' . $s_['box_id'])] = true;
}
ksort($nodesPresent);
$gameLabel = function ($g) use ($catalog) {
    return $catalog[$g]['short'] ?? strtoupper((string)$g);
};
$gameColors = function ($g) use ($catalog) {
    $c = $catalog[$g]['color'] ?? ['#3b82f6', '#1d4ed8'];
    return [preg_match('/^#[0-9a-fA-F]{3,8}$/', $c[0]) ? $c[0] : '#3b82f6', preg_match('/^#[0-9a-fA-F]{3,8}$/', $c[1]) ? $c[1] : '#1d4ed8'];
};
$fmtRam = function ($mb) {
    return $mb >= 1024 ? rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.') . ' GB' : $mb . ' MB';
};
?>
<?php require_once dirname(__DIR__) . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Client Servers - Admin Panel</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
    <style>
        .cs-wrap { max-width: 1380px; margin: 22px auto; padding: 0 18px; }
        .cs-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
        .cs-head h1 { font-size: 22px; font-weight: 700; color: var(--text-primary); letter-spacing: -.01em; }
        .cs-head p { font-size: 13px; color: var(--text-secondary); margin-top: 3px; }
        .cs-head .cs-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .cs-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
        .cs-stat { background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 12px; padding: 14px 16px; }
        .cs-stat .k { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); }
        .cs-stat .v { font-size: 26px; font-weight: 700; color: var(--text-primary); margin-top: 4px; font-variant-numeric: tabular-nums; }
        .cs-stat .s { font-size: 12px; color: var(--text-secondary); margin-top: 2px; }
        .cs-card { background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 14px; overflow: hidden; }
        .cs-bar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; padding: 12px 14px; border-bottom: 1px solid var(--border-subtle); background: var(--bg-card-alt); }
        .cs-search { flex: 1 1 260px; position: relative; }
        .cs-search input { width: 100%; padding: 9px 12px 9px 34px; background: var(--bg-input); border: 1px solid var(--border-subtle); border-radius: 9px; color: var(--text-primary); font-size: 13px; outline: none; }
        .cs-search input:focus, .cs-sel:focus { border-color: var(--neon); box-shadow: 0 0 0 3px var(--neon-dim); }
        .cs-search svg { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--text-muted); }
        .cs-sel { padding: 9px 10px; background: var(--bg-input); border: 1px solid var(--border-subtle); border-radius: 9px; color: var(--text-primary); font-size: 13px; outline: none; }
        .cs-count { font-size: 12px; color: var(--text-muted); margin-left: auto; white-space: nowrap; }
        .cs-scroll { overflow-x: auto; }
        table.cs-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .cs-table th { text-align: left; padding: 11px 14px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); font-weight: 600; border-bottom: 1px solid var(--border-subtle); white-space: nowrap; }
        .cs-table td { padding: 13px 14px; border-bottom: 1px solid var(--border-subtle); vertical-align: middle; color: var(--text-primary); }
        .cs-table tbody tr { transition: background .12s; }
        .cs-table tbody tr:hover { background: rgba(255,255,255,.025); }
        .cs-table tbody tr:last-child td { border-bottom: 0; }
        .cs-name { font-weight: 600; }
        .cs-sub { display: block; font-size: 11px; color: var(--text-muted); margin-top: 2px; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cs-id { display: inline-block; font-size: 11px; color: var(--text-muted); margin-right: 6px; font-variant-numeric: tabular-nums; }
        .cs-owner { display: flex; align-items: center; gap: 9px; }
        .cs-av { width: 28px; height: 28px; border-radius: 50%; background: var(--neon-dim); color: var(--neon); display: grid; place-items: center; font-size: 12px; font-weight: 700; flex: none; text-transform: uppercase; }
        .cs-role { font-size: 10px; padding: 1px 7px; border-radius: 999px; background: rgba(148,163,184,.15); color: var(--text-secondary); margin-left: 6px; }
        .cs-role.admin { background: var(--danger-dim); color: var(--danger); }
        .cs-game { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; color: #fff; letter-spacing: .02em; white-space: nowrap; }
        .cs-ep { display: inline-flex; align-items: center; gap: 6px; }
        .cs-copy { background: none; border: 1px solid var(--border-subtle); color: var(--text-muted); border-radius: 6px; width: 24px; height: 24px; cursor: pointer; display: grid; place-items: center; padding: 0; }
        .cs-copy:hover { color: var(--neon); border-color: var(--neon); }
        .cs-copy svg { width: 13px; height: 13px; }
        .cs-res b { font-variant-numeric: tabular-nums; }
        .cs-res span { display: block; font-size: 11px; color: var(--text-muted); }
        .cs-acts { display: flex; gap: 6px; justify-content: flex-end; white-space: nowrap; }
        .cs-acts .btn { padding: 5px 11px; font-size: 12px; border-radius: 8px; }
        .cs-empty { text-align: center; padding: 46px 16px; color: var(--text-muted); }
        .cs-empty strong { display: block; color: var(--text-secondary); font-size: 15px; margin-bottom: 4px; }
        .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(2,6,12,.74); backdrop-filter: blur(3px); z-index: 9999; align-items: center; justify-content: center; padding: 16px; }
        .modal-backdrop.open { display: flex; }
        .modal-card { background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 16px; width: 100%; max-width: 520px; padding: 22px; box-shadow: 0 24px 60px rgba(0,0,0,.6); max-height: 92vh; overflow-y: auto; animation: csIn .14s ease-out; }
        @keyframes csIn { from { opacity: 0; transform: translateY(8px) scale(.985); } to { opacity: 1; transform: none; } }
        .modal-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .modal-top strong { font-size: 16px; color: var(--text-primary); }
        .modal-x { background: none; border: 0; color: var(--text-muted); font-size: 22px; line-height: 1; cursor: pointer; border-radius: 8px; width: 30px; height: 30px; }
        .modal-x:hover { background: rgba(255,255,255,.06); color: var(--text-primary); }
        .modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .modal-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 13px; }
        .modal-field label { font-size: 12px; color: var(--text-secondary); }
        .modal-field input, .modal-field select { padding: 9px 11px; background: var(--bg-input); border: 1px solid var(--border-subtle); border-radius: 9px; color: var(--text-primary); font-size: 13px; outline: none; }
        .modal-field input:focus, .modal-field select:focus { border-color: var(--neon); box-shadow: 0 0 0 3px var(--neon-dim); }
        .modal-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 6px; }
        .cs-check { display: flex; gap: 8px; align-items: flex-start; font-size: 13px; color: var(--text-secondary); cursor: pointer; margin: 4px 0 14px; }
        .cs-warn { background: var(--danger-dim); border: 1px solid rgba(239,68,68,.35); color: #fca5a5; border-radius: 10px; padding: 10px 12px; font-size: 13px; margin-bottom: 14px; }
        @media (max-width: 900px) { .cs-stats { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 520px) { .cs-stats { grid-template-columns: 1fr 1fr; } .modal-grid { grid-template-columns: 1fr; } .cs-stat .v { font-size: 22px; } }
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
    <?php $adminNavActive = 'client_servers'; include __DIR__ . '/nav.php'; ?>

    <div class="cs-wrap">
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($errorMsg): ?><div class="alert alert-error"><?php echo htmlspecialchars($errorMsg); ?></div><?php endif; ?>

        <div class="cs-head">
            <div>
                <h1><?php echo $showAll ? 'All Deployed Servers' : 'Client Servers'; ?></h1>
                <p>Manage, edit and monitor every game server on your nodes.</p>
            </div>
            <div class="cs-actions">
                <?php if ($showAll): ?>
                    <a href="client_servers.php" class="btn">Clients only</a>
                <?php else: ?>
                    <a href="client_servers.php?all=1" class="btn">Include admin servers</a>
                <?php endif; ?>
                <a href="create_server.php" class="btn btn-green">+ Deploy server</a>
            </div>
        </div>

        <div class="cs-stats">
            <div class="cs-stat"><div class="k">Servers</div><div class="v"><?php echo count($servers); ?></div><div class="s"><?php echo count($gamesPresent); ?> game<?php echo count($gamesPresent) === 1 ? '' : 's'; ?> on <?php echo count($nodesPresent); ?> node<?php echo count($nodesPresent) === 1 ? '' : 's'; ?></div></div>
            <div class="cs-stat"><div class="k">Clients</div><div class="v"><?php echo count($owners); ?></div><div class="s">with at least one server</div></div>
            <div class="cs-stat"><div class="k">Player slots</div><div class="v"><?php echo number_format($totalSlots); ?></div><div class="s">across all servers</div></div>
            <div class="cs-stat"><div class="k">RAM allocated</div><div class="v"><?php echo htmlspecialchars($fmtRam($totalRam)); ?></div><div class="s">sum of all allocations</div></div>
        </div>

        <div class="cs-card">
            <div class="cs-bar">
                <div class="cs-search">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="9" cy="9" r="6"/><path d="M14 14l4 4"/></svg>
                    <input type="search" id="csFilter" placeholder="Search name, client, IP, port, path..." autocomplete="off">
                </div>
                <select id="csGame" class="cs-sel" aria-label="Filter by game">
                    <option value="">All games</option>
                    <?php foreach ($gamesPresent as $g => $n): ?>
                        <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($gameLabel($g)); ?> (<?php echo (int)$n; ?>)</option>
                    <?php endforeach; ?>
                </select>
                <select id="csNode" class="cs-sel" aria-label="Filter by node">
                    <option value="">All nodes</option>
                    <?php foreach (array_keys($nodesPresent) as $nn): ?>
                        <option value="<?php echo htmlspecialchars($nn); ?>"><?php echo htmlspecialchars($nn); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="cs-count" id="csCount"></span>
            </div>

            <div class="cs-scroll">
                <table class="cs-table">
                    <thead>
                        <tr>
                            <th>Server</th>
                            <th>Client</th>
                            <th>Game</th>
                            <th>Node</th>
                            <th>Endpoint</th>
                            <th>Resources</th>
                            <th>Start map</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="csBody">
                        <?php foreach ($servers as $srv):
                            $sid = (int)$srv['id'];
                            $owner = (string)($srv['owner_name'] ?? ('User #' . $srv['user_id']));
                            $nodeName = (string)($srv['box_name'] ?? ('Node #' . $srv['box_id']));
                            $endpoint = $srv['ip_address'] . ':' . $srv['port'];
                            [$c1, $c2] = $gameColors($srv['game']);
                            $hay = strtolower($srv['name'] . ' ' . $owner . ' ' . $endpoint . ' ' . $srv['install_path'] . ' #' . $sid . ' ' . $srv['game'] . ' ' . $nodeName);
                        ?>
                        <tr data-hay="<?php echo htmlspecialchars($hay); ?>" data-game="<?php echo htmlspecialchars($srv['game']); ?>" data-node="<?php echo htmlspecialchars($nodeName); ?>">
                            <td>
                                <span class="cs-id">#<?php echo $sid; ?></span><span class="cs-name"><?php echo htmlspecialchars($srv['name']); ?></span>
                                <span class="cs-sub" title="<?php echo htmlspecialchars($srv['install_path']); ?>"><?php echo htmlspecialchars($srv['install_path']); ?></span>
                            </td>
                            <td>
                                <div class="cs-owner">
                                    <span class="cs-av"><?php echo htmlspecialchars(mb_substr($owner, 0, 1)); ?></span>
                                    <span><?php echo htmlspecialchars($owner); ?><?php if (!empty($srv['owner_role'])): ?><span class="cs-role <?php echo $srv['owner_role'] === 'admin' ? 'admin' : ''; ?>"><?php echo htmlspecialchars($srv['owner_role']); ?></span><?php endif; ?></span>
                                </div>
                            </td>
                            <td><span class="cs-game" style="background:linear-gradient(135deg,<?php echo $c1; ?>,<?php echo $c2; ?>);"><?php echo htmlspecialchars($gameLabel($srv['game'])); ?></span></td>
                            <td><?php echo htmlspecialchars($nodeName); ?></td>
                            <td>
                                <span class="cs-ep"><code><?php echo htmlspecialchars($endpoint); ?></code>
                                <button type="button" class="cs-copy" title="Copy address" data-copy="<?php echo htmlspecialchars($endpoint); ?>"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="7" width="10" height="10" rx="2"/><path d="M13 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/></svg></button></span>
                            </td>
                            <td class="cs-res"><b><?php echo (int)($srv['max_players'] ?? 32); ?></b> slots<span><?php echo htmlspecialchars($fmtRam((int)($srv['ram_mb'] ?? 2048))); ?> RAM</span></td>
                            <td><code><?php echo htmlspecialchars($srv['start_map_or_world'] ?? '-'); ?></code></td>
                            <td>
                                <div class="cs-acts">
                                    <button type="button" class="btn btn-blue"
                                        onclick='openEditModal(<?php echo json_encode([
                                            "id" => $sid,
                                            "name" => $srv["name"],
                                            "user_id" => (int)$srv["user_id"],
                                            "ip_address" => $srv["ip_address"],
                                            "port" => (int)$srv["port"],
                                            "max_players" => (int)($srv["max_players"] ?? 32),
                                            "ram_mb" => (int)($srv["ram_mb"] ?? 2048),
                                            "start_map_or_world" => $srv["start_map_or_world"] ?? "",
                                            "node_primary_ip" => $srv["node_primary_ip"] ?? $srv["ip_address"],
                                            "node_extra_ips" => $srv["node_extra_ips"] ?? ""
                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>Edit</button>
                                    <a href="manage_server.php?id=<?php echo $sid; ?>" class="btn">Console</a>
                                    <a href="../files.php?id=<?php echo $sid; ?>" class="btn">Files</a>
                                    <button type="button" class="btn btn-red" onclick='openDeleteModal(<?php echo json_encode(["id" => $sid, "name" => $srv["name"]], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>Delete</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="cs-empty" id="csEmpty" style="<?php echo empty($servers) ? '' : 'display:none;'; ?>">
                    <strong><?php echo empty($servers) ? 'No client servers yet' : 'No servers match your filters'; ?></strong>
                    <?php echo empty($servers) ? 'Deploy the first one with the button at the top.' : 'Try a different search or clear the filters.'; ?>
                </div>
            </div>
        </div>
    </div>

    <div id="editServerModal" class="modal-backdrop" onclick="if(event.target===this) closeModals();">
        <div class="modal-card" role="dialog" aria-modal="true" aria-label="Edit server">
            <div class="modal-top">
                <strong>Edit server <span id="editModalTitleId" style="color:var(--neon);"></span></strong>
                <button type="button" class="modal-x" onclick="closeModals()" aria-label="Close">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="edit_server" value="1">
                <input type="hidden" name="server_id" id="edit_server_id">

                <div class="modal-field">
                    <label>Server display name</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>

                <div class="modal-field">
                    <label>Assigned client (owner)</label>
                    <select name="user_id" id="edit_user_id">
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['username'] . ' (' . $u['role'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="modal-grid">
                    <div class="modal-field">
                        <label>IP address</label>
                        <input type="text" name="ip_address" id="edit_ip_address" list="node_ip_suggestions" required>
                        <datalist id="node_ip_suggestions"></datalist>
                    </div>
                    <div class="modal-field">
                        <label>Game port</label>
                        <input type="number" name="port" id="edit_port" min="1024" max="65535" required>
                    </div>
                </div>

                <div class="modal-grid">
                    <div class="modal-field">
                        <label>Max players (slots)</label>
                        <input type="number" name="max_players" id="edit_max_players" min="1" max="512" required>
                    </div>
                    <div class="modal-field">
                        <label>RAM allocation (MB)</label>
                        <input type="number" name="ram_mb" id="edit_ram_mb" min="128" step="128" required>
                    </div>
                </div>

                <div class="modal-field">
                    <label>Default start map / world</label>
                    <input type="text" name="start_map_or_world" id="edit_start_map" placeholder="e.g. de_dust2 or world">
                </div>

                <label class="cs-check">
                    <input type="checkbox" name="restart_after" value="1" checked>
                    <span>Restart the server now to apply the new port, slots and RAM</span>
                </label>

                <div class="modal-foot">
                    <button type="button" class="btn" onclick="closeModals()">Cancel</button>
                    <button type="submit" class="btn btn-green">Save changes</button>
                </div>
            </form>
        </div>
    </div>

    <div id="deleteServerModal" class="modal-backdrop" onclick="if(event.target===this) closeModals();">
        <div class="modal-card" style="max-width:460px;" role="dialog" aria-modal="true" aria-label="Delete server">
            <div class="modal-top">
                <strong>Delete server <span id="delModalTitle" style="color:var(--danger);"></span></strong>
                <button type="button" class="modal-x" onclick="closeModals()" aria-label="Close">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="server_id" id="del_server_id">
                <div class="cs-warn">This stops the server and removes it from the panel. The client loses access immediately.</div>
                <label class="cs-check">
                    <input type="checkbox" name="wipe_files" value="1">
                    <span>Also wipe all server files and backups from the node (cannot be undone)</span>
                </label>
                <div class="modal-foot">
                    <button type="button" class="btn" onclick="closeModals()">Cancel</button>
                    <button type="submit" name="delete_server" value="1" class="btn btn-red">Delete server</button>
                </div>
            </form>
        </div>
    </div>

    <div id="footer">Powered by Standalone SwiftPanel &bull; Springs Docker Engine</div>

    <script>
    function $(id) { return document.getElementById(id); }
    function closeModals() {
        document.querySelectorAll('.modal-backdrop.open').forEach(function (m) { m.classList.remove('open'); });
    }
    function openEditModal(srv) {
        $('editModalTitleId').textContent = '#' + srv.id;
        $('edit_server_id').value = srv.id;
        $('edit_name').value = srv.name;
        $('edit_user_id').value = srv.user_id;
        $('edit_ip_address').value = srv.ip_address;
        $('edit_port').value = srv.port;
        $('edit_max_players').value = srv.max_players;
        $('edit_ram_mb').value = srv.ram_mb;
        $('edit_start_map').value = srv.start_map_or_world;

        var dl = $('node_ip_suggestions');
        dl.innerHTML = '';
        var ips = new Set();
        if (srv.node_primary_ip) ips.add(srv.node_primary_ip.trim());
        if (srv.ip_address) ips.add(srv.ip_address.trim());
        if (srv.node_extra_ips) {
            srv.node_extra_ips.split(/[\s,]+/).forEach(function (ip) { if (ip.trim()) ips.add(ip.trim()); });
        }
        ips.forEach(function (ip) {
            var opt = document.createElement('option');
            opt.value = ip;
            dl.appendChild(opt);
        });
        $('editServerModal').classList.add('open');
        setTimeout(function () { $('edit_name').focus(); }, 30);
    }
    function openDeleteModal(srv) {
        $('delModalTitle').textContent = '#' + srv.id + ' ' + srv.name;
        $('del_server_id').value = srv.id;
        $('deleteServerModal').classList.add('open');
    }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModals(); });

    // live filter: search text + game + node
    (function () {
        var rows = Array.prototype.slice.call(document.querySelectorAll('#csBody tr'));
        var q = $('csFilter'), g = $('csGame'), n = $('csNode'), count = $('csCount'), empty = $('csEmpty');
        function apply() {
            var term = q.value.trim().toLowerCase(), shown = 0;
            rows.forEach(function (r) {
                var ok = (!term || r.dataset.hay.indexOf(term) !== -1) && (!g.value || r.dataset.game === g.value) && (!n.value || r.dataset.node === n.value);
                r.style.display = ok ? '' : 'none';
                if (ok) shown++;
            });
            count.textContent = rows.length ? 'Showing ' + shown + ' of ' + rows.length : '';
            if (rows.length) { empty.style.display = shown ? 'none' : ''; }
        }
        q.addEventListener('input', apply); g.addEventListener('change', apply); n.addEventListener('change', apply);
        apply();
    })();

    // copy address
    document.querySelectorAll('.cs-copy').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = b.getAttribute('data-copy');
            function done() { var o = b.innerHTML; b.textContent = '✓'; b.style.color = 'var(--neon)'; setTimeout(function () { b.innerHTML = o; b.style.color = ''; }, 1000); }
            if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(done); return; }
            var ta = document.createElement('textarea'); ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (x) {}
            document.body.removeChild(ta);
        });
    });
    </script>
</body>
</html>
