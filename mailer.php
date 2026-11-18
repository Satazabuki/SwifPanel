<?php
function initMailSchema(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS smtp_settings (
        id INT PRIMARY KEY DEFAULT 1,
        enabled TINYINT(1) DEFAULT 0,
        host VARCHAR(255) DEFAULT '',
        port INT DEFAULT 587,
        encryption ENUM('tls','ssl','none') DEFAULT 'tls',
        username VARCHAR(255) DEFAULT '',
        password VARCHAR(255) DEFAULT '',
        from_email VARCHAR(255) DEFAULT '',
        from_name VARCHAR(120) DEFAULT 'SwiftPanel'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO smtp_settings (id, enabled) VALUES (1, 0)");

    $uCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('email_verified', $uCols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) DEFAULT 1");
    }
    if (!in_array('verify_token', $uCols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN verify_token VARCHAR(64) NULL");
    }
}

function sendSmtpMail(PDO $pdo, string $to, string $subject, string $htmlBody, ?string &$errOut = null): bool {
    initMailSchema($pdo);
    $cfg = $pdo->query("SELECT * FROM smtp_settings WHERE id = 1")->fetch();
    if (!$cfg || empty($cfg['enabled']) || empty($cfg['host']) || empty($to)) {
        $errOut = 'SMTP is disabled or missing host/recipient.';
        return false;
    }

    $host = trim($cfg['host']);
    $port = (int)($cfg['port'] ?: 587);
    $enc  = $cfg['encryption'] ?? 'tls';
    $user = trim($cfg['username'] ?? '');
    $pass = (string)($cfg['password'] ?? '');
    $from = trim($cfg['from_email'] ?: $user);
    $name = trim($cfg['from_name'] ?: 'SwiftPanel');

    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $errOut = "Connection failed: {$errstr} ({$errno})";
        return false;
    }
    stream_set_timeout($fp, 10);

    $read = function() use ($fp) {
        $out = '';
        while ($line = fgets($fp, 515)) {
            $out .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $out;
    };
    $cmd = function(string $c, array $okCodes) use ($fp, $read, &$errOut) {
        fwrite($fp, $c . "\r\n");
        $r = $read();
        $code = (int)substr($r, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            $errOut = "SMTP error on '{$c}': " . trim($r);
            return false;
        }
        return true;
    };

    $greet = $read();
    if ((int)substr($greet, 0, 3) !== 220) {
        $errOut = "Invalid greeting: " . trim($greet);
        fclose($fp);
        return false;
    }

    $helo = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!$cmd("EHLO {$helo}", [250])) { fclose($fp); return false; }

    if ($enc === 'tls') {
        if (!$cmd("STARTTLS", [220])) { fclose($fp); return false; }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            $errOut = "STARTTLS handshake failed.";
            fclose($fp);
            return false;
        }
        if (!$cmd("EHLO {$helo}", [250])) { fclose($fp); return false; }
    }

    if ($user !== '') {
        if (!$cmd("AUTH LOGIN", [334])) { fclose($fp); return false; }
        if (!$cmd(base64_encode($user), [334])) { fclose($fp); return false; }
        if (!$cmd(base64_encode($pass), [235])) { fclose($fp); return false; }
    }

    if (!$cmd("MAIL FROM:<{$from}>", [250])) { fclose($fp); return false; }
    if (!$cmd("RCPT TO:<{$to}>", [250, 251])) { fclose($fp); return false; }
    if (!$cmd("DATA", [354])) { fclose($fp); return false; }

    $encSubj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encName = '=?UTF-8?B?' . base64_encode($name) . '?=';
    $headers = [
        "Date: " . date('r'),
        "From: {$encName} <{$from}>",
        "To: <{$to}>",
        "Subject: {$encSubj}",
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "Content-Transfer-Encoding: base64"
    ];
    $payload = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody));
    fwrite($fp, $payload . "\r\n.\r\n");
    $dataResp = $read();
    fwrite($fp, "QUIT\r\n");
    fclose($fp);

    if ((int)substr($dataResp, 0, 3) !== 250) {
        $errOut = "Message rejected: " . trim($dataResp);
        return false;
    }
    return true;
}

function wrapPanelMail(string $title, string $innerHtml): string {
    return "<div style='font-family:Arial,sans-serif;background:#0d1117;color:#e2e8f0;padding:24px;'>"
         . "<div style='max-width:560px;margin:0 auto;background:#161b22;border:1px solid #30363d;border-radius:8px;padding:24px;'>"
         . "<h2 style='margin-top:0;color:#2ecc71;'>{$title}</h2>{$innerHtml}"
         . "<hr style='border:0;border-top:1px solid #30363d;margin:20px 0;'>"
         . "<p style='font-size:12px;color:#94a3b8;margin:0;'>SwiftPanel Game Server Hosting</p></div></div>";
}
