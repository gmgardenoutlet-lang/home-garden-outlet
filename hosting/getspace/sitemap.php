<?php
declare(strict_types=1);

require __DIR__ . '/catalog.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=900');

function sitemap_url(string $loc): array
{
    return ['loc' => $loc];
}

function sitemap_emit_url(array $url): void
{
    echo '  <url>' . PHP_EOL;
    echo '    <loc>' . catalog_e($url['loc']) . '</loc>' . PHP_EOL;

    echo '  </url>' . PHP_EOL;
}

// No URL has a verified per-page content modification date. A catalogue file
// mtime (or a build/deploy time) would incorrectly change many URLs at once.
$urls = [
    sitemap_url(CATALOG_SITE_URL . '/'),
    sitemap_url(CATALOG_SITE_URL . '/ogrod'),
    sitemap_url(CATALOG_SITE_URL . '/dom'),
    sitemap_url(CATALOG_SITE_URL . '/poradnik/'),
    sitemap_url(CATALOG_SITE_URL . '/poradnik/figury-i-dekoracje-w-ogrodzie-jak-je-dobrac/'),
    sitemap_url(CATALOG_SITE_URL . '/poradnik/czym-jest-outlet-meblowy/'),
    sitemap_url(CATALOG_SITE_URL . '/poradnik/meble-ogrodowe-z-outletu-na-co-zwrocic-uwage/'),
    sitemap_url(CATALOG_SITE_URL . '/poradnik/dlaczego-warto-ogladac-meble-na-zywo/'),
    sitemap_url(CATALOG_SITE_URL . '/poradnik/meble-z-ekspozycji-czy-warto/'),
    sitemap_url(CATALOG_SITE_URL . '/poradnik/zakup-produktu-outletowego-z-dostawa/'),
    sitemap_url(CATALOG_SITE_URL . '/outlet-meblowy-wroclaw/'),
    sitemap_url(CATALOG_SITE_URL . '/meble-ogrodowe-wroclaw/'),
    sitemap_url(CATALOG_SITE_URL . '/sklep/figury-ogrodowe'),
];

foreach (catalog_products_with_slugs() as $product) {
    if (!catalog_is_indexable_figure_shop_product($product)) {
        continue;
    }

    $urls[] = sitemap_url(CATALOG_SITE_URL . catalog_figure_shop_product_url($product));
}

foreach (catalog_products_with_slugs() as $product) {
    if (!catalog_is_public($product) || catalog_is_figure_shop_product($product)
        || catalog_legacy_figure_shop_slug((string)$product['_publicSlug']) !== null) {
        continue;
    }
    $urls[] = sitemap_url(CATALOG_SITE_URL . '/produkt/' . rawurlencode((string)$product['_publicSlug']));
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
foreach ($urls as $url) {
    sitemap_emit_url($url);
}
echo '</urlset>' . PHP_EOL;
