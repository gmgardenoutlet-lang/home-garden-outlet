import { cp, mkdir, readFile, rm, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const publish = path.join(root, "publish");

const rootFiles = [
  "index.html",
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
  "poradnik",
  "meble-ogrodowe-wroclaw",
  "outlet-meblowy-wroclaw",
  "hosting/getspace/stats"
];

const data = JSON.parse(await readFile(path.join(root, "data", "products.json"), "utf8"));
const products = Array.isArray(data.products) ? data.products : [];
const uploadPaths = new Set();

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
await cp(path.join(root, "data", "products.json"), path.join(publish, "data", "products.json"));

for (const relativePath of uploadPaths) {
  const source = path.join(root, relativePath);
  const destination = path.join(publish, relativePath);
  await mkdir(path.dirname(destination), { recursive: true });
  await cp(source, destination);
}

await cp(path.join(root, "hosting", "getspace", "admin"), path.join(publish, "admin"), { recursive: true });
await cp(path.join(root, "hosting", "getspace", ".htaccess"), path.join(publish, ".htaccess"));
await cp(path.join(root, "hosting", "getspace", "catalog.php"), path.join(publish, "catalog.php"));
await cp(path.join(root, "hosting", "getspace", "product.php"), path.join(publish, "product.php"));
await cp(path.join(root, "hosting", "getspace", "sitemap.php"), path.join(publish, "sitemap.php"));

const homepagePath = path.join(publish, "index.html");
const homepage = await readFile(homepagePath, "utf8");
const withoutNetlifyIdentity = homepage.replace(
  /\s*<script src="https:\/\/identity\.netlify\.com\/v1\/netlify-identity-widget\.js"><\/script>\s*/g,
  "\n"
);
const identityTokenRedirect = `  <script>
    if (/^#(?:invite_token|recovery_token|confirmation_token)=/.test(window.location.hash)) {
      window.location.replace("https://classy-lolly-b27cbc.netlify.app/" + window.location.hash);
    }
  </script>
`;
const getspaceHomepage = withoutNetlifyIdentity.replace("</head>", `${identityTokenRedirect}</head>`);
await writeFile(homepagePath, getspaceHomepage, "utf8");

console.log(`Przygotowano paczkę Getspace z ${products.length} produktami i ${uploadPaths.size} używanymi zdjęciami.`);
