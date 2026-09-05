<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/paynow.php';
shop_test_boot();

$orderId = shop_safe_order_id((string)($_GET['order'] ?? ''));
$confirmationToken = (string)($_GET['token'] ?? '');
$order = shop_public_confirmation_order($orderId, $confirmationToken);
$confirmationUrl = $order ? shop_confirmation_url($orderId, $confirmationToken) : '';
$paymentConfirmed = is_array($order)
    && ($order['paymentStatus'] ?? '') === 'confirmed'
    && shop_order_is_paid($order);
$paymentFailed = is_array($order) && paynow_payment_failed($order);

header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, follow, noarchive');
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="robots" content="noindex,follow">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Status płatności | Home &amp; Garden Outlet</title>
  <?php shop_test_stylesheets(); ?>
</head>
<body>
  <?php shop_test_header(); ?>
  <main class="order-result">
    <section class="success-box<?= $paymentFailed ? ' error-box' : '' ?>">
      <p class="eyebrow">Płatność</p>
      <?php if ($paymentConfirmed): ?>
        <h1>Płatność została potwierdzona.</h1>
        <p>Paynow potwierdził płatność za zamówienie <?= e($orderId) ?>. Zamówienie zostało przekazane do dalszej realizacji.</p>
      <?php elseif ($paymentFailed): ?>
        <h1>Płatność nie została zakończona.</h1>
        <p>Nie udało się opłacić zamówienia <?= e($orderId) ?>. Możesz bezpiecznie utworzyć nową próbę płatności dla tego samego zamówienia.</p>
      <?php else: ?>
        <h1>Oczekujemy na potwierdzenie płatności.</h1>
        <p>Jedynym źródłem potwierdzenia jest zweryfikowane powiadomienie Paynow. Jeżeli płatność została wykonana przed chwilą, jej status może zostać zaktualizowany z krótkim opóźnieniem.</p>
      <?php endif; ?>
      <?php if ($confirmationUrl !== ''): ?>
        <div class="shop-actions"><a class="btn" href="<?= e($confirmationUrl) ?>"><?= $paymentFailed ? 'Ponów płatność' : 'Zobacz zamówienie' ?></a></div>
      <?php endif; ?>
    </section>
  </main>
  <?php shop_test_footer(); ?>
</body>
</html>
