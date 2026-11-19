<?php
// mods/index.php - Client Area: browse the mods & plugins of a server's game and install them with one click.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/mods_lib.php';

swiftModsEnsureSchema($pdo);
$user = swiftModsRequireClient($pdo);
$userId = (int)$user['id'];
$csrf = swiftModsCsrfToken();
$root = swiftModsBasePath();   // site root, so links work from this folder

$st = $pdo->prepare("SELECT id, name, game, status, ip_address, port FROM servers WHERE user_id = ? ORDER BY name");
$st->execute([$userId]);
$servers = $st->fetchAll();

$serverId = (int)($_POST['server_id'] ?? $_GET['server'] ?? 0);
$current = null;
foreach ($servers as $sv) {
    if ((int)$sv['id'] === $serverId) {
        $current = $sv;
    }
}
if (!$current && $servers) {
    $current = $servers[0];
}

$notice = '';
$error = '';

// ---- install / remove ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $current) {
    $do = (string)($_POST['do'] ?? '');
    if (!swiftModsCsrfOk()) {
        $error = 'Security check failed. Please reload the page and try again.';
    } elseif (!swiftModsRateOk()) {
        $error = 'Too many requests. Please wait a minute and try again.';
    } else {
        $full = swiftModsLoadServer($pdo, (int)$current['id'], $userId);   // proves the server is theirs
        $modId = (int)($_POST['mod_id'] ?? 0);
        if (!$full) {
            $error = 'Server not found.';
        } elseif ($do === 'install') {
            $ms = $pdo->prepare("SELECT * FROM mods WHERE id = ? AND enabled = 1");
            $ms->execute([$modId]);
            $mod = $ms->fetch();
            if (!$mod || $mod['game'] !== $full['game']) {
                $error = 'This item is not available for this server.';
            } else {
                $err = null;
                @set_time_limit(120);
                if (swiftModsInstall($pdo, $full, $mod, $err)) {
                    $notice = '"' . $mod['name'] . '" was installed on "' . $full['name'] . '". Restart the server so it is loaded.'
                        . ($mod['post_note'] !== '' ? ' Note: ' . $mod['post_note'] : '');
                } else {
                    $error = 'Installation failed: ' . $err;
                }
            }
        } elseif ($do === 'remove') {
            $err = null;
            if (swiftModsRemove($pdo, $full, $modId, $err)) {
                $notice = 'The files of this item were removed. Restart the server to apply it.';
            } else {
                $error = 'Could not remove it: ' . $err;
            }
        } else {
            $error = 'Nothing was done: the request did not say what to do. Please reload the page and try again.';
        }
    }
}

// ---- data for the page ----
$catalog = [];
$installed = [];
if ($current) {
    $cs = $pdo->prepare("SELECT * FROM mods WHERE game = ? AND enabled = 1 ORDER BY category, name");
    $cs->execute([$current['game']]);
    $catalog = $cs->fetchAll();
    $is = $pdo->prepare("SELECT * FROM server_mods WHERE server_id = ?");
    $is->execute([(int)$current['id']]);
    foreach ($is->fetchAll() as $row) {
        $installed[(int)$row['mod_id']] = $row;
    }
}
$catalogIds = [];
foreach ($catalog as $m) {
    $catalogIds[(int)$m['id']] = true;
}
$orphans = [];   // installed, but no longer offered in the catalog: can still be removed
foreach ($installed as $mid => $row) {
    if (!isset($catalogIds[$mid])) {
        $orphans[] = $row;
    }
}
$cat = (string)($_GET['cat'] ?? '');
$cats = [];
foreach ($catalog as $m) {
    $cats[$m['category']] = ($cats[$m['category']] ?? 0) + 1;
}
$shown = [];
foreach ($catalog as $m) {
    if ($cat === '' || $m['category'] === $cat) {
        $shown[] = $m;
    }
}
$isInstalling = $current && $current['status'] === 'installing';
?>
<?php require_once dirname(__DIR__) . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mods &amp; Plugins - SwiftPanel</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($root); ?>/assets/style.css?v=3">
    <style>
        .container { max-width: 1180px; margin: 24px auto; padding: 0 18px; }
        .srv-table { width: 100%; border-collapse: collapse; }
        .srv-table th, .srv-table td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #262c3a; font-size: 13px; vertical-align: top; }
        .srv-table th { color: #94a3b8; font-weight: 600; background: #141820; }
        .form-input { background: #0d1117; color: #fff; border: 1px solid #30363d; border-radius: 4px; padding: 8px 10px; font-size: 13px; }
        .chip { display: inline-block; padding: 4px 11px; border-radius: 999px; border: 1px solid #30363d; color: #94a3b8; font-size: 12px; text-decoration: none; margin: 0 6px 6px 0; }
        .chip.active, .chip:hover { border-color: #2ecc71; color: #2ecc71; }
        .tag { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; background: rgba(52,152,219,0.18); color: #3498db; margin-right: 4px; }
        .tag-ok { background: rgba(46,204,113,0.18); color: #2ecc71; }
        .muted { color: #94a3b8; }
        .alert-warn { background: rgba(243,156,18,0.12); border: 1px solid #f39c12; color: #f5b041; padding: 12px 16px; border-radius: 4px; font-size: 13px; margin-bottom: 14px; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#aaa;">Springs Docker Edition</small></div>
        <div class="user-info">Logged in as: <strong><?php echo htmlspecialchars((string)($_SESSION['username'] ?? 'client')); ?></strong> | <a href="<?php echo htmlspecialchars($root); ?>/logout.php">Logout</a></div>
    </div>
    <div id="navbar">
        <ul>
            <li><a href="<?php echo htmlspecialchars($root); ?>/dashboard.php">Game Servers</a></li>
            <li><a href="<?php echo htmlspecialchars($root); ?>/dashboard.php?page=order">Order Server</a></li>
            <li><a href="<?php echo htmlspecialchars($root); ?>/dashboard.php?page=invoices">Invoices</a></li>
            <li><a href="<?php echo htmlspecialchars($root); ?>/mods/index.php" class="active">Mods &amp; Plugins</a></li>
            <li><a href="<?php echo htmlspecialchars($root); ?>/tickets.php">Support Tickets</a></li>
            <li><a href="<?php echo htmlspecialchars($root); ?>/profile.php">My Profile</a></li>
        </ul>
    </div>
    <div class="container">
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <?php if (!$current): ?>
            <div class="box">
                <div class="box-header">Mods &amp; Plugins</div>
                <div class="box-content">
                    <p>You do not have a game server yet. <a href="<?php echo htmlspecialchars($root); ?>/dashboard.php?page=order" style="color:#2ecc71;">Order one</a> and come back to add mods and plugins.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="box" style="margin-bottom:20px;">
                <div class="box-header">Install on server</div>
                <div class="box-content">
                    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0;">
                        <select name="server" class="form-input" onchange="this.form.submit()" style="min-width:260px;">
                            <?php foreach ($servers as $sv): ?>
                                <option value="<?php echo (int)$sv['id']; ?>" <?php echo (int)$sv['id'] === (int)$current['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sv['name'] . ' - ' . swiftGameLabel($sv['game'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="btn">Show</button></noscript>
                        <span class="muted"><?php echo htmlspecialchars($current['ip_address'] . ':' . $current['port']); ?></span>
                    </form>
                    <?php if ($isInstalling): ?>
                        <div class="alert-warn" style="margin:14px 0 0;">This server is still being installed. You can add mods once the installation has finished.</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($orphans)): ?>
                <div class="box" style="margin-bottom:20px;">
                    <div class="box-header">Installed items no longer in the catalog</div>
                    <div class="box-content" style="padding:0;">
                        <table class="srv-table">
                            <tbody>
                                <?php foreach ($orphans as $o): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($o['mod_name'] !== '' ? $o['mod_name'] : 'Item #' . (int)$o['mod_id']); ?></strong> <span class="muted"><?php echo htmlspecialchars((string)$o['version']); ?></span></td>
                                        <td class="muted"><?php echo (int)$o['file_count']; ?> file(s)</td>
                                        <td style="text-align:right;">
                                            <form method="POST" style="margin:0;" onsubmit="return confirm('Remove the files of this item from your server?');">
                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                                <input type="hidden" name="server_id" value="<?php echo (int)$current['id']; ?>">
                                                <input type="hidden" name="mod_id" value="<?php echo (int)$o['mod_id']; ?>">
                                                <button type="submit" name="do" value="remove" class="btn btn-red" style="padding:4px 10px;font-size:12px;">Remove</button>
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
                    <span>Available for <?php echo htmlspecialchars(swiftGameLabel($current['game'])); ?> (<?php echo count($catalog); ?>)</span>
                    <?php if (!empty($catalog)): ?><input type="text" id="mod-search" class="form-input" placeholder="Search..." style="padding:5px 10px;"><?php endif; ?>
                </div>
                <div class="box-content" style="padding:0;">
                    <?php if (empty($catalog)): ?>
                        <p style="padding:20px;margin:0;" class="muted">There are no mods or plugins available for this game yet.</p>
                    <?php else: ?>
                        <div style="padding:14px 14px 4px;">
                            <a class="chip<?php echo $cat === '' ? ' active' : ''; ?>" href="?server=<?php echo (int)$current['id']; ?>">All (<?php echo count($catalog); ?>)</a>
                            <?php foreach ($cats as $cname => $cnt): ?>
                                <a class="chip<?php echo $cat === $cname ? ' active' : ''; ?>" href="?server=<?php echo (int)$current['id']; ?>&amp;cat=<?php echo urlencode($cname); ?>"><?php echo htmlspecialchars($cname); ?> (<?php echo (int)$cnt; ?>)</a>
                            <?php endforeach; ?>
                        </div>
                        <table class="srv-table">
                            <thead>
                                <tr><th>Name</th><th>Description</th><th>Installs to</th><th style="width:170px;">Action</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shown as $m):
                                    $mid = (int)$m['id'];
                                    $inst = $installed[$mid] ?? null;
                                    $search = strtolower($m['name'] . ' ' . $m['category'] . ' ' . $m['author'] . ' ' . $m['description']);
                                ?>
                                    <tr class="mod-row" data-search="<?php echo htmlspecialchars($search); ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($m['name']); ?></strong><br>
                                            <span class="tag"><?php echo htmlspecialchars($m['category']); ?></span>
                                            <?php if ($m['version'] !== ''): ?><span class="muted">v<?php echo htmlspecialchars($m['version']); ?></span><?php endif; ?>
                                            <?php if ($m['author'] !== ''): ?><br><small class="muted">by <?php echo htmlspecialchars($m['author']); ?></small><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo nl2br(htmlspecialchars((string)$m['description'])); ?>
                                            <?php if ($m['source_type'] === 'upload' && (int)$m['file_size'] > 0): ?><br><small class="muted"><?php echo htmlspecialchars(swiftModsFormatBytes($m['file_size'])); ?></small><?php endif; ?>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($m['target_dir'] !== '' ? $m['target_dir'] . '/' : '(server root)'); ?></code>
                                            <?php if ($m['install_kind'] === 'archive'): ?><br><small class="muted">unpacks an archive</small><?php endif; ?></td>
                                        <td>
                                            <?php if ($inst): ?>
                                                <span class="tag tag-ok">Installed<?php echo $inst['version'] !== '' ? ' v' . htmlspecialchars((string)$inst['version']) : ''; ?></span>
                                                <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
                                                    <form method="POST" style="margin:0;" onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent='Working...';">
                                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                                        <input type="hidden" name="server_id" value="<?php echo (int)$current['id']; ?>">
                                                        <input type="hidden" name="mod_id" value="<?php echo $mid; ?>">
                                                        <input type="hidden" name="do" value="install">
                                                        <button type="submit" class="btn" style="padding:4px 10px;font-size:12px;" <?php echo $isInstalling ? 'disabled' : ''; ?>>Update</button>
                                                    </form>
                                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Remove the files of this item from your server?');">
                                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                                        <input type="hidden" name="server_id" value="<?php echo (int)$current['id']; ?>">
                                                        <input type="hidden" name="mod_id" value="<?php echo $mid; ?>">
                                                        <button type="submit" name="do" value="remove" class="btn btn-red" style="padding:4px 10px;font-size:12px;">Remove</button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <form method="POST" style="margin:0;" onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent='Installing...';">
                                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                                    <input type="hidden" name="server_id" value="<?php echo (int)$current['id']; ?>">
                                                    <input type="hidden" name="mod_id" value="<?php echo $mid; ?>">
                                                    <input type="hidden" name="do" value="install">
                                                    <button type="submit" class="btn btn-green" style="padding:5px 14px;font-size:12px;" <?php echo $isInstalling ? 'disabled' : ''; ?>>Install</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            <p class="muted" style="font-size:12px;margin-top:14px;">Installing downloads the files straight into your server folder. Restart the server afterwards so it loads them. Updating an item overwrites its files, and removing it deletes only the files it added. You can see everything in the File Manager.</p>
        <?php endif; ?>
    </div>
    <script>
        (function () {
            var box = document.getElementById('mod-search');
            if (!box) { return; }
            box.addEventListener('input', function () {
                var q = box.value.toLowerCase().trim();
                var rows = document.querySelectorAll('.mod-row');
                Array.prototype.forEach.call(rows, function (r) {
                    var hay = r.getAttribute('data-search') || '';
                    r.style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
                });
            });
        })();
    </script>
<?php require_once dirname(__DIR__) . '/legal_lib.php'; echo swiftFooterHtml(); ?>
</body>
</html>
