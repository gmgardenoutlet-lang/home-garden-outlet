<?php
declare(strict_types=1);

const CATALOG_PRODUCTS_FILE = __DIR__ . '/data/products.json';
const CATALOG_SITE_URL = 'https://mgoutlet.pl';

function catalog_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function catalog_normalize(string $value): string
{
    $value = trim($value);
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    return strtolower($value);
}

function catalog_has_value($value): bool
{
    $normalized = trim(catalog_normalize((string)$value));
    return $normalized !== ''
        && $normalized !== 'brak'
        && $normalized !== 'xxx'
        && $normalized !== '-'
        && $normalized !== 'niedostepny'
        && strpos($normalized, 'do uzupelnienia') === false;
}

function catalog_slugify(string $value): string
{
    $value = catalog_normalize($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'produkt';
    return trim($value, '-') ?: 'produkt';
}

function catalog_load(): array
{
    if (!is_file(CATALOG_PRODUCTS_FILE)) {
        return [];
    }

    $data = json_decode((string)file_get_contents(CATALOG_PRODUCTS_FILE), true);
    return is_array($data) && isset($data['products']) && is_array($data['products'])
        ? $data['products']
        : [];
}

function catalog_is_public(array $product): bool
{
    return ($product['visible'] ?? true) !== false
        && catalog_normalize((string)($product['productStatus'] ?? '')) !== 'ukryty';
}

function catalog_display_status(array $product): string
{
    $managementStatus = catalog_normalize((string)($product['productStatus'] ?? ''));
    if ($managementStatus === 'sprzedany') {
        return 'Sprzedane';
    }
    if ($managementStatus === 'rezerwacja') {
        return 'Rezerwacja';
    }
    return catalog_has_value($product['status'] ?? '') ? trim((string)$product['status']) : 'Dostępne od ręki';
}

function catalog_products_with_slugs(): array
{
    $products = catalog_load();
    $used = [];

    foreach ($products as $index => &$product) {
        $source = catalog_has_value($product['slug'] ?? '')
            ? (string)$product['slug']
            : (string)($product['name'] ?? 'produkt');
        $base = catalog_slugify($source);
        $used[$base] = ($used[$base] ?? 0) + 1;
        $product['_publicSlug'] = $used[$base] > 1 ? $base . '-' . $used[$base] : $base;
        $product['_catalogIndex'] = $index;
    }
    unset($product);

    return $products;
}

function catalog_find_product(string $slug): ?array
{
    $slug = catalog_slugify($slug);
    foreach (catalog_products_with_slugs() as $product) {
        if (catalog_is_public($product) && ($product['_publicSlug'] ?? '') === $slug) {
            return $product;
        }
    }
    return null;
}

function catalog_image_path($value): string
{
    $path = trim((string)$value);
    if ($path === '' || strpos($path, '..') !== false) {
        return '/product-table.jpeg';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return '/' . ltrim($path, '/');
}

function catalog_images(array $product): array
{
    $paths = [$product['image'] ?? ''];
    foreach (($product['gallery'] ?? []) as $item) {
        $paths[] = is_array($item) ? ($item['image'] ?? '') : $item;
    }

    $result = [];
    foreach ($paths as $path) {
        if (catalog_has_value($path)) {
            $result[] = catalog_image_path($path);
        }
    }
    return array_values(array_unique($result ?: ['/product-table.jpeg']));
}

function catalog_absolute_url(string $path): string
{
    return preg_match('#^https?://#i', $path)
        ? $path
        : CATALOG_SITE_URL . '/' . ltrim($path, '/');
}

function catalog_price_number($value): ?float
{
    $cleaned = str_replace([' ', ','], ['', '.'], (string)$value);
    if (!preg_match('/\d+(?:\.\d+)?/', $cleaned, $matches)) {
        return null;
    }
    return (float)$matches[0];
}

function catalog_shorten(string $value, int $maxLength = 160): string
{
    $text = trim((string)preg_replace('/\s+/', ' ', $value));
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') <= $maxLength) {
        return $text;
    }
    if (!function_exists('mb_substr')) {
        return strlen($text) <= $maxLength ? $text : rtrim(substr($text, 0, $maxLength - 1)) . '…';
    }
    $short = mb_substr($text, 0, $maxLength - 1, 'UTF-8');
    $space = mb_strrpos($short, ' ', 0, 'UTF-8');
    if ($space !== false && $space > 100) {
        $short = mb_substr($short, 0, $space, 'UTF-8');
    }
    return rtrim($short, " .,\t\n\r\0\x0B") . '…';
}

function catalog_seo(array $product): array
{
    $name = catalog_has_value($product['name'] ?? '') ? trim((string)$product['name']) : 'Produkt outletowy';
    $category = catalog_has_value($product['category'] ?? '') ? trim((string)$product['category']) : 'Meble do domu i ogrodu';
    $description = catalog_has_value($product['seoDescription'] ?? '')
        ? (string)$product['seoDescription']
        : ((catalog_has_value($product['description'] ?? '') ? (string)$product['description'] : $name)
            . ' ' . $category . ' dostępne w showroomie Home & Garden Outlet pod Wrocławiem.');

    return [
        'slug' => (string)($product['_publicSlug'] ?? catalog_slugify((string)($product['slug'] ?? $name))),
        'title' => catalog_has_value($product['seoTitle'] ?? '')
            ? trim((string)$product['seoTitle'])
            : $name . ' | Home & Garden Outlet',
        'description' => catalog_shorten($description),
        'imageAlt' => catalog_has_value($product['imageAlt'] ?? '')
            ? trim((string)$product['imageAlt'])
            : $name . ' dostępny w Home & Garden Outlet pod Wrocławiem',
    ];
}

function catalog_category_url(array $product): string
{
    return strpos(catalog_normalize((string)($product['category'] ?? '')), 'ogrod') !== false ? '/ogrod' : '/dom';
}
