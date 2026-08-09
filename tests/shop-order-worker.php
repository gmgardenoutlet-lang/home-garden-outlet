<?php
declare(strict_types=1);

require __DIR__ . '/../hosting/getspace/shop-test/lib.php';

$now = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
$order = shop_create_order([
    'orderId' => '',
    'createdAt' => $now,
    'updatedAt' => $now,
    'status' => 'new',
    'orderStatus' => 'new',
    'paymentStatus' => 'not_started',
    'items' => [],
    'productsTotal' => 0,
    'deliveryCost' => 0,
    'total' => 0,
]);

echo $order['orderId'];
