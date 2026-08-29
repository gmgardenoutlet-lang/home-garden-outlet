<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
shop_test_boot();

$products = shop_test_products();
$publicProducts = shop_test_public_products();
$catalogUrl = 'https://mgoutlet.pl/sklep/figury-ogrodowe';
$catalogDescription = 'Figury ogrodowe do ogrodu, na taras i przy wejściu. Zobacz rzeźby twarzy, smoki, figury zwierząt, donice i kule dekoracyjne.';
$catalogSchema = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => 'Figury ogrodowe', 'url' => $catalogUrl, 'description' => $catalogDescription];
$catalogBreadcrumbs = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://mgoutlet.pl/'],
    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Figury ogrodowe', 'item' => $catalogUrl],
]];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="index, follow">
  <meta name="description" content="<?= e($catalogDescription) ?>">
  <link rel="canonical" href="<?= e($catalogUrl) ?>">
  <title>Figury ogrodowe – rzeźby i dekoracje do ogrodu | Home &amp; Garden Outlet</title>
  <meta property="og:title" content="Figury ogrodowe – rzeźby i dekoracje do ogrodu | Home &amp; Garden Outlet">
  <meta property="og:description" content="<?= e($catalogDescription) ?>">
  <meta property="og:url" content="<?= e($catalogUrl) ?>">
  <meta property="og:image" content="https://mgoutlet.pl/assets/images/figury-ogrodowe-hero.webp">
  <script type="application/ld+json"><?= json_encode($catalogSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
  <script type="application/ld+json"><?= json_encode($catalogBreadcrumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
  <?php shop_test_stylesheets(); ?>
</head>
<body>
  <?php shop_test_header('figures'); ?>

  <main>
    <nav class="shop-breadcrumbs" aria-label="Okruszki">
      <a href="/">Home</a><span aria-hidden="true">›</span><span aria-current="page">Figury ogrodowe</span>
    </nav>
    <section class="shop-hero shop-hero-split">
      <div class="shop-hero-copy">
        <div class="admin-ribbon">Sklep internetowy</div>
        <p class="eyebrow">Figury ogrodowe do ogrodu i na taras</p>
        <h1>Figury ogrodowe</h1>
        <p class="hero-subtitle">Ręcznie malowane dekoracje do ogrodu, na taras i przed wejście</p>
        <p>Wybierz figury ogrodowe, które podkreślą charakter Twojej przestrzeni. Produkty są dostępne u producenta, a każdy egzemplarz może delikatnie różnić się odcieniem i detalami wykończenia ze względu na ręczne malowanie.</p>
        <p><a href="/sklep/figury-ogrodowe/dostawa-i-platnosci">Dostawa i płatności</a> · <a href="/sklep/figury-ogrodowe/zwroty-i-reklamacje">Zwroty i reklamacje</a> · <a href="/sklep/figury-ogrodowe/formularz-odstapienia">Formularz odstąpienia</a></p>
      </div>
      <div class="shop-hero-media">
        <img src="/assets/images/figury-ogrodowe-hero.webp" width="1600" height="900" alt="Figury ogrodowe i dekoracje w showroomie Home & Garden Outlet">
      </div>
      <div class="hero-badges" aria-label="Najważniejsze informacje">
        <span>Ręczne malowanie</span>
        <span>Realizacja 2–5 dni roboczych</span>
        <span>Dostawa zależna od produktu</span>
      </div>
      <div class="shop-actions hero-actions">
        <a class="btn" href="#produkty">Zobacz produkty</a>
      </div>
    </section>

    <?php if (!$products): ?>
      <section class="empty">Nie ma jeszcze figur widocznych w sklepie. Dodaj pierwsze produkty, aby zobaczyć docelowy układ kategorii.</section>
    <?php else: ?>
      <section class="shop-toolbar" aria-label="Opcje listy produktów">
        <div><strong><?= count($products) ?></strong> produktów w kategorii</div>
        <label>Sortuj
          <select data-shop-sort>
            <option value="default">Domyślnie</option>
            <option value="price-asc">Cena rosnąco</option>
            <option value="price-desc">Cena malejąco</option>
            <option value="name">Nazwa A-Z</option>
          </select>
        </label>
      </section>

      <section id="produkty" class="shop-grid" aria-label="Figury ogrodowe" data-shop-grid>
        <?php foreach ($products as $product): ?>
          <?php $view = shop_test_public_product($product); ?>
          <article class="shop-card" data-product-card data-price="<?= e($view['price'] !== null ? (string)$view['price'] : '') ?>" data-name="<?= e($view['name']) ?>">
            <a class="shop-card-image" href="<?= e(shop_test_product_url($view['slug'])) ?>">
              <img src="<?= e($view['image']) ?>" width="520" height="390" loading="lazy" alt="<?= e($view['alt']) ?>">
            </a>
            <div>
              <h2><a href="<?= e(shop_test_product_url($view['slug'])) ?>"><?= e($view['name']) ?></a></h2>
              <?php if ($view['shortDescription'] !== ''): ?><p class="card-description"><?= e($view['shortDescription']) ?></p><?php endif; ?>
              <ul class="card-facts">
                <li>Wysyłka <?= e($view['leadTime']) ?></li>
                <?php if (!empty($product['handPainted'])): ?><li>Ręcznie malowane</li><?php endif; ?>
              </ul>
              <strong class="card-price"><?= e($view['priceLabel']) ?></strong>
              <div class="shop-actions">
                <a class="btn btn-light" href="<?= e(shop_test_product_url($view['slug'])) ?>">Zobacz produkt</a>
                <?php if (shop_sales_enabled() && $view['canBuy']): ?>
                  <button class="btn" type="button" data-add-to-cart="<?= e($view['slug']) ?>">Dodaj do koszyka</button>
                <?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <section class="catalogue-seo-content" aria-labelledby="figury-ogrodowe-info">
      <h2 id="figury-ogrodowe-info">Figury ogrodowe do ogrodu, na taras i przy wejściu</h2>
      <p>Figury ogrodowe pomagają nadać przestrzeni indywidualny charakter — od niewielkiego akcentu przy roślinach po wyrazistą ozdobę ustawioną przy ścieżce, tarasie lub wejściu do domu. W tej kolekcji znajdziesz figury do ogrodu, które można dopasować zarówno do spokojnej zielonej aranżacji, jak i do bardziej dekoracyjnej przestrzeni wokół domu. Różnorodność form pozwala łączyć je z zielenią, ścieżkami oraz strefą wypoczynku w sposób dopasowany do własnego stylu.</p>

      <h2>Rzeźby, twarze, zwierzęta i smoki ogrodowe</h2>
      <p>Oferta obejmuje rzeźby ogrodowe w formie stylizowanych twarzy, w tym duże figury ogrodowe oraz figury z siedziskiem. Są też smoki ogrodowe o bajkowym charakterze oraz figury zwierząt do ogrodu: figury psów, kotków i lwa. Dzięki różnym formom łatwo wybrać dekorację, która będzie subtelnym dodatkiem albo głównym punktem aranżacji. Motywy zwierzęce sprawdzą się w swobodnych kompozycjach, a rzeźby twarzy mogą nadać otoczeniu bardziej artystyczny wyraz.</p>

      <h2>Donice i kule dekoracyjne do ogrodu</h2>
      <p>Dekoracyjne donice ogrodowe łączą miejsce na rośliny z nietypową formą ozdoby. Uzupełniają je kule ogrodowe z ornamentem, które dobrze wyglądają przy rabatach, donicach i wejściu. Te dekoracje ogrodowe pozwalają zestawiać rośliny z rzeźbiarskimi detalami bez tworzenia osobnych, przypadkowych elementów w przestrzeni. Można wykorzystać je jako pojedynczy detal albo połączyć kilka elementów w jedną spójną aranżację.</p>

      <h2>Jak wybrać figurę ogrodową do swojej przestrzeni</h2>
      <p>Warto zacząć od miejsca ekspozycji i skali otoczenia. Duża rzeźba twarzy może podkreślić otwartą część ogrodu lub taras, a mniejsze ozdoby do ogrodu sprawdzą się między roślinami albo przy wejściu. Wybierając ręcznie malowane figury ogrodowe, warto również zwrócić uwagę na ich formę i kolorystykę, aby pasowały do istniejącej aranżacji. Przed wyborem dobrze jest zestawić proporcje dekoracji z wolną przestrzenią wokół niej, tak aby całość pozostała czytelna i harmonijna.</p>
    </section>
  </main>

  <?php shop_test_footer(); ?>
  <script>window.HGO_SHOP_SALES_ENABLED = <?= shop_sales_enabled() ? 'true' : 'false' ?>; window.HGO_SHOP_PRODUCTS = <?= json_encode($publicProducts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
  <script src="/sklep/shop.js?v=20260809-shipment-check1"></script>
</body>
</html>
