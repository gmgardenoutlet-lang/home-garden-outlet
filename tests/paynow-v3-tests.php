<?php
declare(strict_types=1);

require __DIR__ . '/../hosting/getspace/shop-test/lib.php';
require __DIR__ . '/../hosting/getspace/shop-test/paynow.php';

function paynow_test(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

// Official V3 vector from Paynow Integration documentation.
$apiKey = '97a55694-5478-43b5-b406-fb49ebfdd2b5';
$signatureKey = 'b305b996-bca5-4404-a0b7-2ccea3d2b64b';
paynow_test(paynow_request_signature($apiKey, $signatureKey, 'd243fdb3-c287-484a-bb9c-58536f2794c1', '') === 'fXwLZRwo0WiGll90PPl5oULX9VKA0gpFA/3+E+NRp5E=', 'Oficjalny wektor podpisu żądania V3 nie pasuje.');
$notification = '{"paymentId":"NOLV-8F9-08K-WGD","externalId":"12345","status":"CONFIRMED","modifiedAt":"2018-12-12T13:24:52"}';
paynow_test(paynow_notification_signature($notification, $signatureKey) === 'F69sbjUxBX4eFjfUal/Y9XGREbfaRjh/zdq9j4MWeHM=', 'Oficjalny wektor podpisu powiadomienia V3 nie pasuje.');

$order = ['orderId' => 'HGO-20260809-0001', 'status' => 'new', 'paymentStatus' => 'not_started', 'totalCents' => 12345,
    'delivery' => ['pricingType' => 'fixed_price'], 'customer' => ['email' => 'test@example.test'], 'items' => [['productId' => 'a', 'name' => 'A', 'quantity' => 1, 'unitPriceCents' => 12345]]];
paynow_test(paynow_external_id($order) === 'HGO-20260809-0001', 'externalId nie jest powiązany z orderId.');
paynow_test(paynow_payment_payload($order)['amount'] === 12345, 'Kwota nie pochodzi z totalCents.');
$quote = $order; $quote['delivery']['pricingType'] = 'quote_required';
try { paynow_payment_payload($quote); paynow_test(false, 'quote_required dopuszczono do płatności.'); } catch (RuntimeException $e) {}
$paid = $order + ['paymentId' => 'NOLV-8F9-08K-WGD']; $paid['paymentId'] = 'NOLV-8F9-08K-WGD';
$confirmed = paynow_apply_status($paid, 'NOLV-8F9-08K-WGD', 'HGO-20260809-0001', 'CONFIRMED');
paynow_test($confirmed['status'] === 'paid' && $confirmed['paymentStatus'] === 'confirmed', 'CONFIRMED nie ustawia paid.');
paynow_test(paynow_apply_status($confirmed, 'NOLV-8F9-08K-WGD', 'HGO-20260809-0001', 'PENDING') === $confirmed, 'PENDING cofnął CONFIRMED.');
echo "PASS: paynow v3 tests\n";
