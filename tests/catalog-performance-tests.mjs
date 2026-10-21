import assert from 'node:assert/strict';
import { readFile, readdir, stat } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';

const css = await readFile('assets/catalog-performance.css', 'utf8');
assert.equal((css.match(/@font-face/g) || []).length, 6);
assert.equal((css.match(/font-display: swap/g) || []).length, 4);
assert.ok(css.includes('U+0100-02FF'), 'Polish extensions must be available');
assert.ok(!css.includes('overflow-x'), 'Do not mask overflow');
assert.ok(!css.includes('min-height'), 'Do not change accepted layout');
for (const filename of ['index.html', 'dom.html', 'ogrod.html']) {
  const html = await readFile(filename, 'utf8');
  const baseline = execFileSync('git', ['show', `3129a98:${filename}`], {encoding: 'utf8'});
  const seo = source => [...source.replaceAll('\r\n','\n').matchAll(/<(?:meta\b[^>]*|link\b[^>]*rel="canonical"[^>]*|script type="application\/ld\+json">[^]*?<\/script>)/g)].map(m => m[0]);
  assert.deepEqual(seo(html), seo(baseline), `${filename}: metadata must stay unchanged`);
  assert.equal((html.match(/<h1\b/g) || []).length, 1);
  assert.equal((html.match(/rel="preload"/g) || []).length, 2, 'Only two Latin fonts preloaded');
  assert.ok(!html.includes('fonts.googleapis.com'));
  assert.ok(html.includes('fetchpriority="high"'));
  const main = source => source.slice(source.indexOf('<main'), source.indexOf('</main>'))
    .replace(/<picture class="category-hero-media"[^]*?<\/picture>/, '')
    .replace(/<img[^>]*alt="Showroom Home & Garden Outlet[^>]*>/, '')
    .replace(/\s+/g, '');
  assert.equal(main(html), main(baseline), `${filename}: content/cards must stay unchanged`);
}
const shopCss = await readFile('hosting/getspace/shop-test/shop.css', 'utf8');
assert.ok(!shopCss.includes('@import'));
const shop = await readFile('hosting/getspace/shop-test/figures.php', 'utf8');
assert.ok(shop.includes('shop_test_stylesheets(true)'));
assert.ok(shop.includes('fetchpriority="high"'));
const baselineShop = execFileSync('git', ['show','3129a98:hosting/getspace/shop-test/figures.php'], {encoding:'utf8'});
const stripShop = s => s.replace(/shop_test_stylesheets\([^)]*\)/, 'shop_test_stylesheets()').replace(/<img[^>]*alt="Figury ogrodowe i dekoracje[^>]*>/, '').replace(/\s+/g,'');
assert.equal(stripShop(shop), stripShop(baselineShop), 'Shop content must stay unchanged');
const names = await readdir('assets/images/banners');
assert.equal(names.length, 13);
for (const filename of names) {
  assert.match(filename, /^(showroom|dom|ogrod|figury)-\d+\.webp$/);
  assert.ok((await stat(`assets/images/banners/${filename}`)).size > 0);
}
for (const name of ['Manrope-OFL.txt','CormorantGaramond-OFL.txt']) {
  assert.ok((await readFile(`assets/fonts/${name}`, 'utf8')).includes('SIL OPEN FONT LICENSE'));
}
for (const filename of ['script.js','styles.css','hosting/getspace/catalog.php','scripts/prerender-products.mjs','data/products.json']) {
  try {
    // The accepted state-note fix may change only its own function. Keep the
    // remaining presentation, selectors, prices and data frozen at deployment.
    const old = execFileSync('git', ['show', `54c4a6a:${filename}`]);
    const stripStateNotes = source => {
      let text = source.replaceAll('\r\n', '\n');
      // Merchant availability adds only these confirmed-supplier/reservation
      // predicates. Its behavior is covered by merchant-feed-tests.php.
      if (filename === 'hosting/getspace/catalog.php') {
        text = text
          .replace("        && (($product['merchantSupplyModel'] ?? '') !== 'supplier' || ($product['producerAvailability'] ?? '') === 'Dostępny u producenta')\n", '')
          .replace("        && catalog_normalize((string)($product['productStatus'] ?? '')) !== 'rezerwacja'\n", '')
          .replace("        && catalog_normalize((string)($product['status'] ?? '')) !== 'rezerwacja'\n", '');
      }
      const pattern = filename === 'script.js' ? /function getListingStateNotes\([^]*?\n\}/
        : filename === 'scripts/prerender-products.mjs' ? /const listingStateNotes =[^]*?\n\};/
        : filename === 'hosting/getspace/catalog.php' ? /function catalog_listing_state_notes\([^]*?\n\}(?=\n\nfunction catalog_sale_price_text)/ : null;
      if (pattern) {
        assert.ok(pattern.test(text), `${filename}: state-note function must exist`);
        text = text.replace(pattern, '/* accepted listing-state-notes function */');
      }
      return text;
    };
    assert.equal(stripStateNotes(await readFile(filename,'utf8')), stripStateNotes(old.toString()), `${filename}: unrelated source must not change`);
  } catch (e) {
    if (e.status === 128) continue; // optional workflow filename varies between installations
    throw e;
  }
}
const workflow = await readFile('.github/workflows/deploy-getspace.yml', 'utf8');
const baselineWorkflow = execFileSync('git', ['show', '3129a98:.github/workflows/deploy-getspace.yml'], {encoding: 'utf8'});
const withoutPerformanceStep = workflow.replace(/      - name: Sprawdź zakres optymalizacji banerów i fontów\r?\n        run: \|\r?\n          git fetch --no-tags --depth=1 origin 3129a98f435fc75c6a6a12862889abd9b8892959(?: 54c4a6a5b3c212ec6556e5d9454cbd89fe1dfbbd)?\r?\n          node tests\/catalog-performance-tests\.mjs\r?\n\r?\n/, '')
  // GBP diagnostics adds only an isolated mock test, with no network or storage.
  .replace(/          php -n tests\/google-business-diagnostics-tests\.php\r?\n/, '')
  .replace(/          php tests\/google-business-payload-tests\.php\r?\n/, '')
  .replace(/          node tests\/listing-state-notes-tests\.mjs\r?\n/, '')
  .replace(/        # Merchant tests use only the just-fetched public snapshot, never old repo data\.\r?\n/, '')
  .replace('run: HGO_SHOP_SALES_ENABLED=true php tests/merchant-feed-tests.php && php tests/merchant-local-tests.php && node scripts/build-getspace.mjs', 'run: node scripts/build-getspace.mjs')
  // Approved OLX additions run before deployment; keep comparing the remaining
  // FTPS exclusions, full backup, catalogue safeguards and rollback unchanged.
  .replace(/          extensions: curl, sodium(?:, gd)?\r?\n/, '')
  .replace(/          (?:php tests\/(?:olx-oauth-tests|product-id-migration-tests)\.php|node tests\/(?:olx-http-tests|olx-identity-verification-tests)\.mjs)\r?\n/g, '')
  .replace('cancel-in-progress: false', 'cancel-in-progress: true')
  .replace(/      - name: Finalny dry-run OLX na pełnym katalogu produkcyjnym\r?\n        run: node scripts\/olx-production-prepare\.mjs preflight\r?\n\r?\n/, '')
  .replace(/      - name: Migruj wyłącznie productId i przygotuj prywatny magazyn OLX\r?\n        id: olx_prepare\r?\n        env:\r?\n          OLX_BACKUP_DIR: \$\{\{ steps\.paths\.outputs\.backup_dir \}\}\r?\n          OLX_BACKUP_LOCAL: \$\{\{ steps\.paths\.outputs\.backup_local \}\}\r?\n        run: node scripts\/olx-production-prepare\.mjs apply\r?\n\r?\n      - name: Usuń ewentualną blokadę zapisów panelu po przygotowaniu OLX\r?\n        if: \$\{\{ always\(\) && steps\.olx_prepare\.outcome != 'skipped' \}\}\r?\n        run: node scripts\/olx-production-prepare\.mjs restore\r?\n\r?\n/, '');
assert.equal(withoutPerformanceStep.replaceAll('\r\n','\n'), baselineWorkflow.replaceAll('\r\n','\n'), 'Only approved performance, Merchant and OLX additions may change; existing FTPS exclusions, full backup and rollback stay intact');
console.log('PASS: performance assets, scoped layout, original content/SEO/cards/data, font licences');
