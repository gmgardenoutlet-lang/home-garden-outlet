import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, readFile, cp, rm, access } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { publicAdminSource, assertPrivateAllegroAbsent } from '../scripts/getspace-artifact-privacy.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const temp = await mkdtemp(path.join(tmpdir(), 'hgo-allegro-artifact-'));
try {
  const source = path.join(temp, 'source/admin'); const artifact = path.join(temp, 'artifact');
  await mkdir(path.join(source, 'allegro/private_allegro'), { recursive: true });
  for (const file of ['config.php', 'index.php', 'storage.php', '.htaccess']) await writeFile(path.join(source, 'allegro', file), 'SAFE_MODULE');
  for (const file of ['account.enc', 'config.private.php', 'technical.jsonl', '.env', 'unreviewed.php', 'private_allegro/config.php']) {
    await writeFile(path.join(source, 'allegro', file), 'PRIVATE_SENTINEL_NEVER_PUBLIC');
  }
  await cp(source, path.join(artifact, 'admin'), { recursive: true, filter: publicAdminSource });
  await assertPrivateAllegroAbsent(artifact);
  assert.equal(await readFile(path.join(artifact, 'admin/allegro/config.php'), 'utf8'), 'SAFE_MODULE');
  await assert.rejects(access(path.join(artifact, 'admin/allegro/config.private.php')));
  await assert.rejects(access(path.join(artifact, 'admin/allegro/private_allegro/config.php')));
  await writeFile(path.join(artifact, 'admin/allegro/account.enc'), 'PRIVATE_SENTINEL_NEVER_PUBLIC');
  await assert.rejects(assertPrivateAllegroAbsent(artifact));
  const loader = await readFile(path.join(root, 'hosting/getspace/admin/allegro/config.php'), 'utf8');
  assert(!loader.includes('PRIVATE_SENTINEL_NEVER_PUBLIC'));
  const htaccess = await readFile(path.join(root, 'hosting/getspace/admin/.htaccess'), 'utf8');
  assert(htaccess.includes('^allegro/callback/?$ allegro/callback.php [END]'));
  const ownHtaccess = await readFile(path.join(root, 'hosting/getspace/admin/allegro/.htaccess'), 'utf8');
  assert(ownHtaccess.includes('Require all denied') && ownHtaccess.includes('callback\\.php'));
  assert(ownHtaccess.includes("form-action 'self' https://allegro.pl https://allegro.pl.allegrosandbox.pl"));
  assert(ownHtaccess.includes("default-src 'none'"));
  const ignored = execFileSync('git', ['check-ignore', '--stdin'], { cwd: root, input: [
    'private_allegro/config.php', 'hosting/getspace/private_allegro/storage/account.enc',
    'hosting/getspace/admin/allegro/storage/account.enc', 'hosting/getspace/admin/allegro/logs/technical.jsonl',
    'hosting/getspace/admin/allegro/cache/state.json', 'hosting/getspace/admin/allegro/config.private.php',
  ].join('\n') + '\n', encoding: 'utf8' }).trim().split(/\r?\n/);
  assert.equal(ignored.length, 6);
  const tracked = execFileSync('git', ['ls-files'], { cwd: root, encoding: 'utf8' });
  assert(!tracked.split(/\r?\n/).some(file => /(^|\/)private_allegro\//i.test(file)), 'no tracked private Allegro directory');
  const workflow = await readFile(path.join(root, '.github/workflows/deploy-getspace.yml'), 'utf8');
  assert(workflow.includes('**/private_allegro/**') && workflow.includes('admin/allegro/storage/**'));
  const supplied = process.argv.find(arg => arg.startsWith('--artifact='));
  if (supplied) {
    const artifact = path.resolve(root, supplied.slice('--artifact='.length));
    await assertPrivateAllegroAbsent(artifact);
    const config = await readFile(path.join(artifact, 'admin/allegro/config.php'));
    assert.equal(createHash('sha256').update(config).digest('hex'),
      'd1042b53f70713be38edde0158ee656c619a624a2034cbea26f44f138e00bfd9', 'immutable reviewed OFF payload');
  }
  console.log('PASS: Allegro source allowlist, private sentinels excluded, Git/FTPS/routing protections');
} finally {
  // This exact mkdtemp directory is created and owned by this test.
  await rm(temp, { recursive: true, force: true });
}
