<?php
declare(strict_types=1);

/*
 * Jedyny przełącznik sprzedaży internetowej. Zmiana na true wymaga osobnego
 * przeglądu procesu zamówienia i płatności przed publikacją.
 */
define('SHOP_SALES_ENABLED', filter_var(getenv('HGO_SHOP_SALES_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN));

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
