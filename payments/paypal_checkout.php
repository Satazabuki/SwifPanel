<?php
// paypal_checkout.php?invoice=ID&token=PAYTOKEN
// Creates the PayPal order for an unpaid invoice and sends the client to PayPal.
// (Links from before invoices existed used ?order=ID&token=...; they still work.)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/payments_lib.php';

swiftPayEnsureSchema($pdo);

$invoiceId = (int)($_GET['invoice'] ?? 0);
if ($invoiceId <= 0 && !empty($_GET['order'])) {
    $invoiceId = swiftPayInvoiceIdForOrder($pdo, (int)$_GET['order']);
}
$invoice = swiftPayLoadInvoice($pdo, $invoiceId, (string)($_GET['token'] ?? ''));
if (!$invoice) {
    swiftPayRenderPage('Payment', '<p>This payment link is not valid.</p>', 'error');
    exit;
}

$num = htmlspecialchars(swiftPayInvoiceNumber($invoice['id']));
if ($invoice['status'] === 'Paid') {
    swiftPayRenderPage('Payment', '<p>Invoice ' . $num . ' has already been paid. Thank you!</p>', 'success');
    exit;
}
if ($invoice['status'] !== 'Unpaid') {
    swiftPayRenderPage('Payment', '<p>Invoice ' . $num . ' is ' . htmlspecialchars(strtolower($invoice['status'])) . ' and can no longer be paid.</p>', 'error');
    exit;
}
if (!empty($invoice['order_id'])) {
    $os = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
    $os->execute([(int)$invoice['order_id']]);
    if ($os->fetchColumn() === 'Cancelled') {
        swiftPayRenderPage('Payment', '<p>The order for invoice ' . $num . ' was cancelled, so it cannot be paid.</p>', 'error');
        exit;
    }
}

$error = null;
$pp = swiftPayCreatePayPalOrder($pdo, $invoice, $error);
if (!$pp) {
    $retry = '<p><a class="btn btn-green" href="' . htmlspecialchars(swiftPayCheckoutPath($invoice['id'], $invoice['pay_token'])) . '">Try again</a></p>';
    swiftPayRenderPage('Payment', '<p>We could not start the PayPal payment: ' . htmlspecialchars((string)$error) . '</p>' . $retry, 'error');
    exit;
}

header('Location: ' . $pp['approve']);
exit;
