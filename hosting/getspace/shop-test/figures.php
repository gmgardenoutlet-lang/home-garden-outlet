<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
shop_test_boot();

$products = shop_test_products();
$publicProducts = shop_test_public_products();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Figury ogrodowe do ogrodu i na taras | Home & Garden Outlet</title>
  <?php shop_test_stylesheets(); ?>
</head>
<body>
  <?php shop_test_header('figures'); ?>

  <main>
    <section class="shop-hero shop-hero-split">
      <div class="shop-hero-copy">
        <div class="admin-ribbon">Tryb testowy — sklep niepubliczny</div>
        <p class="eyebrow">Figury ogrodowe do ogrodu i na taras</p>
        <h1>Figury ogrodowe</h1>
        <p class="hero-subtitle">Ręcznie malowane dekoracje do ogrodu, na taras i przed wejście</p>
        <p>Wybierz figury ogrodowe, które podkreślą charakter Twojej przestrzeni. Produkty są dostępne u producenta, a każdy egzemplarz może delikatnie różnić się odcieniem i detalami wykończenia ze względu na ręczne malowanie.</p>
      </div>
      <div class="shop-hero-media">
        <img src="/assets/images/figury-ogrodowe-hero.webp" width="1600" height="900" alt="Figury ogrodowe i dekoracje w showroomie Home & Garden Outlet">
      </div>
      <div class="hero-badges" aria-label="Najważniejsze informacje">
        <span>Ręczne malowanie</span>
        <span>Realizacja 2–5 dni roboczych</span>
        <span>Dostawa zależna od produktu</span>
        <span>Odbiór osobisty w Kębłowicach</span>
      </div>
      <div class="shop-actions hero-actions">
        <a class="btn" href="#produkty">Zobacz produkty</a>
        <a class="btn btn-light" href="/sklep-test/figury-ogrodowe/koszyk">Przejdź do koszyka</a>
      </div>
    </section>

    <?php if (!$products): ?>
      <section class="empty">Nie ma jeszcze figur widocznych w sklepie. Dodaj pierwsze produkty, aby zobaczyć docelowy układ kategorii.</section>
    <?php else: ?>
      <section class="shop-toolbar" aria-label="Opcje listy produktów">
        <div><strong><?= count($products) ?></strong> produktów w kategorii</div>
        <label>Sortuj
          <select data-shop-sort>
            <option value="default">Domyślnie</option>
            <option value="price-asc">Cena rosnąco</option>
            <option value="price-desc">Cena malejąco</option>
            <option value="name">Nazwa A-Z</option>
          </select>
        </label>
      </section>

      <section id="produkty" class="shop-grid" aria-label="Figury ogrodowe" data-shop-grid>
        <?php foreach ($products as $product): ?>
          <?php $view = shop_test_public_product($product); ?>
          <article class="shop-card" data-product-card data-price="<?= e($view['price'] !== null ? (string)$view['price'] : '') ?>" data-name="<?= e($view['name']) ?>">
            <a class="shop-card-image" href="<?= e(shop_test_product_url($view['slug'])) ?>">
              <img src="<?= e($view['image']) ?>" width="520" height="390" loading="lazy" alt="<?= e($view['alt']) ?>">
            </a>
            <div>
              <p class="shop-meta"><?= e($view['availability']) ?></p>
              <h2><a href="<?= e(shop_test_product_url($view['slug'])) ?>"><?= e($view['name']) ?></a></h2>
              <?php if ($view['shortDescription'] !== ''): ?><p class="card-description"><?= e($view['shortDescription']) ?></p><?php endif; ?>
              <ul class="card-facts">
                <li>Dostępny u producenta</li>
                <li>Wysyłka <?= e($view['leadTime']) ?></li>
              </ul>
              <strong class="card-price"><?= e($view['priceLabel']) ?></strong>
              <div class="shop-actions">
                <a class="btn btn-light" href="<?= e(shop_test_product_url($view['slug'])) ?>">Zobacz produkt</a>
                <button class="btn" type="button" data-add-to-cart="<?= e($view['slug']) ?>"<?= $view['canBuy'] ? '' : ' disabled' ?>>Dodaj do koszyka</button>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </main>

  <?php shop_test_footer(); ?>
  <div class="cart-toast" data-cart-toast hidden></div>
  <script>window.HGO_SHOP_PRODUCTS = <?= json_encode($publicProducts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
  <script src="/sklep-test/shop.js"></script>
</body>
</html>
