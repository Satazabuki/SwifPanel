<?php
require_once 'config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

// ping_nodes.php - latency from every node location to the visitor.
//
// Returns: {"client_ip":"...", "locations":[{"location":"Europe","node":"EU-Frankfurt","ms":28,"method":"icmp"}]}
//
// The node itself measures the round trip to the visitor (lowest of several ICMP probes, a TCP
// handshake when ICMP is blocked). ms is null when the visitor cannot be reached at all.
//
// Optional in config.php: define('TRUSTED_PROXY_IPS', ['203.0.113.10']);  // public reverse proxies in front of this site

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

// True when $ip lies inside $cidr (IPv4 or IPv6).
function swiftIpInCidr($ip, $cidr)
{
    $parts = explode('/', $cidr);
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($parts[0]);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;
    }
    $bits = (int)$parts[1];
    $bytes = intdiv($bits, 8);
    $rem = $bits % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
        return false;
    }
    if ($rem === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

// Cloudflare's published edge ranges (https://www.cloudflare.com/ips/).
function swiftIsCloudflare($ip)
{
    static $ranges = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];
    foreach ($ranges as $r) {
        if (swiftIpInCidr($ip, $r)) {
            return true;
        }
    }
    return false;
}

function swiftIsPublicIp($ip)
{
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

// Forwarded-for headers can be forged by anyone who talks to the site directly, so they are only
// believed when the request really comes from a proxy: a private/loopback address (proxy on this
// host or LAN) or an address listed in TRUSTED_PROXY_IPS.
function swiftTrustsProxy($remote)
{
    if (!swiftIsPublicIp($remote)) {
        return true;
    }
    return defined('TRUSTED_PROXY_IPS') && is_array(TRUSTED_PROXY_IPS) && in_array($remote, TRUSTED_PROXY_IPS, true);
}

// ---------------------------------------------------------------------------
// Who is the visitor?
// ---------------------------------------------------------------------------
$remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$clientIp = $remote;
$candidates = [];
if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && (swiftIsCloudflare($remote) || swiftTrustsProxy($remote))) {
    $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
}
if (swiftTrustsProxy($remote)) {
    foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $hdr) {
        if (!empty($_SERVER[$hdr])) {
            $candidates[] = $_SERVER[$hdr];
        }
    }
}
foreach ($candidates as $cand) {
    $cand = trim(explode(',', $cand)[0]);
    if (swiftIsPublicIp($cand)) {
        $clientIp = $cand;
        break;
    }
}

// ---------------------------------------------------------------------------
// Cache: one measurement per visitor every 2 minutes (page reloads and repeat visits cost nothing)
// ---------------------------------------------------------------------------
$cacheDir = rtrim(sys_get_temp_dir(), '/\\');
$cacheFile = $cacheDir . '/swift_ping_' . md5($clientIp) . '.json';
if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 120) {
    $cached = json_decode((string)@file_get_contents($cacheFile), true);
    if (is_array($cached) && isset($cached['locations'])) {
        $cached['cached'] = true;
        echo json_encode($cached);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Measure from every node (all nodes of a location; the best one is reported)
// ---------------------------------------------------------------------------
$rows = $pdo->query("SELECT id, name, COALESCE(NULLIF(location,''),'Europe') AS location, ip_address, COALESCE(NULLIF(ssh_port,0),8085) AS api_port, ssh_password AS api_token FROM boxes ORDER BY location ASC, id ASC")->fetchAll();

$best = [];
if (!swiftIsPublicIp($clientIp)) {
    // Local / private visitor (testing): nothing to measure, still list the locations.
    foreach ($rows as $r) {
        if (!isset($best[$r['location']])) {
            $best[$r['location']] = ['location' => $r['location'], 'node' => $r['name'], 'ms' => null, 'method' => null];
        }
    }
    echo json_encode(['client_ip' => $clientIp, 'locations' => array_values($best)]);
    exit;
}

$mh = curl_multi_init();
$handles = [];
foreach ($rows as $i => $r) {
    $ch = curl_init("http://{$r['ip_address']}:{$r['api_port']}/api/ping");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['ip' => $clientIp]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $r['api_token']],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 6
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$i] = $ch;
}

do {
    $status = curl_multi_exec($mh, $active);
    if ($active) {
        curl_multi_select($mh, 0.2);
    }
} while ($active && $status == CURLM_OK);

foreach ($rows as $i => $r) {
    $resp = curl_multi_getcontent($handles[$i]);
    curl_multi_remove_handle($mh, $handles[$i]);
    curl_close($handles[$i]);
    $data = json_decode($resp ?: '{}', true);
    $ms = (is_array($data) && isset($data['ms']) && $data['ms'] !== null) ? (int)$data['ms'] : null;
    $method = (is_array($data) && isset($data['method'])) ? (string)$data['method'] : null;
    $loc = $r['location'];
    $entry = ['location' => $loc, 'node' => $r['name'], 'ms' => $ms, 'method' => $method];
    if (!isset($best[$loc])) {
        $best[$loc] = $entry;
    } elseif ($ms !== null && ($best[$loc]['ms'] === null || $ms < $best[$loc]['ms'])) {
        $best[$loc] = $entry;
    }
}
curl_multi_close($mh);

$result = ['client_ip' => $clientIp, 'locations' => array_values($best)];
@file_put_contents($cacheFile, json_encode($result), LOCK_EX);

// Housekeeping: now and then remove cache files older than an hour.
if (mt_rand(1, 100) === 1) {
    foreach ((array)glob($cacheDir . '/swift_ping_*.json') as $old) {
        if (@filemtime($old) < time() - 3600) {
            @unlink($old);
        }
    }
}

echo json_encode($result);
