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
