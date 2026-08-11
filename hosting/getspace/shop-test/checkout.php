<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
shop_test_boot();
shop_test_require_sales();

$publicProducts = shop_test_public_products();
$checkoutErrors = shop_test_checkout_errors();
$checkoutOld = shop_test_checkout_old_input();
$checkoutPaymentMethod = shop_test_checkout_payment_method($checkoutOld);
function checkout_value(array $old, string $key, string $default = ''): string { return e((string)($old[$key] ?? $default)); }
function checkout_error(array $errors, string $key): string { return isset($errors[$key]) ? '<small class="checkout-field-error" id="error-' . e($key) . '">' . e((string)$errors[$key]) . '</small>' : ''; }
function checkout_field_attrs(array $errors, string $key): string { return isset($errors[$key]) ? ' class="has-error" aria-invalid="true" aria-describedby="error-' . e($key) . '"' : ''; }
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Zamówienie | Figury ogrodowe | Home & Garden Outlet</title>
  <?php shop_test_stylesheets(); ?>
</head>
<body>
  <?php shop_test_header('cart'); ?>

  <main>
    <section class="shop-hero shop-hero-compact">
      <div class="admin-ribbon">Sklep internetowy</div>
      <p class="eyebrow">Zamówienie</p>
      <h1>Dostawa i dane klienta</h1>
      <p>Uzupełnij dane potrzebne do ręcznego potwierdzenia zamówienia. Płatności online zostaną uruchomione po publicznym starcie sklepu.</p>
    </section>

    <section class="checkout-shell checkout-page" aria-label="Zamówienie">
      <div class="cart-panel">
        <div class="cart-head">
          <div><p class="eyebrow">Krok 1</p><h2>Podsumowanie koszyka</h2></div>
          <a class="cart-clear" href="<?= e(shop_catalog_url()) ?>/koszyk">Edytuj koszyk</a>
        </div>
        <div data-cart-items class="cart-items"></div>
        <div class="cart-empty-actions" data-cart-empty-actions hidden>
          <p>Twój koszyk jest pusty.</p>
          <a class="btn" href="<?= e(shop_catalog_url()) ?>">Wróć do sklepu</a>
        </div>
        <div class="checkout-step">
          <p class="eyebrow">Krok 2</p>
          <h3>Sposób dostawy</h3>
          <p>Wybierz jedną z metod dostępnych wspólnie dla produktów w koszyku. Przy większych, ciężkich lub delikatnych figurach koszt dostawy może wymagać indywidualnego potwierdzenia.</p>
          <div data-delivery-options class="delivery-options"></div>
        </div>
        <div class="cart-summary" data-cart-summary>
          <div><span>Produkty</span><strong data-products-total>0,00 zł</strong></div>
          <div><span>Dostawa</span><strong data-delivery-total>—</strong></div>
        </div>
        <div class="cart-total"><span data-cart-total-label>Razem</span><strong data-cart-total>0,00 zł</strong></div>
        <aside class="shipment-check-note" data-shipment-check-notice hidden>
          <strong>Odbiór przesyłki</strong>
          <p>Zalecamy sprawdzenie przesyłki przy odbiorze, najlepiej w obecności kuriera. W przypadku widocznych uszkodzeń warto sporządzić protokół szkody i wykonać zdjęcia. Brak protokołu nie wyłącza prawa do reklamacji.</p>
        </aside>
      </div>

      <form method="post" action="/sklep/order" class="checkout-form" data-checkout-form>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="checkout_submission_token" value="<?= e(shop_test_checkout_submission_token()) ?>">
        <input type="hidden" name="cart_payload" data-cart-payload>
        <section class="checkout-step">
          <p class="eyebrow">Krok 3</p>
          <h3>Dane kontaktowe</h3>
          <div class="form-row"><label>Imię<input name="customer_first_name" value="<?= checkout_value($checkoutOld, 'customer_first_name') ?>" required autocomplete="given-name"<?= checkout_field_attrs($checkoutErrors, 'customer_first_name') ?>><?= checkout_error($checkoutErrors, 'customer_first_name') ?></label><label>Nazwisko<input name="customer_last_name" value="<?= checkout_value($checkoutOld, 'customer_last_name') ?>" required autocomplete="family-name"<?= checkout_field_attrs($checkoutErrors, 'customer_last_name') ?>><?= checkout_error($checkoutErrors, 'customer_last_name') ?></label></div>
          <label>E-mail<input name="customer_email" value="<?= checkout_value($checkoutOld, 'customer_email') ?>" type="email" required maxlength="160" autocomplete="email"<?= checkout_field_attrs($checkoutErrors, 'customer_email') ?>><?= checkout_error($checkoutErrors, 'customer_email') ?></label>
          <label>Telefon<input name="customer_phone" value="<?= checkout_value($checkoutOld, 'customer_phone') ?>" type="tel" inputmode="tel" required maxlength="60" autocomplete="tel"<?= checkout_field_attrs($checkoutErrors, 'customer_phone') ?>><?= checkout_error($checkoutErrors, 'customer_phone') ?></label>
        </section>
        <section class="checkout-step">
          <p class="eyebrow">Krok 4</p>
          <h3>Adres dostawy</h3>
          <label>Ulica i numer<input name="delivery_street" value="<?= checkout_value($checkoutOld, 'delivery_street') ?>" required autocomplete="street-address"<?= checkout_field_attrs($checkoutErrors, 'delivery_street') ?>><?= checkout_error($checkoutErrors, 'delivery_street') ?></label>
          <div class="form-row"><label>Kod pocztowy<input name="delivery_postal_code" value="<?= checkout_value($checkoutOld, 'delivery_postal_code') ?>" required maxlength="6" inputmode="numeric" autocomplete="postal-code"<?= checkout_field_attrs($checkoutErrors, 'delivery_postal_code') ?>><?= checkout_error($checkoutErrors, 'delivery_postal_code') ?></label><label>Miejscowość<input name="delivery_city" value="<?= checkout_value($checkoutOld, 'delivery_city') ?>" required autocomplete="address-level2"<?= checkout_field_attrs($checkoutErrors, 'delivery_city') ?>><?= checkout_error($checkoutErrors, 'delivery_city') ?></label></div>
          <label>Kraj<input name="delivery_country" value="<?= checkout_value($checkoutOld, 'delivery_country', 'PL') ?>" maxlength="2" required autocomplete="country"<?= checkout_field_attrs($checkoutErrors, 'delivery_country') ?>><?= checkout_error($checkoutErrors, 'delivery_country') ?></label>
        </section>
        <section class="checkout-step">
          <p class="eyebrow">Faktura</p>
          <label class="check"><input type="checkbox" name="invoice_requested" value="1" data-invoice-toggle<?= !empty($checkoutOld['invoice_requested']) ? ' checked' : '' ?>><span>Chcę otrzymać fakturę</span></label>
          <div data-invoice-fields hidden>
            <label>Nazwa firmy<input name="invoice_company_name" value="<?= checkout_value($checkoutOld, 'invoice_company_name') ?>" autocomplete="organization"<?= checkout_field_attrs($checkoutErrors, 'invoice_company_name') ?>><?= checkout_error($checkoutErrors, 'invoice_company_name') ?></label>
            <label>NIP<input name="invoice_nip" value="<?= checkout_value($checkoutOld, 'invoice_nip') ?>" inputmode="numeric"<?= checkout_field_attrs($checkoutErrors, 'invoice_nip') ?>><?= checkout_error($checkoutErrors, 'invoice_nip') ?></label>
            <label>Adres firmy <small>(jeżeli różni się od adresu dostawy)</small><textarea name="invoice_address" rows="2" autocomplete="street-address"<?= checkout_field_attrs($checkoutErrors, 'invoice_address') ?>><?= checkout_value($checkoutOld, 'invoice_address') ?></textarea><?= checkout_error($checkoutErrors, 'invoice_address') ?></label>
          </div>
        </section>
        <section class="checkout-step">
          <p class="eyebrow">Uwagi</p>
          <label>Uwagi<textarea name="customer_notes" rows="3" placeholder="Np. dogodna godzina kontaktu albo informacja o dostawie"><?= checkout_value($checkoutOld, 'customer_notes') ?></textarea></label>
          <p class="privacy-note">Administratorem Twoich danych osobowych jest EMAALL GARDEN OUTLET sp. z o.o. Dane podane w formularzu wykorzystamy do przyjęcia i realizacji zamówienia, płatności, dostawy, wystawienia dokumentów sprzedaży oraz obsługi posprzedażowej. Dane mogą być przekazywane podmiotom uczestniczącym w realizacji zamówienia, w szczególności operatorowi płatności, firmom kurierskim, operatorom logistycznym oraz producentowi lub dostawcy realizującemu wysyłkę bezpośrednio do klienta. Więcej informacji o przetwarzaniu danych i Twoich prawach znajdziesz w <a href="/polityka-prywatnosci">Polityce prywatności</a>.</p>
        </section>
        <section class="checkout-step payment-step">
          <p class="eyebrow">Krok 5</p>
          <h3>Płatność</h3>
          <?php if (!empty(shop_payment_methods()['paynow'])): ?>
            <label class="check"><input type="radio" name="payment_method" value="paynow" required<?= $checkoutPaymentMethod === 'paynow' ? ' checked' : '' ?><?= checkout_field_attrs($checkoutErrors, 'payment_method') ?>><span><strong>Płatność online Paynow</strong><br>Wybierzesz bezpieczny BLIK lub przelew online na stronie Paynow.</span></label>
          <?php endif; ?>
          <label class="check"><input type="radio" name="payment_method" value="bank_transfer"<?= $checkoutPaymentMethod === 'bank_transfer' ? ' checked' : '' ?> required<?= checkout_field_attrs($checkoutErrors, 'payment_method') ?>><span><strong>Przelew tradycyjny</strong><br>Po złożeniu zamówienia otrzymasz dane do przelewu. Realizację zamówienia rozpoczniemy po zaksięgowaniu płatności.</span></label>
          <?php if (empty(shop_payment_methods()['paynow'])): ?><p>Płatność online Paynow jest obecnie niedostępna.</p><?php endif; ?>
          <?= checkout_error($checkoutErrors, 'payment_method') ?>
        </section>
        <section class="checkout-step">
          <p class="eyebrow">Krok 6</p>
          <h3>Podsumowanie</h3>
          <label class="check">
            <input type="checkbox" name="terms" data-terms-checkbox required<?= checkout_field_attrs($checkoutErrors, 'terms') ?>>
            <span>Akceptuję <a href="<?= e(shop_catalog_url()) ?>/regulamin" target="_blank" rel="noopener noreferrer">Regulamin</a> sklepu internetowego Home &amp; Garden Outlet.</span>
          </label>
          <?= checkout_error($checkoutErrors, 'terms') ?>
          <button class="btn btn-wide" type="submit">Kupuję i płacę</button>
        </section>
      </form>
    </section>
  </main>

  <?php shop_test_footer(); ?>
  <script>window.HGO_SHOP_SALES_ENABLED = <?= shop_sales_enabled() ? 'true' : 'false' ?>; window.HGO_SHOP_PRODUCTS = <?= json_encode($publicProducts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
  <script src="/sklep/shop.js?v=20260809-shipment-check1"></script>
</body>
</html>
