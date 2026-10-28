import { createHash } from 'node:crypto';
import path from 'node:path';
import { readdir, readFile, writeFile } from 'node:fs/promises';

const allegroFiles = new Set(['.htaccess', 'index.php', 'callback.php', 'bridge.js', 'lib.php', 'config.php', 'oauth.php', 'http.php', 'storage.php']);

export function publicAdminSource(source) {
  const parts = source.replaceAll('\\', '/').split('/');
  const name = parts.at(-1).toLowerCase();
  if (['storage', 'private_allegro', '.credentials.php', '.user.ini'].includes(name)
      || name.startsWith('.env')) return false;
  const index = parts.lastIndexOf('allegro');
  // Exact source allowlist, including no private files in nested directories.
  if (index >= 0 && index < parts.length - 1) return parts.length === index + 2 && allegroFiles.has(parts.at(-1));
  return true;
}

export async function assertPrivateAllegroAbsent(directory) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const target = path.join(directory, entry.name);
    const normalized = target.replaceAll('\\', '/');
    if (entry.name.toLowerCase() === 'private_allegro'
        || (normalized.includes('/admin/allegro/') && !publicAdminSource(target))) {
      throw new Error('Publiczny artefakt zawiera niedozwolone prywatne pliki Allegro.');
    }
    if (entry.isSymbolicLink()) throw new Error('Dowiązania symboliczne nie są dozwolone w publicznym artefakcie.');
    if (entry.isDirectory()) await assertPrivateAllegroAbsent(target);
  }
}

// Shared, reviewed contract: both release builders must produce this exact OFF payload.
export const allegroOffProfile = JSON.parse(await readFile(new URL('./allegro-off-profile.json', import.meta.url), 'utf8'));
const sha = text => createHash('sha256').update(text).digest('hex');
export function allegroOffConfig(source) {
  const contract = allegroOffProfile;
  let text = source.replaceAll('\r\n', '\n');
  if (contract.schema !== 1 || contract.profile !== 'B.1.1-OAUTH-OFF-v1' || sha(text) !== contract.source_sha256) {
    throw new Error('Unreviewed Allegro configuration/profile; build stopped.');
  }
  for (const [before, after] of contract.replacements) {
    if (text.split(before).length !== 2) throw new Error('Allegro OFF transform is no longer exact.');
    text = text.replace(before, after);
  }
  if (sha(text) !== contract.payload_sha256) throw new Error('Wrong Allegro OFF payload; build stopped.');
  return text;
}

export async function applyAllegroOffProfile(publish) {
  for (const file of allegroFiles) {
    const target = path.join(publish, 'admin/allegro', file);
    const source = await readFile(target, 'utf8'); // Missing public module files fail closed.
    await writeFile(target, file === 'config.php' ? allegroOffConfig(source) : source.replaceAll('\r\n', '\n'));
  }
}
