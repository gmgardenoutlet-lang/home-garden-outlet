import { execFile } from "node:child_process";
import { mkdtemp, mkdir, readFile, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";

const execFileAsync = promisify(execFile);
const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const temp = await mkdtemp(path.join(os.tmpdir(), "hgo-prerender-privacy-"));
const sentinel = "SENTINEL-PRIVATE-INTERNAL-NOTE-94f3";

try {
  await mkdir(path.join(temp, "data"), { recursive: true });
  const template = '<!doctype html><html><body><div id="produkty" class="product-grid" aria-live="polite"></div></body></html>';
  await Promise.all(["index.html", "dom.html", "ogrod.html"].map((file) => writeFile(path.join(temp, file), template, "utf8")));
  await writeFile(path.join(temp, "data", "products.json"), JSON.stringify({
    products: [{
      name: "Bezpieczny produkt",
      slug: "bezpieczny-produkt",
      category: "Wyposażenie domu",
      description: "Opis publiczny.",
      outletPrice: "100 zł",
      internalNote: sentinel,
      futureAdminSecret: `${sentinel}-FUTURE`,
    }],
  }), "utf8");

  await execFileAsync(process.execPath, [path.join(repo, "scripts", "prerender-products.mjs")], {
    env: { ...process.env, SITE_ROOT: temp },
  });

  for (const file of ["index.html", "dom.html", "ogrod.html"]) {
    const html = await readFile(path.join(temp, file), "utf8");
    if (html.includes(sentinel)) {
      throw new Error(`${file} ujawnia notatkę lub przyszłe pole administracyjne.`);
    }
    if (!html.includes("Bezpieczny produkt") && file !== "ogrod.html") {
      throw new Error(`${file} nie zawiera oczekiwanej publicznej treści fixture.`);
    }
  }

  const before = await readFile(path.join(temp, "dom.html"), "utf8");
  await writeFile(path.join(temp, "data", "products.json"), JSON.stringify({ products: [] }), "utf8");
  let rejected = false;
  try {
    await execFileAsync(process.execPath, [path.join(repo, "scripts", "prerender-products.mjs")], {
      env: { ...process.env, SITE_ROOT: temp },
    });
  } catch {
    rejected = true;
  }
  if (!rejected || await readFile(path.join(temp, "dom.html"), "utf8") !== before) {
    throw new Error("Pusta migawka nie zatrzymała generatora lub nadpisała poprawny szablon.");
  }

  console.log("PASS: prerender privacy tests");
} finally {
  await rm(temp, { recursive: true, force: true });
}
