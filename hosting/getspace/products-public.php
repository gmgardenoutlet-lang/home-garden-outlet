<?php
declare(strict_types=1);

require_once __DIR__ . '/catalog.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$products = array_map('catalog_public_product_record', catalog_products_with_slugs());

echo json_encode(
    ['products' => $products],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
