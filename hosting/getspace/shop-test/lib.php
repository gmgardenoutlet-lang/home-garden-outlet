<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../admin/lib.php';
require_once __DIR__ . '/../catalog.php';

function shop_new_confirmation_token(): string
{
    // 32 random bytes = 256 bits. Base64url keeps the value safe in a query string.
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function shop_confirmation_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function shop_confirmation_url(string $orderId, string $token): string
{
    return '/sklep/figury-ogrodowe/potwierdzenie?order=' . rawurlencode(shop_safe_order_id($orderId))
        . '&token=' . rawurlencode($token);
}

function shop_public_confirmation_order(string $orderId, string $token): ?array
{
    $orderId = shop_safe_order_id($orderId);
    if ($orderId === '' || $token === '') {
        return null;
    }

    $order = shop_load_order($orderId);
    $storedHash = is_array($order) ? (string)($order['confirmationTokenHash'] ?? '') : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $storedHash)) {
        return null;
    }

    return hash_equals($storedHash, shop_confirmation_token_hash($token)) ? $order : null;
}

function shop_test_boot(): void
{
    boot_admin(false);
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
    return catalog_is_active_figure_shop_product($merged);
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

function shop_test_find_figure_product(string $slug): ?array
{
    $slug = clean_filename($slug);
    foreach ((load_catalog()['products'] ?? []) as $index => $product) {
        if (!is_array($product)) {
            continue;
        }

        $product = array_merge(product_defaults(), $product);
        if (!catalog_is_figure_shop_product($product) || shop_test_slug($product) !== $slug) {
            continue;
        }

        $product['_shopIndex'] = $index;
        $product['_shopSlug'] = $slug;
        return $product;
    }

    return null;
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

function shop_test_has_shipping_method(array $product): bool
{
    foreach (shop_test_delivery_methods($product) as $method) {
        if (($method['type'] ?? '') !== 'odbior_osobisty') {
            return true;
        }
    }
    return false;
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
        'canBuy' => shop_test_is_figure($product) && $price !== null,
        'image' => $images[0],
        'alt' => trim((string)($product['imageAlt'] ?? '')) !== ''
            ? trim((string)$product['imageAlt'])
            : $name . ' dostępna w Home & Garden Outlet',
        'shortDescription' => trim((string)($product['description'] ?? '')),
        'leadTime' => trim((string)($product['leadTime'] ?? '2-5 dni roboczych')),
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
    echo '<link rel="stylesheet" href="/styles.css?v=20260811-footer1">' . PHP_EOL;
    echo '  <link rel="stylesheet" href="/sklep/shop.css?v=20260811-gallery1">' . PHP_EOL;
}

function shop_test_header(string $active = ''): void
{
    $links = [
        ['href' => '/', 'label' => 'Strona główna', 'key' => 'home'],
        ['href' => '/dom', 'label' => 'Dom', 'key' => 'dom'],
        ['href' => '/ogrod', 'label' => 'Ogród', 'key' => 'ogrod'],
        ['href' => shop_catalog_url(), 'label' => 'Figury i dekoracje', 'key' => 'figures'],
        ['href' => '/#nowosci', 'label' => 'Produkty', 'key' => 'products'],
        ['href' => '/poradnik/', 'label' => 'Poradnik', 'key' => 'guide'],
        ['href' => '/#kontakt', 'label' => 'Kontakt', 'key' => 'contact'],
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
<footer class="site-footer">
    <div class="footer-main">
      <div><strong>Home &amp; Garden Outlet</strong><p>Outlet mebli domowych i ogrodowych pod Wrocławiem.</p></div>
      <div><span>Kontakt</span><a href="tel:+48577210777">577 210 777</a><a href="mailto:biuro@mgoutlet.pl">biuro@mgoutlet.pl</a><a href="/#kontakt">ul. Przelotowa 16, 55-080 Kębłowice</a></div>
      <div><span>Godziny otwarcia</span><p>Poniedziałek: nieczynne</p><p>Wtorek: 10:00-16:00</p><p>Środa-piątek: 10:00-18:00</p><p>Sobota-niedziela: 10:00-14:00</p></div>
      <div><span>Social media</span><a href="https://www.facebook.com/mgoutletpl/?locale=pl_PL" target="_blank" rel="noopener">Facebook</a><a href="https://www.instagram.com/_mygardenoutlet_/" target="_blank" rel="noopener">Instagram</a><a href="https://www.tiktok.com/@my_garden_outlet" target="_blank" rel="noopener">TikTok</a></div>
      <div class="footer-shortcuts"><span>Na skróty</span><div class="footer-shortcuts-columns"><div><a href="/outlet-meblowy-wroclaw/">Outlet meblowy Wrocław</a><a href="/meble-ogrodowe-wroclaw/">Meble ogrodowe Wrocław</a><a href="/dom">Meble do domu outlet</a><a href="/ogrod">Meble ogrodowe</a><a href="/poradnik/">Poradnik</a><a href="/sklep/figury-ogrodowe">Katalog figur</a><a href="/#faq-home-title">FAQ</a></div><div><a href="/polityka-prywatnosci">Polityka prywatności</a><a href="/sklep/figury-ogrodowe/regulamin">Regulamin</a><a href="/sklep/figury-ogrodowe/dostawa-i-platnosci">Dostawa i płatności – sklep z figurami</a><a href="/sklep/figury-ogrodowe/zwroty-i-reklamacje">Zwroty i reklamacje</a><a href="/sklep/figury-ogrodowe/formularz-odstapienia">Formularz odstąpienia</a></div></div></div>
    </div>
    <div class="footer-bottom"><p>© 2026 Home &amp; Garden Outlet. Wszystkie prawa zastrzeżone.</p><div class="footer-payments" aria-label="Dostępne metody płatności"><span class="footer-payments-label">Płatności:</span><span class="footer-payment-badge">Paynow</span><span class="footer-payment-badge">BLIK</span><span class="footer-payment-badge">Przelew tradycyjny</span><span class="footer-security"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Bezpieczne zakupy</span></div></div>
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

function shop_test_resolve_item_delivery(array $item): array
{
    $methods = shop_test_delivery_methods($item['product']);
    $profileId = shop_test_delivery_key((string)($item['shippingProfileId'] ?? ''));
    if ($profileId === '' && count($methods) === 1) {
        $profileId = (string)array_key_first($methods);
    }
    if ($profileId === '' || !isset($methods[$profileId])) {
        throw new RuntimeException('Wybierz prawidłowy sposób dostawy dla każdego produktu.');
    }

    $method = $methods[$profileId];
    $requiresConfirmation = !empty($method['requiresConfirmation']) || ($method['pricingType'] ?? '') === 'quote_required';
    $unitCents = $requiresConfirmation || ($method['costNumber'] ?? null) === null
        ? null
        : shop_test_price_cents((float)$method['costNumber']);
    $quantity = (int)$item['quantity'];

    return [
        'shippingProfileId' => $profileId,
        'shippingName' => (string)$method['label'],
        'shippingUnitCents' => $unitCents,
        'shippingLineCents' => $unitCents === null ? null : $unitCents * $quantity,
        'shippingRequiresConfirmation' => $requiresConfirmation,
        'shippingCostLabel' => $requiresConfirmation
            ? 'Koszt wymaga indywidualnego potwierdzenia'
            : ($unitCents === 0 ? 'Bezpłatnie' : shop_test_price_label(shop_test_cents_to_price($unitCents))),
    ];
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
            'shippingProfileId' => shop_test_delivery_key((string)($row['shippingProfileId'] ?? '')),
        ];
    }

    $items = array_values($itemsBySlug);
    if (!$items) {
        throw new RuntimeException('Koszyk jest pusty albo zawiera produkty bez ceny.');
    }

    return [
        'items' => $items,
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
        'countryCode' => $country,
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

function shop_test_order_country_code(array $order): string
{
    $country = strtoupper(trim((string) ($order['countryCode'] ?? (($order['deliveryAddress'] ?? [])['country'] ?? 'PL'))));
    return array_key_exists($country, SHOP_ALLOWED_COUNTRIES) ? $country : 'PL';
}

function shop_test_effective_country_code(string $submittedCountryCode, ?bool $foreignShippingEnabled = null): string
{
    $foreignShippingEnabled ??= FOREIGN_SHIPPING_ENABLED;
    if (!$foreignShippingEnabled) {
        return 'PL';
    }
    $countryCode = strtoupper(trim($submittedCountryCode));
    return isset(SHOP_ALLOWED_COUNTRIES[$countryCode]) ? $countryCode : '';
}

function shop_test_normalize_phone_for_country(string $phone, string $countryCode): ?string
{
    $countryCode = strtoupper($countryCode);
    if (!isset(SHOP_ALLOWED_COUNTRIES[$countryCode])) {
        return null;
    }
    $phone = trim($phone);
    if ($countryCode === 'PL') {
        $compact = preg_replace('/[\s\-()]+/', '', $phone) ?? '';
        if (str_starts_with($compact, '+48')) {
            $compact = substr($compact, 3);
        } elseif (str_starts_with($compact, '0048')) {
            $compact = substr($compact, 4);
        }
        return ctype_digit($compact) && strlen($compact) === 9 ? '+48' . $compact : null;
    }
    $compact = preg_replace('/[\s\-()]+/', '', $phone) ?? '';
    $callingCode = SHOP_ALLOWED_COUNTRIES[$countryCode]['callingCode'];
    return preg_match('/^\+' . preg_quote($callingCode, '/') . '[0-9]{4,12}$/', $compact) === 1 && strlen(substr($compact, 1)) <= 15
        ? $compact : null;
}

function shop_test_normalize_postal_code_for_country(string $postalCode, string $countryCode): ?string
{
    $postalCode = trim($postalCode);
    if ($countryCode === 'PL') {
        return preg_match('/^(\d{2})-?(\d{3})$/', $postalCode, $matches) === 1 ? $matches[1] . '-' . $matches[2] : null;
    }
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]{0,18}[A-Za-z0-9]$/', $postalCode) === 1 ? $postalCode : null;
}

function shop_test_validate_checkout_customer_input(): void
{
    $errors = [];
    $email = shop_test_text_field('customer_email', 160);
    $phonePrefix = trim((string) ($_POST['phone_prefix'] ?? ''));
    $phoneNumber = trim((string) ($_POST['phone_number'] ?? $_POST['customer_phone'] ?? ''));
    $postalCode = shop_test_text_field('delivery_postal_code', 20);
    $countryCode = shop_test_effective_country_code(shop_test_text_field('delivery_country', 2));

    if ($countryCode === '') {
        $errors['delivery_country'] = 'Wybierz prawidłowy kraj dostawy.';
    }
    $_POST['delivery_country'] = $countryCode === '' ? 'PL' : $countryCode;

    if ($email === '') {
        $errors['customer_email'] = 'Podaj adres e-mail.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['customer_email'] = 'Podaj prawidłowy adres e-mail, np. jan@example.pl.';
    }

    $usesPhoneParts = array_key_exists('phone_prefix', $_POST) || array_key_exists('phone_number', $_POST);
    if ($phonePrefix === '' && !$usesPhoneParts) {
        $phonePrefix = '+' . (SHOP_ALLOWED_COUNTRIES[$countryCode]['callingCode'] ?? '48');
    }
    if (!$usesPhoneParts) {
        $normalizedPhone = shop_test_normalize_phone_for_country($phoneNumber, $countryCode);
        if ($phoneNumber === '') {
            $errors['customer_phone'] = 'Podaj numer telefonu.';
        } elseif ($normalizedPhone === null) {
            $errors['customer_phone'] = 'Podaj prawidłowy numer telefonu.';
        } else {
            $_POST['customer_phone'] = $normalizedPhone;
        }
    } elseif ($phonePrefix === '') {
        $errors['customer_phone'] = 'Wybierz kod kierunkowy.';
    } elseif (!isset(SHOP_ALLOWED_COUNTRIES[$countryCode]) || $phonePrefix !== '+' . SHOP_ALLOWED_COUNTRIES[$countryCode]['callingCode']) {
        $errors['customer_phone'] = 'Kod kierunkowy telefonu nie odpowiada wybranemu krajowi.';
    } elseif ($phoneNumber === '') {
        $errors['customer_phone'] = 'Podaj numer telefonu.';
    } else {
        $normalizedPhone = shop_test_normalize_phone_for_country($phonePrefix . $phoneNumber, $countryCode);
        if ($normalizedPhone === null) {
            $errors['customer_phone'] = $countryCode !== 'PL' && isset(SHOP_ALLOWED_COUNTRIES[$countryCode])
                ? 'Podaj numer telefonu z kodem kraju, np. +' . SHOP_ALLOWED_COUNTRIES[$countryCode]['callingCode'] . ' …'
                : 'Podaj prawidłowy numer telefonu.';
        } else {
            $_POST['customer_phone'] = $normalizedPhone;
        }
    }

    if ($postalCode === '') {
        $errors['delivery_postal_code'] = 'Podaj kod pocztowy.';
    } elseif (($normalizedPostalCode = shop_test_normalize_postal_code_for_country($postalCode, $countryCode)) === null) {
        $errors['delivery_postal_code'] = $countryCode === 'PL' ? 'Podaj kod pocztowy w formacie 00-000.' : 'Podaj prawidłowy kod pocztowy.';
    } else {
        $_POST['delivery_postal_code'] = $normalizedPostalCode;
    }

    if ($errors !== []) {
        throw new ShopCheckoutValidationException($errors, $_POST);
    }
    $_POST['customer_email'] = $email;
    $_POST['delivery_country'] = $countryCode;
}

function shop_test_require_terms(): void
{
    if (empty($_POST['terms'])) {
        throw new RuntimeException('Aby złożyć zamówienie, zaakceptuj Regulamin sklepu.');
    }
}

final class ShopCheckoutValidationException extends RuntimeException
{
    public function __construct(public array $errors, public array $oldInput)
    {
        parent::__construct('Formularz wymaga poprawy.');
    }
}

function shop_test_checkout_old_input(): array
{
    $old = $_SESSION['checkout_old_input'] ?? [];
    unset($_SESSION['checkout_old_input']);
    return is_array($old) ? $old : [];
}

function shop_test_checkout_errors(): array
{
    $errors = $_SESSION['checkout_errors'] ?? [];
    unset($_SESSION['checkout_errors']);
    return is_array($errors) ? $errors : [];
}

function shop_test_checkout_remember_validation_error(array $errors, array $input): void
{
    $_SESSION['checkout_errors'] = $errors;
    $oldInput = array_intersect_key($input, array_flip(['customer_first_name', 'customer_last_name', 'customer_email', 'customer_phone', 'phone_prefix', 'phone_number', 'delivery_street', 'delivery_postal_code', 'delivery_city', 'delivery_country', 'invoice_requested', 'invoice_company_name', 'invoice_nip', 'invoice_address', 'customer_notes', 'payment_method', 'terms']));
    $cart = json_decode((string) ($input['cart_payload'] ?? ''), true);
    if (is_array($cart) && is_array($cart['items'] ?? null)) {
        foreach ($cart['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $slug = trim((string) ($item['slug'] ?? ''));
            $shippingProfileId = trim((string) ($item['shippingProfileId'] ?? ''));
            if ($slug !== '' && $shippingProfileId !== '') {
                $oldInput['shipping_selections'][$slug] = $shippingProfileId;
            }
        }
    }
    $_SESSION['checkout_old_input'] = $oldInput;
}

function shop_test_checkout_payment_method(array $oldInput): string
{
    $method = (string)($oldInput['payment_method'] ?? 'bank_transfer');
    return in_array($method, ['bank_transfer', 'paynow'], true) && !empty(shop_payment_methods()[$method]) ? $method : 'bank_transfer';
}

function shop_test_text_field(string $key, int $maxLength = 300): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}

function shop_test_checkout_submission_token(): string
{
    if (empty($_SESSION['checkout_submission_token'])) {
        $_SESSION['checkout_submission_token'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['checkout_submission_token'];
}

function shop_test_checkout_existing_order(string $token): string
{
    $last = $_SESSION['checkout_last_submission'] ?? [];
    if (!is_array($last) || !is_string($last['token'] ?? null) || !is_string($last['orderId'] ?? null)) {
        return '';
    }
    return $token !== '' && hash_equals($last['token'], $token) ? shop_safe_order_id($last['orderId']) : '';
}

function shop_test_checkout_existing_confirmation_token(string $token): string
{
    $last = $_SESSION['checkout_last_submission'] ?? [];
    if (!is_array($last) || !is_string($last['token'] ?? null) || !is_string($last['confirmationToken'] ?? null)) {
        return '';
    }
    return $token !== '' && hash_equals($last['token'], $token) ? $last['confirmationToken'] : '';
}

function shop_test_remember_checkout_order(string $token, string $orderId, string $confirmationToken = ''): void
{
    $_SESSION['checkout_last_submission'] = [
        'token' => $token,
        'orderId' => shop_safe_order_id($orderId),
        'confirmationToken' => $confirmationToken,
    ];
    unset($_SESSION['checkout_submission_token']);
}
