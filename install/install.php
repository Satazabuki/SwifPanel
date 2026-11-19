<?php
/**
 * SwiftPanel web installer.
 *
 * Upload the panel, open https://YOUR-PANEL/install/install.php, fill in the form and press Install.
 * It checks the server, creates the database tables, creates the first admin account and writes ../config.php.
 *
 * Safety: it refuses to run when ../config.php already exists or when install.lock exists, so it can never overwrite a
 * working installation. When it has finished, delete the install/ folder (there is a button for that).
 */

declare(strict_types=1);

const SWIFT_INSTALL_MIN_PHP = '7.4.0';

$root = dirname(__DIR__);
$lockFile = __DIR__ . '/install.lock';
$configFile = $root . '/config.php';

session_name('swift_install');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function csrfOk(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

function postv(string $k, string $default = ''): string
{
    return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default;
}

// ------------------------------------------------------------------------------------------------ requirement checks

/** @return array{0:array<int,array>,1:bool} rows [label, status ok|warn|fail, note] and whether installation may proceed */
function requirementChecks(string $root): array
{
    $rows = [];
    $blocked = false;
    $add = function (string $label, string $status, string $note = '') use (&$rows, &$blocked) {
        $rows[] = [$label, $status, $note];
        if ($status === 'fail') {
            $blocked = true;
        }
    };

    $add('PHP ' . SWIFT_INSTALL_MIN_PHP . ' or newer', version_compare(PHP_VERSION, SWIFT_INSTALL_MIN_PHP, '>=') ? 'ok' : 'fail', 'This server runs PHP ' . PHP_VERSION);
    foreach (['pdo_mysql' => 'database access', 'curl' => 'talking to the game nodes', 'openssl' => 'secure connections and random keys',
              'json' => '', 'mbstring' => 'text handling'] as $ext => $why) {
        $add('PHP extension ' . $ext, extension_loaded($ext) ? 'ok' : 'fail', $why);
    }
    $add('PHP extension zip', extension_loaded('zip') ? 'ok' : 'warn', 'needed by the file manager to extract .zip files');
    $add('Session support', function_exists('session_start') ? 'ok' : 'fail');

    $add('The panel folder is writable (config.php can be created)', is_writable($root) ? 'ok' : 'warn',
        is_writable($root) ? '' : 'not writable: the installer will show you the config.php text to save by hand');
    $tmp = sys_get_temp_dir();
    $add('Temporary folder is writable', is_writable($tmp) ? 'ok' : 'warn', $tmp);

    foreach (['auth.php', 'mailer.php', 'port_helper.php', 'games.php', 'ServerManager.php', 'index.php', 'dashboard.php', 'admin/guard.php', 'assets/style.css'] as $f) {
        $add('File ' . $f, is_file($root . '/' . $f) ? 'ok' : 'warn', is_file($root . '/' . $f) ? '' : 'not found - upload the complete panel before using it');
    }
    return [$rows, !$blocked];
}

// ------------------------------------------------------------------------------------------------ database helpers

function splitHost(string $host): array
{
    $host = trim($host);
    $port = 3306;
    if (preg_match('/^(.+):(\d{1,5})$/', $host, $m) && strpos($m[1], ':') === false) {
        return [$m[1], (int)$m[2]];
    }
    return [$host === '' ? 'localhost' : $host, $port];
}

function dbConnect(string $host, string $user, string $pass, ?string $dbname): PDO
{
    [$h, $port] = splitHost($host);
    $dsn = 'mysql:host=' . $h . ';port=' . $port . ($dbname !== null ? ';dbname=' . $dbname : '') . ';charset=utf8mb4';
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 8,
    ]);
}

/** A readable reason instead of a raw driver message (which can contain the host and user name). */
function dbErrorText(PDOException $e): string
{
    $code = (int)($e->errorInfo[1] ?? 0);
    switch ($code) {
        case 1045: return 'Access denied: the database user name or password is wrong.';
        case 1049: return 'The database does not exist. Create it first, or tick "Create the database if it does not exist".';
        case 1044: return 'This database user has no permission to use that database.';
        case 2002: case 2003: case 2006: return 'Could not reach the database server. Check the host name and that MySQL/MariaDB is running.';
        case 1142: case 1143: return 'The database user lacks a needed privilege (CREATE, ALTER, INSERT, SELECT).';
    }
    return 'Database error' . ($code ? ' ' . $code : '') . ': ' . preg_replace('/\s+/', ' ', mb_substr($e->getMessage(), 0, 200));
}

/** The tables the pages do not create themselves. Pages create the rest (payments, mods, stats, backups, legal...) on first use. */
function schemaStatements(): array
{
    $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        'users' => "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            email VARCHAR(100) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'client',
            email_verified TINYINT(1) NOT NULL DEFAULT 0,
            verify_token VARCHAR(64) NULL,
            totp_secret VARCHAR(64) NULL,
            totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
            totp_last_step BIGINT NOT NULL DEFAULT 0,
            totp_fails INT NOT NULL DEFAULT 0,
            totp_locked_until DATETIME NULL,
            pending_email VARCHAR(100) NULL,
            pending_email_hash VARCHAR(64) NULL,
            pending_email_expires DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_users_username (username),
            KEY idx_users_email (email)
        )" . $t,
        'boxes' => "CREATE TABLE IF NOT EXISTS boxes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            location VARCHAR(64) NULL,
            ip_address VARCHAR(45) NOT NULL,
            extra_ips TEXT NULL,
            ssh_port INT NOT NULL DEFAULT 8085,
            ssh_user VARCHAR(50) NULL,
            ssh_password VARCHAR(255) NULL,
            assigned_ports TEXT NULL
        )" . $t,
        'servers' => "CREATE TABLE IF NOT EXISTS servers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            box_id INT NULL,
            game VARCHAR(32) NOT NULL,
            name VARCHAR(120) NOT NULL,
            ip_address VARCHAR(45) NULL,
            port INT NULL,
            max_players INT NOT NULL DEFAULT 0,
            start_map_or_world VARCHAR(120) NULL,
            ram_mb INT NOT NULL DEFAULT 2048,
            install_path VARCHAR(255) NULL,
            screen_name VARCHAR(100) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'installing',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_servers_user (user_id),
            KEY idx_servers_box (box_id)
        )" . $t,
        'orders' => "CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            game VARCHAR(32) NOT NULL,
            location VARCHAR(64) NULL,
            server_name VARCHAR(120) NOT NULL,
            slots INT DEFAULT 32,
            ram_mb INT DEFAULT 2048,
            status ENUM('Pending','Approved','Cancelled') DEFAULT 'Pending',
            server_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )" . $t,
        'tickets' => "CREATE TABLE IF NOT EXISTS tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            server_id INT NULL,
            subject VARCHAR(255) NOT NULL,
            priority ENUM('Low','Medium','High') DEFAULT 'Medium',
            status ENUM('Open','Answered','Closed') DEFAULT 'Open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )" . $t,
        'ticket_replies' => "CREATE TABLE IF NOT EXISTS ticket_replies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            user_id INT NOT NULL,
            is_admin TINYINT(1) DEFAULT 0,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )" . $t,
        'smtp_settings' => "CREATE TABLE IF NOT EXISTS smtp_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            host VARCHAR(255) NOT NULL DEFAULT '',
            port INT NOT NULL DEFAULT 587,
            encryption VARCHAR(10) NOT NULL DEFAULT 'tls',
            username VARCHAR(255) NOT NULL DEFAULT '',
            password VARCHAR(255) NOT NULL DEFAULT '',
            from_email VARCHAR(255) NOT NULL DEFAULT '',
            from_name VARCHAR(100) NOT NULL DEFAULT 'SwiftPanel'
        )" . $t,
    ];
}

function configText(string $host, string $name, string $user, string $pass): string
{
    $q = function (string $v): string { return var_export($v, true); };
    return "<?php\n"
        . "// Written by the SwiftPanel installer on " . gmdate('Y-m-d H:i') . " UTC. Keep this file private.\n"
        . "define('DB_HOST', " . $q($host) . ");\n"
        . "define('DB_NAME', " . $q($name) . ");\n"
        . "define('DB_USER', " . $q($user) . ");\n"
        . "define('DB_PASS', " . $q($pass) . ");\n"
        . "\n"
        . "// Behind a public reverse proxy (not Cloudflare) list its address so the real visitor IP is used:\n"
        . "// define('TRUSTED_PROXY_IPS', ['203.0.113.10']);\n"
        . "\n"
        . "try {\n"
        . "    [\$__h, \$__p] = (preg_match('/^(.+):(\\d{1,5})\$/', DB_HOST, \$__m) && strpos(\$__m[1], ':') === false) ? [\$__m[1], (int)\$__m[2]] : [DB_HOST, 3306];\n"
        . "    \$pdo = new PDO('mysql:host=' . \$__h . ';port=' . \$__p . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [\n"
        . "        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n"
        . "        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n"
        . "    ]);\n"
        . "} catch (PDOException \$e) {\n"
        . "    error_log('SwiftPanel database connection failed: ' . \$e->getMessage());\n"
        . "    http_response_code(500);\n"
        . "    exit('The database is not reachable right now. Please try again later.');\n"
        . "}\n";
}

function removeTree(string $dir): bool
{
    if (!is_dir($dir)) {
        return true;
    }
    $ok = true;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . '/' . $f;
        if (is_dir($p) && !is_link($p)) {
            $ok = removeTree($p) && $ok;
        } else {
            $ok = @unlink($p) && $ok;
        }
    }
    return @rmdir($dir) && $ok;
}

function detectSiteUrl(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $path = rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/install/install.php')))), '/');
    return ($https ? 'https://' : 'http://') . $host . $path;
}

// ------------------------------------------------------------------------------------------------ request handling

$state = 'form';           // form | locked | exists | done
$errors = [];
$notice = '';
$f = [
    'db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_pass' => '', 'create_db' => '',
    'admin_user' => 'admin', 'admin_email' => '', 'admin_pass' => '', 'admin_pass2' => '',
];
$result = null;

if (is_file($lockFile)) {
    $state = 'locked';
} elseif (is_file($configFile)) {
    $state = 'exists';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if (!csrfOk()) {
        $errors[] = 'The security check failed. Reload the page and try again.';
    } elseif ($action === 'delete_installer') {
        if ($state === 'locked') {
            $ok = removeTree(__DIR__);
            if ($ok) {
                header('Content-Type: text/html; charset=utf-8');
                echo '<!DOCTYPE html><meta charset="utf-8"><title>Installer removed</title><body style="font-family:sans-serif;background:#0b0f14;color:#e6edf3;padding:40px"><h2>The install folder was removed.</h2><p><a style="color:#39ff14" href="../index.php">Open the panel</a> &middot; <a style="color:#39ff14" href="../admin/">Admin login</a></p></body>';
                exit;
            }
            $errors[] = 'The folder could not be removed automatically. Delete the install/ folder by hand (FTP or the aaPanel file manager).';
        }
    } elseif ($state === 'form' && ($action === 'test' || $action === 'install')) {
        foreach ($f as $k => $_) {
            $f[$k] = in_array($k, ['db_pass', 'admin_pass', 'admin_pass2'], true) ? (string)($_POST[$k] ?? '') : postv($k, $f[$k]);
        }
        $f['create_db'] = isset($_POST['create_db']) ? '1' : '';

        [$reqRows, $reqOk] = requirementChecks($root);
        if (!$reqOk) {
            $errors[] = 'The server does not meet the requirements marked "Missing" below.';
        }
        if (!preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $f['db_name'])) {
            $errors[] = 'Database name: use letters, digits, underscore or dash (up to 64 characters).';
        }
        if ($f['db_user'] === '') {
            $errors[] = 'Enter the database user.';
        }
        if ($action === 'install') {
            if (!preg_match('/^[A-Za-z0-9_.\-]{3,32}$/', $f['admin_user'])) {
                $errors[] = 'Admin user name: 3-32 characters, letters, digits and . _ - only.';
            }
            if (!filter_var($f['admin_email'], FILTER_VALIDATE_EMAIL) || strlen($f['admin_email']) > 100) {
                $errors[] = 'Enter a valid admin e-mail address.';
            }
            if (strlen($f['admin_pass']) < 10) {
                $errors[] = 'The admin password needs at least 10 characters.';
            } elseif ($f['admin_pass'] !== $f['admin_pass2']) {
                $errors[] = 'The two admin passwords do not match.';
            }
        }

        if (!$errors) {
            try {
                if ($f['create_db'] === '1') {
                    $srv = dbConnect($f['db_host'], $f['db_user'], $f['db_pass'], null);
                    $srv->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $f['db_name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                }
                $pdo = dbConnect($f['db_host'], $f['db_user'], $f['db_pass'], $f['db_name']);
                $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();

                if ($action === 'test') {
                    $notice = 'Connection works (database server ' . $ver . ').';
                } else {
                    // Refuse to install into a database that already holds panel users.
                    $has = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                    $has->execute(['users']);
                    if ((int)$has->fetchColumn() > 0 && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                        throw new RuntimeException('This database already contains panel users. Use an empty database, or restore your old config.php instead of installing again.');
                    }

                    $created = [];
                    foreach (schemaStatements() as $table => $sql) {
                        $pdo->exec($sql);
                        $created[] = $table;
                    }

                    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username = " . $pdo->quote($f['admin_user']))->fetchColumn();
                    if ($cnt === 0) {
                        $ins = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, email_verified) VALUES (?, ?, ?, 'admin', 1)");
                        $ins->execute([$f['admin_user'], $f['admin_email'], password_hash($f['admin_pass'], PASSWORD_DEFAULT)]);
                    }
                    $pdo->exec("INSERT IGNORE INTO smtp_settings (id, enabled, from_email, from_name) VALUES (1, 0, "
                        . $pdo->quote($f['admin_email']) . ", 'SwiftPanel')");

                    // Folders the panel writes to (best effort).
                    foreach (['backups/files', 'mods/files'] as $d) {
                        $p = $root . '/' . $d;
                        if (!is_dir($p)) {
                            @mkdir($p, 0750, true);
                        }
                        if (is_dir($p) && !is_file($p . '/.htaccess')) {
                            @file_put_contents($p . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
                        }
                    }

                    $cfg = configText($f['db_host'], $f['db_name'], $f['db_user'], $f['db_pass']);
                    $written = @file_put_contents($configFile, $cfg, LOCK_EX) !== false;
                    if ($written) {
                        @chmod($configFile, 0640);
                    }
                    @file_put_contents($lockFile, 'Installed ' . gmdate('c') . "\n");

                    $result = [
                        'tables' => $created, 'config_written' => $written, 'config_text' => $cfg,
                        'admin' => $f['admin_user'], 'root' => $root, 'url' => detectSiteUrl(),
                    ];
                    $state = 'done';
                    $_SESSION['csrf'] = bin2hex(random_bytes(24));
                    $f['db_pass'] = $f['admin_pass'] = $f['admin_pass2'] = '';
                }
            } catch (PDOException $e) {
                $errors[] = dbErrorText($e);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

[$reqRows, $reqOk] = requirementChecks($root);
$siteUrl = detectSiteUrl();

// ------------------------------------------------------------------------------------------------ page

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>SwiftPanel installer</title>
<style>
    :root { --bg:#0b0f14; --card:#121821; --line:#222c3a; --txt:#e6edf3; --mut:#8b98a8; --acc:#39ff14; --bad:#ff5c5c; --warn:#ffb020; }
    * { box-sizing: border-box; }
    body { margin:0; background:var(--bg); color:var(--txt); font:15px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; }
    .wrap { max-width: 820px; margin: 0 auto; padding: 32px 18px 60px; }
    h1 { font-size: 26px; margin: 0 0 4px; } h1 b { color: var(--acc); }
    h2 { font-size: 17px; margin: 0 0 12px; }
    .sub { color: var(--mut); margin-bottom: 22px; }
    .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 20px; margin-bottom: 18px; }
    label { display:block; font-size:13px; color: var(--mut); margin: 12px 0 4px; }
    input[type=text], input[type=password], input[type=email] { width:100%; padding:10px 12px; background:#0b0f14; color:var(--txt); border:1px solid var(--line); border-radius:8px; font-size:15px; }
    input:focus { outline: 2px solid rgba(57,255,20,.45); border-color: var(--acc); }
    .grid { display:grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
    @media (max-width:640px) { .grid { grid-template-columns: 1fr; } }
    .hint { font-size:12.5px; color:var(--mut); margin-top:4px; }
    .btn { display:inline-block; background: var(--acc); color:#06210a; font-weight:700; border:0; border-radius:8px; padding:11px 20px; font-size:15px; cursor:pointer; text-decoration:none; }
    .btn.ghost { background: transparent; color: var(--txt); border:1px solid var(--line); font-weight:600; }
    .btn.red { background: transparent; color: var(--bad); border:1px solid var(--bad); }
    .row { display:flex; gap:10px; flex-wrap:wrap; margin-top:18px; align-items:center; }
    .msg { padding: 11px 14px; border-radius: 8px; margin-bottom: 14px; border:1px solid; }
    .msg.err { background: rgba(255,92,92,.1); border-color: var(--bad); color: #ffb3b3; }
    .msg.ok { background: rgba(57,255,20,.08); border-color: rgba(57,255,20,.5); color: #b8ffa8; }
    .msg.warn { background: rgba(255,176,32,.08); border-color: var(--warn); color: #ffd98a; }
    table { width:100%; border-collapse: collapse; font-size: 14px; }
    td { padding: 6px 4px; border-bottom: 1px solid var(--line); vertical-align: top; }
    td.s { width: 74px; font-weight: 700; } .ok-t { color: var(--acc); } .warn-t { color: var(--warn); } .fail-t { color: var(--bad); }
    td.n { color: var(--mut); font-size: 12.5px; }
    code, pre { background:#0b0f14; border:1px solid var(--line); border-radius:6px; padding:2px 6px; font-size:13px; }
    pre { padding: 12px; overflow:auto; white-space: pre-wrap; word-break: break-all; }
    ol li { margin-bottom: 8px; }
    .chk { display:flex; gap:8px; align-items:center; margin-top: 12px; color: var(--txt); font-size:14px; } .chk input { width:auto; }
    details summary { cursor:pointer; color: var(--mut); }
</style>
</head>
<body>
<div class="wrap">
    <h1>Swift<b>Panel</b> installer</h1>
    <div class="sub">Game server hosting panel &middot; <?php echo h($siteUrl); ?></div>

    <?php foreach ($errors as $e): ?><div class="msg err"><?php echo h($e); ?></div><?php endforeach; ?>
    <?php if ($notice): ?><div class="msg ok"><?php echo h($notice); ?></div><?php endif; ?>

<?php if ($state === 'exists'): ?>
    <div class="card">
        <h2>The panel looks already installed</h2>
        <p><code>config.php</code> exists in the panel folder, so the installer will not run - it would overwrite a working installation.</p>
        <p class="hint">To install from scratch, delete <code>config.php</code> (and <code>install/install.lock</code> if present) and reload this page. Otherwise delete the <code>install/</code> folder.</p>
        <div class="row"><a class="btn" href="../index.php">Open the panel</a></div>
    </div>

<?php elseif ($state === 'locked'): ?>
    <div class="card">
        <h2>Installation finished</h2>
        <p>The installer is locked (<code>install/install.lock</code>). For safety remove the <code>install/</code> folder now.</p>
        <form method="post" onsubmit="return confirm('Delete the install folder?');">
            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
            <input type="hidden" name="action" value="delete_installer">
            <div class="row"><button class="btn red" type="submit">Delete the install folder</button> <a class="btn ghost" href="../admin/">Admin login</a></div>
        </form>
    </div>

<?php elseif ($state === 'done' && $result): ?>
    <div class="card">
        <h2>Installed</h2>
        <div class="msg ok">Created <?php echo count($result['tables']); ?> tables and the admin account <strong><?php echo h($result['admin']); ?></strong>.</div>
        <?php if (!$result['config_written']): ?>
            <div class="msg warn"><strong>config.php could not be written</strong> (the panel folder is not writable). Create <code><?php echo h($result['root']); ?>/config.php</code> with exactly this content, then continue:</div>
            <pre><?php echo h($result['config_text']); ?></pre>
        <?php else: ?>
            <p class="hint">config.php was written (permissions 640).</p>
        <?php endif; ?>
        <h2 style="margin-top:20px">Next steps</h2>
        <ol>
            <li><strong>Remove the installer</strong> with the button below (or delete the <code>install/</code> folder).</li>
            <li>Sign in at <a style="color:var(--acc)" href="../admin/">/admin/</a>, open <strong>SMTP / Email</strong> and send a test e-mail.</li>
            <li>Open <strong>Remote Nodes</strong> and add your first game node, then run the install command it shows on that node.</li>
            <li>Set up <strong>Legal Pages</strong> (Terms of Service, Privacy Policy) and, if you sell servers, <strong>Payments</strong> and game prices.</li>
            <li>Add the cron jobs on this server (aaPanel &rarr; Cron &rarr; Shell script):
<pre>* * * * *   php <?php echo h($result['root']); ?>/stats/collect.php >/dev/null 2>&1
*/5 * * * * php <?php echo h($result['root']); ?>/sbackups/run_scheduled.php >/dev/null 2>&1
0 6 * * *   php <?php echo h($result['root']); ?>/payments/cron_reminders.php >/dev/null 2>&1</pre></li>
            <li>On nginx add <code>location ^~ /stats/ { deny all; }</code>, <code>location ^~ /sbackups/ { deny all; }</code> and <code>location ^~ /backups/files/ { deny all; }</code>.</li>
        </ol>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
            <input type="hidden" name="action" value="delete_installer">
            <div class="row"><button class="btn red" type="submit">Delete the install folder</button> <a class="btn ghost" href="../admin/">Go to admin login</a></div>
        </form>
    </div>

<?php else: ?>
    <div class="card">
        <h2>1. Server check</h2>
        <table>
        <?php foreach ($reqRows as [$label, $status, $note]): ?>
            <tr>
                <td class="s <?php echo $status; ?>-t"><?php echo $status === 'ok' ? 'OK' : ($status === 'warn' ? 'Note' : 'Missing'); ?></td>
                <td><?php echo h($label); ?><?php if ($note): ?><div class="n"><?php echo h($note); ?></div><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </table>
        <?php if (!$reqOk): ?><div class="msg err" style="margin-top:14px">Fix the items marked "Missing" (PHP settings in aaPanel &rarr; App Store &rarr; PHP &rarr; Install extensions), then reload this page.</div><?php endif; ?>
    </div>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">

        <div class="card">
            <h2>2. Database</h2>
            <div class="hint">Create an empty MySQL/MariaDB database and a user for it first (aaPanel &rarr; Database &rarr; Add), or tick the box below if the user may create databases.</div>
            <div class="grid">
                <div><label for="db_host">Host</label><input type="text" id="db_host" name="db_host" value="<?php echo h($f['db_host']); ?>" required>
                    <div class="hint"><code>localhost</code>, or <code>host:port</code></div></div>
                <div><label for="db_name">Database name</label><input type="text" id="db_name" name="db_name" value="<?php echo h($f['db_name']); ?>" required></div>
                <div><label for="db_user">Database user</label><input type="text" id="db_user" name="db_user" value="<?php echo h($f['db_user']); ?>" required></div>
                <div><label for="db_pass">Database password</label><input type="password" id="db_pass" name="db_pass" value="<?php echo h($f['db_pass']); ?>"></div>
            </div>
            <label class="chk"><input type="checkbox" name="create_db" value="1" <?php echo $f['create_db'] ? 'checked' : ''; ?>> Create the database if it does not exist</label>
            <div class="row"><button class="btn ghost" type="submit" name="action" value="test" formnovalidate>Test connection</button></div>
        </div>

        <div class="card">
            <h2>3. Administrator account</h2>
            <div class="grid">
                <div><label for="admin_user">User name</label><input type="text" id="admin_user" name="admin_user" value="<?php echo h($f['admin_user']); ?>" required></div>
                <div><label for="admin_email">E-mail</label><input type="email" id="admin_email" name="admin_email" value="<?php echo h($f['admin_email']); ?>" required></div>
                <div><label for="admin_pass">Password</label><input type="password" id="admin_pass" name="admin_pass" minlength="10" required autocomplete="new-password">
                    <div class="hint">At least 10 characters.</div></div>
                <div><label for="admin_pass2">Repeat password</label><input type="password" id="admin_pass2" name="admin_pass2" minlength="10" required autocomplete="new-password"></div>
            </div>
            <div class="row">
                <button class="btn" type="submit" name="action" value="install" <?php echo $reqOk ? '' : 'disabled'; ?>>Install SwiftPanel</button>
            </div>
            <div class="hint" style="margin-top:12px">This creates the tables, the administrator and <code>config.php</code>. Nothing existing is overwritten: the installer stops if <code>config.php</code> exists or the database already holds users.</div>
        </div>
    </form>
<?php endif; ?>
</div>
</body>
</html>
