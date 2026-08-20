<?php
declare(strict_types=1);

function location_stats_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require_once __DIR__ . '/../hosting/getspace/admin/lib.php';

$locations = [
    ['country' => 'PL', 'region' => 'DS', 'regionName' => 'Lower Silesian Voivodeship', 'city' => 'Wroclaw', 'expectedRegion' => 'Dolnośląskie', 'expectedCity' => 'Wrocław'],
    ['country' => '', 'region' => '', 'regionName' => 'Lower Silesia', 'city' => 'Warsaw', 'expectedRegion' => 'Dolnośląskie', 'expectedCity' => 'Warszawa'],
    ['country' => 'PL', 'region' => 'PL-MZ', 'regionName' => 'Masovian Voivodeship', 'city' => 'Warszawa', 'expectedRegion' => 'Mazowieckie', 'expectedCity' => 'Warszawa'],
    ['country' => 'PL', 'region' => '10', 'regionName' => 'Lodz Voivodeship', 'city' => 'Lodz', 'expectedRegion' => 'Łódzkie', 'expectedCity' => 'Łódź'],
    ['country' => 'PL', 'region' => 'WP', 'regionName' => 'Greater Poland Voivodeship', 'city' => 'Poznan', 'expectedRegion' => 'Wielkopolskie', 'expectedCity' => 'Poznań'],
];
foreach ($locations as $location) {
    location_stats_assert(stats_location_display_region($location['country'], $location['region'], $location['regionName']) === $location['expectedRegion'], 'Niepoprawna polonizacja województwa: ' . $location['region']);
    location_stats_assert(stats_location_display_city($location['country'], $location['region'], $location['city']) === $location['expectedCity'], 'Niepoprawna polonizacja miasta: ' . $location['city']);
}

location_stats_assert(stats_location_display_country('DE', 'Germany') === 'Niemcy', 'Germany powinno być wyświetlane jako Niemcy.');
location_stats_assert(stats_location_display_country('US', 'United States') === 'Stany Zjednoczone', 'United States powinno być wyświetlane po polsku.');
location_stats_assert(stats_location_display_country('', 'Czechia') === 'Czechy', 'Czechia powinno być wyświetlane jako Czechy.');
location_stats_assert(stats_location_display_country('', 'Netherlands') === 'Holandia', 'Netherlands powinno być wyświetlane jako Holandia.');
location_stats_assert(stats_location_display_country('', 'United Kingdom') === 'Wielka Brytania', 'United Kingdom powinno być wyświetlane jako Wielka Brytania.');
location_stats_assert(stats_location_display_country('XX', 'Nieznany kraj') === 'Nieznany kraj', 'Nieznany kraj powinien zachować oryginalną nazwę.');
location_stats_assert(stats_location_display_region('PL', 'XX', 'Nieznany region') === 'Nieznany region', 'Nieznany region powinien zachować oryginalną nazwę.');
location_stats_assert(stats_is_lower_silesia('PL', 'DS') && stats_is_lower_silesia('PL', 'PL-DS') && stats_is_lower_silesia('PL', '02'), 'Dolny Śląsk musi obsługiwać kody DS, PL-DS i 02.');
location_stats_assert(stats_location_display_region('', 'DS', '') === 'Dolnośląskie' && stats_location_display_region('', 'PL-DS', '') === 'Dolnośląskie' && stats_location_display_region('', '02', '') === 'Dolnośląskie', 'Historyczne kody województwa bez country_code muszą być polonizowane.');

$historicalRow = ['code' => 'Germany', 'name' => 'Germany', 'country_code' => '', 'region_name' => 'Lower Silesian Voivodeship', 'region_code' => '', 'city' => 'Wroclaw'];
$historicalSource = $historicalRow;
$historicalCountry = stats_localize_location_row($historicalRow, 'country');
$historicalCity = stats_localize_location_row([
    'country_code' => $historicalRow['code'], 'region_code' => $historicalRow['region_code'],
    'region_name' => $historicalRow['region_name'], 'name' => $historicalRow['city'],
], 'city');
location_stats_assert($historicalCountry['name'] === 'Niemcy' && $historicalCity['region_name'] === 'Dolnośląskie' && $historicalCity['name'] === 'Wrocław', 'Historyczny rekord nie jest w pełni polonizowany przy renderowaniu.');
location_stats_assert($historicalRow === $historicalSource, 'Warstwa prezentacji zmodyfikowała źródłowy rekord historyczny.');

$breakdown = stats_local_breakdown(
    [['code' => 'PL', 'count' => 30], ['code' => 'DE', 'count' => 10]],
    [['country_code' => 'PL', 'code' => 'DS', 'count' => 20], ['country_code' => 'PL', 'code' => 'MZ', 'count' => 10]],
    [
        ['country_code' => 'PL', 'region_code' => 'DS', 'name' => 'Wroclaw', 'count' => 8],
        ['country_code' => 'PL', 'region_code' => 'DS', 'name' => 'Legnica', 'count' => 12],
        ['country_code' => 'PL', 'region_code' => 'MZ', 'name' => 'Warszawa', 'count' => 10],
    ]
);
location_stats_assert($breakdown === ['wroclaw' => 8, 'lowerSilesia' => 12, 'restPoland' => 10, 'foreign' => 10, 'unknown' => 0], 'Klasyfikacja Wrocław / Dolny Śląsk / Polska / zagranica uległa zmianie.');

$_COOKIE = [];
location_stats_assert(!stats_browser_is_excluded(), 'Brak cookie powinien oznaczać liczenie przeglądarki.');
$_COOKIE[HGO_STATS_EXCLUSION_COOKIE] = '1';
location_stats_assert(stats_browser_is_excluded(), 'Cookie wykluczenia powinno zatrzymać tracker.');
$_SERVER['HTTP_HOST'] = 'mgoutlet.pl';
$_SERVER['HTTPS'] = 'on';
$cookieOptions = stats_exclusion_cookie_options(time() + HGO_STATS_EXCLUSION_TTL);
location_stats_assert(!isset($cookieOptions['domain']) && $cookieOptions['path'] === '/' && $cookieOptions['secure'] && $cookieOptions['httponly'] && $cookieOptions['samesite'] === 'Lax', 'Cookie wykluczenia ma nieprawidłowe parametry bezpieczeństwa.');

$tracker = (string)file_get_contents(__DIR__ . '/../hosting/getspace/stats/track.php');
$guard = strpos($tracker, 'if (stats_browser_is_excluded())');
location_stats_assert($guard !== false && $guard < strpos($tracker, '$rawInput =') && $guard < strrpos($tracker, 'geoip_lookup(') && $guard < strrpos($tracker, 'stats_increment('), 'Tracker musi sprawdzać cookie przed odczytem payloadu, GeoIP i zapisem.');

echo "PASS: location and browser exclusion tests\n";
