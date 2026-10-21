<?php
declare(strict_types=1);

// Google-only helpers. Never rewrite the original product image or catalogue.
function gbp_register_secret(string $value): void
{
    if ($value !== '') $GLOBALS['gbp_secrets'][$value] = true;
}

function gbp_safe($value, string $key = '')
{
    if (preg_match('/authorization|password|secret|token|credential|api.?key/i', $key)) return '[REDACTED]';
    if (is_array($value)) {
        foreach ($value as $k => $v) $value[$k] = gbp_safe($v, (string)$k);
        return $value;
    }
    if (!is_string($value)) return $value;
    foreach (array_keys($GLOBALS['gbp_secrets'] ?? []) as $secret) {
        $value = str_replace([$secret, rawurlencode($secret)], '[REDACTED]', $value);
    }
    return preg_replace('/Bearer\s+[^\s"<>]+|(?:ya29\.|GOCSPX-|1\/\/)[A-Za-z0-9._~+\/-]+/i', '[REDACTED]', $value);
}

function gbp_publication_request(array $config, array $payload, string $action): array
{
    foreach (['account_id', 'location_id'] as $key) {
        if (!preg_match('/^\d+$/D', (string)($config[$key] ?? ''))) {
            throw new RuntimeException('Google: Account ID i Location ID muszą zawierać same cyfry.');
        }
    }
    if (!in_array($action, ['photo_upload', 'post_create'], true)) throw new RuntimeException('Nieznana akcja publikacji Google.');
    foreach (['imageUrl', 'productUrl'] as $key) {
        if (!filter_var($payload[$key] ?? '', FILTER_VALIDATE_URL)
            || parse_url($payload[$key], PHP_URL_SCHEME) !== 'https'
            || parse_url($payload[$key], PHP_URL_USER) !== null
            || parse_url($payload[$key], PHP_URL_QUERY) !== null) {
            throw new RuntimeException('Google: wymagany jest publiczny adres HTTPS bez danych logowania i parametrów.');
        }
    }
    $parent = 'accounts/' . $config['account_id'] . '/locations/' . $config['location_id'];
    $media = ['mediaFormat' => 'PHOTO', 'sourceUrl' => $payload['imageUrl']];
    if ($action === 'photo_upload') {
        $body = $media + ['locationAssociation' => ['category' => 'PRODUCT']];
    } else {
        $summary = trim((string)($payload['summary'] ?? ''));
        $length = preg_match_all('/./us', $summary);
        if ($summary === '' || $length === false || $length > 1500) throw new RuntimeException('Google: treść wpisu musi mieć od 1 do 1500 znaków.');
        $body = ['languageCode' => 'pl-PL', 'summary' => $summary, 'topicType' => 'STANDARD',
            'callToAction' => ['actionType' => 'LEARN_MORE', 'url' => $payload['productUrl']], 'media' => [$media]];
    }
    return ['endpoint' => 'https://mybusiness.googleapis.com/v4/' . $parent . ($action === 'photo_upload' ? '/media' : '/localPosts'),
        'method' => 'POST', 'headerNames' => ['Authorization', 'Content-Type'], 'contentType' => 'application/json', 'payload' => $body];
}

function gbp_google_image(string $sourceUrl, string $siteUrl, string $siteRoot, bool $write = false): array
{
    if (parse_url($sourceUrl, PHP_URL_HOST) !== parse_url($siteUrl, PHP_URL_HOST)) {
        throw new RuntimeException('Google: zdjęcie musi pochodzić z lokalnego katalogu uploads.');
    }
    $path = rawurldecode((string)parse_url($sourceUrl, PHP_URL_PATH));
    $uploads = realpath($siteRoot . '/uploads');
    $source = realpath($siteRoot . $path);
    if ($uploads === false || $source === false || !is_file($source)
        || !str_starts_with(str_replace('\\', '/', $source), str_replace('\\', '/', $uploads) . '/')) {
        throw new RuntimeException('Google: nie znaleziono lokalnego zdjęcia w uploads.');
    }
    $info = @getimagesize($source);
    $bytes = filesize($source);
    if (!$info || min($info[0], $info[1]) < 250 || $bytes < 10240 || $bytes > 5 * 1024 * 1024) {
        throw new RuntimeException('Google: zdjęcie musi mieć minimum 250 px na krótszym boku i rozmiar od 10 KB do 5 MB.');
    }
    if (in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
        return ['sourceUrl' => $sourceUrl, 'sourceMime' => $info['mime'], 'targetMime' => $info['mime'], 'conversion' => false, 'prepared' => true];
    }
    if ($info['mime'] !== 'image/webp') throw new RuntimeException('Google: obsługiwane są JPG i PNG; tylko WEBP ma konwersję do JPG.');
    if (!function_exists('imagecreatefromwebp') || !function_exists('imagejpeg')) throw new RuntimeException('Google: konwersja WEBP wymaga rozszerzenia GD z obsługą WEBP i JPEG.');
    if ($info[0] * $info[1] > 24000000) throw new RuntimeException('Google: zdjęcie jest zbyt duże do bezpiecznej konwersji.');
    $relative = '/uploads/google-business/' . hash_file('sha256', $source) . '-v1.jpg';
    $destination = $siteRoot . $relative;
    if ($write && !is_file($destination)) {
        $image = @imagecreatefromwebp($source);
        if (!$image) throw new RuntimeException('Google: nie udało się odczytać zdjęcia WEBP.');
        $canvas = imagecreatetruecolor($info[0], $info[1]);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $info[0], $info[1]);
        if (!is_dir(dirname($destination)) && !@mkdir(dirname($destination), 0755, true) && !is_dir(dirname($destination))) {
            throw new RuntimeException('Google: nie udało się przygotować katalogu kopii JPG.');
        }
        $temporary = tempnam(dirname($destination), '.gbp-');
        try {
            if ($temporary === false || !imagejpeg($canvas, $temporary, 90)) throw new RuntimeException('Google: nie udało się zapisać kopii JPG.');
            $size = filesize($temporary);
            if ($size < 10240 || $size > 5 * 1024 * 1024) throw new RuntimeException('Google: kopia JPG nie spełnia limitu 10 KB–5 MB.');
            if (!rename($temporary, $destination)) throw new RuntimeException('Google: nie udało się zatwierdzić kopii JPG.');
            @chmod($destination, 0644);
        } finally {
            if ($temporary && is_file($temporary)) @unlink($temporary);
            imagedestroy($canvas);
            imagedestroy($image);
        }
    }
    return ['sourceUrl' => rtrim($siteUrl, '/') . $relative, 'sourceMime' => 'image/webp', 'targetMime' => 'image/jpeg',
        'conversion' => true, 'prepared' => is_file($destination)];
}
