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
  "Ostatnia sztuka": "",
  Rezerwacja: "reserved",
  Sprzedane: "sold"
};

const productGrid = document.querySelector("#produkty");
const filterButtons = document.querySelectorAll(".filter-btn");
const menuToggle = document.querySelector(".menu-toggle");
const mainMenu = document.querySelector("#main-menu");
const pageCategory = document.body.dataset.category || "";
const isCategoryPage = Boolean(pageCategory);
const homepageProductLimit = 6;
let products = [...fallbackProducts];

function normalizeImagePath(path) {
  if (!hasDisplayValue(path)) {
    return "product-table.jpeg";
  }

  if (/^https?:\/\//i.test(path)) {
    return path;
  }

  const cleanPath = path.startsWith("/") ? path.slice(1) : path;
  return cleanPath.includes("..") ? "product-table.jpeg" : cleanPath;
}

function normalizeText(text) {
  return String(text || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase();
}

function matchesCategory(productCategory, filter) {
  return normalizeText(productCategory) === normalizeText(filter);
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
  const cleaned = String(value || "").replace(/\s/g, "").replace(",", ".");
  const match = cleaned.match(/\d+(?:\.\d+)?/);
  return match ? Number(match[0]) : null;
}

function createProductSlug(value) {
  return normalizeText(value)
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
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
    slug: hasDisplayValue(product.slug) ? createProductSlug(product.slug) : createProductSlug(name),
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

  return [...new Set(images.length ? images : ["product-table.jpeg"])];
}

function productTemplate(product) {
  const name = product.name || "Produkt outletowy";
  const category = product.category || "Wyposażenie ogrodu";
  const status = product.status || "Dostępne";
  const images = getProductImages(product);
  const seo = getProductSeo(product);
  const image = images[0];
  const galleryData = escapeHtml(JSON.stringify(images));
  const galleryCount = images.length > 1
    ? `<span class="gallery-count">${images.length} zdjęć</span>`
    : "";
  const badgeClass = statusClasses[status] || "";
  const dimensions = hasDisplayValue(product.dimensions) ? `<p class="dimensions">${escapeHtml(product.dimensions)}</p>` : "";
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

  return `
    <article class="product-card">
      <div class="product-image">
        <button class="product-gallery-trigger" type="button" data-gallery="${galleryData}" data-gallery-name="${escapeHtml(name)}" data-gallery-alt="${escapeHtml(seo.imageAlt)}" aria-label="Zobacz zdjęcia produktu: ${escapeHtml(name)}">
          <img src="${escapeHtml(image)}" width="600" height="450" loading="lazy" alt="${escapeHtml(seo.imageAlt)}">
        </button>
        <span class="badge ${badgeClass}">${escapeHtml(status)}</span>
        ${galleryCount}
      </div>
      <div class="product-body">
        <div class="product-meta">
          <span>${escapeHtml(category)}</span>
          <span>Dostępny lokalnie</span>
        </div>
        <h3>${escapeHtml(name)}</h3>
        ${priceRow}
        ${priceNote}
        <p class="product-description">${escapeHtml(product.description || "Produkt dostępny do obejrzenia na miejscu.")}</p>
        ${dimensions}
        <div class="product-actions">
          <a class="btn btn-primary" href="tel:+48577210777">Zadzwoń</a>
          <a class="btn btn-outline" href="sms:+48577210777">Zapytaj o produkt</a>
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
  const image = activeGalleryImages[activeGalleryIndex] || "product-table.jpeg";
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
  const featured = items.filter((product) => product.featured !== false);
  const remaining = items.filter((product) => product.featured === false);
  const selected = shuffleProducts(featured).slice(0, homepageProductLimit);

  if (selected.length < homepageProductLimit) {
    selected.push(...shuffleProducts(remaining).slice(0, homepageProductLimit - selected.length));
  }

  return selected;
}

function renderProducts(filter = "all") {
  if (!productGrid) {
    return;
  }

  const baseProducts = isCategoryPage
    ? products.filter((product) => matchesCategory(product.category, pageCategory))
    : pickHomepageProducts(products);

  const visibleProducts = !isCategoryPage && filter !== "all"
    ? baseProducts.filter((product) => matchesCategory(product.category, filter))
    : baseProducts;
  const productsToRender = isCategoryPage ? sortProducts(visibleProducts) : visibleProducts;

  productGrid.innerHTML = productsToRender.map(productTemplate).join("");
}

async function loadProducts() {
  try {
    const response = await fetch("data/products.json", { cache: "no-store" });

    if (!response.ok) {
      throw new Error("Nie można pobrać pliku produktów.");
    }

    const data = await response.json();
    products = Array.isArray(data.products) && data.products.length > 0
      ? data.products
      : fallbackProducts;

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

    products = [...fallbackProducts];
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

document.addEventListener("click", (event) => {
  const target = event.target instanceof Element ? event.target : null;
  if (!target) return;

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
      openGallery(["product-table.jpeg"], galleryTrigger.dataset.galleryName || "Galeria produktu");
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
  }
});

document.addEventListener("error", (event) => {
  if (event.target instanceof HTMLImageElement && !event.target.dataset.fallbackApplied) {
    event.target.dataset.fallbackApplied = "true";
    event.target.src = "product-table.jpeg";
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

if (productGrid) {
  loadProducts();
}

window.HomeGardenProductSeo = {
  applyProductSeo,
  createProductSlug,
  getProductSeo
};
