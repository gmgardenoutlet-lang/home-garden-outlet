<?php
declare(strict_types=1);

const HGO_GEOIP_DATABASE = '/home/ogfdvopi/private/geoip/GeoLite2-City.mmdb';
const HGO_GEOIP_AUTOLOAD = __DIR__ . '/../vendor/autoload.php';

function geoip_status(): array
{
    static $status = null;
    if (is_array($status)) {
        return $status;
    }

    if (!is_file(HGO_GEOIP_DATABASE) || !is_readable(HGO_GEOIP_DATABASE)) {
        return $status = ['ready' => false, 'reason' => 'Brak pliku GeoLite2-City.mmdb w prywatnym katalogu GeoIP.'];
    }
    if (!is_file(HGO_GEOIP_AUTOLOAD)) {
        return $status = ['ready' => false, 'reason' => 'Brak biblioteki MaxMind w artefakcie wdrożeniowym.'];
    }

    try {
        require_once HGO_GEOIP_AUTOLOAD;
        if (!class_exists('MaxMind\\Db\\Reader')) {
            return $status = ['ready' => false, 'reason' => 'Biblioteka MaxMind nie jest dostępna.'];
        }
    } catch (Throwable $error) {
        return $status = ['ready' => false, 'reason' => 'Nie można uruchomić biblioteki MaxMind.'];
    }

    return $status = ['ready' => true, 'reason' => ''];
}

function geoip_unknown_location(): array
{
    return [
        'country_code' => 'unknown', 'country_name' => 'Nieznana lokalizacja',
        'region_code' => 'unknown', 'region_name' => 'Nieznana lokalizacja',
        'city_key' => 'unknown', 'city_name' => 'Nieznana lokalizacja',
    ];
}

function geoip_name(array $record): string
{
    $names = $record['names'] ?? [];
    if (!is_array($names)) {
        return '';
    }
    foreach (['pl', 'en'] as $language) {
        $name = trim((string)($names[$language] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }
    return '';
}

function geoip_safe_key(string $value): string
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
    return trim($value, '-') ?: 'unknown';
}

function geoip_lookup(string $ip): ?array
{
    if (!geoip_status()['ready']) {
        return null;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return geoip_unknown_location();
    }

    try {
        $reader = new \MaxMind\Db\Reader(HGO_GEOIP_DATABASE);
        $data = $reader->get($ip);
        if (!is_array($data)) {
            return geoip_unknown_location();
        }

        $country = is_array($data['country'] ?? null) ? $data['country'] : [];
        $countryCode = strtoupper(trim((string)($country['iso_code'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return geoip_unknown_location();
        }
        $countryName = geoip_name($country) ?: $countryCode;
        if ($countryCode === 'PL') {
            $countryName = 'Polska';
        }

        $subdivisions = $data['subdivisions'] ?? [];
        $region = is_array($subdivisions) && isset($subdivisions[0]) && is_array($subdivisions[0]) ? $subdivisions[0] : [];
        $regionCode = strtoupper(trim((string)($region['iso_code'] ?? '')));
        $regionName = geoip_name($region);
        if ($regionCode === '' && $regionName === '') {
            $regionCode = 'unknown';
            $regionName = 'Nieznany region';
        }

        $city = is_array($data['city'] ?? null) ? $data['city'] : [];
        $cityName = geoip_name($city);
        $cityId = trim((string)($city['geoname_id'] ?? ''));
        if ($cityName === '') {
            $cityName = 'Nieznana lokalizacja';
            $cityId = 'unknown';
        }

        return [
            'country_code' => $countryCode,
            'country_name' => $countryName,
            'region_code' => $regionCode,
            'region_name' => $regionName,
            'city_key' => $cityId !== '' ? $cityId : geoip_safe_key($cityName),
            'city_name' => $cityName,
        ];
    } catch (Throwable $error) {
        return geoip_unknown_location();
    }
}

function geoip_increment(array &$stats, array $location): void
{
    if (!isset($stats['locations']) || !is_array($stats['locations'])) {
        $stats['locations'] = ['page_views' => 0, 'countries' => [], 'regions' => [], 'cities' => []];
    }
    $locations = &$stats['locations'];
    $locations['page_views'] = max(0, (int)($locations['page_views'] ?? 0)) + 1;
    foreach (['countries', 'regions', 'cities'] as $level) {
        if (!isset($locations[$level]) || !is_array($locations[$level])) {
            $locations[$level] = [];
        }
    }

    $countryCode = (string)$location['country_code'];
    $country = &$locations['countries'][$countryCode];
    if (!is_array($country)) {
        $country = ['name' => (string)$location['country_name'], 'count' => 0];
    }
    $country['count'] = max(0, (int)($country['count'] ?? 0)) + 1;

    $regionKey = $countryCode . ':' . (string)$location['region_code'];
    $region = &$locations['regions'][$regionKey];
    if (!is_array($region)) {
        $region = ['country_code' => $countryCode, 'code' => (string)$location['region_code'], 'name' => (string)$location['region_name'], 'count' => 0];
    }
    $region['count'] = max(0, (int)($region['count'] ?? 0)) + 1;

    $cityKey = $regionKey . ':' . (string)$location['city_key'];
    $city = &$locations['cities'][$cityKey];
    if (!is_array($city)) {
        $city = ['country_code' => $countryCode, 'region_code' => (string)$location['region_code'], 'region_name' => (string)$location['region_name'], 'name' => (string)$location['city_name'], 'count' => 0];
    }
    $city['count'] = max(0, (int)($city['count'] ?? 0)) + 1;
}
