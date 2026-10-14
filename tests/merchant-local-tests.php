<?php
declare(strict_types=1);
require __DIR__.'/../hosting/getspace/lib/merchant-local.php';
function local_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$product=['name'=>'Fotel & pufa','description'=>'Opis <b>produktu</b>','saleType'=>'showroom','visible'=>true,'slug'=>'fotel-pufa',
    'status'=>'Dostępne','productStatus'=>'Aktywny','outletPrice'=>'480 zł','currency'=>'PLN','image'=>'uploads/fotel.webp',
    'localMerchantId'=>'loc-'.str_repeat('a',32),'localMerchantEnabled'=>true,'localMerchantCondition'=>'used',
    'localMerchantContentSource'=>'ai','localMerchantInStoreConfirmed'=>true,'brand'=>'Beliani','internalNote'=>'PRIVATE_SECRET'];
$row=merchant_local_evaluate($product);
local_assert(!$row['errors'],implode(', ',$row['errors']));
$report=merchant_local_report([$product]);
$xml=merchant_local_xml($report,false);
$inventory=merchant_local_xml($report,true);
local_assert(simplexml_load_string($xml)!==false && simplexml_load_string($inventory)!==false,'Valid XML');
local_assert(!str_contains($xml,'PRIVATE_SECRET') && !str_contains($inventory,'PRIVATE_SECRET'),'Private fields excluded');
local_assert(str_contains($xml,'Free_local_listings') && str_contains($xml,'<g:excluded_destination>Free_listings'),'Local only');
local_assert(!str_contains($xml,'<g:shipping>') && !str_contains($xml,'shipping_weight'),'No online shipping');
local_assert(str_contains($xml,'<g:condition>used') && str_contains($xml,'<g:brand>Beliani'),'Real condition and brand');
local_assert(str_contains($xml,'trained_algorithmic_media'),'AI provenance');
local_assert(str_contains($inventory,'<g:store_code>'.MERCHANT_LOCAL_STORE_CODE),'Store code');
local_assert(str_contains($inventory,'limited_availability'),'Conservative local availability');
$updated=$product; $updated['outletPrice']='390 zł';
local_assert(merchant_local_evaluate($updated)['item']['price']==='390.00 PLN','Panel price update');
local_assert(merchant_local_evaluate($updated)['item']['id']===$row['item']['id'],'Stable ID');
foreach (['Sprzedane','Rezerwacja','Zarezerwowane'] as $status) {
    $updated=$product; $updated['status']=$status;
    local_assert(merchant_local_evaluate($updated)['inventory']['availability']==='out_of_stock','Sold/reserved unavailable');
}
$updated=$product; $updated['visible']=false;
local_assert((bool)merchant_local_evaluate($updated)['errors'],'Hidden omitted');
$updated=$product; $updated['saleType']='garden_figure';
local_assert(count(merchant_local_report([$updated]))===0,'Online figures excluded');
$updated=$product; $updated['localMerchantCondition']='';
local_assert((bool)merchant_local_evaluate($updated)['errors'],'Outlet does not imply new');
$updated=$product; $updated['localMerchantCondition']='new'; $updated['brand']='';
local_assert((bool)merchant_local_evaluate($updated)['errors'],'New requires manufacturer details');
local_assert(count(array_filter(merchant_local_report([$product,$product]),fn($r)=>!$r['errors']))===0,'Duplicate IDs blocked');
echo "PASS: local-only XML, matching store inventory IDs, price changes, sold, reserved, hidden, privacy and unknown condition\n";
