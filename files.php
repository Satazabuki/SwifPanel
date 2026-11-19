<?php
require_once 'auth.php';
require_once 'config.php';
require_once 'games.php';

$userId = (int)$_SESSION['user_id'];
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$serverId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($isAdmin) {
    $stmt = $pdo->prepare("SELECT s.*, b.name AS box_name, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token FROM servers s JOIN boxes b ON s.box_id = b.id WHERE s.id = ?");
    $stmt->execute([$serverId]);
} else {
    $stmt = $pdo->prepare("SELECT s.*, b.name AS box_name, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token FROM servers s JOIN boxes b ON s.box_id = b.id WHERE s.id = ? AND s.user_id = ?");
    $stmt->execute([$serverId, $userId]);
}
$server = $stmt->fetch();
if (!$server) { header("Location: dashboard.php"); exit; }

function callSpringsFileApi($server, $endpoint, $payload) {
    $port = (int)($server['api_port'] ?: 8085);
    $payload['install_path'] = $server['install_path'];
    $ch = curl_init("http://{$server['node_ip']}:{$port}{$endpoint}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $server['api_token']],
        CURLOPT_TIMEOUT => in_array($endpoint, ['/api/files/upload', '/api/files/extract'], true) ? 120 : 15
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) return ['error' => 'Springs error: ' . $err];
    $data = json_decode($resp, true);
    return is_array($data) ? $data : ['error' => "Invalid response (HTTP {$code})"];
}

$notice = '';
$errorMsg = '';
$relDir = trim($_GET['dir'] ?? '', '/');
$editFile = trim($_GET['edit'] ?? '', '/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_file'])) {
        $targetFile = trim($_POST['file_path'] ?? '', '/');
        $content = str_replace("\r\n", "\n", $_POST['file_content'] ?? '');
        $res = callSpringsFileApi($server, '/api/files/write', ['rel_path' => $targetFile, 'content' => $content]);
        if (!empty($res['ok'])) {
            $notice = "Saved '{$targetFile}'.";
            $editFile = $targetFile;
            $relDir = trim(dirname($targetFile) === '.' ? '' : dirname($targetFile), '/');
        } else {
            $errorMsg = $res['error'] ?? 'Failed to save file.';
        }
    } elseif (isset($_POST['create_item'])) {
        $cleanName = ltrim(trim($_POST['item_name'] ?? ''), '/');
        $type = $_POST['item_type'] ?? 'file';
        if ($cleanName !== '') {
            $fullRel = $relDir !== '' ? "{$relDir}/{$cleanName}" : $cleanName;
            $ep = $type === 'dir' ? '/api/files/mkdir' : '/api/files/write';
            $res = callSpringsFileApi($server, $ep, ['rel_path' => $fullRel, 'content' => '', 'create_only' => true]);
            if (!empty($res['ok'])) {
                $notice = "Created '{$cleanName}'.";
                if ($type !== 'dir') $editFile = $fullRel;
            } else {
                $errorMsg = $res['error'] ?? 'Create failed.';
            }
        }
    } elseif (isset($_POST['upload_files']) && !empty($_FILES['files']['name'][0])) {
        $uploaded = 0;
        foreach ($_FILES['files']['name'] as $idx => $origName) {
            if ($_FILES['files']['error'][$idx] !== UPLOAD_ERR_OK) continue;
            $safeName = basename($origName);
            $fullRel = $relDir !== '' ? "{$relDir}/{$safeName}" : $safeName;
            $b64 = base64_encode(file_get_contents($_FILES['files']['tmp_name'][$idx]));
            $res = callSpringsFileApi($server, '/api/files/upload', ['rel_path' => $fullRel, 'content_b64' => $b64]);
            if (!empty($res['ok'])) $uploaded++;
            else $errorMsg = $res['error'] ?? "Failed uploading {$safeName}";
        }
        if ($uploaded > 0) $notice = "Uploaded {$uploaded} file(s).";
    } elseif (isset($_POST['extract_zip'])) {
        $zipRel = trim($_POST['target_path'] ?? '', '/');
        if ($zipRel !== '') {
            $res = callSpringsFileApi($server, '/api/files/extract', ['rel_path' => $zipRel]);
            if (!empty($res['ok'])) $notice = "Extracted '{$zipRel}'.";
            else $errorMsg = $res['error'] ?? "Extract failed.";
        }
    } elseif (isset($_POST['delete_item'])) {
        $delRel = trim($_POST['target_path'] ?? '', '/');
        if ($delRel !== '') {
            $res = callSpringsFileApi($server, '/api/files/delete', ['rel_path' => $delRel]);
            if (!empty($res['ok'])) {
                $notice = "Deleted '{$delRel}'.";
                if ($editFile === $delRel) $editFile = '';
            } else {
                $errorMsg = $res['error'] ?? 'Delete failed.';
            }
        }
    }
}

$fileContent = '';
if ($editFile !== '') {
    $readRes = callSpringsFileApi($server, '/api/files/read', ['rel_path' => $editFile]);
    if (!empty($readRes['ok'])) { $fileContent = $readRes['content']; }
    else { $errorMsg = $readRes['error'] ?? 'Unable to open file.'; $editFile = ''; }
}

$listRes = callSpringsFileApi($server, '/api/files/list', ['rel_path' => $relDir]);
$items = $listRes['items'] ?? [];
if (!empty($listRes['error']) && $errorMsg === '') $errorMsg = $listRes['error'];

function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}
?>
<?php require_once __DIR__ . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>File Manager - <?php echo htmlspecialchars($server['name']); ?></title>
    <link rel="stylesheet" href="assets/style.css?v=3">
    <style>
        .container { max-width: 1180px; margin: 24px auto; padding: 0 18px; }
        .breadcrumb { display:flex; align-items:center; gap:6px; flex-wrap:wrap; background:#141820; padding:8px 12px; border-radius:4px; margin-bottom:12px; font-size:13px; }
        .breadcrumb a { color:#3498db; text-decoration:none; }
        .quick-configs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
        .editor-area { width:100%; min-height:420px; background:#0d1117; color:#e6edf3; border:1px solid #30363d; border-radius:4px; padding:12px; font-family:Consolas, Monaco, monospace; font-size:13px; line-height:1.5; resize:vertical; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#aaa;">Springs Docker Edition</small></div>
        <div class="user-info">
            Logged in as: <strong><?php echo htmlspecialchars($_SESSION["username"] ?? "user"); ?></strong> | <a href="logout.php">Logout</a>
        </div>
    </div>
    <div id="navbar">
        <ul>
            <li><a href="server.php?id=<?php echo (int)$server['id']; ?>">Server Management</a></li>
            <li><a href="dashboard.php?page=order">Order Server</a></li>
            <li><a href="dashboard.php?page=invoices">Invoices</a></li>
            <li><a href="mods/index.php">Mods &amp; Plugins</a></li>
            <li><a href="tickets.php">Support Tickets</a></li>
            <li><a href="profile.php">My Profile</a></li>
        </ul>
    </div>
    <div class="container">
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($errorMsg): ?><div class="alert alert-error"><?php echo htmlspecialchars($errorMsg); ?></div><?php endif; ?>
        <div class="box">
            <div class="box-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span>File Manager: <strong><?php echo htmlspecialchars($server['name']); ?></strong> (<code><?php echo htmlspecialchars($server['install_path']); ?></code>)</span>
                <a href="server.php?id=<?php echo (int)$server['id']; ?>" class="btn" style="padding:4px 10px;font-size:12px;">&larr; Back to Console</a>
            </div>
            <div class="box-content">
                <div class="quick-configs">
                    <span style="font-size:12px;opacity:0.75;align-self:center;">Quick Configs:</span>
                    <?php if ($server['game'] === 'cs16'): ?>
                        <a href="files.php?id=<?php echo (int)$server['id']; ?>&dir=cstrike&edit=cstrike/server.cfg" class="btn" style="padding:4px 9px;font-size:12px;">server.cfg</a>
                        <a href="files.php?id=<?php echo (int)$server['id']; ?>&dir=cstrike&edit=cstrike/mapcycle.txt" class="btn" style="padding:4px 9px;font-size:12px;">mapcycle.txt</a>
                        <a href="files.php?id=<?php echo (int)$server['id']; ?>&dir=cstrike/addons/amxmodx/configs&edit=cstrike/addons/amxmodx/configs/users.ini" class="btn" style="padding:4px 9px;font-size:12px;">users.ini</a>
                        <a href="files.php?id=<?php echo (int)$server['id']; ?>&dir=cstrike/addons/amxmodx/configs&edit=cstrike/addons/amxmodx/configs/plugins.ini" class="btn" style="padding:4px 9px;font-size:12px;">plugins.ini</a>
                    <?php elseif ($server['game'] === 'cs2'): ?>
                        <a href="files.php?id=<?php echo (int)$server['id']; ?>&dir=game/csgo/cfg&edit=game/csgo/cfg/server.cfg" class="btn" style="padding:4px 9px;font-size:12px;">server.cfg</a>
                        <a href="files.php?id=<?php echo (int)$server['id']; ?>&edit=springs.env" class="btn" style="padding:4px 9px;font-size:12px;">springs.env (settings)</a>
                    <?php elseif ($server['game'] === 'minecraft'): ?>
                        <a href="files.php?id=<?php echo (int)$server['id']; ?>&edit=server.properties" class="btn" style="padding:4px 9px;font-size:12px;">server.properties</a>
                        <a href="files.php?id=<?php echo (int)$server['id']; ?>&edit=bukkit.yml" class="btn" style="padding:4px 9px;font-size:12px;">bukkit.yml</a>
                    <?php elseif (($gDef = swiftGame($server['game'])) && !empty($gDef['configs'])): ?>
                        <?php foreach ($gDef['configs'] as $cf): ?>
                            <a href="files.php?id=<?php echo (int)$server['id']; ?>&dir=<?php echo urlencode($cf[1]); ?>&edit=<?php echo urlencode($cf[2]); ?>" class="btn" style="padding:4px 9px;font-size:12px;"><?php echo htmlspecialchars($cf[0]); ?></a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ($editFile !== ''): ?>
                    <div style="margin-bottom:16px;border:1px solid #2b3240;border-radius:5px;padding:12px;background:#161b22;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                            <strong>Editing: <code>/<?php echo htmlspecialchars($editFile); ?></code></strong>
                            <a href="files.php?id=<?php echo (int)$server['id']; ?>&dir=<?php echo urlencode($relDir); ?>" class="btn btn-red" style="padding:4px 10px;font-size:12px;">Close Editor</a>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="file_path" value="<?php echo htmlspecialchars($editFile); ?>">
                            <textarea name="file_content" class="editor-area" spellcheck="false"><?php echo htmlspecialchars($fileContent); ?></textarea>
                            <div style="margin-top:10px;"><button type="submit" name="save_file" value="1" class="btn btn-green">Save Changes</button></div>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="breadcrumb">
                    <a href="files.php?id=<?php echo (int)$server['id']; ?>">/ (root)</a>
                    <?php if ($relDir !== '') { $acc = ''; foreach (explode('/', $relDir) as $part) { $acc = $acc === '' ? $part : "{$acc}/{$part}"; echo '<span>/</span><a href="files.php?id=' . (int)$server['id'] . '&dir=' . urlencode($acc) . '">' . htmlspecialchars($part) . '</a>'; } } ?>
                </div>

                <form method="POST" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
                    <input type="text" name="item_name" placeholder="New filename or folder name..." required style="min-width:240px;">
                    <select name="item_type"><option value="file">New File</option><option value="dir">New Folder</option></select>
                    <button type="submit" name="create_item" value="1" class="btn btn-green">Create</button>
                </form>

                <form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center;">
                    <input type="file" name="files[]" multiple required style="font-size:12px;color:#ccc;">
                    <button type="submit" name="upload_files" value="1" class="btn btn-green">Upload to this folder</button>
                </form>

                <table class="data-table">
                    <thead><tr><th>Name</th><th style="width:110px;">Size</th><th style="width:170px;">Modified</th><th style="width:160px;">Actions</th></tr></thead>
                    <tbody>
                        <?php if ($relDir !== ''): $parentDir = dirname($relDir) === '.' ? '' : dirname($relDir); ?>
                        <tr><td colspan="4"><a href="files.php?id=<?php echo (int)$server['id']; ?>&dir=<?php echo urlencode($parentDir); ?>" style="font-weight:bold;">&#128193; .. (Parent Directory)</a></td></tr>
                        <?php endif; ?>
                        <?php if (empty($items)): ?>
                            <tr><td colspan="4" style="text-align:center;opacity:0.7;">This directory is empty.</td></tr>
                        <?php else: foreach ($items as $item): $itemRel = $relDir !== '' ? "{$relDir}/{$item['name']}" : $item['name']; ?>
                            <tr>
                                <td>
                                    <?php if ($item['is_dir']): ?>
                                        <a href="files.php?id=<?php echo (int)$server['id']; ?>&dir=<?php echo urlencode($itemRel); ?>" style="color:#f1c40f;font-weight:bold;">&#128193; <?php echo htmlspecialchars($item['name']); ?></a>
                                    <?php else: ?>
                                        <a href="files.php?id=<?php echo (int)$server['id']; ?>&dir=<?php echo urlencode($relDir); ?>&edit=<?php echo urlencode($itemRel); ?>" style="color:#ecf0f1;">&#128196; <?php echo htmlspecialchars($item['name']); ?></a>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $item['is_dir'] ? 'Folder' : formatBytes($item['size']); ?></td>
                                <td><?php echo date('Y-m-d H:i', $item['mtime']); ?></td>
                                <td>
                                    <?php if (!$item['is_dir']): ?><a href="files.php?id=<?php echo (int)$server['id']; ?>&dir=<?php echo urlencode($relDir); ?>&edit=<?php echo urlencode($itemRel); ?>" class="btn" style="padding:3px 8px;font-size:11px;">Edit</a><?php endif; ?>
                                    <?php if (!$item['is_dir'] && strtolower(pathinfo($item['name'], PATHINFO_EXTENSION)) === 'zip'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="target_path" value="<?php echo htmlspecialchars($itemRel); ?>">
                                            <button type="submit" name="extract_zip" value="1" class="btn btn-green" style="padding:2px 8px;font-size:11px;">Extract</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode('Delete ' . $item['name'] . '?', JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES); ?>);">
                                        <input type="hidden" name="target_path" value="<?php echo htmlspecialchars($itemRel); ?>">
                                        <button type="submit" name="delete_item" value="1" class="btn btn-red" style="padding:3px 8px;font-size:11px;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php require_once __DIR__ . '/legal_lib.php'; echo swiftFooterHtml(); ?>
</body>
</html>
