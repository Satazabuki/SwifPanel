<?php
// paypal_webhook.php - receives PayPal webhook events (set this URL in your PayPal app, see Admin > Payments).
// Every call is verified with PayPal before it is trusted. Events handled:
//   PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.DENIED, PAYMENT.CAPTURE.REFUNDED, PAYMENT.CAPTURE.REVERSED
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/payments_lib.php';

header('Content-Type: application/json');

function swiftWebhookReply($code, $ok, $message)
{
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    swiftWebhookReply(400, false, 'Empty request');
}
$event = json_decode($raw, true);
if (!is_array($event)) {
    swiftWebhookReply(400, false, 'Invalid JSON');
}

swiftPayEnsureSchema($pdo);
$s = swiftPaySettings($pdo, true);
if (!swiftPayReady($s) || $s['webhook_id'] === '') {
    swiftWebhookReply(503, false, 'Webhook is not configured');
}

$h = [
    'auth_algo' => $_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ?? '',
    'cert_url' => $_SERVER['HTTP_PAYPAL_CERT_URL'] ?? '',
    'transmission_id' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? '',
    'transmission_sig' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ?? '',
    'transmission_time' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? '',
];
foreach ($h as $value) {
    if ($value === '') {
        swiftWebhookReply(400, false, 'Missing PayPal signature headers');
    }
}

if (!swiftPayVerifyWebhook($s, $h, $raw)) {
    swiftPayLog('webhook signature verification failed');
    swiftWebhookReply(400, false, 'Signature verification failed');
}

try {
    $result = swiftPayHandleWebhookEvent($pdo, $event);
} catch (Exception $e) {
    swiftPayLog('webhook handling error: ' . $e->getMessage());
    swiftWebhookReply(500, false, 'Processing error');   // PayPal will retry
}
swiftWebhookReply(200, true, $result);
