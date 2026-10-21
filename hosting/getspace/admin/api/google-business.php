<?php
declare(strict_types=1);

require __DIR__ . '/../lib.php';
require_once __DIR__ . '/../google-business-support.php';
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
    $config = load_google_business_config();
    foreach (['client_secret', 'refresh_token'] as $key) gbp_register_secret((string)($config[$key] ?? ''));
    return $config;
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

class GbpRequestException extends RuntimeException
{
    public array $diagnostic;
    public function __construct(string $message, array $diagnostic = [])
    {
        parent::__construct($message);
        $this->diagnostic = gbp_safe($diagnostic);
    }
}

function gbp_public_error(Throwable $exception): string
{
    if ($exception instanceof GbpRequestException) {
        return $exception->getMessage();
    }
    if (preg_match('/token|secret|client|refresh|credential|authorization/i', $exception->getMessage())) {
        return 'Nie udało się wykonać akcji Google. Sprawdź konfigurację API.';
    }
    return $exception->getMessage();
}

function gbp_request(string $url, array $options): array
{
    $host = (string)parse_url($url, PHP_URL_HOST);
    $stage = [
        'oauth2.googleapis.com' => 'oauth_exchange',
        'mybusinessaccountmanagement.googleapis.com' => 'accounts_list',
        'mybusinessbusinessinformation.googleapis.com' => 'locations_list',
    ][$host] ?? 'business_request';
    if ($host === 'mybusiness.googleapis.com') {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $stage = str_ends_with($path, '/media') ? 'photo_create'
            : (str_ends_with($path, '/localPosts') ? 'post_create' : 'reviews_list');
    }
    if (!function_exists('curl_init')) {
        throw new GbpRequestException('Google: etap=' . $stage . '; kod=curl_unavailable');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, $options + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HEADER => false,
    ]);

    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($body === false) {
        throw new GbpRequestException('Google: etap=' . $stage . '; kod=transport_error');
    }

    $decoded = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300) {
        // Never expose response bodies, descriptions, URLs or credential values.
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        $candidate = is_string($error) ? $error : (is_array($error) ? ($error['status'] ?? '') : '');
        $allowed = ['invalid_grant', 'invalid_client', 'unauthorized_client', 'invalid_request',
            'invalid_scope', 'access_denied', 'INVALID_ARGUMENT', 'UNAUTHENTICATED',
            'PERMISSION_DENIED', 'NOT_FOUND', 'RESOURCE_EXHAUSTED', 'UNAVAILABLE', 'INTERNAL'];
        $code = in_array($candidate, $allowed, true) ? $candidate : 'http_error';
        $diagnostic = ['stage' => $stage, 'http' => $status, 'code' => $code, 'endpoint' => $url];
        // OAuth bodies contain credentials. Only publication diagnostics retain a
        // redacted request/response, in authenticated admin output/private logs.
        if (in_array($stage, ['photo_create', 'post_create'], true)) {
            $headers = array_map(static fn($header) => trim(explode(':', $header, 2)[0]), $options[CURLOPT_HTTPHEADER] ?? []);
            $diagnostic += ['method' => 'POST', 'headerNames' => $headers,
                'payload' => json_decode((string)($options[CURLOPT_POSTFIELDS] ?? ''), true),
                'message' => is_array($error) ? ($error['message'] ?? '') : '',
                'responseBody' => is_array($decoded) ? $decoded : '[Non-JSON response omitted]'];
            gbp_log(['action' => 'publication_diagnostic', 'diagnostic' => gbp_safe($diagnostic)]);
        }
        throw new GbpRequestException('Google: etap=' . $stage . '; HTTP=' . $status . '; kod=' . $code, $diagnostic);
    }

    if (!is_array($decoded)) {
        throw new GbpRequestException('Google: etap=' . $stage . '; kod=invalid_response');
    }
    return $decoded;
}

function gbp_access_token(array $config): string
{
    foreach (['client_secret', 'refresh_token'] as $key) gbp_register_secret((string)($config[$key] ?? ''));
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
        throw new GbpRequestException('Google: etap=oauth_exchange; kod=missing_access_token');
    }
    gbp_register_secret($token);
    return $token;
}

function gbp_send_publication(array $config, array $payload, string $token, string $action): array
{
    gbp_register_secret($token);
    $request = gbp_publication_request($config, $payload, $action);
    return gbp_request($request['endpoint'], [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($request['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
    ]);
}

function gbp_create_media(array $config, array $payload, string $token): array
{
    return gbp_send_publication($config, $payload, $token, 'photo_upload');
}

function gbp_create_post(array $config, array $payload, string $token): array
{
    return gbp_send_publication($config, $payload, $token, 'post_create');
}

function gbp_discover_locations(array $config, string $token): array
{
    $accountsResponse = gbp_request('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);

    $locations = [];
    foreach (($accountsResponse['accounts'] ?? []) as $account) {
        if (!is_array($account)) {
            continue;
        }
        $accountName = (string)($account['name'] ?? '');
        $accountId = preg_replace('#^accounts/#', '', $accountName);
        if ($accountName === '' || $accountId === '') {
            continue;
        }

        $locationsResponse = gbp_request(
            'https://mybusinessbusinessinformation.googleapis.com/v1/' . $accountName . '/locations?readMask=name,title,storefrontAddress,phoneNumbers,websiteUri',
            [
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ],
            ]
        );

        foreach (($locationsResponse['locations'] ?? []) as $location) {
            if (!is_array($location)) {
                continue;
            }
            $locationName = (string)($location['name'] ?? '');
            $locationId = preg_replace('#^locations/#', '', $locationName);
            if ($locationId === '') {
                continue;
            }

            $address = $location['storefrontAddress'] ?? [];
            $addressText = '';
            if (is_array($address)) {
                $addressText = trim(implode(', ', array_filter([
                    implode(' ', (array)($address['addressLines'] ?? [])),
                    $address['postalCode'] ?? '',
                    $address['locality'] ?? '',
                ])));
            }

            $locations[] = [
                'accountId' => $accountId,
                'accountName' => (string)($account['accountName'] ?? $account['type'] ?? $accountName),
                'locationId' => $locationId,
                'locationName' => (string)($location['title'] ?? $locationName),
                'address' => $addressText,
                'website' => (string)($location['websiteUri'] ?? ''),
            ];
        }
    }

    return $locations;
}

function gbp_rating_number(string $rating): int
{
    switch (strtoupper($rating)) {
        case 'ONE':
            return 1;
        case 'TWO':
            return 2;
        case 'THREE':
            return 3;
        case 'FOUR':
            return 4;
        case 'FIVE':
            return 5;
        default:
            return 0;
    }
}

function gbp_refresh_reviews(array $config, string $token, bool $save = true): array
{
    $url = 'https://mybusiness.googleapis.com/v4/accounts/'
        . rawurlencode((string)$config['account_id'])
        . '/locations/'
        . rawurlencode((string)$config['location_id'])
        . '/reviews?pageSize=20&orderBy=' . rawurlencode('updateTime desc');

    $response = gbp_request($url, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);

    $reviews = [];
    foreach (($response['reviews'] ?? []) as $review) {
        if (!is_array($review)) {
            continue;
        }
        $comment = trim((string)($review['comment'] ?? ''));
        $rating = gbp_rating_number((string)($review['starRating'] ?? ''));
        if ($comment === '' || $rating < 4) {
            continue;
        }
        $reviewer = $review['reviewer'] ?? [];
        $reviews[] = [
            'reviewId' => (string)($review['reviewId'] ?? $review['name'] ?? ''),
            'author' => trim((string)(is_array($reviewer) ? ($reviewer['displayName'] ?? 'Klient Google') : 'Klient Google')),
            'rating' => $rating,
            'text' => $comment,
            'createTime' => (string)($review['createTime'] ?? ''),
            'updateTime' => (string)($review['updateTime'] ?? ''),
            'relativeTime' => (string)($review['relativePublishTimeDescription'] ?? ''),
            'source' => 'Google',
        ];
        if (count($reviews) >= 8) {
            break;
        }
    }

    $payload = [
        'updatedAt' => date(DATE_ATOM),
        'source' => 'Google',
        'averageRating' => $response['averageRating'] ?? null,
        'totalReviewCount' => $response['totalReviewCount'] ?? null,
        'reviews' => $reviews,
    ];

    if (!$save) return $payload;

    $file = SITE_ROOT . '/data/google-reviews.json';
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0755, true);
    }
    if (file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Nie udało się zapisać pliku data/google-reviews.json.');
    }
    @chmod($file, 0644);

    return $payload;
}

function gbp_log(array $entry): void
{
    $entry = gbp_safe($entry);
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
    if (!in_array($googleAction, ['config_status', 'discover_locations', 'refresh_reviews', 'read_test', 'preview', 'preview_photo', 'preview_post', 'photo_upload', 'post_create'], true)) {
        gbp_json(400, ['ok' => false, 'message' => 'Nieznana akcja Google.']);
    }

    $config = gbp_config();
    $configStatus = gbp_config_status($config);

    if ($googleAction === 'read_test') {
        $token = gbp_access_token($config);
        $locations = gbp_discover_locations($config, $token);
        $reviews = gbp_refresh_reviews($config, $token, false);
        gbp_json(200, ['ok' => true, 'dryRun' => true, 'message' => 'OAuth, konta, lokalizacje i opinie: odczyt OK. Bez zapisu i publikacji.',
            'locations' => $locations, 'reviewsCount' => count($reviews['reviews']), 'configStatus' => $configStatus]);
    }

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

    if ($googleAction === 'discover_locations') {
        foreach (['client_id', 'client_secret', 'refresh_token'] as $key) {
            if (trim((string)($config[$key] ?? '')) === '') {
                gbp_json(400, [
                    'ok' => false,
                    'message' => 'Najpierw zapisz Client ID, Client secret i Refresh token.',
                    'configStatus' => $configStatus,
                ]);
            }
        }

        $token = gbp_access_token($config);
        $locations = gbp_discover_locations($config, $token);
        gbp_json(200, [
            'ok' => true,
            'dryRun' => true,
            'message' => $locations
                ? 'Znaleziono wizytówki Google dostępne dla tego konta.'
                : 'Nie znaleziono wizytówek. Sprawdź, czy konto ma dostęp do profilu firmy i czy w Google Cloud włączone są Business Profile API.',
            'locations' => $locations,
            'configStatus' => $configStatus,
        ]);
    }

    if ($googleAction === 'refresh_reviews') {
        foreach (['client_id', 'client_secret', 'refresh_token', 'account_id', 'location_id'] as $key) {
            if (trim((string)($config[$key] ?? '')) === '') {
                gbp_json(400, [
                    'ok' => false,
                    'message' => 'Najpierw uzupełnij Client ID, Client secret, Refresh token, Account ID i Location ID.',
                    'configStatus' => $configStatus,
                ]);
            }
        }

        $token = gbp_access_token($config);
        $reviewsPayload = gbp_refresh_reviews($config, $token);
        gbp_log([
            'action' => 'refresh_reviews',
            'status' => 'success',
            'count' => count($reviewsPayload['reviews'] ?? []),
        ]);
        gbp_json(200, [
            'ok' => true,
            'dryRun' => false,
            'message' => 'Opinie Google zostały odświeżone i zapisane do data/google-reviews.json.',
            'reviewsCount' => count($reviewsPayload['reviews'] ?? []),
            'updatedAt' => $reviewsPayload['updatedAt'] ?? '',
            'configStatus' => $configStatus,
        ]);
    }

    $index = filter_input(INPUT_POST, 'index', FILTER_VALIDATE_INT);
    $catalog = load_catalog();
    if ($index === false || $index === null || !isset($catalog['products'][$index])) {
        gbp_json(404, ['ok' => false, 'message' => 'Nie znaleziono produktu.']);
    }

    $product = array_merge(product_defaults(), $catalog['products'][$index]);
    $payload = gbp_product_payload($product, $config);
    $originalImageUrl = $payload['imageUrl'];
    $image = gbp_google_image($originalImageUrl, (string)$config['site_url'], SITE_ROOT);
    $payload['imageUrl'] = $image['sourceUrl'];
    $canSend = gbp_can_send($config);
    $dryRun = !$canSend || in_array($googleAction, ['preview', 'preview_photo', 'preview_post'], true);
    $actions = $googleAction === 'preview' ? ['photo_upload', 'post_create']
        : [in_array($googleAction, ['preview_photo', 'photo_upload'], true) ? 'photo_upload' : 'post_create'];
    $requests = array_map(static fn($action) => gbp_publication_request($config, $payload, $action), $actions);

    $baseResponse = [
        'ok' => true,
        'dryRun' => $dryRun,
        'message' => $dryRun
            ? 'Tryb testowy: pokazuję dane, które zostałyby wysłane do Google.'
            : 'Połączono z Google Business Profile.',
        'payload' => $payload,
        'configReady' => $canSend,
        'configStatus' => $configStatus,
        'requests' => $requests,
        'image' => $image,
    ];

    if ($dryRun) {
        gbp_log([
            'product' => (string)($product['slug'] ?? $product['name'] ?? ''),
            'name' => (string)($product['name'] ?? ''),
            'action' => $googleAction,
            'status' => 'dry_run',
            'message' => 'Bez wysyłki do Google.',
            'requests' => $requests,
            'image' => $image,
        ]);
        gbp_json(200, $baseResponse);
    }

    // Only a real, explicitly selected publication may create the Google JPG.
    gbp_google_image($originalImageUrl, (string)$config['site_url'], SITE_ROOT, true);
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
        gbp_json(200, array_replace($baseResponse, [
            'message' => 'Zdjęcie wysłane do Google Business Profile.',
            'productUpdates' => [
                'googleStatus' => 'Wysłano',
                'googleSentAt' => $now,
                'googleMediaId' => $mediaId,
                'googleError' => '',
            ],
        ]));
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
        gbp_json(200, array_replace($baseResponse, [
            'message' => 'Post utworzony w Google Business Profile.',
            'productUpdates' => [
                'googleStatus' => 'Wysłano',
                'googleSentAt' => $now,
                'googlePostId' => $postId,
                'googleError' => '',
            ],
        ]));
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
        'diagnostic' => $exception instanceof GbpRequestException ? $exception->diagnostic : null,
    ]);
}
