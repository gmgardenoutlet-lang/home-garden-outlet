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

  const readCart = () => {
    try {
      const value = JSON.parse(localStorage.getItem(storageKey) || "{}");
      return Array.isArray(value.items) ? value : { items: [], delivery: "" };
    } catch (error) {
      return { items: [], delivery: "" };
    }
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
      return [{ method: "individual", label: "Transport / dostawa do ustalenia indywidualnie", cost: "do ustalenia", costNumber: null }];
    }
    return [...common.values()];
  };

  const cartWithValidItems = () => {
    const cart = readCart();
    const items = cart.items
      .filter((item) => bySlug.has(item.slug))
      .map((item) => ({ slug: item.slug, quantity: Math.max(1, Math.min(20, Number(item.quantity) || 1)) }));
    return { items, delivery: cart.delivery || "" };
  };

  const render = () => {
    if (!cartItems || !cartTotal) return;
    const cart = cartWithValidItems();
    const deliveryMethods = commonDelivery(cart.items);
    if (cart.items.length > 0 && !deliveryMethods.some((method) => method.method === cart.delivery)) {
      cart.delivery = deliveryMethods[0]?.method || "";
      localStorage.setItem(storageKey, JSON.stringify(cart));
    }

    cartItems.innerHTML = "";
    if (cart.items.length === 0) {
      cartItems.innerHTML = "<p>Koszyk jest pusty.</p>";
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
        <div><strong>${escapeHtml(product.name)}</strong><br><span>${formatter.format(price)} / szt.</span></div>
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
        label.innerHTML = `<input type="radio" name="cart_delivery" id="${escapeAttr(inputId)}" value="${escapeAttr(method.method)}"${cart.delivery === method.method ? " checked" : ""}> <span>${escapeHtml(method.label)} — ${escapeHtml(costText)}</span>`;
        deliveryBox.appendChild(label);
        if (cart.delivery === method.method && method.costNumber !== null && method.costNumber !== undefined) {
          deliveryCost = Number(method.costNumber) || 0;
        }
      });
    }

    cartTotal.textContent = formatter.format(productTotal + deliveryCost) + (deliveryCost === 0 && deliveryMethods.find((method) => method.method === cart.delivery)?.costNumber == null ? " + dostawa do ustalenia" : "");
    if (cartPayload) cartPayload.value = JSON.stringify(cart);
  };

  const escapeHtml = (value) => String(value || "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[char]));
  const escapeAttr = escapeHtml;

  document.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    const addSlug = target.getAttribute("data-add-to-cart");
    if (addSlug && bySlug.has(addSlug)) {
      const product = bySlug.get(addSlug);
      if (!product.canBuy) return;
      const cart = cartWithValidItems();
      const existing = cart.items.find((item) => item.slug === addSlug);
      if (existing) existing.quantity = Math.min(20, existing.quantity + 1);
      else cart.items.push({ slug: addSlug, quantity: 1 });
      saveCart(cart);
      if (!cartItems) {
        const previous = target.textContent;
        target.textContent = "Dodano do koszyka";
        setTimeout(() => { target.textContent = previous; }, 1400);
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
    if (!(target instanceof HTMLInputElement) || target.name !== "cart_delivery") return;
    const cart = cartWithValidItems();
    cart.delivery = target.value;
    saveCart(cart);
  });

  if (form) {
    form.addEventListener("submit", (event) => {
      const cart = cartWithValidItems();
      if (cart.items.length === 0) {
        event.preventDefault();
        alert("Koszyk jest pusty.");
        return;
      }
      if (cartPayload) cartPayload.value = JSON.stringify(cart);
    });
  }

  render();
})();
