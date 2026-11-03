<?php
declare(strict_types=1);

const ALLEGRO_PILOT_SCOPES = ['allegro:api:profile:read', 'allegro:api:sale:offers:read',
    'allegro:api:sale:offers:write', 'allegro:api:sale:settings:read'];
function allegro_scope_request(): string { return implode(' ', ALLEGRO_PILOT_SCOPES); }
function allegro_scope_matches(string $scope, bool $legacy = false): bool
{
    $actual = preg_split('/\s+/', trim($scope));
    $expected = $legacy ? [ALLEGRO_B_SCOPE] : ALLEGRO_PILOT_SCOPES;
    sort($actual); sort($expected); return $actual === $expected;
}
const ALLEGRO_PILOT_CATEGORY_ID_PATTERN = '(?:[0-9]{1,32}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})';
/** Narrow pilot allowlist; never orders, payments or bulk endpoints. */
function allegro_pilot_path(string $method, string $path): bool
{
    if ($method === 'POST') return $path === '/sale/product-offers';
    if ($method === 'PATCH') return preg_match('~^/sale/product-offers/[0-9]{1,32}$~D', $path) === 1;
    if ($method !== 'GET') return false;
    if (in_array($path, ['/sale/categories','/sale/shipping-rates','/after-sales-service-conditions/return-policies',
        '/after-sales-service-conditions/implied-warranties','/sale/responsible-producers','/sale/responsible-persons'], true)) return true;
    return preg_match('~^/sale/categories(?:\?parent\.id='.ALLEGRO_PILOT_CATEGORY_ID_PATTERN.'|/'.ALLEGRO_PILOT_CATEGORY_ID_PATTERN.'(?:/parameters)?)$~D', $path) === 1
        || preg_match('~^/sale/product-offers/[0-9]{1,32}(?:/operations/[A-Za-z0-9-]{1,80})?$~D', $path) === 1
        || preg_match('~^/sale/offers\?external\.id=prd_[a-f0-9]{32}$~D', $path) === 1;
}

/** Only allowlisted pilot GETs may receive a larger public API response. */
function allegro_pilot_response_limit(string $method, string $path): int
{
    return $method==='GET' && allegro_pilot_path($method,$path) ? 1048576 : 131072;
}
final class AllegroPilotResponseLimitException extends RuntimeException {}

/** Defence in depth: enforced both before an injected transport and in real cURL. */
function allegro_http_policy(string $method, string $url): void
{
    $allowed = [];
    foreach (['PROD', 'SANDBOX'] as $environment) {
        $hosts = allegro_hosts($environment);
        $allowed[] = ['POST', $hosts['auth'] . '/auth/oauth/device'];
        $allowed[] = ['POST', $hosts['auth'] . '/auth/oauth/token'];
        $allowed[] = ['GET', $hosts['api'] . '/me'];
    }
    foreach (['PROD','SANDBOX'] as $environment) {
        $prefix = allegro_hosts($environment)['api'];
        if (str_starts_with($url, $prefix . '/') && allegro_pilot_path($method, substr($url, strlen($prefix)))) return;
    }
    if (!in_array([$method, $url], $allowed, true)) throw new AllegroSafeException('policy');
}

function allegro_request_spec(array $config, string $method, string $path, string $token = '', array $grant = []): array
{
    $hosts = allegro_hosts($config['environment'] ?? '');
    if ($method === 'GET' && $path === '/me' && $grant === [] && $token !== ''
        && strlen($token) <= 32768 && !preg_match('/[\x00-\x20\x7f]/', $token)) {
        $request = ['method' => 'GET', 'url' => $hosts['api'] . '/me', 'body' => '',
            'headers' => ['Accept: application/vnd.allegro.public.v1+json', 'Accept-Language: pl-PL', 'Authorization: Bearer ' . $token]];
    } elseif ($method === 'POST' && $path === '/auth/oauth/device' && $token === '' && $grant === []) {
        $request = ['method' => 'POST', 'url' => $hosts['auth'] . $path,
            'headers' => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($config['client_id'] . ':' . $config['secret'])],
            'body' => http_build_query(['client_id' => $config['client_id'], 'scope' => allegro_scope_request()], '', '&', PHP_QUERY_RFC3986)];
    } elseif ($method === 'POST' && $path === '/auth/oauth/token' && $token === '') {
        $type = $grant['grant_type'] ?? '';
        $field = $type === 'refresh_token' ? 'refresh_token' : ($type === 'urn:ietf:params:oauth:grant-type:device_code' ? 'device_code' : '');
        if ($field === '' || count($grant) !== 2 || !is_string($grant[$field] ?? null)
            || $grant[$field] === '' || strlen($grant[$field]) > ($field === 'device_code' ? 4096 : 32768)
            || preg_match('/[\x00-\x20\x7f]/', $grant[$field])) throw new AllegroSafeException('policy');
        $request = ['method' => 'POST', 'url' => $hosts['auth'] . $path,
            'headers' => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($config['client_id'] . ':' . $config['secret'])],
            'body' => http_build_query($grant, '', '&', PHP_QUERY_RFC3986)];
    } else { throw new AllegroSafeException('policy'); }
    allegro_http_policy($request['method'], $request['url']);
    return $request;
}

/** No redirects, no arbitrary host/path, no verbose/debug logging, bounded response in memory. */
function allegro_curl(array $request): array
{
    allegro_http_policy($request['method'] ?? '', $request['url'] ?? '');
    if (!function_exists('curl_init')) throw new AllegroSafeException('config');
    $ch = curl_init($request['url']);
    if ($ch === false) throw new AllegroSafeException('network');
    $limit=131072;
    foreach (['PROD','SANDBOX'] as $environment) {
        $prefix=allegro_hosts($environment)['api'];
        if (str_starts_with($request['url'],$prefix.'/')) {
            $limit=allegro_pilot_response_limit($request['method'],substr($request['url'],strlen($prefix)));
        }
    }
    $body = ''; $headers = []; $overLimit=false;
    try {
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $request['method'], CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $request['headers'], CURLOPT_USERAGENT => 'Home-&-Garden-Outlet-Allegro/1.0.0 (+https://mgoutlet.pl/integracja-allegro/)',
            CURLOPT_HEADERFUNCTION => static function ($unused, string $line) use (&$headers): int {
                if (preg_match('/^(Location|Retry-After):\s*(.*?)\s*$/i', trim($line), $m)) $headers[strtolower($m[1])] = $m[2];
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$body,$limit,&$overLimit): int {
                if (strlen($body) + strlen($chunk) > $limit) {$overLimit=true;return 0;}
                $body .= $chunk;
                return strlen($chunk);
            }]);
        if (in_array($request['method'], ['POST','PATCH'], true)) curl_setopt($ch, CURLOPT_POSTFIELDS, $request['body']);
        if (curl_exec($ch) === false) {
            if ($overLimit && $limit===1048576) throw new AllegroPilotResponseLimitException();
            throw new AllegroSafeException('network');
        }
        return ['status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body, 'headers' => $headers];
    } finally { curl_close($ch); }
}

function allegro_http(array $config, string $method, string $path, string $token = '', array $grant = [], ?callable $transport = null): array
{
    // Policy runs before configuration/transport: no stage-B caller can mutate sales resources.
    $request = allegro_request_spec($config, $method, $path, $token, $grant);
    allegro_validate_config($config, true);
    $status = 0;
    try {
        $response = ($transport ?? 'allegro_curl')($request);
        $status = is_int($response['status'] ?? null) ? $response['status'] : 0;
        if (!is_string($response['body'] ?? null) || strlen($response['body']) > 131072) throw new AllegroSafeException('network');
        $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new AllegroSafeException('network');
        if (array_key_exists('device_result', $data)) throw new AllegroSafeException('auth');
        if ($status === 400 && ($grant['grant_type'] ?? '') === 'urn:ietf:params:oauth:grant-type:device_code') {
            $codes = ['authorization_pending' => 'pending', 'slow_down' => 'slow_down', 'access_denied' => 'denied',
                'Invalid device code' => 'expired', 'invalid_grant' => 'expired', 'expired_token' => 'expired'];
            return ['device_result' => $codes[$data['error'] ?? ''] ?? 'expired'];
        }
        if (in_array($status, [400, 401, 403], true)) throw new AllegroSafeException('auth');
        if ($status !== 200) throw new AllegroSafeException('network');
    } catch (Throwable $e) {
        $kind = $e instanceof AllegroSafeException ? $e->kind : 'network';
        allegro_log($config, 'http', ['httpStatus' => $status, 'errorCode' => $kind]);
        throw new AllegroSafeException($kind);
    }
    allegro_log($config, 'http', ['httpStatus' => $status]);
    return $data;
}
