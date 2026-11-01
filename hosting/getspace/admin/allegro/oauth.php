<?php
declare(strict_types=1);

/** Legacy callback is permanently disabled; Device never consumes query parameters. */
function allegro_callback(array $config, string $state, string $code, bool $denied, int $now): string
{
    throw new AllegroSafeException('disabled');
}

function allegro_device_seconds(mixed $value, int $limit): int
{
    // Allegro examples include decimal strings; reject coercion, floats and exponents.
    if (is_string($value) && preg_match('/^[1-9][0-9]{0,5}$/D', $value)) $value = (int)$value;
    if (!is_int($value) || $value < 1 || $value > $limit) throw new AllegroSafeException('auth');
    return $value;
}

function allegro_device_response(array $config, array $data): array
{
    $user = $data['user_code'] ?? null;
    $code = $data['device_code'] ?? null;
    if (!is_string($user) || !preg_match('/^[A-Za-z0-9-]{6,32}$/D', $user)
        || !is_string($code) || $code === '' || strlen($code) > 4096 || preg_match('/[\x00-\x20\x7f]/', $code)) {
        throw new AllegroSafeException('auth');
    }
    $base = allegro_hosts($config['environment'])['auth'] . '/skojarz-aplikacje';
    // Canonical exact URLs reject ports, userinfo, fragments, alternate paths and extra query fields.
    if (($data['verification_uri'] ?? null) !== $base
        || ($data['verification_uri_complete'] ?? null) !== $base . '?code=' . rawurlencode($user)) {
        throw new AllegroSafeException('auth');
    }
    return ['device_code' => $code, 'user_code' => $user, 'verification_uri' => $base,
        'verification_uri_complete' => $data['verification_uri_complete'],
        'expires_in' => allegro_device_seconds($data['expires_in'] ?? null, 86400),
        'interval' => allegro_device_seconds($data['interval'] ?? null, 300)];
}

function allegro_device_start(array $config, string $binding, int $now, ?callable $transport = null): void
{
    allegro_validate_config($config, true);
    if (!preg_match('/^[a-f0-9]{64}$/D', $binding)) throw new AllegroSafeException('session');
    allegro_locked($config, static function (array $vault, string $key) use ($config, $binding, $now, $transport): void {
        $now = max($now, time());
        // Invalidate even if initialization fails; never revive an old pending attempt.
        $vault = allegro_context($config) + ['status' => 'disconnected'];
        allegro_write_vault($config, $key, $vault);
        $data = allegro_device_response($config, allegro_http($config, 'POST', '/auth/oauth/device', '', [], $transport));
        $vault['device'] = ['device_code' => $data['device_code'], 'user_code' => $data['user_code'],
            'verification_uri' => $data['verification_uri'], 'verification_uri_complete' => $data['verification_uri_complete'],
            'expires' => $now + $data['expires_in'], 'interval' => $data['interval'],
            'next_poll_at' => $now + $data['interval'], 'binding' => $binding];
        // The deadline starts BEFORE transport; a slow response cannot extend it.
        if ($vault['device']['expires'] <= time()) throw new AllegroSafeException('state');
        allegro_write_vault($config, $key, $vault);
        allegro_log($config, 'device_start');
    });
}

/** One click = at most one token request. Entire transition serialized by account.lock. */
function allegro_device_poll(array $config, string $binding, int $now, ?callable $transport = null): string
{
    allegro_validate_config($config, true);
    return allegro_locked($config, static function (array $vault, string $key) use ($config, $binding, $now, $transport): string {
        $now = max($now, time());
        $device = $vault['device'] ?? [];
        if (!is_string($device['binding'] ?? null) || !hash_equals($device['binding'], $binding)) throw new AllegroSafeException('session');
        if (($device['expires'] ?? 0) <= $now) {
            unset($vault['device']);
            allegro_write_vault($config, $key, $vault);
            allegro_log($config, 'device_expired');
            return 'expired';
        }
        if ($now < $device['next_poll_at']) return 'pending';
        // Consume before transport: crash, timeout, 429 or malformed response cannot replay credentials.
        unset($vault['device'], $vault['tokens'], $vault['account']);
        $vault['status'] = 'reconnect';
        allegro_write_vault($config, $key, $vault);
        $data = allegro_http($config, 'POST', '/auth/oauth/token', '',
            ['grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'device_code' => $device['device_code']], $transport);
        $result = $data['device_result'] ?? null;
        if (in_array($result, ['pending', 'slow_down'], true)) {
            if ($result === 'slow_down') {
                // Bounded backoff; at cap stop rather than poll faster than a requested slowdown.
                if ($device['interval'] >= 300) { allegro_log($config, 'device_expired'); return 'expired'; }
                $device['interval'] = min(300, max($device['interval'] + 5, $device['interval'] * 2));
            }
            $device['next_poll_at'] = max($now, time()) + $device['interval'];
            if ($device['expires'] <= max($now, time())) { allegro_log($config, 'device_expired'); return 'expired'; }
            $vault['device'] = $device;
            $vault['status'] = 'disconnected';
            allegro_write_vault($config, $key, $vault);
            allegro_log($config, 'device_pending');
            return 'pending';
        }
        if (in_array($result, ['denied', 'expired'], true)) {
            allegro_log($config, $result === 'denied' ? 'device_denied' : 'device_expired');
            return $result;
        }
        if ($device['expires'] <= max($now, time())) throw new AllegroSafeException('state');
        $tokens = allegro_tokens($data, max($now, time()));
        $account = allegro_identity(allegro_http($config, 'GET', '/me', $tokens['access'], [], $transport));
        $vault['tokens'] = $tokens;
        $vault['account'] = $account;
        $vault['status'] = 'connected';
        unset($vault['error']);
        allegro_write_vault($config, $key, $vault);
        allegro_log($config, 'device_connected', ['sellerId' => $account['id']]);
        return 'connected';
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

function allegro_public_status(array $config, string $binding = ''): array
{
    return allegro_locked($config, static function (array $vault, string $key) use ($config, $binding): array {
        $changed = false;
        foreach (['device'] as $field) {
            if (isset($vault[$field]) && ($vault[$field]['expires'] ?? 0) <= time()) {
                unset($vault[$field]);
                $changed = true;
            }
        }
        if ($changed) allegro_write_vault($config, $key, $vault);
        $device = $vault['device'] ?? [];
        $publicDevice = null;
        if (is_string($device['binding'] ?? null) && hash_equals($device['binding'], $binding)) {
            // Revalidate decrypted public URLs before rendering them.
            allegro_device_response($config, $device + ['expires_in' => 1]);
            $publicDevice = array_intersect_key($device, array_flip(['user_code', 'verification_uri', 'verification_uri_complete', 'expires', 'next_poll_at']));
        }
        return ['device' => $publicDevice, 'environment' => $config['environment'], 'status' => $vault['status'] ?? 'disconnected',
            'account' => isset($vault['account']) ? allegro_identity($vault['account']) : null,
            'expires_at' => $vault['tokens']['expires_at'] ?? null, 'lastCheckedAt' => $vault['lastCheckedAt'] ?? null];
    });
}
