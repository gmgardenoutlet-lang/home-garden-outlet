(function () {
  const products = Array.isArray(window.HGO_SHOP_PRODUCTS) ? window.HGO_SHOP_PRODUCTS : [];
  const bySlug = new Map(products.map((product) => [product.slug, product]));
  const storageKey = "hgo-shop-test-cart";
  const formatter = new Intl.NumberFormat("pl-PL", { style: "currency", currency: "PLN" });
  const cartItems = document.querySelector("[data-cart-items]");
  const cartTotal = document.querySelector("[data-cart-total]");
  const deliveryBox = document.querySelector("[data-delivery-options]");
  const cartPayload = document.querySelector("[data-cart-payload]");
  const form = document.querySelector("[data-checkout-form]");
  const productGrid = document.querySelector("[data-shop-grid]");
  const sortSelect = document.querySelector("[data-shop-sort]");
  const cartToast = document.querySelector("[data-cart-toast]");
  const cartCounts = Array.from(document.querySelectorAll("[data-cart-count]"));
  const emptyActions = document.querySelector("[data-cart-empty-actions]");
  const checkoutLink = document.querySelector("[data-checkout-link]");
  const menuToggle = document.querySelector(".menu-toggle");
  const mainMenu = document.querySelector("#main-menu");
  const productCards = productGrid ? Array.from(productGrid.querySelectorAll("[data-product-card]")) : [];

  productCards.forEach((card, index) => {
    card.dataset.defaultOrder = String(index);
  });

  if (menuToggle && mainMenu) {
    menuToggle.addEventListener("click", () => {
      const isOpen = mainMenu.classList.toggle("open");
      menuToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
    });
    mainMenu.addEventListener("click", (event) => {
      if (event.target instanceof HTMLAnchorElement) {
        mainMenu.classList.remove("open");
        menuToggle.setAttribute("aria-expanded", "false");
      }
    });
  }

  const escapeHtml = (value) => String(value || "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[char]));
  const escapeAttr = escapeHtml;

  const readCart = () => {
    try {
      const value = JSON.parse(localStorage.getItem(storageKey) || "{}");
      return Array.isArray(value.items) ? value : { items: [], delivery: "" };
    } catch (error) {
      return { items: [], delivery: "" };
    }
  };

  const cartWithValidItems = () => {
    const cart = readCart();
    const items = cart.items
      .filter((item) => bySlug.has(item.slug))
      .map((item) => ({ slug: item.slug, quantity: Math.max(1, Math.min(20, Number(item.quantity) || 1)) }));
    return { items, delivery: cart.delivery || "" };
  };

  const saveCart = (cart) => {
    localStorage.setItem(storageKey, JSON.stringify(cart));
    render();
  };

  const commonDelivery = (items) => {
    let common = null;
    items.forEach((item) => {
      const product = bySlug.get(item.slug);
      const methods = Array.isArray(product?.deliveryMethods) ? product.deliveryMethods : [];
      const map = new Map(methods.map((method) => [method.method, method]));
      common = common === null ? map : new Map([...common].filter(([key]) => map.has(key)));
    });
    if (common === null) return [];
    if (common.size === 0) {
      return [{
        method: "dostawa-indywidualna",
        profileId: "dostawa-indywidualna",
        label: "Dostawa do ustalenia indywidualnie",
        cost: "do ustalenia",
        costNumber: null,
        requiresConfirmation: true,
        priceFrom: false,
        description: "Produkty w koszyku wymagają indywidualnego potwierdzenia wspólnego transportu."
      }];
    }
    return [...common.values()];
  };

  const updateCount = (cart) => {
    const count = cart.items.reduce((sum, item) => sum + item.quantity, 0);
    cartCounts.forEach((node) => {
      node.textContent = count > 0 ? `(${count})` : "";
    });
    if (checkoutLink) {
      checkoutLink.classList.toggle("is-disabled", count === 0);
      checkoutLink.setAttribute("aria-disabled", count === 0 ? "true" : "false");
    }
  };

  const showToast = (product) => {
    if (!cartToast) return;
    cartToast.hidden = false;
    cartToast.innerHTML = `
      <strong>Dodano do koszyka</strong>
      <span>${escapeHtml(product.name)}</span>
      <div class="shop-actions">
        <a class="btn btn-light" href="/sklep-test/figury-ogrodowe/koszyk">Zobacz koszyk</a>
        <a class="btn" href="/sklep-test/figury-ogrodowe/zamowienie">Przejdź do zamówienia</a>
      </div>
    `;
    clearTimeout(showToast.timer);
    showToast.timer = setTimeout(() => {
      cartToast.hidden = true;
    }, 4200);
  };

  const render = () => {
    const cart = cartWithValidItems();
    const deliveryMethods = commonDelivery(cart.items);
    if (cart.items.length > 0 && deliveryBox && !deliveryMethods.some((method) => method.method === cart.delivery)) {
      cart.delivery = deliveryMethods[0]?.method || "";
      localStorage.setItem(storageKey, JSON.stringify(cart));
    }

    updateCount(cart);

    if (!cartItems || !cartTotal) return;

    cartItems.innerHTML = "";
    const isEmpty = cart.items.length === 0;
    if (emptyActions) emptyActions.hidden = !isEmpty;
    if (form) form.classList.toggle("is-disabled", isEmpty);

    if (isEmpty) {
      if (!emptyActions) cartItems.innerHTML = "<p>Twój koszyk jest pusty.</p>";
      if (deliveryBox) deliveryBox.innerHTML = "";
      cartTotal.textContent = formatter.format(0);
      if (cartPayload) cartPayload.value = JSON.stringify(cart);
      return;
    }

    let productTotal = 0;
    cart.items.forEach((item) => {
      const product = bySlug.get(item.slug);
      const price = Number(product.price) || 0;
      productTotal += price * item.quantity;
      const row = document.createElement("div");
      row.className = "cart-row";
      row.innerHTML = `
        <img src="${escapeAttr(product.image)}" alt="" width="82" height="82">
        <div class="cart-row-main"><strong>${escapeHtml(product.name)}</strong><br><span>${formatter.format(price)} / szt.</span></div>
        <div class="qty">
          <button type="button" data-cart-minus="${escapeAttr(item.slug)}">-</button>
          <span>${item.quantity}</span>
          <button type="button" data-cart-plus="${escapeAttr(item.slug)}">+</button>
        </div>
        <button type="button" class="cart-clear" data-cart-remove="${escapeAttr(item.slug)}">Usuń</button>
      `;
      cartItems.appendChild(row);
    });

    let deliveryCost = 0;
    if (deliveryBox) {
      deliveryBox.innerHTML = "<strong>Dostawa</strong>";
      deliveryMethods.forEach((method) => {
        const inputId = `delivery-${method.method}`;
        const label = document.createElement("label");
        const costText = method.costNumber === null || method.costNumber === undefined ? method.cost : formatter.format(Number(method.costNumber));
        const description = method.description ? `<small>${escapeHtml(method.description)}</small>` : "";
        label.innerHTML = `<input type="radio" name="cart_delivery" id="${escapeAttr(inputId)}" value="${escapeAttr(method.method)}"${cart.delivery === method.method ? " checked" : ""}> <span><strong>${escapeHtml(method.label)}</strong> — ${escapeHtml(costText)}</span>${description}`;
        deliveryBox.appendChild(label);
        if (cart.delivery === method.method && method.costNumber !== null && method.costNumber !== undefined) {
          deliveryCost = Number(method.costNumber) || 0;
        }
      });
      if (deliveryMethods.some((method) => method.costNumber == null)) {
        const note = document.createElement("p");
        note.className = "delivery-note";
        note.textContent = "Dla wybranej dostawy koszt może zostać potwierdzony indywidualnie przed realizacją zamówienia.";
        deliveryBox.appendChild(note);
      }
    }

    const selectedDelivery = deliveryMethods.find((method) => method.method === cart.delivery);
    cartTotal.textContent = formatter.format(productTotal + deliveryCost) + (deliveryBox && selectedDelivery?.costNumber == null ? " + dostawa do ustalenia" : "");
    if (cartPayload) cartPayload.value = JSON.stringify(cart);
  };

  const parsePrice = (value) => {
    const normalized = String(value || "").replace(/\s/g, "").replace(",", ".");
    const match = normalized.match(/\d+(?:\.\d+)?/);
    return match ? Number(match[0]) : Number.POSITIVE_INFINITY;
  };

  const sortProductCards = () => {
    if (!productGrid || !sortSelect) return;
    const mode = sortSelect.value;
    const sorted = [...productCards].sort((a, b) => {
      if (mode === "price-asc") return parsePrice(a.dataset.price) - parsePrice(b.dataset.price);
      if (mode === "price-desc") return parsePrice(b.dataset.price) - parsePrice(a.dataset.price);
      if (mode === "name") return String(a.dataset.name || "").localeCompare(String(b.dataset.name || ""), "pl");
      return Number(a.dataset.defaultOrder || 0) - Number(b.dataset.defaultOrder || 0);
    });
    sorted.forEach((card) => productGrid.appendChild(card));
  };

  document.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    const disabledLink = target.closest("[data-checkout-link].is-disabled");
    if (disabledLink) {
      event.preventDefault();
      alert("Twój koszyk jest pusty.");
      return;
    }

    const addButton = target.closest("[data-add-to-cart]");
    const addSlug = addButton instanceof HTMLElement ? addButton.getAttribute("data-add-to-cart") : "";
    if (addSlug && bySlug.has(addSlug)) {
      const product = bySlug.get(addSlug);
      if (!product.canBuy) return;
      const cart = cartWithValidItems();
      const existing = cart.items.find((item) => item.slug === addSlug);
      if (existing) existing.quantity = Math.min(20, existing.quantity + 1);
      else cart.items.push({ slug: addSlug, quantity: 1 });
      saveCart(cart);
      showToast(product);
      if (!cartToast && addButton) {
        const previous = addButton.textContent;
        addButton.textContent = "Dodano do koszyka";
        setTimeout(() => { addButton.textContent = previous; }, 1400);
      }
    }

    const plusSlug = target.getAttribute("data-cart-plus");
    if (plusSlug) {
      const cart = cartWithValidItems();
      const item = cart.items.find((row) => row.slug === plusSlug);
      if (item) item.quantity = Math.min(20, item.quantity + 1);
      saveCart(cart);
    }

    const minusSlug = target.getAttribute("data-cart-minus");
    if (minusSlug) {
      const cart = cartWithValidItems();
      const item = cart.items.find((row) => row.slug === minusSlug);
      if (item) item.quantity = Math.max(1, item.quantity - 1);
      saveCart(cart);
    }

    const removeSlug = target.getAttribute("data-cart-remove");
    if (removeSlug) {
      const cart = cartWithValidItems();
      cart.items = cart.items.filter((item) => item.slug !== removeSlug);
      saveCart(cart);
    }

    if (target.matches("[data-cart-clear]")) {
      saveCart({ items: [], delivery: "" });
    }
  });

  document.addEventListener("change", (event) => {
    const target = event.target;
    if (target === sortSelect) {
      sortProductCards();
      return;
    }
    if (target instanceof HTMLInputElement && target.name === "cart_delivery") {
      const cart = cartWithValidItems();
      cart.delivery = target.value;
      saveCart(cart);
      return;
    }
    if (target instanceof HTMLInputElement && target.matches("[data-terms-checkbox]")) {
      target.setCustomValidity("");
    }
  });

  if (form) {
    form.addEventListener("submit", (event) => {
      const cart = cartWithValidItems();
      if (cart.items.length === 0) {
        event.preventDefault();
        alert("Twój koszyk jest pusty.");
        return;
      }
      const terms = form.querySelector("[data-terms-checkbox]");
      if (terms instanceof HTMLInputElement && !terms.checked) {
        event.preventDefault();
        terms.setCustomValidity("Aby złożyć zamówienie, zaakceptuj Regulamin sklepu.");
        terms.reportValidity();
        return;
      }
      if (terms instanceof HTMLInputElement) terms.setCustomValidity("");
      if (cartPayload) cartPayload.value = JSON.stringify(cart);
    });
  }

  render();
})();
