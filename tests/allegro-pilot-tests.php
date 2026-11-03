<?php
declare(strict_types=1);
require __DIR__.'/../hosting/getspace/admin/allegro/lib.php';
require __DIR__.'/../hosting/getspace/catalog.php';
require __DIR__.'/../hosting/getspace/admin/product-identity.php';
$checks=0;
function pilot_check(bool $ok): void {global $checks;if(!$ok)throw new RuntimeException('Pilot assertion failed');$checks++;}
function pilot_reject(callable $f): void {try{$f();}catch(Throwable $e){pilot_check(true);return;}throw new RuntimeException('Unsafe pilot acceptance');}
$root=sys_get_temp_dir().'/hgo-pilot-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/vault',0700);mkdir($root.'/uploads',0700);
define('SITE_ROOT',$root);file_put_contents($root.'/uploads/figure.jpg','synthetic-image');
$config=['environment'=>'SANDBOX','auth_flow'=>'DEVICE','client_id'=>'SYNTHETIC_CLIENT','secret'=>'SYNTHETIC_SECRET','key'=>base64_encode(random_bytes(32)),
 'storage_root'=>$root.'/vault','enabled'=>true];
$pid='prd_'.str_repeat('a',32);$now=time();$binding=str_repeat('b',64);
$product=['productId'=>$pid,'saleType'=>'garden_figure','shopVisible'=>true,'shopStatus'=>'Dostępny','name'=>'Figura ogrodowa testowa','grossPrice'=>'150 zł','description'=>'Opis katalogowy','image'=>'/uploads/figure.jpg'];
$meta=['at'=>$now,'environment'=>'SANDBOX','seller'=>'123','category'=>['id'=>'148446','name'=>'Figury','leaf'=>true],
 'parameters'=>[['id'=>'11323','name'=>'Stan','type'=>'dictionary','required'=>true,'requiredForProduct'=>false,'dictionary'=>[['id'=>'new','value'=>'Nowy']]],
 ['id'=>'material','name'=>'Materiał','type'=>'string','requiredForProduct'=>true,'restrictions'=>['minLength'=>2,'maxLength'=>20]],
 ['id'=>'ean','name'=>'EAN','type'=>'string','requiredForProduct'=>false,'required'=>false]],
 'shippingRates'=>[['id'=>'shipping','name'=>'Kurier']],'returnPolicies'=>[['id'=>'returns','name'=>'Zwroty']],
 'impliedWarranties'=>[['id'=>'warranty','name'=>'Reklamacje']],'responsibleProducers'=>[['id'=>'producer','name'=>'Producent']], 'responsiblePersons'=>[]];
$input=['name'=>$product['name'],'description'=>$product['description'],'price'=>'150,00','quantity'=>'1','stock_confirmed'=>'yes',
 'parameters'=>['11323'=>'new','material'=>'Beton'],'shipping'=>'shipping','return'=>'returns','warranty'=>'warranty','producer'=>'producer',
 'safety'=>'Instrukcja bezpieczeństwa potwierdzona przez administratora.','gpsr_confirmed'=>'yes'];
$seed=static function(string $scope)use($config,$now){allegro_locked($config,static function($v,$k)use($config,$now,$scope){allegro_write_vault($config,$k,
 ['status'=>'connected','account'=>['id'=>'123','login'=>'fixture'],'tokens'=>['access'=>'SYNTHETIC_ACCESS','refresh'=>'SYNTHETIC_REFRESH','expires_at'=>$now+3600,'scope'=>$scope]]);});};
$read=static fn()=>allegro_locked($config,static fn($v,$k)=>$v);
$requests=[];$createMode='201';$operationPending=true;$postThrows=false;$remoteOffer=[];
$transport=static function($r)use(&$requests,&$createMode,&$operationPending,&$postThrows,&$remoteOffer,$config){
 $requests[]=$r;$path=substr($r['url'],strlen(allegro_hosts($config['environment'])['api']));
 $reply=static fn($d,$status=200,$h=[])=>['status'=>$status,'body'=>json_encode($d),'headers'=>$h];
 if($r['method']==='GET' && str_starts_with($path,'/sale/offers?'))return $reply(['totalCount'=>0,'offers'=>[]]);
 if($r['method']==='POST') {
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
  '/sale/categories'=>['categories'=>[['id'=>'101','name'=>'Dom i Ogród','leaf'=>false]]],
  '/sale/categories?parent.id=101'=>['categories'=>[['id'=>'202','name'=>'Ogród','leaf'=>false]]],
  '/sale/categories?parent.id=202'=>['categories'=>[['id'=>'303','name'=>'Przedmioty dekoracyjne','leaf'=>false]]],
  '/sale/categories?parent.id=303'=>['categories'=>[['id'=>'404','name'=>'Rzeźby i figury','leaf'=>true]]],
 ];
 $categoryReads=[];
 $getCategory=static function($p)use($tree,&$categoryReads){$categoryReads[]=$p;return $tree[$p];};
 $resolved=allegro_pilot_category($getCategory);
 pilot_check($resolved===['id'=>'404','name'=>'Rzeźby i figury','leaf'=>true]);
 pilot_check($categoryReads===array_keys($tree));
 foreach (['missing','duplicate','not-leaf','missing-leaf','invalid-id','premature-leaf'] as $case) {
  $badTree=$tree;$last='/sale/categories?parent.id=303';
  if($case==='missing')$badTree[$last]['categories']=[];
  if($case==='duplicate')$badTree[$last]['categories'][]=$badTree[$last]['categories'][0];
  if($case==='not-leaf')$badTree[$last]['categories'][0]['leaf']=false;
  if($case==='missing-leaf')unset($badTree[$last]['categories'][0]['leaf']);
  if($case==='invalid-id')$badTree[$last]['categories'][0]['id']='404?token=bad';
  if($case==='premature-leaf')$badTree['/sale/categories']['categories'][0]['leaf']=true;
  pilot_reject(fn()=>allegro_pilot_category(static fn($p)=>$badTree[$p]));
 }
 $seed(allegro_scope_request());$metadataReads=[];
 $metaTransport=static function($r)use($config,$tree,$meta,&$metadataReads){
  $path=substr($r['url'],strlen(allegro_hosts($config['environment'])['api']));$metadataReads[]=$path;
  if(isset($tree[$path]))$body=$tree[$path];
  elseif($path==='/sale/categories/404/parameters')$body=['parameters'=>$meta['parameters']];
  else {
   $keys=['/sale/shipping-rates'=>'shippingRates','/after-sales-service-conditions/return-policies'=>'returnPolicies',
    '/after-sales-service-conditions/implied-warranties'=>'impliedWarranties','/sale/responsible-producers'=>'responsibleProducers',
    '/sale/responsible-persons'=>'responsiblePersons'];
   if(!isset($keys[$path]))throw new LogicException('Unexpected metadata path');
   $body=[$keys[$path]=>$meta[$keys[$path]]];
  }
  return ['status'=>200,'body'=>json_encode($body)];
 };
 $currentMeta=allegro_pilot_metadata($config,$metaTransport);
 pilot_check($currentMeta['category']['id']==='404' && $currentMeta['parameters']===$meta['parameters']);
 pilot_check($metadataReads===[...array_keys($tree),'/sale/categories/404/parameters','/sale/shipping-rates',
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
 $seed(ALLEGRO_B_SCOPE);pilot_reject(fn()=>allegro_pilot_write($config,$preview,false,$transport));pilot_check($requests===[]);
 // Starting a scope upgrade neither disconnects nor overwrites the working token, even on failure.
 pilot_reject(fn()=>allegro_device_start($config,$binding,$now,static fn($r)=>throw new AllegroSafeException('network')));
 pilot_check($read()['tokens']['access']==='SYNTHETIC_ACCESS' && $read()['status']==='connected');
 $device=static fn($r)=>['status'=>200,'body'=>json_encode(['user_code'=>'code123','device_code'=>'DEVICE_CODE','expires_in'=>3600,'interval'=>1])];
 allegro_device_start($config,$binding,$now,$device);pilot_check($read()['tokens']['scope']===ALLEGRO_B_SCOPE);
 allegro_locked($config,static function($v,$k)use($config){$v['device']['next_poll_at']=time()-1;allegro_write_vault($config,$k,$v);});
 allegro_device_poll($config,$binding,$now,static fn($r)=>['status'=>400,'body'=>json_encode(['error'=>'access_denied'])]);
 pilot_check($read()['tokens']['access']==='SYNTHETIC_ACCESS' && $read()['status']==='connected');
 $seed(allegro_scope_request());allegro_pilot_write($config,$preview,false,$transport);
 pilot_check($read()['pilot']['offerId']==='789' && $read()['pilot']['status']==='INACTIVE');
 $writes=count(array_filter($requests,static fn($r)=>$r['method']==='POST'));
 pilot_reject(fn()=>allegro_pilot_write($config,$preview,false,$transport));
 pilot_check(count(array_filter($requests,static fn($r)=>$r['method']==='POST'))===$writes);
 $changedPreview=$preview;$changedPreview['payload']['sellingMode']['price']['amount']='999.00';pilot_reject(fn()=>allegro_pilot_write($config,$changedPreview,true,$transport));
 allegro_pilot_write($config,$preview,true,$transport);pilot_check($read()['pilot']['status']==='ACTIVE');
 pilot_reject(fn()=>allegro_pilot_write($config,$preview,true,$transport));
 pilot_check(count(array_filter($requests,static fn($r)=>$r['method']==='PATCH'))===1);
 // 202 remains PROCESSING until operation 303 and an authoritative GET confirms INACTIVE.
 $seed(allegro_scope_request());$requests=[];$createMode='202';$operationPending=true;
 allegro_pilot_write($config,$preview,false,$transport);pilot_check($read()['pilot']['status']==='PROCESSING');
 pilot_reject(fn()=>allegro_pilot_write($config,$preview,true,$transport));
 allegro_locked($config,static function($v,$k)use($config){$v['pilot']['nextCheck']=0;allegro_write_vault($config,$k,$v);});
 allegro_pilot_check($config,$transport);pilot_check($read()['pilot']['status']==='PROCESSING');
 $operationPending=false;allegro_locked($config,static function($v,$k)use($config){$v['pilot']['nextCheck']=0;allegro_write_vault($config,$k,$v);});
 allegro_pilot_check($config,$transport);pilot_check($read()['pilot']['status']==='INACTIVE');
 // Network uncertainty after POST locks creation; no automatic retry or duplicate.
 $seed(allegro_scope_request());$requests=[];$postThrows=true;
 pilot_reject(fn()=>allegro_pilot_write($config,$preview,false,$transport));
 pilot_check($read()['pilot']['status']==='UNKNOWN');pilot_reject(fn()=>allegro_pilot_write($config,$preview,false,$transport));
 pilot_check(count(array_filter($requests,static fn($r)=>$r['method']==='POST'))===1);
 echo 'PASS: Allegro one-figure pilot — '.$checks." assertions; synthetic transport only\n";
} finally {
 $remove=static function($path)use(&$remove){if(is_dir($path)){foreach(scandir($path) as $x)if($x!=='.'&&$x!=='..')$remove($path.'/'.$x);rmdir($path);}else unlink($path);};$remove($root);
}
