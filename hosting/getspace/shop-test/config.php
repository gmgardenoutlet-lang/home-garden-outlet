<?php
declare(strict_types=1);

/*
 * Jedyny przełącznik sprzedaży internetowej. Zmiana na true wymaga osobnego
 * przeglądu procesu zamówienia i płatności przed publikacją.
 */
define('SHOP_SALES_ENABLED', filter_var(getenv('HGO_SHOP_SALES_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN));

// Paynow V3 is opt-in and obtains credentials only from the hosting environment.
// Never put either key in this repository or in public assets.
define('PAYNOW_ENABLED', filter_var(getenv('HGO_PAYNOW_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN));
define('PAYNOW_ENV', getenv('HGO_PAYNOW_ENV') ?: 'sandbox');
define('PAYNOW_API_KEY', getenv('HGO_PAYNOW_API_KEY') ?: '');
define('PAYNOW_SIGNATURE_KEY', getenv('HGO_PAYNOW_SIGNATURE_KEY') ?: '');
