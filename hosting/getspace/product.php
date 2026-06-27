<?php
declare(strict_types=1);

require __DIR__ . '/catalog.php';

$slug = (string)($_GET['slug'] ?? '');
$product = $slug !== '' ? catalog_find_product($slug) : null;

if ($product === null) {
    http_response_code(404);
    header('X-Robots-Tag: noindex, follow');
    $title = 'Produkt niedostępny | Home & Garden Outlet';
    $description = 'Ten produkt nie jest już dostępny w publicznym katalogu Home & Garden Outlet.';
    $canonical = CATALOG_SITE_URL . '/produkt/' . rawurlencode(catalog_slugify($slug ?: 'produkt'));
    $images = ['/product-table.jpeg'];
    $seo = ['imageAlt' => 'Home & Garden Outlet'];
    $status = 'Produkt niedostępny';
} else {
    $seo = catalog_seo($product);
    $title = $seo['title'];
    $description = $seo['description'];
    $canonical = CATALOG_SITE_URL . '/produkt/' . rawurlencode($seo['slug']);
    $images = catalog_images($product);
    $status = catalog_display_status($product);
}

$mainImage = $images[0];
$absoluteImage = catalog_absolute_url($mainImage);
$categoryUrl = $product ? catalog_category_url($product) : '/';
$isGardenProduct = $product && strpos(catalog_normalize((string)($product['category'] ?? '')), 'ogrod') !== false;
$seoCategoryUrl = $isGardenProduct ? '/meble-ogrodowe-wroclaw/' : '/outlet-meblowy-wroclaw/';
$seoCategoryLabel = $isGardenProduct ? 'Meble ogrodowe outlet Wrocław' : 'Outlet meblowy pod Wrocławiem';
$category = $product && catalog_has_value($product['category'] ?? '') ? (string)$product['category'] : 'Aktualny katalog';
$name = $product && catalog_has_value($product['name'] ?? '') ? (string)$product['name'] : 'Produkt niedostępny';
$descriptionText = $product && catalog_has_value($product['description'] ?? '')
    ? (string)$product['description']
    : 'Skontaktuj się z nami, aby sprawdzić aktualną ofertę produktów dostępnych na miejscu.';
$longDescription = $product && catalog_has_value($product['longDescription'] ?? '') ? (string)$product['longDescription'] : '';
$catalogPrice = $product && catalog_has_value($product['catalogPrice'] ?? '') ? (string)$product['catalogPrice'] : '';
$outletPrice = $product && catalog_has_value($product['outletPrice'] ?? '') ? (string)$product['outletPrice'] : '';
$catalogValue = catalog_price_number($catalogPrice);
$outletValue = catalog_price_number($outletPrice);
$saving = $catalogValue !== null && $outletValue !== null && $catalogValue > $outletValue
    ? round($catalogValue - $outletValue)
    : null;
$isSold = in_array(catalog_normalize($status), ['sprzedane', 'sprzedany'], true);

$productSchema = null;
if ($product !== null) {
    $productSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $name,
        'description' => $description,
        'image' => array_map('catalog_absolute_url', $images),
        'url' => $canonical,
        'brand' => ['@type' => 'Brand', 'name' => 'Home & Garden Outlet'],
    ];
    if ($outletValue !== null) {
        $productSchema['offers'] = [
            '@type' => 'Offer',
            'url' => $canonical,
            'priceCurrency' => 'PLN',
            'price' => number_format($outletValue, 2, '.', ''),
            'availability' => $isSold ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
            'seller' => ['@type' => 'FurnitureStore', 'name' => 'Home & Garden Outlet'],
        ];
    }
}

$breadcrumbs = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Strona główna', 'item' => CATALOG_SITE_URL . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $category, 'item' => CATALOG_SITE_URL . $categoryUrl],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $name, 'item' => $canonical],
    ],
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= catalog_e($title) ?></title>
  <meta name="description" content="<?= catalog_e($description) ?>">
  <?php if ($product === null): ?><meta name="robots" content="noindex, follow"><?php endif; ?>
  <link rel="canonical" href="<?= catalog_e($canonical) ?>">
  <meta property="og:type" content="product">
  <meta property="og:title" content="<?= catalog_e($title) ?>">
  <meta property="og:description" content="<?= catalog_e($description) ?>">
  <meta property="og:url" content="<?= catalog_e($canonical) ?>">
  <meta property="og:image" content="<?= catalog_e($absoluteImage) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="48x48" href="/favicon-48x48.png">
  <link rel="icon" type="image/png" sizes="96x96" href="/favicon-96x96.png">
  <link rel="icon" type="image/png" sizes="192x192" href="/favicon-192x192.png">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#539730">
  <link rel="stylesheet" href="/styles.css?v=20260627-poradnik1">
  <?php if ($productSchema !== null): ?><script type="application/ld+json"><?= json_encode($productSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script><?php endif; ?>
  <script type="application/ld+json"><?= json_encode($breadcrumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body<?= $product !== null ? ' data-product-slug="' . catalog_e((string)$seo['slug']) . '"' : '' ?>>
  <header class="site-header">
    <a class="logo" href="/" aria-label="Home & Garden Outlet - strona główna"><img src="/logo-optimized.jpg" width="64" height="64" alt="Home & Garden Outlet - meble do domu i ogrodu"></a>
    <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="main-menu" aria-label="Otwórz menu"><span></span><span></span><span></span></button>
    <nav id="main-menu" class="main-nav" aria-label="Menu główne"><a href="/">Strona główna</a><a href="/ogrod">Wyposażenie ogrodu</a><a href="/dom">Wyposażenie domu</a><a href="/poradnik/">Poradnik</a><a href="/#onas">O nas</a><a href="/#kontakt">Kontakt</a></nav>
  </header>

  <main class="product-detail-page">
    <nav class="product-breadcrumbs" aria-label="Okruszki">
      <a href="/">Strona główna</a><span>/</span><a href="<?= catalog_e($categoryUrl) ?>"><?= catalog_e($category) ?></a><span>/</span><span aria-current="page"><?= catalog_e($name) ?></span>
    </nav>

    <?php if ($product === null): ?>
      <section class="product-not-found">
        <p class="eyebrow">Aktualny katalog</p>
        <h1>Ten produkt nie jest już dostępny</h1>
        <p>Oferta outletowa zmienia się regularnie. Zobacz pozostałe produkty lub zadzwoń, a pomożemy znaleźć podobną perełkę.</p>
        <div class="hero-actions"><a class="btn btn-primary" href="/#nowosci">Zobacz produkty</a><a class="btn btn-outline" href="tel:+48577210777">Zadzwoń</a></div>
      </section>
    <?php else: ?>
      <article class="product-detail">
        <section class="product-detail-gallery" aria-label="Zdjęcia produktu">
          <button class="product-detail-main product-gallery-trigger" type="button" data-gallery="<?= catalog_e(json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>" data-gallery-name="<?= catalog_e($name) ?>" data-gallery-alt="<?= catalog_e($seo['imageAlt']) ?>" aria-label="Otwórz galerię produktu">
            <img src="<?= catalog_e($mainImage) ?>" width="1000" height="750" alt="<?= catalog_e($seo['imageAlt']) ?>">
          </button>
          <?php if (count($images) > 1): ?>
            <div class="product-detail-thumbnails" aria-label="Pozostałe zdjęcia">
              <?php foreach ($images as $index => $image): ?>
                <button class="product-gallery-trigger" type="button" data-gallery="<?= catalog_e(json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>" data-gallery-name="<?= catalog_e($name) ?>" data-gallery-alt="<?= catalog_e($seo['imageAlt']) ?>" aria-label="Otwórz galerię, zdjęcie <?= $index + 1 ?>">
                  <img src="<?= catalog_e($image) ?>" width="240" height="180" loading="lazy" alt="">
                </button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="product-detail-info">
          <p class="eyebrow"><?= catalog_e($category) ?></p>
          <h1><?= catalog_e($name) ?></h1>
          <div class="product-detail-status"><span class="badge-static"><?= catalog_e($status) ?></span><span>Produkt dostępny lokalnie w Kębłowicach</span></div>

          <?php if ($catalogPrice !== '' || $outletPrice !== ''): ?>
            <div class="product-detail-prices">
              <?php if ($catalogPrice !== ''): ?><span class="catalog-price<?= $outletPrice !== '' ? ' old-price' : '' ?>">Cena katalogowa: <?= catalog_e($catalogPrice) ?></span><?php endif; ?>
              <?php if ($outletPrice !== ''): ?><span class="outlet-price">Cena outletowa: <?= catalog_e($outletPrice) ?></span><?php endif; ?>
              <?php if ($saving !== null): ?><span class="saving-badge">Oszczędzasz: <?= catalog_e((string)$saving) ?> zł</span><?php endif; ?>
            </div>
          <?php else: ?>
            <p class="product-detail-price-question">Zapytaj o cenę</p>
          <?php endif; ?>

          <p class="product-detail-lead"><?= nl2br(catalog_e($descriptionText)) ?></p>

          <?php
          $details = [
              'Stan produktu' => $product['condition'] ?? '',
              'Wymiary' => $product['dimensions'] ?? '',
              'Materiał' => $product['material'] ?? '',
              'Kolor' => $product['color'] ?? '',
          ];
          $visibleDetails = array_filter($details, 'catalog_has_value');
          ?>
          <?php if ($visibleDetails): ?>
            <dl class="product-detail-specs">
              <?php foreach ($visibleDetails as $label => $value): ?><div><dt><?= catalog_e($label) ?></dt><dd><?= catalog_e($value) ?></dd></div><?php endforeach; ?>
            </dl>
          <?php endif; ?>

          <div class="product-detail-actions">
            <a class="btn btn-primary" href="tel:+48577210777">Zadzwoń: 577 210 777</a>
            <a class="btn btn-outline" href="sms:+48577210777?body=Interesuje%20mnie%20produkt:%20<?= rawurlencode($name) ?>">Zapytaj SMS</a>
            <a class="btn btn-light" href="https://maps.app.goo.gl/SJ9LvQcub6rzQKAs5" target="_blank" rel="noopener" data-stat-event="navigation_click">Sprawdź dojazd</a>
          </div>
          <p class="product-detail-note">Przed przyjazdem zadzwoń i potwierdź aktualną dostępność. Produkty outletowe często występują jako pojedyncze sztuki.</p>
        </section>
      </article>

      <?php if ($longDescription !== ''): ?>
        <section class="product-detail-long">
          <p class="eyebrow">Więcej o produkcie</p>
          <h2><?= catalog_e($name) ?></h2>
          <p><?= nl2br(catalog_e($longDescription)) ?></p>
        </section>
      <?php endif; ?>

      <section class="product-detail-local">
        <div><p class="eyebrow">Zobacz na żywo</p><h2>Prawdziwy produkt w lokalnym showroomie pod Wrocławiem</h2><p>Zapraszamy do Home & Garden Outlet przy ul. Przelotowej 16 w Kębłowicach. Na miejscu możesz sprawdzić wygląd, wygodę, kolor i rzeczywisty stan produktu.</p></div>
        <div class="hero-actions"><a class="btn btn-primary" href="<?= catalog_e($categoryUrl) ?>">Zobacz podobne produkty</a><a class="btn btn-outline" href="<?= catalog_e($seoCategoryUrl) ?>"><?= catalog_e($seoCategoryLabel) ?></a><a class="btn btn-outline" href="/#kontakt">Kontakt i godziny otwarcia</a></div>
      </section>
    <?php endif; ?>
  </main>

  <footer class="site-footer">
    <div><strong>Home &amp; Garden Outlet</strong><p>Outlet mebli domowych i ogrodowych pod Wrocławiem.</p></div>
    <div><span>Kontakt</span><a href="tel:+48577210777">577 210 777</a><a href="mailto:gmgardenoutlet@gmail.com">gmgardenoutlet@gmail.com</a><a href="/#kontakt">ul. Przelotowa 16, 55-080 Kębłowice</a></div>
    <div><span>Godziny otwarcia</span><p>Poniedziałek: nieczynne</p><p>Wtorek: 10:00-16:00</p><p>Środa-piątek: 10:00-18:00</p><p>Sobota-niedziela: 10:00-14:00</p></div>
    <div><span>Social media</span><a href="https://www.facebook.com/mgoutletpl/?locale=pl_PL" target="_blank" rel="noopener">Facebook</a><a href="https://www.instagram.com/_mygardenoutlet_/" target="_blank" rel="noopener">Instagram</a><a href="https://www.tiktok.com/@my_garden_outlet" target="_blank" rel="noopener">TikTok</a></div>
    <div><span>Na skróty</span><a href="/outlet-meblowy-wroclaw/">Outlet meblowy Wrocław</a><a href="/meble-ogrodowe-wroclaw/">Meble ogrodowe Wrocław</a><a href="/dom">Meble do domu outlet</a><a href="/ogrod">Wyposażenie ogrodu</a><a href="/poradnik/">Poradnik</a></div>
  </footer>

  <nav class="mobile-sticky-cta" aria-label="Szybki kontakt"><a href="tel:+48577210777">Zadzwoń</a><a href="https://maps.app.goo.gl/SJ9LvQcub6rzQKAs5" target="_blank" rel="noopener" data-stat-event="navigation_click">Jak dojechać</a><a href="https://www.facebook.com/mgoutletpl/?locale=pl_PL" target="_blank" rel="noopener">Facebook</a></nav>
  <script src="/script.js?v=20260627-seo1"></script>
</body>
</html>
