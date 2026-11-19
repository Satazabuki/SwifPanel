<?php
require_once 'auth.php';
require_once 'config.php';
require_once 'mailer.php';
require_once 'games.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/stats/stats_lib.php';
require_once __DIR__ . '/game_icons.php';
require_once 'payments/payments_lib.php';

$userId = (int)$_SESSION['user_id'];
if (empty($_SESSION['console_csrf'])) {
    $_SESSION['console_csrf'] = bin2hex(random_bytes(16));
}
$dashCsrf = (string)$_SESSION['console_csrf'];

$pdo->exec("CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    game VARCHAR(32) NOT NULL,
    location VARCHAR(64) NULL,
    server_name VARCHAR(120) NOT NULL,
    slots INT DEFAULT 32,
    ram_mb INT DEFAULT 2048,
    status ENUM('Pending','Approved','Cancelled') DEFAULT 'Pending',
    server_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
swiftPayEnsureSchema($pdo);

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
    if (!in_array($act, ['start', 'stop', 'restart', 'reinstall'], true)) {
        echo json_encode(swiftLangJsonData(['ok' => false, 'error' => 'Invalid action']));
        exit;
    }
    if ($act === 'reinstall' && !hash_equals((string)($_SESSION['console_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        echo json_encode(swiftLangJsonData(['ok' => false, 'error' => 'Security check failed. Please reload the page.']));
        exit;
    }
    $st = $pdo->prepare("SELECT s.*, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token FROM servers s JOIN boxes b ON s.box_id = b.id WHERE s.id = ? AND s.user_id = ?");
    $st->execute([$srvId, $userId]);
    $srv = $st->fetch();
    if (!$srv) {
        echo json_encode(swiftLangJsonData(['ok' => false, 'error' => 'Server not found']));
        exit;
    }
    if ($srv['status'] === 'installing') {
        echo json_encode(swiftLangJsonData(['ok' => false, 'error' => 'Server is still installing']));
        exit;
    }
    session_write_close();
    $port = (int)($srv['api_port'] ?: 8085);
    $sendCall = function($endpoint, $extra = [], $timeout = 8) use ($srv, $port) {
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
            CURLOPT_TIMEOUT => (int)$timeout
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
        'start_map_or_world' => $srv['start_map_or_world'] ?? swiftDefaultMap($srv['game']),
        'server_name' => (string)($srv['name'] ?? ''),
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
    } elseif ($act === 'reinstall') {
        $sendCall('/api/servers/stop', [], 60);
        $isCs = ($srv['game'] === 'cs16');
        $res = json_decode((string)($isCs
            ? $sendCall('/api/servers/rehlds', ['game' => 'cs16', 'action' => 'reinstall'], 20)
            : $sendCall('/api/servers/install', ['game' => $srv['game']], 20)), true);
        $started = is_array($res) && ($isCs ? !empty($res['ok']) : (($res['status'] ?? '') === 'installing'));
        if (!$started) {
            $why = is_array($res) ? (string)($res['error'] ?? '') : '';
            echo json_encode(swiftLangJsonData(['ok' => false, 'error' => stripos($why, 'not found') !== false
                ? 'This node runs an old version of the agent. Ask the administrator to update the node.'
                : ($why !== '' ? $why : 'Could not reach the server node.')]));
            exit;
        }
        $pdo->prepare("UPDATE servers SET status = 'installing' WHERE id = ?")->execute([$srvId]);
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
        echo json_encode(swiftLangJsonData(['ok' => false, 'error' => 'Invalid action']));
        exit;
    }
    $st = $pdo->prepare("SELECT s.*, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token FROM servers s JOIN boxes b ON s.box_id = b.id WHERE s.id = ? AND s.user_id = ?");
    $st->execute([$srvId, $userId]);
    $srv = $st->fetch();
    if (!$srv) {
        echo json_encode(swiftLangJsonData(['ok' => false, 'error' => 'Server not found']));
        exit;
    }
    if ($srv['status'] === 'installing') {
        echo json_encode(swiftLangJsonData(['ok' => false, 'error' => 'Server is still installing']));
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
        'start_map_or_world' => $srv['start_map_or_world'] ?? swiftDefaultMap($srv['game']),
        'server_name' => (string)($srv['name'] ?? ''),
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
if (empty($nodeLocations)) {
    $nodeLocations = ['Europe'];
}
$viewParam = (string)($_GET['page'] ?? '');
$view = ($viewParam === 'order') ? 'order' : (($viewParam === 'invoices') ? 'invoices' : 'servers');
$payPricing = swiftPayPricingForJs($pdo);
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['cancel_my_order'])) {
        $oid = (int)($_POST['order_id'] ?? 0);
        $cancelSt = $pdo->prepare("UPDATE orders SET status = 'Cancelled' WHERE id = ? AND user_id = ? AND status = 'Pending' AND payment_status <> 'Paid'");
        $cancelSt->execute([$oid, $userId]);
        if ($cancelSt->rowCount() > 0) {
            swiftPayCancelOrderInvoices($pdo, $oid);
        }
        $notice = $cancelSt->rowCount() > 0 ? "Order #{$oid} cancelled." : "Order #{$oid} could not be cancelled here. Paid orders are cancelled and refunded by support - please open a ticket.";
    } elseif (isset($_POST['renew_server'])) {
        $renewErr = null;
        $renewInv = swiftPayCreateRenewalInvoice($pdo, (int)($_POST['server_id'] ?? 0), (int)($_POST['period'] ?? 1), $userId, $renewErr);
        if ($renewInv) {
            header('Location: ' . swiftPayCheckoutPath($renewInv['id'], $renewInv['token']));
            exit;
        }
        $notice = 'Could not create the renewal invoice: ' . $renewErr;
    } elseif (isset($_POST['place_new_order'])) {
        $game = trim($_POST['game'] ?? 'cs16');
        $orderLoc = trim($_POST['location'] ?? ($nodeLocations[0] ?? 'Europe'));
        if (!in_array($orderLoc, $nodeLocations, true)) {
            $orderLoc = $nodeLocations[0];
        }
        $srvName = trim($_POST['server_name'] ?? '');
        $gDef = swiftGame($game);
        $slots = $gDef ? swiftClampSlots($game, (int)($_POST['slots'] ?? $gDef['slots_default'])) : 0;
        if ($srvName !== '' && $gDef) {
            $ram = (int)$gDef['ram'];
            $pdo->prepare("INSERT INTO orders (user_id, game, location, server_name, slots, ram_mb, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')")->execute([$userId, $game, $orderLoc, $srvName, $slots, $ram]);
            $oid = (int)$pdo->lastInsertId();
            $pay = swiftPayAttachToOrder($pdo, $oid, $game, $slots, (int)($_POST['period'] ?? 1));
            if ($pay['required']) {
                header('Location: ' . swiftPayCheckoutPath($pay['invoice_id'], $pay['token']));
                exit;
            }
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

$oStmt = $pdo->prepare("SELECT *, (SELECT i.id FROM invoices i WHERE i.order_id = orders.id ORDER BY i.id DESC LIMIT 1) AS invoice_id FROM orders WHERE user_id = ? AND status = 'Pending' ORDER BY id DESC");
$oStmt->execute([$userId]);
$pendingOrders = $oStmt->fetchAll();

$stmt = $pdo->prepare("SELECT s.*, TIMESTAMPDIFF(SECOND, NOW(), s.expires_at) AS secs_left, DATEDIFF(DATE(s.expires_at), CURDATE()) AS days_left, b.name AS box_name, b.location AS box_location FROM servers s JOIN boxes b ON s.box_id = b.id WHERE s.user_id = ? ORDER BY s.id DESC");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll();

// Invoices, renewal invoices and reminders
swiftPayEnsureRenewalInvoices($pdo, $userId);
$alerts = swiftPayClientAlerts($pdo, $userId);
$invStmt = $pdo->prepare("SELECT i.*, DATEDIFF(i.due_date, CURDATE()) AS days_left FROM invoices i WHERE i.user_id = ? ORDER BY i.id DESC LIMIT 200");
$invStmt->execute([$userId]);
$invoices = $invStmt->fetchAll();
$dueCount = 0;
$overdueCount = 0;
$unpaidTotal = 0.0;
$paidTotal = 0.0;
$invCurrency = $payPricing['currency'];
foreach ($invoices as $invRow) {
    if ($invRow['status'] === 'Unpaid') {
        $dueCount++;
        $unpaidTotal += (float)$invRow['amount'];
        $invCurrency = $invRow['currency'];
        if ((int)$invRow['days_left'] < 0) {
            $overdueCount++;
        }
    } elseif ($invRow['status'] === 'Paid') {
        $paidTotal += (float)$invRow['amount'];
    }
}

$openTickets = 0;
try {
    $tStmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE user_id = ? AND status != 'Closed'");
    $tStmt->execute([$userId]);
    $openTickets = (int)$tStmt->fetchColumn();
} catch (Throwable $e) {
    $openTickets = 0;
}
?>
<?php require_once __DIR__ . '/lang.php'; swiftLangStart(); ?>
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
        .nav-badge { background: #e74c3c; color: #fff; border-radius: 999px; padding: 1px 7px; font-size: 11px; margin-left: 5px; font-weight: 700; }
        .alert-warn { background: rgba(243,156,18,0.12); border: 1px solid #f39c12; color: #f5b041; padding: 12px 16px; border-radius: 4px; font-size: 13px; }
        .badge-muted { background: rgba(149,165,166,0.2); color: #95a5a6; }
        .games-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
        <?php echo swiftGameIconCss(); echo swiftGameBannerCss(); ?>
        .game-title { font-size: 15px; font-weight: 700; color: #fff; margin-bottom: 4px; }
        .game-sub { font-size: 12px; color: #94a3b8; margin-bottom: 12px; }
        .order-panel { margin-top: 18px; background: #141820; border: 1px solid #2ecc71; border-radius: 8px; padding: 18px; display: none; }
        .order-panel.open { display: block; }
        .order-label { display: block; font-size: 12px; color: #94a3b8; margin-bottom: 4px; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#aaa;">Springs Docker Edition</small></div>
        <div class="user-info">Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'client'); ?></strong> | <a href="logout.php">Logout</a></div>
    </div>
    <div id="navbar">
        <ul>
            <li><a href="dashboard.php"<?php echo $view === 'servers' ? ' class="active"' : ''; ?>>Game Servers</a></li>
            <li><a href="dashboard.php?page=order"<?php echo $view === 'order' ? ' class="active"' : ''; ?>>Order Server</a></li>
            <li><a href="dashboard.php?page=invoices"<?php echo $view === 'invoices' ? ' class="active"' : ''; ?>>Invoices<?php echo $dueCount > 0 ? ' <span class="nav-badge">' . (int)$dueCount . '</span>' : ''; ?></a></li>
            <li><a href="mods/index.php">Mods &amp; Plugins</a></li>
            <li><a href="tickets.php">Support Tickets</a></li>
            <li><a href="profile.php">My Profile</a></li>
        </ul>
    </div>
    <div class="container">
        <?php foreach ($alerts as $al): ?>
            <div class="<?php echo $al['level'] === 'danger' ? 'alert alert-error' : 'alert-warn'; ?>" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
                <span><?php echo $al['level'] === 'danger' ? '&#9888; ' : '&#9200; '; ?><?php echo htmlspecialchars($al['text']); ?></span>
                <?php if ($view !== 'invoices'): ?><a href="dashboard.php?page=invoices" class="btn btn-green" style="padding:4px 10px;font-size:12px;">View invoices</a><?php endif; ?>
            </div>
        <?php endforeach; ?>
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
                                <th>Payment</th>
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
                                    <td><?php echo htmlspecialchars(swiftGameLabel($po['game'])); ?></td>
                                    <td><?php echo (int)$po['slots']; ?> Slots / <?php echo (int)$po['ram_mb']; ?> MB</td>
                                    <td><?php $ps = $po['payment_status'] ?? 'Free'; if ($ps === 'Unpaid'): ?><span class="badge badge-pending">UNPAID</span><br><small><?php echo htmlspecialchars(swiftPayMoney($po['amount'], $po['currency'])); ?></small><?php elseif ($ps === 'Paid'): ?><span class="badge badge-online">PAID</span><?php else: ?><span style="color:#94a3b8;">&mdash;</span><?php endif; ?></td>
                                    <td><?php echo htmlspecialchars($po['created_at']); ?></td>
                                    <td>
                                        <?php if (($po['payment_status'] ?? '') === 'Unpaid' && !empty($po['pay_token']) && !empty($po['invoice_id'])): ?>
                                            <a href="<?php echo htmlspecialchars(swiftPayCheckoutPath($po['invoice_id'], $po['pay_token'])); ?>" class="btn btn-green" style="padding:4px 10px;font-size:12px;margin-bottom:4px;">Pay now</a>
                                        <?php endif; ?>
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
        <?php if ($view === 'order'): ?>
        <div class="box">
            <div class="box-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <span>Order a New Game Server &mdash; Choose a Game</span>
                <a href="dashboard.php" class="btn" style="padding:4px 10px;font-size:12px;">&larr; Back to My Servers</a>
            </div>
            <div class="box-content">
                <div class="games-grid">
                    <?php $gjsD = swiftGamesForJs(); $dashPay = function_exists('swiftPayPricingForJs') ? swiftPayPricingForJs($pdo) : ['enabled' => false, 'prices' => [], 'currency' => '', 'zero_decimal' => false];
                    foreach (swiftGames() as $gSlug => $gInfo):
                        $perSlot = (float)($dashPay['prices'][$gSlug] ?? 0);
                        $minSlots = (int)($gjsD[$gSlug]['slots'][0] ?? 0);
                        $from = ($dashPay['enabled'] && $perSlot > 0 && $minSlots > 0) ? $perSlot * $minSlots : 0;
                        $maxSlots = (int)(end($gjsD[$gSlug]['slots']) ?: 0); ?>
                        <div class="gcard game-card" tabindex="0" role="button" data-game="<?php echo htmlspecialchars($gSlug); ?>" data-label="<?php echo htmlspecialchars($gInfo['label']); ?>" onclick="selectGame(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();selectGame(this);}">
                            <?php echo swiftGameBanner($gSlug, $gInfo); ?>
                            <span class="gtag"><?php echo strip_tags(html_entity_decode((string)$gInfo['tagline'])); ?></span>
                            <div class="gtext">
                                <div class="gname"><?php echo htmlspecialchars($gInfo['label']); ?></div>
                                <div class="gprice"><?php echo $from > 0 ? 'From <strong>' . htmlspecialchars($dashPay['zero_decimal'] ? number_format(round($from)) : number_format($from, 2)) . ' ' . htmlspecialchars($dashPay['currency']) . '</strong> / mo' : 'Up to ' . $maxSlots . ' slots'; ?></div>
                            </div>
                            <span class="gcta">Order &rarr;</span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="order-panel" class="order-panel">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <strong style="font-size:15px;color:#2ecc71;">Configure &amp; Order: <span id="selected-game-label"></span></strong>
                        <button type="button" class="btn" style="padding:3px 9px;font-size:11px;" onclick="closeOrder()">Close</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="game" id="order-game-input" value="cs16">
                        <div style="display:grid;grid-template-columns:2fr 1fr;gap:10px;">
                            <div>
                                <label class="order-label">Server Hostname</label>
                                <input type="text" name="server_name" class="form-input" placeholder="e.g. Public #2" maxlength="120" required>
                            </div>
                            <div>
                                <label class="order-label">Player Slots</label>
                                <select name="slots" id="order-slots" class="form-input"></select>
                            </div>
                        </div>
                        <div id="order-ram" style="font-size:12px;color:#2ecc71;margin:-4px 0 2px;"></div>
                        <div id="order-hint" style="font-size:12px;color:#94a3b8;margin:0 0 10px;min-height:16px;"></div>
                        <?php if ($payPricing['enabled']): ?>
                        <label class="order-label">Billing Period</label>
                        <select name="period" id="order-period" class="form-input">
                            <?php foreach (swiftPayPeriods() as $pm): ?>
                                <option value="<?php echo (int)$pm; ?>"><?php echo $pm === 1 ? '1 month' : $pm . ' months'; ?><?php echo $payPricing['discounts'][$pm] > 0 ? ' (save ' . rtrim(rtrim(number_format($payPricing['discounts'][$pm], 2, '.', ''), '0'), '.') . '%)' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="order-price" style="font-size:14px;font-weight:700;color:#2ecc71;margin:0 0 10px;"></div>
                        <?php endif; ?>
                        <label class="order-label">Server Location</label>
                        <select name="location" class="form-input"><?php foreach ($nodeLocations as $nl): ?><option value="<?php echo htmlspecialchars($nl); ?>"><?php echo htmlspecialchars($nl); ?></option><?php endforeach; ?></select>
                        <button type="submit" id="order-submit" name="place_new_order" value="1" class="btn btn-green">Submit Server Order</button>
                        <span style="font-size:12px;color:#94a3b8;margin-left:8px;">Your order is reviewed by an admin before the server is created.</span>
                    </form>
                </div>
            </div>
        </div>
        <script>
            var SWIFT_GAMES = <?php echo json_encode(swiftGamesForJs(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            function fillSlots(slug) {
                var g = SWIFT_GAMES[slug];
                var sel = document.getElementById('order-slots');
                var hint = document.getElementById('order-hint');
                var ram = document.getElementById('order-ram');
                if (!g || !sel) return;
                sel.innerHTML = '';
                g.slots.forEach(function (n) {
                    var o = document.createElement('option');
                    o.value = n;
                    o.textContent = n + ' Slots';
                    if (n === g.slots_default) o.selected = true;
                    sel.appendChild(o);
                });
                if (hint) hint.textContent = g.hint || '';
                if (ram) ram.textContent = 'Includes ' + (g.ram / 1024) + ' GB RAM';
                if (typeof updatePrice === 'function') updatePrice();
            }
            var SWIFT_PRICING = <?php echo json_encode($payPricing, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            function relabelSlots(per, disc, months) {
                var sel = document.getElementById('order-slots');
                if (!sel) return;
                Array.prototype.forEach.call(sel.options, function (o) {
                    var n = parseInt(o.value, 10);
                    var label = n + ' Slots';
                    if (per > 0) {
                        var t = Math.round(per * n * months * (100 - disc) + 0.0000001) / 100;
                        if (SWIFT_PRICING.zero_decimal) t = Math.round(t);
                        label += ' \u2014 ' + (SWIFT_PRICING.zero_decimal ? String(t) : t.toFixed(2)) + ' ' + SWIFT_PRICING.currency + (months > 1 ? ' / ' + months + ' mo' : ' / mo');
                    }
                    o.textContent = label;
                });
            }

            function updatePrice() {
                var box = document.getElementById('order-price');
                var btn = document.getElementById('order-submit');
                if (!SWIFT_PRICING.enabled || !box) return;
                var game = document.getElementById('order-game-input').value;
                var slots = parseInt(document.getElementById('order-slots').value, 10) || 0;
                var months = parseInt(document.getElementById('order-period').value, 10) || 1;
                var per = SWIFT_PRICING.prices[game] || 0;
                var disc = SWIFT_PRICING.discounts[months] || 0;
                relabelSlots(per, disc, months);
                var total = Math.round(per * slots * months * (100 - disc) + 0.0000001) / 100;
                if (SWIFT_PRICING.zero_decimal) total = Math.round(total);
                if (total > 0) {
                    box.textContent = 'Total: ' + (SWIFT_PRICING.zero_decimal ? String(total) : total.toFixed(2)) + ' ' + SWIFT_PRICING.currency + (months > 1 ? ' for ' + months + ' months' : ' per month');
                    if (btn) btn.textContent = 'Continue to PayPal';
                } else {
                    box.textContent = 'No payment required for this plan.';
                    if (btn) btn.textContent = 'Submit Server Order';
                }
            }
            (function () {
                var sl = document.getElementById('order-slots');
                var pe = document.getElementById('order-period');
                if (sl) sl.addEventListener('change', updatePrice);
                if (pe) pe.addEventListener('change', updatePrice);
            })();
            function selectGame(card) {
                document.querySelectorAll('.game-card').forEach(function (c) { c.classList.remove('selected'); });
                card.classList.add('selected');
                document.getElementById('order-game-input').value = card.dataset.game;
                document.getElementById('selected-game-label').textContent = card.dataset.label;
                fillSlots(card.dataset.game);
                var panel = document.getElementById('order-panel');
                panel.classList.add('open');
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            function closeOrder() {
                document.querySelectorAll('.game-card').forEach(function (c) { c.classList.remove('selected'); });
                document.getElementById('order-panel').classList.remove('open');
            }
            fillSlots(document.getElementById('order-game-input').value);
        </script>
        <?php elseif ($view === 'invoices'): ?>
        <div class="stats-grid" style="margin-bottom:18px;">
            <div class="stat-card" style="border-left: 3px solid #f39c12;">
                <span class="stat-card-label">Unpaid invoices</span>
                <span class="stat-card-value"><?php echo $dueCount; ?></span>
                <span class="stat-card-sub"><?php echo htmlspecialchars(swiftPayMoney($unpaidTotal, $invCurrency)); ?> to pay</span>
            </div>
            <div class="stat-card" style="border-left: 3px solid #e74c3c;">
                <span class="stat-card-label">Overdue</span>
                <span class="stat-card-value"><?php echo $overdueCount; ?></span>
                <span class="stat-card-sub">Past their due date</span>
            </div>
            <div class="stat-card" style="border-left: 3px solid #2ecc71;">
                <span class="stat-card-label">Paid so far</span>
                <span class="stat-card-value"><?php echo htmlspecialchars(swiftPayMoney($paidTotal, $invCurrency)); ?></span>
                <span class="stat-card-sub">All paid invoices</span>
            </div>
        </div>

        <?php
            $renewable = [];
            foreach ($servers as $rs) {
                if (!empty($rs['expires_at']) && ($payPricing['prices'][$rs['game']] ?? 0) > 0) {
                    $renewable[] = $rs;
                }
            }
        ?>
        <?php if ($payPricing['enabled'] && !empty($renewable)): ?>
        <div class="box" style="margin-bottom:20px;">
            <div class="box-header">Renew a Server</div>
            <div class="box-content" style="padding:0;">
                <table class="srv-table">
                    <thead>
                        <tr><th>Server</th><th>Game</th><th>Expires</th><th>Renew for</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($renewable as $rs): $rsSecs = (int)$rs['secs_left']; ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($rs['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars(swiftGameLabel($rs['game'])); ?></td>
                                <td>
                                    <?php echo htmlspecialchars(substr((string)$rs['expires_at'], 0, 10)); ?>
                                    <?php if ($rsSecs < 0): ?><span style="color:#e74c3c;font-weight:600;"> (expired)</span><?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" style="display:flex;gap:6px;margin:0;flex-wrap:wrap;">
                                        <input type="hidden" name="server_id" value="<?php echo (int)$rs['id']; ?>">
                                        <select name="period" class="form-input" style="width:auto;margin:0;">
                                            <?php foreach (swiftPayPeriods() as $pm): ?>
                                                <option value="<?php echo (int)$pm; ?>" <?php echo (int)($rs['renew_months'] ?? 1) === $pm ? 'selected' : ''; ?>><?php echo $pm === 1 ? '1 month' : $pm . ' months'; ?><?php echo $payPricing['discounts'][$pm] > 0 ? ' (save ' . rtrim(rtrim(number_format($payPricing['discounts'][$pm], 2, '.', ''), '0'), '.') . '%)' : ''; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" name="renew_server" value="1" class="btn btn-green" style="padding:5px 12px;font-size:12px;">Create invoice &amp; pay</button>
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
            <div class="box-header">My Invoices</div>
            <div class="box-content" style="padding:0;">
                <?php if (empty($invoices)): ?>
                    <p style="padding:20px;margin:0;color:#94a3b8;">You have no invoices yet.</p>
                <?php else: ?>
                    <table class="srv-table">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Issued</th>
                                <th>Due</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $iv):
                                $ivStatus = $iv['status'];
                                $ivLabel = strtoupper($ivStatus);
                                $ivCls = 'badge-muted';
                                if ($ivStatus === 'Paid') {
                                    $ivCls = 'badge-online';
                                } elseif ($ivStatus === 'Unpaid') {
                                    $ivCls = ((int)$iv['days_left'] < 0) ? 'badge-offline' : 'badge-pending';
                                    $ivLabel = ((int)$iv['days_left'] < 0) ? 'OVERDUE' : 'UNPAID';
                                }
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars(swiftPayInvoiceNumber($iv['id'])); ?></strong><br><small style="color:#94a3b8;"><?php echo $iv['type'] === 'Renewal' ? 'Renewal' : 'New server'; ?></small></td>
                                    <td><?php echo htmlspecialchars((string)$iv['description']); ?></td>
                                    <td><strong><?php echo htmlspecialchars(swiftPayMoney($iv['amount'], $iv['currency'])); ?></strong><br><small style="color:#94a3b8;"><?php echo (int)$iv['period_months']; ?> month(s)</small></td>
                                    <td><?php echo htmlspecialchars(substr((string)$iv['issued_at'], 0, 10)); ?></td>
                                    <td><?php echo htmlspecialchars((string)$iv['due_date']); ?></td>
                                    <td><span class="badge <?php echo $ivCls; ?>"><?php echo $ivLabel; ?></span><?php if ($ivStatus === 'Paid' && !empty($iv['paid_at'])): ?><br><small style="color:#94a3b8;"><?php echo htmlspecialchars(substr((string)$iv['paid_at'], 0, 10)); ?></small><?php endif; ?></td>
                                    <td>
                                        <?php if ($ivStatus === 'Unpaid' && $payPricing['enabled']): ?>
                                            <a href="<?php echo htmlspecialchars(swiftPayCheckoutPath($iv['id'], $iv['pay_token'])); ?>" class="btn btn-green" style="padding:4px 12px;font-size:12px;">Pay now</a>
                                        <?php elseif ($ivStatus === 'Unpaid'): ?>
                                            <span style="color:#94a3b8;font-size:12px;">Online payment unavailable</span>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="box">
            <div class="box-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <span>My Game Servers</span>
                <a href="dashboard.php?page=order" class="btn btn-green" style="padding:5px 12px;font-size:12px;">+ Order New Server</a>
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
                                <th>Expires</th>
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
                                    <td><?php echo htmlspecialchars(swiftGameLabel($s['game'])); ?></td>
                                    <td><code><?php echo htmlspecialchars($s['ip_address'] . ':' . $s['port']); ?></code></td>
                                    <td><?php echo (int)($s['max_players'] ?? 32); ?></td>
                                    <td><?php echo htmlspecialchars((string)($s['start_map_or_world'] ?? swiftDefaultMap($s['game']))); ?></td>
                                    <td><?php echo htmlspecialchars($s['box_location'] ?: $s['box_name']); ?></td>
                                    <td>
                                        <?php if (!empty($s['expires_at'])): $exSecs = (int)$s['secs_left']; $exDate = substr((string)$s['expires_at'], 0, 10);
                                            if ($exSecs < 0): ?>
                                                <span style="color:#e74c3c;font-weight:600;">Expired</span><br><small><?php echo htmlspecialchars($exDate); ?></small>
                                            <?php else: $exDays = max(0, (int)$s['days_left']); ?>
                                                <span style="color:<?php echo $exDays <= 7 ? '#f39c12' : '#94a3b8'; ?>;"><?php echo htmlspecialchars($exDate); ?></span><br><small><?php echo $exDays === 0 ? 'today' : 'in ' . $exDays . ' day' . ($exDays === 1 ? '' : 's'); ?></small>
                                            <?php endif; ?>
                                            <?php if ($payPricing['enabled'] && ($payPricing['prices'][$s['game']] ?? 0) > 0): ?>
                                                <form method="POST" style="margin:4px 0 0;">
                                                    <input type="hidden" name="server_id" value="<?php echo (int)$s['id']; ?>">
                                                    <input type="hidden" name="period" value="<?php echo (int)($s['renew_months'] ?? 1); ?>">
                                                    <button type="submit" name="renew_server" value="1" class="btn btn-green" style="padding:3px 9px;font-size:11px;">Renew</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?><span style="color:#94a3b8;">&mdash;</span><?php endif; ?>
                                    </td>
                                    <td class="srv-actions">
                                        <?php $isInst = ($s['status'] === 'installing'); ?>
                                        <button type="button" class="btn-pwr btn-pwr-start pwr-btn-<?php echo (int)$s['id']; ?>" <?php echo $isInst ? 'disabled' : ''; ?> onclick="sendPowerAction(<?php echo (int)$s['id']; ?>, 'start')">Start</button>
                                        <button type="button" class="btn-pwr btn-pwr-restart pwr-btn-<?php echo (int)$s['id']; ?>" <?php echo $isInst ? 'disabled' : ''; ?> onclick="sendPowerAction(<?php echo (int)$s['id']; ?>, 'restart')">Restart</button>
                                        <button type="button" class="btn-pwr btn-pwr-stop pwr-btn-<?php echo (int)$s['id']; ?>" <?php echo $isInst ? 'disabled' : ''; ?> onclick="sendPowerAction(<?php echo (int)$s['id']; ?>, 'stop')">Stop</button>
                                        <button type="button" class="btn-pwr pwr-btn-<?php echo (int)$s['id']; ?>" style="background:#7f1d1d;" <?php echo $isInst ? 'disabled' : ''; ?> onclick="reinstallServer(<?php echo (int)$s['id']; ?>)">Reinstall</button>
                                        <?php if (statsGameSupported((string)$s['game'])): ?><a href="server_stats.php?id=<?php echo (int)$s['id']; ?>" class="btn btn-blue" style="padding:5px 11px;font-size:12px;">Stats</a><?php endif; ?>
                                        <a href="server.php?id=<?php echo (int)$s['id']; ?>" class="btn btn-green" style="padding:5px 11px;font-size:12px;">Console</a>
                                        <a href="files.php?id=<?php echo (int)$s['id']; ?>" class="btn" style="padding:5px 11px;font-size:12px;">Files</a>
                                        <a href="server_backups.php?id=<?php echo (int)$s['id']; ?>" class="btn" style="padding:5px 11px;font-size:12px;">Backups</a>
                                        <a href="mods/index.php?server=<?php echo (int)$s['id']; ?>" class="btn" style="padding:5px 11px;font-size:12px;">Mods</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <script>
        const serverIds = <?php echo json_encode($view !== 'servers' ? [] : array_map(fn($r) => (int)$r['id'], $servers)); ?>;

        const dashCsrf = <?php echo json_encode($dashCsrf); ?>;
        const serverGames = <?php echo json_encode($view !== 'servers' ? new stdClass() : array_column($servers, 'game', 'id')); ?>;

        async function reinstallServer(id) {
            const cs = serverGames[id] === 'cs16';
            const msg = cs
                ? 'REINSTALL this Counter-Strike 1.6 server?\n\nThis DELETES ALL its files (maps, configs, plugins, Metamod, AMX Mod X, uploads) and installs the game again from scratch with the ReHLDS engine. The server is stopped first. This cannot be undone.'
                : 'Reinstall this server?\n\nThe server is stopped and the game files are downloaded again. Files you added (maps, plugins, saves) are kept; edited stock game files may be reset.';
            if (!window.confirm(msg)) return;
            if (cs && !window.confirm('Last check: really delete all files of this server and reinstall?')) return;
            const btns = document.querySelectorAll('.pwr-btn-' + id);
            const badge = document.getElementById('status-badge-' + id);
            btns.forEach(b => b.disabled = true);
            if (badge) { badge.className = 'badge badge-installing'; badge.textContent = 'INSTALLING...'; }
            try {
                const fd = new URLSearchParams();
                fd.append('id', id); fd.append('action', 'reinstall'); fd.append('csrf', dashCsrf);
                const res = await fetch('dashboard.php?ajax=power', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: fd.toString() });
                const j = await res.json();
                if (!j.ok) { window.alert(j.error || 'The reinstall could not be started.'); btns.forEach(b => b.disabled = false); checkServerStatus(id); return; }
                setTimeout(() => checkServerStatus(id), 1500);
            } catch (e) {
                window.alert('Could not reach the panel: ' + e.message);
                btns.forEach(b => b.disabled = false);
            }
        }

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
<?php require_once __DIR__ . '/legal_lib.php'; echo swiftFooterHtml(); ?>
</body>
</html>
