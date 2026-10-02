import { cp, mkdir, readFile, rm, stat } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const publish = path.join(root, "publish");

const rootFiles = [
  "index.html",
  "404.html",
  "dom.html",
  "ogrod.html",
  "styles.css",
  "script.js",
  "robots.txt",
  "sitemap.xml",
  "site.webmanifest",
  "google311554a98a50ab80.html",
  "favicon.ico",
  "favicon-48x48.png",
  "favicon-96x96.png",
  "favicon-192x192.png",
  "favicon-512x512.png",
  "apple-touch-icon.png",
  "logo-optimized.jpg",
  "dom-optimized.jpg",
  "ogrod-optimized.jpg",
  "showroom-best-optimized.jpg",
  "show1-optimized.jpg",
  "show2-optimized.jpg",
  "show3-optimized.jpg",
  "product-table.jpeg",
  "product-sofa.jpeg",
  "product-chaise.jpeg",
  "product-chair.jpeg",
  "product-lamp.jpeg",
  "product-pots.jpeg"
];

const publicDirectories = [
  "assets",
  "poradnik",
  "meble-ogrodowe-wroclaw",
  "outlet-meblowy-wroclaw",
  "polityka-prywatnosci",
  "hosting/getspace/stats"
];

const snapshot = path.resolve(process.env.HGO_PUBLIC_PRODUCTS_SNAPSHOT || path.join(root, ".local-cache", "products-public.json"));
const data = JSON.parse(await readFile(snapshot, "utf8"));
if (!Array.isArray(data.products) || data.products.length === 0) {
  throw new Error("Brak poprawnej publicznej migawki; artefakt nie może korzystać ze starego katalogu.");
}
const products = data.products;
const uploadPaths = new Set();
const staticUploadPaths = [];

for (const product of products) {
  const gallery = Array.isArray(product.gallery) ? product.gallery : [];
  const paths = [
    product.image,
    ...gallery.map((item) => typeof item === "string" ? item : item?.image)
  ];

  for (const value of paths) {
    const cleanPath = String(value || "").replace(/^\/+/, "");
    if (cleanPath.startsWith("uploads/") && !cleanPath.includes("..")) {
      uploadPaths.add(cleanPath);
    }
  }
}

for (const relativePath of staticUploadPaths) {
  uploadPaths.add(relativePath);
}

await rm(publish, { recursive: true, force: true });
await mkdir(publish, { recursive: true });

for (const file of rootFiles) {
  await cp(path.join(root, file), path.join(publish, file));
}

for (const directory of publicDirectories) {
  const target = directory.startsWith("hosting/getspace/")
    ? directory.replace("hosting/getspace/", "")
    : directory;
  await cp(path.join(root, directory), path.join(publish, target), { recursive: true });
}

await mkdir(path.join(publish, "data"), { recursive: true });
// This copy exists only for local PHP preview and prerendering. FTP excludes it.
await cp(snapshot, path.join(publish, "data", "products.json"));
await cp(path.join(root, "data", "google-reviews.json"), path.join(publish, "data", "google-reviews.json"));
await cp(path.join(root, "data", "shipping-profiles.json"), path.join(publish, "data", "shipping-profiles.json"));

await mkdir(path.join(publish, "uploads"), { recursive: true });
await cp(path.join(root, "hosting", "getspace", "uploads", ".htaccess"), path.join(publish, "uploads", ".htaccess"));

for (const relativePath of uploadPaths) {
  const source = path.join(root, relativePath);
  const destination = path.join(publish, relativePath);
  if (!(await stat(source).catch(() => null))?.isFile()) {
    // New production images are served by Getspace and excluded from deployment.
    continue;
  }
  await mkdir(path.dirname(destination), { recursive: true });
  await cp(source, destination);
}

await cp(path.join(root, "hosting", "getspace", "admin"), path.join(publish, "admin"), { recursive: true });
await cp(path.join(root, "hosting", "getspace", "lib"), path.join(publish, "lib"), { recursive: true });
await cp(path.join(root, "hosting", "getspace", "shop-test"), path.join(publish, "shop-test"), { recursive: true });
await cp(path.join(root, "vendor"), path.join(publish, "vendor"), { recursive: true });
await cp(path.join(root, "hosting", "getspace", "vendor", ".htaccess"), path.join(publish, "vendor", ".htaccess"));
await cp(path.join(root, "hosting", "getspace", ".htaccess"), path.join(publish, ".htaccess"));
await cp(path.join(root, "hosting", "getspace", "catalog.php"), path.join(publish, "catalog.php"));
await cp(path.join(root, "hosting", "getspace", "products-public.php"), path.join(publish, "products-public.php"));
await cp(path.join(root, "hosting", "getspace", "product.php"), path.join(publish, "product.php"));
await cp(path.join(root, "hosting", "getspace", "garden.php"), path.join(publish, "garden.php"));
await cp(path.join(root, "hosting", "getspace", "home.php"), path.join(publish, "home.php"));
await cp(path.join(root, "hosting", "getspace", "homepage.php"), path.join(publish, "homepage.php"));
await cp(path.join(root, "hosting", "getspace", "sitemap.php"), path.join(publish, "sitemap.php"));

console.log(`Przygotowano paczkę Getspace z ${products.length} produktami i ${uploadPaths.size} używanymi zdjęciami.`);
