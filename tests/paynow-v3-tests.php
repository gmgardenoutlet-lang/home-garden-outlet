<?php
declare(strict_types=1);

$paynowTestStorage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hgo-paynow-tests-' . bin2hex(random_bytes(6));
putenv('HGO_STORAGE_DIR=' . $paynowTestStorage);
putenv('HGO_SHOP_SALES_ENABLED=true');
putenv('HGO_PAYNOW_ENABLED=true');
putenv('HGO_PAYNOW_ENV=sandbox');
putenv('HGO_PAYNOW_API_KEY=test-api-key');
putenv('HGO_PAYNOW_SIGNATURE_KEY=test-signature-key');

require __DIR__ . '/../hosting/getspace/shop-test/lib.php';
require __DIR__ . '/../hosting/getspace/shop-test/paynow.php';

function paynow_test(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

function paynow_test_order(string $paymentId = ''): array
{
    $now = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
    return shop_create_order([
        'orderId' => '', 'createdAt' => $now, 'updatedAt' => $now,
        'status' => 'awaiting_payment', 'orderStatus' => 'awaiting_payment',
        'paymentMethod' => 'paynow', 'paymentProvider' => 'paynow',
        'paymentId' => $paymentId, 'paymentStatus' => $paymentId === '' ? 'not_started' : 'awaiting_payment',
        'paymentRedirectUrl' => $paymentId === '' ? '' : 'https://paywall.paynow.pl/' . rawurlencode($paymentId),
        'paynowIdempotencyKey' => $paymentId === '' ? '' : 'old-key-' . strtolower($paymentId),
        'customer' => ['email' => 'client@example.test'],
        'items' => [['productId' => 'figura', 'name' => 'Figura ogrodowa', 'quantity' => 1, 'unitPriceCents' => 12345]],
        'productsTotalCents' => 12345, 'shippingTotalCents' => 2499, 'deliveryCostCents' => 2499,
        'totalCents' => 14844, 'delivery' => ['pricingType' => 'fixed_price'],
    ]);
}

// Public sandbox vector published in Paynow V3 documentation; never production credentials.
$apiKey = '97a55694-5478-43b5-b406-fb49ebfdd2b5';
$signatureKey = 'b305b996-bca5-4404-a0b7-2ccea3d2b64b';
$idempotencyKey = 'd243fdb3-c287-484a-bb9c-58536f2794c1';
paynow_test(paynow_request_signature($apiKey, $signatureKey, $idempotencyKey, '') === 'fXwLZRwo0WiGll90PPl5oULX9VKA0gpFA/3+E+NRp5E=', 'Oficjalny wektor podpisu V3 nie pasuje.');
paynow_test(paynow_request_signature($apiKey, $signatureKey, $idempotencyKey, "\n") !== 'fXwLZRwo0WiGll90PPl5oULX9VKA0gpFA/3+E+NRp5E=', 'Dodatkowy newline nie zmienił podpisu.');
paynow_test(paynow_request_signature($apiKey, $signatureKey, $idempotencyKey . '-x', '') !== 'fXwLZRwo0WiGll90PPl5oULX9VKA0gpFA/3+E+NRp5E=', 'Inny Idempotency-Key nie zmienił podpisu.');
$notification = '{"paymentId":"TEST-000-000-000","externalId":"HGO-20260809-0001","status":"CONFIRMED","modifiedAt":"2026-08-11T12:00:00Z"}';
paynow_test(paynow_notification_signature($notification, $signatureKey) !== '', 'Podpis powiadomienia V3 nie został utworzony.');
paynow_test(!hash_equals(paynow_notification_signature($notification, $signatureKey), paynow_notification_signature($notification . ' ', $signatureKey)), 'Zmodyfikowane RAW BODY zachowało podpis.');
$notificationSource = (string)file_get_contents(__DIR__ . '/../hosting/getspace/shop-test/paynow-notification.php');
$signatureCheckPosition = strpos($notificationSource, 'paynow_verify_notification_signature');
$notificationProcessingPosition = strpos($notificationSource, 'paynow_process_notification');
paynow_test($signatureCheckPosition !== false && $notificationProcessingPosition !== false && $signatureCheckPosition < $notificationProcessingPosition, 'Obsługa webhooka uruchamia się przed weryfikacją podpisu.');
paynow_test(paynow_redirect_url('https://paywall.paynow.pl/TEST-000-000-000') !== null, 'Poprawny redirectUrl Paynow został odrzucony.');
paynow_test(paynow_redirect_url('https://example.test/payment') === null, 'Nie-Paynow redirectUrl został dopuszczony.');

$order = ['orderId' => 'HGO-20260809-0001', 'status' => 'new', 'paymentStatus' => 'not_started', 'totalCents' => 12345,
    'delivery' => ['pricingType' => 'fixed_price'], 'customer' => ['email' => 'test@example.test'], 'items' => [['productId' => 'a', 'name' => 'A', 'quantity' => 1, 'unitPriceCents' => 12345]]];
paynow_test(paynow_external_id($order) === 'HGO-20260809-0001', 'externalId nie jest powiązany z orderId.');
paynow_test(paynow_payment_payload($order)['amount'] === 12345, 'Kwota nie pochodzi z totalCents.');
$withShipping = $order; $withShipping['totalCents'] = 14844; $withShipping['shippingTotalCents'] = 2499;
$shippingPayload = paynow_payment_payload($withShipping);
paynow_test(array_sum(array_map(static fn(array $item): int => $item['quantity'] * $item['price'], $shippingPayload['orderItems'])) === $shippingPayload['amount'], 'Pozycje Paynow nie sumują się do backendowego totalCents.');
paynow_test(!array_key_exists('phone', $shippingPayload['buyer']), 'Telefon ma nieprawidłowy format dla Paynow V3.');
$continueUrl = paynow_continue_url('HGO-20260809-0001', 'bezpieczny-token');
paynow_test((paynow_payment_payload($withShipping, $continueUrl)['continueUrl'] ?? '') === $continueUrl, 'Indywidualny continueUrl nie trafił do żądania Paynow.');
$quote = $order; $quote['delivery']['pricingType'] = 'quote_required';
try { paynow_payment_payload($quote); paynow_test(false, 'quote_required dopuszczono do płatności.'); } catch (RuntimeException $e) {}
$foreignQuote = $order; $foreignQuote['countryCode'] = 'DE'; $foreignQuote['shippingTotalCents'] = null; $foreignQuote['totalCents'] = null; $foreignQuote['delivery']['pricingType'] = 'quote_required';
try { paynow_payment_payload($foreignQuote); paynow_test(false, 'Zagraniczną dostawę bez wyceny dopuszczono do płatności.'); } catch (RuntimeException $e) {}
$paid = $order + ['paymentId' => 'NOLV-8F9-08K-WGD']; $paid['paymentId'] = 'NOLV-8F9-08K-WGD';
$confirmed = paynow_apply_status($paid, 'NOLV-8F9-08K-WGD', 'HGO-20260809-0001', 'CONFIRMED');
paynow_test($confirmed['status'] === 'paid' && $confirmed['paymentStatus'] === 'confirmed', 'CONFIRMED nie ustawia paid.');
paynow_test(paynow_apply_status($confirmed, 'NOLV-8F9-08K-WGD', 'HGO-20260809-0001', 'PENDING') === $confirmed, 'PENDING cofnął CONFIRMED.');
$pending = paynow_apply_status($paid, 'NOLV-8F9-08K-WGD', 'HGO-20260809-0001', 'PENDING', '2026-08-11T12:00:00+00:00');
paynow_test(paynow_apply_status($pending, 'NOLV-8F9-08K-WGD', 'HGO-20260809-0001', 'NEW', '2026-08-11T11:59:00+00:00') === $pending, 'Starsze powiadomienie zmieniło status.');
paynow_test(paynow_apply_status($pending, 'NOLV-8F9-08K-WGD', 'HGO-20260809-0001', 'PENDING', '2026-08-11T12:00:00+00:00') === $pending, 'Powtórny webhook nie jest no-op.');

// A: the first messages confirm receipt, not payment.
$newOrder = paynow_test_order();
$receivedMessages = [];
$firstMailResult = shop_send_order_emails($newOrder, static function (string $to, string $subject, string $body, string $headers) use (&$receivedMessages): bool {
    $receivedMessages[] = compact('to', 'subject', 'body', 'headers');
    return true;
});
paynow_test(($newOrder['orderStatus'] ?? '') === 'awaiting_payment' && ($newOrder['paymentStatus'] ?? '') === 'not_started', 'Nowe zamówienie Paynow ma nieprawidłowy status.');
paynow_test($firstMailResult['customer'] && $firstMailResult['admin'] && count($receivedMessages) === 2, 'Pierwsze e-maile nie zostały przygotowane osobno dla klienta i sklepu.');
paynow_test($receivedMessages[0]['subject'] === 'Otrzymaliśmy zamówienie ' . $newOrder['orderId'] . ' | Home & Garden Outlet', 'Pierwszy e-mail klienta ma błędny temat.');
paynow_test($receivedMessages[1]['subject'] === 'Nowe zamówienie ' . $newOrder['orderId'] . ' – oczekuje na płatność', 'Pierwszy e-mail sklepu nie wskazuje oczekiwania na płatność.');
paynow_test(str_contains($receivedMessages[0]['body'], 'Zamówienie oczekuje na opłacenie.') && str_contains($receivedMessages[0]['body'], 'Realizacja zamówienia rozpocznie się po potwierdzeniu płatności.'), 'Pierwszy e-mail nie wyjaśnia stanu zamówienia Paynow.');
paynow_test(!str_contains($receivedMessages[0]['body'], 'została potwierdzona'), 'Pierwszy e-mail błędnie potwierdza płatność.');

// B/C: CONFIRMED sends exactly two durable notifications, even when repeated.
$confirmedOrder = paynow_test_order('CONFIRM-TEST-001');
$confirmedPayload = ['paymentId' => 'CONFIRM-TEST-001', 'externalId' => $confirmedOrder['orderId'], 'status' => 'CONFIRMED', 'modifiedAt' => '2026-09-05T10:00:00+00:00'];
$confirmedMessages = [];
$confirmationMailer = static function (string $to, string $subject, string $body, string $headers) use (&$confirmedMessages): bool {
    $confirmedMessages[] = compact('to', 'subject', 'body', 'headers');
    return true;
};
$confirmedResult = paynow_process_notification($confirmedPayload, $confirmationMailer);
paynow_test(($confirmedResult['status'] ?? '') === 'paid' && ($confirmedResult['paymentStatus'] ?? '') === 'confirmed', 'CONFIRMED nie zapisał paid / confirmed.');
paynow_test(count($confirmedMessages) === 2, 'CONFIRMED nie wysłał dokładnie dwóch powiadomień.');
paynow_test($confirmedMessages[0]['subject'] === 'Płatność za zamówienie ' . $confirmedOrder['orderId'] . ' została potwierdzona | Home & Garden Outlet', 'E-mail potwierdzający klienta ma błędny temat.');
paynow_test($confirmedMessages[1]['to'] === 'biuro@mgoutlet.pl' && $confirmedMessages[1]['subject'] === 'Zamówienie ' . $confirmedOrder['orderId'] . ' opłacone', 'Powiadomienie sklepu o płatności jest błędne.');
paynow_test(str_contains($confirmedMessages[0]['body'], 'Kwota: 148,44 PLN') && !str_contains($confirmedMessages[0]['body'], 'client@example.test'), 'Potwierdzenie płatności ma błędną kwotę lub ujawnia zbędne dane klienta.');
$persistedConfirmed = shop_load_order((string)$confirmedOrder['orderId']);
paynow_test(!empty($persistedConfirmed['emailNotifications']['customerPaymentConfirmedEmailSentAt']) && !empty($persistedConfirmed['emailNotifications']['adminPaymentConfirmedEmailSentAt']), 'Nie zapisano trwałych znaczników wysyłki potwierdzeń.');
paynow_process_notification($confirmedPayload, $confirmationMailer);
paynow_test(count($confirmedMessages) === 2, 'Powtórny CONFIRMED wysłał dodatkowy e-mail.');

// Non-success statuses never send a success message; terminal failures can start a fresh payment.
foreach (['REJECTED', 'ERROR', 'EXPIRED'] as $failedStatus) {
    $failedOrder = paynow_test_order('FAILED-' . $failedStatus);
    $failedMessages = [];
    $failedResult = paynow_process_notification([
        'paymentId' => 'FAILED-' . $failedStatus,
        'externalId' => $failedOrder['orderId'],
        'status' => $failedStatus,
        'modifiedAt' => '2026-09-05T10:10:00+00:00',
    ], static function () use (&$failedMessages): bool { $failedMessages[] = true; return true; });
    paynow_test(($failedResult['orderStatus'] ?? '') === 'payment_failed' && $failedMessages === [], $failedStatus . ' wysłał e-mail sukcesu lub ma zły status.');
    $oldKey = (string)$failedOrder['paynowIdempotencyKey'];
    $newPaymentId = 'RETRY-' . $failedStatus;
    $retryObserved = [];
    $retryResult = paynow_start_payment((string)$failedOrder['orderId'], 'safe-confirmation-token', static function (array $retryOrder, string $continueUrl) use (&$retryObserved, $newPaymentId): array {
        $retryObserved = ['externalId' => paynow_external_id($retryOrder), 'key' => (string)$retryOrder['paynowIdempotencyKey'], 'continueUrl' => $continueUrl];
        return ['paymentId' => $newPaymentId, 'redirectUrl' => 'https://paywall.paynow.pl/' . $newPaymentId, 'idempotencyKey' => (string)$retryOrder['paynowIdempotencyKey']];
    });
    $retriedOrder = shop_load_order((string)$failedOrder['orderId']);
    paynow_test($retryObserved['externalId'] === $failedOrder['orderId'], $failedStatus . ' zmienił externalId przy retry.');
    paynow_test($retryObserved['key'] !== '' && $retryObserved['key'] !== $oldKey, $failedStatus . ' nie otrzymał nowego klucza idempotencji.');
    paynow_test(str_contains($retryObserved['continueUrl'], rawurlencode('safe-confirmation-token')), $failedStatus . ' nie przekazał bezpiecznego continueUrl.');
    paynow_test($retryResult['paymentId'] === $newPaymentId && ($retriedOrder['paymentId'] ?? '') === $newPaymentId && ($retriedOrder['orderStatus'] ?? '') === 'awaiting_payment', $failedStatus . ' nie utworzył nowej próby dla tego samego zamówienia.');
    paynow_test(($retriedOrder['paynowAttempts'][0]['paymentId'] ?? '') === 'FAILED-' . $failedStatus, $failedStatus . ' nie zachował historii poprzedniej próby.');
}

// F: a delayed notification for archived attempt A cannot affect active attempt B, before or after B is confirmed.
$historicalOrder = paynow_test_order('HISTORICAL-A');
paynow_process_notification([
    'paymentId' => 'HISTORICAL-A', 'externalId' => $historicalOrder['orderId'],
    'status' => 'REJECTED', 'modifiedAt' => '2026-09-05T10:30:00+00:00',
]);
paynow_start_payment((string)$historicalOrder['orderId'], 'historical-token', static function (array $retryOrder): array {
    return ['paymentId' => 'HISTORICAL-B', 'redirectUrl' => 'https://paywall.paynow.pl/HISTORICAL-B', 'idempotencyKey' => (string)$retryOrder['paynowIdempotencyKey']];
});
$historicalMessages = [];
$historicalMailer = static function () use (&$historicalMessages): bool { $historicalMessages[] = true; return true; };
$afterOldA = paynow_process_notification([
    'paymentId' => 'HISTORICAL-A', 'externalId' => $historicalOrder['orderId'],
    'status' => 'CONFIRMED', 'modifiedAt' => '2026-09-05T10:31:00+00:00',
], $historicalMailer);
paynow_test(($afterOldA['paymentId'] ?? '') === 'HISTORICAL-B' && ($afterOldA['orderStatus'] ?? '') === 'awaiting_payment' && $historicalMessages === [], 'Opóźniony webhook A zmienił aktywną próbę B albo wysłał e-mail.');
$afterB = paynow_process_notification([
    'paymentId' => 'HISTORICAL-B', 'externalId' => $historicalOrder['orderId'],
    'status' => 'CONFIRMED', 'modifiedAt' => '2026-09-05T10:32:00+00:00',
], $historicalMailer);
paynow_test(($afterB['orderStatus'] ?? '') === 'paid' && count($historicalMessages) === 2, 'CONFIRMED dla aktualnej próby B nie zapisał płatności lub nie wysłał dwóch e-maili.');
$afterOldAPaid = paynow_process_notification([
    'paymentId' => 'HISTORICAL-A', 'externalId' => $historicalOrder['orderId'],
    'status' => 'PENDING', 'modifiedAt' => '2026-09-05T10:33:00+00:00',
], $historicalMailer);
paynow_test(($afterOldAPaid['paymentId'] ?? '') === 'HISTORICAL-B' && ($afterOldAPaid['orderStatus'] ?? '') === 'paid' && ($afterOldAPaid['paymentStatus'] ?? '') === 'confirmed' && count($historicalMessages) === 2, 'Stary webhook A cofnął potwierdzoną próbę B lub wysłał dodatkowy e-mail.');

// NEW/PENDING/ABANDONED also never send payment-confirmed mail.
foreach (['NEW', 'PENDING', 'ABANDONED'] as $nonSuccessStatus) {
    $nonSuccessOrder = paynow_test_order('NON-SUCCESS-' . $nonSuccessStatus);
    $nonSuccessMessages = [];
    paynow_process_notification([
        'paymentId' => 'NON-SUCCESS-' . $nonSuccessStatus,
        'externalId' => $nonSuccessOrder['orderId'],
        'status' => $nonSuccessStatus,
        'modifiedAt' => '2026-09-05T10:20:00+00:00',
    ], static function () use (&$nonSuccessMessages): bool { $nonSuccessMessages[] = true; return true; });
    paynow_test($nonSuccessMessages === [], $nonSuccessStatus . ' wysłał e-mail potwierdzający płatność.');
}

// H: a failed first API call keeps the order and the same idempotency key for a safe timeout retry.
$startFailureOrder = paynow_test_order();
$ordersBeforeFailure = count(shop_load_orders());
try {
    paynow_start_payment((string)$startFailureOrder['orderId'], 'start-failure-token', static function (): array { throw new RuntimeException('symulowany błąd API'); });
    paynow_test(false, 'Symulowany błąd utworzenia płatności nie został zgłoszony.');
} catch (RuntimeException $expected) {
    paynow_record_start_failure((string)$startFailureOrder['orderId']);
}
$afterStartFailure = shop_load_order((string)$startFailureOrder['orderId']);
paynow_test(is_array($afterStartFailure) && count(shop_load_orders()) === $ordersBeforeFailure, 'Błąd startu usunął zamówienie albo utworzył drugie.');
paynow_test(!empty($afterStartFailure['paymentStartFailedAt']), 'Błąd startu płatności nie został oznaczony.');
$uncertainKey = (string)($afterStartFailure['paynowIdempotencyKey'] ?? '');
$retryAfterStartFailure = paynow_start_payment((string)$startFailureOrder['orderId'], 'start-failure-token', static function (array $retryOrder): array {
    return ['paymentId' => 'AFTER-TIMEOUT-001', 'redirectUrl' => 'https://paywall.paynow.pl/AFTER-TIMEOUT-001', 'idempotencyKey' => (string)$retryOrder['paynowIdempotencyKey']];
});
paynow_test($uncertainKey !== '' && ($retryAfterStartFailure['paymentId'] ?? '') === 'AFTER-TIMEOUT-001', 'Nie udało się bezpiecznie ponowić pierwszego żądania Paynow.');
paynow_test(empty((shop_load_order((string)$startFailureOrder['orderId']))['paymentStartFailedAt']), 'Znacznik błędu nie został usunięty po udanym retry.');
$confirmationSource = (string)file_get_contents(__DIR__ . '/../hosting/getspace/shop-test/confirmation.php');
paynow_test(str_contains($confirmationSource, 'Zamówienie zostało zapisane, ale nie udało się uruchomić płatności online.'), 'Widok nie zawiera prawidłowego komunikatu po błędzie startu Paynow.');
$createPaymentSource = (string)file_get_contents(__DIR__ . '/../hosting/getspace/shop-test/paynow-create-payment.php');
paynow_test(str_contains($createPaymentSource, 'paynow_record_start_failure($orderId)') && str_contains($createPaymentSource, 'shop_confirmation_url($orderId, $confirmationToken)'), 'Ponowienie płatności nie wraca bezpiecznie do istniejącego zamówienia po błędzie API.');
$returnSource = (string)file_get_contents(__DIR__ . '/../hosting/getspace/shop-test/paynow-return.php');
paynow_test(str_contains($returnSource, "shop_public_confirmation_order(\$orderId, \$confirmationToken)"), 'Powrót Paynow nie wymaga tokenu przypisanego do zamówienia.');
paynow_test(str_contains($returnSource, 'Płatność została potwierdzona.') && str_contains($returnSource, 'Oczekujemy na potwierdzenie płatności.') && str_contains($returnSource, 'Ponów płatność'), 'Powrót Paynow nie rozróżnia bezpiecznie statusów płatności.');

// I/J: paid orders cannot be paid again and cannot be moved backwards by late events.
try {
    paynow_start_payment((string)$confirmedOrder['orderId'], 'paid-order-token', static fn(): array => []);
    paynow_test(false, 'Już opłacone zamówienie dopuściło nową płatność.');
} catch (RuntimeException $expected) {}
$lateResult = paynow_process_notification([
    'paymentId' => 'CONFIRM-TEST-001', 'externalId' => $confirmedOrder['orderId'],
    'status' => 'PENDING', 'modifiedAt' => '2026-09-05T09:59:00+00:00',
]);
paynow_test(($lateResult['orderStatus'] ?? '') === 'paid' && ($lateResult['paymentStatus'] ?? '') === 'confirmed', 'Starszy webhook cofnął opłacone zamówienie.');

// D: two separate PHP processes exercise the real per-order lock.
$parallelOrder = paynow_test_order('PARALLEL-001');
$parallelLog = $paynowTestStorage . DIRECTORY_SEPARATOR . 'parallel-mails.log';
putenv('HGO_TEST_PAYNOW_ORDER_ID=' . $parallelOrder['orderId']);
putenv('HGO_TEST_PAYNOW_PAYMENT_ID=PARALLEL-001');
putenv('HGO_TEST_PAYNOW_MAIL_LOG=' . $parallelLog);
$workers = [];
for ($index = 0; $index < 2; $index++) {
    $workers[] = proc_open([PHP_BINARY, __DIR__ . '/paynow-notification-worker.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    paynow_test(is_resource($workers[$index]), 'Nie uruchomiono równoległego webhooka.');
    $workerPipes[$index] = $pipes;
}
foreach ($workers as $index => $worker) {
    $output = trim((string)stream_get_contents($workerPipes[$index][1]));
    $error = trim((string)stream_get_contents($workerPipes[$index][2]));
    fclose($workerPipes[$index][1]);
    fclose($workerPipes[$index][2]);
    paynow_test(proc_close($worker) === 0 && $error === '' && $output === 'OK', 'Równoległy webhook zakończył się błędem: ' . $error);
}
$parallelRecipients = is_file($parallelLog) ? file($parallelLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
sort($parallelRecipients);
paynow_test($parallelRecipients === ['biuro@mgoutlet.pl', 'client@example.test'], 'Równoległe webhooki wysłały powiadomienie więcej niż raz.');

echo "PASS: paynow v3 tests\n";
