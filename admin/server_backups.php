<?php
require_once 'guard.php';
require_once '../config.php';
require_once '../lang.php';
require_once '../sbackups/sbackup_lib.php';

sbEnsureSchema($pdo);
if (empty($_SESSION['sbadm_csrf'])) {
    $_SESSION['sbadm_csrf'] = bin2hex(random_bytes(16));
}
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['sbadm_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Security check failed. Please reload the page and try again.';
    } elseif (isset($_POST['save_defaults'])) {
        $max = (int)($_POST['default_max_backups'] ?? 5);
        $min = (int)($_POST['min_interval_hours'] ?? 6);
        if ($max < 0 || $max > 1000) {
            $error = 'The number of backups must be between 0 and 1000 (0 = unlimited).';
        } elseif (!in_array($min, array_values(SB_FREQUENCIES), true)) {
            $error = 'Invalid minimum interval.';
        } else {
            sbSaveSetting($pdo, 'default_max_backups', $max);
            sbSaveSetting($pdo, 'allow_schedule', !empty($_POST['allow_schedule']) ? 1 : 0);
            sbSaveSetting($pdo, 'min_interval_hours', $min);
            $notice = 'Backup settings saved.';
        }
    } elseif (isset($_POST['save_server'])) {
        $sid = (int)($_POST['server_id'] ?? 0);
        $chk = $pdo->prepare("SELECT id FROM servers WHERE id = ?");
        $chk->execute([$sid]);
        $raw = trim((string)($_POST['max_backups'] ?? ''));
        if (!$chk->fetchColumn()) {
            $error = 'Server not found.';
        } elseif ($raw !== '' && (!ctype_digit($raw) || (int)$raw > 1000)) {
            $error = 'The number of backups must be between 0 and 1000 (0 = unlimited), or empty to use the default.';
        } else {
            sbSaveServerCfg($pdo, $sid, ['max_backups' => $raw === '' ? null : (int)$raw, 'disabled' => !empty($_POST['disabled']) ? 1 : 0]);
            $notice = "Server #{$sid}: backup settings saved.";
        }
    }
}

$g = sbSettings($pdo);
$search = trim((string)($_GET['q'] ?? ''));
$sql = "SELECT s.id, s.name, s.game, u.username AS owner, c.max_backups, c.disabled, c.sched_enabled, c.sched_every, c.last_run, c.last_status
        FROM servers s LEFT JOIN users u ON u.id = s.user_id LEFT JOIN sbackup_server c ON c.server_id = s.id";
$args = [];
if ($search !== '') {
    $sql .= " WHERE s.name LIKE ? OR u.username LIKE ?";
    $args = ['%' . $search . '%', '%' . $search . '%'];
}
$sql .= " ORDER BY s.id DESC LIMIT 300";
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$intervals = [6 => 'Every 6 hours', 12 => 'Every 12 hours', 24 => 'Daily', 168 => 'Weekly'];
?>
<?php require_once dirname(__DIR__) . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Server Backups - Admin Panel</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
    <style>
        .container { max-width: 1100px; margin: 24px auto; padding: 0 18px; }
        .form-input { background: #0d1117; color: #fff; border: 1px solid #30363d; border-radius: 4px; padding: 8px 10px; font-size: 14px; }
        label { display: block; font-size: 12px; color: #94a3b8; margin-bottom: 4px; }
        .grid3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 14px; }
        table.t { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.t th { text-align: left; color: #94a3b8; font-weight: 500; font-size: 12px; padding: 8px; border-bottom: 1px solid #263042; }
        table.t td { padding: 8px; border-bottom: 1px solid #1b2130; vertical-align: middle; }
        .hint { font-size: 12px; color: #6b7785; line-height: 1.7; }
        .hint code, code.cmd { background: #0d1117; padding: 2px 6px; border-radius: 3px; color: #9aa7b5; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#e74c3c;">ADMIN CONTROL</small></div>
        <div class="user-info">Admin: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> | <a href="login.php?logout=1">Logout</a></div>
    </div>
    <?php $adminNavActive = 'sbackups'; include __DIR__ . '/nav.php'; ?>
    <div class="container">
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="box" style="margin-bottom:20px;">
            <div class="box-header">Server Backup Limits</div>
            <div class="box-content">
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['sbadm_csrf']); ?>">
                    <div class="grid3">
                        <div>
                            <label for="dm">Backups each client server may keep</label>
                            <input type="number" id="dm" name="default_max_backups" class="form-input" min="0" max="1000" value="<?php echo (int)$g['default_max_backups']; ?>" style="width:100%;box-sizing:border-box">
                            <div class="hint">0 = unlimited. When the limit is reached the client must delete a backup before making a new one; scheduled backups replace their oldest scheduled backup.</div>
                        </div>
                        <div>
                            <label for="mi">Shortest allowed schedule</label>
                            <select id="mi" name="min_interval_hours" class="form-input" style="width:100%">
                                <?php foreach ($intervals as $h => $l): ?><option value="<?php echo $h; ?>" <?php echo (int)$g['min_interval_hours'] === $h ? 'selected' : ''; ?>><?php echo htmlspecialchars($l); ?></option><?php endforeach; ?>
                            </select>
                            <div class="hint">Clients cannot schedule backups more often than this.</div>
                        </div>
                        <div>
                            <label>Scheduled backups</label>
                            <label style="display:inline-flex;gap:8px;align-items:center;color:#fff;cursor:pointer;font-size:14px"><input type="checkbox" name="allow_schedule" value="1" <?php echo $g['allow_schedule'] ? 'checked' : ''; ?>> Clients may schedule backups</label>
                        </div>
                    </div>
                    <button type="submit" name="save_defaults" value="1" class="btn btn-green">Save</button>
                </form>
            </div>
        </div>

        <div class="box" style="margin-bottom:20px;">
            <div class="box-header">Per-server settings</div>
            <div class="box-content">
                <form method="GET" style="display:flex;gap:8px;margin-bottom:14px">
                    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="form-input" placeholder="Search server or client..." style="max-width:320px;width:100%">
                    <button class="btn" type="submit">Search</button>
                </form>
                <div class="hint" style="margin-bottom:10px">Leave "Backups allowed" empty to use the default (<?php echo (int)$g['default_max_backups'] ?: 'unlimited'; ?>). Tick "Disabled" to switch backups off for a server.</div>
                <?php if (!$rows): ?>
                    <div class="hint">No servers found.</div>
                <?php else: ?>
                <table class="t">
                    <tr><th>Server</th><th>Client</th><th>Backups allowed</th><th>Disabled</th><th>Schedule</th><th></th></tr>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <form method="POST">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['sbadm_csrf']); ?>">
                            <input type="hidden" name="server_id" value="<?php echo (int)$r['id']; ?>">
                            <td>#<?php echo (int)$r['id']; ?> <?php echo htmlspecialchars((string)$r['name']); ?></td>
                            <td><?php echo htmlspecialchars((string)($r['owner'] ?? '')); ?></td>
                            <td><input type="number" name="max_backups" class="form-input" min="0" max="1000" style="width:110px" value="<?php echo $r['max_backups'] === null ? '' : (int)$r['max_backups']; ?>" placeholder="default"></td>
                            <td><input type="checkbox" name="disabled" value="1" <?php echo !empty($r['disabled']) ? 'checked' : ''; ?>></td>
                            <td class="hint"><?php echo !empty($r['sched_enabled']) ? htmlspecialchars((string)$r['sched_every']) . ($r['last_status'] ? ' - ' . htmlspecialchars((string)$r['last_status']) : '') : '-'; ?></td>
                            <td><button class="btn" type="submit" name="save_server" value="1" style="padding:4px 12px;font-size:12px">Save</button></td>
                        </form>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="box">
            <div class="box-header">Scheduled backups (cron job)</div>
            <div class="box-content hint">
                Scheduled backups need this cron job on the panel server (aaPanel: Cron &rarr; Shell script, every 5 minutes):<br>
                <code class="cmd">*/5 * * * * php <?php echo htmlspecialchars(dirname(__DIR__)); ?>/sbackups/run_scheduled.php &gt;/dev/null 2&gt;&amp;1</code><br>
                Backups are stored on the game nodes in <code>/var/lib/springs/backups/</code>. The nodes must run the latest Springs agent (re-run the node installer).
            </div>
        </div>
    </div>
</body>
</html>
