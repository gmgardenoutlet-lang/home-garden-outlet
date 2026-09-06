<?php
declare(strict_types=1);

/*
 * Shared, privacy-safe statistics writer.  This file intentionally contains no
 * request parsing: browser validation belongs to stats/track.php and server
 * callers can only pass the narrow fields below.
 */
const HGO_STATS_SITE_ROOT = __DIR__ . '/..';
const HGO_STATS_STORAGE_DIR = HGO_STATS_SITE_ROOT . '/admin/storage/stats';
const HGO_STATS_EVENT_DIR = HGO_STATS_SITE_ROOT . '/admin/storage/events';
const HGO_STATS_EVENT_RETENTION_DAYS = 30;
const HGO_STATS_TIMEZONE = 'Europe/Warsaw';
const HGO_STATS_EVENTS = [
    'page_view', 'product_view', 'call_click', 'sms_click', 'navigation_click',
    'facebook_click', 'instagram_click', 'product_question_click',
    'shop_view', 'figure_view', 'add_to_cart', 'cart_view', 'checkout_view',
    'whatsapp_delivery_click', 'order_created', 'payment_confirmed',
];
const HGO_STATS_BUTTON_EVENTS = [
    'call_click', 'sms_click', 'navigation_click', 'facebook_click',
    'instagram_click', 'product_question_click', 'whatsapp_delivery_click',
];
const HGO_STATS_PRODUCT_EVENTS = [
    'product_view' => 'views', 'figure_view' => 'figure_views',
    'add_to_cart' => 'add_to_cart', 'whatsapp_delivery_click' => 'whatsapp_delivery_click',
];

function stats_now(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone(HGO_STATS_TIMEZONE)); }

function stats_default_day(string $date): array
{
    return ['date' => $date, 'totals' => array_fill_keys(HGO_STATS_EVENTS, 0), 'pages' => [], 'products' => [],
        'buttons' => array_fill_keys(HGO_STATS_BUTTON_EVENTS, 0), 'audit' => ['event_log_count' => 0, 'aggregate_count' => 0, 'last_error' => null]];
}

function stats_ensure_storage(): bool
{
    $adminStorage = dirname(HGO_STATS_STORAGE_DIR);
    foreach ([$adminStorage, HGO_STATS_STORAGE_DIR, HGO_STATS_EVENT_DIR] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) return false;
    }
    return true;
}

function stats_device_class(string $userAgent): string
{
    if ($userAgent === '') return 'unknown';
    if (preg_match('/bot|crawler|spider|slurp|facebookexternalhit|bingpreview/i', $userAgent)) return 'bot';
    if (preg_match('/ipad|tablet|kindle|silk\//i', $userAgent)) return 'tablet';
    if (preg_match('/mobi|android|iphone|ipod/i', $userAgent)) return 'mobile';
    return preg_match('/mozilla|chrome|safari|firefox|edg\//i', $userAgent) ? 'desktop' : 'unknown';
}

function stats_client_class(string $userAgent): string
{
    if ($userAgent === '') return 'unknown';
    if (preg_match('/googlebot|bingbot|duckduckbot|yandexbot|baiduspider|facebookexternalhit/i', $userAgent)) return 'known_bot';
    if (preg_match('/curl|wget|python-requests|axios|okhttp|postman|headless/i', $userAgent)) return 'suspected_automation';
    return preg_match('/mozilla|chrome|safari|firefox|edg\//i', $userAgent) ? 'browser' : 'unknown';
}

function stats_event_location(?array $location): array
{
    return ['country' => (string)($location['country_name'] ?? 'Nieznana lokalizacja'), 'region' => (string)($location['region_name'] ?? 'Nieznana lokalizacja'), 'city' => (string)($location['city_name'] ?? 'Nieznana lokalizacja')];
}

function stats_clean_event_meta(array $meta): array
{
    $result = [];
    $slug = strtolower(trim((string)($meta['productSlug'] ?? '')));
    if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9-]{0,150}$/', $slug)) $result['productSlug'] = $slug;
    foreach (['quantity', 'itemCount', 'itemTypes', 'orderValueCents'] as $key) {
        if (isset($meta[$key]) && is_int($meta[$key]) && $meta[$key] >= 0 && $meta[$key] <= 100000000) $result[$key] = $meta[$key];
    }
    if (($meta['currency'] ?? '') === 'PLN') $result['currency'] = 'PLN';
    if (isset($meta['context']) && in_array($meta['context'], ['product', 'shop', 'category'], true)) $result['context'] = $meta['context'];
    return $result;
}

function stats_append_event(string $event, string $pagePath, ?array $location, array $meta = [], ?DateTimeImmutable $now = null): bool
{
    $now ??= stats_now();
    $record = array_merge(['timestamp' => $now->format(DateTimeInterface::ATOM), 'event_type' => $event, 'path' => $pagePath], stats_event_location($location),
        ['device_class' => stats_device_class((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 'client_class' => stats_client_class((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))], stats_clean_event_meta($meta));
    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) return false;
    $written = file_put_contents(HGO_STATS_EVENT_DIR . '/' . $now->format('Y-m-d') . '.jsonl', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    return $written !== false && $written === strlen($line . PHP_EOL);
}

function stats_cleanup_event_logs(): void
{
    $cutoff = stats_now()->modify('-' . HGO_STATS_EVENT_RETENTION_DAYS . ' days')->setTime(0, 0);
    foreach (glob(HGO_STATS_EVENT_DIR . '/*.jsonl') ?: [] as $file) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', basename($file, '.jsonl'), new DateTimeZone(HGO_STATS_TIMEZONE));
        if ($date && $date < $cutoff) unlink($file);
    }
}

function stats_increment(string $event, string $pagePath, string $productSlug, ?array $location = null, array $meta = [], ?DateTimeImmutable $now = null): bool
{
    $now ??= stats_now(); $date = $now->format('Y-m-d'); $file = HGO_STATS_STORAGE_DIR . '/' . $date . '.json';
    $handle = fopen($file, 'c+');
    if (!$handle) return false;
    $saved = false;
    if (flock($handle, LOCK_EX)) {
        $stats = json_decode((string)stream_get_contents($handle), true);
        if (!is_array($stats) || ($stats['date'] ?? '') !== $date) $stats = stats_default_day($date);
        foreach (HGO_STATS_EVENTS as $knownEvent) $stats['totals'][$knownEvent] = (int)($stats['totals'][$knownEvent] ?? 0);
        foreach (HGO_STATS_BUTTON_EVENTS as $knownButton) $stats['buttons'][$knownButton] = (int)($stats['buttons'][$knownButton] ?? 0);
        $stats['totals'][$event]++;
        if ($event === 'page_view') $stats['pages'][$pagePath] = (int)($stats['pages'][$pagePath] ?? 0) + 1;
        if (in_array($event, HGO_STATS_BUTTON_EVENTS, true)) $stats['buttons'][$event]++;
        if ($event === 'page_view' && is_array($location) && function_exists('geoip_increment')) geoip_increment($stats, $location);
        if ($productSlug !== '' && isset(HGO_STATS_PRODUCT_EVENTS[$event])) {
            if (!isset($stats['products'][$productSlug]) || !is_array($stats['products'][$productSlug])) $stats['products'][$productSlug] = ['views' => 0, 'figure_views' => 0, 'add_to_cart' => 0, 'whatsapp_delivery_click' => 0, 'call_click' => 0, 'sms_click' => 0, 'product_question_click' => 0];
            $metric = HGO_STATS_PRODUCT_EVENTS[$event]; $stats['products'][$productSlug][$metric] = (int)($stats['products'][$productSlug][$metric] ?? 0) + 1;
        }
        $stats['audit']['event_log_count'] = (int)($stats['audit']['event_log_count'] ?? 0) + 1;
        $stats['audit']['aggregate_count'] = (int)($stats['audit']['aggregate_count'] ?? 0) + 1;
        $stats['audit']['last_error'] = null;
        rewind($handle); ftruncate($handle, 0);
        $json = json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $saved = $json !== false && fwrite($handle, $json . PHP_EOL) !== false;
        fflush($handle); flock($handle, LOCK_UN);
    }
    fclose($handle); return $saved;
}

function stats_record_event(string $event, string $pagePath, string $productSlug = '', ?array $location = null, array $meta = []): bool
{
    if (!in_array($event, HGO_STATS_EVENTS, true) || !stats_ensure_storage()) return false;
    $now = stats_now();
    /* JSONL is the audit source. Append it first, never increment an aggregate without it. */
    if (!stats_append_event($event, $pagePath, $location, $meta, $now)) return false;
    stats_cleanup_event_logs();
    return stats_increment($event, $pagePath, $productSlug, $location, $meta, $now);
}
