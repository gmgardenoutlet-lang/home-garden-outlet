<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
http_response_code(200);
echo json_encode(['private_config_readable' => hgo_paynow_config('HGO_PAYNOW_TEST_MARKER') === 'TEST_VALUE']);
