import vm from 'node:vm';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, mkdir, writeFile, readFile, rm, cp, readdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { randomBytes, createHash } from 'node:crypto';
import net from 'node:net';
import { publicAdminSource } from '../scripts/getspace-artifact-privacy.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const temp = await mkdtemp(path.join(tmpdir(), 'hgo-allegro-http-'));
const site = path.join(temp, 'site');
const phpString = value => `'${value.replaceAll('\\', '/').replaceAll("'", "\\'")}'`;
const secret = randomBytes(24).toString('hex');
const key = randomBytes(32).toString('base64');
const php = process.env.HGO_TEST_PHP || 'php';
const phpArgs = JSON.parse(process.env.HGO_TEST_PHP_ARGS || '[]');
let server;
const workerEnv = { ...process.env, HGO_STORAGE_DIR: path.join(temp, 'admin-storage'), HGO_ALLEGRO_CONFIG: path.join(temp, 'config.php') };
for (const name of Object.keys(workerEnv)) if (name.startsWith('ALLEGRO_')) delete workerEnv[name];
try {
  await cp(path.join(root, 'hosting/getspace'), site, { recursive: true, filter: publicAdminSource });
  await mkdir(path.join(temp, 'vault'));
  await mkdir(path.join(temp, 'sessions'));
  await mkdir(path.join(site, 'data'), { recursive: true });
  await writeFile(path.join(site, 'data/products.json'), JSON.stringify({ products: [{
    productId: `prd_${'a'.repeat(32)}`, name: 'Fixture figura', slug: 'fixture-figura', saleType: 'garden_figure',
    material: 'Beton', description: 'Opis figury z katalogu.', longDescription: 'Szczegóły wykończenia figury.', shopVisible: true, shopStatus: 'Dostępny', category: 'Figury i dekoracje ogrodowe', image: '/uploads/fixture.webp', gallery: [], grossPrice: '100 zł',
  }] }));
  await mkdir(path.join(site,'uploads'),{recursive:true});
  await writeFile(path.join(site,'uploads/fixture.webp'),Buffer.from('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAQAcJaQAA3AA/v89WAAAAA==','base64'));
  const configText = `<?php return [
    'ALLEGRO_ENVIRONMENT' => 'SANDBOX', 'ALLEGRO_CLIENT_ID' => '${randomBytes(16).toString('hex')}',
    'ALLEGRO_CLIENT_SECRET' => '${secret}', 'ALLEGRO_TOKEN_KEY' => '${key}',
    'ALLEGRO_AUTH_FLOW' => 'DEVICE',
    'ALLEGRO_STORAGE_DIR' => ${phpString(path.join(temp, 'vault'))},
    'ALLEGRO_OAUTH_ENABLED' => '1', 'ALLEGRO_CALLBACK_LOGS_REDACTED' => '0',
  ];`;
  await writeFile(path.join(temp, 'config.php'), configText);
  const httpCopy = path.join(site, 'admin/allegro/http.php');
  await writeFile(httpCopy, (await readFile(httpCopy, 'utf8')).replace("$transport ?? 'allegro_curl'", "$transport ?? 'fixture_device_transport'"));
  const pilotCopy = path.join(site, 'admin/allegro/lib.php');
  await writeFile(pilotCopy, (await readFile(pilotCopy, 'utf8')).replace("$transport ?? 'allegro_curl'", "$transport ?? 'fixture_pilot_transport'"));
  await writeFile(path.join(temp, 'router.php'), `<?php
    function fixture_pilot_transport($request) {
      if ($request['method'] !== 'GET') throw new RuntimeException('Fixture forbids writes');
      $path=substr($request['url'],strlen('https://api.allegro.pl.allegrosandbox.pl'));
      $root='123e4567-e89b-12d3-a456-426614174000';$leaf='123e4567-e89b-12d3-a456-426614174001';
      if ($path==='/sale/categories') $data=['categories'=>[['id'=>$root,'name'=>'Główna','leaf'=>false]]];
      elseif ($path==='/sale/categories?parent.id='.$root) $data=['categories'=>[['id'=>'22','name'=>'Pośrednia','leaf'=>false]]];
      elseif ($path==='/sale/categories?parent.id=22') $data=['categories'=>[['id'=>$leaf,'name'=>'Końcowa','leaf'=>true]]];
      elseif ($path==='/sale/categories/'.$leaf.'/parameters') $data=['parameters'=>[
        ['id'=>'code','name'=>'Kod producenta','type'=>'string','required'=>false,'requiredForProduct'=>false],
        ['id'=>'cn','name'=>'Kod taryfy celnej','type'=>'string','restrictions'=>['minLength'=>8,'maxLength'=>8]],
        ['id'=>'state','name'=>'Stan produktu','type'=>'dictionary','required'=>true,'dictionary'=>[['id'=>'new','value'=>'Nowy'],['id'=>'used','value'=>'Używany']]],
        ['id'=>'ean','name'=>'EAN','type'=>'string','requiredIf'=>['parametersWithValue'=>[['id'=>'state','oneOfValueIds'=>['new']]]]],
      ]];
      else {
        $keys=['/sale/shipping-rates'=>'shippingRates','/after-sales-service-conditions/return-policies'=>'returnPolicies',
          '/after-sales-service-conditions/implied-warranties'=>'impliedWarranties','/sale/responsible-producers'=>'responsibleProducers','/sale/responsible-persons'=>'responsiblePersons'];
        if (!isset($keys[$path])) throw new RuntimeException('Unexpected fixture GET');
        $data=[$keys[$path]=>[['id'=>'fixture-setting','name'=>'Ustawienie konta']]];
      }
      return ['status'=>200,'body'=>json_encode($data)];
    }
    function fixture_device_transport($request) {
      file_put_contents(${phpString(path.join(temp, 'device-requests'))}, '+', FILE_APPEND | LOCK_EX);
      return ['status'=>200, 'body'=>json_encode(['user_code'=>'abc123xyz','device_code'=>'DEVICE_PRIVATE_NO_HTML',
        'expires_in'=>3600,'interval'=>5,'verification_uri'=>'https://allegro.pl.allegrosandbox.pl/skojarz-aplikacje',
        'verification_uri_complete'=>'https://allegro.pl.allegrosandbox.pl/skojarz-aplikacje?code=abc123xyz'])];
    }
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path === '/fixture-config-flags') {
      require ${phpString(path.join(site, 'admin/allegro/lib.php'))};
      echo json_encode(['logs_safe'=>allegro_config()['logs_safe'], 'file_flag_off'=>str_contains(file_get_contents(getenv('HGO_ALLEGRO_CONFIG')), "'ALLEGRO_CALLBACK_LOGS_REDACTED' => '0'"),
        'opcache'=>ini_get('opcache.enable_cli')]); return;
    }
    if ($path === '/fixture-preview-view' || $path === '/fixture-preview-clear') {
      require ${phpString(path.join(site, 'admin/lib.php'))}; boot_admin(); require_login();
      if ($path === '/fixture-preview-clear') unset($_SESSION['allegro_pilot_preview']);
      else $_SESSION['allegro_pilot_preview'] = ['payload'=>['external'=>['id'=>'prd_'.str_repeat('a',32)],'name'=>'Zapisana figura',
        'sellingMode'=>['price'=>['amount'=>'150.00']],'stock'=>['available'=>1],'delivery'=>['handlingTime'=>'P7D','additionalInfo'=>'Odbiór']], 'nonce'=>'synthetic-view-nonce'];
      header('Content-Type: text/plain'); echo 'OK'; return;
    }
    if ($path === '/fixture-login') {
      require ${phpString(path.join(site, 'admin/lib.php'))}; boot_admin();
      $_SESSION['logged_in'] = true; $_SESSION['username'] = 'fixture';
      header('Content-Type: application/json'); echo json_encode(['csrf'=>csrf_token()]); return;
    }
    if ($path === '/admin/allegro/callback') { require ${phpString(path.join(site, 'admin/allegro/callback.php'))}; return; }
    if ($path === '/admin/') {
      register_shutdown_function(function () {
        file_put_contents(${phpString(path.join(temp, 'product-includes.json'))}, json_encode(get_included_files()));
      });
    }
    return false;
  `);
  await writeFile(path.join(temp, 'worker.php'), `<?php
    require ${phpString(path.join(site, 'admin/allegro/lib.php'))};
    set_exception_handler(function ($e) { echo $e instanceof AllegroSafeException ? 'SAFE_FAILURE:'.$e->kind : 'SAFE_FAILURE:fixture'; exit(1); });
    $config = allegro_config(); $mode = $argv[1] ?? '';
    if ($mode === 'seed') {
      allegro_locked($config, function ($v, $k) use ($config) {
        allegro_write_vault($config, $k, ['status'=>'connected', 'account'=>['id'=>'123456','login'=>'<fixture&seller>'],
          'tokens'=>['access'=>'fixture-access-NO-HTML', 'refresh'=>'fixture-refresh-NO-HTML', 'expires_at'=>time()-1, 'scope'=>allegro_scope_request()]]);
      });
    } elseif (str_starts_with($mode, 'view-')) {
      allegro_locked($config, function ($v, $k) use ($config, $mode) {
        $status = substr($mode,5);
        if ($status === 'CLEAR') unset($v['pilot']);
        else {
          $v['pilot'] = ['productId'=>'prd_'.str_repeat('a',32), 'status'=>$status,
            'payload'=>['name'=>'Zapisana figura', 'sellingMode'=>['price'=>['amount'=>'150.00']], 'stock'=>['available'=>1]]];
          if ($status !== 'UNKNOWN') $v['pilot']['offerId']='789';
          if ($status === 'PROCESSING') $v['pilot']['operation']='/sale/product-offers/789/operations/op';
        }
        allegro_write_vault($config,$k,$v);
      });
    } elseif ($mode === 'refresh') {
      $token = allegro_access_token($config, time(), function ($request) {
        file_put_contents(${phpString(path.join(temp, 'refresh-count'))}, '+', FILE_APPEND | LOCK_EX);
        usleep(250000);
        return ['status'=>200, 'body'=>json_encode(['access_token'=>'fixture-rotated-NO-HTML', 'refresh_token'=>'fixture-rotated-refresh-NO-HTML',
          'token_type'=>'bearer', 'expires_in'=>3600, 'scope'=>allegro_scope_request()])];
      });
      if ($token !== 'fixture-rotated-NO-HTML') exit(2);
    } elseif ($mode === 'write') {
      for ($i=0; $i<60; $i++) allegro_locked($config, function ($v, $k) use ($config, $i) {
        $v['atomicMarker'] = $i; allegro_write_vault($config, $k, $v);
      });
    } elseif ($mode === 'read-atomic') {
      for ($i=0; $i<600; $i++) {
        $bytes = file_get_contents(allegro_storage_dir($config).'/account.enc');
        $plain = sodium_crypto_secretbox_open(substr($bytes,24),substr($bytes,0,24),base64_decode($config['key']));
        if (!is_string($plain) || !is_array(json_decode($plain,true))) exit(3);
        usleep(500);
      }
    }
    echo 'OK';
  `);
  const worker = mode => new Promise((resolve, reject) => {
    const child = spawn(php, [...phpArgs, path.join(temp, 'worker.php'), mode], { cwd: root, env: workerEnv });
    let out = '';
    child.stdout.on('data', value => { out += value; }); child.stderr.on('data', value => { out += value; });
    child.on('error', reject);
    child.on('exit', code => code === 0 && out === 'OK' ? resolve() : reject(new Error(`Allegro fixture ${mode} failed: ${out.match(/SAFE_FAILURE:[a-z]+/)?.[0] || 'fixture output rejected'}`)));
  });
  const portProbe = net.createServer();
  await new Promise(resolve => portProbe.listen(0, '127.0.0.1', resolve));
  const port = portProbe.address().port;
  await new Promise(resolve => portProbe.close(resolve));
  server = spawn(php, [...phpArgs, '-d', `session.save_path=${path.join(temp, 'sessions')}`,
    '-d', 'opcache.enable_cli=0', '-d', 'opcache.enable=0',
    '-d', 'disable_functions=curl_exec', '-d', 'allow_url_fopen=0',
    '-S', `127.0.0.1:${port}`, '-t', site, path.join(temp, 'router.php')], { cwd: root, env: workerEnv, stdio: 'ignore' });
  const request = async (url, options = {}) => {
    const response = await fetch(`http://127.0.0.1:${port}${url}`, { redirect: 'manual', ...options });
    // Drain local fixture responses, including ignored error pages, before another request.
    const body = await response.arrayBuffer();
    return new Response([204,205,304].includes(response.status) ? null : body, {status: response.status, headers: response.headers});
  };
  const post = (fields, cookie = '') => request('/admin/allegro/', { method: 'POST', headers: { cookie }, body: new URLSearchParams(fields) });
  for (let i = 0; i < 60; i++) {
    try { await request('/admin/allegro/'); break; } catch { await new Promise(resolve => setTimeout(resolve, 50)); }
  }
  let response = await post({ action: 'connect', csrf: 'wrong' });
  assert.equal(response.status, 302); assert.equal(response.headers.get('location'), '/admin/');
  response = await request('/fixture-login');
  const setCookie = response.headers.get('set-cookie');
  assert.match(setCookie, /HttpOnly/i); assert.match(setCookie, /SameSite=Strict/i);
  const cookie = setCookie.split(';')[0]; const { csrf } = await response.json();
  for (const action of ['connect', 'check-device', 'finish', 'check', 'disconnect', 'pilot-load', 'pilot-category-back', 'pilot-category-restart', 'pilot-unlock-check', 'pilot-unlock', 'pilot-preview', 'pilot-create', 'pilot-publish', 'pilot-check']) {
    response = await post({ action, csrf: 'wrong', confirm_disconnect: 'yes' }, cookie);
    assert.equal(response.status, 400, `${action}: CSRF rejected`);
    assert.equal(response.headers.get('location'), null);
  }
  response = await request('/admin/allegro/?pilot_form_script=1');
  assert.equal(response.status, 302, 'form script requires admin login');
  response = await request('/admin/allegro/?pilot_form_script=1', { headers: { cookie } });
  assert.equal(response.status, 200);
  assert.match(response.headers.get('content-type'), /application\/javascript/);
  const formScript = await response.text();
  const sandbox = {document: {querySelectorAll: () => [], createElement: tag => ({tag})}};
  vm.createContext(sandbox); vm.runInContext(formScript, sandbox);
  const conditional = {requiredIf: {parametersWithValue: [{id: 'state', oneOfValueIds: ['new']}], parametersWithoutValue: [{id: 'ean'}]}};
  assert.equal(sandbox.allegroPilotRequired({}, {}), false);
  assert.equal(sandbox.allegroPilotRequired({required: true}, {}), true);
  assert.equal(sandbox.allegroPilotRequired({requiredForProduct: true}, {}), true);
  assert.equal(sandbox.allegroPilotRequired(conditional, {state: ['new'], ean: ['']}), true);
  assert.equal(sandbox.allegroPilotRequired(conditional, {state: ['used']}), false);
  assert.equal(sandbox.allegroPilotRequired(conditional, {state: ['new'], ean: ['123']}), false);
  const descriptionNodes = sandbox.allegroPilotDescription('Opis żółtej figury.\n\nWażna informacja o ręcznym malowaniu\n\n<script>test</script>');
  assert.equal(descriptionNodes[1].tag, 'h2');
  assert.equal(descriptionNodes[2].textContent, '<script>test</script>');
  // Exercise actual live required attributes and description updates without a browser/network.
  const dynamicParameters = [{id: 'state', required: true}, {id: 'code'}, {id: 'ean', ...conditional}];
  const liveRows = new Map(dynamicParameters.map(p => {
    const control = {value: '', required: false}; const label = {}; const status = {};
    return [p.id, {dataset: {allegroParam: p.id}, control, label, status,
      querySelectorAll: () => [control], querySelector: selector => selector === '[data-requirement]' ? label : status}];
  }));
  const listeners = {}; const descriptionInput = {addEventListener: (event, fn) => {listeners.description = fn;}, value: ''};
  const livePreview = {replaceChildren: (...nodes) => {livePreview.nodes = nodes;}};
  const group = {dataset: {allegroParameters: JSON.stringify(dynamicParameters)}, querySelectorAll: () => [...liveRows.values()],
    addEventListener: (event, fn) => {listeners[event] = fn;}, closest: () => ({querySelector: selector => selector === '[name="description"]' ? descriptionInput : livePreview})};
  const liveSandbox = {document: {querySelectorAll: selector => selector === '[data-allegro-parameters]' ? [group] : [], createElement: tag => ({tag})}};
  vm.createContext(liveSandbox); vm.runInContext(formScript, liveSandbox);
  assert.equal(liveRows.get('state').control.required, true);
  assert.equal(liveRows.get('code').control.required, false);
  assert.equal(liveRows.get('ean').control.required, false);
  liveRows.get('state').control.value = 'new'; listeners.change();
  assert.equal(liveRows.get('ean').control.required, true);
  // The sample condition depends on ean itself being absent; filling it makes it optional.
  liveRows.get('ean').control.value = '123'; listeners.input();
  assert.equal(liveRows.get('ean').control.required, false);
  descriptionInput.value = 'Żółta figura.\n\nNowy akapit.'; listeners.description();
  assert.equal(livePreview.nodes[0].textContent, 'Żółta figura.');
  const gpsrEvents = {};
  const gpsrText = {value: '', dataset: {gpsrText: 'Informacje dotyczące bezpieczeństwa produktu\n\nNie jest zabawką.'}, focus() {}};
  const compliance = {checked: false};
  const gpsrSelect = {value: '', closest: () => ({querySelector: selector => selector === '[name="safety"]' ? gpsrText : compliance}),
    addEventListener: (event, handler) => {gpsrEvents[event] = handler;}};
  const gpsrSandbox = {document: {querySelectorAll: selector => selector === '[data-gpsr-template]' ? [gpsrSelect] : []}};
  vm.createContext(gpsrSandbox); vm.runInContext(formScript, gpsrSandbox);
  gpsrSelect.value = 'figure'; gpsrEvents.change();
  assert.match(gpsrText.value, /Nie jest zabawką/);
  assert.equal(compliance.checked, false, 'template never confirms GPSR');
  gpsrText.value = 'Moje własne ostrzeżenia.'; gpsrSelect.value = 'manual'; gpsrEvents.change();
  assert.equal(gpsrText.value, 'Moje własne ostrzeżenia.');
  gpsrText.value = ''; gpsrEvents.change(); assert.equal(gpsrText.value, '');
  for (const value of [secret, key, 'fixture-access', 'fixture-refresh', 'Authorization:']) assert(!formScript.includes(value));
  response = await post({ action: 'connect', 'csrf[]': 'invalid-array' }, cookie);
  assert.equal(response.status, 400, 'array CSRF fails without PHP coercion');
  response = await request('/admin/allegro/?environment=PROD', { headers: { cookie } });
  assert.match(await response.text(), /<strong>SANDBOX<\/strong>/, 'GET cannot select environment');
  response = await post({ action: 'connect', csrf }, cookie);
  assert.equal(response.status, 303);
  assert.equal(response.headers.get('location'), '/admin/allegro/', 'same-origin PRG, no authorization URL');
  response = await request('/admin/allegro/', { headers: { cookie } });
  const deviceHtml = await response.text();
  assert.match(deviceHtml, /abc 123 xyz/);
  assert.match(deviceHtml, /https:\/\/allegro.pl.allegrosandbox.pl\/skojarz-aplikacje\?code=abc123xyz/);
  assert.match(deviceHtml, /name="action" value="check-device"/);
  for (const text of ['DEVICE_PRIVATE_NO_HTML', secret, key, 'bridge.js', 'data-allegro-finish', 'code_verifier', 'resume=', '/auth/oauth/authorize']) assert(!deviceHtml.includes(text));
  response = await post({ action: 'check-device', csrf }, cookie);
  assert.equal(response.status, 303);
  assert.equal(await readFile(path.join(temp, 'device-requests'), 'utf8'), '+', 'early poll zero extra HTTP');
  response = await request('/admin/allegro/', { headers: { cookie } });
  assert.match(await response.text(), /Allegro czeka jeszcze na potwierdzenie konta/);
  response = await request('/fixture-login');
  const otherCookie = response.headers.get('set-cookie').split(';')[0]; const otherCsrf = (await response.json()).csrf;
  response = await request('/admin/allegro/', { headers: { cookie: otherCookie } });
  assert(!(await response.text()).includes('abc 123 xyz'), 'other session cannot see user code');
  response = await post({ action: 'check-device', csrf: otherCsrf }, otherCookie);
  assert.equal(response.status, 400, 'other session cannot poll');
  response = await post({ action: 'finish', csrf }, cookie); assert.equal(response.status, 400, 'legacy finish refused');
  for (const target of ['/admin/allegro/callback?state=FAKE_STATE&code=FAKE_CODE', '/admin/allegro/callback.php?state=FAKE_STATE&code=FAKE_CODE']) {
    response = await request(target); const body = await response.text();
    assert.equal(response.status, 400); assert.equal(response.headers.get('set-cookie'), null);
    assert(!body.includes('FAKE_STATE') && !body.includes('FAKE_CODE') && !body.includes('resume='));
  }
  await worker('seed');
  await Promise.all([worker('refresh'), worker('refresh')]);
  assert.equal(await readFile(path.join(temp, 'refresh-count'), 'utf8'), '+', 'exactly one refresh across PHP processes');
  await Promise.all([worker('write'), worker('read-atomic')]);
  assert(!(await readdir(path.join(temp, 'vault/sandbox'))).some(name => name.startsWith('.allegro-')), 'no abandoned atomic temp');
  response = await request('/admin/allegro/', { headers: { cookie } });
  const accountHtml = await response.text();
  assert.match(accountHtml, /&lt;fixture&amp;seller&gt;/, 'escaped account identity');
  assert.match(accountHtml, /123456/);
  assert.match(accountHtml, /Pierwsza figura/);
  assert.match(accountHtml, /name="product_id"/);
  assert.match(accountHtml, /Fixture figura/);
  response = await post({ action: 'connect', csrf }, cookie);
  assert.equal(response.status, 400, 'working connection requires explicit scope-upgrade confirmation');
  // Actual HTTP form navigation; the isolated server uses only synthetic API responses.
  const figureId = `prd_${'a'.repeat(32)}`;
  const rootCategory = '123e4567-e89b-12d3-a456-426614174000';
  const leafCategory = '123e4567-e89b-12d3-a456-426614174001';
  const navigationNonce = html => html.match(/name="navigation_nonce" value="([a-f0-9]+)"/)[1];
  const pilotPage = async fields => {
    const result = await post({csrf, product_id: figureId, ...fields}, cookie);
    assert.equal(result.status, 303);
    return (await request('/admin/allegro/', {headers: {cookie}})).text();
  };
  let categoryHtml = await pilotPage({action: 'pilot-load'});
  assert.match(categoryHtml, /Kategorie główne/);
  for (const category of [rootCategory, '22', leafCategory]) {
    categoryHtml = await pilotPage({action: 'pilot-load', category_id: category, navigation_nonce: navigationNonce(categoryHtml)});
  }
  assert.match(categoryHtml, /Główna → Pośrednia → Końcowa/);
  assert.match(categoryHtml, /value="68109900"/);
  assert.match(categoryHtml, /Opis figury z katalogu/);
  assert.match(categoryHtml, /Ważna informacja o ręcznym malowaniu/);
  assert.match(categoryHtml, /data-allegro-description-preview/);
  assert.match(categoryHtml, /Kod producenta — <span data-requirement>Opcjonalne/);
  assert.doesNotMatch(categoryHtml, /name="parameters\[code\]"[^>]* required/);
  assert.match(categoryHtml, /O sprzedającym/);
  assert.match(categoryHtml, /Wybierz szablon informacji bezpieczeństwa/);
  assert.match(categoryHtml, /delivery.handlingTime=P7D/);
  assert.match(categoryHtml, /Ważna informacja dotycząca odbioru przesyłki/);
  assert.match(categoryHtml, /name="receipt_info"[^>]*data-receipt-limit="650"/);
  assert.doesNotMatch(categoryHtml, /name="gpsr_confirmed"[^>]*checked/);
  // Every rendered status preserves the saved encrypted offer and tokens byte-for-byte.
  const viewVault = path.join(temp, 'vault/sandbox/account.enc');
  await request('/fixture-preview-view',{headers:{cookie}});
  for (const offerStatus of ['INACTIVE','PROCESSING','ACTIVE','UNKNOWN']) {
    await worker(`view-${offerStatus}`);
    const beforeView = createHash('sha256').update(await readFile(viewVault)).digest('hex');
    const view = await (await request('/admin/allegro/', {headers: {cookie}})).text();
    assert.match(view, new RegExp(`data-offer-status>${offerStatus}<`));
    assert.equal(createHash('sha256').update(await readFile(viewVault)).digest('hex'), beforeView, 'render must not mutate saved offer/tokens');
    if (offerStatus !== 'UNKNOWN') assert(view.includes('oferta/789'));
    if (offerStatus === 'INACTIVE') assert(view.includes('>Opublikuj ofertę<'));
    else assert(!view.includes('>Opublikuj ofertę<'));
    assert(!view.includes('>Utwórz szkic<'), 'existing record cannot expose draft creation');
  }
  await worker('view-CLEAR');
  await request('/fixture-preview-clear',{headers:{cookie}});
  if (process.env.HGO_ALLEGRO_PLAYWRIGHT_MODULE) {
    const {chromium} = await import(pathToFileURL(process.env.HGO_ALLEGRO_PLAYWRIGHT_MODULE).href);
    const browser = await chromium.launch({headless: true, executablePath: process.env.HGO_ALLEGRO_BROWSER_EXECUTABLE});
    try {
      const context = await browser.newContext({viewport: {width: 1366, height: 768}});
      const origin = `http://127.0.0.1:${port}`;
      const separator = cookie.indexOf('=');
      await context.addCookies([{name: cookie.slice(0,separator), value: cookie.slice(separator+1), url: origin}]);
      let unexpectedWrites = 0;
      await context.route('**/*', route => {
        if (!route.request().url().startsWith(origin+'/')) return route.abort();
        if (route.request().method() !== 'GET') {unexpectedWrites++; return route.abort();}
        return route.continue();
      });
      const page = await context.newPage(); const jsErrors = [];
      page.on('pageerror', error => jsErrors.push(error.message));
      // A previously saved over-limit manual note must not be silently replaced on page load.
      const oldNote = 'Ręczny tekst '.repeat(60);
      const oldNoteResponse = await post({action:'pilot-preview',csrf,product_id:figureId,receipt_info:oldNote},cookie);
      assert.equal(oldNoteResponse.status,400);
      await page.goto(origin+'/admin/allegro/');
      await page.waitForSelector('body.allegro-compact');
      assert.equal(await page.locator('[role=tabpanel]:visible').count(), 1);
      const tab = name => page.getByRole('tab', {name, exact: true}).click();
      const fitsDesktop = async () => assert(await page.evaluate(() => document.documentElement.scrollHeight <= innerHeight+2 && document.documentElement.scrollWidth <= innerWidth+2), 'desktop workspace fits viewport');
      await fitsDesktop();
      await tab('Dostawa i zwroty');
      assert.equal(await page.locator('[name=receipt_info]').inputValue(),oldNote);
      assert.match(await page.locator('[data-receipt-warning]').textContent(),/Przekroczono limit/);
      page.once('dialog',dialog=>dialog.accept()); await page.locator('[data-receipt-reset]').click();
      await tab('Opis produktu');
      await page.locator('[name=name]').fill('Figura ogrodowa testowa żółta');
      await page.locator('[name=description]').fill('Ręcznie edytowany opis — żółć.');
      await page.locator('[name=price]').fill('150'); await page.locator('[name=quantity]').fill('1');
      await page.locator('[name=stock_confirmed]').check();
      await tab('GPSR i bezpieczeństwo');
      await page.getByRole('button', {name:'Podgląd oferty',exact:true}).click();
      assert.equal(await page.getByRole('tab',{name:'Parametry Allegro',exact:true}).getAttribute('aria-selected'), 'true', 'invalid field opens the right tab');
      await page.locator('[name="parameters[state]"]').selectOption('used');
      await tab('Opis produktu');
      assert.equal(await page.locator('[name=description]').inputValue(), 'Ręcznie edytowany opis — żółć.');
      await tab('Dostawa i zwroty');
      for (const name of ['shipping','return','warranty']) await page.locator(`[name=${name}]`).selectOption('fixture-setting');
      await page.locator('[name=handling_confirmed]').check();
      const receipt = page.locator('[name=receipt_info]');
      const defaultReceipt = await receipt.inputValue();
      assert(defaultReceipt.length < 650 && !defaultReceipt.includes('**'));
      assert.equal(await page.locator('[data-receipt-count]').textContent(), `${Array.from(defaultReceipt).length} / 650`);
      await receipt.fill('Mój ręcznie zmieniony tekst.');
      page.once('dialog', dialog => dialog.dismiss());
      await page.locator('[data-receipt-reset]').click();
      assert.equal(await receipt.inputValue(),'Mój ręcznie zmieniony tekst.');
      page.once('dialog', dialog => dialog.accept());
      await page.locator('[data-receipt-reset]').click();
      assert.equal(await receipt.inputValue(),defaultReceipt);
      await receipt.fill('ą'.repeat(651));
      assert.equal(await page.locator('[data-receipt-count]').textContent(), '651 / 650');
      assert.match(await page.locator('[data-receipt-warning]').textContent(), /Przekroczono limit/);
      await tab('GPSR i bezpieczeństwo');
      await page.locator('[name=producer]').selectOption('fixture-setting');
      await page.locator('[name=safety]').fill('Rzeczywiste ostrzeżenia producenta.');
      await page.locator('[name=gpsr_confirmed]').check();
      await page.getByRole('button', {name:'Podgląd oferty',exact:true}).click();
      assert.equal(await page.getByRole('tab',{name:'Dostawa i zwroty',exact:true}).getAttribute('aria-selected'), 'true');
      assert.equal(await page.evaluate(()=>document.activeElement.name), 'receipt_info');
      page.once('dialog',dialog=>dialog.accept()); await page.locator('[data-receipt-reset]').click();
      const screenshots = path.join(root,'.local-cache/allegro-compact-ui'); await mkdir(screenshots,{recursive:true});
      await page.locator('.allegro-product').evaluate(el=>{el.scrollTop=0;});
      await page.screenshot({path:path.join(screenshots,'desktop-1366.png')});
      await page.setViewportSize({width:1920,height:1080}); await fitsDesktop();
      await tab('Parametry Allegro');
      await page.screenshot({path:path.join(screenshots,'desktop-1920.png')});
      await page.setViewportSize({width:390,height:844});
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'mobile has no horizontal page overflow');
      assert.equal(await page.locator('.allegro-grid').evaluate(el=>getComputedStyle(el).gridTemplateColumns.trim().split(' ').length),1);
      await page.screenshot({path:path.join(screenshots,'mobile-390.png'),fullPage:true});
      assert.equal(unexpectedWrites,0,'UI checks never submit a sales operation');
      assert.deepEqual(jsErrors,[]);
      console.log('PASS: Allegro browser 1366/1920/mobile, tabs, validation focus, receipt counter/reset; zero writes');
    } finally {await browser.close();}
  }
  response = await post({action: 'pilot-preview', csrf, product_id: figureId, description: 'Mój ręcznie zmieniony opis.', 'parameters[cn]': '68109100'}, cookie);
  assert.equal(response.status, 400, 'missing draft fields are rejected by PHP');
  categoryHtml = await (await request('/admin/allegro/', {headers: {cookie}})).text();
  assert.match(categoryHtml, /Mój ręcznie zmieniony opis/);
  assert.match(categoryHtml, /name="parameters\[cn\]"[^>]*value="68109100"/);
  response = await post({action: 'pilot-category-back', csrf, product_id: figureId, navigation_nonce: 'wrong'}, cookie);
  assert.equal(response.status, 400, 'wrong navigation nonce cannot go back');
  categoryHtml = await pilotPage({action: 'pilot-category-back', navigation_nonce: navigationNonce(categoryHtml)});
  assert.match(categoryHtml, /Wybrana ścieżka: Główna → Pośrednia/);
  assert.doesNotMatch(categoryHtml, /name="parameters\[code\]"/);
  assert.match(categoryHtml, new RegExp(`value="${leafCategory}"`));
  categoryHtml = await pilotPage({action: 'pilot-category-back', navigation_nonce: navigationNonce(categoryHtml)});
  assert.match(categoryHtml, /Wybrana ścieżka: Główna<\/p>/);
  categoryHtml = await pilotPage({action: 'pilot-category-restart', navigation_nonce: navigationNonce(categoryHtml)});
  assert.match(categoryHtml, /Kategorie główne/);
  assert.match(categoryHtml, new RegExp(`value="${rootCategory}"`));
  for (const action of ['pilot-create', 'pilot-publish']) {
    response = await post({ action, csrf, product_id: `prd_${'a'.repeat(32)}`, confirm: 'PUBLISH' }, cookie);
    assert.equal(response.status, 400, 'no remote write without server preview and nonce');
  }

  for (const value of [secret, key, 'fixture-access', 'fixture-refresh', 'fixture-rotated', 'fixture-code-NO-HTML']) assert(!accountHtml.includes(value));
  // Real ordinary product save on an isolated copy. Network is disabled in this server.
  const vaultPath = path.join(temp, 'vault/sandbox/account.enc');
  const logsPath = path.join(temp, 'vault/sandbox/technical.jsonl');
  const hash = async file => createHash('sha256').update(await readFile(file)).digest('hex');
  const beforeVault = await hash(vaultPath); const beforeLog = await hash(logsPath);
  response = await request('/admin/', { method: 'POST', headers: { cookie }, body: new URLSearchParams({
    action: 'save_product', csrf, index: '0', name: 'Fixture figura zmieniona', saleType: 'garden_figure',
    category: 'Figury i dekoracje ogrodowe', grossPrice: '125 zł', slug: 'fixture-figura',
  }) });
  assert.equal(response.status, 302); assert.match(response.headers.get('location'), /edit=0/);
  const saved = JSON.parse(await readFile(path.join(site, 'data/products.json'), 'utf8'));
  assert.equal(saved.products[0].grossPrice, '125 zł', 'product really saved');
  assert.equal(saved.products[0].productId, `prd_${'a'.repeat(32)}`, 'ID preserved');
  const includes = JSON.parse(await readFile(path.join(temp, 'product-includes.json'), 'utf8'));
  assert(!includes.some(file => file.replaceAll('\\', '/').includes('/admin/allegro/')), 'product save never loads Allegro transport');
  assert.equal(await hash(vaultPath), beforeVault); assert.equal(await hash(logsPath), beforeLog);
  response = await request('/admin/allegro/?action=disconnect', { headers: { cookie } });
  assert.match(await response.text(), /&lt;fixture&amp;seller&gt;/, 'GET cannot disconnect');
  response = await post({ action: 'disconnect', csrf, confirm_disconnect: 'yes' }, cookie);
  assert.equal(response.status, 303);
  response = await request('/admin/allegro/', { headers: { cookie } });
  const disconnected = await response.text(); assert.match(disconnected, /Nie połączono/); assert(!disconnected.includes('fixture&amp;seller'));
  await writeFile(path.join(temp, 'config.php'), configText.replace("'ALLEGRO_OAUTH_ENABLED' => '1'", "'ALLEGRO_OAUTH_ENABLED' => '0'"));
  response = await request('/admin/allegro/', { headers: { cookie } });
  const blocked = await response.text(); assert(!blocked.includes('>Połącz z Allegro<'));
  response = await post({ action: 'connect', csrf }, cookie); assert.equal(response.status, 400);
  for (const target of ['/private_allegro/config.php', '/config.php', '/admin/allegro/storage/account.enc']) {
    response = await request(target);
    // PHP's development server may fall back to an ancestor's protected index.
    assert([404, 302].includes(response.status), 'private config/vault never served');
    assert(!(await response.text()).includes(secret));
  }
  const logs = await readFile(logsPath, 'utf8');
  for (const value of [secret, key, 'DEVICE_PRIVATE_NO_HTML', 'abc123xyz', 'fixture-access', 'fixture-refresh', 'fixture-rotated', 'fixture-code-NO-HTML']) assert(!logs.includes(value));
  console.log('PASS: Allegro HTTP login/CSRF/Device POST/HTML, concurrent refresh, atomic reads, isolated product save');
} finally {
  if (server) {
    server.kill();
    await new Promise(resolve => { if (server.exitCode !== null) resolve(); else server.once('exit', resolve); });
  }
  // Verified fixed mkdtemp target, owned by this test, outside the repository.
  await rm(temp, { recursive: true, force: true });
}
