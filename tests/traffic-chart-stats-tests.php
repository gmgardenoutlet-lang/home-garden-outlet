<?php
declare(strict_types=1);

function traffic_chart_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hgo-traffic-chart-' . bin2hex(random_bytes(8));
putenv('HGO_STORAGE_DIR=' . $fixture);
mkdir($fixture . DIRECTORY_SEPARATOR . 'stats', 0700, true);

require_once __DIR__ . '/../hosting/getspace/admin/lib.php';

try {
    $end = new DateTimeImmutable('2026-08-30', new DateTimeZone(STATS_TIMEZONE));
    $write = static function (string $date, int $pageViews, int $productViews) use ($fixture): void {
        file_put_contents($fixture . '/stats/' . $date . '.json', json_encode([
            'date' => $date,
            'totals' => ['page_view' => $pageViews, 'product_view' => $productViews],
        ], JSON_THROW_ON_ERROR));
    };
    $write('2026-08-24', 5, 1);
    $write('2026-08-25', 0, 0);
    $write('2026-08-27', 9, 2);
    $write('2026-08-28', 3, 1);
    $write('2026-08-29', 7, 4);
    $write('2026-08-30', 11, 5);

    $daily = load_daily_traffic('7', $end);
    traffic_chart_assert($daily['days'] === 7 && count($daily['rows']) === 7, 'Wykres ma zawierać dokładnie jeden rekord na każdy dzień zakresu.');
    traffic_chart_assert($daily['rows'][0]['date'] === '2026-08-24' && $daily['rows'][1]['page_view'] === 0, 'Plik z zerowymi zdarzeniami musi pozostać zerem.');
    traffic_chart_assert($daily['rows'][2]['available'] === false && $daily['rows'][2]['page_view'] === null, 'Brakujący plik nie może zostać przedstawiony jako zero.');
    traffic_chart_assert($daily['totals']['page_view'] === 35 && $daily['totals']['product_view'] === 13, 'Sumy dzienne są niepoprawne.');
    traffic_chart_assert($daily['complete'] === false, 'Niepełny zakres z brakującym plikiem nie może być oznaczony jako kompletny.');

    $previous = ['complete' => true, 'totals' => ['page_view' => 28, 'product_view' => 13]];
    $comparison = traffic_chart_comparison(['complete' => true, 'totals' => ['page_view' => 35, 'product_view' => 0]], $previous);
    traffic_chart_assert($comparison['page_view']['changePercent'] === 25.0, 'Porównanie okresów dla odsłon jest niepoprawne.');
    traffic_chart_assert($comparison['product_view']['changePercent'] === -100.0, 'Porównanie okresów dla produktów jest niepoprawne.');
    traffic_chart_assert(traffic_chart_comparison($daily, $previous)['page_view']['available'] === false, 'Niepełny okres nie może udawać poprawnego porównania.');
    echo "PASS: traffic chart daily statistics tests\n";
} finally {
    foreach (glob($fixture . '/stats/*') ?: [] as $file) @unlink($file);
    @rmdir($fixture . '/stats');
    @rmdir($fixture);
}
