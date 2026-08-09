<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../admin/lib.php';

function shop_test_boot(): void
{
    boot_admin();
    header('X-Robots-Tag: index, follow');
}

function shop_sales_enabled(): bool
{
    return SHOP_SALES_ENABLED;
}

function shop_catalog_url(): string
{
    return '/sklep/figury-ogrodowe';
}

function shop_test_purchase_unavailable(): void
{
    http_response_code(403);
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, max-age=0');

    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($accept, 'application/json')) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'error' => 'shop_sales_disabled',
            'message' => 'Sprzedaż online nie została jeszcze uruchomiona.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    ?><!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Sprzedaż online wkrótce | Home &amp; Garden Outlet</title>
  <?php shop_test_stylesheets(); ?>
</head>
<body>
  <?php shop_test_header(); ?>
  <main class="order-result">
    <section class="success-box error-box">
      <p class="eyebrow">Sprzedaż online wkrótce</p>
      <h1>Sprzedaż online nie została jeszcze uruchomiona.</h1>
      <p>Trwają ostatnie prace nad uruchomieniem sklepu internetowego. Obecnie możesz przeglądać ofertę.</p>
      <div class="shop-actions"><a class="btn" href="<?= e(shop_catalog_url()) ?>">Zobacz figury ogrodowe</a></div>
    </section>
  </main>
  <?php shop_test_footer(); ?>
</body>
</html><?php
    exit;
}

function shop_test_require_sales(): void
{
    if (!shop_sales_enabled()) {
        shop_test_purchase_unavailable();
    }
}

function shop_test_image_url(string $path): string
{
    $path = trim($path);
    if ($path === '' || str_contains($path, '..')) {
        return '/product-table.jpeg';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return str_starts_with($path, '/') ? $path : '/' . $path;
}

function shop_test_gallery(array $product): array
{
    $paths = [$product['image'] ?? ''];
    foreach (($product['gallery'] ?? []) as $item) {
        $paths[] = is_array($item) ? ($item['image'] ?? '') : $item;
    }

    $result = [];
    foreach ($paths as $path) {
        $path = trim((string)$path);
        if ($path !== '' && !str_contains($path, '..')) {
            $result[] = shop_test_image_url($path);
        }
    }

    return array_values(array_unique($result ?: ['/product-table.jpeg']));
}

function shop_test_slug(array $product): string
{
    $source = trim((string)($product['slug'] ?? '')) !== ''
        ? (string)$product['slug']
        : (string)($product['name'] ?? 'figura');
    return clean_filename($source);
}

function shop_test_delivery_key(string $value): string
{
    $value = strtolower(trim($value));
    return preg_replace('/[^a-z0-9_-]/', '', $value) ?: '';
}

function shop_test_price_number($value): ?float
{
    $cleaned = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], (string)$value);
    if (!preg_match('/\d+(?:\.\d+)?/', $cleaned, $matches)) {
        return null;
    }
    $price = (float)$matches[0];
    return $price > 0 ? $price : null;
}

function shop_test_price_label(?float $price): string
{
    return $price === null ? 'Zapytaj o cenę' : number_format($price, 2, ',', ' ') . ' zł';
}

function shop_test_price_cents(?float $price): ?int
{
    return $price === null ? null : (int)round($price * 100);
}

function shop_test_cents_to_price(int $cents): float
{
    return $cents / 100;
}

function shop_test_is_figure(array $product): bool
{
    $merged = array_merge(product_defaults(), $product);
    return ($merged['saleType'] ?? '') === 'garden_figure'
        && !empty($merged['shopVisible'])
        && ($merged['shopStatus'] ?? '') === 'Dostępny'
        && !in_array((string)($merged['productStatus'] ?? ''), ['Sprzedany', 'Ukryty'], true)
        && !in_array((string)($merged['status'] ?? ''), ['Sprzedane', 'Sprzedany'], true);
}

function shop_test_products(): array
{
    $catalog = load_catalog();
    $result = [];
    foreach (($catalog['products'] ?? []) as $index => $product) {
        if (!is_array($product)) {
            continue;
        }
        $product = array_merge(product_defaults(), $product);
        if (!shop_test_is_figure($product)) {
            continue;
        }
        $product['_shopIndex'] = $index;
        $product['_shopSlug'] = shop_test_slug($product);
        $result[] = $product;
    }

    usort($result, static function (array $a, array $b): int {
        return ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0))
            ?: strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    return $result;
}

function shop_test_product_map(): array
{
    $map = [];
    foreach (shop_test_products() as $product) {
        $map[(string)$product['_shopSlug']] = $product;
    }
    return $map;
}

function shop_test_find_product(string $slug): ?array
{
    $slug = clean_filename($slug);
    $products = shop_test_product_map();
    return $products[$slug] ?? null;
}

function shop_test_delivery_methods(array $product): array
{
    $profiles = shipping_profiles_by_id_for_shop(true);
    $methods = [];

    foreach (product_shipping_profile_ids($product) as $profileId) {
        $profileId = shop_test_delivery_key($profileId);
        if (!isset($profiles[$profileId])) {
            continue;
        }
        $method = shipping_profile_public($profiles[$profileId]);
        $method['pricingType'] = $method['costNumber'] === null || !empty($method['requiresConfirmation'])
            ? 'quote_required'
            : 'fixed_price';
        $methods[$profileId] = $method;
    }

    return $methods;
}

function shop_test_individual_delivery(): array
{
    $profiles = shipping_profiles_by_id_for_shop(false);
    if (!isset($profiles['dostawa-indywidualna'])) {
        return [];
    }
    $method = shipping_profile_public($profiles['dostawa-indywidualna']);
    $method['pricingType'] = 'quote_required';
    return $method;
}

function shop_test_public_product(array $product): array
{
    $price = shop_test_price_number($product['grossPrice'] ?? '');
    $slug = (string)($product['_shopSlug'] ?? shop_test_slug($product));
    $name = trim((string)($product['name'] ?? 'Figura ogrodowa'));
    $images = shop_test_gallery($product);

    return [
        'slug' => $slug,
        'name' => $name,
        'sku' => trim((string)($product['sku'] ?? '')),
        'price' => $price,
        'priceLabel' => shop_test_price_label($price),
        'canBuy' => $price !== null,
        'image' => $images[0],
        'alt' => trim((string)($product['imageAlt'] ?? '')) !== ''
            ? trim((string)$product['imageAlt'])
            : $name . ' dostępna w Home & Garden Outlet',
        'shortDescription' => trim((string)($product['description'] ?? '')),
        'leadTime' => trim((string)($product['leadTime'] ?? '2-5 dni roboczych')),
        'availability' => trim((string)($product['producerAvailability'] ?? 'Dostępny u producenta')),
        'deliveryMethods' => array_values(shop_test_delivery_methods($product)),
    ];
}

function shop_test_public_products(): array
{
    return array_map('shop_test_public_product', shop_test_products());
}

function shop_test_product_url(string $slug): string
{
    return shop_catalog_url() . '/produkt/' . rawurlencode(clean_filename($slug));
}

function shop_test_stylesheets(): void
{
    echo '<link rel="stylesheet" href="/styles.css">' . PHP_EOL;
    echo '  <link rel="stylesheet" href="/sklep/shop.css">' . PHP_EOL;
}

function shop_test_header(string $active = ''): void
{
    $links = [
        ['href' => '/', 'label' => 'Strona główna', 'key' => 'home'],
        ['href' => '/dom', 'label' => 'Dom', 'key' => 'dom'],
        ['href' => '/ogrod', 'label' => 'Ogród', 'key' => 'ogrod'],
        ['href' => '/#faq-home-title', 'label' => 'FAQ', 'key' => 'faq'],
        ['href' => shop_catalog_url(), 'label' => 'Sklep online', 'key' => 'figures'],
    ];
    ?>
  <header class="site-header shop-site-header">
    <a class="logo" href="/" aria-label="Home & Garden Outlet - strona główna">
      <img src="/logo-optimized.jpg" width="64" height="64" alt="Home & Garden Outlet - meble do domu i ogrodu">
    </a>

    <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="main-menu" aria-label="Otwórz menu">
      <span></span>
      <span></span>
      <span></span>
    </button>

    <nav id="main-menu" class="main-nav shop-main-nav" aria-label="Menu sklepu">
      <?php foreach ($links as $link): ?>
        <a href="<?= e($link['href']) ?>"<?= $active === $link['key'] ? ' aria-current="page"' : '' ?>><?= $link['label'] ?></a>
      <?php endforeach; ?>
      <?php if (shop_sales_enabled()): ?>
        <a href="<?= e(shop_catalog_url()) ?>/koszyk"<?= $active === 'cart' ? ' aria-current="page"' : '' ?>>Koszyk <span data-cart-count aria-live="polite"></span></a>
      <?php endif; ?>
    </nav>
  </header>
    <?php
}

function shop_test_footer(): void
{
    ?>
  <footer class="site-footer shop-site-footer">
    <div>
      <strong>Home &amp; Garden Outlet</strong>
      <p>ul. Przelotowa 16<br>55-080 Kębłowice</p>
      <p><a href="tel:+48577210777">577 210 777</a><a href="mailto:biuro@mgoutlet.pl">biuro@mgoutlet.pl</a></p>
    </div>
    <div>
      <strong>Na skróty</strong>
      <a href="/">Strona główna</a>
      <a href="/dom">Dom</a>
      <a href="/ogrod">Ogród</a>
      <a href="/#kontakt">Kontakt</a>
    </div>
    <div>
      <strong>Poradnik i FAQ</strong>
      <a href="/poradnik/">Poradnik</a>
      <a href="/#faq-home-title">FAQ</a>
    </div>
    <div>
      <strong>Figury ogrodowe</strong>
      <a href="<?= e(shop_catalog_url()) ?>">Katalog figur</a>
      <a href="/sklep/figury-ogrodowe/regulamin">Regulamin</a>
      <a href="/polityka-prywatnosci">Polityka prywatności</a>
      <a href="/sklep/figury-ogrodowe/dostawa-i-platnosci">Dostawa i płatności</a>
      <a href="/sklep/figury-ogrodowe/zwroty-i-reklamacje">Zwroty i reklamacje</a>
      <a href="/sklep/figury-ogrodowe/formularz-odstapienia">Formularz odstąpienia</a>
    </div>
  </footer>
  <?php if (shop_sales_enabled()): ?>
    <aside class="cart-toast" data-cart-toast hidden aria-live="polite" aria-atomic="true"></aside>
  <?php endif; ?>
    <?php
}

function shop_test_cart_common_delivery(array $items): array
{
    $common = null;
    foreach ($items as $item) {
        $methods = shop_test_delivery_methods($item['product']);
        $common = $common === null ? $methods : array_intersect_key($common, $methods);
    }
    if ($common === null) {
        return [];
    }
    if (!$common) {
        $individual = shop_test_individual_delivery();
        if (!$individual) {
            return [];
        }
        return [
            (string)$individual['method'] => $individual,
        ];
    }
    return $common;
}

function shop_test_decode_cart(string $payload, array $products): array
{
    if (strlen($payload) > 20000) {
        throw new RuntimeException('Koszyk jest zbyt duży. Odśwież stronę i spróbuj ponownie.');
    }

    $data = json_decode($payload, true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        throw new RuntimeException('Nie udało się odczytać koszyka.');
    }

    $itemsBySlug = [];
    foreach ($data['items'] as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('Koszyk zawiera nieprawidłową pozycję.');
        }
        $slug = clean_filename((string)($row['slug'] ?? ''));
        $quantityRaw = $row['quantity'] ?? null;
        if (!is_int($quantityRaw) && (!is_string($quantityRaw) || !preg_match('/^[1-9][0-9]*$/', $quantityRaw))) {
            throw new RuntimeException('Ilość produktu musi być dodatnią liczbą całkowitą.');
        }
        $quantity = (int)$quantityRaw;
        if ($quantity < 1 || $quantity > 20) {
            throw new RuntimeException('Jednorazowo można zamówić od 1 do 20 sztuk produktu.');
        }
        if ($slug === '' || !isset($products[$slug])) {
            throw new RuntimeException('Wybrany produkt nie jest już dostępny w sprzedaży online.');
        }
        $product = $products[$slug];
        $price = shop_test_price_number($product['grossPrice'] ?? '');
        if ($price === null) {
            throw new RuntimeException('Wybrany produkt nie ma aktualnej ceny i nie może zostać zamówiony.');
        }
        if (isset($itemsBySlug[$slug])) {
            throw new RuntimeException('Ten sam produkt może wystąpić w koszyku tylko raz.');
        }
        $itemsBySlug[$slug] = [
            'product' => $product,
            'slug' => $slug,
            'quantity' => $quantity,
            'price' => $price,
            'priceCents' => shop_test_price_cents($price),
            'lineTotalCents' => (int)shop_test_price_cents($price) * $quantity,
            'lineTotal' => shop_test_cents_to_price((int)shop_test_price_cents($price) * $quantity),
        ];
    }

    $items = array_values($itemsBySlug);
    if (!$items) {
        throw new RuntimeException('Koszyk jest pusty albo zawiera produkty bez ceny.');
    }

    return [
        'items' => $items,
        'delivery' => shop_test_delivery_key((string)($data['delivery'] ?? '')),
    ];
}

function shop_test_required_post(string $key, string $label, int $maxLength = 300): string
{
    $value = shop_test_text_field($key, $maxLength);
    if ($value === '') {
        throw new RuntimeException('Uzupełnij pole: ' . $label . '.');
    }
    return $value;
}

function shop_test_customer_from_post(): array
{
    $firstName = shop_test_required_post('customer_first_name', 'imię', 80);
    $lastName = shop_test_required_post('customer_last_name', 'nazwisko', 120);
    $email = shop_test_required_post('customer_email', 'e-mail', 160);
    $phone = shop_test_required_post('customer_phone', 'telefon', 60);
    $street = shop_test_required_post('delivery_street', 'ulica i numer', 180);
    $postalCode = shop_test_required_post('delivery_postal_code', 'kod pocztowy', 20);
    $city = shop_test_required_post('delivery_city', 'miejscowość', 120);
    $country = strtoupper(shop_test_required_post('delivery_country', 'kraj', 2));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Podaj poprawny adres e-mail.');
    }
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        throw new RuntimeException('Podaj kraj w formacie dwuliterowego kodu, np. PL.');
    }

    $invoiceRequested = !empty($_POST['invoice_requested']);
    $invoice = ['requested' => $invoiceRequested];
    if ($invoiceRequested) {
        $nip = preg_replace('/\D+/', '', shop_test_required_post('invoice_nip', 'NIP', 24)) ?: '';
        if (strlen($nip) !== 10) {
            throw new RuntimeException('Podaj poprawny 10-cyfrowy NIP.');
        }
        $invoice += [
            'companyName' => shop_test_required_post('invoice_company_name', 'nazwa firmy', 180),
            'nip' => $nip,
            'address' => shop_test_text_field('invoice_address', 240),
        ];
    }

    return [
        'customer' => [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
            'phone' => $phone,
        ],
        'deliveryAddress' => [
            'street' => $street,
            'postalCode' => $postalCode,
            'city' => $city,
            'country' => $country,
        ],
        'invoice' => $invoice,
        'customerNote' => shop_test_text_field('customer_notes', 800),
    ];
}

function shop_test_require_terms(): void
{
    if (empty($_POST['terms'])) {
        throw new RuntimeException('Aby złożyć zamówienie, zaakceptuj Regulamin sklepu.');
    }
}

function shop_test_text_field(string $key, int $maxLength = 300): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}
