<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
boot_admin();

function image_url(string $path): string
{
    $path = trim($path);
    if ($path === '' || str_contains($path, '..')) {
        return '/product-table.jpeg';
    }
    return str_starts_with($path, '/') ? $path : '/' . $path;
}

function gallery_paths(array $product): array
{
    $result = [];
    foreach (($product['gallery'] ?? []) as $item) {
        $path = is_array($item) ? (string)($item['image'] ?? '') : (string)$item;
        if ($path !== '' && !str_contains($path, '..')) {
            $result[] = $path;
        }
    }
    return $result;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $action = post_text('action');

        if ($action === 'setup' && credentials() === null) {
            $password = (string)($_POST['password'] ?? '');
            if (!hash_equals($password, (string)($_POST['password_confirm'] ?? ''))) {
                throw new RuntimeException('Powtórzone hasło nie jest takie samo.');
            }
            save_credentials(post_text('username'), $password);
            try_login(post_text('username'), $password);
            flash('success', 'Panel został bezpiecznie uruchomiony.');
            redirect_admin();
        }

        if ($action === 'login') {
            if (!try_login(post_text('username'), (string)($_POST['password'] ?? ''))) {
                throw new RuntimeException('Nieprawidłowa nazwa użytkownika lub hasło.');
            }
            redirect_admin();
        }

        require_login();

        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            redirect_admin();
        }

        if ($action === 'change_password') {
            $config = credentials();
            if (!$config || !password_verify((string)($_POST['current_password'] ?? ''), (string)$config['password_hash'])) {
                throw new RuntimeException('Obecne hasło jest nieprawidłowe.');
            }
            $password = (string)($_POST['new_password'] ?? '');
            if (!hash_equals($password, (string)($_POST['new_password_confirm'] ?? ''))) {
                throw new RuntimeException('Powtórzone nowe hasło nie jest takie samo.');
            }
            save_credentials((string)$config['username'], $password);
            flash('success', 'Hasło zostało zmienione.');
            redirect_admin();
        }

        if ($action === 'toggle_sold') {
            $catalog = load_catalog();
            $index = filter_input(INPUT_POST, 'index', FILTER_VALIDATE_INT);
            if ($index === false || $index === null || !isset($catalog['products'][$index])) {
                throw new RuntimeException('Nie znaleziono produktu do zmiany dostępności.');
            }
            $product = &$catalog['products'][$index];
            $isSold = (string)($product['status'] ?? '') === 'Sprzedane';
            $product['status'] = $isSold ? 'Dostępne' : 'Sprzedane';
            save_catalog($catalog);
            flash('success', $isSold ? 'Produkt ponownie oznaczono jako dostępny.' : 'Produkt oznaczono jako sprzedany.');
            redirect_admin();
        }

        if ($action === 'delete_product') {
            $catalog = load_catalog();
            $index = filter_input(INPUT_POST, 'index', FILTER_VALIDATE_INT);
            if ($index === false || $index === null || !isset($catalog['products'][$index])) {
                throw new RuntimeException('Nie znaleziono produktu do usunięcia.');
            }
            $name = (string)($catalog['products'][$index]['name'] ?? 'Produkt');
            array_splice($catalog['products'], $index, 1);
            save_catalog($catalog);
            flash('success', 'Usunięto produkt: ' . $name . '. Zdjęcia pozostawiono jako zabezpieczenie.');
            redirect_admin();
        }

        if ($action === 'save_product') {
            $catalog = load_catalog();
            $indexRaw = post_text('index');
            $isEdit = $indexRaw !== '' && ctype_digit($indexRaw) && isset($catalog['products'][(int)$indexRaw]);
            $index = $isEdit ? (int)$indexRaw : count($catalog['products']);
            $product = $isEdit ? $catalog['products'][$index] : product_defaults();

            $name = post_text('name');
            if ($name === '') {
                throw new RuntimeException('Podaj nazwę produktu.');
            }

            $textFields = [
                'name', 'category', 'productType', 'catalogPrice', 'outletPrice', 'currency',
                'imageAlt', 'description', 'longDescription', 'dimensions', 'material', 'color',
                'condition', 'status', 'productStatus', 'seoTitle', 'seoDescription', 'slug'
            ];
            foreach ($textFields as $field) {
                $product[$field] = post_text($field);
            }
            $product['featured'] = isset($_POST['featured']);
            $product['visible'] = isset($_POST['visible']);
            $product['order'] = (int)($_POST['order'] ?? 0);
            $product['currency'] = 'PLN';
            $product['productStatus'] = $product['productStatus'] !== '' ? $product['productStatus'] : 'Aktywny';

            if (isset($_FILES['main_image']) && (int)($_FILES['main_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $product['image'] = uploaded_file($_FILES['main_image'], $name);
            }
            if (empty($product['image'])) {
                throw new RuntimeException('Dodaj zdjęcie główne produktu.');
            }

            $gallery = gallery_paths($product);
            $removeGallery = array_map('intval', (array)($_POST['remove_gallery'] ?? []));
            $gallery = array_values(array_filter($gallery, static fn($path, $galleryIndex) => !in_array($galleryIndex, $removeGallery, true), ARRAY_FILTER_USE_BOTH));
            if (isset($_FILES['gallery_images'])) {
                foreach (normalize_gallery_files($_FILES['gallery_images']) as $file) {
                    $newPath = uploaded_file($file, $name);
                    if ($newPath !== '') {
                        $gallery[] = $newPath;
                    }
                }
            }
            $product['gallery'] = array_values(array_unique($gallery));

            if ($isEdit) {
                $catalog['products'][$index] = $product;
            } else {
                array_unshift($catalog['products'], $product);
                $index = 0;
            }
            save_catalog($catalog);
            flash('success', $isEdit ? 'Produkt został zaktualizowany.' : 'Nowy produkt został dodany.');
            redirect_admin('edit=' . $index);
        }
    }
} catch (Throwable $exception) {
    flash('error', $exception->getMessage());
    $fallback = isset($_POST['index']) && ctype_digit((string)$_POST['index']) ? 'edit=' . (int)$_POST['index'] : '';
    redirect_admin($fallback);
}

$setupRequired = credentials() === null;

if (!$setupRequired && is_logged_in() && ($_GET['download'] ?? '') === 'products') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="products-backup-' . date('Y-m-d-His') . '.json"');
    readfile(PRODUCTS_FILE);
    exit;
}

$flashes = pull_flashes();

if ($setupRequired || !is_logged_in()) {
    $title = $setupRequired ? 'Pierwsze uruchomienie panelu' : 'Logowanie do panelu';
    ?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?> | Home & Garden Outlet</title>
  <link rel="stylesheet" href="/admin/style.css">
</head>
<body>
  <main class="narrow">
    <section class="card">
      <p class="muted">Home & Garden Outlet</p>
      <h1><?= e($title) ?></h1>
      <?php foreach ($flashes as $message): ?>
        <div class="flash flash-<?= e($message['type']) ?>"><?= e($message['message']) ?></div>
      <?php endforeach; ?>
      <form method="post" class="form-grid">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $setupRequired ? 'setup' : 'login' ?>">
        <div class="field field-full">
          <label for="username">Nazwa użytkownika</label>
          <input id="username" name="username" autocomplete="username" required value="<?= $setupRequired ? 'admin' : '' ?>">
        </div>
        <div class="field field-full">
          <label for="password">Hasło</label>
          <div class="password-wrap">
            <input id="password" type="password" name="password" autocomplete="<?= $setupRequired ? 'new-password' : 'current-password' ?>" required>
            <button type="button" class="btn btn-secondary btn-small" data-password-toggle="password">Pokaż</button>
          </div>
          <?php if ($setupRequired): ?><small>Minimum 12 znaków, mała i duża litera oraz cyfra.</small><?php endif; ?>
        </div>
        <?php if ($setupRequired): ?>
          <div class="field field-full">
            <label for="password-confirm">Powtórz hasło</label>
            <input id="password-confirm" type="password" name="password_confirm" autocomplete="new-password" required>
          </div>
        <?php endif; ?>
        <div class="field field-full"><button class="btn" type="submit"><?= $setupRequired ? 'Uruchom bezpieczny panel' : 'Zaloguj się' ?></button></div>
      </form>
      <p class="login-note"><?= $setupRequired ? 'To jednorazowa konfiguracja. Hasło zostanie zapisane na Getspace jako bezpieczny skrót.' : 'Panel zapisuje produkty i zdjęcia bezpośrednio na Getspace.' ?></p>
    </section>
  </main>
  <script src="/admin/app.js"></script>
</body>
</html>
    <?php
    exit;
}

$catalog = load_catalog();
$products = $catalog['products'];
$editRaw = (string)($_GET['edit'] ?? '');
$editing = $editRaw !== '' && ctype_digit($editRaw) && isset($products[(int)$editRaw]);
$newProduct = isset($_GET['new']);
$showPassword = isset($_GET['password']);
$editIndex = $editing ? (int)$editRaw : null;
$product = $editing ? array_merge(product_defaults(), $products[$editIndex]) : product_defaults();
$search = trim((string)($_GET['q'] ?? ''));
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Panel produktów | Home & Garden Outlet</title>
  <link rel="stylesheet" href="/admin/style.css">
</head>
<body>
  <header class="admin-header">
    <a class="brand" href="/admin/">Home & Garden Outlet</a>
    <div class="header-actions">
      <a class="btn btn-secondary btn-small" href="/" target="_blank" rel="noopener">Zobacz stronę</a>
      <a class="btn btn-secondary btn-small" href="/admin/?download=products">Kopia produktów</a>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="logout">
        <button class="link-button" type="submit">Wyloguj</button>
      </form>
    </div>
  </header>

  <main class="container">
    <?php foreach ($flashes as $message): ?>
      <div class="flash flash-<?= e($message['type']) ?>"><?= e($message['message']) ?></div>
    <?php endforeach; ?>

    <?php if ($newProduct || $editing): ?>
      <div class="page-heading">
        <div><p class="muted">Katalog produktów</p><h1><?= $editing ? 'Edytuj produkt' : 'Dodaj nowy produkt' ?></h1></div>
        <a class="btn btn-secondary" href="/admin/">Wróć do listy</a>
      </div>
      <form method="post" enctype="multipart/form-data" class="card">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_product">
        <input type="hidden" name="index" value="<?= $editing ? e((string)$editIndex) : '' ?>">
        <div class="form-grid">
          <div class="section-title">Podstawowe informacje</div>
          <div class="field field-full"><label for="name">Nazwa produktu</label><input id="name" name="name" required value="<?= e($product['name']) ?>"></div>
          <div class="field"><label for="category">Kategoria</label><select id="category" name="category"><?php foreach (['Wyposażenie domu','Wyposażenie ogrodu','Dekoracje','Oświetlenie','Inne'] as $option): ?><option<?= $product['category'] === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="productType">Krótki typ produktu</label><input id="productType" name="productType" value="<?= e($product['productType']) ?>" placeholder="np. sofa, stół, donica"></div>
          <div class="field"><label class="check-line"><input type="checkbox" name="visible"<?= $product['visible'] ? ' checked' : '' ?>> Widoczny na stronie</label></div>
          <div class="field"><label class="check-line"><input type="checkbox" name="featured"<?= $product['featured'] ? ' checked' : '' ?>> Polecany na stronie głównej</label></div>

          <div class="section-title">Cena i dostępność</div>
          <div class="field"><label for="catalogPrice">Cena katalogowa</label><input id="catalogPrice" name="catalogPrice" value="<?= e($product['catalogPrice']) ?>" placeholder="np. 2500 zł"></div>
          <div class="field"><label for="outletPrice">Cena outletowa / sprzedaży</label><input id="outletPrice" name="outletPrice" value="<?= e($product['outletPrice']) ?>" placeholder="np. 1350 zł"></div>
          <input type="hidden" name="currency" value="PLN">
          <div class="field"><label for="status">Dostępność</label><select id="status" name="status"><?php foreach (['Dostępne','Nowość','Ostatnia sztuka','Rezerwacja','Sprzedane','Zapytaj o dostępność'] as $option): ?><option<?= $product['status'] === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="productStatus">Status produktu</label><select id="productStatus" name="productStatus"><?php foreach (['Aktywny','Sprzedany','Ukryty','Rezerwacja'] as $option): ?><option<?= $product['productStatus'] === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="condition">Stan produktu</label><select id="condition" name="condition"><option value="">Nie podano</option><?php foreach (['Nowy','Outletowy','Po ekspozycji','Końcówka kolekcji','Produkt z drobnymi śladami','Inny'] as $option): ?><option<?= $product['condition'] === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="order">Kolejność</label><input id="order" type="number" name="order" value="<?= e($product['order']) ?>"></div>

          <div class="section-title">Zdjęcia z telefonu</div>
          <div class="field field-full upload-field">
            <label for="main-image"><?= $editing ? 'Zmień zdjęcie główne' : 'Zdjęcie główne' ?></label>
            <input id="main-image" type="file" name="main_image" accept="image/jpeg,image/png,image/webp"<?= $editing ? '' : ' required' ?>>
            <small>JPG, PNG lub WebP, maksymalnie 12 MB. Serwer automatycznie zmniejszy zdjęcie.</small>
            <?php if ($editing && !empty($product['image'])): ?><div class="image-current"><p class="muted">Obecne zdjęcie:</p><img src="<?= e(image_url((string)$product['image'])) ?>" alt=""></div><?php endif; ?>
            <div class="upload-preview"></div>
          </div>
          <div class="field field-full upload-field">
            <label for="gallery-images">Dodaj zdjęcia do galerii</label>
            <input id="gallery-images" type="file" name="gallery_images[]" accept="image/jpeg,image/png,image/webp" multiple>
            <small>Możesz zaznaczyć kilka zdjęć jednocześnie.</small>
            <div class="upload-preview"></div>
            <?php $currentGallery = gallery_paths($product); if ($currentGallery): ?>
              <div class="gallery-current">
                <?php foreach ($currentGallery as $galleryIndex => $galleryImage): ?>
                  <div class="gallery-item"><img src="<?= e(image_url($galleryImage)) ?>" alt=""><label class="check-line"><input type="checkbox" name="remove_gallery[]" value="<?= e((string)$galleryIndex) ?>"> Usuń z produktu</label></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="field field-full"><label for="imageAlt">Opis zdjęcia dla Google</label><input id="imageAlt" name="imageAlt" value="<?= e($product['imageAlt']) ?>" placeholder="Naturalny opis produktu na zdjęciu"></div>

          <div class="section-title">Opis produktu</div>
          <div class="field field-full"><label for="description">Opis widoczny na karcie</label><textarea id="description" name="description" required><?= e($product['description']) ?></textarea></div>
          <div class="field field-full"><label for="longDescription">Dłuższy opis</label><textarea id="longDescription" name="longDescription"><?= e($product['longDescription']) ?></textarea></div>
          <div class="field"><label for="dimensions">Wymiary</label><input id="dimensions" name="dimensions" value="<?= e($product['dimensions']) ?>"></div>
          <div class="field"><label for="material">Materiał</label><input id="material" name="material" value="<?= e($product['material']) ?>"></div>
          <div class="field"><label for="color">Kolor</label><input id="color" name="color" value="<?= e($product['color']) ?>"></div>

          <div class="section-title">Opcjonalne SEO</div>
          <div class="field field-full"><label for="seoTitle">Tytuł SEO</label><input id="seoTitle" name="seoTitle" value="<?= e($product['seoTitle']) ?>"></div>
          <div class="field field-full"><label for="seoDescription">Opis SEO</label><textarea id="seoDescription" name="seoDescription"><?= e($product['seoDescription']) ?></textarea><small>Zalecane około 140–160 znaków.</small></div>
          <div class="field field-full"><label for="slug">Adres produktu / slug</label><input id="slug" name="slug" value="<?= e($product['slug']) ?>"></div>
        </div>
        <div class="form-actions"><button class="btn" type="submit">Zapisz produkt</button><a class="btn btn-secondary" href="/admin/">Anuluj</a></div>
      </form>
    <?php elseif ($showPassword): ?>
      <div class="page-heading"><div><p class="muted">Bezpieczeństwo</p><h1>Zmień hasło</h1></div><a class="btn btn-secondary" href="/admin/">Wróć</a></div>
      <form method="post" class="card form-grid">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="change_password">
        <div class="field field-full"><label for="current-password">Obecne hasło</label><input id="current-password" type="password" name="current_password" required></div>
        <div class="field field-full"><label for="new-password">Nowe hasło</label><input id="new-password" type="password" name="new_password" required><small>Minimum 12 znaków, mała i duża litera oraz cyfra.</small></div>
        <div class="field field-full"><label for="new-password-confirm">Powtórz nowe hasło</label><input id="new-password-confirm" type="password" name="new_password_confirm" required></div>
        <div class="field field-full"><button class="btn" type="submit">Zmień hasło</button></div>
      </form>
    <?php else: ?>
      <div class="page-heading">
        <div><p class="muted">Bezpośrednio na Getspace · <?= count($products) ?> produktów</p><h1>Produkty</h1></div>
        <div class="header-actions"><a class="btn btn-secondary" href="/admin/?password=1">Zmień hasło</a><a class="btn" href="/admin/?new=1">Dodaj produkt</a></div>
      </div>
      <form method="get" class="card" style="margin-bottom:16px"><div class="password-wrap"><input name="q" value="<?= e($search) ?>" placeholder="Szukaj produktu po nazwie"><button class="btn" type="submit">Szukaj</button></div></form>
      <section class="product-list">
        <?php $shown = 0; foreach ($products as $index => $item): if ($search !== '' && (function_exists('mb_stripos') ? mb_stripos((string)($item['name'] ?? ''), $search) : stripos((string)($item['name'] ?? ''), $search)) === false) continue; $shown++; ?>
          <article class="product-row">
            <img src="<?= e(image_url((string)($item['image'] ?? ''))) ?>" alt="">
            <div><h2><?= e($item['name'] ?? 'Produkt bez nazwy') ?></h2><div class="meta"><span><?= e($item['category'] ?? 'Bez kategorii') ?></span><span class="status"><?= e($item['status'] ?? 'Dostępność do potwierdzenia') ?></span><span><?= e($item['outletPrice'] ?? 'Zapytaj o cenę') ?></span><?= !empty($item['visible']) ? '' : '<span>Ukryty na stronie</span>' ?></div></div>
            <div class="row-actions">
              <a class="btn btn-secondary btn-small" href="/admin/?edit=<?= e((string)$index) ?>">Edytuj</a>
              <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_sold"><input type="hidden" name="index" value="<?= e((string)$index) ?>"><button class="btn <?= ($item['status'] ?? '') === 'Sprzedane' ? 'btn-restore' : 'btn-status' ?> btn-small" type="submit"><?= ($item['status'] ?? '') === 'Sprzedane' ? 'Przywróć' : 'Sprzedane' ?></button></form>
              <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="index" value="<?= e((string)$index) ?>"><button class="btn btn-danger btn-small" type="submit" data-confirm="Usunąć ten produkt? Zdjęcia pozostaną na serwerze.">Usuń</button></form>
            </div>
          </article>
        <?php endforeach; if ($shown === 0): ?><div class="card empty">Nie znaleziono produktów.</div><?php endif; ?>
      </section>
    <?php endif; ?>
  </main>
  <script src="/admin/app.js"></script>
</body>
</html>
