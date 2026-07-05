<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
shop_test_boot();

$publicProducts = shop_test_public_products();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Zamówienie | Figury ogrodowe | Home & Garden Outlet</title>
  <link rel="stylesheet" href="/sklep-test/shop.css">
</head>
<body>
  <header class="shop-header">
    <a href="/sklep-test/figury-ogrodowe" class="shop-logo">Home &amp; Garden Outlet</a>
    <nav>
      <a href="/sklep-test/figury-ogrodowe">Figury</a>
      <a href="/sklep-test/figury-ogrodowe/koszyk">Koszyk <span data-cart-count></span></a>
      <a href="/admin/">Panel</a>
    </nav>
  </header>

  <main>
    <section class="shop-hero shop-hero-compact">
      <div class="admin-ribbon">Tryb testowy — sklep niepubliczny</div>
      <p class="eyebrow">Zamówienie</p>
      <h1>Dostawa i dane klienta</h1>
      <p>Uzupełnij dane potrzebne do ręcznego potwierdzenia zamówienia. Płatności online zostaną uruchomione po publicznym starcie sklepu.</p>
    </section>

    <section class="checkout-shell checkout-page" aria-label="Zamówienie">
      <div class="cart-panel">
        <div class="cart-head">
          <div><p class="eyebrow">Krok 1</p><h2>Podsumowanie koszyka</h2></div>
          <a class="cart-clear" href="/sklep-test/figury-ogrodowe/koszyk">Edytuj koszyk</a>
        </div>
        <div data-cart-items class="cart-items"></div>
        <div class="cart-empty-actions" data-cart-empty-actions hidden>
          <p>Twój koszyk jest pusty.</p>
          <a class="btn" href="/sklep-test/figury-ogrodowe">Wróć do sklepu</a>
        </div>
        <div class="checkout-step">
          <p class="eyebrow">Krok 2</p>
          <h3>Dostawa</h3>
          <p>Wybierz jedną z metod dostępnych wspólnie dla produktów w koszyku. Przy większych, ciężkich lub delikatnych figurach koszt dostawy może wymagać indywidualnego potwierdzenia.</p>
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
          <p>Dostępne metody płatności są prezentowane przy produkcie, w koszyku lub podczas składania zamówienia. Płatności online zostaną uruchomione po publicznym starcie sklepu.</p>
          <strong>Płatność testowa / ręczne potwierdzenie</strong>
        </section>
        <section class="checkout-step">
          <p class="eyebrow">Krok 5</p>
          <h3>Podsumowanie</h3>
          <label class="check">
            <input type="checkbox" name="terms" data-terms-checkbox required>
            <span>Akceptuję <a href="/sklep-test/figury-ogrodowe/regulamin" target="_blank" rel="noopener noreferrer">Regulamin</a> sklepu internetowego Home &amp; Garden Outlet.</span>
          </label>
          <label class="check optional-check"><input type="checkbox" name="privacy"> <span>Zgadzam się na kontakt w sprawie obsługi tego zamówienia.</span></label>
          <button class="btn btn-wide" type="submit">Złóż zamówienie</button>
        </section>
      </form>
    </section>
  </main>

  <script>window.HGO_SHOP_PRODUCTS = <?= json_encode($publicProducts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
  <script src="/sklep-test/shop.js"></script>
</body>
</html>
