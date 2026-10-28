<?php
declare(strict_types=1);

/** Defence in depth: enforced both before an injected transport and in real cURL. */
function allegro_http_policy(string $method, string $url): void
{
    $allowed = [];
    foreach (['PROD', 'SANDBOX'] as $environment) {
        $hosts = allegro_hosts($environment);
        $allowed[] = ['POST', $hosts['auth'] . '/auth/oauth/token'];
        $allowed[] = ['GET', $hosts['api'] . '/me'];
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
    } elseif ($method === 'POST' && $path === '/auth/oauth/token' && $token === '') {
        $type = $grant['grant_type'] ?? '';
        $headers = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'];
        if ($type === 'authorization_code' && count($grant) === 3 && isset($grant['code'], $grant['code_verifier'])
            && is_string($grant['code']) && $grant['code'] !== '' && strlen($grant['code']) <= 4096
            && !preg_match('/[\x00-\x20\x7f]/', $grant['code'])
            && is_string($grant['code_verifier']) && preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $grant['code_verifier'])) {
            // Allegro's current PKCE flow uses client_id and verifier instead of Basic.
            $fields = $grant + ['client_id' => $config['client_id'], 'redirect_uri' => $config['redirect_uri']];
        } elseif ($type === 'refresh_token' && count($grant) === 2 && is_string($grant['refresh_token'] ?? null)
            && $grant['refresh_token'] !== '' && strlen($grant['refresh_token']) <= 32768
            && !preg_match('/[\x00-\x20\x7f]/', $grant['refresh_token'])) {
            $fields = $grant;
            $headers[] = 'Authorization: Basic ' . base64_encode($config['client_id'] . ':' . $config['secret']);
        } else { throw new AllegroSafeException('policy'); }
        $request = ['method' => 'POST', 'url' => $hosts['auth'] . '/auth/oauth/token', 'headers' => $headers,
            'body' => http_build_query($fields, '', '&', PHP_QUERY_RFC3986)];
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
    $body = '';
    try {
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $request['method'], CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $request['headers'], CURLOPT_USERAGENT => 'HGO-Allegro-Stage-B/1',
            CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 131072) return 0;
                $body .= $chunk;
                return strlen($chunk);
            }]);
        if ($request['method'] === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $request['body']);
        if (curl_exec($ch) === false) throw new AllegroSafeException('network');
        return ['status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body];
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
        if (in_array($status, [400, 401, 403], true)) throw new AllegroSafeException('auth');
        if ($status !== 200 || !is_string($response['body'] ?? null) || strlen($response['body']) > 131072) {
            throw new AllegroSafeException('network');
        }
        $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new AllegroSafeException('network');
    } catch (Throwable $e) {
        $kind = $e instanceof AllegroSafeException ? $e->kind : 'network';
        allegro_log($config, 'http', ['httpStatus' => $status, 'errorCode' => $kind]);
        throw new AllegroSafeException($kind);
    }
    allegro_log($config, 'http', ['httpStatus' => $status]);
    return $data;
}
