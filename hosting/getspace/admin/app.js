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

document.querySelectorAll("[data-google-action]").forEach((button) => {
  button.addEventListener("click", async () => {
    const form = button.closest("form");
    const result = form?.querySelector("[data-google-result]");
    const csrf = form?.querySelector('input[name="csrf"]')?.value || "";
    const index = form?.querySelector('input[name="index"]')?.value || "";
    if (!form || !result || csrf === "" || index === "") return;

    const previousText = button.textContent;
    button.disabled = true;
    button.textContent = "Przetwarzam...";
    result.hidden = false;
    result.className = "google-api-result";
    result.textContent = "Łączę z panelem...";

    const body = new FormData();
    body.set("csrf", csrf);
    body.set("index", index);
    body.set("google_action", button.dataset.googleAction || "");

    try {
      const response = await fetch("/admin/api/google-business.php", {
        method: "POST",
        body,
        credentials: "same-origin",
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.ok === false) {
        throw new Error(data.message || "Nie udało się wykonać akcji Google.");
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
      const missingConfig = data.configStatus?.missing?.length
        ? `\n\nBrakuje w konfiguracji: ${data.configStatus.missing.join(", ")}`
        : "";
      result.classList.add(data.dryRun ? "is-warning" : "is-success");
      result.textContent = `${data.message || "Gotowe."}${missingConfig}${locations}${details}`;
    } catch (error) {
      result.classList.add("is-error");
      result.textContent = error instanceof Error ? error.message : "Nie udało się wykonać akcji Google.";
    } finally {
      button.disabled = false;
      button.textContent = previousText;
    }
  });
});
