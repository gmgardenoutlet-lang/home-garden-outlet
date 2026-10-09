import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const snapshotPath = path.resolve(process.env.HGO_PUBLIC_PRODUCTS_SNAPSHOT || path.join(root, ".local-cache", "products-public.json"));
const publish = path.join(root, "publish");
const snapshot = JSON.parse(await readFile(snapshotPath, "utf8"));
const artifact = JSON.parse(await readFile(path.join(publish, "data", "products.json"), "utf8"));
if (!Array.isArray(snapshot.products) || snapshot.products.length === 0
  || JSON.stringify(snapshot) !== JSON.stringify(artifact)) {
  throw new Error("Artefakt nie używa niepustej publicznej migawki.");
}

const slugify = (value) => String(value || "")
  .replace(/ł/g, "l").replace(/Ł/g, "L")
  .normalize("NFD").replace(/[\u0300-\u036f]/g, "")
  .toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "") || "produkt";
const outletUrls = new Set();
const figureUrls = new Set();
const productsByUrl = new Map();
const used = new Map();
const soldUrls = new Set();
for (const product of snapshot.products) {
  const base = slugify(product.slug || product.name);
  const count = (used.get(base) || 0) + 1;
  used.set(base, count);
  const outletUrl = `/produkt/${count > 1 ? `${base}-${count}` : base}`;
  if (product.saleType === "garden_figure") {
    const url = `/sklep/figury-ogrodowe/produkt/${base}`;
    figureUrls.add(url);
    productsByUrl.set(url, product);
  } else {
    outletUrls.add(outletUrl);
    productsByUrl.set(outletUrl, product);
  }
  if (["sprzedane", "sprzedany"].includes(String(product.status || "").toLowerCase())) {
    soldUrls.add(product.saleType === "garden_figure" ? `/sklep/figury-ogrodowe/produkt/${base}` : outletUrl);
  }
}

const pages = await Promise.all(["index.html", "dom.html", "ogrod.html"].map(async (file) => ({
  file,
  html: await readFile(path.join(publish, file), "utf8"),
})));
for (const { file, html } of pages) {
  const cards = [...html.matchAll(/<article class="product-card product-card-static(?: product-card-compact)?">[\s\S]*?<\/article>/g)].map((match) => match[0]);
  if (cards.length === 0) throw new Error(`Brak kart w ${file}.`);
  const pageUrls = new Set();
  for (const card of cards) {
    const url = card.match(/class="product-image-link" href="([^"]+)"/)?.[1];
    if (!url || (!outletUrls.has(url) && !figureUrls.has(url)) || soldUrls.has(url) || pageUrls.has(url)) {
      throw new Error(`Błędny lub sprzedany identyfikator ${url} w ${file}.`);
    }
    pageUrls.add(url);
    if (figureUrls.has(url) && (!card.includes("Kup online") || !card.includes("Cena:") || card.includes("product-delivery-info"))) {
      throw new Error(`Karta figury online ma błędną cenę lub działanie w ${file}.`);
    }
    const product = productsByUrl.get(url);
    const price = product.saleType === "garden_figure" ? product.grossPrice : product.outletPrice;
    if (price && !card.includes(String(price))) {
      throw new Error(`Karta ${url} nie pokazuje ceny z migawki.`);
    }
  }
  if (file === "dom.html" || file === "ogrod.html") {
    const isGarden = file === "ogrod.html";
    const categories = isGarden
      ? ["wyposazenie ogrodu", "ogrod"]
      : ["wyposazenie domu", "dom", "dekoracje", "oswietlenie"];
    const normalize = (value) => String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim().toLowerCase();
    const expected = snapshot.products.filter((product) => {
      if (product.visible === false || normalize(product.productStatus) === "ukryty"
        || ["sprzedany", "sprzedane"].includes(normalize(product.productStatus))
        || ["sprzedany", "sprzedane"].includes(normalize(product.status))) return false;
      if (!categories.includes(normalize(product.category))) return false;
      if (isGarden && (product.saleType === "garden_figure" && product.shopVisible && product.shopStatus === "Dostępny"
        || normalize(product.productType) === "rzezba ogrodowa"
        || product.slug === "figurki-ogrodowe-dekoracyjne-styl-kamienny")) return false;
      return true;
    });
    const expectedUrls = new Set(expected.map((product) => product.saleType === "garden_figure"
      ? `/sklep/figury-ogrodowe/produkt/${slugify(product.slug)}`
      : `/produkt/${slugify(product.slug)}`));
    if (pageUrls.size !== expectedUrls.size || [...expectedUrls].some((url) => !pageUrls.has(url))) {
      const missing = [...expectedUrls].filter((url) => !pageUrls.has(url));
      const unexpected = [...pageUrls].filter((url) => !expectedUrls.has(url));
      throw new Error(`Karty w ${file} nie odpowiadają migawce: brak ${missing.join(", ")}; nadmiar ${unexpected.join(", ")}.`);
    }
  }
  if (file === 'index.html') {
    const eligibleFigure = snapshot.products.some(p => p.saleType === 'garden_figure'
      && p.visible !== false && p.featured !== false && p.shopVisible && p.shopStatus === 'Dostępny'
      && !['Ukryty', 'Sprzedany'].includes(p.productStatus) && !['Sprzedane', 'Sprzedany'].includes(p.status));
    if (eligibleFigure && ![...pageUrls].some(url => figureUrls.has(url))) throw new Error('Missing eligible recommended figure.');
    if (cards.length !== 6) throw new Error('Current snapshot should generate six unique recommendations.');
    const legacy = ['rzezba-ogrodowa-twarz-mala-dostepne-w-roznych-barwach', 'rzezba-betonowa-do-ogrodu-dekoracyjna-glowa-120-cm', 'rzezba-betonowa-do-ogrodu-z-siedziskiem-dekoracyjna-glowa-120-cm', 'lezaca-rzezba-betonowa-do-ogrodu-dekoracyjna-twarz'];
    if (legacy.some(slug => pageUrls.has('/produkt/' + slug))) throw new Error('Legacy face promoted.');
  }
  if (html.includes("internalNote") || html.includes("futureAdminSecret")) {
    throw new Error(`${file} zawiera pole administracyjne.`);
  }
}
console.log(`PASS: public artifact IDs and cards (${snapshot.products.length} records)`);
