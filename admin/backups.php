<?php
require_once 'guard.php';
require_once '../config.php';
require_once '../backups/backup_lib.php';

swiftBackupSchema($pdo);
if (empty($_SESSION['backup_csrf'])) {
    $_SESSION['backup_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['backup_csrf'];

// download: admin only, name must match the strict pattern, file must be inside backups/files/
if (isset($_GET['download'])) {
    $name = (string)$_GET['download'];
    $path = swiftBackupDir() . '/' . $name;
    if (!swiftBackupValidName($name) || !is_file($path)) {
        http_response_code(404);
        exit('Backup not found.');
    }
    session_write_close();
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = 'Security check failed. Please reload the page and try again.';
    } elseif (isset($_POST['create'])) {
        $method = in_array($_POST['method'] ?? 'auto', ['auto', 'mysqldump', 'php'], true) ? $_POST['method'] : 'auto';
        $r = swiftBackupCreate($pdo, 'manual', $method);
        if ($r['ok']) {
            $notice = 'Backup created: ' . $r['name'] . ' (' . number_format($r['size'] / 1024, 1) . ' KB, made with ' . ($r['method'] === 'mysqldump' ? 'mysqldump' : 'the PHP dumper') . ').'
                . ($r['notes'] ? ' Note: ' . implode(' | ', $r['notes']) : '');
        } else {
            $error = $r['error'];
        }
    } elseif (isset($_POST['delete'])) {
        $notice = swiftBackupDelete((string)($_POST['name'] ?? '')) ? 'Backup deleted.' : '';
        if ($notice === '') {
            $error = 'That backup could not be deleted.';
        }
    } elseif (isset($_POST['save_settings'])) {
        $keep = max(1, min(365, (int)($_POST['keep_count'] ?? 14)));
        $pdo->prepare("UPDATE backup_settings SET auto_enabled = ?, keep_count = ? WHERE id = 1")
            ->execute([!empty($_POST['auto_enabled']) ? 1 : 0, $keep]);
        $notice = 'Settings saved.';
    }
}

$cfg = swiftBackupSettings($pdo);
$list = swiftBackupList();
$total = array_sum(array_column($list, 'size'));
$dumpBin = swiftBackupFindMysqldump();
$canDump = $dumpBin && swiftBackupCanProc();
$free = @disk_free_space(swiftBackupDir()) ?: @disk_free_space(__DIR__);
$cronCmd = 'php ' . realpath(__DIR__ . '/../backups/cron_backup.php');
function swiftBkSize($b)
{
    return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format($b / 1024, 1) . ' KB';
}
?>
<?php require_once dirname(__DIR__) . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Database Backups - Admin Panel</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
    <style>
        .container { max-width: 960px; margin: 24px auto; padding: 0 18px; }
        .form-input { box-sizing: border-box; background: #0d1117; color: #fff; border: 1px solid #30363d; border-radius: 4px; padding: 9px 11px; font-size: 13px; }
        label { font-size: 12px; color: #94a3b8; }
        table.bk { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.bk th, table.bk td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #30363d; }
        table.bk th { color: #94a3b8; font-size: 12px; font-weight: 600; }
        .tag { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 11px; background: #1f2937; color: #94a3b8; }
        .tag.auto { background: rgba(52,152,219,.2); color: #3498db; }
        code.cmd { display: block; background: #0d1117; border: 1px solid #30363d; border-radius: 4px; padding: 8px 10px; font-size: 12px; word-break: break-all; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#e74c3c;">ADMIN CONTROL</small></div>
        <div class="user-info">Admin: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> | <a href="login.php?logout=1">Logout</a></div>
    </div>
    <?php $adminNavActive = 'backups'; include __DIR__ . '/nav.php'; ?>
    <div class="container">
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="box" style="margin-bottom:20px;">
            <div class="box-header">Back up the database now</div>
            <div class="box-content">
                <form method="POST" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Working...';">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                    <label>Method</label>
                    <select name="method" class="form-input" style="margin:0 10px 0 6px;">
                        <option value="auto">Automatic (mysqldump if available, otherwise PHP)</option>
                        <option value="mysqldump" <?php echo $canDump ? '' : 'disabled'; ?>>mysqldump<?php echo $canDump ? '' : ' (not available)'; ?></option>
                        <option value="php">PHP dumper (tables, data, views)</option>
                    </select>
                    <button type="submit" name="create" value="1" class="btn btn-green">Create backup</button>
                </form>
                <p style="font-size:12px;color:#94a3b8;margin:12px 0 0;">
                    Database <strong style="color:#fff;"><?php echo htmlspecialchars(defined('DB_NAME') ? DB_NAME : ''); ?></strong>.
                    mysqldump: <?php echo $canDump ? '<span style="color:#2ecc71;">found</span> (' . htmlspecialchars($dumpBin) . '), also saves routines, triggers and events' : '<span style="color:#f39c12;">not available</span>, the built-in PHP dumper is used'; ?>.
                    Free disk space: <?php echo $free ? swiftBkSize($free) : 'unknown'; ?>.
                    Backups contain every user's data and password hashes: keep them private.
                </p>
            </div>
        </div>

        <div class="box" style="margin-bottom:20px;">
            <div class="box-header">Saved backups (<?php echo count($list); ?>, <?php echo swiftBkSize($total); ?>)</div>
            <div class="box-content">
                <?php if (!$list): ?>
                    <p style="color:#94a3b8;margin:0;">No backups yet.</p>
                <?php else: ?>
                    <table class="bk">
                        <thead><tr><th>File</th><th>Type</th><th>Date</th><th>Size</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($list as $b): ?>
                            <tr>
                                <td style="font-family:monospace;"><?php echo htmlspecialchars($b['name']); ?></td>
                                <td><span class="tag <?php echo $b['kind'] === 'auto' ? 'auto' : ''; ?>"><?php echo $b['kind'] === 'auto' ? 'automatic' : 'manual'; ?></span></td>
                                <td><?php echo date('Y-m-d H:i', $b['time']); ?></td>
                                <td><?php echo swiftBkSize($b['size']); ?></td>
                                <td style="text-align:right;white-space:nowrap;">
                                    <a class="btn" style="padding:4px 10px;font-size:12px;" href="backups.php?download=<?php echo urlencode($b['name']); ?>">Download</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this backup permanently?');">
                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="name" value="<?php echo htmlspecialchars($b['name']); ?>">
                                        <button type="submit" name="delete" value="1" class="btn" style="padding:4px 10px;font-size:12px;background:#7f1d1d;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <p style="font-size:12px;color:#94a3b8;margin:12px 0 0;">
                    To restore, download the file and import it with phpMyAdmin or: <code>gunzip -c FILE.sql.gz | mysql -u USER -p DATABASE</code>
                </p>
            </div>
        </div>

        <div class="box">
            <div class="box-header">Automatic backups</div>
            <div class="box-content">
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                    <label style="display:inline-flex;align-items:center;gap:8px;color:#fff;cursor:pointer;">
                        <input type="checkbox" name="auto_enabled" value="1" <?php echo !empty($cfg['auto_enabled']) ? 'checked' : ''; ?>>
                        Create a backup every time the cron job below runs
                    </label>
                    <div style="margin:12px 0;">
                        <label>Keep the newest</label>
                        <input type="number" name="keep_count" min="1" max="365" class="form-input" style="width:90px;margin:0 6px;" value="<?php echo (int)$cfg['keep_count']; ?>">
                        <label>automatic backups (manual ones are never deleted automatically)</label>
                    </div>
                    <button type="submit" name="save_settings" value="1" class="btn btn-green">Save</button>
                </form>
                <p style="font-size:12px;color:#94a3b8;margin:14px 0 6px;">Add this as a cron job (aaPanel: Cron &rarr; Shell script), for example daily at 03:30:</p>
                <code class="cmd"><?php echo htmlspecialchars($cronCmd); ?></code>
                <p style="font-size:12px;color:#94a3b8;margin:10px 0 0;">
                    Last automatic run: <?php echo $cfg['last_auto_at'] ? htmlspecialchars($cfg['last_auto_at'] . ' - ' . (string)$cfg['last_status']) : 'never'; ?>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
