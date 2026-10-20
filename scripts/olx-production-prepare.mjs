import { spawnSync } from 'node:child_process';
import { readFile, writeFile, mkdir, unlink } from 'node:fs/promises';
import path from 'node:path';
import { createHash, randomBytes } from 'node:crypto';
import { identitySummary, verifyIdentityOnly } from './olx-identity-verification.mjs';

// Executed only inside the existing Getspace deployment job. No OAuth/advert requests.
const env = process.env;
const work = path.join(env.RUNNER_TEMP || '', 'olx-stage-b');
const publicDir = (env.FTP_REMOTE_DIR || '').replace(/\/$/, '');
if (!env.RUNNER_TEMP || !/^\/?(?:[^\r\n]*\/)?public_html$/.test(publicDir)) throw Error('Invalid private runner/webroot configuration');
const parent = path.posix.dirname(publicDir);
const privateDir = path.posix.join(parent, 'private_olx');
const q = value => '"' + String(value).replaceAll('\\', '\\\\').replaceAll('"', '\\"') + '"';
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
const saved = name => path.join(work, name);
await mkdir(work, { recursive: true, mode: 0o700 });

function ftp(commands) {
  for (const name of ['FTP_SERVER', 'FTP_USERNAME', 'FTP_PASSWORD']) if (!env[name] || /[\r\n]/.test(env[name])) throw Error('Missing FTPS configuration');
  const program = ['set cmd:fail-exit yes', 'set ftp:ssl-force true', 'set ftp:ssl-protect-data true',
    'set ssl:verify-certificate true', 'set net:max-retries 2', 'set net:timeout 30', ...commands, 'bye'].join('\n');
  const result = spawnSync('lftp', ['-u', `${env.FTP_USERNAME},${env.FTP_PASSWORD}`, `ftp://${env.FTP_SERVER}:21`],
    { input: program, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
  // lftp diagnostics contain protocol errors/paths, never transferred file bodies.
  // Redact connection credentials before reporting a failure; do not dump stdout.
  if (result.status !== 0) {
    let diagnostic = String(result.stderr || result.error?.code || 'No transport diagnostic');
    for (const name of ['FTP_PASSWORD', 'FTP_USERNAME', 'FTP_SERVER']) diagnostic = diagnostic.replaceAll(env[name], '[REDACTED]');
    diagnostic = diagnostic.replace(/ftp:\/\/[^\s]+/g, 'ftp://[REDACTED]');
    throw Error(`FTPS operation failed (${result.status}): ${diagnostic.slice(0, 2000)}`);
  }
  return result.stdout;
}
async function download(remote, local) {
  await mkdir(path.dirname(local), { recursive: true, mode: 0o700 });
  ftp([`get ${q(remote)} -o ${q(local)}`]); return readFile(local);
}
function uploadAtomic(local, remote, staging) {
  ftp([`put ${q(local)} -o ${q(staging)}`, `mv ${q(staging)} ${q(remote)}`]);
}
async function logSummary(report) {
  const text = JSON.stringify(report, null, 2);
  console.log(text);
  if (env.GITHUB_STEP_SUMMARY) await writeFile(env.GITHUB_STEP_SUMMARY, `\n### OLX stage B\n\n\`\`\`json\n${text}\n\`\`\`\n`, { flag: 'a' });
}
async function restoreMaintenance() {
  let marker;
  try { marker = JSON.parse(await readFile(saved('maintenance-active.json'), 'utf8')); }
  catch (error) { if (error.code === 'ENOENT') return; throw error; }
  const current = await download(`${publicDir}/.htaccess`, saved('htaccess-current'));
  if (sha(current) !== marker.maintenanceHash && sha(current) !== marker.originalHash) throw Error('Unexpected .htaccess change: manual recovery required');
  uploadAtomic(saved('htaccess-original'), `${publicDir}/.htaccess`, `${publicDir}/.htaccess.olx-restore`);
  const restored = await download(`${publicDir}/.htaccess`, saved('htaccess-restored'));
  if (sha(restored) !== marker.originalHash) throw Error('Maintenance restoration failed');
  await unlink(saved('maintenance-active.json'));
  console.log('Admin write maintenance removed; original server configuration restored.');
}
const mode = process.argv[2];
if (mode === 'preflight') {
  console.log('FTPS account root (names only):\n' + ftp(['pwd', 'cls -1 -a .']));
  const bytes = await download(`${publicDir}/data/products.json`, saved('products-before.json'));
  const report = { phase: 'FINAL_DRY_RUN', ...identitySummary(JSON.parse(bytes)), sha256_before: sha(bytes) };
  await writeFile(saved('preflight.json'), JSON.stringify(report), { mode: 0o600 });
  await logSummary(report);
  if (report.invalid || report.duplicates || report.products === 0) throw Error('Production catalogue requires manual review');
} else if (mode === 'restore') {
  await restoreMaintenance();
} else if (mode === 'apply') {
  if (!env.OLX_BACKUP_DIR || !env.OLX_BACKUP_LOCAL) throw Error('Verified full backup is required');
  const beforeBytes = await readFile(saved('products-before.json'));
  const backupBytes = await readFile(path.join(env.OLX_BACKUP_LOCAL, 'data/products.json'));
  if (sha(beforeBytes) !== sha(backupBytes)) throw Error('Catalogue changed between dry-run and full backup; rerun safely');
  if (identitySummary(JSON.parse(beforeBytes)).missing > 0) {
  const original = await download(`${publicDir}/.htaccess`, saved('htaccess-original'));
  const backedHtaccess = await readFile(path.join(env.OLX_BACKUP_LOCAL, '.htaccess'));
  if (sha(original) !== sha(backedHtaccess)) throw Error('Server configuration changed during backup');
  // Only admin mutations can write products in the audited application.
  // Checkout/payment endpoints remain available. No logging directive is changed.
  const maintenance = Buffer.concat([Buffer.from('<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteCond %{REQUEST_METHOD} !^(GET|HEAD|OPTIONS)$\nRewriteRule ^admin(?:/|$) - [R=503,L]\n</IfModule>\n'), original]);
  await writeFile(saved('htaccess-maintenance'), maintenance);
  await writeFile(saved('maintenance-active.json'), JSON.stringify({ originalHash: sha(original), maintenanceHash: sha(maintenance) }), { mode: 0o600 });
  try {
    uploadAtomic(saved('htaccess-maintenance'), `${publicDir}/.htaccess`, `${publicDir}/.htaccess.olx-maintenance`);
    const response = await fetch('https://mgoutlet.pl/admin/', { method: 'POST', body: new URLSearchParams({ action: 'olx_maintenance_probe' }), redirect: 'manual' });
    if (response.status !== 503) throw Error('Admin write maintenance was not confirmed');
    if ((await fetch('https://mgoutlet.pl/')).status !== 200) throw Error('Public site unavailable during preparation');
    // Drain requests admitted before the write barrier. No new admin mutation is admitted.
    console.log('Admin writes blocked; waiting for existing requests to finish. Public catalogue stays available.');
    await new Promise(resolve => setTimeout(resolve, 120000));
    const migratedPath = saved('migration-web/data/products.json');
    const fresh = await download(`${publicDir}/data/products.json`, migratedPath);
    if (sha(fresh) !== sha(beforeBytes)) throw Error('Concurrent product edit detected; no migration uploaded');
    const localBackup = path.join(env.RUNNER_TEMP, 'olx-product-id-backup');
    await mkdir(localBackup, { mode: 0o700, recursive: true });
    const result = spawnSync('php', ['scripts/migrate-product-ids.php', `--products=${migratedPath}`, '--apply',
      '--maintenance-confirmed', `--expect-sha256=${sha(fresh)}`, `--backup-dir=${localBackup}`], { encoding: 'utf8' });
    if (result.status !== 0) throw Error('Identity migration preparation failed; no catalogue uploaded');
    const afterBytes = await readFile(migratedPath);
    const after = JSON.parse(afterBytes);
    const summary = verifyIdentityOnly(JSON.parse(beforeBytes), after);
    ftp([`mkdir -p ${q(env.OLX_BACKUP_DIR + '/olx-product-ids')}`,
      `put ${q(saved('products-before.json'))} -o ${q(env.OLX_BACKUP_DIR + '/olx-product-ids/products.before.json')}`,
      `put ${q(migratedPath)} -o ${q(env.OLX_BACKUP_DIR + '/olx-product-ids/products.after.json')}`]);
    const check = await download(`${publicDir}/data/products.json`, saved('products-last-check.json'));
    if (sha(check) !== sha(beforeBytes)) throw Error('Catalogue changed before atomic migration upload');
    if (sha(afterBytes) !== sha(beforeBytes)) {
      // .backup is denied over HTTP by the existing FilesMatch; no private JSON staging endpoint.
      uploadAtomic(migratedPath, `${publicDir}/data/products.json`, `${publicDir}/data/products.olx-stage-b.backup`);
      ftp([`chmod 644 ${q(publicDir + '/data/products.json')}`]);
    }
    const stored = await download(`${publicDir}/data/products.json`, saved('products-verified.json'));
    if (sha(stored) !== sha(afterBytes)) throw Error('Remote migration verification failed; keep private backup for recovery');
    verifyIdentityOnly(JSON.parse(beforeBytes), JSON.parse(stored));
    await logSummary({ phase: 'MIGRATION_VERIFIED', ...summary, sha256_before: sha(beforeBytes), sha256_after: sha(stored),
      non_identity_data: 'UNCHANGED', backup: env.OLX_BACKUP_DIR });
  } finally { await restoreMaintenance(); }
  } else {
    const current = await download(`${publicDir}/data/products.json`, saved('products-verified.json'));
    if (sha(current) !== sha(beforeBytes)) throw Error('Catalogue changed after backup; rerun safely');
    const summary = verifyIdentityOnly(JSON.parse(beforeBytes), JSON.parse(current));
    await logSummary({ phase: 'MIGRATION_VERIFIED', ...summary, sha256_before: sha(beforeBytes), sha256_after: sha(current),
      non_identity_data: 'UNCHANGED', migration: 'NOT_NEEDED', backup: env.OLX_BACKUP_DIR });
  }
  // Never overwrite an existing secret/key. The new configuration has an EMPTY client secret.
  ftp([`mkdir -p ${q(privateDir + '/storage')}`, `chmod 700 ${q(privateDir)}`, `chmod 700 ${q(privateDir + '/storage')}`]);
  const listing = ftp([`cls -1 -a ${q(privateDir + '/')}`]);
  const exists = listing.split(/\r?\n/).some(line => path.posix.basename(line.trim()) === 'config.php');
  if (!exists) {
    const config = "<?php\ndeclare(strict_types=1);\nreturn [\n    'OLX_CLIENT_ID' => '203399',\n    'OLX_CLIENT_SECRET' => '',\n    'OLX_REDIRECT_URI' => 'https://mgoutlet.pl/admin/olx/callback',\n    'OLX_TOKEN_KEY' => '" + randomBytes(32).toString('base64') + "',\n    'OLX_STORAGE_DIR' => __DIR__ . '/storage',\n    'OLX_CALLBACK_LOGS_REDACTED' => '0',\n];\n";
    await writeFile(saved('private-config.php'), config, { mode: 0o600 });
    ftp([`put ${q(saved('private-config.php'))} -o ${q(privateDir + '/config.php')}`, `chmod 600 ${q(privateDir + '/config.php')}`]);
    const downloaded = await download(privateDir + '/config.php', saved('private-config-verified.php'));
    if (sha(downloaded) !== sha(Buffer.from(config))) throw Error('Private configuration verification failed');
    await unlink(saved('private-config.php')); await unlink(saved('private-config-verified.php'));
  } else {
    ftp([`chmod 600 ${q(privateDir + '/config.php')}`]);
  }
  const permissions = ftp([`cls -ld ${q(privateDir)}`, `cls -ld ${q(privateDir + '/storage')}`, `cls -l ${q(privateDir + '/config.php')}`]);
  if ((permissions.match(/drwx------/g) || []).length < 2 || !permissions.includes('-rw-------')) throw Error('Private directory/file permissions could not be verified');
  await logSummary({ phase: 'PRIVATE_STORAGE_READY', directory: '/home/ogfdvopi/domains/mgoutlet.pl/private_olx',
    directory_permissions: '700', config_permissions: '600', existing_config_preserved: exists, oauth_started: false });
} else { throw Error('Unknown preparation mode'); }
