<?php
declare(strict_types=1);
require __DIR__ . '/../hosting/getspace/admin/olx/lib.php';
require __DIR__ . '/../hosting/getspace/admin/product-identity.php';
function olx_assert(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL: ' . $label); }
function olx_reject(callable $operation, string $label): void {
    try { $operation(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('FAIL: accepted ' . $label);
}
$dir = sys_get_temp_dir() . '/hgo-olx-test-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
$config = ['client_id' => '203399', 'secret' => bin2hex(random_bytes(20)),
    'redirect_uri' => OLX_REDIRECT_URI, 'key' => base64_encode(random_bytes(32)), 'dir' => $dir, 'logs_safe' => true];
$binding = hash('sha256', random_bytes(32));
$now = 1800000000;
$requests = [];
$transport = static function (array $config, array $grant) use (&$requests): array {
    $requests[] = $grant;
    return ['access_token' => 'fixture-access-' . count($requests), 'refresh_token' => 'fixture-refresh-' . count($requests),
        'expires_in' => 3600, 'token_type' => 'bearer', 'scope' => 'v2 read write'];
};
$start = static function (int $time) use ($config, $binding): string {
    $url = olx_start($config, $binding, $time);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    olx_assert($query['scope'] === OLX_SCOPE && $query['redirect_uri'] === OLX_REDIRECT_URI, 'OAuth URL');
    return $query['state'];
};
try {
    olx_reject(fn() => olx_start(array_replace($config, ['logs_safe' => false]), $binding, $now), 'unverified hosting logs');
    olx_reject(fn() => olx_start(array_replace($config, ['dir' => dirname(__DIR__)]), $binding, $now), 'public/repository storage');
    olx_assert(olx_status($config) === 'Niepołączone', 'initial status');
    $state = $start($now);
    olx_reject(fn() => olx_callback($config, '', 'fixture-code', false, $now), 'missing state');
    olx_reject(fn() => olx_callback($config, str_repeat('a', 64), 'fixture-code', false, $now), 'wrong state');
    $handle = olx_callback($config, $state, 'fixture-code', false, $now + 1);
    olx_assert($requests === [], 'callback never exchanges before admin verification');
    olx_reject(fn() => olx_callback($config, $state, 'fixture-code', false, $now + 1), 'state replay');
    olx_reject(fn() => olx_finish($config, $handle, 'wrong-session', $now + 2, $transport), 'other admin session');
    olx_assert($requests === [], 'wrong session never reaches token endpoint');
    olx_finish($config, $handle, $binding, $now + 2, $transport);
    olx_assert(count($requests) === 1 && $requests[0]['grant_type'] === 'authorization_code', 'single code exchange');
    olx_assert(olx_status($config) === 'Połączone', 'connected');
    olx_reject(fn() => olx_finish($config, $handle, $binding, $now + 3, $transport), 'finish replay');
    olx_assert(olx_access_token($config, $now + 3, $transport) === 'fixture-access-1' && count($requests) === 1, 'valid token cached');
    olx_assert(olx_access_token($config, $now + 3600, $transport) === 'fixture-access-2', 'expired token refresh');
    olx_assert($requests[1]['refresh_token'] === 'fixture-refresh-1', 'refresh token sent');
    olx_access_token($config, $now + 7200, $transport);
    olx_assert($requests[2]['refresh_token'] === 'fixture-refresh-2', 'rotated refresh persisted');
    $ciphertext = file_get_contents($dir . '/account.enc');
    foreach (['fixture-access', 'fixture-refresh', 'fixture-code', $config['secret']] as $value) {
        olx_assert(!str_contains($ciphertext, $value), 'no plaintext secrets');
    }
    $state = $start($now);
    olx_reject(fn() => olx_callback($config, $state, 'fixture-code', false, $now + 600), 'expired state');
    olx_reject(fn() => olx_callback($config, $state, 'fixture-code', false, $now), 'expired state consumed');
    $state = $start($now);
    olx_reject(fn() => olx_callback($config, $state, '', true, $now), 'denied OAuth');
    olx_reject(fn() => olx_callback($config, $state, 'fixture-code', false, $now), 'denied state consumed');
    $state = $start($now);
    $handle = olx_callback($config, $state, 'fixture-code', false, $now);
    olx_reject(fn() => olx_finish($config, $handle, $binding, $now + 121, $transport), 'expired pending code');
    $state = $start($now);
    $replacement = $start($now);
    olx_reject(fn() => olx_callback($config, $state, 'fixture-code', false, $now), 'replaced attempt');
    olx_disconnect($config);
    olx_reject(fn() => olx_callback($config, $replacement, 'fixture-code', false, $now), 'disconnect cancels pending state');
    olx_assert(olx_status($config) === 'Niepołączone', 'disconnected');
    olx_reject(fn() => olx_access_token($config, $now, $transport), 'disconnected token');
    $state = $start($now);
    $handle = olx_callback($config, $state, 'fixture-code', false, $now);
    olx_finish($config, $handle, $binding, $now, $transport);
    $fail = static function (): array { throw new RuntimeException('DO_NOT_LEAK_REMOTE_RESPONSE'); };
    olx_reject(fn() => olx_access_token($config, $now + 3600, $fail), 'refresh failure');
    olx_assert(olx_status($config) === 'Wymaga ponownego połączenia', 'failed refresh status');
    $count = count($requests);
    olx_reject(fn() => olx_access_token($config, $now + 3601, $transport), 'no retry after ambiguous refresh');
    olx_assert(count($requests) === $count, 'no reused refresh');
    olx_reject(fn() => olx_tokens(['access_token' => 'fixture', 'refresh_token' => 'fixture', 'scope' => 'read', 'expires_in' => 3600, 'token_type' => 'bearer'], $now), 'missing scopes');
    olx_reject(fn() => olx_status(array_replace($config, ['key' => base64_encode(random_bytes(32))])), 'wrong encryption key');
    // Identity migration on synthetic records only; no real product file is opened.
    $catalog = ['meta' => ['version' => 1], 'products' => [
        ['name' => 'Sofa', 'slug' => 'sofa', 'outletPrice' => '100', 'internalNote' => 'private'],
        ['name' => 'Fotel', 'slug' => 'fotel', 'gallery' => [['image' => 'uploads/test.jpg']]],
    ]];
    $prepared = product_ids_prepare($catalog);
    olx_assert($prepared['products'][0]['productId'] !== $prepared['products'][1]['productId'], 'unique IDs');
    olx_assert(product_id_valid($prepared['products'][0]['productId']), 'ID format');
    $stripped = $prepared;
    foreach ($stripped['products'] as &$p) unset($p['productId']);
    unset($p);
    olx_assert($stripped === $catalog, 'all original fields preserved');
    olx_assert(product_ids_prepare($prepared) === $prepared, 'migration idempotent');
    $id = $prepared['products'][0]['productId'];
    $prepared['products'][0]['name'] = 'Changed';
    $prepared['products'][0]['slug'] = 'changed';
    $prepared['products'][0]['category'] = 'Other';
    $prepared['products'] = array_reverse($prepared['products']);
    olx_assert(product_ids_prepare($prepared)['products'][1]['productId'] === $id, 'ID survives changes and reorder');
    $prepared['products'][0]['productId'] = $id;
    olx_reject(fn() => product_ids_prepare($prepared), 'duplicate existing ID');
    olx_assert(product_id_valid(product_id_new($catalog['products'])), 'new product ID');
    echo "PASS: OLX OAuth, encrypted storage, rotation, replay protection, configuration gates and product identity\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) unlink($file);
    rmdir($dir);
}
