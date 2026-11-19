<?php
// cron_reminders.php - run once a day (more often is fine; nothing is ever sent twice).
//
//   * creates renewal invoices for servers that expire soon
//   * cancels unpaid invoices of cancelled orders
//   * e-mails the reminders: server expiring/expired, order invoice due/overdue
//
// Command line:  php /path/to/payments/cron_reminders.php
// By URL:        https://YOUR-PANEL/payments/cron_reminders.php?key=CRON_KEY   (key: Admin > Payments)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/payments_lib.php';

$cli = (PHP_SAPI === 'cli');
swiftPayEnsureSchema($pdo);
$s = swiftPaySettings($pdo, true);

if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = (string)($_GET['key'] ?? '');
    if ((string)$s['cron_key'] === '' || !hash_equals((string)$s['cron_key'], $key)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}

$r = swiftPayRunReminders($pdo);
echo "SwiftPanel reminders\n";
echo "renewal invoices created : " . $r['renewal_invoices'] . "\n";
echo "invoices cancelled       : " . $r['cancelled'] . "\n";
echo "server reminder e-mails  : " . $r['server_mails'] . "\n";
echo "invoice reminder e-mails : " . $r['invoice_mails'] . "\n";
echo "failed e-mails           : " . $r['failed'] . "\n";
if ($r['no_mailer'] > 0) {
    echo "NOT SENT (e-mail is not set up): " . $r['no_mailer'] . " - configure SMTP in Admin > SMTP / Email\n";
}
if (swiftPaySiteUrl($s) === '') {
    echo "Note: set the Site URL in Admin > Payments so the e-mails contain a Pay now link.\n";
}
