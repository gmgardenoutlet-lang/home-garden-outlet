import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = process.env.SITE_ROOT
  ? path.resolve(process.env.SITE_ROOT)
  : path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
// The source checkout contains a historical catalogue. Only an explicit build
// root may use data/products.json, copied there from the public snapshot.
const source = process.env.HGO_PUBLIC_PRODUCTS_SNAPSHOT
  ? path.resolve(process.env.HGO_PUBLIC_PRODUCTS_SNAPSHOT)
  : process.env.SITE_ROOT
    ? path.join(root, "data", "products.json")
    : path.join(root, ".local-cache", "products-public.json");
const data = JSON.parse(await readFile(source, "utf8"));
if (!Array.isArray(data.products) || data.products.length === 0) {
  throw new Error("Brak niepustej publicznej migawki; szablony pozostają bez zmian.");
}
const rawProducts = data.products;

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

// Keep the outlet catalogue separate from the active online figure shop. This
// mirrors hosting/getspace/shop-test/lib.php::shop_test_is_figure(), so older
// showroom sculptures are excluded separately by isLegacyDecorativeSculpture.
const isActiveFigureShopProduct = (product) => product.saleType === "garden_figure"
  && Boolean(product.shopVisible)
  && product.shopStatus === "Dostępny"
  && !["sprzedany", "ukryty"].includes(normalize(product.productStatus))
  && !["sprzedane", "sprzedany"].includes(normalize(product.status));

const isFigureShopProduct = (product) => product.saleType === "garden_figure";

const isLegacyDecorativeSculpture = (product) => normalize(product.productType) === "rzezba ogrodowa"
  || product._publicSlug === "figurki-ogrodowe-dekoracyjne-styl-kamienny";

const isLegacyFigureListingRecord = (product) => [
  "rzezba-ogrodowa-twarz-mala-dostepne-w-roznych-barwach",
  "rzezba-betonowa-do-ogrodu-dekoracyjna-glowa-120-cm",
  "rzezba-betonowa-do-ogrodu-z-siedziskiem-dekoracyjna-glowa-120-cm",
  "lezaca-rzezba-betonowa-do-ogrodu-dekoracyjna-twarz"
].includes(product._publicSlug);

const byCatalogOrder = (left, right) => (Number(left.order) || 0) - (Number(right.order) || 0);

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

  if (category === "figury i dekoracje ogrodowe") {
    return "Figury i dekoracje ogrodowe";
  }

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
  if (category === "figury i dekoracje ogrodowe") {
    return "";
  }
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

const isOutletHomeOrGardenProduct = (product) => {
  if (isFigureShopProduct(product)) {
    return false;
  }
  const category = String(product.category || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .trim();
  return category === "wyposazenie domu" || category === "wyposazenie ogrodu";
};

const productDeliveryInfo = (product) => {
  if (!isOutletHomeOrGardenProduct(product)) {
    return "";
  }

  return `
            <section class="product-delivery-info" aria-label="Informacja o dostawie">
              <strong>Nie możesz przyjechać? Zapytaj o dostawę</strong>
              <p>Możemy zorganizować transport na terenie całej Polski. Przed zakupem możemy również przesłać dodatkowe zdjęcia konkretnego egzemplarza i pokazać jego rzeczywisty stan. Koszt transportu wyceniamy indywidualnie.</p>
              <div class="product-delivery-actions">
                <span class="btn btn-whatsapp is-disabled" aria-disabled="true" title="WhatsApp będzie dostępny po potwierdzeniu numeru firmowego">Napisz na WhatsApp</span>
                <a href="/poradnik/zakup-produktu-outletowego-z-dostawa/">Jak wygląda zakup z dostawą?</a>
              </div>
            </section>`;
};

const listingStateNotes = (product, review = false) => {
  let longDescription = String(product.longDescription || '');
  if (product.slug === 'wanna-hawes-hydromasaz-led-czarna') {
    longDescription = longDescription
      .replaceAll(' Te informacje powinny pozostać widoczne w opisie dla klienta.', '')
      .replaceAll('- stan: outletowy, z widocznymi defektami', '');
  }
  // Parse structure first. A match never promotes an entire list or paragraph.
  // Keep unclear condition-bearing units intact; review metadata is never rendered.
  const canonical = text => String(text).toLowerCase().replace(/\s+/gu, ' ').replace(/[.!?]+$/u, '').trim();
  const technicalKey = /^(?:model|typ|kształt|kolor(?: .*)?|materiał(?:y)?(?: .*)?|wykończenie(?: .*)?|waga(?: .*)?|maksymalne obciążenie(?: .*)?|wymiary|długość(?: .*)?|szerokość(?: .*)?|wysokość(?: .*)?|głębokość(?: .*)?|montaż(?: .*)?|w zestawie(?: .*)?|liczba(?: .*)?|miejsce(?: .*)?|pojemność|napięcie|pobór mocy|ogrzewanie|pompa(?: .*)?|poziom hałasu|dysze(?: .*)?|numer artykułu(?: .*)?|tapicerka|skład(?: .*)?|wypełnienie|konstrukcja(?: .*)?|nóżki|podłokietniki|oparcie|wzór|gęstość(?: .*)?|mechanizm(?: .*)?|funkcja(?: .*)?|cechy dodatkowe|zawartość zestawu|odporność|przeznaczenie|styl|forma|orientacja|wykonanie|sposób zawieszenia|elementy ozdobne|szuflada|otwarte półki|otwarta półka(?: .*)?|obrót|regulacja(?: .*)?|podstawa(?: .*)?|automatyczna blokada(?: .*)?|sztaplowane|zestaw|element \d+)$/iu;
  const stateKey = /^(?:stan|wady|wada|defekt|uszkodzenia|braki|uwaga o stanie|użytkowanie|historia egzemplarza)$/iu;
  const damage = /uszkod|defekt|ubyt|pękni|pekni|odprysk|wgniec|otarci|zarys|przybrudz|zabrudz|przebarwi|plam|urwan|złaman|wyłaman|wybrak|niekompletn|niespraw|nie działa|nie dziala|\bwad[ayę]\b/iu;
  const missing = /brakuje|brakują|brakujace|brakujące|\bbrak\s+(?!wad\b|uszkodzeń\b|defektów\b)\S+/iu;
  const ambiguousWord = /\brys(?:y|ami|ach)\b|przetar|napraw|\bwad[ayę]\b/iu;
  const conditionEvidence = /ślady|śladów|slady|sladow|(?:Oferowan\S*|Ten egzemplarz|Jest to egzemplarz|Produkt|Komplet|Egzemplarz)[^.!?]*(?:outletow|ekspozycyjn)|różnice w fakturze, kolorze lub kształcie|(?:elementy|egzemplarz|produkt|części|część|oświetlenie|LED)[^.!?]*(?:są nowe|jest now[yae]|są now[ey])|(?:egzemplarz|komplet)[^.!?]*zmontowan/iu;
  const continuation = /^(?:Na (?:zdjęciach|przesłanych zdjęciach)|Zdjęcia przedstawiają|Możliwe są|Widoczne|W przypadku weluru|Welur może|Część tych|Wady |Brak ten|Nie (?:wpływa|przeszkadza)|Defekt został|Przed zakupem|Jest to naturalna cecha)/iu;
  const pureInstruction = /^(?:(?:Krzesła|Stół|Fotel|Produkt|Model|Sofa|Mebel|Zestaw) (?:są|jest) częściowo zmontowan|(?:Według (?:danych|producenta|strony źródłowej)|Skład tapicerki|Montaż |Wymaga (?:kompletnego )?montażu))/iu;
  const manufacturerProtection = /^Model został zaprojektowany do użytkowania na zewnątrz, a producent deklaruje odporność na wodę, zabrudzenia, promieniowanie UV oraz wiatr\.$/iu;
  const promotional = /^(?:To (?:dobry wybór|propozycja)|(?:Ławka|Model|Dekoracja|Donica|Figura|Stół|Fotel) (?:dobrze |może |sprawdzi)|Dzięki temu|Stonowane wykończenie|Kontrastowe wykończenie)/iu;
  const generic = /^(?:outletowy|outletowa|outletowe|outletowy\s*\/\s*ekspozycyjny|outletowa\s*\/\s*ekspozycyjna|ekspozycyjny)$/iu;
  const existing = [String(product.description || ''), String(product.condition || '')];
  const selected = [], ambiguous = [], excluded = [];
  let technicalSection = false;
  longDescription.replace(/\r\n?/gu, '\n').trim().split(/\n\s*\n/u).forEach((paragraph, paragraphIndex) => {
    let context = false;
    paragraph.split('\n').forEach((line, lineIndex) => {
      const trimmed = line.trim().replace(/^[-•]\s*/u, '');
      if (!trimmed) return;
      if (/^(?:Dodatkowe informacje|Najważniejsze cechy|W zestawie)\s*:$/iu.test(trimmed)) {
        technicalSection = true;
        return;
      }
      // Semicolons and sentence boundaries are safe divisions; never split damage lists on commas.
      trimmed.split(/;\s*|(?<=[.!?])\s+(?=[A-ZĄĆĘŁŃÓŚŹŻ])/u).forEach(unit => {
        const text = unit.trim();
        if (!text) return;
        const pair = text.match(/^([^:]{1,90}):\s*(.*)$/u);
        const key = pair ? pair[1].trim() : '';
        const value = pair ? pair[2].trim() : text;
        const technical = !!pair && technicalKey.test(key);
        const state = !!pair && stateKey.test(key);
        // A named colour is not a broken part (e.g. "złamana biel").
        const scan = text.replace(/złaman(?:a|ej|ą|e)\s+biel(?:i|ą)?/giu, '');
        const hard = damage.test(scan) || missing.test(scan);
        const evidence = hard || conditionEvidence.test(text) || state;
        const unclear = ambiguousWord.test(text);
        const physicalDamage = missing.test(scan) || damage.test(scan.replace(/zarys\S*|\brysy\b/giu, ''));
        // These are positive descriptions of the design, not claims about a damaged specimen.
        const specimenClaim = /widoczn[^.!?]*(?:rysy|zarys|przetar)|(?:ma|posiada|występują|są)\s+(?:rysy|zarysowania|przetarcia)/iu.test(text);
        const design = !physicalDamage && !specimenClaim && ((/^(?:.*(?:kolorystyka|wykończenie|kolorystyce)|Powierzchni[aeę]|Brązow[ae]|Szaro-czarn[ae]|Szare|Grafitowa|Ciemnoszaro-beżowe)/iu.test(text) && /przetar|zarys/iu.test(text) && /podkreśl|nadaj|zdobi|wykończ/iu.test(text)) || (/\brysy\s+(?:kobiecej\s+)?(?:twarzy|pyska)\b|spokojne rysy|zarysowane usta/iu.test(text) && /łagodne|delikatnie|harmonijn|starannie|dekoracyjn|spokojne|wyraziste|artystyczn|rzeźbiarsk|podkreśl|nadaj/iu.test(text)) || /chronią?[^.!?]*przed zarysowaniem\.?$/iu.test(text));
        if (design || manufacturerProtection.test(text) || (technical && !evidence && !unclear && !/\bnow[yae]\b/iu.test(value)) || (pureInstruction.test(text) && !evidence && !unclear)) {
          excluded.push({text, reason: design ? 'design' : 'technical'});
          return;
        }
        if (!evidence && !unclear && !(context && continuation.test(text))) {
          if (context && !technicalSection && !promotional.test(text)) {
            ambiguous.push({text, reason: 'Unclassified continuation of a condition statement'});
          } else {
            excluded.push({text, reason: 'Not specimen-condition information'});
            return;
          }
        }
        // Do not discard a protection/design sentence that also reports real damage.
        if (technical && (evidence || unclear || /\bnow[yae]\b/iu.test(value))) ambiguous.push({text, reason: 'Technical field also carries condition information; retained intact'});
        else if (unclear && !evidence) ambiguous.push({text, reason: 'Ambiguous condition wording; retained intact'});
        const normal = canonical(value);
        const present = existing.concat(selected.map(x => x.text));
        const exactRepeat = present.some(x => x.split(/\n+|;\s*|(?<=[.!?])\s+(?=[A-ZĄĆĘŁŃÓŚŹŻ])/u).some(part => canonical(part.replace(/^[-•]\s*/u, '')) === canonical(text)));
        const genericRepeat = generic.test(value) && (present.some(x => canonical(x) === normal) || (/outletow/iu.test(value) && /ekspozycyjn/iu.test(value) && present.some(x => /outletow/iu.test(x) && /ekspozycyjn/iu.test(x))));
        const proseRepeat = /^(?:Produkt (?:jest )?outletowy|Jest to egzemplarz outletowy)\.?$/iu.test(text) && existing.some(x => canonical(x) === 'outletowy');
        // This explicit same-module fact repeats the full, already selected statement.
        const moduleClaim = state && value.match(/^(?:w jednym module|jeden z modułów) brakuje (.+?), co (nie przeszkadza w użytkowaniu)\.?$/iu);
        const statementParagraphs = [...new Set(selected.map(x => x.group.split(':')[0]))].map(group => selected.filter(x => x.group.startsWith(group + ':')).map(x => x.text).join(' '));
        const moduleRepeat = moduleClaim && existing.concat(statementParagraphs).some(x => /jeden z modułów|w jednym module/iu.test(x) && canonical(x).includes(canonical('brakuje ' + moduleClaim[1])) && canonical(x).includes(canonical(moduleClaim[2])));
        if (exactRepeat || genericRepeat || proseRepeat || moduleRepeat) { excluded.push({text, reason: 'Duplicate already visible on the card'}); context = true; return; }
        selected.push({text, group: `${paragraphIndex}:${lineIndex}`, bullet: /^[-•]\s*/u.test(line.trim())});
        context = true;
      });
    });
  });
  const groups = [];
  selected.forEach(item => {
    if (groups.at(-1)?.group === item.group) groups.at(-1).text += ' ' + item.text;
    else groups.push({...item});
  });
  const text = groups.map(x => (x.bullet ? '- ' : '') + x.text).join('\n\n');
  return review === true ? {text, ambiguous, excluded} : text;
};

const productCard = (product) => {
  const name = hasValue(product.name) ? product.name : "Produkt outletowy";
  const category = isFigureShopProduct(product) ? "Figury i dekoracje ogrodowe" : readableCategory(product.category);
  const status = displayStatus(product);
  const images = [...new Set([product.image, ...(Array.isArray(product.gallery) ? product.gallery.map(item => typeof item === 'string' ? item : item?.image) : [])]
    .filter(hasValue).map(value => /^https?:\/\//i.test(String(value)) ? String(value) : imagePath({ image: value })))];
  const galleryData = escapeHtml(JSON.stringify(images.length ? images : [imagePath(product)]));
  const badgeClass = status === 'Rezerwacja' ? 'reserved' : ['Sprzedane', 'Sprzedany'].includes(status) ? 'sold' : '';
  const isOnlineFigure = isActiveFigureShopProduct(product);
  const alt = hasValue(product.imageAlt)
    ? product.imageAlt
    : `${name} - ${category}, Home & Garden Outlet Kębłowice pod Wrocławiem`;
  const catalogPrice = hasValue(product.catalogPrice) ? product.catalogPrice : "";
  const outletPriceValue = isFigureShopProduct(product) ? product.grossPrice : product.outletPrice;
  const outletPrice = hasValue(outletPriceValue) ? outletPriceValue : "";
  const catalogValue = parsePrice(catalogPrice);
  const outletValue = parsePrice(outletPrice);
  const saving = catalogValue && outletValue && catalogValue > outletValue
    ? Math.round(catalogValue - outletValue)
    : null;
  const prices = [
    catalogPrice
      ? `<span class="catalog-price${outletPrice ? " old-price" : ""}">Cena katalogowa: ${escapeHtml(catalogPrice)}</span>`
      : "",
    outletPrice ? `<span class="outlet-price">${isFigureShopProduct(product) ? "Cena" : "Cena outletowa"}: ${escapeHtml(outletPrice)}</span>` : "",
    saving ? `<span class="saving-badge">Oszczędzasz: ${saving} zł</span>` : ""
  ].filter(Boolean).join("");
  const condition = !isFigureShopProduct(product) && hasValue(product.condition)
    ? `<p class="product-condition">Stan: ${escapeHtml(product.condition)}</p>`
    : "";
  const stateNotes = listingStateNotes(product);
  const productDescription = String(product.description || "Produkt dostępny do obejrzenia na miejscu.")
    .replace(/\s+/g, " ")
    .trim();
  const detailUrl = isFigureShopProduct(product)
    ? `/sklep/figury-ogrodowe/produkt/${encodeURIComponent(slugify(hasValue(product.slug) ? product.slug : product.name))}`
    : `/produkt/${encodeURIComponent(product._publicSlug)}`;

  return `
        <article class="product-card product-card-static product-card-compact">
          <div class="product-image">
            <a class="product-image-link" href="${escapeHtml(detailUrl)}" data-gallery="${galleryData}" data-gallery-name="${escapeHtml(name)}" data-gallery-alt="${escapeHtml(alt)}" aria-label="Zobacz produkt: ${escapeHtml(name)}">
              <img src="${escapeHtml(imagePath(product))}" width="600" height="450" loading="lazy" alt="${escapeHtml(alt)}">
            </a>
            <span class="badge ${badgeClass}">${escapeHtml(status)}</span>
            ${images.length > 1 ? `<span class="gallery-count">${images.length} zdjęć</span>` : ''}
          </div>
          <div class="product-body">
            <div class="product-meta"><span>${escapeHtml(category)}</span><span>${isOnlineFigure ? "Zakup online" : "Dostępny lokalnie"}</span></div>
            <h3><a class="product-title-link" href="${escapeHtml(detailUrl)}">${escapeHtml(name)}</a></h3>
            ${prices ? `<div class="price-row${outletPrice ? " has-outlet" : ""}">${prices}</div>` : `<p class="price-note">${catalogPrice ? "Zapytaj o cenę outletową." : "Zapytaj o cenę."}</p>`}
            <div class="product-actions">
              <a class="btn btn-primary" href="${escapeHtml(detailUrl)}">${isOnlineFigure ? "Kup online" : "Zobacz produkt"}</a>
              <a class="btn btn-outline" href="tel:+48577210777">Zapytaj o dostępność</a>
            </div>
            <div class="product-description-wrap">
              <p class="product-description">${escapeHtml(productDescription)}</p>
            </div>
            ${condition}
            ${stateNotes ? `<p class="product-state-notes">${escapeHtml(stateNotes)}</p>` : ''}
          </div>
        </article>`;
};

const homepageProducts = () => {
  const publicProducts = products.filter(isPublic).filter((product) => !isSold(product) && !isLegacyFigureListingRecord(product));
  const featured = publicProducts.filter((product) => product.featured !== false);
  const figure = featured.find(isActiveFigureShopProduct);
  const selected = figure ? [figure, ...featured.filter((product) => !isFigureShopProduct(product)).slice(0, 5)] : [];
  selected.push(...featured.filter((product) => !selected.includes(product)).slice(0, 6 - selected.length));

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
  const hasProductGrid = markerPattern.test(html) || emptyPattern.test(html);
  if (!hasProductGrid) {
    throw new Error(`Nie znaleziono siatki produktów w ${file}`);
  }

  const next = html.replace(markerPattern, grid).replace(emptyPattern, grid);
  const cleaned = next.replace(/[ \t]+$/gm, "");

  if (cleaned === html) {
    return;
  }

  await writeFile(fullPath, cleaned, "utf8");
}

await updatePage("index.html", homepageProducts());
await updatePage("dom.html", products.filter(isPublic).filter((product) => !isSold(product)).filter((product) => matchesCategory(product.category, "Wyposażenie domu")).sort(byCatalogOrder));
await updatePage("ogrod.html", products
  .filter(isPublic)
  .filter((product) => !isSold(product))
  .filter((product) => matchesCategory(product.category, "Wyposażenie ogrodu"))
  .filter((product) => !isActiveFigureShopProduct(product) && !isLegacyDecorativeSculpture(product))
  .sort(byCatalogOrder));

console.log(`Wygenerowano statyczny katalog z ${products.length} produktów.`);
