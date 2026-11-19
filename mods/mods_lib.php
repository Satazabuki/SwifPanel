<?php
// mods/mods_lib.php - mods & plugins for SwiftPanel.
//
// The admin curates a catalog (Admin > Mods & Plugins): each entry is a download URL or a file uploaded to
// /mods/files/. A client picks an entry for one of THEIR servers and the node downloads it straight into the
// server folder. Clients never supply a URL or a path: the panel only sends what the catalog says.

// This file only defines functions; opening it directly in a browser is refused.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../games.php';

const SWIFT_MODS_MAX_UPLOAD_MB = 100;
const SWIFT_MODS_URL_TTL = 900;   // seconds a node may use a download link of an uploaded file

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

function swiftModsEnsureSchema(PDO $pdo, $force = false)
{
    $marker = rtrim(sys_get_temp_dir(), '/\\') . '/swift_mods_schema_v1_' . md5(defined('DB_NAME') ? (string)DB_NAME : 'db');
    if (!$force && is_file($marker)) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS mods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game VARCHAR(32) NOT NULL,
        name VARCHAR(120) NOT NULL,
        category VARCHAR(40) NOT NULL DEFAULT 'Plugin',
        version VARCHAR(40) NOT NULL DEFAULT '',
        author VARCHAR(80) NOT NULL DEFAULT '',
        description TEXT NULL,
        source_type ENUM('url','upload') NOT NULL DEFAULT 'url',
        source_url VARCHAR(600) NOT NULL DEFAULT '',
        stored_name VARCHAR(64) NOT NULL DEFAULT '',
        original_name VARCHAR(150) NOT NULL DEFAULT '',
        file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sha256 CHAR(64) NOT NULL DEFAULT '',
        install_kind ENUM('file','archive') NOT NULL DEFAULT 'file',
        target_dir VARCHAR(200) NOT NULL DEFAULT '',
        install_name VARCHAR(100) NOT NULL DEFAULT '',
        post_note VARCHAR(500) NOT NULL DEFAULT '',
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_game (game, enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS server_mods (
        server_id INT NOT NULL,
        mod_id INT NOT NULL,
        mod_name VARCHAR(120) NOT NULL DEFAULT '',
        version VARCHAR(40) NOT NULL DEFAULT '',
        files MEDIUMTEXT NULL,
        file_count INT NOT NULL DEFAULT 0,
        installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (server_id, mod_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mods_settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        signing_key CHAR(32) NOT NULL DEFAULT '',
        site_url VARCHAR(255) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO mods_settings (id) VALUES (1)");
    $pdo->prepare("UPDATE mods_settings SET signing_key = ? WHERE id = 1 AND signing_key = ''")->execute([bin2hex(random_bytes(16))]);
    @touch($marker);
}

function swiftModsSettings(PDO $pdo)
{
    $row = $pdo->query("SELECT * FROM mods_settings WHERE id = 1")->fetch();
    return $row ? $row : ['signing_key' => '', 'site_url' => ''];
}

// ---------------------------------------------------------------------------
// Catalog helpers
// ---------------------------------------------------------------------------

function swiftModsCategories()
{
    return ['Plugin', 'Mod', 'Framework', 'Map', 'Addon', 'Config', 'Other'];
}

// Typical folders per game: only suggestions shown in the admin form.
function swiftModsDirHints($game)
{
    $h = [
        'cs16' => [['AMX Mod X plugins (.amxx)', 'cstrike/addons/amxmodx/plugins'], ['AMX Mod X / Metamod packages (archive)', 'cstrike'], ['Maps (.bsp)', 'cstrike/maps']],
        'cs2' => [['Metamod / CounterStrikeSharp packages (archive)', 'game/csgo'], ['CounterStrikeSharp plugins', 'game/csgo/addons/counterstrikesharp/plugins'], ['Maps', 'game/csgo/maps']],
        'minecraft' => [['Plugins (.jar)', 'plugins'], ['Datapacks', 'world/datapacks']],
        'gmod' => [['Addons (archive)', 'garrysmod/addons'], ['Lua scripts', 'garrysmod/lua'], ['Maps', 'garrysmod/maps']],
        'tf2' => [['SourceMod / Metamod packages (archive)', 'tf'], ['SourceMod plugins (.smx)', 'tf/addons/sourcemod/plugins'], ['Maps', 'tf/maps']],
        'l4d2' => [['SourceMod / Metamod packages (archive)', 'left4dead2'], ['Addons (.vpk)', 'left4dead2/addons'], ['SourceMod plugins', 'left4dead2/addons/sourcemod/plugins']],
        'rust' => [['Oxide / Carbon base (archive)', ''], ['Oxide plugins (.cs)', 'oxide/plugins'], ['Carbon plugins (.cs)', 'carbon/plugins']],
        'valheim' => [['BepInEx base (archive)', ''], ['BepInEx plugins (.dll)', 'BepInEx/plugins']],
        'zomboid' => [['Mods (archive)', 'Zomboid/mods']],
        '7dtd' => [['Mods (archive)', 'Mods']],
        'palworld' => [['Pak mods (.pak)', 'Pal/Content/Paks/~mods']],
    ];
    return $h[$game] ?? [['Server root', '']];
}

// Returns the cleaned folder (relative, '' = server root) or null when it is not acceptable.
function swiftModsValidTarget($t)
{
    $t = trim(str_replace('\\', '/', (string)$t), " /");
    if ($t === '') {
        return '';
    }
    if (strlen($t) > 200 || !preg_match('#^[A-Za-z0-9_.~ -]+(/[A-Za-z0-9_.~ -]+)*$#', $t)) {
        return null;
    }
    foreach (explode('/', $t) as $part) {
        if ($part === '.' || $part === '..') {
            return null;
        }
    }
    return $t;
}

// Returns the file name or null.
function swiftModsValidFilename($n)
{
    $n = trim((string)$n);
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,99}$/', $n) ? $n : null;
}

// Returns the URL or null (http/https only).
function swiftModsValidUrl($u)
{
    $u = trim((string)$u);
    if ($u === '' || strlen($u) > 600 || !preg_match('#^https?://[^\s]+$#i', $u) || !filter_var($u, FILTER_VALIDATE_URL)) {
        return null;
    }
    $host = parse_url($u, PHP_URL_HOST);
    return ($host === null || $host === false || $host === '') ? null : $u;
}

// Returns the lower-case hash ('' = none) or null when malformed.
function swiftModsValidSha($s)
{
    $s = strtolower(trim((string)$s));
    return ($s === '' || preg_match('/^[0-9a-f]{64}$/', $s)) ? $s : null;
}

// zip, tar, tar.gz, tar.bz2 or tar.xz
function swiftModsLooksLikeArchive($path)
{
    $f = @fopen($path, 'rb');
    if (!$f) {
        return false;
    }
    $head = (string)fread($f, 6);
    fseek($f, 257);
    $tar = (string)fread($f, 5);
    fclose($f);
    return substr($head, 0, 2) === 'PK' || substr($head, 0, 2) === "\x1f\x8b" || substr($head, 0, 3) === 'BZh'
        || $head === "\xfd7zXZ\x00" || $tar === 'ustar';
}

function swiftModsFormatBytes($b)
{
    $b = (float)$b;
    if ($b >= 1048576) {
        return number_format($b / 1048576, 1) . ' MB';
    }
    if ($b >= 1024) {
        return number_format($b / 1024, 0) . ' KB';
    }
    return (int)$b . ' B';
}

function swiftModsFilesDir()
{
    return __DIR__ . '/files';
}

// ---------------------------------------------------------------------------
// URLs and signed download links
// ---------------------------------------------------------------------------

// Folder of the panel relative to the site root, whichever folder the running script lives in.
function swiftModsBasePath()
{
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    foreach (['/admin', '/mods', '/payments'] as $sub) {
        if (substr($dir, -strlen($sub)) === $sub) {
            $dir = substr($dir, 0, -strlen($sub));
            break;
        }
    }
    return rtrim($dir, '/');
}

// Public base URL of the panel (what a node can reach); '' when unknown.
function swiftModsSiteUrl(array $s)
{
    $u = trim((string)($s['site_url'] ?? ''));
    if ($u !== '') {
        return rtrim($u, '/');
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        return '';
    }
    return 'https://' . $host . swiftModsBasePath();
}

function swiftModsSignature($key, $modId, $exp)
{
    return hash_hmac('sha256', (int)$modId . '|' . (int)$exp, (string)$key);
}

// Time-limited link the node uses to fetch an uploaded file; '' when the panel URL is unknown.
function swiftModsSignedUrl(PDO $pdo, $modId, $ttl = SWIFT_MODS_URL_TTL)
{
    $s = swiftModsSettings($pdo);
    $base = swiftModsSiteUrl($s);
    if ($base === '' || (string)$s['signing_key'] === '') {
        return '';
    }
    $exp = time() + (int)$ttl;
    return $base . '/mods/download.php?id=' . (int)$modId . '&exp=' . $exp . '&sig=' . swiftModsSignature($s['signing_key'], $modId, $exp);
}

function swiftModsVerifySignature(PDO $pdo, $id, $exp, $sig)
{
    $s = swiftModsSettings($pdo);
    $exp = (int)$exp;
    if ((string)$s['signing_key'] === '' || $exp < time() || $exp > time() + 3600) {
        return false;
    }
    return hash_equals(swiftModsSignature($s['signing_key'], $id, $exp), (string)$sig);
}

// ---------------------------------------------------------------------------
// Sessions
// ---------------------------------------------------------------------------

function swiftModsCsrfToken()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['swiftmods_csrf'])) {
        $_SESSION['swiftmods_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['swiftmods_csrf'];
}

function swiftModsCsrfOk()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $t = (string)($_POST['csrf'] ?? '');
    return $t !== '' && !empty($_SESSION['swiftmods_csrf']) && hash_equals((string)$_SESSION['swiftmods_csrf'], $t);
}

// Logged-in client or a redirect to the login page. (Written here because this folder's pages must not
// depend on relative redirects.)
function swiftModsRequireClient(PDO $pdo)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $login = swiftModsBasePath() . '/index.php';
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        header('Location: ' . $login);
        exit;
    }
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u || (array_key_exists('email_verified', $u) && (int)$u['email_verified'] === 0)) {
        header('Location: ' . $login);
        exit;
    }
    return $u;
}

// Allows a handful of installs per minute per login, so nobody can hammer the nodes.
function swiftModsRateOk($limit = 12, $window = 60)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $now = time();
    $hits = array_filter((array)($_SESSION['swiftmods_hits'] ?? []), function ($t) use ($now, $window) {
        return $t > $now - $window;
    });
    if (count($hits) >= $limit) {
        $_SESSION['swiftmods_hits'] = $hits;
        return false;
    }
    $hits[] = $now;
    $_SESSION['swiftmods_hits'] = array_values($hits);
    return true;
}

// ---------------------------------------------------------------------------
// Talking to the node
// ---------------------------------------------------------------------------

// $server needs node_ip, api_port and api_token (boxes.ip_address / ssh_port / ssh_password).
// Returns ['ok' => bool, 'data' => array, 'error' => string].
function swiftModsAgent(array $server, $endpoint, array $payload, $timeout = 30)
{
    $port = (int)($server['api_port'] ?? 0);
    if ($port <= 0) {
        $port = 8085;
    }
    $ch = curl_init('http://' . $server['node_ip'] . ':' . $port . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . (string)$server['api_token']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => (int)$timeout,
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($raw === false) {
        if ($errno === 28) {
            return ['ok' => false, 'data' => [], 'error' => 'The server node did not answer in time. The download may still be running: check the File Manager and try again.'];
        }
        return ['ok' => false, 'data' => [], 'error' => 'Could not reach the server node: ' . $err];
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'data' => [], 'error' => 'The server node sent an unexpected answer (HTTP ' . $http . ').'];
    }
    $msg = (string)($data['error'] ?? '');
    if ($http === 404 && stripos($msg, 'Endpoint not found') !== false) {
        $msg = 'This node is still running an old version of the agent without mod support. Update the node by running the installer again.';
    } elseif ($http === 401) {
        $msg = 'The node refused the panel\'s access token. Check the node token in Admin > Remote Nodes.';
    } elseif ($msg === '' && $http !== 200) {
        $msg = 'The server node answered with HTTP ' . $http . '.';
    }
    return ['ok' => ($http === 200 && !empty($data['ok'])), 'data' => $data, 'error' => $msg];
}

// Loads one of the client's servers together with its node's access data. null when it is not theirs.
function swiftModsLoadServer(PDO $pdo, $serverId, $userId)
{
    $st = $pdo->prepare("SELECT s.*, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token FROM servers s JOIN boxes b ON s.box_id = b.id WHERE s.id = ? AND s.user_id = ?");
    $st->execute([(int)$serverId, (int)$userId]);
    $r = $st->fetch();
    return $r ? $r : null;
}

// ---------------------------------------------------------------------------
// Install / remove
// ---------------------------------------------------------------------------

// Installs a catalog entry on a server (the caller has already checked that the server is the client's).
function swiftModsInstall(PDO $pdo, array $server, array $mod, &$error = null)
{
    if ((int)$mod['enabled'] !== 1 || $mod['game'] !== $server['game']) {
        $error = 'This item is not available for this server.';
        return false;
    }
    if ($server['status'] === 'installing') {
        $error = 'The server is still being installed. Try again in a few minutes.';
        return false;
    }
    if ($mod['source_type'] === 'upload') {
        if ($mod['stored_name'] === '' || !is_file(swiftModsFilesDir() . '/' . basename($mod['stored_name']))) {
            $error = 'The file of this item is missing. Please tell support.';
            return false;
        }
        $url = swiftModsSignedUrl($pdo, (int)$mod['id']);
        if ($url === '') {
            $error = 'The panel address is not configured yet. Please tell support.';
            return false;
        }
    } else {
        $url = (string)$mod['source_url'];
    }

    $r = swiftModsAgent($server, '/api/mods/install', [
        'install_path' => $server['install_path'],
        'url' => $url,
        'kind' => $mod['install_kind'],
        'target' => $mod['target_dir'],
        'filename' => $mod['install_name'],
        'sha256' => $mod['sha256'],
        'max_mb' => 250,
    ], 55);
    if (!$r['ok']) {
        $error = $r['error'] !== '' ? $r['error'] : 'The installation failed.';
        return false;
    }

    $files = array_values(array_map('strval', (array)($r['data']['files'] ?? [])));
    $count = max(count($files), (int)($r['data']['file_count'] ?? count($files)));
    $pdo->prepare("INSERT INTO server_mods (server_id, mod_id, mod_name, version, files, file_count) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE mod_name = VALUES(mod_name), version = VALUES(version), files = VALUES(files), file_count = VALUES(file_count), installed_at = NOW()")
        ->execute([(int)$server['id'], (int)$mod['id'], substr((string)$mod['name'], 0, 120), (string)$mod['version'], json_encode($files), $count]);
    return true;
}

// Removes the files an installed item added. Works from the list recorded at install time, so it also works
// when the admin has deleted the item from the catalog.
function swiftModsRemove(PDO $pdo, array $server, $modId, &$error = null)
{
    $st = $pdo->prepare("SELECT * FROM server_mods WHERE server_id = ? AND mod_id = ?");
    $st->execute([(int)$server['id'], (int)$modId]);
    $row = $st->fetch();
    if (!$row) {
        $error = 'This item is not installed.';
        return false;
    }
    $files = json_decode((string)$row['files'], true);
    if (!is_array($files)) {
        $files = [];
    }
    if (count($files) < (int)$row['file_count']) {
        $error = 'This package has too many files to remove automatically. Please remove them in the File Manager.';
        return false;
    }
    if ($files) {
        $r = swiftModsAgent($server, '/api/mods/remove', ['install_path' => $server['install_path'], 'paths' => $files], 45);
        if (!$r['ok']) {
            $error = $r['error'] !== '' ? $r['error'] : 'The files could not be removed.';
            return false;
        }
    }
    $pdo->prepare("DELETE FROM server_mods WHERE server_id = ? AND mod_id = ?")->execute([(int)$server['id'], (int)$modId]);
    return true;
}
