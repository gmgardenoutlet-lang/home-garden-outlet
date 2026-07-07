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
