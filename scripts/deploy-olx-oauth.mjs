import { spawnSync } from 'node:child_process';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import path from 'node:path';

// No migrations, private config writes, OAuth calls or advert operations.
const env = process.env;
const root = (env.FTP_REMOTE_DIR || '').replace(/\/$/, '');
if (!env.RUNNER_TEMP || !/(^|\/)public_html$/.test(root)) throw Error('Invalid deployment environment');
const local = path.join(env.RUNNER_TEMP, 'olx-oauth-production-backup');
const backup = path.posix.join(path.posix.dirname(root), 'private_backups', `olx-oauth-${env.GITHUB_RUN_ID}-${env.GITHUB_RUN_ATTEMPT}`);
const quote = value => '"' + value.replaceAll('\\', '\\\\').replaceAll('"', '\\"') + '"';
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
await mkdir(local, { recursive: true, mode: 0o700 });
function ftp(commands) {
  const result = spawnSync('lftp', ['-u', `${env.FTP_USERNAME},${env.FTP_PASSWORD}`, `ftp://${env.FTP_SERVER}:21`], {
    input: ['set cmd:fail-exit yes', 'set ftp:ssl-force true', 'set ftp:ssl-protect-data true',
      'set ssl:verify-certificate true', 'set net:max-retries 2', 'set net:timeout 30', ...commands, 'bye'].join('\n'),
    encoding: 'utf8', maxBuffer: 32 * 1024 * 1024,
  });
  if (result.status !== 0) throw Error('FTPS operation failed; no protocol or credential details are logged');
}
ftp([`mirror --parallel=4 ${quote(root)} ${quote(local)}`,
  `mirror --reverse --parallel=4 ${quote(local)} ${quote(backup)}`]);
const before = await readFile(path.join(local, 'data/products.json'));
const products = JSON.parse(before).products;
if (!Array.isArray(products) || products.length === 0) throw Error('Invalid backup catalogue');
await mkdir(path.join(local, 'verification'), { recursive: true });
const verified = path.join(local, 'verification/products.json');
ftp([`get ${quote(backup + '/data/products.json')} -o ${quote(verified)}`]);
if (sha(before) !== sha(await readFile(verified))) throw Error('Backup verification failed');
const files = ['lib.php', 'callback.php', 'index.php', 'return.php'];
let deployed = false;
try {
  // Stage every file before exposing the new callback. Panel is replaced last.
  ftp(files.map(file => `put ${quote('hosting/getspace/admin/olx/' + file)} -o ${quote(root + '/admin/olx/' + file + '.oauth-stage')}`));
  deployed = true;
  ftp(files.map(file => `mv ${quote(root + '/admin/olx/' + file + '.oauth-stage')} ${quote(root + '/admin/olx/' + file)}`));
  for (const file of files) {
    const target = path.join(local, 'verification', file);
    ftp([`get ${quote(root + '/admin/olx/' + file)} -o ${quote(target)}`]);
    if (sha(await readFile(target)) !== sha(await readFile('hosting/getspace/admin/olx/' + file))) throw Error('Code verification failed');
  }
  ftp([`get ${quote(root + '/data/products.json')} -o ${quote(verified)}`]);
  if (sha(before) !== sha(await readFile(verified))) throw Error('Catalogue changed concurrently; no catalogue writes performed by this job');
} catch (error) {
  if (deployed) {
    // Restore only prior OLX PHP, never products or other production files.
    ftp(['lib.php', 'callback.php', 'index.php'].flatMap(file => [
      `put ${quote(path.join(local, 'admin/olx', file))} -o ${quote(root + '/admin/olx/' + file + '.rollback-stage')}`,
      `mv ${quote(root + '/admin/olx/' + file + '.rollback-stage')} ${quote(root + '/admin/olx/' + file)}`,
    ]));
  }
  throw error;
}
const report = { backup, products: products.length, products_sha256_before: sha(before), products_sha256_after: sha(await readFile(verified)),
  products_modified: false, changed_files: files.map(f => 'admin/olx/' + f), oauth_started: false, adverts_modified: false };
console.log(JSON.stringify(report, null, 2));
await writeFile(env.GITHUB_STEP_SUMMARY, '```json\n' + JSON.stringify(report, null, 2) + '\n```\n', { flag: 'a' });
