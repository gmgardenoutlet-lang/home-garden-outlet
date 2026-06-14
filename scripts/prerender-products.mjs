import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = process.env.SITE_ROOT
  ? path.resolve(process.env.SITE_ROOT)
  : path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const data = JSON.parse(await readFile(path.join(root, "data", "products.json"), "utf8"));
const products = Array.isArray(data.products) ? data.products : [];

const escapeHtml = (value) => String(value ?? "")
  .replace(/&/g, "&amp;")
  .replace(/</g, "&lt;")
  .replace(/>/g, "&gt;")
  .replace(/"/g, "&quot;")
  .replace(/'/g, "&#039;");

const hasValue = (value) => {
  const normalized = String(value ?? "").trim().toLowerCase();
  return Boolean(normalized)
    && normalized !== "brak"
    && normalized !== "xxx"
    && normalized !== "-"
    && !normalized.includes("do uzupełnienia");
};

const parsePrice = (value) => {
  const match = String(value ?? "").replace(/\s/g, "").replace(",", ".").match(/\d+(?:\.\d+)?/);
  return match ? Number(match[0]) : null;
};

const normalize = (value) => String(value ?? "")
  .normalize("NFD")
  .replace(/[\u0300-\u036f]/g, "")
  .trim()
  .toLowerCase();

const isPublic = (product) => product.visible !== false && normalize(product.productStatus) !== "ukryty";

const displayStatus = (product) => {
  const managementStatus = normalize(product.productStatus);
  if (managementStatus === "sprzedany") return "Sprzedany";
  if (managementStatus === "rezerwacja") return "Rezerwacja";
  return hasValue(product.status) ? product.status : "Dostępny od ręki";
};

const matchesCategory = (productCategory, pageCategory) => {
  const category = normalize(productCategory);
  const page = normalize(pageCategory);

  if (page === "wyposazenie domu") {
    return ["wyposazenie domu", "dom", "dekoracje", "oswietlenie"].includes(category);
  }

  if (page === "wyposazenie ogrodu") {
    return ["wyposazenie ogrodu", "ogrod"].includes(category);
  }

  return category === page;
};

const imagePath = (product) => {
  if (!hasValue(product.image) || String(product.image).includes("..")) {
    return "/product-table.jpeg";
  }

  return String(product.image).startsWith("/") ? product.image : `/${product.image}`;
};

const description = (value) => {
  const text = String(value || "Produkt dostępny do obejrzenia na miejscu.")
    .replace(/\s+/g, " ")
    .trim();

  if (text.length <= 280) return text;
  const shortened = text.slice(0, 277);
  return `${shortened.slice(0, shortened.lastIndexOf(" ")).trim()}…`;
};

const productCard = (product) => {
  const name = hasValue(product.name) ? product.name : "Produkt outletowy";
  const category = hasValue(product.category) ? product.category : "Meble do domu i ogrodu";
  const status = displayStatus(product);
  const alt = hasValue(product.imageAlt)
    ? product.imageAlt
    : `${name} — ${category}, Home & Garden Outlet Kębłowice pod Wrocławiem`;
  const catalogPrice = hasValue(product.catalogPrice) ? product.catalogPrice : "";
  const outletPrice = hasValue(product.outletPrice) ? product.outletPrice : "";
  const catalogValue = parsePrice(catalogPrice);
  const outletValue = parsePrice(outletPrice);
  const saving = catalogValue && outletValue && catalogValue > outletValue
    ? Math.round(catalogValue - outletValue)
    : null;
  const prices = [
    catalogPrice
      ? `<span class="catalog-price${outletPrice ? " old-price" : ""}">Cena katalogowa: ${escapeHtml(catalogPrice)}</span>`
      : "",
    outletPrice ? `<span class="outlet-price">Cena outletowa: ${escapeHtml(outletPrice)}</span>` : "",
    saving ? `<span class="saving-badge">Oszczędzasz: ${saving} zł</span>` : ""
  ].filter(Boolean).join("");
  const condition = hasValue(product.condition)
    ? `<p class="dimensions">Stan: ${escapeHtml(product.condition)}</p>`
    : "";
  const dimensions = hasValue(product.dimensions)
    ? `<p class="dimensions">${escapeHtml(product.dimensions)}</p>`
    : "";

  return `
        <article class="product-card product-card-static">
          <div class="product-image">
            <img src="${escapeHtml(imagePath(product))}" width="600" height="450" loading="lazy" alt="${escapeHtml(alt)}">
            <span class="badge">${escapeHtml(status)}</span>
          </div>
          <div class="product-body">
            <div class="product-meta"><span>${escapeHtml(category)}</span><span>${escapeHtml(status)}</span></div>
            <h3>${escapeHtml(name)}</h3>
            ${prices ? `<div class="price-row${outletPrice ? " has-outlet" : ""}">${prices}</div>` : '<p class="price-note">Zapytaj o cenę.</p>'}
            <p class="product-description">${escapeHtml(description(product.description))}</p>
            ${condition}
            ${dimensions}
            <div class="product-actions">
              <a class="btn btn-primary" href="tel:+48577210777">Zadzwoń</a>
              <a class="btn btn-outline" href="sms:+48577210777">Zapytaj o produkt</a>
            </div>
          </div>
        </article>`;
};

const homepageProducts = () => {
  const publicProducts = products.filter(isPublic);
  const featured = publicProducts.filter((product) => product.featured !== false && displayStatus(product) !== "Sprzedany" && displayStatus(product) !== "Sprzedane");
  const selected = featured.slice(0, 6);

  if (selected.length < 6) {
    selected.push(...publicProducts
      .filter((product) => !selected.includes(product) && displayStatus(product) !== "Sprzedany" && displayStatus(product) !== "Sprzedane")
      .slice(0, 6 - selected.length));
  }

  return selected;
};

const startMarker = "<!-- STATIC_PRODUCTS_START -->";
const endMarker = "<!-- STATIC_PRODUCTS_END -->";

async function updatePage(file, pageProducts) {
  const fullPath = path.join(root, file);
  const html = await readFile(fullPath, "utf8");
  const grid = `<div id="produkty" class="product-grid" aria-live="polite">
        ${startMarker}${pageProducts.map(productCard).join("")}
        ${endMarker}
      </div>`;
  const markerPattern = /<div id="produkty" class="product-grid" aria-live="polite">[\s\S]*?<!-- STATIC_PRODUCTS_END -->\s*<\/div>/;
  const emptyPattern = /<div id="produkty" class="product-grid" aria-live="polite"><\/div>/;
  const next = markerPattern.test(html)
    ? html.replace(markerPattern, grid)
    : html.replace(emptyPattern, grid);

  if (next === html) {
    throw new Error(`Nie znaleziono siatki produktów w ${file}`);
  }

  await writeFile(fullPath, next, "utf8");
}

await updatePage("index.html", homepageProducts());
await updatePage("dom.html", products.filter(isPublic).filter((product) => matchesCategory(product.category, "Wyposażenie domu")));
await updatePage("ogrod.html", products.filter(isPublic).filter((product) => matchesCategory(product.category, "Wyposażenie ogrodu")));

console.log(`Wygenerowano statyczny katalog z ${products.length} produktów.`);
