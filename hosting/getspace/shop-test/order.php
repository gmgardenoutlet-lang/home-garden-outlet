<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
shop_test_boot();
shop_test_require_sales();

$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Zamówienie można złożyć tylko formularzem sklepu testowego.');
    }
    require_csrf();
    shop_test_require_terms();
    $paymentMethod = (string)($_POST['payment_method'] ?? '');
    if ($paymentMethod !== 'bank_transfer' || empty(shop_payment_methods()['bank_transfer'])) {
        throw new RuntimeException('Wybrana metoda płatności nie jest dostępna.');
    }

    $products = shop_test_product_map();
    $cart = shop_test_decode_cart((string)($_POST['cart_payload'] ?? ''), $products);
    $deliveryMethods = shop_test_cart_common_delivery($cart['items']);
    $deliveryKey = $cart['delivery'] !== '' ? $cart['delivery'] : array_key_first($deliveryMethods);
    if (!isset($deliveryMethods[$deliveryKey])) {
        throw new RuntimeException('Wybrana metoda dostawy nie pasuje do produktów w koszyku.');
    }

    $productTotalCents = 0;
    $items = [];
    foreach ($cart['items'] as $row) {
        $product = $row['product'];
        $productTotalCents += (int)$row['lineTotalCents'];
        $items[] = [
            'productId' => $row['slug'],
            'name' => (string)($product['name'] ?? $row['slug']),
            'sku' => (string)($product['sku'] ?? ''),
            'quantity' => $row['quantity'],
            'unitPrice' => shop_test_cents_to_price((int)$row['priceCents']),
            'unitPriceCents' => (int)$row['priceCents'],
            'lineTotal' => shop_test_cents_to_price((int)$row['lineTotalCents']),
            'lineTotalCents' => (int)$row['lineTotalCents'],
            'currency' => 'PLN',
        ];
    }

    $delivery = $deliveryMethods[$deliveryKey];
    $deliveryCost = $delivery['costNumber'];
    $deliveryCostCents = $deliveryCost === null ? null : shop_test_price_cents((float)$deliveryCost);
    $totalCents = $productTotalCents + ($deliveryCostCents ?? 0);
    $quoteRequired = ($delivery['pricingType'] ?? '') === 'quote_required' || $deliveryCostCents === null;
    $now = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
    $customerData = shop_test_customer_from_post();

    $order = [
        'orderId' => '',
        'createdAt' => $now,
        'updatedAt' => $now,
        'status' => $quoteRequired ? 'awaiting_shipping_quote' : 'awaiting_payment',
        'orderStatus' => $quoteRequired ? 'awaiting_shipping_quote' : 'awaiting_payment',
        'customer' => $customerData['customer'],
        'deliveryAddress' => $customerData['deliveryAddress'],
        'invoice' => $customerData['invoice'],
        'customerNote' => $customerData['customerNote'],
        'items' => $items,
        'productsTotal' => shop_test_cents_to_price($productTotalCents),
        'productsTotalCents' => $productTotalCents,
        'delivery' => [
            'method' => $delivery['method'],
            'profileId' => $delivery['profileId'] ?? $delivery['method'],
            'label' => $delivery['label'],
            'cost' => $deliveryCost,
            'costCents' => $deliveryCostCents,
            'costLabel' => (string)($delivery['cost'] ?? ($deliveryCost === null ? 'do ustalenia' : shop_test_price_label($deliveryCost))),
            'requiresConfirmation' => !empty($delivery['requiresConfirmation']),
            'priceFrom' => !empty($delivery['priceFrom']),
            'doUstalenia' => $deliveryCost === null || !empty($delivery['requiresConfirmation']),
            'pricingType' => $quoteRequired ? 'quote_required' : 'fixed_price',
        ],
        'deliveryCost' => $deliveryCost,
        'deliveryCostCents' => $deliveryCostCents,
        'total' => shop_test_cents_to_price($totalCents),
        'totalCents' => $totalCents,
        'currency' => 'PLN',
        'paymentMethod' => 'bank_transfer',
        'paymentProvider' => 'bank_transfer',
        'paymentId' => '',
        'paymentStatus' => $quoteRequired ? 'not_started' : 'awaiting',
        'internalNote' => '',
    ];

    if (!$quoteRequired) {
        $order['bankTransfer'] = shop_bank_transfer_details();
    }

    $order = shop_create_order($order);
    if (!$quoteRequired) {
        $order['bankTransfer'] = shop_bank_transfer_details((string)$order['orderId']);
        shop_save_order($order);
    }
    header('Location: ' . shop_catalog_url() . '/potwierdzenie?id=' . rawurlencode((string)$order['orderId']), true, 303);
    exit;
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Nie zapisano zamówienia | Home & Garden Outlet</title>
  <?php shop_test_stylesheets(); ?>
</head>
<body>
  <?php shop_test_header('cart'); ?>
  <main class="order-result">
    <section class="success-box error-box">
      <p class="eyebrow">Nie zapisano zamówienia</p>
      <h1>Sprawdź koszyk</h1>
      <p><?= e($error !== '' ? $error : 'Wystąpił nieznany błąd.') ?></p>
      <div class="shop-actions">
        <a class="btn" href="<?= e(shop_catalog_url()) ?>">Wróć do figur ogrodowych</a>
      </div>
    </section>
  </main>
  <?php shop_test_footer(); ?>
</body>
</html>
