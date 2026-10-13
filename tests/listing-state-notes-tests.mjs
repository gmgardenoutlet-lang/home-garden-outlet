import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import vm from 'node:vm';

const snapshot = process.env.HGO_PUBLIC_PRODUCTS_SNAPSHOT || '.local-cache/products-public.json';
const products = JSON.parse(readFileSync(snapshot, 'utf8')).products;
assert.ok(products.length, 'A nonempty current public snapshot is required');
const jsSource = readFileSync('script.js', 'utf8').match(/function getListingStateNotes\([^]*?\n\}/)[0];
const generatorSource = readFileSync('scripts/prerender-products.mjs', 'utf8').match(/const listingStateNotes =[^]*?\n\};/)[0];
const evaluate = (source, name, records, review = false) => JSON.parse(JSON.stringify(vm.runInNewContext(`${source}; records.map(p => ${name}(p, review))`, {records, review})));
const bySlug = slug => { const p = products.find(p => p.slug === slug); assert.ok(p, slug); return p; };
const fixtures = [
  {longDescription: 'Materiał: MDF; Waga: 26 kg; Blat ma pęknięcie przy lewej krawędzi. Ubytek ma 3 cm. Montaż: częściowo zmontowany'},
  {longDescription: 'Materiał: MDF z pęknięciem przy narożniku'},
  {longDescription: 'Brakuje śruby mocującej i pilota. Brak ten nie przeszkadza w użytkowaniu.'},
  {longDescription: 'Montaż: częściowo zmontowany\nWymaga kompletnego montażu.\nPodłokietniki: brak'},
  {longDescription: 'Kolor: złamana biel\nNoga jest złamana przy mocowaniu.'},
  {longDescription: 'Łagodne rysy twarzy podkreślają dekoracyjny charakter. Na twarzy figury widoczne rysy.'},
  {description: 'Na blacie są rysy.', condition: 'Outletowy', longDescription: 'Na blacie są rysy. Rysy na lewej krawędzi mają 4 cm.\nStan: outletowy'},
  {longDescription: 'Egzemplarz jest zmontowany i pochodzi z ekspozycji.\nMontaż: częściowo zmontowany'},
  {slug: 'wanna-hawes-hydromasaz-led-czarna', longDescription: 'Nowszy opis właściciela: rysy wyłącznie na boku. Elementy LED są nowe.'},
  {longDescription: 'Wady: mały odprysk na ramie.\nWaga: 10 kg.\nBraki: brak dwóch wsporników.'},
  {longDescription: 'Brązowe wykończenie podkreśla kształt, ale widoczne są zarysowania przy podstawie.'},
  {longDescription: 'Nakładki chronią podłoże przed zarysowaniem, ale blat ma zarysowania przy krawędzi.'},
  {longDescription: 'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Jeden z modułów jest wybrakowany — brakuje dwóch wsporników. Brak ten nie przeszkadza w użytkowaniu.\n- uwaga o stanie: w jednym module brakuje dwóch wsporników, co nie przeszkadza w użytkowaniu'},
  {description: 'Na blacie nie ma rys.', longDescription: 'Na blacie są rysy.'},
  {longDescription: 'Jeden z modułów jest wybrakowany — brakuje dwóch wsporników. Brak ten nie przeszkadza w użytkowaniu.\n\nDodatkowe informacje:\n- uwaga o stanie: w jednym module brakuje dwóch wsporników, co nie przeszkadza w użytkowaniu'},
  {longDescription: 'Materiał: drewno z rysami.\nMontaż: wykonany niedbale, widoczne ślady przy mocowaniu.\nWedług danych właściciela na blacie są rysy.'},
];
const records = [...products, ...fixtures];
const before = JSON.stringify(records);
const js = evaluate(jsSource, 'getListingStateNotes', records, true);
const generator = evaluate(generatorSource, 'listingStateNotes', records, true);
const phpCode = 'require "hosting/getspace/catalog.php"; $p=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo json_encode(array_map(fn($x)=>catalog_listing_state_notes($x,true),$p),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);';
const php = spawnSync(process.env.HGO_PHP_BINARY || 'php', ['-r', phpCode], {input: JSON.stringify(records), encoding: 'utf8', maxBuffer: 8 * 1024 * 1024});
assert.equal(php.status, 0, php.stderr);
assert.deepEqual(js, JSON.parse(php.stdout), 'PHP / JS parity, including review diagnostics');
assert.deepEqual(js, generator, 'Generator / JS parity');
assert.equal(JSON.stringify(records), before, 'Never modify source data');
const notes = p => evaluate(jsSource, 'getListingStateNotes', [p])[0];
assert.equal(notes(bySlug('stol-do-jadalni-ivorie-jesion-jasny-180x90')), '');
assert.equal(notes(bySlug('stol-do-jadalni-moora-jesion-naturalny-180x90')), '');
const hawes = notes(bySlug('wanna-hawes-hydromasaz-led-czarna'));
for (const text of ['rysy', 'ślady ekspozycyjne', 'zarysowania elementów chromowanych', 'uszkodzenia czarnej obudowy', 'ubytek przy narożniku', 'Elementy hydromasażu i oświetlenia LED są nowe.']) assert.ok(hawes.includes(text), text);
assert.ok(!/Te informacje powinny|stan: outletowy, z widocznymi defektami|sprawn|pojemność|pompa:/iu.test(hawes));
const f = js.slice(products.length);
assert.equal(f[0].text, 'Blat ma pęknięcie przy lewej krawędzi. Ubytek ma 3 cm.');
assert.equal(f[1].text, fixtures[1].longDescription);
assert.equal(f[1].ambiguous.length, 1, 'Unsplit mixed technical/defect clause stays visible for review');
assert.equal(f[2].text, fixtures[2].longDescription);
assert.equal(f[3].text, '');
assert.equal(f[4].text, 'Noga jest złamana przy mocowaniu.');
assert.equal(f[5].text, 'Na twarzy figury widoczne rysy.');
assert.equal(f[6].text, 'Rysy na lewej krawędzi mają 4 cm.');
assert.equal(f[7].text, 'Egzemplarz jest zmontowany i pochodzi z ekspozycji.');
assert.equal(f[8].text, fixtures[8].longDescription);
assert.equal(f[9].text, 'Wady: mały odprysk na ramie.\n\nBraki: brak dwóch wsporników.');
assert.equal(f[10].text, fixtures[10].longDescription);
assert.equal(f[11].text, fixtures[11].longDescription);
assert.equal(f[12].text, fixtures[12].longDescription.split('\n')[0]);
assert.equal(f[13].text, fixtures[13].longDescription);
assert.equal(f[14].text, fixtures[14].longDescription.split('\n')[0]);
assert.ok(notes(bySlug('zestaw-modulow-kuchennych-ogrodowych-venosa')).includes('brakuje dwóch wsporników'));
assert.ok(!notes(bySlug('zestaw-modulow-kuchennych-ogrodowych-venosa')).includes('uwaga o stanie:'));
assert.equal(f[15].text, fixtures[15].longDescription.replaceAll('\n', '\n\n'));
console.log(`PASS: ${products.length} public records + ${fixtures.length} regression fixtures; PHP/JS/generator parity, complete defects and source immutability`);
