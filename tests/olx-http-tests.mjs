import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, mkdir, writeFile, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';
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
await writeFile(path.join(temp, 'router.php'), `<?php
 $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
 if ($path === '/fixture-login') {
   require ${phpString(path.join(root, 'hosting/getspace/admin/lib.php'))};
   boot_admin(); $_SESSION['logged_in'] = true; $_SESSION['username'] = 'fixture';
   header('Content-Type: application/json'); echo json_encode(['csrf'=>csrf_token()]); return;
 }
 if ($path === '/admin/olx/callback') { require ${phpString(path.join(root, 'hosting/getspace/admin/olx/callback.php'))}; return; }
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
  response = await request('/admin/olx/', { headers: { cookie } });
  assert.match(await response.text(), /Niepołączone/);
  response = await post({ action: 'connect', csrf: 'wrong' }, cookie);
  assert.equal(response.headers.get('location'), null, 'bad CSRF cannot redirect to OLX');
  response = await post({ action: 'connect', csrf }, cookie);
  assert.equal(response.status, 303);
  const authorize = new URL(response.headers.get('location'));
  assert.equal(authorize.origin, 'https://www.olx.pl');
  const state = authorize.searchParams.get('state');
  const callback = `/admin/olx/callback?state=${state}&code=fixture-code-NEVER-REAL`;
  response = await request(callback); // deliberately no admin cookie
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('set-cookie'), null, 'callback does not replace missing Strict cookie');
  assert.equal(response.headers.get('referrer-policy'), 'no-referrer');
  const html = await response.text();
  assert(!html.includes('fixture-code-NEVER-REAL'), 'authorization code never returned to browser');
  const resume = html.match(/\?resume=([a-f0-9]{64})/)[1];
  response = await request(callback);
  assert.equal(response.status, 400, 'callback replay rejected');
  response = await request(`/admin/olx/?resume=${resume}`, { headers: { cookie } });
  assert.match(await response.text(), /Dokończ połączenie OLX/);
  // Use another authenticated session: it must never exchange the fixture code.
  response = await request('/fixture-login');
  const otherCookie = response.headers.get('set-cookie').split(';')[0];
  const otherCsrf = (await response.json()).csrf;
  response = await post({ action: 'finish', csrf: otherCsrf, resume }, otherCookie);
  assert.equal(response.headers.get('location'), null, 'other session rejected before exchange');
  assert.match(await response.text(), /rozpocznij połączenie OLX ponownie/);
  response = await post({ action: 'disconnect', csrf, confirm_disconnect: 'yes' }, cookie);
  assert.equal(response.status, 303);
  response = await request(`/admin/olx/?resume=${resume}`, { headers: { cookie } });
  assert.match(await response.text(), /Niepołączone/);
  await worker('seed');
  await Promise.all([worker('refresh'), worker('refresh')]);
  assert.equal(await readFile(path.join(temp, 'refresh-count'), 'utf8'), '+', 'parallel callers perform one refresh');
  console.log('PASS: OLX HTTP login, Strict cookie, CSRF, cookie-free callback, replay, session binding, disconnect, concurrent refresh');
} finally {
  server.kill();
  await new Promise(resolve => { if (server.exitCode !== null) resolve(); else server.once('exit', resolve); });
  await rm(temp, { recursive: true, force: true });
}
