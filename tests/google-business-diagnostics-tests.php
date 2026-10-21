<?php
declare(strict_types=1);

// Run with php -n: no real HTTP, session, storage, credentials or publication.
if (extension_loaded('curl')) {
    throw new RuntimeException('Run this isolated test with php -n (without cURL).');
}
foreach (['CURLOPT_RETURNTRANSFER', 'CURLOPT_TIMEOUT', 'CURLOPT_HEADER',
    'CURLINFO_HTTP_CODE', 'CURLOPT_POST', 'CURLOPT_POSTFIELDS', 'CURLOPT_HTTPHEADER'] as $i => $constant) {
    define($constant, $i + 1);
}
$responses = [];
function curl_init($url) { return new stdClass(); }
function curl_setopt_array($handle, $options) { return true; }
function curl_exec($handle) { return $GLOBALS['responses'][0]['body']; }
function curl_getinfo($handle, $option) { return $GLOBALS['responses'][0]['status']; }
function curl_close($handle) { array_shift($GLOBALS['responses']); }

$source = file_get_contents(__DIR__ . '/../hosting/getspace/admin/api/google-business.php');
require __DIR__ . '/../hosting/getspace/admin/google-business-support.php';
$start = strpos($source, 'class GbpRequestException');
$end = strpos($source, 'function gbp_create_media');
eval(substr($source, $start, $end - $start));
$start = strpos($source, 'function gbp_discover_locations');
$end = strpos($source, 'function gbp_rating_number');
eval(substr($source, $start, $end - $start));

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function response(int $status, $data): array {
    return ['status' => $status, 'body' => json_encode($data)];
}
function failure(callable $call, string $expected): void {
    try { $call(); } catch (GbpRequestException $e) {
        check(gbp_public_error($e) === $expected, 'Unexpected safe diagnostic');
        check(!str_contains($e->getMessage(), 'PRIVATE_SENTINEL'), 'Response data leaked');
        return;
    }
    throw new RuntimeException('Expected request failure');
}
$responses = [response(400, ['error' => 'invalid_grant', 'error_description' => 'PRIVATE_SENTINEL'])];
failure(fn() => gbp_access_token(['client_id' => 'fake', 'client_secret' => 'fake', 'refresh_token' => 'fake']),
    'Google: etap=oauth_exchange; HTTP=400; kod=invalid_grant');
$responses = [response(403, ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'PRIVATE_SENTINEL']])];
failure(fn() => gbp_discover_locations([], 'fake'),
    'Google: etap=accounts_list; HTTP=403; kod=PERMISSION_DENIED');
$responses = [response(200, ['accounts' => [['name' => 'accounts/123']]]),
    response(400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'PRIVATE_SENTINEL']])];
failure(fn() => gbp_discover_locations([], 'fake'),
    'Google: etap=locations_list; HTTP=400; kod=INVALID_ARGUMENT');
$responses = [response(400, ['error' => 'PRIVATE_SENTINEL'])];
failure(fn() => gbp_request('https://oauth2.googleapis.com/token', []),
    'Google: etap=oauth_exchange; HTTP=400; kod=http_error');
$responses = [response(200, ['accounts' => [['name' => 'accounts/123']]]),
    response(200, ['locations' => [['name' => 'locations/456', 'title' => 'Test profile']]])];
$locations = gbp_discover_locations([], 'fake');
check(count($locations) === 1 && $locations[0]['accountId'] === '123'
    && $locations[0]['locationId'] === '456', 'Location discovery regression');
check($responses === [], 'Unexpected request count');
echo "Google Business safe diagnostics: PASS (5 cases)\n";
