<?php
declare(strict_types=1);
require_once __DIR__ . '/merchant.php';

// Matches the existing code of the linked Kębłowice Business Profile.
const MERCHANT_LOCAL_STORE_CODE = '13874835222925184443';

function merchant_local_evaluate(array $product): array
{
    $errors = [];
    if (($product['saleType'] ?? 'showroom') !== 'showroom') $errors[] = 'Poza katalogiem showroomu.';
    if (empty($product['localMerchantEnabled'])) $errors[] = 'Publikacja lokalna wyłączona.';
    if (empty($product['visible']) || catalog_normalize((string)($product['productStatus'] ?? '')) === 'ukryty') $errors[] = 'Produkt ukryty.';
    $id = (string)($product['localMerchantId'] ?? '');
    if (!preg_match('/^loc-[a-f0-9]{32}$/', $id)) $errors[] = 'Brak stałego ID lokalnego — zapisz produkt.';
    $slug = (string)($product['slug'] ?? '');
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) $errors[] = 'Nieprawidłowy adres produktu.';
    $name = trim((string)($product['name'] ?? ''));
    $description = trim(strip_tags((string)($product['description'] ?? '')));
    if ($name === '' || merchant_length($name) > 150) $errors[] = 'Wymagana nazwa do 150 znaków.';
    if ($description === '' || merchant_length($description) > 5000) $errors[] = 'Wymagany opis do 5000 znaków.';
    $image = merchant_https_url((string)($product['image'] ?? ''));
    if ($image === '') $errors[] = 'Wymagane zdjęcie HTTPS.';
    $price = catalog_price_number((string)($product['outletPrice'] ?? ''));
    if (preg_match('/\b(?:od|from)\b/iu', (string)($product['outletPrice'] ?? ''))) $errors[] = 'Cena lokalna nie może być ceną od.';
    if ($price === null || $price <= 0) $errors[] = 'Wymagana dodatnia cena outletowa.';
    if (($product['currency'] ?? '') !== 'PLN') $errors[] = 'Wymagana waluta PLN.';
    $condition = (string)($product['localMerchantCondition'] ?? '');
    if (!in_array($condition, ['new','used','refurbished'], true)) $errors[] = 'Potwierdź stan lokalnego produktu; Outletowy nie określa stanu Google.';
    if (empty($product['localMerchantInStoreConfirmed'])) $errors[] = 'Potwierdź zakup na miejscu za cenę outletową.';
    $source = (string)($product['localMerchantContentSource'] ?? '');
    if (!in_array($source, ['human','ai'], true)) $errors[] = 'Potwierdź pochodzenie nazwy i opisu.';
    $brand = catalog_confirmed_brand($product);
    $gtin = trim((string)($product['localMerchantGtin'] ?? ''));
    $mpn = trim((string)($product['localMerchantMpn'] ?? ''));
    if ($gtin !== '' && !merchant_gtin_valid($gtin)) $errors[] = 'Nieprawidłowy GTIN.';
    if ($condition === 'new' && $brand === '') $errors[] = 'Nowy produkt wymaga potwierdzonej marki producenta.';
    if ($condition === 'new' && empty($product['localMerchantIdentifiersChecked'])) $errors[] = 'Sprawdź identyfikatory producenta nowego produktu.';
    $status = catalog_normalize((string)($product['status'] ?? ''));
    $productStatus = catalog_normalize((string)($product['productStatus'] ?? ''));
    $unavailable = in_array($status, ['sprzedane','sprzedany','rezerwacja'], true) || in_array($productStatus, ['sprzedany','rezerwacja'], true);
    if (!$unavailable && !in_array($status, ['dostepne','dostępne','nowosc','nowość','ostatnia sztuka'], true)) $errors[] = 'Niepotwierdzony status dostępności.';
    $availability = $unavailable ? 'out_of_stock' : 'limited_availability';
    $item = ['id'=>$id,'title'=>$name,'description'=>$description,'link'=>'https://mgoutlet.pl/produkt/'.$slug,
        'image_link'=>$image,'price'=>$price === null ? '' : number_format($price,2,'.','').' PLN',
        'condition'=>$condition,'availability'=>$unavailable ? 'out_of_stock' : 'in_stock'];
    foreach (['brand'=>$brand,'gtin'=>$gtin,'mpn'=>$mpn] as $key=>$value) if ($value !== '') $item[$key]=$value;
    return ['errors'=>$errors,'item'=>$item,'shipping'=>[],'ai'=>$source === 'ai',
        'inventory'=>['id'=>$id,'store_code'=>MERCHANT_LOCAL_STORE_CODE,'availability'=>$availability,'price'=>$item['price']]];
}

function merchant_local_report(array $products): array
{
    $report = [];
    $counts = [];
    foreach ($products as $product) {
        if (!is_array($product) || ($product['saleType'] ?? 'showroom') !== 'showroom') continue;
        $row = merchant_local_evaluate($product);
        $report[] = ['name'=>(string)($product['name'] ?? '')] + $row;
        $id = $row['item']['id'];
        $counts[$id] = ($counts[$id] ?? 0) + 1;
    }
    foreach ($report as &$row) if ($row['item']['id'] !== '' && $counts[$row['item']['id']] > 1) $row['errors'][] = 'Powielone ID lokalne.';
    unset($row);
    return $report;
}

function merchant_local_xml(array $report, bool $inventory): string
{
    if (!$inventory) {
        $xml = merchant_xml($report);
        $xml = str_replace(['Home &amp; Garden Outlet — figury ogrodowe','<link>https://mgoutlet.pl/sklep/figury-ogrodowe</link>','Aktualne figury dostępne w sklepie online'],
            ['Home &amp; Garden Outlet — showroom','<link>https://mgoutlet.pl/</link>','Aktualna oferta stacjonarna w Kębłowicach'], $xml);
        // Enforce local-only destinations even if the account source is edited.
        return str_replace('</item>', '<g:included_destination>Free_local_listings</g:included_destination><g:excluded_destination>Free_listings</g:excluded_destination><g:excluded_destination>Shopping_ads</g:excluded_destination><g:excluded_destination>Local_inventory_ads</g:excluded_destination></item>', $xml);
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel><title>Home &amp; Garden Outlet — asortyment showroomu</title><link>https://mgoutlet.pl/</link><description>Dostępność na miejscu w Kębłowicach</description>';
    foreach ($report as $row) {
        if ($row['errors']) continue;
        $xml .= '<item>';
        foreach ($row['inventory'] as $key=>$value) $xml .= '<g:'.$key.'>'.merchant_xml_value($value).'</g:'.$key.'>';
        $xml .= '</item>';
    }
    return $xml.'</channel></rss>';
}
