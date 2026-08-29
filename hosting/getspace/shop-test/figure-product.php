<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
shop_test_boot();

$slug = (string)($_GET['slug'] ?? '');
$product = shop_test_find_figure_product($slug);
$isHidden = $product !== null && catalog_normalize((string)($product['productStatus'] ?? '')) === 'ukryty';
$isSold = $product !== null && (
    catalog_normalize((string)($product['productStatus'] ?? '')) === 'sprzedany'
    || in_array(catalog_normalize((string)($product['status'] ?? '')), ['sprzedane', 'sprzedany'], true)
);
$isAvailable = $product !== null && shop_test_is_figure($product);
$isTemporarilyUnavailable = $product !== null && !$isAvailable && !$isSold && !$isHidden;
$showProduct = $product !== null && !$isHidden;
if ($product === null) {
    http_response_code(404);
} elseif ($isHidden) {
    http_response_code(404);
    header('X-Robots-Tag: noindex, follow');
}
$view = $product ? shop_test_public_product($product) : null;
$publicProducts = shop_test_public_products();
$images = $product ? shop_test_gallery($product) : ['/product-table.jpeg'];
$galleryJson = json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$productUrl = $showProduct && $view ? 'https://mgoutlet.pl' . shop_test_product_url($view['slug']) : '';
$metaDescription = $product !== null && catalog_has_value($product['seoDescription'] ?? '') ? trim((string)$product['seoDescription']) : '';
$mainImageUrl = preg_match('#^https?://#i', (string)$images[0]) ? (string)$images[0] : 'https://mgoutlet.pl' . shop_test_image_url((string)$images[0]);
$productBreadcrumbs = null;
$productSchema = null;
if ($showProduct && $view) {
    $productBreadcrumbs = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://mgoutlet.pl/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Figury ogrodowe', 'item' => 'https://mgoutlet.pl' . shop_catalog_url()],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $view['name'], 'item' => $productUrl],
    ]];
    if ($isAvailable && $view['canBuy'] && $view['price'] !== null) {
        $productSchema = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $view['name'],
            'image' => array_map(static fn(string $image): string => preg_match('#^https?://#i', $image) ? $image : 'https://mgoutlet.pl' . shop_test_image_url($image), $images),
            'description' => $view['shortDescription'], 'url' => $productUrl,
            'offers' => ['@type' => 'Offer', 'url' => $productUrl, 'price' => number_format((float)$view['price'], 2, '.', ''), 'priceCurrency' => 'PLN', 'availability' => 'https://schema.org/InStock']];
        if ($view['sku'] !== '') { $productSchema['sku'] = $view['sku']; }
    }
}
$details = $product ? array_filter([
    'SKU' => $product['sku'] ?? '',
    'Materiał' => $product['material'] ?? '',
    'Kolor' => $product['color'] ?? '',
    'Wymiary ogólne' => $product['dimensions'] ?? '',
    'Wysokość' => $product['height'] ?? '',
    'Szerokość' => $product['width'] ?? '',
    'Głębokość' => $product['depth'] ?? '',
    'Waga' => $product['weight'] ?? '',
    'Wymiary paczki' => $product['packageDimensions'] ?? '',
    'Waga po zapakowaniu' => $product['packageWeight'] ?? '',
    'Długość paczki' => $product['packageLengthCm'] ?? '',
    'Szerokość paczki' => $product['packageWidthCm'] ?? '',
    'Wysokość paczki' => $product['packageHeightCm'] ?? '',
    'Zastosowanie zewnętrzne' => !empty($product['outdoorUse']) ? 'Tak' : '',
    'Transport ostrożny' => !empty($product['fragileTransport']) ? 'Tak' : '',
    'Produkt delikatny' => !empty($product['delicateProduct']) ? 'Tak' : '',
    'Produkt ręcznie malowany' => !empty($product['handPainted']) ? 'Tak' : '',
    'Produkt ciężki' => !empty($product['heavyProduct']) ? 'Tak' : '',
    'Produkt gabarytowy' => !empty($product['oversizedProduct']) ? 'Tak' : '',
], static function ($value): bool {
    return trim((string)$value) !== '';
}) : [];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="<?= $showProduct && !$isTemporarilyUnavailable ? 'index, follow' : 'noindex, follow' ?>">
  <?php if ($showProduct && $view): ?><link rel="canonical" href="<?= e($productUrl) ?>"><?php endif; ?>
  <title><?= $showProduct && $view ? e($view['name']) : 'Figura niedostępna' ?> | Home & Garden Outlet</title>
  <?php if ($metaDescription !== ''): ?><meta name="description" content="<?= e($metaDescription) ?>"><?php endif; ?>
  <?php if ($showProduct && $view): ?>
    <meta property="og:title" content="<?= e($view['name']) ?> | Home &amp; Garden Outlet">
    <meta property="og:description" content="<?= e($metaDescription !== '' ? $metaDescription : $view['shortDescription']) ?>">
    <meta property="og:url" content="<?= e($productUrl) ?>">
    <meta property="og:image" content="<?= e($mainImageUrl) ?>">
    <?php if ($productSchema !== null): ?><script type="application/ld+json"><?= json_encode($productSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script><?php endif; ?>
    <?php if ($productBreadcrumbs !== null): ?><script type="application/ld+json"><?= json_encode($productBreadcrumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script><?php endif; ?>
  <?php endif; ?>
  <?php shop_test_stylesheets(); ?>
</head>
<body>
  <?php shop_test_header('figures'); ?>

  <main>
    <?php if (!$showProduct || !$view): ?>
      <section class="empty"><h1>Nie znaleziono figury</h1><p>Produkt nie jest widoczny w sklepie albo został ukryty.</p><a class="btn" href="<?= e(shop_catalog_url()) ?>">Wróć</a></section>
    <?php else: ?>
      <nav class="shop-breadcrumbs" aria-label="Okruszki">
        <a href="/">Home</a><span aria-hidden="true">›</span><a href="<?= e(shop_catalog_url()) ?>">Figury ogrodowe</a><span aria-hidden="true">›</span><span aria-current="page"><?= e($view['name']) ?></span>
      </nav>
      <article class="product-test">
        <section class="product-test-gallery">
          <button
            class="product-test-main product-gallery-trigger"
            type="button"
            data-gallery="<?= e($galleryJson ?: '[]') ?>"
            data-gallery-name="<?= e($view['name']) ?>"
            data-gallery-alt="<?= e($view['alt']) ?>"
            data-gallery-start="0"
            aria-label="Otwórz galerię produktu <?= e($view['name']) ?>"
          >
            <img class="product-main-image" src="<?= e($images[0]) ?>" width="900" height="720" alt="<?= e($view['alt']) ?>">
          </button>
          <?php if (count($images) > 1): ?>
            <div class="product-thumbs">
              <?php foreach ($images as $index => $image): ?>
                <button class="product-thumb<?= $index === 0 ? ' active' : '' ?>" type="button" data-shop-gallery-index="<?= e((string)$index) ?>" aria-label="Pokaż zdjęcie <?= e((string)($index + 1)) ?>">
                  <img src="<?= e($image) ?>" width="180" height="140" loading="lazy" alt="">
                </button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
        <section class="product-test-info">
          <p class="eyebrow">Figura ogrodowa</p>
          <h1><?= e($view['name']) ?></h1>
          <div class="product-status-grid">
            <?php if ($isSold): ?>
              <span>Produkt sprzedany — obecnie niedostępny</span>
            <?php elseif ($isTemporarilyUnavailable): ?>
              <span>Produkt obecnie niedostępny w sprzedaży online</span>
            <?php else: ?>
              <span>Wysyłka <?= e($view['leadTime']) ?></span>
            <?php endif; ?>
            <?php if (!empty($product['handPainted'])): ?><span>Ręcznie malowane</span><?php endif; ?>
          </div>
          <strong class="product-price"><?= e($view['priceLabel']) ?></strong>
          <?php if ($view['shortDescription'] !== ''): ?><p><?= nl2br(e($view['shortDescription'])) ?></p><?php endif; ?>
          <?php if (trim((string)($product['longDescription'] ?? '')) !== ''): ?><p><?= nl2br(e($product['longDescription'])) ?></p><?php endif; ?>

          <?php if (!empty($product['handPainted'])): ?>
            <div class="shop-note">Figury są malowane ręcznie, dlatego poszczególne egzemplarze mogą nieznacznie różnić się od siebie oraz od produktu prezentowanego na zdjęciach, w szczególności odcieniem, intensywnością barw i detalami wykończenia. Takie niewielkie różnice są naturalną cechą ręcznego wykonania.</div>
          <?php endif; ?>

          <?php if ($isAvailable): ?><p><a href="/sklep/figury-ogrodowe/dostawa-i-platnosci">Dostawa i płatności</a> · <a href="/sklep/figury-ogrodowe/zwroty-i-reklamacje">Zwroty i reklamacje</a> · <a href="/sklep/figury-ogrodowe/formularz-odstapienia">Formularz odstąpienia</a></p><?php endif; ?>

          <div class="shop-actions">
            <?php if (shop_sales_enabled() && $view['canBuy']): ?>
              <button class="btn" type="button" data-add-to-cart="<?= e($view['slug']) ?>">Dodaj do koszyka</button>
            <?php endif; ?>
            <a class="btn btn-light" href="sms:+48577210777?body=Interesuje%20mnie%20figura:%20<?= rawurlencode($view['name']) ?>">Zapytaj o produkt</a>
          </div>

          <?php if ($details): ?>
            <dl class="specs">
              <?php foreach ($details as $label => $value): ?><div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div><?php endforeach; ?>
            </dl>
          <?php endif; ?>

          <?php if ($isAvailable): ?><section class="delivery-box">
            <h2>Dostępne formy dostawy</h2>
            <p class="delivery-note">Dostępne formy dostawy zależą od wagi, wymiarów i rodzaju produktu.</p>
            <?php foreach (shop_test_delivery_methods($product) as $method): ?>
              <article class="delivery-card">
                <div>
                  <strong><?= e($method['label']) ?></strong>
                  <?php if (!empty($method['description'])): ?><p><?= e($method['description']) ?></p><?php endif; ?>
                </div>
                <span><?= e($method['cost']) ?></span>
                <?php if (!empty($method['requiresConfirmation'])): ?><small>Koszt i możliwość wysyłki potwierdzimy przed realizacją.</small><?php endif; ?>
              </article>
            <?php endforeach; ?>
          </section><?php endif; ?>
          <?php if ($isAvailable && shop_test_has_shipping_method($product)): ?>
            <aside class="shipment-check-note">
              <strong>Ważne przy odbiorze przesyłki</strong>
              <p>Zalecamy sprawdzenie stanu przesyłki przy odbiorze, najlepiej w obecności kuriera. W przypadku widocznych uszkodzeń opakowania lub produktu prosimy, jeśli to możliwe, o sporządzenie z kurierem protokołu szkody oraz wykonanie zdjęć. Ułatwi to sprawne rozpatrzenie zgłoszenia.</p>
              <p>Brak protokołu szkody nie wyłącza prawa do złożenia reklamacji.</p>
            </aside>
          <?php endif; ?>
        </section>
      </article>
    <?php endif; ?>
  </main>

  <?php shop_test_footer(); ?>
  <script>window.HGO_SHOP_SALES_ENABLED = <?= shop_sales_enabled() ? 'true' : 'false' ?>; window.HGO_SHOP_PRODUCTS = <?= json_encode($publicProducts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
  <script src="/sklep/shop.js?v=20260809-shipment-check1"></script>
</body>
</html>
