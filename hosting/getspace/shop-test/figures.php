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
  <title>Figury ogrodowe do ogrodu i na taras | Home & Garden Outlet</title>
  <link rel="stylesheet" href="/sklep-test/shop.css">
</head>
<body>
  <header class="shop-header">
    <a href="/sklep-test/figury-ogrodowe" class="shop-logo">Home &amp; Garden Outlet</a>
    <nav><a href="#produkty">Figury</a><a href="#koszyk">Koszyk</a><a href="/admin/">Panel</a><a href="/admin/?orders=1">Zamówienia</a></nav>
  </header>

  <main>
    <section class="shop-hero">
      <div class="admin-ribbon">Tryb testowy — sklep niepubliczny</div>
      <p class="eyebrow">Figury ogrodowe do ogrodu i na taras</p>
      <h1>Figury ogrodowe</h1>
      <p>Wybierz dekoracje do ogrodu, strefy wejściowej lub tarasu. Produkty są dostępne u producenta, a ścieżka zamówienia jest przygotowana pod przyszły sklep online Home &amp; Garden Outlet.</p>
      <div class="hero-badges" aria-label="Najważniejsze informacje">
        <span>Dostępne u producenta</span>
        <span>Realizacja 2-5 dni roboczych</span>
        <span>Dostawa zależna od produktu</span>
      </div>
    </section>

    <?php if (!$products): ?>
      <section class="empty">Nie ma jeszcze figur widocznych w sklepie. W panelu ustaw typ produktu „Figura ogrodowa / sklep online”, włącz widoczność i status „Dostępny”.</section>
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
            <a class="shop-card-image" href="/sklep-test/figury-ogrodowe/<?= e($view['slug']) ?>">
              <img src="<?= e($view['image']) ?>" width="520" height="390" loading="lazy" alt="<?= e($view['alt']) ?>">
            </a>
            <div>
              <p class="shop-meta"><?= e($view['availability']) ?></p>
              <h2><a href="/sklep-test/figury-ogrodowe/<?= e($view['slug']) ?>"><?= e($view['name']) ?></a></h2>
              <?php if ($view['shortDescription'] !== ''): ?><p class="card-description"><?= e($view['shortDescription']) ?></p><?php endif; ?>
              <ul class="card-facts">
                <li>Dostępny u producenta</li>
                <li>Wysyłka <?= e($view['leadTime']) ?></li>
              </ul>
              <strong class="card-price"><?= e($view['priceLabel']) ?></strong>
              <div class="shop-actions">
                <a class="btn btn-light" href="/sklep-test/figury-ogrodowe/<?= e($view['slug']) ?>">Zobacz produkt</a>
                <button class="btn" type="button" data-add-to-cart="<?= e($view['slug']) ?>"<?= $view['canBuy'] ? '' : ' disabled' ?>>Dodaj do koszyka</button>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <section id="koszyk" class="checkout-shell" aria-label="Koszyk i zamówienie">
      <div class="cart-panel">
        <div class="cart-head">
          <div><p class="eyebrow">Krok 1</p><h2>Koszyk</h2></div>
          <button type="button" class="cart-clear" data-cart-clear>Wyczyść</button>
        </div>
        <div data-cart-items class="cart-items"></div>
        <div class="checkout-step">
          <p class="eyebrow">Krok 2</p>
          <h3>Dostawa</h3>
          <p>Wybierz jedną z metod dostępnych dla produktów w koszyku. Przy większych lub ciężkich figurach koszt może wymagać potwierdzenia.</p>
          <div data-delivery-options class="delivery-options"></div>
        </div>
        <div class="cart-total"><span>Razem</span><strong data-cart-total>0,00 zł</strong></div>
      </div>

      <form method="post" action="/sklep-test/order" class="checkout-form" data-checkout-form>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="cart_payload" data-cart-payload>
        <section class="checkout-step">
          <p class="eyebrow">Krok 3</p>
          <h3>Dane klienta</h3>
          <label>Imię i nazwisko<input name="customer_name" required autocomplete="name"></label>
          <label>E-mail<input name="customer_email" type="email" required autocomplete="email"></label>
          <label>Telefon<input name="customer_phone" required autocomplete="tel"></label>
          <label>Adres<input name="customer_address" required autocomplete="street-address"></label>
          <div class="form-row"><label>Kod pocztowy<input name="customer_postal" required autocomplete="postal-code"></label><label>Miasto<input name="customer_city" required autocomplete="address-level2"></label></div>
          <label>Uwagi<textarea name="customer_notes" rows="3" placeholder="Np. dogodna godzina kontaktu albo informacja o dostawie"></textarea></label>
        </section>
        <section class="checkout-step payment-step">
          <p class="eyebrow">Krok 4</p>
          <h3>Płatność</h3>
          <p>Płatność online zostanie podłączona przy publicznym uruchomieniu sklepu. Teraz zamówienie trafia do obsługi i może być potwierdzone ręcznie.</p>
          <strong>Płatność ręczna</strong>
        </section>
        <section class="checkout-step">
          <p class="eyebrow">Krok 5</p>
          <h3>Podsumowanie</h3>
          <label class="check"><input type="checkbox" name="terms" required> Potwierdzam dane zamówienia i przyjmuję do wiadomości, że płatność online nie jest jeszcze aktywna.</label>
          <label class="check"><input type="checkbox" name="privacy" required> Zgadzam się na kontakt w sprawie zamówienia.</label>
          <button class="btn btn-wide" type="submit">Złóż zamówienie</button>
        </section>
      </form>
    </section>
  </main>

  <script>window.HGO_SHOP_PRODUCTS = <?= json_encode($publicProducts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
  <script src="/sklep-test/shop.js"></script>
</body>
</html>
