<?php
// payments_lib.php - PayPal payments for SwiftPanel orders.
//
// Flow (PayPal Orders API v2, redirect checkout):
//   order placed  ->  paypal_checkout.php creates a PayPal order and redirects the client to PayPal
//   client pays   ->  PayPal redirects to paypal_return.php, which captures the payment
//   safety net    ->  paypal_webhook.php receives PAYMENT.CAPTURE.* events (verified with PayPal)
// Prices are always computed on the server from the admin's price list; nothing is trusted from the browser.

// This file only defines functions; opening it directly in a browser is refused.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../games.php';
require_once __DIR__ . '/invoices_lib.php';

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

function swiftPayTableExists(PDO $pdo, $table)
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function swiftPayAddColumn(PDO $pdo, $table, $column, $definition)
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $column]);
    if ((int)$st->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

// Creates the payment tables and adds the payment columns to `orders` and `servers`. Cheap after the
// first run (a marker file); the admin Payments page forces a full check every time it is opened.
function swiftPayEnsureSchema(PDO $pdo, $force = false)
{
    $marker = rtrim(sys_get_temp_dir(), '/\\') . '/swift_pay_schema_v2_' . md5(defined('DB_NAME') ? (string)DB_NAME : 'db');
    if (!$force && is_file($marker)) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        mode VARCHAR(10) NOT NULL DEFAULT 'sandbox',
        client_id VARCHAR(255) NOT NULL DEFAULT '',
        client_secret VARCHAR(255) NOT NULL DEFAULT '',
        webhook_id VARCHAR(100) NOT NULL DEFAULT '',
        currency CHAR(3) NOT NULL DEFAULT 'EUR',
        brand_name VARCHAR(100) NOT NULL DEFAULT 'SwiftPanel',
        site_url VARCHAR(255) NOT NULL DEFAULT '',
        discount_3 DECIMAL(5,2) NOT NULL DEFAULT 0,
        discount_6 DECIMAL(5,2) NOT NULL DEFAULT 0,
        discount_12 DECIMAL(5,2) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO payment_settings (id) VALUES (1)");
    swiftPayAddColumn($pdo, 'payment_settings', 'renewal_days', "TINYINT UNSIGNED NOT NULL DEFAULT 7");
    swiftPayAddColumn($pdo, 'payment_settings', 'remind_days', "VARCHAR(30) NOT NULL DEFAULT '7,3,1'");
    swiftPayAddColumn($pdo, 'payment_settings', 'invoice_due_days', "TINYINT UNSIGNED NOT NULL DEFAULT 3");
    swiftPayAddColumn($pdo, 'payment_settings', 'cron_key', "CHAR(32) NOT NULL DEFAULT ''");
    $pdo->prepare("UPDATE payment_settings SET cron_key = ? WHERE id = 1 AND cron_key = ''")->execute([bin2hex(random_bytes(16))]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS game_prices (
        game VARCHAR(32) NOT NULL PRIMARY KEY,
        price_per_slot DECIMAL(10,4) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NULL,
        user_id INT NULL,
        provider VARCHAR(20) NOT NULL DEFAULT 'paypal',
        paypal_order_id VARCHAR(64) NULL,
        capture_id VARCHAR(64) NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        currency CHAR(3) NOT NULL DEFAULT 'EUR',
        status ENUM('Created','Pending','Completed','Failed','Cancelled','Refunded') NOT NULL DEFAULT 'Created',
        payer_email VARCHAR(190) NULL,
        note VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_paypal_order (paypal_order_id),
        KEY idx_order (order_id),
        KEY idx_capture (capture_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    swiftPayAddColumn($pdo, 'payments', 'invoice_id', "INT NULL");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_events (
        event_id VARCHAR(64) NOT NULL PRIMARY KEY,
        event_type VARCHAR(64) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        order_id INT NULL,
        server_id INT NULL,
        type ENUM('New','Renewal') NOT NULL DEFAULT 'New',
        description VARCHAR(255) NOT NULL DEFAULT '',
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        currency CHAR(3) NOT NULL DEFAULT 'EUR',
        period_months TINYINT UNSIGNED NOT NULL DEFAULT 1,
        status ENUM('Unpaid','Paid','Cancelled','Refunded') NOT NULL DEFAULT 'Unpaid',
        due_date DATE NOT NULL,
        pay_token CHAR(32) NOT NULL,
        issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        paid_at DATETIME NULL,
        KEY idx_user (user_id),
        KEY idx_order (order_id),
        KEY idx_server (server_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reminder_log (
        kind VARCHAR(16) NOT NULL,
        ref_id INT NOT NULL,
        stage VARCHAR(40) NOT NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (kind, ref_id, stage)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $haveOrders = swiftPayTableExists($pdo, 'orders');
    $haveServers = swiftPayTableExists($pdo, 'servers');
    if ($haveServers) {
        swiftPayAddColumn($pdo, 'servers', 'expires_at', "DATETIME NULL");
        swiftPayAddColumn($pdo, 'servers', 'renew_months', "TINYINT UNSIGNED NOT NULL DEFAULT 1");
    }
    if ($haveOrders) {
        swiftPayAddColumn($pdo, 'orders', 'period_months', "TINYINT UNSIGNED NOT NULL DEFAULT 1");
        swiftPayAddColumn($pdo, 'orders', 'amount', "DECIMAL(10,2) NULL");
        swiftPayAddColumn($pdo, 'orders', 'currency', "CHAR(3) NULL");
        swiftPayAddColumn($pdo, 'orders', 'payment_status', "ENUM('Free','Unpaid','Paid','Refunded') NOT NULL DEFAULT 'Free'");
        swiftPayAddColumn($pdo, 'orders', 'pay_token', "CHAR(32) NULL");
        swiftPayAddColumn($pdo, 'orders', 'paid_at', "DATETIME NULL");
        swiftPayBackfillInvoices($pdo);
    }
    if ($haveOrders && $haveServers) {
        @touch($marker);
    }
}

// ---------------------------------------------------------------------------
// Settings and prices
// ---------------------------------------------------------------------------

function swiftPaySettings(PDO $pdo, $fresh = false)
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    $defaults = [
        'enabled' => 0, 'mode' => 'sandbox', 'client_id' => '', 'client_secret' => '', 'webhook_id' => '',
        'currency' => 'EUR', 'brand_name' => 'SwiftPanel', 'site_url' => '',
        'discount_3' => 0, 'discount_6' => 0, 'discount_12' => 0,
        'renewal_days' => 7, 'remind_days' => '7,3,1', 'invoice_due_days' => 3, 'cron_key' => '',
    ];
    $row = false;
    try {
        $row = $pdo->query("SELECT * FROM payment_settings WHERE id = 1")->fetch();
    } catch (Exception $e) {
        $row = false;
    }
    $cache = array_merge($defaults, is_array($row) ? $row : []);
    return $cache;
}

// Payments are only offered when they are switched on AND the PayPal credentials are filled in.
function swiftPayReady(array $s)
{
    return !empty($s['enabled']) && $s['client_id'] !== '' && $s['client_secret'] !== '';
}

// game slug => price per slot per month
function swiftPayPrices(PDO $pdo)
{
    $out = [];
    try {
        foreach ($pdo->query("SELECT game, price_per_slot FROM game_prices")->fetchAll() as $r) {
            $out[$r['game']] = (float)$r['price_per_slot'];
        }
    } catch (Exception $e) {
        $out = [];
    }
    return $out;
}

function swiftPayPeriods()
{
    return [1, 3, 6, 12];
}

function swiftPayDiscount(array $s, $months)
{
    $months = (int)$months;
    $d = 0.0;
    if ($months === 3) {
        $d = (float)$s['discount_3'];
    } elseif ($months === 6) {
        $d = (float)$s['discount_6'];
    } elseif ($months === 12) {
        $d = (float)$s['discount_12'];
    }
    return max(0.0, min(90.0, $d));
}

// PayPal does not accept decimals in these currencies.
function swiftPayZeroDecimal($currency)
{
    return in_array(strtoupper((string)$currency), ['HUF', 'JPY', 'TWD'], true);
}

// Total in cents: price per slot x slots x months, minus the period discount.
function swiftPayCents($pricePerSlot, $slots, $months, $discountPct)
{
    $gross = (float)$pricePerSlot * (int)$slots * (int)$months;
    $disc = max(0.0, min(90.0, (float)$discountPct));
    // The tiny epsilon makes exact half-cent ties round up the same way the browser does.
    return (int)round($gross * (100.0 - $disc) + 0.0000001);
}

// "12.50" (or "13" for zero-decimal currencies) from a cent amount.
function swiftPayFormat($cents, $currency)
{
    if (swiftPayZeroDecimal($currency)) {
        return (string)(int)round($cents / 100);
    }
    return number_format($cents / 100, 2, '.', '');
}

function swiftPayQuote(PDO $pdo, $game, $slots, $months)
{
    $s = swiftPaySettings($pdo);
    $months = in_array((int)$months, swiftPayPeriods(), true) ? (int)$months : 1;
    $prices = swiftPayPrices($pdo);
    $per = isset($prices[$game]) ? (float)$prices[$game] : 0.0;
    $cents = swiftPayCents($per, (int)$slots, $months, swiftPayDiscount($s, $months));
    if (swiftPayZeroDecimal($s['currency'])) {
        $cents = (int)(round($cents / 100) * 100);
    }
    return [
        'required' => swiftPayReady($s) && $cents > 0,
        'cents' => $cents,
        'amount' => $cents / 100,
        'currency' => strtoupper((string)$s['currency']),
        'months' => $months,
    ];
}

// Data for the order-form JavaScript (the server recalculates everything on submit).
function swiftPayPricingForJs(PDO $pdo)
{
    $s = swiftPaySettings($pdo);
    $prices = swiftPayPrices($pdo);
    $per = [];
    foreach (swiftGames() as $slug => $g) {
        $per[$slug] = isset($prices[$slug]) ? (float)$prices[$slug] : 0.0;
    }
    return [
        'enabled' => swiftPayReady($s),
        'currency' => strtoupper((string)$s['currency']),
        'zero_decimal' => swiftPayZeroDecimal($s['currency']),
        'prices' => $per,
        'discounts' => [
            1 => 0.0,
            3 => swiftPayDiscount($s, 3),
            6 => swiftPayDiscount($s, 6),
            12 => swiftPayDiscount($s, 12),
        ],
    ];
}

// ---------------------------------------------------------------------------
// Orders
// ---------------------------------------------------------------------------

// Stores the price on a freshly inserted order, creates its invoice when payment is needed and returns
// ['required', 'token', 'invoice_id', 'amount', 'currency', 'months'].
function swiftPayAttachToOrder(PDO $pdo, $orderId, $game, $slots, $months)
{
    $q = swiftPayQuote($pdo, $game, $slots, $months);
    $token = bin2hex(random_bytes(16));
    $status = $q['required'] ? 'Unpaid' : 'Free';
    $pdo->prepare("UPDATE orders SET period_months = ?, amount = ?, currency = ?, payment_status = ?, pay_token = ? WHERE id = ?")
        ->execute([$q['months'], number_format($q['amount'], 2, '.', ''), $q['currency'], $status, $token, (int)$orderId]);

    $invoiceId = 0;
    if ($q['required']) {
        $o = $pdo->prepare("SELECT user_id, server_name FROM orders WHERE id = ?");
        $o->execute([(int)$orderId]);
        $row = $o->fetch();
        $s = swiftPaySettings($pdo);
        $inv = swiftPayCreateInvoice($pdo, [
            'user_id' => (int)$row['user_id'],
            'order_id' => (int)$orderId,
            'server_id' => null,
            'type' => 'New',
            'description' => 'New server: ' . swiftGameLabel($game) . ' - ' . $row['server_name'] . ', ' . (int)$slots . ' slots, ' . $q['months'] . ' month(s)',
            'cents' => $q['cents'],
            'currency' => $q['currency'],
            'period_months' => $q['months'],
            'due_days' => (int)$s['invoice_due_days'],
            'token' => $token,   // same secret as the order, so older pay links keep working
        ]);
        $invoiceId = $inv['id'];
    }
    return [
        'required' => $q['required'], 'token' => $token, 'invoice_id' => $invoiceId, 'amount' => $q['amount'],
        'currency' => $q['currency'], 'months' => $q['months'],
    ];
}

// Link that starts the PayPal payment of an invoice. Absolute from the site root, so it works from any folder.
function swiftPayCheckoutPath($invoiceId, $token)
{
    return swiftPayBasePath() . '/payments/paypal_checkout.php?invoice=' . (int)$invoiceId . '&token=' . urlencode((string)$token);
}

function swiftPayMarkOrderPaid(PDO $pdo, $orderId, &$firstTime = null)
{
    $st = $pdo->prepare("UPDATE orders SET payment_status = 'Paid', paid_at = NOW() WHERE id = ? AND payment_status <> 'Paid'");
    $st->execute([(int)$orderId]);
    $firstTime = $st->rowCount() > 0;
}

// The invoice a payment belongs to; payments from before invoices existed are found through their order.
function swiftPayPaymentInvoiceId(PDO $pdo, array $payment)
{
    if (!empty($payment['invoice_id'])) {
        return (int)$payment['invoice_id'];
    }
    if (!empty($payment['order_id'])) {
        return swiftPayInvoiceIdForOrder($pdo, (int)$payment['order_id']);
    }
    return 0;
}

function swiftPayFindPayment(PDO $pdo, $column, $value)
{
    if (!in_array($column, ['id', 'paypal_order_id', 'capture_id'], true) || (string)$value === '') {
        return null;
    }
    $st = $pdo->prepare("SELECT * FROM payments WHERE `{$column}` = ? LIMIT 1");
    $st->execute([$value]);
    $p = $st->fetch();
    return $p ? $p : null;
}

// ---------------------------------------------------------------------------
// URLs and small helpers
// ---------------------------------------------------------------------------

function swiftPayBasePath()
{
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    foreach (['/admin', '/payments'] as $sub) {
        if (substr($dir, -strlen($sub)) === $sub) {
            $dir = substr($dir, 0, -strlen($sub));
            break;
        }
    }
    return rtrim($dir, '/');
}

// Full base URL of the panel (scheme + host + folder), no trailing slash. Empty when it cannot be known
// (command line without a configured Site URL).
function swiftPaySiteUrl(array $s)
{
    $u = trim((string)($s['site_url'] ?? ''));
    if ($u !== '') {
        return rtrim($u, '/');
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        if (PHP_SAPI === 'cli') {
            return '';
        }
        $host = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
    }
    return 'https://' . $host . swiftPayBasePath();
}

function swiftPayMoney($amount, $currency)
{
    $dec = swiftPayZeroDecimal($currency) ? 0 : 2;
    return number_format((float)$amount, $dec, '.', ',') . ' ' . strtoupper((string)$currency);
}

function swiftPayCsrfToken()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['swiftpay_csrf'])) {
        $_SESSION['swiftpay_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['swiftpay_csrf'];
}

function swiftPayCsrfOk()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $t = (string)($_POST['csrf'] ?? '');
    return !empty($_SESSION['swiftpay_csrf']) && hash_equals((string)$_SESSION['swiftpay_csrf'], $t);
}

function swiftPayLog($message)
{
    error_log('[SwiftPanel payments] ' . $message);
}

// Simple result page for the PayPal pages (kind: info | success | error).
function swiftPayRenderPage($title, $bodyHtml, $kind = 'info')
{
    $cls = ($kind === 'success') ? 'alert alert-success' : (($kind === 'error') ? 'alert alert-error' : '');
    $base = htmlspecialchars(swiftPayBasePath());
    header('Content-Type: text/html; charset=utf-8');
    require_once dirname(__DIR__) . '/lang.php';
    swiftLangStart();
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . htmlspecialchars($title) . ' - SwiftPanel</title>';
    echo '<link rel="stylesheet" href="' . $base . '/assets/style.css?v=3"></head><body>';
    echo '<div id="header"><div class="logo">Swift<span>Panel</span></div></div>';
    echo '<div style="max-width:640px;margin:40px auto;padding:0 18px;">';
    echo '<div class="box"><div class="box-header">' . htmlspecialchars($title) . '</div>';
    echo '<div class="box-content">';
    if ($cls !== '') {
        echo '<div class="' . $cls . '" style="margin-bottom:14px;">' . $bodyHtml . '</div>';
    } else {
        echo '<div style="margin-bottom:14px;">' . $bodyHtml . '</div>';
    }
    echo '<a href="' . $base . '/dashboard.php" class="btn btn-green">Go to Client Area</a> ';
    echo '<a href="' . $base . '/index.php" class="btn">Home</a>';
    require_once dirname(__DIR__) . '/legal_lib.php';
    echo '</div></div></div>' . swiftFooterHtml() . '</body></html>';
}

// ---------------------------------------------------------------------------
// PayPal REST client
// ---------------------------------------------------------------------------

function swiftPayBase(array $s)
{
    return (($s['mode'] ?? 'sandbox') === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

function swiftPayErrMsg($data, $code)
{
    if (is_array($data)) {
        if (!empty($data['error_description'])) {
            return (string)$data['error_description'];
        }
        $msg = !empty($data['message']) ? (string)$data['message'] : (!empty($data['name']) ? (string)$data['name'] : '');
        if (!empty($data['details'][0])) {
            $d = $data['details'][0];
            $extra = !empty($d['description']) ? (string)$d['description'] : (!empty($d['issue']) ? (string)$d['issue'] : '');
            if ($extra !== '') {
                $msg = ($msg !== '' ? $msg . ': ' : '') . $extra;
            }
        }
        if ($msg !== '') {
            return $msg;
        }
    }
    return 'PayPal returned HTTP ' . (int)$code;
}

function swiftPayHttp($method, $url, array $headers, $body = null, $userpwd = null)
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    if ($userpwd !== null) {
        $opts[CURLOPT_USERPWD] = $userpwd;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) {
        return ['ok' => false, 'code' => 0, 'data' => null, 'error' => ($err !== '' ? $err : 'Network error')];
    }
    $data = json_decode($resp, true);
    $ok = ($code >= 200 && $code < 300);
    return ['ok' => $ok, 'code' => $code, 'data' => $data, 'error' => $ok ? '' : swiftPayErrMsg($data, $code)];
}

function swiftPayToken(array $s, &$error = null)
{
    static $tokens = [];
    $key = md5(($s['mode'] ?? '') . '|' . ($s['client_id'] ?? ''));
    if (isset($tokens[$key])) {
        return $tokens[$key];
    }
    if (($s['client_id'] ?? '') === '' || ($s['client_secret'] ?? '') === '') {
        $error = 'PayPal credentials are not set.';
        return null;
    }
    $r = swiftPayHttp(
        'POST',
        swiftPayBase($s) . '/v1/oauth2/token',
        ['Accept: application/json', 'Accept-Language: en_US', 'Content-Type: application/x-www-form-urlencoded'],
        'grant_type=client_credentials',
        $s['client_id'] . ':' . $s['client_secret']
    );
    if (!$r['ok'] || empty($r['data']['access_token'])) {
        $error = $r['error'] !== '' ? $r['error'] : 'Could not get a PayPal access token.';
        return null;
    }
    $tokens[$key] = $r['data']['access_token'];
    return $tokens[$key];
}

function swiftPayApi(array $s, $method, $path, $payload = null, array $extraHeaders = [])
{
    $err = null;
    $token = swiftPayToken($s, $err);
    if ($token === null) {
        return ['ok' => false, 'code' => 0, 'data' => null, 'error' => ($err ? $err : 'Could not get a PayPal access token.')];
    }
    $headers = array_merge(['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'], $extraHeaders);
    $body = null;
    if ($payload !== null) {
        $body = is_string($payload) ? $payload : json_encode($payload);
    }
    return swiftPayHttp($method, swiftPayBase($s) . $path, $headers, $body);
}

// "OK" or an error message; used by the admin "Test connection" button.
function swiftPayTestConnection(PDO $pdo)
{
    $s = swiftPaySettings($pdo, true);
    $err = null;
    $token = swiftPayToken($s, $err);
    if ($token === null) {
        return [false, $err ? $err : 'Connection failed.'];
    }
    return [true, 'Connected to PayPal (' . ($s['mode'] === 'live' ? 'live' : 'sandbox') . ') - credentials are valid.'];
}

// ---------------------------------------------------------------------------
// Checkout
// ---------------------------------------------------------------------------

// Creates the PayPal order for an invoice and stores a "Created" payment row.
// Returns ['id' => paypal order id, 'approve' => url] or null (see $error).
function swiftPayCreatePayPalOrder(PDO $pdo, array $invoice, &$error = null)
{
    $s = swiftPaySettings($pdo);
    if (!swiftPayReady($s)) {
        $error = 'Online payments are not available right now.';
        return null;
    }
    $currency = strtoupper((string)$invoice['currency']);
    $cents = (int)round((float)$invoice['amount'] * 100);
    if ($cents <= 0) {
        $error = 'This invoice has no amount to pay.';
        return null;
    }

    $base = swiftPaySiteUrl($s);
    $q = 'i=' . (int)$invoice['id'] . '&t=' . urlencode((string)$invoice['pay_token']);

    $payload = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => 'inv-' . (int)$invoice['id'],
            'custom_id' => (string)(int)$invoice['id'],
            'description' => substr(swiftPayInvoiceNumber($invoice['id']) . ' - ' . (string)$invoice['description'], 0, 120),
            'amount' => ['currency_code' => $currency, 'value' => swiftPayFormat($cents, $currency)],
        ]],
        'payment_source' => ['paypal' => ['experience_context' => [
            'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
            'brand_name' => substr((string)$s['brand_name'], 0, 120),
            'landing_page' => 'LOGIN',
            'shipping_preference' => 'NO_SHIPPING',
            'user_action' => 'PAY_NOW',
            'return_url' => $base . '/payments/paypal_return.php?' . $q,
            'cancel_url' => $base . '/payments/paypal_cancel.php?' . $q,
        ]]],
    ];

    $r = swiftPayApi($s, 'POST', '/v2/checkout/orders', $payload, ['PayPal-Request-Id: sp-' . bin2hex(random_bytes(12))]);
    if (!$r['ok'] || empty($r['data']['id'])) {
        $error = $r['error'] !== '' ? $r['error'] : 'PayPal could not create the order.';
        swiftPayLog('create order failed for invoice ' . (int)$invoice['id'] . ': ' . $error);
        return null;
    }

    $approve = '';
    foreach ((array)($r['data']['links'] ?? []) as $link) {
        if (isset($link['rel'], $link['href']) && in_array($link['rel'], ['payer-action', 'approve'], true)) {
            $approve = (string)$link['href'];
            break;
        }
    }
    $host = strtolower((string)parse_url($approve, PHP_URL_HOST));
    if ($approve === '' || ($host !== 'paypal.com' && substr($host, -11) !== '.paypal.com')) {
        $error = 'PayPal did not return a valid approval link.';
        return null;
    }

    $pdo->prepare("INSERT INTO payments (order_id, invoice_id, user_id, provider, paypal_order_id, amount, currency, status) VALUES (?, ?, ?, 'paypal', ?, ?, ?, 'Created')")
        ->execute([!empty($invoice['order_id']) ? (int)$invoice['order_id'] : null, (int)$invoice['id'], (int)$invoice['user_id'], (string)$r['data']['id'], number_format($cents / 100, 2, '.', ''), $currency]);

    return ['id' => (string)$r['data']['id'], 'approve' => $approve];
}

// Captures an approved PayPal order. Returns the PayPal order object or null (see $error).
function swiftPayCapturePayPalOrder(PDO $pdo, $paypalOrderId, &$error = null)
{
    $s = swiftPaySettings($pdo);
    $path = '/v2/checkout/orders/' . rawurlencode((string)$paypalOrderId);
    $r = swiftPayApi($s, 'POST', $path . '/capture', '{}', ['PayPal-Request-Id: sp-capture-' . $paypalOrderId]);
    if (!$r['ok']) {
        // Returning twice (reload) -> the order is already captured: read it instead.
        if ($r['code'] === 422 && strpos(json_encode($r['data']), 'ORDER_ALREADY_CAPTURED') !== false) {
            $g = swiftPayApi($s, 'GET', $path);
            if ($g['ok'] && is_array($g['data'])) {
                return $g['data'];
            }
        }
        $error = $r['error'] !== '' ? $r['error'] : 'PayPal could not capture the payment.';
        swiftPayLog('capture failed for ' . $paypalOrderId . ': ' . $error);
        return null;
    }
    return is_array($r['data']) ? $r['data'] : null;
}

// Verifies amount and currency of a capture against our payment row, then records it and pays the invoice.
// Returns true when the payment is now Completed.
function swiftPayCompleteFromCapture(PDO $pdo, array $payment, $captureId, $value, $currency, $payerEmail, &$firstTime = null)
{
    $firstTime = false;
    if (strtoupper((string)$currency) !== strtoupper((string)$payment['currency']) || abs((float)$value - (float)$payment['amount']) > 0.009) {
        $pdo->prepare("UPDATE payments SET status = 'Failed', capture_id = ?, note = ? WHERE id = ?")
            ->execute([(string)$captureId, 'Amount or currency mismatch: got ' . $value . ' ' . $currency, (int)$payment['id']]);
        swiftPayLog('amount mismatch on payment ' . (int)$payment['id']);
        return false;
    }
    if ($payment['status'] === 'Refunded') {
        return false;
    }
    $pdo->prepare("UPDATE payments SET status = 'Completed', capture_id = ?, payer_email = COALESCE(?, payer_email) WHERE id = ?")
        ->execute([(string)$captureId, $payerEmail !== '' ? substr((string)$payerEmail, 0, 190) : null, (int)$payment['id']]);
    $invoiceId = swiftPayPaymentInvoiceId($pdo, $payment);
    if ($invoiceId > 0) {
        swiftPayMarkInvoicePaid($pdo, $invoiceId, $firstTime);
    } elseif (!empty($payment['order_id'])) {
        swiftPayMarkOrderPaid($pdo, (int)$payment['order_id'], $firstTime);   // old order that never got an invoice
    }
    return true;
}

// Reads the capture out of a PayPal order object and applies it.
// Returns ['state' => paid|pending|failed, 'first' => bool, 'message' => string].
function swiftPayApplyOrderResult(PDO $pdo, array $payment, array $ppOrder)
{
    $cap = $ppOrder['purchase_units'][0]['payments']['captures'][0] ?? null;
    if (!is_array($cap)) {
        return ['state' => 'failed', 'first' => false, 'message' => 'PayPal did not report a payment for this order.'];
    }
    $status = strtoupper((string)($cap['status'] ?? ''));
    $captureId = (string)($cap['id'] ?? '');
    $value = (string)($cap['amount']['value'] ?? '0');
    $currency = (string)($cap['amount']['currency_code'] ?? '');
    $payerEmail = (string)($ppOrder['payer']['email_address'] ?? ($ppOrder['payment_source']['paypal']['email_address'] ?? ''));

    if ($status === 'COMPLETED') {
        $first = false;
        if (swiftPayCompleteFromCapture($pdo, $payment, $captureId, $value, $currency, $payerEmail, $first)) {
            return ['state' => 'paid', 'first' => $first, 'message' => ''];
        }
        return ['state' => 'failed', 'first' => false, 'message' => 'The paid amount did not match the order. Please contact support.'];
    }
    if ($status === 'PENDING') {
        $pdo->prepare("UPDATE payments SET status = 'Pending', capture_id = ?, payer_email = COALESCE(?, payer_email) WHERE id = ? AND status <> 'Completed'")
            ->execute([$captureId, $payerEmail !== '' ? substr($payerEmail, 0, 190) : null, (int)$payment['id']]);
        $reason = (string)($cap['status_details']['reason'] ?? '');
        return ['state' => 'pending', 'first' => false, 'message' => $reason];
    }
    $pdo->prepare("UPDATE payments SET status = 'Failed', capture_id = ?, note = ? WHERE id = ? AND status <> 'Completed'")
        ->execute([$captureId, 'Capture status ' . $status, (int)$payment['id']]);
    return ['state' => 'failed', 'first' => false, 'message' => 'PayPal reported the payment as ' . strtolower($status) . '.'];
}

// ---------------------------------------------------------------------------
// Receipt e-mail
// ---------------------------------------------------------------------------

// E-mails the receipt of a paid invoice.
function swiftPaySendReceipt(PDO $pdo, $invoiceId)
{
    $st = $pdo->prepare("SELECT i.*, u.username, u.email FROM invoices i JOIN users u ON i.user_id = u.id WHERE i.id = ?");
    $st->execute([(int)$invoiceId]);
    $i = $st->fetch();
    if (!$i) {
        return;
    }
    $next = ($i['type'] === 'Renewal')
        ? '<p>Your server has been renewed for ' . (int)$i['period_months'] . ' month(s). Thank you!</p>'
        : '<p>Your server will be set up as soon as an administrator approves the order. You will receive another e-mail then.</p>';
    swiftPayMail(
        $pdo, $i['email'], 'Payment received for invoice ' . swiftPayInvoiceNumber($i['id']), 'Payment Received',
        '<p>Hello <strong>' . htmlspecialchars($i['username']) . '</strong>,</p>'
        . '<p>We received your payment of <strong>' . htmlspecialchars(swiftPayMoney($i['amount'], $i['currency'])) . '</strong> for invoice <strong>' . htmlspecialchars(swiftPayInvoiceNumber($i['id'])) . '</strong>:</p>'
        . '<p>' . htmlspecialchars((string)$i['description']) . '</p>' . $next
    );
}

// ---------------------------------------------------------------------------
// Refunds and manual payments (admin)
// ---------------------------------------------------------------------------

// Full refund of a completed PayPal payment.
function swiftPayRefundPayment(PDO $pdo, $paymentId, &$error = null)
{
    $p = swiftPayFindPayment($pdo, 'id', (int)$paymentId);
    if (!$p || $p['status'] !== 'Completed' || $p['provider'] !== 'paypal' || (string)$p['capture_id'] === '') {
        $error = 'This payment cannot be refunded.';
        return false;
    }
    $s = swiftPaySettings($pdo, true);
    $r = swiftPayApi(
        $s,
        'POST',
        '/v2/payments/captures/' . rawurlencode((string)$p['capture_id']) . '/refund',
        ['note_to_payer' => 'Refund for ' . (!empty($p['invoice_id']) ? swiftPayInvoiceNumber($p['invoice_id']) : 'order #' . (int)$p['order_id'])],
        ['PayPal-Request-Id: sp-refund-' . (int)$p['id'] . '-' . $p['capture_id']]
    );
    if (!$r['ok']) {
        $error = $r['error'] !== '' ? $r['error'] : 'PayPal refused the refund.';
        return false;
    }
    swiftPayApplyRefund($pdo, $p, 'Refunded by admin');
    return true;
}

// Records a refund / reversal on the payment, its invoice and its order.
function swiftPayApplyRefund(PDO $pdo, array $payment, $note)
{
    $pdo->prepare("UPDATE payments SET status = 'Refunded', note = ? WHERE id = ?")->execute([(string)$note, (int)$payment['id']]);
    $invoiceId = swiftPayPaymentInvoiceId($pdo, $payment);
    if ($invoiceId > 0) {
        $pdo->prepare("UPDATE invoices SET status = 'Refunded' WHERE id = ?")->execute([$invoiceId]);
    }
    if (!empty($payment['order_id'])) {
        $pdo->prepare("UPDATE orders SET payment_status = 'Refunded' WHERE id = ?")->execute([(int)$payment['order_id']]);
    }
}

// Records an offline payment (bank transfer, cash...) for an unpaid invoice.
function swiftPayManualPaid(PDO $pdo, $invoiceId, $note, &$error = null)
{
    $inv = swiftPayGetInvoice($pdo, (int)$invoiceId);
    if (!$inv || $inv['status'] !== 'Unpaid') {
        $error = 'Invoice not found or not unpaid.';
        return false;
    }
    $pdo->prepare("INSERT INTO payments (order_id, invoice_id, user_id, provider, amount, currency, status, note) VALUES (?, ?, ?, 'manual', ?, ?, 'Completed', ?)")
        ->execute([!empty($inv['order_id']) ? (int)$inv['order_id'] : null, (int)$inv['id'], (int)$inv['user_id'], number_format((float)$inv['amount'], 2, '.', ''), (string)$inv['currency'], substr((string)$note, 0, 250)]);
    $first = false;
    swiftPayMarkInvoicePaid($pdo, (int)$inv['id'], $first);
    return true;
}

// ---------------------------------------------------------------------------
// Webhooks
// ---------------------------------------------------------------------------

// Asks PayPal to verify the signature of a webhook call. $raw is the untouched request body.
function swiftPayVerifyWebhook(array $s, array $h, $raw)
{
    $body = '{"auth_algo":' . json_encode((string)$h['auth_algo'])
        . ',"cert_url":' . json_encode((string)$h['cert_url'])
        . ',"transmission_id":' . json_encode((string)$h['transmission_id'])
        . ',"transmission_sig":' . json_encode((string)$h['transmission_sig'])
        . ',"transmission_time":' . json_encode((string)$h['transmission_time'])
        . ',"webhook_id":' . json_encode((string)$s['webhook_id'])
        . ',"webhook_event":' . $raw . '}';
    $r = swiftPayApi($s, 'POST', '/v1/notifications/verify-webhook-signature', $body);
    return $r['ok'] && is_array($r['data']) && (($r['data']['verification_status'] ?? '') === 'SUCCESS');
}

// The capture id behind a refund event (the refund resource links back to its capture).
function swiftPayCaptureIdFromLinks(array $res)
{
    foreach ((array)($res['links'] ?? []) as $link) {
        if (($link['rel'] ?? '') === 'up' && !empty($link['href'])) {
            return basename((string)parse_url((string)$link['href'], PHP_URL_PATH));
        }
    }
    return '';
}

// Applies one verified PayPal event. Returns a short status string.
function swiftPayHandleWebhookEvent(PDO $pdo, array $event)
{
    $id = (string)($event['id'] ?? '');
    $type = (string)($event['event_type'] ?? '');
    $res = is_array($event['resource'] ?? null) ? $event['resource'] : [];

    if ($id !== '') {
        $ins = $pdo->prepare("INSERT IGNORE INTO payment_events (event_id, event_type) VALUES (?, ?)");
        $ins->execute([substr($id, 0, 64), substr($type, 0, 64)]);
        if ($ins->rowCount() === 0) {
            return 'duplicate';
        }
    }
    if (strpos($type, 'PAYMENT.CAPTURE.') !== 0) {
        return 'ignored';
    }

    $captureId = ($type === 'PAYMENT.CAPTURE.REFUNDED') ? swiftPayCaptureIdFromLinks($res) : (string)($res['id'] ?? '');
    $ppOrderId = (string)($res['supplementary_data']['related_ids']['order_id'] ?? '');
    $payment = null;
    if ($ppOrderId !== '') {
        $payment = swiftPayFindPayment($pdo, 'paypal_order_id', $ppOrderId);
    }
    if (!$payment && $captureId !== '') {
        $payment = swiftPayFindPayment($pdo, 'capture_id', $captureId);
    }
    if (!$payment) {
        return 'unknown payment';
    }

    if ($type === 'PAYMENT.CAPTURE.COMPLETED') {
        $first = false;
        $done = swiftPayCompleteFromCapture(
            $pdo, $payment, $captureId,
            (string)($res['amount']['value'] ?? '0'), (string)($res['amount']['currency_code'] ?? ''), '', $first
        );
        $receiptInvoice = swiftPayPaymentInvoiceId($pdo, $payment);
        if ($done && $first && $receiptInvoice > 0) {
            swiftPaySendReceipt($pdo, $receiptInvoice);
        }
        return $done ? 'completed' : 'rejected';
    }
    if ($type === 'PAYMENT.CAPTURE.DENIED') {
        $pdo->prepare("UPDATE payments SET status = 'Failed', note = 'Denied by PayPal' WHERE id = ? AND status <> 'Completed'")->execute([(int)$payment['id']]);
        return 'denied';
    }
    if ($type === 'PAYMENT.CAPTURE.REFUNDED' || $type === 'PAYMENT.CAPTURE.REVERSED') {
        swiftPayApplyRefund($pdo, $payment, $type === 'PAYMENT.CAPTURE.REVERSED' ? 'Reversed by PayPal' : 'Refunded in PayPal');
        return 'refunded';
    }
    return 'ignored';
}
