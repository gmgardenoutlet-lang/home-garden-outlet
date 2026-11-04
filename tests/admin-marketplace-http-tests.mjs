import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {mkdtemp,mkdir,writeFile,readFile,rm,cp,readdir} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {fileURLToPath,pathToFileURL} from 'node:url';
import {randomBytes,createHash} from 'node:crypto';
import net from 'node:net';
import {publicAdminSource} from '../scripts/getspace-artifact-privacy.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const temp=await mkdtemp(path.join(tmpdir(),'hgo-marketplace-http-'));const site=path.join(temp,'site');
const php=process.env.HGO_TEST_PHP||'php',args=JSON.parse(process.env.HGO_TEST_PHP_ARGS||'[]');
const quote=value=>"'"+value.replaceAll('\\','/').replaceAll("'","\\'")+"'";
const ids=['a','b','c','d'].map(c=>'prd_'+c.repeat(32));
const products=ids.map((id,i)=>({productId:id,name:['Figura ogrodowa Anioł z rozłożonymi skrzydłami – 75 cm','Figura ogrodowa Żuraw dekoracyjny','Figura ogrodowa betonowa','Figura testowa bez zapisanego statusu'][i],
 slug:'fixture-'+i,saleType:'garden_figure',category:'Figury i dekoracje ogrodowe',shopVisible:i!==3,shopStatus:i===1?'Ukryty':'Dostępny',status:'Dostępne',productStatus:'Aktywny',image:'/uploads/fixture.webp',grossPrice:'340 zł',description:'Opis testowy figury.'}));
let server;
try {
 await cp(path.join(root,'hosting/getspace'),site,{recursive:true,filter:publicAdminSource});
 for(const dir of ['sessions','admin-storage','allegro','allegro/prod','olx','olx/publications','olx/drafts']) await mkdir(path.join(temp,dir),{recursive:true});
 await mkdir(path.join(site,'data'),{recursive:true});await mkdir(path.join(site,'uploads'),{recursive:true});
 await writeFile(path.join(site,'data/products.json'),JSON.stringify({products}));
 await writeFile(path.join(site,'uploads/fixture.webp'),Buffer.from('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAQAcJaQAA3AA/v89WAAAAA==','base64'));
 await writeFile(path.join(site,'admin/.credentials.php'),"<?php return ['username'=>'fixture','password_hash'=>'SYNTHETIC_NOT_USED'];");
 const key=randomBytes(32).toString('base64');
 await writeFile(path.join(temp,'allegro-config.php'),`<?php return ['ALLEGRO_ENVIRONMENT'=>'PROD','ALLEGRO_AUTH_FLOW'=>'DEVICE','ALLEGRO_CLIENT_ID'=>'SYNTHETIC_CLIENT','ALLEGRO_CLIENT_SECRET'=>'SYNTHETIC_SECRET','ALLEGRO_TOKEN_KEY'=>'${key}','ALLEGRO_STORAGE_DIR'=>${quote(path.join(temp,'allegro'))},'ALLEGRO_OAUTH_ENABLED'=>'1'];`);
 await writeFile(path.join(temp,'olx-config.php'),`<?php return ['OLX_STORAGE_DIR'=>${quote(path.join(temp,'olx'))}];`);
 await writeFile(path.join(temp,'seed.php'),`<?php require ${quote(path.join(site,'admin/allegro/lib.php'))};
  $config=allegro_config();$v=allegro_context($config)+['status'=>'connected','account'=>['id'=>'123','login'=>'fixture-owner'],
  'tokens'=>['access'=>'SYNTHETIC_ACCESS','refresh'=>'SYNTHETIC_REFRESH','expires_at'=>time()+3600,'scope'=>allegro_scope_request()],
  'pilot'=>['productId'=>'${ids[0]}','offerId'=>'789','status'=>'ACTIVE']];
  $nonce=random_bytes(24);file_put_contents(${quote(path.join(temp,'allegro/prod/account.enc'))},$nonce.sodium_crypto_secretbox(json_encode($v),$nonce,base64_decode($config['key'])));`);
 const env={...process.env,HGO_STORAGE_DIR:path.join(temp,'admin-storage'),HGO_ALLEGRO_CONFIG:path.join(temp,'allegro-config.php'),HGO_OLX_CONFIG:path.join(temp,'olx-config.php')};
 for(const name of Object.keys(env)) if(name.startsWith('ALLEGRO_')||name.startsWith('OLX_')) delete env[name];
 await new Promise((resolve,reject)=>{const worker=spawn(php,[...args,path.join(temp,'seed.php')],{env,stdio:'ignore'});worker.on('exit',code=>code===0?resolve():reject(new Error('Fixture seed failed')));worker.on('error',reject);});
 for(const i of [0,1]) await writeFile(path.join(temp,`olx/publications/${ids[i]}.json`),JSON.stringify({productId:ids[i],external_id:ids[i],advert_id:String(100+i),phase:'created',status:'active',verified_at:Math.floor(Date.now()/1000),valid_to:new Date(Date.now()+86400000).toISOString(),url:'https://www.olx.pl/d/oferta/fixture-ID123.html'}));
 await writeFile(path.join(temp,`olx/drafts/${ids[2]}.json`),JSON.stringify({titleOverride:'Szkic figury'}));
 await writeFile(path.join(temp,'router.php'),`<?php
  if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/fixture-login') {require ${quote(path.join(site,'admin/lib.php'))};boot_admin();$_SESSION['logged_in']=true;$_SESSION['username']='fixture';header('Content-Type: application/json');echo json_encode(['csrf'=>csrf_token()]);return;}return false;`);
 const probe=net.createServer();await new Promise(resolve=>probe.listen(0,'127.0.0.1',resolve));const port=probe.address().port;await new Promise(resolve=>probe.close(resolve));
 const origin=`http://127.0.0.1:${port}`;
 server=spawn(php,[...args,'-d','error_reporting=24575','-d',`session.save_path=${path.join(temp,'sessions')}`,'-d','disable_functions=curl_exec','-d','allow_url_fopen=0','-S',`127.0.0.1:${port}`,'-t',site,path.join(temp,'router.php')],{env,stdio:'ignore'});
 const get=async(url,options={})=>{const r=await fetch(origin+url,{redirect:'manual',...options});const body=await r.arrayBuffer();return new Response(body,{status:r.status,headers:r.headers});};
 for(let i=0;i<60;i++){try{await get('/admin/');break;}catch{await new Promise(resolve=>setTimeout(resolve,50));}}
 const login=await get('/fixture-login');const cookie=login.headers.get('set-cookie').split(';')[0];const {csrf}=await login.json();
 const headers={cookie};const fingerprint=async()=>{
  const files=[];for(const folder of ['allegro/prod','olx/publications','olx/drafts'])for(const name of await readdir(path.join(temp,folder)))files.push([folder+'/'+name,createHash('sha256').update(await readFile(path.join(temp,folder,name))).digest('hex')]);return JSON.stringify(files);
 };
 const before=await fingerprint();const originalVault=await readFile(path.join(temp,'allegro/prod/account.enc'));const catalogue=await readFile(path.join(site,'data/products.json'),'utf8');
 const list=await get('/admin/?figures=1',{headers});assert.equal(list.status,200);const html=await list.text();
 assert.equal((html.match(/class="product-row"/g)||[]).length,4);
 assert.match(html,/Odśwież statusy Allegro i OLX/);assert.match(html,/Ostatnia udana synchronizacja/);
 assert.match(html,/Otwórz Allegro/);assert.match(html,/Otwórz OLX/);assert.match(html,/Szkic \(lokalny\)/);assert.match(html,/Status nieustalony/);
 assert.match(html,/Usunięcie z katalogu MG Outlet nie kończy/);assert.match(html,/product-delete/);
 for(const secret of [key,'SYNTHETIC_ACCESS','SYNTHETIC_REFRESH','SYNTHETIC_SECRET']) assert(!html.includes(secret));
 for(const [filter,count] of [['allegro-active',1],['olx-active',2],['olx-draft',1],['unknown',3]]) {
  const body=await (await get('/admin/?figures=1&platform='+filter,{headers})).text();assert.equal((body.match(/class="product-row"/g)||[]).length,count);
 }
 for(const [filter,count] of [['q=Anio%C5%82',1],['visibility=visible',3],['status=Ukryty',1]]) {
  const body=await (await get('/admin/?figures=1&'+filter,{headers})).text();assert.equal((body.match(/class="product-row"/g)||[]).length,count);
 }
 assert.equal((await get('/admin/marketplace-sync.php',{headers})).status,404);
 assert.equal(await fingerprint(),before,'list/filter must not write integrations');
 const badSync=await get('/admin/',{method:'POST',headers:{...headers,'content-type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'sync_marketplaces',csrf:'wrong'})});
 assert.equal(badSync.status,302);assert.equal(await fingerprint(),before);
 const anonSync=await get('/admin/',{method:'POST',headers:{'content-type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'sync_marketplaces',csrf})});
 assert.equal(anonSync.status,302);assert.equal(await fingerprint(),before);
 const sync=await get('/admin/',{method:'POST',headers:{...headers,'content-type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'sync_marketplaces',csrf,return:'figures'})});
 assert.equal(sync.status,302);assert.deepEqual(await readFile(path.join(temp,'allegro/prod/account.enc')),originalVault);
 const afterSync=JSON.parse(await fingerprint());for(const [name,hash] of JSON.parse(before))assert.equal(new Map(afterSync).get(name),hash,'all existing integration records remain byte-identical');
 const syncedHtml=await (await get('/admin/?figures=1',{headers})).text();assert.match(syncedHtml,/Postęp OLX/);assert.match(syncedHtml,/Aktualizacja nie powiodła się/);
 const cached=await readFile(path.join(temp,'private_marketplace/statuses.json'),'utf8');
 for(const secret of [key,'SYNTHETIC_ACCESS','SYNTHETIC_REFRESH','SYNTHETIC_SECRET'])assert(!cached.includes(secret));
 const cipherBefore=await readFile(path.join(temp,'allegro/prod/account.enc'));
 const hint=await (await get('/admin/allegro/?productId='+ids[2],{headers})).text();assert(hint.includes(`value="${ids[2]}" selected`));
 assert.deepEqual(await readFile(path.join(temp,'allegro/prod/account.enc')),cipherBefore,'existing Allegro page preserves offer/tokens');
 const afterNavigation=await fingerprint(); // Existing Allegro screen may create its normal lock file.
 assert.equal(await readFile(path.join(site,'data/products.json'),'utf8'),catalogue,'GET preserves catalogue');
 assert.equal((await get('/admin/marketplace-status.php')).status,404);
 const wrong=await get('/admin/',{method:'POST',headers,body:new URLSearchParams({action:'delete_product',index:'0',csrf:'wrong'})});assert.equal(wrong.status,302);assert.equal(await readFile(path.join(site,'data/products.json'),'utf8'),catalogue,'invalid CSRF cannot delete');
 await get('/admin/?figures=1',{headers}); // Consume the expected CSRF flash before the visual fixture.
 if(process.env.HGO_ALLEGRO_PLAYWRIGHT_MODULE){
  const {chromium}=await import(pathToFileURL(process.env.HGO_ALLEGRO_PLAYWRIGHT_MODULE).href);
  const browser=await chromium.launch({headless:true,executablePath:process.env.HGO_ALLEGRO_BROWSER_EXECUTABLE});
  try{
   const context=await browser.newContext({viewport:{width:1366,height:900}});const sep=cookie.indexOf('=');
   await context.addCookies([{name:cookie.slice(0,sep),value:cookie.slice(sep+1),url:origin}]);let writes=0;
   await context.route('**/*',r=>{if(!r.request().url().startsWith(origin+'/'))return r.abort();if(r.request().method()!=='GET'){writes++;return r.abort();}return r.continue();});
   const page=await context.newPage();await page.goto(origin+'/admin/?figures=1');
   const first=page.locator('.marketplace-product-list .product-row').first();
   const channels=first.locator('[data-marketplace]');assert.equal(await channels.count(),2);
   const a=await channels.nth(0).boundingBox(),b=await channels.nth(1).boundingBox();assert(Math.abs(a.y-b.y)<2&&a.x+a.width<=b.x,'desktop badges side by side');
   const buttons=await first.locator('.row-actions .btn').all();
   const bounds=await Promise.all(buttons.map(button=>button.boundingBox()));
   for(let i=0;i<bounds.length;i++)for(let j=i+1;j<bounds.length;j++){const a=bounds[i],b=bounds[j];assert(!(Math.min(a.x+a.width,b.x+b.width)-Math.max(a.x,b.x)>1&&Math.min(a.y+a.height,b.y+b.height)-Math.max(a.y,b.y)>1),'buttons must not overlap');}
   let prompt='';page.once('dialog',async dialog=>{prompt=dialog.message();await dialog.dismiss();});await first.getByRole('button',{name:'Usuń',exact:true}).click();assert(prompt.includes('nie kończy'));assert.equal(writes,0);
   const out=path.join(root,'.local-cache/admin-marketplace-ui');await mkdir(out,{recursive:true});await page.evaluate(()=>window.scrollTo(0,0));await page.screenshot({path:path.join(out,'desktop-1366.png'),fullPage:true});
   await page.setViewportSize({width:1920,height:1080});await page.screenshot({path:path.join(out,'desktop-1920.png'),fullPage:true});
   await page.setViewportSize({width:390,height:844});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'mobile no horizontal overflow');
   const mobileA=await channels.nth(0).boundingBox(),mobileB=await channels.nth(1).boundingBox();assert(mobileB.y>=mobileA.y+mobileA.height,'mobile statuses stack');
   await page.screenshot({path:path.join(out,'mobile-390.png'),fullPage:true});assert.equal(writes,0);
   console.log('PASS: product-list desktop/mobile, status cards, links and delete confirmation; zero API');
  }finally{await browser.close();}
 }
 // Existing sold/restore action is exercised only on this disposable catalogue.
 for(const expected of ['Sprzedane','Dostępne']) {
  const result=await get('/admin/',{method:'POST',headers,body:new URLSearchParams({action:'toggle_sold',index:'0',return:'figures',csrf})});assert.equal(result.status,302);
  assert.equal(JSON.parse(await readFile(path.join(site,'data/products.json'),'utf8')).products[0].status,expected);
  const refreshed=await (await get('/admin/?figures=1',{headers})).text();assert(refreshed.includes('<span class="product-availability">'+(expected==='Sprzedane'?'Sprzedane':'Dostępny')+'</span>'));
 }
 assert.equal(await fingerprint(),afterNavigation,'sold/restore does not mutate marketplace records');
 console.log('PASS: product-list HTTP filters/statuses/navigation/sold/restore; vaults unchanged');
}finally{
 if(server){server.kill();await new Promise(resolve=>server.exitCode!==null?resolve():server.once('exit',resolve));}
 await rm(temp,{recursive:true,force:true});
}
