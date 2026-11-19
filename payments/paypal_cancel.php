<?php
// paypal_cancel.php?i=INVOICEID&t=PAYTOKEN&token=PAYPALORDERID   (the client clicked "Cancel and return" at PayPal)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/payments_lib.php';

swiftPayEnsureSchema($pdo);

$invoiceId = (int)($_GET['i'] ?? 0);
if ($invoiceId <= 0 && !empty($_GET['o'])) {
    $invoiceId = swiftPayInvoiceIdForOrder($pdo, (int)$_GET['o']);
}
$invoice = swiftPayLoadInvoice($pdo, $invoiceId, (string)($_GET['t'] ?? ''));
if (!$invoice) {
    swiftPayRenderPage('Payment cancelled', '<p>The payment was cancelled.</p>', 'info');
    exit;
}

$ppOrderId = (string)($_GET['token'] ?? '');
if ($ppOrderId !== '') {
    $pdo->prepare("UPDATE payments SET status = 'Cancelled' WHERE paypal_order_id = ? AND invoice_id = ? AND status = 'Created'")
        ->execute([$ppOrderId, (int)$invoice['id']]);
}

$pay = '';
if ($invoice['status'] === 'Unpaid') {
    $pay = '<p><a class="btn btn-green" href="' . htmlspecialchars(swiftPayCheckoutPath($invoice['id'], $invoice['pay_token'])) . '">Pay now</a></p>';
}
swiftPayRenderPage(
    'Payment cancelled',
    '<p>No money was taken. Invoice ' . htmlspecialchars(swiftPayInvoiceNumber($invoice['id'])) . ' is still open; you can pay it any time from the Invoices tab in your Client Area.</p>' . $pay,
    'info'
);
