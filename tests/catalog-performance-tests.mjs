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
    const old = execFileSync('git', ['show', `3129a98:${filename}`]);
    assert.equal((await readFile(filename,'utf8')).replaceAll('\r\n','\n'), old.toString().replaceAll('\r\n','\n'), `${filename}: unrelated source must not change`);
  } catch (e) {
    if (e.status === 128) continue; // optional workflow filename varies between installations
    throw e;
  }
}
const workflow = await readFile('.github/workflows/deploy-getspace.yml', 'utf8');
const baselineWorkflow = execFileSync('git', ['show', '3129a98:.github/workflows/deploy-getspace.yml'], {encoding: 'utf8'});
const withoutPerformanceStep = workflow.replace(/      - name: Sprawdź zakres optymalizacji banerów i fontów\r?\n        run: \|\r?\n          git fetch --no-tags --depth=1 origin 3129a98f435fc75c6a6a12862889abd9b8892959\r?\n          node tests\/catalog-performance-tests\.mjs\r?\n\r?\n/, '');
assert.equal(withoutPerformanceStep.replaceAll('\r\n','\n'), baselineWorkflow.replaceAll('\r\n','\n'), 'Only the performance CI check may change; FTPS exclusions, backup and rollback stay intact');
console.log('PASS: performance assets, scoped layout, original content/SEO/cards/data, font licences');
