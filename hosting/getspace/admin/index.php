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

function admin_order_status_key(string $status): string
{
    return [
        'Testowe' => 'new', 'Nowe' => 'new', 'Oczekuje na płatność' => 'awaiting_payment',
        'Opłacone' => 'paid', 'W przygotowaniu' => 'processing', 'Wysłane' => 'shipped',
        'Odebrane osobiście' => 'pickup_completed', 'Anulowane' => 'cancelled', 'Zwrócone' => 'returned',
    ][$status] ?? $status;
}

function admin_order_status_label(string $status): string
{
    return [
        'new' => 'Nowe', 'awaiting_payment' => 'Oczekuje na płatność', 'awaiting_shipping_quote' => 'Oczekuje na wycenę dostawy',
        'paid' => 'Opłacone', 'processing' => 'W przygotowaniu', 'shipped' => 'Wysłane', 'completed' => 'Zrealizowane',
        'cancelled' => 'Anulowane', 'payment_failed' => 'Błąd płatności', 'returned' => 'Zwrócone',
        'refunded' => 'Zwrot środków', 'pickup_completed' => 'Odebrane osobiście',
    ][admin_order_status_key($status)] ?? $status;
}

function admin_payment_status_label(string $status): string
{
    return [
        'confirmed' => 'Potwierdzona', 'not_started' => 'Nie rozpoczęta', 'new' => 'Nowa',
        'pending' => 'Oczekuje na potwierdzenie', 'awaiting' => 'Oczekuje na płatność',
        'awaiting_payment' => 'Oczekuje na płatność', 'paid' => 'Opłacona', 'rejected' => 'Odrzucona',
        'expired' => 'Wygasła', 'abandoned' => 'Przerwana', 'error' => 'Błąd płatności',
        'failed' => 'Błąd płatności', 'cancelled' => 'Anulowana', 'refunded' => 'Zwrot środków',
        'Testowe bez płatności' => 'Nie rozpoczęta', 'Oczekuje na płatność' => 'Oczekuje na płatność',
        'Opłacone' => 'Opłacona', 'Anulowane' => 'Anulowana', 'Zwrot' => 'Zwrot środków',
    ][$status] ?? $status;
}

function admin_order_is_paid(array $order): bool
{
    return in_array((string)($order['paymentStatus'] ?? ''), ['confirmed', 'paid'], true)
        || admin_order_status_key((string)($order['orderStatus'] ?? $order['status'] ?? '')) === 'paid';
}

function admin_order_is_archived(array $order): bool
{
    return !empty($order['archived']);
}

function admin_order_is_pickup(array $order): bool
{
    $delivery = (array)($order['delivery'] ?? []);
    $text = (string)($delivery['label'] ?? '');
    foreach ((array)($order['items'] ?? []) as $item) $text .= ' ' . (string)($item['shippingName'] ?? '');
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    return str_contains($text, 'odbiór') || str_contains($text, 'odbior');
}

function admin_order_matches_filter(array $order, string $filter): bool
{
    if ($filter === 'archive') return admin_order_is_archived($order);
    if (admin_order_is_archived($order)) return false;
    $status = admin_order_status_key((string)($order['orderStatus'] ?? $order['status'] ?? ''));
    $paid = admin_order_is_paid($order);
    $terminal = in_array($status, ['shipped', 'completed', 'cancelled'], true);
    return match ($filter) {
        'new' => $status === 'new',
        'unpaid' => $status !== 'cancelled' && ($status === 'awaiting_payment' || !$paid),
        'paid' => $paid,
        'preparing' => in_array($status, ['paid', 'processing'], true) && !$terminal,
        'shipping' => $paid && !admin_order_is_pickup($order) && !$terminal,
        'shipped' => $status === 'shipped',
        'completed' => $status === 'completed',
        'cancelled' => $status === 'cancelled',
        default => true,
    };
}

function admin_order_status_options(): array
{
    return ['new', 'awaiting_payment', 'awaiting_shipping_quote', 'paid', 'processing', 'shipped', 'completed', 'cancelled', 'payment_failed'];
}

function admin_payment_status_options(): array
{
    return ['not_started', 'awaiting', 'awaiting_payment', 'confirmed', 'paid', 'failed', 'cancelled'];
}

function admin_order_date_label(string $date): string
{
    try { return (new DateTimeImmutable($date))->setTimezone(new DateTimeZone(STATS_TIMEZONE))->format('d.m.Y H:i'); }
    catch (Throwable $ignored) { return $date; }
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

        if ($action === 'save_google_config') {
            $currentGoogleConfig = load_google_business_config();
            save_google_business_config([
                'enabled' => isset($_POST['google_enabled']),
                'dry_run' => isset($_POST['google_dry_run']),
                'client_id' => post_text('google_client_id'),
                'client_secret' => post_text('google_client_secret'),
                'refresh_token' => post_text('google_refresh_token'),
                'account_id' => post_text('google_account_id'),
                'location_id' => post_text('google_location_id'),
                'site_url' => post_text('google_site_url'),
            ], $currentGoogleConfig);
            flash('success', 'Konfiguracja Google API została zapisana w chronionym katalogu panelu.');
            redirect_admin('google_config=1');
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
            redirect_admin(post_text('return') === 'figures' ? 'figures=1' : '');
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
            redirect_admin(post_text('return') === 'figures' ? 'figures=1' : '');
        }

        if ($action === 'update_order') {
            shop_update_order(
                post_text('order_id'),
                post_text('order_status'),
                post_text('payment_status'),
                post_text('internal_note')
            );
            flash('success', 'Zamówienie zostało zaktualizowane.');
            redirect_admin('orders=1');
        }

        if ($action === 'archive_order' || $action === 'restore_order') {
            $archived = $action === 'archive_order';
            shop_set_order_archived(post_text('order_id'), $archived, (string)($_SESSION['admin_username'] ?? 'administrator'));
            flash('success', $archived ? 'Zamówienie przeniesiono do archiwum.' : 'Zamówienie przywrócono z archiwum.');
            redirect_admin('orders=1' . ($archived ? '' : '&filter=all'));
        }

        if ($action === 'mark_order_test') {
            if (post_text('test_order_confirmation') !== '1') {
                throw new RuntimeException('Potwierdź oznaczenie zamówienia jako testowe.');
            }
            shop_mark_order_as_test(post_text('order_id'), (string)($_SESSION['admin_username'] ?? 'administrator'));
            flash('success', 'Zamówienie oznaczono jako testowe.');
            redirect_admin('orders=1');
        }

        if ($action === 'delete_test_order') {
            shop_delete_test_order(post_text('order_id'), post_text('delete_confirmation'));
            flash('success', 'Testowe zamówienie zostało trwale usunięte.');
            redirect_admin('orders=1&filter=archive');
        }

        if ($action === 'mark_bank_transfer_paid') {
            $changed = shop_mark_bank_transfer_paid(post_text('order_id'), (string)($_SESSION['admin_username'] ?? 'administrator'));
            flash('success', $changed ? 'Płatność przelewem oznaczono jako otrzymaną.' : 'Płatność była już oznaczona jako otrzymana.');
            redirect_admin('orders=1');
        }
        if ($action === 'set_item_shipping_quote') {
            $changed = shop_set_item_shipping_quote(post_text('order_id'), (int)post_text('item_index'), post_text('shipping_unit_cost'), (string)($_SESSION['admin_username'] ?? 'administrator'));
            flash('success', $changed ? 'Koszt dostawy pozycji został zapisany.' : 'Koszt tej pozycji był już ustalony.');
            redirect_admin('orders=1');
        }

        if ($action === 'save_shipping_profile') {
            $profiles = shipping_profiles_by_id(false);
            $originalId = clean_shipping_profile_id(post_text('original_profile_id'));
            $requestedId = clean_shipping_profile_id(post_text('shipping_id') !== '' ? post_text('shipping_id') : post_text('shipping_name'));
            if ($requestedId === '') {
                throw new RuntimeException('Podaj ID techniczne profilu dostawy.');
            }
            if ($originalId !== '' && $originalId !== $requestedId) {
                unset($profiles[$originalId]);
            }
            if (($originalId === '' || $originalId !== $requestedId) && isset($profiles[$requestedId])) {
                throw new RuntimeException('Profil dostawy o takim ID już istnieje.');
            }

            $types = array_keys(shipping_profile_types());
            $profile = array_merge(shipping_profile_defaults(), $profiles[$requestedId] ?? []);
            $profile['id'] = $requestedId;
            $profile['name'] = post_text('shipping_name');
            $profile['customerName'] = post_text('shipping_customer_name');
            $profile['type'] = in_array(post_text('shipping_type'), $types, true) ? post_text('shipping_type') : 'kurier';
            $profile['price'] = shipping_profile_price_number(post_text('shipping_price'));
            $profile['currency'] = 'PLN';
            $profile['active'] = post_text('shipping_status') === 'active';
            $profile['description'] = post_text('shipping_description');
            $profile['maxWeightKg'] = post_text('shipping_max_weight');
            $profile['maxLengthCm'] = post_text('shipping_max_length');
            $profile['maxWidthCm'] = post_text('shipping_max_width');
            $profile['maxHeightCm'] = post_text('shipping_max_height');
            $profile['requiresConfirmation'] = isset($_POST['shipping_requires_confirmation']);
            $profile['priceFrom'] = isset($_POST['shipping_price_from']);
            $profile['sortOrder'] = (int)($_POST['shipping_sort_order'] ?? 100);
            $profile['internalNote'] = post_text('shipping_internal_note');
            if ($profile['name'] === '') {
                throw new RuntimeException('Podaj nazwę profilu dostawy.');
            }
            if ($profile['customerName'] === '') {
                $profile['customerName'] = $profile['name'];
            }

            $profiles[$requestedId] = $profile;
            save_shipping_profiles(array_values($profiles));
            flash('success', 'Profil dostawy został zapisany.');
            redirect_admin('shipping=1');
        }

        if ($action === 'delete_shipping_profile') {
            $id = clean_shipping_profile_id(post_text('profile_id'));
            $profiles = shipping_profiles_by_id(false);
            if ($id === '' || !isset($profiles[$id])) {
                throw new RuntimeException('Nie znaleziono profilu dostawy do usunięcia.');
            }
            unset($profiles[$id]);
            save_shipping_profiles(array_values($profiles));
            flash('success', 'Profil dostawy został usunięty. Produkty z tym ID przejdą na dostawę indywidualną.');
            redirect_admin('shipping=1');
        }

        if ($action === 'import_catalog') {
            if (!isset($_POST['confirm_import'])) {
                throw new RuntimeException('Potwierdź, że chcesz zastąpić katalog przygotowaną kopią.');
            }

            $rawCatalog = trim((string)($_POST['catalog_json'] ?? ''));
            if ($rawCatalog === '' || strlen($rawCatalog) > 2 * 1024 * 1024) {
                throw new RuntimeException('Wklej poprawny katalog JSON o rozmiarze do 2 MB.');
            }

            $importedCatalog = json_decode($rawCatalog, true);
            if (!is_array($importedCatalog) || !isset($importedCatalog['products']) || !is_array($importedCatalog['products'])) {
                throw new RuntimeException('Importowany plik musi zawierać tablicę products.');
            }
            if (count($importedCatalog['products']) < 1 || count($importedCatalog['products']) > 1000) {
                throw new RuntimeException('Importowany katalog ma nieprawidłową liczbę produktów.');
            }

            foreach ($importedCatalog['products'] as $productIndex => $importedProduct) {
                if (!is_array($importedProduct)) {
                    throw new RuntimeException('Produkt nr ' . ($productIndex + 1) . ' ma nieprawidłową strukturę.');
                }
                if (trim((string)($importedProduct['name'] ?? '')) === '') {
                    throw new RuntimeException('Produkt nr ' . ($productIndex + 1) . ' nie ma nazwy.');
                }
                if (trim((string)($importedProduct['image'] ?? '')) === '') {
                    throw new RuntimeException('Produkt nr ' . ($productIndex + 1) . ' nie ma zdjęcia głównego.');
                }
            }

            save_catalog($importedCatalog);
            flash('success', 'Zaimportowano bezpiecznie ' . count($importedCatalog['products']) . ' produktów. Poprzedni katalog zapisano w kopiach panelu.');
            redirect_admin();
        }

        if ($action === 'prepare_figure_images') {
            $name = post_text('draft_name');
            if ($name === '') throw new RuntimeException('Podaj roboczą nazwę figury. Posłuży do przygotowania opisowych nazw plików.');
            $draft = create_product_image_draft((array)($_FILES['draft_images'] ?? []), $name);
            flash('success', 'Zdjęcia przygotowano jako WebP w prywatnym katalogu roboczym. Sprawdź dane i zapisz produkt dopiero po akceptacji.');
            redirect_admin('new=1&type=garden_figure&draft=' . rawurlencode((string)$draft['id']));
        }

        if ($action === 'cancel_figure_draft') {
            remove_product_image_draft(post_text('draft_id'));
            flash('success', 'Anulowano przygotowanie i usunięto prywatne pliki robocze.');
            redirect_admin('figures=1');
        }

        if ($action === 'import_codex_draft') {
            $draftId = product_image_draft_id(post_text('draft_id'));
            $draft = $draftId === '' ? null : load_product_image_draft($draftId);
            if (!$draft) throw new RuntimeException('Nie znaleziono aktywnego draftu do importu.');
            $file = $_FILES['product_draft_json'] ?? null;
            if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Wskaż poprawny plik product-draft.json.');
            }
            if ((int)($file['size'] ?? 0) > MAX_PRODUCT_DRAFT_JSON_BYTES || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
                throw new RuntimeException('Plik product-draft.json przekracza limit 256 KB albo nie jest poprawnym uploadem.');
            }
            $json = file_get_contents((string)$file['tmp_name']);
            if ($json === false) throw new RuntimeException('Nie udało się odczytać pliku product-draft.json.');
            try {
                $analysis = validate_codex_product_draft($json, $draft);
            } catch (JsonException $exception) {
                throw new RuntimeException('Plik product-draft.json nie zawiera poprawnego JSON.');
            }
            $draft['analysis'] = array_merge($analysis, ['status' => 'codex_prepared', 'importedAt' => time()]);
            save_product_image_draft($draft);
            flash('success', 'Dane z Codexa zostały wczytane. Sprawdź formularz przed zapisaniem produktu.');
            redirect_admin('new=1&type=garden_figure&draft=' . rawurlencode($draftId));
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
                'name', 'saleType', 'category', 'productType', 'sku', 'grossPrice', 'shopStatus',
                'catalogPrice', 'outletPrice', 'currency',
                'imageAlt', 'description', 'longDescription', 'dimensions', 'material', 'color',
                'height', 'width', 'depth', 'weight', 'packageDimensions', 'packageWeight',
                'packageLengthCm', 'packageWidthCm', 'packageHeightCm', 'producerAvailability', 'leadTime',
                'condition', 'status', 'productStatus', 'seoTitle', 'seoDescription', 'slug',
                'googleStatus', 'googleSentAt', 'googleMediaId', 'googlePostId', 'googleText', 'googleError'
            ];
            foreach ($textFields as $field) {
                $product[$field] = post_text($field);
            }
            $product['saleType'] = in_array($product['saleType'], ['showroom', 'garden_figure'], true) ? $product['saleType'] : 'showroom';
            if (!in_array($product['category'], product_category_options(), true)) {
                throw new RuntimeException('Wybierz obsługiwaną kategorię produktu.');
            }
            $product['featured'] = isset($_POST['featured']);
            $product['visible'] = isset($_POST['visible']);
            $product['shopVisible'] = isset($_POST['shopVisible']);
            $product['outdoorUse'] = isset($_POST['outdoorUse']);
            $product['fragileTransport'] = isset($_POST['fragileTransport']);
            $product['delicateProduct'] = isset($_POST['delicateProduct']);
            $product['handPainted'] = isset($_POST['handPainted']);
            $product['heavyProduct'] = isset($_POST['heavyProduct']);
            $product['oversizedProduct'] = isset($_POST['oversizedProduct']);
            $product['googleManualProduct'] = isset($_POST['googleManualProduct']);
            $product['order'] = (int)($_POST['order'] ?? 0);
            $product['currency'] = 'PLN';
            $product['shopStatus'] = $product['shopStatus'] !== '' ? $product['shopStatus'] : 'Ukryty';
            $product['productStatus'] = $product['productStatus'] !== '' ? $product['productStatus'] : 'Aktywny';
            $product['producerAvailability'] = $product['producerAvailability'] !== '' ? $product['producerAvailability'] : 'Dostępny u producenta';
            $product['leadTime'] = $product['leadTime'] !== '' ? $product['leadTime'] : '2-5 dni roboczych';
            $shippingProfileMap = shipping_profiles_by_id(false);
            $shippingProfileIds = [];
            foreach ((array)($_POST['shipping_profile_ids'] ?? []) as $profileId) {
                $profileId = clean_shipping_profile_id((string)$profileId);
                if ($profileId !== '' && isset($shippingProfileMap[$profileId])) {
                    $shippingProfileIds[] = $profileId;
                }
            }
            $product['shippingProfileIds'] = array_values(array_unique($shippingProfileIds));
            unset($product['deliveryMethods']);
            $product['slug'] = unique_product_slug($product['slug'] !== '' ? $product['slug'] : $name, $catalog['products'], $isEdit ? $index : null);
            if ($product['googleText'] === '') {
                $product['googleText'] = google_business_description($product);
            }
            if ($product['googleManualProduct'] && in_array($product['googleStatus'], ['', 'Nie wysłano'], true)) {
                $product['googleStatus'] = 'Dodane ręcznie';
            }

            $draftId = product_image_draft_id(post_text('draft_id'));
            $draftPublished = [];
            if ($draftId !== '') {
                $published = publish_product_image_draft($draftId, basename(post_text('draft_main')), array_map('basename', (array)($_POST['draft_gallery'] ?? [])), $name);
                $product['image'] = $published['main'];
                $product['gallery'] = $published['gallery'];
                $draftPublished = $published['created'];
            } else {
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
                        if ($newPath !== '') $gallery[] = $newPath;
                    }
                }
                $product['gallery'] = array_values(array_unique($gallery));
            }

            if ($isEdit) {
                $catalog['products'][$index] = $product;
            } else {
                array_unshift($catalog['products'], $product);
                $index = 0;
            }
            try {
                save_catalog($catalog);
            } catch (Throwable $exception) {
                foreach ($draftPublished as $publicPath) @unlink(SITE_ROOT . $publicPath);
                throw $exception;
            }
            if ($draftId !== '') remove_product_image_draft($draftId);
            $previewPath = (($product['saleType'] ?? 'showroom') === 'garden_figure') ? '/sklep/figury-ogrodowe/produkt/' . $product['slug'] : '/produkt/' . $product['slug'];
            flash('success', ($isEdit ? 'Produkt został zaktualizowany.' : 'Nowy produkt został dodany.') . ' Podgląd: ' . $previewPath);
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

cleanup_product_image_drafts();
$catalog = load_catalog();
$products = $catalog['products'];
$editRaw = (string)($_GET['edit'] ?? '');
$editing = $editRaw !== '' && ctype_digit($editRaw) && isset($products[(int)$editRaw]);
$newProduct = isset($_GET['new']);
$newType = (string)($_GET['type'] ?? '');
$newSaleType = in_array($newType, ['showroom', 'garden_figure'], true) ? $newType : 'showroom';
$newFigureMethod = in_array((string)($_GET['method'] ?? ''), ['manual', 'codex'], true) ? (string)$_GET['method'] : '';
$showPassword = isset($_GET['password']);
$showImport = isset($_GET['import']);
$showStats = isset($_GET['stats']);
$showOrders = isset($_GET['orders']);
$showFigures = isset($_GET['figures']);
$showShipping = isset($_GET['shipping']);
$showGoogleConfig = isset($_GET['google_config']);
$editIndex = $editing ? (int)$editRaw : null;
$product = $editing ? array_merge(product_defaults(), $products[$editIndex]) : product_defaults();
$draftId = product_image_draft_id((string)($_GET['draft'] ?? ''));
$imageDraft = (!$editing && $draftId !== '') ? load_product_image_draft($draftId) : null;
$codexAnalysis = $imageDraft ? imported_codex_product_draft($imageDraft) : null;
if (!$editing && $newProduct) {
    $product['saleType'] = $newSaleType;
    if ($newSaleType === 'garden_figure') {
        $product['category'] = 'Figury i dekoracje ogrodowe';
        $product['productType'] = 'figura ogrodowa';
        $product['shopVisible'] = true;
        $product['visible'] = false;
        $product['shopStatus'] = 'Dostępny';
        $product['status'] = 'Dostępne';
        $product['productStatus'] = 'Aktywny';
        $product['producerAvailability'] = 'Dostępny u producenta';
        $product['leadTime'] = '2-5 dni roboczych';
    }
}
if ($imageDraft) {
    $product['name'] = (string)($imageDraft['productName'] ?? '');
    if ($codexAnalysis) {
        foreach ((array)($codexAnalysis['product'] ?? []) as $field => $value) {
            if (array_key_exists($field, $product) && is_string($value) && $value !== '') $product[$field] = $value;
        }
    } else {
        $product['imageAlt'] = $product['name'] !== '' ? $product['name'] . ' – figura ogrodowa' : '';
        $product['description'] = $product['name'] !== '' ? $product['name'] . '. Produkt dostępny w Home & Garden Outlet.' : '';
        $product['longDescription'] = $product['name'] !== '' ? 'Figura ogrodowa „' . $product['name'] . '”. Przed zakupem sprawdź wymiary, materiał, dostępność i sposób dostawy.' : '';
        $product['seoTitle'] = $product['name'] !== '' ? $product['name'] . ' | Home & Garden Outlet' : '';
        $product['seoDescription'] = $product['name'] !== '' ? $product['name'] . ' – sprawdź dostępność, wymiary i dostawę w Home & Garden Outlet.' : '';
        $product['slug'] = clean_filename($product['name']);
    }
}
$codexImageMap = [];
foreach ((array)($codexAnalysis['images'] ?? []) as $image) {
    if (is_array($image) && isset($image['draftFile'])) $codexImageMap[(string)$image['draftFile']] = $image;
}
$codexMainFile = '';
foreach ($codexImageMap as $file => $image) if (($image['role'] ?? '') === 'main') $codexMainFile = $file;
$googleTextPreview = trim((string)($product['googleText'] ?? '')) !== ''
    ? (string)$product['googleText']
    : google_business_description($product);
$googleStatusOptions = ['Nie wysłano', 'Wysłano', 'Błąd', 'Dodane ręcznie'];
$shippingProfiles = load_shipping_profiles();
$shippingProfilesById = shipping_profiles_by_id(false);
$activeShippingProfilesById = shipping_profiles_by_id(true);
$currentShippingProfileIds = product_shipping_profile_ids($product);
$search = trim((string)($_GET['q'] ?? ''));
$isFigureProduct = static fn(array $item): bool => (($item['saleType'] ?? 'showroom') === 'garden_figure');
$listedProducts = [];
foreach ($products as $listedIndex => $listedProduct) {
    if (!is_array($listedProduct)) {
        continue;
    }
    if ($showFigures === $isFigureProduct($listedProduct)) {
        $listedProducts[$listedIndex] = $listedProduct;
    }
}
$activeFiguresTab = $showFigures || (($editing || $newProduct) && (($product['saleType'] ?? 'showroom') === 'garden_figure'));
$activeOutletTab = !$activeFiguresTab && !$showOrders && !$showShipping && !$showStats && !$showGoogleConfig && !$showImport && !$showPassword;
$statusFilter = trim((string)($_GET['status'] ?? ''));
$visibilityFilter = trim((string)($_GET['visibility'] ?? ''));
$deliveryFilter = trim((string)($_GET['delivery'] ?? ''));
$missingFilter = trim((string)($_GET['missing'] ?? ''));
$statsRange = normalize_stats_range((string)($_GET['range'] ?? 'today'));
$statsTab = (string)($_GET['stats_tab'] ?? 'general') === 'locations' ? 'locations' : 'general';
$statsProductLimit = normalize_stats_product_limit($_GET['product_limit'] ?? 10);
$statsRangeLabels = ['today' => 'Dzisiaj', '7' => 'Ostatnie 7 dni', '30' => 'Ostatnie 30 dni', '90' => 'Ostatnie 90 dni'];
$statsProductLimitLabels = [10 => 'Top 10', 25 => 'Top 25', 50 => 'Top 50'];
$statsToday = $stats7 = $stats30 = $stats90 = $statsSelected = $statsLocations = null;
$statsCards = [];
$statsTopProducts = [];
$googleConfig = load_google_business_config();
$googleConfigStatus = google_business_config_status($googleConfig);
$shopOrders = $showOrders ? shop_load_orders() : [];
$orderFilterLabels = ['all' => 'Wszystkie', 'new' => 'Nowe', 'unpaid' => 'Nieopłacone', 'paid' => 'Opłacone', 'preparing' => 'Do przygotowania', 'shipping' => 'Do wysyłki', 'shipped' => 'Wysłane', 'completed' => 'Zrealizowane', 'cancelled' => 'Anulowane', 'archive' => 'Archiwum'];
$orderFilter = (string)($_GET['filter'] ?? 'all');
if (!isset($orderFilterLabels[$orderFilter])) $orderFilter = 'all';
usort($shopOrders, static fn(array $a, array $b): int => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? '')));
$orderFilterCounts = array_fill_keys(array_keys($orderFilterLabels), 0);
foreach ($shopOrders as $shopOrder) {
    foreach ($orderFilterLabels as $filterKey => $_label) {
        if (admin_order_matches_filter($shopOrder, $filterKey)) $orderFilterCounts[$filterKey]++;
    }
}
$visibleShopOrders = array_values(array_filter($shopOrders, static fn(array $order): bool => admin_order_matches_filter($order, $orderFilter)));
$shopOrderStatuses = admin_order_status_options();
$shopPaymentStatuses = admin_payment_status_options();
$shopDeliveryLabels = shop_delivery_labels();
$shippingEditId = clean_shipping_profile_id((string)($_GET['shipping_edit'] ?? ''));
$shippingEditing = $shippingEditId !== '' && isset($shippingProfilesById[$shippingEditId]);
$shippingNew = isset($_GET['shipping_new']);
$shippingProfile = $shippingEditing ? $shippingProfilesById[$shippingEditId] : shipping_profile_defaults();
if ($shippingNew && $shippingProfile['sortOrder'] === 100) {
    $shippingProfile['sortOrder'] = count($shippingProfiles) * 10 + 10;
}
$filteredProducts = [];
foreach ($listedProducts as $listedIndex => $listedProduct) {
    $nameMatch = $search === '' || (function_exists('mb_stripos')
        ? mb_stripos((string)($listedProduct['name'] ?? ''), $search) !== false
        : stripos((string)($listedProduct['name'] ?? ''), $search) !== false);
    if (!$nameMatch) {
        continue;
    }

    if ($statusFilter !== '') {
        $statusValue = $showFigures ? (string)($listedProduct['shopStatus'] ?? '') : (string)($listedProduct['status'] ?? '');
        if ($statusValue !== $statusFilter) {
            continue;
        }
    }

    if ($visibilityFilter === 'visible') {
        if ($showFigures ? empty($listedProduct['shopVisible']) : empty($listedProduct['visible'])) {
            continue;
        }
    } elseif ($visibilityFilter === 'hidden') {
        if ($showFigures ? !empty($listedProduct['shopVisible']) : !empty($listedProduct['visible'])) {
            continue;
        }
    }

    if ($showFigures && $deliveryFilter !== '') {
        if (!in_array($deliveryFilter, product_shipping_profile_ids($listedProduct), true)) {
            continue;
        }
    }

    if ($showFigures && $missingFilter !== '') {
        $deliveryMethods = product_shipping_profile_ids($listedProduct);
        $missing = false;
        if ($missingFilter === 'price') {
            $missing = trim((string)($listedProduct['grossPrice'] ?? '')) === '';
        } elseif ($missingFilter === 'image') {
            $missing = trim((string)($listedProduct['image'] ?? '')) === '';
        } elseif ($missingFilter === 'weight') {
            $missing = trim((string)($listedProduct['weight'] ?? '')) === '';
        } elseif ($missingFilter === 'delivery') {
            $missing = count($deliveryMethods) === 0;
        }
        if (!$missing) {
            continue;
        }
    }

    $filteredProducts[$listedIndex] = $listedProduct;
}

if ($showStats) {
    $statsToday = load_stats_summary('today', $catalog);
    $stats7 = load_stats_summary('7', $catalog);
    $stats30 = load_stats_summary('30', $catalog);
    $stats90 = load_stats_summary('90', $catalog);
    $statsSelected = $statsRange === 'today' ? $statsToday : ($statsRange === '7' ? $stats7 : ($statsRange === '30' ? $stats30 : $stats90));
    $statsLocations = load_location_summary($statsRange);
    $statsTopProducts = array_slice($statsSelected['topProducts'] ?? [], 0, $statsProductLimit);
    $statsCards = [
        ['label' => 'Odsłony dzisiaj', 'value' => $statsToday['totals']['page_view'] ?? 0],
        ['label' => 'Odsłony 7 dni', 'value' => $stats7['totals']['page_view'] ?? 0],
        ['label' => 'Odsłony 30 dni', 'value' => $stats30['totals']['page_view'] ?? 0],
        ['label' => 'Odsłony 90 dni', 'value' => $stats90['totals']['page_view'] ?? 0],
        ['label' => 'Produkty dzisiaj', 'value' => $statsToday['totals']['product_view'] ?? 0],
        ['label' => 'Telefony dzisiaj', 'value' => $statsToday['totals']['call_click'] ?? 0],
        ['label' => 'Nawigacja dzisiaj', 'value' => $statsToday['totals']['navigation_click'] ?? 0],
        ['label' => 'SMS dzisiaj', 'value' => $statsToday['totals']['sms_click'] ?? 0],
    ];
}
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
      <a class="btn btn-secondary btn-small" href="/sklep/figury-ogrodowe" target="_blank" rel="noopener">Podgląd sklepu figur</a>
      <a class="btn btn-secondary btn-small" href="/admin/?stats=1">Statystyki</a>
      <a class="btn btn-secondary btn-small" href="/admin/?orders=1">Zamówienia sklepu</a>
      <a class="btn btn-secondary btn-small" href="/admin/?shipping=1">Cennik dostaw</a>
      <a class="btn btn-secondary btn-small" href="/admin/?google_config=1">Google API</a>
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

    <nav class="admin-tabs" aria-label="Główne sekcje panelu">
      <a class="<?= $activeOutletTab ? 'active' : '' ?>" href="/admin/">Produkty outletowe</a>
      <a class="<?= $activeFiguresTab ? 'active' : '' ?>" href="/admin/?figures=1">Figury ogrodowe / sklep online</a>
      <a class="<?= $showOrders ? 'active' : '' ?>" href="/admin/?orders=1">Zamówienia sklepu</a>
      <a class="<?= $showShipping ? 'active' : '' ?>" href="/admin/?shipping=1">Cennik dostaw</a>
      <a href="/sklep/figury-ogrodowe" target="_blank" rel="noopener">Podgląd sklepu figur</a>
    </nav>

    <?php if ($showShipping): ?>
      <div class="page-heading">
        <div>
          <p class="muted">Centralne ceny dostawy dla sklepu z figurami</p>
          <h1>Cennik dostaw</h1>
        </div>
        <div class="header-actions">
          <a class="btn btn-secondary" href="/admin/?figures=1">Figury ogrodowe</a>
          <a class="btn" href="/admin/?shipping=1&amp;shipping_new=1">Dodaj profil dostawy</a>
        </div>
      </div>

      <section class="card shipping-info">
        <strong>Jak to działa?</strong>
        <p>Cenę dostawy ustawiasz tutaj jeden raz. Przy produkcie wybierasz tylko pasujące profile dostawy. Zmiana ceny profilu automatycznie zmieni cenę widoczną na karcie produktu, w koszyku i w nowych zamówieniach.</p>
      </section>

      <section class="shipping-layout">
        <div class="card">
          <div class="section-head"><div><p class="muted"><?= e((string)count($shippingProfiles)) ?> profili</p><h2>Profile wysyłek</h2></div></div>
          <div class="shipping-profile-list">
            <?php foreach ($shippingProfiles as $profileRow): ?>
              <article class="shipping-profile-row">
                <div>
                  <h3><?= e($profileRow['name']) ?></h3>
                  <p class="muted"><code><?= e($profileRow['id']) ?></code> · <?= e((string)($profileRow['customerName'] ?? $profileRow['name'])) ?> · <?= e(shipping_profile_price_label($profileRow)) ?></p>
                  <?php if (trim((string)($profileRow['description'] ?? '')) !== ''): ?><p><?= e($profileRow['description']) ?></p><?php endif; ?>
                  <div class="meta">
                    <span><?= !empty($profileRow['active']) ? 'Aktywny' : 'Ukryty' ?></span>
                    <span><?= e((string)($profileRow['type'] ?? '')) ?></span>
                    <?php if (!empty($profileRow['requiresConfirmation'])): ?><span>Wymaga potwierdzenia</span><?php endif; ?>
                    <?php if (!empty($profileRow['priceFrom'])): ?><span>Cena „od”</span><?php endif; ?>
                  </div>
                </div>
                <div class="row-actions">
                  <a class="btn btn-secondary btn-small" href="/admin/?shipping=1&amp;shipping_edit=<?= e((string)$profileRow['id']) ?>">Edytuj</a>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_shipping_profile">
                    <input type="hidden" name="profile_id" value="<?= e((string)$profileRow['id']) ?>">
                    <button class="btn btn-danger btn-small" type="submit" data-confirm="Usunąć ten profil dostawy? Produkty z tym ID przejdą na dostawę indywidualną.">Usuń</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($shippingNew || $shippingEditing): ?>
          <form method="post" class="card form-grid shipping-profile-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_shipping_profile">
            <input type="hidden" name="original_profile_id" value="<?= $shippingEditing ? e((string)$shippingProfile['id']) : '' ?>">
            <div class="section-title"><?= $shippingEditing ? 'Edytuj profil dostawy' : 'Nowy profil dostawy' ?></div>
            <div class="field"><label for="shipping-id">ID techniczne / slug</label><input id="shipping-id" name="shipping_id" required value="<?= e((string)$shippingProfile['id']) ?>" placeholder="np. kurier-standardowy"></div>
            <div class="field"><label for="shipping-name">Nazwa w panelu</label><input id="shipping-name" name="shipping_name" required value="<?= e((string)$shippingProfile['name']) ?>" placeholder="np. Kurier standardowy"></div>
            <div class="field"><label for="shipping-customer-name">Nazwa dla klienta</label><input id="shipping-customer-name" name="shipping_customer_name" value="<?= e((string)$shippingProfile['customerName']) ?>" placeholder="np. Kurier standardowy"></div>
            <div class="field"><label for="shipping-type">Typ dostawy</label><select id="shipping-type" name="shipping_type"><?php foreach (shipping_profile_types() as $typeKey => $typeLabel): ?><option value="<?= e($typeKey) ?>"<?= ($shippingProfile['type'] ?? '') === $typeKey ? ' selected' : '' ?>><?= e($typeLabel) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="shipping-price">Cena brutto</label><input id="shipping-price" name="shipping_price" value="<?= ($shippingProfile['price'] ?? null) === null ? '' : e(number_format((float)$shippingProfile['price'], 2, ',', '')) ?>" placeholder="np. 39,99"></div>
            <div class="field"><label for="shipping-status">Status</label><select id="shipping-status" name="shipping_status"><option value="active"<?= !empty($shippingProfile['active']) ? ' selected' : '' ?>>Aktywny</option><option value="hidden"<?= empty($shippingProfile['active']) ? ' selected' : '' ?>>Ukryty</option></select></div>
            <div class="field field-full"><label for="shipping-description">Krótki opis dla klienta</label><textarea id="shipping-description" name="shipping_description" rows="3"><?= e((string)$shippingProfile['description']) ?></textarea></div>
            <div class="field"><label for="shipping-max-weight">Maks. waga paczki</label><input id="shipping-max-weight" name="shipping_max_weight" value="<?= e((string)$shippingProfile['maxWeightKg']) ?>" placeholder="kg"></div>
            <div class="field"><label for="shipping-max-length">Maks. długość</label><input id="shipping-max-length" name="shipping_max_length" value="<?= e((string)$shippingProfile['maxLengthCm']) ?>" placeholder="cm"></div>
            <div class="field"><label for="shipping-max-width">Maks. szerokość</label><input id="shipping-max-width" name="shipping_max_width" value="<?= e((string)$shippingProfile['maxWidthCm']) ?>" placeholder="cm"></div>
            <div class="field"><label for="shipping-max-height">Maks. wysokość</label><input id="shipping-max-height" name="shipping_max_height" value="<?= e((string)$shippingProfile['maxHeightCm']) ?>" placeholder="cm"></div>
            <div class="field"><label class="check-line"><input type="checkbox" name="shipping_requires_confirmation"<?= !empty($shippingProfile['requiresConfirmation']) ? ' checked' : '' ?>> Wymaga indywidualnego potwierdzenia</label></div>
            <div class="field"><label class="check-line"><input type="checkbox" name="shipping_price_from"<?= !empty($shippingProfile['priceFrom']) ? ' checked' : '' ?>> Cena jest „od”</label></div>
            <div class="field"><label for="shipping-sort-order">Kolejność</label><input id="shipping-sort-order" type="number" name="shipping_sort_order" value="<?= e((string)$shippingProfile['sortOrder']) ?>"></div>
            <div class="field field-full"><label for="shipping-internal-note">Notatka wewnętrzna</label><textarea id="shipping-internal-note" name="shipping_internal_note" rows="2"><?= e((string)$shippingProfile['internalNote']) ?></textarea></div>
            <div class="field field-full form-actions"><button class="btn" type="submit">Zapisz profil dostawy</button><a class="btn btn-secondary" href="/admin/?shipping=1">Anuluj</a></div>
          </form>
        <?php else: ?>
          <section class="card empty">Wybierz profil z listy albo dodaj nowy profil dostawy.</section>
        <?php endif; ?>
      </section>
    <?php elseif ($showStats): ?>
      <div class="page-heading">
        <div><p class="muted">Anonimowe liczniki bez cookies i danych osobowych</p><h1>Statystyki</h1></div>
        <div class="header-actions"><a class="btn btn-secondary" href="/admin/">Produkty</a><a class="btn" href="/admin/?stats=1&amp;stats_tab=<?= e($statsTab) ?>&amp;range=<?= e($statsRange) ?>&amp;product_limit=<?= e((string)$statsProductLimit) ?>">Odśwież statystyki</a></div>
      </div>

      <nav class="admin-tabs stats-tabs" aria-label="Widok statystyk">
        <a class="<?= $statsTab === 'general' ? 'active' : '' ?>" href="/admin/?stats=1&amp;stats_tab=general&amp;range=<?= e($statsRange) ?>&amp;product_limit=<?= e((string)$statsProductLimit) ?>">Statystyki ogólne</a>
        <a class="<?= $statsTab === 'locations' ? 'active' : '' ?>" href="/admin/?stats=1&amp;stats_tab=locations&amp;range=<?= e($statsRange) ?>&amp;product_limit=<?= e((string)$statsProductLimit) ?>">Lokalizacja odsłon</a>
      </nav>

      <nav class="range-switch" aria-label="Zakres statystyk">
        <?php foreach ($statsRangeLabels as $rangeKey => $rangeLabel): ?>
          <a class="<?= $statsRange === $rangeKey ? 'active' : '' ?>" href="/admin/?stats=1&amp;stats_tab=<?= e($statsTab) ?>&amp;range=<?= e($rangeKey) ?>&amp;product_limit=<?= e((string)$statsProductLimit) ?>"><?= e($rangeLabel) ?></a>
        <?php endforeach; ?>
      </nav>

      <?php if ($statsTab === 'general'): ?>
      <section class="stats-grid">
        <?php foreach ($statsCards as $card): ?>
          <article class="stat-card">
            <span><?= e($card['label']) ?></span>
            <strong><?= e(number_format((int)$card['value'], 0, ',', ' ')) ?></strong>
          </article>
        <?php endforeach; ?>
      </section>

      <?php if (($statsSelected['invalidFiles'] ?? 0) > 0): ?>
        <div class="flash flash-error">Pominięto <?= e((string)$statsSelected['invalidFiles']) ?> uszkodzony plik statystyk. Panel działa dalej i pokazuje poprawne dane.</div>
      <?php endif; ?>

      <?php if (empty($statsSelected['hasData'])): ?>
        <section class="card empty">Brak danych statystycznych dla wybranego okresu.</section>
      <?php else: ?>
        <section class="card stats-section">
          <div class="section-head"><div><p class="muted"><?= e($statsRangeLabels[$statsRange]) ?></p><h2>Kontakt i działania klientów</h2></div></div>
          <div class="stats-actions-grid">
            <?php foreach ($statsSelected['buttonRows'] as $row): ?>
              <div><span><?= e($row['label']) ?></span><strong><?= e(number_format((int)$row['count'], 0, ',', ' ')) ?></strong></div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="card stats-section">
          <div class="section-head">
            <div><p class="muted">Top <?= e((string)$statsProductLimit) ?></p><h2>Najczęściej oglądane produkty</h2></div>
            <nav class="range-switch range-switch-compact" aria-label="Liczba produktów w tabeli">
              <?php foreach ($statsProductLimitLabels as $limitValue => $limitLabel): ?>
                <a class="<?= $statsProductLimit === $limitValue ? 'active' : '' ?>" href="/admin/?stats=1&amp;stats_tab=general&amp;range=<?= e($statsRange) ?>&amp;product_limit=<?= e((string)$limitValue) ?>"><?= e($limitLabel) ?></a>
              <?php endforeach; ?>
            </nav>
          </div>
          <?php if (empty($statsTopProducts)): ?>
            <p class="muted">Brak odsłon produktów w wybranym okresie.</p>
          <?php else: ?>
            <div class="table-wrap">
              <table class="stats-table">
                <thead><tr><th>Produkt</th><th>Slug</th><th>Odsłony</th><th>Telefon</th><th>SMS</th><th>Zapytanie</th></tr></thead>
                <tbody>
                  <?php foreach ($statsTopProducts as $row): ?>
                    <tr>
                      <td><?= e($row['name']) ?></td>
                      <td><code><?= e($row['slug']) ?></code></td>
                      <td><?= e(number_format((int)$row['views'], 0, ',', ' ')) ?></td>
                      <td><?= e(number_format((int)$row['call_click'], 0, ',', ' ')) ?></td>
                      <td><?= e(number_format((int)$row['sms_click'], 0, ',', ' ')) ?></td>
                      <td><?= e(number_format((int)$row['product_question_click'], 0, ',', ' ')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        <section class="card stats-section">
          <div class="section-head"><div><p class="muted">Top 10</p><h2>Najczęściej odwiedzane podstrony</h2></div></div>
          <?php if (empty($statsSelected['topPages'])): ?>
            <p class="muted">Brak odsłon podstron w wybranym okresie.</p>
          <?php else: ?>
            <div class="table-wrap">
              <table class="stats-table">
                <thead><tr><th>Ścieżka strony</th><th>Odsłony</th></tr></thead>
                <tbody>
                  <?php foreach ($statsSelected['topPages'] as $path => $count): ?>
                    <tr><td><code><?= e($path) ?></code></td><td><?= e(number_format((int)$count, 0, ',', ' ')) ?></td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        <section class="card stats-section">
          <div class="section-head"><div><p class="muted"><?= e($statsRangeLabels[$statsRange]) ?></p><h2>Najczęściej klikane przyciski</h2></div></div>
          <div class="table-wrap">
            <table class="stats-table">
              <thead><tr><th>Zdarzenie</th><th>Nazwa</th><th>Kliknięcia</th></tr></thead>
              <tbody>
                <?php foreach ($statsSelected['buttonRows'] as $row): ?>
                  <tr><td><code><?= e($row['event']) ?></code></td><td><?= e($row['label']) ?></td><td><?= e(number_format((int)$row['count'], 0, ',', ' ')) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>
      <?php else: ?>
        <?php $geoip = geoip_status(); ?>
        <?php if (!$geoip['ready']): ?>
          <section class="card empty"><h2>GeoIP nie skonfigurowane</h2><p><?= e($geoip['reason']) ?></p><p class="muted">Statystyki ogólne działają normalnie. Wgraj oficjalny plik GeoLite2-City.mmdb do <code>/home/ogfdvopi/private/geoip/GeoLite2-City.mmdb</code>.</p></section>
        <?php elseif (($statsLocations['pageViews'] ?? 0) === 0): ?>
          <section class="card empty"><h2>Brak danych lokalizacji w wybranym okresie</h2><p>Dane lokalizacji będą dostępne od pierwszej odsłony po uruchomieniu GeoIP.</p></section>
        <?php else: ?>
          <section class="card stats-section">
            <div class="section-head"><div><p class="muted">Skąd oglądana jest strona · <?= e($statsRangeLabels[$statsRange]) ?></p><h2>Ruch lokalny vs reszta Polski</h2></div></div>
            <div class="stats-actions-grid">
              <?php foreach (['wroclaw' => 'Wrocław', 'lowerSilesia' => 'Dolny Śląsk poza Wrocławiem', 'restPoland' => 'Pozostała Polska', 'foreign' => 'Zagranica'] as $key => $label): ?>
                <div><span><?= e($label) ?></span><strong><?= e(number_format((int)$statsLocations['local'][$key], 0, ',', ' ')) ?> · <?= e(stats_percent((int)$statsLocations['local'][$key], (int)$statsLocations['pageViews'])) ?></strong></div>
              <?php endforeach; ?>
            </div>
            <?php if (($statsLocations['local']['unknown'] ?? 0) > 0): ?><p class="muted">Nieznana lokalizacja: <?= e(number_format((int)$statsLocations['local']['unknown'], 0, ',', ' ')) ?> odsłon.</p><?php endif; ?>
          </section>
          <p class="muted stats-note">Dane lokalizacji są dostępne od <?= e((string)$statsLocations['firstDate']) ?> i obejmują <?= e((string)$statsLocations['daysWithData']) ?> dni z wybranego zakresu.</p>
          <section class="card stats-section"><div class="section-head"><div><p class="muted">Kraje</p><h2>Lokalizacja odsłon</h2></div></div><div class="table-wrap"><table class="stats-table"><thead><tr><th>Kraj</th><th>Odsłony</th><th>Udział</th></tr></thead><tbody><?php foreach ($statsLocations['countries'] as $row): ?><tr><td><?= e((string)$row['name']) ?> <code><?= e((string)$row['code']) ?></code></td><td><?= e(number_format((int)$row['count'], 0, ',', ' ')) ?></td><td><?= e(stats_percent((int)$row['count'], (int)$statsLocations['pageViews'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
          <section class="card stats-section"><div class="section-head"><div><p class="muted">Polska</p><h2>Województwa / regiony</h2></div></div><div class="table-wrap"><table class="stats-table"><thead><tr><th>Województwo</th><th>Odsłony</th><th>Udział</th></tr></thead><tbody><?php foreach (array_slice($statsLocations['regions'], 0, 20) as $row): ?><tr><td><?= e((string)$row['name']) ?></td><td><?= e(number_format((int)$row['count'], 0, ',', ' ')) ?></td><td><?= e(stats_percent((int)$row['count'], (int)$statsLocations['pageViews'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
          <section class="card stats-section"><div class="section-head"><div><p class="muted">Top 20</p><h2>Najpopularniejsze miasta</h2></div></div><div class="table-wrap"><table class="stats-table"><thead><tr><th>Miasto</th><th>Województwo / region</th><th>Odsłony</th><th>Udział</th></tr></thead><tbody><?php foreach ($statsLocations['cities'] as $row): ?><tr><td><?= e((string)$row['name']) ?></td><td><?= e((string)$row['region_name']) ?></td><td><?= e(number_format((int)$row['count'], 0, ',', ' ')) ?></td><td><?= e(stats_percent((int)$row['count'], (int)$statsLocations['pageViews'])) ?></td></tr><?php endforeach; ?><?php if ($statsLocations['otherCities'] > 0): ?><tr><td>Pozostałe</td><td>—</td><td><?= e(number_format((int)$statsLocations['otherCities'], 0, ',', ' ')) ?></td><td><?= e(stats_percent((int)$statsLocations['otherCities'], (int)$statsLocations['pageViews'])) ?></td></tr><?php endif; ?></tbody></table></div></section>
        <?php endif; ?>
      <?php endif; ?>
    <?php elseif ($showOrders): ?>
      <div class="page-heading">
        <div>
          <p class="muted">Testowy sklep figur · dane zamówień są w chronionym katalogu panelu</p>
          <h1>Zamówienia sklepu</h1>
        </div>
        <div class="header-actions">
          <a class="btn btn-secondary" href="/sklep/figury-ogrodowe" target="_blank" rel="noopener">Podgląd sklepu figur</a>
          <a class="btn btn-secondary" href="/admin/">Produkty</a>
        </div>
      </div>

      <?php if (!$shopOrders): ?>
        <section class="card empty">Nie ma jeszcze zamówień testowych.</section>
      <?php else: ?>
        <nav class="order-filters" aria-label="Filtry zamówień">
          <?php foreach ($orderFilterLabels as $filterKey => $filterLabel): ?>
            <a class="<?= $orderFilter === $filterKey ? 'active' : '' ?>" href="/admin/?orders=1&amp;filter=<?= e($filterKey) ?>"><?= e($filterLabel) ?> <span><?= e((string)$orderFilterCounts[$filterKey]) ?></span></a>
          <?php endforeach; ?>
        </nav>
        <?php if (!$visibleShopOrders): ?>
          <section class="card empty">Brak zamówień dla wybranego filtra.</section>
        <?php else: ?>
        <section class="orders-list">
          <?php foreach ($visibleShopOrders as $order): ?>
            <?php
              $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
              $deliveryAddress = is_array($order['deliveryAddress'] ?? null) ? $order['deliveryAddress'] : [];
              $delivery = is_array($order['delivery'] ?? null) ? $order['delivery'] : [];
              $items = is_array($order['items'] ?? null) ? $order['items'] : [];
              $orderStatus = (string)($order['orderStatus'] ?? $order['status'] ?? 'new');
              $paymentStatus = (string)($order['paymentStatus'] ?? 'not_started');
              $paymentMethod = (string)($order['paymentMethod'] ?? '');
            ?>
            <details class="card order-card">
              <summary class="order-card-head">
                <div>
                  <h2><?= e((string)($order['orderId'] ?? 'Zamówienie')) ?></h2>
                  <p class="muted">Klient: <?= e((string)($customer['email'] ?? 'brak e-maila')) ?> · <?= e(admin_order_date_label((string)($order['createdAt'] ?? ''))) ?></p>
                  <p class="order-card-badges"><span class="order-badge"><?= e(admin_order_status_label($orderStatus)) ?></span><span class="order-badge order-badge-payment">Płatność: <?= e($paymentMethod === 'paynow' ? 'Paynow — ' . admin_payment_status_label($paymentStatus) : ($paymentMethod === 'bank_transfer' ? 'Przelew tradycyjny — ' . admin_payment_status_label($paymentStatus) : admin_payment_status_label($paymentStatus))) ?></span></p>
                </div>
                <strong><?= e(number_format((float)($order['total'] ?? 0), 2, ',', ' ')) ?> zł</strong>
                <span class="order-details-toggle"><span class="order-details-open">Rozwiń szczegóły</span><span class="order-details-close">Zwiń szczegóły</span></span>
              </summary>

              <div class="order-card-details">

              <div class="order-grid">
                <div>
                  <h3>Klient</h3>
                  <p><?= e(trim((string)($customer['firstName'] ?? '') . ' ' . (string)($customer['lastName'] ?? '')) ?: (string)($customer['name'] ?? '')) ?></p>
                  <p><a href="mailto:<?= e($customer['email'] ?? '') ?>"><?= e($customer['email'] ?? '') ?></a></p>
                  <p><a href="tel:<?= e($customer['phone'] ?? '') ?>"><?= e($customer['phone'] ?? '') ?></a></p>
                  <p><?= e($deliveryAddress['street'] ?? $customer['address'] ?? '') ?>, <?= e($deliveryAddress['postalCode'] ?? $customer['postalCode'] ?? '') ?> <?= e($deliveryAddress['city'] ?? $customer['city'] ?? '') ?></p>
                  <?php if (trim((string)($customer['notes'] ?? '')) !== ''): ?><p class="muted">Uwagi: <?= e($customer['notes']) ?></p><?php endif; ?>
                </div>
                <div>
                  <h3>Dostawa i płatność</h3>
                  <p><?= e($delivery['label'] ?? 'Do ustalenia') ?> · <?= e((string)($delivery['costLabel'] ?? 'do ustalenia')) ?></p>
                  <?php if (trim((string)($delivery['profileId'] ?? '')) !== ''): ?><p class="muted">Profil dostawy: <code><?= e((string)$delivery['profileId']) ?></code><?= !empty($delivery['requiresConfirmation']) ? ' · wymaga potwierdzenia' : '' ?></p><?php endif; ?>
                  <p>Metoda płatności: <?= e($paymentMethod === 'paynow' ? 'Paynow' : ($paymentMethod === 'bank_transfer' ? 'Przelew tradycyjny' : $paymentMethod)) ?></p>
                  <p>Status płatności: <?= e(admin_payment_status_label($paymentStatus)) ?></p>
                  <?php if (($order['paymentProvider'] ?? '') === 'paynow' && !empty($order['paymentId'])): ?><p>Paynow paymentId: <?= e((string)$order['paymentId']) ?></p><?php endif; ?>
                  <p>Status zamówienia: <?= e(admin_order_status_label($orderStatus)) ?></p>
                </div>
              </div>

              <div class="table-wrap">
                <table class="stats-table">
                  <thead><tr><th>Produkt</th><th>Ilość</th><th>Cena</th><th>Suma</th><th>Dostawa pozycji</th></tr></thead>
                  <tbody>
                    <?php foreach ($items as $item): ?>
                      <tr>
                        <td><?= e($item['name'] ?? '') ?><br><code><?= e($item['slug'] ?? '') ?></code></td>
                        <td><?= e((string)($item['quantity'] ?? 0)) ?></td>
                        <td><?= e(number_format((float)($item['price'] ?? 0), 2, ',', ' ')) ?> zł</td>
                        <td><?= e(number_format((float)($item['lineTotal'] ?? 0), 2, ',', ' ')) ?> zł</td>
                        <td><?= e((string)($item['shippingName'] ?? 'Dostawa')) ?><br><small><?= !empty($item['shippingRequiresConfirmation']) ? 'koszt do potwierdzenia' : e(number_format(((int)($item['shippingLineCents'] ?? 0)) / 100, 2, ',', ' ') . ' zł') ?></small></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <form method="post" class="form-grid order-admin-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_order">
                <input type="hidden" name="order_id" value="<?= e((string)($order['orderId'] ?? '')) ?>">
                <div class="field">
                  <label>Status zamówienia</label>
                  <select name="order_status">
                    <?php foreach ($shopOrderStatuses as $option): ?><option value="<?= e($option) ?>"<?= (admin_order_status_key($orderStatus) === $option) ? ' selected' : '' ?>><?= e(admin_order_status_label($option)) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="field">
                  <?php if (($order['paymentProvider'] ?? '') === 'paynow'): ?>
                    <label>Status płatności Paynow</label>
                    <input type="text" value="<?= e(admin_payment_status_label($paymentStatus)) ?>" readonly aria-readonly="true">
                  <?php else: ?>
                    <label>Status płatności</label>
                    <select name="payment_status">
                      <?php foreach ($shopPaymentStatuses as $option): ?><option value="<?= e($option) ?>"<?= ($paymentStatus === $option) ? ' selected' : '' ?>><?= e(admin_payment_status_label($option)) ?></option><?php endforeach; ?>
                    </select>
                  <?php endif; ?>
                </div>
                <div class="field field-full">
                  <label>Notatka wewnętrzna</label>
                  <textarea name="internal_note" rows="3"><?= e((string)($order['internalNote'] ?? '')) ?></textarea>
                </div>
                <div class="field field-full"><button class="btn btn-small" type="submit">Zapisz status zamówienia</button></div>
              </form>
              <?php if (($order['paymentProvider'] ?? '') === 'bank_transfer' && ($order['paymentStatus'] ?? '') === 'awaiting'): ?>
                <form method="post" class="order-admin-form">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="mark_bank_transfer_paid">
                  <input type="hidden" name="order_id" value="<?= e((string)($order['orderId'] ?? '')) ?>">
                  <button class="btn btn-small" type="submit">Płatność otrzymana</button>
                </form>
              <?php endif; ?>
              <section class="order-management" aria-label="Zarządzanie zamówieniem">
                <h3>Zarządzanie zamówieniem</h3>
                <?php if (admin_order_is_archived($order)): ?>
                  <form method="post" class="order-admin-form">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="restore_order">
                    <input type="hidden" name="order_id" value="<?= e((string)($order['orderId'] ?? '')) ?>">
                    <button class="btn btn-small" type="submit">Przywróć z archiwum</button>
                  </form>
                  <?php if (!empty($order['isTestOrder'])): ?>
                    <form method="post" class="order-admin-form order-delete-form">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete_test_order">
                      <input type="hidden" name="order_id" value="<?= e((string)($order['orderId'] ?? '')) ?>">
                      <label>Wpisz numer zamówienia, aby trwale je usunąć<input name="delete_confirmation" autocomplete="off" required></label>
                      <p class="muted">Tej operacji nie można cofnąć. Trwale usuwane mogą być wyłącznie zamówienia testowe.</p>
                      <button class="btn btn-small btn-danger" type="submit">Usuń trwale</button>
                    </form>
                  <?php endif; ?>
                <?php else: ?>
                  <form method="post" class="order-admin-form">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="archive_order">
                    <input type="hidden" name="order_id" value="<?= e((string)($order['orderId'] ?? '')) ?>">
                    <button class="btn btn-small" type="submit">Przenieś do archiwum</button>
                  </form>
                  <?php if (empty($order['isTestOrder'])): ?>
                    <form method="post" class="order-admin-form order-test-form">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="mark_order_test">
                      <input type="hidden" name="order_id" value="<?= e((string)($order['orderId'] ?? '')) ?>">
                      <label class="check-line"><input type="checkbox" name="test_order_confirmation" value="1" required> Potwierdzam, że to zamówienie administracyjne/testowe.</label>
                      <button class="btn btn-small btn-secondary" type="submit">Oznacz jako testowe</button>
                    </form>
                  <?php else: ?>
                    <p class="muted">Zamówienie jest jednoznacznie oznaczone jako testowe. Trwałe usunięcie będzie dostępne dopiero w archiwum.</p>
                  <?php endif; ?>
                <?php endif; ?>
              </section>
              <?php if (($order['orderStatus'] ?? '') === 'awaiting_shipping_quote'): ?>
                <?php foreach ($items as $itemIndex => $item): ?>
                  <?php if (!empty($item['shippingRequiresConfirmation'])): ?>
                    <form method="post" class="order-admin-form">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="set_item_shipping_quote"><input type="hidden" name="order_id" value="<?= e((string)($order['orderId'] ?? '')) ?>"><input type="hidden" name="item_index" value="<?= e((string)$itemIndex) ?>">
                      <label>Ustal koszt dostawy jednej sztuki: <?= e((string)($item['name'] ?? 'Produkt')) ?> (ilość <?= e((string)($item['quantity'] ?? 1)) ?>)<input name="shipping_unit_cost" inputmode="decimal" required></label>
                      <button class="btn btn-small" type="submit">Ustal koszt tej pozycji</button>
                    </form>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php if (false): ?>
                <form method="post" class="order-admin-form">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="set_shipping_quote"><input type="hidden" name="order_id" value="<?= e((string)($order['orderId'] ?? '')) ?>">
                  <label>Finalny koszt dostawy (PLN)<input name="shipping_cost" inputmode="decimal" required></label>
                  <button class="btn btn-small" type="submit">Ustal koszt dostawy</button>
                </form>
              <?php endif; ?>
              <?php endif; ?>
              </div>
            </details>
          <?php endforeach; ?>
        </section>
        <?php endif; ?>
      <?php endif; ?>
    <?php elseif ($showGoogleConfig): ?>
      <div class="page-heading">
        <div>
          <p class="muted">Tajne dane są zapisywane tylko na serwerze, poza publicznymi plikami strony</p>
          <h1>Google API</h1>
        </div>
        <a class="btn btn-secondary" href="/admin/">Wróć do produktów</a>
      </div>
      <form method="post" class="card form-grid">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_google_config">
        <div class="field field-full google-helper">
          <strong><?= $googleConfigStatus['ready'] ? 'Konfiguracja wygląda na gotową' : 'Konfiguracja jeszcze nie jest kompletna' ?></strong>
          <p>
            <?= $googleConfigStatus['ready']
                ? 'Panel może próbować realnej wysyłki do Google Business Profile.'
                : 'Na razie panel będzie działał w trybie testowym. Brakuje: ' . e(implode(', ', $googleConfigStatus['missing'])) ?>
          </p>
        </div>
        <div class="field">
          <label class="check-line"><input type="checkbox" name="google_enabled"<?= !empty($googleConfig['enabled']) ? ' checked' : '' ?>> Włącz realną integrację Google API</label>
          <small>Zostaw wyłączone, dopóki nie sprawdzimy całej konfiguracji.</small>
        </div>
        <div class="field">
          <label class="check-line"><input type="checkbox" name="google_dry_run"<?= !empty($googleConfig['dry_run']) ? ' checked' : '' ?>> Tryb testowy bez wysyłania</label>
          <small>Bezpieczny tryb: panel tylko pokazuje, co zostałoby wysłane.</small>
        </div>
        <div class="field field-full"><label for="google_client_id">Client ID</label><input id="google_client_id" name="google_client_id" value="<?= e($googleConfig['client_id']) ?>" autocomplete="off"></div>
        <div class="field field-full"><label for="google_client_secret">Client secret</label><input id="google_client_secret" type="password" name="google_client_secret" autocomplete="new-password" placeholder="<?= trim((string)$googleConfig['client_secret']) !== '' ? 'Zapisany - zostaw puste, aby nie zmieniać' : 'Wklej client secret' ?>"></div>
        <div class="field field-full"><label for="google_refresh_token">Refresh token</label><input id="google_refresh_token" type="password" name="google_refresh_token" autocomplete="new-password" placeholder="<?= trim((string)$googleConfig['refresh_token']) !== '' ? 'Zapisany - zostaw puste, aby nie zmieniać' : 'Wklej refresh token z OAuth Playground' ?>"></div>
        <div class="field"><label for="google_account_id">Account ID</label><input id="google_account_id" name="google_account_id" value="<?= e($googleConfig['account_id']) ?>" autocomplete="off"></div>
        <div class="field"><label for="google_location_id">Location ID</label><input id="google_location_id" name="google_location_id" value="<?= e($googleConfig['location_id']) ?>" autocomplete="off"></div>
        <div class="field field-full"><label for="google_site_url">Adres strony</label><input id="google_site_url" name="google_site_url" value="<?= e($googleConfig['site_url']) ?>" autocomplete="off"></div>
        <div class="field field-full google-helper">
          <strong>Instrukcja do tokena</strong>
          <p>Refresh token wygenerujemy przez Google OAuth Playground ze scope: https://www.googleapis.com/auth/business.manage. Dane wklejone tutaj nie są pokazywane klientom i nie trafiają do GitHuba.</p>
        </div>
        <div class="field field-full google-api-actions">
          <button class="btn btn-secondary btn-small" type="button" data-google-action="discover_locations">Pobierz wizytówki z Google</button>
          <button class="btn btn-secondary btn-small" type="button" data-google-action="refresh_reviews">Odśwież opinie z Google</button>
          <small>Po zapisaniu Client ID, Client secret i Refresh token kliknij tutaj. Panel pokaże Account ID i Location ID do wklejenia w pola powyżej.</small>
          <div class="google-api-result" data-google-result hidden></div>
        </div>
        <div class="field field-full"><button class="btn" type="submit">Zapisz konfigurację Google API</button></div>
      </form>
    <?php elseif ($showImport): ?>
      <div class="page-heading">
        <div><p class="muted">Bezpieczna aktualizacja</p><h1>Import katalogu</h1></div>
        <a class="btn btn-secondary" href="/admin/">Wróć do listy</a>
      </div>
      <form method="post" class="card">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="import_catalog">
        <div class="form-grid">
          <div class="field field-full">
            <label for="catalog-json">Przygotowany katalog JSON</label>
            <textarea id="catalog-json" name="catalog_json" required maxlength="2097152" rows="18" placeholder='{"products":[...]}'></textarea>
            <small>Panel sprawdzi strukturę, nazwy i zdjęcia. Przed zapisem automatycznie utworzy kopię obecnego katalogu.</small>
          </div>
          <div class="field field-full">
            <label class="check-line"><input type="checkbox" name="confirm_import" required> Potwierdzam zastąpienie katalogu przygotowaną kopią</label>
          </div>
        </div>
        <div class="form-actions"><button class="btn" type="submit">Sprawdź i importuj katalog</button><a class="btn btn-secondary" href="/admin/">Anuluj</a></div>
      </form>
    <?php elseif ($newProduct || $editing): ?>
      <div class="page-heading">
        <div><p class="muted">Katalog produktów</p><h1><?= $editing ? 'Edytuj produkt' : 'Dodaj nowy produkt' ?></h1></div>
        <a class="btn btn-secondary" href="/admin/">Wróć do listy</a>
      </div>
      <?php if ($newProduct && $newSaleType === 'garden_figure' && !$imageDraft && $newFigureMethod === ''): ?>
        <section class="card">
          <div class="section-title">Wybierz sposób dodania figury</div>
          <div class="form-grid">
            <div class="field">
              <h2>Dodaj ręcznie</h2>
              <p>Samodzielnie uzupełnij wszystkie dane produktu i zdjęcia.</p>
              <a class="btn" href="/admin/?new=1&amp;type=garden_figure&amp;method=manual">Dodaj ręcznie</a>
            </div>
            <div class="field">
              <h2>Przygotuj z Codexem</h2>
              <p>Wgraj zdjęcia, przygotuj draft i wykorzystaj Codexa do stworzenia nazw, opisów, SEO i danych zdjęć.</p>
              <a class="btn btn-secondary" href="/admin/?new=1&amp;type=garden_figure&amp;method=codex">Przygotuj z Codexem</a>
            </div>
          </div>
          <div class="form-actions"><a class="btn btn-secondary" href="/admin/?figures=1">Wróć do figur</a></div>
        </section>
      <?php elseif ($newProduct && $newSaleType === 'garden_figure' && !$imageDraft && $newFigureMethod === 'codex'): ?>
        <form method="post" enctype="multipart/form-data" class="card form-grid">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="prepare_figure_images">
          <div class="section-title">1. Przygotuj zdjęcia figury</div>
          <div class="field field-full"><label for="draft-name">Robocza nazwa figury</label><input id="draft-name" name="draft_name" required placeholder="np. smok ogrodowy szaro-czarny"><small>Serwer nie analizuje obrazów przez AI. Nazwa służy tylko do przygotowania czytelnych nazw WebP; po przygotowaniu możesz poprawić wszystkie pola.</small></div>
          <div class="field field-full upload-field"><label for="draft-images">Zdjęcia produktu</label><input id="draft-images" type="file" name="draft_images[]" accept="image/jpeg,image/png,image/webp" multiple required><small>JPG, PNG lub WebP, maks. <?= MAX_DRAFT_IMAGES ?> plików po 12 MB. Oryginały trafią do prywatnego katalogu roboczego.</small><div class="upload-preview"></div></div>
          <div class="form-actions"><button class="btn" type="submit">Przygotuj produkt</button><a class="btn btn-secondary" href="/admin/?figures=1">Anuluj</a></div>
        </form>
      <?php else: ?>
      <?php if ($imageDraft): ?>
        <section class="card form-grid">
          <div class="section-title">Analiza lokalna przez Codex</div>
          <div class="field field-full"><p>Eksport zawiera wyłącznie manifest i przygotowane WebP tego draftu. Nie zapisuje ani nie publikuje produktu.</p></div>
          <form method="post" action="/admin/draft-export.php" class="form-actions">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="draft_id" value="<?= e((string)$imageDraft['id']) ?>">
            <button class="btn btn-secondary" type="submit">Eksportuj draft dla Codexa</button>
          </form>
          <form method="post" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="import_codex_draft"><input type="hidden" name="draft_id" value="<?= e((string)$imageDraft['id']) ?>">
            <div class="field"><label for="product-draft-json">Wynik analizy Codexa</label><input id="product-draft-json" type="file" name="product_draft_json" accept="application/json,.json" required><small>Wybierz product-draft.json dla tego samego draftu.</small></div>
            <div class="form-actions"><button class="btn btn-secondary" type="submit">Importuj wynik Codexa</button></div>
          </form>
          <?php if ($codexAnalysis): ?><div class="field field-full"><small>Wczytano wynik Codexa. Zmień dowolne pole poniżej przed zapisem.</small></div><?php endif; ?>
        </section>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="card">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_product">
        <input type="hidden" name="index" value="<?= $editing ? e((string)$editIndex) : '' ?>">
        <?php if ($imageDraft): ?><input type="hidden" name="draft_id" value="<?= e((string)$imageDraft['id']) ?>"><?php endif; ?>
        <div class="form-grid">
          <div class="section-title">Podstawowe informacje</div>
          <div class="field field-full"><label for="name">Nazwa produktu</label><input id="name" name="name" required value="<?= e($product['name']) ?>"></div>
          <div class="field field-full">
            <label for="saleType">Typ produktu</label>
            <select id="saleType" name="saleType" data-sale-type>
              <option value="showroom"<?= ($product['saleType'] ?? 'showroom') === 'showroom' ? ' selected' : '' ?>>Produkt outletowy / showroom</option>
              <option value="garden_figure"<?= ($product['saleType'] ?? '') === 'garden_figure' ? ' selected' : '' ?>>Figura ogrodowa / sklep online</option>
            </select>
            <small>Tryb showroom działa jak obecnie. Tryb figury pokazuje dodatkowe pola sklepu testowego.</small>
          </div>
          <div class="field"><label for="category">Kategoria</label><select id="category" name="category"><?php foreach (product_category_options() as $option): ?><option<?= $product['category'] === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div>
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

          <div class="section-title shop-fields" data-shop-fields>Sklep online — figury ogrodowe</div>
          <div class="field shop-fields" data-shop-fields><label class="check-line"><input type="checkbox" name="shopVisible"<?= !empty($product['shopVisible']) ? ' checked' : '' ?>> Widoczny w sklepie testowym</label></div>
          <div class="field shop-fields" data-shop-fields><label for="shopStatus">Status w sklepie</label><select id="shopStatus" name="shopStatus"><?php foreach (['Dostępny','Ukryty','Wyłączony'] as $option): ?><option<?= ($product['shopStatus'] ?? 'Ukryty') === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div>
          <div class="field shop-fields" data-shop-fields><label for="sku">SKU / numer produktu</label><input id="sku" name="sku" value="<?= e($product['sku']) ?>" placeholder="np. FIG-001"></div>
          <div class="field shop-fields" data-shop-fields><label for="grossPrice">Cena brutto w sklepie</label><input id="grossPrice" name="grossPrice" value="<?= e($product['grossPrice']) ?>" placeholder="np. 249 zł"></div>
          <div class="field shop-fields" data-shop-fields><label for="producerAvailability">Dostępność</label><input id="producerAvailability" name="producerAvailability" value="<?= e($product['producerAvailability']) ?>"></div>
          <div class="field shop-fields" data-shop-fields><label for="leadTime">Czas realizacji</label><input id="leadTime" name="leadTime" value="<?= e($product['leadTime']) ?>"></div>
          <div class="field shop-fields" data-shop-fields><label for="height">Wysokość</label><input id="height" name="height" value="<?= e($product['height']) ?>" placeholder="np. 80 cm"></div>
          <div class="field shop-fields" data-shop-fields><label for="width">Szerokość</label><input id="width" name="width" value="<?= e($product['width']) ?>" placeholder="np. 35 cm"></div>
          <div class="field shop-fields" data-shop-fields><label for="depth">Głębokość</label><input id="depth" name="depth" value="<?= e($product['depth']) ?>" placeholder="np. 28 cm"></div>
          <div class="field shop-fields" data-shop-fields><label for="weight">Waga produktu</label><input id="weight" name="weight" value="<?= e($product['weight']) ?>" placeholder="np. 12 kg"></div>
          <div class="field field-full shop-fields" data-shop-fields><label for="packageDimensions">Wymiary paczki</label><input id="packageDimensions" name="packageDimensions" value="<?= e($product['packageDimensions']) ?>" placeholder="np. 90 × 45 × 45 cm"></div>
          <div class="field shop-fields" data-shop-fields><label for="packageWeight">Waga po zapakowaniu</label><input id="packageWeight" name="packageWeight" value="<?= e($product['packageWeight']) ?>" placeholder="np. 14 kg"></div>
          <div class="field shop-fields" data-shop-fields><label for="packageLengthCm">Długość paczki</label><input id="packageLengthCm" name="packageLengthCm" value="<?= e($product['packageLengthCm']) ?>" placeholder="cm"></div>
          <div class="field shop-fields" data-shop-fields><label for="packageWidthCm">Szerokość paczki</label><input id="packageWidthCm" name="packageWidthCm" value="<?= e($product['packageWidthCm']) ?>" placeholder="cm"></div>
          <div class="field shop-fields" data-shop-fields><label for="packageHeightCm">Wysokość paczki</label><input id="packageHeightCm" name="packageHeightCm" value="<?= e($product['packageHeightCm']) ?>" placeholder="cm"></div>
          <div class="field shop-fields" data-shop-fields><label class="check-line"><input type="checkbox" name="outdoorUse"<?= !empty($product['outdoorUse']) ? ' checked' : '' ?>> Produkt przeznaczony na zewnątrz</label></div>
          <div class="field shop-fields" data-shop-fields><label class="check-line"><input type="checkbox" name="fragileTransport"<?= !empty($product['fragileTransport']) ? ' checked' : '' ?>> Ciężki / kruchy / wymaga ostrożnego transportu</label></div>
          <div class="field shop-fields" data-shop-fields><label class="check-line"><input type="checkbox" name="delicateProduct"<?= !empty($product['delicateProduct']) ? ' checked' : '' ?>> Produkt delikatny</label></div>
          <div class="field shop-fields" data-shop-fields><label class="check-line"><input type="checkbox" name="handPainted"<?= !empty($product['handPainted']) ? ' checked' : '' ?>> Produkt ręcznie malowany</label></div>
          <div class="field shop-fields" data-shop-fields><label class="check-line"><input type="checkbox" name="heavyProduct"<?= !empty($product['heavyProduct']) ? ' checked' : '' ?>> Produkt ciężki</label></div>
          <div class="field shop-fields" data-shop-fields><label class="check-line"><input type="checkbox" name="oversizedProduct"<?= !empty($product['oversizedProduct']) ? ' checked' : '' ?>> Produkt gabarytowy</label></div>
          <div class="field field-full shop-fields" data-shop-fields>
            <label>Dostawa produktu</label>
            <div class="delivery-grid">
              <?php foreach ($shippingProfiles as $deliveryProfile): $deliveryKey = (string)$deliveryProfile['id']; ?>
                <label class="delivery-option">
                  <span><input type="checkbox" name="shipping_profile_ids[]" value="<?= e($deliveryKey) ?>"<?= in_array($deliveryKey, $currentShippingProfileIds, true) ? ' checked' : '' ?>> <?= e((string)$deliveryProfile['name']) ?></span>
                  <small><?= e(shipping_profile_price_label($deliveryProfile)) ?><?= empty($deliveryProfile['active']) ? ' · ukryty' : '' ?></small>
                </label>
              <?php endforeach; ?>
            </div>
            <small>Ceny pochodzą z zakładki „Cennik dostaw”. Zmiana ceny profilu automatycznie zmieni ją przy wszystkich produktach korzystających z tej metody.</small>
          </div>

          <div class="section-title">Zdjęcia z telefonu</div>
          <?php if ($imageDraft): ?>
            <div class="field field-full"><p><strong>2. Wybierz zdjęcie główne.</strong> Pozostałe zaznaczone zdjęcia trafią do galerii. Pliki są nadal prywatną wersją roboczą.</p><?php if ($codexAnalysis && $codexMainFile === ''): ?><small>Codex nie wskazał zdjęcia głównego — wybierz je ręcznie przed zapisaniem.</small><?php endif; ?>
              <div class="gallery-current">
                <?php foreach ((array)$imageDraft['images'] as $draftImageIndex => $draftImage): $prepared = (string)($draftImage['prepared'] ?? ''); if ($prepared === '') continue; $imageAnalysis = $codexImageMap[$prepared] ?? []; $isMain = $codexAnalysis ? $codexMainFile === $prepared : $draftImageIndex === 0; $isGallery = $codexAnalysis ? (($imageAnalysis['role'] ?? '') === 'gallery') : $draftImageIndex !== 0; ?>
                  <div class="gallery-item"><img src="/admin/draft-image.php?id=<?= e((string)$imageDraft['id']) ?>&amp;file=<?= rawurlencode($prepared) ?>" alt=""><label class="check-line"><input type="radio" name="draft_main" value="<?= e($prepared) ?>"<?= $isMain ? ' checked' : '' ?>> Zdjęcie główne</label><label class="check-line"><input type="checkbox" name="draft_gallery[]" value="<?= e($prepared) ?>"<?= $isGallery ? ' checked' : '' ?>> Galeria</label><small><?= e((string)($imageAnalysis['finalFilename'] ?? $prepared)) ?><?= !empty($imageAnalysis['alt']) ? ' · ALT: ' . e((string)$imageAnalysis['alt']) : '' ?></small></div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php else: ?>
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
          <?php endif; ?>
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
          <div class="field field-full"><label for="slug">Adres produktu / slug</label><input id="slug" name="slug" value="<?= e($product['slug']) ?>" placeholder="Utworzy się automatycznie z nazwy"><small>Zostaw puste, a panel sam przygotuje czytelny adres strony produktu.</small></div>

          <div class="section-title">Google Business Profile</div>
          <div class="field field-full google-helper">
            <strong>Ręczne dodanie do wizytówki Google</strong>
            <p>Google może nie pozwalać na automatyczne dodawanie produktów przez API. Ten blok przygotowuje opis do skopiowania, a po uzupełnieniu tajnej konfiguracji serwera może też wysłać zdjęcie lub utworzyć post w Google Business Profile.</p>
          </div>
          <div class="field"><label class="check-line"><input type="checkbox" name="googleManualProduct"<?= !empty($product['googleManualProduct']) ? ' checked' : '' ?>> Dodane ręcznie do Produktów Google</label></div>
          <div class="field"><label for="googleStatus">Status Google</label><select id="googleStatus" name="googleStatus"><?php foreach ($googleStatusOptions as $option): ?><option<?= ($product['googleStatus'] ?? 'Nie wysłano') === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div>
          <div class="field field-full">
            <label for="googleText">Treść do Google</label>
            <textarea id="googleText" name="googleText" rows="6"><?= e($googleTextPreview) ?></textarea>
            <small>Skopiuj ten opis do produktu lub posta w wizytówce Google. Nie obiecuje stałej dostępności produktu.</small>
            <button class="btn btn-secondary btn-small copy-button" type="button" data-copy-target="googleText">Skopiuj opis</button>
          </div>
          <div class="field"><label for="googleSentAt">Data wysłania / dodania</label><input id="googleSentAt" name="googleSentAt" value="<?= e($product['googleSentAt']) ?>" placeholder="np. 2026-06-28"></div>
          <div class="field"><label for="googleMediaId">ID zdjęcia Google</label><input id="googleMediaId" name="googleMediaId" value="<?= e($product['googleMediaId']) ?>" placeholder="na przyszłą integrację API"></div>
          <div class="field"><label for="googlePostId">ID posta Google</label><input id="googlePostId" name="googlePostId" value="<?= e($product['googlePostId']) ?>" placeholder="na przyszłą integrację API"></div>
          <div class="field field-full"><label for="googleError">Błąd API Google</label><input id="googleError" name="googleError" value="<?= e($product['googleError']) ?>" placeholder="puste, jeśli nie było błędu"></div>
          <div class="field field-full google-api-actions">
            <button class="btn btn-secondary btn-small" type="button" data-google-action="config_status">Sprawdź konfigurację API</button>
            <button class="btn btn-secondary btn-small" type="button" data-google-action="preview">Sprawdź dane do Google</button>
            <button class="btn btn-secondary btn-small" type="button" data-google-action="photo_upload">Wyślij zdjęcie do Google</button>
            <button class="btn btn-secondary btn-small" type="button" data-google-action="post_create">Utwórz post Google</button>
            <small>Jeśli tajna konfiguracja Google API nie jest ustawiona, panel pokaże tylko bezpieczny podgląd danych bez wysyłania.</small>
            <div class="google-api-result" data-google-result hidden></div>
          </div>
        </div>
        <div class="form-actions"><button class="btn" type="submit">Zapisz produkt</button><?php if ($imageDraft): ?><button class="btn btn-secondary" type="submit" formmethod="post" formaction="/admin/" name="action" value="cancel_figure_draft">Anuluj przygotowanie</button><?php else: ?><a class="btn btn-secondary" href="<?= (($product['saleType'] ?? 'showroom') === 'garden_figure') ? '/admin/?figures=1' : '/admin/' ?>">Anuluj</a><?php endif; ?></div>
      </form>
      <?php endif; ?>
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
        <div>
          <p class="muted"><?= $showFigures ? 'Sklep online · figury ogrodowe' : 'Showroom i outlet · bez koszyka' ?> · <?= count($filteredProducts) ?> produktów</p>
          <h1><?= $showFigures ? 'Figury ogrodowe' : 'Produkty outletowe' ?></h1>
        </div>
        <div class="header-actions">
          <a class="btn btn-secondary" href="/admin/?password=1">Zmień hasło</a>
          <a class="btn" href="/admin/?new=1&amp;type=<?= $showFigures ? 'garden_figure' : 'showroom' ?>"><?= $showFigures ? 'Dodaj figurę' : 'Dodaj produkt outletowy' ?></a>
        </div>
      </div>
      <form method="get" class="card" style="margin-bottom:16px">
        <?php if ($showFigures): ?><input type="hidden" name="figures" value="1"><?php endif; ?>
        <div class="admin-filter-grid">
          <input name="q" value="<?= e($search) ?>" placeholder="Szukaj produktu po nazwie">
          <select name="status">
            <option value="">Każdy status</option>
            <?php foreach (($showFigures ? ['Dostępny', 'Ukryty', 'Wyłączony'] : ['Dostępne', 'Zarezerwowane', 'Sprzedane']) as $option): ?>
              <option value="<?= e($option) ?>"<?= $statusFilter === $option ? ' selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="visibility">
            <option value="">Widoczność</option>
            <option value="visible"<?= $visibilityFilter === 'visible' ? ' selected' : '' ?>>Widoczne</option>
            <option value="hidden"<?= $visibilityFilter === 'hidden' ? ' selected' : '' ?>>Ukryte</option>
          </select>
          <?php if ($showFigures): ?>
            <select name="delivery">
              <option value="">Każda dostawa</option>
              <?php foreach ($shopDeliveryLabels as $deliveryKey => $deliveryLabel): ?>
                <option value="<?= e($deliveryKey) ?>"<?= $deliveryFilter === $deliveryKey ? ' selected' : '' ?>><?= e($deliveryLabel) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="missing">
              <option value="">Brakujące dane</option>
              <option value="price"<?= $missingFilter === 'price' ? ' selected' : '' ?>>Brak ceny</option>
              <option value="image"<?= $missingFilter === 'image' ? ' selected' : '' ?>>Brak zdjęcia</option>
              <option value="weight"<?= $missingFilter === 'weight' ? ' selected' : '' ?>>Brak wagi</option>
              <option value="delivery"<?= $missingFilter === 'delivery' ? ' selected' : '' ?>>Brak dostawy</option>
            </select>
          <?php endif; ?>
          <button class="btn" type="submit">Filtruj</button>
        </div>
      </form>
      <section class="product-list">
        <?php $shown = 0; foreach ($filteredProducts as $index => $item): $shown++; ?>
          <article class="product-row">
            <img src="<?= e(image_url((string)($item['image'] ?? ''))) ?>" alt="">
            <div><h2><?= e($item['name'] ?? 'Produkt bez nazwy') ?></h2><div class="meta"><span><?= e($item['category'] ?? 'Bez kategorii') ?></span><?= (($item['saleType'] ?? 'showroom') === 'garden_figure') ? '<span>Figura / sklep online</span>' : '<span>Outlet / showroom</span>' ?><span class="status"><?= e($item['status'] ?? 'Dostępność do potwierdzenia') ?></span><span><?= e(($item['saleType'] ?? 'showroom') === 'garden_figure' ? ($item['grossPrice'] ?? 'Cena sklepu') : ($item['outletPrice'] ?? 'Zapytaj o cenę')) ?></span><?= (($item['saleType'] ?? 'showroom') === 'garden_figure' ? (!empty($item['shopVisible']) ? '' : '<span>Ukryty w sklepie</span>') : (!empty($item['visible']) ? '' : '<span>Ukryty na stronie</span>')) ?></div></div>
            <div class="row-actions">
              <?php $itemSlug = clean_filename((string)(($item['slug'] ?? '') !== '' ? $item['slug'] : ($item['name'] ?? 'produkt'))); ?>
              <a class="btn btn-secondary btn-small" href="<?= (($item['saleType'] ?? 'showroom') === 'garden_figure') ? '/sklep/figury-ogrodowe/produkt/' . e($itemSlug) : '/produkt/' . e($itemSlug) ?>" target="_blank" rel="noopener">Podgląd</a>
              <a class="btn btn-secondary btn-small" href="/admin/?edit=<?= e((string)$index) ?>">Edytuj</a>
              <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_sold"><input type="hidden" name="return" value="<?= $showFigures ? 'figures' : 'outlet' ?>"><input type="hidden" name="index" value="<?= e((string)$index) ?>"><button class="btn <?= ($item['status'] ?? '') === 'Sprzedane' ? 'btn-restore' : 'btn-status' ?> btn-small" type="submit"><?= ($item['status'] ?? '') === 'Sprzedane' ? 'Przywróć' : 'Sprzedane' ?></button></form>
              <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="return" value="<?= $showFigures ? 'figures' : 'outlet' ?>"><input type="hidden" name="index" value="<?= e((string)$index) ?>"><button class="btn btn-danger btn-small" type="submit" data-confirm="Usunąć ten produkt? Zdjęcia pozostaną na serwerze.">Usuń</button></form>
            </div>
          </article>
        <?php endforeach; if ($shown === 0): ?><div class="card empty"><?= $showFigures ? 'Nie znaleziono figur ogrodowych w sklepie online.' : 'Nie znaleziono produktów outletowych.' ?></div><?php endif; ?>
      </section>
    <?php endif; ?>
  </main>
  <script src="/admin/app.js"></script>
</body>
</html>
