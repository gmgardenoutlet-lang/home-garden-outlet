<?php
declare(strict_types=1);

/**
 * Private, command-line-only migration for the owner decisions confirmed on
 * 2026-09-14. It is intentionally outside the deployed web root.
 *
 * Dry run (default):
 *   php scripts/migrate-owner-decisions-2026-09-14.php \
 *     --products=/private/current-products.json \
 *     --shipping=/private/current-shipping-profiles.json
 *
 * Future write (never run from the web root):
 *   php scripts/migrate-owner-decisions-2026-09-14.php ... --write \
 *     --backup-dir=/private/backups
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function migration_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit($code);
}

function migration_load(string $path, string $collection): array
{
    if (!is_file($path) || !is_readable($path)) {
        migration_fail("Nie można odczytać pliku: {$path}");
    }
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data) || !isset($data[$collection]) || !is_array($data[$collection])) {
        migration_fail("Plik {$path} nie zawiera tablicy {$collection}.");
    }
    return $data;
}

function migration_append_note(array &$product, string $note): void
{
    $current = trim((string)($product['internalNote'] ?? ''));
    if ($note === '' || ($current !== '' && str_contains($current, $note))) {
        return;
    }
    $product['internalNote'] = $current === '' ? $note : $current . "\n\n" . $note;
}

function migration_set(array &$record, string $field, string $old, string $new, array &$changes, array &$conflicts, string $label): void
{
    $current = (string)($record[$field] ?? '');
    if ($current === $new) {
        return;
    }
    if ($current !== $old) {
        $conflicts[] = "{$label}.{$field}: wartość nie jest ani oczekiwaną starą, ani docelową";
        return;
    }
    $record[$field] = $new;
    $changes[] = "{$label}.{$field}";
}

function migration_replace(array &$record, string $field, string $old, string $new, array &$changes, array &$conflicts, string $label, string $idempotenceNote = ''): void
{
    $current = (string)($record[$field] ?? '');
    if (str_contains($current, $old)) {
        $record[$field] = trim(str_replace($old, $new, $current));
        $changes[] = "{$label}.{$field}";
        return;
    }
    if ($new !== '' && str_contains($current, $new)) {
        return;
    }
    if ($new === '' && $idempotenceNote !== '' && str_contains((string)($record['internalNote'] ?? ''), $idempotenceNote)) {
        return;
    }
    $conflicts[] = "{$label}.{$field}: nie znaleziono dokładnej starej treści; możliwa nowsza edycja";
}

function migration_product_index(array $products): array
{
    $index = [];
    foreach ($products as $position => $product) {
        if (!is_array($product)) {
            continue;
        }
        $slug = (string)($product['slug'] ?? '');
        if ($slug !== '') {
            $index[$slug][] = $position;
        }
    }
    return $index;
}

function migration_apply_products(array $data): array
{
    $changes = [];
    $conflicts = [];
    $index = migration_product_index($data['products']);

    $mutate = static function (string $slug, callable $callback) use (&$data, &$index, &$changes, &$conflicts): void {
        $positions = $index[$slug] ?? [];
        if (count($positions) !== 1) {
            $conflicts[] = "{$slug}: oczekiwano dokładnie jednego rekordu, znaleziono " . count($positions);
            return;
        }
        $position = $positions[0];
        $candidate = $data['products'][$position];
        $localChanges = [];
        $localConflicts = [];
        $callback($candidate, $localChanges, $localConflicts, $slug);
        if ($localConflicts) {
            array_push($conflicts, ...$localConflicts);
            return;
        }
        $data['products'][$position] = $candidate;
        array_push($changes, ...$localChanges);
    };

    $mutate('stol-rozkladany-avis-czarny', static function (array &$p, array &$c, array &$x, string $label): void {
        migration_replace($p, 'longDescription', 'Model ma funkcję rozkładania — długość blatu można zwiększyć ze 140 cm do 190 cm.', 'Model ma funkcję rozkładania — długość blatu można zwiększyć ze 160 cm do 210 cm.', $c, $x, $label);
        migration_set($p, 'dimensions', 'Szerokość: 90 cm Głębokość / długość: 140 / 190 cm Wysokość: 76 cm Wysokość nóżek: 74 cm', 'Szerokość: 90 cm Głębokość / długość: 160 / 210 cm Wysokość: 76 cm Wysokość nóżek: 74 cm', $c, $x, $label);
        migration_set($p, 'imageAlt', 'Czarny stół do jadalni rozkładany AVIS 140/190 × 90 cm z metalowymi nogami, dostępny w Home & Garden Outlet pod Wrocławiem.', 'Czarny stół do jadalni rozkładany AVIS 160/210 × 90 cm z metalowymi nogami, dostępny w Home & Garden Outlet pod Wrocławiem.', $c, $x, $label);
        migration_set($p, 'seoDescription', 'Czarny stół rozkładany AVIS 140/190 × 90 cm z blatem MDF i stalowymi nogami. Home & Garden Outlet pod Wrocławiem.', 'Czarny stół rozkładany AVIS 160/210 × 90 cm z blatem MDF i stalowymi nogami. Home & Garden Outlet pod Wrocławiem.', $c, $x, $label);
    });

    $mutate('zestaw-4-krzesel-magalia-szary-welur', static function (array &$p, array &$c, array &$x, string $label): void {
        migration_set($p, 'name', 'Zestaw 2 krzeseł do jadalni MAGALIA Welur Jasnobeżowy', 'Zestaw 4 krzeseł do jadalni MAGALIA — szary welur', $c, $x, $label);
    });

    $mutate('zestaw-2-lamp-sciennych-lorenta-czarno-zlote', static function (array &$p, array &$c, array &$x, string $label): void {
        migration_set($p, 'dimensions', 'wysokość 12 szerokość 11 długość 12 głębokość 12 wymiary 12x11x12', '12 × 11 × 12', $c, $x, $label);
    });

    $mutate('zestaw-2-krzesel-piseco-ciemnozielone', static function (array &$p, array &$c, array &$x, string $label): void {
        migration_set($p, 'dimensions', 'Szerokość: 48 cm Głębokość: Do weryfikacji — na stronie Ceneo występują niejednoznaczne dane dotyczące głębokości Wysokość: 97 cm Powierzchnia siedziska: 48 x 42 cm Wysokość siedziska: 49 cm Grubość tapicerki: 13 — jednostka nie została jednoznacznie podana w danych Ceneo Waga: 7 kg Maksymalne obciążenie: 180 kg według danych Ceneo', 'Szerokość: 48 cm Głębokość całkowita: 50 cm Wysokość: 97 cm Powierzchnia siedziska: 48 x 42 cm Głębokość siedziska: 42 cm Wysokość siedziska: 49 cm Waga: 7 kg Maksymalne obciążenie: 180 kg według danych Ceneo', $c, $x, $label);
    });

    $mutate('szafka-lazienkowa-rosell-40-cm', static function (array &$p, array &$c, array &$x, string $label): void {
        migration_set($p, 'material', 'Płyta pilśniowa, rattan, żelazo  Uwaga: w szczegółowej specyfikacji Beliani materiał główny podano jako „płyta pilśniowa”, natomiast w opisie produktu użyto określenia „płyta wiórowa”.', 'Płyta pilśniowa, rattan, żelazo', $c, $x, $label);
    });

    $mutate('hamak-ogrodowy-treviso-drewniany-bezowy', static function (array &$p, array &$c, array &$x, string $label): void {
        $old = 'Hamak ogrodowy TREVISO to wolnostojący model ze stabilnym drewnianym stelażem, dzięki któremu nie wymaga mocowania do drzew ani słupów. Zakrzywiona konstrukcja z drewna modrzewiowego oraz beżowa powierzchnia do leżenia tworzą wygodne miejsce do odpoczynku w ogrodzie lub na tarasie.  Powierzchnia hamaka wykonana jest z bawełny i według danych producenta może być zdejmowana oraz prana. Model przeznaczony jest do użytkowania na zewnątrz, jednak dla zachowania dobrego stanu producent zaleca zabezpieczanie go przed intensywnymi opadami i przechowywanie pod przykryciem, gdy nie jest używany.  Hamak wyposażony jest w drewniane rozpórki, liny oraz metalowy system mocowania do stelaża. Maksymalne dopuszczalne obciążenie wynosi 150 kg.  Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Na drewnianej konstrukcji widoczne są drobne ślady użytkowania i ekspozycji. Na podstawie przesłanych zdjęć nie widać istotnych uszkodzeń konstrukcyjnych.  Dodatkowe informacje: - model: TREVISO - typ: hamak ogrodowy ze stelażem - kolor: beżowy / brązowy - odcień tkaniny: złamana biel - materiał stelaża: drewno modrzewiowe - materiał powierzchni do leżenia: 100% bawełna - maksymalne obciążenie: 150 kg - zdejmowany materiał: tak - materiał nadający się do prania: tak - odporność tkaniny na promieniowanie słoneczne: klasa 4 według ISO 105-B05 - montaż według źródła: wymaga kompletnego montażu - waga produktu: 43 kg - stan: outletowy / ekspozycyjny';
        migration_set($p, 'dimensions', $old, 'Szerokość: 415 cm Głębokość: 124 cm Wysokość: 122 cm Wysokość siedziska: 57 cm', $c, $x, $label);
    });

    $hawesNote = 'Decyzja właściciela: hydromasaż i LED są nowe; ich działanie nie było testowane.';
    $mutate('wanna-hawes-hydromasaz-led-czarna', static function (array &$p, array &$c, array &$x, string $label) use ($hawesNote): void {
        $changeCount = count($c);
        migration_replace($p, 'longDescription', 'Przed sprzedażą należy potwierdzić działanie hydromasażu, LED, panelu sterowania, baterii, odpływu oraz szczelność instalacji. Jeżeli wanna nie była testowana z wodą, warto oznaczyć ją jako technicznie niesprawdzoną.', 'Elementy hydromasażu i oświetlenia LED są nowe.', $c, $x, $label);
        if (!$x && count($c) > $changeCount) {
            migration_append_note($p, $hawesNote);
        } elseif (!$x && !str_contains((string)($p['internalNote'] ?? ''), $hawesNote)) {
            $x[] = "{$label}.internalNote: publiczna treść jest docelowa, ale brakuje potwierdzonej notatki wewnętrznej";
        }
    });

    $zarqaNote = 'Decyzja właściciela: produkt jest nowy; działanie światła i wentylatora nie było testowane.';
    $mutate('wentylator-sufitowy-zarqa-oswietlenie-led', static function (array &$p, array &$c, array &$x, string $label) use ($zarqaNote): void {
        migration_replace($p, 'longDescription', 'Model przeznaczony jest do montażu sufitowego. Zgodnie z opisem źródłowym montaż powinien zostać wykonany przez wykwalifikowanego elektryka. Na zdjęciach widoczne są przewody montażowe, dlatego przed sprzedażą warto sprawdzić stan instalacji, kompletność zestawu oraz działanie oświetlenia i wentylatora.', 'Model przeznaczony jest do montażu sufitowego. Zgodnie z opisem źródłowym montaż powinien zostać wykonany przez wykwalifikowanego elektryka.', $c, $x, $label);
        migration_replace($p, 'longDescription', 'Produkt outletowy. Nie widać jednoznacznych dużych uszkodzeń na zdjęciach, ale przed zakupem zalecamy obejrzenie lampy na miejscu, szczególnie pod kątem kompletności elementów dekoracyjnych, łopatek, przewodów i pilota.', 'Oferowany egzemplarz jest nowy.', $c, $x, $label);
        migration_replace($p, 'longDescription', '- obecność pilota w komplecie: do potwierdzenia', '', $c, $x, $label, $zarqaNote);
        if (!$x) {
            migration_append_note($p, $zarqaNote);
        }
    });

    return [$data, $changes, $conflicts];
}

function migration_apply_shipping(array $data): array
{
    $changes = [];
    $conflicts = [];
    $positions = [];
    foreach ($data['profiles'] as $position => $profile) {
        if (is_array($profile) && (string)($profile['id'] ?? '') === 'paleta') {
            $positions[] = $position;
        }
    }
    if (count($positions) !== 1) {
        return [$data, [], ['paleta: oczekiwano dokładnie jednego profilu, znaleziono ' . count($positions)]];
    }
    $position = $positions[0];
    $profile = $data['profiles'][$position];
    $price = is_numeric($profile['price'] ?? null) ? round((float)$profile['price'], 2) : null;
    $requires = !empty($profile['requiresConfirmation']);
    $from = !empty($profile['priceFrom']);
    if ($price === 250.0 && $requires === false && $from === false) {
        return [$data, [], []];
    }
    if ($price !== 250.0 || $requires !== false || $from !== true) {
        return [$data, [], ['paleta: oczekiwano price=250, requiresConfirmation=false, priceFrom=true; możliwa nowsza edycja']];
    }
    $data['profiles'][$position]['priceFrom'] = false;
    $changes[] = 'paleta.priceFrom';
    return [$data, $changes, $conflicts];
}

function migration_path_inside(string $child, string $parent): bool
{
    $child = rtrim(str_replace('\\', '/', $child), '/');
    $parent = rtrim(str_replace('\\', '/', $parent), '/');
    return $child === $parent || str_starts_with($child . '/', $parent . '/');
}

function migration_write_files(array $files, string $backupDir): array
{
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        migration_fail("Nie można utworzyć katalogu kopii: {$backupDir}");
    }
    $backupReal = realpath($backupDir);
    if ($backupReal === false) {
        migration_fail('Nie można rozpoznać katalogu kopii.');
    }
    foreach (array_keys($files) as $path) {
        $inputDir = realpath(dirname($path));
        if ($inputDir !== false && migration_path_inside($backupReal, $inputDir)) {
            migration_fail('Katalog kopii musi znajdować się poza katalogiem danych/publicznym webrootem.');
        }
    }

    $stamp = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $backups = [];
    $temps = [];
    foreach ($files as $path => $data) {
        $backup = $backupReal . DIRECTORY_SEPARATOR . basename($path) . ".{$stamp}.bak";
        if (!copy($path, $backup)) {
            migration_fail("Nie udało się utworzyć kopii {$backup}.");
        }
        $backups[$path] = $backup;
        $temp = $path . ".migration-{$stamp}.tmp";
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || file_put_contents($temp, $json . "\n", LOCK_EX) === false) {
            migration_fail("Nie udało się przygotować pliku tymczasowego dla {$path}.");
        }
        $temps[$path] = $temp;
    }

    $written = [];
    try {
        foreach ($temps as $path => $temp) {
            if (!rename($temp, $path)) {
                throw new RuntimeException("Nie udało się podmienić {$path}.");
            }
            $written[] = $path;
        }
    } catch (Throwable $exception) {
        foreach ($written as $path) {
            @copy($backups[$path], $path);
        }
        foreach ($temps as $temp) {
            @unlink($temp);
        }
        migration_fail($exception->getMessage() . ' Przywrócono wcześniej zapisane pliki z kopii.');
    }
    return $backups;
}

function migration_main(array $options): int
{
    $productsPath = (string)($options['products'] ?? '');
    $shippingPath = (string)($options['shipping'] ?? '');
    $write = array_key_exists('write', $options);
    if ($productsPath === '' || $shippingPath === '') {
        migration_fail('Wymagane są --products=PATH oraz --shipping=PATH. Domyślny tryb to dry-run.');
    }

    $products = migration_load($productsPath, 'products');
    $shipping = migration_load($shippingPath, 'profiles');
    [$nextProducts, $productChanges, $productConflicts] = migration_apply_products($products);
    [$nextShipping, $shippingChanges, $shippingConflicts] = migration_apply_shipping($shipping);
    $changes = array_merge($productChanges, $shippingChanges);
    $conflicts = array_merge($productConflicts, $shippingConflicts);

    echo ($write ? "TRYB: ZAPIS\n" : "TRYB: PODGLĄD (bez zapisu)\n");
    echo 'Zmiany: ' . count($changes) . "\n";
    foreach ($changes as $change) {
        echo "  + {$change}\n";
    }
    echo 'Konflikty: ' . count($conflicts) . "\n";
    foreach ($conflicts as $conflict) {
        echo "  ! {$conflict}\n";
    }

    if ($conflicts) {
        migration_fail('Wykryto konflikt; nic nie zapisano.', 2);
    }
    if (!$write) {
        return 0;
    }
    $backupDir = (string)($options['backup-dir'] ?? '');
    if ($backupDir === '') {
        migration_fail('Tryb --write wymaga jawnego --backup-dir poza katalogiem publicznym.');
    }
    $files = [];
    if ($productChanges) {
        $files[$productsPath] = $nextProducts;
    }
    if ($shippingChanges) {
        $files[$shippingPath] = $nextShipping;
    }
    if (!$files) {
        echo "Brak zmian do zapisania.\n";
        return 0;
    }
    $backups = migration_write_files($files, $backupDir);
    foreach ($backups as $path => $backup) {
        echo "Kopia przed zapisem {$path}: {$backup}\n";
    }
    echo "Zapis zakończony.\n";
    return 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(migration_main(getopt('', ['products:', 'shipping:', 'write', 'backup-dir:'])));
}
