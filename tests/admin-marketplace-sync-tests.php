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
 $og=static function($method,$path,$body)use(&$calls,$reply){$calls[]=['olx',$method,$path];return $path==='/adverts'?['http'=>200,'data'=>['data'=>[]]]:$reply();};
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
 sync_check($cache['work']['olx_active']===0&&$cache['work']['allegro_offers']===0&&$cache['work']['allegro_active']===0&&$cache['work']['updated']===1&&$cache['work']['uncertain']===2);
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
 // Forty scanned products are not forty offers. Twenty OLX account adverts are counted independently.
 $remove($root.'/cache');mkdir($root.'/cache',0700);
 $manyIds=array_map(static fn($i)=>'prd_'.str_pad(dechex($i),32,'0',STR_PAD_LEFT),range(1,40));
 $manyProducts=array_map(static fn($id)=>['productId'=>$id],$manyIds);
 file_put_contents($root.'/olx/publications/'.$b.'.json',json_encode(['productId'=>$b,'external_id'=>$b,'advert_id'=>'101','phase'=>'created','status'=>'limited']));
 $ads=[];for($i=0;$i<20;$i++)$ads[]=['id'=>100+$i,'external_id'=>$i<3?$manyIds[$i]:'foreign-'.$i,
  'status'=>$i<8?'active':($i<12?'limited':($i<16?'outdated':'future_status')),'title'=>'PRIVATE_TITLE','contact'=>['phone'=>'PRIVATE_PHONE']];
 $readCounts=[];$olxPages=static function($method,$path,$query)use(&$readCounts,$ads,$a,$b){
  sync_check($method==='GET');$readCounts[]=$path;
  if($path==='/adverts')return ['http'=>200,'data'=>['data'=>array_slice($ads,$query['offset'],6)]];
  $id=substr($path,9);return ['http'=>200,'data'=>['data'=>['id'=>(int)$id,'external_id'=>$id==='100'?$a:$b,'status'=>'active']]];
 };
 $agPages=static function($path)use($a,$b,$offer){return ['offers'=>str_contains($path,'external.id='.$a)?[$offer($a,501,'ACTIVE'),$offer($b,502,'ACTIVE')]:[],'totalCount'=>str_contains($path,'external.id='.$a)?2:0];};
 $stamp=8000;
 do {
  $cache=admin_marketplace_sync($manyProducts,$ac,$oc,$agPages,$olxPages,$root.'/cache',$stamp);$stamp+=5;
  sync_check(count($readCounts)<=($stamp-8000)/5); // At most one OLX request per click, including account pagination.
  if($cache['work']['olx_account']['offset']>0&&!$cache['work']['olx_account']['done']) {
   $dashboard=admin_marketplace_dashboard($cache,[]);sync_check(str_contains($dashboard['olx_scope'],'pełna liczba nieustalona'));
  }
 }while(!$cache['work']['done']&&$stamp<8100);
 sync_check($cache['work']['done']&&$cache['work']['allegro']===40&&$cache['work']['allegro_offers']===2&&$cache['work']['allegro_active']===2);
 sync_check($cache['work']['olx_targets']===2&&$cache['work']['olx']===2&&$cache['work']['olx_account']['offset']===20);
 $dashboard=admin_marketplace_dashboard($cache,[]);
 sync_check($dashboard['allegro']['total']===2&&$dashboard['allegro']['active']===2);
 sync_check($dashboard['olx']['total']===20&&$dashboard['olx']['active']===8&&$dashboard['olx']['pending']===4&&$dashboard['olx']['ended']===4&&$dashboard['olx']['unknown']===4);
 sync_check($dashboard['olx']['linked']===3&&$dashboard['olx']['total']-$dashboard['olx']['linked']===17);
 sync_check(str_contains($dashboard['progress'],'2 sprawdzonych powiązanych ogłoszeń zapisanych lokalnie / 2')&&!str_contains($dashboard['progress'],'/ 40'));
 sync_check(!str_contains(file_get_contents($root.'/cache/statuses.json'),'PRIVATE_PHONE')&&!str_contains(file_get_contents($root.'/cache/statuses.json'),'PRIVATE_TITLE'));
 // A failed next account scan preserves the last completed inventory and confirmed active rows.
 $oldInventory=$cache['olx_inventory'];$failingAccount=static function($method,$path,$query)use($olxPages){if($path==='/adverts')throw new RuntimeException('HTTP 429');return $olxPages($method,$path,$query);};
 $stamp+=60;
 do{$cache=admin_marketplace_sync($manyProducts,$ac,$oc,$agPages,$failingAccount,$root.'/cache',$stamp);$stamp+=5;}while(!$cache['work']['done']);
 sync_check($cache['olx_inventory']===$oldInventory&&$cache['rows'][$a]['allegro']['key']==='active');
 $dashboard=admin_marketplace_dashboard($cache,[]);sync_check(str_contains($dashboard['progress'],'Pełna liczba na koncie OLX nieustalona: HTTP 429'));
 // A complete but ambiguous result must not erase the last confirmed ACTIVE card.
 $cache['next_at']=0;admin_marketplace_cache_write($root.'/cache',$cache);
 $ambiguous=static fn($path)=>['offers'=>[$offer($a,501,'ACTIVE'),$offer($a,503,'INACTIVE')],'totalCount'=>2];
 $cache=admin_marketplace_sync($manyProducts,$ac,$oc,$ambiguous,$olxPages,$root.'/cache',$stamp+60);
 sync_check($cache['rows'][$a]['allegro']['key']==='active'&&$cache['rows'][$a]['allegro']['id']==='501'&&isset($cache['rows'][$a]['allegro']['refresh_error']));
 // No publication records: account adverts remain countable, but no guessed catalog associations.
 unlink($root.'/olx/publications/'.$a.'.json');unlink($root.'/olx/publications/'.$b.'.json');$remove($root.'/cache');mkdir($root.'/cache',0700);
 $unlinkedPages=static function($method,$path,$query)use($ads){sync_check($method==='GET'&&$path==='/adverts');$page=array_slice($ads,$query['offset'],7);foreach($page as &$ad)$ad['external_id']='not-a-product';return ['http'=>200,'data'=>['data'=>$page]];};
 $stamp=9000;do{$cache=admin_marketplace_sync($manyProducts,$ac,$oc,$agPages,$unlinkedPages,$root.'/cache',$stamp);$stamp+=5;}while(!$cache['work']['done']);
 $dashboard=admin_marketplace_dashboard($cache,[]);sync_check($cache['work']['olx_targets']===0&&$dashboard['olx']['total']===20&&$dashboard['olx']['linked']===0);
 sync_check(!isset($cache['rows'][$a]['olx'])&&!is_file($root.'/olx/publications/'.$a.'.json'));
 // Duplicate pages cannot manufacture a full account count.
 sync_reject(fn()=>admin_marketplace_olx_account_page(['http'=>200,'data'=>['data'=>[$ads[0]]]],$manyIds,[],['100'=>['status'=>'active']]));
 // Real v2 legacy cache: preserve ongoing progress and confirmed rows; do not start account scans mid-pass.
 $remove($root.'/cache');mkdir($root.'/cache',0700);$page=0;
 $cache=admin_marketplace_sync($small,$ac,$oc,$paged,$og,$root.'/cache',10000);
 foreach(['summary_version','olx_account','olx_targets','olx_attempted','allegro_inventory','uncertain'] as $field)unset($cache['work'][$field]);
 unset($cache['olx_inventory']);admin_marketplace_cache_write($root.'/cache',$cache);
 $cache=admin_marketplace_sync($small,$ac,$oc,$paged,$og,$root.'/cache',10005);
 sync_check($cache['work']['done']&&$page===2&&!isset($cache['work']['summary_version']));
 $dashboard=admin_marketplace_dashboard($cache,[]);sync_check(str_contains($dashboard['olx_scope'],'pełna liczba na koncie nieustalona'));
 // Exact ID conflicts stay unlinked and remote bodies are discarded.
 [$accountFound,$received]=admin_marketplace_olx_account_page(['http'=>200,'data'=>['data'=>[['id'=>100,'external_id'=>$b,'status'=>'active']]]],$manyIds,['100'=>$a],[]);
 sync_check($received===1&&$accountFound['100']['productId']===null);
 $totals=admin_marketplace_counts([['status'=>'ACTIVE'],['status'=>'INACTIVE'],['status'=>'ENDED'],['status'=>'ACTIVATING'],['status'=>'UNKNOWN']]);
 sync_check($totals['total']===5&&$totals['active']===1&&$totals['draft']===1&&$totals['ended']===1&&$totals['pending']===1&&$totals['unknown']===1);
 echo 'PASS: marketplace sync — '.$n." assertions; simulated GET only; publication records unchanged\n";
}finally{$remove($root);}
