const fallbackProducts = [
  {
    name: "Stół z krzesłami do ogrodu i jadalni",
    category: "Wyposażenie ogrodu",
    catalogPrice: "do uzupełnienia",
    outletPrice: "cena outletowa",
    status: "Nowość",
    image: "product-table.jpeg",
    description: "Realny zestaw z ekspozycji w showroomie. Jasny blat, wygodne krzesła i naturalny klimat do domu lub ogrodu.",
    dimensions: "Wymiary: do uzupełnienia po pomiarze produktu",
    featured: true,
    order: 10
  },
  {
    name: "Narożnik wypoczynkowy",
    category: "Wyposażenie domu",
    catalogPrice: "do uzupełnienia",
    outletPrice: "cena outletowa",
    status: "Ostatnia sztuka",
    image: "product-sofa.jpeg",
    description: "Jasny narożnik z ekspozycji. Zdjęcie wykonane na miejscu, bez sztucznych wizualizacji.",
    dimensions: "Wymiary: do uzupełnienia",
    featured: true,
    order: 20
  },
  {
    name: "Zestaw foteli z podnóżkami",
    category: "Wyposażenie ogrodu",
    catalogPrice: "do uzupełnienia",
    outletPrice: "cena outletowa",
    status: "Rezerwacja",
    image: "product-chaise.jpeg",
    description: "Drewniane fotele wypoczynkowe z podnóżkami. Dobra propozycja na taras, werandę lub ogród.",
    dimensions: "Wymiary: do uzupełnienia",
    featured: true,
    order: 30
  },
  {
    name: "Zestaw wypoczynkowy z fotelami",
    category: "Wyposażenie ogrodu",
    catalogPrice: "do uzupełnienia",
    outletPrice: "cena outletowa",
    status: "Nowość",
    image: "product-chair.jpeg",
    description: "Komplet wypoczynkowy z fotelami, stolikiem i sofą. Produkt dostępny do obejrzenia na miejscu.",
    dimensions: "Wymiary: do uzupełnienia",
    featured: true,
    order: 40
  },
  {
    name: "Leżaki ogrodowe",
    category: "Wyposażenie ogrodu",
    catalogPrice: "do uzupełnienia",
    outletPrice: "cena outletowa",
    status: "Nowość",
    image: "product-lamp.jpeg",
    description: "Leżaki z ekspozycji outletowej. Zdjęcie pokazuje realny stan i ustawienie produktu w showroomie.",
    dimensions: "Wymiary: do uzupełnienia",
    featured: true,
    order: 50
  },
  {
    name: "Donice i dekoracje do ogrodu",
    category: "Wyposażenie ogrodu",
    catalogPrice: "do uzupełnienia",
    outletPrice: "cena outletowa",
    status: "Ostatnia sztuka",
    image: "product-pots.jpeg",
    description: "Donice i dodatki ogrodowe dostępne w outlecie. Dobry przykład żywego katalogu z prawdziwymi zdjęciami.",
    dimensions: "Wymiary: do uzupełnienia",
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
let products = [...fallbackProducts];

function normalizeImagePath(path) {
  if (!path) {
    return "product-table.jpeg";
  }

  if (path.startsWith("http") || path.startsWith("data:")) {
    return path;
  }

  return path.startsWith("/") ? path.slice(1) : path;
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

function productTemplate(product) {
  const name = product.name || "Produkt outletowy";
  const category = product.category || "Wyposażenie ogrodu";
  const status = product.status || "Dostępne";
  const message = encodeURIComponent(`Dzień dobry, interesuje mnie produkt: ${name}`);
  const badgeClass = statusClasses[status] || "";
  const dimensions = product.dimensions ? `<p class="dimensions">${product.dimensions}</p>` : "";

  return `
    <article class="product-card">
      <div class="product-image">
        <img src="${normalizeImagePath(product.image)}" alt="${name}">
        <span class="badge ${badgeClass}">${status}</span>
      </div>
      <div class="product-body">
        <div class="product-meta">
          <span>${category}</span>
          <span>Dostępny lokalnie</span>
        </div>
        <h3>${name}</h3>
        <div class="price-row">
          <span class="catalog-price">${product.catalogPrice || "do uzupełnienia"}</span>
          <span class="outlet-price">${product.outletPrice || "cena outletowa"}</span>
        </div>
        <p class="product-description">${product.description || "Produkt dostępny do obejrzenia na miejscu."}</p>
        ${dimensions}
        <div class="product-actions">
          <a class="btn btn-primary" href="tel:+48577210777">Zadzwoń</a>
          <a class="btn btn-outline" href="sms:+48577210777?body=${message}">Zapytaj SMS</a>
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

function pickHomepageProducts(items) {
  const featured = sortProducts(items.filter((product) => product.featured !== false));
  const garden = featured.filter((product) => matchesCategory(product.category, "Wyposażenie ogrodu")).slice(0, 3);
  const home = featured.filter((product) => matchesCategory(product.category, "Wyposażenie domu")).slice(0, 3);
  return [...garden, ...home];
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

  productGrid.innerHTML = sortProducts(visibleProducts).map(productTemplate).join("");
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
    document.body.classList.toggle("menu-open", isOpen);
  });

  mainMenu.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", () => {
      mainMenu.classList.remove("open");
      menuToggle.setAttribute("aria-expanded", "false");
      document.body.classList.remove("menu-open");
    });
  });
}

loadProducts();
