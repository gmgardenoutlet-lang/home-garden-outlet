<?php
declare(strict_types=1);
require __DIR__ . '/../hosting/getspace/admin/olx/prepare-lib.php';
set_error_handler(static function($level,$message) { if(error_reporting() & $level) throw new RuntimeException($message); });
function check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException('FAIL: '.$label); }
function reject(callable $fn,string $label): void { try {$fn();} catch(Throwable $e) {return;} throw new RuntimeException('FAIL: '.$label); }
$dir=sys_get_temp_dir().'/hgo-olx-prepare-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
$config=['client_id'=>'203399','secret'=>'FIXTURE_SECRET','key'=>base64_encode(random_bytes(32)),'redirect_uri'=>OLX_REDIRECT_URI,'dir'=>$dir,'logs_safe'=>true];
$product=['productId'=>'prd_'.str_repeat('a',32),'name'=>'Konsola drewniana biała do salonu','slug'=>'fixture','outletPrice'=>'240,50 zł','catalogPrice'=>'900 zł','currency'=>'PLN','category'=>'Meble',
    'description'=>str_repeat('Opis produktu z katalogu. ',5),'longDescription'=>'','image'=>'/uploads/main.webp','gallery'=>['/uploads/other.jpg','/uploads/main.webp']];
$original=serialize($product); $calls=[];
$transport=static function($path,$query) use (&$calls) { $calls[]=[$path,$query]; return [['id'=>99,'name'=>'Konsole','parent_id'=>1,'is_leaf'=>true,'photos_limit'=>2]]; };
try {
    foreach(['/adverts','/adverts/1','/users/me','https://example.com','/categories/../adverts'] as $bad) reject(fn()=>olx_config_get($config,$bad,[],$transport),'forbidden path');
    check(!$calls,'no forbidden transport calls');
    $rows=olx_cached_get($config,'/categories',[],false,$transport);
    olx_cached_get($config,'/categories',[],false,$transport); check(count($calls)===1,'cache hit');
    olx_cached_get($config,'/categories',[],true,$transport); check(count($calls)===2,'manual cache refresh');
    olx_config_get($config,'/categories/suggestion',['q'=>'Konsola'],$transport); check($calls[2][1]['q']==='Konsola','suggestion query');
    $idx=olx_category_index(array_merge([['id'=>1,'name'=>'Dom','parent_id'=>null,'is_leaf'=>false]],$rows));
    check(olx_category_path($idx[99],$idx)==='Dom → Konsole','category path');
    $draft=olx_default_draft($product); check($draft['priceOverride']===240.5,'outlet price instead of catalog');
    check(!$draft['selectedCategoryId'] && !$draft['selectedImages'],'no implicit category or photos');
    check(count(olx_product_images($product))===2,'deduplicate photos preserving main');
    check(olx_sale_price('550 zł / szt.')===null,'ambiguous price needs manual correction');
    $definitions=[['code'=>'price','label'=>'Cena','validation'=>['required'=>true,'type'=>'price','numeric'=>true,'min'=>1,'max'=>10000]],
        ['code'=>'state','label'=>'Stan','validation'=>['type'=>'attribute','required'=>true],'values'=>[['code'=>'used','label'=>'Używane']]],
        ['code'=>'width','label'=>'Szerokość','validation'=>['numeric'=>true,'min'=>1,'max'=>300]],
        ['code'=>'features','label'=>'Cechy','validation'=>['allow_multiple_values'=>true],'values'=>[['code'=>'wood','label'=>'Drewno']]]];
    $input=$draft+['city_id'=>'10','district_id'=>'0','contact_name'=>'Outlet','contact_phone'=>'577210777'];
    $input['selectedCategoryId']=99; $input['selectedImages']=olx_product_images($product); $input['attributeValues']=['state'=>'used','width'=>'100','features'=>['wood','']];
    $draft=olx_clean_draft($product,$input);
    check($draft['attributeValues']['features']===['wood'],'remove empty multi fields');
    $validate=static fn($d,$c=null)=>olx_validate_draft($product,$d,$c??$idx[99],$definitions,['id'=>10],[],[['code'=>'PLN']]);
    check($validate($draft)===[],'valid draft');
    $bad=$draft; $bad['titleOverride']=str_repeat('A',151); check(count($validate($bad))>=2,'title constraints without truncation');
    $bad=$draft; $bad['attributeValues']['state']='invented'; check((bool)$validate($bad),'enum validation');
    $bad=$draft; $bad['attributeValues']['width']='301'; check((bool)$validate($bad),'numeric max');
    $bad=$draft; unset($bad['attributeValues']['state']); check((bool)$validate($bad),'required attribute');
    check((bool)$validate($draft,['id'=>99,'name'=>'Root','is_leaf'=>false,'photos_limit'=>2]),'non-leaf rejected');
    $bad=$input; $bad['selectedImages']=['https://evil.test/photo.jpg']; reject(fn()=>olx_clean_draft($product,$bad),'foreign photo');
    $payload=olx_payload_preview($product,$draft,$definitions);
    check($payload['external_id']===$product['productId'] && $payload['price']['value']===240.5,'payload identity and price');
    check(!array_key_exists('external_url',$payload),'restricted external URL omitted for product adverts');
    check(!isset($payload['advert_id']) && !isset($payload['access_token']),'no advert or token');
    $file=olx_draft_file($config,$product['productId']); olx_json_write($file,$draft);
    check(olx_json_read($file)===$draft,'separate draft roundtrip');
    check(serialize($product)===$original,'product unchanged');
    if(PHP_OS_FAMILY!=='Windows') check((fileperms($file)&0777)===0600,'draft mode 600');
    echo "PASS: OLX Stage C GET allowlist, cache, suggestion, price, leaf, attributes, photos, payload, independent drafts; zero advert requests\n";
} finally {
    foreach(['cache','drafts','settings'] as $folder) { foreach(glob($dir.'/'.$folder.'/*')?:[] as $f) unlink($f); if(is_dir($dir.'/'.$folder))rmdir($dir.'/'.$folder); }
    rmdir($dir);
}
