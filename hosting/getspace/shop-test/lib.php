<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/lib.php';

function shop_test_boot(): void
{
    boot_admin();
    require_login();
    header('X-Robots-Tag: noindex, nofollow, noarchive');
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
    $profiles = shipping_profiles_by_id(true);
    $methods = [];

    foreach (product_shipping_profile_ids($product) as $profileId) {
        $profileId = shop_test_delivery_key($profileId);
        if (!isset($profiles[$profileId])) {
            continue;
        }
        $methods[$profileId] = shipping_profile_public($profiles[$profileId]);
    }

    if (!$methods) {
        $fallback = $profiles['dostawa-indywidualna'] ?? [
            'id' => 'dostawa-indywidualna',
            'customerName' => 'Dostawa do ustalenia indywidualnie',
            'type' => 'do_ustalenia',
            'price' => null,
            'requiresConfirmation' => true,
            'description' => 'Skontaktujemy się po złożeniu zamówienia w celu potwierdzenia kosztu i sposobu transportu.',
        ];
        $methods['dostawa-indywidualna'] = shipping_profile_public($fallback);
        if (empty($methods['dostawa-indywidualna']['cost'])) {
            $methods['dostawa-indywidualna']['cost'] = 'do ustalenia';
        }
    }

    return $methods;
}

function shop_test_individual_delivery(): array
{
    $profiles = shipping_profiles_by_id(false);
    if (isset($profiles['dostawa-indywidualna'])) {
        return shipping_profile_public($profiles['dostawa-indywidualna']);
    }
    return [
        'method' => 'dostawa-indywidualna',
        'profileId' => 'dostawa-indywidualna',
        'label' => 'Dostawa do ustalenia indywidualnie',
        'type' => 'do_ustalenia',
        'cost' => 'do ustalenia',
        'costNumber' => null,
        'priceFrom' => false,
        'requiresConfirmation' => true,
        'description' => 'Skontaktujemy się po złożeniu zamówienia w celu potwierdzenia kosztu i sposobu transportu.',
    ];
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
    return '/sklep-test/figury-ogrodowe/produkt/' . rawurlencode(clean_filename($slug));
}

function shop_test_stylesheets(): void
{
    echo '<link rel="stylesheet" href="/styles.css">' . PHP_EOL;
    echo '  <link rel="stylesheet" href="/sklep-test/shop.css">' . PHP_EOL;
}

function shop_test_header(string $active = ''): void
{
    $links = [
        ['href' => '/', 'label' => 'Strona główna', 'key' => 'home'],
        ['href' => '/dom', 'label' => 'Dom', 'key' => 'dom'],
        ['href' => '/ogrod', 'label' => 'Ogród', 'key' => 'ogrod'],
        ['href' => '/outlet-meblowy-wroclaw/', 'label' => 'Outlet meblowy Wrocław', 'key' => 'outlet'],
        ['href' => '/meble-ogrodowe-wroclaw/', 'label' => 'Meble ogrodowe Wrocław', 'key' => 'garden'],
        ['href' => '/#faq-home-title', 'label' => 'FAQ', 'key' => 'faq'],
        ['href' => '/sklep-test/figury-ogrodowe', 'label' => 'Figury ogrodowe', 'key' => 'figures'],
        ['href' => '/sklep-test/figury-ogrodowe/koszyk', 'label' => 'Koszyk <span data-cart-count></span>', 'key' => 'cart'],
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

    <nav id="main-menu" class="main-nav shop-main-nav" aria-label="Menu sklepu testowego">
      <?php foreach ($links as $link): ?>
        <a href="<?= e($link['href']) ?>"<?= $active === $link['key'] ? ' aria-current="page"' : '' ?>><?= $link['label'] ?></a>
      <?php endforeach; ?>
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
      <a href="/meble-ogrodowe-wroclaw/">Meble ogrodowe Wrocław</a>
      <a href="/outlet-meblowy-wroclaw/">Outlet meblowy Wrocław</a>
    </div>
    <div>
      <strong>Figury ogrodowe</strong>
      <a href="/sklep-test/figury-ogrodowe">Kategoria</a>
      <a href="/sklep-test/figury-ogrodowe/koszyk">Koszyk</a>
      <a href="/sklep-test/figury-ogrodowe/regulamin">Regulamin</a>
      <a href="/sklep-test/figury-ogrodowe/polityka-prywatnosci">Polityka prywatności</a>
    </div>
  </footer>
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

    $items = [];
    foreach ($data['items'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $slug = clean_filename((string)($row['slug'] ?? ''));
        $quantity = max(1, min(20, (int)($row['quantity'] ?? 1)));
        if ($slug === '' || !isset($products[$slug])) {
            continue;
        }
        $product = $products[$slug];
        $price = shop_test_price_number($product['grossPrice'] ?? '');
        if ($price === null) {
            continue;
        }
        $items[] = [
            'product' => $product,
            'slug' => $slug,
            'quantity' => $quantity,
            'price' => $price,
            'lineTotal' => round($price * $quantity, 2),
        ];
    }

    if (!$items) {
        throw new RuntimeException('Koszyk jest pusty albo zawiera produkty bez ceny.');
    }

    return [
        'items' => $items,
        'delivery' => shop_test_delivery_key((string)($data['delivery'] ?? '')),
    ];
}

function shop_test_text_field(string $key, int $maxLength = 300): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}
