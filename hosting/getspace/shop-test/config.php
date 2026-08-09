<?php
declare(strict_types=1);

/*
 * Jedyny przełącznik sprzedaży internetowej. Zmiana na true wymaga osobnego
 * przeglądu procesu zamówienia i płatności przed publikacją.
 */
define('SHOP_SALES_ENABLED', filter_var(getenv('HGO_SHOP_SALES_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN));
