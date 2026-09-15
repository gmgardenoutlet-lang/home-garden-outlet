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
$productPage = (string)file_get_contents($root . '/hosting/getspace/product.php');
$figureProductPage = (string)file_get_contents($root . '/hosting/getspace/shop-test/figure-product.php');
$homepage = (string)file_get_contents($root . '/hosting/getspace/homepage.php');
$siteScript = (string)file_get_contents($root . '/script.js');
$publicProductsEndpoint = (string)file_get_contents($root . '/hosting/getspace/products-public.php');
$catalogHelpers = (string)file_get_contents($root . '/hosting/getspace/catalog.php');
$terms = (string)file_get_contents($root . '/hosting/getspace/shop-test/terms.php');
$privacy = (string)file_get_contents($root . '/hosting/getspace/shop-test/privacy.php');
$buildScript = (string)file_get_contents($root . '/scripts/build-getspace.mjs');
$prerenderScript = (string)file_get_contents($root . '/scripts/prerender-products.mjs');
$errorPage = (string)file_get_contents($root . '/404.html');
$htaccess = (string)file_get_contents($root . '/hosting/getspace/.htaccess');

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
frontend_assert(!str_contains($checkout, 'Płatności online zostaną uruchomione po publicznym starcie sklepu'), 'Checkout nadal pokazuje nieaktualną zapowiedź startu płatności.');
frontend_assert(str_contains($checkout, 'przejdziesz do Paynow albo otrzymasz dane do przelewu tradycyjnego'), 'Checkout nie opisuje aktualnego przebiegu płatności.');
frontend_assert(!str_contains(strtolower($checkout), 'visa') && !str_contains(strtolower($checkout), 'mastercard') && !str_contains(strtolower($checkout), 'google pay') && !str_contains(strtolower($checkout), 'apple pay'), 'Checkout eksponuje niedozwolone metody kartowe.');
frontend_assert(str_contains($checkout, 'Podsumowanie zamówienia'), 'Checkout nie ma jednoznacznego przycisku finalizacji.');
frontend_assert(str_contains($checkout, 'data-delivery-options'), 'Checkout nie zawiera wyboru dostawy.');
frontend_assert(str_contains($javascript, 'deliverySelected'), 'Frontend nie wymaga świadomego wyboru dostawy.');
frontend_assert(str_contains($javascript, 'Koszt wymaga indywidualnego potwierdzenia'), 'Frontend nie komunikuje wyceny indywidualnej.');
frontend_assert(str_contains($javascript, 'data-products-total') && str_contains($javascript, 'data-delivery-total'), 'Frontend nie rozbija podsumowania na produkty i dostawę.');
frontend_assert(str_contains($javascript, 'unitShipping * item.quantity') && str_contains($javascript, ' × ${formatter.format(unitShipping)} = ${formatter.format(unitShipping * item.quantity)}'), 'Frontend nie pokazuje ceny jednostkowej i sumy dostawy pozycji.');
frontend_assert(str_contains($order, 'shop_test_resolve_item_delivery'), 'Backend nie rozlicza dostawy per pozycja.');
frontend_assert(str_contains($checkout, 'FOREIGN_SHIPPING_ENABLED') && str_contains($checkout, 'data-checkout-country'), 'Checkout nie ma przełączanego wybierania kraju.');
frontend_assert(str_contains($javascript, 'Złóż zamówienie do wyceny') && str_contains($javascript, 'Do indywidualnej wyceny'), 'Frontend nie obsługuje wyceny zagranicznej.');
frontend_assert(str_contains($checkout, 'phone-prefix-static') && str_contains($checkout, 'if (FOREIGN_SHIPPING_ENABLED)'), 'Telefon nie ogranicza prefiksu w trybie PL-only.');
frontend_assert(str_contains($checkout, 'checkout-static-field') && str_contains($checkout, 'name="delivery_country" value="PL"'), 'Kraj nie jest statyczny w trybie PL-only.');
frontend_assert(str_contains($order, "'paymentMethod' => \$paymentMethod") && str_contains($order, "'shippingTotalCents' => \$shippingTotalCents"), 'Backend nie wymusza modelu płatności i dostawy dla kraju.');
frontend_assert(!str_contains($productPage, "'brand' => ['@type' => 'Brand', 'name' => 'Home & Garden Outlet']"), 'Karta mebla nadal przypisuje markę sklepu każdemu produktowi.');
frontend_assert(str_contains($productPage, 'catalog_confirmed_brand') && str_contains($productPage, "'seller' => ['@type' => 'FurnitureStore'"), 'JSON-LD mebla nie rozdziela potwierdzonej marki od sprzedawcy.');
frontend_assert(str_contains($figureProductPage, 'https://schema.org/OutOfStock') && str_contains($figureProductPage, "'seller' => ['@type' => 'FurnitureStore'"), 'JSON-LD figury nie zachowuje statusu sprzedanego produktu lub sprzedawcy.');
frontend_assert(str_contains($homepage, 'catalog_sale_price_text') && str_contains($homepage, 'Zakup online') && str_contains($homepage, 'Kup online'), 'Strona główna nie używa ceny i sposobu zakupu sklepu figur.');
frontend_assert(str_contains($siteScript, 'fetch("/products-public.php"') && str_contains($siteScript, 'getProductSalePrice(product)') && str_contains($siteScript, 'Kup online'), 'Dynamiczne karty nie używają oczyszczonego źródła, ceny sklepu lub właściwego CTA figur.');
frontend_assert(str_contains($siteScript, 'if (isFigureShopProduct(product))') && str_contains($siteScript, '"Figury i dekoracje ogrodowe"'), 'Dynamiczna karta figury nadal pokazuje lokalny model dostawy lub błędną kategorię mebli.');
frontend_assert(str_contains($publicProductsEndpoint, "array_map('catalog_public_product_record'") && str_contains($catalogHelpers, 'function catalog_public_product_fields') && str_contains($catalogHelpers, 'array_intersect_key'), 'Publiczny endpoint produktów nie korzysta z zamkniętej listy pól.');
frontend_assert(!str_contains($publicProductsEndpoint, "'internalNote'") && !str_contains($prerenderScript, 'internalNote'), 'Endpoint lub prerender odwołuje się do notatki wewnętrznej.');
frontend_assert(str_contains($htaccess, 'RewriteRule ^data/products\\.json$ - [F,L,NC]'), 'Surowy plik produktów pozostaje publicznie dostępny zamiast oczyszczonego endpointu.');
frontend_assert(str_contains($htaccess, '<Files "products.json">') && substr_count($htaccess, 'Require all denied') >= 2, 'Brakuje zapasowej blokady Apache dla products.json.');
frontend_assert(str_contains($buildScript, 'hosting", "getspace", ".htaccess"') && str_contains($buildScript, 'products-public.php'), 'Build nie zawiera blokady surowych danych lub publicznego endpointu.');
frontend_assert(str_contains($siteScript, 'product-card-static') && str_contains($siteScript, 'fallbackProducts'), 'Stary skrypt bez dostępu do JSON-a nie ma bezpiecznego fallbacku do kart statycznych.');
frontend_assert(str_contains($terms, 'Regulamin może być aktualizowany.') && !str_contains($terms, 'przed publicznym uruchomieniem sklepu online'), 'Regulamin nadal zawiera usuniętą frazę albo stracił informację o aktualizacji.');
frontend_assert(!str_contains($terms, 'src="/sklep/shop.js"') && !str_contains($privacy, 'src="/sklep/shop.js"'), 'Strona prawna ładuje niewersjonowany skrypt sklepu.');
frontend_assert(!str_contains($siteScript, 'formatGoogleReviewDate(review.updateTime || review.createTime, review.relativeTime)'), 'Opinie nadal używają historycznego czasu względnego bez daty źródłowej.');
frontend_assert(str_contains($siteScript, 'dateText ? `${dateText} · Źródło: Google` : "Źródło: Google"'), 'Opinie bez dokładnej daty nie mają uczciwego opisu źródła.');
frontend_assert(str_contains($htaccess, 'ErrorDocument 404 /404.html'), 'Apache nie ma lokalnego dokumentu błędu 404.');
frontend_assert(str_contains($errorPage, 'Błąd 404') && str_contains($errorPage, 'href="/dom"') && str_contains($errorPage, 'href="/ogrod"') && str_contains($errorPage, 'href="/sklep/figury-ogrodowe"') && str_contains($errorPage, 'href="/#kontakt"'), 'Strona 404 nie zawiera wymaganych polskich odnośników.');

echo 'PASS: shop frontend ' . ($expectedSales ? 'enabled' : 'disabled') . " tests\n";
