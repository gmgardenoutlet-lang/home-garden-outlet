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
$order = (string)file_get_contents($root . '/hosting/getspace/shop-test/order.php');
$summary = (string)file_get_contents($root . '/hosting/getspace/shop-test/checkout-summary.php');
$summarySubmit = (string)file_get_contents($root . '/hosting/getspace/shop-test/checkout-summary-submit.php');

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
frontend_assert(str_contains($checkout, 'value="paynow"'), 'Checkout nie zawiera opcji Paynow.');
frontend_assert(!str_contains(strtolower($checkout), 'visa') && !str_contains(strtolower($checkout), 'mastercard') && !str_contains(strtolower($checkout), 'google pay') && !str_contains(strtolower($checkout), 'apple pay'), 'Checkout eksponuje niedozwolone metody kartowe.');
frontend_assert(str_contains($checkout, 'Przejdź do podsumowania'), 'Checkout nie ma przejścia do podsumowania.');
frontend_assert(str_contains($checkout, 'data-delivery-options'), 'Checkout nie zawiera wyboru dostawy.');
frontend_assert(str_contains($javascript, 'deliverySelected'), 'Frontend nie wymaga świadomego wyboru dostawy.');
frontend_assert(str_contains($javascript, 'Koszt wymaga indywidualnego potwierdzenia'), 'Frontend nie komunikuje wyceny indywidualnej.');
frontend_assert(str_contains($javascript, 'data-products-total') && str_contains($javascript, 'data-delivery-total'), 'Frontend nie rozbija podsumowania na produkty i dostawę.');
frontend_assert(str_contains($order, 'shop_test_resolve_item_delivery'), 'Backend nie rozlicza dostawy per pozycja.');
frontend_assert(str_contains($checkout, 'Przejdź do podsumowania') && str_contains($summary, 'Podsumowanie zamówienia'), 'Checkout nie kieruje do podsumowania.');
frontend_assert(str_contains($summarySubmit, 'checkout_summary_draft') && str_contains($order, 'finalize_checkout'), 'Draft podsumowania nie jest finalizowany chronionym POST-em.');
frontend_assert(str_contains($checkout, 'FOREIGN_SHIPPING_ENABLED') && str_contains($checkout, 'data-checkout-country'), 'Checkout nie ma przełączanego wybierania kraju.');
frontend_assert(str_contains($javascript, 'Złóż zamówienie do wyceny') && str_contains($javascript, 'Do indywidualnej wyceny'), 'Frontend nie obsługuje wyceny zagranicznej.');
frontend_assert(str_contains($checkout, 'phone-prefix-static') && str_contains($checkout, 'if (FOREIGN_SHIPPING_ENABLED)'), 'Telefon nie ogranicza prefiksu w trybie PL-only.');
frontend_assert(str_contains($checkout, 'checkout-static-field') && str_contains($checkout, 'name="delivery_country" value="PL"'), 'Kraj nie jest statyczny w trybie PL-only.');
frontend_assert(str_contains($order, "'paymentMethod' => \$paymentMethod") && str_contains($order, "'shippingTotalCents' => \$shippingTotalCents"), 'Backend nie wymusza modelu płatności i dostawy dla kraju.');

echo 'PASS: shop frontend ' . ($expectedSales ? 'enabled' : 'disabled') . " tests\n";
