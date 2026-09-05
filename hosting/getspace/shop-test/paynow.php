<?php
declare(strict_types=1);

/* Small Paynow API V3 helper. Secrets are environment-only (see config.php). */

function paynow_environment(): string
{
    if (!in_array(PAYNOW_ENV, ['sandbox', 'production'], true)) {
        throw new RuntimeException('Nieprawidłowe środowisko Paynow.');
    }
    return PAYNOW_ENV;
}

function paynow_base_url(): string
{
    return paynow_environment() === 'sandbox' ? 'https://api.sandbox.paynow.pl' : 'https://api.paynow.pl';
}

function paynow_is_enabled(): bool
{
    return PAYNOW_ENABLED && PAYNOW_API_KEY !== '' && PAYNOW_SIGNATURE_KEY !== '';
}

function paynow_redirect_url(string $url): ?string
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (strtolower((string)($parts['scheme'] ?? '')) !== 'https' || !preg_match('/(?:^|\\.)paynow\\.pl$/', $host)) {
        return null;
    }
    return $url;
}

function paynow_sorted_parameters(array $parameters): array
{
    ksort($parameters, SORT_STRING);
    return $parameters;
}

function paynow_canonical_payload(string $apiKey, string $idempotencyKey, string $body, array $parameters = []): string
{
    $parsedParameters = [];
    foreach (paynow_sorted_parameters($parameters) as $key => $value) {
        $parsedParameters[$key] = is_array($value) ? $value : [$value];
    }
    $payload = json_encode([
        'headers' => ['Api-Key' => $apiKey, 'Idempotency-Key' => $idempotencyKey],
        'parameters' => $parsedParameters ?: new stdClass(),
        'body' => $body,
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) throw new RuntimeException('Nie udało się przygotować podpisu Paynow.');
    return $payload;
}

function paynow_request_signature(string $apiKey, string $signatureKey, string $idempotencyKey, string $body, array $parameters = []): string
{
    return base64_encode(hash_hmac('sha256', paynow_canonical_payload($apiKey, $idempotencyKey, $body, $parameters), $signatureKey, true));
}

function paynow_notification_signature(string $rawBody, string $signatureKey): string
{
    return base64_encode(hash_hmac('sha256', $rawBody, $signatureKey, true));
}

function paynow_verify_notification_signature(string $rawBody, string $signature): bool
{
    return $signature !== '' && hash_equals(paynow_notification_signature($rawBody, PAYNOW_SIGNATURE_KEY), trim($signature));
}

function paynow_idempotency_key(array $order): string
{
    $stored = trim((string)($order['paynowIdempotencyKey'] ?? ''));
    if ($stored !== '') {
        return $stored;
    }
    // 36 characters, below Paynow's documented 45-character limit.
    return bin2hex(random_bytes(18));
}

function paynow_order_lock(string $orderId, callable $callback)
{
    if (!is_dir(ORDERS_DIR) && !@mkdir(ORDERS_DIR, 0750, true) && !is_dir(ORDERS_DIR)) {
        throw new RuntimeException('Nie udało się przygotować blokady płatności.');
    }
    $lock = @fopen(ORDERS_DIR . '/.' . shop_safe_order_id($orderId) . '.paynow.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Nie udało się bezpiecznie zablokować płatności.');
    }
    try {
        return $callback();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function paynow_external_id(array $order): string
{
    $id = trim((string)($order['orderId'] ?? $order['order_id'] ?? ''));
    if ($id === '' || !preg_match('/^HGO-[0-9]{8}-(?:[0-9]{4}|[a-f0-9]{12})$/', $id)) {
        throw new RuntimeException('Nieprawidłowy identyfikator zamówienia do płatności.');
    }
    return $id;
}

function paynow_continue_url(string $orderId, string $confirmationToken): string
{
    if (shop_safe_order_id($orderId) === '' || $confirmationToken === '') {
        return '';
    }
    return 'https://mgoutlet.pl/sklep/figury-ogrodowe/platnosc/powrot/?order=' . rawurlencode($orderId)
        . '&token=' . rawurlencode($confirmationToken);
}

function paynow_payment_allowed(array $order): void
{
    shop_test_require_sales();
    if (shop_test_order_country_code($order) !== 'PL' && ($order['shippingTotalCents'] ?? null) === null) {
        throw new RuntimeException('Płatność online jest dostępna dopiero po ustaleniu kosztu dostawy.');
    }
    if (!paynow_is_enabled()) {
        throw new RuntimeException('Płatności Paynow nie są skonfigurowane.');
    }
    if (($order['status'] ?? '') === 'cancelled' || ($order['orderStatus'] ?? '') === 'cancelled') {
        throw new RuntimeException('Nie można opłacić anulowanego zamówienia.');
    }
    if (strtolower((string)($order['paymentStatus'] ?? '')) === 'confirmed' || in_array(($order['status'] ?? ''), ['paid', 'processing', 'shipped', 'completed'], true)) {
        throw new RuntimeException('Zamówienie jest już opłacone.');
    }
    if (($order['delivery']['pricingType'] ?? '') !== 'fixed_price' || !isset($order['totalCents']) || (int)$order['totalCents'] <= 0) {
        throw new RuntimeException('Płatność online jest dostępna wyłącznie dla dostawy ze znanym kosztem.');
    }
    if (($order['paymentMethod'] ?? '') !== 'paynow') {
        throw new RuntimeException('Zamówienie nie zostało utworzone dla Paynow.');
    }
}

function paynow_payment_failed(array $order): bool
{
    return in_array(strtolower((string)($order['paymentStatus'] ?? '')), ['rejected', 'error', 'expired'], true)
        || (($order['orderStatus'] ?? $order['status'] ?? '') === 'payment_failed');
}

function paynow_payment_payload(array $order, string $continueUrl = ''): array
{
    if (shop_test_order_country_code($order) !== 'PL' && ($order['shippingTotalCents'] ?? null) === null) {
        throw new RuntimeException('Płatność online jest dostępna dopiero po ustaleniu kosztu dostawy.');
    }
    if (($order['delivery']['pricingType'] ?? '') !== 'fixed_price' || (int)($order['totalCents'] ?? 0) <= 0) {
        throw new RuntimeException('Płatność online jest dostępna wyłącznie dla dostawy ze znanym kosztem.');
    }
    $customer = (array)($order['customer'] ?? []);
    $address = (array)($order['deliveryAddress'] ?? []);
    $buyer = ['email' => (string)($customer['email'] ?? '')];
    foreach (['firstName' => 'firstName', 'lastName' => 'lastName'] as $target => $source) {
        if (!empty($customer[$source])) $buyer[$target] = (string)$customer[$source];
    }
    if ($address) {
        $buyer['address'] = ['shipping' => [
            'street' => (string)($address['street'] ?? ''), 'zipcode' => (string)($address['postalCode'] ?? ''),
            'city' => (string)($address['city'] ?? ''), 'country' => (string)($address['country'] ?? 'PL'),
        ]];
    }
    $items = [];
    foreach ((array)($order['items'] ?? []) as $item) {
        $items[] = ['name' => (string)($item['name'] ?? $item['productId']), 'category' => 'Garden', 'quantity' => (int)$item['quantity'], 'price' => (int)$item['unitPriceCents']];
    }
    $shippingCents = (int)($order['shippingTotalCents'] ?? $order['deliveryCostCents'] ?? 0);
    if ($shippingCents > 0) {
        $items[] = ['name' => 'Dostawa', 'category' => 'Delivery', 'quantity' => 1, 'price' => $shippingCents];
    }
    $payload = ['amount' => (int)$order['totalCents'], 'currency' => 'PLN', 'externalId' => paynow_external_id($order),
        'description' => 'Zamówienie ' . paynow_external_id($order), 'buyer' => $buyer, 'orderItems' => $items];
    if ($continueUrl !== '') {
        $payload['continueUrl'] = $continueUrl;
    }
    return $payload;
}

function paynow_status_mapping(string $status): array
{
    return match (strtoupper($status)) {
        'NEW', 'PENDING' => ['orderStatus' => 'awaiting_payment', 'paymentStatus' => 'awaiting_payment', 'terminal' => false],
        'CONFIRMED' => ['orderStatus' => 'paid', 'paymentStatus' => 'confirmed', 'terminal' => true],
        'REJECTED', 'ERROR', 'EXPIRED' => ['orderStatus' => 'payment_failed', 'paymentStatus' => strtolower($status), 'terminal' => true],
        'ABANDONED' => ['orderStatus' => 'awaiting_payment', 'paymentStatus' => 'abandoned', 'terminal' => false],
        default => throw new RuntimeException('Nieznany status Paynow.'),
    };
}

function paynow_apply_status(array $order, string $paymentId, string $externalId, string $status, string $modifiedAt = ''): array
{
    if (!hash_equals(paynow_external_id($order), $externalId) || !hash_equals((string)($order['paymentId'] ?? ''), $paymentId)) {
        throw new RuntimeException('Powiadomienie nie pasuje do zamówienia.');
    }
    if (($order['paymentStatus'] ?? '') === 'confirmed') return $order; // terminal success: out-of-order events are no-op
    $previousModifiedAt = (string)($order['paymentModifiedAt'] ?? '');
    if ($modifiedAt !== '' && $previousModifiedAt !== '') {
        try {
            if (new DateTimeImmutable($modifiedAt) < new DateTimeImmutable($previousModifiedAt)) {
                return $order; // late notification; do not move the state backwards
            }
        } catch (Throwable $ignored) {
            throw new RuntimeException('Nieprawidłowa data powiadomienia Paynow.');
        }
    }
    $mapped = paynow_status_mapping($status);
    if (strtoupper($status) === strtoupper((string)($order['paynowStatus'] ?? '')) && ($modifiedAt === '' || $modifiedAt === $previousModifiedAt)) {
        return $order; // duplicate notification
    }
    $order['paymentProvider'] = 'paynow';
    $order['paymentStatus'] = $mapped['paymentStatus'];
    $order['status'] = $order['orderStatus'] = $mapped['orderStatus'];
    $order['paymentModifiedAt'] = $modifiedAt !== '' ? $modifiedAt : (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
    $order['paynowStatus'] = strtoupper($status);
    if (strtoupper($status) === 'CONFIRMED') {
        $order['paymentConfirmedAt'] = $order['paymentModifiedAt'];
    }
    return $order;
}

function paynow_is_historical_attempt(array $order, string $paymentId): bool
{
    foreach ((array)($order['paynowAttempts'] ?? []) as $attempt) {
        if (is_array($attempt) && hash_equals((string)($attempt['paymentId'] ?? ''), $paymentId)) {
            return true;
        }
    }
    return false;
}

function paynow_post_payment(array $order, string $continueUrl = ''): array
{
    $idempotencyKey = paynow_idempotency_key($order);
    $payload = paynow_payment_payload($order, $continueUrl);
    // This exact byte string is both signed and sent. Match Paynow's PHP SDK:
    // do not enable JSON_UNESCAPED_UNICODE.
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($body === false) throw new RuntimeException('Nie udało się przygotować płatności Paynow.');
    if (!function_exists('curl_init')) throw new RuntimeException('Serwer nie obsługuje wymaganego połączenia z Paynow.');

    $curl = curl_init(paynow_base_url() . '/v3/payments');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json', 'Content-Type: application/json',
            'Api-Key: ' . PAYNOW_API_KEY,
            'Idempotency-Key: ' . $idempotencyKey,
            'Signature: ' . paynow_request_signature(PAYNOW_API_KEY, PAYNOW_SIGNATURE_KEY, $idempotencyKey, $body),
            'User-Agent: HGO-Paynow-V3/1.0',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $responseBody = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($responseBody) || $httpCode !== 201) {
        $response = is_string($responseBody) ? json_decode($responseBody, true) : null;
        $error = is_array($response) && is_array($response['errors'][0] ?? null) ? $response['errors'][0] : [];
        $errorType = preg_replace('/[^A-Z_]/', '', (string)($error['errorType'] ?? '')) ?: 'UNKNOWN';
        $message = trim((string)($error['message'] ?? ''));
        if ($curlError !== '') {
            throw new RuntimeException('Paynow: bezpieczne połączenie nie powiodło się.');
        }
        throw new RuntimeException('Paynow HTTP ' . $httpCode . ' [' . $errorType . ']' . ($message !== '' ? ': ' . $message : '.'));
    }
    $response = json_decode($responseBody, true);
    $redirectUrl = is_array($response) && is_string($response['redirectUrl'] ?? null)
        ? paynow_redirect_url($response['redirectUrl'])
        : null;
    if (!is_array($response) || !is_string($response['paymentId'] ?? null) || $redirectUrl === null) {
        throw new RuntimeException('Paynow zwrócił nieprawidłową odpowiedź.');
    }
    return ['paymentId' => $response['paymentId'], 'redirectUrl' => $redirectUrl, 'idempotencyKey' => $idempotencyKey];
}

function paynow_process_notification(array $payload, ?callable $mailer = null): array
{
    return paynow_order_lock((string)$payload['externalId'], static function () use ($payload, $mailer): array {
        $order = shop_load_order((string)$payload['externalId']);
        if (!$order) throw new RuntimeException('not found');
        $externalId = (string)$payload['externalId'];
        $paymentId = (string)$payload['paymentId'];
        if (!hash_equals(paynow_external_id($order), $externalId)) {
            throw new RuntimeException('Powiadomienie nie pasuje do zamówienia.');
        }
        if (!hash_equals((string)($order['paymentId'] ?? ''), $paymentId)
            && paynow_is_historical_attempt($order, $paymentId)) {
            return $order; // signed notification for an archived payment attempt: no state or e-mail changes
        }
        $updated = paynow_apply_status($order, (string)$payload['paymentId'], (string)$payload['externalId'], (string)$payload['status'], (string)($payload['modifiedAt'] ?? ''));
        if ($updated !== $order) shop_save_order($updated);
        if (strtoupper((string)$payload['status']) === 'CONFIRMED') {
            $updated = shop_send_payment_confirmed_emails($updated, $mailer);
        }
        return $updated;
    });
}

function paynow_record_start_failure(string $orderId): array
{
    return paynow_order_lock($orderId, static function () use ($orderId): array {
        $order = shop_load_order($orderId);
        if (!$order) throw new RuntimeException('Nie znaleziono zamówienia.');
        $order['paymentStartFailedAt'] = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
        shop_save_order($order);
        return $order;
    });
}

function paynow_start_payment(string $orderId, string $confirmationToken = '', ?callable $paymentCreator = null): array
{
    return paynow_order_lock($orderId, static function () use ($orderId, $confirmationToken, $paymentCreator): array {
        $order = shop_load_order($orderId);
        if (!$order) throw new RuntimeException('Nie znaleziono zamówienia.');
        paynow_payment_allowed($order);
        $retryFailedPayment = paynow_payment_failed($order);
        if (!empty($order['paymentId']) && !empty($order['paymentRedirectUrl']) && !$retryFailedPayment) {
            return ['paymentId' => (string)$order['paymentId'], 'redirectUrl' => (string)$order['paymentRedirectUrl']];
        }

        if ($retryFailedPayment) {
            $order['paynowAttempts'][] = [
                'paymentId' => (string)($order['paymentId'] ?? ''),
                'paymentRedirectUrl' => (string)($order['paymentRedirectUrl'] ?? ''),
                'idempotencyKey' => (string)($order['paynowIdempotencyKey'] ?? ''),
                'status' => (string)($order['paymentStatus'] ?? ''),
                'finishedAt' => (string)($order['paymentModifiedAt'] ?? ''),
            ];
            unset($order['paymentId'], $order['paymentRedirectUrl'], $order['paynowIdempotencyKey'], $order['paynowStatus'], $order['paymentModifiedAt']);
            $order['paymentStatus'] = 'not_started';
            $order['status'] = $order['orderStatus'] = 'awaiting_payment';
        }

        $order['paynowIdempotencyKey'] = paynow_idempotency_key($order);
        shop_save_order($order); // preserve the key before a timeout/retry
        $continueUrl = paynow_continue_url($orderId, $confirmationToken);
        $created = $paymentCreator !== null ? $paymentCreator($order, $continueUrl) : paynow_post_payment($order, $continueUrl);
        $now = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
        $order['paymentProvider'] = 'paynow';
        $order['paymentId'] = $created['paymentId'];
        $order['paymentRedirectUrl'] = $created['redirectUrl'];
        $order['paynowIdempotencyKey'] = $created['idempotencyKey'];
        $order['paymentStatus'] = 'awaiting_payment';
        $order['status'] = $order['orderStatus'] = 'awaiting_payment';
        $order['paymentStartedAt'] = $now;
        $order['updatedAt'] = $now;
        unset($order['paymentStartFailedAt']);
        shop_save_order($order);
        return ['paymentId' => $created['paymentId'], 'redirectUrl' => $created['redirectUrl']];
    });
}
