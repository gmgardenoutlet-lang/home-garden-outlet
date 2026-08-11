<?php
declare(strict_types=1);

require __DIR__ . '/../hosting/getspace/shop-test/lib.php';
require __DIR__ . '/../hosting/getspace/shop-test/paynow.php';

function paynow_test(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

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

$order = ['orderId' => 'HGO-20260809-0001', 'status' => 'new', 'paymentStatus' => 'not_started', 'totalCents' => 12345,
    'delivery' => ['pricingType' => 'fixed_price'], 'customer' => ['email' => 'test@example.test'], 'items' => [['productId' => 'a', 'name' => 'A', 'quantity' => 1, 'unitPriceCents' => 12345]]];
paynow_test(paynow_external_id($order) === 'HGO-20260809-0001', 'externalId nie jest powiązany z orderId.');
paynow_test(paynow_payment_payload($order)['amount'] === 12345, 'Kwota nie pochodzi z totalCents.');
$withShipping = $order; $withShipping['totalCents'] = 14844; $withShipping['shippingTotalCents'] = 2499;
$shippingPayload = paynow_payment_payload($withShipping);
paynow_test(array_sum(array_map(static fn(array $item): int => $item['quantity'] * $item['price'], $shippingPayload['orderItems'])) === $shippingPayload['amount'], 'Pozycje Paynow nie sumują się do backendowego totalCents.');
paynow_test(!array_key_exists('phone', $shippingPayload['buyer']), 'Telefon ma nieprawidłowy format dla Paynow V3.');
$quote = $order; $quote['delivery']['pricingType'] = 'quote_required';
try { paynow_payment_payload($quote); paynow_test(false, 'quote_required dopuszczono do płatności.'); } catch (RuntimeException $e) {}
$paid = $order + ['paymentId' => 'NOLV-8F9-08K-WGD']; $paid['paymentId'] = 'NOLV-8F9-08K-WGD';
$confirmed = paynow_apply_status($paid, 'NOLV-8F9-08K-WGD', 'HGO-20260809-0001', 'CONFIRMED');
paynow_test($confirmed['status'] === 'paid' && $confirmed['paymentStatus'] === 'confirmed', 'CONFIRMED nie ustawia paid.');
paynow_test(paynow_apply_status($confirmed, 'NOLV-8F9-08K-WGD', 'HGO-20260809-0001', 'PENDING') === $confirmed, 'PENDING cofnął CONFIRMED.');
$pending = paynow_apply_status($paid, 'NOLV-8F9-08K-WGD', 'HGO-20260809-0001', 'PENDING', '2026-08-11T12:00:00+00:00');
paynow_test(paynow_apply_status($pending, 'NOLV-8F9-08K-WGD', 'HGO-20260809-0001', 'NEW', '2026-08-11T11:59:00+00:00') === $pending, 'Starsze powiadomienie zmieniło status.');
paynow_test(paynow_apply_status($pending, 'NOLV-8F9-08K-WGD', 'HGO-20260809-0001', 'PENDING', '2026-08-11T12:00:00+00:00') === $pending, 'Powtórny webhook nie jest no-op.');
echo "PASS: paynow v3 tests\n";
