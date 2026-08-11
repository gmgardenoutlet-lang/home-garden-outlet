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

function test_checkout_customer_errors(array $overrides = []): array
{
    $_POST = test_customer_post($overrides);
    try {
        shop_test_validate_checkout_customer_input();
    } catch (ShopCheckoutValidationException $exception) {
        return $exception->errors;
    }
    return [];
}

test_assert(shop_sales_enabled(), 'Test musi działać z HGO_SHOP_SALES_ENABLED=true.');
test_assert(FOREIGN_SHIPPING_ENABLED === false, 'Dostawy zagraniczne muszą pozostać wyłączone publicznie.');
test_assert(array_keys(SHOP_ALLOWED_COUNTRIES) === ['PL', 'DE', 'CZ', 'SK', 'LT'], 'Centralna lista krajów nie jest kompletna.');
test_assert(shop_test_order_country_code(['deliveryAddress' => ['country' => 'DE']]) === 'DE', 'Nie odczytano kraju z adresu dostawy.');
test_assert(shop_test_order_country_code([]) === 'PL', 'Historyczne zamówienie bez countryCode nie ma fallbacku PL.');
test_assert(shop_test_normalize_phone_for_country('123 456 789', 'PL') === '+48123456789', 'Polska normalizacja telefonu uległa regresji.');
test_assert(shop_test_normalize_phone_for_country('+49 30 123456', 'DE') === '+4930123456', 'Telefon DE nie spełnia E.164.');
test_assert(shop_test_normalize_phone_for_country('030 123456', 'DE') === null, 'Telefon zagraniczny bez kodu kraju został zaakceptowany.');
test_assert(shop_test_normalize_postal_code_for_country('55080', 'PL') === '55-080', 'Polski kod pocztowy nie jest normalizowany.');
test_assert(shop_test_normalize_postal_code_for_country('10115', 'DE') === '10115', 'Zagraniczny kod pocztowy został odrzucony.');
boot_admin();
$checkoutSubmissionToken = shop_test_checkout_submission_token();
test_assert($checkoutSubmissionToken !== '', 'Brakuje tokenu idempotencji checkoutu.');
shop_test_remember_checkout_order($checkoutSubmissionToken, 'HGO-20260809-0001');
test_assert(shop_test_checkout_existing_order($checkoutSubmissionToken) === 'HGO-20260809-0001', 'Ponowny submit checkoutu nie wskazuje istniejącego zamówienia.');
test_assert(shop_test_checkout_submission_token() !== $checkoutSubmissionToken, 'Nowy checkout używa starego tokenu idempotencji.');

$errorInput = test_customer_post(['customer_phone' => '123456789', 'payment_method' => 'paynow', 'cart_payload' => json_encode(['items' => [['slug' => 'figura-testowa', 'shippingProfileId' => 'kurier-standardowy'], ['slug' => 'figura-testowa-druga', 'shippingProfileId' => 'kurier-sredni']]])]);
shop_test_checkout_remember_validation_error(['customer_email' => 'Testowy błąd pola e-mail'], $errorInput);
$rememberedErrors = shop_test_checkout_errors();
$rememberedInput = shop_test_checkout_old_input();
test_assert(($rememberedErrors['customer_email'] ?? '') === 'Testowy błąd pola e-mail' && ($rememberedInput['customer_first_name'] ?? '') === 'Jan' && ($rememberedInput['customer_phone'] ?? '') === '123456789', 'Error bag nie zachowuje danych kontaktowych.');
test_assert(($rememberedInput['payment_method'] ?? '') === 'paynow' && shop_test_checkout_payment_method($rememberedInput) === 'bank_transfer', 'Error bag nie zachowuje wyboru płatności lub nie odrzuca nieaktywnej metody.');
test_assert(($rememberedInput['shipping_selections']['figura-testowa'] ?? '') === 'kurier-standardowy' && ($rememberedInput['shipping_selections']['figura-testowa-druga'] ?? '') === 'kurier-sredni', 'Error bag nie zachowuje identyfikatorów dostawy per pozycja.');
test_assert(shop_load_orders() === [], 'Sam test error bag utworzył zamówienie.');

foreach (['jan@example.pl', 'biuro@firma.com', 'test.test+zamowienie@example.com'] as $email) {
    test_assert(test_checkout_customer_errors(['customer_email' => $email]) === [], 'Poprawny e-mail został odrzucony.');
}
test_assert(isset(test_checkout_customer_errors(['customer_email' => 'test@'])['customer_email']), 'Niepoprawny e-mail został zaakceptowany.');
test_assert(test_checkout_customer_errors(['customer_email' => ''])['customer_email'] === 'Podaj adres e-mail.', 'Pusty e-mail nie zwraca właściwego błędu.');

foreach (['123456789', '123 456 789', '123-456-789', '+48123456789', '+48 123 456 789', '0048 123456789'] as $phone) {
    test_assert(test_checkout_customer_errors(['customer_phone' => $phone]) === [], 'Poprawny polski telefon został odrzucony.');
    test_assert(($_POST['customer_phone'] ?? '') === '+48123456789', 'Telefon nie został znormalizowany do formatu +48.');
}
test_assert(test_checkout_customer_errors(['phone_prefix' => '+48', 'phone_number' => '600 402 939']) === [], 'Telefon PL w dwóch polach został odrzucony.');
test_assert(($_POST['customer_phone'] ?? '') === '+48600402939', 'Telefon PL z prefiksem nie został zapisany jako E.164.');
test_assert(test_checkout_customer_errors(['delivery_country' => 'DE', 'phone_prefix' => '+48', 'phone_number' => '30123456'])['customer_phone'] === 'Kod kierunkowy telefonu nie odpowiada wybranemu krajowi.', 'Niezgodny prefiks kraju nie został zablokowany.');
foreach (['12345', '1234567890', 'abc123456789', '+49 123456789'] as $phone) {
    test_assert(isset(test_checkout_customer_errors(['customer_phone' => $phone])['customer_phone']), 'Niepoprawny telefon został zaakceptowany.');
}
test_assert(test_checkout_customer_errors(['customer_phone' => ''])['customer_phone'] === 'Podaj numer telefonu.', 'Pusty telefon nie zwraca właściwego błędu.');

foreach (['55-080', '55080'] as $postalCode) {
    test_assert(test_checkout_customer_errors(['delivery_postal_code' => $postalCode]) === [], 'Poprawny kod pocztowy został odrzucony.');
    test_assert(($_POST['delivery_postal_code'] ?? '') === '55-080', 'Kod pocztowy nie został znormalizowany.');
}
foreach (['5-080', '5508', 'ABCDE'] as $postalCode) {
    test_assert(isset(test_checkout_customer_errors(['delivery_postal_code' => $postalCode])['delivery_postal_code']), 'Niepoprawny kod pocztowy został zaakceptowany.');
}
test_assert(test_checkout_customer_errors(['delivery_postal_code' => ''])['delivery_postal_code'] === 'Podaj kod pocztowy.', 'Pusty kod pocztowy nie zwraca właściwego błędu.');

$threeFieldErrors = test_checkout_customer_errors(['customer_email' => 'test@', 'customer_phone' => '123', 'delivery_postal_code' => 'abc']);
test_assert(count($threeFieldErrors) === 3 && isset($threeFieldErrors['customer_email'], $threeFieldErrors['customer_phone'], $threeFieldErrors['delivery_postal_code']), 'Trzy błędne pola nie zwracają trzech osobnych błędów.');
test_assert(shop_load_orders() === [], 'Walidacja błędnych danych utworzyła zamówienie.');

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
    [
        'id' => 'kurier-sredni',
        'name' => 'Kurier średni',
        'customerName' => 'Kurier średni',
        'price' => 35.00,
        'active' => true,
        'sortOrder' => 30,
    ],
    [
        'id' => 'odbior-osobisty',
        'name' => 'Odbiór osobisty',
        'customerName' => 'Odbiór osobisty',
        'price' => 0.00,
        'active' => true,
        'sortOrder' => 40,
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
    'shippingProfileIds' => ['kurier-sredni'],
]);
$products = [
    (string)$product['_shopSlug'] => $product,
    (string)$secondProduct['_shopSlug'] => $secondProduct,
];
test_assert(shop_test_is_figure($product), 'Fixture produktu nie kwalifikuje się do sprzedaży.');
test_assert(shop_test_delivery_methods($product) !== [], 'Fixture nie ma dostępnej metody dostawy.');

$slug = (string)$product['_shopSlug'];
$payload = json_encode([
    'shippingCost' => 0.01,
    'items' => [[
        'slug' => $slug,
        'quantity' => 1,
        'shippingProfileId' => 'kurier-standardowy',
        'price' => 0.01,
        'name' => 'Zmodyfikowana nazwa klienta',
    ]],
]);
$cart = shop_test_decode_cart((string)$payload, $products);
test_assert($cart['items'][0]['shippingProfileId'] === 'kurier-standardowy' && !isset($cart['shippingCost']), 'Koszt dostawy z przeglądarki nie został odrzucony.');
$itemShipping = shop_test_resolve_item_delivery($cart['items'][0]);
test_assert($itemShipping['shippingLineCents'] === 2499, 'Dostawa pozycji nie została policzona z profilu serwera.');

$perItemCart = shop_test_decode_cart((string)json_encode(['items' => [
    ['slug' => $slug, 'quantity' => 1, 'shippingProfileId' => 'kurier-standardowy'],
    ['slug' => $secondProduct['_shopSlug'], 'quantity' => 1, 'shippingProfileId' => 'kurier-sredni'],
]]), $products);
$perItemShipping = array_map('shop_test_resolve_item_delivery', $perItemCart['items']);
test_assert(array_sum(array_map(static fn(array $shipping): int => (int)$shipping['shippingLineCents'], $perItemShipping)) === 5999, 'Różne profile dostawy nie sumują się per produkt.');
$quantityShipping = shop_test_resolve_item_delivery(array_merge($cart['items'][0], ['quantity' => 2]));
test_assert($quantityShipping['shippingLineCents'] === 4998, 'Koszt dostawy nie mnoży się przez quantity.');
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

$paynowConfirmedOrder = shop_create_order([
    'orderId' => '', 'createdAt' => $now, 'updatedAt' => $now,
    'status' => 'paid', 'orderStatus' => 'paid', 'paymentProvider' => 'paynow', 'paymentMethod' => 'paynow',
    'paymentStatus' => 'confirmed', 'paymentId' => 'TEST-PAYNOW-ORDER', 'totalCents' => 2000,
]);
shop_update_order((string)$paynowConfirmedOrder['orderId'], 'W przygotowaniu', 'not_started', 'test realizacji');
$paynowAfterManualUpdate = shop_load_order((string)$paynowConfirmedOrder['orderId']);
test_assert(($paynowAfterManualUpdate['paymentStatus'] ?? '') === 'confirmed', 'Ręczny zapis cofnął potwierdzoną płatność Paynow.');
test_assert(($paynowAfterManualUpdate['orderStatus'] ?? '') === 'W przygotowaniu', 'Nie można zaktualizować realizacji zamówienia Paynow.');

shop_set_order_archived((string)$paynowConfirmedOrder['orderId'], true, 'test-admin');
$archivedPaynowOrder = shop_load_order((string)$paynowConfirmedOrder['orderId']);
test_assert(!empty($archivedPaynowOrder['archived']) && !empty($archivedPaynowOrder['archivedAt']) && ($archivedPaynowOrder['paymentStatus'] ?? '') === 'confirmed', 'Archiwizacja zmieniła płatność Paynow albo nie zapisała metadanych.');
shop_set_order_archived((string)$paynowConfirmedOrder['orderId'], false, 'test-admin');
$restoredPaynowOrder = shop_load_order((string)$paynowConfirmedOrder['orderId']);
test_assert(empty($restoredPaynowOrder['archived']) && !isset($restoredPaynowOrder['archivedAt']) && ($restoredPaynowOrder['paymentId'] ?? '') === ($paynowConfirmedOrder['paymentId'] ?? ''), 'Przywrócenie zmieniło dane płatności lub pozostawiło archiwum.');
test_throws(static fn() => shop_delete_test_order((string)$paynowConfirmedOrder['orderId'], (string)$paynowConfirmedOrder['orderId']), 'Zwykłe zamówienie zostało trwale usunięte.');
shop_mark_order_as_test((string)$paynowConfirmedOrder['orderId'], 'test-admin');
test_throws(static fn() => shop_delete_test_order((string)$paynowConfirmedOrder['orderId'], 'błędne-potwierdzenie'), 'Usunięcie testowe zaakceptowało błędne potwierdzenie.');
shop_delete_test_order((string)$paynowConfirmedOrder['orderId'], (string)$paynowConfirmedOrder['orderId']);
test_assert(shop_load_order((string)$paynowConfirmedOrder['orderId']) === null, 'Testowe zamówienie nie zostało trwale usunięte po potwierdzeniu.');

$quoteOrder = shop_create_order(['orderId' => '', 'createdAt' => $now, 'updatedAt' => $now, 'status' => 'awaiting_shipping_quote', 'orderStatus' => 'awaiting_shipping_quote', 'paymentProvider' => 'bank_transfer', 'paymentStatus' => 'not_started', 'productsTotalCents' => 19999, 'totalCents' => 19999, 'customer' => ['email' => 'client@example.test'], 'items' => [['name' => 'Figura', 'quantity' => 1, 'unitPriceCents' => 19999, 'shippingName' => 'Paleta', 'shippingRequiresConfirmation' => true, 'shippingUnitCents' => null, 'shippingLineCents' => null]], 'delivery' => ['label' => 'Paleta']]);
$quoteMessages = [];
test_assert(shop_set_item_shipping_quote((string)$quoteOrder['orderId'], 0, '150,00', 'test-admin', static function ($to, $subject, $body, $headers) use (&$quoteMessages): bool { $quoteMessages[] = $body; return true; }), 'Nie ustalono kosztu dostawy.');
$quoted = shop_load_order((string)$quoteOrder['orderId']);
test_assert(($quoted['totalCents'] ?? 0) === 34999 && ($quoted['orderStatus'] ?? '') === 'awaiting_payment' && ($quoted['paymentStatus'] ?? '') === 'awaiting', 'Wycena dostawy nie wyliczyła poprawnej kwoty lub statusu.');
test_assert(str_contains($quoteMessages[0] ?? '', 'Rachunek:'), 'E-mail po wycenie nie zawiera danych przelewu.');
test_assert(shop_set_item_shipping_quote((string)$quoteOrder['orderId'], 0, '999,00', 'test-admin') === false, 'Ponowne ustalenie kosztu nie jest idempotentne.');

$multiQuote = shop_create_order(['orderId' => '', 'createdAt' => $now, 'updatedAt' => $now, 'status' => 'awaiting_shipping_quote', 'orderStatus' => 'awaiting_shipping_quote', 'paymentProvider' => 'bank_transfer', 'paymentStatus' => 'not_started', 'productsTotalCents' => 30000, 'customer' => ['email' => 'client@example.test'], 'items' => [
    ['name' => 'Znana dostawa', 'quantity' => 1, 'shippingLineCents' => 2499, 'shippingRequiresConfirmation' => false],
    ['name' => 'Wycena A', 'quantity' => 2, 'shippingLineCents' => null, 'shippingRequiresConfirmation' => true],
    ['name' => 'Wycena B', 'quantity' => 1, 'shippingLineCents' => null, 'shippingRequiresConfirmation' => true],
], 'delivery' => ['label' => 'Per produkt']]);
test_assert(shop_set_item_shipping_quote((string)$multiQuote['orderId'], 1, '80,00', 'test-admin') === true, 'Nie zapisano pierwszej wyceny pozycji.');
$multiPartial = shop_load_order((string)$multiQuote['orderId']);
test_assert(($multiPartial['orderStatus'] ?? '') === 'awaiting_shipping_quote' && ($multiPartial['shippingTotalCents'] ?? 0) === 18499, 'Częściowa wycena zmieniła status lub znany koszt dostawy.');
test_assert(shop_set_item_shipping_quote((string)$multiQuote['orderId'], 2, '35,00', 'test-admin') === true, 'Nie zapisano drugiej wyceny pozycji.');
$multiFinal = shop_load_order((string)$multiQuote['orderId']);
test_assert(($multiFinal['orderStatus'] ?? '') === 'awaiting_payment' && ($multiFinal['shippingTotalCents'] ?? 0) === 21999 && ($multiFinal['totalCents'] ?? 0) === 51999, 'Ostatnia wycena nie wyliczyła poprawnych sum lub statusu.');

$mailOrder = $bankOrder + ['orderId' => 'HGO-20260809-0001', 'customer' => ['email' => 'client@example.test'], 'items' => [['name' => 'Figura', 'quantity' => 1, 'unitPriceCents' => 19999]], 'delivery' => ['label' => 'Kurier'], 'deliveryCostCents' => 2499, 'totalCents' => 22498];
$sentMessages = [];
$mailResult = shop_send_order_emails($mailOrder, static function (string $to, string $subject, string $body, string $headers) use (&$sentMessages): bool { $sentMessages[] = compact('to', 'subject', 'body', 'headers'); return true; });
test_assert($mailResult['customer'] && $mailResult['admin'] && count($sentMessages) === 2 && str_contains($sentMessages[0]['body'], 'Razem: 224,98 PLN'), 'E-mail przelewu nie zawiera backendowej kwoty.');
$quoteMail = $mailOrder; $quoteMail['orderStatus'] = 'awaiting_shipping_quote';
$quoteLines = shop_order_email_lines($quoteMail, false);
test_assert(!str_contains(implode("\n", $quoteLines), 'Rachunek:') && !str_contains(implode("\n", $quoteLines), 'Razem:'), 'E-mail wyceny zawiera dane przelewu lub finalną kwotę.');

$foreignOrder = shop_create_order(['orderId' => '', 'createdAt' => $now, 'updatedAt' => $now, 'countryCode' => 'DE', 'status' => 'awaiting_shipping_quote', 'orderStatus' => 'awaiting_shipping_quote', 'paymentProvider' => 'paynow', 'paymentMethod' => 'paynow', 'paymentStatus' => 'not_started', 'productsTotalCents' => 19999, 'shippingTotalCents' => null, 'deliveryCostCents' => null, 'totalCents' => null, 'customer' => ['email' => 'client@example.test'], 'deliveryAddress' => ['country' => 'DE'], 'items' => [['name' => 'Figura', 'quantity' => 1, 'unitPriceCents' => 19999, 'shippingRequiresConfirmation' => true, 'shippingLineCents' => null]], 'delivery' => ['pricingType' => 'quote_required', 'requiresConfirmation' => true]]);
test_assert(($foreignOrder['orderStatus'] ?? '') === 'awaiting_shipping_quote' && array_key_exists('shippingTotalCents', $foreignOrder) && $foreignOrder['shippingTotalCents'] === null && $foreignOrder['totalCents'] === null, 'Zagraniczne zamówienie udaje znany koszt lub sumę.');
$foreignQuoteLines = shop_order_email_lines($foreignOrder, false);
test_assert(str_contains(implode("\n", $foreignQuoteLines), 'Dostawa razem: koszt do potwierdzenia.') && !str_contains(implode("\n", $foreignQuoteLines), 'Razem:'), 'E-mail zagranicznej wyceny zawiera finalną kwotę.');

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
