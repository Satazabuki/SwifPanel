<?php
require_once 'auth.php';
require_once 'config.php';
require_once 'games.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/sbackups/sbackup_lib.php';

$userId = (int)$_SESSION['user_id'];
$serverId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');

$stmt = $pdo->prepare("SELECT s.*, b.name AS box_name, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token FROM servers s JOIN boxes b ON s.box_id = b.id WHERE s.id = ?" . ($isAdmin ? "" : " AND s.user_id = ?"));
$stmt->execute($isAdmin ? [$serverId] : [$serverId, $userId]);
$server = $stmt->fetch();
if (!$server) { header("Location: dashboard.php"); exit; }

if (empty($_SESSION['sb_csrf'])) { $_SESSION['sb_csrf'] = bin2hex(random_bytes(16)); }
$csrf = (string)$_SESSION['sb_csrf'];
session_write_close();

sbEnsureSchema($pdo);
$lim = sbLimits($pdo, (int)$server['id']);
$cfg = sbServerCfg($pdo, (int)$server['id']);
$freqs = sbAllowedFrequencies($lim['min_interval_hours']);

function sbJson($data, int $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(swiftLangJsonData($data), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sbSchedInfo(array $cfg, array $lim): array
{
    return [
        'enabled' => (int)$cfg['sched_enabled'] === 1, 'every' => $cfg['sched_every'], 'hour' => (int)$cfg['sched_hour'],
        'dow' => (int)$cfg['sched_dow'], 'keep' => (int)$cfg['sched_keep'],
        'next_run' => $cfg['next_run'], 'last_run' => $cfg['last_run'], 'last_status' => sbStatusText($cfg['last_status']),
        'allowed' => $lim['allow_schedule'] && !$lim['disabled'],
    ];
}

if (isset($_GET['download'])) {
    if ($lim['disabled'] && !$isAdmin) { http_response_code(403); echo 'Backups are disabled for this server.'; exit; }
    $err = sbStreamDownload($server, (string)$_GET['download']);
    if ($err !== '') { http_response_code(404); echo htmlspecialchars(t($err)); }
    exit;
}

$ajax = (string)($_GET['ajax'] ?? '');
if ($ajax !== '') {
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    if ($ajax !== 'list' && (!$isPost || !hash_equals($csrf, (string)($_POST['csrf'] ?? '')))) {
        sbJson(['ok' => false, 'error' => 'Security check failed. Please reload the page.']);
    }
    $limitsOut = ['max_backups' => $lim['max_backups'], 'disabled' => $lim['disabled']];
    $installing = ($server['status'] ?? '') === 'installing';

    if ($ajax === 'list') {
        $r = sbCall($server, '/api/backups/list', [], 15);
        if ($r === null) { sbJson(['ok' => false, 'error' => 'Could not reach the server node.', 'limits' => $limitsOut]); }
        if (empty($r['ok'])) { sbJson(['ok' => false, 'error' => 'This node runs an old version of the agent. Ask the administrator to update the node.', 'limits' => $limitsOut]); }
        sbJson(['ok' => true, 'items' => $r['items'] ?? [], 'total_size' => (int)($r['total_size'] ?? 0), 'job' => $r['job'] ?? null,
            'limits' => $limitsOut, 'schedule' => sbSchedInfo(sbServerCfg($pdo, (int)$server['id']), $lim)]);
    }
    if ($lim['disabled']) { sbJson(['ok' => false, 'error' => 'Backups are disabled for this server.']); }
    if ($installing) { sbJson(['ok' => false, 'error' => 'Server is still installing']); }

    if ($ajax === 'create') {
        $r = sbCall($server, '/api/backups/create', ['kind' => 'manual', 'max_count' => $lim['max_backups'], 'max_bytes' => $lim['max_bytes']], 20);
        if ($r === null) { sbJson(['ok' => false, 'error' => 'Could not reach the server node.']); }
        sbJson(['ok' => !empty($r['ok']), 'error' => (string)($r['error'] ?? '')]);
    }
    if ($ajax === 'delete') {
        $r = sbCall($server, '/api/backups/delete', ['id' => (string)($_POST['id'] ?? '')], 20);
        if ($r === null) { sbJson(['ok' => false, 'error' => 'Could not reach the server node.']); }
        sbJson(['ok' => !empty($r['ok']), 'error' => (string)($r['error'] ?? '')]);
    }
    if ($ajax === 'restore') {
        $id = (string)($_POST['id'] ?? '');
        $stop = sbCall($server, '/api/servers/stop', [], 90);      // the server must be stopped; the agent saves the game first
        if ($stop === null) { sbJson(['ok' => false, 'error' => 'Could not reach the server node.']); }
        $r = sbCall($server, '/api/backups/restore', ['id' => $id], 30);
        if ($r === null) { sbJson(['ok' => false, 'error' => 'Could not reach the server node.']); }
        sbJson(['ok' => !empty($r['ok']), 'error' => (string)($r['error'] ?? '')]);
    }
    if ($ajax === 'schedule') {
        if (!$lim['allow_schedule']) { sbJson(['ok' => false, 'error' => 'Scheduled backups are switched off by the administrator.']); }
        $enabled = !empty($_POST['enabled']) ? 1 : 0;
        $every = (string)($_POST['every'] ?? 'daily');
        if (!in_array($every, $freqs, true)) { sbJson(['ok' => false, 'error' => 'That frequency is not allowed.']); }
        $hour = max(0, min(23, (int)($_POST['hour'] ?? 3)));
        $dow = max(0, min(6, (int)($_POST['dow'] ?? 1)));
        $keep = max(1, min(50, (int)($_POST['keep'] ?? 3)));
        if ($lim['max_backups'] > 0 && $keep > $lim['max_backups']) { $keep = $lim['max_backups']; }
        $f = ['sched_enabled' => $enabled, 'sched_every' => $every, 'sched_hour' => $hour, 'sched_dow' => $dow, 'sched_keep' => $keep];
        $f['next_run'] = $enabled ? gmdate('Y-m-d H:i:s', sbNextRun($every, $hour, $dow, time())) : null;
        if (!$enabled) { $f['last_status'] = ''; }
        sbSaveServerCfg($pdo, (int)$server['id'], $f);
        sbJson(['ok' => true, 'schedule' => sbSchedInfo(sbServerCfg($pdo, (int)$server['id']), $lim)]);
    }
    sbJson(['ok' => false, 'error' => 'Unknown action.']);
}

$title = htmlspecialchars((string)$server['name']);
$sid = (int)$server['id'];
$dowNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$freqLabels = ['6h' => 'Every 6 hours', '12h' => 'Every 12 hours', 'daily' => 'Every day', 'weekly' => 'Every week'];
?>
<?php require_once __DIR__ . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo sprintf(t('Backups - %s'), $title); ?></title>
<link rel="stylesheet" href="assets/style.css?v=3">
<style>
:root{--ink:#e6edf3;--ink2:#aab4c3;--muted:#7d8899;--card:#141820;--line:#262c3a}
.container{max-width:1100px;margin:24px auto;padding:0 18px}
.top{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px}
.top h1{font-size:20px;margin:0;color:#fff}.top .sub{color:var(--muted);font-size:13px;margin-top:3px}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px}
.tile{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
.tile .k{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.tile .v{font-size:24px;font-weight:700;color:#fff;margin-top:4px;font-variant-numeric:tabular-nums}.tile .v small{font-size:13px;color:var(--ink2);font-weight:400}
.bar{height:6px;border-radius:4px;background:#1d2433;margin-top:10px;overflow:hidden}.bar i{display:block;height:100%;background:var(--neon,#39ff14);width:0;transition:width .3s}.bar.warn i{background:#f5a623}.bar.full i{background:#e74c3c}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px}
.card h2{font-size:15px;margin:0 0 2px;color:#fff}.card .hint{font-size:12px;color:var(--muted);margin-bottom:12px;line-height:1.6}
.row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
table.t{width:100%;border-collapse:collapse;font-size:13px}
table.t th{text-align:left;color:var(--muted);font-weight:500;font-size:12px;padding:6px 8px;border-bottom:1px solid var(--line)}
table.t td{padding:9px 8px;border-bottom:1px solid #1b2130;color:var(--ink);vertical-align:middle}
table.t td.act{text-align:right;white-space:nowrap}
.badge{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;border:1px solid #2f3b52;color:var(--ink2)}.badge.auto{border-color:#2d4d7a;color:#7fb2ff}.badge.run{border-color:#7a6a2d;color:#f5c542}
.empty{color:var(--muted);font-size:13px;padding:22px 6px;text-align:center}
.msg{display:none;margin-bottom:14px;padding:10px 14px;border-radius:8px;font-size:13px}.msg.ok{display:block;background:rgba(46,204,113,.1);border:1px solid #1e6b3f;color:#7ee2a8}.msg.err{display:block;background:rgba(231,76,60,.1);border:1px solid #7a2a24;color:#ff9a90}.msg.info{display:block;background:#1a2230;border:1px solid #2a3550;color:var(--ink2)}
.form-input{background:#0d1117;color:#fff;border:1px solid #30363d;border-radius:6px;padding:8px 10px;font-size:13px}
label.lbl{display:block;font-size:12px;color:var(--muted);margin-bottom:4px}
.sgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:12px 0}
.btnx{cursor:pointer;border:1px solid #30363d;background:#161c26;color:#fff;border-radius:6px;padding:6px 12px;font-size:12px}.btnx:hover{border-color:var(--neon,#39ff14)}.btnx.red{border-color:#7a2a24;color:#ff9a90}.btnx[disabled]{opacity:.45;cursor:not-allowed}
.spin{display:inline-block;width:11px;height:11px;border:2px solid #f5c542;border-top-color:transparent;border-radius:50%;animation:sp 1s linear infinite;margin-right:6px;vertical-align:-1px}@keyframes sp{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<div id="header">
    <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#aaa;">Springs Docker Edition</small></div>
    <div class="user-info">Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'client'); ?></strong> | <a href="logout.php">Logout</a></div>
</div>
<div id="navbar"><ul>
    <li><a href="dashboard.php" class="active">Game Servers</a></li>
    <li><a href="dashboard.php?page=order">Order Server</a></li>
    <li><a href="dashboard.php?page=invoices">Invoices</a></li>
    <li><a href="mods/index.php">Mods &amp; Plugins</a></li>
    <li><a href="tickets.php">Support Tickets</a></li>
    <li><a href="profile.php">My Profile</a></li>
</ul></div>

<div class="container">
    <div class="top">
        <div>
            <h1><?php echo sprintf(t('%s &middot; Backups'), $title); ?></h1>
            <div class="sub"><code><?php echo htmlspecialchars($server['ip_address'] . ':' . $server['port']); ?></code> &middot; <?php echo htmlspecialchars($server['box_name']); ?>
                &middot; <a href="server.php?id=<?php echo $sid; ?>">Console</a> &middot; <a href="files.php?id=<?php echo $sid; ?>">Files</a> &middot; <a href="dashboard.php">Back to servers</a></div>
        </div>
        <button class="btn btn-green" id="btnCreate" type="button" <?php echo $lim['disabled'] ? 'disabled' : ''; ?>>Create backup now</button>
    </div>

    <div id="msg" class="msg"></div>
    <?php if ($lim['disabled']): ?><div class="msg info" style="display:block">Backups are disabled for this server. Please contact support if you need them.</div><?php endif; ?>

    <div class="tiles">
        <div class="tile"><div class="k">Backups</div><div class="v" id="tCount">&ndash;</div><div class="bar" id="bCount"><i></i></div></div>
        <div class="tile"><div class="k">Storage used</div><div class="v" id="tSize">&ndash;</div></div>
        <div class="tile"><div class="k">Scheduled backups</div><div class="v" id="tSched" style="font-size:18px;margin-top:8px">&ndash;</div></div>
    </div>

    <div class="card">
        <h2>Your backups</h2>
        <div class="hint">A backup is a copy of all files of this server (maps, configs, plugins, worlds). Backups are kept on the game node, separate from the server files, so they survive a reinstall. Restoring replaces the current server files with the backup.</div>
        <div id="list"><div class="empty">Loading...</div></div>
    </div>

    <div class="card" id="schedCard">
        <h2>Scheduled backups</h2>
        <div class="hint" id="schedHint">The panel makes a backup automatically. When the limit is reached, the oldest scheduled backup is replaced; backups you made yourself are never deleted automatically. Times are UTC.</div>
        <div id="schedOff" class="empty" style="display:none">Scheduled backups are switched off for this server.</div>
        <form id="schedForm" onsubmit="return false">
            <label style="display:inline-flex;gap:8px;align-items:center;color:#fff;cursor:pointer"><input type="checkbox" id="sEnabled"> Make backups automatically</label>
            <div class="sgrid">
                <div><label class="lbl" for="sEvery">How often</label>
                    <select id="sEvery" class="form-input" style="width:100%"><?php foreach ($freqs as $f): ?><option value="<?php echo $f; ?>"><?php echo htmlspecialchars($freqLabels[$f]); ?></option><?php endforeach; ?></select></div>
                <div id="dowBox"><label class="lbl" for="sDow">Day</label>
                    <select id="sDow" class="form-input" style="width:100%"><?php foreach ($dowNames as $i => $n): ?><option value="<?php echo $i; ?>"><?php echo $n; ?></option><?php endforeach; ?></select></div>
                <div id="hourBox"><label class="lbl" for="sHour">Time (UTC)</label>
                    <select id="sHour" class="form-input" style="width:100%"><?php for ($h = 0; $h < 24; $h++): ?><option value="<?php echo $h; ?>"><?php echo sprintf('%02d:00', $h); ?></option><?php endfor; ?></select>
                    <div class="hint" id="localHint" style="margin:4px 0 0"></div></div>
                <div><label class="lbl" for="sKeep">Scheduled backups to keep</label>
                    <input type="number" id="sKeep" class="form-input" min="1" max="50" style="width:100%"></div>
            </div>
            <button class="btn btn-green" id="btnSched" type="button">Save schedule</button>
            <span class="hint" id="schedStatus" style="margin-left:10px"></span>
        </form>
    </div>
</div>

<script>
(function () {
    var SID = <?php echo $sid; ?>, CSRF = <?php echo json_encode($csrf); ?>, BASE = 'server_backups.php?id=' + SID;
    var DOWS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var $ = function (id) { return document.getElementById(id); };
    var state = { busy: false, timer: null, data: null };
    var DISABLED = <?php echo json_encode($lim['disabled']); ?>;

    function say(text, kind) { var m = $('msg'); m.className = 'msg ' + (kind || 'info'); m.textContent = text; if (kind === 'ok') setTimeout(function () { if (m.textContent === text) m.className = 'msg'; }, 6000); }
    function size(b) { if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB'; if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB'; return Math.max(1, Math.round(b / 1024)) + ' KB'; }
    function utc(s) { return s ? new Date(s.replace(' ', 'T') + 'Z') : null; }
    function post(action, extra) {
        var fd = new FormData(); fd.append('csrf', CSRF);
        for (var k in (extra || {})) fd.append(k, extra[k]);
        return fetch(BASE + '&ajax=' + action, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, error: 'The panel sent an unexpected answer.' }; });
    }
    function bar(el, used, max) {
        var pct = max > 0 ? Math.min(100, used * 100 / max) : 0;
        el.firstElementChild.style.width = pct + '%'; el.className = 'bar' + (pct >= 100 ? ' full' : pct >= 80 ? ' warn' : '');
    }

    function render(d) {
        state.data = d;
        var L = d.limits || {};
        var items = d.items || [], done = items.filter(function (i) { return !i.running; });
        $('tCount').innerHTML = done.length + ' <small>/ ' + (L.max_backups > 0 ? L.max_backups : 'unlimited') + '</small>';
        $('tSize').innerHTML = size(d.total_size || 0);
        bar($('bCount'), done.length, L.max_backups);
        var job = d.job, running = job && job.state === 'running';
        var h = '';
        if (!items.length) h = '<div class="empty">You have no backups yet. Press "Create backup now" to make the first one.</div>';
        else {
            h = '<table class="t"><tr><th>Created</th><th>Type</th><th>Size</th><th></th></tr>';
            items.forEach(function (i) {
                var when = new Date(i.ts * 1000).toLocaleString();
                var type = i.running ? '<span class="badge run"><span class="spin"></span>' + (job && job.kind === 'restore' ? 'Restoring' : 'Creating...') + '</span>' : '<span class="badge ' + (i.kind === 'auto' ? 'auto' : '') + '">' + (i.kind === 'auto' ? 'Scheduled' : 'Manual') + '</span>';
                h += '<tr><td>' + when + '</td><td>' + type + '</td><td>' + (i.running ? '&hellip;' : size(i.size)) + '</td><td class="act">';
                if (!i.running) {
                    h += '<a class="btnx" href="' + BASE + '&download=' + encodeURIComponent(i.id) + '">Download</a> ';
                    h += '<button class="btnx" data-act="restore" data-id="' + i.id + '"' + (running ? ' disabled' : '') + '>Restore</button> ';
                    h += '<button class="btnx red" data-act="delete" data-id="' + i.id + '"' + (running ? ' disabled' : '') + '>Delete</button>';
                }
                h += '</td></tr>';
            });
            h += '</table>';
        }
        if (job && job.kind === 'restore' && running) h = '<div class="msg info" style="display:block"><span class="spin"></span>Restoring the backup. Please keep this page open; the server stays stopped until you start it.</div>' + h;
        $('list').innerHTML = h;
        $('btnCreate').disabled = DISABLED || running;
        if (job && job.state === 'failed' && job.error && !state.shownError) { say(job.error, 'err'); state.shownError = job.finished; }
        if (job && job.state === 'done' && state.waiting) { say(job.kind === 'restore' ? 'The backup was restored. Start the server when you are ready.' : 'The backup is ready.', 'ok'); }
        if (!running) state.waiting = false;
        schedule(d.schedule);
        clearTimeout(state.timer); state.timer = setTimeout(load, running ? 2500 : 30000);
    }

    function schedule(s) {
        if (!s) return;
        var on = s.enabled && s.allowed;
        $('tSched').innerHTML = !s.allowed ? 'Not available' : on ? (s.next_run ? 'Next: ' + utc(s.next_run).toLocaleString() : 'On') : 'Off';
        $('schedForm').style.display = s.allowed ? '' : 'none'; $('schedOff').style.display = s.allowed ? 'none' : '';
        if (!state.schedLoaded) {
            $('sEnabled').checked = s.enabled; $('sKeep').value = s.keep; $('sHour').value = s.hour; $('sDow').value = s.dow;
            if ([].some.call($('sEvery').options, function (o) { return o.value === s.every; })) $('sEvery').value = s.every;
            state.schedLoaded = true; every();
        }
        $('schedStatus').textContent = s.last_status ? 'Last run: ' + s.last_status : '';
    }
    function every() {
        var w = $('sEvery').value;
        $('dowBox').style.display = w === 'weekly' ? '' : 'none';
        $('hourBox').style.display = (w === 'daily' || w === 'weekly') ? '' : 'none';
        var h = parseInt($('sHour').value, 10), d = new Date(); d.setUTCHours(h, 0, 0, 0);
        $('localHint').textContent = 'Your local time: ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function load() {
        fetch(BASE + '&ajax=list', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) {
                say(d.error || 'Could not load backups.', 'err');
                $('list').innerHTML = '<div class="empty">&mdash;</div>';
                clearTimeout(state.timer); state.timer = setTimeout(load, 15000);
                return;
            }
            render(d);
        }).catch(function () { say('Could not load backups.', 'err'); clearTimeout(state.timer); state.timer = setTimeout(load, 15000); });
    }

    $('btnCreate').onclick = function () {
        this.disabled = true; state.waiting = true; state.shownError = 0;
        post('create').then(function (r) { if (!r.ok) { say(r.error || 'The backup could not be started.', 'err'); state.waiting = false; } else say('Backup started. Large servers can take a few minutes.', 'info'); load(); });
    };
    $('list').onclick = function (e) {
        var b = e.target.closest('button[data-act]'); if (!b) return;
        var id = b.getAttribute('data-id');
        if (b.getAttribute('data-act') === 'delete') {
            if (!window.confirm('Delete this backup permanently?')) return;
            b.disabled = true;
            post('delete', { id: id }).then(function (r) { say(r.ok ? 'Backup deleted.' : (r.error || 'Could not delete it.'), r.ok ? 'ok' : 'err'); load(); });
        } else {
            if (!window.confirm('Restore this backup?\n\nThe server will be stopped and ALL current files of this server will be replaced by the files in the backup. Changes made after the backup was created are lost. Make a new backup first if you may need the current state.')) return;
            b.disabled = true; state.waiting = true; state.shownError = 0;
            say('Stopping the server and starting the restore...', 'info');
            post('restore', { id: id }).then(function (r) { if (!r.ok) { say(r.error || 'The restore could not be started.', 'err'); state.waiting = false; } load(); });
        }
    };
    $('sEvery').onchange = every; $('sHour').onchange = every;
    $('btnSched').onclick = function () {
        post('schedule', { enabled: $('sEnabled').checked ? 1 : 0, every: $('sEvery').value, hour: $('sHour').value, dow: $('sDow').value, keep: $('sKeep').value })
            .then(function (r) {
                if (!r.ok) { say(r.error || 'Could not save the schedule.', 'err'); return; }
                state.schedLoaded = false; $('sKeep').value = r.schedule.keep; schedule(r.schedule); say('Schedule saved.', 'ok');
            });
    };
    load();
})();
</script>
<?php require_once __DIR__ . '/legal_lib.php'; echo swiftFooterHtml(); ?>
</body>
</html>
