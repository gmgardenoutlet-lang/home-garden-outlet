<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/../shop-test/lib.php';
require __DIR__ . '/../shop-test/paynow.php';
boot_admin();
require_login();

function admin_paynow_test_diagnostic(array $overrides = []): array
{
    return array_merge([
        'POST_RECEIVED' => false,
        'ACTION_MATCHED' => false,
        'CREATE_PAYMENT_CALLED' => false,
        'PAYNOW_HTTP_STATUS' => 'brak',
        'PAYMENT_ID_RECEIVED' => false,
        'REDIRECT_URL_RECEIVED' => false,
        'REDIRECT_ATTEMPTED' => false,
        'ERROR_STAGE' => 'brak',
        'ERROR_MESSAGE' => 'brak',
    ], $overrides);
}

function admin_paynow_safe_message(Throwable $exception): string
{
    $message = preg_replace('/[^\pL\pN .,:;_()\-\[\]]/u', '', $exception->getMessage()) ?: 'Nieznany błąd.';
    return function_exists('mb_substr') ? mb_substr($message, 0, 300, 'UTF-8') : substr($message, 0, 300);
}

function admin_paynow_test_candidate(): ?array
{
    $candidate = null;
    foreach (shop_test_products() as $product) {
        $price = shop_test_price_number($product['grossPrice'] ?? '');
        if ($price === null) continue;
        foreach (shop_test_delivery_methods($product) as $profileId => $method) {
            if (($method['pricingType'] ?? '') !== 'fixed_price') continue;
            $shipping = shop_test_resolve_item_delivery(['product' => $product, 'shippingProfileId' => $profileId, 'quantity' => 1]);
            if ($shipping['shippingLineCents'] === null) continue;
            $totalCents = (int)shop_test_price_cents($price) + (int)$shipping['shippingLineCents'];
            if ($candidate === null || $totalCents < $candidate['totalCents']) {
                $candidate = compact('product', 'shipping', 'totalCents');
            }
        }
    }
    return $candidate;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $diagnostic = admin_paynow_test_diagnostic(['POST_RECEIVED' => true]);
        require_csrf();
        $action = post_text('action');
        if ($action === 'prepare') {
            $_SESSION['paynow_controlled_test_diagnostic'] = admin_paynow_test_diagnostic(['POST_RECEIVED' => true, 'ACTION_MATCHED' => true, 'ERROR_STAGE' => 'prepare_order']);
            $stage = 'prepare_order';
            $candidate = admin_paynow_test_candidate();
            if (!$candidate) throw new RuntimeException('Brak produktu z ustalonym kosztem dostawy do testu.');
            $product = $candidate['product'];
            $shipping = $candidate['shipping'];
            $now = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
            $productCents = (int)shop_test_price_cents(shop_test_price_number($product['grossPrice'] ?? ''));
            $order = shop_create_order([
                'orderId' => '', 'createdAt' => $now, 'updatedAt' => $now,
                'status' => 'awaiting_payment', 'orderStatus' => 'awaiting_payment',
                'customer' => ['firstName' => 'Test', 'lastName' => 'administracyjny', 'email' => 'biuro@mgoutlet.pl', 'phone' => '000000000'],
                'deliveryAddress' => ['street' => 'Test administracyjny', 'postalCode' => '00-000', 'city' => 'Wrocław', 'country' => 'PL'],
                'invoice' => ['requested' => false], 'customerNote' => '',
                'items' => [[
                    'productId' => (string)($product['_shopSlug'] ?? ''), 'name' => (string)($product['name'] ?? ''), 'quantity' => 1,
                    'unitPriceCents' => $productCents, 'unitPrice' => shop_test_cents_to_price($productCents),
                    'lineTotalCents' => $productCents, 'lineTotal' => shop_test_cents_to_price($productCents), 'currency' => 'PLN',
                ] + $shipping],
                'productsTotalCents' => $productCents, 'productsTotal' => shop_test_cents_to_price($productCents),
                'shippingTotalCents' => (int)$shipping['shippingLineCents'], 'shippingTotal' => shop_test_cents_to_price((int)$shipping['shippingLineCents']),
                'delivery' => ['label' => (string)$shipping['shippingName'], 'pricingType' => 'fixed_price', 'requiresConfirmation' => false],
                'deliveryCostCents' => (int)$shipping['shippingLineCents'], 'deliveryCost' => shop_test_cents_to_price((int)$shipping['shippingLineCents']),
                'totalCents' => $candidate['totalCents'], 'total' => shop_test_cents_to_price($candidate['totalCents']), 'currency' => 'PLN',
                'paymentMethod' => 'paynow', 'paymentProvider' => 'paynow', 'paymentId' => '', 'paymentStatus' => 'not_started',
                'paynowAdminTest' => true, 'internalNote' => 'Kontrolowany test produkcyjny Paynow.',
            ]);
            header('Location: /admin/paynow-controlled-test.php?order_id=' . rawurlencode((string)$order['orderId']), true, 303);
            exit;
        }
        if ($action === 'start') {
            $diagnostic['ACTION_MATCHED'] = true;
            $diagnostic['CREATE_PAYMENT_CALLED'] = true;
            $_SESSION['paynow_controlled_test_diagnostic'] = $diagnostic;
            $stage = 'create_payment';
            $payment = paynow_start_admin_test_payment(shop_safe_order_id(post_text('order_id')));
            $diagnostic['PAYNOW_HTTP_STATUS'] = !empty($payment['existing']) ? 'brak (istniejąca płatność)' : '201';
            $diagnostic['PAYMENT_ID_RECEIVED'] = !empty($payment['paymentId']);
            $diagnostic['REDIRECT_URL_RECEIVED'] = !empty($payment['redirectUrl']);
            if (headers_sent()) throw new RuntimeException('Nie można wykonać przekierowania HTTP.');
            $diagnostic['REDIRECT_ATTEMPTED'] = true;
            $_SESSION['paynow_controlled_test_diagnostic'] = $diagnostic;
            header('Location: ' . $payment['redirectUrl'], true, 303);
            exit;
        }
        if ($action !== 'prepare' && $action !== 'start') throw new RuntimeException('Nieprawidłowa akcja formularza.');
    }
} catch (Throwable $e) {
    $message = admin_paynow_safe_message($e);
    $httpStatus = preg_match('/Paynow HTTP ([0-9]{3})/', $message, $match) ? $match[1] : 'brak';
    $diagnostic = ($diagnostic ?? admin_paynow_test_diagnostic());
    $diagnostic['PAYNOW_HTTP_STATUS'] = $httpStatus;
    $diagnostic['ERROR_STAGE'] = $stage ?? 'unknown';
    $diagnostic['ERROR_MESSAGE'] = $message;
    $_SESSION['paynow_controlled_test_diagnostic'] = $diagnostic;
    $error = ['stage' => $diagnostic['ERROR_STAGE'], 'message' => $diagnostic['ERROR_MESSAGE']];
}

$orderId = shop_safe_order_id((string)($_GET['order_id'] ?? ''));
$order = $orderId !== '' ? shop_load_order($orderId) : null;
$payload = $order && !empty($order['paynowAdminTest']) ? paynow_payment_payload($order) : null;
$itemsTotal = is_array($payload) ? array_sum(array_map(static fn(array $item): int => (int)$item['quantity'] * (int)$item['price'], $payload['orderItems'])) : null;
$payloadBody = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES) : '';
$canonicalPayload = is_string($payloadBody) ? paynow_canonical_payload(PAYNOW_API_KEY, paynow_idempotency_key($order ?? []), $payloadBody) : '';
$diagnostic = $_SESSION['paynow_controlled_test_diagnostic'] ?? admin_paynow_test_diagnostic();
?>
<!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow,noarchive"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kontrolowany test Paynow</title><link rel="stylesheet" href="/admin/style.css"></head>
<body><main class="narrow"><section class="card"><h1>Kontrolowany test Paynow</h1>
<?php if (!empty($error)): ?><p><strong>Stage:</strong> <?= e($error['stage']) ?><br><strong>Message:</strong> <?= e($error['message']) ?></p><?php elseif (!$order): ?>
  <p>Utworzy jedno normalne zamówienie testowe z najniższą aktualną ceną produktu i znanym kosztem dostawy. Nie tworzy płatności.</p>
  <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="prepare"><button class="btn" type="submit">Przygotuj zamówienie testowe</button></form>
<?php elseif (!empty($order['paynowAdminTest'])): ?>
  <p><strong>Zamówienie:</strong> <?= e($order['orderId']) ?><br><strong>Kwota:</strong> <?= e(shop_test_price_label((float)$order['total'])) ?></p>
  <p><strong>paymentId zapisany:</strong> <?= !empty($order['paymentId']) ? 'TAK' : 'NIE' ?><br><strong>redirectUrl zapisany:</strong> <?= !empty($order['paymentRedirectUrl']) ? 'TAK' : 'NIE' ?></p>
  <?php if ($payload): ?><p><strong>Payload sanity:</strong><br>amount: <?= e((string)$payload['amount']) ?><br>currency: <?= e($payload['currency']) ?><br>externalId: <?= e($payload['externalId']) ?><br>description: <?= e($payload['description']) ?><br>buyer: e-mail, imię i nazwisko<br>orderItems suma: <?= e((string)$itemsTotal) ?><br>continueUrl: konfiguracja Paynow<br>notificationUrl: konfiguracja Paynow<br>body byte length: <?= e((string)strlen($payloadBody)) ?><br>canonical payload byte length: <?= e((string)strlen($canonicalPayload)) ?><br>SAME_BODY_USED_FOR_SIGNATURE_AND_REQUEST: TAK</p><?php endif; ?>
  <p>Płatność nie została jeszcze utworzona. Przejście dalej utworzy jedną płatność produkcyjną i otworzy stronę Paynow.</p>
  <p><strong>Ostatnia diagnostyka POST:</strong><br><?php foreach ($diagnostic as $label => $value): ?><?= e($label) ?>: <?= is_bool($value) ? ($value ? 'TAK' : 'NIE') : e((string)$value) ?><br><?php endforeach; ?></p>
  <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="start"><input type="hidden" name="order_id" value="<?= e($order['orderId']) ?>"><button class="btn" type="submit">Utwórz płatność i przejdź do Paynow</button></form>
<?php else: ?><p>Nie znaleziono kontrolowanego zamówienia testowego.</p><?php endif; ?>
</section></main></body></html>
