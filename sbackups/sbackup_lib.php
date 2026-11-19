<?php
/**
 * Server backups (client feature): manual and scheduled backups of a game server's files.
 * The archives are made and stored on the node by the Springs agent (/api/backups/*); the panel keeps the settings,
 * the limits and the schedules. Limit: a maximum number of backups per server (a global default set by the
 * administrator, optionally overridden per server; 0 = unlimited).
 */

const SB_FREQUENCIES = ['6h' => 6, '12h' => 12, 'daily' => 24, 'weekly' => 168];   // label => hours between runs

function sbEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS sbackup_settings (
        k VARCHAR(40) NOT NULL PRIMARY KEY,
        v VARCHAR(100) NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sbackup_server (
        server_id INT NOT NULL PRIMARY KEY,
        max_backups INT NULL,
        disabled TINYINT NOT NULL DEFAULT 0,
        sched_enabled TINYINT NOT NULL DEFAULT 0,
        sched_every VARCHAR(10) NOT NULL DEFAULT 'daily',
        sched_hour TINYINT NOT NULL DEFAULT 3,
        sched_dow TINYINT NOT NULL DEFAULT 1,
        sched_keep INT NOT NULL DEFAULT 3,
        next_run DATETIME NULL,
        last_run DATETIME NULL,
        last_status VARCHAR(200) NOT NULL DEFAULT ''
    )");
}

function sbDefaults(): array
{
    return ['default_max_backups' => 5, 'allow_schedule' => 1, 'min_interval_hours' => 6];
}

function sbSettings(PDO $pdo): array
{
    sbEnsureSchema($pdo);
    $s = sbDefaults();
    foreach ($pdo->query("SELECT k, v FROM sbackup_settings")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (array_key_exists($r['k'], $s)) {
            $s[$r['k']] = (int)$r['v'];
        }
    }
    return $s;
}

function sbSaveSetting(PDO $pdo, string $k, int $v): void
{
    sbEnsureSchema($pdo);
    $st = $pdo->prepare("SELECT 1 FROM sbackup_settings WHERE k = ?");
    $st->execute([$k]);
    if ($st->fetchColumn()) {
        $pdo->prepare("UPDATE sbackup_settings SET v = ? WHERE k = ?")->execute([(string)$v, $k]);
    } else {
        $pdo->prepare("INSERT INTO sbackup_settings (k, v) VALUES (?, ?)")->execute([$k, (string)$v]);
    }
}

/** The per-server row (with defaults filled in) */
function sbServerCfg(PDO $pdo, int $serverId): array
{
    sbEnsureSchema($pdo);
    $st = $pdo->prepare("SELECT * FROM sbackup_server WHERE server_id = ?");
    $st->execute([$serverId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: ['server_id' => $serverId, 'max_backups' => null, 'disabled' => 0, 'sched_enabled' => 0,
        'sched_every' => 'daily', 'sched_hour' => 3, 'sched_dow' => 1, 'sched_keep' => 3, 'next_run' => null, 'last_run' => null, 'last_status' => ''];
}

function sbSaveServerCfg(PDO $pdo, int $serverId, array $f): void
{
    sbEnsureSchema($pdo);
    $cur = sbServerCfg($pdo, $serverId);
    $new = array_merge($cur, $f);
    $st = $pdo->prepare("SELECT 1 FROM sbackup_server WHERE server_id = ?");
    $st->execute([$serverId]);
    $cols = ['max_backups', 'disabled', 'sched_enabled', 'sched_every', 'sched_hour', 'sched_dow', 'sched_keep', 'next_run', 'last_run', 'last_status'];
    if ($st->fetchColumn()) {
        $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
        $pdo->prepare("UPDATE sbackup_server SET $set WHERE server_id = ?")->execute(array_merge(array_map(fn($c) => $new[$c], $cols), [$serverId]));
    } else {
        $pdo->prepare("INSERT INTO sbackup_server (server_id, " . implode(', ', $cols) . ") VALUES (?, " . implode(', ', array_fill(0, count($cols), '?')) . ")")
            ->execute(array_merge([$serverId], array_map(fn($c) => $new[$c], $cols)));
    }
}

/** Effective limits of one server: ['max_backups' => n (0 = unlimited), 'allow_schedule', 'min_interval_hours', 'disabled'] */
function sbLimits(PDO $pdo, int $serverId): array
{
    $g = sbSettings($pdo);
    $c = sbServerCfg($pdo, $serverId);
    $maxB = $c['max_backups'] === null ? $g['default_max_backups'] : (int)$c['max_backups'];
    return [
        'max_backups' => max(0, $maxB),
        'max_bytes' => 0,
        'allow_schedule' => (int)$g['allow_schedule'] === 1,
        'min_interval_hours' => max(1, (int)$g['min_interval_hours']),
        'disabled' => (int)$c['disabled'] === 1,
    ];
}

/** Frequencies a client may pick, given the administrator's minimum interval. */
function sbAllowedFrequencies(int $minHours): array
{
    return array_keys(array_filter(SB_FREQUENCIES, fn($h) => $h >= $minHours));
}

/** Next UTC run time (timestamp) after $now for a schedule. */
function sbNextRun(string $every, int $hour, int $dow, int $now): int
{
    $hour = max(0, min(23, $hour));
    if ($every === '6h' || $every === '12h') {
        return $now + SB_FREQUENCIES[$every] * 3600;
    }
    $today = gmmktime($hour, 0, 0, (int)gmdate('n', $now), (int)gmdate('j', $now), (int)gmdate('Y', $now));
    if ($every === 'weekly') {
        $dow = max(0, min(6, $dow));       // 0 = Sunday
        $t = $today + ((($dow - (int)gmdate('w', $now)) + 7) % 7) * 86400;
        return $t > $now ? $t : $t + 7 * 86400;
    }
    return $today > $now ? $today : $today + 86400;
}

/** Call the agent on the server's node. $server needs node_ip, api_port, api_token, screen_name, install_path. */
function sbCall(array $server, string $endpoint, array $payload = [], int $timeout = 20): ?array
{
    $payload['screen_name'] = $server['screen_name'];
    $payload['install_path'] = $server['install_path'];
    $port = (int)($server['api_port'] ?: 8085);
    $ch = curl_init("http://{$server['node_ip']}:{$port}{$endpoint}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $server['api_token']],
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$resp, true);
    return is_array($j) ? $j : null;
}

/** Streams a backup from the node to the browser. Returns an error text, or '' when the download was sent. */
function sbStreamDownload(array $server, string $id): string
{
    if (!preg_match('/^bk_\d{8}-\d{6}_(manual|auto)\.tar\.gz$/', $id)) {
        return 'Invalid backup id';
    }
    $port = (int)($server['api_port'] ?: 8085);
    $url = "http://{$server['node_ip']}:{$port}/api/backups/download?screen_name=" . rawurlencode($server['screen_name']) . '&id=' . rawurlencode($id);
    $started = false;
    $status = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $server['api_token']],
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$status) {
            if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) {
                $status = (int)$m[1];
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($c, $data) use (&$started, &$status, $server, $id) {
            if ($status !== 200) {
                return strlen($data);          // an error answer from the node: swallow it
            }
            if (!$started) {
                $started = true;
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }
                header('Content-Type: application/gzip');
                header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $server['name'] . '_' . $id) . '"');
                header('Cache-Control: no-store');
                $len = curl_getinfo($c, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
                if ($len > 0) {
                    header('Content-Length: ' . (int)$len);
                }
            }
            echo $data;
            @flush();
            return strlen($data);
        },
    ]);
    curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($started) {
        return '';
    }
    return $status === 404 ? 'Backup not found' : ($err !== '' ? 'Could not reach the server node.' : 'The download failed.');
}

/**
 * Scheduled backups: runs every due schedule once. $caller(server, endpoint, payload) can replace the HTTP call (tests).
 * @return array{ran:int,failed:int,skipped:int}
 */
function sbRunScheduled(PDO $pdo, ?int $now = null, ?callable $caller = null): array
{
    sbEnsureSchema($pdo);
    $now = $now ?? time();
    $caller = $caller ?: 'sbCall';
    $g = sbSettings($pdo);
    $out = ['ran' => 0, 'failed' => 0, 'skipped' => 0];
    $due = $pdo->prepare("SELECT c.*, s.name, s.screen_name, s.install_path, s.status AS srv_status, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token
        FROM sbackup_server c JOIN servers s ON s.id = c.server_id JOIN boxes b ON b.id = s.box_id
        WHERE c.sched_enabled = 1 AND c.disabled = 0 AND (c.next_run IS NULL OR c.next_run <= ?)");
    $due->execute([gmdate('Y-m-d H:i:s', $now)]);
    foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $sid = (int)$c['server_id'];
        $every = isset(SB_FREQUENCIES[$c['sched_every']]) ? $c['sched_every'] : 'daily';
        $next = gmdate('Y-m-d H:i:s', sbNextRun($every, (int)$c['sched_hour'], (int)$c['sched_dow'], $now));
        $status = '';
        if (!$g['allow_schedule']) {
            $status = 'Scheduled backups are switched off by the administrator.';
            $out['skipped']++;
        } elseif ($c['srv_status'] === 'installing') {
            $status = 'Skipped: the server is still installing.';
            $out['skipped']++;
        } else {
            $lim = sbLimits($pdo, $sid);
            $list = $caller($c, '/api/backups/list', [], 20);
            if (!$list || empty($list['ok'])) {
                $status = 'The server node did not answer.';
                $out['failed']++;
            } elseif (!empty($list['job']) && ($list['job']['state'] ?? '') === 'running') {
                $status = 'Skipped: another backup was still running.';
                $out['skipped']++;
            } else {
                $items = array_values(array_filter($list['items'] ?? [], fn($i) => empty($i['running'])));
                usort($items, fn($a, $b) => $a['ts'] <=> $b['ts']);                       // oldest first
                $keep = max(1, (int)$c['sched_keep']);
                $total = array_sum(array_column($items, 'size'));
                $autos = array_values(array_filter($items, fn($i) => $i['kind'] === 'auto'));
                $count = count($items);
                // make room: scheduled backups rotate, manual ones are never deleted automatically
                while ($autos && (count($autos) >= $keep || ($lim['max_backups'] && $count >= $lim['max_backups'])
                        || ($lim['max_bytes'] && $total >= $lim['max_bytes']))) {
                    $old = array_shift($autos);
                    $r = $caller($c, '/api/backups/delete', ['id' => $old['id']], 20);
                    if (!$r || empty($r['ok'])) {
                        break;
                    }
                    $count--;
                    $total -= (int)$old['size'];
                }
                if (($lim['max_backups'] && $count >= $lim['max_backups']) || ($lim['max_bytes'] && $total >= $lim['max_bytes'])) {
                    $status = 'Skipped: the backup limit is reached by manual backups. Delete one to let scheduled backups run.';
                    $out['skipped']++;
                } else {
                    $r = $caller($c, '/api/backups/create', ['kind' => 'auto', 'max_count' => $lim['max_backups'], 'max_bytes' => $lim['max_bytes']], 20);
                    if ($r && !empty($r['ok'])) {
                        $status = 'Started ' . gmdate('Y-m-d H:i', $now) . ' UTC';
                        $out['ran']++;
                    } else {
                        $status = 'Failed: ' . (string)($r['error'] ?? 'the server node did not answer.');
                        $out['failed']++;
                    }
                }
            }
        }
        sbSaveServerCfg($pdo, $sid, ['next_run' => $next, 'last_run' => gmdate('Y-m-d H:i:s', $now), 'last_status' => mb_substr($status, 0, 190)]);
    }
    return $out;
}

/** Translates a stored (English) scheduler status for display. */
function sbStatusText($s) {
    $s = (string)$s;
    if ($s === '' || !function_exists('t')) return $s;
    if (preg_match('/^Started (.+) UTC$/', $s, $m)) return sprintf(t('Started %s UTC'), $m[1]);
    if (preg_match('/^Failed: (.+)$/s', $s, $m)) return sprintf(t('Failed: %s'), $m[1]);
    return t($s);
}
