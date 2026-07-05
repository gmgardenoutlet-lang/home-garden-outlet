<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
shop_test_boot();

$products = shop_test_products();
$publicProducts = array_map('shop_test_public_product', $products);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Figury ogrodowe — sklep testowy | Home & Garden Outlet</title>
  <link rel="stylesheet" href="/sklep-test/shop.css">
</head>
<body>
  <header class="shop-header">
    <a href="/admin/" class="shop-logo">Home &amp; Garden Outlet</a>
    <nav><a href="/admin/">Panel</a><a href="/admin/?orders=1">Zamówienia</a></nav>
  </header>

  <main>
    <section class="shop-hero">
      <p class="eyebrow">Ukryty moduł testowy · noindex</p>
      <h1>Figury ogrodowe — sklep testowy</h1>
      <p>Ten widok służy tylko do testów koszyka i zamówień. Nie ma linku z publicznej strony, a dostęp wymaga zalogowania do panelu administratora.</p>
    </section>

    <?php if (!$products): ?>
      <section class="empty">Nie ma jeszcze figur widocznych w sklepie testowym. W panelu ustaw typ produktu „Figura ogrodowa / sklep online”, włącz widoczność i status „Dostępny”.</section>
    <?php else: ?>
      <section class="shop-grid" aria-label="Figury ogrodowe">
        <?php foreach ($products as $product): ?>
          <?php $view = shop_test_public_product($product); ?>
          <article class="shop-card">
            <a class="shop-card-image" href="/sklep-test/figury-ogrodowe/<?= e($view['slug']) ?>">
              <img src="<?= e($view['image']) ?>" width="520" height="390" loading="lazy" alt="<?= e($view['alt']) ?>">
            </a>
            <div>
              <p class="shop-meta"><?= e($view['availability']) ?> · <?= e($view['leadTime']) ?></p>
              <h2><a href="/sklep-test/figury-ogrodowe/<?= e($view['slug']) ?>"><?= e($view['name']) ?></a></h2>
              <?php if ($view['shortDescription'] !== ''): ?><p><?= e($view['shortDescription']) ?></p><?php endif; ?>
              <strong><?= e($view['priceLabel']) ?></strong>
              <div class="shop-actions">
                <a class="btn btn-light" href="/sklep-test/figury-ogrodowe/<?= e($view['slug']) ?>">Szczegóły</a>
                <button class="btn" type="button" data-add-to-cart="<?= e($view['slug']) ?>"<?= $view['canBuy'] ? '' : ' disabled' ?>>Dodaj do koszyka</button>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <aside id="koszyk" class="cart-panel" aria-label="Koszyk testowy">
      <div class="cart-head">
        <div><p class="eyebrow">Koszyk</p><h2>Zamówienie testowe</h2></div>
        <button type="button" class="cart-clear" data-cart-clear>Wyczyść</button>
      </div>
      <div data-cart-items class="cart-items"></div>
      <div data-delivery-options class="delivery-options"></div>
      <div class="cart-total"><span>Razem</span><strong data-cart-total>0,00 zł</strong></div>

      <form method="post" action="/sklep-test/order" class="checkout-form" data-checkout-form>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="cart_payload" data-cart-payload>
        <h3>Dane do zamówienia</h3>
        <label>Imię i nazwisko<input name="customer_name" required autocomplete="name"></label>
        <label>E-mail<input name="customer_email" type="email" required autocomplete="email"></label>
        <label>Telefon<input name="customer_phone" required autocomplete="tel"></label>
        <label>Adres<input name="customer_address" required autocomplete="street-address"></label>
        <div class="form-row"><label>Kod pocztowy<input name="customer_postal" required autocomplete="postal-code"></label><label>Miasto<input name="customer_city" required autocomplete="address-level2"></label></div>
        <label>Uwagi<textarea name="customer_notes" rows="3" placeholder="Np. dogodna godzina kontaktu albo informacja o dostawie"></textarea></label>
        <label class="check"><input type="checkbox" name="terms" required> Potwierdzam, że to zamówienie testowe bez płatności online.</label>
        <label class="check"><input type="checkbox" name="privacy" required> Zgadzam się na kontakt w sprawie zamówienia.</label>
        <button class="btn btn-wide" type="submit">Złóż zamówienie testowe</button>
      </form>
    </aside>
  </main>

  <script>window.HGO_SHOP_PRODUCTS = <?= json_encode($publicProducts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
  <script src="/sklep-test/shop.js"></script>
</body>
</html>
