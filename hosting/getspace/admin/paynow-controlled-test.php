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
        'EXISTING_PAYMENT_USED' => false,
        'PAYNOW_HTTP_STATUS' => 'brak',
        'PAYMENT_ID_RECEIVED' => false,
        'REDIRECT_URL_RECEIVED' => false,
        'REDIRECT_ATTEMPTED' => false,
        'ERROR_STAGE' => 'brak',
        'ERROR_MESSAGE' => 'brak',
    ], $overrides);
}

function admin_paynow_store_diagnostic(string $orderId, array $diagnostic): void
{
    if ($orderId === '') {
        return;
    }
    $_SESSION['paynow_controlled_test_diagnostics'][$orderId] = $diagnostic;
}

function admin_paynow_order_diagnostic(string $orderId): array
{
    $diagnostics = $_SESSION['paynow_controlled_test_diagnostics'] ?? [];
    return is_array($diagnostics) && is_array($diagnostics[$orderId] ?? null)
        ? $diagnostics[$orderId]
        : admin_paynow_test_diagnostic();
}

function admin_paynow_safe_message(Throwable $exception): string
{
    $message = preg_replace('/[^\pL\pN .,:;_()\-\[\]]/u', '', $exception->getMessage()) ?: 'Nieznany błąd.';
    return function_exists('mb_substr') ? mb_substr($message, 0, 300, 'UTF-8') : substr($message, 0, 300);
}

function admin_paynow_saved_redirect_url(array $order): ?string
{
    if (trim((string)($order['paymentId'] ?? '')) === '') {
        return null;
    }
    $url = trim((string)($order['paymentRedirectUrl'] ?? ''));
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || !preg_match('/(?:^|\\.)paynow\\.pl$/', $host)) {
        return null;
    }
    return $url;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $diagnostic = admin_paynow_test_diagnostic(['POST_RECEIVED' => true]);
        require_csrf();
        $action = post_text('action');
        if ($action === 'prepare') {
            $stage = 'prepare_order';
            $now = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
            $testAmountCents = 2000;
            $order = shop_create_order([
                'orderId' => '', 'createdAt' => $now, 'updatedAt' => $now,
                'status' => 'awaiting_payment', 'orderStatus' => 'awaiting_payment',
                'customer' => ['firstName' => 'Test', 'lastName' => 'administracyjny', 'email' => 'biuro@mgoutlet.pl', 'phone' => '000000000'],
                'deliveryAddress' => ['street' => 'Test administracyjny', 'postalCode' => '00-000', 'city' => 'Wrocław', 'country' => 'PL'],
                'invoice' => ['requested' => false], 'customerNote' => '',
                'items' => [[
                    'productId' => 'admin-paynow-test-20-pln', 'name' => 'Test Paynow 20 zł (administracyjne)', 'quantity' => 1,
                    'unitPriceCents' => $testAmountCents, 'unitPrice' => shop_test_cents_to_price($testAmountCents),
                    'lineTotalCents' => $testAmountCents, 'lineTotal' => shop_test_cents_to_price($testAmountCents), 'currency' => 'PLN',
                ]],
                'productsTotalCents' => $testAmountCents, 'productsTotal' => shop_test_cents_to_price($testAmountCents),
                'shippingTotalCents' => 0, 'shippingTotal' => shop_test_cents_to_price(0),
                'delivery' => ['label' => 'Test administracyjny', 'pricingType' => 'fixed_price', 'requiresConfirmation' => false],
                'deliveryCostCents' => 0, 'deliveryCost' => shop_test_cents_to_price(0),
                'totalCents' => $testAmountCents, 'total' => shop_test_cents_to_price($testAmountCents), 'currency' => 'PLN',
                'paymentMethod' => 'paynow', 'paymentProvider' => 'paynow', 'paymentId' => '', 'paymentStatus' => 'not_started',
                'paynowAdminTest' => true, 'paynowAdminTestAmountCents' => $testAmountCents, 'internalNote' => 'Kontrolowany test produkcyjny Paynow 20,00 zł.',
            ]);
            admin_paynow_store_diagnostic((string)$order['orderId'], admin_paynow_test_diagnostic([
                'POST_RECEIVED' => true, 'ACTION_MATCHED' => true, 'ERROR_STAGE' => 'prepare_order',
            ]));
            header('Location: /admin/paynow-controlled-test.php?order_id=' . rawurlencode((string)$order['orderId']), true, 303);
            exit;
        }
        if ($action === 'start') {
            $diagnosticOrderId = shop_safe_order_id(post_text('order_id'));
            if ($diagnosticOrderId === '') throw new RuntimeException('Brak identyfikatora zamówienia testowego.');
            $diagnostic['ACTION_MATCHED'] = true;
            admin_paynow_store_diagnostic($diagnosticOrderId, $diagnostic);
            $stage = 'create_payment';
            $payment = paynow_start_admin_test_payment($diagnosticOrderId);
            $diagnostic['CREATE_PAYMENT_CALLED'] = empty($payment['existing']);
            $diagnostic['EXISTING_PAYMENT_USED'] = !empty($payment['existing']);
            $diagnostic['PAYNOW_HTTP_STATUS'] = !empty($payment['existing']) ? 'brak (istniejąca płatność)' : '201';
            $diagnostic['PAYMENT_ID_RECEIVED'] = !empty($payment['paymentId']);
            $diagnostic['REDIRECT_URL_RECEIVED'] = !empty($payment['redirectUrl']);
            admin_paynow_store_diagnostic($diagnosticOrderId, $diagnostic);
            if (headers_sent()) throw new RuntimeException('Nie można wykonać przekierowania do ekranu testowego.');
            header('Location: /admin/paynow-controlled-test.php?order_id=' . rawurlencode($diagnosticOrderId), true, 303);
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
    admin_paynow_store_diagnostic($diagnosticOrderId ?? '', $diagnostic);
    $error = ['stage' => $diagnostic['ERROR_STAGE'], 'message' => $diagnostic['ERROR_MESSAGE']];
}

$orderId = shop_safe_order_id((string)($_GET['order_id'] ?? ''));
$order = $orderId !== '' ? shop_load_order($orderId) : null;
$payload = $order && !empty($order['paynowAdminTest']) ? paynow_payment_payload($order) : null;
$itemsTotal = is_array($payload) ? array_sum(array_map(static fn(array $item): int => (int)$item['quantity'] * (int)$item['price'], $payload['orderItems'])) : null;
$payloadBody = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES) : '';
$canonicalPayload = is_string($payloadBody) ? paynow_canonical_payload(PAYNOW_API_KEY, paynow_idempotency_key($order ?? []), $payloadBody) : '';
$diagnostic = $orderId !== '' ? admin_paynow_order_diagnostic($orderId) : admin_paynow_test_diagnostic();
$savedRedirectUrl = is_array($order) ? admin_paynow_saved_redirect_url($order) : null;
$hasStoredIdempotencyKey = is_array($order) && !empty($order['paynowIdempotencyKey']);
?>
<!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow,noarchive"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kontrolowany test Paynow</title><link rel="stylesheet" href="/admin/style.css"></head>
<body><main class="narrow"><section class="card"><h1>Kontrolowany test Paynow</h1>
<?php if (!empty($error)): ?><p><strong>Stage:</strong> <?= e($error['stage']) ?><br><strong>Message:</strong> <?= e($error['message']) ?></p><?php elseif (!$order): ?>
  <p>Utworzy jedno chronione zamówienie techniczne administratora na 20,00 zł z ustaloną dostawą 0,00 zł. Nie tworzy płatności.</p>
  <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="prepare"><button class="btn" type="submit">Przygotuj test Paynow 20 zł</button></form>
<?php elseif (!empty($order['paynowAdminTest'])): ?>
  <p><strong>Zamówienie:</strong> <?= e($order['orderId']) ?><br><strong>Kwota:</strong> <?= e(shop_test_price_label((float)$order['total'])) ?></p>
  <p><strong>paymentId zapisany:</strong> <?= !empty($order['paymentId']) ? 'TAK' : 'NIE' ?><br><strong>redirectUrl zapisany:</strong> <?= !empty($order['paymentRedirectUrl']) ? 'TAK' : 'NIE' ?><br><strong>Idempotency-Key zapisany:</strong> <?= $hasStoredIdempotencyKey ? 'TAK' : 'NIE' ?></p>
  <?php if ($payload): ?><p><strong>Payload sanity:</strong><br>amount: <?= e((string)$payload['amount']) ?><br>currency: <?= e($payload['currency']) ?><br>externalId: <?= e($payload['externalId']) ?><br>description: <?= e($payload['description']) ?><br>buyer: e-mail, imię i nazwisko<br>orderItems suma: <?= e((string)$itemsTotal) ?><br>continueUrl: konfiguracja Paynow<br>notificationUrl: konfiguracja Paynow<br>body byte length: <?= e((string)strlen($payloadBody)) ?><br>canonical payload byte length: <?= e((string)strlen($canonicalPayload)) ?><br>SAME_BODY_USED_FOR_SIGNATURE_AND_REQUEST: TAK</p><?php endif; ?>
  <?php if ($savedRedirectUrl !== null): ?>
    <p>Istnieje już jedna kontrolowana płatność. Przejście dalej otworzy jej stronę Paynow bez tworzenia nowej.</p>
  <?php elseif (!empty($order['paymentId'])): ?>
    <p>Istniejąca płatność nie ma poprawnego, bezpiecznego adresu przekierowania Paynow. Link nie jest dostępny.</p>
  <?php elseif ($hasStoredIdempotencyKey): ?>
    <p>Wcześniejsza próba ma zapisany Idempotency-Key, ale brak paymentId. Dla bezpieczeństwa nie można utworzyć kolejnej płatności bez potwierdzenia stanu w Paynow.</p>
  <?php else: ?>
    <p>Płatność nie została jeszcze utworzona. Przejście dalej utworzy jedną płatność produkcyjną i otworzy stronę Paynow.</p>
  <?php endif; ?>
  <p><strong>Ostatnia diagnostyka POST:</strong><br><?php foreach ($diagnostic as $label => $value): ?><?= e($label) ?>: <?= is_bool($value) ? ($value ? 'TAK' : 'NIE') : e((string)$value) ?><br><?php endforeach; ?></p>
  <?php if ($savedRedirectUrl !== null): ?>
    <a class="btn" href="<?= e($savedRedirectUrl) ?>" rel="noreferrer">Przejdź do utworzonej płatności Paynow</a>
  <?php elseif (empty($order['paymentId']) && !$hasStoredIdempotencyKey): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="start"><input type="hidden" name="order_id" value="<?= e($order['orderId']) ?>"><button class="btn" type="submit">Utwórz płatność i przejdź do Paynow</button></form>
  <?php endif; ?>
<?php else: ?><p>Nie znaleziono kontrolowanego zamówienia testowego.</p><?php endif; ?>
</section></main></body></html>
