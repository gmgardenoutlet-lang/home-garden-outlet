<?php
declare(strict_types=1);

function allegro_base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function allegro_pkce_challenge(string $verifier): string
{
    if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)) throw new AllegroSafeException('state');
    return allegro_base64url(hash('sha256', $verifier, true));
}

function allegro_start(array $config, string $binding, int $now): string
{
    allegro_validate_config($config, true);
    if (!preg_match('/^[a-f0-9]{64}$/D', $binding)) throw new AllegroSafeException('session');
    return allegro_locked($config, static function (array $vault, string $key) use ($config, $binding, $now): string {
        $state = bin2hex(random_bytes(32));
        $verifier = allegro_base64url(random_bytes(64)); // 86 RFC7636 unreserved characters.
        unset($vault['pending']);
        $vault['state'] = ['hash' => hash('sha256', $state), 'binding' => $binding,
            'expires' => $now + 600, 'verifier' => $verifier];
        allegro_write_vault($config, $key, $vault);
        allegro_log($config, 'oauth_start');
        return allegro_hosts($config['environment'])['auth'] . '/auth/oauth/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => $config['client_id'], 'redirect_uri' => $config['redirect_uri'],
            'scope' => ALLEGRO_B_SCOPE, 'state' => $state, 'code_challenge' => allegro_pkce_challenge($verifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    });
}

/** Cookie-independent callback. Consumes state, but NEVER exchanges the code here. */
function allegro_callback(array $config, string $state, string $code, bool $denied, int $now): string
{
    allegro_validate_config($config, true);
    if (!preg_match('/^[a-f0-9]{64}$/D', $state)) throw new AllegroSafeException('state');
    return allegro_locked($config, static function (array $vault, string $key) use ($config, $state, $code, $denied, $now): string {
        $now = max($now, time()); // Lock contention must not extend an expired credential.
        $record = $vault['state'] ?? [];
        if (!is_string($record['hash'] ?? null) || !hash_equals($record['hash'], hash('sha256', $state))) {
            throw new AllegroSafeException('state');
        }
        unset($vault['state']);
        allegro_write_vault($config, $key, $vault); // Consume even on denial, missing code or expiry.
        if (($record['expires'] ?? 0) <= $now) throw new AllegroSafeException('state');
        if ($denied) throw new AllegroSafeException('denied');
        if ($code === '' || strlen($code) > 4096 || preg_match('/[\x00-\x20\x7f]/', $code)) throw new AllegroSafeException('auth');
        $handle = bin2hex(random_bytes(32));
        $vault['pending'] = ['hash' => hash('sha256', $handle), 'binding' => $record['binding'], 'code' => $code,
            // Allegro's authorization code lives for 10 seconds. Leave a margin;
            // the cookie bridge must continue automatically, never wait minutes.
            'verifier' => $record['verifier'], 'expires' => min($now + 8, $record['expires'])];
        allegro_write_vault($config, $key, $vault);
        allegro_log($config, 'oauth_callback');
        return $handle;
    });
}

function allegro_tokens(array $data, int $now): array
{
    foreach (['access_token', 'refresh_token'] as $field) {
        if (!is_string($data[$field] ?? null) || $data[$field] === '' || strlen($data[$field]) > 32768
            || preg_match('/[\x00-\x20\x7f]/', $data[$field])) throw new AllegroSafeException('auth');
    }
    $scope = is_string($data['scope'] ?? null) ? preg_split('/\s+/', trim($data['scope'])) : [];
    if (!is_string($data['token_type'] ?? null) || strtolower($data['token_type']) !== 'bearer' || !is_int($data['expires_in'] ?? null)
        || $data['expires_in'] <= 90 || $data['expires_in'] > 86400
        || array_values(array_unique($scope)) !== [ALLEGRO_B_SCOPE]) throw new AllegroSafeException('auth');
    return ['access' => $data['access_token'], 'refresh' => $data['refresh_token'],
        'expires_at' => $now + $data['expires_in'], 'scope' => ALLEGRO_B_SCOPE];
}

function allegro_identity(array $data): array
{
    if (!is_string($data['id'] ?? null) || !preg_match('/^[0-9]{1,32}$/D', $data['id'])
        || !is_string($data['login'] ?? null) || $data['login'] === '' || strlen($data['login']) > 128
        || preg_match('/[\x00-\x1f\x7f]/u', $data['login']) || preg_match('//u', $data['login']) !== 1) {
        throw new AllegroSafeException('identity');
    }
    // Deliberately do not retain email, name, address or any other /me fields.
    return ['id' => $data['id'], 'login' => $data['login']];
}

/** Read-only check for the same-session automatic POST bridge; returns no credentials. */
function allegro_pending_ready(array $config, string $handle, string $binding, int $now): bool
{
    return allegro_locked($config, static function (array $vault) use ($handle, $binding, $now): bool {
        $now = max($now, time());
        $pending = $vault['pending'] ?? [];
        return preg_match('/^[a-f0-9]{64}$/D', $handle) === 1 && is_string($pending['hash'] ?? null)
            && hash_equals($pending['hash'], hash('sha256', $handle))
            && is_string($pending['binding'] ?? null) && hash_equals($pending['binding'], $binding)
            && ($pending['expires'] ?? 0) > $now;
    });
}

function allegro_finish(array $config, string $handle, string $binding, int $now, ?callable $transport = null): void
{
    allegro_validate_config($config, true);
    allegro_locked($config, static function (array $vault, string $key) use ($config, $handle, $binding, $now, $transport): void {
        $now = max($now, time());
        $pending = $vault['pending'] ?? [];
        if (!preg_match('/^[a-f0-9]{64}$/D', $handle) || !is_string($pending['hash'] ?? null)
            || !hash_equals($pending['hash'], hash('sha256', $handle))
            || !hash_equals($pending['binding'], $binding)) throw new AllegroSafeException('session');
        unset($vault['pending'], $vault['tokens'], $vault['account'], $vault['state'], $vault['lastCheckedAt']);
        $vault['status'] = 'reconnect';
        allegro_write_vault($config, $key, $vault); // A crash cannot replay a code exchange.
        if ($pending['expires'] <= $now) throw new AllegroSafeException('state');
        try {
            $data = allegro_http($config, 'POST', '/auth/oauth/token', '', ['grant_type' => 'authorization_code',
                'code' => $pending['code'], 'code_verifier' => $pending['verifier']], $transport);
            $tokens = allegro_tokens($data, $now);
            $account = allegro_identity(allegro_http($config, 'GET', '/me', $tokens['access'], [], $transport));
            $vault['tokens'] = $tokens;
            $vault['account'] = $account;
            $vault['status'] = 'connected';
            unset($vault['error']);
            allegro_write_vault($config, $key, $vault);
            allegro_log($config, 'oauth_finish', ['sellerId' => $account['id']]);
        } catch (Throwable $e) {
            unset($vault['tokens'], $vault['account']);
            $vault['status'] = 'reconnect';
            $vault['error'] = $e instanceof AllegroSafeException ? $e->kind : 'auth';
            allegro_write_vault($config, $key, $vault);
            throw new AllegroSafeException($vault['error']);
        }
    });
}

/** Account lock remains held during refresh and until the rotated pair is durably saved. */
function allegro_refresh_locked(array $config, array &$vault, string $key, int $now, ?callable $transport): string
{
    $now = max($now, time());
    if (($vault['status'] ?? '') !== 'connected' || empty($vault['tokens']) || empty($vault['account'])) {
        throw new AllegroSafeException('auth');
    }
    if ($vault['tokens']['expires_at'] > $now + 90) return $vault['tokens']['access'];
    $refresh = $vault['tokens']['refresh'];
    $vault['status'] = 'reconnect';
    unset($vault['tokens']);
    allegro_write_vault($config, $key, $vault); // Persist fail-closed before using a rotating credential.
    try {
        $tokens = allegro_tokens(allegro_http($config, 'POST', '/auth/oauth/token', '',
            ['grant_type' => 'refresh_token', 'refresh_token' => $refresh], $transport), $now);
        $vault['tokens'] = $tokens;
        $vault['status'] = 'connected';
        unset($vault['error']);
        allegro_write_vault($config, $key, $vault);
        allegro_log($config, 'refresh', ['sellerId' => $vault['account']['id']]);
    } catch (Throwable $e) {
        unset($vault['tokens']);
        $vault['status'] = 'reconnect';
        $vault['error'] = 'refresh';
        allegro_write_vault($config, $key, $vault);
        throw new AllegroSafeException('refresh');
    }
    return $vault['tokens']['access'];
}

/** Server-only; never called by product save or by a background catalogue task. */
function allegro_access_token(array $config, int $now, ?callable $transport = null): string
{
    allegro_validate_config($config, true);
    return allegro_locked($config, static function (array $vault, string $key) use ($config, $now, $transport): string {
        return allegro_refresh_locked($config, $vault, $key, $now, $transport);
    });
}

function allegro_diagnose(array $config, int $now, ?callable $transport = null): void
{
    allegro_validate_config($config, true);
    allegro_locked($config, static function (array $vault, string $key) use ($config, $now, $transport): void {
        $access = allegro_refresh_locked($config, $vault, $key, $now, $transport);
        try {
            $account = allegro_identity(allegro_http($config, 'GET', '/me', $access, [], $transport));
            if ($account['id'] !== $vault['account']['id']) throw new AllegroSafeException('identity');
        } catch (AllegroSafeException $e) {
            if (in_array($e->kind, ['auth', 'identity'], true)) {
                unset($vault['tokens']);
                $vault['status'] = 'reconnect';
                $vault['error'] = $e->kind;
                allegro_write_vault($config, $key, $vault);
            }
            throw $e;
        }
        $vault['account'] = $account;
        $vault['lastCheckedAt'] = gmdate('c', $now);
        allegro_write_vault($config, $key, $vault);
        allegro_log($config, 'account_read', ['sellerId' => $account['id']]);
    });
}

function allegro_disconnect(array $config): void
{
    // Local deletion works even with OAuth disabled / missing Client Secret / unsafe hosting logs.
    allegro_locked($config, static function (array $vault, string $key) use ($config): void {
        allegro_write_vault($config, $key, allegro_context($config) + ['status' => 'disconnected']);
        allegro_log($config, 'disconnect');
    });
}

function allegro_public_status(array $config): array
{
    return allegro_locked($config, static function (array $vault, string $key) use ($config): array {
        $changed = false;
        foreach (['state', 'pending'] as $field) {
            if (isset($vault[$field]) && ($vault[$field]['expires'] ?? 0) <= time()) {
                unset($vault[$field]);
                $changed = true;
            }
        }
        if ($changed) allegro_write_vault($config, $key, $vault);
        return ['environment' => $config['environment'], 'status' => $vault['status'] ?? 'disconnected',
            'account' => isset($vault['account']) ? allegro_identity($vault['account']) : null,
            'expires_at' => $vault['tokens']['expires_at'] ?? null, 'lastCheckedAt' => $vault['lastCheckedAt'] ?? null];
    });
}
