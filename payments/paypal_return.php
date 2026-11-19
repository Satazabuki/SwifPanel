<?php
// paypal_return.php?i=INVOICEID&t=PAYTOKEN&token=PAYPALORDERID   (PayPal sends the client back here after approval)
// Captures the payment, verifies the amount and pays the invoice.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/payments_lib.php';

swiftPayEnsureSchema($pdo);

$invoiceId = (int)($_GET['i'] ?? 0);
if ($invoiceId <= 0 && !empty($_GET['o'])) {          // return links created before invoices existed
    $invoiceId = swiftPayInvoiceIdForOrder($pdo, (int)$_GET['o']);
}
$invoice = swiftPayLoadInvoice($pdo, $invoiceId, (string)($_GET['t'] ?? ''));
$ppOrderId = (string)($_GET['token'] ?? '');
$payment = $invoice ? swiftPayFindPayment($pdo, 'paypal_order_id', $ppOrderId) : null;

// The PayPal order must be one we created for exactly this invoice.
$matches = false;
if ($invoice && $payment) {
    if (!empty($payment['invoice_id'])) {
        $matches = ((int)$payment['invoice_id'] === (int)$invoice['id']);
    } else {
        $matches = (!empty($payment['order_id']) && (int)$payment['order_id'] === (int)$invoice['order_id']);
    }
}
if (!$matches) {
    swiftPayRenderPage('Payment', '<p>This payment could not be matched to an invoice.</p>', 'error');
    exit;
}

$invoiceNo = (int)$invoice['id'];
$num = htmlspecialchars(swiftPayInvoiceNumber($invoiceNo));
$retryLink = '<p><a class="btn btn-green" href="' . htmlspecialchars(swiftPayCheckoutPath($invoiceNo, $invoice['pay_token'])) . '">Try the payment again</a></p>';

if ($invoice['status'] === 'Paid') {
    swiftPayRenderPage('Payment received', '<p>Invoice ' . $num . ' is paid. Thank you!</p>', 'success');
    exit;
}
if ($invoice['status'] !== 'Unpaid') {
    swiftPayRenderPage('Payment', '<p>Invoice ' . $num . ' is ' . htmlspecialchars(strtolower($invoice['status'])) . ' and can no longer be paid. No money was taken.</p>', 'error');
    exit;
}

$error = null;
$ppOrder = swiftPayCapturePayPalOrder($pdo, $ppOrderId, $error);
if (!$ppOrder) {
    swiftPayRenderPage('Payment failed', '<p>The payment could not be completed: ' . htmlspecialchars((string)$error) . '</p>' . $retryLink, 'error');
    exit;
}

$result = swiftPayApplyOrderResult($pdo, $payment, $ppOrder);

if ($result['state'] === 'paid') {
    if ($result['first']) {
        swiftPaySendReceipt($pdo, $invoiceNo);
    }
    if ($invoice['type'] === 'Renewal' && !empty($invoice['server_id'])) {
        $ex = $pdo->prepare("SELECT expires_at FROM servers WHERE id = ?");
        $ex->execute([(int)$invoice['server_id']]);
        $until = (string)$ex->fetchColumn();
        swiftPayRenderPage(
            'Payment received',
            '<p>Thank you! Invoice ' . $num . ' is paid and your server has been renewed' . ($until !== '' ? ' until <strong>' . htmlspecialchars(substr($until, 0, 10)) . '</strong>' : '') . '.</p>',
            'success'
        );
    } else {
        swiftPayRenderPage(
            'Payment received',
            '<p>Thank you! We received your payment for invoice ' . $num . '.</p>'
            . '<p>An administrator will now set up your server. You will get an e-mail as soon as it is ready, and it will appear in your Client Area.</p>',
            'success'
        );
    }
    exit;
}
if ($result['state'] === 'pending') {
    $why = $result['message'] !== '' ? ' (' . htmlspecialchars($result['message']) . ')' : '';
    swiftPayRenderPage(
        'Payment pending',
        '<p>PayPal is still processing your payment' . $why . '. Invoice ' . $num . ' will be marked as paid automatically as soon as the payment completes.</p>',
        'info'
    );
    exit;
}
swiftPayRenderPage('Payment failed', '<p>' . htmlspecialchars($result['message']) . '</p>' . $retryLink, 'error');
