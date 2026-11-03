<?php
declare(strict_types=1);
require __DIR__.'/../hosting/getspace/admin/allegro/lib.php';
require __DIR__.'/../hosting/getspace/catalog.php';
require __DIR__.'/../hosting/getspace/admin/product-identity.php';
$checks=0;
function pilot_check(bool $ok): void {global $checks;if(!$ok)throw new RuntimeException('Pilot assertion failed');$checks++;}
function pilot_reject(callable $f): void {try{$f();}catch(Throwable $e){pilot_check(true);return;}throw new RuntimeException('Unsafe pilot acceptance');}
$root=sys_get_temp_dir().'/hgo-pilot-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/vault',0700);mkdir($root.'/uploads',0700);
define('SITE_ROOT',$root);file_put_contents($root.'/uploads/figure.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAfQAAAH0CAIAAABEtEjdAAAG8klEQVR4nO3UQQkAMAzAwPo33ZoYDMKdgrwyC0DO/A4A4D1zBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gCBzBwgyd4AgcwcIMneAIHMHCDJ3gKADlkfpS/HtDQIAAAAASUVORK5CYII='));
$config=['environment'=>'SANDBOX','auth_flow'=>'DEVICE','client_id'=>'SYNTHETIC_CLIENT','secret'=>'SYNTHETIC_SECRET','key'=>base64_encode(random_bytes(32)),
 'storage_root'=>$root.'/vault','enabled'=>true];
$pid='prd_'.str_repeat('a',32);$now=time();$binding=str_repeat('b',64);
$product=['productId'=>$pid,'saleType'=>'garden_figure','shopVisible'=>true,'shopStatus'=>'Dostępny','name'=>'Figura ogrodowa testowa','grossPrice'=>'150 zł','description'=>'Opis katalogowy','image'=>'/uploads/figure.png'];
$meta=['at'=>$now,'environment'=>'SANDBOX','seller'=>'123','category'=>['id'=>'148446','name'=>'Figury','leaf'=>true],
 'parameters'=>[['id'=>'11323','name'=>'Stan','type'=>'dictionary','required'=>true,'requiredForProduct'=>false,'dictionary'=>[['id'=>'new','value'=>'Nowy']]],
 ['id'=>'material','name'=>'Materiał','type'=>'string','requiredForProduct'=>true,'restrictions'=>['minLength'=>2,'maxLength'=>20]],
 ['id'=>'ean','name'=>'EAN','type'=>'string','requiredForProduct'=>false,'required'=>false]],
 'shippingRates'=>[['id'=>'shipping','name'=>'Kurier']],'returnPolicies'=>[['id'=>'returns','name'=>'Zwroty']],
 'impliedWarranties'=>[['id'=>'warranty','name'=>'Reklamacje']],'responsibleProducers'=>[['id'=>'producer','name'=>'Producent']], 'responsiblePersons'=>[]];
$input=['name'=>$product['name'],'description'=>$product['description'],'price'=>'150,00','quantity'=>'1','stock_confirmed'=>'yes',
 'parameters'=>['11323'=>'new','material'=>'Beton'],'shipping'=>'shipping','return'=>'returns','warranty'=>'warranty','producer'=>'producer',
 'safety'=>'Instrukcja bezpieczeństwa potwierdzona przez administratora.','gpsr_confirmed'=>'yes','handling_confirmed'=>'yes'];
$seed=static function(string $scope)use($config,$now){allegro_locked($config,static function($v,$k)use($config,$now,$scope){allegro_write_vault($config,$k,
 ['status'=>'connected','account'=>['id'=>'123','login'=>'fixture'],'tokens'=>['access'=>'SYNTHETIC_ACCESS','refresh'=>'SYNTHETIC_REFRESH','expires_at'=>$now+3600,'scope'=>$scope]]);});};
$read=static fn()=>allegro_locked($config,static fn($v,$k)=>$v);
$requests=[];$createMode='201';$operationPending=true;$postThrows=false;$remoteOffer=[];
$transport=static function($r)use(&$requests,&$createMode,&$operationPending,&$postThrows,&$remoteOffer,$config){
 $requests[]=$r;
 if(str_starts_with($r['url'],'https://upload.')) return ['status'=>201,'body'=>json_encode(['location'=>'https://a.allegroimg.com/original/fixture/image'])];
 $path=substr($r['url'],strlen(allegro_hosts($config['environment'])['api']));
 $reply=static fn($d,$status=200,$h=[])=>['status'=>$status,'body'=>json_encode($d),'headers'=>$h];
 if($r['method']==='GET' && str_starts_with($path,'/sale/offers?'))return $reply(['totalCount'=>0,'offers'=>[]]);
 if($r['method']==='POST' && str_ends_with($r['url'],'/sale/product-offers')) {
  if($postThrows)throw new AllegroSafeException('network');
  $remoteOffer=json_decode($r['body'],true);$remoteOffer['id']='789';
  if($createMode==='202')return $reply($remoteOffer,202,['location'=>'/sale/product-offers/789/operations/op-1','retry-after'=>'1']);
  return $reply($remoteOffer,201);
 }
 if(str_contains($path,'/operations/'))return $operationPending?$reply([],202,['retry-after'=>'1']):$reply([],303,['location'=>'/sale/product-offers/789']);
 if($r['method']==='PATCH') {$remoteOffer['publication']['status']='ACTIVE';return $reply($remoteOffer);}
 return $reply($remoteOffer);
};
try {
 pilot_check(allegro_scope_matches(implode(' ',array_reverse(ALLEGRO_PILOT_SCOPES))));
 // IDs are supplied by the API fixture, unrelated to the obsolete storefront ID.
 $tree=[
  '/sale/categories'=>['categories'=>[['id'=>'123e4567-e89b-12d3-a456-426614174000','name'=>'Dom i Ogród','leaf'=>false]]],
  '/sale/categories?parent.id=123e4567-e89b-12d3-a456-426614174000'=>['categories'=>[['id'=>'202','name'=>'Ogród','leaf'=>false]]],
  '/sale/categories?parent.id=202'=>['categories'=>[['id'=>'303','name'=>'Przedmioty dekoracyjne','leaf'=>false]]],
  '/sale/categories?parent.id=303'=>['categories'=>[['id'=>'ABCDEF12-3456-7890-abcd-1234567890ab','name'=>'Rzeźby i figury','leaf'=>true]]],
 ];
 $categoryReads=[];
 $getCategory=static function($p)use($tree,&$categoryReads){$categoryReads[]=$p;return $tree[$p];};
 $state=allegro_pilot_category($getCategory);
 pilot_check($state['path']===[] && !$state['leaf'] && $categoryReads===['/sale/categories']);
 pilot_reject(fn()=>allegro_pilot_category($getCategory,[],'123e4567-e89b-12d3-a456-426614174000'));
 pilot_reject(fn()=>allegro_pilot_category($getCategory,$state,'999'));
 foreach (['123e4567-e89b-12d3-a456-426614174000','202','303','ABCDEF12-3456-7890-abcd-1234567890ab'] as $selection) $state=allegro_pilot_category($getCategory,$state,$selection);
 pilot_check($state['leaf'] && $state['options']===[] && array_column($state['path'],'id')===['123e4567-e89b-12d3-a456-426614174000','202','303','ABCDEF12-3456-7890-abcd-1234567890ab']);
 pilot_reject(fn()=>allegro_pilot_category($getCategory,$state,'ABCDEF12-3456-7890-abcd-1234567890ab'));
 // Arbitrary API names and a root leaf work without any predefined path/depth.
 $rootLeaf=['categories'=>[['id'=>'777','name'=>'Dowolna kategoria','leaf'=>true]]];
 $roots=allegro_pilot_category(static fn($p)=>$rootLeaf);
 pilot_check(allegro_pilot_category(static fn($p)=>$rootLeaf,$roots,'777')['leaf']);
 pilot_reject(fn()=>allegro_pilot_category(static fn($p)=>['categories'=>[]],$roots,'777'));
 foreach (['missing-leaf','invalid-id','duplicate','missing-name'] as $case) {
  $bad=$rootLeaf;
  if($case==='missing-leaf')unset($bad['categories'][0]['leaf']);
  if($case==='invalid-id')$bad['categories'][0]['id']='777?token=bad';
  if($case==='duplicate')$bad['categories'][]=$bad['categories'][0];
  if($case==='missing-name')unset($bad['categories'][0]['name']);
  pilot_reject(fn()=>allegro_pilot_category(static fn($p)=>$bad));
 }
 // Category-only UUID policy: no extra query arguments, escapes or path manipulation.
 foreach (['123','123e4567-e89b-12d3-a456-426614174000','ABCDEF12-3456-7890-abcd-1234567890ab'] as $categoryId) {
  foreach (['/sale/categories?parent.id='.$categoryId,'/sale/categories/'.$categoryId.'/parameters'] as $path) {
   pilot_check(allegro_pilot_path('GET',$path));
   allegro_http_policy('GET',allegro_hosts('SANDBOX')['api'].$path);
  }
 }
 foreach (['','abc','123e4567-e89b-12d3-a456-42661417400','123e4567-e89b-12d3-a456-42661417400g',
  '{123e4567-e89b-12d3-a456-426614174000}','../123','123/parameters','123&parent.id=456',
  '123?extra=1','123#fragment','123%26extra=1','123%2F456','123;extra=1','123 ',"123\n",str_repeat('1',33)] as $badId) {
  $bad=['categories'=>[['id'=>$badId,'name'=>'Test','leaf'=>true]]];
  pilot_reject(fn()=>allegro_pilot_category(static fn($p)=>$bad));
  foreach (['/sale/categories?parent.id='.$badId,'/sale/categories/'.$badId.'/parameters'] as $path) {
   pilot_check(!allegro_pilot_path('GET',$path));
   pilot_reject(fn()=>allegro_http_policy('GET',allegro_hosts('SANDBOX')['api'].$path));
  }
 }
 pilot_check(!allegro_pilot_path('GET','/sale/categories?parent.id=123&parent.id=456'));
 pilot_check(!allegro_pilot_path('GET','/sale/product-offers/123e4567-e89b-12d3-a456-426614174000'));
 pilot_check(!allegro_pilot_path('PATCH','/sale/product-offers/123e4567-e89b-12d3-a456-426614174000'));
 pilot_reject(fn()=>allegro_pilot_category($getCategory,allegro_pilot_category($getCategory),'ffffffff-ffff-ffff-ffff-ffffffffffff'));
 $seed(allegro_scope_request());$metadataReads=[];
 $metaTransport=static function($r)use($config,$tree,$meta,&$metadataReads){
  $path=substr($r['url'],strlen(allegro_hosts($config['environment'])['api']));$metadataReads[]=$path;
  if(isset($tree[$path]))$body=$tree[$path];
  elseif($path==='/sale/categories/ABCDEF12-3456-7890-abcd-1234567890ab/parameters')$body=['parameters'=>$meta['parameters']];
  else {
   $keys=['/sale/shipping-rates'=>'shippingRates','/after-sales-service-conditions/return-policies'=>'returnPolicies',
    '/after-sales-service-conditions/implied-warranties'=>'impliedWarranties','/sale/responsible-producers'=>'responsibleProducers',
    '/sale/responsible-persons'=>'responsiblePersons'];
   if(!isset($keys[$path]))throw new LogicException('Unexpected metadata path');
   $body=[$keys[$path]=>$meta[$keys[$path]]];
  }
  return ['status'=>200,'body'=>json_encode($body)];
 };
 $currentMeta=allegro_pilot_metadata($config,[],'',$metaTransport);
 pilot_check(!isset($currentMeta['parameters']) && $metadataReads===['/sale/categories']);
 foreach (['123e4567-e89b-12d3-a456-426614174000','202','303','ABCDEF12-3456-7890-abcd-1234567890ab'] as $selection) $currentMeta=allegro_pilot_metadata($config,$currentMeta,$selection,$metaTransport);
 pilot_check($currentMeta['category']['id']==='ABCDEF12-3456-7890-abcd-1234567890ab' && $currentMeta['parameters']===$meta['parameters']);
 pilot_check($metadataReads===['/sale/categories','/sale/categories','/sale/categories?parent.id=123e4567-e89b-12d3-a456-426614174000','/sale/categories?parent.id=123e4567-e89b-12d3-a456-426614174000','/sale/categories?parent.id=202','/sale/categories?parent.id=202','/sale/categories?parent.id=303','/sale/categories?parent.id=303','/sale/categories/ABCDEF12-3456-7890-abcd-1234567890ab/parameters','/sale/shipping-rates',
  '/after-sales-service-conditions/return-policies','/after-sales-service-conditions/implied-warranties',
  '/sale/responsible-producers','/sale/responsible-persons']);

 pilot_check(!allegro_scope_matches(ALLEGRO_B_SCOPE));pilot_check(!allegro_scope_matches(allegro_scope_request().' allegro:api:orders:read'));
 $token=['access_token'=>'SYNTHETIC_ACCESS','refresh_token'=>'SYNTHETIC_REFRESH','token_type'=>'bearer','expires_in'=>3600,'scope'=>implode(' ',array_reverse(ALLEGRO_PILOT_SCOPES))];
 pilot_check(allegro_tokens($token,$now)['scope']===allegro_scope_request());
 pilot_reject(fn()=>allegro_tokens(array_replace($token,['scope'=>ALLEGRO_B_SCOPE]),$now));
 pilot_check(allegro_tokens(array_replace($token,['scope'=>ALLEGRO_B_SCOPE]),$now,true)['scope']===ALLEGRO_B_SCOPE);
 foreach (['/order/checkout-forms','/payments','/sale/offers','/sale/product-offers/789?x=1','https://evil.test/','/sale/product-offers/789/../1'] as $path)pilot_check(!allegro_pilot_path('POST',$path));
 pilot_check(allegro_pilot_path('PATCH','/sale/product-offers/789'));pilot_check(!allegro_pilot_path('PATCH','/sale/product-offers'));
 foreach ([404,403,500] as $httpStatus) {
  try {
   allegro_pilot_api($config,'GET','/sale/shipping-rates','SYNTHETIC_TOKEN',[],static fn($r)=>['status'=>$httpStatus,'body'=>'SYNTHETIC_RESPONSE_SECRET','headers'=>['x-secret'=>'SYNTHETIC_HEADER_SECRET']]);
   throw new LogicException('GET failure must throw');
  } catch (RuntimeException $e) {
   pilot_check(str_contains($e->getMessage(),'GET /sale/shipping-rates') && str_contains($e->getMessage(),'HTTP '.$httpStatus));
   foreach (['SYNTHETIC_TOKEN','SYNTHETIC_RESPONSE_SECRET','SYNTHETIC_HEADER_SECRET','Authorization'] as $secret) pilot_check(!str_contains($e->getMessage(),$secret));
  }
 }
 // Larger GET payloads only; OAuth, /me and writes retain 128 KiB.
 foreach ([['GET','/sale/categories',1048576],['GET','/sale/shipping-rates',1048576],
  ['GET','/me',131072],['POST','/auth/oauth/token',131072],['POST','/auth/oauth/device',131072],
  ['POST','/sale/product-offers',131072],['PATCH','/sale/product-offers/789',131072]] as [$method,$path,$limit]) {
  pilot_check(allegro_pilot_response_limit($method,$path)===$limit);
 }
 $large=json_encode(['categories'=>[],'padding'=>str_repeat('x',200000)]);
 $largeResult=allegro_pilot_api($config,'GET','/sale/categories','SYNTHETIC_TOKEN',[],static fn($r)=>['status'=>200,'body'=>$large]);
 pilot_check(strlen($large)>131072 && strlen($largeResult['data']['padding'])===200000);
 $boundary=json_encode(['padding'=>str_repeat('x',1048576-14)]);
 pilot_check(strlen($boundary)===1048576);
 pilot_check(isset(allegro_pilot_api($config,'GET','/sale/categories','SYNTHETIC_TOKEN',[],static fn($r)=>['status'=>200,'body'=>$boundary])['data']['padding']));
 foreach ([
  [static fn($r)=>['status'=>200,'body'=>$boundary.' '],'przekroczony limit'],
  [static fn($r)=>throw new AllegroPilotResponseLimitException(),'przekroczony limit'],
  [static fn($r)=>throw new RuntimeException('SYNTHETIC_TRANSPORT_SECRET'),'cURL/transportu'],
  [static fn($r)=>['status'=>200,'body'=>'SYNTHETIC_BODY_SECRET'],'niepoprawny JSON'],
  [static fn($r)=>['status'=>200,'body'=>'null'],'niepoprawny JSON'],
  [static fn($r)=>['status'=>200],'cURL/transportu'],
 ] as [$fake,$problem]) {
  try {
   allegro_pilot_api($config,'GET','/sale/categories','SYNTHETIC_TOKEN',[],$fake);
   throw new LogicException('GET failure must throw');
  } catch (RuntimeException $e) {
   pilot_check(str_contains($e->getMessage(),'GET /sale/categories') && str_contains($e->getMessage(),$problem));
   foreach (['SYNTHETIC_TOKEN','SYNTHETIC_TRANSPORT_SECRET','SYNTHETIC_BODY_SECRET','Authorization'] as $secret) pilot_check(!str_contains($e->getMessage(),$secret));
  }
 }
 foreach (['POST','PATCH'] as $method) pilot_reject(fn()=>allegro_pilot_api($config,$method,$method==='POST'?'/sale/product-offers':'/sale/product-offers/789','SYNTHETIC_TOKEN',[],static fn($r)=>['status'=>200,'body'=>$large]));
 // Back/restart replays only the chosen ancestors via synthetic GETs, even from a leaf.
 $metadataReads=[];
 $back=allegro_pilot_category_return($config,$currentMeta,false,$metaTransport);
 pilot_check(array_column($back['path'],'id')===array_slice(array_column($currentMeta['categoryPath'],'id'),0,-1));
 pilot_check(!isset($back['parameters']) && array_column($back['options'],'id')===[$currentMeta['category']['id']]);
 pilot_check(!in_array('/sale/categories/'.$currentMeta['category']['id'].'/parameters',$metadataReads,true));
 $backAgain=allegro_pilot_category_return($config,$back,false,$metaTransport);
 pilot_check(count($backAgain['path'])===2);
 $restart=allegro_pilot_category_return($config,$currentMeta,true,$metaTransport);
 pilot_check($restart['path']===[] && count($restart['options'])===1 && !$restart['leaf']);
 pilot_check(allegro_pilot_category_return($config,$restart,false,$metaTransport)['path']===[]);
 // Text is local, editable and converted to safe Allegro paragraphs/headings.
 $described=array_replace($product,['description'=>'Figura z katalogu.','longDescription'=>'Szczegóły: ręczne wykończenie, żółć i źdźbło.']);
 $unchanged=$described;$description=allegro_pilot_description($described);
 pilot_check($described===$unchanged && str_contains($description,'Figura z katalogu.') && str_contains($description,'Szczegóły: ręczne wykończenie, żółć i źdźbło.'));
 pilot_check(substr_count($description,'Ważna informacja o ręcznym malowaniu')===1);
 pilot_check(substr_count(allegro_pilot_description(['description'=>$description]),'Ważna informacja o ręcznym malowaniu')===1);
 pilot_check(!str_contains($description,'showroom') && !str_contains($description,'mgoutlet.pl'));
 $html=allegro_pilot_description_html($description);
 pilot_check(str_contains($html,'<h2>Ważna informacja o ręcznym malowaniu</h2>') && substr_count($html,'<p>')>=4);
 pilot_check(str_contains(allegro_pilot_description_html('<script>alert(1)</script>'), '&lt;script&gt;') && !str_contains(allegro_pilot_description_html('<script>alert(1)</script>'),'<script>'));
 $cn=['id'=>'cn','name'=>'Kod taryfy celnej','type'=>'string','required'=>false,'requiredForProduct'=>false,'restrictions'=>['minLength'=>8,'maxLength'=>8]];
 pilot_check(allegro_pilot_cn(['material'=>'Beton'],$cn)==='68109900');
 pilot_check(allegro_pilot_cn(['name'=>'Betonowa figura','color'=>'betonowy'],$cn)==='');
 pilot_check(allegro_pilot_cn(['material'=>'Nieznany'],$cn)==='');
 pilot_check(allegro_pilot_cn(['material'=>'Beton'],array_replace($cn,['name'=>'Kod producenta']))==='');
 pilot_check(allegro_pilot_cn(['material'=>'Beton'],array_replace($cn,['restrictions'=>['maxLength'=>4]]))==='');
 pilot_check(allegro_pilot_parameter_row($cn,'68109100',['cn'=>'68109100'])['values']===['68109100']);
 $cnDictionary=array_replace($cn,['type'=>'dictionary','dictionary'=>[['id'=>'concrete','value'=>'6810 99 00']],'restrictions'=>[]]);
 pilot_check(allegro_pilot_cn(['material'=>'Beton'],$cnDictionary)==='concrete');
 pilot_check(allegro_pilot_cn(['material'=>'Beton'],array_replace($cnDictionary,['dictionary'=>[]]))==='');
 $manufacturer=['id'=>'maker-code','name'=>'Kod producenta','type'=>'string','required'=>false,'requiredForProduct'=>false];
 pilot_check(allegro_pilot_parameter_row($manufacturer,'',[])===null);
 foreach (['required','requiredForProduct'] as $reason) {
  try {allegro_pilot_parameter_row(array_replace($manufacturer,[$reason=>true]),'',[]);throw new LogicException('Required value accepted');}
  catch(RuntimeException $e){pilot_check(str_contains($e->getMessage(),'Kod producenta'));}
 }
 $condition=array_replace($manufacturer,['requiredIf'=>['parametersWithValue'=>[['id'=>'state','oneOfValueIds'=>['new']]],'parametersWithoutValue'=>[['id'=>'ean']]]]);
 pilot_check(allegro_pilot_parameter_required($condition,['state'=>'new','ean'=>'']));
 pilot_check(!allegro_pilot_parameter_required($condition,['state'=>'used','ean'=>'']));
 pilot_check(!allegro_pilot_parameter_required($condition,['state'=>'new','ean'=>'123']));
 pilot_check(allegro_pilot_parameter_row($condition,'',['state'=>'used'])===null);
 pilot_reject(fn()=>allegro_pilot_parameter_row($condition,'',['state'=>'new']));
 $range=['id'=>'weight','name'=>'Waga','type'=>'float','unit'=>'kg','required'=>true,'restrictions'=>['min'=>1,'max'=>10,'precision'=>1,'range'=>true]];
 pilot_check(allegro_pilot_parameter_row($range,['1.2','2.4'],[])['rangeValue']===['from'=>'1.2','to'=>'2.4']);
 foreach ([['2','1'],['1','11'],['1','2.22'],['1','']] as $bad) pilot_reject(fn()=>allegro_pilot_parameter_row($range,$bad,[]));
 $multi=['id'=>'brand','name'=>'Producent / marka','type'=>'dictionary','restrictions'=>['multipleChoices'=>true],
  'options'=>['dependsOnParameterId'=>'state'],'dictionary'=>[['id'=>'unknown','value'=>'Producent nieznany'],['id'=>'known','value'=>'Znany','dependsOnValueIds'=>['new']]]];
 pilot_check(allegro_pilot_parameter_row($multi,['unknown','known'],['state'=>'new'])['valuesIds']===['unknown','known']);
 pilot_reject(fn()=>allegro_pilot_parameter_row($multi,'known',['state'=>'used']));
 pilot_reject(fn()=>allegro_pilot_parameter_row($multi,'invented',[]));
 $optionalMeta=$meta;$optionalMeta['parameters'][]=$manufacturer;
 pilot_check(!str_contains(json_encode(allegro_pilot_payload($product,$optionalMeta,$input)),'maker-code'));
 $edited=array_replace($input,['description'=>"Ręcznie edytowany opis.\n\nDrugi akapit."]);
 pilot_check(allegro_pilot_payload($product,$meta,$edited)['description']['sections'][0]['items'][0]['content']==='<p>Ręcznie edytowany opis.</p><p>Drugi akapit.</p>');
 $productMeta=$meta;$productMeta['parameters'][2]['options']=['describesProduct'=>true];
 $withEan=array_replace($input,['parameters'=>$input['parameters']+['ean'=>'5901234123457']]);
 pilot_check(count(allegro_pilot_payload($product,$productMeta,$withEan)['productSet'][0]['product']['parameters'])===2);
 // Rendered required attributes, API reasons, CN hint and restrictions match PHP checks.
 if (!function_exists('e')) {function e($v): string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}}
 $control=static function($p,$product=[])use($manufacturer,$condition){ob_start();allegro_pilot_parameter_control($p,$product,[$manufacturer,$condition]);return ob_get_clean();};
 $optionalHtml=$control($manufacturer);
 pilot_check(str_contains($optionalHtml,'Opcjonalne') && !str_contains($optionalHtml,' required'));
 $requiredHtml=$control(array_replace($manufacturer,['requiredForProduct'=>true]));
 pilot_check(str_contains($requiredHtml,' required') && str_contains($requiredHtml,'requiredForProduct=true'));
 $conditionalHtml=$control($condition);
 pilot_check(str_contains($conditionalHtml,'Wymagane warunkowo') && str_contains($conditionalHtml,'brak wartości: ean') && !str_contains($conditionalHtml,' required'));
 pilot_check(str_contains($control($cn,['material'=>'Beton']),'value="68109900"') && str_contains($control($cn,['material'=>'Beton']),'maxlength="8"'));
 pilot_check(!str_contains($control($cn),'68109900'));
 if (!function_exists('csrf_token')) {function csrf_token(): string {return 'synthetic-csrf';}}
 ob_start();allegro_pilot_category_buttons($currentMeta+['productId'=>$pid,'nonce'=>'fixture']);$buttons=ob_get_clean();
 pilot_check(str_contains($buttons,'Wróć o poziom') && str_contains($buttons,'Wybierz kategorię od początku') && str_contains($buttons,'navigation_nonce'));
 // Official binary upload, including exact content type and safe server policy.
 $image=allegro_pilot_image_file('/uploads/figure.png');
 pilot_check($image['mime']==='image/png' && str_starts_with($image['bytes'],"\x89PNG"));
 foreach (['PROD','SANDBOX'] as $environment) {
  $seen=[];$uploadConfig=array_replace($config,['environment'=>$environment]);
  $uploaded=allegro_pilot_image_upload($uploadConfig,$image,'SYNTHETIC_ACCESS',static function($r)use(&$seen){$seen=$r;return ['status'=>201,'body'=>json_encode(['location'=>'https://a.allegroimg.com/original/fixture/image'])];});
  pilot_check($uploaded==='https://a.allegroimg.com/original/fixture/image' && $seen['body']===$image['bytes']);
  pilot_check($seen['url']===($environment==='PROD'?'https://upload.allegro.pl':'https://upload.allegro.pl.allegrosandbox.pl').'/sale/images');
  pilot_check(in_array('Content-Type: image/png',$seen['headers'],true));
  allegro_http_policy('POST',$seen['url']);
 }
 foreach (['https://evil.test/sale/images','https://api.allegro.pl/sale/images','https://upload.allegro.pl/sale/images?url=evil','https://upload.allegro.pl/sale/product-offers'] as $url) pilot_reject(fn()=>allegro_http_policy('POST',$url));
 foreach ([400,401,403,413,415,422,500] as $status) pilot_reject(fn()=>allegro_pilot_image_upload($config,$image,'SYNTHETIC_ACCESS',static fn($r)=>['status'=>$status,'body'=>'PRIVATE_BODY']));
 foreach (['not json',json_encode(['location'=>'https://evil.test/image']),json_encode([])] as $body) pilot_reject(fn()=>allegro_pilot_image_upload($config,$image,'SYNTHETIC_ACCESS',static fn($r)=>['status'=>201,'body'=>$body]));
 pilot_reject(fn()=>allegro_pilot_image_file('https://evil.test/uploads/figure.png'));
 pilot_reject(fn()=>allegro_pilot_image_file('/uploads/../figure.png'));
 pilot_reject(fn()=>allegro_pilot_image_file('/uploads/figure.png?secret=bad'));
 file_put_contents($root.'/uploads/not-image.png','not an image');
 pilot_reject(fn()=>allegro_pilot_image_file('/uploads/not-image.png'));
 file_put_contents($root.'/uploads/wrong.jpg',$image['bytes']);
 pilot_reject(fn()=>allegro_pilot_image_file('/uploads/wrong.jpg'));
 file_put_contents($root.'/uploads/too-large.png',str_repeat('x',10485761));
 pilot_reject(fn()=>allegro_pilot_image_file('/uploads/too-large.png'));
 $small=$image['bytes'];$small=substr_replace($small,pack('NN',10,10),16,8);
 file_put_contents($root.'/uploads/small.png',$small);
 pilot_reject(fn()=>allegro_pilot_image_file('/uploads/small.png'));
 $huge=substr_replace($image['bytes'],pack('NN',10000,10000),16,8);
 file_put_contents($root.'/uploads/huge.png',$huge);
 pilot_reject(fn()=>allegro_pilot_image_file('/uploads/huge.png'));
 file_put_contents($root.'/uploads/unsupported.gif',base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
 pilot_reject(fn()=>allegro_pilot_image_file('/uploads/unsupported.gif'));
 $payloadForUpload=allegro_pilot_payload($product,$meta,$input);
 $uploadedPayload=allegro_pilot_uploaded_payload($config,$payloadForUpload,$product,'SYNTHETIC_ACCESS',static fn($r)=>['status'=>201,'body'=>json_encode(['location'=>'https://a.allegroimg.com/original/fixture/image'])]);
 pilot_check($uploadedPayload['images']===['https://a.allegroimg.com/original/fixture/image'] && $uploadedPayload['productSet'][0]['product']['images']===$uploadedPayload['images']);
 $otherPayload=$payloadForUpload;$otherPayload['images']=['https://mgoutlet.pl/uploads/not-image.png'];
 pilot_reject(fn()=>allegro_pilot_uploaded_payload($config,$otherPayload,$product,'SYNTHETIC_ACCESS',static fn($r)=>throw new LogicException('must not upload')));
 $many=array_replace($product,['gallery'=>array_map(static fn($i)=>'/uploads/extra-'.$i.'.png',range(1,17))]);
 pilot_reject(fn()=>allegro_pilot_uploaded_payload($config,$payloadForUpload,$many,'SYNTHETIC_ACCESS',static fn($r)=>[]));
 $seed(allegro_scope_request());$uploadRequests=[];
 $uploadFailure=static function($r)use(&$uploadRequests){$uploadRequests[]=$r;return str_starts_with($r['url'],'https://upload.')?['status'=>422,'body'=>'PRIVATE']:['status'=>200,'body'=>json_encode(['totalCount'=>0,'offers'=>[]])];};
 $failedPreview=['payload'=>$payloadForUpload,'fingerprint'=>allegro_pilot_fingerprint($product),'seller'=>'123','environment'=>'SANDBOX','at'=>$now];
 pilot_reject(fn()=>allegro_pilot_write($config,$failedPreview,false,$uploadFailure,$product));
 pilot_check(empty($read()['pilot']) && count(array_filter($uploadRequests,static fn($r)=>$r['method']==='POST' && str_ends_with($r['url'],'/sale/product-offers')))===0);
 // Delivery lives outside the product description. Full receipt text fits 650 characters.
 pilot_check(allegro_pilot_length(allegro_pilot_receipt_note())===647);
 pilot_check($payloadForUpload['delivery']['handlingTime']==='P7D' && $payloadForUpload['delivery']['additionalInfo']===allegro_pilot_receipt_note());
 pilot_check(!str_contains($payloadForUpload['description']['sections'][0]['items'][0]['content'],'protokół'));
 pilot_reject(fn()=>allegro_pilot_payload($product,$meta,array_replace($input,['handling_confirmed'=>''])));
 pilot_reject(fn()=>allegro_pilot_payload($product,$meta,array_replace($input,['receipt_info'=>str_repeat('x',651)])));
 pilot_check(allegro_pilot_payload($product,$meta,array_replace($input,['receipt_info'=>'Ręcznie zmieniona informacja.']))['delivery']['additionalInfo']==='Ręcznie zmieniona informacja.');
 pilot_check(str_contains(allegro_pilot_gpsr_template(),'Nie jest zabawką.') && str_contains(allegro_pilot_gpsr_template(),'nie instrukcją')===false);
 pilot_reject(fn()=>allegro_pilot_payload($product,$meta,array_replace($input,['safety'=>allegro_pilot_gpsr_template(),'gpsr_confirmed'=>''])));
 pilot_reject(fn()=>allegro_pilot_payload($product,$meta,array_replace($input,['safety'=>''])));
 pilot_check(allegro_pilot_payload($product,$meta,array_replace($input,['safety'=>'Ręczne informacje producenta.']))['productSet'][0]['safetyInformation']['description']==='Ręczne informacje producenta.');
 // UNKNOWN recovery never erases the whole vault, and cannot reopen an existing/ambiguous offer.
 $seedUnknown=static function()use($config,$seed,$pid,$product){$seed(allegro_scope_request());allegro_locked($config,static function($v,$k)use($config,$pid,$product){$v['unrelatedMarker']='preserve';$v['pilot']=['productId'=>$pid,'status'=>'UNKNOWN','fingerprint'=>allegro_pilot_fingerprint($product),'failure'=>['httpStatus'=>422]];allegro_write_vault($config,$k,$v);});};
 $absent=static fn($r)=>['status'=>200,'body'=>json_encode(['offers'=>[],'totalCount'=>0,'count'=>0])];
 $seedUnknown();$clock=$now+100;
 allegro_pilot_unlock_check($config,$pid,false,'',$absent,$clock);
 pilot_check(count($read()['pilot']['absenceChecks'])===1);
 pilot_reject(fn()=>allegro_pilot_unlock_check($config,$pid,true,'wrong',$absent,$clock+1));
 pilot_reject(fn()=>allegro_pilot_unlock_check($config,$pid,false,'',$absent,$clock+1));
 allegro_pilot_unlock_check($config,$pid,false,'',$absent,$clock+60);
 pilot_check(count($read()['pilot']['absenceChecks'])===2);
 $recoveryNonce=$read()['pilot']['recoveryNonce'];
 allegro_pilot_unlock_check($config,$pid,true,$recoveryNonce,$absent,$clock+61);
 pilot_check(empty($read()['pilot']) && $read()['unrelatedMarker']==='preserve' && $read()['tokens']['access']==='SYNTHETIC_ACCESS');
 pilot_check($read()['pilotFailures'][0]['httpStatus']===422 && count($read()['pilotFailures'][0]['absenceChecks'])===2);
 // A reopened attempt can create exactly one new INACTIVE draft, using uploaded URLs.
 allegro_pilot_write($config,$failedPreview,false,$transport,$product);
 pilot_check($read()['pilot']['status']==='INACTIVE' && count($read()['pilotFailures'])===1);
 pilot_check($read()['pilot']['payload']['images']===['https://a.allegroimg.com/original/fixture/image']);
 $remoteOffer['delivery']['handlingTime']='PT168H';allegro_pilot_check($config,$transport);pilot_check($read()['pilot']['status']==='INACTIVE');
 $remoteOffer['delivery']['handlingTime']='P2D';pilot_reject(fn()=>allegro_pilot_check($config,$transport));
 $remoteOffer['delivery']['handlingTime']='P7D';
 pilot_reject(fn()=>allegro_pilot_write($config,$failedPreview,false,$transport,$product));
 // Even a newly appearing offer on the final confirmation read prevents reopening.
 $seedUnknown();allegro_pilot_unlock_check($config,$pid,false,'',$absent,$clock);
 allegro_pilot_unlock_check($config,$pid,false,'',$absent,$clock+60);$proof=$read()['pilot']['recoveryNonce'];
 pilot_reject(fn()=>allegro_pilot_unlock_check($config,$pid,true,$proof,static fn($r)=>['status'=>200,'body'=>json_encode(['offers'=>[['id'=>'987']],'totalCount'=>1])],$clock+61));
 pilot_check(isset($read()['pilot']) && empty($read()['pilot']['absenceChecks']));
 foreach ([['offers'=>[['id'=>'987']],'totalCount'=>1],['offers'=>[]],['offers'=>[],'totalCount'=>'0'],['offers'=>[],'totalCount'=>1],['offers'=>[],'totalCount'=>0,'count'=>1]] as $bad) {
  $seedUnknown();allegro_pilot_unlock_check($config,$pid,false,'',$absent,$clock);
  pilot_reject(fn()=>allegro_pilot_unlock_check($config,$pid,false,'',static fn($r)=>['status'=>200,'body'=>json_encode($bad)],$clock+60));
  pilot_check(isset($read()['pilot']) && empty($read()['pilot']['absenceChecks']));
 }
 $seedUnknown();
 try {allegro_pilot_unlock_check($config,$pid,false,'',static fn($r)=>['status'=>200,'body'=>json_encode(['offers'=>[['id'=>'987']],'totalCount'=>1])],$clock);throw new LogicException('must block');}
 catch(RuntimeException $e){pilot_check(str_contains($e->getMessage(),'987'));}
 $seedUnknown();allegro_pilot_unlock_check($config,$pid,false,'',$absent,$clock);
 pilot_reject(fn()=>allegro_pilot_unlock_check($config,$pid,false,'',static fn($r)=>throw new AllegroSafeException('network'),$clock+60));
 pilot_check(empty($read()['pilot']['absenceChecks']));
 $seedUnknown();
 allegro_locked($config,static function($v,$k)use($config){$v['pilot']['offerId']='987';allegro_write_vault($config,$k,$v);});
 pilot_reject(fn()=>allegro_pilot_unlock_check($config,$pid,false,'',$absent,$clock));
 $seed(allegro_scope_request());
 $called=false;
 pilot_reject(function()use($config,&$called){allegro_pilot_api($config,'GET','/sale/shipping-rates?token=SECRET','SYNTHETIC_TOKEN',[],function($r)use(&$called){$called=true;return [];});});
 pilot_check(!$called);

 pilot_check(count(allegro_pilot_products(['products'=>[$product]]))===1);
 pilot_check(allegro_pilot_products(['products'=>[array_replace($product,['shopStatus'=>'Sprzedany'])]])===[]);
 pilot_reject(fn()=>allegro_pilot_products(['products'=>[$product,$product]]));
 $payload=allegro_pilot_payload($product,$meta,$input);
 pilot_check($payload['publication']['status']==='INACTIVE' && $payload['stock']['available']===1 && $payload['sellingMode']['price']['amount']==='150.00');
 pilot_check(count($payload['productSet'][0]['product']['parameters'])===1);pilot_check(!str_contains(json_encode($payload),'"id":"ean"'));
 foreach (['price'=>'','quantity'=>'0','stock_confirmed'=>'','shipping'=>'foreign','producer'=>'','gpsr_confirmed'=>''] as $f=>$value)pilot_reject(fn()=>allegro_pilot_payload($product,$meta,array_replace($input,[$f=>$value])));
 $gtin=$meta;$gtin['parameters'][2]['requiredForProduct']=true;
 pilot_reject(fn()=>allegro_pilot_payload($product,$gtin,$input));
 $conditional=$meta;$conditional['parameters'][2]['requiredIf']=['parametersWithValue'=>[['id'=>'11323','oneOfValueIds'=>['new']]],'parametersWithoutValue'=>[]];
 pilot_reject(fn()=>allegro_pilot_payload($product,$conditional,$input));
 pilot_reject(fn()=>allegro_pilot_payload($product,$meta,array_replace($input,['parameters'=>['11323'=>'foreign','material'=>'Beton']])));
 pilot_reject(fn()=>allegro_pilot_payload(array_replace($product,['image'=>'https://evil.test/a.jpg']),$meta,$input));
 pilot_reject(fn()=>allegro_pilot_location($config,'https://evil.test/sale/product-offers/789','789',false));
 pilot_reject(fn()=>allegro_pilot_location($config,'/sale/product-offers/999/operations/op-1','789',true));
 $preview=['payload'=>$payload,'fingerprint'=>allegro_pilot_fingerprint($product),'seller'=>'123','environment'=>'SANDBOX','at'=>$now];
 $requests=[];
 $seed(ALLEGRO_B_SCOPE);pilot_reject(fn()=>allegro_pilot_write($config,$preview,false,$transport,$product));pilot_check($requests===[]);
 // Starting a scope upgrade neither disconnects nor overwrites the working token, even on failure.
 pilot_reject(fn()=>allegro_device_start($config,$binding,$now,static fn($r)=>throw new AllegroSafeException('network')));
 pilot_check($read()['tokens']['access']==='SYNTHETIC_ACCESS' && $read()['status']==='connected');
 $device=static fn($r)=>['status'=>200,'body'=>json_encode(['user_code'=>'code123','device_code'=>'DEVICE_CODE','expires_in'=>3600,'interval'=>1])];
 allegro_device_start($config,$binding,$now,$device);pilot_check($read()['tokens']['scope']===ALLEGRO_B_SCOPE);
 allegro_locked($config,static function($v,$k)use($config){$v['device']['next_poll_at']=time()-1;allegro_write_vault($config,$k,$v);});
 allegro_device_poll($config,$binding,$now,static fn($r)=>['status'=>400,'body'=>json_encode(['error'=>'access_denied'])]);
 pilot_check($read()['tokens']['access']==='SYNTHETIC_ACCESS' && $read()['status']==='connected');
 $seed(allegro_scope_request());allegro_pilot_write($config,$preview,false,$transport,$product);
 pilot_check($read()['pilot']['offerId']==='789' && $read()['pilot']['status']==='INACTIVE');
 $writes=count(array_filter($requests,static fn($r)=>$r['method']==='POST' && str_ends_with($r['url'],'/sale/product-offers')));
 pilot_reject(fn()=>allegro_pilot_write($config,$preview,false,$transport,$product));
 pilot_check(count(array_filter($requests,static fn($r)=>$r['method']==='POST' && str_ends_with($r['url'],'/sale/product-offers')))===$writes);
 $changedPreview=$preview;$changedPreview['payload']['sellingMode']['price']['amount']='999.00';pilot_reject(fn()=>allegro_pilot_write($config,$changedPreview,true,$transport,$product));
 allegro_pilot_write($config,$preview,true,$transport,$product);pilot_check($read()['pilot']['status']==='ACTIVE');
 pilot_reject(fn()=>allegro_pilot_write($config,$preview,true,$transport,$product));
 pilot_check(count(array_filter($requests,static fn($r)=>$r['method']==='PATCH'))===1);
 // 202 remains PROCESSING until operation 303 and an authoritative GET confirms INACTIVE.
 $seed(allegro_scope_request());$requests=[];$createMode='202';$operationPending=true;
 allegro_pilot_write($config,$preview,false,$transport,$product);pilot_check($read()['pilot']['status']==='PROCESSING');
 pilot_reject(fn()=>allegro_pilot_write($config,$preview,true,$transport,$product));
 allegro_locked($config,static function($v,$k)use($config){$v['pilot']['nextCheck']=0;allegro_write_vault($config,$k,$v);});
 allegro_pilot_check($config,$transport);pilot_check($read()['pilot']['status']==='PROCESSING');
 $operationPending=false;allegro_locked($config,static function($v,$k)use($config){$v['pilot']['nextCheck']=0;allegro_write_vault($config,$k,$v);});
 allegro_pilot_check($config,$transport);pilot_check($read()['pilot']['status']==='INACTIVE');
 // Network uncertainty after POST locks creation; no automatic retry or duplicate.
 $seed(allegro_scope_request());$requests=[];$postThrows=true;
 pilot_reject(fn()=>allegro_pilot_write($config,$preview,false,$transport,$product));
 pilot_check($read()['pilot']['status']==='UNKNOWN');pilot_reject(fn()=>allegro_pilot_write($config,$preview,false,$transport,$product));
 pilot_check(count(array_filter($requests,static fn($r)=>$r['method']==='POST' && str_ends_with($r['url'],'/sale/product-offers')))===1);
 echo 'PASS: Allegro one-figure pilot — '.$checks." assertions; synthetic transport only\n";
} finally {
 $remove=static function($path)use(&$remove){if(is_dir($path)){foreach(scandir($path) as $x)if($x!=='.'&&$x!=='..')$remove($path.'/'.$x);rmdir($path);}else unlink($path);};$remove($root);
}
