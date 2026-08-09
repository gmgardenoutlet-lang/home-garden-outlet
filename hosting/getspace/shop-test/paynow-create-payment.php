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
// Payment initiation is deliberately unavailable until checkout ownership and live Sandbox credentials are configured.
http_response_code(503); echo '{"error":"payment_integration_not_activated"}';
