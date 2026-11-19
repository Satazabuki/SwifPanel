<?php
// payments/invoices_lib.php - invoices, renewals, expiry dates and reminders for SwiftPanel.
// Loaded by payments_lib.php. Every date calculation is done by the database (NOW(), CURDATE()),
// so the PHP and MySQL time zones can never disagree.

// This file only defines functions; opening it directly in a browser is refused.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}

// ---------------------------------------------------------------------------
// Invoices
// ---------------------------------------------------------------------------

function swiftPayInvoiceNumber($id)
{
    return sprintf('INV-%06d', (int)$id);
}

// $d: user_id, order_id|null, server_id|null, type (New|Renewal), description, cents, currency,
//     period_months, due_days (days from today), token (optional)
// Returns ['id' => int, 'token' => string].
function swiftPayCreateInvoice(PDO $pdo, array $d)
{
    $token = !empty($d['token']) ? (string)$d['token'] : bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO invoices (user_id, order_id, server_id, type, description, amount, currency, period_months, status, due_date, pay_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Unpaid', DATE_ADD(CURDATE(), INTERVAL ? DAY), ?)")
        ->execute([
            (int)$d['user_id'],
            !empty($d['order_id']) ? (int)$d['order_id'] : null,
            !empty($d['server_id']) ? (int)$d['server_id'] : null,
            (($d['type'] ?? 'New') === 'Renewal') ? 'Renewal' : 'New',
            substr((string)$d['description'], 0, 250),
            number_format(((int)$d['cents']) / 100, 2, '.', ''),
            strtoupper((string)$d['currency']),
            max(1, (int)$d['period_months']),
            max(0, (int)$d['due_days']),
            $token,
        ]);
    return ['id' => (int)$pdo->lastInsertId(), 'token' => $token];
}

function swiftPayGetInvoice(PDO $pdo, $id)
{
    $st = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $st->execute([(int)$id]);
    $r = $st->fetch();
    return $r ? $r : null;
}

// Loads an invoice for a payment page; the secret pay_token proves the visitor owns the link.
function swiftPayLoadInvoice(PDO $pdo, $id, $token)
{
    $token = (string)$token;
    if ((int)$id <= 0 || $token === '') {
        return null;
    }
    $st = $pdo->prepare("SELECT i.*, u.username, u.email FROM invoices i JOIN users u ON i.user_id = u.id WHERE i.id = ?");
    $st->execute([(int)$id]);
    $i = $st->fetch();
    if (!$i || empty($i['pay_token']) || !hash_equals((string)$i['pay_token'], $token)) {
        return null;
    }
    return $i;
}

// Latest invoice of an order (old pay links used the order id).
function swiftPayInvoiceIdForOrder(PDO $pdo, $orderId)
{
    $st = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$orderId]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : 0;
}

function swiftPayCancelInvoice(PDO $pdo, $invoiceId)
{
    $st = $pdo->prepare("UPDATE invoices SET status = 'Cancelled' WHERE id = ? AND status = 'Unpaid'");
    $st->execute([(int)$invoiceId]);
    return $st->rowCount() > 0;
}

function swiftPayCancelOrderInvoices(PDO $pdo, $orderId)
{
    $st = $pdo->prepare("UPDATE invoices SET status = 'Cancelled' WHERE order_id = ? AND status = 'Unpaid'");
    $st->execute([(int)$orderId]);
}

// Marks an invoice paid and applies what the payment buys:
//   order invoice   -> the order becomes Paid (the admin can now approve it)
//   renewal invoice -> the server's expiry moves forward by the invoice period
// Only the first call changes anything ($firstTime tells the caller whether it was the first).
function swiftPayMarkInvoicePaid(PDO $pdo, $invoiceId, &$firstTime = null)
{
    $st = $pdo->prepare("UPDATE invoices SET status = 'Paid', paid_at = NOW() WHERE id = ? AND status IN ('Unpaid', 'Cancelled')");
    $st->execute([(int)$invoiceId]);
    $firstTime = $st->rowCount() > 0;
    if (!$firstTime) {
        return;
    }
    $inv = swiftPayGetInvoice($pdo, $invoiceId);
    if (!$inv) {
        return;
    }
    if (!empty($inv['order_id'])) {
        $pdo->prepare("UPDATE orders SET payment_status = 'Paid', paid_at = NOW() WHERE id = ? AND payment_status <> 'Paid'")
            ->execute([(int)$inv['order_id']]);
    }
    if ($inv['type'] === 'Renewal' && !empty($inv['server_id'])) {
        // Extend from the current expiry when it is still in the future, otherwise from now.
        $pdo->prepare("UPDATE servers SET expires_at = DATE_ADD(GREATEST(COALESCE(expires_at, NOW()), NOW()), INTERVAL ? MONTH), renew_months = ? WHERE id = ?")
            ->execute([(int)$inv['period_months'], (int)$inv['period_months'], (int)$inv['server_id']]);
    }
}

// ---------------------------------------------------------------------------
// Renewals
// ---------------------------------------------------------------------------

function swiftPayOpenRenewalInvoice(PDO $pdo, $serverId)
{
    $st = $pdo->prepare("SELECT * FROM invoices WHERE server_id = ? AND type = 'Renewal' AND status = 'Unpaid' ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$serverId]);
    $r = $st->fetch();
    return $r ? $r : null;
}

// Creates (or reuses) the unpaid renewal invoice of a server.
// $userId (optional) makes sure the server belongs to that client.
// Returns ['id', 'token'] or null (see $error).
function swiftPayCreateRenewalInvoice(PDO $pdo, $serverId, $months, $userId = null, &$error = null)
{
    $st = $pdo->prepare("SELECT s.*, GREATEST(0, COALESCE(DATEDIFF(DATE(s.expires_at), CURDATE()), 0)) AS days_to_expiry FROM servers s WHERE s.id = ?");
    $st->execute([(int)$serverId]);
    $srv = $st->fetch();
    if (!$srv || ($userId !== null && (int)$srv['user_id'] !== (int)$userId)) {
        $error = 'Server not found.';
        return null;
    }
    $months = in_array((int)$months, swiftPayPeriods(), true) ? (int)$months : 1;
    $q = swiftPayQuote($pdo, $srv['game'], (int)$srv['max_players'], $months);
    if (!$q['required']) {
        $error = 'This server has no price, so there is nothing to renew.';
        return null;
    }

    $open = swiftPayOpenRenewalInvoice($pdo, (int)$srv['id']);
    if ($open) {
        if ((int)$open['period_months'] === $months && (int)round((float)$open['amount'] * 100) === (int)$q['cents']) {
            return ['id' => (int)$open['id'], 'token' => (string)$open['pay_token']];
        }
        swiftPayCancelInvoice($pdo, (int)$open['id']);   // other period or changed price: replace it
    }

    return swiftPayCreateInvoice($pdo, [
        'user_id' => (int)$srv['user_id'],
        'order_id' => null,
        'server_id' => (int)$srv['id'],
        'type' => 'Renewal',
        'description' => 'Renewal: ' . swiftGameLabel($srv['game']) . ' - ' . $srv['name'] . ', ' . (int)$srv['max_players'] . ' slots, ' . $months . ' month(s)',
        'cents' => $q['cents'],
        'currency' => $q['currency'],
        'period_months' => $months,
        'due_days' => (int)$srv['days_to_expiry'],
    ]);
}

// Creates renewal invoices for servers that expire within "renewal_days" calendar days and have none yet.
// Works for one client (dashboard visit) or everybody (cron). Returns how many were created.
function swiftPayEnsureRenewalInvoices(PDO $pdo, $userId = null)
{
    $s = swiftPaySettings($pdo);
    if (!swiftPayReady($s)) {
        return 0;
    }
    $days = max(1, (int)$s['renewal_days']);
    $sql = "SELECT s.id, s.renew_months FROM servers s WHERE s.expires_at IS NOT NULL AND DATE(s.expires_at) <= DATE_ADD(CURDATE(), INTERVAL ? DAY)"
        . " AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.server_id = s.id AND i.type = 'Renewal' AND i.status = 'Unpaid')";
    $params = [$days];
    if ($userId !== null) {
        $sql .= " AND s.user_id = ?";
        $params[] = (int)$userId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $created = 0;
    foreach ($st->fetchAll() as $row) {
        $err = null;
        if (swiftPayCreateRenewalInvoice($pdo, (int)$row['id'], (int)$row['renew_months'], null, $err)) {
            $created++;
        }
    }
    return $created;
}

// ---------------------------------------------------------------------------
// Reminder schedule (pure functions: no database, easy to test)
// ---------------------------------------------------------------------------

// "7,3,1" -> [1, 3, 7]
function swiftPayThresholds(array $s)
{
    $out = [];
    foreach (explode(',', (string)($s['remind_days'] ?? '')) as $p) {
        $n = (int)trim($p);
        if ($n >= 1 && $n <= 60) {
            $out[$n] = $n;
        }
    }
    if (!$out) {
        $out = [1 => 1, 3 => 3, 7 => 7];
    }
    $out = array_values($out);
    sort($out);
    return $out;
}

// Stage of an unpaid invoice: u<N> = due within N days, due = due today, o<K> = K+ days overdue.
function swiftPayInvoiceStage($days, array $th)
{
    $days = (int)$days;
    if ($days < 0) {
        $over = -$days;
        $st = '';
        foreach ([1, 4, 8] as $k) {
            if ($over >= $k) {
                $st = 'o' . $k;
            }
        }
        return $st;
    }
    if ($days === 0) {
        return 'due';
    }
    foreach ($th as $n) {
        if ($days <= $n) {
            return 'u' . $n;
        }
    }
    return '';
}

// Stage of a server expiry. $daysLeft = calendar days until the expiry date (0 = today), $expired = the
// expiry moment has passed. u<N> = expires within N days, e0/e3/e7 = expired 0+/3+/7+ days ago.
function swiftPayServerStage($daysLeft, $expired, array $th)
{
    $days = (int)$daysLeft;
    if ($expired) {
        $over = max(0, -$days);
        if ($over >= 7) {
            return 'e7';
        }
        if ($over >= 3) {
            return 'e3';
        }
        return 'e0';
    }
    $days = max(0, $days);
    foreach ($th as $n) {
        if ($days <= $n) {
            return 'u' . $n;
        }
    }
    return '';
}

function swiftPayInvoiceStageOrder(array $th)
{
    $o = [];
    foreach (array_reverse($th) as $n) {
        $o[] = 'u' . $n;
    }
    return array_merge($o, ['due', 'o1', 'o4', 'o8']);
}

function swiftPayServerStageOrder(array $th)
{
    $o = [];
    foreach (array_reverse($th) as $n) {
        $o[] = 'u' . $n;
    }
    return array_merge($o, ['e0', 'e3', 'e7']);
}

// Every stage up to and including $stage: after a reminder is sent they are all marked as sent,
// so a late first run never sends a pile of old reminders one after another.
function swiftPayStagesUpTo($stage, array $order)
{
    $out = [];
    foreach ($order as $st) {
        $out[] = $st;
        if ($st === $stage) {
            return $out;
        }
    }
    return [$stage];
}

function swiftPayReminderSent(PDO $pdo, $kind, $refId, $stage)
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM reminder_log WHERE kind = ? AND ref_id = ? AND stage = ?");
    $st->execute([$kind, (int)$refId, $stage]);
    return (int)$st->fetchColumn() > 0;
}

function swiftPayReminderMark(PDO $pdo, $kind, $refId, array $stages)
{
    $st = $pdo->prepare("INSERT IGNORE INTO reminder_log (kind, ref_id, stage) VALUES (?, ?, ?)");
    foreach ($stages as $stage) {
        $st->execute([$kind, (int)$refId, substr((string)$stage, 0, 40)]);
    }
}

// ---------------------------------------------------------------------------
// Alerts on the client dashboard (always live, no cron needed)
// ---------------------------------------------------------------------------

// Returns [['level' => 'danger'|'warning', 'text' => string], ...], most urgent first.
function swiftPayClientAlerts(PDO $pdo, $userId)
{
    $s = swiftPaySettings($pdo);
    $th = swiftPayThresholds($s);
    $window = (int)end($th);
    $danger = [];
    $warning = [];

    $st = $pdo->prepare("SELECT i.id, i.description, i.amount, i.currency, i.due_date, DATEDIFF(i.due_date, CURDATE()) AS days_left FROM invoices i WHERE i.user_id = ? AND i.status = 'Unpaid' ORDER BY i.due_date ASC");
    $st->execute([(int)$userId]);
    foreach ($st->fetchAll() as $i) {
        $days = (int)$i['days_left'];
        $label = swiftPayInvoiceNumber($i['id']) . ' (' . swiftPayMoney($i['amount'], $i['currency']) . ')';
        if ($days < 0) {
            $danger[] = ['level' => 'danger', 'text' => 'Invoice ' . $label . ' is overdue since ' . $i['due_date'] . '.'];
        } elseif ($days <= $window) {
            $when = ($days === 0) ? 'today' : 'in ' . $days . ' day' . ($days === 1 ? '' : 's') . ' (' . $i['due_date'] . ')';
            $warning[] = ['level' => 'warning', 'text' => 'Invoice ' . $label . ' is due ' . $when . '.'];
        }
    }

    $st = $pdo->prepare("SELECT s.id, s.name, s.expires_at, TIMESTAMPDIFF(SECOND, NOW(), s.expires_at) AS secs_left, DATEDIFF(DATE(s.expires_at), CURDATE()) AS days_left FROM servers s WHERE s.user_id = ? AND s.expires_at IS NOT NULL ORDER BY s.expires_at ASC");
    $st->execute([(int)$userId]);
    foreach ($st->fetchAll() as $v) {
        $secs = (int)$v['secs_left'];
        $date = substr((string)$v['expires_at'], 0, 10);
        if ($secs < 0) {
            $danger[] = ['level' => 'danger', 'text' => 'Server "' . $v['name'] . '" expired on ' . $date . '. Renew it to keep it.'];
        } else {
            $days = max(0, (int)$v['days_left']);
            if ($days <= $window) {
                $when = ($days === 0) ? 'today' : 'in ' . $days . ' day' . ($days === 1 ? '' : 's');
                $warning[] = ['level' => 'warning', 'text' => 'Server "' . $v['name'] . '" expires ' . $when . ' (' . $date . ').'];
            }
        }
    }
    return array_merge($danger, $warning);
}

// ---------------------------------------------------------------------------
// Reminder e-mails (run by payments/cron_reminders.php, once a day is enough)
// ---------------------------------------------------------------------------

// true = sent, false = failed, null = no mailer available.
function swiftPayMail(PDO $pdo, $to, $subject, $title, $bodyHtml)
{
    if (!function_exists('sendSmtpMail') && is_file(__DIR__ . '/../mailer.php')) {
        require_once __DIR__ . '/../mailer.php';
    }
    if (!function_exists('sendSmtpMail') || !function_exists('wrapPanelMail')) {
        return null;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $err = null;
    return (bool)sendSmtpMail($pdo, $to, $subject, wrapPanelMail($title, $bodyHtml), $err);
}

function swiftPayPayLinkHtml($base, array $invoice)
{
    if ($base === '' || empty($invoice['id']) || empty($invoice['pay_token'])) {
        return '';
    }
    $url = $base . '/payments/paypal_checkout.php?invoice=' . (int)$invoice['id'] . '&token=' . urlencode((string)$invoice['pay_token']);
    return '<p><a href="' . htmlspecialchars($url) . '" style="display:inline-block;padding:10px 18px;background:#2ecc71;color:#fff;text-decoration:none;border-radius:4px;font-weight:bold;">Pay now</a></p>'
        . '<p style="font-size:12px;color:#777;">Or open the Invoices tab in your Client Area.</p>';
}

// Creates renewal invoices, cancels invoices of cancelled orders and sends the reminders.
// Returns a summary array.
function swiftPayRunReminders(PDO $pdo)
{
    $res = ['renewal_invoices' => 0, 'cancelled' => 0, 'server_mails' => 0, 'invoice_mails' => 0, 'no_mailer' => 0, 'failed' => 0];
    $s = swiftPaySettings($pdo, true);
    $th = swiftPayThresholds($s);
    $base = swiftPaySiteUrl($s);

    $res['renewal_invoices'] = swiftPayEnsureRenewalInvoices($pdo);
    $res['cancelled'] = (int)$pdo->exec("UPDATE invoices i JOIN orders o ON i.order_id = o.id SET i.status = 'Cancelled' WHERE i.status = 'Unpaid' AND o.status = 'Cancelled'");

    // --- servers that expire (or expired) ---
    $servers = $pdo->query("SELECT s.id, s.user_id, s.name, s.game, s.max_players, s.renew_months, s.expires_at, TIMESTAMPDIFF(SECOND, NOW(), s.expires_at) AS secs_left, DATEDIFF(DATE(s.expires_at), CURDATE()) AS days_left, u.username, u.email FROM servers s JOIN users u ON s.user_id = u.id WHERE s.expires_at IS NOT NULL")->fetchAll();
    foreach ($servers as $srv) {
        $stage = swiftPayServerStage((int)$srv['days_left'], (int)$srv['secs_left'] < 0, $th);
        if ($stage === '') {
            continue;
        }
        $date = substr((string)$srv['expires_at'], 0, 10);
        if (swiftPayReminderSent($pdo, 'server', (int)$srv['id'], $stage . '@' . $date)) {
            continue;
        }
        $expired = ((int)$srv['secs_left'] < 0);
        $inv = swiftPayOpenRenewalInvoice($pdo, (int)$srv['id']);
        if (!$inv && swiftPayReady($s)) {
            $e = null;
            $made = swiftPayCreateRenewalInvoice($pdo, (int)$srv['id'], (int)$srv['renew_months'], null, $e);
            if ($made) {
                $inv = swiftPayGetInvoice($pdo, (int)$made['id']);
            }
        }
        $body = '<p>Hello <strong>' . htmlspecialchars($srv['username']) . '</strong>,</p>';
        if ($expired) {
            $subject = 'Your server "' . $srv['name'] . '" has expired';
            $body .= '<p>Your server <strong>' . htmlspecialchars($srv['name']) . '</strong> (' . htmlspecialchars(swiftGameLabel($srv['game'])) . ') expired on <strong>' . htmlspecialchars($date) . '</strong>. Please renew it to keep it.</p>';
        } else {
            $days = max(0, (int)$srv['days_left']);
            $subject = 'Your server "' . $srv['name'] . '" expires ' . ($days === 0 ? 'today' : 'in ' . $days . ' day' . ($days === 1 ? '' : 's'));
            $body .= '<p>Your server <strong>' . htmlspecialchars($srv['name']) . '</strong> (' . htmlspecialchars(swiftGameLabel($srv['game'])) . ') expires on <strong>' . htmlspecialchars($date) . '</strong>.</p>';
        }
        if ($inv) {
            $body .= '<p>Your renewal invoice <strong>' . htmlspecialchars(swiftPayInvoiceNumber($inv['id'])) . '</strong> for <strong>' . htmlspecialchars(swiftPayMoney($inv['amount'], $inv['currency'])) . '</strong> (' . (int)$inv['period_months'] . ' month(s)) is ready.</p>' . swiftPayPayLinkHtml($base, $inv);
        } else {
            $body .= '<p>Please contact support to renew this server.</p>';
        }
        try {
            $sent = swiftPayMail($pdo, $srv['email'], $subject, $expired ? 'Server Expired' : 'Server Expiring Soon', $body);
        } catch (Exception $ex) {
            $sent = false;
        }
        if ($sent === true) {
            $stages = [];
            foreach (swiftPayStagesUpTo($stage, swiftPayServerStageOrder($th)) as $st) {
                $stages[] = $st . '@' . $date;
            }
            swiftPayReminderMark($pdo, 'server', (int)$srv['id'], $stages);
            $res['server_mails']++;
        } elseif ($sent === null) {
            $res['no_mailer']++;
        } else {
            $res['failed']++;
        }
    }

    // --- unpaid order invoices (renewals are covered by the server reminders above) ---
    $invoices = $pdo->query("SELECT i.*, DATEDIFF(i.due_date, CURDATE()) AS days_left, u.username, u.email FROM invoices i JOIN users u ON i.user_id = u.id LEFT JOIN orders o ON i.order_id = o.id LEFT JOIN servers sv ON i.server_id = sv.id WHERE i.status = 'Unpaid' AND (i.type = 'New' OR sv.id IS NULL) AND (o.id IS NULL OR o.status = 'Pending')")->fetchAll();
    foreach ($invoices as $inv) {
        $stage = swiftPayInvoiceStage((int)$inv['days_left'], $th);
        if ($stage === '' || swiftPayReminderSent($pdo, 'invoice', (int)$inv['id'], $stage)) {
            continue;
        }
        $num = swiftPayInvoiceNumber($inv['id']);
        $days = (int)$inv['days_left'];
        if ($days < 0) {
            $subject = 'Invoice ' . $num . ' is overdue';
            $line = 'is <strong>overdue</strong> since ' . htmlspecialchars((string)$inv['due_date']);
        } elseif ($days === 0) {
            $subject = 'Invoice ' . $num . ' is due today';
            $line = 'is <strong>due today</strong>';
        } else {
            $subject = 'Invoice ' . $num . ' is due in ' . $days . ' day' . ($days === 1 ? '' : 's');
            $line = 'is due on <strong>' . htmlspecialchars((string)$inv['due_date']) . '</strong>';
        }
        $body = '<p>Hello <strong>' . htmlspecialchars($inv['username']) . '</strong>,</p>'
            . '<p>Invoice <strong>' . htmlspecialchars($num) . '</strong> for <strong>' . htmlspecialchars(swiftPayMoney($inv['amount'], $inv['currency'])) . '</strong> ' . $line . '.</p>'
            . '<p>' . htmlspecialchars((string)$inv['description']) . '</p>' . swiftPayPayLinkHtml($base, $inv);
        try {
            $sent = swiftPayMail($pdo, $inv['email'], $subject, 'Invoice Reminder', $body);
        } catch (Exception $ex) {
            $sent = false;
        }
        if ($sent === true) {
            swiftPayReminderMark($pdo, 'invoice', (int)$inv['id'], swiftPayStagesUpTo($stage, swiftPayInvoiceStageOrder($th)));
            $res['invoice_mails']++;
        } elseif ($sent === null) {
            $res['no_mailer']++;
        } else {
            $res['failed']++;
        }
    }
    return $res;
}

// ---------------------------------------------------------------------------
// One-time migration: orders from before invoices existed get an invoice
// ---------------------------------------------------------------------------

function swiftPayBackfillInvoices(PDO $pdo)
{
    $rows = $pdo->query("SELECT o.* FROM orders o WHERE o.payment_status IN ('Unpaid', 'Paid', 'Refunded') AND o.amount IS NOT NULL AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.order_id = o.id)")->fetchAll();
    $ins = $pdo->prepare("INSERT INTO invoices (user_id, order_id, server_id, type, description, amount, currency, period_months, status, due_date, pay_token, paid_at) VALUES (?, ?, NULL, 'New', ?, ?, ?, ?, ?, DATE(?), ?, ?)");
    $link = $pdo->prepare("UPDATE payments SET invoice_id = ? WHERE order_id = ? AND invoice_id IS NULL");
    foreach ($rows as $o) {
        $status = $o['payment_status'];
        if ($status === 'Unpaid' && $o['status'] === 'Cancelled') {
            $status = 'Cancelled';
        }
        $desc = 'New server: ' . swiftGameLabel($o['game']) . ' - ' . $o['server_name'] . ', ' . (int)$o['slots'] . ' slots, ' . (int)$o['period_months'] . ' month(s)';
        $token = !empty($o['pay_token']) ? (string)$o['pay_token'] : bin2hex(random_bytes(16));
        $ins->execute([
            (int)$o['user_id'], (int)$o['id'], substr($desc, 0, 250), number_format((float)$o['amount'], 2, '.', ''),
            (string)($o['currency'] ?: 'EUR'), max(1, (int)$o['period_months']), $status, (string)$o['created_at'], $token,
            ($status === 'Paid' || $status === 'Refunded') ? ($o['paid_at'] ?: $o['created_at']) : null,
        ]);
        $link->execute([(int)$pdo->lastInsertId(), (int)$o['id']]);
    }
}
