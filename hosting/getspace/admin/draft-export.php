<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
boot_admin();
require_login();
require_csrf();

$id = product_image_draft_id(post_text('draft_id'));
$draft = $id === '' ? null : load_product_image_draft($id);
if (!$draft) {
    http_response_code(404);
    exit('Nie znaleziono wskazanego draftu.');
}
if (!class_exists('ZipArchive')) {
    http_response_code(503);
    exit('Hosting nie ma włączonego rozszerzenia PHP ZipArchive potrzebnego do eksportu draftu.');
}

$temporary = tempnam(sys_get_temp_dir(), 'hgo-draft-');
if ($temporary === false) {
    http_response_code(500);
    exit('Nie udało się przygotować eksportu draftu.');
}
register_shutdown_function(static function () use ($temporary): void { @unlink($temporary); });

$zip = new ZipArchive();
if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Nie udało się utworzyć archiwum ZIP.');
}
$metadata = json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($metadata === false || !$zip->addFromString('draft.json', $metadata . PHP_EOL)) {
    $zip->close();
    http_response_code(500);
    exit('Nie udało się dodać manifestu draftu do archiwum.');
}
$path = product_image_draft_path($id);
foreach ((array)($draft['images'] ?? []) as $image) {
    $file = is_array($image) ? (string)($image['prepared'] ?? '') : '';
    if ($file === '' || basename($file) !== $file || substr($file, -5) !== '.webp' || !is_file($path . '/' . $file)) {
        $zip->close();
        http_response_code(500);
        exit('Draft zawiera brakujące lub nieprawidłowe przygotowane zdjęcie.');
    }
    if (!$zip->addFile($path . '/' . $file, $file)) {
        $zip->close();
        http_response_code(500);
        exit('Nie udało się dodać zdjęcia do archiwum.');
    }
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Length: ' . (string)filesize($temporary));
header('Content-Disposition: attachment; filename="figura-draft-' . $id . '.zip"');
header('X-Content-Type-Options: nosniff');
readfile($temporary);
