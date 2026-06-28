<?php
declare(strict_types=1);

require __DIR__ . '/../lib.php';
boot_admin();

header('Content-Type: application/json; charset=utf-8');

function gbp_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function gbp_config(): array
{
    return load_google_business_config();
}

function gbp_clean_url(string $path, string $siteUrl): string
{
    $siteUrl = rtrim($siteUrl, '/');
    $path = trim($path);
    if ($path === '' || str_contains($path, '..')) {
        return $siteUrl . '/product-table.jpeg';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return $siteUrl . '/' . ltrim($path, '/');
}

function gbp_product_payload(array $product, array $config): array
{
    $siteUrl = rtrim((string)$config['site_url'], '/') ?: 'https://mgoutlet.pl';
    $name = trim((string)($product['name'] ?? 'Produkt z oferty'));
    $slug = clean_filename((string)($product['slug'] ?? ($name !== '' ? $name : 'produkt')));
    $imageUrl = gbp_clean_url((string)($product['image'] ?? ''), $siteUrl);
    $productUrl = $siteUrl . '/produkt/' . $slug;
    $summary = trim((string)($product['googleText'] ?? ''));
    if ($summary === '') {
        $summary = google_business_description($product);
    }

    return [
        'name' => $name !== '' ? $name : 'Produkt z oferty',
        'summary' => $summary,
        'imageUrl' => $imageUrl,
        'productUrl' => $productUrl,
        'price' => trim((string)($product['outletPrice'] ?? '')),
    ];
}

function gbp_can_send(array $config): bool
{
    foreach (['client_id', 'client_secret', 'refresh_token', 'account_id', 'location_id'] as $key) {
        if (trim((string)($config[$key] ?? '')) === '') {
            return false;
        }
    }
    return !empty($config['enabled']) && empty($config['dry_run']);
}

function gbp_config_status(array $config): array
{
    return google_business_config_status($config);
}

function gbp_public_error(Throwable $exception): string
{
    if (preg_match('/token|secret|client|refresh|credential|authorization/i', $exception->getMessage())) {
        return 'Nie udało się wykonać akcji Google. Sprawdź konfigurację API.';
    }
    return $exception->getMessage();
}

function gbp_request(string $url, array $options): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Na serwerze PHP brakuje rozszerzenia cURL.');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, $options + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HEADER => false,
    ]);

    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($body === false) {
        throw new RuntimeException($error !== '' ? $error : 'Nie udało się połączyć z Google API.');
    }

    $decoded = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded) ? (string)($decoded['error']['message'] ?? $decoded['error_description'] ?? 'Błąd Google API') : 'Błąd Google API';
        throw new RuntimeException($message);
    }

    return is_array($decoded) ? $decoded : [];
}

function gbp_access_token(array $config): string
{
    $response = gbp_request('https://oauth2.googleapis.com/token', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => (string)$config['client_id'],
            'client_secret' => (string)$config['client_secret'],
            'refresh_token' => (string)$config['refresh_token'],
            'grant_type' => 'refresh_token',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $token = (string)($response['access_token'] ?? '');
    if ($token === '') {
        throw new RuntimeException('Google nie zwrócił access tokena.');
    }
    return $token;
}

function gbp_create_media(array $config, array $payload, string $token): array
{
    $parent = 'accounts/' . rawurlencode((string)$config['account_id']) . '/locations/' . rawurlencode((string)$config['location_id']);
    return gbp_request('https://mybusiness.googleapis.com/v4/' . $parent . '/media', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'mediaFormat' => 'PHOTO',
            'locationAssociation' => ['category' => 'PRODUCT'],
            'sourceUrl' => $payload['imageUrl'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
}

function gbp_create_post(array $config, array $payload, string $token): array
{
    $parent = 'accounts/' . rawurlencode((string)$config['account_id']) . '/locations/' . rawurlencode((string)$config['location_id']);
    return gbp_request('https://mybusiness.googleapis.com/v4/' . $parent . '/localPosts', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'languageCode' => 'pl-PL',
            'summary' => $payload['summary'],
            'topicType' => 'STANDARD',
            'callToAction' => [
                'actionType' => 'LEARN_MORE',
                'url' => $payload['productUrl'],
            ],
            'media' => [[
                'mediaFormat' => 'PHOTO',
                'sourceUrl' => $payload['imageUrl'],
            ]],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
}

function gbp_log(array $entry): void
{
    if (!is_dir(STORAGE_DIR)) {
        @mkdir(STORAGE_DIR, 0750, true);
    }
    $file = STORAGE_DIR . '/google-business-log.json';
    $log = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    if (!is_array($log)) {
        $log = [];
    }
    $log[] = array_merge(['date' => date(DATE_ATOM)], $entry);
    $log = array_slice($log, -300);
    file_put_contents($file, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    @chmod($file, 0640);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gbp_json(405, ['ok' => false, 'message' => 'Dozwolona jest tylko metoda POST.']);
}

if (!is_logged_in()) {
    gbp_json(401, ['ok' => false, 'message' => 'Zaloguj się do panelu.']);
}

try {
    require_csrf();

    $googleAction = post_text('google_action');
    if (!in_array($googleAction, ['config_status', 'preview', 'photo_upload', 'post_create'], true)) {
        gbp_json(400, ['ok' => false, 'message' => 'Nieznana akcja Google.']);
    }

    $config = gbp_config();
    $configStatus = gbp_config_status($config);

    if ($googleAction === 'config_status') {
        gbp_json(200, [
            'ok' => true,
            'dryRun' => !$configStatus['ready'],
            'configReady' => $configStatus['ready'],
            'configStatus' => $configStatus,
            'message' => $configStatus['ready']
                ? 'Konfiguracja Google API wygląda na gotową do realnej wysyłki.'
                : 'Google API działa w trybie testowym. Brakuje konfiguracji lub dry-run jest nadal włączony.',
        ]);
    }

    $index = filter_input(INPUT_POST, 'index', FILTER_VALIDATE_INT);
    $catalog = load_catalog();
    if ($index === false || $index === null || !isset($catalog['products'][$index])) {
        gbp_json(404, ['ok' => false, 'message' => 'Nie znaleziono produktu.']);
    }

    $product = array_merge(product_defaults(), $catalog['products'][$index]);
    $payload = gbp_product_payload($product, $config);
    $canSend = gbp_can_send($config);
    $dryRun = !$canSend;

    $baseResponse = [
        'ok' => true,
        'dryRun' => $dryRun,
        'message' => $dryRun
            ? 'Tryb testowy: pokazuję dane, które zostałyby wysłane do Google.'
            : 'Połączono z Google Business Profile.',
        'payload' => $payload,
        'configReady' => $canSend,
        'configStatus' => $configStatus,
    ];

    if ($googleAction === 'preview' || $dryRun) {
        gbp_log([
            'product' => (string)($product['slug'] ?? $product['name'] ?? ''),
            'name' => (string)($product['name'] ?? ''),
            'action' => $googleAction,
            'status' => 'dry_run',
            'message' => 'Bez wysyłki do Google.',
        ]);
        gbp_json(200, $baseResponse);
    }

    $token = gbp_access_token($config);
    $now = date('Y-m-d H:i:s');

    if ($googleAction === 'photo_upload') {
        $result = gbp_create_media($config, $payload, $token);
        $mediaId = (string)($result['name'] ?? $result['mediaItem']['name'] ?? '');
        $catalog['products'][$index]['googleStatus'] = 'Wysłano';
        $catalog['products'][$index]['googleSentAt'] = $now;
        $catalog['products'][$index]['googleMediaId'] = $mediaId;
        $catalog['products'][$index]['googleError'] = '';
        save_catalog($catalog);
        gbp_log([
            'product' => (string)($product['slug'] ?? ''),
            'name' => $payload['name'],
            'action' => 'photo_upload',
            'status' => 'success',
            'mediaId' => $mediaId,
        ]);
        gbp_json(200, $baseResponse + [
            'message' => 'Zdjęcie wysłane do Google Business Profile.',
            'productUpdates' => [
                'googleStatus' => 'Wysłano',
                'googleSentAt' => $now,
                'googleMediaId' => $mediaId,
                'googleError' => '',
            ],
        ]);
    }

    if ($googleAction === 'post_create') {
        $result = gbp_create_post($config, $payload, $token);
        $postId = (string)($result['name'] ?? '');
        $catalog['products'][$index]['googleStatus'] = 'Wysłano';
        $catalog['products'][$index]['googleSentAt'] = $now;
        $catalog['products'][$index]['googlePostId'] = $postId;
        $catalog['products'][$index]['googleError'] = '';
        save_catalog($catalog);
        gbp_log([
            'product' => (string)($product['slug'] ?? ''),
            'name' => $payload['name'],
            'action' => 'post_create',
            'status' => 'success',
            'postId' => $postId,
        ]);
        gbp_json(200, $baseResponse + [
            'message' => 'Post utworzony w Google Business Profile.',
            'productUpdates' => [
                'googleStatus' => 'Wysłano',
                'googleSentAt' => $now,
                'googlePostId' => $postId,
                'googleError' => '',
            ],
        ]);
    }
} catch (Throwable $exception) {
    $publicError = gbp_public_error($exception);
    gbp_log([
        'action' => post_text('google_action'),
        'status' => 'error',
        'message' => $publicError,
    ]);
    gbp_json(500, [
        'ok' => false,
        'message' => 'Nie udało się wykonać akcji Google. Sprawdź konfigurację API albo spróbuj ponownie.',
        'error' => $publicError,
    ]);
}
