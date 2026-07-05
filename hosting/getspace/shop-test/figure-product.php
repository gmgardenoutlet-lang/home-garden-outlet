<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
shop_test_boot();

$slug = (string)($_GET['slug'] ?? '');
$product = shop_test_find_product($slug);
if (!$product) {
    http_response_code(404);
}
$view = $product ? shop_test_public_product($product) : null;
$images = $product ? shop_test_gallery($product) : ['/product-table.jpeg'];
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
    'Zastosowanie zewnętrzne' => !empty($product['outdoorUse']) ? 'Tak' : '',
    'Transport ostrożny' => !empty($product['fragileTransport']) ? 'Tak' : '',
], static function ($value): bool {
    return trim((string)$value) !== '';
}) : [];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $view ? e($view['name']) : 'Figura niedostępna' ?> | Home & Garden Outlet</title>
  <link rel="stylesheet" href="/sklep-test/shop.css">
</head>
<body>
  <header class="shop-header">
    <a href="/sklep-test/figury-ogrodowe" class="shop-logo">Home &amp; Garden Outlet</a>
    <nav><a href="/sklep-test/figury-ogrodowe">Figury</a><a href="/sklep-test/figury-ogrodowe#koszyk">Koszyk</a><a href="/admin/">Panel</a></nav>
  </header>

  <main>
    <?php if (!$product || !$view): ?>
      <section class="empty"><h1>Nie znaleziono figury</h1><p>Produkt nie jest widoczny w sklepie albo został ukryty.</p><a class="btn" href="/sklep-test/figury-ogrodowe">Wróć</a></section>
    <?php else: ?>
      <article class="product-test">
        <section class="product-test-gallery">
          <img class="product-main-image" src="<?= e($images[0]) ?>" width="900" height="720" alt="<?= e($view['alt']) ?>">
          <?php if (count($images) > 1): ?>
            <div class="product-thumbs">
              <?php foreach ($images as $image): ?><img src="<?= e($image) ?>" width="180" height="140" loading="lazy" alt=""><?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
        <section class="product-test-info">
          <div class="admin-ribbon admin-ribbon-inline">Tryb testowy — sklep niepubliczny</div>
          <p class="eyebrow">Figura ogrodowa</p>
          <h1><?= e($view['name']) ?></h1>
          <div class="product-status-grid">
            <span><?= e($view['availability']) ?></span>
            <span>Wysyłka <?= e($view['leadTime']) ?></span>
          </div>
          <strong class="product-price"><?= e($view['priceLabel']) ?></strong>
          <?php if ($view['shortDescription'] !== ''): ?><p><?= nl2br(e($view['shortDescription'])) ?></p><?php endif; ?>
          <?php if (trim((string)($product['longDescription'] ?? '')) !== ''): ?><p><?= nl2br(e($product['longDescription'])) ?></p><?php endif; ?>
          <div class="shop-actions">
            <button class="btn" type="button" data-add-to-cart="<?= e($view['slug']) ?>"<?= $view['canBuy'] ? '' : ' disabled' ?>>Dodaj do koszyka</button>
            <a class="btn btn-light" href="sms:+48577210777?body=Interesuje%20mnie%20figura:%20<?= rawurlencode($view['name']) ?>">Zapytaj o produkt</a>
            <a class="btn btn-light" href="/sklep-test/figury-ogrodowe#koszyk">Koszyk i zamówienie</a>
          </div>

          <?php if ($details): ?>
            <dl class="specs">
              <?php foreach ($details as $label => $value): ?><div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div><?php endforeach; ?>
            </dl>
          <?php endif; ?>

          <section class="delivery-box">
            <h2>Dostępne formy dostawy</h2>
            <?php foreach (shop_test_delivery_methods($product) as $method): ?>
              <p><strong><?= e($method['label']) ?></strong> — <?= e($method['cost']) ?></p>
            <?php endforeach; ?>
          </section>
        </section>
      </article>
    <?php endif; ?>
  </main>

  <script>window.HGO_SHOP_PRODUCTS = <?= json_encode($view ? [$view] : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
  <script src="/sklep-test/shop.js"></script>
</body>
</html>
