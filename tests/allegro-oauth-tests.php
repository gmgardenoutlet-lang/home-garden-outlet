<?php
declare(strict_types=1);
require __DIR__ . '/../hosting/getspace/admin/allegro/lib.php';

$assertions = 0;
function a_check(bool $ok, string $label): void {
    global $assertions;
    $assertions++;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
}
function a_reject(callable $operation, string $label, ?string $kind = null): void {
    try { $operation(); }
    catch (AllegroSafeException $e) {
        a_check($kind === null || $e->kind === $kind, $label . ' safe error kind');
        return;
    }
    throw new RuntimeException('FAIL: accepted ' . $label);
}
function a_remove(string $dir): void {
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $dir . '/' . $name;
        if (is_dir($path) && !is_link($path)) a_remove($path); else unlink($path);
    }
    rmdir($dir);
}

$dir = sys_get_temp_dir() . '/hgo-allegro-device-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
$config = ['environment' => 'SANDBOX', 'auth_flow' => 'DEVICE', 'client_id' => bin2hex(random_bytes(16)),
    'secret' => bin2hex(random_bytes(24)), 'storage_root' => $dir, 'key' => base64_encode(random_bytes(32)),
    'logs_safe' => false, 'enabled' => true];
$binding = hash('sha256', random_bytes(32));
$now = time();
$reply = static fn(array $data, int $status = 200): array => ['status' => $status, 'body' => json_encode($data)];
$device = ['user_code' => 'abc123xyz', 'device_code' => 'SYNTHETIC_DEVICE_PRIVATE', 'expires_in' => 3600, 'interval' => 5,
    'verification_uri' => 'https://allegro.pl.allegrosandbox.pl/skojarz-aplikacje',
    'verification_uri_complete' => 'https://allegro.pl.allegrosandbox.pl/skojarz-aplikacje?code=abc123xyz'];
$tokenData = ['access_token' => 'fixture-access-private', 'refresh_token' => 'fixture-refresh-private',
    'token_type' => 'bearer', 'expires_in' => 3600, 'scope' => ALLEGRO_B_SCOPE];
$requests = [];
$tokenReply = $reply($tokenData);
$transport = function ($r) use (&$requests, &$tokenReply, $reply, $device) {
    $requests[] = $r;
    if (str_ends_with($r['url'], '/device')) return $reply($device);
    if (str_ends_with($r['url'], '/me')) return $reply(['id' => '123456', 'login' => 'fixture-seller', 'email' => 'PRIVATE_NOT_RETAINED']);
    return $tokenReply;
};
$read = static fn() => allegro_locked($config, static fn($v) => $v);
$start = static fn() => allegro_device_start($config, $binding, $now, $transport);
$due = function () use ($config) { allegro_locked($config, function ($v,$k) use ($config) { $v['device']['next_poll_at']=time()-1; allegro_write_vault($config,$k,$v); }); };
try {
    allegro_validate_config($config, true);
    a_check(!isset($config['redirect_uri']) && !$config['logs_safe'], 'Device without callback or G16');
    foreach (['', 'AUTHORIZATION_CODE', 'device', 'PKCE'] as $flow) a_reject(fn()=>allegro_validate_config(array_replace($config,['auth_flow'=>$flow]),true),'explicit DEVICE');
    a_reject(fn()=>allegro_validate_config(array_replace($config,['enabled'=>false]),true),'OFF', 'disabled');
    foreach (['', "bad\r\nsecret"] as $secret) a_reject(fn()=>allegro_validate_config(array_replace($config,['secret'=>$secret]),true),'invalid secret');
    foreach ([dirname(__DIR__), __DIR__.'/../hosting/getspace'] as $root) a_reject(fn()=>allegro_validate_config(array_replace($config,['storage_root'=>$root])),'private root');
    a_check(!function_exists('allegro_start') && !function_exists('allegro_pkce_challenge') && !function_exists('allegro_finish'), 'legacy functions absent');
    a_reject(fn()=>allegro_callback($config,'fake','fake',false,$now),'legacy callback disabled','disabled');
    $start();
    a_check(count($requests)===1, 'one initialization');
    $r=$requests[0]; parse_str($r['body'],$fields);
    a_check($fields===['client_id'=>$config['client_id'],'scope'=>ALLEGRO_B_SCOPE], 'exact device init fields');
    a_check(in_array('Authorization: Basic '.base64_encode($config['client_id'].':'.$config['secret']),$r['headers'],true),'Basic');
    a_check(in_array('Content-Type: application/x-www-form-urlencoded',$r['headers'],true),'form body');
    a_check($r['url']==='https://allegro.pl.allegrosandbox.pl/auth/oauth/device','sandbox endpoint');
    $v=$read();
    a_check(!isset($v['state'],$v['pending']) && isset($v['device']), 'only device pending');
    $public=allegro_public_status($config,$binding);
    a_check($public['device']['user_code']===$device['user_code'],'public user code');
    a_check(allegro_public_status($config,hash('sha256','other'))['device']===null,'session-bound view');
    foreach ([$device['device_code'],$config['secret'],'binding','device_code'] as $secret) a_check(!str_contains(json_encode($public),$secret),'private status');
    $cipher=file_get_contents(allegro_storage_dir($config).'/account.enc');
    foreach ([$device['device_code'],$device['user_code'],$config['secret'],$binding] as $secret) a_check(!str_contains($cipher,$secret),'encrypted device');
    $before=count($requests);
    a_check(allegro_device_poll($config,$binding,$now,$transport)==='pending','early poll');
    a_check(count($requests)===$before,'early poll zero HTTP');
    a_reject(fn()=>allegro_device_poll($config,hash('sha256','other'),$now+10,$transport),'poll binding','session');
    a_check(count($requests)===$before,'other binding zero HTTP');
    foreach (['authorization_pending','slow_down'] as $error) {
        $due();$previous=$read()['device'];$tokenReply=$reply(['error'=>$error,'error_description'=>'PRIVATE_REMOTE'],400);
        a_check(allegro_device_poll($config,$binding,$now,$transport)==='pending','pending response');
        a_check(isset($read()['device']) && $read()['device']['expires']===$previous['expires'],'no lifetime extension');
        a_check($read()['device']['interval']>=($error==='slow_down'?$previous['interval']+5:$previous['interval']),'interval respected');
        $n=count($requests);allegro_device_poll($config,$binding,$now,$transport);a_check(count($requests)===$n,'backoff no spam');
    }
    foreach (['access_denied'=>'denied','Invalid device code'=>'expired','expired_token'=>'expired','invalid_grant'=>'expired','other'=>'expired'] as $error=>$expected) {
        $start();$due();$tokenReply=$reply(['error'=>$error],400);
        a_check(allegro_device_poll($config,$binding,$now,$transport)===$expected,'terminal category');
        a_check(!isset($read()['device']) && !isset($read()['tokens']),'terminal consumes device');
    }
    $start();allegro_locked($config,function($v,$k)use($config){$v['device']['expires']=time()-1;allegro_write_vault($config,$k,$v);});
    $n=count($requests);a_check(allegro_device_poll($config,$binding,$now,$transport)==='expired','expiry');a_check(count($requests)===$n,'expired zero HTTP');
    $start();allegro_locked($config,function($v,$k)use($config){$v['device']['expires']=time()-1;allegro_write_vault($config,$k,$v);});
    allegro_public_status($config,$binding);a_check(!isset($read()['device']),'status prunes expired device');
    $start();$due();$tokenReply=$reply(['error'=>'PRIVATE_REMOTE'],429);$n=count($requests);
    a_reject(fn()=>allegro_device_poll($config,$binding,$now,$transport),'429','network');
    a_check(count($requests)===$n+1 && !isset($read()['device']),'429 no retry');
    a_reject(fn()=>allegro_device_poll($config,$binding,$now,$transport),'no replay');
    foreach ([302,500] as $status) { $start();$due();$tokenReply=$reply([], $status);a_reject(fn()=>allegro_device_poll($config,$binding,$now,$transport),'HTTP failure');a_check(!isset($read()['device']),'failure removes pending'); }
    $start();$due();$n=0;
    a_reject(function()use($config,$binding,$now,&$n){allegro_device_poll($config,$binding,$now,function()use(&$n){$n++;throw new RuntimeException('PRIVATE_REMOTE');});},'timeout');
    a_check($n===1 && !isset($read()['device']),'timeout consumes before transport');
    $start();$due();$tokenReply=$reply($tokenData);$n=count($requests);
    a_check(allegro_device_poll($config,$binding,$now,$transport)==='connected','success');
    a_check(count($requests)===$n+2,'one token and one identity');
    parse_str($requests[$n]['body'],$grant);
    a_check($grant===['grant_type'=>'urn:ietf:params:oauth:grant-type:device_code','device_code'=>$device['device_code']],'device grant fields');
    a_check($read()['account']===['id'=>'123456','login'=>'fixture-seller'],'minimal account');
    a_check(!isset($read()['device'])&&!str_contains(json_encode($read()),'PRIVATE_NOT_RETAINED'),'device consumed and no email');
    $n=count($requests);a_check(allegro_access_token($config,$now,$transport)===$tokenData['access_token'],'cached token');a_check(count($requests)===$n,'no unnecessary refresh');
    // Preserve failure-closed rotation and atomic storage coverage.
    $tokenReply=$reply(array_replace($tokenData,['access_token'=>'rotated-access','refresh_token'=>'rotated-refresh']));
    allegro_access_token($config,$now+3601,$transport);
    a_check($read()['tokens']['refresh']==='rotated-refresh','rotation saved');
    parse_str($requests[array_key_last($requests)]['body'],$grant);a_check($grant['grant_type']==='refresh_token'&&$grant['refresh_token']===$tokenData['refresh_token'],'old refresh once');
    $fail=fn()=>throw new RuntimeException('PRIVATE_REMOTE');
    a_reject(fn()=>allegro_access_token($config,$now+7202,$fail),'refresh ambiguity','refresh');
    a_check(!isset($read()['tokens'])&&$read()['status']==='reconnect','refresh fail closed persisted');
    a_reject(fn()=>allegro_access_token($config,$now+7203,$transport),'no refresh replay');
    // Device replacement is atomic; failures must not retain the previous pending code.
    $start();$due();$start();$n=count($requests);allegro_device_poll($config,$binding,$now,$transport);
    a_check(count($requests)===$n && $read()['device']['next_poll_at']>$now,'new init replaces old due flow');
    a_reject(fn()=>allegro_device_start($config,$binding,$now,fn()=>$reply([],500)),'failed initialization');
    a_check(!isset($read()['device']),'old pending invalidated before failed init');
    $start();$due();$tokenReply=$reply(['device_result'=>'pending']);
    a_reject(fn()=>allegro_device_poll($config,$binding,$now,$transport),'reserved result cannot be injected via 200');
    a_check(!isset($read()['device']),'malformed 200 fail closed');
    $start();$due();allegro_locked($config,function($v,$k)use($config){$v['device']['interval']=300;allegro_write_vault($config,$k,$v);});
    $tokenReply=$reply(['error'=>'slow_down'],400);
    a_check(allegro_device_poll($config,$binding,$now,$transport)==='expired'&&!isset($read()['device']),'backoff cap stops polling');
    $prodDevice=array_replace($device,['verification_uri'=>'https://allegro.pl/skojarz-aplikacje','verification_uri_complete'=>'https://allegro.pl/skojarz-aplikacje?code=abc123xyz']);
    $prodConfig=array_replace($config,['environment'=>'PROD']);
    a_check(allegro_device_response($prodConfig,$prodDevice)['user_code']==='abc123xyz','PROD response host');
    a_check(allegro_request_spec($prodConfig,'POST','/auth/oauth/device')['url']==='https://allegro.pl/auth/oauth/device','PROD device policy');
    a_check(allegro_request_spec($prodConfig,'GET','/me','fixture')['url']==='https://api.allegro.pl/me','PROD identity policy');
    foreach ([$config, $prodConfig] as $urlConfig) {
        $base = allegro_hosts($urlConfig['environment'])['auth'] . '/skojarz-aplikacje';
        foreach ([[], ['verification_uri'=>'https://evil.test/', 'verification_uri_complete'=>'https://evil.test/?code=foreign'],
            ['verification_uri'=>[], 'verification_uri_complete'=>null]] as $returnedUrls) {
            $response = array_replace(array_diff_key($device, array_flip(['verification_uri','verification_uri_complete'])), $returnedUrls);
            $canonical = allegro_device_response($urlConfig, $response);
            a_check($canonical['verification_uri']===$base && $canonical['verification_uri_complete']===$base.'?code='.rawurlencode($device['user_code']), 'response URLs ignored; canonical host');
        }
        foreach (['x', 'a&code=foreign/?#%', str_repeat('x',128)] as $user) {
            $canonical = allegro_device_response($urlConfig, array_replace($device,['user_code'=>$user]));
            a_check($canonical['verification_uri_complete']===$base.'?code='.rawurlencode($user),'user code safely URL encoded');
        }
    }
    $savedVault=$read();$start();
    allegro_locked($config,function($v,$k)use($config){$v['device']['verification_uri']='https://evil.test/';$v['device']['verification_uri_complete']='https://evil.test/?code=foreign';allegro_write_vault($config,$k,$v);});
    $safeDevice=allegro_public_status($config,$binding)['device'];
    a_check($safeDevice['verification_uri']===$device['verification_uri'] && $safeDevice['verification_uri_complete']===$device['verification_uri_complete'],'stored response URLs never exposed for rendering');
    allegro_locked($config,function($v,$k)use($config,$savedVault){allegro_write_vault($config,$k,$savedVault);});
    $path=allegro_storage_dir($config).'/account.enc';$cipher=file_get_contents($path);
    a_reject(fn()=>allegro_locked($config,function($v,$k)use($config){$v['oversized']=str_repeat('x',110000);allegro_write_vault($config,$k,$v);}), 'atomic failed write');
    a_check(file_get_contents($path)===$cipher && glob(dirname($path).'/.allegro-*')===[],'old complete file retained');
    a_reject(fn()=>allegro_public_status(array_replace($config,['key'=>base64_encode(random_bytes(32))])),'wrong key','storage');
    $prod=array_replace($config,['environment'=>'PROD']);a_check(allegro_public_status($prod)['status']==='disconnected','partition');
    copy($path,allegro_storage_dir($prod).'/account.enc');a_reject(fn()=>allegro_public_status($prod),'cross env','storage');
    a_reject(fn()=>allegro_public_status(array_replace($config,['client_id'=>'another-client'])),'cross client','storage');
    a_reject(fn()=>allegro_public_status(array_replace($config,['auth_flow'=>'AUTHORIZATION_CODE'])),'legacy context','storage');
    foreach ([['token_type'=>'mac'],['token_type'=>[]],['scope'=>ALLEGRO_B_SCOPE.' allegro:api:sale:offers:write'],['expires_in'=>0],['expires_in'=>1.2],['access_token'=>"bad\r\n"],['refresh_token'=>'']] as $bad) a_reject(fn()=>allegro_tokens(array_replace($tokenData,$bad),$now),'strict token');
    foreach (['user_code'=>['','bad code',[],str_repeat('x',129)], 'device_code'=>['',"bad\n",[],str_repeat('x',4097)], 'expires_in'=>[0,-1,86401,'1e3',true,1.2], 'interval'=>[0,301,[],true] ] as $field=>$values) {
        foreach($values as $bad)a_reject(fn()=>allegro_device_response($config,array_replace($device,[$field=>$bad])),'malformed device');
    }
    a_check(allegro_device_response($config,array_replace($device,['expires_in'=>'3600','interval'=>'5']))['interval']===5,'documented decimal strings');
    foreach (array_merge(range(0,32),[127]) as $control) foreach (['user_code','device_code'] as $field) {
        a_reject(fn()=>allegro_device_response($config,array_replace($device,[$field=>'valid'.chr($control).'code'])),'control characters rejected');
    }
    $limits=allegro_device_response($config,array_replace($device,['expires_in'=>'86400','interval'=>'300','device_code'=>str_repeat('x',4096)]));
    a_check($limits['expires_in']===86400 && $limits['interval']===300 && strlen($limits['device_code'])===4096,'device numeric and code upper bounds');

    foreach(['POST','GET','PATCH','PUT','DELETE'] as $method)foreach(['/sale/offers','/offers','/sale/categories','https://evil.test/me','/auth/oauth/authorize','/me?x=1'] as $p){
        $n=0;a_reject(function()use($config,$method,$p,&$n){allegro_http($config,$method,$p,'fixture',[],function()use(&$n){$n++;return [];});},'HTTP policy','policy');a_check($n===0,'policy before transport');
    }
    a_reject(fn()=>allegro_request_spec($config,'POST','/auth/oauth/token','',['grant_type'=>'authorization_code','code'=>'fake','code_verifier'=>str_repeat('a',43)]),'old exchange','policy');
    foreach(['http://allegro.pl/auth/oauth/device','https://evil.test/auth/oauth/device','https://api.allegro.pl/sale/offers','https://allegro.pl/auth/oauth/token?x=1'] as $url)a_reject(fn()=>allegro_curl(['method'=>'POST','url'=>$url]),'curl policy','policy');
    $start();$due();$tokenReply=$reply($tokenData);allegro_device_poll($config,$binding,$now,$transport);
    allegro_diagnose($config,$now,$transport);a_check($read()['lastCheckedAt']===gmdate('c',$now),'identity diagnostics');
    a_reject(fn()=>allegro_diagnose($config,$now,fn()=>$reply(['id'=>'999','login'=>'other'])),'identity mismatch','identity');a_check(!isset($read()['tokens']),'mismatch disables tokens');
    $start();allegro_disconnect(array_replace($config,['enabled'=>false,'secret'=>'']));a_check($read()['status']==='disconnected'&&!isset($read()['device']),'disconnect cancels all');
    allegro_log($config,'PRIVATE_REMOTE',['device_code'=>'PRIVATE_REMOTE','user_code'=>'PRIVATE_REMOTE','errorCode'=>'PRIVATE_REMOTE','Authorization'=>'PRIVATE_REMOTE']);
    $logs=file_get_contents(allegro_storage_dir($config).'/technical.jsonl');
    foreach([$device['device_code'],$device['user_code'],$config['secret'],$binding,'fixture-access','fixture-refresh','rotated-access','rotated-refresh','PRIVATE_REMOTE'] as $secret)a_check(!str_contains($logs,$secret),'no secrets in logs');
    echo "PASS: Allegro Device/vault/policy/rotation/logs — {$assertions} assertions\n";
} finally { a_remove($dir); }
