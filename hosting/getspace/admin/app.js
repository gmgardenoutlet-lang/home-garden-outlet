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
