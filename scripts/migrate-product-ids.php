<?php
declare(strict_types=1);
require __DIR__ . '/../hosting/getspace/admin/product-identity.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
try {
    $options = getopt('', ['products:', 'apply', 'expect-sha256:', 'backup-dir:', 'maintenance-confirmed']);
    $file = realpath((string)($options['products'] ?? ''));
    if (!$file || !is_file($file)) throw new RuntimeException('Podaj --products z pełną ścieżką do katalogu.');
    $original = file_get_contents($file);
    $catalog = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
    $summary = product_ids_summary($catalog);
    $hash = hash('sha256', $original);
    $changed = count(array_filter($catalog['products'], static fn(array $p): bool => ($p['productId'] ?? '') === ''));
    echo "Liczba produktów: " . count($catalog['products']) . "; brak ID: $changed\nSHA256: $hash\n";
    echo 'Duplikaty productId: ' . $summary['duplicates'] . '; nieprawidłowe ID: ' . $summary['invalid'] . "\n";
    $prepared = product_ids_prepare($catalog);
    if (!isset($options['apply'])) { echo "PODGLĄD: nie zapisano zmian. ID zostaną wygenerowane przy zastosowaniu migracji.\n"; exit; }
    if (!isset($options['maintenance-confirmed']) || !hash_equals($hash, (string)($options['expect-sha256'] ?? ''))) {
        throw new RuntimeException('Zatrzymaj zapisy panelu i zamówień, potwierdź --maintenance-confirmed oraz SHA256 z podglądu.');
    }
    if ($changed === 0) { echo "Brak zmian.\n"; exit; }
    $backupDir = realpath((string)($options['backup-dir'] ?? ''));
    if (!$backupDir || !is_dir($backupDir) || !is_writable($backupDir)) throw new RuntimeException('Wymagany prywatny, zapisywalny --backup-dir.');
    $webroot = realpath(dirname($file, 2));
    $normalizedBackup = strtolower(str_replace('\\', '/', $backupDir));
    $normalizedRoot = strtolower(str_replace('\\', '/', $webroot));
    if ($normalizedBackup === $normalizedRoot || str_starts_with($normalizedBackup, $normalizedRoot . '/')) {
        throw new RuntimeException('Kopia musi znajdować się poza webrootem.');
    }
    $lock = fopen($file . '.identity.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Nie udało się zablokować migracji.');
    $temp = null;
    try {
        if (!hash_equals($hash, hash_file('sha256', $file))) throw new RuntimeException('Katalog zmienił się; przerwano.');
        $backup = $backupDir . '/products-before-ids-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
        $oldMask = umask(0077);
        try { $out = fopen($backup, 'x'); } finally { umask($oldMask); }
        if (!$out) throw new RuntimeException('Nie udało się utworzyć kopii.');
        try { if (fwrite($out, $original) !== strlen($original) || !fflush($out)) throw new RuntimeException('Niepełna kopia.'); }
        finally { fclose($out); }
        if (!hash_equals($hash, hash_file('sha256', $backup))) throw new RuntimeException('Kopia niezgodna.');
        $json = json_encode($prepared, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $temp = tempnam(dirname($file), '.product-ids-');
        if ($temp === false || file_put_contents($temp, $json) !== strlen($json)) throw new RuntimeException('Niepełny zapis migracji.');
        if (!hash_equals($hash, hash_file('sha256', $file))) throw new RuntimeException('Konflikt zapisu; przerwano.');
        if (!chmod($temp, fileperms($file) & 0777) || !rename($temp, $file)) throw new RuntimeException('Nie udało się zastosować migracji.');
        $savedBytes = file_get_contents($file);
        $saved = json_decode($savedBytes, true, 512, JSON_THROW_ON_ERROR);
        $after = product_ids_summary($saved);
        $withoutIds = $saved;
        foreach ($withoutIds['products'] as $index => &$product) {
            if (!array_key_exists('productId', $catalog['products'][$index])) unset($product['productId']);
            else $product['productId'] = $catalog['products'][$index]['productId'];
        }
        unset($product);
        if ($withoutIds !== $catalog || $after['products'] !== $summary['products']
            || $after['missing'] || $after['duplicates'] || $after['invalid'] || $saved !== $prepared) {
            throw new RuntimeException('Weryfikacja po migracji nie powiodła się. Zatrzymaj zapisy i sprawdź kopię: ' . $backup);
        }
        echo "Zapisano ID. Kopia: $backup\n";
        echo 'Po migracji: ' . json_encode($after, JSON_THROW_ON_ERROR) . "\nSHA256 po: " . hash('sha256', $savedBytes) . "\nDane poza productId: BEZ ZMIAN\n";
    } finally {
        if (is_string($temp) && is_file($temp)) unlink($temp);
        flock($lock, LOCK_UN); fclose($lock);
    }
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
