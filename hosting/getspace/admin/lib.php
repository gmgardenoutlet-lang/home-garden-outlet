<?php
declare(strict_types=1);

const SITE_ROOT = __DIR__ . '/..';
const PRODUCTS_FILE = SITE_ROOT . '/data/products.json';
const UPLOAD_DIR = SITE_ROOT . '/uploads';
const STORAGE_DIR = __DIR__ . '/storage';
const BACKUP_DIR = STORAGE_DIR . '/backups';
const CREDENTIALS_FILE = __DIR__ . '/.credentials.php';
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
        'category' => 'Wyposażenie domu',
        'productType' => '',
        'featured' => true,
        'visible' => true,
        'catalogPrice' => '',
        'outletPrice' => '',
        'currency' => 'PLN',
        'image' => '',
        'gallery' => [],
        'imageAlt' => '',
        'description' => '',
        'longDescription' => '',
        'dimensions' => '',
        'material' => '',
        'color' => '',
        'condition' => 'Outletowy',
        'status' => 'Dostępne',
        'productStatus' => 'Aktywny',
        'seoTitle' => '',
        'seoDescription' => '',
        'slug' => '',
        'order' => 0,
    ];
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
