<?php
declare(strict_types=1);

$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), DIRECTORY_SEPARATOR);
$path = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
$staticPath = realpath($root . str_replace('/', DIRECTORY_SEPARATOR, $path));

if (preg_match('#^/data(?:/|$)#i', $path) === 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    return true;
}

if ($path === '/sitemap.xml') {
    require $root . DIRECTORY_SEPARATOR . 'sitemap.php';
    return true;
}

if ($staticPath !== false && str_starts_with($staticPath, $root . DIRECTORY_SEPARATOR) && is_file($staticPath)) {
    return false;
}

// The public snapshot can reference newer production uploads that are not
// stored in Git. Preview those public images without copying server files.
$rawPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
if (preg_match('#^/uploads/[a-zA-Z0-9_./%~-]+\.(?:jpe?g|png|webp|gif)$#i', $rawPath) === 1
    && strpos($rawPath, '..') === false
    && strpos($path, '..') === false
    && strpos($rawPath, '//') === false
    && in_array((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD'], true)) {
    header('Location: https://mgoutlet.pl' . $rawPath, true, 302);
    return true;
}

if (in_array($path, ['/sklep/shop.css', '/sklep/shop.js'], true)) {
    $extension = pathinfo($path, PATHINFO_EXTENSION);
    header('Content-Type: ' . ($extension === 'css' ? 'text/css' : 'application/javascript') . '; charset=utf-8');
    readfile($root . DIRECTORY_SEPARATOR . 'shop-test' . DIRECTORY_SEPARATOR . 'shop.' . $extension);
    return true;
}

$exactRoutes = [
    '/' => 'homepage.php',
    '/dom' => 'home.php',
    '/ogrod' => 'garden.php',
    '/sklep/figury-ogrodowe' => 'shop-test/figures.php',
    '/sklep/figury-ogrodowe/koszyk' => 'shop-test/cart.php',
    '/sklep/figury-ogrodowe/zamowienie' => 'shop-test/checkout.php',
    '/sklep/figury-ogrodowe/potwierdzenie' => 'shop-test/confirmation.php',
    '/sklep/figury-ogrodowe/regulamin' => 'shop-test/terms.php',
    '/sklep/figury-ogrodowe/dostawa-i-platnosci' => 'shop-test/delivery.php',
    '/sklep/figury-ogrodowe/zwroty-i-reklamacje' => 'shop-test/returns.php',
    '/sklep/figury-ogrodowe/formularz-odstapienia' => 'shop-test/withdrawal-form.php',
    '/sklep/figury-ogrodowe/polityka-prywatnosci' => 'shop-test/privacy.php',
];

if (isset($exactRoutes[$path])) {
    // Local-only reproducible card comparison; never copied into publish.
    if ($path === '/' && isset($_GET['preview-products'])) {
        ob_start();
        require $root . DIRECTORY_SEPARATOR . 'homepage.php';
        $page = (string)ob_get_clean();
        $requested = explode(',', (string)$_GET['preview-products']);
        $bySlug = array_column($homepageProducts, null, '_publicSlug');
        $pinned = [];
        foreach (array_unique($requested) as $slug) {
            if (isset($bySlug[$slug]) && ($bySlug[$slug]['featured'] ?? null) !== false) {
                $pinned[] = $bySlug[$slug];
            }
        }
        if (count($pinned) !== 6 || !array_filter($pinned, 'catalog_is_active_figure_shop_product')) {
            http_response_code(400);
            echo 'Preview requires six eligible products including an active figure.';
            return true;
        }
        $cards = str_replace('\\n', "\n", implode('', array_map('homepage_card', $pinned)));
        $page = preg_replace('/<!-- STATIC_PRODUCTS_START -->.*<!-- STATIC_PRODUCTS_END -->/s', '<!-- STATIC_PRODUCTS_START -->' . $cards . '<!-- STATIC_PRODUCTS_END -->', $page, 1);
        $slugs = catalog_e((string)json_encode(array_column($pinned, '_publicSlug')));
        $page = preg_replace('/data-homepage-selected-slugs="[^"]*"/', 'data-homepage-selected-slugs="' . $slugs . '"', $page, 1);
        if (isset($_GET['preview-nojs'])) {
            $page = preg_replace('#<script src="/script\.js[^\"]*"></script>#', '', $page);
        }
        echo $page;
        return true;
    }
    require $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $exactRoutes[$path]);
    return true;
}

if (preg_match('#^/produkt/([a-z0-9-]+)/?$#', $path, $match) === 1) {
    $_GET['slug'] = $match[1];
    require $root . DIRECTORY_SEPARATOR . 'product.php';
    return true;
}

if (preg_match('#^/sklep/figury-ogrodowe/produkt/([a-z0-9-]+)/?$#', $path, $match) === 1) {
    $_GET['slug'] = $match[1];
    require $root . DIRECTORY_SEPARATOR . 'shop-test' . DIRECTORY_SEPARATOR . 'figure-product.php';
    return true;
}

http_response_code(404);
require $root . DIRECTORY_SEPARATOR . '404.html';
return true;
