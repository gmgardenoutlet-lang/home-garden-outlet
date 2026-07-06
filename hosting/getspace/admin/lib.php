<?php
declare(strict_types=1);

const SITE_ROOT = __DIR__ . '/..';
const PRODUCTS_FILE = SITE_ROOT . '/data/products.json';
const SHIPPING_PROFILES_FILE = SITE_ROOT . '/data/shipping-profiles.json';
const UPLOAD_DIR = SITE_ROOT . '/uploads';
const STORAGE_DIR = __DIR__ . '/storage';
const BACKUP_DIR = STORAGE_DIR . '/backups';
const STATS_DIR = STORAGE_DIR . '/stats';
const ORDERS_DIR = STORAGE_DIR . '/orders';
const STATS_TIMEZONE = 'Europe/Warsaw';
const CREDENTIALS_FILE = __DIR__ . '/.credentials.php';
const GOOGLE_BUSINESS_CONFIG_FILE = STORAGE_DIR . '/google-business.php';
const MAX_UPLOAD_BYTES = 12 * 1024 * 1024;
const MAX_IMAGE_EDGE = 2200;

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

function boot_admin(): void
{
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

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_name('hgo_admin');
    session_start();

    header('X-Robots-Tag: noindex, nofollow, noarchive');
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
    return in_array($range, ['today', '7', '30'], true) ? $range : 'today';
}

function normalize_stats_product_limit($limit): int
{
    $value = (int)$limit;
    return in_array($value, [10, 25, 50], true) ? $value : 10;
}

function stats_range_days(string $range): int
{
    if ($range === '30') {
        return 30;
    }
    if ($range === '7') {
        return 7;
    }
    return 1;
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
        'Testowe bez płatności',
        'Oczekuje na płatność',
        'Opłacone',
        'Anulowane',
        'Zwrot',
    ];
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
        $id = 'TEST-' . $date . '-' . str_pad((string)$attempt, 4, '0', STR_PAD_LEFT);
        if (!is_file(ORDERS_DIR . '/' . $id . '.json')) {
            return $id;
        }
    }
    return 'TEST-' . $date . '-' . bin2hex(random_bytes(3));
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
    foreach (glob(ORDERS_DIR . '/TEST-*.json') ?: [] as $file) {
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

function shop_update_order(string $orderId, string $orderStatus, string $paymentStatus, string $internalNote): void
{
    $order = shop_load_order($orderId);
    if (!$order) {
        throw new RuntimeException('Nie znaleziono zamówienia.');
    }
    if (!in_array($orderStatus, shop_order_statuses(), true)) {
        throw new RuntimeException('Nieprawidłowy status zamówienia.');
    }
    if (!in_array($paymentStatus, shop_payment_statuses(), true)) {
        throw new RuntimeException('Nieprawidłowy status płatności.');
    }
    $order['orderStatus'] = $orderStatus;
    $order['paymentStatus'] = $paymentStatus;
    $order['internalNote'] = trim($internalNote);
    $order['updatedAt'] = (new DateTimeImmutable('now', new DateTimeZone(STATS_TIMEZONE)))->format(DATE_ATOM);
    shop_save_order($order);
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

    $tmp = (string)($file['tmp_name'] ?? '');
    $info = @getimagesize($tmp);
    $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Dozwolone zdjęcia: JPG, PNG lub WebP. Zdjęcia HEIC zmień w telefonie na JPG.');
    }

    $base = clean_filename($productName) . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $optimized = optimize_image($tmp, $mime, UPLOAD_DIR . '/' . $base . '.webp');
    if ($optimized) {
        return '/uploads/' . $base . '.webp';
    }

    $target = UPLOAD_DIR . '/' . $base . '.' . $extensions[$mime];
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Serwer nie mógł zapisać zdjęcia.');
    }
    @chmod($target, 0644);
    return '/uploads/' . basename($target);
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

function safe_image_path(string $path): string
{
    return str_starts_with($path, '/uploads/') && !str_contains($path, '..') ? $path : '';
}
