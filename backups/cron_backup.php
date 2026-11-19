<?php
// cron_backup.php - automatic database backup. Command line only.
//
//   php /www/wwwroot/gp.marinkovic.biz/backups/cron_backup.php
//
// Runs when "Automatic backups" is switched on in Admin > Backups, then keeps the newest N automatic backups.
// Schedule it as often as you want backups (aaPanel: Cron > Shell script, for example daily at 03:30).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/backup_lib.php';

swiftBackupSchema($pdo);
$cfg = swiftBackupSettings($pdo);
if (empty($cfg['auto_enabled'])) {
    echo "Automatic backups are switched off (Admin > Backups).\n";
    exit(0);
}
$r = swiftBackupCreate($pdo, 'auto');
if ($r['ok']) {
    $pruned = swiftBackupPrune((int)$cfg['keep_count']);
    $msg = 'OK ' . $r['name'] . ' (' . $r['size'] . ' bytes, ' . $r['method'] . ')' . ($pruned ? ", removed $pruned old" : '');
    $pdo->prepare("UPDATE backup_settings SET last_auto_at = NOW(), last_status = ? WHERE id = 1")->execute([substr($msg, 0, 250)]);
    echo $msg . "\n";
    exit(0);
}
$pdo->prepare("UPDATE backup_settings SET last_auto_at = NOW(), last_status = ? WHERE id = 1")->execute([substr('FAILED: ' . $r['error'], 0, 250)]);
fwrite(STDERR, $r['error'] . "\n");
exit(1);
