<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/paynow.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, max-age=0');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); echo '{"error":"method_not_allowed"}'; exit; }
if (!paynow_is_enabled()) { http_response_code(503); echo '{"error":"service_unavailable"}'; exit; }
$raw = (string)file_get_contents('php://input');
$signature = (string)($_SERVER['HTTP_SIGNATURE'] ?? '');
if (!paynow_verify_notification_signature($raw, $signature)) { http_response_code(401); echo '{"error":"invalid_notification"}'; exit; }
$payload = json_decode($raw, true);
if (!is_array($payload) || !is_string($payload['paymentId'] ?? null) || !is_string($payload['externalId'] ?? null) || !is_string($payload['status'] ?? null)) { http_response_code(400); echo '{"error":"invalid_notification"}'; exit; }
try {
    $order = shop_load_order((string)$payload['externalId']);
    if (!$order) throw new RuntimeException('not found');
    $updated = paynow_apply_status($order, $payload['paymentId'], $payload['externalId'], $payload['status'], (string)($payload['modifiedAt'] ?? ''));
    if ($updated !== $order) shop_save_order($updated);
    http_response_code(202);
} catch (Throwable $e) { http_response_code(400); echo '{"error":"invalid_notification"}'; }
