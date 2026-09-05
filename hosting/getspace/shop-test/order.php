<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/paynow.php';
shop_test_boot();
shop_test_require_sales();
header('X-Robots-Tag: noindex, follow, noarchive');

$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Zamówienie można złożyć tylko formularzem sklepu testowego.');
    }
    require_csrf();
    shop_test_validate_checkout_customer_input();
    shop_test_require_terms();
    $submissionToken = (string)($_POST['checkout_submission_token'] ?? '');
    $existingOrderId = shop_test_checkout_existing_order($submissionToken);
    $existingConfirmationToken = shop_test_checkout_existing_confirmation_token($submissionToken);
    if ($existingOrderId !== '' && $existingConfirmationToken !== '') {
        header('Location: ' . shop_confirmation_url($existingOrderId, $existingConfirmationToken), true, 303);
        exit;
    }
    if ($submissionToken === '' || !hash_equals(shop_test_checkout_submission_token(), $submissionToken)) {
        throw new RuntimeException('Formularz zamówienia wygasł. Odśwież stronę i spróbuj ponownie.');
    }
    $customerData = shop_test_customer_from_post();
    $countryCode = $customerData['countryCode'];
    $foreignShipping = $countryCode !== 'PL';
    $paymentMethod = (string)($_POST['payment_method'] ?? '');
    if (!$foreignShipping && (!in_array($paymentMethod, ['bank_transfer', 'paynow'], true) || empty(shop_payment_methods()[$paymentMethod]))) {
        throw new RuntimeException('Wybrana metoda płatności nie jest dostępna.');
    }
    if ($foreignShipping) {
        $paymentMethod = null;
    }

    $products = shop_test_product_map();
    $cart = shop_test_decode_cart((string)($_POST['cart_payload'] ?? ''), $products);

    $productTotalCents = 0;
    $shippingTotalCents = 0;
    $quoteRequired = $foreignShipping;
    $items = [];
    foreach ($cart['items'] as $row) {
        $product = $row['product'];
        $shipping = shop_test_resolve_item_delivery($row);
        if ($foreignShipping) {
            $shipping['shippingUnitCents'] = null;
            $shipping['shippingLineCents'] = null;
            $shipping['shippingRequiresConfirmation'] = true;
        }
        $productTotalCents += (int)$row['lineTotalCents'];
        if ($shipping['shippingLineCents'] !== null) {
            $shippingTotalCents += (int)$shipping['shippingLineCents'];
        } else {
            $quoteRequired = true;
        }
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
        ] + $shipping;
    }

    $shippingTotalCents = $foreignShipping ? null : $shippingTotalCents;
    $totalCents = $shippingTotalCents === null ? null : $productTotalCents + $shippingTotalCents;
    $now = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
    $confirmationToken = shop_new_confirmation_token();

    $order = [
        'orderId' => '',
        'createdAt' => $now,
        'updatedAt' => $now,
        'status' => $quoteRequired ? 'awaiting_shipping_quote' : 'awaiting_payment',
        'orderStatus' => $quoteRequired ? 'awaiting_shipping_quote' : 'awaiting_payment',
        'countryCode' => $countryCode,
        'customer' => $customerData['customer'],
        'deliveryAddress' => $customerData['deliveryAddress'],
        'invoice' => $customerData['invoice'],
        'customerNote' => $customerData['customerNote'],
        'items' => $items,
        'productsTotal' => shop_test_cents_to_price($productTotalCents),
        'productsTotalCents' => $productTotalCents,
        'shippingTotalCents' => $shippingTotalCents,
        'shippingTotal' => $shippingTotalCents === null ? null : shop_test_cents_to_price($shippingTotalCents),
        'delivery' => ['label' => 'Dostawa per produkt', 'requiresConfirmation' => $quoteRequired, 'pricingType' => $quoteRequired ? 'quote_required' : 'fixed_price'],
        'deliveryCost' => $quoteRequired ? null : shop_test_cents_to_price($shippingTotalCents),
        'deliveryCostCents' => $quoteRequired ? null : $shippingTotalCents,
        'total' => $totalCents === null ? null : shop_test_cents_to_price($totalCents),
        'totalCents' => $totalCents,
        'currency' => 'PLN',
        'paymentMethod' => $paymentMethod,
        'paymentProvider' => $paymentMethod,
        'paymentId' => '',
        'paymentStatus' => $quoteRequired ? 'not_started' : ($paymentMethod === 'paynow' ? 'not_started' : 'awaiting'),
        'internalNote' => '',
        'confirmationTokenHash' => shop_confirmation_token_hash($confirmationToken),
    ];

    if (!$quoteRequired && $paymentMethod === 'bank_transfer') {
        $order['bankTransfer'] = shop_bank_transfer_details();
    }

    $order = shop_create_order($order);
    shop_test_remember_checkout_order($submissionToken, (string)$order['orderId'], $confirmationToken);
    if (!$quoteRequired && $paymentMethod === 'bank_transfer') {
        $order['bankTransfer'] = shop_bank_transfer_details((string)$order['orderId']);
        shop_save_order($order);
    }
    $sent = shop_send_order_emails($order);
    $order['emailNotifications'] = [
        'customerCreatedAt' => $sent['customer'] ? $now : null,
        'adminCreatedAt' => $sent['admin'] ? $now : null,
        'customerFailed' => !$sent['customer'],
        'adminFailed' => !$sent['admin'],
    ];
    shop_save_order($order);
    if ($paymentMethod === 'paynow' && !$quoteRequired) {
        try {
            paynow_start_payment((string)$order['orderId'], $confirmationToken);
        } catch (Throwable $paymentException) {
            paynow_record_start_failure((string)$order['orderId']);
            header('Location: ' . shop_confirmation_url((string)$order['orderId'], $confirmationToken), true, 303);
            exit;
        }
    }
    header('Location: ' . shop_confirmation_url((string)$order['orderId'], $confirmationToken), true, 303);
    exit;
} catch (ShopCheckoutValidationException $exception) {
    shop_test_checkout_remember_validation_error($exception->errors, $exception->oldInput);
    header('Location: ' . shop_catalog_url() . '/zamowienie', true, 303);
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
  <meta name="robots" content="noindex, follow">
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
