<?php
// backup_lib.php - MySQL / MariaDB backups of the panel database (admin only).
//
// A backup is a gzip-compressed .sql file in backups/files/ (blocked from the web, see .htaccess; on nginx add
// "location ^~ /backups/files/ { deny all; }"). It is made with mysqldump when the server has it, otherwise by a
// pure PHP dumper (tables, data, views). Each file is read back and checked before it is kept.

function swiftBackupDir()
{
    return __DIR__ . '/files';
}

function swiftBackupSchema($pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS backup_settings (
        id TINYINT NOT NULL PRIMARY KEY,
        auto_enabled TINYINT(1) NOT NULL DEFAULT 0,
        keep_count INT NOT NULL DEFAULT 14,
        last_auto_at DATETIME NULL,
        last_status VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO backup_settings (id) VALUES (1)");
}

function swiftBackupSettings($pdo)
{
    $r = $pdo->query("SELECT * FROM backup_settings WHERE id = 1")->fetch();
    return $r ?: ['auto_enabled' => 0, 'keep_count' => 14, 'last_auto_at' => null, 'last_status' => null];
}

function swiftBackupValidName($name)
{
    return is_string($name) && preg_match('/^(manual|auto)-\d{8}-\d{6}-[a-f0-9]{8}\.sql\.gz$/', $name) === 1;
}

/** Newest first: [['name','size','time','kind'], ...] */
function swiftBackupList()
{
    $out = [];
    foreach (glob(swiftBackupDir() . '/*.sql.gz') ?: [] as $f) {
        $n = basename($f);
        if (!swiftBackupValidName($n) || !is_file($f)) {
            continue;
        }
        $out[] = ['name' => $n, 'size' => (int)filesize($f), 'time' => (int)filemtime($f), 'kind' => strpos($n, 'auto-') === 0 ? 'auto' : 'manual'];
    }
    usort($out, function ($a, $b) {
        return $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']);
    });
    return $out;
}

function swiftBackupDelete($name)
{
    if (!swiftBackupValidName($name)) {
        return false;
    }
    $p = swiftBackupDir() . '/' . $name;
    return is_file($p) && @unlink($p);
}

/** Keeps the newest $keep AUTOMATIC backups; manual ones are never deleted here. Returns how many were removed. */
function swiftBackupPrune($keep)
{
    $keep = max(1, (int)$keep);
    $n = 0;
    $auto = array_values(array_filter(swiftBackupList(), function ($b) {
        return $b['kind'] === 'auto';
    }));
    foreach (array_slice($auto, $keep) as $b) {
        if (swiftBackupDelete($b['name'])) {
            $n++;
        }
    }
    return $n;
}

// ---------------------------------------------------------------------------
// mysqldump
// ---------------------------------------------------------------------------
function swiftBackupFindMysqldump()
{
    $cands = ['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/usr/bin/mariadb-dump', '/usr/local/mysql/bin/mysqldump',
              '/www/server/mysql/bin/mysqldump', '/opt/mysql/bin/mysqldump'];
    foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $d) {
        if ($d !== '') {
            $cands[] = rtrim($d, '/') . '/mysqldump';
        }
    }
    foreach ($cands as $c) {
        if (@is_file($c) && @is_executable($c)) {
            return $c;
        }
    }
    return null;
}

function swiftBackupCanProc()
{
    if (!function_exists('proc_open')) {
        return false;
    }
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('proc_open', $dis, true);
}

function swiftBackupHostParts($host)
{
    $host = (string)$host;
    if ($host !== '' && $host[0] === '/') {
        return ['socket' => $host];
    }
    if (preg_match('/^(.+):(\d{1,5})$/', $host, $m) && strpos($m[1], ':') === false) {
        return ['host' => $m[1], 'port' => (int)$m[2]];
    }
    return ['host' => $host !== '' ? $host : 'localhost'];
}

function swiftBackupDumpMysqldump($w, &$err)
{
    $bin = swiftBackupFindMysqldump();
    if (!$bin || !swiftBackupCanProc() || !defined('DB_NAME')) {
        $err = 'mysqldump is not available on this server';
        return false;
    }
    $cnf = tempnam(sys_get_temp_dir(), 'swb');
    $errFile = tempnam(sys_get_temp_dir(), 'swe');
    if ($cnf === false || $errFile === false) {
        $err = 'could not create a temporary file';
        return false;
    }
    chmod($cnf, 0600);
    $q = function ($v) {
        return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], (string)$v) . '"';
    };
    $parts = swiftBackupHostParts(defined('DB_HOST') ? DB_HOST : 'localhost');
    $ini = "[client]\nuser=" . $q(DB_USER) . "\npassword=" . $q(DB_PASS) . "\n";
    if (isset($parts['socket'])) {
        $ini .= "socket=" . $q($parts['socket']) . "\n";
    } else {
        $ini .= "host=" . $q($parts['host']) . "\n" . (isset($parts['port']) ? "port=" . $parts['port'] . "\n" : '');
    }
    file_put_contents($cnf, $ini);    // the password is never on the command line (visible in `ps`)
    $ok = false;
    try {
        $cmd = [$bin, '--defaults-extra-file=' . $cnf, '--single-transaction', '--quick', '--routines', '--triggers', '--events',
                '--default-character-set=utf8mb4', DB_NAME];
        $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes);
        if (!is_resource($proc)) {
            $err = 'mysqldump could not be started';
            return false;
        }
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 262144);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                $w($chunk);
            }
        }
        fclose($pipes[1]);
        $code = proc_close($proc);
        if ($code !== 0) {
            $msg = trim((string)@file_get_contents($errFile, false, null, 0, 600));
            $err = 'mysqldump failed (exit ' . $code . ')' . ($msg !== '' ? ': ' . preg_replace('/\s+/', ' ', $msg) : '');
            return false;
        }
        $ok = true;
    } finally {
        @unlink($cnf);
        @unlink($errFile);
    }
    return $ok;
}

// ---------------------------------------------------------------------------
// pure PHP dumper (tables, data, views)
// ---------------------------------------------------------------------------
function swiftBackupQ($name)
{
    return '`' . str_replace('`', '``', (string)$name) . '`';
}

function swiftBackupValue($pdo, $v)
{
    if ($v === null) {
        return 'NULL';
    }
    if (is_int($v)) {
        return (string)$v;
    }
    if (is_float($v)) {
        return is_finite($v) ? sprintf('%.17g', $v) : 'NULL';
    }
    $v = (string)$v;
    if ($v === '') {
        return "''";
    }
    if (!preg_match('//u', $v)) {
        return '0x' . bin2hex($v);       // binary data (BLOBs): hex literal, safe for any byte
    }
    return $pdo->quote($v);
}

function swiftBackupDumpPhp($pdo, $w, &$err)
{
    try {
        $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $w("-- SwiftPanel database dump (PHP)\n-- Date: " . date('Y-m-d H:i:s') . "\n-- Database: " . $db . "\n\n"
         . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        $tables = [];
        $views = [];
        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $r) {
            if (strtoupper((string)$r[1]) === 'VIEW') {
                $views[] = $r[0];
            } else {
                $tables[] = $r[0];
            }
        }
        try {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);   // rows are streamed, memory stays flat
        } catch (Throwable $e) {
        }
        foreach ($tables as $t) {
            $q = swiftBackupQ($t);
            $c = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM);
            $w("-- Table " . $t . "\nDROP TABLE IF EXISTS " . $q . ";\n" . $c[1] . ";\n\n");
            $st = $pdo->query('SELECT * FROM ' . $q);
            $batch = [];
            $len = 0;
            while (($row = $st->fetch(PDO::FETCH_NUM)) !== false) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = swiftBackupValue($pdo, $v);
                }
                $line = '(' . implode(',', $vals) . ')';
                $batch[] = $line;
                $len += strlen($line);
                if ($len >= 400000 || count($batch) >= 500) {
                    $w('INSERT INTO ' . $q . ' VALUES ' . implode(",\n", $batch) . ";\n");
                    $batch = [];
                    $len = 0;
                }
            }
            if ($batch) {
                $w('INSERT INTO ' . $q . ' VALUES ' . implode(",\n", $batch) . ";\n");
            }
            $st->closeCursor();
            $w("\n");
        }
        try {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        } catch (Throwable $e) {
        }
        foreach ($views as $v) {
            $q = swiftBackupQ($v);
            $c = $pdo->query('SHOW CREATE VIEW ' . $q)->fetch(PDO::FETCH_NUM);
            $w("-- View " . $v . "\nDROP VIEW IF EXISTS " . $q . ";\n" . $c[1] . ";\n\n");
        }
        $w("SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- SwiftPanel dump complete\n");
        return true;
    } catch (Throwable $e) {
        $err = 'PHP dump failed: ' . substr($e->getMessage(), 0, 200);
        return false;
    }
}

/** Reads the whole gzip back; true when it is intact and ends with a "finished" marker. */
function swiftBackupVerify($path)
{
    $gz = @gzopen($path, 'rb');
    if (!$gz) {
        return false;
    }
    $tail = '';
    $total = 0;
    while (!gzeof($gz)) {
        $chunk = gzread($gz, 262144);
        if ($chunk === false) {
            gzclose($gz);
            return false;
        }
        $total += strlen($chunk);
        $tail = substr($tail . $chunk, -400);
    }
    gzclose($gz);
    return $total > 100 && (strpos($tail, '-- Dump completed') !== false || strpos($tail, '-- SwiftPanel dump complete') !== false);
}

/**
 * Makes a backup. $method: 'auto' (mysqldump, then PHP), 'mysqldump' or 'php'.
 * Returns ['ok'=>true,'name','size','method','notes'=>[...]] or ['ok'=>false,'error'].
 */
function swiftBackupCreate($pdo, $kind = 'manual', $method = 'auto')
{
    $kind = $kind === 'auto' ? 'auto' : 'manual';
    $dir = swiftBackupDir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return ['ok' => false, 'error' => 'The folder backups/files/ does not exist and could not be created.'];
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'error' => 'The folder backups/files/ is not writable by the web server.'];
    }
    @set_time_limit(0);
    @ignore_user_abort(true);
    $order = $method === 'php' ? ['php'] : ($method === 'mysqldump' ? ['mysqldump'] : ['mysqldump', 'php']);
    $name = $kind . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql.gz';
    $final = $dir . '/' . $name;
    $tmp = $final . '.part';
    $notes = [];
    foreach ($order as $m) {
        $gz = @gzopen($tmp, 'wb6');
        if (!$gz) {
            return ['ok' => false, 'error' => 'Could not write into backups/files/.'];
        }
        $err = '';
        $w = function ($s) use ($gz) {
            gzwrite($gz, $s);
        };
        $ok = $m === 'mysqldump' ? swiftBackupDumpMysqldump($w, $err) : swiftBackupDumpPhp($pdo, $w, $err);
        gzclose($gz);
        if ($ok && !swiftBackupVerify($tmp)) {
            $ok = false;
            $err = 'the file failed the integrity check';
        }
        if ($ok) {
            @chmod($tmp, 0640);
            if (!@rename($tmp, $final)) {
                @unlink($tmp);
                return ['ok' => false, 'error' => 'Could not move the finished backup into place.'];
            }
            return ['ok' => true, 'name' => $name, 'size' => (int)filesize($final), 'method' => $m, 'notes' => $notes];
        }
        @unlink($tmp);
        $notes[] = ($m === 'mysqldump' ? 'mysqldump' : 'PHP dumper') . ': ' . $err;
    }
    return ['ok' => false, 'error' => 'The backup failed. ' . implode(' | ', $notes)];
}
