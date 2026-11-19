<?php
require_once __DIR__ . '/guard.php';
require_once '../auth.php';
require_once '../config.php';
require_once '../games.php';
require_once '../port_helper.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../dashboard.php');
    exit;
}

if (empty($_SESSION['nodes_csrf'])) {
    $_SESSION['nodes_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['nodes_csrf'];
$notice = '';
$noticeHtml = '';
$errorMsg = '';
$reopen = null;   // node id whose edit window should open again after a validation error
$posted = null;   // what the admin typed, so a validation error does not lose the edits

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------
// "27015-27060, 25565" -> normalised text; errors go to $err. A pool is limited to 500 ports (the allocator reads 500).
function nodesCleanPorts($spec, &$err)
{
    $parts = preg_split('/[\s,;]+/', trim((string)$spec), -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    $total = 0;
    foreach ($parts as $p) {
        if (preg_match('/^(\d{1,5})-(\d{1,5})$/', $p, $m)) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            if ($a > $b) {
                $err = "Port range {$p} is backwards (start is bigger than end).";
                return null;
            }
            $total += $b - $a + 1;
        } elseif (preg_match('/^\d{1,5}$/', $p)) {
            $a = $b = (int)$p;
            $total++;
        } else {
            $err = "'{$p}' is not a port or a range like 27015-27060.";
            return null;
        }
        if ($a < 1024 || $b > 65535) {
            $err = "Ports must be between 1024 and 65535 (found {$p}).";
            return null;
        }
        $out[] = ($a === $b) ? (string)$a : "{$a}-{$b}";
    }
    if ($total > 500) {
        $err = "The port pool has {$total} ports; the allocator handles at most 500. Use a smaller range.";
        return null;
    }
    return implode(', ', array_unique($out));
}

function nodesCleanIps($raw, &$err)
{
    $list = [];
    foreach (preg_split('/[\s,;]+/', trim((string)$raw), -1, PREG_SPLIT_NO_EMPTY) as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $err = "'{$ip}' is not a valid IP address.";
            return null;
        }
        $list[$ip] = true;
    }
    return implode(', ', array_keys($list));
}

function nodesValidToken($t)
{
    return preg_match('/^[A-Fa-f0-9_-]{8,128}$/', (string)$t) === 1;   // the installer only accepts these characters
}

// Validates the posted node fields. Returns [fields, error].
function nodesReadFields($isNew, $existingToken = '')
{
    $name = trim((string)($_POST['name'] ?? ''));
    $location = trim((string)($_POST['location'] ?? '')) ?: 'Europe';
    $ip = trim((string)($_POST['ip_address'] ?? ''));
    $port = (int)($_POST['ssh_port'] ?? 0);
    $token = trim((string)($_POST['ssh_password'] ?? ''));
    $err = '';
    if ($name === '' || mb_strlen($name) > 80) {
        return [null, 'Give the node a name (up to 80 characters).'];
    }
    if (mb_strlen($location) > 80) {
        return [null, 'The location is too long (up to 80 characters).'];
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return [null, "'{$ip}' is not a valid primary IP address."];
    }
    if ($port < 1 || $port > 65535) {
        return [null, 'The daemon port must be between 1 and 65535.'];
    }
    $extra = nodesCleanIps($_POST['extra_ips'] ?? '', $err);
    if ($extra === null) {
        return [null, $err];
    }
    $pool = nodesCleanPorts($_POST['assigned_ports'] ?? '', $err);
    if ($pool === null) {
        return [null, $err];
    }
    if ($token === '' && !$isNew) {
        $token = $existingToken;
    }
    if (!nodesValidToken($token)) {
        return [null, 'The secret token must be 8-128 characters: 0-9, a-f, A-F, _ and - only (the installer rejects anything else).'];
    }
    // the primary IP is not an "additional" IP
    $extra = implode(', ', array_filter(array_map('trim', explode(',', $extra)), function ($x) use ($ip) {
        return $x !== '' && $x !== $ip;
    }));
    return [['name' => $name, 'location' => $location, 'ip' => $ip, 'port' => $port, 'token' => $token, 'extra' => $extra, 'pool' => $pool], ''];
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost && !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $errorMsg = 'Security check failed. Please reload the page and try again.';
    $isPost = false;
}

// ---------------------------------------------------------------------------
// actions
// ---------------------------------------------------------------------------
if ($isPost && isset($_POST['add_node'])) {
    [$f, $err] = nodesReadFields(true);
    if (!$f) {
        $errorMsg = $err;
    } else {
        $dup = $pdo->prepare("SELECT id, name FROM boxes WHERE ip_address = ? AND ssh_port = ?");
        $dup->execute([$f['ip'], $f['port']]);
        if ($d = $dup->fetch()) {
            $errorMsg = "A node with {$f['ip']}:{$f['port']} already exists (#{$d['id']} {$d['name']}).";
        } else {
            $pdo->prepare("INSERT INTO boxes (name, location, ip_address, extra_ips, ssh_port, ssh_user, ssh_password, assigned_ports) VALUES (?, ?, ?, ?, ?, 'springs', ?, ?)")
                ->execute([$f['name'], $f['location'], $f['ip'], $f['extra'], $f['port'], $f['token'], $f['pool']]);
            $cmdUrl = 'curl -fsSL "https://' . ($_SERVER['HTTP_HOST'] ?? 'YOUR-PANEL') . '/springs_install.php?port=' . $f['port'] . '&token=' . urlencode($f['token']) . '" | bash';
            $noticeHtml = "Node '" . htmlspecialchars($f['name']) . "' added. Run this as root on the node:<br><code style='display:inline-block;margin-top:6px;user-select:all;word-break:break-all;'>" . htmlspecialchars($cmdUrl) . "</code>";
        }
    }
}

if ($isPost && isset($_POST['edit_node'])) {
    $boxId = (int)($_POST['box_id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM boxes WHERE id = ?");
    $st->execute([$boxId]);
    $cur = $st->fetch();
    if (!$cur) {
        $errorMsg = "Node #{$boxId} was not found.";
    } else {
        [$f, $err] = nodesReadFields(false, (string)$cur['ssh_password']);
        if (!$f) {
            $errorMsg = $err;
            $reopen = $boxId;
            $posted = $_POST;
        } else {
            // servers keep their own IP: do not strand any of them on an address the node no longer has
            $ss = $pdo->prepare("SELECT id, name, ip_address FROM servers WHERE box_id = ?");
            $ss->execute([$boxId]);
            $allowed = array_merge([$f['ip']], array_filter(array_map('trim', explode(',', $f['extra']))));
            $stranded = [];
            foreach ($ss->fetchAll() as $sv) {
                if (!in_array($sv['ip_address'], $allowed, true)) {
                    $stranded[$sv['ip_address']][] = '#' . $sv['id'] . ' ' . $sv['name'];
                }
            }
            $dup = $pdo->prepare("SELECT id, name FROM boxes WHERE ip_address = ? AND ssh_port = ? AND id != ?");
            $dup->execute([$f['ip'], $f['port'], $boxId]);
            if ($stranded) {
                $bits = [];
                foreach ($stranded as $ip => $names) {
                    $bits[] = $ip . ' (' . implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' +' . (count($names) - 3) . ' more' : '') . ')';
                }
                $errorMsg = 'Servers on this node still use ' . implode('; ', $bits) . '. Keep that address as the primary or an additional IP, or move those servers first.';
                $reopen = $boxId;
                $posted = $_POST;
            } elseif ($d = $dup->fetch()) {
                $errorMsg = "Another node already uses {$f['ip']}:{$f['port']} (#{$d['id']} {$d['name']}).";
                $reopen = $boxId;
                $posted = $_POST;
            } else {
                $pdo->prepare("UPDATE boxes SET name = ?, location = ?, ip_address = ?, extra_ips = ?, ssh_port = ?, ssh_password = ?, assigned_ports = ? WHERE id = ?")
                    ->execute([$f['name'], $f['location'], $f['ip'], $f['extra'], $f['port'], $f['token'], $f['pool'], $boxId]);
                $notice = "Node '{$f['name']}' saved.";
                if ($f['token'] !== (string)$cur['ssh_password'] || $f['port'] !== (int)$cur['ssh_port']) {
                    $notice .= ' The token or daemon port changed: run the installer command on the node again so it matches.';
                }
            }
        }
    }
}

if ($isPost && isset($_POST['delete_node'])) {
    $boxId = (int)($_POST['box_id'] ?? 0);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE box_id = ?");
    $cnt->execute([$boxId]);
    $n = (int)$cnt->fetchColumn();
    if ($n > 0) {
        $errorMsg = "Node #{$boxId} still hosts {$n} server" . ($n === 1 ? '' : 's') . '. Delete or move them first.';
    } else {
        $pdo->prepare("DELETE FROM boxes WHERE id = ?")->execute([$boxId]);
        $notice = "Node #{$boxId} deleted.";
    }
}

// ---------------------------------------------------------------------------
// data for the page
// ---------------------------------------------------------------------------
$nodes = $pdo->query("SELECT * FROM boxes ORDER BY id ASC")->fetchAll();
$hostsAll = [];
foreach ($pdo->query("SELECT id, box_id, name, game, ip_address, port FROM servers ORDER BY port ASC")->fetchAll() as $sv) {
    $hostsAll[(int)$sv['box_id']][] = $sv;
}
$gameShort = function ($g) {
    $c = function_exists('swiftGames') ? swiftGames() : [];
    return $c[$g]['short'] ?? strtoupper((string)$g);
};
$nodeData = [];
$totServers = 0;
$totFree = 0;
foreach ($nodes as $n) {
    $nid = (int)$n['id'];
    $spec = (string)($n['assigned_ports'] ?? '');
    $e = '';
    $normSpec = $spec === '' ? '' : (nodesCleanPorts($spec, $e) ?? $spec);
    $used = [];
    $list = [];
    foreach ($hostsAll[$nid] ?? [] as $sv) {
        $blk = function_exists('swiftPortBlock') ? max(1, (int)swiftPortBlock((string)$sv['game'])) : 1;
        for ($i = 0; $i < $blk; $i++) {
            $used[(int)$sv['port'] + $i] = ['n' => $sv['name'], 'g' => $gameShort($sv['game']), 'id' => (int)$sv['id'], 'main' => $i === 0];
        }
        $list[] = ['id' => (int)$sv['id'], 'name' => $sv['name'], 'game' => $gameShort($sv['game']), 'ip' => $sv['ip_address'], 'port' => (int)$sv['port']];
    }
    $poolAll = [];
    foreach (preg_split('/[\s,;]+/', $spec, -1, PREG_SPLIT_NO_EMPTY) as $part) {
        if (preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
            for ($p = max(1024, (int)$m[1]); $p <= min(65535, (int)$m[2]) && count($poolAll) < 500; $p++) {
                $poolAll[$p] = true;
            }
        } elseif (ctype_digit($part) && (int)$part >= 1024 && (int)$part <= 65535) {
            $poolAll[(int)$part] = true;
        }
    }
    $inPoolUsed = count(array_intersect_key($used, $poolAll));
    $free = count($poolAll) - $inPoolUsed;
    $totServers += count($list);
    $totFree += $free;
    $nodeData[] = [
        'id' => $nid, 'name' => $n['name'], 'location' => $n['location'] ?: 'Europe', 'ip' => $n['ip_address'], 'port' => (int)$n['ssh_port'],
        'extra' => (string)($n['extra_ips'] ?? ''), 'pool' => $spec, 'token' => (string)$n['ssh_password'],
        'used' => (object)$used, 'servers' => $list, 'poolSize' => count($poolAll), 'poolUsed' => $inPoolUsed, 'poolFree' => $free,
    ];
}
$generatedToken = bin2hex(random_bytes(16));
$host = htmlspecialchars($_SERVER['HTTP_HOST'] ?? '');
?>
<?php require_once dirname(__DIR__) . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nodes - SwiftPanel</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
    <style>
        .nd-wrap { max-width: 1380px; margin: 22px auto; padding: 0 18px; }
        .nd-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
        .nd-head h1 { font-size: 22px; font-weight: 700; color: var(--text-primary); letter-spacing: -.01em; }
        .nd-head p { font-size: 13px; color: var(--text-secondary); margin-top: 3px; }
        .nd-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
        .nd-stat { background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 12px; padding: 14px 16px; }
        .nd-stat .k { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); }
        .nd-stat .v { font-size: 26px; font-weight: 700; color: var(--text-primary); margin-top: 4px; font-variant-numeric: tabular-nums; }
        .nd-stat .s { font-size: 12px; color: var(--text-secondary); margin-top: 2px; }
        .nd-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(440px, 1fr)); gap: 14px; }
        .nd-card { background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 14px; padding: 16px; cursor: pointer; transition: border-color .15s, transform .15s, box-shadow .15s; position: relative; }
        .nd-card:hover { border-color: var(--border-glow); box-shadow: 0 6px 22px rgba(0,0,0,.35); transform: translateY(-1px); }
        .nd-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
        .nd-name { font-size: 16px; font-weight: 700; color: var(--text-primary); }
        .nd-meta { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-top: 6px; }
        .nd-chip { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; background: rgba(148,163,184,.13); color: var(--text-secondary); }
        .nd-chip.loc { background: rgba(46,204,113,.14); color: #2ecc71; }
        .nd-chip.ip { font-family: 'JetBrains Mono', monospace; background: var(--bg-input); border: 1px solid var(--border-subtle); color: var(--text-primary); }
        .nd-st { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: rgba(255,255,255,.06); color: var(--text-secondary); white-space: nowrap; }
        .nd-st.on { background: rgba(46,204,113,.16); color: #2ecc71; }
        .nd-st.off { background: var(--danger-dim); color: var(--danger); }
        .nd-bars { margin-top: 14px; display: grid; gap: 9px; }
        .nd-bar .l { display: flex; justify-content: space-between; font-size: 12px; color: var(--text-secondary); margin-bottom: 4px; }
        .nd-bar .l b { color: var(--text-primary); font-weight: 600; font-variant-numeric: tabular-nums; }
        .nd-track { height: 7px; background: #1e293b; border-radius: 999px; overflow: hidden; }
        .nd-fill { height: 100%; width: 0; border-radius: 999px; background: var(--neon); transition: width .4s ease, background .3s; }
        .nd-fill.warn { background: #f39c12; } .nd-fill.crit { background: var(--danger); }
        .nd-foot { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--border-subtle); font-size: 12px; color: var(--text-secondary); }
        .nd-foot .net { font-family: monospace; color: var(--text-primary); }
        .nd-edit { font-size: 11px; color: var(--text-muted); }
        .nd-card:hover .nd-edit { color: var(--neon); }
        .nd-empty { text-align: center; padding: 50px 16px; color: var(--text-muted); background: var(--bg-card); border: 1px dashed var(--border-subtle); border-radius: 14px; }
        .nd-empty strong { display: block; color: var(--text-secondary); font-size: 15px; margin-bottom: 4px; }

        .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(2,6,12,.74); backdrop-filter: blur(3px); z-index: 9999; align-items: center; justify-content: center; padding: 16px; }
        .modal-backdrop.open { display: flex; }
        .modal-card { background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 16px; width: 100%; max-width: 640px; padding: 22px; box-shadow: 0 24px 60px rgba(0,0,0,.6); max-height: 92vh; overflow-y: auto; animation: ndIn .14s ease-out; }
        @keyframes ndIn { from { opacity: 0; transform: translateY(8px) scale(.985); } to { opacity: 1; transform: none; } }
        .modal-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
        .modal-top strong { font-size: 16px; color: var(--text-primary); }
        .modal-x { background: none; border: 0; color: var(--text-muted); font-size: 22px; line-height: 1; cursor: pointer; border-radius: 8px; width: 30px; height: 30px; }
        .modal-x:hover { background: rgba(255,255,255,.06); color: var(--text-primary); }
        .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border-subtle); margin-bottom: 16px; }
        .tab { background: none; border: 0; color: var(--text-secondary); padding: 8px 14px; font-size: 13px; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; }
        .tab.on { color: var(--neon); border-bottom-color: var(--neon); }
        .pane { display: none; } .pane.on { display: block; }
        .fld { display: flex; flex-direction: column; gap: 5px; margin-bottom: 13px; }
        .fld label { font-size: 12px; color: var(--text-secondary); }
        .fld small { font-size: 11px; color: var(--text-muted); }
        .fld input, .fld select, .fld textarea { padding: 9px 11px; background: var(--bg-input); border: 1px solid var(--border-subtle); border-radius: 9px; color: var(--text-primary); font-size: 13px; outline: none; font-family: inherit; }
        .fld input:focus, .fld textarea:focus { border-color: var(--neon); box-shadow: 0 0 0 3px var(--neon-dim); }
        .fld input.bad, .fld textarea.bad { border-color: var(--danger); box-shadow: 0 0 0 3px var(--danger-dim); }
        .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .tokrow { display: flex; gap: 6px; } .tokrow input { flex: 1; font-family: monospace; }
        .tags { display: flex; flex-wrap: wrap; gap: 6px; padding: 7px; background: var(--bg-input); border: 1px solid var(--border-subtle); border-radius: 9px; min-height: 42px; align-items: center; }
        .tags:focus-within { border-color: var(--neon); box-shadow: 0 0 0 3px var(--neon-dim); }
        .tags input { flex: 1; min-width: 140px; background: none; border: 0; box-shadow: none !important; padding: 4px; color: var(--text-primary); font-size: 13px; outline: none; font-family: monospace; }
        .tag { display: inline-flex; align-items: center; gap: 6px; background: var(--neon-dim); color: var(--neon); border-radius: 999px; padding: 3px 4px 3px 10px; font-size: 12px; font-family: monospace; }
        .tag button { background: none; border: 0; color: inherit; cursor: pointer; width: 18px; height: 18px; border-radius: 50%; line-height: 1; }
        .tag button:hover { background: rgba(255,255,255,.15); }
        .tag.primary { background: rgba(59,130,246,.18); color: #60a5fa; padding-right: 10px; }
        .presets { display: flex; gap: 6px; flex-wrap: wrap; margin: -4px 0 12px; }
        .presets button { font-size: 11px; padding: 3px 10px; border-radius: 999px; background: var(--bg-input); border: 1px solid var(--border-subtle); color: var(--text-secondary); cursor: pointer; }
        .presets button:hover { color: var(--neon); border-color: var(--neon); }
        .pstats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 12px; }
        .pstat { background: var(--bg-input); border: 1px solid var(--border-subtle); border-radius: 10px; padding: 8px 10px; }
        .pstat .k { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
        .pstat .v { font-size: 17px; font-weight: 700; color: var(--text-primary); font-variant-numeric: tabular-nums; }
        .pmap { display: flex; flex-wrap: wrap; gap: 3px; padding: 10px; background: var(--bg-input); border: 1px solid var(--border-subtle); border-radius: 10px; max-height: 190px; overflow-y: auto; }
        .pm { width: 13px; height: 13px; border-radius: 3px; border: 1px solid rgba(57,255,20,.45); background: transparent; }
        .pm.used { background: #3b82f6; border-color: #3b82f6; }
        .pm.sub { background: #1d4ed8; border-color: #1d4ed8; opacity: .7; }
        .legend { display: flex; gap: 14px; font-size: 11px; color: var(--text-muted); margin: 8px 0 12px; flex-wrap: wrap; }
        .legend i { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 5px; vertical-align: -1px; }
        .svlist { display: flex; flex-direction: column; gap: 5px; max-height: 150px; overflow-y: auto; }
        .svlist div { display: flex; justify-content: space-between; font-size: 12px; padding: 6px 10px; background: var(--bg-input); border: 1px solid var(--border-subtle); border-radius: 8px; color: var(--text-secondary); }
        .svlist b { color: var(--text-primary); font-weight: 600; }
        .cmdbox { width: 100%; background: #06080b; color: var(--neon); border: 1px solid #1a2e22; border-radius: 9px; padding: 10px; font-family: monospace; font-size: 11.5px; resize: none; height: 78px; }
        .warnbox { background: rgba(243,156,18,.12); border: 1px solid rgba(243,156,18,.4); color: #f8c471; border-radius: 10px; padding: 9px 12px; font-size: 12.5px; margin-bottom: 12px; }
        .dangerbox { background: var(--danger-dim); border: 1px solid rgba(239,68,68,.35); border-radius: 12px; padding: 14px; margin-top: 8px; }
        .dangerbox p { font-size: 12.5px; color: #fca5a5; margin-bottom: 10px; }
        .modal-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; padding-top: 14px; border-top: 1px solid var(--border-subtle); }
        @media (max-width: 900px) { .nd-stats { grid-template-columns: repeat(2, 1fr); } .nd-grid { grid-template-columns: 1fr; } }
        @media (max-width: 560px) { .row2, .pstats { grid-template-columns: 1fr 1fr; } }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#e74c3c;">ADMIN CONTROL</small></div>
        <div class="user-info">
            Logged in as <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'admin'); ?></strong>
            | <a href="login.php?logout=1">Logout</a>
        </div>
    </div>
    <?php $adminNavActive = 'nodes'; include __DIR__ . '/nav.php'; ?>

    <div class="nd-wrap">
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($noticeHtml): ?><div class="alert alert-success"><?php echo $noticeHtml; ?></div><?php endif; ?>
        <?php if ($errorMsg): ?><div class="alert alert-error"><?php echo htmlspecialchars($errorMsg); ?></div><?php endif; ?>

        <div class="nd-head">
            <div>
                <h1>Nodes</h1>
                <p>Your Springs Docker hosts. Click a node to edit its name, IPs and game-port pool.</p>
            </div>
            <button type="button" class="btn btn-green" onclick="openAdd()">+ Add node</button>
        </div>

        <div class="nd-stats">
            <div class="nd-stat"><div class="k">Nodes</div><div class="v"><?php echo count($nodes); ?></div><div class="s"><span id="onlineCount">0</span> online right now</div></div>
            <div class="nd-stat"><div class="k">Servers hosted</div><div class="v"><?php echo $totServers; ?></div><div class="s">across all nodes</div></div>
            <div class="nd-stat"><div class="k">Free game ports</div><div class="v"><?php echo number_format($totFree); ?></div><div class="s">left in the port pools</div></div>
            <div class="nd-stat"><div class="k">Telemetry</div><div class="v" style="font-size:18px;padding-top:6px;color:var(--neon);">Live</div><div class="s">refreshes every 5 seconds</div></div>
        </div>

        <?php if (!$nodes): ?>
            <div class="nd-empty"><strong>No nodes yet</strong>Add your first Springs node, then run the install command on it.</div>
        <?php else: ?>
        <div class="nd-grid">
            <?php foreach ($nodeData as $nd): $nid = $nd['id'];
                $pct = $nd['poolSize'] ? round($nd['poolUsed'] / $nd['poolSize'] * 100) : 0;
                $extraList = array_filter(array_map('trim', explode(',', $nd['extra'])));
            ?>
            <div class="nd-card" id="card-<?php echo $nid; ?>" tabindex="0" role="button" aria-label="Edit node <?php echo htmlspecialchars($nd['name']); ?>" onclick="openEdit(<?php echo $nid; ?>)" onkeydown="if(event.key==='Enter'){openEdit(<?php echo $nid; ?>)}">
                <div class="nd-top">
                    <div>
                        <div class="nd-name"><?php echo htmlspecialchars($nd['name']); ?></div>
                        <div class="nd-meta">
                            <span class="nd-chip loc"><?php echo htmlspecialchars($nd['location']); ?></span>
                            <span class="nd-chip ip"><?php echo htmlspecialchars($nd['ip'] . ':' . $nd['port']); ?></span>
                            <?php foreach (array_slice($extraList, 0, 3) as $x): ?><span class="nd-chip ip"><?php echo htmlspecialchars($x); ?></span><?php endforeach; ?>
                            <?php if (count($extraList) > 3): ?><span class="nd-chip">+<?php echo count($extraList) - 3; ?> IPs</span><?php endif; ?>
                        </div>
                    </div>
                    <span id="st-<?php echo $nid; ?>" class="nd-st">Querying...</span>
                </div>
                <div class="nd-bars">
                    <div class="nd-bar"><div class="l"><span>CPU</span><b id="cpu-t-<?php echo $nid; ?>">--%</b></div><div class="nd-track"><div class="nd-fill" id="cpu-b-<?php echo $nid; ?>"></div></div></div>
                    <div class="nd-bar"><div class="l"><span>Memory</span><b id="ram-t-<?php echo $nid; ?>">-- / -- MB</b></div><div class="nd-track"><div class="nd-fill" id="ram-b-<?php echo $nid; ?>"></div></div></div>
                    <div class="nd-bar"><div class="l"><span>Disk</span><b id="disk-t-<?php echo $nid; ?>">-- / -- GB</b></div><div class="nd-track"><div class="nd-fill" id="disk-b-<?php echo $nid; ?>"></div></div></div>
                    <div class="nd-bar"><div class="l"><span>Game ports</span><b><?php echo $nd['pool'] === '' ? 'default pool (27015-27060)' : $nd['poolUsed'] . ' used / ' . $nd['poolSize'] . ' &middot; ' . $nd['poolFree'] . ' free'; ?></b></div><div class="nd-track"><div class="nd-fill <?php echo $pct >= 90 ? 'crit' : ($pct >= 70 ? 'warn' : ''); ?>" style="width:<?php echo $pct; ?>%"></div></div></div>
                </div>
                <div class="nd-foot">
                    <span><?php echo count($nd['servers']); ?> server<?php echo count($nd['servers']) === 1 ? '' : 's'; ?> &middot; <span class="net" id="net-<?php echo $nid; ?>">&darr; -- &uarr; --</span></span>
                    <span class="nd-edit">Click to edit &rsaquo;</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- edit node -->
    <div id="editModal" class="modal-backdrop" onclick="if(event.target===this) closeModals();">
        <div class="modal-card" role="dialog" aria-modal="true" aria-label="Edit node">
            <div class="modal-top"><strong>Edit node <span id="eTitle" style="color:var(--neon);"></span></strong><button type="button" class="modal-x" onclick="closeModals()" aria-label="Close">&times;</button></div>
            <div class="tabs" id="eTabs">
                <button type="button" class="tab on" data-p="g">General</button>
                <button type="button" class="tab" data-p="n">IP addresses</button>
                <button type="button" class="tab" data-p="p">Game ports</button>
                <button type="button" class="tab" data-p="d">Install &amp; delete</button>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="edit_node" value="1">
                <input type="hidden" name="box_id" id="e_id">
                <input type="hidden" name="extra_ips" id="e_extra">

                <div class="pane on" data-p="g">
                    <div class="fld"><label>Display name</label><input type="text" name="name" id="e_name" maxlength="80" required></div>
                    <div class="fld"><label>Location (shown to clients when they order)</label><input type="text" name="location" id="e_location" maxlength="80" placeholder="Germany (Frankfurt)"></div>
                    <div class="row2">
                        <div class="fld"><label>Primary IP address</label><input type="text" name="ip_address" id="e_ip" required></div>
                        <div class="fld"><label>Daemon port</label><input type="number" name="ssh_port" id="e_port" min="1" max="65535" required></div>
                    </div>
                    <div class="fld"><label>Secret bearer token</label>
                        <div class="tokrow"><input type="password" name="ssh_password" id="e_token" autocomplete="off" placeholder="leave as it is to keep the current token">
                        <button type="button" class="btn" id="e_show">Show</button><button type="button" class="btn" id="e_gen">New</button></div>
                        <small>If you change the token or the daemon port, run the install command (last tab) on the node again.</small></div>
                    <div class="nd-st" id="eStatus" style="align-self:flex-start;display:inline-block;">Querying...</div>
                </div>

                <div class="pane" data-p="n">
                    <div class="fld"><label>Primary IP (from General tab)</label><div class="tags" style="min-height:0;"><span class="tag primary" id="e_primaryTag"></span></div></div>
                    <div class="fld"><label>Additional game IPs</label>
                        <div class="tags" id="e_tags"><input type="text" id="e_tagInput" placeholder="type an IP, press Enter" autocomplete="off"></div>
                        <small>Clients can host on any of these. Press Enter, comma or space to add; paste a list to add many.</small></div>
                    <div class="fld"><label>Servers by IP</label><div class="svlist" id="e_ipUse"></div></div>
                </div>

                <div class="pane" data-p="p">
                    <div class="fld"><label>Game port pool</label>
                        <textarea name="assigned_ports" id="e_pool" rows="2" placeholder="27015-27060, 25565-25585"></textarea>
                        <small>Ports and ranges separated by commas, 1024-65535, at most 500 ports. New servers get the first free port from this pool.</small></div>
                    <div class="presets" id="e_presets">
                        <button type="button" data-v="27015-27060">CS 1.6 / Source</button>
                        <button type="button" data-v="25565-25585">Minecraft</button>
                        <button type="button" data-v="27015-27060, 25565-25585">Both</button>
                        <button type="button" data-v="27015-27115, 25565-25600, 28015-28030">Wide</button>
                    </div>
                    <div class="pstats">
                        <div class="pstat"><div class="k">Pool</div><div class="v" id="ps_size">0</div></div>
                        <div class="pstat"><div class="k">Used</div><div class="v" id="ps_used">0</div></div>
                        <div class="pstat"><div class="k">Free</div><div class="v" id="ps_free">0</div></div>
                        <div class="pstat"><div class="k">Next free</div><div class="v" style="font-size:12px;" id="ps_next">-</div></div>
                    </div>
                    <div class="pmap" id="e_map"></div>
                    <div class="legend"><span><i style="border:1px solid rgba(57,255,20,.6)"></i>free</span><span><i style="background:#3b82f6"></i>used (game port)</span><span><i style="background:#1d4ed8;opacity:.7"></i>used (extra port of a multi-port game)</span></div>
                    <div class="warnbox" id="e_poolWarn" style="display:none;"></div>
                    <div class="fld"><label>Servers on this node</label><div class="svlist" id="e_servers"></div></div>
                </div>

                <div class="pane" data-p="d">
                    <div class="fld"><label>Install command (run as root on the node)</label>
                        <textarea class="cmdbox" id="e_cmd" readonly></textarea>
                        <div><button type="button" class="btn btn-blue" id="e_copy" style="margin-top:8px;">Copy command</button></div></div>
                    <div class="dangerbox"><p id="e_delText"></p>
                        <button type="button" class="btn btn-red" id="e_delBtn">Delete this node</button></div>
                </div>

                <div class="modal-foot" id="e_foot">
                    <button type="button" class="btn" onclick="closeModals()">Cancel</button>
                    <button type="submit" class="btn btn-green">Save changes</button>
                </div>
            </form>
            <form method="POST" id="delForm" style="display:none;">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="box_id" id="d_id"><input type="hidden" name="delete_node" value="1">
            </form>
        </div>
    </div>

    <!-- add node -->
    <div id="addModal" class="modal-backdrop" onclick="if(event.target===this) closeModals();">
        <div class="modal-card" role="dialog" aria-modal="true" aria-label="Add node">
            <div class="modal-top"><strong>Add a Springs node</strong><button type="button" class="modal-x" onclick="closeModals()" aria-label="Close">&times;</button></div>
            <form method="POST" id="addForm">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="add_node" value="1">
                <div class="row2">
                    <div class="fld"><label>Display name</label><input type="text" name="name" maxlength="80" placeholder="EU-Frankfurt-01" required></div>
                    <div class="fld"><label>Location</label><input type="text" name="location" maxlength="80" value="Germany (Frankfurt)"></div>
                </div>
                <div class="row2">
                    <div class="fld"><label>Primary IP address</label><input type="text" name="ip_address" placeholder="185.191.124.120" required></div>
                    <div class="fld"><label>Daemon port</label><input type="number" name="ssh_port" id="a_port" value="8085" min="1" max="65535" required></div>
                </div>
                <div class="fld"><label>Additional game IPs (optional)</label><input type="text" name="extra_ips" placeholder="185.191.124.121, 185.191.124.122"></div>
                <div class="fld"><label>Game port pool</label><input type="text" name="assigned_ports" value="27015-27060, 25565-25585"></div>
                <div class="fld"><label>Secret bearer token</label><input type="text" name="ssh_password" id="a_token" value="<?php echo htmlspecialchars($generatedToken); ?>" style="font-family:monospace;" required></div>
                <div class="fld"><label>Then run this as root on the node</label><textarea class="cmdbox" id="a_cmd" readonly></textarea></div>
                <div class="modal-foot"><button type="button" class="btn" onclick="closeModals()">Cancel</button><button type="submit" class="btn btn-green">Add node</button></div>
            </form>
        </div>
    </div>

    <div id="footer">Powered by Standalone SwiftPanel &bull; Springs Docker Engine</div>

    <script>
    const NODES = <?php echo json_encode($nodeData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const REOPEN = <?php echo json_encode($reopen); ?>;
    const POSTED = <?php echo json_encode($posted ? array_intersect_key($posted, array_flip(['name', 'location', 'ip_address', 'ssh_port', 'extra_ips', 'assigned_ports'])) : null, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const HOST = <?php echo json_encode($_SERVER['HTTP_HOST'] ?? ''); ?>;
    const $ = (id) => document.getElementById(id);
    const nodeStatus = {};
    let cur = null, extras = [];

    function closeModals() { document.querySelectorAll('.modal-backdrop.open').forEach((m) => m.classList.remove('open')); }
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModals(); });
    const cmdFor = (port, token) => 'curl -fsSL "https://' + HOST + '/springs_install.php?port=' + port + '&token=' + token + '" | bash';
    const validIp = (s) => /^(\d{1,3}\.){3}\d{1,3}$/.test(s) ? s.split('.').every((n) => +n <= 255) : /^[0-9a-fA-F:]+$/.test(s) && s.includes(':');
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // ---- tabs
    function tab(name) {
        document.querySelectorAll('#eTabs .tab').forEach((t) => t.classList.toggle('on', t.dataset.p === name));
        document.querySelectorAll('#editForm .pane').forEach((p) => p.classList.toggle('on', p.dataset.p === name));
        $('e_foot').style.display = name === 'd' ? 'none' : '';
    }
    document.querySelectorAll('#eTabs .tab').forEach((t) => t.addEventListener('click', () => tab(t.dataset.p)));

    // ---- extra IP tag editor
    function renderTags() {
        const box = $('e_tags');
        box.querySelectorAll('.tag').forEach((t) => t.remove());
        extras.forEach((ip, i) => {
            const t = document.createElement('span'); t.className = 'tag'; t.textContent = ip;
            const b = document.createElement('button'); b.type = 'button'; b.textContent = '×'; b.setAttribute('aria-label', 'Remove ' + ip);
            b.onclick = () => { extras.splice(i, 1); renderTags(); }; t.appendChild(b); box.insertBefore(t, $('e_tagInput'));
        });
        $('e_extra').value = extras.join(', ');
        renderIpUse();
    }
    function addTags(text) {
        let bad = [];
        text.split(/[\s,;]+/).filter(Boolean).forEach((ip) => {
            if (!validIp(ip)) { bad.push(ip); return; }
            if (ip !== $('e_ip').value.trim() && !extras.includes(ip)) extras.push(ip);
        });
        $('e_tagInput').classList.toggle('bad', bad.length > 0);
        $('e_tagInput').title = bad.length ? 'Not a valid IP: ' + bad.join(', ') : '';
        renderTags();
        return bad;
    }
    $('e_tagInput').addEventListener('keydown', (e) => {
        if (['Enter', ',', ' ', ';'].includes(e.key)) { e.preventDefault(); const bad = addTags(e.target.value); e.target.value = bad.join(' '); }
        else if (e.key === 'Backspace' && !e.target.value && extras.length) { extras.pop(); renderTags(); }
    });
    $('e_tagInput').addEventListener('blur', (e) => { if (e.target.value.trim()) { const bad = addTags(e.target.value); e.target.value = bad.join(' '); } });
    $('e_tagInput').addEventListener('paste', (e) => { e.preventDefault(); addTags((e.clipboardData || window.clipboardData).getData('text')); });
    $('e_ip').addEventListener('input', () => { $('e_primaryTag').textContent = $('e_ip').value.trim() + ' (primary)'; renderIpUse(); });
    function renderIpUse() {
        if (!cur) return;
        const allowed = [$('e_ip').value.trim()].concat(extras);
        const by = {};
        cur.servers.forEach((s) => { (by[s.ip] = by[s.ip] || []).push(s); });
        const rows = Object.keys(by).map((ip) => '<div><span><b>' + esc(ip) + '</b> ' + (allowed.includes(ip) ? '' : '<span style="color:var(--danger)">(not on this node any more!)</span>') + '</span><span>' + by[ip].length + ' server' + (by[ip].length === 1 ? '' : 's') + '</span></div>');
        $('e_ipUse').innerHTML = rows.length ? rows.join('') : '<div><span>No servers use this node yet.</span></div>';
    }

    // ---- port pool: parse, stats, map
    function parsePool(spec) {
        const ports = new Set(), errs = [];
        spec.split(/[\s,;]+/).filter(Boolean).forEach((p) => {
            let m;
            if ((m = p.match(/^(\d{1,5})-(\d{1,5})$/))) {
                const a = +m[1], b = +m[2];
                if (a > b) { errs.push(p + ' is backwards'); return; }
                if (a < 1024 || b > 65535) { errs.push(p + ' is outside 1024-65535'); return; }
                for (let x = a; x <= b; x++) ports.add(x);
            } else if (/^\d{1,5}$/.test(p)) {
                if (+p < 1024 || +p > 65535) { errs.push(p + ' is outside 1024-65535'); return; }
                ports.add(+p);
            } else errs.push("'" + p + "' is not a port or range");
        });
        if (ports.size > 500) errs.push('the pool has ' + ports.size + ' ports (maximum 500)');
        return { ports: Array.from(ports).sort((a, b) => a - b), errs };
    }
    function renderPool() {
        if (!cur) return;
        const spec = $('e_pool').value, r = parsePool(spec), used = cur.used;
        const usedIn = r.ports.filter((p) => used[p]);
        $('ps_size').textContent = r.ports.length; $('ps_used').textContent = usedIn.length; $('ps_free').textContent = r.ports.length - usedIn.length;
        const src = r.ports.filter((p) => p >= 27000 || p < 25000), mc = r.ports.filter((p) => p >= 25000 && p <= 26999);
        const nf = (a) => { const x = (a.length ? a : r.ports).find((p) => !used[p]); return x || 'none'; };
        $('ps_next').textContent = r.ports.length ? 'CS/Source ' + nf(src) + ' · MC ' + nf(mc.length ? mc : []) : (spec.trim() ? '-' : 'defaults');
        $('e_pool').classList.toggle('bad', r.errs.length > 0);
        const map = $('e_map'); map.innerHTML = '';
        const frag = document.createDocumentFragment();
        r.ports.forEach((p) => {
            const c = document.createElement('span'); c.className = 'pm' + (used[p] ? (used[p].main ? ' used' : ' sub') : '');
            c.title = p + (used[p] ? ' - ' + used[p].n + ' (' + used[p].g + ')' : ' - free'); frag.appendChild(c);
        });
        map.appendChild(frag);
        if (!r.ports.length) map.innerHTML = '<span style="color:var(--text-muted);font-size:12px;">' + (spec.trim() ? 'No valid ports yet.' : 'Empty pool: the built-in defaults are used (27015-27060, Minecraft 25565-25600).') + '</span>';
        const outside = Object.keys(used).map(Number).filter((p) => !r.ports.includes(p));
        const w = $('e_poolWarn'), msgs = [];
        if (r.errs.length) msgs.push('Fix before saving: ' + r.errs.join('; ') + '.');
        if (outside.length) msgs.push(outside.length + ' port(s) in use are outside this pool (' + outside.slice(0, 8).join(', ') + (outside.length > 8 ? ', ...' : '') + '). Those servers keep working; the pool only decides new allocations.');
        w.style.display = msgs.length ? '' : 'none'; w.innerHTML = msgs.map(esc).join('<br>');
        $('e_servers').innerHTML = cur.servers.length ? cur.servers.map((s) => '<div><span><b>' + esc(s.name) + '</b> &middot; ' + esc(s.game) + '</span><span>' + esc(s.ip) + ':' + s.port + '</span></div>').join('') : '<div><span>No servers yet.</span></div>';
        return r;
    }
    $('e_pool').addEventListener('input', renderPool);
    $('e_presets').addEventListener('click', (e) => { if (e.target.dataset.v) { $('e_pool').value = e.target.dataset.v; renderPool(); } });

    // ---- token helpers
    const randHex = (n) => Array.from(crypto.getRandomValues(new Uint8Array(n)), (b) => b.toString(16).padStart(2, '0')).join('');
    $('e_show').onclick = () => { const t = $('e_token'); t.type = t.type === 'password' ? 'text' : 'password'; $('e_show').textContent = t.type === 'password' ? 'Show' : 'Hide'; };
    $('e_gen').onclick = () => { $('e_token').type = 'text'; $('e_show').textContent = 'Hide'; $('e_token').value = randHex(16); updateCmd(); };
    function updateCmd() { if (cur) $('e_cmd').value = cmdFor($('e_port').value || cur.port, $('e_token').value.trim() || cur.token); }
    $('e_port').addEventListener('input', updateCmd); $('e_token').addEventListener('input', updateCmd);
    $('e_copy').onclick = () => { navigator.clipboard && navigator.clipboard.writeText($('e_cmd').value); $('e_copy').textContent = 'Copied!'; setTimeout(() => $('e_copy').textContent = 'Copy command', 1800); };

    function setStatusBadge(el, st) { el.className = 'nd-st' + (st === 'on' ? ' on' : st === 'off' ? ' off' : ''); el.textContent = st === 'on' ? 'Online' : st === 'off' ? 'Offline' : 'Querying...'; }

    function openEdit(id) {
        cur = NODES.find((n) => n.id === id); if (!cur) return;
        $('eTitle').textContent = '#' + cur.id + ' ' + cur.name;
        $('e_id').value = cur.id; $('e_name').value = cur.name; $('e_location').value = cur.location;
        $('e_ip').value = cur.ip; $('e_port').value = cur.port; $('e_token').value = ''; $('e_token').type = 'password'; $('e_show').textContent = 'Show';
        $('e_pool').value = cur.pool; extras = [];
        $('e_primaryTag').textContent = cur.ip + ' (primary)';
        cur.extra.split(/[\s,;]+/).filter(Boolean).forEach((ip) => { if (!extras.includes(ip)) extras.push(ip); });
        renderTags(); renderPool(); updateCmd(); tab('g');
        setStatusBadge($('eStatus'), nodeStatus[id] || '');
        const n = cur.servers.length;
        $('e_delText').textContent = n ? 'This node still hosts ' + n + (n === 1 ? ' server' : ' servers') + '. Delete or move them first; the node cannot be removed until it is empty.' : 'Removes the node from the panel. The Springs daemon and Docker on the machine are not touched.';
        $('e_delBtn').disabled = n > 0; $('e_delBtn').style.opacity = n ? .45 : 1;
        $('e_delBtn').onclick = () => { if (confirm('Delete node ' + cur.name + '?')) { $('d_id').value = cur.id; $('delForm').submit(); } };
        $('editModal').classList.add('open'); setTimeout(() => $('e_name').focus(), 30);
    }
    $('editForm').addEventListener('submit', (e) => {
        if ($('e_tagInput').value.trim()) addTags($('e_tagInput').value);
        const r = renderPool();
        if (r && r.errs.length) { e.preventDefault(); tab('p'); $('e_pool').focus(); }
    });

    function openAdd() { $('addModal').classList.add('open'); updateAdd(); }
    function updateAdd() { $('a_cmd').value = cmdFor($('a_port').value || 8085, $('a_token').value.trim()); }
    $('a_port').addEventListener('input', updateAdd); $('a_token').addEventListener('input', updateAdd);

    // ---- live telemetry (unchanged endpoint)
    const fmtSpeed = (k) => k >= 1024 ? (k / 1024).toFixed(2) + ' MB/s' : k.toFixed(1) + ' KB/s';
    const level = (p) => p >= 90 ? 'crit' : p >= 75 ? 'warn' : '';
    function setBar(id, pct) { const b = $(id); b.style.width = Math.min(100, pct) + '%'; b.className = 'nd-fill ' + level(pct); }
    async function poll(id) {
        const badge = $('st-' + id);
        try {
            const data = await (await fetch('../node_stats_ajax.php?id=' + id)).json();
            if (data.status === 'ok' && data.stats) {
                const s = data.stats; nodeStatus[id] = 'on'; setStatusBadge(badge, 'on');
                $('cpu-t-' + id).textContent = s.cpu.toFixed(1) + '%'; setBar('cpu-b-' + id, s.cpu);
                const rp = s.ram_used / Math.max(1, s.ram_total) * 100;
                $('ram-t-' + id).textContent = s.ram_used + ' / ' + s.ram_total + ' MB (' + rp.toFixed(0) + '%)'; setBar('ram-b-' + id, rp);
                const dp = s.disk_used / Math.max(.1, s.disk_total) * 100;
                $('disk-t-' + id).textContent = s.disk_used + ' / ' + s.disk_total + ' GB (' + dp.toFixed(0) + '%)'; setBar('disk-b-' + id, dp);
                $('net-' + id).innerHTML = '&darr; ' + fmtSpeed(s.net_rx_kbps) + ' &uarr; ' + fmtSpeed(s.net_tx_kbps);
            } else { nodeStatus[id] = 'off'; setStatusBadge(badge, 'off'); }
        } catch (e) { nodeStatus[id] = 'off'; setStatusBadge(badge, 'off'); }
        if (cur && cur.id === id) setStatusBadge($('eStatus'), nodeStatus[id]);
        $('onlineCount').textContent = Object.values(nodeStatus).filter((v) => v === 'on').length;
    }
    document.addEventListener('DOMContentLoaded', () => {
        NODES.forEach((n) => poll(n.id)); setInterval(() => NODES.forEach((n) => poll(n.id)), 5000);
        if (REOPEN) {
            openEdit(REOPEN);
            if (POSTED) {
                $('e_name').value = POSTED.name || ''; $('e_location').value = POSTED.location || ''; $('e_ip').value = POSTED.ip_address || '';
                $('e_port').value = POSTED.ssh_port || ''; $('e_pool').value = POSTED.assigned_ports || '';
                extras = []; addTags(POSTED.extra_ips || ''); $('e_primaryTag').textContent = $('e_ip').value.trim() + ' (primary)'; renderPool(); updateCmd();
            }
        }
    });
    </script>
</body>
</html>
