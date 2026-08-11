(function () {
  const products = Array.isArray(window.HGO_SHOP_PRODUCTS) ? window.HGO_SHOP_PRODUCTS : [];
  const salesEnabled = window.HGO_SHOP_SALES_ENABLED === true;
  const foreignShippingEnabled = window.HGO_FOREIGN_SHIPPING_ENABLED === true;
  const bySlug = new Map(products.map((product) => [product.slug, product]));
  const storageKey = "hgo-shop-test-cart";
  const formatter = new Intl.NumberFormat("pl-PL", { style: "currency", currency: "PLN" });
  const cartItems = document.querySelector("[data-cart-items]");
  const cartTotal = document.querySelector("[data-cart-total]");
  const cartTotalLabel = document.querySelector("[data-cart-total-label]");
  const productsTotal = document.querySelector("[data-products-total]");
  const deliveryTotal = document.querySelector("[data-delivery-total]");
  const deliveryBox = document.querySelector("[data-delivery-options]");
  const cartPayload = document.querySelector("[data-cart-payload]");
  const form = document.querySelector("[data-checkout-form]");
  const invoiceToggle = document.querySelector("[data-invoice-toggle]");
  const invoiceFields = document.querySelector("[data-invoice-fields]");
  const productGrid = document.querySelector("[data-shop-grid]");
  const sortSelect = document.querySelector("[data-shop-sort]");
  const cartToast = document.querySelector("[data-cart-toast]");
  const cartCounts = Array.from(document.querySelectorAll("[data-cart-count]"));
  const emptyActions = document.querySelector("[data-cart-empty-actions]");
  const checkoutLink = document.querySelector("[data-checkout-link]");
  const checkoutSubmit = form ? form.querySelector('[data-checkout-submit]') : null;
  const countrySelect = document.querySelector('[data-checkout-country]');
  const phonePrefixSelect = document.querySelector('[data-phone-prefix]');
  const postalCodeInput = document.querySelector('[data-postal-code]');
  const paymentStep = document.querySelector('[data-payment-step]');
  const foreignShippingNotice = document.querySelector('[data-foreign-shipping-notice]');
  const shipmentCheckNotice = document.querySelector("[data-shipment-check-notice]");
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

  const galleryModal = document.createElement("div");
  galleryModal.className = "gallery-modal";
  galleryModal.setAttribute("aria-hidden", "true");
  galleryModal.innerHTML = `
    <div class="gallery-backdrop" data-gallery-close></div>
    <section class="gallery-dialog" role="dialog" aria-modal="true" aria-labelledby="shop-gallery-title">
      <div class="gallery-toolbar">
        <h2 id="shop-gallery-title">Galeria produktu</h2>
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
  const galleryTitle = galleryModal.querySelector("#shop-gallery-title");
  const galleryThumbnails = galleryModal.querySelector(".gallery-thumbnails");
  const productGalleryTrigger = document.querySelector(".product-test-main.product-gallery-trigger");
  const productGalleryImage = productGalleryTrigger ? productGalleryTrigger.querySelector(".product-main-image") : null;
  const productGalleryThumbs = Array.from(document.querySelectorAll("[data-shop-gallery-index]"));
  let activeGalleryImages = [];
  let activeGalleryIndex = 0;
  let activeGalleryAlt = "";
  let galleryTouchStartX = 0;

  const parseGalleryImages = (trigger) => {
    try {
      const images = JSON.parse(trigger?.dataset.gallery || "[]");
      return Array.isArray(images) && images.length > 0 ? images : ["/product-table.jpeg"];
    } catch (error) {
      return ["/product-table.jpeg"];
    }
  };

  const updateGallery = () => {
    const image = activeGalleryImages[activeGalleryIndex] || "/product-table.jpeg";
    if (galleryMainImage instanceof HTMLImageElement) {
      galleryMainImage.src = image;
      galleryMainImage.alt = `${activeGalleryAlt || galleryTitle?.textContent || "Galeria produktu"} - zdjęcie ${activeGalleryIndex + 1}`;
    }
    if (galleryThumbnails) {
      galleryThumbnails.innerHTML = activeGalleryImages.map((path, index) => `
        <button class="gallery-thumbnail${index === activeGalleryIndex ? " active" : ""}" type="button" data-gallery-index="${index}" aria-label="Pokaż zdjęcie ${index + 1}">
          <img src="${escapeAttr(path)}" alt="" width="120" height="90" loading="lazy">
        </button>
      `).join("");
    }
    galleryModal.classList.toggle("single-image", activeGalleryImages.length < 2);
  };

  const openGallery = (images, name, imageAlt = "", startIndex = 0) => {
    activeGalleryImages = Array.isArray(images) && images.length > 0 ? images : ["/product-table.jpeg"];
    activeGalleryIndex = Math.max(0, Math.min(Number(startIndex) || 0, activeGalleryImages.length - 1));
    activeGalleryAlt = imageAlt;
    if (galleryTitle) galleryTitle.textContent = name || "Galeria produktu";
    updateGallery();
    galleryModal.classList.add("open");
    galleryModal.setAttribute("aria-hidden", "false");
    document.body.classList.add("gallery-open");
    const closeButton = galleryModal.querySelector(".gallery-close");
    if (closeButton instanceof HTMLButtonElement) closeButton.focus();
  };

  const closeGallery = () => {
    galleryModal.classList.remove("open");
    galleryModal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("gallery-open");
  };

  const moveGallery = (direction) => {
    if (activeGalleryImages.length < 2) return;
    activeGalleryIndex = (activeGalleryIndex + direction + activeGalleryImages.length) % activeGalleryImages.length;
    updateGallery();
  };

  const setProductGalleryIndex = (index) => {
    if (!productGalleryTrigger || !(productGalleryImage instanceof HTMLImageElement)) return;
    const images = parseGalleryImages(productGalleryTrigger);
    const nextIndex = Math.max(0, Math.min(Number(index) || 0, images.length - 1));
    productGalleryImage.src = images[nextIndex] || "/product-table.jpeg";
    productGalleryTrigger.dataset.galleryStart = String(nextIndex);
    productGalleryThumbs.forEach((thumb) => {
      thumb.classList.toggle("active", Number(thumb.getAttribute("data-shop-gallery-index") || 0) === nextIndex);
    });
  };

  const readCart = () => {
    try {
      const value = JSON.parse(localStorage.getItem(storageKey) || "{}");
      return Array.isArray(value.items) ? value : { items: [] };
    } catch (error) {
      return { items: [] };
    }
  };

  const cartWithValidItems = () => {
    const cart = readCart();
    const items = cart.items
      .filter((item) => bySlug.has(item.slug))
      .map((item) => ({ slug: item.slug, quantity: Math.max(1, Math.min(20, Number(item.quantity) || 1)), shippingProfileId: item.shippingProfileId || "" }));
    return { items };
  };

  const saveCart = (cart) => {
    if (!salesEnabled) return;
    localStorage.setItem(storageKey, JSON.stringify(cart));
    renderPerItemCart();
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
  };

  const deliveryRequiresConfirmation = (method) => Boolean(method?.requiresConfirmation) || method?.costNumber === null || method?.costNumber === undefined;

  const deliveryCostLabel = (method) => {
    if (deliveryRequiresConfirmation(method)) return "Koszt wymaga indywidualnego potwierdzenia";
    return Number(method.costNumber) === 0 ? "Bezpłatnie" : formatter.format(Number(method.costNumber));
  };

  const itemDeliveryMethods = (item) => Array.isArray(bySlug.get(item.slug)?.deliveryMethods) ? bySlug.get(item.slug).deliveryMethods : [];
  const isForeignCheckout = () => foreignShippingEnabled && countrySelect instanceof HTMLSelectElement && countrySelect.value !== 'PL';

  const updateCountryCheckoutUi = () => {
    const foreign = isForeignCheckout();
    if (paymentStep) paymentStep.hidden = foreign;
    if (foreignShippingNotice) foreignShippingNotice.hidden = !foreign;
    if (checkoutSubmit instanceof HTMLButtonElement) checkoutSubmit.textContent = foreign ? 'Złóż zamówienie do wyceny' : 'Kupuję i płacę';
    if (postalCodeInput instanceof HTMLInputElement) {
      postalCodeInput.maxLength = foreign ? 20 : 6;
      postalCodeInput.inputMode = foreign ? 'text' : 'numeric';
    }
    if (form) form.querySelectorAll('input[name="payment_method"]').forEach((input) => {
      if (input instanceof HTMLInputElement) { input.disabled = foreign; input.required = !foreign; }
    });
  };

  const setCheckoutAvailability = (cart) => {
    const hasDelivery = isForeignCheckout() || cart.items.every((item) => itemDeliveryMethods(item).some((method) => method.method === item.shippingProfileId));
    const canContinue = cart.items.length > 0 && hasDelivery;
    if (checkoutLink) {
      checkoutLink.classList.toggle("is-disabled", !canContinue);
      checkoutLink.setAttribute("aria-disabled", canContinue ? "false" : "true");
    }
    if (checkoutSubmit instanceof HTMLButtonElement) {
      checkoutSubmit.disabled = !canContinue;
      checkoutSubmit.setAttribute("aria-disabled", canContinue ? "false" : "true");
    }
  };

  const showToast = (product) => {
    if (!cartToast) return;
    cartToast.hidden = false;
    cartToast.innerHTML = `
      <strong>Dodano do koszyka</strong>
      <span>${escapeHtml(product.name)}</span>
      <div class="shop-actions">
        <a class="btn btn-light" href="/sklep/figury-ogrodowe/koszyk">Zobacz koszyk</a>
        <a class="btn" href="/sklep/figury-ogrodowe/zamowienie">Przejdź do zamówienia</a>
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
    if (cart.items.length > 0 && !deliveryMethods.some((method) => method.method === cart.delivery)) {
      cart.delivery = deliveryMethods.length === 1 ? deliveryMethods[0]?.method || "" : "";
      cart.deliverySelected = deliveryMethods.length === 1;
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
      if (productsTotal) productsTotal.textContent = formatter.format(0);
      if (deliveryTotal) deliveryTotal.textContent = "—";
      if (cartTotalLabel) cartTotalLabel.textContent = "Razem";
      cartTotal.textContent = formatter.format(0);
      if (shipmentCheckNotice) shipmentCheckNotice.hidden = true;
      setCheckoutAvailability(cart, deliveryMethods);
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
        const costText = deliveryCostLabel(method);
        const description = method.description ? `<small>${escapeHtml(method.description)}</small>` : "";
        label.innerHTML = `<input type="radio" name="cart_delivery" id="${escapeAttr(inputId)}" value="${escapeAttr(method.method)}"${cart.delivery === method.method ? " checked" : ""}> <span><strong>${escapeHtml(method.label)}</strong> — ${escapeHtml(costText)}</span>${description}`;
        deliveryBox.appendChild(label);
        if (cart.delivery === method.method && !deliveryRequiresConfirmation(method)) {
          deliveryCost = Number(method.costNumber) || 0;
        }
      });
      const selectedDelivery = deliveryMethods.find((method) => method.method === cart.delivery);
      if (selectedDelivery && deliveryRequiresConfirmation(selectedDelivery)) {
        const note = document.createElement("p");
        note.className = "delivery-note";
        note.textContent = "Koszt dostawy wymaga indywidualnego potwierdzenia. Po złożeniu zamówienia skontaktujemy się z Tobą w celu ustalenia kosztu dostawy. Płatność wykonasz dopiero po otrzymaniu pełnej kwoty zamówienia.";
        deliveryBox.appendChild(note);
      }
    }

    const selectedDelivery = deliveryMethods.find((method) => method.method === cart.delivery);
    cartTotal.textContent = formatter.format(productTotal + deliveryCost) + (deliveryBox && selectedDelivery?.costNumber == null ? " + dostawa do ustalenia" : "");
    const quoteRequired = selectedDelivery && deliveryRequiresConfirmation(selectedDelivery);
    if (productsTotal) productsTotal.textContent = formatter.format(productTotal);
    if (deliveryTotal) deliveryTotal.textContent = selectedDelivery ? deliveryCostLabel(selectedDelivery) : "Wybierz sposób dostawy";
    if (!selectedDelivery) {
      if (cartTotalLabel) cartTotalLabel.textContent = "Wybierz sposób dostawy";
      cartTotal.textContent = "—";
    } else if (quoteRequired) {
      if (cartTotalLabel) cartTotalLabel.textContent = "Pełna kwota po potwierdzeniu dostawy";
      cartTotal.textContent = "—";
    } else {
      if (cartTotalLabel) cartTotalLabel.textContent = "Razem";
      cartTotal.textContent = formatter.format(productTotal + deliveryCost);
    }
    setCheckoutAvailability(cart, deliveryMethods);
    if (cartPayload) cartPayload.value = JSON.stringify(cart);
  };

  const renderPerItemCart = () => {
    const cart = cartWithValidItems();
    updateCountryCheckoutUi();
    let changed = false;
    cart.items.forEach((item) => {
      const methods = itemDeliveryMethods(item);
      if (!methods.some((method) => method.method === item.shippingProfileId)) {
        item.shippingProfileId = methods.length === 1 ? methods[0].method : "";
        changed = true;
      }
    });
    if (changed) localStorage.setItem(storageKey, JSON.stringify(cart));
    updateCount(cart);
    if (!cartItems || !cartTotal) return;
    if (deliveryBox) {
      const section = deliveryBox.closest('.checkout-step');
      if (section) section.hidden = true;
    }
    cartItems.innerHTML = "";
    const isEmpty = cart.items.length === 0;
    if (emptyActions) emptyActions.hidden = !isEmpty;
    if (isEmpty) {
      if (!emptyActions) cartItems.innerHTML = "<p>Twój koszyk jest pusty.</p>";
      if (productsTotal) productsTotal.textContent = formatter.format(0);
      if (deliveryTotal) deliveryTotal.textContent = "—";
      if (cartTotalLabel) cartTotalLabel.textContent = "Razem";
      cartTotal.textContent = formatter.format(0);
      setCheckoutAvailability(cart);
      if (cartPayload) cartPayload.value = JSON.stringify(cart);
      return;
    }

    if (isForeignCheckout()) {
      let productTotal = 0;
      cart.items.forEach((item) => {
        const product = bySlug.get(item.slug);
        const price = Number(product.price) || 0;
        productTotal += price * item.quantity;
        const row = document.createElement("div");
        row.className = "cart-row";
        row.innerHTML = `<img src="${escapeAttr(product.image)}" alt="" width="82" height="82"><div class="cart-row-main"><strong>${escapeHtml(product.name)}</strong><br><span>${formatter.format(price)} / szt.</span></div><div class="qty"><button type="button" data-cart-minus="${escapeAttr(item.slug)}">-</button><span>${item.quantity}</span><button type="button" data-cart-plus="${escapeAttr(item.slug)}">+</button></div><button type="button" class="cart-clear" data-cart-remove="${escapeAttr(item.slug)}">Usuń</button>`;
        cartItems.appendChild(row);
      });
      if (productsTotal) productsTotal.textContent = formatter.format(productTotal);
      if (deliveryTotal) deliveryTotal.textContent = 'Do indywidualnej wyceny';
      if (cartTotalLabel) cartTotalLabel.textContent = 'Kwota końcowa po wycenie dostawy';
      cartTotal.textContent = '—';
      if (shipmentCheckNotice) shipmentCheckNotice.hidden = true;
      setCheckoutAvailability(cart);
      if (cartPayload) cartPayload.value = JSON.stringify(cart);
      return;
    }

    let productTotal = 0;
    let shippingTotal = 0;
    let quoteRequired = false;
    let hasShipment = false;
    cart.items.forEach((item) => {
      const product = bySlug.get(item.slug);
      const price = Number(product.price) || 0;
      const methods = itemDeliveryMethods(item);
      const selected = methods.find((method) => method.method === item.shippingProfileId);
      productTotal += price * item.quantity;
      if (selected && !deliveryRequiresConfirmation(selected)) shippingTotal += (Number(selected.costNumber) || 0) * item.quantity;
      if (selected && deliveryRequiresConfirmation(selected)) quoteRequired = true;
      if (selected && selected.type !== "odbior_osobisty") hasShipment = true;
      const options = methods.map((method) => {
        const inputId = `item-delivery-${item.slug}-${method.method}`;
        return `<label><input type="radio" name="item_delivery_${escapeAttr(item.slug)}" value="${escapeAttr(method.method)}" data-item-shipping="${escapeAttr(item.slug)}"${item.shippingProfileId === method.method ? " checked" : ""}> <span><strong>${escapeHtml(method.label)}</strong> — ${escapeHtml(deliveryCostLabel(method))}</span></label>`;
      }).join("");
      const selectedText = selected ? `${escapeHtml(selected.label)}: ${escapeHtml(deliveryCostLabel(selected))}` : "Wybierz sposób dostawy";
      const lineShipping = selected && !deliveryRequiresConfirmation(selected) ? formatter.format((Number(selected.costNumber) || 0) * item.quantity) : "Koszt do potwierdzenia";
      const row = document.createElement("div");
      row.className = "cart-row cart-row-delivery";
      row.innerHTML = `
        <img src="${escapeAttr(product.image)}" alt="" width="82" height="82">
        <div class="cart-row-main"><strong>${escapeHtml(product.name)}</strong><br><span>${formatter.format(price)} / szt.</span><div class="item-delivery"><strong>Sposób dostawy</strong>${options}<small>${selectedText}${selected ? ` · ${item.quantity} × ${lineShipping}` : ""}</small></div></div>
        <div class="qty"><button type="button" data-cart-minus="${escapeAttr(item.slug)}">-</button><span>${item.quantity}</span><button type="button" data-cart-plus="${escapeAttr(item.slug)}">+</button></div>
        <button type="button" class="cart-clear" data-cart-remove="${escapeAttr(item.slug)}">Usuń</button>
      `;
      cartItems.appendChild(row);
    });
    if (productsTotal) productsTotal.textContent = formatter.format(productTotal);
    if (deliveryTotal) deliveryTotal.textContent = quoteRequired ? "Koszt wymaga indywidualnego potwierdzenia" : formatter.format(shippingTotal);
    if (quoteRequired) {
      if (cartTotalLabel) cartTotalLabel.textContent = "Pełna kwota po potwierdzeniu dostawy";
      cartTotal.textContent = "—";
    } else {
      if (cartTotalLabel) cartTotalLabel.textContent = "Razem";
      cartTotal.textContent = formatter.format(productTotal + shippingTotal);
    }
    setCheckoutAvailability(cart);
    if (shipmentCheckNotice) shipmentCheckNotice.hidden = !hasShipment;
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

    const productThumb = target.closest("[data-shop-gallery-index]");
    if (productThumb instanceof HTMLElement) {
      event.preventDefault();
      setProductGalleryIndex(Number(productThumb.getAttribute("data-shop-gallery-index") || 0));
      return;
    }

    const galleryTrigger = target.closest(".product-gallery-trigger");
    const closeTrigger = target.closest("[data-gallery-close]");
    const previousTrigger = target.closest("[data-gallery-prev]");
    const nextTrigger = target.closest("[data-gallery-next]");
    const modalThumbnail = target.closest("[data-gallery-index]");

    if (galleryTrigger instanceof HTMLElement) {
      event.preventDefault();
      openGallery(
        parseGalleryImages(galleryTrigger),
        galleryTrigger.dataset.galleryName || "Galeria produktu",
        galleryTrigger.dataset.galleryAlt || "",
        Number(galleryTrigger.dataset.galleryStart || 0)
      );
      return;
    }
    if (closeTrigger) {
      event.preventDefault();
      closeGallery();
      return;
    }
    if (previousTrigger) {
      event.preventDefault();
      moveGallery(-1);
      return;
    }
    if (nextTrigger) {
      event.preventDefault();
      moveGallery(1);
      return;
    }
    if (modalThumbnail instanceof HTMLElement) {
      event.preventDefault();
      activeGalleryIndex = Number(modalThumbnail.getAttribute("data-gallery-index") || 0);
      updateGallery();
      return;
    }

    const disabledLink = target.closest("[data-checkout-link].is-disabled");
    if (disabledLink) {
      if (cartWithValidItems().items.length > 0) {
        event.preventDefault();
        alert("Wybierz sposób dostawy przed przejściem do zamówienia.");
        return;
      }
      event.preventDefault();
      alert("Twój koszyk jest pusty.");
      return;
    }

    const addButton = target.closest("[data-add-to-cart]");
    const addSlug = addButton instanceof HTMLElement ? addButton.getAttribute("data-add-to-cart") : "";
    if (salesEnabled && addSlug && bySlug.has(addSlug)) {
      const product = bySlug.get(addSlug);
      if (!product.canBuy) return;
      const cart = cartWithValidItems();
      const existing = cart.items.find((item) => item.slug === addSlug);
      if (existing) existing.quantity = Math.min(20, existing.quantity + 1);
      else cart.items.push({ slug: addSlug, quantity: 1, shippingProfileId: "" });
      saveCart(cart);
      showToast(product);
      if (!cartToast && addButton) {
        const previous = addButton.textContent;
        addButton.textContent = "Dodano do koszyka";
        setTimeout(() => { addButton.textContent = previous; }, 1400);
      }
    }

    const plusSlug = target.getAttribute("data-cart-plus");
    if (salesEnabled && plusSlug) {
      const cart = cartWithValidItems();
      const item = cart.items.find((row) => row.slug === plusSlug);
      if (item) item.quantity = Math.min(20, item.quantity + 1);
      saveCart(cart);
    }

    const minusSlug = target.getAttribute("data-cart-minus");
    if (salesEnabled && minusSlug) {
      const cart = cartWithValidItems();
      const item = cart.items.find((row) => row.slug === minusSlug);
      if (item) item.quantity = Math.max(1, item.quantity - 1);
      saveCart(cart);
    }

    const removeSlug = target.getAttribute("data-cart-remove");
    if (salesEnabled && removeSlug) {
      const cart = cartWithValidItems();
      cart.items = cart.items.filter((item) => item.slug !== removeSlug);
      saveCart(cart);
    }

    if (salesEnabled && target.matches("[data-cart-clear]")) {
      saveCart({ items: [] });
    }
  });

  document.addEventListener("error", (event) => {
    if (event.target instanceof HTMLImageElement && !event.target.dataset.fallbackApplied) {
      event.target.dataset.fallbackApplied = "true";
      event.target.src = "/product-table.jpeg";
    }
  }, true);

  document.addEventListener("keydown", (event) => {
    if (!galleryModal.classList.contains("open")) return;
    if (event.key === "Escape") closeGallery();
    if (event.key === "ArrowLeft") moveGallery(-1);
    if (event.key === "ArrowRight") moveGallery(1);
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

  document.addEventListener("change", (event) => {
    const target = event.target;
    if (target === sortSelect) {
      sortProductCards();
      return;
    }
    if (target instanceof HTMLInputElement && target.matches("[data-item-shipping]")) {
      const cart = cartWithValidItems();
      const item = cart.items.find((row) => row.slug === target.dataset.itemShipping);
      if (item) item.shippingProfileId = target.value;
      saveCart(cart);
      return;
    }
    if (target instanceof HTMLInputElement && target.matches("[data-terms-checkbox]")) {
      target.setCustomValidity("");
    }
    if (target === countrySelect) {
      const callingCode = countrySelect.selectedOptions[0]?.dataset.callingCode;
      if (callingCode && phonePrefixSelect instanceof HTMLSelectElement) phonePrefixSelect.value = `+${callingCode}`;
      if (countrySelect.value !== 'PL') {
        const cart = cartWithValidItems();
        cart.items.forEach((item) => { item.shippingProfileId = ''; });
        localStorage.setItem(storageKey, JSON.stringify(cart));
      }
      renderPerItemCart();
      return;
    }
  });

  if (form && salesEnabled) {
    form.addEventListener("submit", (event) => {
      const cart = cartWithValidItems();
      if (cart.items.length === 0) {
        event.preventDefault();
        alert("Twój koszyk jest pusty.");
        return;
      }
      if (!cart.items.every((item) => itemDeliveryMethods(item).some((method) => method.method === item.shippingProfileId))) {
        event.preventDefault();
        alert("Wybierz sposób dostawy dla każdego produktu przed złożeniem zamówienia.");
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
      if (checkoutSubmit instanceof HTMLButtonElement) {
        checkoutSubmit.disabled = true;
        checkoutSubmit.textContent = "Przetwarzanie zamówienia…";
      }
    });
  }

  const updateInvoiceFields = () => {
    if (!(invoiceToggle instanceof HTMLInputElement) || !invoiceFields) return;
    const requested = invoiceToggle.checked;
    invoiceFields.hidden = !requested;
    invoiceFields.querySelectorAll("input").forEach((field) => {
      field.required = requested && (field.name === "invoice_company_name" || field.name === "invoice_nip");
    });
  };
  if (invoiceToggle) {
    invoiceToggle.addEventListener("change", updateInvoiceFields);
    updateInvoiceFields();
  }

  if (!salesEnabled) {
    try { localStorage.removeItem(storageKey); } catch (error) {}
  }
  renderPerItemCart();
})();
