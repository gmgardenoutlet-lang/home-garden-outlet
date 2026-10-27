import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {mkdtemp,mkdir,writeFile,readFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {randomBytes} from 'node:crypto';
import net from 'node:net';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const temp=await mkdtemp(path.join(tmpdir(),'hgo-olx-publication-http-'));
const quote=s=>"'"+s.replaceAll('\\','/').replaceAll("'","\\'")+"'";
const id='prd_'+'b'.repeat(32), product={productId:id,name:'Fixture garden decoration',status:'available'};
const prepared={product,draft:{fixture:true},category:{id:99,name:'Fixture',is_leaf:true},definitions:[],payload:{external_id:id,auto_extend_enabled:false,title:product.name,images:[]}};
for(const dir of ['vault','sessions']) await mkdir(path.join(temp,dir));
await writeFile(path.join(temp,'config.php'),`<?php return ['OLX_CLIENT_SECRET'=>'fixture','OLX_TOKEN_KEY'=>'${randomBytes(32).toString('base64')}','OLX_STORAGE_DIR'=>${quote(path.join(temp,'vault'))},'OLX_CALLBACK_LOGS_REDACTED'=>'1'];`);
const lib=path.join(root,'hosting/getspace/admin/olx/publish-lib.php');
const helper=`<?php
 require ${quote(lib)};
 function fixture_catalog() { return json_decode(${quote(JSON.stringify({products:[product]}))},true); }
 function fixture_prepare($config,$catalog,$id,$safety) { return json_decode(${quote(JSON.stringify(prepared))},true); }
 function fixture_categories($config) { return []; }
 function fixture_publish($config,$id,$hash,$prepare,$unused=null,$freshness=null) {
   return olx_publish_one($config,$id,$hash,$prepare,static function($method,$path,$payload=[]) use($id) {
     file_put_contents(${quote(path.join(temp,'calls'))},json_encode([$method,$path,$payload])."\\n",FILE_APPEND);
     if($method==='POST') return ['http'=>200,'data'=>['data'=>['id'=>789,'external_id'=>$id,'status'=>'new']]];
     return ['http'=>200,'data'=>['data'=>$path==='/adverts'?[]:['id'=>789,'external_id'=>$id,'status'=>'unconfirmed']]];
   });
 }
`;
await writeFile(path.join(temp,'helper.php'),helper);
// Injection exists only in a temporary fixture copy; deployed routes have no test mode.
const page=(await readFile(path.join(root,'hosting/getspace/admin/olx/publish.php'),'utf8'))
 .replace("require __DIR__.'/../lib.php';",`require ${quote(path.join(root,'hosting/getspace/admin/lib.php'))};`)
 .replace("require __DIR__.'/publish-lib.php';",`require ${quote(path.join(temp,'helper.php'))};`)
 .replaceAll('load_catalog()','fixture_catalog()')
 .replaceAll('olx_publish_prepare_with_safety(','fixture_prepare(')
 .replaceAll('olx_categories(','fixture_categories(')
 .replaceAll('olx_publish_one(','fixture_publish(');
await writeFile(path.join(temp,'publish.php'),page);
await writeFile(path.join(temp,'router.php'),`<?php
 if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/login-fixture') {
 require ${quote(path.join(root,'hosting/getspace/admin/lib.php'))}; boot_admin(); $_SESSION['logged_in']=true; $_SESSION['username']='fixture'; echo json_encode(['csrf'=>csrf_token()]); return;
 } require ${quote(path.join(temp,'publish.php'))};`);
const probe=net.createServer(); await new Promise(r=>probe.listen(0,'127.0.0.1',r)); const port=probe.address().port; await new Promise(r=>probe.close(r));
const phpArgs=JSON.parse(process.env.HGO_TEST_PHP_ARGS||'[]');
const server=spawn(process.env.HGO_TEST_PHP||'php',[...phpArgs,'-d',`session.save_path=${path.join(temp,'sessions')}`,'-S',`127.0.0.1:${port}`,'-t',path.join(root,'hosting/getspace'),path.join(temp,'router.php')],{cwd:root,env:{...process.env,HGO_OLX_CONFIG:path.join(temp,'config.php'),HGO_STORAGE_DIR:path.join(temp,'admin-storage')},stdio:'ignore'});
const url=`http://127.0.0.1:${port}/admin/olx/publish.php?productId=${id}`;
const request=(fields,cookie)=>fetch(url,{redirect:'manual',...(fields?{method:'POST',body:new URLSearchParams(fields)}:{}),headers:{cookie:cookie||''}});
const calls=async()=>{try{return (await readFile(path.join(temp,'calls'),'utf8')).trim().split('\n').filter(Boolean).map(JSON.parse);}catch{return [];}};
try {
 let up=false; for(let i=0;i<60;i++){try{await request();up=true;break;}catch{await new Promise(r=>setTimeout(r,50));}} assert(up);
 assert.equal((await request({action:'publish',csrf:'bad',confirm:'yes'})).status,302,'no session redirects'); assert.deepEqual(await calls(),[]);
 const login=await fetch(`http://127.0.0.1:${port}/login-fixture`); const cookie=login.headers.get('set-cookie').split(';')[0]; const {csrf}=await login.json();
 let response=await request({action:'publish',csrf:'bad',confirm:'yes'},cookie); assert.equal(response.status,403,'CSRF rejected'); assert.deepEqual(await calls(),[]);
 response=await request(null,cookie); assert.equal(response.status,200); assert.deepEqual(await calls(),[],'GET never publishes');
 response=await request({action:'publish',csrf,confirm:'yes',nonce:'invented',payload:'{}'},cookie); assert.match(await response.text(),/Potwierdzenie wygasło/); assert.deepEqual(await calls(),[],'no approved preview');
 const review={action:'review',csrf,safety_confirm:'yes'};
 response=await request(review,cookie); let html=await response.text(); assert.match(html,/GOTOWE — finalny podgląd/);
 const nonce=html.match(/name="nonce" value="([a-f0-9]+)"/)[1]; assert.deepEqual(await calls(),[],'review never POSTs');
 response=await request({action:'publish',csrf,nonce},cookie); assert.match(await response.text(),/Potwierdzenie wygasło/); assert.deepEqual(await calls(),[],'extra confirmation mandatory');
 response=await request(review,cookie); html=await response.text(); const freshNonce=html.match(/name="nonce" value="([a-f0-9]+)"/)[1];
 const confirm={action:'publish',csrf,nonce:freshNonce,confirm:'yes',payload:JSON.stringify({external_id:'evil',auto_extend_enabled:true})};
 const results=await Promise.all([request(confirm,cookie),request(confirm,cookie)]);
 assert.equal(results.filter(r=>r.status===303).length,1,'double click one accepted request');
 const recorded=await calls(); assert.deepEqual(recorded.map(c=>c[0]),['GET','POST','GET']);
 assert.equal(recorded[1][2].external_id,id,'arbitrary payload ignored'); assert.equal(recorded[1][2].auto_extend_enabled,false);
 response=await request(null,cookie); html=await response.text(); assert.match(html,/789/); assert.match(html,/unconfirmed/); assert.doesNotMatch(html,/value="publish"/,'published product cannot publish again');
 await request(confirm,cookie); assert.equal((await calls()).filter(c=>c[0]==='POST').length,1,'refresh/replay cannot repeat POST');
 console.log('PASS: Stage D HTTP session, POST, CSRF, preview nonce, explicit checkbox, double click/replay, arbitrary payload rejection, actual GET status; synthetic transport only');
} finally {
 server.kill(); await new Promise(r=>server.once('exit',r)); await rm(temp,{recursive:true,force:true});
}
