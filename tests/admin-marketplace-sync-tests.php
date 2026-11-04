<?php
declare(strict_types=1);
require __DIR__.'/../hosting/getspace/admin/marketplace-sync.php';
require_once __DIR__.'/../hosting/getspace/admin/allegro/lib.php';
require_once __DIR__.'/../hosting/getspace/admin/olx/publish-lib.php';
$n=0;function sync_check(bool $ok): void {global $n;$n++;if(!$ok)throw new RuntimeException('Sync assertion '.$n.' failed');}
function sync_reject(callable $f): void {try{$f();}catch(Throwable $e){sync_check(true);return;}throw new RuntimeException('Expected rejection');}
$root=sys_get_temp_dir().'/hgo-sync-'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/olx',0700);mkdir($root.'/olx/publications',0700);mkdir($root.'/allegro',0700);mkdir($root.'/cache',0700);
$ids=array_map(static fn($i)=>'prd_'.str_pad(dechex($i),32,'0',STR_PAD_LEFT),range(1,23));$a=$ids[0];$b=$ids[1];
$products=array_map(static fn($id)=>['productId'=>$id],$ids);
$ac=['environment'=>'PROD','auth_flow'=>'DEVICE','client_id'=>'SYNTHETIC','key'=>base64_encode(random_bytes(32)),'storage_root'=>$root.'/allegro'];$oc=['dir'=>$root.'/olx'];
$binding=['productId'=>$a,'external_id'=>$a,'advert_id'=>'100','status'=>'limited','phase'=>'created'];
$reply=static fn($status='active',$extra=[])=>['http'=>200,'data'=>['data'=>array_replace(['id'=>100,'external_id'=>$a,'status'=>$status,'url'=>'https://www.olx.pl/d/oferta/test.html'],$extra)]];
$offer=static fn($id,$num,$status)=>['id'=>(string)$num,'external'=>['id'=>$id],'publication'=>['status'=>$status]];
$remove=static function($p)use(&$remove){if(is_dir($p)){foreach(scandir($p)as$f)if($f!=='.'&&$f!=='..')$remove($p.'/'.$f);rmdir($p);}else unlink($p);};
try {
 foreach(['active'=>'active','limited'=>'limited','outdated'=>'inactive','inactive'=>'inactive','moderated'=>'error','removed'=>'inactive','disabled'=>'publishing','new'=>'publishing']as$status=>$expected){$row=admin_marketplace_olx_observation($a,$binding,$reply($status),1000);sync_check($row['key']===$expected&&$row['updated']===1000);}
 sync_check(admin_marketplace_olx_observation($a,$binding,$reply(),1000)['label']==='Wystawione');
 $known=$binding;$known['advert_id']='110883622';$r=$reply('limited',['id'=>'110883622']);sync_check(str_contains(admin_marketplace_olx_observation($a,$known,$r,1000)['label'],'Rozbieżność'));
 foreach([['id'=>101],['external_id'=>$b],['status'=>'madeup'],['url'=>'https://evil.test/'],['valid_to'=>'invalid']]as$bad)sync_reject(fn()=>admin_marketplace_olx_observation($a,$binding,$reply('active',$bad),1000));
 sync_reject(fn()=>admin_marketplace_olx_observation($a,$binding,['http'=>429],1000));
 foreach(['ACTIVE'=>'active','INACTIVE'=>'draft','ACTIVATING'=>'publishing','ENDED'=>'inactive']as$status=>$expected)sync_check(admin_marketplace_allegro_observation($a,[['id'=>'123','status'=>$status]],1000)['key']===$expected);
 sync_check(admin_marketplace_allegro_observation($a,[],1000)['key']==='unknown');sync_check(str_contains(admin_marketplace_allegro_observation($a,[['id'=>'1'],['id'=>'2']],1000)['detail'],'Wiele'));
 [$found,$offset,$total]=admin_marketplace_allegro_page([$a,$b],['offers'=>[$offer($a,1,'ACTIVE'),$offer($b,2,'ENDED')],'totalCount'=>2],[],0);
 sync_check(count($found)===2&&$offset===2&&$total===2);
 foreach([['offers'=>[$offer('wrong',1,'ACTIVE')],'totalCount'=>1],['offers'=>[],'totalCount'=>2],['offers'=>[],'totalCount'=>'0'],['offers'=>[$offer($a,1,'BAD')],'totalCount'=>1]]as$bad)sync_reject(fn()=>admin_marketplace_allegro_page([$a],$bad,[],0));
 foreach(['/sale/offers?external.id='.$a.'&limit=100&offset=0','/sale/offers?external.id='.$a.'&external.id='.$b.'&limit=100&offset=100']as$path)sync_check(allegro_pilot_path('GET',$path));
 foreach(['/sale/offers?external.id='.$a.'&limit=100&offset=-1','/sale/offers?external.id='.$a.'&limit=100&offset=0&token=bad','/sale/offers?external.id=evil&limit=100&offset=0']as$path)sync_check(!allegro_pilot_path('GET',$path));
 file_put_contents($root.'/olx/publications/'.$a.'.json',json_encode($binding));$before=hash_file('sha256',$root.'/olx/publications/'.$a.'.json');
 $calls=[];$ag=static function($path)use(&$calls,$a,$b,$offer){$calls[]=['allegro',$path];return ['offers'=>[$offer($a,1,'ACTIVE'),$offer($b,2,'INACTIVE')],'totalCount'=>2];};
 $og=static function($method,$path,$body)use(&$calls,$reply){$calls[]=['olx',$method,$path];return $reply();};
 $cache=admin_marketplace_sync($products,$ac,$oc,$ag,$og,$root.'/cache',1000);
 sync_check(count($calls)===2&&$calls[0][1]==='GET'&&$cache['work']['allegro_index']===20&&$cache['work']['olx']===1&&!$cache['work']['done']);
 sync_check($cache['rows'][$a]['olx']['key']==='active'&&$cache['rows'][$a]['allegro']['key']==='active');
 sync_check($cache['work']['olx_active']===1&&$cache['work']['allegro_offers']===2&&$cache['work']['allegro_active']===1);
 sync_check($cache['work']['allegro']===20&&$cache['work']['updated']===3); // 18 unknown -> unknown results are not changes.
 sync_check(admin_marketplace_sync_summary($cache['work'])===[
  'OLX: sprawdzone ogłoszenia: 1. Aktywne według API: 1.',
  'Allegro: sprawdzone produkty: 20. Znalezione oferty: 2, w tym aktywne: 1.',
  'Łącznie zmienione statusy: 3. Błędy: 0.']);
 sync_check(hash_file('sha256',$root.'/olx/publications/'.$a.'.json')===$before&&!is_dir($root.'/allegro/prod'));
 sync_reject(fn()=>admin_marketplace_sync($products,$ac,$oc,$ag,$og,$root.'/cache',1001));sync_check(count($calls)===2);
 $empty=static fn($path)=>['offers'=>[],'totalCount'=>0];
 $cache=admin_marketplace_sync($products,$ac,$oc,$empty,$og,$root.'/cache',1005);
 sync_check($cache['work']['done']&&$cache['last_success']===1005&&$cache['work']['allegro']===23);
 sync_check($cache['work']['allegro_offers']===2&&$cache['work']['allegro_active']===1&&$cache['work']['olx_active']===1&&$cache['work']['updated']===3);
 $cache=admin_marketplace_sync($products,$ac,$oc,static function(){throw new RuntimeException('HTTP 429 PRIVATE_TOKEN');},static function(){throw new RuntimeException('timeout PRIVATE_TOKEN');},$root.'/cache',1065);
 sync_check($cache['rows'][$a]['olx']['key']==='active'&&$cache['rows'][$a]['olx']['updated']===1000&&isset($cache['rows'][$a]['olx']['refresh_error']));
 sync_check($cache['rows'][$a]['allegro']['key']==='active'&&$cache['rows'][$a]['allegro']['updated']===1000&&$cache['last_success']===1005);
 sync_check(!str_contains(file_get_contents($root.'/cache/statuses.json'),'PRIVATE_TOKEN')&&$cache['next_at']===1125);
 sync_check($cache['work']['olx']===0&&$cache['work']['olx_active']===0&&$cache['work']['allegro']===0&&$cache['work']['allegro_offers']===0&&$cache['work']['allegro_active']===0&&$cache['work']['updated']===0&&$cache['work']['errors']===2);
 $rows=[0=>['olx'=>admin_marketplace_unknown('olx'),'allegro'=>admin_marketplace_unknown('allegro')]];
 $applied=admin_marketplace_apply_cache([['productId'=>$a]],$rows,$cache,$cache['context']);sync_check($applied[0]['olx']['key']==='active'&&str_contains($applied[0]['olx']['detail'],'nie powiodła'));
 sync_check(admin_marketplace_apply_cache([['productId'=>$a]],$rows,$cache,'different')===$rows);
 // Resume a paginated group without committing any partial result; duplicate signatures stay ambiguous.
 $remove($root.'/cache');mkdir($root.'/cache',0700);$page=0;
 $paged=static function($path)use(&$page,$a,$offer){$page++;return ['offers'=>[$offer($a,$page,'ACTIVE')],'totalCount'=>2];};
 $cache=admin_marketplace_sync(array_slice($products,0,2),$ac,$oc,$paged,$og,$root.'/cache',2000);
 sync_check($cache['work']['offset']===1&&!isset($cache['rows'][$a]['allegro']));
 sync_check($cache['work']['allegro']===0&&$cache['work']['allegro_offers']===0&&$cache['work']['allegro_active']===0);
 $cache=admin_marketplace_sync(array_slice($products,0,2),$ac,$oc,$paged,$og,$root.'/cache',2005);
 sync_check($cache['work']['done']&&$page===2&&$cache['rows'][$a]['allegro']['key']==='unknown'&&str_contains($cache['rows'][$a]['allegro']['detail'],'Wiele'));
 sync_check(hash_file('sha256',$root.'/olx/publications/'.$a.'.json')===$before);
 sync_check($cache['work']['allegro']===2&&$cache['work']['allegro_offers']===2&&$cache['work']['allegro_active']===2&&$cache['work']['updated']===1); // Ambiguous offers counted, never randomly assigned.
 $files=glob($root.'/cache/.statuses-*');sync_check($files===[]);
 // Independent platform failure, nonblocking concurrency and safe cache failure.
 $remove($root.'/cache');mkdir($root.'/cache',0700);
 $badOlx=static function(){throw new RuntimeException('HTTP 429');};
 $cache=admin_marketplace_sync(array_slice($products,0,2),$ac,$oc,$ag,$badOlx,$root.'/cache',3000);
 sync_check($cache['rows'][$a]['allegro']['key']==='active'&&$cache['work']['errors']===1&&$cache['next_at']===3060);
 $lock=fopen($root.'/cache/sync.lock','c');flock($lock,LOCK_EX|LOCK_NB);
 sync_reject(fn()=>admin_marketplace_sync($products,$ac,$oc,$ag,$og,$root.'/cache',4000));flock($lock,LOCK_UN);fclose($lock);
 $bytes=file_get_contents($root.'/cache/statuses.json');
 sync_reject(fn()=>admin_marketplace_cache_write($root.'/cache',['large'=>str_repeat('x',4000001)]));sync_check(file_get_contents($root.'/cache/statuses.json')===$bytes);
 sync_check(admin_marketplace_olx_observation($a,$binding,$reply('active',['valid_to'=>'2000-01-01T00:00:00Z']),time())['key']==='limited');
 $remove($root.'/cache');mkdir($root.'/cache',0700);$sandbox=$ac;$sandbox['environment']='SANDBOX';$used=false;
 $cache=admin_marketplace_sync(array_slice($products,0,1),$sandbox,$oc,static function()use(&$used){$used=true;return [];},$og,$root.'/cache',5000);
 sync_check(!$used&&$cache['rows'][$a]['olx']['key']==='active'&&$cache['work']['errors']===1);
 sync_check(!str_contains(file_get_contents($root.'/cache/statuses.json'),$ac['key']));
 // A fresh pass with identical confirmed states counts zero changes; limited is not active.
 $remove($root.'/cache');mkdir($root.'/cache',0700);
 $small=array_slice($products,0,2);
 $cache=admin_marketplace_sync($small,$ac,$oc,$ag,$og,$root.'/cache',6000);
 $cache=admin_marketplace_sync($small,$ac,$oc,$ag,$og,$root.'/cache',6005);
 $cache=admin_marketplace_sync($small,$ac,$oc,$ag,$og,$root.'/cache',6065);
 sync_check($cache['work']['updated']===0&&$cache['work']['olx']===1&&$cache['work']['allegro']===2&&$cache['work']['allegro_offers']===2);
 $cache=admin_marketplace_sync($small,$ac,$oc,$ag,$og,$root.'/cache',6070);
 $cache=admin_marketplace_sync($small,$ac,$oc,$empty,static fn()=>$reply('limited'),$root.'/cache',6130);
 sync_check($cache['work']['olx_active']===0&&$cache['work']['allegro_offers']===0&&$cache['work']['allegro_active']===0&&$cache['work']['updated']===3);
 // Preserve old in-progress work. New counters cannot be reconstructed from its product count.
 $remove($root.'/cache');mkdir($root.'/cache',0700);$page=0;
 $cache=admin_marketplace_sync($small,$ac,$oc,$paged,$og,$root.'/cache',7000);
 unset($cache['work']['counters_version'],$cache['work']['olx_active'],$cache['work']['allegro_offers'],$cache['work']['allegro_active']);
 admin_marketplace_cache_write($root.'/cache',$cache);
 $cache=admin_marketplace_sync($small,$ac,$oc,$paged,$og,$root.'/cache',7005);
 sync_check($page===2&&$cache['work']['done']&&!isset($cache['work']['counters_version']));
 sync_check(str_contains(implode(' ',admin_marketplace_sync_summary($cache['work'])),'Starszy zapis: pełne liczniki'));
 $cache=admin_marketplace_sync($small,$ac,$oc,$empty,$og,$root.'/cache',7065);
 sync_check($cache['work']['counters_version']===2&&$cache['work']['allegro_offers']===0);
 echo 'PASS: marketplace sync — '.$n." assertions; simulated GET only; publication records unchanged\n";
}finally{$remove($root);}
