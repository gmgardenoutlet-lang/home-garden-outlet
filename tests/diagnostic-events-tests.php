<?php
declare(strict_types=1);

function event_assert(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
$root = sys_get_temp_dir() . '/hgo-events-' . bin2hex(random_bytes(4));
mkdir($root . '/events', 0700, true);
putenv('HGO_STORAGE_DIR=' . $root);
require_once __DIR__ . '/../hosting/getspace/admin/lib.php';

$today = stats_today()->format('Y-m-d');
$old = stats_today()->modify('-31 days')->format('Y-m-d');
$records = [
    ['timestamp' => $today . 'T10:00:00+02:00', 'event_type' => 'page_view', 'path' => '/', 'country' => 'US', 'region' => 'New York', 'city' => 'New York', 'device_class' => 'desktop', 'client_class' => 'browser'],
    ['timestamp' => $today . 'T10:01:00+02:00', 'event_type' => 'product_view', 'path' => '/produkt/test', 'country' => 'US', 'region' => 'New York', 'city' => 'New York', 'device_class' => 'mobile', 'client_class' => 'known_bot'],
];
file_put_contents($root . '/events/' . $today . '.jsonl', implode("\n", array_map(static fn($r) => json_encode($r), $records)) . "\n");
file_put_contents($root . '/events/' . $old . '.jsonl', "{}\n");
$ny = load_diagnostic_events('today', ['city' => 'New York', 'type' => 'page_view']);
event_assert(count($ny) === 1 && $ny[0]['path'] === '/', 'Filtry zdarzeń nie zawężają po mieście i typie.');
event_assert(stats_event_filter('../../x', ['', 'browser']) === '', 'Walidacja filtru dopuszcza niedozwoloną wartość.');
$tracker = (string)file_get_contents(__DIR__ . '/../hosting/getspace/stats/track.php');
event_assert(strpos($tracker, 'stats_browser_is_excluded())') < strpos($tracker, 'stats_append_event('), 'Wykluczona przeglądarka mogłaby trafić do dziennika.');
event_assert(strpos($tracker, 'HTTP_USER_AGENT') !== false && strpos($tracker, "'user_agent'") === false, 'Tracker nie może zapisywać pełnego User-Agent.');
echo "PASS: diagnostic event tests\n";
