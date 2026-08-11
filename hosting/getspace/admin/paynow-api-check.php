<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
boot_admin();
require_login();

// Use the exact same configuration loader and V3 signature helper as Paynow.
require_once __DIR__ . '/../shop-test/config.php';
require_once __DIR__ . '/../shop-test/paynow.php';

$httpStatus = 0;
$message = 'Nie wykonano testu.';
$methods = [];

if (PAYNOW_API_KEY === '' || PAYNOW_SIGNATURE_KEY === '') {
    $message = 'Brak wymaganej konfiguracji Paynow.';
} elseif (!function_exists('curl_init')) {
    $message = 'Serwer nie obsługuje wymaganego połączenia HTTPS.';
} else {
    $idempotencyKey = bin2hex(random_bytes(18));
    // Paynow's documented signature example for this endpoint uses no query
    // parameters, so the signed parameter map is deliberately empty.
    $parameters = [];
    $curl = curl_init(paynow_base_url() . '/v3/payments/paymentmethods');
    curl_setopt_array($curl, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Api-Key: ' . PAYNOW_API_KEY,
            'Idempotency-Key: ' . $idempotencyKey,
            'Signature: ' . paynow_request_signature(PAYNOW_API_KEY, PAYNOW_SIGNATURE_KEY, $idempotencyKey, '', $parameters),
            'User-Agent: HGO-Paynow-V3/1.0',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $responseBody = curl_exec($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    $response = is_string($responseBody) ? json_decode($responseBody, true) : null;
    if ($httpStatus >= 200 && $httpStatus < 300 && is_array($response)) {
        foreach ($response as $group) {
            if (!is_array($group)) continue;
            $type = trim((string)($group['type'] ?? ''));
            foreach ((array)($group['paymentMethods'] ?? []) as $method) {
                if (!is_array($method)) continue;
                $name = trim((string)($method['name'] ?? ''));
                if ($name !== '' || $type !== '') $methods[] = ['name' => $name, 'type' => $type];
            }
        }
        $message = 'Połączenie i odpowiedź Paynow zostały odebrane.';
    } elseif ($curlError !== '') {
        $message = 'Nie udało się nawiązać bezpiecznego połączenia z Paynow.';
    } elseif (is_array($response)) {
        $message = trim((string)($response['message'] ?? $response['errors'][0]['message'] ?? 'Paynow odrzucił żądanie.'));
    } else {
        $message = 'Paynow zwrócił nieprawidłową odpowiedź.';
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Test połączenia Paynow | Home &amp; Garden Outlet</title>
  <link rel="stylesheet" href="/admin/style.css">
</head>
<body>
  <main class="narrow"><section class="card">
    <h1>Test połączenia Paynow</h1>
    <dl><dt>HTTP status</dt><dd><?= e((string)$httpStatus) ?></dd><dt>Wynik</dt><dd><?= e($message) ?></dd></dl>
    <?php if ($methods): ?><h2>Metody płatności</h2><ul><?php foreach ($methods as $method): ?><li><?= e(trim($method['name'] . ($method['type'] !== '' ? ' — ' . $method['type'] : ''))) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </section></main>
</body>
</html>
