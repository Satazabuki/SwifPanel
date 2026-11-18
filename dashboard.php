<?php
require_once 'auth.php';
require_once 'config.php';
require_once 'mailer.php';

$userId = (int)$_SESSION['user_id'];

$pdo->exec("CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    game VARCHAR(32) NOT NULL,
    server_name VARCHAR(120) NOT NULL,
    slots INT DEFAULT 32,
    ram_mb INT DEFAULT 2048,
    status ENUM('Pending','Approved','Cancelled') DEFAULT 'Pending',
    server_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    header('Content-Type: application/json');
    $srvId = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT s.screen_name, s.install_path, s.status AS db_status, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token FROM servers s JOIN boxes b ON s.box_id = b.id WHERE s.id = ? AND s.user_id = ?");
    $st->execute([$srvId, $userId]);
    $srv = $st->fetch();
    if (!$srv) {
        echo json_encode(['state' => 'offline']);
        exit;
    }
    $port = (int)($srv['api_port'] ?: 8085);
    $ch = curl_init("http://{$srv['node_ip']}:{$port}/api/servers/status");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['screen_name' => $srv['screen_name']]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $srv['api_token']],
        CURLOPT_TIMEOUT => 4
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp ?: '{}', true);
    if (!empty($data['running'])) {
        if ($srv['db_status'] === 'installing') {
            $pdo->prepare("UPDATE servers SET status = 'online' WHERE id = ?")->execute([$srvId]);
        }
        echo json_encode(['state' => 'online']);
        exit;
    }
    if ($srv['db_status'] === 'installing') {
        $c2 = curl_init("http://{$srv['node_ip']}:{$port}/api/servers/console");
        curl_setopt_array($c2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['screen_name' => $srv['screen_name'], 'install_path' => $srv['install_path'], 'lines' => 15]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $srv['api_token']],
            CURLOPT_TIMEOUT => 4
        ]);
        $logOut = json_decode(curl_exec($c2) ?: '{}', true)['log'] ?? '';
        curl_close($c2);
        if (strpos($logOut, '[Springs] Complete!') !== false || strpos($logOut, '[Springs] Ready!') !== false) {
            $pdo->prepare("UPDATE servers SET status = 'offline' WHERE id = ?")->execute([$srvId]);
            echo json_encode(['state' => 'offline']);
            exit;
        }
        echo json_encode(['state' => 'installing']);
        exit;
    }
    echo json_encode(['state' => 'offline']);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'power' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $srvId = (int)($_POST['id'] ?? 0);
    $act = $_POST['action'] ?? '';
    if (!in_array($act, ['start', 'stop', 'restart'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid action']);
        exit;
    }
    $st = $pdo->prepare("SELECT s.*, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token FROM servers s JOIN boxes b ON s.box_id = b.id WHERE s.id = ? AND s.user_id = ?");
    $st->execute([$srvId, $userId]);
    $srv = $st->fetch();
    if (!$srv) {
        echo json_encode(['ok' => false, 'error' => 'Server not found']);
        exit;
    }
    if ($srv['status'] === 'installing') {
        echo json_encode(['ok' => false, 'error' => 'Server is still installing']);
        exit;
    }
    session_write_close();
    $port = (int)($srv['api_port'] ?: 8085);
    $sendCall = function($endpoint, $extra = []) use ($srv, $port) {
        $payload = array_merge([
            'screen_name' => $srv['screen_name'],
            'install_path' => $srv['install_path']
        ], $extra);
        $ch = curl_init("http://{$srv['node_ip']}:{$port}{$endpoint}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $srv['api_token']],
            CURLOPT_TIMEOUT => 8
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    };
    $startPayload = [
        'game' => $srv['game'],
        'ip_address' => $srv['ip_address'],
        'port' => (int)$srv['port'],
        'max_players' => (int)($srv['max_players'] ?? 32),
        'start_map_or_world' => $srv['start_map_or_world'] ?? 'de_dust2',
        'ram_mb' => (int)($srv['ram_mb'] ?? 2048)
    ];
    if ($act === 'start') {
        $sendCall('/api/servers/start', $startPayload);
    } elseif ($act === 'stop') {
        $sendCall('/api/servers/stop');
    } elseif ($act === 'restart') {
        $sendCall('/api/servers/stop');
        sleep(1);
        $sendCall('/api/servers/start', $startPayload);
    }
    echo json_encode(['ok' => true]);
    exit;
}

$nodeLocations = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(location,''),'Europe') AS loc FROM boxes ORDER BY loc ASC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($nodeLocations)) if (isset($_GET['ajax']) && $_GET['ajax'] === 'power' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $srvId = (int)($_POST['id'] ?? 0);
    $act = $_POST['action'] ?? '';
    if (!in_array($act, ['start', 'stop', 'restart'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid action']);
        exit;
    }
    $st = $pdo->prepare("SELECT s.*, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token FROM servers s JOIN boxes b ON s.box_id = b.id WHERE s.id = ? AND s.user_id = ?");
    $st->execute([$srvId, $userId]);
    $srv = $st->fetch();
    if (!$srv) {
        echo json_encode(['ok' => false, 'error' => 'Server not found']);
        exit;
    }
    if ($srv['status'] === 'installing') {
        echo json_encode(['ok' => false, 'error' => 'Server is still installing']);
        exit;
    }
    session_write_close();
    $port = (int)($srv['api_port'] ?: 8085);
    $sendCall = function($endpoint, $extra = []) use ($srv, $port) {
        $payload = array_merge([
            'screen_name' => $srv['screen_name'],
            'install_path' => $srv['install_path']
        ], $extra);
        $ch = curl_init("http://{$srv['node_ip']}:{$port}{$endpoint}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $srv['api_token']],
            CURLOPT_TIMEOUT => 8
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    };
    $startPayload = [
        'game' => $srv['game'],
        'ip_address' => $srv['ip_address'],
        'port' => (int)$srv['port'],
        'max_players' => (int)($srv['max_players'] ?? 32),
        'start_map_or_world' => $srv['start_map_or_world'] ?? 'de_dust2',
        'ram_mb' => (int)($srv['ram_mb'] ?? 2048)
    ];
    if ($act === 'start') {
        $sendCall('/api/servers/start', $startPayload);
    } elseif ($act === 'stop') {
        $sendCall('/api/servers/stop');
    } elseif ($act === 'restart') {
        $sendCall('/api/servers/stop');
        sleep(1);
        $sendCall('/api/servers/start', $startPayload);
    }
    echo json_encode(['ok' => true]);
    exit;
}

$nodeLocations = ['Europe'];
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['cancel_my_order'])) {
        $oid = (int)($_POST['order_id'] ?? 0);
        $pdo->prepare("UPDATE orders SET status = 'Cancelled' WHERE id = ? AND user_id = ? AND status = 'Pending'")->execute([$oid, $userId]);
        $notice = "Order #{$oid} cancelled.";
    } elseif (isset($_POST['place_new_order'])) {
        $game = trim($_POST['game'] ?? 'cs16');
        $orderLoc = trim($_POST['location'] ?? ($nodeLocations[0] ?? 'Europe'));
        $srvName = trim($_POST['server_name'] ?? '');
        $slots = max(10, min(64, (int)($_POST['slots'] ?? 32)));
        if ($srvName !== '') {
            $ram = ($game === 'cs2') ? 4096 : 2048;
            $pdo->prepare("INSERT INTO orders (user_id, game, location, server_name, slots, ram_mb, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')")->execute([$userId, $game, $orderLoc, $srvName, $slots, $ram]);
            $oid = (int)$pdo->lastInsertId();
            $uInfo = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
            $uInfo->execute([$userId]);
            $uData = $uInfo->fetch();
            if ($uData && filter_var($uData['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                $oHtml = wrapPanelMail("Order #{$oid} Received", "<p>Hello <strong>" . htmlspecialchars($uData['username']) . "</strong>,</p><p>Your new server order (<strong>" . htmlspecialchars($srvName) . "</strong> &bull; " . strtoupper(htmlspecialchars($game)) . " &bull; {$slots} slots) is now awaiting admin approval.</p>");
                sendSmtpMail($pdo, $uData['email'], "Server Order #{$oid} Confirmation", $oHtml);
            }
            $notice = "Order #{$oid} submitted! It is now awaiting admin approval.";
        }
    }
}

$oStmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? AND status = 'Pending' ORDER BY id DESC");
$oStmt->execute([$userId]);
$pendingOrders = $oStmt->fetchAll();

$stmt = $pdo->prepare("SELECT s.*, b.name AS box_name, b.location AS box_location FROM servers s JOIN boxes b ON s.box_id = b.id WHERE s.user_id = ? ORDER BY s.id DESC");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll();

$openTickets = 0;
try {
    $tStmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE user_id = ? AND status != 'Closed'");
    $tStmt->execute([$userId]);
    $openTickets = (int)$tStmt->fetchColumn();
} catch (Throwable $e) {
    $openTickets = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Game Servers - SwiftPanel</title>
    <link rel="stylesheet" href="assets/style.css?v=3">
    <style>
        .container { max-width: 1180px; margin: 24px auto; padding: 0 18px; }
        .srv-table { width: 100%; border-collapse: collapse; }
        .srv-table th, .srv-table td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #262c3a; font-size: 13px; }
        .srv-table th { color: #94a3b8; font-weight: 600; background: #141820; }
        .srv-actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .btn-pwr { padding: 4px 8px; font-size: 11px; border-radius: 4px; border: 1px solid transparent; cursor: pointer; font-weight: 600; transition: opacity 0.15s; }
        .btn-pwr:disabled { opacity: 0.45; cursor: not-allowed; }
        .btn-pwr-start { background: rgba(46,204,113,0.16); color: #2ecc71; border-color: rgba(46,204,113,0.35); }
        .btn-pwr-restart { background: rgba(243,156,18,0.16); color: #f39c12; border-color: rgba(243,156,18,0.35); }
        .btn-pwr-stop { background: rgba(231,76,60,0.16); color: #e74c3c; border-color: rgba(231,76,60,0.35); }
        .badge-checking { background: rgba(255,255,255,0.07); color: #94a3b8; }
        .badge-pending { background: rgba(243,156,18,0.2); color: #f39c12; }
        .badge-installing { background: rgba(52,152,219,0.2); color: #3498db; }
        .form-input { background: #0d1117; color: #fff; border: 1px solid #30363d; border-radius: 4px; padding: 8px 10px; font-size: 13px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 22px; }
        .stat-card { background: #141820; border: 1px solid #262c3a; border-radius: 6px; padding: 16px 18px; display: flex; flex-direction: column; gap: 6px; }
        .stat-card-label { font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .stat-card-value { font-size: 26px; font-weight: 700; color: #f8fafc; line-height: 1.1; }
        .stat-card-sub { font-size: 12px; color: #64748b; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#aaa;">Springs Docker Edition</small></div>
        <div class="user-info">Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'client'); ?></strong> | <a href="logout.php">Logout</a></div>
    </div>
    <div id="navbar">
        <ul>
            <li><a href="dashboard.php" class="active">Game Servers</a></li>
            <li><a href="tickets.php">Support Tickets</a></li>
            <li><a href="profile.php">My Profile</a></li>
        </ul>
    </div>
    <div class="container">
        <div class="stats-grid">
            <div class="stat-card" style="border-left: 3px solid #2ecc71;">
                <span class="stat-card-label">Active Servers</span>
                <span class="stat-card-value"><?php echo count($servers); ?></span>
                <span class="stat-card-sub">Assigned to your account</span>
            </div>
            <div class="stat-card" style="border-left: 3px solid #3498db;">
                <span class="stat-card-label">Online Now</span>
                <span class="stat-card-value" id="stat-online-count">0</span>
                <span class="stat-card-sub">Live running instances</span>
            </div>
            <div class="stat-card" style="border-left: 3px solid #f39c12;">
                <span class="stat-card-label">Pending Orders</span>
                <span class="stat-card-value"><?php echo count($pendingOrders); ?></span>
                <span class="stat-card-sub">Awaiting admin approval</span>
            </div>
            <div class="stat-card" style="border-left: 3px solid #a855f7;">
                <span class="stat-card-label">Open Support Tickets</span>
                <span class="stat-card-value"><?php echo $openTickets; ?></span>
                <span class="stat-card-sub"><a href="tickets.php" style="color:#a855f7;text-decoration:none;">View tickets &rarr;</a></span>
            </div>
        </div>
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

        <?php if (!empty($pendingOrders)): ?>
            <div class="box" style="margin-bottom:20px;border-color:#f39c12;">
                <div class="box-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span>Pending Server Orders (<?php echo count($pendingOrders); ?>)</span>
                    <small style="color:#f39c12;">Awaiting Admin Approval</small>
                </div>
                <div class="box-content" style="padding:0;">
                    <table class="srv-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Order #</th>
                                <th>Server Name</th>
                                <th>Game</th>
                                <th>Slots / RAM</th>
                                <th>Submitted</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingOrders as $po): ?>
                                <tr>
                                    <td><span class="badge badge-pending">PENDING APPROVAL</span></td>
                                    <td>#<?php echo (int)$po['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($po['server_name']); ?></strong></td>
                                    <td><?php echo strtoupper(htmlspecialchars($po['game'])); ?></td>
                                    <td><?php echo (int)$po['slots']; ?> Slots / <?php echo (int)$po['ram_mb']; ?> MB</td>
                                    <td><?php echo htmlspecialchars($po['created_at']); ?></td>
                                    <td>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="order_id" value="<?php echo (int)$po['id']; ?>">
                                            <button type="submit" name="cancel_my_order" value="1" class="btn btn-red" style="padding:4px 10px;font-size:12px;">Cancel Order</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
        <div class="box">
            <div class="box-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <span>My Game Servers</span>
                <button type="button" class="btn btn-green" style="padding:5px 12px;font-size:12px;" onclick="document.getElementById('quick-order-box').style.display='block'">+ Order New Server</button>
            </div>
            <div id="quick-order-box" style="display:none;padding:14px 16px;background:#141820;border-bottom:1px solid #262c3a;">
                <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0;">
                    <select name="game" class="form-input">
                        <option value="cs16">Counter-Strike 1.6</option>
                        <option value="cs2">Counter-Strike 2</option>
                        <option value="minecraft">Minecraft PaperMC</option>
                    </select>
                    <select name="location" class="form-input"><?php foreach ($nodeLocations as $nl): ?><option value="<?php echo htmlspecialchars($nl); ?>"><?php echo htmlspecialchars($nl); ?></option><?php endforeach; ?></select>
                    <input type="text" name="server_name" class="form-input" placeholder="Server Hostname (e.g. Public #2)" style="min-width:220px;" required>
                    <select name="slots" class="form-input">
                        <option value="12">12 Slots</option>
                        <option value="16">16 Slots</option>
                        <option value="24">24 Slots</option>
                        <option value="32" selected>32 Slots</option>
                    </select>
                    <button type="submit" name="place_new_order" value="1" class="btn btn-green">Submit Order</button>
                    <button type="button" class="btn" onclick="document.getElementById('quick-order-box').style.display='none'">Close</button>
                </form>
            </div>
            <div class="box-content" style="padding:0;">
                <?php if (empty($servers)): ?>
                    <p style="padding:20px;margin:0;color:#94a3b8;">No active game servers are currently assigned to your account.</p>
                <?php else: ?>
                    <table class="srv-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Server Name</th>
                                <th>Game</th>
                                <th>IP Address : Port</th>
                                <th>Slots</th>
                                <th>Start Map</th>
                                <th>Location</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($servers as $s):
                                $initCls = ($s['status'] === 'installing') ? 'badge-installing' : 'badge-checking';
                                $initTxt = ($s['status'] === 'installing') ? 'INSTALLING...' : 'Checking...';
                            ?>
                                <tr>
                                    <td><span id="status-badge-<?php echo (int)$s['id']; ?>" class="badge <?php echo $initCls; ?>"><?php echo $initTxt; ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($s['name']); ?></strong></td>
                                    <td><?php echo strtoupper(htmlspecialchars($s['game'])); ?></td>
                                    <td><code><?php echo htmlspecialchars($s['ip_address'] . ':' . $s['port']); ?></code></td>
                                    <td><?php echo (int)($s['max_players'] ?? 32); ?></td>
                                    <td><?php echo htmlspecialchars((string)($s['start_map_or_world'] ?? 'de_dust2')); ?></td>
                                    <td><?php echo htmlspecialchars($s['box_location'] ?: $s['box_name']); ?></td>
                                    <td class="srv-actions">
                                        <?php $isInst = ($s['status'] === 'installing'); ?>
                                        <button type="button" class="btn-pwr btn-pwr-start pwr-btn-<?php echo (int)$s['id']; ?>" <?php echo $isInst ? 'disabled' : ''; ?> onclick="sendPowerAction(<?php echo (int)$s['id']; ?>, 'start')">Start</button>
                                        <button type="button" class="btn-pwr btn-pwr-restart pwr-btn-<?php echo (int)$s['id']; ?>" <?php echo $isInst ? 'disabled' : ''; ?> onclick="sendPowerAction(<?php echo (int)$s['id']; ?>, 'restart')">Restart</button>
                                        <button type="button" class="btn-pwr btn-pwr-stop pwr-btn-<?php echo (int)$s['id']; ?>" <?php echo $isInst ? 'disabled' : ''; ?> onclick="sendPowerAction(<?php echo (int)$s['id']; ?>, 'stop')">Stop</button>
                                        <a href="server.php?id=<?php echo (int)$s['id']; ?>" class="btn btn-green" style="padding:5px 11px;font-size:12px;">Console</a>
                                        <a href="files.php?id=<?php echo (int)$s['id']; ?>" class="btn" style="padding:5px 11px;font-size:12px;">Files</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        const serverIds = <?php echo json_encode(array_map(fn($r) => (int)$r['id'], $servers)); ?>;

        const onlineStates = {};
        function updateOnlineCounter() {
            const count = Object.values(onlineStates).filter(Boolean).length;
            const counterEl = document.getElementById('stat-online-count');
            if (counterEl) counterEl.textContent = count;
        }

        async function checkServerStatus(id) {
            try {
                const res = await fetch('dashboard.php?ajax=status&id=' + id);
                const data = await res.json();
                const el = document.getElementById('status-badge-' + id);
                if (!el) return;
                if (data.state === 'online') {
                    el.className = 'badge badge-online';
                    el.textContent = 'ONLINE';
                    onlineStates[id] = true;
                } else if (data.state === 'installing') {
                    el.className = 'badge badge-installing';
                    el.textContent = 'INSTALLING...';
                    onlineStates[id] = false;
                } else {
                    el.className = 'badge badge-offline';
                    el.textContent = 'OFFLINE';
                    onlineStates[id] = false;
                }
                updateOnlineCounter();
            } catch (e) {}
        }

        async function sendPowerAction(id, action) {
            const btns = document.querySelectorAll('.pwr-btn-' + id);
            const badge = document.getElementById('status-badge-' + id);
            btns.forEach(b => b.disabled = true);
            if (badge) {
                badge.className = 'badge badge-installing';
                badge.textContent = action.toUpperCase() + 'ING...';
            }
            try {
                const fd = new URLSearchParams();
                fd.append('id', id);
                fd.append('action', action);
                await fetch('dashboard.php?ajax=power', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: fd.toString()
                });
                setTimeout(() => checkServerStatus(id), 1200);
            } catch (e) {
            } finally {
                setTimeout(() => btns.forEach(b => b.disabled = false), 1500);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            serverIds.forEach(id => checkServerStatus(id));
            setInterval(() => serverIds.forEach(id => checkServerStatus(id)), 4000);
        });
    </script>
<script>
(async function measureLocationPings() {
    const selects = document.querySelectorAll("select[name=\"location\"]");
    if (!selects.length) return;
    try {
        const res = await fetch("ping_nodes.php");
        const data = await res.json();
        const results = data.locations || [];

        const valid = results.filter(r => r.ms !== null).sort((a, b) => a.ms - b.ms);
        const fastest = valid.length ? valid[0].location : null;

        selects.forEach(sel => {
            Array.from(sel.options).forEach(opt => {
                const found = results.find(r => r.location === opt.value);
                if (found && found.ms !== null) {
                    opt.textContent = found.location + " — " + found.ms + " ms" + (found.location === fastest ? " (Best Ping)" : "");
                }
            });
            if (fastest) sel.value = fastest;
        });

        const bar = document.getElementById("location-ping-bar");
        if (bar && results.length) {
            bar.innerHTML = results.map(r => {
                const color = r.ms === null ? "#94a3b8" : (r.ms < 60 ? "#2ecc71" : (r.ms < 120 ? "#f39c12" : "#e74c3c"));
                const label = r.ms === null ? "Offline" : r.ms + " ms";
                return "<span style=\"display:inline-flex;align-items:center;gap:5px;padding:3px 8px;background:#0d1117;border:1px solid #30363d;border-radius:999px;font-size:11px;\"><span style=\"width:7px;height:7px;border-radius:50%;background:" + color + ";\"></span><strong>" + r.location + ":</strong> <span style=\"color:" + color + ";font-family:monospace;\">" + label + "</span></span>";
            }).join(" ");
        }
    } catch (e) {}
})();
</script>
</body>
</html>
