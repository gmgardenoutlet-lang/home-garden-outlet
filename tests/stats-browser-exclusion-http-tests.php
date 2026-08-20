<?php
declare(strict_types=1);

function stats_cookie_http_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function stats_cookie_http_request(string $url): array
{
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
    file_get_contents($url, false, $context);
    return $http_response_header ?? [];
}

$fixtureDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hgo-stats-cookie-' . bin2hex(random_bytes(8));
$port = random_int(20000, 45000);
mkdir($fixtureDir, 0700, true);
$library = var_export(realpath(__DIR__ . '/../hosting/getspace/lib/stats-exclusion.php'), true);
file_put_contents($fixtureDir . DIRECTORY_SEPARATOR . 'index.php', "<?php require {$library}; set_stats_browser_excluded((string)(\$_GET['exclude'] ?? '') === '1'); http_response_code(204);");

$pipes = [];
$process = proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($fixtureDir),
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);

try {
    stats_cookie_http_assert(is_resource($process), 'Nie udało się uruchomić serwera testowego PHP.');
    $setHeaders = [];
    for ($attempt = 0; $attempt < 20 && $setHeaders === []; $attempt++) {
        usleep(100000);
        $setHeaders = stats_cookie_http_request('http://127.0.0.1:' . $port . '/?exclude=1');
    }
    $setCookie = implode("\n", array_filter($setHeaders, static fn(string $header): bool => stripos($header, 'Set-Cookie:') === 0));
    stats_cookie_http_assert(stripos($setCookie, 'hgo_stats_excluded=1') !== false, 'Wykluczenie nie wysyła hgo_stats_excluded=1 w Set-Cookie.');
    stats_cookie_http_assert(stripos($setCookie, 'Path=/') !== false && stripos($setCookie, 'HttpOnly') !== false && stripos($setCookie, 'SameSite=Lax') !== false, 'Cookie wykluczenia ma nieprawidłowe atrybuty.');
    stats_cookie_http_assert(stripos($setCookie, 'Domain=') === false, 'Nowe cookie powinno być host-only.');

    $clearHeaders = stats_cookie_http_request('http://127.0.0.1:' . $port . '/?exclude=0');
    $clearCookie = implode("\n", array_filter($clearHeaders, static fn(string $header): bool => stripos($header, 'Set-Cookie:') === 0));
    stats_cookie_http_assert(stripos($clearCookie, 'hgo_stats_excluded=deleted') !== false || stripos($clearCookie, 'hgo_stats_excluded=;') !== false, 'Ponowne liczenie nie usuwa cookie.');
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($process);
    }
    @unlink($fixtureDir . DIRECTORY_SEPARATOR . 'index.php');
    @rmdir($fixtureDir);
}

echo "PASS: browser exclusion HTTP cookie tests\n";
