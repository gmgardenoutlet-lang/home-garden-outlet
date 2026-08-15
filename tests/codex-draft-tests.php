<?php
declare(strict_types=1);

require __DIR__ . '/../hosting/getspace/admin/lib.php';

function codex_draft_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function codex_draft_rejects(string $json, array $draft): bool
{
    try {
        validate_codex_product_draft($json, $draft);
        return false;
    } catch (Throwable $exception) {
        return true;
    }
}

$id = str_repeat('a', 32);
$draft = [
    'id' => $id,
    'images' => [
        ['prepared' => 'figura.webp'],
        ['prepared' => 'figura-ujecie-2.webp'],
    ],
];
$valid = [
    'draftId' => $id,
    'status' => 'codex_prepared',
    'product' => [
        'name' => 'Figura testowa', 'saleType' => 'garden_figure', 'category' => 'Wyposażenie ogrodu',
        'productType' => 'figura ogrodowa', 'imageAlt' => 'Figura testowa z przodu',
        'description' => 'Krótki opis.', 'longDescription' => 'Dłuższy opis.', 'color' => 'szary',
        'seoTitle' => 'Figura testowa', 'seoDescription' => 'Opis SEO.', 'slug' => 'figura-testowa',
        'nieznanePole' => 'zignoruj',
    ],
    'images' => [
        ['draftFile' => 'figura.webp', 'role' => 'main', 'finalFilename' => 'figura-testowa.webp', 'alt' => 'Figura testowa z przodu', 'view' => 'front', 'confidence' => 'high'],
        ['draftFile' => 'figura-ujecie-2.webp', 'role' => 'gallery', 'finalFilename' => 'figura-testowa-widok-z-boku.webp', 'alt' => 'Figura testowa z boku', 'view' => 'side', 'confidence' => 'medium'],
    ],
    'manualFieldsRequired' => ['grossPrice', 'material', 'nieznanePole'],
    'unknownTopLevel' => true,
];
$result = validate_codex_product_draft(json_encode($valid, JSON_UNESCAPED_UNICODE), $draft);
codex_draft_assert(($result['product']['name'] ?? '') === 'Figura testowa', 'Poprawny wynik Codexa nie został odczytany.');
codex_draft_assert($result['manualFieldsRequired'] === ['grossPrice', 'material'], 'Nieznane pola wymagane nie zostały odrzucone.');
codex_draft_assert(count($result['images']) === 2, 'Poprawne zdjęcia nie zostały zachowane.');

$otherId = $valid; $otherId['draftId'] = str_repeat('b', 32);
codex_draft_assert(codex_draft_rejects(json_encode($otherId), $draft), 'Inny draftId powinien zostać odrzucony.');
$missingId = $valid; unset($missingId['draftId']);
codex_draft_assert(codex_draft_rejects(json_encode($missingId), $draft), 'Brak draftId powinien zostać odrzucony.');
$missingImage = $valid; $missingImage['images'][0]['draftFile'] = 'brak.webp';
codex_draft_assert(codex_draft_rejects(json_encode($missingImage), $draft), 'Brakujące zdjęcie powinno zostać odrzucone.');
$path = $valid; $path['images'][0]['draftFile'] = '../uploads/other.webp';
codex_draft_assert(codex_draft_rejects(json_encode($path), $draft), 'Traversal w draftFile powinien zostać odrzucony.');
$filename = $valid; $filename['images'][0]['finalFilename'] = '../other.webp';
codex_draft_assert(codex_draft_rejects(json_encode($filename), $draft), 'Niedozwolona finalFilename powinna zostać odrzucona.');
$twoMain = $valid; $twoMain['images'][1]['role'] = 'main';
codex_draft_assert(codex_draft_rejects(json_encode($twoMain), $draft), 'Dwa zdjęcia główne powinny zostać odrzucone.');
$long = $valid; $long['product']['name'] = str_repeat('a', 181);
codex_draft_assert(codex_draft_rejects(json_encode($long), $draft), 'Zbyt długi tekst powinien zostać odrzucony.');
codex_draft_assert(codex_draft_rejects('{', $draft), 'Błędny JSON powinien zostać odrzucony.');
codex_draft_assert(codex_draft_rejects(str_repeat(' ', MAX_PRODUCT_DRAFT_JSON_BYTES + 1), $draft), 'Zbyt duży JSON powinien zostać odrzucony.');

echo "PASS: codex draft validation tests\n";
