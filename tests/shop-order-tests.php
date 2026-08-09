<?php
declare(strict_types=1);

require __DIR__ . '/../hosting/getspace/shop-test/lib.php';

function test_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        test_fail($message);
    }
}

function test_throws(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return;
    }
    test_fail($message);
}

function test_customer_post(array $overrides = []): array
{
    return array_merge([
        'customer_first_name' => 'Jan',
        'customer_last_name' => 'Kowalski',
        'customer_email' => 'jan.kowalski@example.test',
        'customer_phone' => '+48500100200',
        'delivery_street' => 'Przykładowa 1',
        'delivery_postal_code' => '00-001',
        'delivery_city' => 'Warszawa',
        'delivery_country' => 'PL',
        'customer_notes' => 'Test zamówienia',
    ], $overrides);
}

test_assert(shop_sales_enabled(), 'Test musi działać z HGO_SHOP_SALES_ENABLED=true.');
boot_admin();

// Customer-facing delivery code deliberately does not fall back to defaults.
// Give this isolated test an explicit admin-cennik fixture instead.
save_shipping_profiles([
    [
        'id' => 'kurier-standardowy',
        'name' => 'Kurier standardowy',
        'customerName' => 'Kurier standardowy',
        'price' => 24.99,
        'active' => true,
        'sortOrder' => 10,
    ],
    [
        'id' => 'dostawa-indywidualna',
        'name' => 'Dostawa indywidualna',
        'customerName' => 'Dostawa indywidualna',
        'price' => null,
        'requiresConfirmation' => true,
        'active' => true,
        'sortOrder' => 20,
    ],
]);

// Fixture keeps this test independent from the editable production catalog.
$product = array_merge(product_defaults(), [
    '_shopSlug' => 'figura-testowa',
    'slug' => 'figura-testowa',
    'name' => 'Figura testowa',
    'saleType' => 'garden_figure',
    'shopVisible' => true,
    'shopStatus' => 'Dostępny',
    'productStatus' => 'Aktywny',
    'status' => 'Dostępne',
    'grossPrice' => '199,99',
    'shippingProfileIds' => ['kurier-standardowy'],
]);
$secondProduct = array_merge($product, [
    '_shopSlug' => 'figura-testowa-druga',
    'slug' => 'figura-testowa-druga',
    'name' => 'Druga figura testowa',
    'grossPrice' => '100,01',
]);
$products = [
    (string)$product['_shopSlug'] => $product,
    (string)$secondProduct['_shopSlug'] => $secondProduct,
];
test_assert(shop_test_is_figure($product), 'Fixture produktu nie kwalifikuje się do sprzedaży.');
test_assert(shop_test_delivery_methods($product) !== [], 'Fixture nie ma dostępnej metody dostawy.');

$slug = (string)$product['_shopSlug'];
$payload = json_encode([
    'items' => [[
        'slug' => $slug,
        'quantity' => 1,
        'price' => 0.01,
        'name' => 'Zmodyfikowana nazwa klienta',
    ]],
]);
$cart = shop_test_decode_cart((string)$payload, $products);
$expectedPrice = shop_test_price_number($product['grossPrice'] ?? '');
test_assert($cart['items'][0]['price'] === $expectedPrice, 'Cena z payloadu klienta nie została zignorowana.');
test_assert($cart['items'][0]['lineTotalCents'] === shop_test_price_cents($expectedPrice), 'Nieprawidłowa suma pozycji w groszach.');

$multiCart = shop_test_decode_cart((string)json_encode(['items' => [
    ['slug' => $slug, 'quantity' => 2],
    ['slug' => $secondProduct['_shopSlug'], 'quantity' => 3],
]]), $products);
$multiItemsCents = array_sum(array_map(static fn(array $item): int => (int)$item['lineTotalCents'], $multiCart['items']));
test_assert($multiCart['items'][0]['lineTotalCents'] === 39998, 'Błędna suma pierwszej pozycji koszyka wielopozycyjnego.');
test_assert($multiCart['items'][1]['lineTotalCents'] === 30003, 'Błędna suma drugiej pozycji koszyka wielopozycyjnego.');
test_assert($multiItemsCents === 70001, 'Błędna suma pozycji koszyka wielopozycyjnego.');

foreach ([0, -1, 1.5, 'abc', 21, '999999999'] as $quantity) {
    test_throws(static function () use ($slug, $quantity, $products): void {
        shop_test_decode_cart((string)json_encode(['items' => [['slug' => $slug, 'quantity' => $quantity]]]), $products);
    }, 'Nieprawidłowe quantity zostało zaakceptowane: ' . var_export($quantity, true));
}

test_throws(static function () use ($slug, $products): void {
    shop_test_decode_cart((string)json_encode(['items' => [
        ['slug' => $slug, 'quantity' => 1],
        ['slug' => $slug, 'quantity' => 1],
    ]]), $products);
}, 'Powtórzony produkt został zaakceptowany.');

foreach (['nieistniejacy-produkt', $slug . '-ukryty'] as $invalidSlug) {
    test_throws(static function () use ($invalidSlug, $products): void {
        shop_test_decode_cart((string)json_encode(['items' => [['slug' => $invalidSlug, 'quantity' => 1]]]), $products);
    }, 'Niedostępny produkt został zaakceptowany.');
}

$hiddenProduct = $product;
$hiddenProduct['shopVisible'] = false;
test_assert(!shop_test_is_figure($hiddenProduct), 'Ukryty produkt nadal kwalifikuje się do sprzedaży.');
$unavailableProduct = $product;
$unavailableProduct['shopStatus'] = 'Niedostępny';
test_assert(!shop_test_is_figure($unavailableProduct), 'Niedostępny produkt nadal kwalifikuje się do sprzedaży.');
$invalidPriceProducts = $products;
$invalidPriceProducts[$slug]['grossPrice'] = 'brak ceny';
test_throws(static function () use ($slug, $invalidPriceProducts): void {
    shop_test_decode_cart((string)json_encode(['items' => [['slug' => $slug, 'quantity' => 1]]]), $invalidPriceProducts);
}, 'Produkt bez poprawnej ceny został zaakceptowany.');

$_POST = test_customer_post();
$customer = shop_test_customer_from_post();
test_assert($customer['customer']['email'] === 'jan.kowalski@example.test', 'Nie odczytano danych klienta.');
foreach ([
    ['customer_email' => ''],
    ['customer_email' => 'nie-e-mail'],
    ['customer_first_name' => ''],
    ['customer_last_name' => ''],
    ['delivery_street' => ''],
] as $invalidCustomer) {
    $_POST = test_customer_post($invalidCustomer);
    test_throws(static fn() => shop_test_customer_from_post(), 'Nieprawidłowe dane klienta zostały zaakceptowane.');
}

$_POST = [];
test_throws(static fn() => require_csrf(), 'Brak CSRF został zaakceptowany.');
$_POST = ['csrf' => 'nieprawidlowy'];
test_throws(static fn() => require_csrf(), 'Błędny CSRF został zaakceptowany.');
$_POST = [];
test_throws(static fn() => shop_test_require_terms(), 'Brak akceptacji regulaminu został zaakceptowany.');
$_POST = ['terms' => '1'];
shop_test_require_terms();

$deliveryMethods = shop_test_cart_common_delivery($cart['items']);
$delivery = reset($deliveryMethods);
test_assert(($delivery['pricingType'] ?? '') === 'fixed_price', 'Test zamówienia wymaga dostawy fixed_price.');
$shippingCents = shop_test_price_cents((float)$delivery['costNumber']);
$itemCents = (int)$cart['items'][0]['lineTotalCents'];
$now = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
$order = shop_create_order([
    'orderId' => '',
    'createdAt' => $now,
    'updatedAt' => $now,
    'status' => 'new',
    'orderStatus' => 'new',
    'customer' => $customer['customer'],
    'deliveryAddress' => $customer['deliveryAddress'],
    'invoice' => $customer['invoice'],
    'items' => [[
        'productId' => $slug,
        'quantity' => 1,
        'unitPriceCents' => (int)$cart['items'][0]['priceCents'],
        'lineTotalCents' => $itemCents,
    ]],
    'productsTotalCents' => $itemCents,
    'deliveryCostCents' => $shippingCents,
    'totalCents' => $itemCents + $shippingCents,
    'paymentProvider' => '',
    'paymentId' => '',
    'paymentStatus' => 'not_started',
]);
test_assert((string)$order['orderId'] !== '' && $order['orderId'] === $order['order_id'], 'Nie nadano poprawnego order_id.');
test_assert(is_file(shop_order_file((string)$order['orderId'])), 'Nie zapisano testowego JSON zamówienia.');
test_assert($order['totalCents'] === $order['productsTotalCents'] + $order['deliveryCostCents'], 'Suma zamówienia w groszach jest błędna.');

$transfer = shop_bank_transfer_details((string)$order['orderId']);
test_assert(($transfer['recipient'] ?? '') !== '' && preg_match('/^PL\d{26}$/', (string)($transfer['accountNumber'] ?? '')) === 1, 'Brakuje poprawnej konfiguracji rachunku przelewu.');
test_assert(($transfer['transferTitle'] ?? '') === 'Zamówienie ' . $order['orderId'], 'Tytuł przelewu nie bazuje na order_id.');
test_assert(!empty(shop_payment_methods()['bank_transfer']) && empty(shop_payment_methods()['paynow']), 'Metody płatności nie są niezależnie skonfigurowane.');

$bankOrder = shop_create_order([
    'orderId' => '', 'createdAt' => $now, 'updatedAt' => $now,
    'status' => 'awaiting_payment', 'orderStatus' => 'awaiting_payment',
    'paymentProvider' => 'bank_transfer', 'paymentMethod' => 'bank_transfer', 'paymentStatus' => 'awaiting',
    'totalCents' => 22498,
]);
test_assert(shop_mark_bank_transfer_paid((string)$bankOrder['orderId'], 'test-admin') === true, 'Administrator nie oznaczył przelewu jako opłaconego.');
$paidBankOrder = shop_load_order((string)$bankOrder['orderId']);
test_assert(($paidBankOrder['orderStatus'] ?? '') === 'paid' && ($paidBankOrder['paymentStatus'] ?? '') === 'paid' && !empty($paidBankOrder['paymentConfirmedAt']), 'Potwierdzenie przelewu nie zapisało statusów.');
test_assert(shop_mark_bank_transfer_paid((string)$bankOrder['orderId'], 'test-admin') === false, 'Powtórne potwierdzenie przelewu nie jest idempotentne.');

$quoteOrder = shop_create_order(['orderId' => '', 'createdAt' => $now, 'updatedAt' => $now, 'status' => 'awaiting_shipping_quote', 'orderStatus' => 'awaiting_shipping_quote', 'paymentProvider' => 'bank_transfer', 'paymentStatus' => 'not_started', 'productsTotalCents' => 19999, 'totalCents' => 19999, 'customer' => ['email' => 'client@example.test'], 'items' => [['name' => 'Figura', 'quantity' => 1, 'unitPriceCents' => 19999]], 'delivery' => ['label' => 'Paleta']]);
$quoteMessages = [];
test_assert(shop_set_shipping_quote((string)$quoteOrder['orderId'], '150,00', 'test-admin', static function ($to, $subject, $body, $headers) use (&$quoteMessages): bool { $quoteMessages[] = $body; return true; }), 'Nie ustalono kosztu dostawy.');
$quoted = shop_load_order((string)$quoteOrder['orderId']);
test_assert(($quoted['totalCents'] ?? 0) === 34999 && ($quoted['orderStatus'] ?? '') === 'awaiting_payment' && ($quoted['paymentStatus'] ?? '') === 'awaiting', 'Wycena dostawy nie wyliczyła poprawnej kwoty lub statusu.');
test_assert(str_contains($quoteMessages[0] ?? '', 'Rachunek:'), 'E-mail po wycenie nie zawiera danych przelewu.');
test_assert(shop_set_shipping_quote((string)$quoteOrder['orderId'], '999,00', 'test-admin') === false, 'Ponowne ustalenie kosztu nie jest idempotentne.');

$mailOrder = $bankOrder + ['orderId' => 'HGO-20260809-0001', 'customer' => ['email' => 'client@example.test'], 'items' => [['name' => 'Figura', 'quantity' => 1, 'unitPriceCents' => 19999]], 'delivery' => ['label' => 'Kurier'], 'deliveryCostCents' => 2499, 'totalCents' => 22498];
$sentMessages = [];
$mailResult = shop_send_order_emails($mailOrder, static function (string $to, string $subject, string $body, string $headers) use (&$sentMessages): bool { $sentMessages[] = compact('to', 'subject', 'body', 'headers'); return true; });
test_assert($mailResult['customer'] && $mailResult['admin'] && count($sentMessages) === 2 && str_contains($sentMessages[0]['body'], 'Razem: 224,98 PLN'), 'E-mail przelewu nie zawiera backendowej kwoty.');
$quoteMail = $mailOrder; $quoteMail['orderStatus'] = 'awaiting_shipping_quote';
$quoteLines = shop_order_email_lines($quoteMail, false);
test_assert(!str_contains(implode("\n", $quoteLines), 'Rachunek:') && !str_contains(implode("\n", $quoteLines), 'Razem:'), 'E-mail wyceny zawiera dane przelewu lub finalną kwotę.');

$quote = shop_test_individual_delivery();
test_assert(($quote['pricingType'] ?? '') === 'quote_required' && ($quote['costNumber'] ?? null) === null, 'Dostawa indywidualna nie została oznaczona jako quote_required.');

$workers = [];
for ($index = 0; $index < 6; $index++) {
    $workers[] = proc_open([PHP_BINARY, __DIR__ . '/shop-order-worker.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    test_assert(is_resource($workers[$index]), 'Nie udało się uruchomić procesu współbieżnego.');
    $workerPipes[$index] = $pipes;
}
$ids = [];
foreach ($workers as $index => $worker) {
    $ids[] = trim((string)stream_get_contents($workerPipes[$index][1]));
    $error = trim((string)stream_get_contents($workerPipes[$index][2]));
    fclose($workerPipes[$index][1]);
    fclose($workerPipes[$index][2]);
    test_assert(proc_close($worker) === 0 && $error === '', 'Proces współbieżny zakończył się błędem: ' . $error);
}
test_assert(count(array_unique($ids)) === count($ids) && !in_array('', $ids, true), 'Współbieżne zamówienia otrzymały kolidujące identyfikatory.');
foreach ($ids as $id) {
    test_assert(is_file(shop_order_file($id)), 'Brakuje pliku zamówienia z testu współbieżności.');
}

echo "PASS: shop order tests\n";
