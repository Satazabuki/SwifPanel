<?php
// download.php?id=MODID&exp=UNIXTIME&sig=HMAC
// Serves an uploaded mod file to a game node. The link is signed by the panel when a client clicks Install,
// works for a few minutes only, and cannot be forged. (Files in /mods/files/ have random names and are not
// meant to be opened directly: see the README for the web server rule that blocks that folder.)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/mods_lib.php';

swiftModsEnsureSchema($pdo);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0 || !swiftModsVerifySignature($pdo, $id, (int)($_GET['exp'] ?? 0), (string)($_GET['sig'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

$st = $pdo->prepare("SELECT * FROM mods WHERE id = ? AND enabled = 1 AND source_type = 'upload'");
$st->execute([$id]);
$mod = $st->fetch();
$path = $mod ? swiftModsFilesDir() . '/' . basename((string)$mod['stored_name']) : '';
if (!$mod || (string)$mod['stored_name'] === '' || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
@set_time_limit(0);
$name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$mod['original_name']);
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . ($name !== '' ? $name : 'download.bin') . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
