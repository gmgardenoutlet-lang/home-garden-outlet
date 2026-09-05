<?php
declare(strict_types=1);

const SITE_ROOT = __DIR__ . '/..';
const PRODUCTS_FILE = SITE_ROOT . '/data/products.json';
const SHIPPING_PROFILES_FILE = SITE_ROOT . '/data/shipping-profiles.json';
const UPLOAD_DIR = SITE_ROOT . '/uploads';
define('STORAGE_DIR', getenv('HGO_STORAGE_DIR') ?: __DIR__ . '/storage');
define('PRODUCT_IMAGE_DRAFT_DIR', STORAGE_DIR . '/product-image-drafts');
define('BACKUP_DIR', STORAGE_DIR . '/backups');
define('STATS_DIR', STORAGE_DIR . '/stats');
define('STATS_EVENT_DIR', STORAGE_DIR . '/events');
define('ORDERS_DIR', STORAGE_DIR . '/orders');
const STATS_TIMEZONE = 'Europe/Warsaw';
const CREDENTIALS_FILE = __DIR__ . '/.credentials.php';
const GOOGLE_BUSINESS_CONFIG_FILE = STORAGE_DIR . '/google-business.php';
const SHOP_BANK_TRANSFER_RECIPIENT = 'EMAALL GARDEN OUTLET sp. z o.o.';
const SHOP_BANK_TRANSFER_ACCOUNT = 'PL34114020040000390281029165';
const SHOP_BANK_TRANSFER_BANK = 'mBank';
const SHOP_BANK_TRANSFER_BIC = 'BREXPLPWMBK';
const MAX_UPLOAD_BYTES = 12 * 1024 * 1024;
const MAX_IMAGE_EDGE = 2200;
const MAX_DRAFT_IMAGES = 12;
const PRODUCT_IMAGE_DRAFT_TTL = 7 * 24 * 60 * 60;
const MAX_PRODUCT_DRAFT_JSON_BYTES = 256 * 1024;

require_once SITE_ROOT . '/lib/geoip.php';
require_once SITE_ROOT . '/lib/stats-exclusion.php';
require_once SITE_ROOT . '/catalog.php';
require_once __DIR__ . '/../shop-test/config.php';

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

function boot_admin(bool $sendRobotsHeader = true): void
{
    $host = strtolower((string)preg_replace('/:\\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === 'www.mgoutlet.pl') {
        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/admin/');
        if (!str_starts_with($requestUri, '/')) {
            $requestUri = '/admin/';
        }
        header('Location: https://mgoutlet.pl' . $requestUri, true, 301);
        exit;
    }

    if (!is_dir(STORAGE_DIR)) {
        @mkdir(STORAGE_DIR, 0750, true);
    }
    if (!is_dir(BACKUP_DIR)) {
        @mkdir(BACKUP_DIR, 0750, true);
    }
    if (!is_dir(ORDERS_DIR)) {
        @mkdir(ORDERS_DIR, 0750, true);
    }
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }
    if (!is_dir(PRODUCT_IMAGE_DRAFT_DIR)) {
        @mkdir(PRODUCT_IMAGE_DRAFT_DIR, 0750, true);
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_name('hgo_admin');
    session_start();

    if ($sendRobotsHeader) {
        header('X-Robots-Tag: noindex, nofollow, noarchive');
    }
    header('Cache-Control: no-store, max-age=0');
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect_admin(string $query = ''): void
{
    header('Location: /admin/' . ($query !== '' ? '?' . ltrim($query, '?') : ''));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['csrf'];
}

function require_csrf(): void
{
    $provided = (string)($_POST['csrf'] ?? '');
    if ($provided === '' || !hash_equals(csrf_token(), $provided)) {
        throw new RuntimeException('Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($messages) ? $messages : [];
}

function credentials(): ?array
{
    if (!is_file(CREDENTIALS_FILE)) {
        return null;
    }
    $config = require CREDENTIALS_FILE;
    return is_array($config) ? $config : null;
}

function google_business_config_defaults(): array
{
    return [
        'enabled' => false,
        'dry_run' => true,
        'client_id' => '',
        'client_secret' => '',
        'refresh_token' => '',
        'account_id' => '',
        'location_id' => '',
        'site_url' => 'https://mgoutlet.pl',
    ];
}

function load_google_business_config(): array
{
    $defaults = google_business_config_defaults();
    if (!is_file(GOOGLE_BUSINESS_CONFIG_FILE)) {
        return $defaults;
    }

    $config = require GOOGLE_BUSINESS_CONFIG_FILE;
    return is_array($config) ? array_merge($defaults, $config) : $defaults;
}

function google_business_config_status(array $config): array
{
    $missing = [];
    foreach (['client_id', 'client_secret', 'refresh_token', 'account_id', 'location_id'] as $key) {
        if (trim((string)($config[$key] ?? '')) === '') {
            $missing[] = $key;
        }
    }
    if (empty($config['enabled'])) {
        $missing[] = 'enabled=true';
    }
    if (!empty($config['dry_run'])) {
        $missing[] = 'dry_run=false';
    }

    return [
        'ready' => $missing === [],
        'enabled' => !empty($config['enabled']),
        'dryRun' => !empty($config['dry_run']),
        'missing' => $missing,
    ];
}

function save_google_business_config(array $newConfig, array $previousConfig = []): void
{
    $previousConfig = array_merge(google_business_config_defaults(), $previousConfig);
    $siteUrl = trim((string)($newConfig['site_url'] ?? ''));
    if ($siteUrl === '') {
        $siteUrl = 'https://mgoutlet.pl';
    }
    if (!preg_match('#^https://[a-z0-9.-]+(?:/)?$#i', $siteUrl)) {
        throw new RuntimeException('Adres strony musi być adresem HTTPS, np. https://mgoutlet.pl');
    }

    $config = [
        'enabled' => !empty($newConfig['enabled']),
        'dry_run' => !empty($newConfig['dry_run']),
        'client_id' => trim((string)($newConfig['client_id'] ?? '')),
        'client_secret' => trim((string)($newConfig['client_secret'] ?? '')) !== ''
            ? trim((string)$newConfig['client_secret'])
            : (string)$previousConfig['client_secret'],
        'refresh_token' => trim((string)($newConfig['refresh_token'] ?? '')) !== ''
            ? trim((string)$newConfig['refresh_token'])
            : (string)$previousConfig['refresh_token'],
        'account_id' => trim((string)($newConfig['account_id'] ?? '')),
        'location_id' => trim((string)($newConfig['location_id'] ?? '')),
        'site_url' => rtrim($siteUrl, '/'),
    ];

    if (!is_dir(STORAGE_DIR)) {
        @mkdir(STORAGE_DIR, 0750, true);
    }

    $payload = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents(GOOGLE_BUSINESS_CONFIG_FILE, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Nie udało się zapisać konfiguracji Google API.');
    }
    @chmod(GOOGLE_BUSINESS_CONFIG_FILE, 0640);
}

function save_credentials(string $username, string $password): void
{
    if (trim($username) === '') {
        throw new RuntimeException('Podaj nazwę użytkownika.');
    }
    if (strlen($password) < 12) {
        throw new RuntimeException('Hasło musi mieć co najmniej 12 znaków.');
    }
    if (!preg_match('/[A-ZĄĆĘŁŃÓŚŹŻ]/u', $password) || !preg_match('/[a-ząćęłńóśźż]/u', $password) || !preg_match('/\d/', $password)) {
        throw new RuntimeException('Hasło musi zawierać małą literę, dużą literę i cyfrę.');
    }

    $payload = "<?php\nreturn " . var_export([
        'username' => trim($username),
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => date(DATE_ATOM),
    ], true) . ";\n";

    if (file_put_contents(CREDENTIALS_FILE, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Nie udało się zapisać danych logowania.');
    }
    @chmod(CREDENTIALS_FILE, 0640);
}

function is_logged_in(): bool
{
    return !empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect_admin();
    }
}

function login_attempt_file(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return STORAGE_DIR . '/login-' . hash('sha256', $ip) . '.json';
}

function login_allowed(): bool
{
    $file = login_attempt_file();
    if (!is_file($file)) {
        return true;
    }
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data)) {
        return true;
    }
    return !((int)($data['count'] ?? 0) >= 7 && (int)($data['last'] ?? 0) > time() - 900);
}

function register_failed_login(): void
{
    $file = login_attempt_file();
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    $count = is_array($data) && (int)($data['last'] ?? 0) > time() - 900 ? (int)($data['count'] ?? 0) + 1 : 1;
    file_put_contents($file, json_encode(['count' => $count, 'last' => time()]), LOCK_EX);
}

function clear_failed_logins(): void
{
    @unlink(login_attempt_file());
}

function try_login(string $username, string $password): bool
{
    if (!login_allowed()) {
        throw new RuntimeException('Za dużo prób logowania. Spróbuj ponownie za około 15 minut.');
    }
    $config = credentials();
    if (!$config || !hash_equals((string)$config['username'], trim($username)) || !password_verify($password, (string)$config['password_hash'])) {
        register_failed_login();
        return false;
    }

    clear_failed_logins();
    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    $_SESSION['username'] = (string)$config['username'];
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return true;
}

function load_catalog(): array
{
    if (!is_file(PRODUCTS_FILE)) {
        return ['products' => []];
    }
    $json = file_get_contents(PRODUCTS_FILE);
    $data = json_decode((string)$json, true);
    if (!is_array($data) || !isset($data['products']) || !is_array($data['products'])) {
        throw new RuntimeException('Plik produktów jest uszkodzony lub ma nieprawidłową strukturę.');
    }
    return $data;
}

function shipping_profile_defaults(): array
{
    return [
        'id' => '',
        'name' => '',
        'customerName' => '',
        'type' => 'kurier',
        'price' => null,
        'currency' => 'PLN',
        'active' => true,
        'description' => '',
        'maxWeightKg' => '',
        'maxLengthCm' => '',
        'maxWidthCm' => '',
        'maxHeightCm' => '',
        'requiresConfirmation' => false,
        'priceFrom' => false,
        'sortOrder' => 100,
        'internalNote' => '',
    ];
}

function default_shipping_profiles(): array
{
    return [
        ['id' => 'paczkomat-maly', 'name' => 'Paczkomat mały', 'customerName' => 'Paczkomat mały', 'type' => 'paczkomat', 'price' => 19.99, 'description' => 'Dostawa do Paczkomatu dla mniejszych figur i dekoracji.', 'maxWeightKg' => 10, 'maxLengthCm' => 41, 'maxWidthCm' => 38, 'maxHeightCm' => 8, 'sortOrder' => 10],
        ['id' => 'paczkomat-sredni', 'name' => 'Paczkomat średni', 'customerName' => 'Paczkomat średni', 'type' => 'paczkomat', 'price' => 24.99, 'description' => 'Dostawa do Paczkomatu dla średnich produktów.', 'maxWeightKg' => 15, 'maxLengthCm' => 41, 'maxWidthCm' => 38, 'maxHeightCm' => 19, 'sortOrder' => 20],
        ['id' => 'paczkomat-duzy', 'name' => 'Paczkomat duży', 'customerName' => 'Paczkomat duży', 'type' => 'paczkomat', 'price' => 29.99, 'description' => 'Dostawa do Paczkomatu dla większych paczek mieszczących się w limicie gabarytu.', 'maxWeightKg' => 25, 'maxLengthCm' => 64, 'maxWidthCm' => 38, 'maxHeightCm' => 41, 'sortOrder' => 30],
        ['id' => 'kurier-standardowy', 'name' => 'Kurier standardowy', 'customerName' => 'Kurier standardowy', 'type' => 'kurier', 'price' => 39.99, 'description' => 'Dostawa kurierem dla standardowych produktów.', 'maxWeightKg' => 20, 'maxLengthCm' => 65, 'maxWidthCm' => 40, 'maxHeightCm' => 40, 'sortOrder' => 40],
        ['id' => 'kurier-gabarytowy', 'name' => 'Kurier gabarytowy', 'customerName' => 'Kurier gabarytowy', 'type' => 'kurier_gabarytowy', 'price' => 69.99, 'description' => 'Dostawa dla większych produktów. Koszt może wymagać potwierdzenia przy większej liczbie sztuk.', 'maxWeightKg' => 31.5, 'maxLengthCm' => 120, 'maxWidthCm' => 60, 'maxHeightCm' => 60, 'sortOrder' => 50],
        ['id' => 'paleta', 'name' => 'Paleta', 'customerName' => 'Paleta', 'type' => 'paleta', 'price' => 149.00, 'description' => 'Dostawa paletowa dla ciężkich lub gabarytowych produktów.', 'requiresConfirmation' => true, 'priceFrom' => true, 'sortOrder' => 60],
        ['id' => 'odbior-osobisty', 'name' => 'Odbiór osobisty', 'customerName' => 'Odbiór osobisty', 'type' => 'odbior_osobisty', 'price' => 0.00, 'description' => 'Odbiór osobisty w showroomie Home & Garden Outlet, ul. Przelotowa 16, 55-080 Kębłowice.', 'sortOrder' => 70],
        ['id' => 'dostawa-indywidualna', 'name' => 'Dostawa do ustalenia indywidualnie', 'customerName' => 'Dostawa do ustalenia indywidualnie', 'type' => 'do_ustalenia', 'price' => null, 'description' => 'Skontaktujemy się po złożeniu zamówienia w celu potwierdzenia kosztu i sposobu transportu.', 'requiresConfirmation' => true, 'sortOrder' => 80],
    ];
}

function clean_shipping_profile_id(string $value): string
{
    return clean_filename($value);
}

function shipping_profile_types(): array
{
    return [
        'paczkomat' => 'paczkomat',
        'kurier' => 'kurier',
        'kurier_gabarytowy' => 'kurier gabarytowy',
        'paleta' => 'paleta',
        'odbior_osobisty' => 'odbiór osobisty',
        'do_ustalenia' => 'do ustalenia',
    ];
}

function shipping_profile_price_number($value): ?float
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $cleaned = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], $value);
    if (!preg_match('/\d+(?:\.\d+)?/', $cleaned, $matches)) {
        return null;
    }
    $price = (float)$matches[0];
    return $price >= 0 ? round($price, 2) : null;
}

function normalize_shipping_profile(array $profile): array
{
    $normalized = array_merge(shipping_profile_defaults(), $profile);
    $normalized['id'] = clean_shipping_profile_id((string)$normalized['id']);
    $normalized['name'] = trim((string)$normalized['name']);
    $normalized['customerName'] = trim((string)$normalized['customerName']);
    if ($normalized['customerName'] === '') {
        $normalized['customerName'] = $normalized['name'];
    }
    $types = array_keys(shipping_profile_types());
    $normalized['type'] = in_array((string)$normalized['type'], $types, true) ? (string)$normalized['type'] : 'kurier';
    $normalized['price'] = shipping_profile_price_number($normalized['price']);
    $normalized['currency'] = 'PLN';
    $normalized['active'] = !empty($normalized['active']);
    $normalized['description'] = trim((string)$normalized['description']);
    foreach (['maxWeightKg', 'maxLengthCm', 'maxWidthCm', 'maxHeightCm'] as $field) {
        $normalized[$field] = trim((string)$normalized[$field]);
    }
    $normalized['requiresConfirmation'] = !empty($normalized['requiresConfirmation']);
    $normalized['priceFrom'] = !empty($normalized['priceFrom']);
    $normalized['sortOrder'] = (int)$normalized['sortOrder'];
    $normalized['internalNote'] = trim((string)$normalized['internalNote']);
    return $normalized;
}

function load_shipping_profiles(): array
{
    $profiles = [];
    if (is_file(SHIPPING_PROFILES_FILE)) {
        $data = json_decode((string)file_get_contents(SHIPPING_PROFILES_FILE), true);
        if (is_array($data)) {
            $profiles = is_array($data['profiles'] ?? null) ? $data['profiles'] : $data;
        }
    }
    if (!$profiles) {
        $profiles = default_shipping_profiles();
    }
    $result = [];
    foreach ($profiles as $profile) {
        if (!is_array($profile)) {
            continue;
        }
        $normalized = normalize_shipping_profile($profile);
        if ($normalized['id'] !== '' && $normalized['name'] !== '') {
            $result[$normalized['id']] = $normalized;
        }
    }
    if (!$result) {
        foreach (default_shipping_profiles() as $profile) {
            $normalized = normalize_shipping_profile($profile);
            $result[$normalized['id']] = $normalized;
        }
    }
    uasort($result, static function (array $a, array $b): int {
        return ((int)$a['sortOrder'] <=> (int)$b['sortOrder']) ?: strcmp((string)$a['name'], (string)$b['name']);
    });
    return array_values($result);
}

/**
 * Customer-facing shop paths must use the current cennik saved by the admin
 * panel. Unlike load_shipping_profiles(), this deliberately has no defaults:
 * an unreadable or malformed production file means that delivery is unavailable.
 */
function load_shipping_profiles_for_shop(): array
{
    if (!is_file(SHIPPING_PROFILES_FILE)) {
        return [];
    }

    $json = @file_get_contents(SHIPPING_PROFILES_FILE);
    if ($json === false || trim($json) === '') {
        return [];
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }
    $rawProfiles = is_array($data['profiles'] ?? null)
        ? $data['profiles']
        : (array_is_list($data) ? $data : null);
    if (!is_array($rawProfiles) || !$rawProfiles) {
        return [];
    }

    $result = [];
    foreach ($rawProfiles as $profile) {
        if (!is_array($profile)) {
            return [];
        }
        $normalized = normalize_shipping_profile($profile);
        if ($normalized['id'] === '' || $normalized['name'] === '') {
            return [];
        }
        $result[$normalized['id']] = $normalized;
    }
    if (!$result) {
        return [];
    }
    uasort($result, static function (array $a, array $b): int {
        return ((int)$a['sortOrder'] <=> (int)$b['sortOrder']) ?: strcmp((string)$a['name'], (string)$b['name']);
    });
    return array_values($result);
}

function save_shipping_profiles(array $profiles): void
{
    $normalized = [];
    foreach ($profiles as $profile) {
        if (!is_array($profile)) {
            continue;
        }
        $item = normalize_shipping_profile($profile);
        if ($item['id'] !== '' && $item['name'] !== '') {
            $normalized[$item['id']] = $item;
        }
    }
    uasort($normalized, static function (array $a, array $b): int {
        return ((int)$a['sortOrder'] <=> (int)$b['sortOrder']) ?: strcmp((string)$a['name'], (string)$b['name']);
    });
    $directory = dirname(SHIPPING_PROFILES_FILE);
    if (!is_dir($directory)) {
        @mkdir($directory, 0755, true);
    }
    $payload = ['profiles' => array_values($normalized)];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Nie udało się przygotować cennika dostaw.');
    }
    $temp = SHIPPING_PROFILES_FILE . '.tmp';
    if (file_put_contents($temp, $json . PHP_EOL, LOCK_EX) === false || !@rename($temp, SHIPPING_PROFILES_FILE)) {
        @unlink($temp);
        throw new RuntimeException('Nie udało się zapisać cennika dostaw.');
    }
    @chmod(SHIPPING_PROFILES_FILE, 0644);
}

function shipping_profiles_by_id(bool $activeOnly = false): array
{
    $profiles = [];
    foreach (load_shipping_profiles() as $profile) {
        if ($activeOnly && empty($profile['active'])) {
            continue;
        }
        $profiles[(string)$profile['id']] = $profile;
    }
    return $profiles;
}

function shipping_profiles_by_id_for_shop(bool $activeOnly = false): array
{
    $profiles = [];
    foreach (load_shipping_profiles_for_shop() as $profile) {
        if ($activeOnly && empty($profile['active'])) {
            continue;
        }
        $profiles[(string)$profile['id']] = $profile;
    }
    return $profiles;
}

function shipping_legacy_method_map(): array
{
    return [
        'parcel_locker' => 'paczkomat-sredni',
        'courier' => 'kurier-standardowy',
        'large_courier' => 'kurier-gabarytowy',
        'pallet' => 'paleta',
        'pickup' => 'odbior-osobisty',
        'individual' => 'dostawa-indywidualna',
    ];
}

function product_shipping_profile_ids(array $product): array
{
    $ids = [];
    foreach ((array)($product['shippingProfileIds'] ?? []) as $id) {
        $id = clean_shipping_profile_id((string)$id);
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    if (!$ids) {
        $legacyMap = shipping_legacy_method_map();
        foreach ((array)($product['deliveryMethods'] ?? []) as $method) {
            if (!is_array($method)) {
                continue;
            }
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($method['method'] ?? ''))) ?: '';
            if (isset($legacyMap[$key])) {
                $ids[] = $legacyMap[$key];
            }
        }
    }
    return array_values(array_unique($ids));
}

function shipping_profile_price_label(array $profile): string
{
    if (($profile['price'] ?? null) === null) {
        return 'do ustalenia';
    }
    $price = number_format((float)$profile['price'], 2, ',', ' ') . ' zł';
    return !empty($profile['priceFrom']) ? 'od ' . $price : $price;
}

function shipping_profile_public(array $profile): array
{
    $price = ($profile['price'] ?? null) === null ? null : (float)$profile['price'];
    $requiresConfirmation = !empty($profile['requiresConfirmation']) || $price === null;
    return [
        'method' => (string)$profile['id'],
        'profileId' => (string)$profile['id'],
        'label' => (string)$profile['customerName'],
        'type' => (string)$profile['type'],
        'cost' => shipping_profile_price_label($profile),
        'costNumber' => $requiresConfirmation ? null : $price,
        'priceFrom' => !empty($profile['priceFrom']),
        'requiresConfirmation' => $requiresConfirmation,
        'description' => (string)$profile['description'],
    ];
}

function stats_event_labels(): array
{
    return [
        'call_click' => 'Telefon',
        'sms_click' => 'SMS',
        'navigation_click' => 'Nawigacja',
        'facebook_click' => 'Facebook',
        'instagram_click' => 'Instagram',
        'product_question_click' => 'Zapytanie o produkt',
    ];
}

function normalize_stats_range(string $range): string
{
    return in_array($range, ['today', '7', '30', '90'], true) ? $range : 'today';
}

function normalize_stats_product_limit($limit): int
{
    $value = (int)$limit;
    return in_array($value, [10, 25, 50], true) ? $value : 10;
}

function stats_range_days(string $range): int
{
    if ($range === '90') {
        return 90;
    }
    if ($range === '30') {
        return 30;
    }
    if ($range === '7') {
        return 7;
    }
    return 1;
}

function stats_percent(int $count, int $total): string
{
    return $total > 0 ? number_format(($count * 100) / $total, 1, ',', ' ') . '%' : '0,0%';
}

function empty_location_summary(string $range): array
{
    return [
        'range' => $range,
        'days' => stats_range_days($range),
        'pageViews' => 0,
        'daysWithData' => 0,
        'firstDate' => null,
        'countries' => [],
        'regions' => [],
        'cities' => [],
        'otherCities' => 0,
        'local' => ['wroclaw' => 0, 'lowerSilesia' => 0, 'restPoland' => 0, 'foreign' => 0, 'unknown' => 0],
    ];
}

function stats_location_row(array $row, array $fields): array
{
    $result = [];
    foreach ($fields as $field => $fallback) {
        $result[$field] = $field === 'count' ? safe_stat_int($row[$field] ?? 0) : trim((string)($row[$field] ?? $fallback));
    }
    return $result;
}

function stats_region_code_key(string $regionCode): string
{
    $regionCode = strtoupper(trim($regionCode));
    return preg_replace('/^PL-/', '', $regionCode) ?: $regionCode;
}

function stats_polish_voivodeship(string $countryCode, string $regionCode, string $name): string
{
    $byCode = [
        'DS' => 'Dolnośląskie', '02' => 'Dolnośląskie',
        'KP' => 'Kujawsko-Pomorskie', '04' => 'Kujawsko-Pomorskie',
        'LU' => 'Lubelskie', '06' => 'Lubelskie', 'LB' => 'Lubuskie', '08' => 'Lubuskie',
        'LD' => 'Łódzkie', '10' => 'Łódzkie', 'MA' => 'Małopolskie', '12' => 'Małopolskie',
        'MZ' => 'Mazowieckie', '14' => 'Mazowieckie', 'OP' => 'Opolskie', '16' => 'Opolskie',
        'PK' => 'Podkarpackie', '18' => 'Podkarpackie', 'PD' => 'Podlaskie', '20' => 'Podlaskie',
        'PM' => 'Pomorskie', '22' => 'Pomorskie', 'SL' => 'Śląskie', '24' => 'Śląskie',
        'SK' => 'Świętokrzyskie', '26' => 'Świętokrzyskie', 'WN' => 'Warmińsko-Mazurskie', '28' => 'Warmińsko-Mazurskie',
        'WP' => 'Wielkopolskie', '30' => 'Wielkopolskie', 'ZP' => 'Zachodniopomorskie', '32' => 'Zachodniopomorskie',
    ];
    $code = stats_region_code_key($regionCode);
    $normalizedCountryCode = strtoupper(trim($countryCode));
    if (($normalizedCountryCode === '' || $normalizedCountryCode === 'PL') && isset($byCode[$code])) {
        return $byCode[$code];
    }

    $byName = [
        'lower-silesian-voivodeship' => 'Dolnośląskie', 'lower-silesia' => 'Dolnośląskie', 'dolnoslaskie' => 'Dolnośląskie',
        'kujawsko-pomorskie' => 'Kujawsko-Pomorskie', 'kuyavian-pomeranian-voivodeship' => 'Kujawsko-Pomorskie',
        'lubelskie' => 'Lubelskie', 'lublin-voivodeship' => 'Lubelskie', 'lubuskie' => 'Lubuskie', 'lubusz-voivodeship' => 'Lubuskie',
        'lodzkie' => 'Łódzkie', 'lodz-voivodeship' => 'Łódzkie', 'malopolskie' => 'Małopolskie', 'lesser-poland-voivodeship' => 'Małopolskie',
        'mazowieckie' => 'Mazowieckie', 'masovian-voivodeship' => 'Mazowieckie', 'opolskie' => 'Opolskie', 'opole-voivodeship' => 'Opolskie',
        'podkarpackie' => 'Podkarpackie', 'subcarpathian-voivodeship' => 'Podkarpackie', 'podlaskie' => 'Podlaskie', 'podlaskie-voivodeship' => 'Podlaskie',
        'pomorskie' => 'Pomorskie', 'pomeranian-voivodeship' => 'Pomorskie', 'slaskie' => 'Śląskie', 'silesian-voivodeship' => 'Śląskie',
        'swietokrzyskie' => 'Świętokrzyskie', 'holy-cross-voivodeship' => 'Świętokrzyskie',
        'warminsko-mazurskie' => 'Warmińsko-Mazurskie', 'warmian-masurian-voivodeship' => 'Warmińsko-Mazurskie',
        'wielkopolskie' => 'Wielkopolskie', 'greater-poland-voivodeship' => 'Wielkopolskie',
        'zachodniopomorskie' => 'Zachodniopomorskie', 'west-pomeranian-voivodeship' => 'Zachodniopomorskie',
    ];
    return $byName[geoip_safe_key($name)] ?? '';
}

function stats_is_lower_silesia(string $countryCode, string $regionCode): bool
{
    return strtoupper(trim($countryCode)) === 'PL' && in_array(stats_region_code_key($regionCode), ['DS', '02'], true);
}

function stats_location_display_country(string $countryCode, string $name): string
{
    $byCode = [
        'PL' => 'Polska', 'DE' => 'Niemcy', 'US' => 'Stany Zjednoczone', 'CZ' => 'Czechy', 'NL' => 'Holandia',
        'GB' => 'Wielka Brytania', 'FR' => 'Francja', 'ES' => 'Hiszpania', 'IT' => 'Włochy', 'AT' => 'Austria',
        'BE' => 'Belgia', 'CH' => 'Szwajcaria', 'DK' => 'Dania', 'SE' => 'Szwecja', 'NO' => 'Norwegia',
        'FI' => 'Finlandia', 'IE' => 'Irlandia', 'UA' => 'Ukraina', 'SK' => 'Słowacja', 'LT' => 'Litwa',
        'LV' => 'Łotwa', 'EE' => 'Estonia',
    ];
    $code = strtoupper(trim($countryCode));
    if (isset($byCode[$code])) {
        return $byCode[$code];
    }
    $byName = ['germany' => 'Niemcy', 'united-states' => 'Stany Zjednoczone', 'usa' => 'Stany Zjednoczone', 'czechia' => 'Czechy', 'czech-republic' => 'Czechy', 'netherlands' => 'Holandia', 'united-kingdom' => 'Wielka Brytania'];
    $original = $name !== '' ? $name : $countryCode;
    return $byName[geoip_safe_key($original)] ?? ($original !== '' ? $original : 'Nieznana lokalizacja');
}

function stats_location_display_region(string $countryCode, string $regionCode, string $name): string
{
    $voivodeship = stats_polish_voivodeship($countryCode, $regionCode, $name);
    if ($voivodeship !== '') return $voivodeship;
    return $name !== '' ? $name : 'Nieznana lokalizacja';
}

function stats_location_display_city(string $countryCode, string $regionCode, string $name): string
{
    $cities = ['wroclaw' => 'Wrocław', 'krakow' => 'Kraków', 'poznan' => 'Poznań', 'lodz' => 'Łódź', 'warszawa' => 'Warszawa', 'warsaw' => 'Warszawa'];
    $key = geoip_safe_key($name);
    if (isset($cities[$key])) return $cities[$key];
    return $name !== '' ? $name : 'Nieznana lokalizacja';
}

function stats_localize_location_row(array $row, string $level): array
{
    // Work on a copy: rows read from historical JSON files remain untouched.
    if ($level === 'country') {
        $row['name'] = stats_location_display_country((string)($row['code'] ?? ''), (string)($row['name'] ?? ''));
    } elseif ($level === 'region') {
        $row['name'] = stats_location_display_region((string)($row['country_code'] ?? ''), (string)($row['code'] ?? ''), (string)($row['name'] ?? ''));
    } elseif ($level === 'city') {
        $countryCode = (string)($row['country_code'] ?? '');
        $regionCode = (string)($row['region_code'] ?? '');
        $row['region_name'] = stats_location_display_region($countryCode, $regionCode, (string)($row['region_name'] ?? ''));
        $row['name'] = stats_location_display_city($countryCode, $regionCode, (string)($row['name'] ?? ''));
    }
    return $row;
}

function stats_local_breakdown(array $countries, array $regions, array $cities): array
{
    $poland = $lowerSilesiaTotal = $wroclaw = $foreign = $unknown = 0;
    foreach ($countries as $row) {
        $code = strtoupper(trim((string)($row['code'] ?? '')));
        $count = safe_stat_int($row['count'] ?? 0);
        if ($code === 'PL') {
            $poland += $count;
        } elseif ($code === 'UNKNOWN' || $code === '') {
            $unknown += $count;
        } else {
            $foreign += $count;
        }
    }
    foreach ($regions as $row) {
        if (stats_is_lower_silesia((string)($row['country_code'] ?? ''), (string)($row['code'] ?? ''))) {
            $lowerSilesiaTotal += safe_stat_int($row['count'] ?? 0);
        }
    }
    foreach ($cities as $row) {
        if (stats_is_lower_silesia((string)($row['country_code'] ?? ''), (string)($row['region_code'] ?? ''))
            && geoip_safe_key((string)($row['name'] ?? '')) === 'wroclaw') {
            $wroclaw += safe_stat_int($row['count'] ?? 0);
        }
    }

    return [
        'wroclaw' => $wroclaw,
        'lowerSilesia' => max(0, $lowerSilesiaTotal - $wroclaw),
        'restPoland' => max(0, $poland - $lowerSilesiaTotal),
        'foreign' => $foreign,
        'unknown' => $unknown,
    ];
}

function load_location_summary(string $range): array
{
    $range = normalize_stats_range($range);
    $summary = empty_location_summary($range);
    $countries = $regions = $cities = [];
    $today = stats_today();

    for ($offset = 0; $offset < $summary['days']; $offset++) {
        $date = $today->modify('-' . $offset . ' days')->format('Y-m-d');
        $raw = @file_get_contents(STATS_DIR . '/' . $date . '.json');
        $day = json_decode((string)$raw, true);
        $locations = is_array($day) && is_array($day['locations'] ?? null) ? $day['locations'] : null;
        $pageViews = is_array($locations) ? safe_stat_int($locations['page_views'] ?? 0) : 0;
        if ($pageViews === 0) {
            continue;
        }
        $summary['pageViews'] += $pageViews;
        $summary['daysWithData']++;
        $summary['firstDate'] = $summary['firstDate'] === null || $date < $summary['firstDate'] ? $date : $summary['firstDate'];

        foreach ((array)($locations['countries'] ?? []) as $code => $row) {
            if (!is_array($row)) continue;
            $code = strtoupper(trim((string)$code));
            if ($code === '') $code = 'UNKNOWN';
            if (!isset($countries[$code])) $countries[$code] = ['code' => $code, 'name' => '', 'count' => 0];
            $countries[$code]['name'] = $countries[$code]['name'] ?: trim((string)($row['name'] ?? 'Nieznana lokalizacja'));
            $countries[$code]['count'] += safe_stat_int($row['count'] ?? 0);
        }
        foreach ((array)($locations['regions'] ?? []) as $key => $row) {
            if (!is_array($row)) continue;
            $key = (string)$key;
            if ($key === '') continue;
            if (!isset($regions[$key])) $regions[$key] = ['country_code' => '', 'code' => '', 'name' => '', 'count' => 0];
            foreach (['country_code', 'code', 'name'] as $field) if ($regions[$key][$field] === '') $regions[$key][$field] = trim((string)($row[$field] ?? ''));
            $regions[$key]['count'] += safe_stat_int($row['count'] ?? 0);
        }
        foreach ((array)($locations['cities'] ?? []) as $key => $row) {
            if (!is_array($row)) continue;
            $key = (string)$key;
            if ($key === '') continue;
            if (!isset($cities[$key])) $cities[$key] = ['country_code' => '', 'region_code' => '', 'region_name' => '', 'name' => '', 'count' => 0];
            foreach (['country_code', 'region_code', 'region_name', 'name'] as $field) if ($cities[$key][$field] === '') $cities[$key][$field] = trim((string)($row[$field] ?? ''));
            $cities[$key]['count'] += safe_stat_int($row['count'] ?? 0);
        }
    }

    foreach ($countries as &$row) {
        $row = stats_localize_location_row($row, 'country');
    }
    unset($row);
    foreach ($regions as &$row) {
        $row = stats_localize_location_row($row, 'region');
    }
    unset($row);
    foreach ($cities as &$row) {
        $row = stats_localize_location_row($row, 'city');
    }
    unset($row);
    $summary['local'] = stats_local_breakdown($countries, $regions, $cities);

    uasort($countries, static fn(array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp($a['name'], $b['name']));
    uasort($regions, static fn(array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp($a['name'], $b['name']));
    uasort($cities, static fn(array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp($a['name'], $b['name']));
    $summary['countries'] = array_values($countries);
    $summary['regions'] = array_values(array_filter($regions, static fn(array $row): bool => ($row['country_code'] ?? '') === 'PL'));
    $summary['cities'] = array_slice(array_values($cities), 0, 20);
    foreach (array_slice(array_values($cities), 20) as $row) $summary['otherCities'] += safe_stat_int($row['count'] ?? 0);
    return $summary;
}

function empty_stats_summary(string $range): array
{
    $events = ['page_view', 'product_view', 'call_click', 'sms_click', 'navigation_click', 'facebook_click', 'instagram_click', 'product_question_click'];
    return [
        'range' => $range,
        'days' => stats_range_days($range),
        'totals' => array_fill_keys($events, 0),
        'buttons' => array_fill_keys(array_keys(stats_event_labels()), 0),
        'pages' => [],
        'products' => [],
        'topPages' => [],
        'topProducts' => [],
        'buttonRows' => [],
        'daysRead' => 0,
        'missingDays' => 0,
        'invalidFiles' => 0,
        'hasData' => false,
    ];
}

function safe_stat_int($value): int
{
    return max(0, (int)$value);
}

function product_names_by_slug(array $catalog): array
{
    $names = [];
    foreach (($catalog['products'] ?? []) as $product) {
        if (!is_array($product)) {
            continue;
        }
        $source = trim((string)($product['slug'] ?? '')) !== ''
            ? (string)$product['slug']
            : (string)($product['name'] ?? '');
        $slug = clean_filename($source);
        if ($slug !== '') {
            $names[$slug] = (string)($product['name'] ?? $slug);
        }
    }
    return $names;
}

function stats_today(): DateTimeImmutable
{
    return new DateTimeImmutable('today', new DateTimeZone(STATS_TIMEZONE));
}

function normalize_traffic_chart_range(string $range): string
{
    return in_array($range, ['7', '28', '90'], true) ? $range : '28';
}

function traffic_chart_range_days(string $range): int
{
    return (int)normalize_traffic_chart_range($range);
}

/**
 * Returns daily, private statistics for the authenticated admin view only.
 * A missing file remains unavailable (null values); a valid file with no
 * events is represented as zero. This deliberately never alters storage.
 */
function load_daily_traffic(string $range, ?DateTimeImmutable $endDate = null): array
{
    $range = normalize_traffic_chart_range($range);
    $days = traffic_chart_range_days($range);
    $endDate = $endDate ?: stats_today();
    $rows = [];
    $totals = ['page_view' => 0, 'product_view' => 0];
    $daysWithData = 0;

    for ($offset = $days - 1; $offset >= 0; $offset--) {
        $date = $endDate->modify('-' . $offset . ' days')->format('Y-m-d');
        $file = STATS_DIR . '/' . $date . '.json';
        $row = ['date' => $date, 'page_view' => null, 'product_view' => null, 'available' => false];
        if (is_file($file)) {
            $day = json_decode((string)file_get_contents($file), true);
            if (is_array($day) && ($day['date'] ?? $date) === $date) {
                $row['page_view'] = safe_stat_int($day['totals']['page_view'] ?? 0);
                $row['product_view'] = safe_stat_int($day['totals']['product_view'] ?? 0);
                $row['available'] = true;
                $daysWithData++;
                $totals['page_view'] += $row['page_view'];
                $totals['product_view'] += $row['product_view'];
            }
        }
        $rows[] = $row;
    }

    return [
        'range' => $range,
        'days' => $days,
        'daysWithData' => $daysWithData,
        'complete' => $daysWithData === $days,
        'totals' => $totals,
        'rows' => $rows,
    ];
}

function traffic_chart_comparison(array $current, array $previous): array
{
    $result = [];
    foreach (['page_view', 'product_view'] as $metric) {
        $currentValue = safe_stat_int($current['totals'][$metric] ?? 0);
        $previousValue = safe_stat_int($previous['totals'][$metric] ?? 0);
        $available = !empty($current['complete']) && !empty($previous['complete']);
        $result[$metric] = [
            'available' => $available,
            'previous' => $previousValue,
            'changePercent' => $available && $previousValue > 0
                ? round((($currentValue - $previousValue) / $previousValue) * 100, 1)
                : null,
        ];
    }
    return $result;
}

function stats_event_filter(string $value, array $allowed): string
{
    return in_array($value, $allowed, true) ? $value : '';
}

function load_diagnostic_events(string $range, array $filters = []): array
{
    $days = $range === '30' ? 30 : ($range === '7' ? 7 : 1);
    $today = stats_today(); $events = [];
    for ($offset = 0; $offset < $days; $offset++) {
        $file = STATS_EVENT_DIR . '/' . $today->modify('-' . $offset . ' days')->format('Y-m-d') . '.jsonl';
        if (!is_file($file)) continue;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (!is_array($row) || !preg_match('/^\d{4}-\d{2}-\d{2}T/', (string)($row['timestamp'] ?? ''))) continue;
            $match = true;
            foreach (['event_type' => 'type', 'country' => 'country', 'city' => 'city', 'client_class' => 'client', 'device_class' => 'device'] as $field => $filter) {
                $wanted = (string)($filters[$filter] ?? ''); $actual = (string)($row[$field] ?? '');
                if ($filter === 'type' && $wanted === 'other') { if (in_array($actual, ['page_view', 'product_view'], true)) $match = false; }
                elseif ($wanted !== '' && $actual !== $wanted) $match = false;
            }
            if ($match) $events[] = $row;
        }
    }
    usort($events, static fn(array $a, array $b): int => strcmp((string)$b['timestamp'], (string)$a['timestamp']));
    return array_slice($events, 0, 200);
}

function load_stats_summary(string $range, array $catalog): array
{
    $range = normalize_stats_range($range);
    $summary = empty_stats_summary($range);
    $productNames = product_names_by_slug($catalog);
    $today = stats_today();

    for ($offset = 0; $offset < $summary['days']; $offset++) {
        $date = $today->modify('-' . $offset . ' days')->format('Y-m-d');
        $file = STATS_DIR . '/' . $date . '.json';
        if (!is_file($file)) {
            $summary['missingDays']++;
            continue;
        }

        $raw = file_get_contents($file);
        $day = json_decode((string)$raw, true);
        if (!is_array($day)) {
            $summary['invalidFiles']++;
            continue;
        }

        $summary['daysRead']++;

        foreach ($summary['totals'] as $event => $current) {
            $value = safe_stat_int($day['totals'][$event] ?? 0);
            $summary['totals'][$event] += $value;
            if ($value > 0) {
                $summary['hasData'] = true;
            }
        }

        foreach ($summary['buttons'] as $event => $current) {
            $value = safe_stat_int($day['buttons'][$event] ?? 0);
            $summary['buttons'][$event] += $value;
            if ($value > 0) {
                $summary['hasData'] = true;
            }
        }

        if (isset($day['pages']) && is_array($day['pages'])) {
            foreach ($day['pages'] as $path => $count) {
                $path = (string)$path;
                if ($path === '' || strlen($path) > 180) {
                    continue;
                }
                $summary['pages'][$path] = safe_stat_int($summary['pages'][$path] ?? 0) + safe_stat_int($count);
                if (safe_stat_int($count) > 0) {
                    $summary['hasData'] = true;
                }
            }
        }

        if (isset($day['products']) && is_array($day['products'])) {
            foreach ($day['products'] as $slug => $metrics) {
                $slug = clean_filename((string)$slug);
                if ($slug === '' || !is_array($metrics)) {
                    continue;
                }
                if (!isset($summary['products'][$slug])) {
                    $summary['products'][$slug] = [
                        'slug' => $slug,
                        'name' => $productNames[$slug] ?? $slug,
                        'views' => 0,
                        'call_click' => 0,
                        'sms_click' => 0,
                        'product_question_click' => 0,
                    ];
                }
                foreach (['views', 'call_click', 'sms_click', 'product_question_click'] as $metric) {
                    $value = safe_stat_int($metrics[$metric] ?? 0);
                    $summary['products'][$slug][$metric] += $value;
                    if ($value > 0) {
                        $summary['hasData'] = true;
                    }
                }
            }
        }
    }

    arsort($summary['pages']);
    $summary['topPages'] = array_slice($summary['pages'], 0, 10, true);

    $summary['topProducts'] = array_values($summary['products']);
    usort($summary['topProducts'], static function (array $a, array $b): int {
        return ($b['views'] <=> $a['views']) ?: strcmp($a['name'], $b['name']);
    });

    foreach (stats_event_labels() as $event => $label) {
        $summary['buttonRows'][] = [
            'event' => $event,
            'label' => $label,
            'count' => safe_stat_int($summary['buttons'][$event] ?? 0),
        ];
    }

    return $summary;
}

function shop_order_statuses(): array
{
    return [
        'new',
        'awaiting_payment',
        'awaiting_shipping_quote',
        'paid',
        'processing',
        'shipped',
        'completed',
        'cancelled',
        'payment_failed',
        'Testowe',
        'Nowe',
        'Oczekuje na płatność',
        'Opłacone',
        'W przygotowaniu',
        'Wysłane',
        'Odebrane osobiście',
        'Anulowane',
        'Zwrócone',
    ];
}

function shop_payment_statuses(): array
{
    return [
        'not_started',
        'awaiting',
        'awaiting_payment',
        'confirmed',
        'paid',
        'failed',
        'cancelled',
        'Testowe bez płatności',
        'Oczekuje na płatność',
        'Opłacone',
        'Anulowane',
        'Zwrot',
    ];
}

function shop_payment_methods(): array
{
    // Paynow is only exposed when explicitly enabled from private server
    // configuration. There is deliberately no separate card option.
    return ['bank_transfer' => true, 'paynow' => defined('PAYNOW_ENABLED') && PAYNOW_ENABLED];
}

function shop_bank_transfer_details(string $orderId = ''): array
{
    $title = $orderId !== '' ? 'Zamówienie ' . shop_safe_order_id($orderId) : 'Zamówienie';
    return [
        'recipient' => SHOP_BANK_TRANSFER_RECIPIENT,
        'accountNumber' => SHOP_BANK_TRANSFER_ACCOUNT,
        'bankName' => SHOP_BANK_TRANSFER_BANK,
        'bic' => SHOP_BANK_TRANSFER_BIC,
        'currency' => 'PLN',
        'transferTitle' => $title,
    ];
}

function shop_order_email_lines(array $order, bool $includeTransfer): array
{
    $lines = [
        'Numer zamówienia: ' . ($order['orderId'] ?? ''),
        'Data: ' . ($order['createdAt'] ?? ''),
        'Zamówienie zostało przyjęte.',
    ];
    foreach ((array)($order['items'] ?? []) as $item) {
        $lines[] = sprintf('%s — %d szt. × %s PLN', (string)($item['name'] ?? ''), (int)($item['quantity'] ?? 0), number_format(((int)($item['unitPriceCents'] ?? 0)) / 100, 2, ',', ' '));
    }
    foreach ((array)($order['items'] ?? []) as $item) {
        if (!empty($item['shippingRequiresConfirmation'])) {
            $lines[] = 'Dostawa ' . (string)($item['name'] ?? '') . ': ' . (string)($item['shippingName'] ?? 'Dostawa') . ' — koszt do potwierdzenia';
        } else {
            $lines[] = 'Dostawa ' . (string)($item['name'] ?? '') . ': ' . (string)($item['shippingName'] ?? 'Dostawa') . ' — ' . number_format(((int)($item['shippingLineCents'] ?? 0)) / 100, 2, ',', ' ') . ' PLN';
        }
    }
    if (($order['shippingTotalCents'] ?? $order['deliveryCostCents'] ?? null) === null) {
        $lines[] = 'Dostawa razem: koszt do potwierdzenia.';
    } else {
        $lines[] = 'Dostawa razem: ' . number_format(((int)($order['shippingTotalCents'] ?? $order['deliveryCostCents'] ?? 0)) / 100, 2, ',', ' ') . ' PLN';
    }
    if (!$includeTransfer) {
        $lines[] = 'Koszt dostawy wymaga indywidualnego potwierdzenia. Skontaktujemy się z Tobą przed płatnością.';
        return $lines;
    }
    $lines[] = 'Koszt dostawy: ' . number_format(((int)($order['deliveryCostCents'] ?? 0)) / 100, 2, ',', ' ') . ' PLN';
    $lines[] = 'Razem: ' . number_format(((int)($order['totalCents'] ?? 0)) / 100, 2, ',', ' ') . ' PLN';
    if (($order['paymentMethod'] ?? '') === 'paynow') {
        $lines[] = 'Płatność: Paynow (BLIK lub przelew online).';
        $lines[] = 'Zamówienie oczekuje na opłacenie.';
        $lines[] = 'Przycisk do płatności znajduje się na stronie potwierdzenia zamówienia.';
        $lines[] = 'Realizacja zamówienia rozpocznie się po potwierdzeniu płatności.';
    } else {
        $transfer = shop_bank_transfer_details((string)($order['orderId'] ?? ''));
        $lines[] = 'Płatność: Przelew tradycyjny';
        $lines[] = 'Odbiorca: ' . $transfer['recipient'];
        $lines[] = 'Rachunek: ' . $transfer['accountNumber'];
        $lines[] = 'Tytuł: ' . $transfer['transferTitle'];
        $lines[] = 'Realizację zamówienia rozpoczniemy po zaksięgowaniu płatności.';
    }
    return $lines;
}

function shop_send_order_emails(array $order, ?callable $mailer = null): array
{
    $mailer ??= static fn(string $to, string $subject, string $body, string $headers): bool => @mail($to, $subject, $body, $headers);
    $quote = ($order['orderStatus'] ?? '') === 'awaiting_shipping_quote';
    $headers = "From: Home & Garden Outlet <biuro@mgoutlet.pl>\r\nReply-To: biuro@mgoutlet.pl\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
    $lines = shop_order_email_lines($order, !$quote);
    $body = implode("\n", $lines);
    $result = ['customer' => false, 'admin' => false];
    if (empty($order['emailNotifications']['customerCreatedAt'])) {
        $result['customer'] = $mailer((string)($order['customer']['email'] ?? ''), 'Otrzymaliśmy zamówienie ' . ($order['orderId'] ?? '') . ' | Home & Garden Outlet', $body, $headers);
    }
    if (empty($order['emailNotifications']['adminCreatedAt'])) {
        $adminSubject = 'Nowe zamówienie ' . ($order['orderId'] ?? '');
        if (!$quote && ($order['paymentMethod'] ?? '') === 'paynow') {
            $adminSubject .= ' – oczekuje na płatność';
        }
        $result['admin'] = $mailer('biuro@mgoutlet.pl', $adminSubject, $body, $headers);
    }
    return $result;
}

function shop_payment_confirmed_email_lines(array $order, bool $forAdmin = false): array
{
    $orderId = (string)($order['orderId'] ?? '');
    $lines = [
        'Numer zamówienia: ' . $orderId,
        'Paynow potwierdził prawidłowe opłacenie zamówienia.',
        'Kwota: ' . number_format(((int)($order['totalCents'] ?? 0)) / 100, 2, ',', ' ') . ' PLN',
    ];
    if ($forAdmin) {
        $lines[] = 'Status: paid / confirmed';
    } else {
        $lines[] = 'Zamówienie zostało przekazane do dalszej realizacji.';
        $lines[] = '';
        $lines[] = 'Home & Garden Outlet';
    }
    return $lines;
}

function shop_send_payment_confirmed_emails(array $order, ?callable $mailer = null): array
{
    if (($order['paymentMethod'] ?? $order['paymentProvider'] ?? '') !== 'paynow'
        || ($order['orderStatus'] ?? $order['status'] ?? '') !== 'paid'
        || ($order['paymentStatus'] ?? '') !== 'confirmed') {
        return $order;
    }

    $mailer ??= static fn(string $to, string $subject, string $body, string $headers): bool => @mail($to, $subject, $body, $headers);
    $headers = "From: Home & Garden Outlet <biuro@mgoutlet.pl>\r\nReply-To: biuro@mgoutlet.pl\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
    $orderId = (string)($order['orderId'] ?? '');
    $now = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
    $notifications = [
        'customer' => [
            'attemptedAt' => 'customerPaymentConfirmedEmailAttemptedAt',
            'sentAt' => 'customerPaymentConfirmedEmailSentAt',
            'failed' => 'customerPaymentConfirmedEmailFailed',
            'to' => (string)($order['customer']['email'] ?? ''),
            'subject' => 'Płatność za zamówienie ' . $orderId . ' została potwierdzona | Home & Garden Outlet',
            'body' => implode("\n", shop_payment_confirmed_email_lines($order)),
        ],
        'admin' => [
            'attemptedAt' => 'adminPaymentConfirmedEmailAttemptedAt',
            'sentAt' => 'adminPaymentConfirmedEmailSentAt',
            'failed' => 'adminPaymentConfirmedEmailFailed',
            'to' => 'biuro@mgoutlet.pl',
            'subject' => 'Zamówienie ' . $orderId . ' opłacone',
            'body' => implode("\n", shop_payment_confirmed_email_lines($order, true)),
        ],
    ];

    foreach ($notifications as $notification) {
        if (!empty($order['emailNotifications'][$notification['attemptedAt']])) {
            continue;
        }

        // Persist the attempt before mail() so a PHP restart or a repeated webhook
        // cannot send the same notification twice.
        $order['emailNotifications'][$notification['attemptedAt']] = $now;
        shop_save_order($order);
        try {
            $sent = $mailer($notification['to'], $notification['subject'], $notification['body'], $headers);
        } catch (Throwable $ignored) {
            $sent = false;
        }
        $order['emailNotifications'][$notification['sentAt']] = $sent ? $now : null;
        $order['emailNotifications'][$notification['failed']] = !$sent;
        shop_save_order($order);
    }

    return $order;
}

function shop_producer_whatsapp_recipients(): array
{
    return [
        '1' => ['label' => 'Numer 1', 'number' => shop_producer_whatsapp_number(HGO_WHATSAPP_PRODUCER_1)],
        '2' => ['label' => 'Numer 2', 'number' => shop_producer_whatsapp_number(HGO_WHATSAPP_PRODUCER_2)],
    ];
}

function shop_order_is_paid(array $order): bool
{
    return in_array((string)($order['paymentStatus'] ?? ''), ['confirmed', 'paid'], true)
        || in_array((string)($order['orderStatus'] ?? $order['status'] ?? ''), ['paid', 'Opłacone'], true);
}

function shop_producer_whatsapp_number(string $number): string
{
    $number = preg_replace('/[^0-9]/', '', $number) ?: '';
    return preg_match('/^[1-9][0-9]{6,14}$/', $number) === 1 ? $number : '';
}

function shop_figure_product_url(string $slug): string
{
    return '/sklep/figury-ogrodowe/produkt/' . rawurlencode(clean_filename($slug));
}

function shop_producer_product_url(array $item, ?array $catalog = null): ?string
{
    $productId = clean_filename((string)($item['productId'] ?? $item['slug'] ?? ''));
    if ($productId === '' || $productId === 'produkt') {
        return null;
    }

    $catalog ??= load_catalog();
    foreach (($catalog['products'] ?? []) as $product) {
        if (!is_array($product)) {
            continue;
        }
        $product = array_merge(product_defaults(), $product);
        $storedSlug = (string)($product['slug'] ?? '');
        $productSlug = clean_filename($storedSlug !== '' ? $storedSlug : (string)$product['name']);
        if ($productSlug !== $productId || !catalog_is_figure_shop_product($product)) {
            continue;
        }
        if (catalog_normalize((string)($product['productStatus'] ?? '')) === 'ukryty') {
            return null;
        }
        return 'https://mgoutlet.pl' . shop_figure_product_url($productSlug);
    }
    return null;
}

function shop_producer_whatsapp_message(array $order, ?array $catalog = null): array
{
    $orderId = shop_safe_order_id((string)($order['orderId'] ?? ''));
    if ($orderId === '') {
        throw new RuntimeException('Zamówienie nie ma prawidłowego numeru.');
    }

    $lines = ['ZAMÓWIENIE ' . $orderId];
    $warnings = [];
    foreach ((array)($order['items'] ?? []) as $item) {
        if (!is_array($item)) {
            $warnings[] = 'Nie można odczytać jednej z pozycji zamówienia.';
            continue;
        }
        $name = trim((string)($item['name'] ?? 'Produkt'));
        $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $url = shop_producer_product_url($item, $catalog);
        if ($quantity === false || $url === null) {
            $warnings[] = 'Nie można ustalić publicznego linku dla produktu: ' . ($name !== '' ? $name : 'bez nazwy') . '.';
            continue;
        }
        $lines[] = $quantity . ' × ' . ($name !== '' ? $name : 'Produkt') . "\n" . $url;
    }

    if (count($lines) === 1) {
        throw new RuntimeException('Zamówienie nie zawiera pozycji z prawidłowym publicznym linkiem do produktu.');
    }
    return ['message' => implode("\n\n", $lines), 'warnings' => $warnings];
}

function shop_producer_whatsapp_message_url(string $number, string $message): ?string
{
    $number = shop_producer_whatsapp_number($number);
    if ($number === '') {
        return null;
    }
    return 'https://wa.me/' . $number . '?text=' . rawurlencode($message);
}

function shop_producer_whatsapp_url(array $order, string $recipientKey): ?string
{
    $recipient = shop_producer_whatsapp_recipients()[$recipientKey] ?? null;
    if (!is_array($recipient) || $recipient['number'] === '') {
        return null;
    }
    $payload = shop_producer_whatsapp_message($order);
    return shop_producer_whatsapp_message_url($recipient['number'], $payload['message']);
}

function shop_mark_producer_handed_off(string $orderId, string $recipientKey, string $administrator): void
{
    $order = shop_load_order($orderId);
    if (!$order) {
        throw new RuntimeException('Nie znaleziono zamówienia.');
    }
    if (!shop_order_is_paid($order)) {
        throw new RuntimeException('Do producenta można przekazać wyłącznie opłacone zamówienie.');
    }
    $recipient = shop_producer_whatsapp_recipients()[$recipientKey] ?? null;
    if (!is_array($recipient) || $recipient['number'] === '') {
        throw new RuntimeException('Nieprawidłowy odbiorca producenta.');
    }
    shop_producer_whatsapp_message($order);
    $now = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
    $order['producerHandoff'] ??= [];
    $order['producerHandoff'][$recipientKey] = [
        'handedOffAt' => $now,
        'handedOffBy' => $administrator !== '' ? $administrator : 'administrator',
    ];
    $order['updatedAt'] = $now;
    shop_save_order($order);
}

function shop_delivery_labels(): array
{
    $labels = [];
    foreach (load_shipping_profiles() as $profile) {
        if (!empty($profile['active'])) {
            $labels[(string)$profile['id']] = (string)$profile['name'];
        }
    }
    return $labels;
}

function shop_safe_order_id(string $value): string
{
    $value = preg_replace('/[^A-Z0-9-]/i', '', $value) ?: '';
    return trim($value);
}

function shop_order_file(string $orderId): string
{
    $orderId = shop_safe_order_id($orderId);
    if ($orderId === '') {
        throw new RuntimeException('Nieprawidłowy numer zamówienia.');
    }
    return ORDERS_DIR . '/' . $orderId . '.json';
}

function shop_next_order_id(): string
{
    $date = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format('Ymd');
    for ($attempt = 1; $attempt <= 9999; $attempt++) {
        $id = 'HGO-' . $date . '-' . str_pad((string)$attempt, 4, '0', STR_PAD_LEFT);
        if (!is_file(ORDERS_DIR . '/' . $id . '.json')) {
            return $id;
        }
    }
    do {
        $id = 'HGO-' . $date . '-' . bin2hex(random_bytes(6));
    } while (is_file(ORDERS_DIR . '/' . $id . '.json'));
    return $id;
}

function shop_load_order(string $orderId): ?array
{
    $file = shop_order_file($orderId);
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function shop_load_orders(): array
{
    $orders = [];
    foreach (glob(ORDERS_DIR . '/{TEST,HGO}-*.json', GLOB_BRACE) ?: [] as $file) {
        $data = json_decode((string)file_get_contents($file), true);
        if (is_array($data) && isset($data['orderId'])) {
            $orders[] = $data;
        }
    }

    usort($orders, static function (array $a, array $b): int {
        return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
    });

    return $orders;
}

function shop_save_order(array $order): void
{
    if (!is_dir(ORDERS_DIR)) {
        @mkdir(ORDERS_DIR, 0750, true);
    }
    $orderId = shop_safe_order_id((string)($order['orderId'] ?? ''));
    if ($orderId === '') {
        throw new RuntimeException('Brakuje numeru zamówienia.');
    }

    $json = json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Nie udało się przygotować danych zamówienia.');
    }

    $target = shop_order_file($orderId);
    $temp = $target . '.tmp';
    if (file_put_contents($temp, $json . PHP_EOL, LOCK_EX) === false || !@rename($temp, $target)) {
        @unlink($temp);
        throw new RuntimeException('Nie udało się zapisać zamówienia.');
    }
    @chmod($target, 0640);
}

function shop_create_order(array $order): array
{
    if (!is_dir(ORDERS_DIR) && !@mkdir(ORDERS_DIR, 0750, true) && !is_dir(ORDERS_DIR)) {
        throw new RuntimeException('Nie udało się przygotować katalogu zamówień.');
    }

    $lock = @fopen(ORDERS_DIR . '/.order-create.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Nie udało się zablokować tworzenia zamówienia.');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Nie udało się bezpiecznie utworzyć zamówienia.');
        }
        $order['orderId'] = shop_next_order_id();
        $order['order_id'] = $order['orderId'];
        shop_save_order($order);
        return $order;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function shop_update_order(string $orderId, string $orderStatus, string $paymentStatus, string $internalNote): void
{
    $order = shop_load_order($orderId);
    if (!$order) {
        throw new RuntimeException('Nie znaleziono zamówienia.');
    }
    if (!in_array($orderStatus, shop_order_statuses(), true)) {
        throw new RuntimeException('Nieprawidłowy status zamówienia.');
    }
    $isPaynow = ($order['paymentProvider'] ?? '') === 'paynow';
    if (!$isPaynow && !in_array($paymentStatus, shop_payment_statuses(), true)) {
        throw new RuntimeException('Nieprawidłowy status płatności.');
    }
    $order['orderStatus'] = $orderStatus;
    $order['status'] = $orderStatus;
    if (!$isPaynow) {
        $order['paymentStatus'] = $paymentStatus;
    }
    $order['internalNote'] = trim($internalNote);
    $order['updatedAt'] = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
    shop_save_order($order);
}

function shop_set_order_archived(string $orderId, bool $archived, string $administrator): void
{
    $order = shop_load_order($orderId);
    if (!$order) {
        throw new RuntimeException('Nie znaleziono zamówienia.');
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
    $order['archived'] = $archived;
    if ($archived) {
        $order['archivedAt'] = $now;
        $order['archivedBy'] = $administrator !== '' ? $administrator : 'administrator';
    } else {
        unset($order['archivedAt'], $order['archivedBy']);
    }
    $order['updatedAt'] = $now;
    shop_save_order($order);
}

function shop_mark_order_as_test(string $orderId, string $administrator): void
{
    $order = shop_load_order($orderId);
    if (!$order) {
        throw new RuntimeException('Nie znaleziono zamówienia.');
    }

    $order['isTestOrder'] = true;
    $order['testOrderMarkedAt'] = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
    $order['testOrderMarkedBy'] = $administrator !== '' ? $administrator : 'administrator';
    $order['updatedAt'] = $order['testOrderMarkedAt'];
    shop_save_order($order);
}

function shop_delete_test_order(string $orderId, string $confirmation): void
{
    $order = shop_load_order($orderId);
    if (!$order) {
        throw new RuntimeException('Nie znaleziono zamówienia.');
    }
    if (empty($order['isTestOrder'])) {
        throw new RuntimeException('Trwale można usunąć wyłącznie jednoznacznie oznaczone zamówienie testowe.');
    }
    if (!hash_equals((string)($order['orderId'] ?? ''), trim($confirmation))) {
        throw new RuntimeException('Wpisz dokładny numer zamówienia, aby potwierdzić trwałe usunięcie.');
    }

    $file = shop_order_file($orderId);
    if (!is_file($file) || !@unlink($file)) {
        throw new RuntimeException('Nie udało się trwale usunąć zamówienia testowego.');
    }
}

function shop_mark_bank_transfer_paid(string $orderId, string $administrator = ''): bool
{
    $order = shop_load_order($orderId);
    if (!$order) {
        throw new RuntimeException('Nie znaleziono zamówienia.');
    }
    if (($order['paymentProvider'] ?? '') !== 'bank_transfer') {
        throw new RuntimeException('To zamówienie nie oczekuje na przelew tradycyjny.');
    }
    if (($order['paymentStatus'] ?? '') === 'paid' && ($order['orderStatus'] ?? '') === 'paid') {
        return false;
    }
    if (($order['orderStatus'] ?? '') !== 'awaiting_payment' || ($order['paymentStatus'] ?? '') !== 'awaiting') {
        throw new RuntimeException('Płatności nie można potwierdzić w obecnym statusie zamówienia.');
    }
    $now = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
    $order['paymentStatus'] = 'paid';
    $order['status'] = $order['orderStatus'] = 'paid';
    $order['paymentConfirmedAt'] = $now;
    if ($administrator !== '') {
        $order['paymentConfirmedBy'] = $administrator;
    }
    $order['updatedAt'] = $now;
    shop_save_order($order);
    return true;
}

function shop_recalculate_item_shipping(array &$order): bool
{
    $shippingTotal = 0;
    $allKnown = true;
    foreach ((array)($order['items'] ?? []) as $item) {
        if (!empty($item['shippingRequiresConfirmation']) || !isset($item['shippingLineCents']) || $item['shippingLineCents'] === null) {
            $allKnown = false;
            continue;
        }
        $shippingTotal += (int)$item['shippingLineCents'];
    }
    $order['shippingTotalCents'] = $shippingTotal;
    $order['shippingTotal'] = $shippingTotal / 100;
    $order['deliveryCostCents'] = $allKnown ? $shippingTotal : null;
    $order['deliveryCost'] = $allKnown ? $shippingTotal / 100 : null;
    $order['totalCents'] = (int)($order['productsTotalCents'] ?? 0) + $shippingTotal;
    $order['total'] = $order['totalCents'] / 100;
    return $allKnown;
}

function shop_set_item_shipping_quote(string $orderId, int $itemIndex, string $unitCost, string $administrator = '', ?callable $mailer = null): bool
{
    $order = shop_load_order($orderId);
    if (!$order) throw new RuntimeException('Nie znaleziono zamówienia.');
    if (($order['orderStatus'] ?? '') !== 'awaiting_shipping_quote') return false;
    if (!preg_match('/^\d+(?:[,.]\d{1,2})?$/', trim($unitCost))) throw new RuntimeException('Podaj prawidłowy koszt dostawy jednej sztuki w PLN.');
    if (!isset($order['items'][$itemIndex]) || !is_array($order['items'][$itemIndex])) throw new RuntimeException('Nie znaleziono pozycji zamówienia.');
    if (empty($order['items'][$itemIndex]['shippingRequiresConfirmation'])) return false;
    $unitCents = (int)round((float)str_replace(',', '.', $unitCost) * 100);
    $quantity = (int)($order['items'][$itemIndex]['quantity'] ?? 0);
    if ($unitCents < 0 || $quantity < 1) throw new RuntimeException('Nieprawidłowy koszt lub ilość pozycji.');
    $now = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
    $order['items'][$itemIndex]['shippingUnitCents'] = $unitCents;
    $order['items'][$itemIndex]['shippingLineCents'] = $unitCents * $quantity;
    $order['items'][$itemIndex]['shippingRequiresConfirmation'] = false;
    $order['items'][$itemIndex]['shippingQuotedAt'] = $now;
    $order['items'][$itemIndex]['shippingQuotedBy'] = $administrator;
    $allKnown = shop_recalculate_item_shipping($order);
    if ($allKnown) {
        $order['status'] = $order['orderStatus'] = 'awaiting_payment';
        if (($order['paymentMethod'] ?? '') === 'paynow') {
            $order['paymentProvider'] = 'paynow';
            $order['paymentStatus'] = 'not_started';
        } else {
            $order['paymentProvider'] = $order['paymentMethod'] = 'bank_transfer';
            $order['paymentStatus'] = 'awaiting';
        }
        $order['shippingQuoteConfirmedAt'] = $now;
        $order['shippingQuoteConfirmedBy'] = $administrator;
    }
    shop_save_order($order);
    if (!$allKnown || !empty($order['emailNotifications']['paymentDetailsSentAt'])) return true;
    $mailer ??= static fn(string $to, string $subject, string $body, string $headers): bool => @mail($to, $subject, $body, $headers);
    $headers = "From: Home & Garden Outlet <biuro@mgoutlet.pl>\r\nReply-To: biuro@mgoutlet.pl\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
    $sent = $mailer((string)($order['customer']['email'] ?? ''), 'Dane do płatności – zamówienie ' . $order['orderId'], implode("\n", shop_order_email_lines($order, true)), $headers);
    $order['emailNotifications']['paymentDetailsSentAt'] = $sent ? $now : null;
    $order['emailNotifications']['paymentDetailsFailed'] = !$sent;
    shop_save_order($order);
    return true;
}

function shop_set_shipping_quote(string $orderId, string $cost, string $administrator = '', ?callable $mailer = null): bool
{
    $order = shop_load_order($orderId);
    if (!$order) throw new RuntimeException('Nie znaleziono zamówienia.');
    if (($order['orderStatus'] ?? '') !== 'awaiting_shipping_quote') return false;
    if (!preg_match('/^\d+(?:[,.]\d{1,2})?$/', trim($cost))) throw new RuntimeException('Podaj prawidłowy koszt dostawy w PLN.');
    $cents = (int)round((float)str_replace(',', '.', $cost) * 100);
    if ($cents < 0) throw new RuntimeException('Koszt dostawy nie może być ujemny.');
    $now = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
    $knownShippingCents = 0;
    foreach ((array)($order['items'] ?? []) as $item) {
        if (empty($item['shippingRequiresConfirmation'])) {
            $knownShippingCents += (int)($item['shippingLineCents'] ?? 0);
        }
    }
    $order['shippingTotalCents'] = $knownShippingCents + $cents;
    $order['shippingTotal'] = $order['shippingTotalCents'] / 100;
    $order['deliveryCostCents'] = $order['shippingTotalCents'];
    $order['deliveryCost'] = $cents / 100;
    $order['delivery']['costCents'] = $cents;
    $order['delivery']['cost'] = $cents / 100;
    $order['delivery']['costLabel'] = number_format($cents / 100, 2, ',', ' ') . ' zł';
    $order['delivery']['requiresConfirmation'] = false;
    $order['delivery']['pricingType'] = 'fixed_price';
    $order['totalCents'] = (int)($order['productsTotalCents'] ?? 0) + $order['shippingTotalCents'];
    $order['total'] = $order['totalCents'] / 100;
    $order['status'] = $order['orderStatus'] = 'awaiting_payment';
    if (($order['paymentMethod'] ?? '') === 'paynow') {
        $order['paymentProvider'] = 'paynow';
        $order['paymentStatus'] = 'not_started';
    } else {
        $order['paymentProvider'] = $order['paymentMethod'] = 'bank_transfer';
        $order['paymentStatus'] = 'awaiting';
    }
    $order['shippingQuoteConfirmedAt'] = $now;
    $order['shippingQuoteConfirmedBy'] = $administrator;
    shop_save_order($order);
    $mailer ??= static fn(string $to, string $subject, string $body, string $headers): bool => @mail($to, $subject, $body, $headers);
    $headers = "From: Home & Garden Outlet <biuro@mgoutlet.pl>\r\nReply-To: biuro@mgoutlet.pl\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
    $sent = $mailer((string)($order['customer']['email'] ?? ''), 'Dane do płatności – zamówienie ' . $order['orderId'], implode("\n", shop_order_email_lines($order, true)), $headers);
    $order['emailNotifications']['paymentDetailsSentAt'] = $sent ? $now : null;
    $order['emailNotifications']['paymentDetailsFailed'] = !$sent;
    shop_save_order($order);
    return true;
}

function save_catalog(array $catalog): void
{
    $directory = dirname(PRODUCTS_FILE);
    if (!is_dir($directory)) {
        @mkdir($directory, 0755, true);
    }

    if (is_file(PRODUCTS_FILE)) {
        $backup = BACKUP_DIR . '/products-' . date('Ymd-His') . '.json';
        @copy(PRODUCTS_FILE, $backup);
        cleanup_backups();
    }

    $json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Nie udało się przygotować danych produktu.');
    }

    $temp = PRODUCTS_FILE . '.tmp';
    if (file_put_contents($temp, $json . PHP_EOL, LOCK_EX) === false || !@rename($temp, PRODUCTS_FILE)) {
        @unlink($temp);
        throw new RuntimeException('Nie udało się zapisać produktów. Sprawdź uprawnienia katalogu data.');
    }
    @chmod(PRODUCTS_FILE, 0644);
}

function cleanup_backups(): void
{
    $files = glob(BACKUP_DIR . '/products-*.json') ?: [];
    rsort($files);
    foreach (array_slice($files, 40) as $file) {
        @unlink($file);
    }
}

function product_defaults(): array
{
    return [
        'name' => '',
        'saleType' => 'showroom',
        'category' => 'Wyposażenie domu',
        'productType' => '',
        'featured' => true,
        'visible' => true,
        'shopVisible' => false,
        'shopStatus' => 'Ukryty',
        'sku' => '',
        'grossPrice' => '',
        'catalogPrice' => '',
        'outletPrice' => '',
        'currency' => 'PLN',
        'image' => '',
        'gallery' => [],
        'imageAlt' => '',
        'description' => '',
        'longDescription' => '',
        'dimensions' => '',
        'height' => '',
        'width' => '',
        'depth' => '',
        'weight' => '',
        'packageDimensions' => '',
        'packageWeight' => '',
        'packageLengthCm' => '',
        'packageWidthCm' => '',
        'packageHeightCm' => '',
        'material' => '',
        'color' => '',
        'outdoorUse' => false,
        'fragileTransport' => false,
        'delicateProduct' => false,
        'handPainted' => false,
        'heavyProduct' => false,
        'oversizedProduct' => false,
        'producerAvailability' => 'Dostępny u producenta',
        'leadTime' => '2-5 dni roboczych',
        'deliveryMethods' => [],
        'shippingProfileIds' => [],
        'condition' => 'Outletowy',
        'status' => 'Dostępne',
        'productStatus' => 'Aktywny',
        'seoTitle' => '',
        'seoDescription' => '',
        'slug' => '',
        'order' => 0,
        'googleManualProduct' => false,
        'googleStatus' => 'Nie wysłano',
        'googleSentAt' => '',
        'googleMediaId' => '',
        'googlePostId' => '',
        'googleText' => '',
        'googleError' => '',
    ];
}

function product_category_options(): array
{
    return [
        'Wyposażenie domu',
        'Wyposażenie ogrodu',
        'Figury i dekoracje ogrodowe',
        'Dekoracje',
        'Oświetlenie',
        'Inne',
    ];
}

function google_business_description(array $product): string
{
    $name = trim((string)($product['name'] ?? 'Produkt z oferty'));
    if ($name === '') {
        $name = 'Produkt z oferty';
    }

    $outletPrice = trim((string)($product['outletPrice'] ?? ''));
    $statusRaw = trim((string)($product['status'] ?? ''));
    $status = function_exists('mb_strtolower') ? mb_strtolower($statusRaw, 'UTF-8') : strtolower($statusRaw);
    $isSold = str_contains($status, 'sprzedane') || str_contains($status, 'sprzedany');

    $parts = [
        $name . ' dostępny w Home & Garden Outlet w Kębłowicach pod Wrocławiem.',
    ];

    if ($outletPrice !== '') {
        $parts[] = 'Cena outletowa: ' . $outletPrice . '.';
    }

    if ($isSold) {
        $parts[] = 'Produkt może być już niedostępny, ale możesz zadzwonić i zapytać o podobne meble z aktualnej oferty.';
    } else {
        $parts[] = 'Produkt można obejrzeć na żywo w naszym showroomie.';
        $parts[] = 'Oferta outletowa - często pojedyncza sztuka lub końcówka kolekcji.';
        $parts[] = 'Przed przyjazdem warto zadzwonić pod numer 577 210 777 i potwierdzić dostępność.';
    }

    return implode(' ', $parts);
}

function post_text(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function clean_filename(string $value): string
{
    $value = trim($value);
    $value = strtr($value, [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
    ]);
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'produkt';
    return trim($value, '-') ?: 'produkt';
}

function unique_product_slug(string $requested, array $products, ?int $currentIndex = null): string
{
    $base = clean_filename($requested);
    $candidate = $base;
    $suffix = 2;

    while (true) {
        $taken = false;
        foreach ($products as $index => $product) {
            if ($currentIndex !== null && $index === $currentIndex) {
                continue;
            }
            $existingSource = trim((string)($product['slug'] ?? '')) !== ''
                ? (string)$product['slug']
                : (string)($product['name'] ?? 'produkt');
            if (clean_filename($existingSource) === $candidate) {
                $taken = true;
                break;
            }
        }
        if (!$taken) {
            return $candidate;
        }
        $candidate = $base . '-' . $suffix;
        $suffix++;
    }
}

function uploaded_file(array $file, string $productName): string
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Nie udało się przesłać zdjęcia. Kod błędu: ' . $error);
    }
    if ((int)($file['size'] ?? 0) > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Zdjęcie jest za duże. Maksymalny rozmiar to 12 MB.');
    }

    [$tmp, $mime, $extension] = validated_uploaded_image($file);

    $base = clean_filename($productName) . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $optimized = optimize_image($tmp, $mime, UPLOAD_DIR . '/' . $base . '.webp');
    if ($optimized) {
        return '/uploads/' . $base . '.webp';
    }

    $target = UPLOAD_DIR . '/' . $base . '.' . $extension;
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Serwer nie mógł zapisać zdjęcia.');
    }
    @chmod($target, 0644);
    return '/uploads/' . basename($target);
}

/** @return array{string,string,string} temporary path, verified MIME type, extension */
function validated_uploaded_image(array $file): array
{
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Nieprawidłowy plik przesłanego zdjęcia.');
    }
    $info = @getimagesize($tmp);
    $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Dozwolone zdjęcia: JPG, PNG lub WebP. Zdjęcia HEIC zmień w telefonie na JPG.');
    }
    return [$tmp, $mime, $extensions[$mime]];
}

function product_image_draft_id(string $id): string
{
    return preg_match('/^[a-f0-9]{32}$/', $id) ? $id : '';
}

function product_image_draft_path(string $id): string
{
    $id = product_image_draft_id($id);
    return $id === '' ? '' : PRODUCT_IMAGE_DRAFT_DIR . '/' . $id;
}

function load_product_image_draft(string $id): ?array
{
    $path = product_image_draft_path($id);
    $metadata = $path === '' ? false : @file_get_contents($path . '/draft.json');
    $draft = is_string($metadata) ? json_decode($metadata, true) : null;
    return is_array($draft) && ($draft['id'] ?? '') === $id ? $draft : null;
}

function save_product_image_draft(array $draft): void
{
    $path = product_image_draft_path((string)($draft['id'] ?? ''));
    if ($path === '') {
        throw new RuntimeException('Nieprawidłowy identyfikator przygotowania zdjęć.');
    }
    $json = json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($path . '/draft.json', $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Nie udało się zapisać wersji roboczej zdjęć.');
    }
    @chmod($path . '/draft.json', 0640);
}

function codex_product_draft_field_limits(): array
{
    return [
        'name' => 180, 'category' => 60, 'productType' => 120, 'imageAlt' => 240,
        'description' => 1500, 'longDescription' => 6000, 'color' => 100,
        'seoTitle' => 180, 'seoDescription' => 350, 'slug' => 180,
    ];
}

function valid_codex_final_filename(string $value): bool
{
    return strlen($value) <= 200 && (bool)preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*\.webp$/', $value);
}

/** @return array{product:array,images:array,manualFieldsRequired:array} */
function validate_codex_product_draft(string $json, array $draft): array
{
    if ($json === '' || strlen($json) > MAX_PRODUCT_DRAFT_JSON_BYTES) {
        throw new RuntimeException('Plik product-draft.json jest pusty albo przekracza limit 256 KB.');
    }
    $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data) || array_is_list($data)) throw new RuntimeException('product-draft.json musi zawierać obiekt JSON.');
    $draftId = (string)($data['draftId'] ?? '');
    if (!hash_equals((string)($draft['id'] ?? ''), $draftId)) {
        throw new RuntimeException('Wynik Codexa należy do innego draftu. Identyfikator draftId musi być identyczny.');
    }
    if (($data['status'] ?? '') !== 'codex_prepared') throw new RuntimeException('Nieprawidłowy status wyniku Codexa.');
    if (!is_array($data['product'] ?? null) || !is_array($data['images'] ?? null)) {
        throw new RuntimeException('product-draft.json musi zawierać obiekty product oraz images.');
    }

    $product = [];
    foreach (codex_product_draft_field_limits() as $field => $limit) {
        if (!array_key_exists($field, $data['product'])) continue;
        if (!is_string($data['product'][$field])) throw new RuntimeException('Pole product.' . $field . ' musi być tekstem.');
        $value = trim($data['product'][$field]);
        if (strlen($value) > $limit) throw new RuntimeException('Pole product.' . $field . ' jest za długie.');
        $product[$field] = $value;
    }
    if (isset($data['product']['saleType']) && $data['product']['saleType'] !== 'garden_figure') {
        throw new RuntimeException('Wynik Codexa może dotyczyć wyłącznie typu garden_figure.');
    }
    $categories = product_category_options();
    if (isset($product['category']) && $product['category'] !== '' && !in_array($product['category'], $categories, true)) {
        throw new RuntimeException('Wynik Codexa zawiera nieobsługiwaną kategorię.');
    }
    if (isset($product['slug']) && $product['slug'] !== '' && clean_filename($product['slug']) !== $product['slug']) {
        throw new RuntimeException('Slug w wyniku Codexa ma niedozwolony format.');
    }

    $available = [];
    foreach ((array)($draft['images'] ?? []) as $image) {
        if (is_array($image) && isset($image['prepared'])) $available[(string)$image['prepared']] = true;
    }
    if (count($data['images']) < 1 || count($data['images']) > MAX_DRAFT_IMAGES) {
        throw new RuntimeException('Wynik Codexa musi zawierać od 1 do ' . MAX_DRAFT_IMAGES . ' zdjęć.');
    }
    $images = [];
    $finalNames = [];
    $mainCount = 0;
    foreach ($data['images'] as $index => $image) {
        if (!is_array($image)) throw new RuntimeException('Wpis zdjęcia nr ' . ($index + 1) . ' jest nieprawidłowy.');
        $file = (string)($image['draftFile'] ?? '');
        if ($file === '' || basename($file) !== $file || !isset($available[$file])) {
            throw new RuntimeException('Wynik Codexa wskazuje zdjęcie spoza aktywnego draftu.');
        }
        if (isset($images[$file])) throw new RuntimeException('To samo zdjęcie zostało wskazane więcej niż raz.');
        $role = (string)($image['role'] ?? 'gallery');
        if (!in_array($role, ['main', 'gallery'], true)) throw new RuntimeException('Rola zdjęcia musi być main albo gallery.');
        if ($role === 'main') $mainCount++;
        $finalFilename = (string)($image['finalFilename'] ?? '');
        if (!valid_codex_final_filename($finalFilename)) throw new RuntimeException('Proponowana nazwa pliku WebP jest nieprawidłowa.');
        if (isset($finalNames[$finalFilename])) throw new RuntimeException('Dwa zdjęcia mają tę samą proponowaną nazwę WebP.');
        $finalNames[$finalFilename] = true;
        $alt = (string)($image['alt'] ?? '');
        if (strlen($alt) > 240) throw new RuntimeException('ALT zdjęcia jest za długi.');
        $view = (string)($image['view'] ?? 'other');
        if (!in_array($view, ['front', 'side', 'back', 'detail', 'closeup', 'other'], true)) throw new RuntimeException('Nieprawidłowy typ ujęcia zdjęcia.');
        $confidence = (string)($image['confidence'] ?? 'medium');
        if (!in_array($confidence, ['high', 'medium', 'low'], true)) throw new RuntimeException('Nieprawidłowy poziom pewności analizy zdjęcia.');
        $images[$file] = ['draftFile' => $file, 'role' => $role, 'finalFilename' => $finalFilename, 'alt' => trim($alt), 'view' => $view, 'confidence' => $confidence];
    }
    if ($mainCount > 1) throw new RuntimeException('Wynik Codexa wskazuje więcej niż jedno zdjęcie główne.');

    $allowedManualFields = ['grossPrice', 'catalogPrice', 'material', 'dimensions', 'height', 'width', 'depth', 'weight', 'sku', 'shippingProfileIds', 'packageDimensions', 'packageWeight', 'packageLengthCm', 'packageWidthCm', 'packageHeightCm', 'leadTime', 'producerAvailability'];
    $manualFieldsRequired = [];
    foreach ((array)($data['manualFieldsRequired'] ?? []) as $field) {
        if (!is_string($field) || !in_array($field, $allowedManualFields, true)) continue;
        $manualFieldsRequired[] = $field;
    }
    return ['product' => $product, 'images' => array_values($images), 'manualFieldsRequired' => array_values(array_unique($manualFieldsRequired))];
}

function imported_codex_product_draft(array $draft): ?array
{
    $analysis = $draft['analysis'] ?? null;
    return is_array($analysis) && ($analysis['status'] ?? '') === 'codex_prepared' ? $analysis : null;
}

function remove_product_image_draft(string $id): void
{
    $path = product_image_draft_path($id);
    if ($path === '' || !is_dir($path)) return;
    foreach (glob($path . '/*') ?: [] as $file) {
        if (is_file($file)) @unlink($file);
    }
    @rmdir($path);
}

function cleanup_product_image_drafts(): void
{
    foreach (glob(PRODUCT_IMAGE_DRAFT_DIR . '/*', GLOB_ONLYDIR) ?: [] as $path) {
        $id = basename($path);
        $draft = load_product_image_draft($id);
        $createdAt = (int)($draft['createdAt'] ?? 0);
        if ($createdAt > 0 && $createdAt < time() - PRODUCT_IMAGE_DRAFT_TTL) remove_product_image_draft($id);
    }
}

function copy_to_unique_upload(string $source, string $suggestedName): string
{
    $suggestedName = basename($suggestedName);
    $base = clean_filename(pathinfo($suggestedName, PATHINFO_FILENAME));
    $number = 1;
    while ($number < 1000) {
        $name = $base . ($number === 1 ? '' : '-' . $number) . '.webp';
        $target = UPLOAD_DIR . '/' . $name;
        $output = @fopen($target, 'x+b');
        if ($output === false) {
            $number++;
            continue;
        }
        $input = @fopen($source, 'rb');
        if ($input === false) {
            fclose($output);
            @unlink($target);
            throw new RuntimeException('Nie udało się odczytać przygotowanego zdjęcia.');
        }
        $copied = stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
        if ($copied === false) {
            @unlink($target);
            throw new RuntimeException('Nie udało się przenieść przygotowanego zdjęcia do katalogu produktów.');
        }
        @chmod($target, 0644);
        return '/uploads/' . $name;
    }
    throw new RuntimeException('Nie udało się utworzyć unikalnej nazwy zdjęcia.');
}

/** @return array{main:string,gallery:array<int,string>,created:array<int,string>} */
function publish_product_image_draft(string $id, string $mainFile, array $galleryFiles, string $productName): array
{
    $draft = load_product_image_draft($id);
    $path = product_image_draft_path($id);
    if (!$draft || $path === '') throw new RuntimeException('Wersja robocza zdjęć nie istnieje lub wygasła.');
    $allowed = [];
    foreach ((array)($draft['images'] ?? []) as $image) {
        if (is_array($image) && isset($image['prepared'])) $allowed[(string)$image['prepared']] = true;
    }
    $proposedNames = [];
    foreach ((array)(imported_codex_product_draft($draft)['images'] ?? []) as $image) {
        if (is_array($image) && isset($image['draftFile'], $image['finalFilename']) && valid_codex_final_filename((string)$image['finalFilename'])) {
            $proposedNames[(string)$image['draftFile']] = (string)$image['finalFilename'];
        }
    }
    $requested = array_values(array_unique(array_merge([$mainFile], $galleryFiles)));
    if ($mainFile === '' || !isset($allowed[$mainFile])) throw new RuntimeException('Wybierz zdjęcie główne z przygotowanej wersji roboczej.');
    foreach ($requested as $file) if (!isset($allowed[$file])) throw new RuntimeException('Nieprawidłowe zdjęcie wersji roboczej.');

    $published = [];
    try {
        foreach ($requested as $requestedIndex => $file) {
            $source = $path . '/' . $file;
            if (!is_file($source)) throw new RuntimeException('Brakuje przygotowanego pliku zdjęcia.');
            $suggested = $proposedNames[$file] ?? (clean_filename($productName) . ($file === $mainFile ? '' : '-ujecie-' . ($requestedIndex + 1)) . '.webp');
            $published[$file] = copy_to_unique_upload($source, $suggested);
        }
    } catch (Throwable $exception) {
        foreach ($published as $publicPath) @unlink(SITE_ROOT . $publicPath);
        throw $exception;
    }
    $gallery = [];
    foreach ($galleryFiles as $file) if ($file !== $mainFile && isset($published[$file])) $gallery[] = $published[$file];
    return ['main' => $published[$mainFile], 'gallery' => array_values(array_unique($gallery)), 'created' => array_values($published)];
}

function optimize_image(string $sourcePath, string $mime, string $targetPath): bool
{
    if (!function_exists('imagewebp')) {
        return false;
    }
    $create = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
    ][$mime] ?? '';
    if ($create === '' || !function_exists($create)) {
        return false;
    }
    $source = @$create($sourcePath);
    if (!$source) {
        return false;
    }

    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($sourcePath);
        $orientation = (int)($exif['Orientation'] ?? 1);
        if ($orientation === 3) {
            $source = imagerotate($source, 180, 0);
        } elseif ($orientation === 6) {
            $source = imagerotate($source, -90, 0);
        } elseif ($orientation === 8) {
            $source = imagerotate($source, 90, 0);
        }
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min(1, MAX_IMAGE_EDGE / max($width, $height));
    $targetWidth = max(1, (int)round($width * $scale));
    $targetHeight = max(1, (int)round($height * $scale));
    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
    $saved = imagewebp($canvas, $targetPath, 84);
    imagedestroy($canvas);
    imagedestroy($source);
    if ($saved) {
        @chmod($targetPath, 0644);
    }
    return $saved;
}

function normalize_gallery_files(array $files): array
{
    $result = [];
    $names = $files['name'] ?? [];
    if (!is_array($names)) {
        return $result;
    }
    foreach ($names as $index => $name) {
        $result[] = [
            'name' => $name,
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];
    }
    return $result;
}

/** @return array{id:string,images:array<int,array{prepared:string,original:string}>} */
function create_product_image_draft(array $files, string $productName): array
{
    $files = normalize_gallery_files($files);
    $files = array_values(array_filter($files, static fn(array $file): bool => (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    if (!$files) throw new RuntimeException('Dodaj co najmniej jedno zdjęcie figury.');
    if (count($files) > MAX_DRAFT_IMAGES) throw new RuntimeException('Możesz przygotować maksymalnie ' . MAX_DRAFT_IMAGES . ' zdjęć jednocześnie.');

    $id = bin2hex(random_bytes(16));
    $path = product_image_draft_path($id);
    if ($path === '' || !@mkdir($path, 0750, true)) throw new RuntimeException('Nie udało się utworzyć bezpiecznego katalogu roboczego zdjęć.');
    $base = clean_filename($productName);
    $images = [];
    try {
        foreach ($files as $index => $file) {
            $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Nie udało się przesłać zdjęcia. Kod błędu: ' . $error);
            if ((int)($file['size'] ?? 0) > MAX_UPLOAD_BYTES) throw new RuntimeException('Zdjęcie jest za duże. Maksymalny rozmiar to 12 MB.');
            [$tmp, $mime, $extension] = validated_uploaded_image($file);
            $position = $index + 1;
            $original = 'oryginal-' . str_pad((string)$position, 2, '0', STR_PAD_LEFT) . '.' . $extension;
            if (!move_uploaded_file($tmp, $path . '/' . $original)) throw new RuntimeException('Serwer nie mógł zapisać oryginału zdjęcia.');
            @chmod($path . '/' . $original, 0640);
            $prepared = $position === 1 ? $base . '.webp' : $base . '-ujecie-' . $position . '.webp';
            if (!optimize_image($path . '/' . $original, $mime, $path . '/' . $prepared)) {
                throw new RuntimeException('Serwer nie ma bezpiecznie dostępnej konwersji do WebP dla tego zdjęcia.');
            }
            @chmod($path . '/' . $prepared, 0640);
            $images[] = ['original' => $original, 'prepared' => $prepared];
        }
        $draft = ['id' => $id, 'createdAt' => time(), 'productName' => trim($productName), 'images' => $images];
        save_product_image_draft($draft);
        return $draft;
    } catch (Throwable $exception) {
        remove_product_image_draft($id);
        throw $exception;
    }
}

function safe_image_path(string $path): string
{
    return str_starts_with($path, '/uploads/') && !str_contains($path, '..') ? $path : '';
}
