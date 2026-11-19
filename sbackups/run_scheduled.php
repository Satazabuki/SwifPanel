<?php
// Scheduled server backups - run from cron every 5 minutes (command line only), for example:
//   */5 * * * * php /www/wwwroot/gp.marinkovic.biz/sbackups/run_scheduled.php >/dev/null 2>&1
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/sbackup_lib.php';

$lock = fopen(sys_get_temp_dir() . '/swiftpanel_sbackups.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
$r = sbRunScheduled($pdo);
echo gmdate('c') . " started={$r['ran']} failed={$r['failed']} skipped={$r['skipped']}\n";
