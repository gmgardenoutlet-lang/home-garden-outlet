import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, mkdir, writeFile, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomBytes, createHash } from 'node:crypto';
import net from 'node:net';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const temp = await mkdtemp(path.join(tmpdir(), 'hgo-olx-http-'));
const phpString = value => `'${value.replaceAll('\\', '/').replaceAll("'", "\\'")}'`;
await mkdir(path.join(temp, 'vault'));
await mkdir(path.join(temp, 'sessions'));
await writeFile(path.join(temp, 'config.php'), `<?php return [
 'OLX_CLIENT_SECRET' => '${randomBytes(20).toString('hex')}',
 'OLX_TOKEN_KEY' => '${randomBytes(32).toString('base64')}',
 'OLX_STORAGE_DIR' => ${phpString(path.join(temp, 'vault'))},
 'OLX_CALLBACK_LOGS_REDACTED' => '1',
];`);
// Only this temporary callback copy uses a synthetic transport. Production has no test switch.
const callbackFixture = (await readFile(path.join(root, 'hosting/getspace/admin/olx/callback.php'), 'utf8'))
  .replace("require __DIR__ . '/lib.php';", `require ${phpString(path.join(root, 'hosting/getspace/admin/olx/lib.php'))};`)
  .replace("isset($_GET['error']), time());", "isset($_GET['error']), time(), static fn() => ['access_token'=>'fixture-access', 'refresh_token'=>'fixture-refresh', 'expires_in'=>3600, 'scope'=>'v2 read write', 'token_type'=>'bearer']);");
await writeFile(path.join(temp, 'callback.php'), callbackFixture);
const preparationProduct = { productId: 'prd_'+'a'.repeat(32), name: 'Konsola drewniana biała do salonu', category: 'Meble',
  description: 'Opis produktu z katalogu. '.repeat(5), longDescription: '', outletPrice: '240 zł', catalogPrice: '900 zł',
  slug: 'fixture', currency: 'PLN', image: '/uploads/fixture.webp', gallery: [] };
const preparationFixture = (await readFile(path.join(root, 'hosting/getspace/admin/olx/prepare.php'), 'utf8'))
  .replace("require __DIR__ . '/../lib.php';", `require ${phpString(path.join(root, 'hosting/getspace/admin/lib.php'))};`)
  .replace("require __DIR__ . '/prepare-lib.php';", `require ${phpString(path.join(root, 'hosting/getspace/admin/olx/prepare-lib.php'))};`)
  .replace("require_once __DIR__ . '/publish-lib.php';", `require_once ${phpString(path.join(root, 'hosting/getspace/admin/olx/publish-lib.php'))};`)
  .replace('$catalog = load_catalog();', `$catalog = json_decode(${phpString(JSON.stringify({products:[preparationProduct]}))}, true);`);
await writeFile(path.join(temp, 'prepare.php'), preparationFixture);
await mkdir(path.join(temp, 'vault/cache'), {mode:0o700});
const category = {id:99,name:'Konsole',parent_id:1,is_leaf:true,photos_limit:2};
const definitions = [{code:'state',label:'Stan',validation:{required:true,type:'attribute'},values:[{code:'used',label:'Używane'}]}];
for (const [apiPath,data] of [['/categories',[{id:1,name:'Dom',is_leaf:false,parent_id:null},category]],['/currencies',[{code:'PLN'}]],
  ['/categories/99',category],['/categories/99/attributes',definitions],['/cities/10',{id:10,name:'Fixture'}],['/cities/10/districts',[]]]) {
  const key=createHash('sha256').update(apiPath+'[]').digest('hex');
  await writeFile(path.join(temp,'vault/cache',key+'.json'),JSON.stringify({data,expires:Math.floor(Date.now()/1000)+3600}),{mode:0o600});
}
await writeFile(path.join(temp, 'router.php'), `<?php
 $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
 if ($path === '/fixture-login') {
   require ${phpString(path.join(root, 'hosting/getspace/admin/lib.php'))};
   boot_admin(); $_SESSION['logged_in'] = true; $_SESSION['username'] = 'fixture';
   header('Content-Type: application/json'); echo json_encode(['csrf'=>csrf_token()]); return;
 }
 if ($path === '/admin/olx/callback') { require ${phpString(path.join(temp, 'callback.php'))}; return; }
 if ($path === '/admin/olx/prepare.php') { require ${phpString(path.join(temp, 'prepare.php'))}; return; }
 return false;
`);
const portProbe = net.createServer();
await new Promise(resolve => portProbe.listen(0, '127.0.0.1', resolve));
const port = portProbe.address().port;
await new Promise(resolve => portProbe.close(resolve));
const origin = `http://127.0.0.1:${port}`;
const phpArgs = JSON.parse(process.env.HGO_TEST_PHP_ARGS || '[]');
const workerEnv = { ...process.env, HGO_STORAGE_DIR: path.join(temp, 'admin-storage'), HGO_OLX_CONFIG: path.join(temp, 'config.php') };
await writeFile(path.join(temp, 'worker.php'), `<?php
 require ${phpString(path.join(root, 'hosting/getspace/admin/olx/lib.php'))};
 $config = olx_config();
 if (($argv[1] ?? '') === 'seed') {
   olx_locked($config, function ($vault, $key) use ($config) {
     olx_write_vault($config, $key, ['status'=>'connected', 'tokens'=>['access'=>'fixture-old', 'refresh'=>'fixture-old-refresh', 'expires'=>time()-1]]);
   });
 } else {
   $token = olx_access_token($config, time(), function ($config, $grant) {
     file_put_contents(${phpString(path.join(temp, 'refresh-count'))}, '+', FILE_APPEND);
     usleep(250000);
     return ['access_token'=>'fixture-rotated', 'refresh_token'=>'fixture-new-refresh', 'expires_in'=>3600, 'scope'=>'read write v2', 'token_type'=>'bearer'];
   });
   if ($token !== 'fixture-rotated') exit(2);
 }
 echo 'OK';
`);
const worker = mode => new Promise((resolve, reject) => {
  const child = spawn(process.env.HGO_TEST_PHP || 'php', [...phpArgs, path.join(temp, 'worker.php'), mode], { cwd: root, env: workerEnv });
  let output = '';
  child.stdout.on('data', data => { output += data; });
  child.stderr.on('data', data => { output += data; });
  child.on('error', reject);
  child.on('exit', code => code === 0 && output === 'OK' ? resolve() : reject(new Error('Refresh worker failed')));
});
const server = spawn(process.env.HGO_TEST_PHP || 'php', [...phpArgs,
  '-d', `session.save_path=${path.join(temp, 'sessions')}`,
  '-S', `127.0.0.1:${port}`, '-t', path.join(root, 'hosting/getspace'), path.join(temp, 'router.php')], {
  cwd: root, env: workerEnv,
  stdio: 'ignore',
});
const request = (url, options = {}) => fetch(origin + url, { redirect: 'manual', ...options });
const post = (fields, cookie = '') => request('/admin/olx/', { method: 'POST', headers: { cookie }, body: new URLSearchParams(fields) });
try {
  let available = false;
  for (let i = 0; i < 60; i++) {
    try { await request('/admin/olx/'); available = true; break; } catch {}
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  assert(available, 'PHP server starts');
  let response = await post({ action: 'connect', csrf: 'invalid' });
  assert.equal(response.status, 302, 'anonymous operation redirects to login');
  assert.equal(response.headers.get('location'), '/admin/');
  response = await request('/fixture-login');
  const setCookie = response.headers.get('set-cookie');
  assert.match(setCookie, /HttpOnly/i);
  assert.match(setCookie, /SameSite=Strict/i);
  const cookie = setCookie.split(';')[0];
  const { csrf } = await response.json();
  const preparePath='/admin/olx/prepare.php?productId='+preparationProduct.productId;
  response=await request(preparePath); assert.equal(response.status,302,'anonymous preview denied');
  response=await request(preparePath,{headers:{cookie}});
  let preparationHtml=await response.text(); assert.match(preparationHtml,/PAYLOAD PREVIEW/); assert.match(preparationHtml,/value="240"/);
  assert(!preparationHtml.includes('fixture-access') && !preparationHtml.includes('fixture-refresh'),'no tokens in preview');
  const prepPost=fields=>request(preparePath,{method:'POST',headers:{cookie},body:new URLSearchParams(fields)});
  response=await prepPost({action:'category',csrf:'wrong',selectedCategoryId:'99'});
  assert(!(await response.text()).includes('Kategoria szkicu zatwierdzona'),'CSRF protects category draft');
  response=await prepPost({action:'category',csrf,selectedCategoryId:'99'});
  assert.match(await response.text(),/Kategoria szkicu zatwierdzona/);
  response=await prepPost({action:'save',csrf,titleOverride:preparationProduct.name,descriptionOverride:preparationProduct.description,
    priceOverride:'230',currency:'PLN','selectedImages[]':'https://mgoutlet.pl/uploads/fixture.webp','attributeValues[state]':'used',
    city_id:'10',district_id:'0',contact_name:'Outlet',contact_phone:'577210777'});
  preparationHtml=await response.text(); assert.match(preparationHtml,/Gotowe lokalnie/); assert.match(preparationHtml,/Zapisano wyłącznie osobny szkic/);
  const savedDraft=JSON.parse(await readFile(path.join(temp,'vault/drafts',preparationProduct.productId+'.json'),'utf8'));
  assert.equal(savedDraft.priceOverride,230); assert.equal(preparationProduct.outletPrice,'240 zł'); assert(!('advert_id' in savedDraft));
  response=await request('/admin/olx/prepare.php',{method:'POST',headers:{cookie},body:new URLSearchParams({action:'map',csrf,sourceCategory:'Meble',mappingCategory:'99'})});
  assert.match(await response.text(),/Zapisano mapowanie/);
  assert.equal(JSON.parse(await readFile(path.join(temp,'vault/settings/mapping.json'),'utf8')).mappings.Meble.category_id,99);
  response = await request('/admin/olx/', { headers: { cookie } });
  assert.match(await response.text(), /Niepołączone/);
  response = await post({ action: 'connect', csrf: 'wrong' }, cookie);
  assert.equal(response.headers.get('location'), null, 'bad CSRF cannot redirect to OLX');
  response = await post({ action: 'connect', csrf }, cookie);
  assert.equal(response.status, 200, 'same-origin POST document avoids form-action external redirect');
  assert.equal(response.headers.get('location'), null);
  const authorizationPage = await response.text();
  const authorize = new URL(authorizationPage.match(/content="0;url=([^"]+)"/)[1].replaceAll('&amp;', '&'));
  assert.equal(authorize.origin, 'https://www.olx.pl');
  const state = authorize.searchParams.get('state');
  const successfulCallback = `/admin/olx/callback?state=${state}&code=fixture-code-NEVER-REAL`;
  response = await request(successfulCallback); // no Strict admin cookie
  assert.equal(response.status, 303);
  assert.equal(response.headers.get('location'), '/admin/olx/return.php');
  assert.equal(response.headers.get('set-cookie'), null);
  assert(!(await response.text()).includes('fixture-code-NEVER-REAL'));
  response = await request(successfulCallback);
  assert.equal(response.status, 400, 'replayed callback rejected');
  response = await request('/admin/olx/', { headers: { cookie } });
  assert.match(await response.text(), /Połączone/);
  // Invalid callbacks must reject without an exchange or touching the Strict cookie.
  response = await request('/admin/olx/callback?state=invalid&code=fixture-code-NEVER-REAL');
  assert.equal(response.status, 400);
  assert.equal(response.headers.get('set-cookie'), null);
  assert.equal(response.headers.get('referrer-policy'), 'no-referrer');
  assert(!(await response.text()).includes('fixture-code-NEVER-REAL'));
  response = await request('/admin/olx/callback');
  assert.equal(response.status, 400);
  response = await request('/admin/olx/return.php');
  assert.equal(response.headers.get('set-cookie'), null);
  assert.match(await response.text(), /content="0;url=\/admin\/olx\/\?connected=1"/);
  response = await post({ action: 'disconnect', csrf, confirm_disconnect: 'yes' }, cookie);
  assert.equal(response.status, 303);
  await worker('seed');
  await Promise.all([worker('refresh'), worker('refresh')]);
  assert.equal(await readFile(path.join(temp, 'refresh-count'), 'utf8'), '+', 'parallel callers perform one refresh');
  response=await request('/admin/olx/prepare.php',{headers:{cookie}});
  let productList=await response.text();
  assert.match(productList,/class="olx-product-photo"/,'product thumbnail');
  assert.match(productList,/>Wystaw na OLX<\/a>/,'manual draft entry');
  await mkdir(path.join(temp,'vault/publications'),{mode:0o700});
  await writeFile(path.join(temp,'vault/publications',preparationProduct.productId+'.json'),JSON.stringify({phase:'created',advert_id:'789',status:'limited'}),{mode:0o600});
  response=await request('/admin/olx/prepare.php',{headers:{cookie}}); productList=await response.text();
  assert.match(productList,/NIEOPŁACONE — LIMIT OGŁOSZEŃ/,'actual limited status');
  assert.match(productList,/>Szczegóły OLX<\/a>/,'existing advert details action');
  assert.doesNotMatch(productList,/>Wystaw na OLX<\/a>/,'existing advert never gets publish action');
  console.log('PASS: OLX HTTP login, Strict cookie, CSRF, cookie-free callback, clean return, disconnect, concurrent refresh');
} finally {
  server.kill();
  await new Promise(resolve => { if (server.exitCode !== null) resolve(); else server.once('exit', resolve); });
  await rm(temp, { recursive: true, force: true });
}
