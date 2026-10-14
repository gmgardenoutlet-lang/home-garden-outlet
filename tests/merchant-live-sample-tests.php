<?php
declare(strict_types=1);
require __DIR__ . '/../hosting/getspace/lib/merchant.php';
$snapshot=json_decode((string)file_get_contents(__DIR__.'/../.local-cache/products-public.json'),true,512,JSON_THROW_ON_ERROR);
$live=json_decode((string)file_get_contents(__DIR__.'/../.local-cache/merchant/live-products.json'),true,512,JSON_THROW_ON_ERROR);
$liveBySlug=array_column($live['products'],null,'slug');
$prepared=merchant_prepare_figures($snapshot,'all','ai',['merchantHandlingDays'=>'3','merchantTransitMinDays'=>'1','merchantTransitMaxDays'=>'2',
    'merchantHandlingWeek'=>'mon-fri','merchantTransitWeek'=>'mon-fri']);
$report=[];
foreach ($prepared['products'] as $product) {
    if (!catalog_is_figure_shop_product($product)) continue;
    $page=$liveBySlug[$product['slug']]??null;
    if (!$page) throw new RuntimeException('Missing verified public page');
    // Test owner-confirmed supplier model with the actual price, image,
    // lead time and delivery methods displayed by the live shop.
    $product['producerAvailability']=$page['canBuy']?'Dostępny u producenta':'Niedostępny';
    $product['leadTime']=$page['leadTime']??'';
    $row=merchant_evaluate($product,$page['delivery']);
    if (!$row['errors'] && (float)$page['price'] !== shop_test_price_number($product['grossPrice'])) throw new RuntimeException('Price mismatch');
    $report[]=['slug'=>$product['slug']]+$row;
}
$xml=merchant_xml($report);
if (simplexml_load_string($xml)===false) throw new RuntimeException('Invalid sample XML');
file_put_contents(__DIR__.'/../.local-cache/merchant/sample.xml',$xml);
$ready=count(array_filter($report,static fn($r)=>!$r['errors']));
echo json_encode(['scope'=>'Simulation with owner-confirmed metadata; production panel not modified','ready'=>$ready,
    'omitted'=>count($report)-$ready,'issues'=>array_values(array_filter(array_map(static fn($r)=>['slug'=>$r['slug'],'errors'=>$r['errors']],$report),static fn($r)=>!!$r['errors']))],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
