<?php
declare(strict_types=1);

require __DIR__ . '/../scripts/migrate-owner-decisions-2026-09-14.php';

function migration_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$changes = [];
$conflicts = [];
$record = ['dimensions' => 'stara wartość'];
migration_set($record, 'dimensions', 'stara wartość', 'nowa wartość', $changes, $conflicts, 'produkt');
migration_test_assert($record['dimensions'] === 'nowa wartość' && $changes === ['produkt.dimensions'] && $conflicts === [], 'Migracja nie zmienia dokładnej starej wartości.');
$changes = [];
migration_set($record, 'dimensions', 'stara wartość', 'nowa wartość', $changes, $conflicts, 'produkt');
migration_test_assert($record['dimensions'] === 'nowa wartość' && $changes === [] && $conflicts === [], 'Powtórzenie migracji nie jest idempotentne.');

$custom = ['dimensions' => 'nowsza wartość z panelu'];
$changes = [];
$conflicts = [];
migration_set($custom, 'dimensions', 'stara wartość', 'nowa wartość', $changes, $conflicts, 'produkt');
migration_test_assert($custom['dimensions'] === 'nowsza wartość z panelu' && $changes === [] && count($conflicts) === 1, 'Migracja nadpisuje późniejszą edycję zamiast zgłosić konflikt.');

$note = 'Decyzja właściciela: element nie był testowany.';
$removed = ['longDescription' => 'Opis publiczny. USUŃ DOPISEK', 'internalNote' => ''];
$changes = [];
$conflicts = [];
migration_replace($removed, 'longDescription', 'USUŃ DOPISEK', '', $changes, $conflicts, 'produkt', $note);
migration_append_note($removed, $note);
migration_append_note($removed, $note);
migration_test_assert($removed['longDescription'] === 'Opis publiczny.' && substr_count($removed['internalNote'], $note) === 1, 'Usunięty dopisek nie trafia jednokrotnie do notatki wewnętrznej.');
$changes = [];
$conflicts = [];
migration_replace($removed, 'longDescription', 'USUŃ DOPISEK', '', $changes, $conflicts, 'produkt', $note);
migration_test_assert($changes === [] && $conflicts === [], 'Usunięcie dopisku nie jest idempotentne.');

[$shipping, $shippingChanges, $shippingConflicts] = migration_apply_shipping(['profiles' => [[
    'id' => 'paleta',
    'price' => 250,
    'requiresConfirmation' => false,
    'priceFrom' => true,
]]]);
migration_test_assert($shipping['profiles'][0]['priceFrom'] === false && $shippingChanges === ['paleta.priceFrom'] && $shippingConflicts === [], 'Migracja palety nie usuwa „od” z potwierdzonej konfiguracji produkcyjnej.');
[$shippingAgain, $shippingChangesAgain, $shippingConflictsAgain] = migration_apply_shipping($shipping);
migration_test_assert($shippingAgain === $shipping && $shippingChangesAgain === [] && $shippingConflictsAgain === [], 'Migracja palety nie jest idempotentna.');
[$customShipping, $customShippingChanges, $customShippingConflicts] = migration_apply_shipping(['profiles' => [[
    'id' => 'paleta',
    'price' => 275,
    'requiresConfirmation' => false,
    'priceFrom' => true,
]]]);
migration_test_assert($customShipping['profiles'][0]['price'] === 275 && $customShippingChanges === [] && count($customShippingConflicts) === 1, 'Migracja palety nadpisuje nowszą stawkę zamiast zgłosić konflikt.');

$source = (string)file_get_contents(__DIR__ . '/../scripts/migrate-owner-decisions-2026-09-14.php');
foreach ([
    'stol-rozkladany-avis-czarny',
    'zestaw-4-krzesel-magalia-szary-welur',
    'zestaw-2-lamp-sciennych-lorenta-czarno-zlote',
    'zestaw-2-krzesel-piseco-ciemnozielone',
    'szafka-lazienkowa-rosell-40-cm',
    'hamak-ogrodowy-treviso-drewniany-bezowy',
    'wanna-hawes-hydromasaz-led-czarna',
    'wentylator-sufitowy-zarqa-oswietlenie-led',
] as $slug) {
    migration_test_assert(str_contains($source, $slug), "Migracja nie wskazuje dokładnego rekordu {$slug}.");
}
migration_test_assert(str_contains($source, 'TRYB: PODGLĄD (bez zapisu)') && str_contains($source, "array_key_exists('write'") && str_contains($source, "['backup-dir']"), 'Migracja nie ma domyślnego dry-run, jawnego zapisu lub obowiązkowej kopii.');

$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hgo-migration-test-' . bin2hex(random_bytes(5));
$dataDir = $tempRoot . DIRECTORY_SEPARATOR . 'data';
$backupDir = $tempRoot . DIRECTORY_SEPARATOR . 'private-backups';
mkdir($dataDir, 0700, true);
$input = $dataDir . DIRECTORY_SEPARATOR . 'products.json';
file_put_contents($input, "{\"products\":[]}\n");
$backups = migration_write_files([$input => ['products' => [['slug' => 'po-zmianie']]]], $backupDir);
$written = json_decode((string)file_get_contents($input), true);
$backup = $backups[$input] ?? '';
migration_test_assert(($written['products'][0]['slug'] ?? '') === 'po-zmianie' && is_file($backup) && str_contains((string)file_get_contents($backup), '"products":[]'), 'Zapis testowy nie tworzy kopii aktualnych danych przed podmianą.');
@unlink($input);
@unlink($backup);
@rmdir($dataDir);
@rmdir($backupDir);
@rmdir($tempRoot);

echo "PASS: owner decisions migration tests\n";
