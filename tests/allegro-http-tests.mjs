import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, mkdir, writeFile, readFile, rm, cp, readdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
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
    category: 'Figury i dekoracje ogrodowe', image: '/uploads/fixture.webp', gallery: [], grossPrice: '100 zł',
  }] }));
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
  await writeFile(path.join(temp, 'router.php'), `<?php
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
          'tokens'=>['access'=>'fixture-access-NO-HTML', 'refresh'=>'fixture-refresh-NO-HTML', 'expires_at'=>time()-1, 'scope'=>ALLEGRO_B_SCOPE]]);
      });
    } elseif ($mode === 'refresh') {
      $token = allegro_access_token($config, time(), function ($request) {
        file_put_contents(${phpString(path.join(temp, 'refresh-count'))}, '+', FILE_APPEND | LOCK_EX);
        usleep(250000);
        return ['status'=>200, 'body'=>json_encode(['access_token'=>'fixture-rotated-NO-HTML', 'refresh_token'=>'fixture-rotated-refresh-NO-HTML',
          'token_type'=>'bearer', 'expires_in'=>3600, 'scope'=>ALLEGRO_B_SCOPE])];
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
  const request = (url, options = {}) => fetch(`http://127.0.0.1:${port}${url}`, { redirect: 'manual', ...options });
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
  for (const action of ['connect', 'check-device', 'finish', 'check', 'disconnect']) {
    response = await post({ action, csrf: 'wrong', confirm_disconnect: 'yes' }, cookie);
    assert.equal(response.status, 400, `${action}: CSRF rejected`);
    assert.equal(response.headers.get('location'), null);
  }
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
