import { spawnSync } from 'node:child_process';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import path from 'node:path';

// No migrations, private config writes, OAuth calls or advert operations.
const env = process.env;
const root = (env.FTP_REMOTE_DIR || '').replace(/\/$/, '');
if (!env.RUNNER_TEMP || !/(^|\/)public_html$/.test(root)) throw Error('Invalid deployment environment');
const local = path.join(env.RUNNER_TEMP, 'olx-oauth-production-backup');
const reuse = env.OLX_REUSE_BACKUP || '';
if (reuse && !/^olx-oauth-\d+-\d+$/.test(reuse)) throw Error('Invalid backup name');
const backup = path.posix.join(path.posix.dirname(root), 'private_backups', reuse || `olx-oauth-${env.GITHUB_RUN_ID}-${env.GITHUB_RUN_ATTEMPT}`);
const quote = value => '"' + value.replaceAll('\\', '\\\\').replaceAll('"', '\\"') + '"';
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
await mkdir(local, { recursive: true, mode: 0o700 });
function ftp(commands) {
  const result = spawnSync('lftp', ['-u', `${env.FTP_USERNAME},${env.FTP_PASSWORD}`, `ftp://${env.FTP_SERVER}:21`], {
    input: ['set cmd:fail-exit yes', 'set ftp:ssl-force true', 'set ftp:ssl-protect-data true',
      'set ssl:verify-certificate true', 'set net:max-retries 2', 'set net:timeout 30', 'set xfer:clobber yes', ...commands, 'bye'].join('\n'),
    encoding: 'utf8', maxBuffer: 32 * 1024 * 1024,
  });
  if (result.status !== 0) throw Error('FTPS operation failed; no protocol or credential details are logged');
  return result.stdout;
}
const stage = env.OLX_DEPLOY_STAGE || 'oauth';
if (!['oauth', 'preparation', 'publication'].includes(stage)) throw Error('Unknown OLX deployment scope');
const files = stage === 'publication'
  ? ['admin/olx/prepare-lib.php','admin/olx/publish-lib.php','admin/olx/publish.php','admin/olx/.htaccess','admin/olx/prepare.php','admin/olx/index.php','admin/index.php']
  : stage === 'preparation'
  ? ['admin/olx/prepare-lib.php', 'admin/olx/.htaccess', 'admin/olx/prepare.php', 'admin/olx/index.php', 'admin/index.php']
  : ['admin/olx/lib.php', 'admin/olx/callback.php', 'admin/olx/return.php', 'admin/olx/index.php'];
const patchBackup = backup + `/patch-${env.GITHUB_RUN_ID}-${env.GITHUB_RUN_ATTEMPT}`;
if (!reuse) {
  ftp([`mirror --parallel=4 ${quote(root)} ${quote(local)}`,
    `mirror --reverse --parallel=4 ${quote(local)} ${quote(backup)}`]);
} else {
  await mkdir(path.join(local, 'data'), { recursive: true, mode: 0o700 });
  ftp([`get ${quote(backup + '/data/products.json')} -o ${quote(path.join(local, 'data/products.json'))}`,
    `get ${quote(root + '/data/products.json')} -o ${quote(path.join(local, 'data/products.current.json'))}`]);
  if (sha(await readFile(path.join(local, 'data/products.json'))) !== sha(await readFile(path.join(local, 'data/products.current.json'))))
    throw Error('Existing full backup catalogue differs from production; full new backup required');
}
// Preserve every existing file from the current production revision separately.
// New code is staged outside webroot, never as downloadable PHP source.
const originals = new Set();
ftp([`mkdir -p ${quote(patchBackup + '/stage/admin/olx')}`, `mkdir -p ${quote(patchBackup + '/original/admin/olx')}`]);
const listings = new Map();
for (const file of files) {
  const dir = path.posix.dirname(file);
  if (!listings.has(dir)) listings.set(dir, ftp([`cls -1 -a ${quote(root + '/' + dir)}`]));
  const exists = listings.get(dir).split(/\r?\n/).some(line => path.posix.basename(line.trim().replace(/\/$/, '')) === path.posix.basename(file));
  if (!exists) {
    if (!['admin/olx/prepare.php','admin/olx/prepare-lib.php','admin/olx/return.php','admin/olx/publish.php','admin/olx/publish-lib.php'].includes(file)) throw Error('Missing expected production code');
    continue;
  }
  originals.add(file);
  const saved = path.join(local, file); await mkdir(path.dirname(saved), { recursive: true, mode: 0o700 });
  ftp([`get ${quote(root + '/' + file)} -o ${quote(saved)}`,
    `put ${quote(saved)} -o ${quote(patchBackup + '/original/' + file)}`,
    `get ${quote(patchBackup + '/original/' + file)} -o ${quote(saved + '.verified')}`]);
  if (sha(await readFile(saved)) !== sha(await readFile(saved + '.verified'))) throw Error('Code rollback backup verification failed');
}
const before = await readFile(path.join(local, 'data/products.json'));
const products = JSON.parse(before).products;
if (!Array.isArray(products) || products.length === 0) throw Error('Invalid backup catalogue');
await mkdir(path.join(local, 'verification'), { recursive: true });
const verified = path.join(local, 'verification/products.json');
ftp([`get ${quote(backup + '/data/products.json')} -o ${quote(verified)}`]);
if (sha(before) !== sha(await readFile(verified))) throw Error('Backup verification failed');
let deployed = false;
try {
  ftp(files.map(file => `put ${quote('hosting/getspace/' + file)} -o ${quote(patchBackup + '/stage/' + file)}`));
  // Recheck current code immediately before swapping, preventing stale overwrite.
  for (const file of originals) {
    const check = path.join(local, file + '.pre-swap');
    ftp([`get ${quote(root + '/' + file)} -o ${quote(check)}`]);
    if (sha(await readFile(check)) !== sha(await readFile(path.join(local, file)))) throw Error('Production code changed during preparation');
  }
  deployed = true;
  ftp(files.map(file => `mv ${quote(patchBackup + '/stage/' + file)} ${quote(root + '/' + file)}`));
  for (const file of files) {
    const target = path.join(local, 'verification', path.basename(file));
    ftp([`get ${quote(root + '/' + file)} -o ${quote(target)}`]);
    if (sha(await readFile(target)) !== sha(await readFile('hosting/getspace/' + file))) throw Error('Code verification failed');
  }
  ftp([`get ${quote(root + '/data/products.json')} -o ${quote(verified)}`]);
  if (sha(before) !== sha(await readFile(verified))) throw Error('Catalogue changed concurrently; no catalogue writes performed by this job');
} catch (error) {
  if (deployed) {
    for (const file of files) {
      if (originals.has(file)) ftp([
        `put ${quote(path.join(local, file))} -o ${quote(patchBackup + '/stage/' + file)}`,
        `mv ${quote(patchBackup + '/stage/' + file)} ${quote(root + '/' + file)}`,
      ]);
      else {
        const check = path.join(local, 'verification', path.basename(file) + '.rollback-check');
        ftp([`get ${quote(root + '/' + file)} -o ${quote(check)}`]);
        if (sha(await readFile(check)) === sha(await readFile('hosting/getspace/' + file))) ftp([`rm ${quote(root + '/' + file)}`]);
      }
    }
  }
  throw error;
}
const report = { backup, patch_backup: patchBackup, stage, products: products.length, products_sha256_before: sha(before), products_sha256_after: sha(await readFile(verified)),
  products_modified: false, changed_files: files, oauth_started: false, adverts_modified: false };
console.log(JSON.stringify(report, null, 2));
await writeFile(env.GITHUB_STEP_SUMMARY, '```json\n' + JSON.stringify(report, null, 2) + '\n```\n', { flag: 'a' });
