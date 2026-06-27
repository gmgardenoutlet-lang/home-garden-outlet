<?php
declare(strict_types=1);

const HGO_STATS_SITE_ROOT = __DIR__ . '/..';
const HGO_STATS_STORAGE_DIR = HGO_STATS_SITE_ROOT . '/admin/storage/stats';
const HGO_STATS_PRODUCTS_FILE = HGO_STATS_SITE_ROOT . '/data/products.json';
const HGO_STATS_TIMEZONE = 'Europe/Warsaw';
const HGO_STATS_MAX_BODY_BYTES = 2048;
const HGO_STATS_MAX_WRITES_PER_MINUTE = 600;
const HGO_STATS_EVENTS = [
    'page_view',
    'product_view',
    'call_click',
    'sms_click',
    'navigation_click',
    'facebook_click',
    'instagram_click',
    'product_question_click',
];
const HGO_STATS_BUTTON_EVENTS = [
    'call_click',
    'sms_click',
    'navigation_click',
    'facebook_click',
    'instagram_click',
    'product_question_click',
];
const HGO_STATS_PRODUCT_EVENTS = [
    'product_view' => 'views',
    'call_click' => 'call_click',
    'sms_click' => 'sms_click',
    'product_question_click' => 'product_question_click',
];
const HGO_STATS_ALLOWED_HOSTS = [
    'mgoutlet.pl',
    'www.mgoutlet.pl',
    'localhost',
    '127.0.0.1',
];
const HGO_STATS_ALLOWED_PAGE_PATHS = [
    '/',
    '/index.html',
    '/dom',
    '/dom.html',
    '/ogrod',
    '/ogrod.html',
    '/poradnik',
    '/poradnik/index.html',
    '/poradnik/czym-jest-outlet-meblowy',
    '/poradnik/czym-jest-outlet-meblowy/index.html',
    '/poradnik/meble-ogrodowe-z-outletu-na-co-zwrocic-uwage',
    '/poradnik/meble-ogrodowe-z-outletu-na-co-zwrocic-uwage/index.html',
    '/poradnik/dlaczego-warto-ogladac-meble-na-zywo',
    '/poradnik/dlaczego-warto-ogladac-meble-na-zywo/index.html',
    '/poradnik/meble-z-ekspozycji-czy-warto',
    '/poradnik/meble-z-ekspozycji-czy-warto/index.html',
    '/meble-ogrodowe-wroclaw',
    '/meble-ogrodowe-wroclaw/index.html',
    '/outlet-meblowy-wroclaw',
    '/outlet-meblowy-wroclaw/index.html',
];

function stats_finish(int $status = 204): void
{
    http_response_code($status);
    header('Cache-Control: no-store, max-age=0');
    header('Content-Length: 0');
    exit;
}

function stats_request_origin_allowed(): bool
{
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
        $value = (string)($_SERVER[$key] ?? '');
        if ($value === '') {
            continue;
        }

        $host = strtolower((string)(parse_url($value, PHP_URL_HOST) ?: ''));
        if ($host === '' || !in_array($host, HGO_STATS_ALLOWED_HOSTS, true)) {
            return false;
        }
    }

    return true;
}

function stats_ensure_storage(): void
{
    $adminStorage = dirname(HGO_STATS_STORAGE_DIR);
    foreach ([$adminStorage, HGO_STATS_STORAGE_DIR] as $directory) {
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }
    }

    $deny = "Options -Indexes\nRequire all denied\n";
    foreach ([$adminStorage . '/.htaccess', HGO_STATS_STORAGE_DIR . '/.htaccess'] as $file) {
        if (!is_file($file)) {
            @file_put_contents($file, $deny, LOCK_EX);
            @chmod($file, 0640);
        }
    }
}

function stats_normalize_path(string $path): string
{
    $parsed = parse_url($path, PHP_URL_PATH);
    $clean = is_string($parsed) && $parsed !== '' ? $parsed : '/';
    $clean = rawurldecode($clean);
    $clean = preg_replace('#/+#', '/', $clean) ?: '/';

    if (strlen($clean) > 180 || substr($clean, 0, 1) !== '/' || strpos($clean, '..') !== false) {
        return '/';
    }

    if (!preg_match('#^/[a-zA-Z0-9/_\-.]*$#', $clean)) {
        return '/';
    }

    return $clean !== '/' ? rtrim($clean, '/') : '/';
}

function stats_slugify(string $value): string
{
    $value = trim($value);
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
    return trim($value, '-');
}

function stats_clean_slug($value): string
{
    $slug = stats_slugify((string)$value);
    return preg_match('/^[a-z0-9][a-z0-9-]{0,150}$/', $slug) ? $slug : '';
}

function stats_product_slug_exists(string $slug): bool
{
    if ($slug === '' || !is_file(HGO_STATS_PRODUCTS_FILE)) {
        return false;
    }

    $catalog = json_decode((string)file_get_contents(HGO_STATS_PRODUCTS_FILE), true);
    $products = is_array($catalog) && isset($catalog['products']) && is_array($catalog['products'])
        ? $catalog['products']
        : [];

    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }

        $source = trim((string)($product['slug'] ?? '')) !== ''
            ? (string)$product['slug']
            : (string)($product['name'] ?? '');
        if (stats_clean_slug($source) === $slug) {
            return true;
        }
    }

    return false;
}

function stats_page_path_allowed(string $path): bool
{
    return in_array($path, HGO_STATS_ALLOWED_PAGE_PATHS, true);
}

function stats_product_slug_from_path(string $path): string
{
    if (preg_match('#^/produkt/([a-z0-9-]+)$#', $path, $matches) !== 1) {
        return '';
    }

    return stats_clean_slug($matches[1] ?? '');
}

function stats_default_day(string $date): array
{
    return [
        'date' => $date,
        'totals' => array_fill_keys(HGO_STATS_EVENTS, 0),
        'pages' => [],
        'products' => [],
        'buttons' => array_fill_keys(HGO_STATS_BUTTON_EVENTS, 0),
    ];
}

function stats_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(HGO_STATS_TIMEZONE));
}

function stats_global_rate_allowed(): bool
{
    $minute = stats_now()->format('Y-m-d-H-i');
    $file = HGO_STATS_STORAGE_DIR . '/.rate-limit.json';
    $handle = @fopen($file, 'c+');
    if (!$handle) {
        return true;
    }

    $allowed = true;
    if (flock($handle, LOCK_EX)) {
        $raw = stream_get_contents($handle);
        $data = json_decode((string)$raw, true);
        if (!is_array($data) || ($data['minute'] ?? '') !== $minute) {
            $data = ['minute' => $minute, 'count' => 0];
        }

        $data['count'] = (int)($data['count'] ?? 0) + 1;
        $allowed = $data['count'] <= HGO_STATS_MAX_WRITES_PER_MINUTE;

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($data, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        fflush($handle);
        flock($handle, LOCK_UN);
    }
    fclose($handle);
    @chmod($file, 0640);

    return $allowed;
}

function stats_increment(string $event, string $pagePath, string $productSlug): bool
{
    $date = stats_now()->format('Y-m-d');
    $file = HGO_STATS_STORAGE_DIR . '/' . $date . '.json';
    $handle = @fopen($file, 'c+');
    if (!$handle) {
        return false;
    }

    $saved = false;
    if (flock($handle, LOCK_EX)) {
        $raw = stream_get_contents($handle);
        $stats = json_decode((string)$raw, true);
        if (!is_array($stats) || ($stats['date'] ?? '') !== $date) {
            $stats = stats_default_day($date);
        }

        foreach (HGO_STATS_EVENTS as $knownEvent) {
            $stats['totals'][$knownEvent] = (int)($stats['totals'][$knownEvent] ?? 0);
        }
        foreach (HGO_STATS_BUTTON_EVENTS as $knownButton) {
            $stats['buttons'][$knownButton] = (int)($stats['buttons'][$knownButton] ?? 0);
        }

        $stats['totals'][$event]++;

        if ($event === 'page_view') {
            $stats['pages'][$pagePath] = (int)($stats['pages'][$pagePath] ?? 0) + 1;
        }

        if (in_array($event, HGO_STATS_BUTTON_EVENTS, true)) {
            $stats['buttons'][$event]++;
        }

        if ($productSlug !== '' && isset(HGO_STATS_PRODUCT_EVENTS[$event])) {
            if (!isset($stats['products'][$productSlug]) || !is_array($stats['products'][$productSlug])) {
                $stats['products'][$productSlug] = [
                    'views' => 0,
                    'call_click' => 0,
                    'sms_click' => 0,
                    'product_question_click' => 0,
                ];
            }
            $productMetric = HGO_STATS_PRODUCT_EVENTS[$event];
            $stats['products'][$productSlug][$productMetric] = (int)($stats['products'][$productSlug][$productMetric] ?? 0) + 1;
        }

        rewind($handle);
        ftruncate($handle, 0);
        $json = json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $saved = $json !== false && fwrite($handle, $json . PHP_EOL) !== false;
        fflush($handle);
        flock($handle, LOCK_UN);
    }
    fclose($handle);
    @chmod($file, 0640);

    return $saved;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stats_finish(405);
}

if (!stats_request_origin_allowed()) {
    stats_finish(204);
}

$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > HGO_STATS_MAX_BODY_BYTES) {
    stats_finish(413);
}

$rawInput = (string)file_get_contents('php://input');
if ($rawInput === '' || strlen($rawInput) > HGO_STATS_MAX_BODY_BYTES) {
    stats_finish(400);
}

$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    stats_finish(400);
}

$event = (string)($payload['event'] ?? '');
if (!in_array($event, HGO_STATS_EVENTS, true)) {
    stats_finish(400);
}

$pagePath = stats_normalize_path((string)($payload['path'] ?? '/'));
$productSlug = stats_clean_slug($payload['productSlug'] ?? '');
$productSlugExists = $productSlug !== '' && stats_product_slug_exists($productSlug);
$productPathSlug = stats_product_slug_from_path($pagePath);
$productPathMatches = $productSlugExists && $productPathSlug !== '' && $productPathSlug === $productSlug;

if ($event === 'page_view' && !stats_page_path_allowed($pagePath)) {
    stats_finish(204);
}

if ($event === 'product_view' && !$productPathMatches) {
    stats_finish(204);
}

if ($event !== 'page_view' && $event !== 'product_view' && !stats_page_path_allowed($pagePath) && !$productPathMatches) {
    stats_finish(204);
}

if ($productSlug !== '' && !$productSlugExists) {
    $productSlug = '';
}

stats_ensure_storage();
if (!stats_global_rate_allowed()) {
    stats_finish(204);
}

stats_increment($event, $pagePath, $productSlug);
stats_finish(204);
