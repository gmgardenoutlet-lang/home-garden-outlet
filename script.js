const products = [
  {
    name: "Stół z krzesłami do ogrodu i jadalni",
    category: "Wyposażenie ogrodu",
    catalogPrice: "do uzupełnienia",
    outletPrice: "cena outletowa",
    status: "Nowość",
    image: "product-table.jpeg",
    description: "Realny zestaw z ekspozycji w showroomie. Jasny blat, wygodne krzesła i naturalny klimat do domu lub ogrodu.",
    dimensions: "Wymiary: do uzupełnienia po pomiarze produktu"
  },
  {
    name: "Narożnik wypoczynkowy",
    category: "Wyposażenie domu",
    catalogPrice: "do uzupełnienia",
    outletPrice: "cena outletowa",
    status: "Ostatnia sztuka",
    image: "product-sofa.jpeg",
    description: "Jasny narożnik z ekspozycji. Zdjęcie wykonane na miejscu, bez sztucznych wizualizacji.",
    dimensions: "Wymiary: do uzupełnienia"
  },
  {
    name: "Zestaw foteli z podnóżkami",
    category: "Wyposażenie ogrodu",
    catalogPrice: "do uzupełnienia",
    outletPrice: "cena outletowa",
    status: "Rezerwacja",
    image: "product-chaise.jpeg",
    description: "Drewniane fotele wypoczynkowe z podnóżkami. Dobra propozycja na taras, werandę lub ogród.",
    dimensions: "Wymiary: do uzupełnienia"
  },
  {
    name: "Zestaw wypoczynkowy z fotelami",
    category: "Wyposażenie ogrodu",
    catalogPrice: "do uzupełnienia",
    outletPrice: "cena outletowa",
    status: "Nowość",
    image: "product-chair.jpeg",
    description: "Komplet wypoczynkowy z fotelami, stolikiem i sofą. Produkt dostępny do obejrzenia na miejscu.",
    dimensions: "Wymiary: do uzupełnienia"
  },
  {
    name: "Leżaki ogrodowe",
    category: "Wyposażenie ogrodu",
    catalogPrice: "do uzupełnienia",
    outletPrice: "cena outletowa",
    status: "Nowość",
    image: "product-lamp.jpeg",
    description: "Leżaki z ekspozycji outletowej. Zdjęcie pokazuje realny stan i ustawienie produktu w showroomie.",
    dimensions: "Wymiary: do uzupełnienia"
  },
  {
    name: "Donice i dekoracje do ogrodu",
    category: "Wyposażenie ogrodu",
    catalogPrice: "do uzupełnienia",
    outletPrice: "cena outletowa",
    status: "Ostatnia sztuka",
    image: "product-pots.jpeg",
    description: "Donice i dodatki ogrodowe dostępne w outlecie. Dobry przykład żywego katalogu z prawdziwymi zdjęciami.",
    dimensions: "Wymiary: do uzupełnienia"
  }
];

const statusClasses = {
  "Nowość": "",
  "Ostatnia sztuka": "",
  "Rezerwacja": "reserved",
  "Sprzedane": "sold"
};

const productGrid = document.querySelector("#produkty");
const filterButtons = document.querySelectorAll(".filter-btn");
const menuToggle = document.querySelector(".menu-toggle");
const mainMenu = document.querySelector("#main-menu");

function productTemplate(product) {
  const message = encodeURIComponent(`Dzień dobry, interesuje mnie produkt: ${product.name}`);
  const badgeClass = statusClasses[product.status] || "";

  return `
    <article class="product-card">
      <div class="product-image">
        <img src="${product.image}" alt="${product.name}">
        <span class="badge ${badgeClass}">${product.status}</span>
      </div>
      <div class="product-body">
        <div class="product-meta">
          <span>${product.category}</span>
          <span>Dostępny lokalnie</span>
        </div>
        <h3>${product.name}</h3>
        <div class="price-row">
          <span class="catalog-price">${product.catalogPrice}</span>
          <span class="outlet-price">${product.outletPrice}</span>
        </div>
        <p class="product-description">${product.description}</p>
        <p class="dimensions">${product.dimensions}</p>
        <div class="product-actions">
          <a class="btn btn-primary" href="tel:+48577210777">Zadzwoń</a>
          <a class="btn btn-outline" href="sms:+48577210777?body=${message}">Zapytaj SMS</a>
        </div>
      </div>
    </article>
  `;
}

function renderProducts(filter = "all") {
  const visibleProducts = filter === "all"
    ? products
    : products.filter((product) => product.category === filter);

  productGrid.innerHTML = visibleProducts.map(productTemplate).join("");
}

filterButtons.forEach((button) => {
  button.addEventListener("click", () => {
    filterButtons.forEach((item) => item.classList.remove("active"));
    button.classList.add("active");
    renderProducts(button.dataset.filter);
  });
});

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

renderProducts();
