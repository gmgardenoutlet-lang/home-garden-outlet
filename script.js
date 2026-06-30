const fallbackProducts = [
  {
    name: "Stół z krzesłami do ogrodu i jadalni",
    category: "Wyposażenie ogrodu",
    catalogPrice: "1350 zł",
    outletPrice: "",
    status: "Nowość",
    image: "product-table.jpeg",
    description: "Realny zestaw z ekspozycji w showroomie. Jasny blat, wygodne krzesła i naturalny klimat do domu lub ogrodu.",
    dimensions: "",
    featured: true,
    order: 10
  },
  {
    name: "Narożnik wypoczynkowy",
    category: "Wyposażenie domu",
    catalogPrice: "2300 zł",
    outletPrice: "",
    status: "Ostatnia sztuka",
    image: "product-sofa.jpeg",
    description: "Jasny narożnik z ekspozycji. Zdjęcie wykonane na miejscu, bez sztucznych wizualizacji.",
    dimensions: "",
    featured: true,
    order: 20
  },
  {
    name: "Zestaw foteli z podnóżkami",
    category: "Wyposażenie ogrodu",
    catalogPrice: "1185 zł / komplet",
    outletPrice: "",
    status: "Rezerwacja",
    image: "product-chaise.jpeg",
    description: "Drewniane fotele wypoczynkowe z podnóżkami. Dobra propozycja na taras, werandę lub ogród.",
    dimensions: "",
    featured: true,
    order: 30
  },
  {
    name: "Zestaw wypoczynkowy z fotelami",
    category: "Wyposażenie ogrodu",
    catalogPrice: "1350 zł",
    outletPrice: "",
    status: "Nowość",
    image: "product-chair.jpeg",
    description: "Komplet wypoczynkowy z fotelami, stolikiem i sofą. Produkt dostępny do obejrzenia na miejscu.",
    dimensions: "",
    featured: true,
    order: 40
  },
  {
    name: "Leżaki ogrodowe",
    category: "Wyposażenie ogrodu",
    catalogPrice: "1185 zł / komplet",
    outletPrice: "",
    status: "Nowość",
    image: "product-lamp.jpeg",
    description: "Leżaki z ekspozycji outletowej. Zdjęcie pokazuje realny stan i ustawienie produktu w showroomie.",
    dimensions: "",
    featured: true,
    order: 50
  },
  {
    name: "Donice i dekoracje do ogrodu",
    category: "Wyposażenie ogrodu",
    catalogPrice: "ceny od 80 zł",
    outletPrice: "",
    status: "Ostatnia sztuka",
    image: "product-pots.jpeg",
    description: "Donice i dodatki ogrodowe dostępne w outlecie. Dobry przykład żywego katalogu z prawdziwymi zdjęciami.",
    dimensions: "",
    featured: true,
    order: 60
  }
];

const statusClasses = {
  "Nowość": "",
  Nowosc: "",
  "Dostępny od ręki": "",
  "Zapytaj o dostępność": "",
  "Ostatnia sztuka": "",
  Rezerwacja: "reserved",
  Sprzedane: "sold",
  Sprzedany: "sold"
};

const productGrid = document.querySelector("#produkty");
const filterButtons = document.querySelectorAll(".filter-btn");
const productSearchInput = document.querySelector("[data-product-search]");
const productFilterInputs = document.querySelectorAll("[data-product-filter]");
const productCount = document.querySelector("[data-product-count]");
const productEmpty = document.querySelector("[data-product-empty]");
const googleReviewsGrid = document.querySelector("[data-google-reviews]");
const googleReviewTitle = document.querySelector("[data-google-review-title]");
const googleReviewSummary = document.querySelector("[data-google-review-summary]");
const googleReviewAddLinks = document.querySelectorAll("[data-google-review-add]");
const menuToggle = document.querySelector(".menu-toggle");
const mainMenu = document.querySelector("#main-menu");
const pageCategory = document.body.dataset.category || "";
const isCategoryPage = Boolean(pageCategory);
const homepageProductLimit = 6;
const statsEndpoint = "/stats/track.php";
// Tutaj należy wkleić prawdziwy link do opinii wygenerowany w Google Business Profile.
const GOOGLE_REVIEW_URL = "https://g.page/r/CbBzpFh7V9LBEBM/review";
const trackedEvents = new Set([
  "page_view",
  "product_view",
  "call_click",
  "sms_click",
  "navigation_click",
  "facebook_click",
  "instagram_click",
  "product_question_click"
]);
let products = assignProductSlugs(fallbackProducts);

function initializeGoogleReviewLinks() {
  if (!googleReviewAddLinks.length || !GOOGLE_REVIEW_URL) {
    return;
  }

  googleReviewAddLinks.forEach((link) => {
    link.href = GOOGLE_REVIEW_URL;
    link.hidden = false;
  });
}

function formatGoogleReviewDate(value, fallback = "") {
  if (!value) {
    return fallback || "Źródło: Google";
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return fallback || "Źródło: Google";
  }
  return date.toLocaleDateString("pl-PL", { year: "numeric", month: "long", day: "numeric" });
}

function createReviewCard(review, featured = false) {
  const card = document.createElement("article");
  card.className = `review-card${featured ? " review-card-featured" : ""}`;

  const stars = document.createElement("div");
  stars.className = "stars";
  stars.textContent = "★".repeat(Math.max(1, Math.min(5, Number(review.rating) || 5)));

  const text = document.createElement("p");
  text.textContent = String(review.text || "").trim();

  const author = document.createElement("strong");
  author.textContent = String(review.author || "Klient Google").trim();

  const meta = document.createElement("span");
  const dateText = formatGoogleReviewDate(review.updateTime || review.createTime, review.relativeTime);
  meta.textContent = `${dateText} · Źródło: Google`;

  card.append(stars, text, author, meta);
  return card;
}

async function loadGoogleReviews() {
  if (!googleReviewsGrid) {
    return;
  }

  try {
    const response = await fetch("/data/google-reviews.json", { cache: "no-store" });
    if (!response.ok) {
      return;
    }
    const data = await response.json();
    const reviews = Array.isArray(data.reviews)
      ? data.reviews.filter((review) => String(review.text || "").trim() !== "")
      : [];
    if (!reviews.length) {
      return;
    }

    googleReviewsGrid.innerHTML = "";
    reviews.slice(0, 3).forEach((review) => {
      googleReviewsGrid.appendChild(createReviewCard(review, true));
    });

    if (googleReviewTitle && data.averageRating && data.totalReviewCount) {
      googleReviewTitle.textContent = `Ocena ${Number(data.averageRating).toFixed(1)} / 5 w Google`;
    }
    if (googleReviewSummary) {
      googleReviewSummary.textContent = data.updatedAt
        ? `Aktualne opinie z Google, odświeżone ${formatGoogleReviewDate(data.updatedAt)}.`
        : "Aktualne opinie klientów z Google.";
    }
  } catch (error) {
    // Jeśli cache opinii nie jest jeszcze gotowy, zostawiamy statyczne opinie w HTML.
  }
}

function sendStatsEvent(eventName, extra = {}) {
  if (!trackedEvents.has(eventName)) {
    return;
  }

  const payload = {
    event: eventName,
    path: window.location.pathname || "/",
    productSlug: extra.productSlug || document.body.dataset.productSlug || ""
  };
  const body = JSON.stringify(payload);

  try {
    if (navigator.sendBeacon) {
      const sent = navigator.sendBeacon(statsEndpoint, new Blob([body], { type: "application/json" }));
      if (sent) {
        return;
      }
    }

    fetch(statsEndpoint, {
      method: "POST",
      body,
      headers: { "Content-Type": "application/json" },
      keepalive: true,
      credentials: "omit"
    }).catch(() => {});
  } catch (error) {
    // Statystyki nie mogą wpływać na działanie strony.
  }
}

function classifyTrackedLink(link) {
  const href = link.getAttribute("href") || "";
  const hrefLower = href.toLowerCase();
  const explicitEvent = link.dataset.statEvent || "";
  const events = [];

  if (trackedEvents.has(explicitEvent)) {
    events.push(explicitEvent);
  }

  if (hrefLower.startsWith("tel:")) {
    events.push("call_click");
  }

  if (hrefLower.startsWith("sms:")) {
    events.push("sms_click");
  }

  if (hrefLower.includes("facebook.com") || hrefLower.includes("m.me/")) {
    events.push("facebook_click");
  }

  if (hrefLower.includes("instagram.com")) {
    events.push("instagram_click");
  }

  return [...new Set(events)];
}

function normalizeImagePath(path) {
  if (!hasDisplayValue(path)) {
    return "/product-table.jpeg";
  }

  if (/^https?:\/\//i.test(path)) {
    return path;
  }

  const cleanPath = path.startsWith("/") ? path.slice(1) : path;
  return cleanPath.includes("..") ? "/product-table.jpeg" : `/${cleanPath}`;
}

function normalizeText(text) {
  return String(text || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase();
}

function matchesCategory(productCategory, filter) {
  const normalizedCategory = normalizeText(productCategory);
  const normalizedFilter = normalizeText(filter);

  if (normalizedFilter === "wyposazenie domu") {
    return ["wyposazenie domu", "dom", "dekoracje", "oswietlenie"].includes(normalizedCategory);
  }

  if (normalizedFilter === "wyposazenie ogrodu") {
    return ["wyposazenie ogrodu", "ogrod"].includes(normalizedCategory);
  }

  return normalizedCategory === normalizedFilter;
}

function getReadableCategory(productCategory) {
  const normalizedCategory = normalizeText(productCategory);

  if (normalizedCategory.includes("ogrod")) {
    return "Meble ogrodowe";
  }

  if (normalizedCategory.includes("dom") || normalizedCategory.includes("dekoracje") || normalizedCategory.includes("oswietlenie")) {
    return "Meble do domu";
  }

  return hasDisplayValue(productCategory) ? productCategory : "Produkt outletowy";
}

function isProductPublic(product) {
  if (product.visible === false) {
    return false;
  }

  return normalizeText(product.productStatus) !== "ukryty";
}

function getProductDisplayStatus(product) {
  const managementStatus = normalizeText(product.productStatus);

  if (managementStatus === "sprzedany") {
    return "Sprzedany";
  }

  if (managementStatus === "rezerwacja") {
    return "Rezerwacja";
  }

  return hasDisplayValue(product.status) ? product.status : "Dostępny od ręki";
}

function isSoldProduct(product) {
  return ["sprzedany", "sprzedane"].includes(normalizeText(getProductDisplayStatus(product)));
}

function escapeHtml(value) {
  return String(value || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function hasDisplayValue(value) {
  const normalized = normalizeText(value).trim();
  return Boolean(normalized)
    && !normalized.includes("do uzupelnienia")
    && normalized !== "cena outletowa"
    && normalized !== "brak"
    && normalized !== "xxx"
    && normalized !== "-";
}

function parsePrice(value) {
  const raw = String(value || "")
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
}

function getProductSearchText(product) {
  return normalizeText([
    product.name,
    product.description,
    product.longDescription,
    product.category,
    product.productType,
    product.condition,
    product.status,
    product.productStatus,
    product.availability,
    product.material,
    product.color,
    Array.isArray(product.tags) ? product.tags.join(" ") : product.tags,
    Array.isArray(product.keywords) ? product.keywords.join(" ") : product.keywords
  ].filter(hasDisplayValue).join(" "));
}

function getProductPriceValue(product) {
  return parsePrice(product.outletPrice) || parsePrice(product.catalogPrice);
}

function matchesAvailabilityFilter(product, filter) {
  if (!filter || filter === "all") {
    return true;
  }

  const status = normalizeText(getProductDisplayStatus(product));
  const productStatus = normalizeText(product.productStatus);
  const availability = normalizeText(product.availability);
  const haystack = `${status} ${productStatus} ${availability}`;

  if (filter === "available") {
    return !haystack.includes("sprzed") && !haystack.includes("rezerw");
  }

  if (filter === "last") {
    return haystack.includes("ostatnia") || haystack.includes("pojedyncza") || haystack.includes("pojedynczy");
  }

  return true;
}

function matchesPriceFilter(product, filter) {
  if (!filter || filter === "all") {
    return true;
  }

  const price = getProductPriceValue(product);

  if (!price) {
    return true;
  }

  if (filter === "under-500") {
    return price <= 500;
  }

  if (filter === "500-1500") {
    return price >= 500 && price <= 1500;
  }

  if (filter === "over-1500") {
    return price > 1500;
  }

  return true;
}

function getDiscoveryFilters() {
  const filters = {
    category: "all",
    availability: "all",
    price: "all",
    search: normalizeText(productSearchInput?.value || "").trim()
  };

  productFilterInputs.forEach((input) => {
    if (input.checked && input.dataset.productFilter) {
      filters[input.dataset.productFilter] = input.value;
    }
  });

  return filters;
}

function hasActiveDiscoveryFilters(filters) {
  return Boolean(filters.search)
    || filters.category !== "all"
    || filters.availability !== "all"
    || filters.price !== "all";
}

function applyDiscoveryFilters(items, filters) {
  return items.filter((product) => {
    if (filters.category !== "all" && !matchesCategory(product.category, filters.category)) {
      return false;
    }

    if (!matchesAvailabilityFilter(product, filters.availability)) {
      return false;
    }

    if (!matchesPriceFilter(product, filters.price)) {
      return false;
    }

    if (filters.search && !getProductSearchText(product).includes(filters.search)) {
      return false;
    }

    return true;
  });
}

function createProductSlug(value) {
  return normalizeText(String(value || "").replace(/ł/g, "l").replace(/Ł/g, "L"))
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

function assignProductSlugs(items) {
  const used = new Map();

  return items.map((product) => {
    const source = hasDisplayValue(product.slug) ? product.slug : product.name;
    const base = createProductSlug(source) || "produkt";
    const count = (used.get(base) || 0) + 1;
    used.set(base, count);

    return {
      ...product,
      _publicSlug: count > 1 ? `${base}-${count}` : base
    };
  });
}

function shortenSeoDescription(value, maxLength = 160) {
  const text = String(value || "").replace(/\s+/g, " ").trim();

  if (text.length <= maxLength) {
    return text;
  }

  const shortened = text.slice(0, maxLength - 1);
  const lastSpace = shortened.lastIndexOf(" ");
  return `${shortened.slice(0, lastSpace > 100 ? lastSpace : maxLength - 1).trim()}.`;
}

function getProductSeo(product) {
  const name = hasDisplayValue(product.name) ? product.name.trim() : "Produkt outletowy";
  const category = hasDisplayValue(product.category) ? product.category.trim() : "Meble do domu i ogrodu";
  const description = hasDisplayValue(product.description) ? product.description.trim() : "";
  const locationSuffix = `${category} dostępne w Home & Garden Outlet pod Wrocławiem.`;
  const descriptionLimit = Math.max(50, 159 - locationSuffix.length);
  const descriptionIntro = shortenSeoDescription(description || name, descriptionLimit).replace(/\.$/, "");
  const generatedDescription = `${descriptionIntro}. ${locationSuffix}`;

  return {
    slug: product._publicSlug || (hasDisplayValue(product.slug) ? createProductSlug(product.slug) : createProductSlug(name)),
    title: hasDisplayValue(product.seoTitle) ? product.seoTitle.trim() : `${name} | Home & Garden Outlet`,
    description: hasDisplayValue(product.seoDescription)
      ? shortenSeoDescription(product.seoDescription)
      : shortenSeoDescription(generatedDescription),
    imageAlt: hasDisplayValue(product.imageAlt)
      ? product.imageAlt.trim()
      : `${name} - ${category}, Home & Garden Outlet`
  };
}

function setMetaContent(selector, content) {
  let element = document.head.querySelector(selector);

  if (!element) {
    element = document.createElement("meta");
    const nameMatch = selector.match(/\[name="([^"]+)"\]/);
    const propertyMatch = selector.match(/\[property="([^"]+)"\]/);

    if (nameMatch) element.setAttribute("name", nameMatch[1]);
    if (propertyMatch) element.setAttribute("property", propertyMatch[1]);
    document.head.appendChild(element);
  }

  element.setAttribute("content", content);
}

function applyProductSeo(product) {
  const seo = getProductSeo(product);
  document.title = seo.title;
  setMetaContent('meta[name="description"]', seo.description);
  setMetaContent('meta[property="og:title"]', seo.title);
  setMetaContent('meta[property="og:description"]', seo.description);

  document.querySelectorAll("[data-product-main-image]").forEach((image) => {
    image.alt = seo.imageAlt;
  });

  return seo;
}

function getProductImages(product) {
  const gallery = Array.isArray(product.gallery) ? product.gallery : [];
  const galleryPaths = gallery.map((item) => typeof item === "string" ? item : item?.image);
  const images = [product.image, ...galleryPaths]
    .filter(hasDisplayValue)
    .map(normalizeImagePath);

  return [...new Set(images.length ? images : ["/product-table.jpeg"])];
}

function productCategoryLinks(product) {
  const category = normalizeText(product.category);
  const isGarden = category.includes("ogrod");
  const links = isGarden
    ? [
      { href: "/ogrod", label: "Więcej mebli ogrodowych" },
      { href: "/meble-ogrodowe-wroclaw/", label: "Meble ogrodowe outlet Wrocław" }
    ]
    : [
      { href: "/dom", label: "Więcej mebli do domu" },
      { href: "/outlet-meblowy-wroclaw/", label: "Outlet meblowy pod Wrocławiem" }
    ];

  return `
        <div class="product-card-links" aria-label="Powiązane kategorie">
          ${links.map((link) => `<a href="${escapeHtml(link.href)}">${escapeHtml(link.label)}</a>`).join("")}
        </div>
      `;
}

function productTemplate(product) {
  const name = product.name || "Produkt outletowy";
  const category = getReadableCategory(product.category || "Wyposażenie ogrodu");
  const status = getProductDisplayStatus(product);
  const images = getProductImages(product);
  const seo = getProductSeo(product);
  const detailUrl = `/produkt/${encodeURIComponent(seo.slug)}`;
  const image = images[0];
  const galleryData = escapeHtml(JSON.stringify(images));
  const galleryCount = images.length > 1
    ? `<span class="gallery-count">${images.length} zdjęć</span>`
    : "";
  const badgeClass = statusClasses[status] || "";
  const dimensions = hasDisplayValue(product.dimensions) ? `<p class="dimensions">${escapeHtml(product.dimensions)}</p>` : "";
  const condition = hasDisplayValue(product.condition) ? `<p class="dimensions">Stan: ${escapeHtml(product.condition)}</p>` : "";
  const hasCatalogPrice = hasDisplayValue(product.catalogPrice);
  const hasOutletPrice = hasDisplayValue(product.outletPrice);
  const catalogValue = parsePrice(product.catalogPrice);
  const outletValue = parsePrice(product.outletPrice);
  const savings = hasCatalogPrice && hasOutletPrice && catalogValue && outletValue && catalogValue > outletValue
    ? Math.round(catalogValue - outletValue)
    : null;
  const priceItems = [
    hasCatalogPrice ? `<span class="catalog-price${hasOutletPrice ? " old-price" : ""}">Cena katalogowa: ${escapeHtml(product.catalogPrice)}</span>` : "",
    hasOutletPrice ? `<span class="outlet-price">Cena outletowa: ${escapeHtml(product.outletPrice)}</span>` : "",
    savings ? `<span class="saving-badge">Oszczędzasz: ${savings} zł</span>` : ""
  ].filter(Boolean);
  const priceRow = priceItems.length ? `<div class="price-row${hasOutletPrice ? " has-outlet" : ""}">${priceItems.join("")}</div>` : "";
  const priceNote = hasOutletPrice
    ? ""
    : `<p class="price-note">${hasCatalogPrice ? "Zapytaj o cenę outletową." : "Zapytaj o cenę."}</p>`;
  const description = product.description || "Produkt dostępny do obejrzenia na miejscu.";

  return `
    <article class="product-card">
      <div class="product-image">
        <a class="product-image-link" href="${escapeHtml(detailUrl)}" data-gallery="${galleryData}" data-gallery-name="${escapeHtml(name)}" data-gallery-alt="${escapeHtml(seo.imageAlt)}" aria-label="Zobacz produkt: ${escapeHtml(name)}">
          <img src="${escapeHtml(image)}" width="600" height="450" loading="lazy" alt="${escapeHtml(seo.imageAlt)}">
        </a>
        <span class="badge ${badgeClass}">${escapeHtml(status)}</span>
        ${galleryCount}
      </div>
      <div class="product-body">
        <div class="product-meta">
          <span>${escapeHtml(category)}</span>
          <span>Dostępny lokalnie</span>
        </div>
        <h3><a class="product-title-link" href="${escapeHtml(detailUrl)}">${escapeHtml(name)}</a></h3>
        ${priceRow}
        ${priceNote}
        <div class="product-description-wrap">
          <p class="product-description">${escapeHtml(description)}</p>
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
    </article>
  `;
}

const galleryModal = document.createElement("div");
galleryModal.className = "gallery-modal";
galleryModal.setAttribute("aria-hidden", "true");
galleryModal.innerHTML = `
  <div class="gallery-backdrop" data-gallery-close></div>
  <section class="gallery-dialog" role="dialog" aria-modal="true" aria-labelledby="gallery-title">
    <div class="gallery-toolbar">
      <h2 id="gallery-title">Galeria produktu</h2>
      <button class="gallery-close" type="button" data-gallery-close aria-label="Zamknij galerię">&times;</button>
    </div>
    <div class="gallery-main">
      <button class="gallery-nav gallery-prev" type="button" data-gallery-prev aria-label="Poprzednie zdjęcie">&#8592;</button>
      <img class="gallery-main-image" src="" alt="" width="1200" height="900">
      <button class="gallery-nav gallery-next" type="button" data-gallery-next aria-label="Następne zdjęcie">&#8594;</button>
    </div>
    <div class="gallery-thumbnails" aria-label="Miniatury zdjęć"></div>
  </section>
`;
document.body.appendChild(galleryModal);

const galleryMainImage = galleryModal.querySelector(".gallery-main-image");
const galleryTitle = galleryModal.querySelector("#gallery-title");
const galleryThumbnails = galleryModal.querySelector(".gallery-thumbnails");
let activeGalleryImages = [];
let activeGalleryIndex = 0;
let galleryTouchStartX = 0;
let activeGalleryAlt = "";

function updateGallery() {
  const image = activeGalleryImages[activeGalleryIndex] || "/product-table.jpeg";
  galleryMainImage.src = image;
  galleryMainImage.alt = `${activeGalleryAlt || galleryTitle.textContent} - zdjęcie ${activeGalleryIndex + 1}`;
  galleryThumbnails.innerHTML = activeGalleryImages.map((path, index) => `
    <button class="gallery-thumbnail${index === activeGalleryIndex ? " active" : ""}" type="button" data-gallery-index="${index}" aria-label="Pokaż zdjęcie ${index + 1}">
      <img src="${escapeHtml(path)}" alt="" width="120" height="90" loading="lazy">
    </button>
  `).join("");

  galleryModal.classList.toggle("single-image", activeGalleryImages.length < 2);
}

function openGallery(images, name, imageAlt = "") {
  activeGalleryImages = images;
  activeGalleryIndex = 0;
  activeGalleryAlt = imageAlt;
  galleryTitle.textContent = name;
  updateGallery();
  galleryModal.classList.add("open");
  galleryModal.setAttribute("aria-hidden", "false");
  document.body.classList.add("gallery-open");
  galleryModal.querySelector(".gallery-close").focus();
}

function closeGallery() {
  galleryModal.classList.remove("open");
  galleryModal.setAttribute("aria-hidden", "true");
  document.body.classList.remove("gallery-open");
}

function moveGallery(direction) {
  if (activeGalleryImages.length < 2) {
    return;
  }

  activeGalleryIndex = (activeGalleryIndex + direction + activeGalleryImages.length) % activeGalleryImages.length;
  updateGallery();
}

function getActiveFilter() {
  const activeButton = document.querySelector(".filter-btn.active");
  return activeButton ? activeButton.dataset.filter : "all";
}

function sortProducts(items) {
  return [...items].sort((a, b) => Number(a.order || 0) - Number(b.order || 0));
}

function shuffleProducts(items) {
  const shuffled = [...items];

  for (let index = shuffled.length - 1; index > 0; index -= 1) {
    const randomIndex = Math.floor(Math.random() * (index + 1));
    [shuffled[index], shuffled[randomIndex]] = [shuffled[randomIndex], shuffled[index]];
  }

  return shuffled;
}

function pickHomepageProducts(items) {
  const availableItems = items.filter((product) => !isSoldProduct(product));
  const featured = availableItems.filter((product) => product.featured !== false);
  const remaining = availableItems.filter((product) => product.featured === false);
  const selected = shuffleProducts(featured).slice(0, homepageProductLimit);

  if (selected.length < homepageProductLimit) {
    selected.push(...shuffleProducts(remaining).slice(0, homepageProductLimit - selected.length));
  }

  return selected;
}

function updateProductCount(count, filters) {
  if (!productCount) {
    return;
  }

  if (hasActiveDiscoveryFilters(filters)) {
    productCount.textContent = `Pokazano ${count} produktów`;
    return;
  }

  if (isCategoryPage) {
    const label = normalizeText(pageCategory).includes("ogrod")
      ? "Meble ogrodowe"
      : "Meble do domu";
    productCount.textContent = `${label} — aktualnie ${count} produktów`;
    return;
  }

  productCount.textContent = `Aktualnie pokazujemy ${count} produktów z outletu`;
}

function renderProducts(filter = "all") {
  if (!productGrid) {
    return;
  }

  const publicProducts = products.filter(isProductPublic).filter((product) => !isSoldProduct(product));
  const filters = getDiscoveryFilters();
  if (filter !== "all" && filters.category === "all") {
    filters.category = filter;
  }

  const categoryProducts = isCategoryPage
    ? publicProducts.filter((product) => matchesCategory(product.category, pageCategory))
    : publicProducts;

  const filteredProducts = applyDiscoveryFilters(categoryProducts, filters);
  const productsToRender = !isCategoryPage && !hasActiveDiscoveryFilters(filters)
    ? pickHomepageProducts(filteredProducts)
    : sortProducts(filteredProducts);

  productGrid.innerHTML = productsToRender.map(productTemplate).join("");
  updateProductCount(productsToRender.length, filters);
  if (productEmpty) {
    productEmpty.hidden = productsToRender.length > 0;
  }
  requestAnimationFrame(initializeDescriptionToggles);
}

function initializeDescriptionToggles() {
  document.querySelectorAll(".product-description-wrap").forEach((wrapper) => {
    const description = wrapper.querySelector(".product-description");
    const toggle = wrapper.querySelector(".description-toggle");

    if (!description || !toggle || description.classList.contains("expanded")) {
      return;
    }

    toggle.hidden = description.scrollHeight <= description.clientHeight + 1;
  });
}

async function loadProducts() {
  try {
    const response = await fetch("/data/products.json", { cache: "no-store" });

    if (!response.ok) {
      throw new Error("Nie można pobrać pliku produktów.");
    }

    const data = await response.json();
    products = assignProductSlugs(Array.isArray(data.products) && data.products.length > 0
      ? data.products
      : fallbackProducts);

    const productPageSlug = document.body.dataset.productSlug;
    if (productPageSlug) {
      const productPageItem = products.find((product) => getProductSeo(product).slug === createProductSlug(productPageSlug));
      if (productPageItem) {
        applyProductSeo(productPageItem);
      }
    }
  } catch (error) {
    if (productGrid?.querySelector(".product-card-static")) {
      return;
    }

    products = assignProductSlugs(fallbackProducts);
  }

  renderProducts(getActiveFilter());
}

filterButtons.forEach((button) => {
  button.addEventListener("click", () => {
    filterButtons.forEach((item) => item.classList.remove("active"));
    button.classList.add("active");
    renderProducts(button.dataset.filter);
  });
});

productSearchInput?.addEventListener("input", () => {
  renderProducts(getActiveFilter());
});

productFilterInputs.forEach((input) => {
  input.addEventListener("change", () => {
    renderProducts(getActiveFilter());
  });
});

document.addEventListener("click", (event) => {
  const target = event.target instanceof Element ? event.target : null;
  if (!target) return;

  const trackedLink = target.closest("a[href]");
  if (trackedLink) {
    classifyTrackedLink(trackedLink).forEach((eventName) => sendStatsEvent(eventName));
  }

  const galleryTrigger = target.closest(".product-gallery-trigger");
  const closeTrigger = target.closest("[data-gallery-close]");
  const previousTrigger = target.closest("[data-gallery-prev]");
  const nextTrigger = target.closest("[data-gallery-next]");
  const thumbnailTrigger = target.closest("[data-gallery-index]");

  if (galleryTrigger) {
    try {
      const images = JSON.parse(galleryTrigger.dataset.gallery || "[]");
      openGallery(
        images,
        galleryTrigger.dataset.galleryName || "Galeria produktu",
        galleryTrigger.dataset.galleryAlt || ""
      );
    } catch (error) {
      openGallery(["/product-table.jpeg"], galleryTrigger.dataset.galleryName || "Galeria produktu");
    }
  } else if (closeTrigger) {
    closeGallery();
  } else if (previousTrigger) {
    moveGallery(-1);
  } else if (nextTrigger) {
    moveGallery(1);
  } else if (thumbnailTrigger) {
    activeGalleryIndex = Number(thumbnailTrigger.dataset.galleryIndex || 0);
    updateGallery();
  } else {
    const descriptionToggle = target.closest(".description-toggle");

    if (descriptionToggle) {
      const wrapper = descriptionToggle.closest(".product-description-wrap");
      const description = wrapper?.querySelector(".product-description");
      const expanded = descriptionToggle.getAttribute("aria-expanded") === "true";

      description?.classList.toggle("expanded", !expanded);
      descriptionToggle.setAttribute("aria-expanded", String(!expanded));
      descriptionToggle.textContent = expanded ? "Więcej" : "Mniej";
    }
  }
});

document.addEventListener("error", (event) => {
  if (event.target instanceof HTMLImageElement && !event.target.dataset.fallbackApplied) {
    event.target.dataset.fallbackApplied = "true";
    event.target.src = "/product-table.jpeg";
  }
}, true);

document.addEventListener("keydown", (event) => {
  if (!galleryModal.classList.contains("open")) {
    return;
  }

  if (event.key === "Escape") {
    closeGallery();
  } else if (event.key === "ArrowLeft") {
    moveGallery(-1);
  } else if (event.key === "ArrowRight") {
    moveGallery(1);
  }
});

galleryModal.addEventListener("touchstart", (event) => {
  galleryTouchStartX = event.changedTouches[0]?.clientX || 0;
}, { passive: true });

galleryModal.addEventListener("touchend", (event) => {
  const touchEndX = event.changedTouches[0]?.clientX || 0;
  const distance = touchEndX - galleryTouchStartX;

  if (Math.abs(distance) > 50) {
    moveGallery(distance > 0 ? -1 : 1);
  }
}, { passive: true });

if (menuToggle && mainMenu) {
  menuToggle.addEventListener("click", () => {
    const isOpen = mainMenu.classList.toggle("open");
    menuToggle.setAttribute("aria-expanded", String(isOpen));
    menuToggle.setAttribute("aria-label", isOpen ? "Zamknij menu" : "Otwórz menu");
    document.body.classList.toggle("menu-open", isOpen);
  });

  mainMenu.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", () => {
      mainMenu.classList.remove("open");
      menuToggle.setAttribute("aria-expanded", "false");
      menuToggle.setAttribute("aria-label", "Otwórz menu");
      document.body.classList.remove("menu-open");
    });
  });
}

if (window.netlifyIdentity) {
  window.netlifyIdentity.on("init", (user) => {
    if (!user && window.location.hash.includes("invite_token")) {
      window.netlifyIdentity.open("signup");
    }

    if (!user && window.location.hash.includes("recovery_token")) {
      window.netlifyIdentity.open("recovery");
    }
  });
}

sendStatsEvent("page_view");
if (document.body.dataset.productSlug) {
  sendStatsEvent("product_view");
}

initializeGoogleReviewLinks();
loadGoogleReviews();

if (productGrid) {
  initializeDescriptionToggles();
  loadProducts();
}

window.HomeGardenProductSeo = {
  applyProductSeo,
  createProductSlug,
  getProductSeo
};
