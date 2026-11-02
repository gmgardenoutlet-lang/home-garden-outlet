<?php
declare(strict_types=1);
// Synthetic private configuration only. cURL execution is disabled by the caller.
[$script, $runtime, $private] = $argv;
require $runtime . '/admin/allegro/lib.php';
$checks = 0;
$check = static function (bool $ok) use (&$checks): void {
    if (!$ok) throw new RuntimeException('Device ready assertion failed');
    $checks++;
};
$reject = static function (callable $call, ?string $kind = null) use ($check): void {
    try { $call(); } catch (AllegroSafeException $e) {
        $check($kind === null || $e->kind === $kind);
        return;
    }
    throw new RuntimeException('Device ready accepted invalid configuration');
};
$values = ['ALLEGRO_ENVIRONMENT' => 'PROD', 'ALLEGRO_AUTH_FLOW' => 'DEVICE',
    'ALLEGRO_CLIENT_ID' => bin2hex(random_bytes(16)), 'ALLEGRO_CLIENT_SECRET' => bin2hex(random_bytes(24)),
    'ALLEGRO_TOKEN_KEY' => base64_encode(random_bytes(32)), 'ALLEGRO_STORAGE_DIR' => $private . '/storage',
    'ALLEGRO_OAUTH_ENABLED' => '0'];
$save = static function (array $data) use ($private): void {
    file_put_contents($private . '/config.php', '<?php return ' . var_export($data, true) . ';');
};
$save($values);
// Conflicting, syntactically valid ENV credentials must have no influence.
putenv('ALLEGRO_CLIENT_ID=SYNTHETIC_ENV_ID');
putenv('ALLEGRO_CLIENT_SECRET=SYNTHETIC_ENV_SECRET');
putenv('ALLEGRO_TOKEN_KEY=' . base64_encode(random_bytes(32)));
putenv('ALLEGRO_OAUTH_ENABLED=1'); // Must not override private OFF.
putenv('ALLEGRO_CALLBACK_LOGS_REDACTED=1');
$config = allegro_config();
$check($config['environment'] === 'PROD' && $config['auth_flow'] === 'DEVICE');
$check($config['enabled'] === false && $config['logs_safe'] === false);
foreach (['client_id' => 'ALLEGRO_CLIENT_ID', 'secret' => 'ALLEGRO_CLIENT_SECRET', 'key' => 'ALLEGRO_TOKEN_KEY'] as $field => $name) {
    $check($config[$field] === $values[$name] && $config[$field] !== getenv($name));
}
$calls = 0;
$transport = static function ($request) use (&$calls): array { $calls++; throw new RuntimeException('Network forbidden'); };
$binding = str_repeat('a', 64);
$reject(fn() => allegro_validate_config($config, true), 'disabled');
$reject(fn() => allegro_device_start($config, $binding, time(), $transport), 'disabled');
$reject(fn() => allegro_device_poll($config, $binding, time(), $transport), 'disabled');
$reject(fn() => allegro_diagnose($config, time(), $transport), 'disabled');
$reject(fn() => allegro_access_token($config, time(), $transport), 'disabled');
$reject(fn() => allegro_http($config, 'POST', '/auth/oauth/device', '', [], $transport), 'disabled');
$check($calls === 0 && !is_file($private . '/storage/account.enc'));
$values['ALLEGRO_OAUTH_ENABLED'] = '1';
$save($values);
$enabled = allegro_config();
$check($enabled['enabled'] === true && strlen(allegro_validate_config($enabled, true)) === 32);
foreach (['ALLEGRO_CLIENT_ID', 'ALLEGRO_CLIENT_SECRET', 'ALLEGRO_TOKEN_KEY'] as $name) {
    $missing = $values;
    unset($missing[$name]);
    $save($missing);
    $reject(fn() => allegro_validate_config(allegro_config(), true), 'config');
    $invalid = $values;
    $invalid[$name] = ['not-a-string'];
    $save($invalid);
    $reject(fn() => allegro_config(), 'config');
}
$invalid = $values;
$invalid['ALLEGRO_TOKEN_KEY'] = 'invalid-base64';
$save($invalid);
$reject(fn() => allegro_validate_config(allegro_config(), true), 'config');
$save($values);
// No Device Authorization is started in the enabled case: validation only.
foreach (['AUTHORIZATION_CODE', '', 'device'] as $flow) {
    $reject(fn() => allegro_validate_config(array_replace($enabled, ['auth_flow' => $flow]), true), 'config');
}
foreach (['client_id' => '', 'secret' => '', 'key' => base64_encode(str_repeat('x', 31)),
          'storage_root' => $runtime] as $field => $value) {
    $reject(fn() => allegro_validate_config(array_replace($enabled, [$field => $value]), true), 'config');
}
$reject(fn() => allegro_validate_config(array_replace($enabled, ['key' => 'invalid-base64']), true), 'config');
$reject(fn() => allegro_validate_config(array_replace($enabled, ['storage_root' => $private . '/missing']), true), 'config');
putenv('ALLEGRO_ENVIRONMENT=SANDBOX');
$reject(fn() => allegro_config(), 'config');
putenv('ALLEGRO_ENVIRONMENT');
foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
    foreach (['/sale/offers', '/sale/product-offers', '/sale/offer-publication-commands'] as $path) {
        $reject(fn() => allegro_http($enabled, $method, $path, 'synthetic', [], $transport), 'policy');
    }
}
$check($calls === 0 && ALLEGRO_B_SCOPE === 'allegro:api:profile:read');
$request = allegro_request_spec($enabled, 'POST', '/auth/oauth/device');
parse_str($request['body'], $body);
$check($body['scope'] === ALLEGRO_B_SCOPE && $request['url'] === 'https://allegro.pl/auth/oauth/device');
$reject(fn() => allegro_callback($enabled, 'unused', 'unused', false, time()), 'disabled');
$http = file_get_contents($runtime . '/admin/allegro/http.php');
$check(str_contains($http, 'Home-&-Garden-Outlet-Allegro/1.0.0 (+https://mgoutlet.pl/integracja-allegro/)'));
$check(!str_contains($http, 'HGO-Allegro-Stage-B/1'));
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile()) continue;
    $data = file_get_contents($file->getPathname());
    $check(!str_contains($data, $values['ALLEGRO_CLIENT_SECRET']) && !str_contains($data, $values['ALLEGRO_TOKEN_KEY']));
}
$values['ALLEGRO_OAUTH_ENABLED'] = '0';
$save($values);
echo 'PASS: Device ready private OFF/ON validation, strict PROD/DEVICE, sale/scope/UA, artifact privacy; ' . $checks . " assertions; network calls=0\n";
