<?php
declare(strict_types=1);

require __DIR__ . '/../hosting/getspace/shop-test/lib.php';

function frontend_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function frontend_assert(bool $condition, string $message): void
{
    if (!$condition) {
        frontend_fail($message);
    }
}

function frontend_render(string $template, array $query = []): string
{
    $code = '$_GET = ' . var_export($query, true) . '; require ' . var_export($template, true) . ';';
    $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1';
    return (string)shell_exec($command);
}

$expectedSales = ($argv[1] ?? '') === 'enabled';
frontend_assert(shop_sales_enabled() === $expectedSales, 'Nieprawidłowy stan SHOP_SALES_ENABLED dla testu.');
frontend_assert(PAYNOW_ENABLED === false, 'Paynow ma pozostać wyłączone w testach frontendu.');

$root = dirname(__DIR__);
$config = (string)file_get_contents($root . '/hosting/getspace/shop-test/config.php');
$catalog = (string)file_get_contents($root . '/hosting/getspace/shop-test/figures.php');
$product = (string)file_get_contents($root . '/hosting/getspace/shop-test/figure-product.php');
$layout = (string)file_get_contents($root . '/hosting/getspace/shop-test/lib.php');
$checkout = (string)file_get_contents($root . '/hosting/getspace/shop-test/checkout.php');
$javascript = (string)file_get_contents($root . '/hosting/getspace/shop-test/shop.js');

frontend_assert(str_contains($config, "=== 'true'"), 'Sprzedaż nie wymaga jawnej wartości true.');
frontend_assert(!str_contains($config, "?: 'true'"), 'W konfiguracji pozostał niebezpieczny fallback true.');
frontend_assert(str_contains($catalog, "shop_sales_enabled() && \$view['canBuy']"), 'Katalog nie warunkuje CTA stanem sprzedaży i canBuy.');
frontend_assert(str_contains($catalog, 'data-add-to-cart'), 'Katalog nie renderuje data-add-to-cart.');
frontend_assert(str_contains($product, "shop_sales_enabled() && \$view['canBuy']"), 'Karta produktu nie warunkuje CTA stanem sprzedaży i canBuy.');
frontend_assert(str_contains($product, 'data-add-to-cart'), 'Karta produktu nie renderuje data-add-to-cart.');
frontend_assert(str_contains($layout, 'data-cart-count'), 'Wspólny header nie ma licznika koszyka.');
frontend_assert(str_contains($layout, 'data-cart-toast'), 'Wspólny layout nie ma toastu koszyka.');
frontend_assert(str_contains($javascript, 'localStorage.setItem(storageKey'), 'Istniejący mechanizm localStorage koszyka nie jest używany.');
frontend_assert(str_contains($javascript, 'showToast(product)'), 'Dodawanie do koszyka nie wywołuje toastu.');
frontend_assert(str_contains($checkout, 'value="bank_transfer"'), 'Checkout nie zawiera przelewu tradycyjnego.');
frontend_assert(str_contains($checkout, 'Kupuję i płacę'), 'Checkout nie ma jednoznacznego przycisku finalizacji.');

$products = shop_test_public_products();
$purchasable = null;
foreach ($products as $candidate) {
    if (!empty($candidate['canBuy'])) {
        $purchasable = $candidate;
        break;
    }
}
frontend_assert(is_array($purchasable), 'Brak produktu testowego z canBuy=true.');

$catalogHtml = frontend_render($root . '/hosting/getspace/shop-test/figures.php');
$productHtml = frontend_render($root . '/hosting/getspace/shop-test/figure-product.php', ['slug' => $purchasable['slug']]);
frontend_assert(str_contains($catalogHtml, '<!DOCTYPE html>'), 'Katalog nie renderuje się jako dokument HTML.');
frontend_assert(str_contains($productHtml, '<!DOCTYPE html>'), 'Karta produktu nie renderuje się jako dokument HTML.');

if ($expectedSales) {
    frontend_assert(str_contains($catalogHtml, 'data-add-to-cart="' . $purchasable['slug'] . '"'), 'Katalog nie wyrenderował CTA dla produktu możliwego do kupienia.');
    frontend_assert(str_contains($productHtml, 'data-add-to-cart="' . $purchasable['slug'] . '"'), 'Karta produktu nie wyrenderowała CTA dla produktu możliwego do kupienia.');
    frontend_assert(str_contains($catalogHtml, 'data-cart-count'), 'W trybie sprzedaży header nie wyrenderował koszyka.');
    frontend_assert(str_contains($catalogHtml, 'data-cart-toast'), 'W trybie sprzedaży layout nie wyrenderował toastu.');
} else {
    frontend_assert(!str_contains($catalogHtml, 'data-add-to-cart'), 'Katalog wyrenderował CTA mimo wyłączonej sprzedaży.');
    frontend_assert(!str_contains($productHtml, 'data-add-to-cart'), 'Karta produktu wyrenderowała CTA mimo wyłączonej sprzedaży.');
    frontend_assert(!str_contains($catalogHtml, 'data-cart-count'), 'Header wyrenderował koszyk mimo wyłączonej sprzedaży.');
    frontend_assert(!str_contains($catalogHtml, 'data-cart-toast'), 'Layout wyrenderował toast mimo wyłączonej sprzedaży.');
}

echo 'PASS: shop frontend ' . ($expectedSales ? 'enabled' : 'disabled') . " tests\n";
