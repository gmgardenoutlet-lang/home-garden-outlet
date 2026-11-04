<?php
declare(strict_types=1);
require __DIR__.'/../hosting/getspace/admin/allegro/lib.php';
$checks=0;
function operation_check(bool $ok): void {global $checks;if (!$ok) throw new RuntimeException('Operation assertion failed');$checks++;}
$root=sys_get_temp_dir().'/hgo-operation-'.bin2hex(random_bytes(8));mkdir($root,0700);
$pid='prd_'.str_repeat('a',32);$other='prd_'.str_repeat('b',32);
$id='19082641588';$path='/sale/product-offers/'.$id;
$operation=$path.'/operations/69e49d6e-9101-4c05-b2b2-860b954d88b2';
try {
 foreach (['PROD','SANDBOX'] as $environment) {
  $config=['environment'=>$environment,'auth_flow'=>'DEVICE','client_id'=>'SYNTHETIC_CLIENT','secret'=>'SYNTHETIC_SECRET',
   'key'=>base64_encode(random_bytes(32)),'storage_root'=>$root,'enabled'=>true];
  $record=['productId'=>$pid,'offerId'=>$id,'status'=>'PROCESSING','operation'=>$operation,'nextCheck'=>0,'activationAttempted'=>true,'history'=>['preserve']];
  $base=['status'=>'connected','account'=>['id'=>'123'],'tokens'=>['access'=>'SYNTHETIC_ACCESS','refresh'=>'SYNTHETIC_REFRESH','expires_at'=>time()+3600,'scope'=>allegro_scope_request()],
   'offersVersion'=>2,'offers'=>[$pid=>$record,$other=>['productId'=>$other,'offerId'=>'777','status'=>'ACTIVE','history'=>['original angel']]],'pilotFailures'=>[['productId'=>$other,'code'=>'historical']]];
  $read=static fn()=>allegro_locked($config,static fn($v,$k)=>$v);
  $seed=static function()use($config,$base){allegro_locked($config,static function($v,$k)use($config,$base){allegro_write_vault($config,$k,$base);});};
  $host=allegro_hosts($environment)['api'];$cases=[];
  $cases[]=[202,'',[],null,true];
  $cases[]=[202,'',['location'=>$host.$operation],null,true];
  $cases[]=[202,'',['location'=>'https://evil.test'.$operation],null,false];
  $cases[]=[202,'',['location'=>$path.'/operations/different'],null,false];
  $cases[]=[202,'not JSON',['retry-after'=>'7'],null,true];
  foreach (['ACTIVE','INACTIVE','ACTIVATING','ENDED'] as $status) foreach ([$path,$host.$path] as $location)
   $cases[]=[303,'',['location'=>$location],['id'=>$id,'external'=>['id'=>$pid],'publication'=>['status'=>$status]],true];
  foreach (['','https://evil.test'.$path,'https://api.allegro.pl.evil.test'.$path,'http://api.allegro.pl'.$path,
   'https://api.allegro.pl@evil.test'.$path,'//api.allegro.pl'.$path,$path.'?x=1',$path.'#x',$path.'/',
   '/sale/product-offers/999',$host.$path.'/operations/other',allegro_hosts($environment==='PROD'?'SANDBOX':'PROD')['api'].$path] as $location)
   $cases[]=[303,'',['location'=>$location],null,false];
  $cases[]=[303,'',[],null,false];$cases[]=[303,'',['location'=>['invalid']],null,false];
  foreach ([200,201,204,301,302,404,500] as $code) $cases[]=[$code,'{}',['location'=>$host.$path],null,false];
  foreach ([['id'=>'999','external'=>['id'=>$pid],'publication'=>['status'=>'ACTIVE']],
   ['id'=>$id,'external'=>['id'=>$other],'publication'=>['status'=>'ACTIVE']],
   ['id'=>$id,'external'=>['id'=>$pid],'publication'=>['status'=>'UNKNOWN']]] as $remote)
   $cases[]=[303,'',['location'=>$path],$remote,false];
  foreach ([202,303,404] as $code) $cases[]=[303,'',['location'=>$path],['httpStatus'=>$code],false];
  foreach ($cases as [$code,$body,$headers,$remote,$ok]) {
   $seed();$before=$read();$file=allegro_storage_dir($config).'/account.enc';$cipher=file_get_contents($file);$requests=[];
   $transport=static function($r)use(&$requests,$operation,$path,$host,$code,$body,$headers,$remote){
    $requests[]=$r;operation_check($r['method']==='GET');
    if ($r['url']===$host.$operation) return ['status'=>$code,'body'=>$body,'headers'=>$headers];
    operation_check($r['url']===$host.$path);
    return ['status'=>$remote['httpStatus'] ?? 200,'body'=>json_encode($remote)];
   };
   $error=null;
   try {allegro_pilot_check($config,$transport,$pid);} catch (Throwable $e) {$error=$e;}
   operation_check(($error===null)===$ok);
   $after=$read();operation_check($after['offers'][$other]===$before['offers'][$other]);
   operation_check($after['tokens']===$before['tokens'] && $after['pilotFailures']===$before['pilotFailures']);
   if (!$ok) {
    operation_check($after===$before && file_get_contents($file)===$cipher);
    operation_check($error instanceof RuntimeException && $error->getMessage()!=='');
   } elseif ($code===202) {
    operation_check(count($requests)===1 && $after['offers'][$pid]['operation']===$operation);
    $pending=$after['offers'][$pid];operation_check($pending['nextCheck']>=time()+1);unset($pending['nextCheck']);
    $expected=$record;unset($expected['nextCheck']);operation_check($pending===$expected);
   } else {
    operation_check(count($requests)===2 && !isset($after['offers'][$pid]['operation']));
    operation_check($after['offers'][$pid]['status']===$remote['publication']['status']);
    operation_check($after['offers'][$pid]['offerId']===$id && $after['offers'][$pid]['productId']===$pid);
   }
  }
  // Failed observation must also preserve the caller's in-memory record.
  $candidate=$record;$failed=false;
  try {allegro_pilot_observe($config,$candidate,'SYNTHETIC_ACCESS',static fn($r)=>str_contains($r['url'],'/operations/')
   ? ['status'=>303,'body'=>'','headers'=>['location'=>$path]] : ['status'=>200,'body'=>'bad JSON']);}
  catch (Throwable $e) {$failed=true;}
  operation_check($failed && $candidate===$record);
 }
 echo 'PASS: Allegro operation 202/303 — '.$checks." assertions; GET-only synthetic transport\n";
} finally {
 $remove=static function($p)use(&$remove){if(is_dir($p)){foreach(scandir($p) as $x)if($x!=='.'&&$x!=='..')$remove($p.'/'.$x);rmdir($p);}else unlink($p);};$remove($root);
}
