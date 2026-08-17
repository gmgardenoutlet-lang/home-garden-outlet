<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/paynow.php';
shop_test_boot();
shop_test_require_sales();

$orderId = shop_safe_order_id((string)($_GET['order'] ?? ''));
$confirmationToken = (string)($_GET['token'] ?? '');
$order = shop_public_confirmation_order($orderId, $confirmationToken);
$paynowRedirectUrl = is_array($order) ? paynow_redirect_url((string)($order['paymentRedirectUrl'] ?? '')) : null;
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, follow, noarchive');
if (!$order) {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, follow">
  <title>Potwierdzenie zamówienia | Home & Garden Outlet</title>
  <?php shop_test_stylesheets(); ?>
</head>
<body>
  <?php shop_test_header('figures'); ?>

  <main class="order-result-page">
    <?php if ($order): ?>
      <section class="success-box confirmation-box">
        <p class="eyebrow">Zamówienie zapisane</p>
        <h1>Dziękujemy za zamówienie</h1>
        <p>Zamówienie zostało zapisane. Status płatności jest potwierdzany wyłącznie przez Paynow.</p>

        <div class="confirmation-grid">
          <section>
            <h2>Numer zamówienia</h2>
            <p class="order-number"><?= e($order['orderId'] ?? '') ?></p>
          </section>
          <section>
            <h2>Płatność</h2>
            <p><?= e($order['paymentStatus'] ?? 'not_started') ?></p>
          </section>
        </div>

        <?php if (($order['paymentMethod'] ?? '') === 'bank_transfer' && is_array($order['bankTransfer'] ?? null)): ?>
          <?php $transfer = $order['bankTransfer']; ?>
          <section class="confirmation-section">
            <h2>Przelew tradycyjny</h2>
            <p>Oczekujemy na zaksięgowanie płatności. Realizację zamówienia rozpoczniemy po jej otrzymaniu.</p>
            <p><strong>Kwota:</strong> <?= e(shop_test_price_label(((int)($order['totalCents'] ?? 0)) / 100)) ?><br><strong>Odbiorca:</strong> <?= e($transfer['recipient'] ?? '') ?><br><strong>Rachunek:</strong> <?= e($transfer['accountNumber'] ?? '') ?><br><strong>Tytuł:</strong> <?= e($transfer['transferTitle'] ?? '') ?></p>
          </section>
        <?php elseif (($order['status'] ?? '') === 'awaiting_shipping_quote'): ?>
          <section class="confirmation-section"><h2><?= shop_test_order_country_code($order) === 'PL' ? 'Koszt dostawy do potwierdzenia' : 'Zamówienie zostało przyjęte do wyceny dostawy' ?></h2><p><?= shop_test_order_country_code($order) === 'PL' ? 'Koszt dostawy wymaga indywidualnego potwierdzenia. Skontaktujemy się z Tobą przed płatnością.' : 'Skontaktujemy się z Tobą po ustaleniu kosztu wysyłki.' ?></p></section>
        <?php elseif (($order['paymentMethod'] ?? '') === 'paynow' && ($order['delivery']['pricingType'] ?? '') === 'fixed_price'): ?>
          <section class="confirmation-section">
            <h2>Płatność online Paynow</h2>
            <p>Możesz opłacić zamówienie BLIK-iem lub przelewem online. Płatność zostanie uznana dopiero po potwierdzeniu Paynow.</p>
            <?php if ($paynowRedirectUrl !== null): ?>
              <a class="btn" href="<?= e($paynowRedirectUrl) ?>" rel="noreferrer">Opłać zamówienie</a>
            <?php else: ?>
              <form method="post" action="/sklep/paynow/create-payment"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="order_id" value="<?= e($order['orderId'] ?? '') ?>"><input type="hidden" name="confirmation_token" value="<?= e($confirmationToken) ?>"><button class="btn" type="submit">Przygotuj płatność Paynow</button></form>
            <?php endif; ?>
          </section>
        <?php endif; ?>

        <section class="confirmation-section">
          <h2>Produkty</h2>
          <div class="confirmation-items">
            <?php foreach (($order['items'] ?? []) as $item): ?>
              <div class="confirmation-item">
                <strong><?= e($item['name'] ?? '') ?></strong>
                <span><?= e((string)($item['quantity'] ?? 1)) ?> szt. × <?= e(shop_test_price_label((float)($item['unitPrice'] ?? $item['price'] ?? 0))) ?></span>
                <span><?= e(shop_test_price_label((float)($item['lineTotal'] ?? 0))) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <div class="confirmation-grid">
          <section>
            <h2>Dane klienta</h2>
            <?php $customer = is_array($order['customer'] ?? null) ? $order['customer'] : []; ?>
            <?php $address = is_array($order['deliveryAddress'] ?? null) ? $order['deliveryAddress'] : []; ?>
            <p><?= e(trim((string)($customer['firstName'] ?? '') . ' ' . (string)($customer['lastName'] ?? ''))) ?><br><?= e($customer['email'] ?? '') ?><br><?= e($customer['phone'] ?? '') ?></p>
            <p><?= e($address['street'] ?? '') ?><br><?= e(trim((string)($address['postalCode'] ?? '') . ' ' . (string)($address['city'] ?? ''))) ?><br><?= e($address['country'] ?? '') ?></p>
          </section>
          <section>
            <h2>Dostawa</h2>
            <?php $delivery = is_array($order['delivery'] ?? null) ? $order['delivery'] : []; ?>
            <p><strong><?= e($delivery['label'] ?? 'Dostawa') ?></strong><br><?= e($delivery['costLabel'] ?? 'do ustalenia') ?></p>
            <p><strong>Razem:</strong> <?= e(shop_test_price_label((float)($order['total'] ?? 0))) ?><?= ($order['deliveryCost'] ?? null) === null ? ' + dostawa do ustalenia' : '' ?></p>
          </section>
        </div>

        <div class="shop-actions">
          <a class="btn" href="<?= e(shop_catalog_url()) ?>">Wróć do sklepu</a>
        </div>
      </section>
      <script>try { localStorage.removeItem("hgo-shop-test-cart"); } catch (error) {}</script>
    <?php else: ?>
      <section class="success-box error-box">
        <p class="eyebrow">Nie znaleziono zamówienia</p>
        <h1>Nie udało się odczytać zamówienia</h1>
        <p>Nie można otworzyć tego potwierdzenia zamówienia.</p>
        <div class="shop-actions">
          <a class="btn" href="<?= e(shop_catalog_url()) ?>">Wróć do sklepu</a>
        </div>
      </section>
    <?php endif; ?>
  </main>
  <?php shop_test_footer(); ?>
</body>
</html>
