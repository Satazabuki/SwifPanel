<?php
require_once 'guard.php';
require_once '../config.php';
require_once '../payments/payments_lib.php';

swiftPayEnsureSchema($pdo, true);   // the admin page always re-checks the payment tables

$notice = '';
$error = '';
$currencies = ['EUR', 'USD', 'GBP', 'CHF', 'AUD', 'CAD', 'CZK', 'DKK', 'HUF', 'JPY', 'NOK', 'NZD', 'PLN', 'SEK', 'SGD'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!swiftPayCsrfOk()) {
        $error = 'Security check failed. Please reload the page and try again.';
    } elseif (isset($_POST['save_settings'])) {
        $mode = (($_POST['mode'] ?? '') === 'live') ? 'live' : 'sandbox';
        $cur = strtoupper(trim((string)($_POST['currency'] ?? 'EUR')));
        if (!in_array($cur, $currencies, true)) {
            $cur = 'EUR';
        }
        $siteUrl = trim((string)($_POST['site_url'] ?? ''));
        if ($siteUrl !== '' && !preg_match('#^https?://[A-Za-z0-9.\-:\[\]]+(/[A-Za-z0-9._~/\-]*)?$#', $siteUrl)) {
            $error = 'Site URL must look like https://panel.example.com';
        } else {
            $disc = [];
            foreach (['discount_3', 'discount_6', 'discount_12'] as $k) {
                $disc[$k] = max(0.0, min(90.0, (float)str_replace(',', '.', (string)($_POST[$k] ?? '0'))));
            }
            $secret = trim((string)($_POST['client_secret'] ?? ''));
            $renewalDays = max(1, min(60, (int)($_POST['renewal_days'] ?? 7)));
            $dueDays = max(0, min(60, (int)($_POST['invoice_due_days'] ?? 3)));
            $rd = [];
            foreach (explode(',', (string)($_POST['remind_days'] ?? '')) as $part) {
                $n = (int)trim($part);
                if ($n >= 1 && $n <= 60) {
                    $rd[$n] = $n;
                }
            }
            if (!$rd) {
                $rd = [7 => 7, 3 => 3, 1 => 1];
            }
            krsort($rd);
            $sql = "UPDATE payment_settings SET enabled = ?, mode = ?, client_id = ?, webhook_id = ?, currency = ?, brand_name = ?, site_url = ?, discount_3 = ?, discount_6 = ?, discount_12 = ?, renewal_days = ?, remind_days = ?, invoice_due_days = ?";
            $params = [
                !empty($_POST['enabled']) ? 1 : 0, $mode, trim((string)($_POST['client_id'] ?? '')), trim((string)($_POST['webhook_id'] ?? '')),
                $cur, substr(trim((string)($_POST['brand_name'] ?? '')) ?: 'SwiftPanel', 0, 100), $siteUrl,
                $disc['discount_3'], $disc['discount_6'], $disc['discount_12'],
                $renewalDays, implode(',', $rd), $dueDays,
            ];
            if ($secret !== '') {
                $sql .= ", client_secret = ?";
                $params[] = $secret;
            }
            $sql .= " WHERE id = 1";
            $pdo->prepare($sql)->execute($params);
            $notice = 'Payment settings saved.';
        }
    } elseif (isset($_POST['test_connection'])) {
        list($okConn, $msgConn) = swiftPayTestConnection($pdo);
        if ($okConn) {
            $notice = $msgConn;
        } else {
            $error = 'PayPal connection failed: ' . $msgConn;
        }
    } elseif (isset($_POST['save_prices'])) {
        $posted = (isset($_POST['price']) && is_array($_POST['price'])) ? $_POST['price'] : [];
        $up = $pdo->prepare("INSERT INTO game_prices (game, price_per_slot) VALUES (?, ?) ON DUPLICATE KEY UPDATE price_per_slot = VALUES(price_per_slot)");
        foreach (swiftGames() as $slug => $g) {
            $raw = isset($posted[$slug]) ? (string)$posted[$slug] : '0';
            $v = max(0.0, min(9999.0, (float)str_replace(',', '.', $raw)));
            $up->execute([$slug, number_format($v, 4, '.', '')]);
        }
        $notice = 'Prices saved. A price of 0 means the game is free (no payment is asked).';
    } elseif (isset($_POST['refund_payment'])) {
        $err = null;
        if (swiftPayRefundPayment($pdo, (int)($_POST['payment_id'] ?? 0), $err)) {
            $notice = 'Payment refunded in PayPal. If the server was already created, stop or delete it manually.';
        } else {
            $error = 'Refund failed: ' . $err;
        }
    } elseif (isset($_POST['manual_paid'])) {
        $err = null;
        $note = trim((string)($_POST['note'] ?? '')) ?: 'Manual payment (marked by admin)';
        if (swiftPayManualPaid($pdo, (int)($_POST['invoice_id'] ?? 0), $note, $err)) {
            $notice = 'Invoice marked as paid. A new order can now be approved on the Orders page; a renewal has extended the server.';
        } else {
            $error = 'Could not mark as paid: ' . $err;
        }
    } elseif (isset($_POST['cancel_invoice'])) {
        $notice = swiftPayCancelInvoice($pdo, (int)($_POST['invoice_id'] ?? 0)) ? 'Invoice cancelled.' : 'Only unpaid invoices can be cancelled.';
    } elseif (isset($_POST['regen_cron_key'])) {
        $pdo->prepare("UPDATE payment_settings SET cron_key = ? WHERE id = 1")->execute([bin2hex(random_bytes(16))]);
        $notice = 'New cron key created. Update the URL in your cron job if you use one.';
    }
}

$s = swiftPaySettings($pdo, true);
$prices = swiftPayPrices($pdo);
$cur = strtoupper((string)$s['currency']);
$stats = $pdo->query("SELECT COALESCE(SUM(CASE WHEN status = 'Completed' THEN amount ELSE 0 END), 0) AS revenue, COALESCE(SUM(CASE WHEN status = 'Refunded' THEN amount ELSE 0 END), 0) AS refunded, COALESCE(SUM(status = 'Completed'), 0) AS paid_count FROM payments")->fetch();
$unpaidCount = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'Unpaid'")->fetchColumn();
$overdueCount = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'Unpaid' AND due_date < CURDATE()")->fetchColumn();
$payments = $pdo->query("SELECT p.*, i.description AS inv_description, u.username FROM payments p LEFT JOIN invoices i ON p.invoice_id = i.id LEFT JOIN users u ON p.user_id = u.id ORDER BY p.id DESC LIMIT 100")->fetchAll();
$invoicesAll = $pdo->query("SELECT i.*, u.username, DATEDIFF(i.due_date, CURDATE()) AS days_left FROM invoices i JOIN users u ON i.user_id = u.id ORDER BY i.id DESC LIMIT 200")->fetchAll();
$cronUrl = swiftPaySiteUrl($s) . '/payments/cron_reminders.php?key=' . $s['cron_key'];
$cronFile = realpath(__DIR__ . '/../payments/cron_reminders.php');
$webhookUrl = swiftPaySiteUrl($s) . '/payments/paypal_webhook.php';
$csrf = swiftPayCsrfToken();
$ready = swiftPayReady($s);
?>
<?php require_once dirname(__DIR__) . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payments - Admin Panel</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
    <style>
        .container { max-width: 1180px; margin: 24px auto; padding: 0 18px; }
        .form-input { width: 100%; box-sizing: border-box; background: #0d1117; color: #fff; border: 1px solid #30363d; border-radius: 4px; padding: 9px 11px; font-size: 13px; }
        label { display: block; font-size: 12px; color: #94a3b8; margin-bottom: 4px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .stat-card { background: #141820; border: 1px solid #262c3a; border-radius: 6px; padding: 14px 16px; }
        .stat-card-label { font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; display: block; }
        .stat-card-value { font-size: 22px; font-weight: 700; color: #f8fafc; display: block; margin-top: 4px; }
        .p-table { width: 100%; border-collapse: collapse; }
        .p-table th, .p-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #262c3a; font-size: 13px; vertical-align: top; }
        .p-table th { color: #94a3b8; background: #141820; }
        .pbadge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .pb-Completed { background: rgba(46,204,113,0.2); color: #2ecc71; }
        .pb-Pending, .pb-Created { background: rgba(243,156,18,0.2); color: #f39c12; }
        .pb-Failed, .pb-Cancelled { background: rgba(231,76,60,0.2); color: #e74c3c; }
        .pb-Refunded { background: rgba(149,165,166,0.2); color: #95a5a6; }
        .pb-Paid { background: rgba(46,204,113,0.2); color: #2ecc71; }
        .pb-Unpaid { background: rgba(243,156,18,0.2); color: #f39c12; }
        .pb-Overdue { background: rgba(231,76,60,0.2); color: #e74c3c; }
        code.small { font-size: 11px; color: #94a3b8; word-break: break-all; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#e74c3c;">ADMIN CONTROL</small></div>
        <div class="user-info">Admin: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> | <a href="login.php?logout=1">Logout</a></div>
    </div>
    <?php $adminNavActive = 'payments'; include __DIR__ . '/nav.php'; ?>
    <div class="container">
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (!empty($s['enabled']) && !$ready): ?>
            <div class="alert alert-error">Payments are switched on but the PayPal Client ID or Secret is missing, so clients are NOT asked to pay.</div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card" style="border-left:3px solid #2ecc71;">
                <span class="stat-card-label">Revenue (PayPal + manual)</span>
                <span class="stat-card-value"><?php echo htmlspecialchars(swiftPayMoney($stats['revenue'], $cur)); ?></span>
            </div>
            <div class="stat-card" style="border-left:3px solid #3498db;">
                <span class="stat-card-label">Paid orders</span>
                <span class="stat-card-value"><?php echo (int)$stats['paid_count']; ?></span>
            </div>
            <div class="stat-card" style="border-left:3px solid #f39c12;">
                <span class="stat-card-label">Unpaid invoices</span>
                <span class="stat-card-value"><?php echo $unpaidCount; ?></span>
                <small style="color:<?php echo $overdueCount > 0 ? '#e74c3c' : '#64748b'; ?>;"><?php echo $overdueCount; ?> overdue</small>
            </div>
            <div class="stat-card" style="border-left:3px solid #95a5a6;">
                <span class="stat-card-label">Refunded</span>
                <span class="stat-card-value"><?php echo htmlspecialchars(swiftPayMoney($stats['refunded'], $cur)); ?></span>
            </div>
        </div>

        <div class="box" style="margin-bottom:20px;">
            <div class="box-header">PayPal Settings</div>
            <div class="box-content">
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                    <div style="margin-bottom:14px;">
                        <label style="display:inline-flex;align-items:center;gap:8px;color:#fff;cursor:pointer;">
                            <input type="checkbox" name="enabled" value="1" <?php echo !empty($s['enabled']) ? 'checked' : ''; ?>>
                            Ask clients to pay with PayPal when they order a game that has a price
                        </label>
                    </div>
                    <div class="form-grid">
                        <div>
                            <label>Mode</label>
                            <select name="mode" class="form-input">
                                <option value="sandbox" <?php echo $s['mode'] !== 'live' ? 'selected' : ''; ?>>Sandbox (test payments)</option>
                                <option value="live" <?php echo $s['mode'] === 'live' ? 'selected' : ''; ?>>Live (real money)</option>
                            </select>
                        </div>
                        <div>
                            <label>Currency</label>
                            <select name="currency" class="form-input">
                                <?php foreach ($currencies as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $cur === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Client ID</label>
                            <input type="text" name="client_id" class="form-input" value="<?php echo htmlspecialchars($s['client_id']); ?>" autocomplete="off">
                        </div>
                        <div>
                            <label>Client Secret <?php echo $s['client_secret'] !== '' ? '(saved - leave blank to keep)' : ''; ?></label>
                            <input type="password" name="client_secret" class="form-input" placeholder="<?php echo $s['client_secret'] !== '' ? '••••••••••••' : 'Paste the secret'; ?>" autocomplete="new-password">
                        </div>
                        <div>
                            <label>Webhook ID (from your PayPal app)</label>
                            <input type="text" name="webhook_id" class="form-input" value="<?php echo htmlspecialchars($s['webhook_id']); ?>" placeholder="e.g. 8PT597110X687430L" autocomplete="off">
                        </div>
                        <div>
                            <label>Business name shown on PayPal</label>
                            <input type="text" name="brand_name" class="form-input" value="<?php echo htmlspecialchars($s['brand_name']); ?>" maxlength="100">
                        </div>
                        <div>
                            <label>Site URL (optional, e.g. https://gp.example.com)</label>
                            <input type="text" name="site_url" class="form-input" value="<?php echo htmlspecialchars($s['site_url']); ?>" placeholder="auto-detected when empty">
                        </div>
                    </div>
                    <div class="form-grid" style="margin-top:14px;">
                        <div>
                            <label>Discount for 3 months (%)</label>
                            <input type="number" step="0.01" min="0" max="90" name="discount_3" class="form-input" value="<?php echo htmlspecialchars((string)(float)$s['discount_3']); ?>">
                        </div>
                        <div>
                            <label>Discount for 6 months (%)</label>
                            <input type="number" step="0.01" min="0" max="90" name="discount_6" class="form-input" value="<?php echo htmlspecialchars((string)(float)$s['discount_6']); ?>">
                        </div>
                        <div>
                            <label>Discount for 12 months (%)</label>
                            <input type="number" step="0.01" min="0" max="90" name="discount_12" class="form-input" value="<?php echo htmlspecialchars((string)(float)$s['discount_12']); ?>">
                        </div>
                    </div>
                    <div class="form-grid" style="margin-top:14px;">
                        <div>
                            <label>Create the renewal invoice this many days before a server expires</label>
                            <input type="number" min="1" max="60" name="renewal_days" class="form-input" value="<?php echo (int)$s['renewal_days']; ?>">
                        </div>
                        <div>
                            <label>Reminder e-mails (days before expiry / due date, comma separated)</label>
                            <input type="text" name="remind_days" class="form-input" value="<?php echo htmlspecialchars((string)$s['remind_days']); ?>" placeholder="7,3,1">
                        </div>
                        <div>
                            <label>Days a new order invoice stays open before it is due</label>
                            <input type="number" min="0" max="60" name="invoice_due_days" class="form-input" value="<?php echo (int)$s['invoice_due_days']; ?>">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;">
                        <button type="submit" name="save_settings" value="1" class="btn btn-green">Save Settings</button>
                        <button type="submit" name="test_connection" value="1" class="btn btn-blue">Test PayPal Connection</button>
                    </div>
                </form>
                <div style="margin-top:16px;padding:12px 14px;background:#0d1117;border:1px solid #262c3a;border-radius:6px;font-size:12px;color:#94a3b8;line-height:1.6;">
                    <strong style="color:#fff;">PayPal setup</strong><br>
                    1. At <code>developer.paypal.com</code> create a REST app (Sandbox first, Live later) and paste its Client ID and Secret above.<br>
                    2. In that app add a webhook with this URL and paste the Webhook ID it gives you:<br>
                    <code class="small"><?php echo htmlspecialchars($webhookUrl); ?></code><br>
                    3. Subscribe it to: <code>PAYMENT.CAPTURE.COMPLETED</code>, <code>PAYMENT.CAPTURE.DENIED</code>, <code>PAYMENT.CAPTURE.REFUNDED</code>, <code>PAYMENT.CAPTURE.REVERSED</code>.<br>
                    4. Switch Mode to Live only after a successful sandbox test payment. Use a PayPal sandbox buyer account to test.
                </div>
            </div>
        </div>

        <div class="box" style="margin-bottom:20px;">
            <div class="box-header">Game Prices (<?php echo htmlspecialchars($cur); ?> per slot per month)</div>
            <div class="box-content" style="padding:0;">
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                    <table class="p-table">
                        <thead><tr><th>Game</th><th style="width:220px;">Price per slot / month</th><th>Example (default slots, 1 month)</th></tr></thead>
                        <tbody>
                            <?php foreach (swiftGames() as $slug => $g):
                                $per = isset($prices[$slug]) ? (float)$prices[$slug] : 0.0;
                                $exCents = swiftPayCents($per, (int)$g['slots_default'], 1, 0);
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($g['label']); ?></strong></td>
                                    <td><input type="number" step="0.0001" min="0" name="price[<?php echo htmlspecialchars($slug); ?>]" class="form-input" value="<?php echo htmlspecialchars(rtrim(rtrim(number_format($per, 4, '.', ''), '0'), '.') ?: '0'); ?>"></td>
                                    <td style="color:#94a3b8;"><?php echo $exCents > 0 ? htmlspecialchars((int)$g['slots_default'] . ' slots = ' . swiftPayFormat($exCents, $cur) . ' ' . $cur) : 'Free (no payment)'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="padding:14px;">
                        <button type="submit" name="save_prices" value="1" class="btn btn-green">Save Prices</button>
                        <span style="font-size:12px;color:#94a3b8;margin-left:10px;">Total = price x slots x months, minus the period discount. 0 = free game.</span>
                    </div>
                </form>
            </div>
        </div>

        <div class="box" style="margin-bottom:20px;">
            <div class="box-header">Reminders &amp; renewal invoices (cron job)</div>
            <div class="box-content" style="font-size:13px;line-height:1.7;color:#94a3b8;">
                A daily job creates renewal invoices before servers expire and e-mails the reminders (server expiring / expired, order invoice due / overdue). Nothing is ever sent twice.
                The on-screen reminders in the Client Area work without it.<br>
                <strong style="color:#fff;">Command line</strong> (e.g. aaPanel: Cron &rarr; Shell script, daily):<br>
                <code class="small">php <?php echo htmlspecialchars($cronFile ? $cronFile : '/path/to/payments/cron_reminders.php'); ?></code><br>
                <strong style="color:#fff;">Or by URL</strong> (aaPanel: Cron &rarr; Visit URL, daily):<br>
                <code class="small"><?php echo htmlspecialchars($cronUrl); ?></code>
                <?php if (trim((string)$s['site_url']) === ''): ?>
                    <div style="margin-top:8px;color:#f39c12;">Set the Site URL above (e.g. https://gp.example.com) so the e-mails contain a working Pay now link.</div>
                <?php endif; ?>
                <form method="POST" style="margin-top:10px;" onsubmit="return confirm('Create a new cron key? The old URL stops working.');">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                    <button type="submit" name="regen_cron_key" value="1" class="btn" style="padding:4px 10px;font-size:12px;">Create new cron key</button>
                </form>
            </div>
        </div>

        <div class="box" style="margin-bottom:20px;">
            <div class="box-header">Invoices (latest 200)</div>
            <div class="box-content" style="padding:0;">
                <?php if (empty($invoicesAll)): ?>
                    <p style="padding:20px;margin:0;color:#94a3b8;">No invoices yet.</p>
                <?php else: ?>
                    <table class="p-table">
                        <thead><tr><th>Invoice</th><th>Client</th><th>Description</th><th>Amount</th><th>Due</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($invoicesAll as $iv):
                                $ivState = $iv['status'];
                                if ($ivState === 'Unpaid' && (int)$iv['days_left'] < 0) {
                                    $ivState = 'Overdue';
                                }
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars(swiftPayInvoiceNumber($iv['id'])); ?></strong><br><small style="color:#94a3b8;"><?php echo $iv['type'] === 'Renewal' ? 'Renewal' : 'New server'; ?></small></td>
                                    <td><?php echo htmlspecialchars($iv['username']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$iv['description']); ?></td>
                                    <td><strong><?php echo htmlspecialchars(swiftPayMoney($iv['amount'], $iv['currency'])); ?></strong><br><small style="color:#94a3b8;"><?php echo (int)$iv['period_months']; ?> mo</small></td>
                                    <td><?php echo htmlspecialchars((string)$iv['due_date']); ?></td>
                                    <td><span class="pbadge pb-<?php echo htmlspecialchars($ivState); ?>"><?php echo strtoupper(htmlspecialchars($ivState)); ?></span></td>
                                    <td>
                                        <?php if ($iv['status'] === 'Unpaid'): ?>
                                            <form method="POST" style="display:flex;gap:6px;margin:0;flex-wrap:wrap;">
                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                                <input type="hidden" name="invoice_id" value="<?php echo (int)$iv['id']; ?>">
                                                <input type="text" name="note" class="form-input" placeholder="Note (e.g. bank transfer)" style="max-width:170px;padding:5px 8px;">
                                                <button type="submit" name="manual_paid" value="1" class="btn btn-green" style="padding:4px 10px;font-size:12px;" onclick="return confirm('Mark this invoice as paid without a PayPal payment?');">Mark paid</button>
                                                <button type="submit" name="cancel_invoice" value="1" class="btn btn-red" style="padding:4px 10px;font-size:12px;" onclick="return confirm('Cancel this invoice?');">Cancel</button>
                                            </form>
                                        <?php else: ?>&mdash;<?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="box">
            <div class="box-header">Transactions (latest 100)</div>
            <div class="box-content" style="padding:0;">
                <?php if (empty($payments)): ?>
                    <p style="padding:20px;margin:0;color:#94a3b8;">No payments yet.</p>
                <?php else: ?>
                    <table class="p-table">
                        <thead><tr><th>#</th><th>Date</th><th>Invoice</th><th>Client</th><th>Amount</th><th>Status</th><th>PayPal</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><?php echo (int)$p['id']; ?></td>
                                    <td><?php echo htmlspecialchars($p['created_at']); ?></td>
                                    <td>
                                        <?php if (!empty($p['invoice_id'])): ?>
                                            <strong><?php echo htmlspecialchars(swiftPayInvoiceNumber($p['invoice_id'])); ?></strong><br>
                                            <small style="color:#94a3b8;"><?php echo htmlspecialchars((string)$p['inv_description']); ?></small>
                                        <?php elseif (!empty($p['order_id'])): ?>
                                            <a href="orders.php" style="color:#3498db;">Order #<?php echo (int)$p['order_id']; ?></a>
                                        <?php else: ?>&mdash;<?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$p['username']); ?><br><small style="color:#94a3b8;"><?php echo htmlspecialchars((string)$p['payer_email']); ?></small></td>
                                    <td><strong><?php echo htmlspecialchars(swiftPayMoney($p['amount'], $p['currency'])); ?></strong><br><small style="color:#94a3b8;"><?php echo htmlspecialchars($p['provider']); ?></small></td>
                                    <td><span class="pbadge pb-<?php echo htmlspecialchars($p['status']); ?>"><?php echo strtoupper(htmlspecialchars($p['status'])); ?></span><?php if (!empty($p['note'])): ?><br><small style="color:#94a3b8;"><?php echo htmlspecialchars($p['note']); ?></small><?php endif; ?></td>
                                    <td>
                                        <?php if (!empty($p['paypal_order_id'])): ?><code class="small">Order <?php echo htmlspecialchars($p['paypal_order_id']); ?></code><br><?php endif; ?>
                                        <?php if (!empty($p['capture_id'])): ?><code class="small">Capture <?php echo htmlspecialchars($p['capture_id']); ?></code><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($p['status'] === 'Completed' && $p['provider'] === 'paypal' && !empty($p['capture_id'])): ?>
                                            <form method="POST" style="margin:0;" onsubmit="return confirm('Refund the full amount to the client in PayPal?');">
                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                                <input type="hidden" name="payment_id" value="<?php echo (int)$p['id']; ?>">
                                                <button type="submit" name="refund_payment" value="1" class="btn btn-red" style="padding:4px 10px;font-size:12px;">Refund</button>
                                            </form>
                                        <?php else: ?>&mdash;<?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
