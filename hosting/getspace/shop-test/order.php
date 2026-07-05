<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
shop_test_boot();

$error = '';
$order = null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Zamówienie można złożyć tylko formularzem sklepu testowego.');
    }
    require_csrf();
    if (empty($_POST['terms']) || empty($_POST['privacy'])) {
        throw new RuntimeException('Potwierdź zgody wymagane do zamówienia testowego.');
    }

    $products = shop_test_product_map();
    $cart = shop_test_decode_cart((string)($_POST['cart_payload'] ?? ''), $products);
    $deliveryMethods = shop_test_cart_common_delivery($cart['items']);
    $deliveryKey = $cart['delivery'] !== '' ? $cart['delivery'] : array_key_first($deliveryMethods);
    if (!isset($deliveryMethods[$deliveryKey])) {
        throw new RuntimeException('Wybrana metoda dostawy nie pasuje do produktów w koszyku.');
    }

    $productTotal = 0.0;
    $items = [];
    foreach ($cart['items'] as $row) {
        $product = $row['product'];
        $productTotal += $row['lineTotal'];
        $items[] = [
            'slug' => $row['slug'],
            'name' => (string)($product['name'] ?? $row['slug']),
            'sku' => (string)($product['sku'] ?? ''),
            'quantity' => $row['quantity'],
            'price' => $row['price'],
            'lineTotal' => $row['lineTotal'],
        ];
    }

    $delivery = $deliveryMethods[$deliveryKey];
    $deliveryCost = $delivery['costNumber'];
    $total = $deliveryCost === null ? $productTotal : $productTotal + $deliveryCost;
    $orderId = shop_next_order_id();
    $now = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);

    $order = [
        'orderId' => $orderId,
        'createdAt' => $now,
        'updatedAt' => $now,
        'testMode' => true,
        'customer' => [
            'name' => shop_test_text_field('customer_name', 120),
            'email' => shop_test_text_field('customer_email', 160),
            'phone' => shop_test_text_field('customer_phone', 60),
            'address' => shop_test_text_field('customer_address', 180),
            'postalCode' => shop_test_text_field('customer_postal', 20),
            'city' => shop_test_text_field('customer_city', 120),
            'notes' => shop_test_text_field('customer_notes', 800),
        ],
        'items' => $items,
        'productsTotal' => round($productTotal, 2),
        'delivery' => [
            'method' => $delivery['method'],
            'label' => $delivery['label'],
            'cost' => $deliveryCost,
            'costLabel' => $deliveryCost === null ? 'do ustalenia' : shop_test_price_label($deliveryCost),
        ],
        'deliveryCost' => $deliveryCost,
        'total' => round($total, 2),
        'paymentMethod' => 'Brak płatności online — test',
        'paymentProvider' => '',
        'paymentId' => '',
        'paymentStatus' => 'Testowe bez płatności',
        'orderStatus' => 'Testowe',
        'internalNote' => '',
    ];

    foreach (['name', 'email', 'phone', 'address', 'postalCode', 'city'] as $required) {
        if (trim((string)$order['customer'][$required]) === '') {
            throw new RuntimeException('Uzupełnij wszystkie wymagane dane klienta.');
        }
    }
    if (!filter_var($order['customer']['email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Podaj poprawny adres e-mail.');
    }

    shop_save_order($order);
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
  <title>Zamówienie testowe | Home & Garden Outlet</title>
  <link rel="stylesheet" href="/sklep-test/shop.css">
</head>
<body>
  <main class="order-result">
    <?php if ($order && $error === ''): ?>
      <section class="success-box">
        <p class="eyebrow">Zamówienie testowe zapisane</p>
        <h1><?= e($order['orderId']) ?></h1>
        <p>Zamówienie trafiło do chronionej zakładki „Zamówienia sklepu” w panelu administratora. Nie ma tutaj płatności online.</p>
        <p><strong>Razem:</strong> <?= e(shop_test_price_label((float)$order['total'])) ?><?= $order['deliveryCost'] === null ? ' + dostawa do ustalenia' : '' ?></p>
        <div class="shop-actions"><a class="btn" href="/admin/?orders=1">Przejdź do zamówień</a><a class="btn btn-light" href="/sklep-test/figury-ogrodowe">Wróć do sklepu testowego</a></div>
      </section>
    <?php else: ?>
      <section class="success-box error-box">
        <p class="eyebrow">Nie zapisano zamówienia</p>
        <h1>Sprawdź koszyk</h1>
        <p><?= e($error !== '' ? $error : 'Wystąpił nieznany błąd.') ?></p>
        <a class="btn" href="/sklep-test/figury-ogrodowe">Wróć do sklepu testowego</a>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
