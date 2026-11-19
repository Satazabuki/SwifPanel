<?php
require_once 'auth.php';
require_once 'config.php';
require_once 'games.php';
require_once __DIR__ . '/lang.php';

$userId = (int)$_SESSION['user_id'];
$serverId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');   // administrators can open every server's console
$stmt = $pdo->prepare("SELECT s.*, b.name AS box_name, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token FROM servers s JOIN boxes b ON s.box_id = b.id WHERE s.id = ?" . ($isAdmin ? "" : " AND s.user_id = ?"));
$stmt->execute($isAdmin ? [$serverId] : [$serverId, $userId]);
$server = $stmt->fetch();
if (!$server) { header("Location: dashboard.php"); exit; }

if (empty($_SESSION['console_csrf'])) {
    $_SESSION['console_csrf'] = bin2hex(random_bytes(16));
}
$consoleCsrf = (string)$_SESSION['console_csrf'];
session_write_close();

function consoleCsrfOk($token)
{
    global $consoleCsrf;
    return $consoleCsrf !== '' && hash_equals($consoleCsrf, (string)$token);
}

function callSpringsClient($server, $endpoint, $payload = [], $timeout = 8) {
    $port = (int)($server['api_port'] ?: 8085);
    $payload['screen_name'] = $server['screen_name'];
    $payload['install_path'] = $server['install_path'];
    $ch = curl_init("http://{$server['node_ip']}:{$port}{$endpoint}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $server['api_token']],
        CURLOPT_TIMEOUT => (int)$timeout
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp ?: '{}', true) ?: [];
}

function cleanLog($raw) {
    return preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', (string)$raw);
}

// ---------------------------------------------------------------------------
// Live console. The browser opens ONE long-lived connection (Server-Sent Events). The agent on the node
// streams the game's log ("docker logs -f"); this script forwards every event the moment it arrives.
// Events: snapshot (replace the console), lines (append), status, error, ping (keep-alive).
// Nodes that still run an old agent are served by a polling fallback.
// ---------------------------------------------------------------------------
function sseSend(array $event)
{
    echo 'data: ' . json_encode(swiftLangJsonData($event), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

// Follows the agent's log stream until it ends. Returns 'ended', 'unsupported' (old agent) or 'error'.
function proxyAgentStream($server, $deadline)
{
    $port = (int)($server['api_port'] ?: 8085);
    $status = 0;
    $buf = '';
    $ch = curl_init("http://{$server['node_ip']}:{$port}/api/servers/logs/stream");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['screen_name' => $server['screen_name'], 'install_path' => $server['install_path'], 'tail' => 200]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $server['api_token']],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => max(5, $deadline - time()),
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$status) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$status, &$buf) {
            if ($status !== 200) {
                return strlen($chunk);
            }
            $buf .= $chunk;
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = substr($buf, 0, $pos);
                $buf = substr($buf, $pos + 1);
                $event = json_decode($line, true);
                if (is_array($event)) {
                    sseSend($event);
                }
            }
            return connection_aborted() ? 0 : strlen($chunk);
        },
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function () {
            return connection_aborted() ? 1 : 0;
        },
    ]);
    curl_exec($ch);
    curl_close($ch);
    if ($status === 404) {
        return 'unsupported';
    }
    if ($status === 401) {
        sseSend(['t' => 'error', 'message' => 'The node refused the panel\'s access token. Check the node token in Admin > Remote Nodes.']);
        return 'error';
    }
    return $status === 200 ? 'ended' : 'error';
}

function streamConsole($server, $deadline)
{
    $sentOfflineSnapshot = false;
    $polling = false;
    $lastHash = '';
    while (time() < $deadline && !connection_aborted()) {
        $st = callSpringsClient($server, '/api/servers/status', [], 5);
        $running = !empty($st['running']);
        if ($running && !$polling) {
            $res = proxyAgentStream($server, $deadline);
            if ($res === 'unsupported') {
                $polling = true;
                continue;
            }
            $sentOfflineSnapshot = ($res === 'ended');   // the stream already delivered everything up to the stop
            if ($res === 'error') {
                sleep(2);
            }
            continue;
        }
        if ($polling) {
            $console = callSpringsClient($server, '/api/servers/console', ['lines' => 150], 6);
            $log = cleanLog($console['log'] ?? '[Springs] Waiting for console output...');
            $hash = md5($log . ($running ? '1' : '0'));
            if ($hash !== $lastHash) {
                $lastHash = $hash;
                sseSend(['t' => 'snapshot', 'log' => $log, 'running' => $running]);
            } else {
                sseSend(['t' => 'ping']);
            }
            sleep(1);
            continue;
        }
        // offline: show the last log once, then just wait (and keep the connection alive) until it starts
        if (!$sentOfflineSnapshot) {
            $console = callSpringsClient($server, '/api/servers/console', ['lines' => 200], 6);
            $offlineLog = cleanLog($console['log'] ?? '');
            if ($offlineLog !== '' && substr($offlineLog, -1) !== "\n") {
                $offlineLog .= "\n";
            }
            sseSend(['t' => 'snapshot', 'log' => $offlineLog, 'running' => false]);
            $sentOfflineSnapshot = true;
        } else {
            sseSend(['t' => 'ping']);
        }
        sleep(2);
    }
}

if (isset($_GET['stream']) && $_GET['stream'] === 'console') {
    @set_time_limit(0);
    @ini_set('zlib.output_compression', '0');
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    echo "retry: 2000\n\n";
    @flush();
    streamConsole($server, time() + 100);   // the browser reconnects automatically afterwards
    exit;
}

function consoleReply($ok, $extra = [])
{
    header('Content-Type: application/json');
    echo json_encode(swiftLangJsonData(array_merge(['ok' => (bool)$ok], $extra)), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'repair' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consoleCsrfOk($_POST['csrf'] ?? '')) {
        http_response_code(403);
        consoleReply(false, ['error' => 'Security check failed. Please reload the page.']);
    }
    $r = callSpringsClient($server, '/api/servers/repair', ['game' => $server['game']], 90);   // the dependency check can take a few seconds
    if (!$r) {
        consoleReply(false, ['error' => 'Could not reach the server node.']);
    }
    if (empty($r['ok'])) {
        $why = (string)($r['error'] ?? '');
        consoleReply(false, ['error' => stripos($why, 'Endpoint not found') !== false
            ? 'This node is still running an old version of the agent. Update the node by running the installer again.'
            : ($why !== '' ? $why : 'The repair failed.')]);
    }
    consoleReply(true, [
        'using' => (string)($r['using'] ?? ''),
        'value' => (string)($r['value'] ?? ''),
        'changes' => array_values(array_map('strval', (array)($r['changes'] ?? []))),
        'reasons' => array_values(array_map('strval', (array)($r['reasons'] ?? []))),
    ]);
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'rehlds' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consoleCsrfOk($_POST['csrf'] ?? '')) {
        http_response_code(403);
        consoleReply(false, ['error' => 'Security check failed. Please reload the page.']);
    }
    if ($server['game'] !== 'cs16') {
        consoleReply(false, ['error' => 'ReHLDS is only for Counter-Strike 1.6.']);
    }
    $do = (string)($_POST['do'] ?? '');
    $act = in_array($do, ['install', 'reinstall'], true) ? $do : 'status';
    $r = callSpringsClient($server, '/api/servers/rehlds', ['game' => 'cs16', 'action' => $act], 15);   // the node works in the background; the page polls
    if (!$r) {
        consoleReply(false, ['error' => 'Could not reach the server node.']);
    }
    if (empty($r['ok'])) {
        $why = (string)($r['error'] ?? '');
        consoleReply(false, ['error' => stripos($why, 'Endpoint not found') !== false
            ? 'This node is still running an old version of the agent. Update the node by running the installer again.'
            : ($why !== '' ? $why : 'The ReHLDS update failed.')]);
    }
    $job = is_array($r['job'] ?? null) ? $r['job'] : null;
    consoleReply(true, [
        'installed' => !empty($r['installed']),
        'version' => (string)($r['version'] ?? ''),
        'state' => $job ? (string)($job['state'] ?? '') : '',
        'error' => $job ? (string)($job['error'] ?? '') : '',
        'log' => $job ? array_values(array_map('strval', (array)($job['log'] ?? []))) : [],
    ]);
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'diag' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consoleCsrfOk($_POST['csrf'] ?? '')) {
        http_response_code(403);
        consoleReply(false, ['error' => 'Security check failed. Please reload the page.']);
    }
    $r = callSpringsClient($server, '/api/servers/diagnose', ['game' => $server['game']], 20);
    if (!$r) {
        consoleReply(false, ['error' => 'Could not reach the server node.']);
    }
    if (empty($r['ok'])) {
        $why = (string)($r['error'] ?? '');
        consoleReply(false, ['error' => stripos($why, 'Endpoint not found') !== false
            ? 'This node is still running an old version of the agent. Update the node by running the installer again.'
            : ($why !== '' ? $why : 'The node could not run the check.')]);
    }
    $findings = [];
    foreach ((array)($r['findings'] ?? []) as $f) {
        $level = (string)($f['level'] ?? 'info');
        $findings[] = ['level' => in_array($level, ['ok', 'warn', 'error', 'info'], true) ? $level : 'info', 'text' => (string)($f['text'] ?? '')];
    }
    consoleReply(true, ['findings' => $findings]);
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'cmd' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consoleCsrfOk($_POST['csrf'] ?? '')) {
        http_response_code(403);
        consoleReply(false, ['error' => 'Security check failed. Please reload the page.']);
    }
    $cmd = trim(preg_replace('/[\r\n]+/', ' ', (string)($_POST['rcon_cmd'] ?? '')));
    if ($cmd === '') {
        consoleReply(false, ['error' => 'Type a command first.']);
    }
    if (strlen($cmd) > 1000) {
        consoleReply(false, ['error' => 'The command is too long.']);
    }
    $r = callSpringsClient($server, '/api/servers/command', ['command' => $cmd, 'game' => $server['game']], 15);
    if (!$r) {
        consoleReply(false, ['error' => 'Could not reach the server node. Check that it is online.']);
    }
    // new agents answer {"ok":...}; old ones {"status":"sent"} without any proof of delivery
    $delivered = array_key_exists('ok', $r) ? !empty($r['ok']) : (($r['status'] ?? '') === 'sent');
    if (!$delivered) {
        consoleReply(false, ['error' => (string)($r['error'] ?? 'The server did not accept the command.')]);
    }
    consoleReply(true, ['via' => (string)($r['via'] ?? 'stdin'), 'reply' => (string)($r['reply'] ?? ''), 'note' => (string)($r['note'] ?? '')]);
}

$notice = '';
$startPayload = [
    'game' => $server['game'],
    'ip_address' => $server['ip_address'],
    'port' => (int)$server['port'],
    'max_players' => (int)($server['max_players'] ?? 32),
    'start_map_or_world' => $server['start_map_or_world'] ?? swiftDefaultMap($server['game']),
    'server_name' => (string)($server['name'] ?? ''),
    'ram_mb' => (int)($server['ram_mb'] ?? 2048)
];

if (isset($_GET['ajax']) && $_GET['ajax'] === 'power' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consoleCsrfOk($_POST['csrf'] ?? '')) {
        http_response_code(403);
        consoleReply(false, ['error' => 'Security check failed. Please reload the page.']);
    }
    $act = (string)($_POST['action'] ?? '');
    $fail = null;
    $result = function ($r) use (&$fail) {
        if (!$r) {
            $fail = 'Could not reach the server node.';
        } elseif (isset($r['error']) || (($r['status'] ?? '') === 'error')) {
            $fail = (string)($r['error'] ?? 'The node reported an error.');
        }
    };
    if ($act === 'start') {
        $result(callSpringsClient($server, '/api/servers/start', $startPayload, 30));
    } elseif ($act === 'stop') {
        $result(callSpringsClient($server, '/api/servers/stop', [], 55));
    } elseif ($act === 'restart') {
        callSpringsClient($server, '/api/servers/stop', [], 55);
        $result(callSpringsClient($server, '/api/servers/start', $startPayload, 30));
    } else {
        consoleReply(false, ['error' => 'Unknown action.']);
    }
    if ($fail !== null) {
        consoleReply(false, ['error' => $fail]);
    }
    $st = callSpringsClient($server, '/api/servers/status', [], 5);
    consoleReply(true, ['running' => !empty($st['running'])]);
}

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
<?php require_once __DIR__ . '/lang.php'; swiftLangStart(); ?>
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
        .live-dot.off { background:#e74c3c; box-shadow:none; }
        .console-window .t-cmd { color:#58a6ff; }
        .console-window .t-reply { color:#7ee787; }
        .console-window .t-err { color:#ff7b72; }
        .console-window .t-sys { color:#8b949e; }
        .console-window .t-warn { color:#e3b341; }
        .cmd-hint { font-size:12px; color:#64748b; margin-top:6px; }
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
            <li><a href="dashboard.php?page=order">Order Server</a></li>
            <li><a href="dashboard.php?page=invoices">Invoices</a></li>
            <li><a href="mods/index.php">Mods &amp; Plugins</a></li>
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
                <span style="margin-left:10px;color:#94a3b8;">Map: <strong><?php echo htmlspecialchars((string)($server['start_map_or_world'] ?? swiftDefaultMap($server['game']))); ?></strong></span>
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
                        <button type="submit" name="action" value="start" data-power="start" class="btn btn-green" style="padding:5px 12px;font-size:12px;">Start</button>
                        <button type="submit" name="action" value="restart" data-power="restart" class="btn" style="padding:5px 12px;font-size:12px;">Restart</button>
                        <button type="submit" name="action" value="stop" data-power="stop" class="btn btn-red" style="padding:5px 12px;font-size:12px;">Stop</button>
                    </form>
                    <button type="button" id="btn-scroll" class="btn" style="padding:5px 10px;font-size:12px;">Auto-Scroll: ON</button>
                    <button type="button" id="btn-clear" class="btn" style="padding:5px 10px;font-size:12px;">Clear</button>
                    <?php if ($server['game'] === 'cs16'): ?><button type="button" id="btn-diag" class="btn" style="padding:5px 10px;font-size:12px;" title="Checks Metamod / AMX Mod X: liblist.gam, the library and its dependencies, plugins.ini and the boot log">Diagnose</button>
                    <button type="button" id="btn-reinstall" class="btn" style="padding:5px 10px;font-size:12px;" title="Deletes ALL files of this server and installs CS 1.6 again from scratch (legacy game files + ReHLDS). The server must be stopped.">Full reinstall (ReHLDS)</button>
                    <button type="button" id="btn-rehlds" class="btn" style="padding:5px 10px;font-size:12px;" title="Installs or updates the ReHLDS engine (the fixed, faster replacement of the stock HLDS engine). The server must be stopped.">Update ReHLDS</button>
                    <button type="button" id="btn-repair" class="btn" style="padding:5px 10px;font-size:12px;" title="Rewrites cstrike/liblist.gam cleanly and picks a game library that can really load (Metamod if usable, otherwise the stock one). A backup is kept.">Repair liblist.gam</button><?php endif; ?>
                    <a href="files.php?id=<?php echo (int)$server['id']; ?>" class="btn" style="padding:5px 12px;font-size:12px;">File Manager</a>
                    <a href="mods/index.php?server=<?php echo (int)$server['id']; ?>" class="btn" style="padding:5px 12px;font-size:12px;">Mods &amp; Plugins</a>
                </div>
            </div>
            <div class="box-content">
                <div id="console-output" class="console-window"><?php echo htmlspecialchars($logText); ?></div>
                <form id="cmd-form" class="cmd-bar" autocomplete="off">
                    <input type="text" id="cmd-input" name="rcon_cmd" placeholder="Type a console command and press Enter..." autocomplete="off" maxlength="1000" required>
                    <button type="submit" id="cmd-btn" class="btn btn-green">Send Command</button>
                </form>
                <div class="cmd-hint">&uarr; / &darr; browse your command history. Replies from RCON games (Rust, CS 1.6) appear in green; other games print their answer in the log.</div>
            </div>
        </div>
    </div>
    <script>
        (function () {
            const serverId = <?php echo (int)$server['id']; ?>;
            const csrf = <?php echo json_encode($consoleCsrf); ?>;
            const MAX_LINES = 3000;
            const CHUNK_LINES = 200;
            const outEl = document.getElementById('console-output');
            const badgeEl = document.getElementById('srv-badge');
            const dotEl = document.getElementById('live-dot');
            const labelEl = document.getElementById('live-label');
            const scrollBtn = document.getElementById('btn-scroll');
            const clearBtn = document.getElementById('btn-clear');
            const cmdForm = document.getElementById('cmd-form');
            const cmdInput = document.getElementById('cmd-input');
            const cmdBtn = document.getElementById('cmd-btn');
            const powerBtns = Array.prototype.slice.call(document.querySelectorAll('[data-power]'));
            const diagBtn = document.getElementById('btn-diag');
            const repairBtn = document.getElementById('btn-repair');
            const rehldsBtn = document.getElementById('btn-rehlds');
            const reinstallBtn = document.getElementById('btn-reinstall');

            let autoScroll = true;
            let nodes = [];
            let total = 0;
            let sending = false;
            let running = null;

            // ---- the terminal: append-only, trimmed to the newest lines ----
            function scrollDown() { if (autoScroll) { outEl.scrollTop = outEl.scrollHeight; } }
            function addText(text, cls) {
                if (!text) { return; }
                const parts = text.split('\n');
                let chunk = '';
                let lines = 0;
                for (let i = 0; i < parts.length; i++) {
                    chunk += parts[i] + (i < parts.length - 1 ? '\n' : '');
                    lines++;
                    if (lines >= CHUNK_LINES || i === parts.length - 1) { push(chunk, cls, lines); chunk = ''; lines = 0; }
                }
                while (total > MAX_LINES && nodes.length > 1) {
                    const old = nodes.shift();
                    old.el.remove();
                    total -= old.n;
                }
                scrollDown();
            }
            function push(text, cls, n) {
                if (!text) { return; }
                const el = document.createElement('span');
                if (cls) { el.className = cls; }
                el.textContent = text;
                outEl.appendChild(el);
                nodes.push({ el: el, n: n });
                total += n;
            }
            function replaceAll(text) {
                outEl.textContent = '';
                nodes = [];
                total = 0;
                addText(text);
                outEl.scrollTop = outEl.scrollHeight;
            }
            function sys(text) { addText('[Springs] ' + text + '\n', 't-sys'); }

            // ---- what the game answered to the command we just sent ----
            // Some terminals echo the typed line back: it is hidden once (we already show our own '> command' line).
            // Plugin commands (meta, amx_..., sm ...) that print nothing usually mean the plugin is not loaded.
            const PLUGIN_RE = /^(meta|amx\w*|sm|oxide\.\S*|o\.\S*|carbon\.\S*|c\.\S*)(\s|$)/i;
            let pending = null;
            function watchCommand(cmd) {
                const mine = { cmd: cmd, t: Date.now(), echoSeen: false, got: false };
                pending = mine;
                if (!PLUGIN_RE.test(cmd)) { return; }
                setTimeout(() => {
                    if (pending === mine && !mine.got) {
                        sys('The game printed nothing for "' + cmd + '". For plugin commands this usually means the plugin or its framework is not loaded'
                            + (diagBtn ? ' - press Diagnose to find out why.' : '.'));
                    }
                }, 2500);
            }
            function filterAnswer(text) {
                if (!pending || Date.now() - pending.t > 5000) { return text; }
                const parts = text.split('\n');
                let out = '';
                for (let i = 0; i < parts.length; i++) {
                    const last = i === parts.length - 1;
                    const t = parts[i].trim();
                    if (!last && !pending.echoSeen && t === pending.cmd) { pending.echoSeen = true; continue; }
                    if (t !== '') { pending.got = true; }
                    out += parts[i] + (last ? '' : '\n');
                }
                return out;
            }
            function err(text) { addText('[Springs] ' + text + '\n', 't-err'); }

            function setRunning(r) {
                running = !!r;
                badgeEl.className = 'badge ' + (running ? 'badge-online' : 'badge-offline');
                badgeEl.textContent = running ? 'ONLINE' : 'OFFLINE';
            }
            function setLive(state) {
                dotEl.className = 'live-dot' + (state === 'live' ? '' : (state === 'off' ? ' off' : ' paused'));
                labelEl.textContent = state === 'live' ? 'LIVE' : (state === 'off' ? 'DISCONNECTED' : 'RECONNECTING...');
            }

            scrollBtn.addEventListener('click', () => {
                autoScroll = !autoScroll;
                scrollBtn.textContent = 'Auto-Scroll: ' + (autoScroll ? 'ON' : 'OFF');
                if (autoScroll) { outEl.scrollTop = outEl.scrollHeight; }
            });
            clearBtn.addEventListener('click', () => { outEl.textContent = ''; nodes = []; total = 0; });

            // ---- live stream ----
            function handle(ev) {
                if (ev.t === 'snapshot') {
                    replaceAll(ev.log || (ev.running ? '' : '[Springs] The server is offline. Press Start to boot it.\n'));
                    setRunning(ev.running);
                } else if (ev.t === 'lines') {
                    addText(filterAnswer(ev.text));
                } else if (ev.t === 'status') {
                    setRunning(ev.running);
                    if (!ev.running) { sys('The server stopped.'); }
                } else if (ev.t === 'error') {
                    err(ev.message || 'Console error');
                }
            }
            let es = null;
            let failures = 0;
            function connect() {
                es = new EventSource('server.php?id=' + serverId + '&stream=console');
                es.onopen = () => { failures = 0; setLive('live'); };
                es.onmessage = (e) => {
                    try { handle(JSON.parse(e.data)); } catch (x) { /* ignore a broken event */ }
                    setLive('live');
                };
                es.onerror = () => {
                    es.close();
                    failures++;
                    setLive(failures > 3 ? 'off' : 'reconnecting');
                    setTimeout(connect, Math.min(1500 * failures, 8000));
                };
            }
            connect();
            outEl.scrollTop = outEl.scrollHeight;

            // ---- commands ----
            let history = [];
            let historyIdx = -1;
            const HKEY = 'swiftcmd_' + serverId;
            try { history = JSON.parse(localStorage.getItem(HKEY) || '[]').slice(0, 50); } catch (x) { history = []; }
            function remember(cmd) {
                history = [cmd].concat(history.filter((h) => h !== cmd)).slice(0, 50);
                try { localStorage.setItem(HKEY, JSON.stringify(history)); } catch (x) { /* private mode */ }
            }
            cmdForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const cmd = cmdInput.value.trim();
                if (!cmd || sending) { return; }
                sending = true;
                cmdBtn.disabled = true;
                remember(cmd);
                historyIdx = -1;
                cmdInput.value = '';
                addText('> ' + cmd + '\n', 't-cmd');
                watchCommand(cmd);      // the game's echo can arrive before our own request returns
                try {
                    const body = new URLSearchParams();
                    body.append('rcon_cmd', cmd);
                    body.append('csrf', csrf);
                    const r = await fetch('server.php?id=' + serverId + '&ajax=cmd', { method: 'POST', body: body, credentials: 'same-origin' });
                    let j = null;
                    try { j = await r.json(); } catch (x) { j = null; }
                    if (!j) { pending = null; err('The panel sent an unexpected answer (HTTP ' + r.status + ').'); }
                    else if (!j.ok) { pending = null; err(j.error || 'The command was not sent.'); }
                    else {
                        if (j.reply) { if (pending) { pending.got = true; } addText(String(j.reply).replace(/\s+$/, '') + '\n', 't-reply'); }
                        if (j.note) { sys(j.note); }
                    }
                } catch (x) {
                    pending = null;
                    err('Could not reach the panel: ' + x.message);
                } finally {
                    sending = false;
                    cmdBtn.disabled = false;
                    cmdInput.focus();
                }
            });
            cmdInput.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowUp' && history.length > 0) {
                    e.preventDefault();
                    historyIdx = Math.min(historyIdx + 1, history.length - 1);
                    cmdInput.value = history[historyIdx];
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    historyIdx = Math.max(historyIdx - 1, -1);
                    cmdInput.value = historyIdx === -1 ? '' : history[historyIdx];
                }
            });

            // ---- Diagnose (CS 1.6: Metamod / AMX Mod X) ----
            if (diagBtn) {
                diagBtn.addEventListener('click', async () => {
                    diagBtn.disabled = true;
                    sys('Checking Metamod and AMX Mod X...');
                    try {
                        const body = new URLSearchParams();
                        body.append('csrf', csrf);
                        const r = await fetch('server.php?id=' + serverId + '&ajax=diag', { method: 'POST', body: body, credentials: 'same-origin' });
                        let j = null;
                        try { j = await r.json(); } catch (x) { j = null; }
                        if (!j) { err('The panel sent an unexpected answer (HTTP ' + r.status + ').'); }
                        else if (!j.ok) { err(j.error || 'The check failed.'); }
                        else {
                            (j.findings || []).forEach((f) => {
                                if (f.level === 'ok') { addText('  [ok]    ' + f.text + '\n', 't-reply'); }
                                else if (f.level === 'warn') { addText('  [warn]  ' + f.text + '\n', 't-warn'); }
                                else if (f.level === 'error') { addText('  [ERROR] ' + f.text + '\n', 't-err'); }
                                else { addText('  [info]  ' + f.text + '\n', 't-sys'); }
                            });
                        }
                    } catch (x) {
                        err('Could not reach the panel: ' + x.message);
                    } finally {
                        diagBtn.disabled = false;
                    }
                });
            }

            // ---- Update ReHLDS (CS 1.6) ----
            if (rehldsBtn) {
                const rehldsCall = async (what) => {
                    const body = new URLSearchParams();
                    body.append('csrf', csrf);
                    body.append('do', what);
                    const r = await fetch('server.php?id=' + serverId + '&ajax=rehlds', { method: 'POST', body: body, credentials: 'same-origin' });
                    let j = null;
                    try { j = await r.json(); } catch (x) { j = null; }
                    return j || { ok: false, error: 'The panel sent an unexpected answer (HTTP ' + r.status + ').' };
                };
                const runJob = async (what, btns, startMsg) => {
                    btns.forEach((b) => { b.disabled = true; });
                    sys(startMsg);
                    try {
                        let j = await rehldsCall(what);
                        let shown = 0;
                        for (let n = 0; n < 400; n++) {
                            if (!j.ok) { err(j.error || 'The ReHLDS update failed.'); return; }
                            (j.log || []).slice(shown).forEach((l) => { addText('  ' + l + '\n', 't-sys'); });
                            shown = (j.log || []).length;
                            if (j.state === 'done') { sys('ReHLDS ' + (j.version || '') + ' is installed. Start the server, then type "version" in the console to check.'); return; }
                            if (j.state === 'error') { err(j.error || 'The ReHLDS update failed.'); return; }
                            await new Promise((res) => setTimeout(res, 3000));
                            j = await rehldsCall('status');
                        }
                        sys('The job is still running on the node. Press Diagnose in a few minutes to see whether ReHLDS is installed.');
                    } catch (x) {
                        err('Could not reach the panel: ' + x.message);
                    } finally {
                        btns.forEach((b) => { b.disabled = false; });
                    }
                };
                const both = [rehldsBtn, reinstallBtn].filter(Boolean);
                rehldsBtn.addEventListener('click', () => {
                    if (!window.confirm('Install or update ReHLDS?\n\nThe server must be stopped. The game files are switched to the Steam legacy build (required by ReHLDS) and the newest ReHLDS engine is installed. This can take a few minutes. Your configs and plugins are kept. Plugins that patch engine memory (for example Orpheu) do not work with ReHLDS.')) { return; }
                    runJob('install', both, 'Updating ReHLDS: legacy game files first, then the engine. Please wait...');
                });
                if (reinstallBtn) {
                    reinstallBtn.addEventListener('click', () => {
                        if (!window.confirm('FULL REINSTALL?\n\nThis DELETES EVERYTHING in this server\'s folder (maps, configs, plugins, Metamod, AMX Mod X, uploads) and installs Counter-Strike 1.6 again from scratch with the Steam legacy files and ReHLDS.\n\nThe server must be stopped. This cannot be undone.')) { return; }
                        if (!window.confirm('Last check: really delete all files of this server and reinstall?')) { return; }
                        runJob('reinstall', both, 'Full reinstall: deleting the server files and installing again. This takes several minutes, please keep this page open...');
                    });
                }
            }

            // ---- Repair liblist.gam (CS 1.6) ----
            if (repairBtn) {
                repairBtn.addEventListener('click', async () => {
                    if (!window.confirm('Rewrite cstrike/liblist.gam?\n\nIt is cleaned up and set to Metamod when Metamod can really be loaded, otherwise to the stock game library. A backup (liblist.gam.springs-backup) is kept. Restart the server afterwards.')) { return; }
                    repairBtn.disabled = true;
                    sys('Repairing liblist.gam (checking Metamod and its libraries)...');
                    try {
                        const body = new URLSearchParams();
                        body.append('csrf', csrf);
                        const r = await fetch('server.php?id=' + serverId + '&ajax=repair', { method: 'POST', body: body, credentials: 'same-origin' });
                        let j = null;
                        try { j = await r.json(); } catch (x) { j = null; }
                        if (!j) { err('The panel sent an unexpected answer (HTTP ' + r.status + ').'); }
                        else if (!j.ok) { err(j.error || 'The repair failed.'); }
                        else {
                            (j.changes || []).forEach((c) => { addText('  [done]  ' + c + '\n', 't-reply'); });
                            (j.reasons || []).forEach((c) => { addText('  [note]  ' + c + '\n', 't-warn'); });
                            sys((j.using === 'metamod' ? 'Metamod will be loaded' : 'The stock game library will be loaded') + ' at the next start. Restart the server to apply it.');
                        }
                    } catch (x) {
                        err('Could not reach the panel: ' + x.message);
                    } finally {
                        repairBtn.disabled = false;
                    }
                });
            }

            // ---- start / stop / restart without reloading the page ----
            const LABEL = { start: 'Starting the server...', stop: 'Stopping the server (saving first)...', restart: 'Restarting the server...' };
            powerBtns.forEach((btn) => {
                btn.addEventListener('click', async (e) => {
                    e.preventDefault();
                    const action = btn.getAttribute('data-power');
                    powerBtns.forEach((b) => { b.disabled = true; });
                    sys(LABEL[action] || 'Working...');
                    try {
                        const body = new URLSearchParams();
                        body.append('csrf', csrf);
                        body.append('action', action);
                        const r = await fetch('server.php?id=' + serverId + '&ajax=power', { method: 'POST', body: body, credentials: 'same-origin' });
                        let j = null;
                        try { j = await r.json(); } catch (x) { j = null; }
                        if (!j) { err('The panel sent an unexpected answer (HTTP ' + r.status + ').'); }
                        else if (!j.ok) { err(j.error || 'The action failed.'); }
                        else { setRunning(j.running); }
                    } catch (x) {
                        err('Could not reach the panel: ' + x.message);
                    } finally {
                        powerBtns.forEach((b) => { b.disabled = false; });
                    }
                });
            });
        })();
    </script>
<?php require_once __DIR__ . '/legal_lib.php'; echo swiftFooterHtml(); ?>
</body>
</html>
