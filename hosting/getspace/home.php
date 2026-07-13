<?php
declare(strict_types=1);

require __DIR__ . '/catalog.php';

function home_has_display_value($value): bool
{
    $normalized = trim(catalog_normalize((string)$value));

    return $normalized !== ''
        && strpos($normalized, 'do uzupelnienia') === false
        && $normalized !== 'cena outletowa'
        && $normalized !== 'brak'
        && $normalized !== 'xxx'
        && $normalized !== '-';
}

function home_display_status(array $product): string
{
    $managementStatus = catalog_normalize((string)($product['productStatus'] ?? ''));

    if ($managementStatus === 'sprzedany') {
        return 'Sprzedany';
    }

    if ($managementStatus === 'rezerwacja') {
        return 'Rezerwacja';
    }

    return home_has_display_value($product['status'] ?? '')
        ? (string)$product['status']
        : 'Dostępny od ręki';
}

function home_is_sold(array $product): bool
{
    return in_array(catalog_normalize(home_display_status($product)), ['sprzedany', 'sprzedane'], true);
}

function home_images(array $product): array
{
    $gallery = is_array($product['gallery'] ?? null) ? $product['gallery'] : [];
    $paths = [$product['image'] ?? ''];

    foreach ($gallery as $item) {
        $paths[] = is_array($item) ? ($item['image'] ?? '') : $item;
    }

    $images = [];
    foreach ($paths as $path) {
        if (home_has_display_value($path)) {
            $images[] = catalog_image_path($path);
        }
    }

    return array_values(array_unique($images ?: ['/product-table.jpeg']));
}

function home_parse_price($value): ?float
{
    $raw = preg_replace('/[^\d,.]/u', '', preg_replace('/\s/u', '', (string)$value) ?? '') ?? '';

    if ($raw === '') {
        return null;
    }

    $hasComma = strpos($raw, ',') !== false;
    $hasDot = strpos($raw, '.') !== false;

    if ($hasComma && $hasDot) {
        $normalized = str_replace(',', '.', str_replace('.', '', $raw));
    } elseif ($hasDot) {
        $parts = explode('.', $raw);
        $normalized = count($parts) > 1 && strlen((string)end($parts)) === 3
            ? implode('', $parts)
            : $raw;
    } else {
        $normalized = str_replace(',', '.', $raw);
    }

    return is_numeric($normalized) ? (float)$normalized : null;
}

function home_readable_category($value): string
{
    $normalized = catalog_normalize((string)$value);

    if (strpos($normalized, 'ogrod') !== false) {
        return 'Meble ogrodowe';
    }

    if (strpos($normalized, 'dom') !== false || strpos($normalized, 'dekoracje') !== false || strpos($normalized, 'oswietlenie') !== false) {
        return 'Meble do domu';
    }

    return home_has_display_value($value) ? (string)$value : 'Produkt outletowy';
}

function home_image_alt(array $product, string $name): string
{
    if (home_has_display_value($product['imageAlt'] ?? '')) {
        return trim((string)$product['imageAlt']);
    }

    $category = home_has_display_value($product['category'] ?? '')
        ? trim((string)$product['category'])
        : 'Meble do domu i ogrodu';

    return $name . ' - ' . $category . ', Home & Garden Outlet';
}

function home_card(array $product): string
{
    $name = (string)($product['name'] ?? '') ?: 'Produkt outletowy';
    $category = home_readable_category($product['category'] ?? 'Wyposażenie domu');
    $status = home_display_status($product);
    $images = home_images($product);
    $slug = (string)($product['_publicSlug'] ?? catalog_slugify($name));
    $detailUrl = '/produkt/' . rawurlencode($slug);
    $imageAlt = home_image_alt($product, $name);
    $galleryData = catalog_e((string)json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $badgeClass = $status === 'Rezerwacja' ? 'reserved' : (in_array($status, ['Sprzedane', 'Sprzedany'], true) ? 'sold' : '');
    $dimensions = home_has_display_value($product['dimensions'] ?? '')
        ? '<p class="dimensions">' . catalog_e($product['dimensions']) . '</p>'
        : '';
    $condition = home_has_display_value($product['condition'] ?? '')
        ? '<p class="dimensions">Stan: ' . catalog_e($product['condition']) . '</p>'
        : '';
    $hasCatalogPrice = home_has_display_value($product['catalogPrice'] ?? '');
    $hasOutletPrice = home_has_display_value($product['outletPrice'] ?? '');
    $catalogValue = home_parse_price($product['catalogPrice'] ?? '');
    $outletValue = home_parse_price($product['outletPrice'] ?? '');
    $savings = $hasCatalogPrice && $hasOutletPrice && $catalogValue && $outletValue && $catalogValue > $outletValue
        ? (int)round($catalogValue - $outletValue)
        : null;
    $priceItems = [];

    if ($hasCatalogPrice) {
        $priceItems[] = '<span class="catalog-price' . ($hasOutletPrice ? ' old-price' : '') . '">Cena katalogowa: ' . catalog_e($product['catalogPrice']) . '</span>';
    }
    if ($hasOutletPrice) {
        $priceItems[] = '<span class="outlet-price">Cena outletowa: ' . catalog_e($product['outletPrice']) . '</span>';
    }
    if ($savings !== null) {
        $priceItems[] = '<span class="saving-badge">Oszczędzasz: ' . $savings . ' zł</span>';
    }

    $priceRow = $priceItems
        ? '<div class="price-row' . ($hasOutletPrice ? ' has-outlet' : '') . '">' . implode('', $priceItems) . '</div>'
        : '';
    $priceNote = $hasOutletPrice ? '' : '<p class="price-note">' . ($hasCatalogPrice ? 'Zapytaj o cenę outletową.' : 'Zapytaj o cenę.') . '</p>';
    $description = (string)($product['description'] ?? '') ?: 'Produkt dostępny do obejrzenia na miejscu.';
    $galleryCount = count($images) > 1 ? '<span class="gallery-count">' . count($images) . ' zdjęć</span>' : '';

    return '\n    <article class="product-card product-card-static">\n      <div class="product-image">\n        <a class="product-image-link" href="' . catalog_e($detailUrl) . '" data-gallery="' . $galleryData . '" data-gallery-name="' . catalog_e($name) . '" data-gallery-alt="' . catalog_e($imageAlt) . '" aria-label="Zobacz produkt: ' . catalog_e($name) . '">\n          <img src="' . catalog_e($images[0]) . '" width="600" height="450" loading="lazy" alt="' . catalog_e($imageAlt) . '">\n        </a>\n        <span class="badge ' . $badgeClass . '">' . catalog_e($status) . '</span>\n        ' . $galleryCount . '\n      </div>\n      <div class="product-body">\n        <div class="product-meta">\n          <span>' . catalog_e($category) . '</span>\n          <span>Dostępny lokalnie</span>\n        </div>\n        <h3><a class="product-title-link" href="' . catalog_e($detailUrl) . '">' . catalog_e($name) . '</a></h3>\n        ' . $priceRow . '\n        ' . $priceNote . '\n        <div class="product-description-wrap">\n          <p class="product-description">' . catalog_e($description) . '</p>\n          <button class="description-toggle" type="button" aria-expanded="false" hidden>Więcej</button>\n        </div>\n        ' . $condition . '\n        ' . $dimensions . '\n        <div class="product-card-links" aria-label="Powiązane kategorie">\n          <a href="/dom">Więcej mebli do domu</a><a href="/outlet-meblowy-wroclaw/">Outlet meblowy pod Wrocławiem</a>\n        </div>\n        <div class="product-actions">\n          <a class="btn btn-primary" href="' . catalog_e($detailUrl) . '">Zobacz produkt</a>\n          <a class="btn btn-outline" href="tel:+48577210777">Zapytaj o dostępność</a>\n        </div>\n      </div>\n    </article>';
}

$catalogData = is_file(CATALOG_PRODUCTS_FILE)
    ? json_decode((string)@file_get_contents(CATALOG_PRODUCTS_FILE), true)
    : null;
$catalogIsAvailable = is_array($catalogData) && isset($catalogData['products']) && is_array($catalogData['products']);
$homeProducts = $catalogIsAvailable ? catalog_products_with_slugs() : [];

$homeProducts = array_values(array_filter($homeProducts, static function (array $product): bool {
    return catalog_is_public($product)
        && !home_is_sold($product)
        && in_array(catalog_normalize((string)($product['category'] ?? '')), ['wyposazenie domu', 'dom', 'dekoracje', 'oswietlenie'], true);
}));

usort($homeProducts, static function (array $left, array $right): int {
    $byOrder = (float)($left['order'] ?? 0) <=> (float)($right['order'] ?? 0);

    return $byOrder !== 0 ? $byOrder : (int)($left['_catalogIndex'] ?? 0) <=> (int)($right['_catalogIndex'] ?? 0);
});

$cards = str_replace('\\n', "\n", implode('', array_map('home_card', $homeProducts)));

ob_start();
require __DIR__ . '/dom.html';
$page = (string)ob_get_clean();

if (!$catalogIsAvailable) {
    $page = str_replace(' class="product-count" data-product-count', ' class="product-count"', $page);
    $page = str_replace(' class="empty-products" data-product-empty hidden', ' class="empty-products" data-product-empty', $page);
    $cards = "\n        <template class=\"product-card-static\" data-product-static-fallback></template>";
}

$page = preg_replace(
    '/<!-- STATIC_PRODUCTS_START -->.*<!-- STATIC_PRODUCTS_END -->/s',
    '<!-- STATIC_PRODUCTS_START -->' . $cards . "\n        " . '<!-- STATIC_PRODUCTS_END -->',
    $page,
    1
);

echo $page;
