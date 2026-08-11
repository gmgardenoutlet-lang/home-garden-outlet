<?php
declare(strict_types=1);

/*
 * Jedyny przełącznik sprzedaży internetowej. Zmiana na true wymaga osobnego
 * przeglądu procesu zamówienia i płatności przed publikacją.
 */
// Sales are opt-in: the shop is never opened merely because the host does not
// provide an environment variable. Only an explicit `true` enables purchases.
define('SHOP_SALES_ENABLED', strtolower(trim((string)(getenv('HGO_SHOP_SALES_ENABLED') ?: ''))) === 'true');

// Paynow V3 is opt-in. Prefer server-side environment variables. On shared
// hosting the optional fallback is a hand-created PHP file one level above
// public_html, never a file deployed with this application.
$hgoPrivateConfig = [];
$hgoPrivateConfigFile = getenv('HGO_PRIVATE_CONFIG_FILE') ?: dirname(__DIR__, 2) . '/.hgo-private/paynow.php';
if (is_file($hgoPrivateConfigFile) && is_readable($hgoPrivateConfigFile)) {
    try {
        $hgoCandidate = require $hgoPrivateConfigFile;
        if (is_array($hgoCandidate)) {
            $hgoPrivateConfig = $hgoCandidate;
        }
    } catch (Throwable $ignored) {
        // Fail closed; never expose configuration-loading details to HTTP clients.
    }
}
function hgo_paynow_config(string $name, string $default = ''): string
{
    global $hgoPrivateConfig;
    $environmentValue = getenv($name);
    if ($environmentValue !== false && $environmentValue !== '') {
        return (string)$environmentValue;
    }
    return isset($hgoPrivateConfig[$name]) && is_scalar($hgoPrivateConfig[$name])
        ? (string)$hgoPrivateConfig[$name]
        : $default;
}

define('PAYNOW_ENABLED', filter_var(hgo_paynow_config('HGO_PAYNOW_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN));
define('PAYNOW_ENV', hgo_paynow_config('HGO_PAYNOW_ENV', 'sandbox'));
define('PAYNOW_API_KEY', hgo_paynow_config('HGO_PAYNOW_API_KEY'));
define('PAYNOW_SIGNATURE_KEY', hgo_paynow_config('HGO_PAYNOW_SIGNATURE_KEY'));
define('SHOP_ALLOWED_COUNTRIES', [
    'PL' => ['name' => 'Polska', 'callingCode' => '48'],
    'DE' => ['name' => 'Niemcy', 'callingCode' => '49'],
    'CZ' => ['name' => 'Czechy', 'callingCode' => '420'],
    'SK' => ['name' => 'Słowacja', 'callingCode' => '421'],
    'LT' => ['name' => 'Litwa', 'callingCode' => '370'],
]);
define('FOREIGN_SHIPPING_ENABLED', filter_var(hgo_paynow_config('HGO_FOREIGN_SHIPPING_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN));
