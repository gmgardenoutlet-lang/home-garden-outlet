<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/geoip.php';
require_once __DIR__ . '/../lib/stats-exclusion.php';
require_once __DIR__ . '/../lib/stats-writer.php';

const HGO_STATS_PRODUCTS_FILE = HGO_STATS_SITE_ROOT . '/data/products.json';
const HGO_STATS_MAX_BODY_BYTES = 2048;
const HGO_STATS_MAX_WRITES_PER_MINUTE = 600;
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

function stats_figure_slug_exists(string $slug): bool
{
    if ($slug === '' || !is_file(HGO_STATS_PRODUCTS_FILE)) return false;
    $catalog = json_decode((string)file_get_contents(HGO_STATS_PRODUCTS_FILE), true);
    foreach ((array)($catalog['products'] ?? []) as $product) {
        if (!is_array($product) || ($product['saleType'] ?? '') !== 'garden_figure') continue;
        $source = trim((string)($product['slug'] ?? '')) !== '' ? (string)$product['slug'] : (string)($product['name'] ?? '');
        if (stats_clean_slug($source) === $slug) return true;
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

function stats_figure_slug_from_path(string $path): string
{
    return preg_match('#^/sklep/figury-ogrodowe/produkt/([a-z0-9-]+)$#', $path, $matches) === 1 ? stats_clean_slug($matches[1] ?? '') : '';
}

function stats_event_meta_from_payload(array $payload): array
{
    $meta = [];
    foreach (['quantity', 'itemCount', 'itemTypes', 'orderValueCents'] as $key) {
        if (isset($payload[$key]) && is_int($payload[$key]) && $payload[$key] >= 0 && $payload[$key] <= 100000000) $meta[$key] = $payload[$key];
    }
    if (($payload['context'] ?? '') === 'product' || ($payload['context'] ?? '') === 'shop' || ($payload['context'] ?? '') === 'category') $meta['context'] = $payload['context'];
    return $meta;
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stats_finish(405);
}

// This must precede payload parsing, storage access and the GeoIP lookup.
if (stats_browser_is_excluded()) {
    stats_finish(204);
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
$figureSlugExists = $productSlug !== '' && stats_figure_slug_exists($productSlug);
$figurePathSlug = stats_figure_slug_from_path($pagePath);
$figurePathMatches = $figureSlugExists && $figurePathSlug !== '' && $figurePathSlug === $productSlug;
$meta = stats_event_meta_from_payload($payload);

if ($event === 'page_view' && !stats_page_path_allowed($pagePath)) {
    stats_finish(204);
}

if ($event === 'product_view' && !$productPathMatches) {
    stats_finish(204);
}

if ($event === 'shop_view' && $pagePath !== '/sklep/figury-ogrodowe') {
    stats_finish(204);
}

if ($event === 'figure_view' && !$figurePathMatches) {
    stats_finish(204);
}

if ($event === 'add_to_cart' && !($figurePathMatches || ($pagePath === '/sklep/figury-ogrodowe' && $figureSlugExists))) {
    stats_finish(204);
}

if ($event === 'cart_view' && $pagePath !== '/sklep/figury-ogrodowe/koszyk') {
    stats_finish(204);
}

if ($event === 'checkout_view' && $pagePath !== '/sklep/figury-ogrodowe/zamowienie') {
    stats_finish(204);
}

if ($event === 'whatsapp_delivery_click' && !($productPathMatches || (stats_page_path_allowed($pagePath) && $productSlugExists))) {
    stats_finish(204);
}

if (!in_array($event, ['page_view', 'product_view', 'shop_view', 'figure_view', 'add_to_cart', 'cart_view', 'checkout_view', 'whatsapp_delivery_click'], true) && !stats_page_path_allowed($pagePath) && !$productPathMatches) {
    stats_finish(204);
}

if ($productSlug !== '' && !$productSlugExists && !$figureSlugExists) {
    $productSlug = '';
}

if (!stats_ensure_storage()) {
    stats_finish(503);
}
if (!stats_global_rate_allowed()) {
    stats_finish(204);
}

$location = geoip_lookup((string)($_SERVER['REMOTE_ADDR'] ?? ''));
stats_record_event($event, $pagePath, $productSlug, $event === 'page_view' ? $location : null, $meta);
stats_finish(204);
