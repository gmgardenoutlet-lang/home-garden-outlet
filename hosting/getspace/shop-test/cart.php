<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
shop_test_boot();
shop_test_require_sales();

$publicProducts = shop_test_public_products();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, follow">
  <title>Koszyk | Figury ogrodowe | Home & Garden Outlet</title>
  <?php shop_test_stylesheets(); ?>
</head>
<body>
  <?php shop_test_header('cart'); ?>

  <main>
    <section class="shop-hero shop-hero-compact">
      <div class="admin-ribbon">Sklep internetowy</div>
      <p class="eyebrow">Koszyk</p>
      <h1>Twój koszyk</h1>
      <p>Sprawdź wybrane figury ogrodowe, ilości i przejdź dalej do dostawy oraz danych klienta.</p>
    </section>

    <section class="cart-page">
      <div class="cart-panel">
        <div class="cart-head">
          <div><p class="eyebrow">Krok 1</p><h2>Koszyk</h2></div>
          <button type="button" class="cart-clear" data-cart-clear>Wyczyść</button>
        </div>
        <div data-cart-items class="cart-items"></div>
        <div class="cart-empty-actions" data-cart-empty-actions hidden>
          <p>Twój koszyk jest pusty.</p>
          <a class="btn" href="<?= e(shop_catalog_url()) ?>">Wróć do sklepu</a>
        </div>
        <section class="checkout-step">
          <p class="eyebrow">Sposób dostawy</p>
          <h2>Wybierz dostawę</h2>
          <p>Dostępne sposoby dostawy zależą od wybranego produktu. Koszt wybranej dostawy zostanie doliczony do zamówienia przed jego złożeniem.</p>
          <div data-delivery-options class="delivery-options"></div>
        </section>
        <div class="cart-summary" data-cart-summary>
          <div><span>Produkty</span><strong data-products-total>0,00 zł</strong></div>
          <div><span>Dostawa</span><strong data-delivery-total>—</strong></div>
        </div>
        <div class="cart-total"><span data-cart-total-label>Razem</span><strong data-cart-total>0,00 zł</strong></div>
        <div class="shop-actions cart-next-actions">
          <a class="btn btn-light" href="<?= e(shop_catalog_url()) ?>">Wróć do zakupów</a>
          <a class="btn" href="<?= e(shop_catalog_url()) ?>/zamowienie" data-checkout-link>Przejdź do dostawy i danych</a>
        </div>
      </div>
    </section>
  </main>

  <?php shop_test_footer(); ?>
  <script>window.HGO_SHOP_SALES_ENABLED = <?= shop_sales_enabled() ? 'true' : 'false' ?>; window.HGO_SHOP_PRODUCTS = <?= json_encode($publicProducts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
  <script src="/sklep/shop.js?v=20260809-shipment-check1"></script>
</body>
</html>
