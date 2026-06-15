<?php
declare(strict_types=1);

require __DIR__ . '/catalog.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=900');

$lastModified = is_file(CATALOG_PRODUCTS_FILE) ? date('Y-m-d', (int)filemtime(CATALOG_PRODUCTS_FILE)) : date('Y-m-d');
$urls = [
    ['loc' => CATALOG_SITE_URL . '/', 'lastmod' => $lastModified],
    ['loc' => CATALOG_SITE_URL . '/ogrod', 'lastmod' => $lastModified],
    ['loc' => CATALOG_SITE_URL . '/dom', 'lastmod' => $lastModified],
    ['loc' => CATALOG_SITE_URL . '/outlet-meblowy-wroclaw/', 'lastmod' => $lastModified],
    ['loc' => CATALOG_SITE_URL . '/meble-ogrodowe-wroclaw/', 'lastmod' => $lastModified],
];

foreach (catalog_products_with_slugs() as $product) {
    if (!catalog_is_public($product)) {
        continue;
    }
    $urls[] = [
        'loc' => CATALOG_SITE_URL . '/produkt/' . rawurlencode((string)$product['_publicSlug']),
        'lastmod' => $lastModified,
    ];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
foreach ($urls as $url) {
    echo '  <url>' . PHP_EOL;
    echo '    <loc>' . catalog_e($url['loc']) . '</loc>' . PHP_EOL;
    echo '    <lastmod>' . catalog_e($url['lastmod']) . '</lastmod>' . PHP_EOL;
    echo '  </url>' . PHP_EOL;
}
echo '</urlset>' . PHP_EOL;
