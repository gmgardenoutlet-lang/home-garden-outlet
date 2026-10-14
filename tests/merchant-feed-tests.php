<?php
declare(strict_types=1);
require __DIR__ . '/../hosting/getspace/lib/merchant.php';
function merchant_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$methods = [['type'=>'kurier', 'label'=>'Kurier & dostawa', 'costNumber'=>35, 'requiresConfirmation'=>false, 'priceFrom'=>false]];
$product = array_merge(product_defaults(), ['name'=>'Żółw & ogród <szary>', 'slug'=>'zolw-szary', 'saleType'=>'garden_figure',
    'shopVisible'=>true, 'shopStatus'=>'Dostępny', 'grossPrice'=>'90,50 zł', 'image'=>'uploads/zolw.jpg',
    'description'=>'Żółw do ogrodu & tarasu.', 'merchantEnabled'=>true, 'merchantId'=>'fig-' . str_repeat('a',32),
    'merchantCondition'=>'new', 'merchantIdentifiers'=>'none', 'merchantSupplyModel'=>'supplier', 'merchantContentSource'=>'ai',
    'internalNote'=>'SECRET-INTERNAL-NOTE', 'googleText'=>'SECRET-GOOGLE-TEXT']);
$timing = ['merchantHandlingDays'=>'3','merchantTransitMinDays'=>'1','merchantTransitMaxDays'=>'2',
    'merchantHandlingWeek'=>'mon-fri','merchantTransitWeek'=>'mon-fri'];
$product = array_merge($product, $timing);
$evaluate = static fn(array $p): array => merchant_evaluate($p, $methods);
$ready = $evaluate($product);
merchant_assert(!$ready['errors'], implode(' ', $ready['errors']));
$xml = merchant_xml([$ready]);
$parsed = simplexml_load_string($xml);
merchant_assert($parsed !== false, 'Invalid XML');
$g = $parsed->channel->item->children('http://base.google.com/ns/1.0');
merchant_assert((string)$g->structured_title->children('http://base.google.com/ns/1.0')->content === $product['name'], 'Polish characters/XML escaping');
merchant_assert((string)$g->price === '90.50 PLN', 'Actual shop gross price');
merchant_assert((string)$g->identifier_exists === 'false', 'Confirmed lack of identifiers');
merchant_assert((string)$g->shipping->children('http://base.google.com/ns/1.0')->max_handling_time === '3', 'Handling days');
merchant_assert((string)$g->shipping_transit_business_days === 'Mon-Fri', 'Business days');
merchant_assert(!str_contains($xml,'SECRET') && !str_contains($xml,'internalNote'), 'Private field leak');
$changed = $product;
$changed['grossPrice'] = '125 zł'; $changed['slug']='zolw-inny-adres'; $changed['name']='Zmieniona nazwa';
$new = $evaluate($changed);
merchant_assert($new['item']['price']==='125.00 PLN' && $new['item']['id']===$ready['item']['id'], 'Price/ID updates');
foreach (['Sprzedany','Rezerwacja'] as $status) {
    $changed=$product; $changed['productStatus']=$status;
    merchant_assert($evaluate($changed)['item']['availability']==='out_of_stock', 'Sold/reserved not withdrawn');
    merchant_assert(!catalog_is_active_figure_shop_product($changed), 'Shop disagrees with feed');
}
$changed=$product; $changed['producerAvailability']='Niedostępny u producenta';
merchant_assert($evaluate($changed)['item']['availability']==='out_of_stock' && !catalog_is_active_figure_shop_product($changed), 'Supplier unavailable');
foreach (['shopVisible'=>false,'shopStatus'=>'Ukryty','productStatus'=>'Ukryty','merchantEnabled'=>false,
    'merchantCondition'=>'','merchantIdentifiers'=>'','image'=>'','merchantSupplyModel'=>'','merchantContentSource'=>''] as $key=>$value) {
    $changed=$product; $changed[$key]=$value;
    merchant_assert((bool)$evaluate($changed)['errors'], 'Missing field/hidden not rejected: '.$key);
    merchant_assert(!str_contains(merchant_xml([$evaluate($changed)]),'<item>'), 'Rejected product in XML: '.$key);
}
$changed=$product; $changed['merchantCondition']=''; $changed['condition']='Outletowy';
merchant_assert(merchant_condition($changed)==='', 'Outlet condition guessed');
$changed=$product; $changed['brand']='Producent';
merchant_assert((bool)$evaluate($changed)['errors'], 'No-identifiers contradiction accepted');
merchant_assert(merchant_gtin_valid('5901234123457') && !merchant_gtin_valid('5901234123458'), 'GTIN checksum');
$quote=merchant_evaluate($product,[['type'=>'paleta','label'=>'Paleta','costNumber'=>250,'requiresConfirmation'=>true]]);
merchant_assert((bool)$quote['errors'], 'Unconfirmed shipping accepted');
$pickup=merchant_evaluate($product,[['type'=>'odbior_osobisty','label'=>'Odbiór','costNumber'=>0]]);
merchant_assert((bool)$pickup['errors'], 'Pickup-only item accepted');
$sample=json_decode((string)file_get_contents(__DIR__.'/../.local-cache/products-public.json'),true,512,JSON_THROW_ON_ERROR);
$pilot=merchant_prepare_figures($sample,'pilot','ai',$timing);
merchant_assert(count(array_filter($pilot['products'],static fn($p)=>!empty($p['merchantEnabled'])))===3,'Pilot is not 3 figures');
$all=merchant_prepare_figures($pilot,'all','ai',$timing);
$repeat=merchant_prepare_figures($all,'all','ai',$timing);
merchant_assert(merchant_delivery_timing($product+[])!==[],'Confirmed delivery timing');
$invalidTiming=$product; $invalidTiming['merchantTransitMinDays']='4';
merchant_assert(merchant_delivery_timing($invalidTiming)===[] && (bool)$evaluate($invalidTiming)['errors'],'Inverted transport range');
$missingTiming=$product; $missingTiming['merchantHandlingDays']='';
merchant_assert(merchant_delivery_timing($missingTiming)===[] && (bool)$evaluate($missingTiming)['errors'],'Missing preparation days');
merchant_assert($all===$repeat,'Initialization changed stable IDs');
foreach ($sample['products'] as $index=>$original) {
    if (!catalog_is_figure_shop_product($original)) merchant_assert($all['products'][$index]===$original,'Furniture modified');
    else foreach (['name','slug','grossPrice','image','description','shopVisible','status','productStatus'] as $key) {
        merchant_assert(($original[$key]??null)===($all['products'][$index][$key]??null),'Bulk initialization changed '.$key);
    }
}
$duplicate=$product; $duplicate['slug']='another';
$report=merchant_catalog_report([$product,$duplicate]);
merchant_assert(count(array_filter($report,static fn($r)=>in_array('Powielone ID Google.',$r['errors'],true)))===2,'Duplicate IDs not rejected');
echo "PASS: Merchant XML, privacy, price, stable IDs, sold/hidden/supplier availability, identifiers, shipping, 3-product pilot and all-figure initialization\n";
