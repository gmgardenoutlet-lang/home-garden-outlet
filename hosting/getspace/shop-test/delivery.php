<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
shop_test_boot();
$profiles = load_shipping_profiles_for_shop();
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="index,follow">
  <link rel="canonical" href="https://mgoutlet.pl/sklep/figury-ogrodowe/dostawa-i-platnosci">
  <title>Dostawa i płatności | Home &amp; Garden Outlet</title>
  <?php shop_test_stylesheets(); ?>
</head>
<body>
  <?php shop_test_header('figures'); ?>
  <main>
    <article class="legal-page">
      <p class="eyebrow">Informacje dla kupujących</p>
      <h1>Dostawa i płatności</h1>
      <p class="legal-lead">Dostępne metody i ceny wynikają z aktualnej konfiguracji sklepu. Dostępne metody dostawy zależą od konkretnego produktu.</p>
      <?php if (!$profiles): ?>
        <section>
          <h2>Metody dostawy są chwilowo niedostępne</h2>
          <p>Nie możemy teraz bezpiecznie wyświetlić aktualnego cennika dostaw. Skontaktuj się z nami, aby potwierdzić transport.</p>
        </section>
      <?php endif; ?>
      <?php foreach ($profiles as $profile): ?>
        <?php if (empty($profile['active'])) { continue; } ?>
        <?php $public = shipping_profile_public($profile); ?>
        <?php $quote = $public['costNumber'] === null || !empty($public['requiresConfirmation']); ?>
        <section>
          <h2><?= e($public['label']) ?></h2>
          <?php if ((string)($public['description'] ?? '') !== ''): ?><p><?= e((string)$public['description']) ?></p><?php endif; ?>
          <p><strong><?= $quote ? 'Koszt wymaga indywidualnego potwierdzenia' : (($public['costNumber'] ?? null) === 0 ? 'Bezpłatnie' : e((string)$public['cost'])) ?></strong></p>
        </section>
      <?php endforeach; ?>
      <section>
        <h2>Płatności</h2>
        <p>Płatności online są obecnie w trakcie uruchamiania.</p>
        <p>W przypadku metod wymagających potwierdzenia pełna wartość dostawy zostanie ustalona przed płatnością.</p>
      </section>
    </article>
  </main>
  <?php shop_test_footer(); ?>
</body>
</html>
