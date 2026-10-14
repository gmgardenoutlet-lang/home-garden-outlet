<?php
declare(strict_types=1);
require_once __DIR__.'/lib/merchant-local.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
try {
    $json = @file_get_contents(PRODUCTS_FILE);
    if ($json === false) throw new RuntimeException('Source unavailable');
    $catalog = json_decode($json,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($catalog['products'] ?? null)) throw new RuntimeException('Invalid source');
    echo merchant_local_xml(merchant_local_report($catalog['products']), defined('MERCHANT_LOCAL_INVENTORY') && MERCHANT_LOCAL_INVENTORY);
} catch (Throwable $exception) {
    http_response_code(503);
    header('Retry-After: 300');
    echo '<?xml version="1.0" encoding="UTF-8"?><error>Feed chwilowo niedostępny</error>';
}
