<?php
// mods/admin.php - Admin > Mods & Plugins: curate the catalog clients can install from.
require_once __DIR__ . '/../admin/guard.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/mods_lib.php';

swiftModsEnsureSchema($pdo, true);
$csrf = swiftModsCsrfToken();
$root = swiftModsBasePath();
$games = swiftGames();

// Saves (adds or changes) a catalog entry from the admin form. Returns the id, or 0 with $error set.
function swiftModsAdminSave(PDO $pdo, array $p, array $files, &$error)
{
    $games = swiftGames();
    $id = (int)($p['mod_id'] ?? 0);
    $old = null;
    if ($id > 0) {
        $st = $pdo->prepare("SELECT * FROM mods WHERE id = ?");
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) {
            $error = 'That item no longer exists.';
            return 0;
        }
    }

    $game = (string)($p['game'] ?? '');
    $name = trim((string)($p['name'] ?? ''));
    if (!isset($games[$game])) {
        $error = 'Choose a game.';
        return 0;
    }
    if ($name === '' || strlen($name) > 120) {
        $error = 'Enter a name (up to 120 characters).';
        return 0;
    }
    $category = in_array((string)($p['category'] ?? ''), swiftModsCategories(), true) ? (string)$p['category'] : 'Plugin';
    $version = substr(trim((string)($p['version'] ?? '')), 0, 40);
    $author = substr(trim((string)($p['author'] ?? '')), 0, 80);
    $description = substr(trim((string)($p['description'] ?? '')), 0, 2000);
    $note = substr(trim((string)($p['post_note'] ?? '')), 0, 500);
    $kind = (($p['install_kind'] ?? '') === 'archive') ? 'archive' : 'file';
    $enabled = !empty($p['enabled']) ? 1 : 0;
    $sourceType = (($p['source_type'] ?? '') === 'upload') ? 'upload' : 'url';
    $target = swiftModsValidTarget($p['target_dir'] ?? '');
    if ($target === null) {
        $error = 'The target folder may only contain letters, digits and . _ ~ - and spaces, separated by / (no "..").';
        return 0;
    }
    $sha = swiftModsValidSha($p['sha256'] ?? '');
    if ($sha === null) {
        $error = 'The SHA-256 checksum must be 64 hexadecimal characters (or empty).';
        return 0;
    }

    // ---- where the file comes from ----
    $url = '';
    $stored = '';
    $orig = '';
    $size = 0;
    $newUpload = null;
    if ($sourceType === 'upload') {
        $up = $files['package'] ?? null;
        $hasUpload = is_array($up) && (int)($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($hasUpload) {
            if ((int)$up['error'] !== UPLOAD_ERR_OK) {
                $error = 'The upload failed (error ' . (int)$up['error'] . '). Check upload_max_filesize and post_max_size in php.ini.';
                return 0;
            }
            if ((int)$up['size'] > SWIFT_MODS_MAX_UPLOAD_MB * 1048576) {
                $error = 'The file is larger than ' . SWIFT_MODS_MAX_UPLOAD_MB . ' MB.';
                return 0;
            }
            if (!is_uploaded_file((string)$up['tmp_name'])) {
                $error = 'The upload could not be verified.';
                return 0;
            }
            if ($kind === 'archive' && !swiftModsLooksLikeArchive((string)$up['tmp_name'])) {
                $error = 'An archive must be a .zip, .tar.gz, .tar.bz2 or .tar.xz file.';
                return 0;
            }
            $newUpload = [
                'tmp' => (string)$up['tmp_name'],
                'orig' => substr(preg_replace('/[^A-Za-z0-9._ -]/', '_', basename((string)$up['name'])), 0, 150),
                'size' => (int)$up['size'],
                'sha' => hash_file('sha256', (string)$up['tmp_name']),
            ];
            $orig = $newUpload['orig'];
            $size = $newUpload['size'];
            $sha = $newUpload['sha'];
        } elseif ($old && $old['source_type'] === 'upload' && $old['stored_name'] !== '') {
            $stored = (string)$old['stored_name'];
            $orig = (string)$old['original_name'];
            $size = (int)$old['file_size'];
            $sha = (string)$old['sha256'];
            if ($kind === 'archive' && !swiftModsLooksLikeArchive(swiftModsFilesDir() . '/' . basename($stored))) {
                $error = 'The uploaded file is not an archive. Upload a .zip or .tar.gz, or set the type to "single file".';
                return 0;
            }
        } else {
            $error = 'Choose a file to upload.';
            return 0;
        }
    } else {
        $url = swiftModsValidUrl($p['source_url'] ?? '');
        if ($url === null) {
            $error = 'Enter a valid http(s) download link.';
            return 0;
        }
    }

    // ---- name of the saved file (single files only) ----
    $installName = '';
    if ($kind === 'file') {
        $cand = trim((string)($p['install_name'] ?? ''));
        if ($cand === '') {
            $cand = ($sourceType === 'upload') ? $orig : basename((string)parse_url($url, PHP_URL_PATH));
        }
        $installName = swiftModsValidFilename($cand);
        if ($installName === null) {
            $error = 'Enter the file name to save as (letters, digits, . _ - and spaces; no folders).';
            return 0;
        }
    }

    // ---- store the upload, then the row ----
    if ($newUpload) {
        $stored = bin2hex(random_bytes(16)) . '.dat';
        if (!@move_uploaded_file($newUpload['tmp'], swiftModsFilesDir() . '/' . $stored)) {
            $error = 'Could not save the file in /mods/files/. Make that folder writable for the web server.';
            return 0;
        }
        @chmod(swiftModsFilesDir() . '/' . $stored, 0640);
    }

    $vals = [$game, $name, $category, $version, $author, $description, $sourceType, $url, $stored, $orig, $size, $sha, $kind, $target, $installName, $note, $enabled];
    try {
        if ($old) {
            $pdo->prepare("UPDATE mods SET game=?, name=?, category=?, version=?, author=?, description=?, source_type=?, source_url=?, stored_name=?, original_name=?, file_size=?, sha256=?, install_kind=?, target_dir=?, install_name=?, post_note=?, enabled=? WHERE id=?")
                ->execute(array_merge($vals, [$id]));
        } else {
            $pdo->prepare("INSERT INTO mods (game, name, category, version, author, description, source_type, source_url, stored_name, original_name, file_size, sha256, install_kind, target_dir, install_name, post_note, enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute($vals);
            $id = (int)$pdo->lastInsertId();
        }
    } catch (Exception $e) {
        if ($newUpload) {
            @unlink(swiftModsFilesDir() . '/' . $stored);
        }
        $error = 'The item could not be saved.';
        return 0;
    }

    // the previous uploaded file is no longer used
    if ($old && $old['stored_name'] !== '' && $old['stored_name'] !== $stored) {
        @unlink(swiftModsFilesDir() . '/' . basename((string)$old['stored_name']));
    }
    return $id;
}

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!swiftModsCsrfOk()) {
        $error = 'Security check failed. Please reload the page and try again.';
    } elseif (isset($_POST['save_mod'])) {
        $err = null;
        $newId = swiftModsAdminSave($pdo, $_POST, $_FILES, $err);
        if ($newId > 0) {
            $notice = !empty($_POST['mod_id']) ? 'Item saved.' : 'Item added to the catalog.';
        } else {
            $error = (string)$err;
        }
    } elseif (isset($_POST['toggle_mod'])) {
        $pdo->prepare("UPDATE mods SET enabled = 1 - enabled WHERE id = ?")->execute([(int)($_POST['mod_id'] ?? 0)]);
        $notice = 'Visibility changed.';
    } elseif (isset($_POST['delete_mod'])) {
        $did = (int)($_POST['mod_id'] ?? 0);
        $ds = $pdo->prepare("SELECT stored_name FROM mods WHERE id = ?");
        $ds->execute([$did]);
        $row = $ds->fetch();
        if ($row) {
            if ($row['stored_name'] !== '') {
                @unlink(swiftModsFilesDir() . '/' . basename((string)$row['stored_name']));
            }
            $pdo->prepare("DELETE FROM mods WHERE id = ?")->execute([$did]);
            $notice = 'Item deleted. Servers that have it installed keep the files and can still remove them.';
        }
    } elseif (isset($_POST['save_settings'])) {
        $site = trim((string)($_POST['site_url'] ?? ''));
        if ($site !== '' && !preg_match('#^https?://[^\s]+$#i', $site)) {
            $error = 'The panel address must start with http:// or https://';
        } else {
            $pdo->prepare("UPDATE mods_settings SET site_url = ? WHERE id = 1")->execute([rtrim($site, '/')]);
            if (!empty($_POST['regen_key'])) {
                $pdo->prepare("UPDATE mods_settings SET signing_key = ? WHERE id = 1")->execute([bin2hex(random_bytes(16))]);
            }
            $notice = 'Settings saved.';
        }
    }
}

$settings = swiftModsSettings($pdo);
$list = $pdo->query("SELECT m.*, (SELECT COUNT(*) FROM server_mods sm WHERE sm.mod_id = m.id) AS installs FROM mods m ORDER BY m.game, m.category, m.name")->fetchAll();

$edit = null;
if (!empty($_GET['edit'])) {
    $es = $pdo->prepare("SELECT * FROM mods WHERE id = ?");
    $es->execute([(int)$_GET['edit']]);
    $edit = $es->fetch();
}
$f = $edit ? $edit : [
    'id' => 0, 'game' => (string)($_POST['game'] ?? key($games)), 'name' => '', 'category' => 'Plugin', 'version' => '', 'author' => '',
    'description' => '', 'source_type' => 'url', 'source_url' => '', 'sha256' => '', 'install_kind' => 'file', 'target_dir' => '',
    'install_name' => '', 'post_note' => '', 'enabled' => 1, 'original_name' => '', 'file_size' => 0,
];
$hints = [];
foreach ($games as $slug => $g) {
    $hints[$slug] = swiftModsDirHints($slug);
}
$siteShown = swiftModsSiteUrl($settings);
$filesWritable = is_dir(swiftModsFilesDir()) && is_writable(swiftModsFilesDir());
?>
<?php require_once dirname(__DIR__) . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin - Mods &amp; Plugins</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($root); ?>/assets/style.css?v=3">
    <style>
        .container { max-width: 1180px; margin: 24px auto; padding: 0 18px; }
        .p-table { width: 100%; border-collapse: collapse; }
        .p-table th, .p-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #262c3a; font-size: 13px; vertical-align: top; }
        .p-table th { color: #94a3b8; font-weight: 600; background: #141820; }
        .form-input { background: #0d1117; color: #fff; border: 1px solid #30363d; border-radius: 4px; padding: 8px 10px; font-size: 13px; width: 100%; box-sizing: border-box; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; }
        .form-grid label { display: block; font-size: 12px; color: #94a3b8; margin-bottom: 4px; }
        .hint { font-size: 12px; color: #64748b; margin-top: 4px; }
        .tag { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; background: rgba(52,152,219,0.18); color: #3498db; }
        .tag-off { background: rgba(149,165,166,0.2); color: #95a5a6; }
        .tag-on { background: rgba(46,204,113,0.18); color: #2ecc71; }
        code.small { font-size: 12px; background: #0d1117; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#e74c3c;">ADMIN CONTROL</small></div>
        <div class="user-info">Admin: <strong><?php echo htmlspecialchars((string)($_SESSION['username'] ?? 'admin')); ?></strong> | <a href="<?php echo htmlspecialchars($root); ?>/admin/login.php?logout=1">Logout</a></div>
    </div>
    <?php $adminNavActive = 'mods'; $adminNavRoot = $root; include __DIR__ . '/../admin/nav.php'; ?>
    <div class="container">
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="box" style="margin-bottom:20px;">
            <div class="box-header">Download settings</div>
            <div class="box-content">
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                    <div class="form-grid">
                        <div>
                            <label>Panel address the nodes can reach (needed for uploaded files)</label>
                            <input type="text" name="site_url" class="form-input" value="<?php echo htmlspecialchars((string)$settings['site_url']); ?>" placeholder="https://gp.example.com">
                            <div class="hint">Empty = detected from this page: <code class="small"><?php echo htmlspecialchars($siteShown !== '' ? $siteShown : 'unknown'); ?></code></div>
                        </div>
                        <div>
                            <label>&nbsp;</label>
                            <label style="color:#e2e8f0;"><input type="checkbox" name="regen_key" value="1"> Create a new signing key (invalidates links that are being used right now)</label>
                        </div>
                    </div>
                    <div style="margin-top:12px;"><button type="submit" name="save_settings" value="1" class="btn btn-green">Save</button></div>
                </form>
                <div class="hint" style="margin-top:12px;line-height:1.7;">
                    Uploaded files are kept in <code class="small">/mods/files/</code> under random names and handed to the nodes through short-lived signed links (<?php echo (int)(SWIFT_MODS_URL_TTL / 60); ?> minutes). Block direct access to that folder: Apache does it with the included .htaccess; on nginx add
                    <code class="small">location ^~ /mods/files/ { deny all; }</code> to the site config.<br>
                    Max upload here: <?php echo SWIFT_MODS_MAX_UPLOAD_MB; ?> MB. PHP currently allows <?php echo htmlspecialchars((string)ini_get('upload_max_filesize')); ?> per file and <?php echo htmlspecialchars((string)ini_get('post_max_size')); ?> per request.
                    <?php if (!$filesWritable): ?><br><span style="color:#e74c3c;">The folder /mods/files/ is not writable by the web server, uploads will fail.</span><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="box" style="margin-bottom:20px;">
            <div class="box-header"><?php echo $edit ? 'Edit item #' . (int)$edit['id'] : 'Add a mod or plugin'; ?></div>
            <div class="box-content">
                <form method="POST" enctype="multipart/form-data" style="margin:0;" id="mod-form">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="mod_id" value="<?php echo (int)$f['id']; ?>">
                    <div class="form-grid">
                        <div>
                            <label>Game</label>
                            <select name="game" id="f-game" class="form-input">
                                <?php foreach ($games as $slug => $g): ?>
                                    <option value="<?php echo htmlspecialchars($slug); ?>" <?php echo $f['game'] === $slug ? 'selected' : ''; ?>><?php echo htmlspecialchars($g['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Name</label><input type="text" name="name" class="form-input" maxlength="120" value="<?php echo htmlspecialchars((string)$f['name']); ?>" required></div>
                        <div>
                            <label>Category</label>
                            <select name="category" class="form-input">
                                <?php foreach (swiftModsCategories() as $c): ?>
                                    <option <?php echo $f['category'] === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Version</label><input type="text" name="version" class="form-input" maxlength="40" value="<?php echo htmlspecialchars((string)$f['version']); ?>"></div>
                        <div><label>Author</label><input type="text" name="author" class="form-input" maxlength="80" value="<?php echo htmlspecialchars((string)$f['author']); ?>"></div>
                    </div>
                    <div style="margin-top:14px;">
                        <label style="display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;">Description (shown to clients)</label>
                        <textarea name="description" class="form-input" rows="3" maxlength="2000"><?php echo htmlspecialchars((string)$f['description']); ?></textarea>
                    </div>

                    <div class="form-grid" style="margin-top:14px;">
                        <div>
                            <label>Where does the file come from?</label>
                            <select name="source_type" id="f-source" class="form-input">
                                <option value="url" <?php echo $f['source_type'] === 'url' ? 'selected' : ''; ?>>A download link (URL)</option>
                                <option value="upload" <?php echo $f['source_type'] === 'upload' ? 'selected' : ''; ?>>Upload a file to /mods/files/</option>
                            </select>
                        </div>
                        <div id="box-url">
                            <label>Download link (http/https)</label>
                            <input type="text" name="source_url" class="form-input" maxlength="600" value="<?php echo htmlspecialchars((string)$f['source_url']); ?>" placeholder="https://example.com/plugin.zip">
                            <div class="hint">The node downloads it directly. Use a direct file link, not a web page.</div>
                        </div>
                        <div id="box-upload">
                            <label>File (max <?php echo SWIFT_MODS_MAX_UPLOAD_MB; ?> MB)</label>
                            <input type="file" name="package" class="form-input">
                            <?php if ($edit && $edit['source_type'] === 'upload' && $edit['stored_name'] !== ''): ?>
                                <div class="hint">Current: <?php echo htmlspecialchars((string)$edit['original_name']); ?> (<?php echo htmlspecialchars(swiftModsFormatBytes($edit['file_size'])); ?>). Leave empty to keep it.</div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label>SHA-256 checksum (optional, checked by the node)</label>
                            <input type="text" name="sha256" class="form-input" maxlength="64" value="<?php echo htmlspecialchars((string)$f['sha256']); ?>" placeholder="auto for uploaded files">
                        </div>
                    </div>

                    <div class="form-grid" style="margin-top:14px;">
                        <div>
                            <label>What is it?</label>
                            <select name="install_kind" id="f-kind" class="form-input">
                                <option value="file" <?php echo $f['install_kind'] === 'file' ? 'selected' : ''; ?>>A single file (plugin .jar, .amxx, .smx ...)</option>
                                <option value="archive" <?php echo $f['install_kind'] === 'archive' ? 'selected' : ''; ?>>An archive to unpack (.zip, .tar.gz)</option>
                            </select>
                        </div>
                        <div>
                            <label>Install into this folder (relative to the server, empty = server root)</label>
                            <input type="text" name="target_dir" id="f-target" class="form-input" maxlength="200" value="<?php echo htmlspecialchars((string)$f['target_dir']); ?>" placeholder="plugins">
                            <select id="f-pick" class="form-input" style="margin-top:6px;"></select>
                        </div>
                        <div id="box-name">
                            <label>Save the file as</label>
                            <input type="text" name="install_name" class="form-input" maxlength="100" value="<?php echo htmlspecialchars((string)$f['install_name']); ?>" placeholder="empty = original name">
                        </div>
                        <div>
                            <label>Note shown after installing (optional)</label>
                            <input type="text" name="post_note" class="form-input" maxlength="500" value="<?php echo htmlspecialchars((string)$f['post_note']); ?>" placeholder="e.g. Add the plugin name to plugins.ini">
                        </div>
                    </div>

                    <div style="margin-top:14px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                        <label style="color:#e2e8f0;font-size:13px;"><input type="checkbox" name="enabled" value="1" <?php echo (int)$f['enabled'] === 1 ? 'checked' : ''; ?>> Visible to clients</label>
                        <button type="submit" name="save_mod" value="1" class="btn btn-green"><?php echo $edit ? 'Save changes' : 'Add to catalog'; ?></button>
                        <?php if ($edit): ?><a href="<?php echo htmlspecialchars($root); ?>/mods/admin.php" class="btn">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="box">
            <div class="box-header">Catalog (<?php echo count($list); ?>)</div>
            <div class="box-content" style="padding:0;">
                <?php if (empty($list)): ?>
                    <p style="padding:20px;margin:0;color:#94a3b8;">The catalog is empty. Add the first mod or plugin above; clients see only items of the game their server runs.</p>
                <?php else: ?>
                    <table class="p-table">
                        <thead><tr><th>Game</th><th>Item</th><th>Install</th><th>Source</th><th>Installed on</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($list as $m): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(swiftGameLabel($m['game'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($m['name']); ?></strong> <?php echo $m['version'] !== '' ? '<span style="color:#94a3b8;">v' . htmlspecialchars($m['version']) . '</span>' : ''; ?><br><span class="tag"><?php echo htmlspecialchars($m['category']); ?></span></td>
                                    <td><code class="small"><?php echo htmlspecialchars(($m['target_dir'] !== '' ? $m['target_dir'] : '(root)') . '/' . ($m['install_kind'] === 'file' ? $m['install_name'] : '*')); ?></code><br><small style="color:#94a3b8;"><?php echo $m['install_kind'] === 'archive' ? 'unpack archive' : 'single file'; ?></small></td>
                                    <td>
                                        <?php if ($m['source_type'] === 'upload'): ?>
                                            Upload: <?php echo htmlspecialchars((string)$m['original_name']); ?><br><small style="color:#94a3b8;"><?php echo htmlspecialchars(swiftModsFormatBytes($m['file_size'])); ?></small>
                                        <?php else: ?>
                                            <small style="word-break:break-all;"><?php echo htmlspecialchars((string)$m['source_url']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo (int)$m['installs']; ?> server(s)</td>
                                    <td><span class="tag <?php echo (int)$m['enabled'] === 1 ? 'tag-on' : 'tag-off'; ?>"><?php echo (int)$m['enabled'] === 1 ? 'VISIBLE' : 'HIDDEN'; ?></span></td>
                                    <td>
                                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                            <a class="btn" style="padding:3px 9px;font-size:12px;" href="<?php echo htmlspecialchars($root); ?>/mods/admin.php?edit=<?php echo (int)$m['id']; ?>">Edit</a>
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                                <input type="hidden" name="mod_id" value="<?php echo (int)$m['id']; ?>">
                                                <button type="submit" name="toggle_mod" value="1" class="btn" style="padding:3px 9px;font-size:12px;"><?php echo (int)$m['enabled'] === 1 ? 'Hide' : 'Show'; ?></button>
                                            </form>
                                            <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this item from the catalog?');">
                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                                <input type="hidden" name="mod_id" value="<?php echo (int)$m['id']; ?>">
                                                <button type="submit" name="delete_mod" value="1" class="btn btn-red" style="padding:3px 9px;font-size:12px;">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var HINTS = <?php echo json_encode($hints, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
            var game = document.getElementById('f-game');
            var pick = document.getElementById('f-pick');
            var target = document.getElementById('f-target');
            var source = document.getElementById('f-source');
            var kind = document.getElementById('f-kind');
            function fillHints() {
                pick.innerHTML = '';
                var first = document.createElement('option');
                first.value = '';
                first.textContent = 'Typical folders for this game...';
                pick.appendChild(first);
                (HINTS[game.value] || []).forEach(function (h) {
                    var o = document.createElement('option');
                    o.value = h[1];
                    o.textContent = h[0] + (h[1] !== '' ? ' \u2192 ' + h[1] : ' \u2192 (server root)');
                    pick.appendChild(o);
                });
            }
            function showParts() {
                document.getElementById('box-url').style.display = source.value === 'url' ? '' : 'none';
                document.getElementById('box-upload').style.display = source.value === 'upload' ? '' : 'none';
                document.getElementById('box-name').style.display = kind.value === 'file' ? '' : 'none';
            }
            pick.addEventListener('change', function () {
                if (pick.selectedIndex > 0) { target.value = pick.value; }
            });
            game.addEventListener('change', fillHints);
            source.addEventListener('change', showParts);
            kind.addEventListener('change', showParts);
            fillHints();
            showParts();
        })();
    </script>
</body>
</html>
