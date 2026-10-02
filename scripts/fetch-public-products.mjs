import { mkdir, rename, rm, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const output = path.resolve(process.env.HGO_PUBLIC_PRODUCTS_SNAPSHOT || path.join(root, ".local-cache", "products-public.json"));
const url = process.env.HGO_PUBLIC_PRODUCTS_URL || "https://mgoutlet.pl/products-public.php";
const publicFields = new Set([
  "name", "category", "catalogPrice", "outletPrice", "grossPrice", "currency",
  "status", "condition", "dimensions", "visible", "productStatus", "image",
  "gallery", "imageAlt", "description", "longDescription", "material", "color",
  "availability", "featured", "order", "slug", "seoTitle", "seoDescription",
  "keywords", "tags", "productType", "saleType", "shopVisible", "shopStatus",
]);

const response = await fetch(url, {
  headers: { Accept: "application/json" },
  cache: "no-store",
  signal: AbortSignal.timeout(30000),
});
if (!response.ok) {
  throw new Error(`Publiczny katalog zwrócił HTTP ${response.status}; dotychczasowa migawka pozostaje bez zmian.`);
}

const body = await response.text();
if (body.length > 20_000_000) {
  throw new Error("Publiczny katalog przekroczył bezpieczny limit rozmiaru.");
}
const data = JSON.parse(body);
if (!Array.isArray(data?.products) || data.products.length === 0) {
  throw new Error("Publiczny katalog jest pusty lub niepoprawny; dotychczasowa migawka pozostaje bez zmian.");
}

const products = data.products.map((product, index) => {
  if (!product || typeof product !== "object" || Array.isArray(product)
    || typeof product.name !== "string" || !product.name.trim()
    || typeof product.slug !== "string" || !product.slug.trim()) {
    throw new Error(`Niepoprawny publiczny identyfikator produktu ${index + 1}; migawka pozostaje bez zmian.`);
  }
  return Object.fromEntries(Object.entries(product).filter(([field]) => publicFields.has(field)));
});
const slugs = products.map((product) => product.slug);
if (new Set(slugs).size !== slugs.length) {
  throw new Error("Publiczny katalog zawiera powtórzone identyfikatory; migawka pozostaje bez zmian.");
}

await mkdir(path.dirname(output), { recursive: true });
const temporary = `${output}.${process.pid}.tmp`;
try {
  await writeFile(temporary, `${JSON.stringify({ products }, null, 2)}\n`, { encoding: "utf8", flag: "wx" });
  await rename(temporary, output);
} finally {
  await rm(temporary, { force: true });
}
console.log(`Zapisano ${products.length} publicznych produktów w lokalnej migawce: ${output}`);
