<?php
/**
 * Statistics collector - run from cron every minute or two (command line only):
 *   * * * * * php /www/wwwroot/gp.marinkovic.biz/stats/collect.php >/dev/null 2>&1
 * Asks every node how many players each supported game server has and stores one sample per server.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/stats_lib.php';

$lock = fopen(sys_get_temp_dir() . '/swiftpanel_stats.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);        // the previous run is still going
}
[$stored, $failed] = statsCollect($pdo);
if (random_int(1, 60) === 1) {      // roughly once an hour is plenty
    statsPrune($pdo);
}
echo gmdate('c') . " stored=$stored nodes_failed=$failed\n";
