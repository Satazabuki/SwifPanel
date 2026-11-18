<?php
function parsePortPool($spec, $game = 'cs16') {
    $ports = [];
    $spec = trim((string)$spec);
    if ($spec === '') {
        $spec = ($game === 'minecraft') ? '25565-25600' : '27015-27060';
    }
    foreach (preg_split('/[\s,;]+/', $spec, -1, PREG_SPLIT_NO_EMPTY) as $part) {
        if (preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
            $a = max(1024, (int)$m[1]);
            $b = min(65535, (int)$m[2]);
            for ($p = $a; $p <= $b && count($ports) < 500; $p++) {
                $ports[] = $p;
            }
        } elseif (ctype_digit($part)) {
            $p = (int)$part;
            if ($p >= 1024 && $p <= 65535) $ports[] = $p;
        }
    }
    $ports = array_values(array_unique($ports));
    sort($ports);
    if ($game === 'minecraft') {
        $mc = array_values(array_filter($ports, fn($p) => $p >= 25000 && $p <= 26999));
        if (!empty($mc)) return $mc;
    } else {
        $src = array_values(array_filter($ports, fn($p) => $p >= 27000 || $p < 25000));
        if (!empty($src)) return $src;
    }
    return $ports;
}

function getUsedPortsOnNode(PDO $pdo, array $box) {
    $st = $pdo->prepare("SELECT port FROM servers WHERE box_id = ?");
    $st->execute([(int)$box['id']]);
    $used = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    $apiPort = (int)($box['ssh_port'] ?: 8085);
    $ch = curl_init("http://{$box['ip_address']}:{$apiPort}/api/ports/used");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $box['ssh_password']],
        CURLOPT_TIMEOUT => 2
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp ?: '{}', true);
    if (!empty($data['ports']) && is_array($data['ports'])) {
        foreach ($data['ports'] as $lp) {
            $used[] = (int)$lp;
        }
    }
    return array_values(array_unique($used));
}

function allocateFreePort(PDO $pdo, array $box, $game = 'cs16') {
    $pool = parsePortPool($box['assigned_ports'] ?? '', $game);
    $used = getUsedPortsOnNode($pdo, $box);
    foreach ($pool as $candidate) {
        if (!in_array($candidate, $used, true)) {
            return $candidate;
        }
    }
    $fallback = ($game === 'minecraft') ? 25565 : 27015;
    while (in_array($fallback, $used, true)) {
        $fallback++;
    }
    return $fallback;
}
