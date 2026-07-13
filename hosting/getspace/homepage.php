<?php
declare(strict_types=1);

require __DIR__ . '/catalog.php';

function homepage_has_display_value($value): bool
{
    $normalized = trim(catalog_normalize((string)$value));

    return $normalized !== ''
        && strpos($normalized, 'do uzupelnienia') === false
        && $normalized !== 'cena outletowa'
        && $normalized !== 'brak'
        && $normalized !== 'xxx'
        && $normalized !== '-';
}

function homepage_display_status(array $product): string
{
    $managementStatus = catalog_normalize((string)($product['productStatus'] ?? ''));

    if ($managementStatus === 'sprzedany') {
        return 'Sprzedany';
    }

    if ($managementStatus === 'rezerwacja') {
        return 'Rezerwacja';
    }

    return homepage_has_display_value($product['status'] ?? '')
        ? (string)$product['status']
        : 'Dostępny od ręki';
}

function homepage_is_sold(array $product): bool
{
    return in_array(catalog_normalize(homepage_display_status($product)), ['sprzedany', 'sprzedane'], true);
}

function homepage_images(array $product): array
{
    $gallery = is_array($product['gallery'] ?? null) ? $product['gallery'] : [];
    $paths = [$product['image'] ?? ''];

    foreach ($gallery as $item) {
        $paths[] = is_array($item) ? ($item['image'] ?? '') : $item;
    }

    $images = [];
    foreach ($paths as $path) {
        if (homepage_has_display_value($path)) {
            $images[] = catalog_image_path($path);
        }
    }

    return array_values(array_unique($images ?: ['/product-table.jpeg']));
}

function homepage_parse_price($value): ?float
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

function homepage_readable_category($value): string
{
    $normalized = catalog_normalize((string)$value);

    if (strpos($normalized, 'ogrod') !== false) {
        return 'Meble ogrodowe';
    }

    if (strpos($normalized, 'dom') !== false || strpos($normalized, 'dekoracje') !== false || strpos($normalized, 'oswietlenie') !== false) {
        return 'Meble do domu';
    }

    return homepage_has_display_value($value) ? (string)$value : 'Produkt outletowy';
}

function homepage_category_links(array $product): string
{
    $category = catalog_normalize((string)($product['category'] ?? ''));

    return strpos($category, 'ogrod') !== false
        ? '<a href="/ogrod">Więcej mebli ogrodowych</a><a href="/meble-ogrodowe-wroclaw/">Meble ogrodowe outlet Wrocław</a>'
        : '<a href="/dom">Więcej mebli do domu</a><a href="/outlet-meblowy-wroclaw/">Outlet meblowy pod Wrocławiem</a>';
}

function homepage_image_alt(array $product, string $name): string
{
    if (homepage_has_display_value($product['imageAlt'] ?? '')) {
        return trim((string)$product['imageAlt']);
    }

    $category = homepage_has_display_value($product['category'] ?? '')
        ? trim((string)$product['category'])
        : 'Meble do domu i ogrodu';

    return $name . ' - ' . $category . ', Home & Garden Outlet';
}

function homepage_card(array $product): string
{
    $name = (string)($product['name'] ?? '') ?: 'Produkt outletowy';
    $category = homepage_readable_category($product['category'] ?? 'Wyposażenie domu');
    $status = homepage_display_status($product);
    $images = homepage_images($product);
    $slug = (string)($product['_publicSlug'] ?? catalog_slugify($name));
    $detailUrl = '/produkt/' . rawurlencode($slug);
    $imageAlt = homepage_image_alt($product, $name);
    $galleryData = catalog_e((string)json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $badgeClass = $status === 'Rezerwacja' ? 'reserved' : (in_array($status, ['Sprzedane', 'Sprzedany'], true) ? 'sold' : '');
    $dimensions = homepage_has_display_value($product['dimensions'] ?? '')
        ? '<p class="dimensions">' . catalog_e($product['dimensions']) . '</p>'
        : '';
    $condition = homepage_has_display_value($product['condition'] ?? '')
        ? '<p class="dimensions">Stan: ' . catalog_e($product['condition']) . '</p>'
        : '';
    $hasCatalogPrice = homepage_has_display_value($product['catalogPrice'] ?? '');
    $hasOutletPrice = homepage_has_display_value($product['outletPrice'] ?? '');
    $catalogValue = homepage_parse_price($product['catalogPrice'] ?? '');
    $outletValue = homepage_parse_price($product['outletPrice'] ?? '');
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

    return '\n    <article class="product-card product-card-static">\n      <div class="product-image">\n        <a class="product-image-link" href="' . catalog_e($detailUrl) . '" data-gallery="' . $galleryData . '" data-gallery-name="' . catalog_e($name) . '" data-gallery-alt="' . catalog_e($imageAlt) . '" aria-label="Zobacz produkt: ' . catalog_e($name) . '">\n          <img src="' . catalog_e($images[0]) . '" width="600" height="450" loading="lazy" alt="' . catalog_e($imageAlt) . '">\n        </a>\n        <span class="badge ' . $badgeClass . '">' . catalog_e($status) . '</span>\n        ' . $galleryCount . '\n      </div>\n      <div class="product-body">\n        <div class="product-meta">\n          <span>' . catalog_e($category) . '</span>\n          <span>Dostępny lokalnie</span>\n        </div>\n        <h3><a class="product-title-link" href="' . catalog_e($detailUrl) . '">' . catalog_e($name) . '</a></h3>\n        ' . $priceRow . '\n        ' . $priceNote . '\n        <div class="product-description-wrap">\n          <p class="product-description">' . catalog_e($description) . '</p>\n          <button class="description-toggle" type="button" aria-expanded="false" hidden>Więcej</button>\n        </div>\n        ' . $condition . '\n        ' . $dimensions . '\n        <div class="product-card-links" aria-label="Powiązane kategorie">\n          ' . homepage_category_links($product) . '\n        </div>\n        <div class="product-actions">\n          <a class="btn btn-primary" href="' . catalog_e($detailUrl) . '">Zobacz produkt</a>\n          <a class="btn btn-outline" href="tel:+48577210777">Zapytaj o dostępność</a>\n        </div>\n      </div>\n    </article>';
}

function homepage_shuffle(array $products): array
{
    for ($index = count($products) - 1; $index > 0; $index--) {
        $randomIndex = random_int(0, $index);
        [$products[$index], $products[$randomIndex]] = [$products[$randomIndex], $products[$index]];
    }

    return $products;
}

$catalogData = is_file(CATALOG_PRODUCTS_FILE)
    ? json_decode((string)@file_get_contents(CATALOG_PRODUCTS_FILE), true)
    : null;
$catalogIsAvailable = is_array($catalogData) && isset($catalogData['products']) && is_array($catalogData['products']);
$homepageProducts = $catalogIsAvailable ? catalog_products_with_slugs() : [];

$homepageProducts = array_values(array_filter($homepageProducts, static function (array $product): bool {
    return catalog_is_public($product) && !homepage_is_sold($product);
}));

$featuredProducts = array_values(array_filter($homepageProducts, static function (array $product): bool {
    return ($product['featured'] ?? null) !== false;
}));
$remainingProducts = array_values(array_filter($homepageProducts, static function (array $product): bool {
    return ($product['featured'] ?? null) === false;
}));
$selectedProducts = array_slice(homepage_shuffle($featuredProducts), 0, 6);

if (count($selectedProducts) < 6) {
    $selectedProducts = array_merge(
        $selectedProducts,
        array_slice(homepage_shuffle($remainingProducts), 0, 6 - count($selectedProducts))
    );
}

$selectedSlugs = array_values(array_map(static function (array $product): string {
    return (string)($product['_publicSlug'] ?? catalog_slugify((string)($product['name'] ?? '')));
}, $selectedProducts));
$cards = str_replace('\n', "\n", implode('', array_map('homepage_card', $selectedProducts)));

ob_start();
require __DIR__ . '/index.html';
$page = (string)ob_get_clean();

$page = preg_replace(
    '/\s*<script src="https:\/\/identity\.netlify\.com\/v1\/netlify-identity-widget\.js"><\/script>\s*/',
    "\n",
    $page,
    1
);

if (!$catalogIsAvailable) {
    $page = str_replace(' class="product-count" data-product-count', ' class="product-count"', $page);
    $page = str_replace(' class="empty-products" data-product-empty hidden', ' class="empty-products" data-product-empty', $page);
    $cards = "\n        <template class=\"product-card-static\" data-product-static-fallback></template>";
} else {
    $selectedSlugsJson = catalog_e((string)json_encode($selectedSlugs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $page = str_replace(
        '<div id="produkty" class="product-grid" aria-live="polite">',
        '<div id="produkty" class="product-grid" aria-live="polite" data-homepage-selected-slugs="' . $selectedSlugsJson . '">',
        $page
    );
}

$page = preg_replace(
    '/<!-- STATIC_PRODUCTS_START -->.*<!-- STATIC_PRODUCTS_END -->/s',
    '<!-- STATIC_PRODUCTS_START -->' . $cards . "\n        " . '<!-- STATIC_PRODUCTS_END -->',
    $page,
    1
);

echo $page;
