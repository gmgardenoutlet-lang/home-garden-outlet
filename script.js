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

function productTemplate(product) {
  const name = product.name || "Produkt outletowy";
  const category = product.category || "Wyposażenie ogrodu";
  const status = product.status || "Dostępne";
  const image = normalizeImagePath(product.image);
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
        <img src="${escapeHtml(image)}" width="600" height="450" loading="lazy" alt="${escapeHtml(name)}">
        <span class="badge ${badgeClass}">${escapeHtml(status)}</span>
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
  return shuffleProducts(featured).slice(0, homepageProductLimit);
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
  } catch (error) {
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

loadProducts();
