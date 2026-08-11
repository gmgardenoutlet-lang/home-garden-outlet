<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/paynow.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, max-age=0');
if (!shop_sales_enabled()) { http_response_code(403); echo '{"error":"shop_sales_disabled"}'; exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); echo '{"error":"method_not_allowed"}'; exit; }
if (!paynow_is_enabled()) { http_response_code(503); echo '{"error":"service_unavailable"}'; exit; }
try {
    require_csrf();
    $orderId = shop_safe_order_id((string)($_POST['order_id'] ?? ''));
    $result = paynow_start_payment($orderId);
    if (!str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Location: ' . shop_catalog_url() . '/potwierdzenie?id=' . rawurlencode($orderId), true, 303);
        exit;
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400); echo '{"error":"payment_not_started"}';
}
