import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import vm from 'node:vm';

const exec = promisify(execFile);
const products = JSON.parse(await readFile('.local-cache/products-public.json', 'utf8')).products;
const script = await readFile('script.js', 'utf8');
const generator = await readFile('scripts/prerender-products.mjs', 'utf8');
const jsFn = script.match(/function getListingStateNotes\([^]*?\n\}/)[0];
const genFn = generator.match(/const listingStateNotes =[^]*?\n\};/)[0];
const jsNotes = Array.from(vm.runInNewContext(`${jsFn}; products.map(getListingStateNotes)`, { products }));
const genNotes = Array.from(vm.runInNewContext(`${genFn}; products.map(listingStateNotes)`, { products }));
const php = process.env.HGO_PHP_BINARY || 'php';
const result = await exec(php, ['-r', 'require "hosting/getspace/catalog.php"; $p=json_decode(file_get_contents(".local-cache/products-public.json"),true)["products"]; echo json_encode(array_map("catalog_listing_state_notes",$p));']);
assert.deepEqual(jsNotes, JSON.parse(result.stdout));
assert.deepEqual(jsNotes, genNotes);
const damaged = products.findIndex(p => p.slug === 'wanna-hawes-hydromasaz-led-czarna');
assert.ok(jsNotes[damaged].includes('ubytek przy narożniku'));
assert.ok(jsNotes[damaged].includes('Elementy hydromasażu i oświetlenia LED są nowe.'));
assert.ok(!jsNotes[damaged].includes('pojemność: 285 l'));
assert.ok(!jsNotes[damaged].includes('Te informacje powinny pozostać widoczne'));
assert.ok(!jsNotes[damaged].includes('- stan: outletowy, z widocznymi defektami'));
for (const text of ['rysy', 'ślady ekspozycyjne', 'zarysowania elementów chromowanych', 'uszkodzenia czarnej obudowy', 'ubytek przy narożniku']) {
  assert.ok(jsNotes[damaged].includes(text), `Retain HAWES defect: ${text}`);
}
const ownerEdit = { ...products[damaged], longDescription: 'Nowszy opis właściciela: rysy wyłącznie na boku. Elementy LED są nowe.' };
assert.equal(vm.runInNewContext(`${jsFn}; getListingStateNotes(product)`, { product: ownerEdit }), ownerEdit.longDescription);
const publicCopy = await exec(php, ['-r', 'require "hosting/getspace/catalog.php"; $p=json_decode(file_get_contents(".local-cache/products-public.json"),true)["products"]; foreach($p as $v) { if(($v["slug"]??"")==="wanna-hawes-hydromasaz-led-czarna") { echo catalog_apply_reviewed_product_fixes($v)["longDescription"]; }}']);
assert.ok(!publicCopy.stdout.includes('Te informacje powinny pozostać widoczne'));
assert.ok(!publicCopy.stdout.includes('- stan: outletowy, z widocznymi defektami'));
assert.ok(publicCopy.stdout.includes('Elementy hydromasażu i oświetlenia LED są nowe.'));
const longName = products.findIndex(p => p.slug === 'lozko-dzienne-z-szufladami-drewno-sosnowe-royville-90-x-200-cm-bialy');
assert.equal(jsNotes[longName], '', 'Do not confuse sosnowego with nowe');
const base = process.env.HGO_PREVIEW_URL || 'http://127.0.0.1:4173';
for (const [route, file] of [['/', 'index.html'], ['/dom', 'dom.html'], ['/ogrod', 'ogrod.html']]) {
  const response = await fetch(base + route);
  assert.equal(response.status, 200);
  const pages = [await response.text(), await readFile('publish/' + file, 'utf8')];
  for (const html of pages) {
    const cards = [...html.matchAll(/<article class="product-card product-card-static product-card-compact">[^]*?<\/article>/g)].map(m => m[0]);
    assert.ok(cards.length);
    for (const card of cards) {
      assert.ok(!/class="dimensions"|product-delivery-info|product-card-links|description-toggle/.test(card));
      assert.ok(card.indexOf('product-actions') < card.indexOf('product-description-wrap'));
      assert.ok(card.includes('tel:+48577210777'));
    }
  }
}
const css = await readFile('styles.css', 'utf8');
assert.ok(css.includes('.product-card-compact .product-description'));
assert.ok(css.includes('-webkit-line-clamp: unset'));
console.log(`PASS: compact PHP/JS/static cards; identical complete state notes for ${products.length} records; defects retained`);
