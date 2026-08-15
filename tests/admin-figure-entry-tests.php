<?php
declare(strict_types=1);

function figure_entry_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$source = (string)file_get_contents(__DIR__ . '/../hosting/getspace/admin/index.php');

figure_entry_assert(str_contains($source, "['manual', 'codex']"), 'Brakuje rozróżnienia ręcznej i Codexowej metody dodania.');
figure_entry_assert(str_contains($source, 'Wybierz sposób dodania figury'), 'Brakuje ekranu wyboru metody.');
figure_entry_assert(str_contains($source, 'method=manual'), 'Brakuje wejścia do ręcznego formularza.');
figure_entry_assert(str_contains($source, 'method=codex'), 'Brakuje wejścia do workflow Codexa.');
figure_entry_assert(str_contains($source, "\$newFigureMethod === 'codex'"), 'Workflow Codexa nie jest ograniczony do wybranej metody.');
figure_entry_assert(str_contains($source, 'name="action" value="save_product"') || str_contains($source, 'name="action" value="save_product"'), 'Brakuje istniejącego zapisu produktu.');
figure_entry_assert(str_contains($source, 'prepare_figure_images'), 'Brakuje istniejącego przygotowania draftu Codexa.');

echo "PASS: figure entry UX tests\n";
