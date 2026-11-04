<?php
declare(strict_types=1);
require __DIR__.'/../hosting/getspace/admin/marketplace-status.php';
require __DIR__.'/../hosting/getspace/admin/allegro/lib.php';
require __DIR__.'/../hosting/getspace/admin/olx/publish-lib.php';
$checks=0;
function marketplace_check(bool $ok): void {global $checks;if(!$ok)throw new RuntimeException('Marketplace assertion failed');$checks++;}
$root=sys_get_temp_dir().'/hgo-marketplace-'.bin2hex(random_bytes(6));mkdir($root,0700);
foreach (['allegro','allegro/prod','olx','olx/publications','olx/drafts'] as $dir) mkdir($root.'/'.$dir,0700);
$ids=array_map(static fn($c)=>'prd_'.str_repeat($c,32),['a','b','c','d']);[$a,$b,$c,$d]=$ids;
$config=['environment'=>'PROD','auth_flow'=>'DEVICE','client_id'=>'SYNTHETIC_CLIENT','key'=>base64_encode(random_bytes(32)),'storage_root'=>$root.'/allegro'];
$olx=['dir'=>$root.'/olx'];$now=time();
$seal=static function(array $pilot)use($config,$root){
 $vault=allegro_context($config)+['status'=>'connected','tokens'=>['access'=>'PRIVATE_ACCESS','refresh'=>'PRIVATE_REFRESH'],'pilot'=>$pilot];
 $nonce=random_bytes(24);file_put_contents($root.'/allegro/prod/account.enc',$nonce.sodium_crypto_secretbox(json_encode($vault),$nonce,base64_decode($config['key'])));
};
$tree=static function()use($root){$out=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));foreach($iterator as $file)$out[substr($file->getPathname(),strlen($root))]=hash_file('sha256',$file->getPathname());ksort($out);return $out;};
try {
 $seal(['productId'=>$a,'offerId'=>'123','status'=>'ACTIVE','payload'=>['secret'=>'PRIVATE_PAYLOAD']]);
 $activeOlx=['productId'=>$b,'external_id'=>$b,'advert_id'=>'456','phase'=>'created','status'=>'active','verified_at'=>$now,'valid_to'=>gmdate('c',$now+86400),'url'=>'https://www.olx.pl/d/oferta/fixture-ID123.html'];
 file_put_contents($root.'/olx/publications/'.$b.'.json',json_encode($activeOlx));
 file_put_contents($root.'/olx/drafts/'.$c.'.json',json_encode(['titleOverride'=>'Szkic lokalny']));
 $products=array_map(static fn($id)=>['productId'=>$id],$ids);
 $before=$tree();$rows=admin_product_marketplaces($products,$config,$olx);marketplace_check($tree()===$before);
 marketplace_check($rows[0]['allegro']['key']==='active' && $rows[0]['olx']['key']==='unknown');
 marketplace_check($rows[1]['olx']['key']==='active' && $rows[1]['allegro']['key']==='unknown');
 marketplace_check($rows[2]['olx']['key']==='draft' && $rows[2]['olx']['action']==='Dokończ OLX');
 marketplace_check($rows[3]['olx']['key']==='unknown' && $rows[3]['allegro']['key']==='unknown');
 marketplace_check($rows[0]['allegro']['url']==='https://allegro.pl/oferta/123');
 marketplace_check($rows[1]['olx']['url']===$activeOlx['url']);
 marketplace_check(str_contains($rows[3]['allegro']['url'],$d));
 foreach (['PRIVATE_ACCESS','PRIVATE_REFRESH','PRIVATE_PAYLOAD',$config['key']] as $private) marketplace_check(!str_contains(json_encode($rows),$private));
 $snapshot=admin_allegro_list_snapshot($config);
 foreach (['INACTIVE'=>'draft','ACTIVE'=>'active','PROCESSING'=>'publishing','ACTIVATING'=>'publishing','ENDED'=>'inactive','UNKNOWN'=>'error','FAILED'=>'error','other'=>'unknown'] as $status=>$expected) {
  $snap=$snapshot;$snap['offers'][$a]['status']=$status;marketplace_check(admin_allegro_product_status($a,$snap)['key']===$expected);
 }
 $bad=$snapshot;unset($bad['offers'][$a]['offerId']);marketplace_check(admin_allegro_product_status($a,$bad)['key']==='unknown');
 $bad=$snapshot;$bad['environment']='SANDBOX';marketplace_check(admin_allegro_product_status($a,$bad)['key']==='unknown');
 $bad=$snapshot;$bad['offers'][$a]['offerId']='123?secret=bad';marketplace_check(admin_allegro_product_status($a,$bad)['id']==='');
 marketplace_check(admin_allegro_product_status('bad-id',$snapshot)['key']==='unknown');
 // Multiple independent offer states, including a reopened legacy attempt.
 $multi=allegro_offer_migrate(allegro_context($config)+['pilot'=>['productId'=>$a,'offerId'=>'123','status'=>'ACTIVE']]);
 $multi['offers'][$b]=['productId'=>$b,'offerId'=>'124','status'=>'INACTIVE','operation'=>'private-operation','payload'=>['private'=>'PRIVATE_PAYLOAD']];
 allegro_write_vault($config,base64_decode($config['key']),$multi);
 $before=$tree();$multiSnapshot=admin_allegro_list_snapshot($config);
 marketplace_check($tree()===$before);
 marketplace_check(admin_allegro_product_status($a,$multiSnapshot)['key']==='active');
 marketplace_check(admin_allegro_product_status($b,$multiSnapshot)['key']==='draft');
 marketplace_check(!str_contains(json_encode($multiSnapshot),'PRIVATE_PAYLOAD') && !str_contains(json_encode($multiSnapshot),'private-operation'));
 unset($multi['offers'][$a]);allegro_write_vault($config,base64_decode($config['key']),$multi);
 marketplace_check(admin_allegro_product_status($a,admin_allegro_list_snapshot($config))['key']==='unknown');
 // One record on each platform can confirm both listings, while absence remains unknown.
 $seal(['productId'=>$b,'offerId'=>'123','status'=>'ACTIVE']);
 $both=admin_product_marketplaces($products,$config,$olx);marketplace_check($both[1]['allegro']['key']==='active' && $both[1]['olx']['key']==='active');
 foreach (['new'=>'publishing','unconfirmed'=>'publishing','unpaid'=>'publishing','outdated'=>'inactive','inactive'=>'inactive','blocked'=>'error','moderated'=>'error'] as $status=>$key) {
  marketplace_check(admin_olx_product_status($b,array_replace($activeOlx,['status'=>$status]))['key']===$key);
 }
 foreach (['uncertain'=>'error','error'=>'error','publishing'=>'publishing'] as $phase=>$key) marketplace_check(admin_olx_product_status($b,array_replace($activeOlx,['phase'=>$phase]))['key']===$key);
 $missing=$activeOlx;unset($missing['verified_at']);marketplace_check(admin_olx_product_status($b,$missing)['key']==='unknown');
 marketplace_check(admin_olx_product_status($b,array_replace($activeOlx,['valid_to'=>gmdate('c',$now-1)]))['key']==='unknown');
 marketplace_check(admin_olx_product_status($b,array_replace($activeOlx,['error_code'=>'verification_pending']))['key']==='error');
 marketplace_check(admin_olx_product_status($a,$activeOlx)['key']==='error');
 marketplace_check(!str_starts_with(admin_olx_product_status($b,array_replace($activeOlx,['url'=>'https://evil.test/']))['url'],'https://'));
 marketplace_check(admin_marketplace_matches($rows[0],'allegro-active') && !admin_marketplace_matches($rows[1],'allegro-active'));
 marketplace_check(admin_marketplace_matches($rows[1],'olx-active') && admin_marketplace_matches($rows[3],'unknown'));
 // Missing stores remain missing: no mkdir, locks, chmod, refresh or network.
 unlink($root.'/allegro/prod/account.enc');rmdir($root.'/allegro/prod');
 $before=$tree();$missing=admin_product_marketplaces($products,$config,$olx);marketplace_check($before===$tree() && !is_dir($root.'/allegro/prod'));
 marketplace_check($missing[0]['allegro']['key']==='unknown');
 file_put_contents($root.'/olx/publications/'.$d.'.json','not JSON');
 marketplace_check(admin_product_marketplaces($products,$config,$olx)[3]['olx']['key']==='unknown');
 echo 'PASS: product marketplace statuses — '.$checks." assertions; read-only files, zero API\n";
} finally {
 $remove=static function($path)use(&$remove){if(is_dir($path)){foreach(scandir($path) as $file)if($file!=='.' && $file!=='..')$remove($path.'/'.$file);rmdir($path);}else unlink($path);};$remove($root);
}
