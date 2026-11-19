<?php
require_once 'guard.php';
require_once '../config.php';
require_once '../games.php';
require_once '../payments/payments_lib.php';
swiftPayEnsureSchema($pdo);

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
                'server_name' => (string)($server['name'] ?? ''),
                'start_map' => $server['start_map_or_world'] ?? swiftDefaultMap($server['game']),
                'start_map_or_world' => $server['start_map_or_world'] ?? swiftDefaultMap($server['game'])
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
                'server_name' => (string)($server['name'] ?? ''),
                'start_map' => $server['start_map_or_world'] ?? swiftDefaultMap($server['game']),
                'start_map_or_world' => $server['start_map_or_world'] ?? swiftDefaultMap($server['game'])
            ]);
            $msg = 'Server restarted.';
        } elseif ($act === 'reinstall') {
            callSpringsAdmin($server, '/api/servers/stop');
            $pdo->prepare("UPDATE servers SET status = 'installing' WHERE id = ?")->execute([$serverId]);
            callSpringsAdmin($server, '/api/servers/install', ['game' => $server['game']]);
            $msg = 'Reinstall started. Follow install.log in the console view.';
        }
    } elseif (!empty($_POST['rcon_cmd'])) {
        $cmdResult = callSpringsAdmin($server, '/api/servers/command', ['command' => trim($_POST['rcon_cmd']), 'game' => $server['game']]);
        // new agents answer {"ok":...}; old ones {"status":"sent"} without any proof of delivery
        $cmdDelivered = array_key_exists('ok', $cmdResult) ? !empty($cmdResult['ok']) : (($cmdResult['status'] ?? '') === 'sent');
        if ($cmdDelivered) {
            $msg = 'Command sent.' . (!empty($cmdResult['reply']) ? "\n" . $cmdResult['reply'] : '');
        } else {
            $cmdError = $cmdResult ? (string)($cmdResult['error'] ?? 'The server did not accept the command.') : 'Could not reach the server node.';
        }
    } elseif (isset($_POST['set_expiry'])) {
        $expMonths = in_array((int)($_POST['renew_months'] ?? 1), swiftPayPeriods(), true) ? (int)$_POST['renew_months'] : 1;
        $expRaw = trim((string)($_POST['expires_on'] ?? ''));
        if (!swiftPayCsrfOk()) {
            $msg = 'Security check failed. Please reload the page and try again.';
        } elseif ($expRaw === '') {
            $pdo->prepare("UPDATE servers SET expires_at = NULL, renew_months = ? WHERE id = ?")->execute([$expMonths, $serverId]);
            $msg = 'Expiry removed: this server never expires.';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expRaw) && strtotime($expRaw) !== false) {
            $pdo->prepare("UPDATE servers SET expires_at = ?, renew_months = ? WHERE id = ?")->execute([$expRaw . ' 23:59:59', $expMonths, $serverId]);
            $msg = 'Expiry date saved.';
        } else {
            $msg = 'Please enter the date as YYYY-MM-DD.';
        }
    } elseif (isset($_POST['make_renewal_invoice'])) {
        if (!swiftPayCsrfOk()) {
            $msg = 'Security check failed. Please reload the page and try again.';
        } else {
            $invErr = null;
            $newInv = swiftPayCreateRenewalInvoice($pdo, $serverId, (int)($_POST['renew_months'] ?? 1), null, $invErr);
            $msg = $newInv ? 'Renewal invoice ' . swiftPayInvoiceNumber($newInv['id']) . ' is ready (see Payments).' : 'Could not create the invoice: ' . $invErr;
        }
    }
}

$expSt = $pdo->prepare("SELECT expires_at, renew_months, TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS secs_left, DATEDIFF(DATE(expires_at), CURDATE()) AS days_left FROM servers WHERE id = ?");
$expSt->execute([$serverId]);
$expInfo = $expSt->fetch();
$csrf = swiftPayCsrfToken();
$status = callSpringsAdmin($server, '/api/servers/status');
$isRunning = !empty($status['running']);
$console = callSpringsAdmin($server, '/api/servers/console', ['lines' => 45]);
$logText = $console['log'] ?? 'No console output available.';
?>
<?php require_once dirname(__DIR__) . '/lang.php'; swiftLangStart(); ?>
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
    <?php $adminNavActive = 'client_servers'; include __DIR__ . '/nav.php'; ?>
    <div class="container">
        <?php if ($msg): ?><div class="alert alert-success" style="white-space:pre-wrap;"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
        <?php if (!empty($cmdError)): ?><div class="alert alert-error"><?php echo htmlspecialchars($cmdError); ?></div><?php endif; ?>
        <div class="box">
            <div class="box-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span>Server: <strong><?php echo htmlspecialchars($server['name']); ?></strong> (Owner: <?php echo htmlspecialchars($server['owner_name']); ?> | <?php echo htmlspecialchars($server['ip_address'] . ':' . $server['port']); ?>)</span>
                <div style="display:flex;gap:8px;">
                    <a href="../server.php?id=<?php echo (int)$server['id']; ?>" class="btn btn-green" style="padding:4px 10px;font-size:12px;">Live Console</a>
                    <a href="client_servers.php" class="btn" style="padding:4px 10px;font-size:12px;">&larr; All Client Servers</a>
                </div>
            </div>
            <div class="box-content">
                <p>Status: <strong style="color:<?php echo $isRunning ? '#2ecc71' : '#e74c3c'; ?>;"><?php echo $isRunning ? 'ONLINE' : 'OFFLINE'; ?></strong></p>
                <form method="POST" style="display:flex;gap:8px;margin-bottom:14px;">
                    <button type="submit" name="action" value="start" class="btn btn-green">Start</button>
                    <button type="submit" name="action" value="restart" class="btn">Restart</button>
                    <button type="submit" name="action" value="stop" class="btn btn-red">Stop</button>
                    <button type="submit" name="action" value="reinstall" class="btn" onclick="return confirm('Stop the server and download the game files again? Files you added (maps, plugins, saves) are kept; edited stock game files may be reset.');">Reinstall files</button>
                </form>
                <div class="console-box"><?php echo htmlspecialchars($logText); ?></div>
                <form method="POST" style="display:flex;gap:8px;margin-top:10px;">
                    <input type="text" name="rcon_cmd" placeholder="Enter server console command..." style="flex:1;padding:8px;">
                    <button type="submit" class="btn btn-green">Send Command</button>
                </form>
            </div>
        </div>

        <div class="box" style="margin-top:20px;">
            <div class="box-header">Expiry &amp; Renewal</div>
            <div class="box-content">
                <?php if (!empty($expInfo['expires_at'])): ?>
                    <p>Expires on <strong><?php echo htmlspecialchars(substr((string)$expInfo['expires_at'], 0, 10)); ?></strong>
                        <?php if ((int)$expInfo['secs_left'] < 0): ?><span style="color:#e74c3c;font-weight:600;">(expired)</span>
                        <?php else: ?>(<?php echo (int)$expInfo['days_left'] === 0 ? 'today' : 'in ' . (int)$expInfo['days_left'] . ' days'; ?>)<?php endif; ?>
                        &mdash; renews for <?php echo (int)$expInfo['renew_months']; ?> month(s).</p>
                <?php else: ?>
                    <p>This server <strong>never expires</strong> (free server, or no expiry date set).</p>
                <?php endif; ?>
                <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                    <div>
                        <label style="display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;">Expires on (empty = never)</label>
                        <input type="date" name="expires_on" value="<?php echo htmlspecialchars(substr((string)($expInfo['expires_at'] ?? ''), 0, 10)); ?>" style="padding:7px 9px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;">Renewal period</label>
                        <select name="renew_months" style="padding:8px 9px;">
                            <?php foreach (swiftPayPeriods() as $pm): ?>
                                <option value="<?php echo (int)$pm; ?>" <?php echo (int)($expInfo['renew_months'] ?? 1) === $pm ? 'selected' : ''; ?>><?php echo $pm === 1 ? '1 month' : $pm . ' months'; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="set_expiry" value="1" class="btn btn-green">Save</button>
                    <button type="submit" name="make_renewal_invoice" value="1" class="btn">Create renewal invoice</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
