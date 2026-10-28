<?php
declare(strict_types=1);

const ALLEGRO_PROD_CALLBACK = 'https://mgoutlet.pl/admin/allegro/callback';
// Stage B needs only /me. Sales scopes require a future explicit code change.
const ALLEGRO_B_SCOPE = 'allegro:api:profile:read';

function allegro_private_path(string $path): bool
{
    $real = realpath($path);
    $root = realpath(__DIR__ . '/../..');
    if ($real === false || $root === false) return false;
    $normal = static fn(string $p): string => strtolower(str_replace('\\', '/', $p));
    $real = $normal($real);
    $roots = [$normal($root)];
    if (!empty($_SERVER['DOCUMENT_ROOT']) && ($doc = realpath($_SERVER['DOCUMENT_ROOT'])) !== false) $roots[] = $normal($doc);
    foreach ($roots as $public) if ($real === $public || str_starts_with($real, $public . '/')) return false;
    if (preg_match('~(?:^|/)private_olx(?:/|$)~', $real)) return false;
    for ($dir = is_dir($path) ? realpath($path) : dirname((string)realpath($path)); $dir; $dir = dirname($dir)) {
        if (is_dir($dir . '/.git') || is_file($dir . '/.git')) return false;
        if (dirname($dir) === $dir) break;
    }
    return true;
}

function allegro_hosts(string $environment): array
{
    return match ($environment) {
        'PROD' => ['auth' => 'https://allegro.pl', 'api' => 'https://api.allegro.pl'],
        'SANDBOX' => ['auth' => 'https://allegro.pl.allegrosandbox.pl', 'api' => 'https://api.allegro.pl.allegrosandbox.pl'],
        default => throw new AllegroSafeException('config'),
    };
}

function allegro_config(): array
{
    $file = getenv('HGO_ALLEGRO_CONFIG') ?: dirname(__DIR__, 3) . '/private_allegro/config.php';
    $private = [];
    if (is_file($file)) {
        if (!allegro_private_path($file) || is_link($file)) throw new AllegroSafeException('config');
        $private = require $file;
        if (!is_array($private)) throw new AllegroSafeException('config');
    }
    $read = static function (string $key, string $default = '') use ($private): string {
        $value = getenv($key);
        if ($value === false) $value = $private[$key] ?? $default;
        if (!is_string($value)) throw new AllegroSafeException('config');
        return $value;
    };
    $environment = $read('ALLEGRO_ENVIRONMENT', 'SANDBOX');
    allegro_hosts($environment); // Never select environment from a request.
    return [
        'environment' => $environment,
        'client_id' => $read('ALLEGRO_CLIENT_ID'),
        'secret' => $read('ALLEGRO_CLIENT_SECRET'),
        'redirect_uri' => $read('ALLEGRO_REDIRECT_URI', $environment === 'PROD' ? ALLEGRO_PROD_CALLBACK : ''),
        'key' => $read('ALLEGRO_TOKEN_KEY'),
        'storage_root' => $read('ALLEGRO_STORAGE_DIR', dirname($file) . '/storage'),
        'enabled' => $read('ALLEGRO_OAUTH_ENABLED', '0') === '1',
        'logs_safe' => $read('ALLEGRO_CALLBACK_LOGS_REDACTED', '0') === '1',
    ];
}

function allegro_validate_config(array $config, bool $oauth = false): string
{
    allegro_hosts($config['environment'] ?? '');
    $key = base64_decode($config['key'] ?? '', true);
    $root = $config['storage_root'] ?? '';
    if (!is_string($key) || strlen($key) !== 32 || !function_exists('sodium_crypto_secretbox')
        || !is_dir($root) || !is_writable($root) || !allegro_private_path($root) || is_link($root)) {
        throw new AllegroSafeException('config');
    }
    if ($oauth) {
        $uri = $config['redirect_uri'] ?? '';
        $parts = parse_url($uri);
        if (!preg_match('/^[a-zA-Z0-9_-]{8,128}$/D', $config['client_id'] ?? '')
            || ($config['secret'] ?? '') === '' || preg_match('/[\x00-\x20\x7f]/', $config['secret'])
            || !function_exists('curl_init') || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || !preg_match('/^[a-z0-9.-]+$/D', $parts['host'])
            || filter_var($parts['host'], FILTER_VALIDATE_IP) || !str_contains($parts['host'], '.')
            || ($parts['path'] ?? '') !== '/admin/allegro/callback'
            || array_intersect(['user', 'pass', 'port', 'query', 'fragment'], array_keys($parts))
            || ($config['environment'] === 'PROD' && $uri !== ALLEGRO_PROD_CALLBACK)
            || ($config['environment'] === 'SANDBOX' && $uri === ALLEGRO_PROD_CALLBACK)) {
            throw new AllegroSafeException('config');
        }
        if (empty($config['enabled']) || empty($config['logs_safe'])) throw new AllegroSafeException('disabled');
    }
    return $key;
}

function allegro_context(array $config): array
{
    return ['schemaVersion' => 1, 'environment' => $config['environment'],
        'clientHash' => hash('sha256', ($config['client_id'] ?? '') . "\n" . ($config['redirect_uri'] ?? ''))];
}
