document.addEventListener("change", (event) => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement) || input.type !== "file") return;

  const preview = input.closest(".upload-field")?.querySelector(".upload-preview");
  if (!preview) return;

  preview.innerHTML = "";
  [...input.files].slice(0, 8).forEach((file) => {
    const image = document.createElement("img");
    image.src = URL.createObjectURL(file);
    image.alt = "Podgląd wybranego zdjęcia";
    image.onload = () => URL.revokeObjectURL(image.src);
    preview.appendChild(image);
  });
});

document.querySelectorAll("[data-confirm]").forEach((button) => {
  button.addEventListener("click", (event) => {
    if (!window.confirm(button.dataset.confirm || "Czy na pewno?")) {
      event.preventDefault();
    }
  });
});

document.querySelectorAll("[data-password-toggle]").forEach((button) => {
  button.addEventListener("click", () => {
    const input = document.getElementById(button.dataset.passwordToggle);
    if (!input) return;
    input.type = input.type === "password" ? "text" : "password";
    button.textContent = input.type === "password" ? "Pokaż" : "Ukryj";
  });
});

document.querySelectorAll("[data-copy-target]").forEach((button) => {
  button.addEventListener("click", async () => {
    const target = document.getElementById(button.dataset.copyTarget || "");
    if (!(target instanceof HTMLTextAreaElement || target instanceof HTMLInputElement)) return;

    try {
      await navigator.clipboard.writeText(target.value);
      const previous = button.textContent;
      button.textContent = "Skopiowano";
      window.setTimeout(() => {
        button.textContent = previous;
      }, 1800);
    } catch (error) {
      target.focus();
      target.select();
      button.textContent = "Zaznaczono tekst";
    }
  });
});

(() => {
  const saleType = document.querySelector("[data-sale-type]");
  const shopFields = Array.from(document.querySelectorAll("[data-shop-fields]"));
  if (!(saleType instanceof HTMLSelectElement) || shopFields.length === 0) return;

  const updateShopFields = () => {
    const isFigure = saleType.value === "garden_figure";
    shopFields.forEach((field) => {
      field.hidden = !isFigure;
    });
  };

  saleType.addEventListener("change", updateShopFields);
  updateShopFields();
})();

(() => {
  const root = document.querySelector("[data-traffic-chart]");
  if (!root) return;
  const source = root.querySelector("[data-traffic-chart-data]");
  const svg = root.querySelector(".traffic-chart");
  const tooltip = root.querySelector(".traffic-tooltip");
  if (!(source instanceof HTMLScriptElement) || !(svg instanceof SVGElement) || !(tooltip instanceof HTMLElement)) return;
  let data;
  try { data = JSON.parse(source.textContent || "{}"); } catch (_) { return; }
  const rows = Array.isArray(data.rows) ? data.rows : [];
  const toggles = [...root.querySelectorAll("[data-traffic-series], [data-traffic-average]")];
  const ns = "http://www.w3.org/2000/svg";
  const chart = { width: 900, height: 320, left: 54, right: 18, top: 18, bottom: 42 };
  const formatDate = (value) => value ? value.slice(8, 10) + "." + value.slice(5, 7) + "." + value.slice(0, 4) : "";
  const element = (name, attrs = {}, text = "") => { const node = document.createElementNS(ns, name); Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, String(value))); node.textContent = text; return node; };
  const movingAverage = () => rows.map((row, index) => {
    const sample = rows.slice(Math.max(0, index - 6), index + 1).map((item) => item.page_view).filter((value) => Number.isFinite(value));
    return sample.length ? sample.reduce((sum, value) => sum + value, 0) / sample.length : null;
  });
  const render = () => {
    const pageEnabled = root.querySelector('[data-traffic-series="page_view"]')?.checked;
    const productEnabled = root.querySelector('[data-traffic-series="product_view"]')?.checked;
    const averageEnabled = root.querySelector('[data-traffic-average]')?.checked;
    const average = movingAverage();
    const visible = [];
    if (pageEnabled) visible.push(...rows.map((row) => row.page_view));
    if (productEnabled) visible.push(...rows.map((row) => row.product_view));
    if (averageEnabled && pageEnabled) visible.push(...average);
    const max = Math.max(1, ...visible.filter(Number.isFinite));
    const top = Math.ceil(max / 5) * 5 || 1;
    const plotWidth = chart.width - chart.left - chart.right, plotHeight = chart.height - chart.top - chart.bottom;
    const x = (index) => chart.left + (rows.length < 2 ? plotWidth / 2 : (index / (rows.length - 1)) * plotWidth);
    const y = (value) => chart.top + plotHeight - (value / top) * plotHeight;
    svg.replaceChildren();
    for (let step = 0; step <= 4; step++) { const value = Math.round((top / 4) * step); const pointY = y(value); svg.append(element("line", { x1: chart.left, x2: chart.width - chart.right, y1: pointY, y2: pointY, class: "grid" })); svg.append(element("text", { x: chart.left - 8, y: pointY + 4, "text-anchor": "end", class: "axis-label" }, value)); }
    const labelEvery = rows.length <= 7 ? 1 : rows.length <= 28 ? 4 : 14;
    rows.forEach((row, index) => { if (index % labelEvery === 0 || index === rows.length - 1) svg.append(element("text", { x: x(index), y: chart.height - 14, "text-anchor": index === rows.length - 1 ? "end" : "middle", class: "axis-label" }, row.date ? row.date.slice(8, 10) + "." + row.date.slice(5, 7) : "—")); });
    const drawSeries = (values, className, points) => { let segment = []; const flush = () => { if (segment.length > 1) svg.append(element("polyline", { points: segment.map((point) => point.join(",")).join(" "), class: className })); segment = []; }; values.forEach((value, index) => { if (!Number.isFinite(value)) { flush(); return; } segment.push([x(index), y(value)]); if (points) svg.append(element("circle", { cx: x(index), cy: y(value), r: 3.8, class: "point", fill: points })); }); flush(); };
    if (pageEnabled) drawSeries(rows.map((row) => row.page_view), "line-page", "#1e6b45");
    if (productEnabled) drawSeries(rows.map((row) => row.product_view), "line-product", "#c27724");
    if (averageEnabled && pageEnabled) drawSeries(average, "line-average");
    const show = (event) => { const bounds = svg.getBoundingClientRect(); const index = Math.max(0, Math.min(rows.length - 1, Math.round(((event.clientX - bounds.left) / bounds.width) * Math.max(rows.length - 1, 0)))); const row = rows[index]; if (!row) return; const lines = [formatDate(row.date) + (row.date === data.today ? " · dzisiaj — dzień w toku" : "")]; if (pageEnabled) lines.push("Odsłony stron: " + (Number.isFinite(row.page_view) ? row.page_view : "brak danych")); if (productEnabled) lines.push("Wyświetlenia produktów: " + (Number.isFinite(row.product_view) ? row.product_view : "brak danych")); tooltip.innerHTML = lines.map((line) => "<div>" + line + "</div>").join(""); tooltip.hidden = false; tooltip.style.left = Math.min(root.clientWidth - 238, Math.max(8, event.clientX - bounds.left + 12)) + "px"; tooltip.style.top = "16px"; };
    svg.onpointermove = show; svg.onpointerleave = () => { tooltip.hidden = true; };
  };
  toggles.forEach((toggle) => toggle.addEventListener("change", render));
  render();
})();

(() => {
  const googleText = document.getElementById("googleText");
  if (!(googleText instanceof HTMLTextAreaElement)) return;

  const nameInput = document.getElementById("name");
  const outletPriceInput = document.getElementById("outletPrice");
  const statusInput = document.getElementById("status");

  const fieldValue = (field) => field instanceof HTMLInputElement || field instanceof HTMLSelectElement
    ? field.value.trim()
    : "";

  const normalize = (value) => value.replace(/\s+/g, " ").trim();

  const buildGoogleText = () => {
    const name = fieldValue(nameInput) || "Produkt z oferty";
    const outletPrice = fieldValue(outletPriceInput);
    const status = fieldValue(statusInput).toLowerCase();
    const isSold = status.includes("sprzedane") || status.includes("sprzedany");
    const parts = [
      `${name} dostępny w Home & Garden Outlet w Kębłowicach pod Wrocławiem.`,
    ];

    if (outletPrice !== "") {
      parts.push(`Cena outletowa: ${outletPrice}.`);
    }

    if (isSold) {
      parts.push("Produkt może być już niedostępny, ale możesz zadzwonić i zapytać o podobne meble z aktualnej oferty.");
    } else {
      parts.push("Produkt można obejrzeć na żywo w naszym showroomie.");
      parts.push("Oferta outletowa - często pojedyncza sztuka lub końcówka kolekcji.");
      parts.push("Przed przyjazdem warto zadzwonić pod numer 577 210 777 i potwierdzić dostępność.");
    }

    return parts.join(" ");
  };

  let lastAutoText = buildGoogleText();
  let userEditedGoogleText = googleText.value.trim() !== "" && normalize(googleText.value) !== normalize(lastAutoText);

  const shouldReplaceCurrentText = () => {
    const current = normalize(googleText.value);
    return current === ""
      || current === normalize(lastAutoText)
      || current.startsWith("Produkt z oferty dostępny w Home & Garden Outlet");
  };

  const updateGoogleText = () => {
    if (userEditedGoogleText && !shouldReplaceCurrentText()) return;
    if (!shouldReplaceCurrentText()) return;

    lastAutoText = buildGoogleText();
    googleText.value = lastAutoText;
    userEditedGoogleText = false;
  };

  googleText.addEventListener("input", () => {
    userEditedGoogleText = normalize(googleText.value) !== normalize(lastAutoText);
  });

  [nameInput, outletPriceInput, statusInput].forEach((field) => {
    if (!field) return;
    field.addEventListener("input", updateGoogleText);
    field.addEventListener("change", updateGoogleText);
  });

  updateGoogleText();
})();

document.querySelectorAll("[data-google-action]").forEach((button) => {
  button.addEventListener("click", async () => {
    const form = button.closest("form");
    const result = form?.querySelector("[data-google-result]");
    const csrf = form?.querySelector('input[name="csrf"]')?.value || "";
    const index = form?.querySelector('input[name="index"]')?.value || "";
    const googleAction = button.dataset.googleAction || "";
    const needsProduct = !["config_status", "discover_locations", "refresh_reviews"].includes(googleAction);
    if (!form || !result || csrf === "" || (needsProduct && index === "")) return;

    const previousText = button.textContent;
    button.disabled = true;
    button.textContent = "Przetwarzam...";
    result.hidden = false;
    result.className = "google-api-result";
    result.textContent = "Łączę z panelem...";

    const body = new FormData();
    body.set("csrf", csrf);
    if (index !== "") {
      body.set("index", index);
    }
    body.set("google_action", googleAction);

    try {
      const response = await fetch("/admin/api/google-business.php", {
        method: "POST",
        body,
        credentials: "same-origin",
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.ok === false) {
        const errorDetails = data.error ? `\n\nSzczegóły: ${data.error}` : "";
        throw new Error((data.message || "Nie udało się wykonać akcji Google.") + errorDetails);
      }

      const updates = data.productUpdates || {};
      Object.entries(updates).forEach(([field, value]) => {
        const safeField = window.CSS?.escape ? CSS.escape(field) : field.replace(/["\\]/g, "");
        const input = form.querySelector(`[name="${safeField}"]`);
        if (input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement || input instanceof HTMLSelectElement) {
          input.value = value;
        }
      });

      const details = data.payload
        ? `\n\nProdukt: ${data.payload.name || ""}\nURL produktu: ${data.payload.productUrl || ""}\nZdjęcie: ${data.payload.imageUrl || ""}\n\nTreść:\n${data.payload.summary || ""}`
        : "";
      const locations = Array.isArray(data.locations) && data.locations.length
        ? `\n\nZnalezione wizytówki:\n${data.locations.map((item, index) => `${index + 1}. ${item.locationName || "Wizytówka"}\n   Account ID: ${item.accountId || ""}\n   Location ID: ${item.locationId || ""}\n   Adres: ${item.address || "-"}\n   WWW: ${item.website || "-"}`).join("\n\n")}`
        : "";
      const reviewsInfo = Number.isInteger(data.reviewsCount)
        ? `\n\nPobrane opinie: ${data.reviewsCount}\nAktualizacja: ${data.updatedAt || "-"}`
        : "";
      const missingConfig = data.configStatus?.missing?.length
        ? `\n\nBrakuje w konfiguracji: ${data.configStatus.missing.join(", ")}`
        : "";
      result.classList.add(data.dryRun ? "is-warning" : "is-success");
      result.textContent = `${data.message || "Gotowe."}${missingConfig}${locations}${reviewsInfo}${details}`;
    } catch (error) {
      result.classList.add("is-error");
      result.textContent = error instanceof Error ? error.message : "Nie udało się wykonać akcji Google.";
    } finally {
      button.disabled = false;
      button.textContent = previousText;
    }
  });
});
