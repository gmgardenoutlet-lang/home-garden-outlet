<?php
declare(strict_types=1);
require __DIR__ . '/../hosting/getspace/admin/allegro/lib.php';

$assertions = 0;
function a_check(bool $ok, string $label): void {
    global $assertions;
    $assertions++;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
}
function a_reject(callable $operation, string $label, ?string $kind = null): void {
    try { $operation(); }
    catch (AllegroSafeException $e) {
        a_check($kind === null || $e->kind === $kind, $label . ' safe error kind');
        return;
    }
    throw new RuntimeException('FAIL: accepted ' . $label);
}
function a_remove(string $dir): void {
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $dir . '/' . $name;
        if (is_dir($path) && !is_link($path)) a_remove($path); else unlink($path);
    }
    rmdir($dir);
}

$dir = sys_get_temp_dir() . '/hgo-allegro-unit-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
$config = ['environment' => 'SANDBOX', 'client_id' => bin2hex(random_bytes(16)), 'secret' => bin2hex(random_bytes(24)),
    'redirect_uri' => 'https://sandbox-admin.example.test/admin/allegro/callback', 'storage_root' => $dir,
    'key' => base64_encode(random_bytes(32)), 'logs_safe' => true, 'enabled' => true];
$binding = hash('sha256', random_bytes(32));
$now = time();
$requests = [];
$reply = static fn(array $data, int $status = 200): array => ['status' => $status, 'body' => json_encode($data, JSON_THROW_ON_ERROR)];
$tokenData = static fn(string $suffix): array => ['access_token' => 'fixture-access-' . $suffix, 'refresh_token' => 'fixture-refresh-' . $suffix,
    'token_type' => 'bearer', 'expires_in' => 3600, 'scope' => ALLEGRO_B_SCOPE];
$transport = static function (array $request) use (&$requests, $reply, $tokenData): array {
    $requests[] = $request;
    return str_ends_with($request['url'], '/me') ? $reply(['id' => '123456', 'login' => 'fixture-seller', 'email' => 'do-not-retain@example.test'])
        : $reply($tokenData((string)count($requests)));
};
$start = static function (int $time) use ($config, $binding): array {
    parse_str((string)parse_url(allegro_start($config, $binding, $time), PHP_URL_QUERY), $query);
    return $query;
};
$read = static fn(): array => allegro_locked($config, static fn(array $v): array => $v);
try {
    a_reject(fn() => allegro_start(array_replace($config, ['logs_safe' => false]), $binding, $now), 'unverified logs', 'disabled');
    a_reject(fn() => allegro_start(array_replace($config, ['enabled' => false]), $binding, $now), 'OAuth disabled', 'disabled');
    a_reject(fn() => allegro_start(array_replace($config, ['storage_root' => dirname(__DIR__)]), $binding, $now), 'repository storage', 'config');
    a_reject(fn() => allegro_start(array_replace($config, ['storage_root' => __DIR__ . '/../hosting/getspace']), $binding, $now), 'webroot storage', 'config');
    a_reject(fn() => allegro_start(array_replace($config, ['redirect_uri' => 'http://sandbox-admin.example.test/admin/allegro/callback']), $binding, $now), 'HTTP callback');
    a_reject(fn() => allegro_start(array_replace($config, ['redirect_uri' => $config['redirect_uri'] . '?environment=PROD']), $binding, $now), 'callback query');
    a_reject(fn() => allegro_start(array_replace($config, ['environment' => 'UNKNOWN']), $binding, $now), 'unknown environment');
    a_reject(fn() => allegro_start(array_replace($config, ['redirect_uri' => ALLEGRO_PROD_CALLBACK]), $binding, $now), 'sandbox callback equals production');
    a_check(allegro_public_status($config)['status'] === 'disconnected', 'initial disconnected');
    $q = $start($now);
    a_check(preg_match('/^[a-f0-9]{64}$/D', $q['state']) === 1, 'random state format');
    a_check($q['scope'] === 'allegro:api:profile:read', 'minimal scope, no sales or orders');
    a_check($q['code_challenge_method'] === 'S256' && !isset($q['code_verifier']), 'verifier never in authorize URL');
    $vault = $read();
    $verifier = $vault['state']['verifier'];
    a_check(strlen($verifier) >= 43 && strlen($verifier) <= 128, 'verifier length');
    a_check($q['code_challenge'] === allegro_pkce_challenge($verifier), 'challenge matches persisted verifier');
    a_check(allegro_pkce_challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk') === 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', 'RFC7636 known vector');
    foreach (['', str_repeat('a', 42), str_repeat('a', 129), str_repeat('a', 43) . '+'] as $bad) a_reject(fn() => allegro_pkce_challenge($bad), 'invalid verifier');
    a_reject(fn() => allegro_callback($config, '', 'fixture-code', false, $now), 'missing state');
    a_reject(fn() => allegro_callback($config, str_repeat('f', 64), 'fixture-code', false, $now), 'wrong state');
    $handle = allegro_callback($config, $q['state'], 'fixture-code', false, $now + 1);
    $deadline = $read()['pending']['expires'];
    a_check($deadline >= $now + 9 && $deadline <= max($now + 1, time()) + 8, 'code bridge deadline is callback plus 8 seconds');
    a_check(allegro_pending_ready($config, $handle, $binding, $now + 2), 'same session ready for automatic POST');
    a_check(!allegro_pending_ready($config, $handle, hash('sha256', 'other'), $now + 2), 'other session cannot auto-finish');
    a_check(!allegro_pending_ready($config, $handle, $binding, $deadline), 'automatic bridge expires before code lifetime');
    a_check(!isset($read()['state']) && $requests === [], 'callback consumes state without token HTTP');
    a_reject(fn() => allegro_callback($config, $q['state'], 'fixture-code', false, $now), 'state replay');
    a_reject(fn() => allegro_finish($config, $handle, hash('sha256', 'other'), $now + 2, $transport), 'other session', 'session');
    a_check($requests === [], 'wrong binding has zero network calls');
    allegro_finish($config, $handle, $binding, $now + 2, $transport);
    a_check(count($requests) === 2 && $requests[0]['method'] === 'POST' && $requests[1]['method'] === 'GET', 'exchange then /me');
    parse_str($requests[0]['body'], $grant);
    a_check($grant['code_verifier'] === $verifier && $grant['client_id'] === $config['client_id'], 'PKCE exchange fields');
    a_check(!str_contains(implode('\n', $requests[0]['headers']), 'Authorization') && !isset($grant['client_secret']), 'PKCE exchange without Basic or secret');
    a_check(allegro_public_status($config)['account'] === ['id' => '123456', 'login' => 'fixture-seller'], 'only minimal /me stored');
    a_check(!str_contains(json_encode($read()), 'email'), 'no email retention');
    a_reject(fn() => allegro_finish($config, $handle, $binding, $now + 3, $transport), 'finish replay');
    $before = count($requests);
    $token = allegro_access_token($config, $now + 3, $transport);
    a_check($token === 'fixture-access-1' && count($requests) === $before, 'cached access no request');
    $path = allegro_storage_dir($config) . '/account.enc';
    $cipher = file_get_contents($path);
    foreach ([$token, $verifier, 'fixture-refresh', 'fixture-code', $config['secret'], $binding, $q['state']] as $secret) {
        a_check(!str_contains($cipher, $secret), 'encrypted vault no plaintext');
    }
    a_reject(fn() => allegro_public_status(array_replace($config, ['key' => base64_encode(random_bytes(32))])), 'wrong encryption key', 'storage');
    // An invalid update never replaces the old complete vault.
    a_reject(fn() => allegro_locked($config, function ($v, $key) use ($config) { $v['oversized'] = str_repeat('x', 110000); allegro_write_vault($config, $key, $v); }), 'failed write', 'storage');
    a_check(file_get_contents($path) === $cipher && glob(dirname($path) . '/.allegro-*') === [], 'failed atomic write preserves old file and cleans temps');
    allegro_locked($config, fn($v, $key) => allegro_write_vault($config, $key, $v));
    a_check(file_get_contents($path) !== $cipher && $read()['tokens']['access'] === $token, 'new nonce, equivalent decrypted vault');
    allegro_access_token($config, $now + 3603, $transport);
    $request = $requests[array_key_last($requests)];
    parse_str($request['body'], $refreshGrant);
    a_check($refreshGrant['refresh_token'] === 'fixture-refresh-1', 'old refresh used once');
    a_check(in_array('Authorization: Basic ' . base64_encode($config['client_id'] . ':' . $config['secret']), $request['headers'], true), 'refresh Basic per official documentation');
    a_check($read()['tokens']['refresh'] === 'fixture-refresh-3', 'new refresh durably stored');
    // Environment storage partition + encrypted context stop cross-environment reuse.
    $prod = array_replace($config, ['environment' => 'PROD', 'redirect_uri' => ALLEGRO_PROD_CALLBACK]);
    a_check(allegro_public_status($prod)['status'] === 'disconnected', 'separate environment directory');
    copy($path, allegro_storage_dir($prod) . '/account.enc');
    a_reject(fn() => allegro_public_status($prod), 'copied sandbox vault on production', 'storage');
    unlink(allegro_storage_dir($prod) . '/account.enc');
    a_reject(fn() => allegro_public_status(array_replace($config, ['client_id' => bin2hex(random_bytes(16))])), 'different app identity', 'storage');
    foreach (['POST', 'PATCH', 'PUT', 'DELETE', 'GET'] as $method) {
        foreach (['/sale/product-offers', '/sale/product-proposals', '/sale/images', '/sale/offers/1', 'https://evil.example/me', '/me?x=1'] as $pathBad) {
            $called = false;
            $blockedTransport = function () use (&$called): array { $called = true; return []; };
            a_reject(fn() => allegro_http($config, $method, $pathBad, 'fixture-access', [], $blockedTransport), 'blocked route', 'policy');
            a_check(!$called, 'policy before transport');
        }
    }
    foreach (['https://api.allegro.pl/sale/offers', 'http://api.allegro.pl/me', 'https://api.allegro.pl.evil.test/me', 'https://upload.allegro.pl/sale/images'] as $url) {
        a_reject(fn() => allegro_curl(['method' => 'POST', 'url' => $url]), 'low-level cURL policy', 'policy');
    }
    $q = $start($now);
    $replacement = $start($now);
    a_check($q['state'] !== $replacement['state'], 'unique attempts');
    a_reject(fn() => allegro_callback($config, $q['state'], 'fixture-code', false, $now), 'superseded state');
    foreach (['expired', 'missing-code', 'denied'] as $case) {
        $q = $start($now);
        a_reject(fn() => allegro_callback($config, $q['state'], $case === 'missing-code' ? '' : 'fixture-code', $case === 'denied', $case === 'expired' ? $now + 600 : $now), $case);
        a_reject(fn() => allegro_callback($config, $q['state'], 'fixture-code', false, $now), 'bad callback consumes state');
    }
    $q = $start($now);
    $h = allegro_callback($config, $q['state'], 'fixture-code', false, $now);
    $deadline = $read()['pending']['expires'];
    a_reject(fn() => allegro_finish($config, $h, $binding, $deadline, $transport), '10-second code deadline margin');
    a_reject(fn() => allegro_finish($config, $h, $binding, $now, $transport), 'expired pending consumed');
    $q = $start($now);
    $h = allegro_callback($config, $q['state'], 'fixture-code', false, $now);
    allegro_locked($config, function ($v, $key) use ($config) { $v['pending']['expires'] = time() - 1; allegro_write_vault($config, $key, $v); });
    a_reject(fn() => allegro_finish($config, $h, $binding, $now - 10, $transport), 'old timestamp before lock cannot revive expired code', 'state');
    $q = $start($now);
    $h = allegro_callback($config, $q['state'], 'fixture-code', false, $now);
    allegro_finish($config, $h, $binding, $now, $transport);
    $calls = 0;
    $fail = static function () use (&$calls): array { $calls++; throw new RuntimeException('SENSITIVE_REMOTE_BODY_NEVER_LEAK'); };
    a_reject(fn() => allegro_access_token($config, $now + 3600, $fail), 'ambiguous refresh', 'refresh');
    a_check(!isset($read()['tokens']) && $read()['status'] === 'reconnect', 'fail closed persisted');
    a_reject(fn() => allegro_access_token($config, $now + 3601, $fail), 'no aggressive retry');
    a_check($calls === 1, 'no reused refresh after ambiguity');
    $badToken = $tokenData('bad'); $badToken['scope'] .= ' allegro:api:orders:read';
    a_reject(fn() => allegro_tokens($badToken, $now), 'unexpected scope');
    foreach ([['expires_in' => 0], ['token_type' => []], ['refresh_token' => ''], ['access_token' => "bad\r\nheader"]] as $bad) {
        a_reject(fn() => allegro_tokens(array_replace($tokenData('bad'), $bad), $now), 'malformed token pair');
    }
    a_reject(fn() => allegro_identity(['id' => '123', 'login' => "bad\nlogin"]), 'unsafe identity');
    $q = $start($now - 601);
    allegro_public_status($config);
    a_check(!isset($read()['state']), 'expired encrypted state pruned on status visit');
    $q = $start($now);
    $h = allegro_callback($config, $q['state'], 'fixture-code', false, $now);
    $exchangeCalls = 0;
    $exchangeFailure = static function () use (&$exchangeCalls): array { $exchangeCalls++; throw new RuntimeException('SENSITIVE_REMOTE_BODY_NEVER_LEAK'); };
    a_reject(fn() => allegro_finish($config, $h, $binding, $now, $exchangeFailure), 'failed code exchange', 'network');
    a_reject(fn() => allegro_finish($config, $h, $binding, $now, $exchangeFailure), 'failed code exchange cannot replay', 'session');
    a_check($exchangeCalls === 1 && !isset($read()['tokens']), 'ambiguous exchange remains fail closed');
    $q = $start($now);
    $h = allegro_callback($config, $q['state'], 'fixture-code', false, $now);
    allegro_finish($config, $h, $binding, $now, $transport);
    allegro_diagnose($config, $now + 3, $transport);
    a_check(allegro_public_status($config)['lastCheckedAt'] === gmdate('c', $now + 3), 'diagnostics records successful /me read');
    a_reject(fn() => allegro_diagnose($config, $now + 4, $fail), 'account read transport failure', 'network');
    a_check($read()['status'] === 'connected' && isset($read()['tokens']), 'read network error does not silently disconnect');
    a_reject(fn() => allegro_diagnose($config, $now + 5, fn() => $reply(['id' => '999999', 'login' => 'other-seller'])), 'identity mismatch', 'identity');
    a_check($read()['status'] === 'reconnect' && !isset($read()['tokens']), 'identity mismatch disables access');
    $httpCalls = 0;
    $rateLimited = function () use (&$httpCalls, $reply): array {
        $httpCalls++;
        return $reply(['errors' => [['message' => 'SENSITIVE_REMOTE_BODY_NEVER_LEAK']]], 429);
    };
    a_reject(fn() => allegro_http($config, 'GET', '/me', 'fixture-access', [], $rateLimited), 'rate limit without retries', 'network');
    a_check($httpCalls === 1, 'no automatic HTTP retry');
    allegro_log($config, 'http', ['errorCode' => 'access_token=SECRET_MARKER', 'state' => 'SECRET_MARKER', 'code_verifier' => 'SECRET_MARKER', 'sellerId' => 'SECRET_MARKER', 'Authorization' => 'SECRET_MARKER']);
    $logs = file_get_contents(allegro_storage_dir($config) . '/technical.jsonl');
    foreach (['SECRET_MARKER', 'fixture-access', 'fixture-refresh', 'fixture-code', $binding, $verifier, $config['secret'], 'SENSITIVE_REMOTE_BODY_NEVER_LEAK'] as $secret) a_check(!str_contains($logs, $secret), 'no secret or raw remote body in logs');
    foreach (explode("\n", trim($logs)) as $line) {
        $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        a_check(array_diff(array_keys($record), ['timestamp', 'operation', 'environment', 'correlationId', 'errorCode', 'httpStatus', 'sellerId']) === [], 'log fields allowlist');
    }
    $q = $start($now);
    allegro_disconnect(array_replace($config, ['secret' => '', 'logs_safe' => false, 'enabled' => false]));
    a_check(allegro_public_status($config)['status'] === 'disconnected' && !isset($read()['tokens'], $read()['pending'], $read()['state']), 'disconnect without OAuth readiness erases secrets');
    a_reject(fn() => allegro_callback($config, $q['state'], 'fixture-code', false, $now), 'disconnect cancels pending');
    $snapshot = json_encode(allegro_public_status($config));
    a_check(!str_contains($snapshot, 'token') && !str_contains($snapshot, $config['secret']), 'status has no credentials');
    echo "PASS: Allegro OAuth/PKCE/vault/policy/logs — {$assertions} assertions\n";
} finally { a_remove($dir); }
