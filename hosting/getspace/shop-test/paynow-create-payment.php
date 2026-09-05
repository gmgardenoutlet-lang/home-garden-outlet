<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/paynow.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, max-age=0');
$wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
$authorizedOrder = false;
$orderId = '';
$confirmationToken = '';
if (!shop_sales_enabled()) { http_response_code(403); echo '{"error":"shop_sales_disabled"}'; exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); echo '{"error":"method_not_allowed"}'; exit; }
if (!paynow_is_enabled()) { http_response_code(503); echo '{"error":"service_unavailable"}'; exit; }
try {
    require_csrf();
    $orderId = shop_safe_order_id((string)($_POST['order_id'] ?? ''));
    $confirmationToken = (string)($_POST['confirmation_token'] ?? '');
    if (shop_public_confirmation_order($orderId, $confirmationToken) === null) {
        throw new RuntimeException('Nie można otworzyć potwierdzenia zamówienia.');
    }
    $authorizedOrder = true;
    $result = paynow_start_payment($orderId, $confirmationToken);
    if (!$wantsJson) {
        header('Location: ' . shop_confirmation_url($orderId, $confirmationToken), true, 303);
        exit;
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($authorizedOrder) {
        try {
            paynow_record_start_failure($orderId);
        } catch (Throwable $ignored) {}
        if (!$wantsJson) {
            header('Location: ' . shop_confirmation_url($orderId, $confirmationToken), true, 303);
            exit;
        }
    }
    http_response_code(400); echo '{"error":"payment_not_started"}';
}
