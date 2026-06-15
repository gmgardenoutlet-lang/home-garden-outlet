#!/usr/bin/env node

import { copyFile, mkdir, readFile, writeFile } from "node:fs/promises";
import { basename, dirname, extname, join, resolve } from "node:path";
import { pathToFileURL } from "node:url";

const EMPTY_MARKERS = new Set(["", "brak", "do uzupełnienia", "xxx", "null", "undefined"]);
const PROTECTED_FIELDS = ["name", "catalogPrice", "outletPrice", "image"];
const DEFAULT_INPUT = "data/products.json";
const DEFAULT_OUTPUT_DIR = "migration-output";

function parseArgs(argv) {
  const options = {
    input: DEFAULT_INPUT,
    output: "",
    reportDir: DEFAULT_OUTPUT_DIR,
    apply: false,
  };

  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];
    if (argument === "--apply") {
      options.apply = true;
    } else if (argument === "--input") {
      options.input = argv[++index] || "";
    } else if (argument === "--output") {
      options.output = argv[++index] || "";
    } else if (argument === "--report-dir") {
      options.reportDir = argv[++index] || "";
    } else if (argument === "--help" || argument === "-h") {
      printHelp();
      process.exit(0);
    } else {
      throw new Error(`Nieznany argument: ${argument}`);
    }
  }

  if (!options.input) {
    throw new Error("Podaj plik wejściowy przez --input.");
  }
  if (options.apply && !options.output) {
    throw new Error("Tryb --apply wymaga osobnego pliku docelowego przez --output.");
  }
  return options;
}

function printHelp() {
  console.log(`
Bezpieczne uzupełnianie starszych produktów.

Dry-run (domyślny, nie zmienia katalogu):
  node scripts/enrich-products.mjs --input migration-backups/products-live.json

Zapis zatwierdzonej propozycji do osobnego pliku:
  node scripts/enrich-products.mjs --apply --input migration-backups/products-live.json --output migration-output/products-enriched.json

Opcje:
  --input <plik>       katalog źródłowy
  --output <plik>      osobny plik wynikowy; wymagany z --apply
  --report-dir <dir>   katalog raportów, domyślnie migration-output
  --apply              zapisuje wynik do --output; nigdy nie nadpisuje wejścia
`);
}

function hasValue(value) {
  if (value === null || value === undefined) {
    return false;
  }
  if (Array.isArray(value)) {
    return value.length > 0;
  }
  if (typeof value === "boolean" || typeof value === "number") {
    return true;
  }
  return !EMPTY_MARKERS.has(String(value).trim().toLocaleLowerCase("pl-PL"));
}

function normalized(value) {
  return String(value ?? "").trim().toLocaleLowerCase("pl-PL");
}

function slugify(value) {
  return String(value ?? "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase("pl-PL")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "") || "produkt";
}

function uniqueSlug(requested, usedSlugs) {
  const base = slugify(requested);
  let candidate = base;
  let suffix = 2;
  while (usedSlugs.has(candidate)) {
    candidate = `${base}-${suffix}`;
    suffix += 1;
  }
  usedSlugs.add(candidate);
  return candidate;
}

function inferProductType(name, category) {
  const text = normalized(name);
  const categoryText = normalized(category);
  if (text.startsWith("zestaw ") && categoryText.includes("ogrodu")) {
    return "zestaw ogrodowy";
  }
  const rules = [
    ["zestaw ogrodowy", ["zestaw ogrodowy", "zestaw mebli ogrodowych", "zestaw wypoczynkowy ogrodowy"]],
    ["zestaw wypoczynkowy", ["zestaw wypoczynkowy"]],
    ["zestaw modułowy", ["zestaw modułowy"]],
    ["zestaw koszy", ["zestaw 3 koszy", "zestaw 5 koszy", "zestaw koszy"]],
    ["rzeźba ogrodowa", ["rzeźba", "rzezba", "dekoracyjna głowa", "dekoracyjna twarz"]],
    ["sofa rozkładana", ["sofa rozkładana", "kanapa rozkładana"]],
    ["sofa", ["sofa", "kanapa"]],
    ["narożnik", ["narożnik", "naroznik"]],
    ["szezlong", ["szezlong"]],
    ["fotel ogrodowy", ["fotel ogrodowy", "fotele ogrodowe"]],
    ["fotel", ["fotel"]],
    ["ławka ogrodowa", ["ławka ogrodowa", "lawka ogrodowa"]],
    ["ławka ze schowkiem", ["ławka-schowek", "ławka ze schowkiem"]],
    ["ławka", ["ławka", "lawka"]],
    ["leżak", ["leżak", "lezak"]],
    ["stolik ogrodowy", ["stolik ogrodowy"]],
    ["stół ogrodowy", ["stół ogrodowy", "stol ogrodowy"]],
    ["stolik", ["stolik"]],
    ["stół", ["stół ", "stol "]],
    ["krzesło", ["krzesło", "krzesla", "krzesła"]],
    ["lampa", ["lampa"]],
    ["lustro", ["lustro"]],
    ["wanna", ["wanna"]],
    ["łóżko", ["łóżko", "lozko"]],
    ["kosz", ["kosz", "koszy"]],
    ["donica", ["donica", "donice"]],
    ["szafka", ["szafka"]],
    ["dekoracja", ["dekoracja"]],
    ["stojak na kwiaty", ["stojak na kwiaty"]],
    ["wózek kuchenny", ["wózek kuchenny", "wozek kuchenny"]],
    ["stołek barowy", ["stołek barowy", "stołków barowych"]],
    ["palenisko ogrodowe", ["palenisko"]],
    ["dywan", ["dywan"]],
    ["podpórka na książki", ["podpórki na książki", "podporki na ksiazki"]],
    ["fontanna ogrodowa", ["fontanna"]],
  ];

  for (const [type, phrases] of rules) {
    if (phrases.some((phrase) => text.includes(phrase))) {
      return type;
    }
  }
  return "";
}

function isSold(product) {
  return [product.status, product.productStatus]
    .map(normalized)
    .some((status) => ["sprzedany", "sprzedane"].includes(status));
}

function isHidden(product) {
  return normalized(product.productStatus) === "ukryty" || product.visible === false;
}

function buildImageAlt(product) {
  return `${String(product.name).trim()} dostępny w Home & Garden Outlet pod Wrocławiem`;
}

function buildSeoTitle(product) {
  const suffix = " | Home & Garden Outlet";
  const maximumNameLength = Math.max(20, 60 - suffix.length);
  let name = String(product.name).trim();
  if (name.length > maximumNameLength) {
    name = `${name.slice(0, maximumNameLength - 1).trimEnd()}…`;
  }
  return `${name}${suffix}`;
}

function buildSeoDescription(product) {
  const name = String(product.name).trim();
  const category = hasValue(product.category) ? String(product.category).trim() : "Produkt outletowy";
  const ending = " Dostępność ograniczona.";
  const base = `${name} — ${category}. Dostępny w Home & Garden Outlet w Kębłowicach pod Wrocławiem.`;
  const limit = 160;
  if (`${base}${ending}`.length <= limit) {
    return `${base}${ending}`;
  }
  const available = limit - ending.length - 1;
  return `${base.slice(0, available).trimEnd()}…${ending}`;
}

function csvCell(value) {
  const text = typeof value === "string" ? value : JSON.stringify(value);
  return `"${String(text ?? "").replaceAll('"', '""')}"`;
}

function addMissing(product, field, value, changes, reason, confidence = "wysoka") {
  if (Object.prototype.hasOwnProperty.call(product, field) && hasValue(product[field])) {
    return false;
  }
  if (value === "" || value === undefined) {
    return false;
  }

  const before = Object.prototype.hasOwnProperty.call(product, field) ? product[field] : undefined;
  product[field] = value;
  changes.push({ field, before, after: value, reason, confidence });
  return true;
}

function enrichProduct(sourceProduct, usedSlugs, index) {
  const product = structuredClone(sourceProduct);
  const changes = [];
  const manualReview = [];
  const name = hasValue(product.name) ? String(product.name).trim() : "";

  if (!name) {
    manualReview.push("Brak nazwy produktu — pominięto automatyczne pola tekstowe.");
    return { product, changes, manualReview };
  }

  const inferredType = inferProductType(name, product.category);
  if (inferredType) {
    addMissing(product, "productType", inferredType, changes, "Typ wywnioskowany zachowawczo z nazwy produktu.");
  } else if (!hasValue(product.productType)) {
    manualReview.push("Nie udało się bezpiecznie ustalić krótkiego typu produktu.");
  }

  if (!Object.prototype.hasOwnProperty.call(product, "gallery")) {
    product.gallery = [];
    changes.push({
      field: "gallery",
      before: undefined,
      after: [],
      reason: "Normalizacja formatu bez dodawania zdjęć.",
      confidence: "wysoka",
    });
  }

  addMissing(product, "imageAlt", buildImageAlt(product), changes, "Naturalny opis zdjęcia na podstawie nazwy.");
  addMissing(product, "seoTitle", buildSeoTitle(product), changes, "Tytuł SEO na podstawie nazwy i marki.");
  addMissing(product, "seoDescription", buildSeoDescription(product), changes, "Opis SEO na podstawie nazwy, kategorii i lokalizacji.");

  if (hasValue(product.slug)) {
    const existingSlug = slugify(product.slug);
    if (usedSlugs.has(existingSlug)) {
      manualReview.push(`Istniejący slug „${product.slug}” jest duplikatem.`);
    } else {
      usedSlugs.add(existingSlug);
    }
  } else {
    addMissing(product, "slug", uniqueSlug(name, usedSlugs), changes, "Unikalny slug wygenerowany z nazwy produktu.");
  }

  addMissing(product, "currency", "PLN", changes, "Domyślna waluta katalogu.");
  addMissing(product, "featured", false, changes, "Starszy produkt domyślnie nie jest polecany.");
  addMissing(product, "visible", !isSold(product) && !isHidden(product), changes, "Widoczność ustalona na podstawie istniejącego statusu.");
  addMissing(product, "status", isSold(product) ? "Sprzedane" : "Dostępne", changes, "Status ustalony wyłącznie, gdy wcześniej go brakowało.");
  addMissing(product, "productStatus", isSold(product) ? "Sprzedany" : isHidden(product) ? "Ukryty" : "Aktywny", changes, "Status panelu ustalony wyłącznie, gdy wcześniej go brakowało.");
  addMissing(product, "order", 0, changes, "Domyślna kolejność.");

  for (const field of ["catalogPrice", "outletPrice", "dimensions", "material", "color", "condition", "longDescription"]) {
    if (!hasValue(product[field])) {
      manualReview.push(`Pole „${field}” pozostawiono bez zmian — wymaga danych właściciela.`);
    }
  }

  return { product, changes, manualReview, index };
}

function assertCatalog(catalog, sourceLabel) {
  if (!catalog || !Array.isArray(catalog.products)) {
    throw new Error(`${sourceLabel}: oczekiwano obiektu z tablicą products.`);
  }
  if (catalog.products.length === 0) {
    throw new Error(`${sourceLabel}: katalog nie zawiera produktów.`);
  }
  for (const [index, product] of catalog.products.entries()) {
    if (!product || typeof product !== "object" || Array.isArray(product)) {
      throw new Error(`${sourceLabel}: produkt nr ${index + 1} nie jest obiektem.`);
    }
  }
}

function assertProtectedData(sourceProducts, enrichedProducts) {
  if (sourceProducts.length !== enrichedProducts.length) {
    throw new Error("Zmieniono liczbę produktów — migracja przerwana.");
  }
  for (let index = 0; index < sourceProducts.length; index += 1) {
    for (const field of PROTECTED_FIELDS) {
      if (JSON.stringify(sourceProducts[index][field]) !== JSON.stringify(enrichedProducts[index][field])) {
        throw new Error(`Chronione pole „${field}” produktu nr ${index + 1} uległo zmianie — migracja przerwana.`);
      }
    }
  }
}

function timestamp() {
  return new Date().toISOString().replace(/[:.]/g, "-");
}

export async function runMigration(options) {
  const inputPath = resolve(options.input);
  const reportDir = resolve(options.reportDir);
  const runStamp = timestamp();

  const raw = await readFile(inputPath, "utf8");
  const sourceCatalog = JSON.parse(raw);
  assertCatalog(sourceCatalog, "Plik wejściowy");

  const usedSlugs = new Set();
  const results = sourceCatalog.products.map((product, index) => enrichProduct(product, usedSlugs, index));
  const enrichedCatalog = { ...sourceCatalog, products: results.map((result) => result.product) };
  assertCatalog(enrichedCatalog, "Wynik migracji");
  assertProtectedData(sourceCatalog.products, enrichedCatalog.products);

  await mkdir(reportDir, { recursive: true });
  const reportBase = `enrich-products-${runStamp}`;
  const reportJsonPath = join(reportDir, `${reportBase}.json`);
  const reportCsvPath = join(reportDir, `${reportBase}.csv`);
  const proposedPath = join(reportDir, `${reportBase}-proposed-products.json`);
  const changedProducts = results.filter((result) => result.changes.length > 0);
  const totalChanges = results.reduce((sum, result) => sum + result.changes.length, 0);

  const report = {
    mode: options.apply ? "apply-to-separate-output" : "dry-run",
    generatedAt: new Date().toISOString(),
    input: inputPath,
    inputProductCount: sourceCatalog.products.length,
    outputProductCount: enrichedCatalog.products.length,
    changedProductCount: changedProducts.length,
    totalProposedChanges: totalChanges,
    protectedFieldsVerified: PROTECTED_FIELDS,
    products: results.map((result, index) => ({
      index,
      name: sourceCatalog.products[index].name ?? "",
      changes: result.changes,
      manualReview: result.manualReview,
    })),
  };

  const csvRows = [
    ["index", "name", "field", "before", "after", "reason", "confidence"].map(csvCell).join(","),
  ];
  for (const [index, result] of results.entries()) {
    for (const change of result.changes) {
      csvRows.push([
        index,
        sourceCatalog.products[index].name ?? "",
        change.field,
        change.before,
        change.after,
        change.reason,
        change.confidence,
      ].map(csvCell).join(","));
    }
  }

  await writeFile(reportJsonPath, `${JSON.stringify(report, null, 2)}\n`, "utf8");
  await writeFile(reportCsvPath, `${csvRows.join("\n")}\n`, "utf8");
  await writeFile(proposedPath, `${JSON.stringify(enrichedCatalog, null, 2)}\n`, "utf8");

  let outputPath = "";
  let backupPath = "";
  if (options.apply) {
    outputPath = resolve(options.output);
    await mkdir(dirname(outputPath), { recursive: true });
    backupPath = join(dirname(outputPath), `${basename(outputPath, extname(outputPath))}-source-backup-${runStamp}${extname(outputPath) || ".json"}`);
    await copyFile(inputPath, backupPath);
    await writeFile(outputPath, `${JSON.stringify(enrichedCatalog, null, 2)}\n`, "utf8");
  }

  const summary = {
    mode: report.mode,
    input: inputPath,
    products: sourceCatalog.products.length,
    changedProducts: changedProducts.length,
    proposedChanges: totalChanges,
    protectedFieldsVerified: PROTECTED_FIELDS,
    reportJsonPath,
    reportCsvPath,
    proposedPath,
    outputPath,
    backupPath,
  };
  console.log(JSON.stringify(summary, null, 2));
  return summary;
}

if (typeof process !== "undefined" && Array.isArray(process.argv)) {
  const executedFile = process.argv[1] ? pathToFileURL(resolve(process.argv[1])).href : "";
  if (executedFile === import.meta.url) {
    const options = parseArgs(process.argv.slice(2));
    runMigration(options).catch((error) => {
      console.error(`BŁĄD: ${error.message}`);
      process.exitCode = 1;
    });
  }
}
