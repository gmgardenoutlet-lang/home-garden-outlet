<?php
declare(strict_types=1);

require __DIR__ . '/catalog.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=900');

function sitemap_catalog_lastmod(): ?string
{
    if (!is_file(CATALOG_PRODUCTS_FILE)) {
        return null;
    }

    $modifiedAt = @filemtime(CATALOG_PRODUCTS_FILE);
    if (!is_int($modifiedAt) || $modifiedAt <= 0) {
        return null;
    }

    $lastmod = gmdate('Y-m-d', $modifiedAt);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastmod) === 1 ? $lastmod : null;
}

function sitemap_url(string $loc, ?string $lastmod = null): array
{
    return ['loc' => $loc, 'lastmod' => $lastmod];
}

function sitemap_emit_url(array $url): void
{
    echo '  <url>' . PHP_EOL;
    echo '    <loc>' . catalog_e($url['loc']) . '</loc>' . PHP_EOL;

    $lastmod = $url['lastmod'] ?? null;
    if (is_string($lastmod) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastmod) === 1) {
        echo '    <lastmod>' . catalog_e($lastmod) . '</lastmod>' . PHP_EOL;
    }

    echo '  </url>' . PHP_EOL;
}

$lastModified = sitemap_catalog_lastmod();
$urls = [
    sitemap_url(CATALOG_SITE_URL . '/', $lastModified),
    sitemap_url(CATALOG_SITE_URL . '/ogrod', $lastModified),
    sitemap_url(CATALOG_SITE_URL . '/dom', $lastModified),
    sitemap_url(CATALOG_SITE_URL . '/poradnik/'),
    sitemap_url(CATALOG_SITE_URL . '/poradnik/czym-jest-outlet-meblowy/'),
    sitemap_url(CATALOG_SITE_URL . '/poradnik/meble-ogrodowe-z-outletu-na-co-zwrocic-uwage/'),
    sitemap_url(CATALOG_SITE_URL . '/poradnik/dlaczego-warto-ogladac-meble-na-zywo/'),
    sitemap_url(CATALOG_SITE_URL . '/poradnik/meble-z-ekspozycji-czy-warto/'),
    sitemap_url(CATALOG_SITE_URL . '/outlet-meblowy-wroclaw/'),
    sitemap_url(CATALOG_SITE_URL . '/meble-ogrodowe-wroclaw/'),
];

foreach (catalog_products_with_slugs() as $product) {
    if (!catalog_is_public($product)) {
        continue;
    }
    $urls[] = sitemap_url(
        CATALOG_SITE_URL . '/produkt/' . rawurlencode((string)$product['_publicSlug']),
        $lastModified
    );
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
foreach ($urls as $url) {
    sitemap_emit_url($url);
}
echo '</urlset>' . PHP_EOL;
