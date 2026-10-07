import assert from "node:assert/strict";
import { execFile } from "node:child_process";
import { cp, mkdtemp, mkdir, readFile, rm, writeFile } from "node:fs/promises";
import { promisify } from "node:util";
import vm from "node:vm";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const exec = promisify(execFile);
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const temp = await mkdtemp(path.join(os.tmpdir(), "hgo-figure-exclusion-"));
const excluded = [
  "rzezba-ogrodowa-twarz-mala-dostepne-w-roznych-barwach",
  "rzezba-betonowa-do-ogrodu-dekoracyjna-glowa-120-cm",
  "rzezba-betonowa-do-ogrodu-z-siedziskiem-dekoracyjna-glowa-120-cm",
  "lezaca-rzezba-betonowa-do-ogrodu-dekoracyjna-twarz",
];
const smoki = "figurki-ogrodowe-dekoracyjne-styl-kamienny";
const items = [...excluded, smoki, "zwykly-produkt"].map((slug) => ({
  slug, _publicSlug: slug, name: slug, category: "Wyposażenie ogrodu",
  featured: true, visible: true, status: "Dostępne", outletPrice: "100 zł",
}));
const verify = (html, label) => {
  for (const slug of excluded) assert.ok(!html.includes(`/produkt/${slug}`), `${label}: promoted ${slug}`);
  assert.ok(html.includes(`/produkt/${smoki}`), `${label}: SMOKI lost outlet eligibility`);
};

try {
  await mkdir(path.join(temp, "data"));
  await writeFile(path.join(temp, "data", "products.json"), JSON.stringify({ products: items }));
  for (const file of ["index.html", "dom.html", "ogrod.html"]) await cp(path.join(root, file), path.join(temp, file));
  for (const file of ["catalog.php", "homepage.php"]) await cp(path.join(root, "hosting", "getspace", file), path.join(temp, file));
  await exec(process.execPath, [path.join(root, "scripts", "prerender-products.mjs")], {
    env: { ...process.env, SITE_ROOT: temp, HGO_PUBLIC_PRODUCTS_SNAPSHOT: "" },
  });
  verify(await readFile(path.join(temp, "index.html"), "utf8"), "static generator");
  const php = await exec(process.env.HGO_PHP_BINARY || "php", [path.join(temp, "homepage.php")]);
  verify(php.stdout, "PHP homepage");

  const script = await readFile(path.join(root, "script.js"), "utf8");
  const functions = ["isLegacyFigureListingRecord", "pickRandomHomepageProducts", "pickHomepageProducts", "renderProducts"]
    .map((name) => {
      const fn = script.match(new RegExp(`function ${name}\\([^]*?\\n\\}`));
      assert.ok(fn, `Missing ${name}`);
      return fn[0];
    }).join("\n");
  const context = {
    items, homepageProductLimit: 6, isSoldProduct: () => false,
    isFigureShopProduct: p => p.saleType === 'garden_figure',
    isActiveFigureShopProduct: p => p.saleType === 'garden_figure' && p.shopVisible && p.shopStatus === 'Dostępny',
    shuffleProducts: (products) => products, getProductSeo: (product) => ({ slug: product.slug }),
    products: items, productGrid: { innerHTML: "" }, productEmpty: null, isCategoryPage: false,
    isProductPublic: () => true, getDiscoveryFilters: () => ({ category: "all" }),
    applyDiscoveryFilters: (products) => products, hasActiveDiscoveryFilters: () => true,
    getHomepageSelectedSlugs: () => null, sortProducts: (products) => products,
    productTemplate: (product) => `<a href="/produkt/${product.slug}">product</a>`,
    updateProductCount: () => {}, requestAnimationFrame: () => {}, initializeDescriptionToggles: () => {},
  };
  const result = vm.runInNewContext(`${functions}; const selections = [pickHomepageProducts(items), pickHomepageProducts(items, items.map(p => p.slug))]; renderProducts(); ({ selections, html: productGrid.innerHTML });`, context);
  verify(result.html, "JavaScript active search/filters");
  const selections = result.selections;
  for (const selected of selections) {
    assert.ok(selected.some((product) => product.slug === smoki));
    assert.ok(selected.every((product) => !excluded.includes(product.slug)));
  }
  const figure = { ...items[0], slug: 'approved-figure', _publicSlug: 'approved-figure', saleType: 'garden_figure', shopVisible: true, shopStatus: 'Dostępny', grossPrice: '200 zł' };
  const diverse = Array.from({ length: 8 }, (_, i) => ({ ...items[5], slug: `outlet-${i}`, _publicSlug: `outlet-${i}` }));
  const fixture = [...diverse, figure];
  context.items = fixture;
  const quota = vm.runInNewContext(`${functions}; [pickHomepageProducts(items), pickHomepageProducts(items, items.slice(0, 6).map(p => p.slug))];`, context);
  for (const selected of quota) {
    assert.equal(selected.length, 6);
    assert.equal(selected.filter(p => p.saleType === 'garden_figure').length, 1);
    assert.equal(new Set(selected.map(p => p.slug)).size, 6);
  }
  context.items = [...diverse, { ...figure, featured: false }];
  assert.ok(vm.runInNewContext(`${functions}; pickHomepageProducts(items).every(p => p.saleType !== 'garden_figure');`, context));
  context.items = [...diverse, { ...figure, shopVisible: false }];
  assert.ok(vm.runInNewContext(`${functions}; pickHomepageProducts(items).every(p => p.saleType !== 'garden_figure');`, context));
  context.items = fixture;
  const preferred = [figure.slug, ...diverse.slice(0, 5).map(p => p.slug)];
  context.preferred = preferred;
  assert.deepEqual(Array.from(vm.runInNewContext(`${functions}; pickHomepageProducts(items, preferred).map(p => p.slug);`, context)), preferred);
  await writeFile(path.join(temp, 'data', 'products.json'), JSON.stringify({ products: fixture }));
  await exec(process.execPath, [path.join(root, 'scripts', 'prerender-products.mjs')], { env: { ...process.env, SITE_ROOT: temp, HGO_PUBLIC_PRODUCTS_SNAPSHOT: '' } });
  const staticHtml = await readFile(path.join(temp, 'index.html'), 'utf8');
  assert.ok(staticHtml.includes('/sklep/figury-ogrodowe/produkt/approved-figure'));
  for (let i = 0; i < 8; i++) {
    const phpQuota = await exec(process.env.HGO_PHP_BINARY || 'php', [path.join(temp, 'homepage.php')]);
    assert.ok(phpQuota.stdout.includes('/sklep/figury-ogrodowe/produkt/approved-figure'));
  }
  console.log("PASS: PHP/JavaScript/static homepage exclude four legacy figures and preserve SMOKI eligibility");
} finally {
  await rm(temp, { recursive: true, force: true });
}
