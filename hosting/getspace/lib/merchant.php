<?php
declare(strict_types=1);

require_once __DIR__ . '/../shop-test/lib.php';

function merchant_length(string $value): int
{
    return preg_match_all('/./us', $value, $matches) === false ? PHP_INT_MAX : count($matches[0]);
}

function merchant_condition(array $product): string
{
    $explicit = (string)($product['merchantCondition'] ?? '');
    if (in_array($explicit, ['new', 'used', 'refurbished'], true)) return $explicit;
    return ($product['condition'] ?? '') === 'Nowy' ? 'new' : '';
}

function merchant_https_url(string $value): string
{
    $value = trim($value);
    if ($value === '' || str_contains($value, '..') || preg_match('/[\x00-\x20]/', $value)) return '';
    if (str_starts_with($value, '/uploads/') || str_starts_with($value, 'uploads/')) {
        $value = 'https://mgoutlet.pl/' . ltrim($value, '/');
    }
    $parts = parse_url($value);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
        && !isset($parts['user']) && !isset($parts['pass']) ? $value : '';
}

function merchant_gtin_valid(string $value): bool
{
    if (!preg_match('/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/', $value)) return false;
    $sum = 0;
    $weight = 3;
    for ($i = strlen($value) - 2; $i >= 0; $i--) {
        $sum += (int)$value[$i] * $weight;
        $weight = $weight === 3 ? 1 : 3;
    }
    return (10 - $sum % 10) % 10 === (int)substr($value, -1);
}

/** One evaluator shared by the public feed and the authenticated admin diagnostics. */
function merchant_evaluate(array $product, ?array $methods = null): array
{
    $errors = [];
    if (!catalog_is_figure_shop_product($product)) $errors[] = 'Mebel / sprzedaż lokalna — poza kanałem online.';
    if (empty($product['merchantEnabled'])) $errors[] = 'Publikacja w Google wyłączona.';
    if (!shop_sales_enabled()) $errors[] = 'Sprzedaż internetowa wyłączona.';
    if (empty($product['shopVisible']) || ($product['shopStatus'] ?? '') !== 'Dostępny'
        || catalog_normalize((string)($product['productStatus'] ?? '')) === 'ukryty') {
        $errors[] = 'Produkt ukryty lub wyłączony w sklepie.';
    }
    $id = trim((string)($product['merchantId'] ?? ''));
    if (!preg_match('/^fig-[a-f0-9]{32}$/', $id)) $errors[] = 'Brak stałego ID Google — zapisz produkt.';
    $slug = trim((string)($product['slug'] ?? ''));
    if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) $errors[] = 'Brak prawidłowego adresu produktu.';
    $name = trim((string)($product['name'] ?? ''));
    $description = trim(strip_tags((string)($product['description'] ?? '')));
    if ($name === '' || merchant_length($name) > 150) $errors[] = 'Nazwa musi mieć od 1 do 150 znaków.';
    if ($description === '' || merchant_length($description) > 5000) $errors[] = 'Opis musi mieć od 1 do 5000 znaków.';
    $image = merchant_https_url((string)($product['image'] ?? ''));
    if ($image === '') $errors[] = 'Brak zdjęcia produktu pod adresem HTTPS (bez zastępczego zdjęcia).';
    $price = shop_test_price_number($product['grossPrice'] ?? '');
    if ($price === null) $errors[] = 'Brak dodatniej ceny brutto.';
    if (($product['currency'] ?? '') !== 'PLN') $errors[] = 'Cena musi być w PLN.';
    $condition = merchant_condition($product);
    if ($condition === '') $errors[] = 'Potwierdź stan Google: nowy, używany lub odnowiony.';
    $identifierMode = (string)($product['merchantIdentifiers'] ?? '');
    $gtin = trim((string)($product['gtin'] ?? ''));
    $mpn = trim((string)($product['mpn'] ?? ''));
    $brand = catalog_confirmed_brand($product);
    if ($gtin !== '' && !merchant_gtin_valid($gtin)) $errors[] = 'Nieprawidłowy GTIN (długość lub suma kontrolna).';
    if ($identifierMode === 'none') {
        if ($gtin !== '' || $mpn !== '' || $brand !== '') $errors[] = 'Brak identyfikatorów koliduje z marką, GTIN lub MPN.';
    } elseif ($identifierMode === 'assigned') {
        if ($brand === '' || ($gtin === '' && $mpn === '')) $errors[] = 'Podaj markę oraz GTIN lub MPN producenta.';
    } else {
        $errors[] = 'Potwierdź, czy producent nadał identyfikatory.';
    }
    if (!in_array((string)($product['merchantContentSource'] ?? ''), ['human', 'ai'], true)) {
        $errors[] = 'Potwierdź pochodzenie nazwy i opisu (własne lub przygotowane przez AI).';
    }
    if (($product['merchantSupplyModel'] ?? '') !== 'supplier') {
        $errors[] = 'Potwierdź sprzedaż z wysyłką od producenta; własny magazyn wymaga obsługi ilości.';
    }
    if (trim((string)($product['leadTime'] ?? '')) === '') $errors[] = 'Brak potwierdzonego czasu realizacji.';
    $methods ??= shop_test_delivery_methods($product);
    $shipping = [];
    foreach ($methods as $method) {
        if (($method['type'] ?? '') === 'odbior_osobisty' || !empty($method['requiresConfirmation'])
            || !empty($method['priceFrom']) || !is_numeric($method['costNumber'] ?? null)
            || (float)$method['costNumber'] < 0) continue;
        $shipping[] = ['country' => 'PL', 'service' => (string)$method['label'],
            'price' => number_format((float)$method['costNumber'], 2, '.', '') . ' PLN'];
    }
    if (!$shipping) $errors[] = 'Brak dostawy w Polsce z ustaloną ceną, bez indywidualnej wyceny.';
    $sold = in_array(catalog_normalize((string)($product['productStatus'] ?? '')), ['sprzedany', 'rezerwacja'], true)
        || in_array(catalog_normalize((string)($product['status'] ?? '')), ['sprzedany', 'sprzedane', 'rezerwacja'], true);
    $supplierUnavailable = ($product['producerAvailability'] ?? '') !== 'Dostępny u producenta';
    $item = ['id' => $id, 'title' => $name, 'description' => $description,
        'link' => 'https://mgoutlet.pl' . shop_test_product_url($slug), 'image_link' => $image,
        'price' => $price === null ? '' : number_format($price, 2, '.', '') . ' PLN',
        'availability' => $sold || $supplierUnavailable ? 'out_of_stock' : 'in_stock', 'condition' => $condition];
    if ($identifierMode === 'none') $item['identifier_exists'] = 'false';
    foreach (['brand' => $brand, 'gtin' => $gtin, 'mpn' => $mpn, 'color' => trim((string)($product['color'] ?? ''))] as $key => $value) {
        if ($value !== '') $item[$key] = $value;
    }
    $group = trim((string)($product['merchantGroupId'] ?? ''));
    if ($group !== '') {
        if (merchant_length($group) > 50 || trim((string)($product['color'] ?? '')) === '') {
            $errors[] = 'Grupa wariantów wymaga ID do 50 znaków i rzeczywistego koloru.';
        }
        $item['item_group_id'] = $group;
    }
    return ['errors' => $errors, 'item' => $item, 'shipping' => $shipping,
        'ai' => ($product['merchantContentSource'] ?? '') === 'ai'];
}

function merchant_catalog_report(array $products): array
{
    $report = [];
    $counts = [];
    foreach ($products as $product) {
        if (!is_array($product) || !catalog_is_figure_shop_product($product)) continue;
        $evaluation = merchant_evaluate($product);
        $report[] = ['name' => (string)($product['name'] ?? ''), 'slug' => (string)($product['slug'] ?? '')] + $evaluation;
        $id = $evaluation['item']['id'];
        $counts[$id] = ($counts[$id] ?? 0) + 1;
    }
    foreach ($report as &$row) {
        if ($row['item']['id'] !== '' && $counts[$row['item']['id']] > 1) $row['errors'][] = 'Powielone ID Google.';
    }
    unset($row);
    return $report;
}

/** Explicit administrator action, never run on a public read or on future products. */
function merchant_prepare_figures(array $catalog, string $scope, string $contentSource): array
{
    if (!in_array($scope, ['pilot', 'all'], true) || !in_array($contentSource, ['human','ai'], true)) {
        throw new RuntimeException('Wybierz zakres i pochodzenie treści.');
    }
    $prepared = 0;
    foreach ($catalog['products'] as &$product) {
        if (!is_array($product) || !catalog_is_figure_shop_product($product)) continue;
        if ($scope === 'pilot' && $prepared >= 3) continue;
        $product['merchantId'] = !empty($product['merchantId']) ? $product['merchantId'] : 'fig-' . bin2hex(random_bytes(16));
        $product['merchantCondition'] = 'new';
        $product['condition'] = 'Nowy';
        $product['merchantIdentifiers'] = 'none';
        $product['merchantSupplyModel'] = 'supplier';
        $product['merchantContentSource'] = $contentSource;
        $product['merchantEnabled'] = true;
        $prepared++;
    }
    unset($product);
    return $catalog;
}

function merchant_xml_value(string $value): string
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function merchant_xml(array $report): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'
        . '<title>Home &amp; Garden Outlet — figury ogrodowe</title><link>https://mgoutlet.pl/sklep/figury-ogrodowe</link>'
        . '<description>Aktualne figury dostępne w sklepie online</description>';
    foreach ($report as $row) {
        if ($row['errors']) continue;
        $xml .= '<item>';
        foreach ($row['item'] as $key => $value) {
            if ($row['ai'] && in_array($key, ['title', 'description'], true)) {
                $xml .= '<g:structured_' . $key . '><g:digital_source_type>trained_algorithmic_media</g:digital_source_type>'
                    . '<g:content>' . merchant_xml_value($value) . '</g:content></g:structured_' . $key . '>';
            } else {
                $xml .= '<g:' . $key . '>' . merchant_xml_value($value) . '</g:' . $key . '>';
            }
        }
        foreach ($row['shipping'] as $shipping) {
            $xml .= '<g:shipping>';
            foreach ($shipping as $key => $value) $xml .= '<g:' . $key . '>' . merchant_xml_value($value) . '</g:' . $key . '>';
            $xml .= '</g:shipping>';
        }
        $xml .= '</item>';
    }
    return $xml . '</channel></rss>' . "\n";
}
