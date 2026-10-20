<?php
declare(strict_types=1);

// This module deliberately implements no advert endpoints.
const OLX_TOKEN_URL = 'https://www.olx.pl/api/open/oauth/token';
const OLX_REDIRECT_URI = 'https://mgoutlet.pl/admin/olx/callback';
const OLX_SCOPE = 'read write v2';

final class OlxSafeException extends RuntimeException {}

function olx_error(string $kind = 'config'): OlxSafeException
{
    $messages = [
        'config' => 'OLX nie jest skonfigurowany. Sprawdź prywatną konfigurację i ochronę logów callbacku.',
        'state' => 'Brakujący, nieprawidłowy lub wygasły state OLX. Rozpocznij połączenie ponownie.',
        'session' => 'Wróć do panelu, zaloguj się i rozpocznij połączenie OLX ponownie.',
        'storage' => 'Nie można bezpiecznie odczytać lub zapisać magazynu OLX.',
        'auth' => 'OLX odrzucił autoryzację. Połącz konto ponownie.',
        'network' => 'Nie udało się bezpiecznie uzyskać tokenu OLX. Spróbuj ponownie później.',
        'refresh' => 'Token OLX wygasł i nie udało się go odnowić. Połącz konto ponownie.',
    ];
    return new OlxSafeException($messages[$kind] ?? $messages['config']);
}

function olx_outside_webroot(string $path): bool
{
    $root = realpath(__DIR__ . '/../..');
    $real = realpath($path);
    if ($root === false || $real === false) return false;
    $root = strtolower(str_replace('\\', '/', $root));
    $real = strtolower(str_replace('\\', '/', $real));
    if ($real === $root || str_starts_with($real, $root . '/')) return false;
    for ($dir = is_dir($path) ? realpath($path) : dirname(realpath($path)); $dir && dirname($dir) !== $dir; $dir = dirname($dir)) {
        if (is_dir($dir . '/.git') || is_file($dir . '/.git')) return false;
    }
    return true;
}

function olx_config(): array
{
    $file = getenv('HGO_OLX_CONFIG') ?: dirname(__DIR__, 3) . '/private_olx/config.php';
    $private = [];
    if (is_file($file)) {
        if (!olx_outside_webroot($file)) throw olx_error();
        $private = require $file;
        if (!is_array($private)) throw olx_error();
    }
    $read = static fn(string $key, string $default = ''): string => (string)(getenv($key) ?: ($private[$key] ?? $default));
    return [
        'client_id' => $read('OLX_CLIENT_ID', '203399'),
        'secret' => $read('OLX_CLIENT_SECRET'),
        'redirect_uri' => $read('OLX_REDIRECT_URI', OLX_REDIRECT_URI),
        'key' => $read('OLX_TOKEN_KEY'),
        'dir' => $read('OLX_STORAGE_DIR', dirname($file) . '/storage'),
        // Set only after the hosting operator confirms every logging layer.
        'logs_safe' => $read('OLX_CALLBACK_LOGS_REDACTED') === '1',
    ];
}

function olx_validate_config(array $config): string
{
    $key = base64_decode($config['key'], true);
    if (!$config['logs_safe'] || $config['secret'] === '' || $config['client_id'] !== '203399'
        || $config['redirect_uri'] !== OLX_REDIRECT_URI
        || !function_exists('sodium_crypto_secretbox') || !function_exists('curl_init') || !is_string($key) || strlen($key) !== 32
        || !is_dir($config['dir']) || !olx_outside_webroot($config['dir']) || !is_writable($config['dir'])) {
        throw olx_error();
    }
    return $key;
}

function olx_read_vault(array $config, string $key): array
{
    $file = $config['dir'] . '/account.enc';
    if (!is_file($file)) return ['status' => 'disconnected'];
    $bytes = file_get_contents($file);
    if (!is_string($bytes) || strlen($bytes) < 40) throw olx_error('storage');
    $plain = sodium_crypto_secretbox_open(substr($bytes, 24), substr($bytes, 0, 24), $key);
    if ($plain === false) throw olx_error('storage');
    try { $data = json_decode($plain, true, 32, JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { throw olx_error('storage'); }
    if (!is_array($data)) throw olx_error('storage');
    return $data;
}

function olx_write_vault(array $config, string $key, array $data): void
{
    $nonce = random_bytes(24);
    $bytes = $nonce . sodium_crypto_secretbox(json_encode($data, JSON_THROW_ON_ERROR), $nonce, $key);
    $temp = tempnam($config['dir'], '.olx-');
    if ($temp === false) throw olx_error('storage');
    try {
        if (!chmod($temp, 0600) || file_put_contents($temp, $bytes) !== strlen($bytes)
            || !rename($temp, $config['dir'] . '/account.enc')) throw olx_error('storage');
    } finally { if (is_file($temp)) unlink($temp); }
}

/** All state/token operations share a lock, including refresh and disconnect. */
function olx_locked(array $config, callable $operation)
{
    $key = olx_validate_config($config);
    $oldMask = umask(0077);
    try { $lock = fopen($config['dir'] . '/account.lock', 'c'); }
    finally { umask($oldMask); }
    if (!$lock) throw olx_error('storage');
    try {
        if (!flock($lock, LOCK_EX)) throw olx_error('storage');
        return $operation(olx_read_vault($config, $key), $key);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function olx_start(array $config, string $binding, int $now): string
{
    return olx_locked($config, static function (array $vault, string $key) use ($config, $binding, $now): string {
        $state = bin2hex(random_bytes(32));
        // Only one pending connection for this single-admin account.
        unset($vault['pending']);
        $vault['state'] = ['hash' => hash('sha256', $state), 'binding' => $binding, 'expires' => $now + 600];
        olx_write_vault($config, $key, $vault);
        return 'https://www.olx.pl/oauth/authorize?' . http_build_query([
            'client_id' => $config['client_id'], 'response_type' => 'code', 'scope' => OLX_SCOPE,
            'redirect_uri' => $config['redirect_uri'], 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    });
}

/** Cookie-independent callback: consume state before accepting any code. No session_start here. */
function olx_callback(array $config, string $state, string $code, bool $denied, int $now): string
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $state)) throw olx_error('state');
    return olx_locked($config, static function (array $vault, string $key) use ($config, $state, $code, $denied, $now): string {
        $record = $vault['state'] ?? [];
        if (!isset($record['hash']) || !hash_equals($record['hash'], hash('sha256', $state))) throw olx_error('state');
        unset($vault['state']);
        olx_write_vault($config, $key, $vault); // Consume even if expired/denied; replay cannot succeed.
        if (($record['expires'] ?? 0) <= $now) throw olx_error('state');
        if ($denied || $code === '' || strlen($code) > 4096) throw olx_error('auth');
        $handle = bin2hex(random_bytes(32));
        $vault['pending'] = ['hash' => hash('sha256', $handle), 'binding' => $record['binding'],
            'code' => $code, 'expires' => min($now + 120, $record['expires'])];
        olx_write_vault($config, $key, $vault);
        return $handle;
    });
}

function olx_http_token(array $config, array $grant): array
{
    if (!function_exists('curl_init')) throw olx_error('config');
    $ch = curl_init(OLX_TOKEN_URL);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json', 'Version: 2.0'],
        CURLOPT_POSTFIELDS => http_build_query($grant + ['client_id' => $config['client_id'],
            'client_secret' => $config['secret']], '', '&', PHP_QUERY_RFC3986)]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body)) throw olx_error('network');
    if ($status === 400 || $status === 401 || $status === 403) throw olx_error('auth');
    if ($status !== 200 || strlen($body) > 65536) throw olx_error('network');
    $data = json_decode($body, true);
    if (!is_array($data)) throw olx_error('network');
    return $data;
}

function olx_tokens(array $data, int $now, ?string $oldRefresh = null): array
{
    $scope = preg_split('/\s+/', trim((string)($data['scope'] ?? '')));
    $refresh = $data['refresh_token'] ?? $oldRefresh;
    if (!is_string($data['access_token'] ?? null) || $data['access_token'] === ''
        || !is_string($refresh) || $refresh === '' || (int)($data['expires_in'] ?? 0) <= 0
        || strtolower((string)($data['token_type'] ?? '')) !== 'bearer'
        || array_diff(['read', 'write', 'v2'], $scope)) throw olx_error('auth');
    return ['access' => $data['access_token'], 'refresh' => $refresh,
        'expires' => $now + (int)$data['expires_in'], 'scope' => OLX_SCOPE];
}

function olx_finish(array $config, string $handle, string $binding, int $now, ?callable $transport = null): void
{
    olx_locked($config, static function (array $vault, string $key) use ($config, $handle, $binding, $now, $transport): void {
        $pending = $vault['pending'] ?? [];
        if (!preg_match('/^[a-f0-9]{64}$/D', $handle) || !isset($pending['hash'])
            || !hash_equals($pending['hash'], hash('sha256', $handle))
            || !hash_equals($pending['binding'], $binding)) throw olx_error('session');
        unset($vault['pending']);
        olx_write_vault($config, $key, $vault);
        if ($pending['expires'] <= $now) throw olx_error('state');
        try {
            $data = ($transport ?? 'olx_http_token')($config, ['grant_type' => 'authorization_code',
                'code' => $pending['code'], 'scope' => OLX_SCOPE, 'redirect_uri' => $config['redirect_uri']]);
            $vault['tokens'] = olx_tokens($data, $now);
            $vault['status'] = 'connected';
            unset($vault['error']);
        } catch (Throwable $e) {
            $vault['error'] = 'auth';
            olx_write_vault($config, $key, $vault);
            throw olx_error('auth');
        }
        olx_write_vault($config, $key, $vault);
    });
}

/** Server-only. Called on demand, never by product save or a catalogue job. */
function olx_access_token(array $config, int $now, ?callable $transport = null): string
{
    return olx_locked($config, static function (array $vault, string $key) use ($config, $now, $transport): string {
        if (($vault['status'] ?? '') !== 'connected' || empty($vault['tokens'])) throw olx_error('auth');
        if ($vault['tokens']['expires'] > $now + 90) return $vault['tokens']['access'];
        // Persist fail-closed before sending a rotating refresh token. A crash or
        // ambiguous network result requires reconnect, never retries the old token.
        $vault['status'] = 'reconnect';
        olx_write_vault($config, $key, $vault);
        try {
            $data = ($transport ?? 'olx_http_token')($config, ['grant_type' => 'refresh_token',
                'refresh_token' => $vault['tokens']['refresh']]);
            $vault['tokens'] = olx_tokens($data, $now, $vault['tokens']['refresh']);
            $vault['status'] = 'connected';
            unset($vault['error']);
        } catch (Throwable $e) {
            unset($vault['tokens']);
            $vault['error'] = 'refresh';
            olx_write_vault($config, $key, $vault);
            throw olx_error('refresh');
        }
        olx_write_vault($config, $key, $vault);
        return $vault['tokens']['access'];
    });
}

function olx_disconnect(array $config): void
{
    olx_locked($config, static function (array $vault, string $key) use ($config): void {
        olx_write_vault($config, $key, ['status' => 'disconnected']);
    });
}

function olx_status(array $config): string
{
    return olx_locked($config, static function (array $vault): string {
        if (($vault['status'] ?? '') === 'reconnect') return 'Wymaga ponownego połączenia';
        if (($vault['status'] ?? '') === 'connected') return 'Połączone';
        if (isset($vault['error'])) return 'Błąd autoryzacji';
        return 'Niepołączone';
    });
}
