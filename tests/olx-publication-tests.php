<?php
declare(strict_types=1);
require __DIR__.'/../hosting/getspace/admin/olx/publish-lib.php';
set_error_handler(static function($level,$message) { if(error_reporting() & $level) throw new RuntimeException($message); });
function check(bool $ok,string $label): void { if(!$ok) throw new RuntimeException('FAIL: '.$label); }
function reject(callable $fn,string $label): void { try {$fn();} catch(Throwable $e) {return;} throw new RuntimeException('FAIL: '.$label); }
$dir=sys_get_temp_dir().'/hgo-olx-publish-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
$config=['client_id'=>'203399','secret'=>'FIXTURE_SECRET','key'=>base64_encode(random_bytes(32)),'redirect_uri'=>OLX_REDIRECT_URI,'dir'=>$dir,'logs_safe'=>true];
$id='prd_'.str_repeat('a',32);
$prepared=['product'=>['productId'=>$id,'status'=>'available'],'draft'=>['fixture'=>true],
    'payload'=>['external_id'=>$id,'auto_extend_enabled'=>false,'contact'=>['name'=>'PRIVATE_CONTACT']]];
$hash=olx_publication_fingerprint($prepared); $prepare=static fn()=>$prepared;
$file=olx_publication_file($config,$id);
$reset=static function() use($file) { if(is_file($file)) unlink($file); };
$calls=[]; $postResponse=['http'=>200,'data'=>['data'=>['id'=>123,'external_id'=>$id,'status'=>'unconfirmed']]];
$getResponse=['http'=>200,'data'=>['data'=>['id'=>123,'external_id'=>$id,'status'=>'unpaid','url'=>'https://www.olx.pl/d/oferta/fixture.html','created_at'=>'2026-10-27T12:00:00+02:00','valid_to'=>'2026-11-27T12:00:00+02:00']]];
$transport=static function($method,$path,$payload=[]) use(&$calls,&$postResponse,&$getResponse,$file) {
    $calls[]=[$method,$path,$payload];
    if($method==='POST') {
        check((olx_json_read($file)['phase']??'')==='publishing','durable barrier before POST');
        if(($postResponse['throw']??false)) throw new RuntimeException('fixture timeout');
        return $postResponse;
    }
    if($path==='/adverts') return ['http'=>200,'data'=>['data'=>[]]];
    check((olx_json_read($file)['advert_id']??'')==='123','ID persisted before GET');
    return $getResponse;
};
try {
    check(olx_publication_label([])==='NIE WYSTAWIONO','default status');
    $state=olx_publish_one($config,$id,$hash,$prepare,$transport);
    check($state['advert_id']==='123' && $state['status']==='unpaid' && isset($state['verified_at']),'success saves GET status and ID');
    check(array_column($calls,0)===['GET','POST','GET'],'exactly one POST and followup GET');
    check($calls[1][2]['auto_extend_enabled']===false && $calls[1][2]['external_id']===$id,'identity and no auto extend');
    reject(fn()=>olx_publish_one($config,$id,$hash,$prepare,$transport),'double click existing ID');
    check(count($calls)===3,'duplicate has no transport');
    check(!str_contains(file_get_contents($file.'.audit'),'PRIVATE_CONTACT'),'audit excludes payload');
    check(!str_contains(file_get_contents($file),'PRIVATE_CONTACT'),'state excludes payload');
    foreach(['publishing','uncertain','created'] as $phase) {
        olx_json_write($file,['phase'=>$phase]); $calls=[];
        reject(fn()=>olx_publish_one($config,$id,$hash,$prepare,$transport),'persisted '.$phase);
        check(!$calls,'blocked phase has zero requests');
    }
    $reset(); $calls=[];
    reject(fn()=>olx_publish_one($config,$id,$hash,static function(){throw new OlxSafeException('Missing required attribute');},$transport),'required attribute prevents POST');
    check(!$calls,'validation failure zero requests');
    reject(fn()=>olx_publish_one($config,$id,str_repeat('0',64),$prepare,$transport),'changed preview');
    check(!$calls,'hash mismatch zero requests');
    reject(fn()=>olx_publish_one($config,$id,$hash,$prepare,$transport,static fn()=>false),'changed during validation');
    check(array_column($calls,0)===['GET'],'freshness prevents POST');
    foreach([0,500,502,401] as $http) {
        $reset(); $calls=[]; $postResponse=['http'=>$http,'data'=>null];
        $state=olx_publish_one($config,$id,$hash,$prepare,$transport);
        check($state['phase']==='uncertain','uncertain HTTP '.$http);
        reject(fn()=>olx_publish_one($config,$id,$hash,$prepare,$transport),'no retry HTTP '.$http);
        check(count(array_filter($calls,static fn($c)=>$c[0]==='POST'))===1,'one POST only');
    }
    $reset(); $calls=[]; $postResponse=['throw'=>true];
    check(olx_publish_one($config,$id,$hash,$prepare,$transport)['phase']==='uncertain','exception uncertain');
    $reset(); $calls=[]; $postResponse=['http'=>400,'data'=>['error'=>['validation'=>[['field'=>'title','message'=>'PRIVATE_CONTACT fixture-access'],['field'=>'attributes.state','message'=>'SECRET']]]]];
    $state=olx_publish_one($config,$id,$hash,$prepare,$transport);
    check($state['phase']==='error' && empty($state['advert_id']),'400 never published');
    check(str_contains($state['errors'][0],'Tytuł') && str_contains($state['errors'][1],'attributes.state'),'field errors');
    check(!str_contains(json_encode($state),'SECRET') && !str_contains(json_encode($state),'PRIVATE_CONTACT'),'no raw remote messages');
    check(str_contains(olx_publication_errors(['error'=>['detail'=>'Partner is not allowed to use external URL']])[0],'external_url'),'documented general error mapped');
    check(str_contains(olx_publication_errors(['data'=>['error'=>['detail'=>'Image error: Remote file not exists']]])[0],'Zdjęcia'),'nested error mapped');
    check(str_contains(olx_publication_errors(['error'=>['validation'=>[['field'=>'district/city_id']]]])[0],'Lokalizacja'),'slash field name');
    $unknown=olx_publication_errors(['error'=>['detail'=>'PRIVATE_CONTACT fixture-access']]);
    check(!str_contains(json_encode($unknown),'PRIVATE_CONTACT') && str_contains($unknown[0],'HTTP 400'),'unknown detail never exposed');
    $reset(); $postResponse=['http'=>200,'data'=>['data'=>['id'=>123,'external_id'=>$id,'status'=>'new']]]; $getResponse=['http'=>503,'data'=>null];
    $state=olx_publish_one($config,$id,$hash,$prepare,$transport);
    check($state['advert_id']==='123' && $state['error_code']==='verification_pending','GET failure keeps ID');
    $reset(); $calls=[];
    $existing=static function($m,$p,$d=[]) use(&$calls,$id) { $calls[]=[$m,$p]; return ['http'=>200,'data'=>['data'=>[['id'=>456,'external_id'=>$id,'status'=>'active']]]]; };
    reject(fn()=>olx_publish_one($config,$id,$hash,$prepare,$existing),'remote external ID prevents POST');
    check(count($calls)===1 && olx_json_read($file)['advert_id']==='456','remote advert recovered without POST');
    $reset(); $calls=[];
    olx_publication_locked($config,$id,static function() use($config,$id,$hash,$prepare,$transport,&$calls) {
        reject(fn()=>olx_publish_one($config,$id,$hash,$prepare,$transport),'parallel lock'); check(!$calls,'lock contention zero API calls');
    });
    check(olx_publish_safety_input(['safety_confirm'=>'yes'])===[],'optional GPSR omitted');
    check(olx_publish_safety_input(['safety_confirm'=>'yes','placed_before_2024'=>'yes'])===['placed_before_2024'=>true],'explicit GPSR market date');
    reject(fn()=>olx_publish_safety_input([]),'safety confirmation required');
    foreach(['active'=>'AKTYWNE','unconfirmed'=>'OCZEKUJE','outdated'=>'WYGASŁE','removed_by_user'=>'NIEAKTYWNE'] as $status=>$label) check(olx_publication_label(['phase'=>'created','status'=>$status])===$label,'real status '.$status);
    foreach(['PUT','DELETE','PATCH'] as $verb) reject(fn()=>olx_advert_http($config,$verb,'/adverts/123'),'update/delete forbidden');
    foreach(['/adverts/123/commands','/adverts/123'] as $path) reject(fn()=>olx_advert_http($config,'POST',$path),'command POST forbidden');
    $admin=file_get_contents(__DIR__.'/../hosting/getspace/admin/index.php');
    check(!preg_match('/olx_(?:publish_one|advert_http)\s*\(/',$admin),'product save/edit/sold has no advert write hooks');
    check(!preg_match('/olx_(?:publish_one|advert_http)\s*\(/',file_get_contents(__DIR__.'/../hosting/getspace/admin/olx/callback.php')),'OAuth has no advert hooks');
    if(PHP_OS_FAMILY!=='Windows') { check((fileperms($file.'.lock')&0777)===0600,'lock 600'); check((fileperms(dirname($file))&0777)===0700,'state directory 700'); }
    echo "PASS: Stage D barrier, duplicate, concurrency lock, fresh validation, timeout/5xx no retry, 400 fields, success/GET persistence, reconciliation, status, auto extend, audit and independent product lifecycle; synthetic transport only\n";
} finally {
    foreach(glob($dir.'/publications/*')?:[] as $f) unlink($f);
    rmdir($dir.'/publications'); rmdir($dir);
}
