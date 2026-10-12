import json
import re
import sys
from pathlib import Path

from PIL import Image, ImageOps


if "--banners" in sys.argv:
    # Explicit allowlist: this mode never reads or writes the product catalogue.
    banner_root = Path(__file__).resolve().parent.parent
    output = banner_root / "assets" / "images" / "banners"
    output.mkdir(parents=True, exist_ok=True)
    sources = {
        "showroom": ("showroom-best-optimized.jpg", (960, 1100, 1400)),
        "dom": ("dom-optimized.jpg", (480, 768, 1086)),
        "ogrod": ("ogrod-optimized.jpg", (480, 768, 1086)),
        "figury": ("assets/images/figury-ogrodowe-hero.webp", (640, 960, 1280, 1600)),
    }
    for name, (source, widths) in sources.items():
        with Image.open(banner_root / source) as original:
            image = ImageOps.exif_transpose(original).convert("RGB")
            for width in widths:
                width = min(width, image.width)
                height = round(image.height * width / image.width)
                target = output / f"{name}-{width}.webp"
                image.resize((width, height), Image.Resampling.LANCZOS).save(
                    target, "WEBP", quality=84, method=6
                )
                print(f"{target.relative_to(banner_root)}: {width}x{height}, {target.stat().st_size} B")
    raise SystemExit(0)


root = Path(sys.argv[1] if len(sys.argv) > 1 else "publish").resolve()
products_path = root / "data" / "products.json"
data = json.loads(products_path.read_text(encoding="utf-8"))
products = data.get("products", [])


def optimized_path(value: str) -> str:
    clean = str(value or "")
    if not clean.lower().startswith("/uploads/"):
        return clean

    without_extension = re.sub(r"\.(?:jpe?g|png)$", "", clean, flags=re.IGNORECASE)
    if without_extension.lower().endswith(".webp"):
        return without_extension
    return f"{without_extension}.webp"


def convert_image(source_value: str) -> str:
    target_value = optimized_path(source_value)
    if target_value == source_value:
        return source_value

    source = root / source_value.lstrip("/")
    target = root / target_value.lstrip("/")
    if not source.exists():
        print(f"OSTRZEŻENIE: brak zdjęcia {source_value}")
        return source_value

    target.parent.mkdir(parents=True, exist_ok=True)
    with Image.open(source) as original:
        image = ImageOps.exif_transpose(original)
        image.thumbnail((2000, 2000), Image.Resampling.LANCZOS)

        if image.mode not in ("RGB", "RGBA"):
            image = image.convert("RGBA" if "transparency" in image.info else "RGB")

        image.save(target, "WEBP", quality=84, method=6)

    if source.resolve() != target.resolve():
        source.unlink()

    return target_value


for product in products:
    if product.get("image"):
        product["image"] = convert_image(product["image"])

    gallery = product.get("gallery")
    if isinstance(gallery, list):
        for index, item in enumerate(gallery):
            if isinstance(item, str):
                gallery[index] = convert_image(item)
            elif isinstance(item, dict) and item.get("image"):
                item["image"] = convert_image(item["image"])


products_path.write_text(
    json.dumps(data, ensure_ascii=False, indent=2) + "\n",
    encoding="utf-8",
)
print("Zoptymalizowano zdjęcia produktów do WebP i zaktualizowano paczkę publikacyjną.")
