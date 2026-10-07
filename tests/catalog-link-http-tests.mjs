import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const base = process.env.HGO_PREVIEW_URL || "http://127.0.0.1:4173";
const oldCaptain = "/produkt/krzeslo-biurowe-captain-jasnobezowe-2";
const captain = "/produkt/krzeslo-biurowe-captain-jasnobezowe";
const retiredHeads = [
  "/produkt/rzezba-betonowa-do-ogrodu-dekoracyjna-glowa-140-cm",
  "/produkt/rzezba-betonowa-do-ogrodu-z-siedziskiem-dekoracyjna-glowa-140-cm",
];
const pendingFigures = [
  "rzezba-ogrodowa-twarz-mala-dostepne-w-roznych-barwach",
  "rzezba-betonowa-do-ogrodu-dekoracyjna-glowa-120-cm",
  "rzezba-betonowa-do-ogrodu-z-siedziskiem-dekoracyjna-glowa-120-cm",
  "lezaca-rzezba-betonowa-do-ogrodu-dekoracyjna-twarz",
];
const smoki = "figurki-ogrodowe-dekoracyjne-styl-kamienny";
const urls = (html) => [...new Set([...html.matchAll(/href="([^" ]*\/produkt\/[^" ]+)"/g)]
  .map((match) => match[1]))].sort();

for (const source of [oldCaptain, `${oldCaptain}/`]) {
  const response = await fetch(base + source, { redirect: "manual" });
  assert.equal(response.status, 301);
  assert.equal(response.headers.get("location"), "https://mgoutlet.pl" + captain);
  await response.arrayBuffer();
}
const target = await fetch(base + captain, { redirect: "manual" });
assert.equal(target.status, 200);
assert.equal(target.headers.get("location"), null, "Canonical CAPTAIN must not redirect back.");
const targetHtml = await target.text();
assert.ok(targetHtml.includes("https://schema.org/OutOfStock"), "CAPTAIN must remain sold.");
assert.ok(/sprzedan/i.test(targetHtml), "The page must communicate the sold status.");

for (const source of retiredHeads) {
  const response = await fetch(base + source, { redirect: "manual" });
  assert.equal(response.status, 404);
  assert.equal(response.headers.get("location"), null);
  await response.arrayBuffer();
}

const targets = [
  'figura-ogrodowa-twarz-czarna-artystyczne-wykonczenie',
  'figura-ogrodowa-twarz-kobiety-114-cm-czarna-z-miedzianym-motywem-winorosli',
  'figura-ogrodowa-twarz-kobiety-z-zamknietymi-oczami-i-siedziskiem-114-cm-szaro-brazowa',
];
for (const [index, slug] of pendingFigures.slice(0, 3).entries()) {
  for (const suffix of ['', '/']) {
    const response = await fetch(base + `/produkt/${slug}${suffix}`, { redirect: 'manual' });
    assert.equal(response.status, 301);
    assert.equal(response.headers.get('location'), `https://mgoutlet.pl/sklep/figury-ogrodowe/produkt/${targets[index]}`);
    await response.arrayBuffer();
  }
  const response = await fetch(base + `/sklep/figury-ogrodowe/produkt/${targets[index]}`, { redirect: 'manual' });
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('location'), null, 'Target must not redirect back');
  await response.arrayBuffer();
}
for (const slug of [smoki, pendingFigures[3]]) {
  const response = await fetch(base + `/produkt/${slug}`, { redirect: "manual" });
  assert.equal(response.status, 200, `${slug}: existing record must stay available pending URL decisions`);
  assert.equal(response.headers.get("location"), null);
  await response.arrayBuffer();
}

for (const [route, file] of [["/dom", "dom.html"], ["/ogrod", "ogrod.html"]]) {
  const response = await fetch(base + route);
  assert.equal(response.status, 200);
  const html = await response.text();
  const artifact = await readFile(path.join(root, "publish", file), "utf8");
  const source = await readFile(path.join(root, file), "utf8");
  assert.deepEqual(urls(html), urls(artifact), `${route}: server HTML differs from artifact.`);
  assert.deepEqual(urls(source), urls(artifact), `${file}: source differs from artifact.`);
  for (const old of [oldCaptain, ...retiredHeads]) {
    assert.ok(!urls(html).includes(old));
  }
  if (route === "/ogrod") for (const slug of [smoki, ...pendingFigures]) assert.ok(!urls(html).includes(`/produkt/${slug}`));
  console.log(`PASS: ${route}, server/source/artifact (${urls(html).length} products)`);
}

const snapshot = JSON.parse(await readFile(path.join(root, ".local-cache", "products-public.json"), "utf8"));
const figures = snapshot.products.filter((product) => product.saleType === "garden_figure"
  && product.shopVisible && product.shopStatus === "Dostępny"
  && !["Sprzedany", "Ukryty"].includes(product.productStatus)
  && !["Sprzedane", "Sprzedany"].includes(product.status));
const shop = await fetch(base + "/sklep/figury-ogrodowe");
assert.equal(shop.status, 200);
assert.deepEqual(urls(await shop.text()), figures.map((product) =>
  `/sklep/figury-ogrodowe/produkt/${product.slug}`).sort());
console.log(`PASS: figure catalogue unchanged (${figures.length} products), CAPTAIN 301 without loop, retired heads 404`);

const home = await fetch(base + "/");
const homeHtml = await home.text();
for (const slug of pendingFigures) {
  assert.ok(!urls(homeHtml).includes(`/produkt/${slug}`));
  for (const directory of [root, path.join(root, "publish")]) {
    const template = await readFile(path.join(directory, "index.html"), "utf8");
    assert.ok(!urls(template).includes(`/produkt/${slug}`));
  }
}
const sitemapResponse = await fetch(base + '/sitemap.xml');
assert.equal(sitemapResponse.status, 200);
const xml = await sitemapResponse.text();
const locations = [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1]);
assert.equal(new Set(locations).size, locations.length);
for (const slug of pendingFigures.slice(0, 3)) assert.ok(!locations.includes('https://mgoutlet.pl/produkt/' + slug));
for (const slug of targets) assert.ok(locations.includes('https://mgoutlet.pl/sklep/figury-ogrodowe/produkt/' + slug));
for (const slug of [smoki, pendingFigures[3]]) assert.ok(locations.includes('https://mgoutlet.pl/produkt/' + slug));
const expected = snapshot.products.filter(p => p.saleType === 'garden_figure'
  ? p.shopVisible && p.shopStatus === 'Dostępny' && p.productStatus !== 'Ukryty'
  : p.visible !== false && p.productStatus !== 'Ukryty' && !pendingFigures.slice(0, 3).includes(p.slug))
  .map(p => 'https://mgoutlet.pl' + (p.saleType === 'garden_figure' ? '/sklep/figury-ogrodowe/produkt/' : '/produkt/') + p.slug);
assert.deepEqual(locations.filter(u => u.includes('/produkt/')).sort(), expected.sort());
console.log(`PASS: approved redirects, no loops; lying face/SMOKI retain 200; sitemap ${locations.length} URLs matches current snapshot`);
