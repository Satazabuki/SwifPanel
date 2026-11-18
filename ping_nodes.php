<?php
require_once 'config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$clientIp = '';
foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $hdr) {
    if (!empty($_SERVER[$hdr])) {
        $cand = trim(explode(',', $_SERVER[$hdr])[0]);
        if (filter_var($cand, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $clientIp = $cand;
            break;
        }
    }
}
if ($clientIp === '') {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
}

$rows = $pdo->query("SELECT id, name, COALESCE(NULLIF(location,''),'Europe') AS location, ip_address, COALESCE(NULLIF(ssh_port,0),8085) AS api_port, ssh_password AS api_token FROM boxes ORDER BY location ASC, id ASC")->fetchAll();
$byLoc = [];
foreach ($rows as $r) {
    $loc = $r['location'];
    if (!isset($byLoc[$loc])) {
        $byLoc[$loc] = $r;
    }
}

$mh = curl_multi_init();
$handles = [];
foreach ($byLoc as $loc => $r) {
    $ch = curl_init("http://{$r['ip_address']}:{$r['api_port']}/api/ping");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['ip' => $clientIp]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $r['api_token']],
        CURLOPT_TIMEOUT => 3
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$loc] = $ch;
}

do {
    $status = curl_multi_exec($mh, $active);
    if ($active) curl_multi_select($mh, 0.2);
} while ($active && $status == CURLM_OK);

$out = [];
foreach ($byLoc as $loc => $r) {
    $resp = curl_multi_getcontent($handles[$loc]);
    curl_multi_remove_handle($mh, $handles[$loc]);
    curl_close($handles[$loc]);
    $data = json_decode($resp ?: '{}', true);
    $ms = isset($data['ms']) && $data['ms'] !== null ? (int)$data['ms'] : null;
    $out[] = ['location' => $loc, 'node' => $r['name'], 'ms' => $ms];
}
curl_multi_close($mh);

echo json_encode(['client_ip' => $clientIp, 'locations' => $out]);
