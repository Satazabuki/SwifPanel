<?php
require_once 'auth.php';
require_once 'config.php';

$userId = (int)$_SESSION['user_id'];
$serverId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT s.*, b.name AS box_name, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token FROM servers s JOIN boxes b ON s.box_id = b.id WHERE s.id = ? AND s.user_id = ?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch();
if (!$server) { header("Location: dashboard.php"); exit; }

session_write_close();

function callSpringsClient($server, $endpoint, $payload = []) {
    $port = (int)($server['api_port'] ?: 8085);
    $payload['screen_name'] = $server['screen_name'];
    $payload['install_path'] = $server['install_path'];
    $ch = curl_init("http://{$server['node_ip']}:{$port}{$endpoint}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $server['api_token']],
        CURLOPT_TIMEOUT => 8
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp ?: '{}', true) ?: [];
}

function cleanLog($raw) {
    return preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', (string)$raw);
}

if (isset($_GET['stream']) && $_GET['stream'] === 'console') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    @ob_end_clean();

    $lastHash = '';
    $start = time();
    while (!connection_aborted() && (time() - $start) < 55) {
        $status = callSpringsClient($server, '/api/servers/status');
        $console = callSpringsClient($server, '/api/servers/console', ['lines' => 120]);
        $logText = cleanLog($console['log'] ?? '[Springs] Waiting for console output...');
        $running = !empty($status['running']);
        $hash = md5($logText . ($running ? '1' : '0'));

        if ($hash !== $lastHash) {
            $lastHash = $hash;
            echo "data: " . json_encode(['running' => $running, 'log' => $logText]) . "\n\n";
            @flush();
        }
        usleep(600000);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'cmd' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $cmd = trim($_POST['rcon_cmd'] ?? '');
    if ($cmd !== '') {
        callSpringsClient($server, '/api/servers/command', ['command' => $cmd]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

$notice = '';
$startPayload = [
    'game' => $server['game'],
    'ip_address' => $server['ip_address'],
    'port' => (int)$server['port'],
    'max_players' => (int)($server['max_players'] ?? 32),
    'start_map_or_world' => $server['start_map_or_world'] ?? 'de_dust2',
    'ram_mb' => (int)($server['ram_mb'] ?? 2048)
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    if ($act === 'start') {
        callSpringsClient($server, '/api/servers/start', $startPayload);
        sleep(1);
        $notice = 'Server start command sent.';
    } elseif ($act === 'stop') {
        callSpringsClient($server, '/api/servers/stop');
        $notice = 'Server stop command sent.';
    } elseif ($act === 'restart') {
        callSpringsClient($server, '/api/servers/stop');
        sleep(1);
        callSpringsClient($server, '/api/servers/start', $startPayload);
        sleep(1);
        $notice = 'Server restarted.';
    }
}

$status = callSpringsClient($server, '/api/servers/status');
$isRunning = !empty($status['running']);
$console = callSpringsClient($server, '/api/servers/console', ['lines' => 120]);
$logText = cleanLog($console['log'] ?? '[Springs] Waiting for console output...');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Live Console - <?php echo htmlspecialchars($server['name']); ?></title>
    <link rel="stylesheet" href="assets/style.css?v=3">
    <style>
        .container { max-width: 1180px; margin: 24px auto; padding: 0 18px; }
        .meta-bar { display:flex; flex-wrap:wrap; gap:16px; align-items:center; justify-content:space-between; background:#141820; border:1px solid #262c3a; border-radius:6px; padding:12px 16px; margin-bottom:14px; font-size:13px; }
        .console-window { background:#090c10; color:#e6edf3; border:1px solid #30363d; border-radius:6px; padding:14px; font-family:Consolas, Monaco, 'Courier New', monospace; font-size:13px; line-height:1.5; height:460px; overflow-y:auto; white-space:pre-wrap; word-break:break-word; }
        .cmd-bar { display:flex; gap:8px; margin-top:10px; }
        .cmd-bar input { flex:1; background:#0d1117; color:#fff; border:1px solid #30363d; border-radius:4px; padding:9px 12px; font-family:Consolas, monospace; font-size:13px; }
        .live-dot { display:inline-block; width:8px; height:8px; border-radius:50%; background:#2ecc71; margin-right:6px; box-shadow:0 0 8px #2ecc71; }
        .live-dot.paused { background:#f39c12; box-shadow:none; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#aaa;">Springs Docker Edition</small></div>
        <div class="user-info">Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'client'); ?></strong> | <a href="logout.php">Logout</a></div>
    </div>
    <div id="navbar">
        <ul>
            <li><a href="dashboard.php">Game Servers</a></li>
            <li><a href="server.php?id=<?php echo (int)$server['id']; ?>" class="active">Server Management</a></li>
            <li><a href="tickets.php">Support Tickets</a></li>
            <li><a href="profile.php">My Profile</a></li>
        </ul>
    </div>
    <div class="container">
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <div class="meta-bar">
            <div>
                <strong style="font-size:15px;color:#fff;"><?php echo htmlspecialchars($server['name']); ?></strong>
                <span style="margin-left:10px;color:#94a3b8;">IP: <code><?php echo htmlspecialchars($server['ip_address'] . ':' . $server['port']); ?></code></span>
                <span style="margin-left:10px;color:#94a3b8;">Slots: <strong><?php echo (int)($server['max_players'] ?? 32); ?></strong></span>
                <span style="margin-left:10px;color:#94a3b8;">Map: <strong><?php echo htmlspecialchars((string)($server['start_map_or_world'] ?? 'de_dust2')); ?></strong></span>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <span style="font-size:12px;color:#94a3b8;"><span id="live-dot" class="live-dot"></span><span id="live-label">LIVE STREAM</span></span>
                <span id="srv-badge" class="badge <?php echo $isRunning ? 'badge-online' : 'badge-offline'; ?>"><?php echo $isRunning ? 'ONLINE' : 'OFFLINE'; ?></span>
            </div>
        </div>
        <div class="box">
            <div class="box-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <span>Server Control &amp; Live Console</span>
                <div style="display:flex;gap:8px;align-items:center;">
                    <form method="POST" style="display:flex;gap:6px;margin:0;">
                        <button type="submit" name="action" value="start" class="btn btn-green" style="padding:5px 12px;font-size:12px;">Start</button>
                        <button type="submit" name="action" value="restart" class="btn" style="padding:5px 12px;font-size:12px;">Restart</button>
                        <button type="submit" name="action" value="stop" class="btn btn-red" style="padding:5px 12px;font-size:12px;">Stop</button>
                    </form>
                    <button type="button" id="btn-scroll" class="btn" style="padding:5px 10px;font-size:12px;">Auto-Scroll: ON</button>
                    <a href="files.php?id=<?php echo (int)$server['id']; ?>" class="btn" style="padding:5px 12px;font-size:12px;">File Manager</a>
                </div>
            </div>
            <div class="box-content">
                <div id="console-output" class="console-window"><?php echo htmlspecialchars($logText); ?></div>
                <form id="cmd-form" class="cmd-bar">
                    <input type="text" id="cmd-input" name="rcon_cmd" placeholder="Type console command and press Enter..." autocomplete="off" required>
                    <button type="submit" class="btn btn-green">Send Command</button>
                </form>
            </div>
        </div>
    </div>
    <script>
        const serverId = <?php echo (int)$server['id']; ?>;
        const outEl = document.getElementById('console-output');
        const badgeEl = document.getElementById('srv-badge');
        const dotEl = document.getElementById('live-dot');
        const labelEl = document.getElementById('live-label');
        const scrollBtn = document.getElementById('btn-scroll');
        const cmdForm = document.getElementById('cmd-form');
        const cmdInput = document.getElementById('cmd-input');

        let autoScroll = true;
        let cmdHistory = [];
        let historyIdx = -1;

        outEl.scrollTop = outEl.scrollHeight;

        scrollBtn.addEventListener('click', () => {
            autoScroll = !autoScroll;
            scrollBtn.textContent = 'Auto-Scroll: ' + (autoScroll ? 'ON' : 'OFF');
            if (autoScroll) outEl.scrollTop = outEl.scrollHeight;
        });

        function connectStream() {
            const es = new EventSource('server.php?id=' + serverId + '&stream=console');
            es.onopen = () => {
                dotEl.className = 'live-dot';
                labelEl.textContent = 'LIVE STREAM';
            };
            es.onmessage = (e) => {
                try {
                    const data = JSON.parse(e.data);
                    outEl.textContent = data.log;
                    if (autoScroll) outEl.scrollTop = outEl.scrollHeight;
                    badgeEl.className = 'badge ' + (data.running ? 'badge-online' : 'badge-offline');
                    badgeEl.textContent = data.running ? 'ONLINE' : 'OFFLINE';
                } catch (err) {}
            };
            es.onerror = () => {
                dotEl.className = 'live-dot paused';
                labelEl.textContent = 'RECONNECTING...';
                es.close();
                setTimeout(connectStream, 1500);
            };
        }
        connectStream();

        cmdForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const cmd = cmdInput.value.trim();
            if (!cmd) return;
            cmdHistory.unshift(cmd);
            historyIdx = -1;
            cmdInput.value = '';
            outEl.textContent += '\n> ' + cmd + '\n';
            if (autoScroll) outEl.scrollTop = outEl.scrollHeight;

            const body = new URLSearchParams();
            body.append('rcon_cmd', cmd);
            await fetch('server.php?id=' + serverId + '&ajax=cmd', { method: 'POST', body });
        });

        cmdInput.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowUp' && cmdHistory.length > 0) {
                e.preventDefault();
                historyIdx = Math.min(historyIdx + 1, cmdHistory.length - 1);
                cmdInput.value = cmdHistory[historyIdx];
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                historyIdx = Math.max(historyIdx - 1, -1);
                cmdInput.value = historyIdx === -1 ? '' : cmdHistory[historyIdx];
            }
        });
    </script>
</body>
</html>
