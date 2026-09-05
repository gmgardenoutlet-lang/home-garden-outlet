<?php
declare(strict_types=1);

require __DIR__ . '/../hosting/getspace/shop-test/lib.php';
require __DIR__ . '/../hosting/getspace/shop-test/paynow.php';

$orderId = (string)getenv('HGO_TEST_PAYNOW_ORDER_ID');
$paymentId = (string)getenv('HGO_TEST_PAYNOW_PAYMENT_ID');
$mailLog = (string)getenv('HGO_TEST_PAYNOW_MAIL_LOG');
if ($orderId === '' || $paymentId === '' || $mailLog === '') {
    throw new RuntimeException('Brakuje konfiguracji testu równoległego webhooka.');
}

paynow_process_notification([
    'paymentId' => $paymentId,
    'externalId' => $orderId,
    'status' => 'CONFIRMED',
    'modifiedAt' => '2026-09-05T10:30:00+00:00',
], static function (string $to) use ($mailLog): bool {
    return file_put_contents($mailLog, $to . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
});

echo 'OK';
