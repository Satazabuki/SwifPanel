<?php
/**
 * Server statistics: storage, collection from the nodes, and the aggregation behind the client page.
 * All timestamps are stored in UTC (DATETIME, 'Y-m-d H:i:s'); the page converts to the viewer's time zone.
 */

const STATS_KEEP_DAYS = 30;
const STATS_GAP_MAX = 600;      // seconds: a longer hole between two samples is "no data", not "same map"

function statsEnsureSchema(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS server_stats (id INTEGER PRIMARY KEY AUTOINCREMENT, server_id INTEGER NOT NULL, ts TEXT NOT NULL, up INTEGER NOT NULL DEFAULT 0, players INTEGER NOT NULL DEFAULT 0, max_players INTEGER NOT NULL DEFAULT 0, map TEXT NULL, cpu REAL NULL, mem_mb INTEGER NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_server_stats ON server_stats (server_id, ts)");
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS server_stats (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        server_id INT NOT NULL,
        ts DATETIME NOT NULL,
        up TINYINT(1) NOT NULL DEFAULT 0,
        players SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        max_players SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        map VARCHAR(80) NULL,
        cpu DECIMAL(6,1) NULL,
        mem_mb INT NULL,
        KEY idx_server_ts (server_id, ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Which games the nodes can query (mirrors QUERY_PORTS in the agent). */
function statsGameSupported(string $game): bool
{
    return in_array($game, ['cs16', 'cs2', 'gmod', 'tf2', 'l4d2', 'rust', 'valheim', '7dtd', 'minecraft'], true);
}

/** Does this game report a map name? (Minecraft, Rust, Valheim and 7DTD report one world, so there is nothing to rotate.) */
function statsGameHasMaps(string $game): bool
{
    return in_array($game, ['cs16', 'cs2', 'gmod', 'tf2', 'l4d2'], true);
}

function statsCallNode(array $node, string $endpoint, array $payload, int $timeout = 25): ?array
{
    $port = (int)($node['api_port'] ?: 8085);
    $ch = curl_init("http://{$node['node_ip']}:{$port}{$endpoint}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $node['api_token']],
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$resp, true);
    return is_array($j) ? $j : null;
}

/**
 * One collection round: ask every node about its servers and store one sample per supported server.
 * $caller lets tests replace the HTTP call. Returns [samples stored, nodes unreachable].
 */
function statsCollect(PDO $pdo, ?callable $caller = null): array
{
    statsEnsureSchema($pdo);
    $caller = $caller ?: 'statsCallNode';
    $rows = $pdo->query("SELECT s.id, s.game, s.screen_name, s.ip_address, s.port, s.status, s.box_id,
            b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token
        FROM servers s JOIN boxes b ON s.box_id = b.id
        WHERE s.status <> 'installing' AND s.screen_name <> ''")->fetchAll(PDO::FETCH_ASSOC);
    $byNode = [];
    foreach ($rows as $r) {
        if (!statsGameSupported((string)$r['game'])) {
            continue;
        }
        $byNode[$r['box_id']][] = $r;
    }
    $now = gmdate('Y-m-d H:i:s');
    $ins = $pdo->prepare("INSERT INTO server_stats (server_id, ts, up, players, max_players, map, cpu, mem_mb) VALUES (?,?,?,?,?,?,?,?)");
    $stored = 0;
    $failed = 0;
    foreach ($byNode as $list) {
        $items = [];
        foreach ($list as $r) {
            $items[] = ['key' => (string)$r['id'], 'game' => $r['game'], 'screen_name' => $r['screen_name'],
                        'ip_address' => $r['ip_address'], 'port' => (int)$r['port']];
        }
        $resp = $caller($list[0], '/api/servers/query_many', ['servers' => $items, 'resources' => true]);
        if (!$resp || empty($resp['ok']) || !isset($resp['results']) || !is_array($resp['results'])) {
            $failed++;      // node down or an old agent: leave a gap rather than recording "offline"
            continue;
        }
        foreach ($list as $r) {
            $q = $resp['results'][(string)$r['id']] ?? null;
            if (!is_array($q) || empty($q['supported'])) {
                continue;
            }
            $online = !empty($q['online']);
            $map = isset($q['map']) && $q['map'] !== null ? mb_substr((string)$q['map'], 0, 80) : null;
            $ins->execute([
                (int)$r['id'], $now, $online ? 1 : 0,
                $online ? max(0, (int)$q['players']) : 0,
                $online ? max(0, (int)$q['max']) : 0,
                $online ? $map : null,
                isset($q['cpu']) ? (float)$q['cpu'] : null,
                isset($q['mem_mb']) ? (int)$q['mem_mb'] : null,
            ]);
            $stored++;
        }
    }
    return [$stored, $failed];
}

function statsPrune(PDO $pdo): int
{
    $st = $pdo->prepare("DELETE FROM server_stats WHERE ts < ?");
    $st->execute([gmdate('Y-m-d H:i:s', time() - STATS_KEEP_DAYS * 86400)]);
    return $st->rowCount();
}

/** @return array{0:int,1:int} [seconds covered, bucket seconds] */
function statsRange(string $range): array
{
    switch ($range) {
        case '7d':  return [7 * 86400, 1800];
        case '30d': return [30 * 86400, 7200];
        default:    return [86400, 300];
    }
}

/**
 * Everything the page draws, for one server and one range.
 * Weighting: each sample stands for the time until the next sample (capped at STATS_GAP_MAX), so the map shares and the
 * uptime are time-based and do not depend on how often the cron runs.
 */
function statsReport(PDO $pdo, int $serverId, string $range, ?int $now = null): array
{
    $now = $now ?? time();
    [$span, $bucket] = statsRange($range);
    $from = $now - $span;
    $st = $pdo->prepare("SELECT ts, up, players, max_players, map, cpu, mem_mb FROM server_stats WHERE server_id = ? AND ts >= ? ORDER BY ts ASC");
    $st->execute([$serverId, gmdate('Y-m-d H:i:s', $from)]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $samples = [];
    foreach ($rows as $r) {
        $t = strtotime($r['ts'] . ' UTC');
        $samples[] = [
            't' => $t, 'up' => (int)$r['up'], 'p' => (int)$r['players'], 'm' => (int)$r['max_players'],
            'map' => $r['map'] !== null && $r['map'] !== '' ? (string)$r['map'] : null,
            'cpu' => $r['cpu'] !== null ? (float)$r['cpu'] : null, 'mem' => $r['mem_mb'] !== null ? (int)$r['mem_mb'] : null,
        ];
    }
    $n = count($samples);
    for ($i = 0; $i < $n; $i++) {
        $next = $i + 1 < $n ? $samples[$i + 1]['t'] : min($now, $samples[$i]['t'] + 120);
        $samples[$i]['w'] = max(0, min($next - $samples[$i]['t'], STATS_GAP_MAX));
    }

    // series (fixed buckets so the chart has even spacing) and hourly buckets (for the heat map, drawn in local time)
    $series = [];
    $hourly = [];
    foreach ($samples as $s) {
        $b = intdiv($s['t'], $bucket) * $bucket;
        $h = intdiv($s['t'], 3600) * 3600;
        foreach ([[&$series, $b], [&$hourly, $h]] as $pair) {
            $arr = &$pair[0];
            $k = $pair[1];
            if (!isset($arr[$k])) {
                $arr[$k] = ['t' => $k, 'n' => 0, 'sum' => 0, 'max' => 0, 'up' => 0, 'cpu' => 0.0, 'cpun' => 0, 'mem' => 0, 'memn' => 0];
            }
            $arr[$k]['n']++;
            $arr[$k]['sum'] += $s['p'];
            $arr[$k]['max'] = max($arr[$k]['max'], $s['p']);
            $arr[$k]['up'] += $s['up'];
            if ($s['cpu'] !== null) { $arr[$k]['cpu'] += $s['cpu']; $arr[$k]['cpun']++; }
            if ($s['mem'] !== null) { $arr[$k]['mem'] += $s['mem']; $arr[$k]['memn']++; }
            unset($arr);
        }
    }
    $fmt = function (array $a): array {
        ksort($a);
        $out = [];
        foreach ($a as $b) {
            $out[] = ['t' => $b['t'], 'avg' => round($b['sum'] / $b['n'], 2), 'max' => $b['max'], 'up' => round($b['up'] / $b['n'], 2),
                      'cpu' => $b['cpun'] ? round($b['cpu'] / $b['cpun'], 1) : null, 'mem' => $b['memn'] ? (int)round($b['mem'] / $b['memn']) : null];
        }
        return $out;
    };
    $series = $fmt($series);
    $hourly = $fmt($hourly);

    // totals
    $upSec = 0; $allSec = 0; $peak = 0; $peakAt = null; $wp = 0.0; $wsec = 0; $maxSlots = 0; $last = null;
    foreach ($samples as $s) {
        $allSec += $s['w'];
        if ($s['up']) {
            $upSec += $s['w'];
            $wp += $s['p'] * $s['w'];
            $wsec += $s['w'];
            if ($peakAt === null || $s['p'] > $peak) { $peak = $s['p']; $peakAt = $s['t']; }
            $maxSlots = max($maxSlots, $s['m']);
        }
        $last = $s;
    }

    // maps: share of online time, plays (runs of the same map), average / peak players on the map
    $maps = [];
    $segments = [];
    $cur = null;
    foreach ($samples as $s) {
        if (!$s['up'] || $s['map'] === null) {
            $cur = null;
            continue;
        }
        $m = $s['map'];
        if (!isset($maps[$m])) {
            $maps[$m] = ['map' => $m, 'sec' => 0, 'plays' => 0, 'psum' => 0.0, 'peak' => 0];
        }
        $maps[$m]['sec'] += $s['w'];
        $maps[$m]['psum'] += $s['p'] * $s['w'];
        $maps[$m]['peak'] = max($maps[$m]['peak'], $s['p']);
        $gapOk = $cur !== null && $cur['map'] === $m && ($s['t'] - $cur['end']) <= STATS_GAP_MAX;
        if ($gapOk) {
            $idx = count($segments) - 1;
            $segments[$idx]['end'] = $s['t'] + $s['w'];
            $segments[$idx]['psum'] += $s['p'] * max(1, $s['w']);
            $segments[$idx]['wsum'] += max(1, $s['w']);
            $segments[$idx]['peak'] = max($segments[$idx]['peak'], $s['p']);
            $cur['end'] = $s['t'] + $s['w'];
        } else {
            $maps[$m]['plays']++;
            $segments[] = ['map' => $m, 'start' => $s['t'], 'end' => $s['t'] + $s['w'], 'psum' => $s['p'] * max(1, $s['w']), 'wsum' => max(1, $s['w']), 'peak' => $s['p']];
            $cur = ['map' => $m, 'end' => $s['t'] + $s['w']];
        }
    }
    $mapList = array_values($maps);
    foreach ($mapList as &$m) {
        $m['avg'] = $m['sec'] > 0 ? round($m['psum'] / $m['sec'], 1) : 0;
        unset($m['psum']);
    }
    unset($m);
    usort($mapList, fn($a, $b) => $b['sec'] <=> $a['sec']);
    foreach ($segments as &$g) {
        $g['avg'] = round($g['psum'] / $g['wsum'], 1);
        unset($g['psum'], $g['wsum']);
    }
    unset($g);
    $segmentCount = count($segments);
    $segments = array_slice($segments, -600);

    $cpuVals = array_values(array_filter(array_column($samples, 'cpu'), fn($v) => $v !== null));
    $memVals = array_values(array_filter(array_column($samples, 'mem'), fn($v) => $v !== null));

    return [
        'range' => $range, 'from' => $from, 'to' => $now, 'bucket' => $bucket, 'samples' => $n,
        'series' => $series, 'hourly' => $hourly, 'maps' => array_slice($mapList, 0, 40), 'segments' => $segments,
        'summary' => [
            'uptime' => $allSec > 0 ? round(100 * $upSec / $allSec, 1) : null,
            'avg' => $wsec > 0 ? round($wp / $wsec, 1) : 0,
            'peak' => $peak, 'peak_at' => $peakAt, 'slots' => $maxSlots,
            'unique_maps' => count($mapList), 'map_changes' => max(0, $segmentCount - 1),
            'cpu_avg' => $cpuVals ? round(array_sum($cpuVals) / count($cpuVals), 1) : null,
            'mem_avg' => $memVals ? (int)round(array_sum($memVals) / count($memVals)) : null,
            'last_at' => $last ? $last['t'] : null,
        ],
    ];
}
