<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/lib.php';

function shop_test_boot(): void
{
    boot_admin();
    require_login();
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}

function shop_test_image_url(string $path): string
{
    $path = trim($path);
    if ($path === '' || str_contains($path, '..')) {
        return '/product-table.jpeg';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return str_starts_with($path, '/') ? $path : '/' . $path;
}

function shop_test_gallery(array $product): array
{
    $paths = [$product['image'] ?? ''];
    foreach (($product['gallery'] ?? []) as $item) {
        $paths[] = is_array($item) ? ($item['image'] ?? '') : $item;
    }

    $result = [];
    foreach ($paths as $path) {
        $path = trim((string)$path);
        if ($path !== '' && !str_contains($path, '..')) {
            $result[] = shop_test_image_url($path);
        }
    }

    return array_values(array_unique($result ?: ['/product-table.jpeg']));
}

function shop_test_slug(array $product): string
{
    $source = trim((string)($product['slug'] ?? '')) !== ''
        ? (string)$product['slug']
        : (string)($product['name'] ?? 'figura');
    return clean_filename($source);
}

function shop_test_delivery_key(string $value): string
{
    $value = strtolower(trim($value));
    return preg_replace('/[^a-z0-9_]/', '', $value) ?: '';
}

function shop_test_price_number($value): ?float
{
    $cleaned = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], (string)$value);
    if (!preg_match('/\d+(?:\.\d+)?/', $cleaned, $matches)) {
        return null;
    }
    $price = (float)$matches[0];
    return $price > 0 ? $price : null;
}

function shop_test_price_label(?float $price): string
{
    return $price === null ? 'Zapytaj o cenę' : number_format($price, 2, ',', ' ') . ' zł';
}

function shop_test_is_figure(array $product): bool
{
    $merged = array_merge(product_defaults(), $product);
    return ($merged['saleType'] ?? '') === 'garden_figure'
        && !empty($merged['shopVisible'])
        && ($merged['shopStatus'] ?? '') === 'Dostępny'
        && !in_array((string)($merged['productStatus'] ?? ''), ['Sprzedany', 'Ukryty'], true)
        && !in_array((string)($merged['status'] ?? ''), ['Sprzedane', 'Sprzedany'], true);
}

function shop_test_products(): array
{
    $catalog = load_catalog();
    $result = [];
    foreach (($catalog['products'] ?? []) as $index => $product) {
        if (!is_array($product)) {
            continue;
        }
        $product = array_merge(product_defaults(), $product);
        if (!shop_test_is_figure($product)) {
            continue;
        }
        $product['_shopIndex'] = $index;
        $product['_shopSlug'] = shop_test_slug($product);
        $result[] = $product;
    }

    usort($result, static function (array $a, array $b): int {
        return ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0))
            ?: strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    return $result;
}

function shop_test_product_map(): array
{
    $map = [];
    foreach (shop_test_products() as $product) {
        $map[(string)$product['_shopSlug']] = $product;
    }
    return $map;
}

function shop_test_find_product(string $slug): ?array
{
    $slug = clean_filename($slug);
    $products = shop_test_product_map();
    return $products[$slug] ?? null;
}

function shop_test_delivery_methods(array $product): array
{
    $labels = shop_delivery_labels();
    $methods = [];
    foreach (($product['deliveryMethods'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $method = shop_test_delivery_key((string)($item['method'] ?? ''));
        if (!isset($labels[$method])) {
            continue;
        }
        $cost = trim((string)($item['cost'] ?? ''));
        $methods[$method] = [
            'method' => $method,
            'label' => $labels[$method],
            'cost' => $cost !== '' ? $cost : 'do ustalenia',
            'costNumber' => shop_test_price_number($cost),
        ];
    }

    if (!$methods) {
        $methods['individual'] = [
            'method' => 'individual',
            'label' => $labels['individual'],
            'cost' => 'do ustalenia',
            'costNumber' => null,
        ];
    }

    return $methods;
}

function shop_test_public_product(array $product): array
{
    $price = shop_test_price_number($product['grossPrice'] ?? '');
    $slug = (string)($product['_shopSlug'] ?? shop_test_slug($product));
    $name = trim((string)($product['name'] ?? 'Figura ogrodowa'));
    $images = shop_test_gallery($product);

    return [
        'slug' => $slug,
        'name' => $name,
        'sku' => trim((string)($product['sku'] ?? '')),
        'price' => $price,
        'priceLabel' => shop_test_price_label($price),
        'canBuy' => $price !== null,
        'image' => $images[0],
        'alt' => trim((string)($product['imageAlt'] ?? '')) !== ''
            ? trim((string)$product['imageAlt'])
            : $name . ' dostępna w Home & Garden Outlet',
        'shortDescription' => trim((string)($product['description'] ?? '')),
        'leadTime' => trim((string)($product['leadTime'] ?? '2-5 dni roboczych')),
        'availability' => trim((string)($product['producerAvailability'] ?? 'Dostępny u producenta')),
        'deliveryMethods' => array_values(shop_test_delivery_methods($product)),
    ];
}

function shop_test_cart_common_delivery(array $items): array
{
    $common = null;
    foreach ($items as $item) {
        $methods = shop_test_delivery_methods($item['product']);
        $common = $common === null ? $methods : array_intersect_key($common, $methods);
    }
    if ($common === null) {
        return [];
    }
    if (!$common) {
        $labels = shop_delivery_labels();
        return [
            'individual' => [
                'method' => 'individual',
                'label' => $labels['individual'],
                'cost' => 'do ustalenia',
                'costNumber' => null,
            ],
        ];
    }
    return $common;
}

function shop_test_decode_cart(string $payload, array $products): array
{
    if (strlen($payload) > 20000) {
        throw new RuntimeException('Koszyk jest zbyt duży. Odśwież stronę i spróbuj ponownie.');
    }

    $data = json_decode($payload, true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        throw new RuntimeException('Nie udało się odczytać koszyka.');
    }

    $items = [];
    foreach ($data['items'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $slug = clean_filename((string)($row['slug'] ?? ''));
        $quantity = max(1, min(20, (int)($row['quantity'] ?? 1)));
        if ($slug === '' || !isset($products[$slug])) {
            continue;
        }
        $product = $products[$slug];
        $price = shop_test_price_number($product['grossPrice'] ?? '');
        if ($price === null) {
            continue;
        }
        $items[] = [
            'product' => $product,
            'slug' => $slug,
            'quantity' => $quantity,
            'price' => $price,
            'lineTotal' => round($price * $quantity, 2),
        ];
    }

    if (!$items) {
        throw new RuntimeException('Koszyk jest pusty albo zawiera produkty bez ceny.');
    }

    return [
        'items' => $items,
        'delivery' => shop_test_delivery_key((string)($data['delivery'] ?? '')),
    ];
}

function shop_test_text_field(string $key, int $maxLength = 300): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}
