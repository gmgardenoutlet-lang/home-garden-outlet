import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = process.env.SITE_ROOT
  ? path.resolve(process.env.SITE_ROOT)
  : path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const data = JSON.parse(await readFile(path.join(root, "data", "products.json"), "utf8"));
const rawProducts = Array.isArray(data.products) ? data.products : [];

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
  const raw = String(value ?? "")
    .replace(/\s/g, "")
    .replace(/[^\d,.]/g, "");

  if (!raw) {
    return null;
  }

  let normalized = raw;
  const hasComma = normalized.includes(",");
  const hasDot = normalized.includes(".");

  if (hasComma && hasDot) {
    normalized = normalized.replace(/\./g, "").replace(",", ".");
  } else if (hasDot && !hasComma) {
    const parts = normalized.split(".");
    const lastPart = parts[parts.length - 1];
    normalized = parts.length > 1 && lastPart.length === 3
      ? parts.join("")
      : normalized;
  } else {
    normalized = normalized.replace(",", ".");
  }

  const match = normalized.match(/\d+(?:\.\d+)?/);
  return match ? Number(match[0]) : null;
};

const normalize = (value) => String(value ?? "")
  .normalize("NFD")
  .replace(/[\u0300-\u036f]/g, "")
  .trim()
  .toLowerCase();

const slugify = (value) => normalize(String(value ?? "").replace(/ł/g, "l").replace(/Ł/g, "L"))
  .replace(/[^a-z0-9]+/g, "-")
  .replace(/^-+|-+$/g, "") || "produkt";

const assignSlugs = (items) => {
  const used = new Map();
  return items.map((product) => {
    const base = slugify(hasValue(product.slug) ? product.slug : product.name);
    const count = (used.get(base) || 0) + 1;
    used.set(base, count);
    return { ...product, _publicSlug: count > 1 ? `${base}-${count}` : base };
  });
};

const products = assignSlugs(rawProducts);

const isPublic = (product) => product.visible !== false && normalize(product.productStatus) !== "ukryty";

const displayStatus = (product) => {
  const managementStatus = normalize(product.productStatus);
  if (managementStatus === "sprzedany") return "Sprzedany";
  if (managementStatus === "rezerwacja") return "Rezerwacja";
  return hasValue(product.status) ? product.status : "Dostępny od ręki";
};

const isSold = (product) => ["sprzedany", "sprzedane"].includes(normalize(displayStatus(product)));

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

const readableCategory = (productCategory) => {
  const category = normalize(productCategory);

  if (category.includes("ogrod")) {
    return "Meble ogrodowe";
  }

  if (category.includes("dom") || category.includes("dekoracje") || category.includes("oswietlenie")) {
    return "Meble do domu";
  }

  return hasValue(productCategory) ? productCategory : "Produkt outletowy";
};

const productCategoryLinks = (product) => {
  const category = normalize(product.category);
  const links = category.includes("ogrod")
    ? [
      { href: "/ogrod", label: "Więcej mebli ogrodowych" },
      { href: "/meble-ogrodowe-wroclaw/", label: "Meble ogrodowe outlet Wrocław" }
    ]
    : [
      { href: "/dom", label: "Więcej mebli do domu" },
      { href: "/outlet-meblowy-wroclaw/", label: "Outlet meblowy pod Wrocławiem" }
    ];

  return `<div class="product-card-links" aria-label="Powiązane kategorie">${links
    .map((link) => `<a href="${escapeHtml(link.href)}">${escapeHtml(link.label)}</a>`)
    .join("")}</div>`;
};

const productCard = (product) => {
  const name = hasValue(product.name) ? product.name : "Produkt outletowy";
  const category = readableCategory(product.category);
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
  const productDescription = String(product.description || "Produkt dostępny do obejrzenia na miejscu.")
    .replace(/\s+/g, " ")
    .trim();
  const detailUrl = `/produkt/${encodeURIComponent(product._publicSlug)}`;

  return `
        <article class="product-card product-card-static">
          <div class="product-image">
            <a class="product-image-link" href="${escapeHtml(detailUrl)}" aria-label="Zobacz produkt: ${escapeHtml(name)}">
              <img src="${escapeHtml(imagePath(product))}" width="600" height="450" loading="lazy" alt="${escapeHtml(alt)}">
            </a>
            <span class="badge">${escapeHtml(status)}</span>
          </div>
          <div class="product-body">
            <div class="product-meta"><span>${escapeHtml(category)}</span><span>${escapeHtml(status)}</span></div>
            <h3><a class="product-title-link" href="${escapeHtml(detailUrl)}">${escapeHtml(name)}</a></h3>
            ${prices ? `<div class="price-row${outletPrice ? " has-outlet" : ""}">${prices}</div>` : '<p class="price-note">Zapytaj o cenę.</p>'}
            <div class="product-description-wrap">
              <p class="product-description">${escapeHtml(productDescription)}</p>
              <button class="description-toggle" type="button" aria-expanded="false" hidden>Więcej</button>
            </div>
            ${condition}
            ${dimensions}
            ${productCategoryLinks(product)}
            <div class="product-actions">
              <a class="btn btn-primary" href="${escapeHtml(detailUrl)}">Zobacz produkt</a>
              <a class="btn btn-outline" href="tel:+48577210777">Zapytaj o dostępność</a>
            </div>
          </div>
        </article>`;
};

const homepageProducts = () => {
  const publicProducts = products.filter(isPublic).filter((product) => !isSold(product));
  const featured = publicProducts.filter((product) => product.featured !== false);
  const selected = featured.slice(0, 6);

  if (selected.length < 6) {
    selected.push(...publicProducts
      .filter((product) => !selected.includes(product))
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
  const cleaned = next.replace(/[ \t]+$/gm, "");

  if (cleaned === html) {
    throw new Error(`Nie znaleziono siatki produktów w ${file}`);
  }

  await writeFile(fullPath, cleaned, "utf8");
}

await updatePage("index.html", homepageProducts());
await updatePage("dom.html", products.filter(isPublic).filter((product) => !isSold(product)).filter((product) => matchesCategory(product.category, "Wyposażenie domu")));
await updatePage("ogrod.html", products.filter(isPublic).filter((product) => !isSold(product)).filter((product) => matchesCategory(product.category, "Wyposażenie ogrodu")));

console.log(`Wygenerowano statyczny katalog z ${products.length} produktów.`);
