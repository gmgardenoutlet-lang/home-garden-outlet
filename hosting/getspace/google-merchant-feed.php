<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/merchant.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
try {
    // Do not use repository snapshots, sessions or cached generated artefacts.
    $data = json_decode((string)file_get_contents(PRODUCTS_FILE), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data['products'] ?? null)) throw new RuntimeException('Invalid catalogue');
    if (!load_shipping_profiles_for_shop()) throw new RuntimeException('Shipping source unavailable');
    echo merchant_xml(merchant_catalog_report($data['products']));
} catch (Throwable $exception) {
    // A transient source error must not look like intentional catalogue deletion.
    http_response_code(503);
    header('Retry-After: 300');
    echo '<?xml version="1.0" encoding="UTF-8"?><error>Feed chwilowo niedostępny</error>';
}
