<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
shop_test_boot();

$orderId = shop_safe_order_id((string)($_GET['id'] ?? ''));
$order = $orderId !== '' ? shop_load_order($orderId) : null;
if (!$order) {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Potwierdzenie zamówienia | Home & Garden Outlet</title>
  <?php shop_test_stylesheets(); ?>
</head>
<body>
  <?php shop_test_header('figures'); ?>

  <main class="order-result-page">
    <?php if ($order): ?>
      <section class="success-box confirmation-box">
        <div class="admin-ribbon admin-ribbon-inline">Tryb testowy — sklep niepubliczny</div>
        <p class="eyebrow">Zamówienie zapisane</p>
        <h1>Dziękujemy za zamówienie</h1>
        <p>Zamówienie zostało zapisane. Obsługa Home &amp; Garden Outlet może je ręcznie potwierdzić przed uruchomieniem płatności online.</p>

        <div class="confirmation-grid">
          <section>
            <h2>Numer zamówienia</h2>
            <p class="order-number"><?= e($order['orderId'] ?? '') ?></p>
          </section>
          <section>
            <h2>Płatność</h2>
            <p><?= e($order['paymentStatus'] ?? 'Testowe bez płatności') ?></p>
          </section>
        </div>

        <section class="confirmation-section">
          <h2>Produkty</h2>
          <div class="confirmation-items">
            <?php foreach (($order['items'] ?? []) as $item): ?>
              <div class="confirmation-item">
                <strong><?= e($item['name'] ?? '') ?></strong>
                <span><?= e((string)($item['quantity'] ?? 1)) ?> szt. × <?= e(shop_test_price_label((float)($item['price'] ?? 0))) ?></span>
                <span><?= e(shop_test_price_label((float)($item['lineTotal'] ?? 0))) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <div class="confirmation-grid">
          <section>
            <h2>Dane klienta</h2>
            <?php $customer = is_array($order['customer'] ?? null) ? $order['customer'] : []; ?>
            <p><?= e($customer['name'] ?? '') ?><br><?= e($customer['email'] ?? '') ?><br><?= e($customer['phone'] ?? '') ?></p>
            <p><?= e($customer['address'] ?? '') ?><br><?= e(trim((string)($customer['postalCode'] ?? '') . ' ' . (string)($customer['city'] ?? ''))) ?></p>
          </section>
          <section>
            <h2>Dostawa</h2>
            <?php $delivery = is_array($order['delivery'] ?? null) ? $order['delivery'] : []; ?>
            <p><strong><?= e($delivery['label'] ?? 'Dostawa') ?></strong><br><?= e($delivery['costLabel'] ?? 'do ustalenia') ?></p>
            <p><strong>Razem:</strong> <?= e(shop_test_price_label((float)($order['total'] ?? 0))) ?><?= ($order['deliveryCost'] ?? null) === null ? ' + dostawa do ustalenia' : '' ?></p>
          </section>
        </div>

        <div class="shop-actions">
          <a class="btn" href="/sklep-test/figury-ogrodowe">Wróć do sklepu</a>
          <a class="btn btn-light" href="/sklep-test/figury-ogrodowe/koszyk">Koszyk</a>
        </div>
      </section>
      <script>try { localStorage.removeItem("hgo-shop-test-cart"); } catch (error) {}</script>
    <?php else: ?>
      <section class="success-box error-box">
        <p class="eyebrow">Nie znaleziono zamówienia</p>
        <h1>Nie udało się odczytać zamówienia</h1>
        <p>Nie udało się odczytać wskazanego zamówienia testowego.</p>
        <div class="shop-actions">
          <a class="btn" href="/sklep-test/figury-ogrodowe">Wróć do sklepu</a>
        </div>
      </section>
    <?php endif; ?>
  </main>
  <?php shop_test_footer(); ?>
</body>
</html>
