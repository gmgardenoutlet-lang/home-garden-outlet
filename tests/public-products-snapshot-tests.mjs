import { execFile } from "node:child_process";
import { createServer } from "node:http";
import { mkdtemp, readFile, rm } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";

const execFileAsync = promisify(execFile);
const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const temp = await mkdtemp(path.join(os.tmpdir(), "hgo-public-snapshot-"));
const output = path.join(temp, "products-public.json");
const privateText = "PRIVATE-NOTE-DO-NOT-COPY";
let mode = "valid";
const server = createServer((_request, response) => {
  if (mode === "error") {
    response.writeHead(503).end("unavailable");
    return;
  }
  response.setHeader("Content-Type", "application/json");
  response.end(JSON.stringify(mode === "empty" ? { products: [] } : {
    products: [
      { name: "Dostępny fotel", slug: "dostepny-fotel", status: "Dostępne", image: "/uploads/fotel.webp", internalNote: privateText },
      { name: "Sprzedany stół", slug: mode === "duplicate" ? "dostepny-fotel" : "sprzedany-stol", status: "Sprzedane", futurePrivateField: privateText },
      { name: "Figura online", slug: "figura-online", saleType: "garden_figure", shopVisible: true, shopStatus: "Dostępny" },
    ],
  }));
});

try {
  await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
  const address = server.address();
  const env = {
    ...process.env,
    HGO_PUBLIC_PRODUCTS_URL: `http://127.0.0.1:${address.port}/products-public.php`,
    HGO_PUBLIC_PRODUCTS_SNAPSHOT: output,
  };
  const run = () => execFileAsync(process.execPath, [path.join(repo, "scripts", "fetch-public-products.mjs")], { env });

  await run();
  const initial = await readFile(output, "utf8");
  const data = JSON.parse(initial);
  if (data.products.length !== 3 || initial.includes(privateText) || data.products[2].slug !== "figura-online") {
    throw new Error("Migawka nie zachowała publicznych identyfikatorów lub ujawniła pole prywatne.");
  }

  for (const failure of ["empty", "error", "duplicate"]) {
    mode = failure;
    let rejected = false;
    try { await run(); } catch { rejected = true; }
    if (!rejected || await readFile(output, "utf8") !== initial) {
      throw new Error(`Błąd ${failure} zastąpił poprawną migawkę.`);
    }
  }
  console.log("PASS: public products snapshot, privacy and failure preservation");
} finally {
  await new Promise((resolve) => server.close(resolve));
  await rm(temp, { recursive: true, force: true });
}
