<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hgo-sitemap-' . bin2hex(random_bytes(8));
$dataDirectory = $directory . DIRECTORY_SEPARATOR . 'data';
if (!mkdir($dataDirectory, 0700, true)) {
    throw new RuntimeException('Nie można utworzyć katalogu testowego.');
}

function sitemap_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function sitemap_test_run(string $script): array
{
    $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Nie można uruchomić generatora sitemap.');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    sitemap_test_assert(proc_close($process) === 0, 'Generator sitemap zakończył się błędem: ' . $error);

    $document = new DOMDocument();
    sitemap_test_assert($document->loadXML((string)$output), 'Sitemap nie jest poprawnym XML.');
    sitemap_test_assert($document->documentElement?->localName === 'urlset', 'Brak elementu urlset.');
    $result = [];
    foreach ($document->getElementsByTagName('url') as $url) {
        $locations = $url->getElementsByTagName('loc');
        sitemap_test_assert($locations->length === 1, 'Adres w sitemap nie ma dokładnie jednego loc.');
        $result[$locations->item(0)->textContent] = $url->getElementsByTagName('lastmod')->item(0)?->textContent;
    }
    return $result;
}

try {
    copy($root . '/hosting/getspace/catalog.php', $directory . '/catalog.php');
    copy($root . '/hosting/getspace/sitemap.php', $directory . '/sitemap.php');
    $products = [
        ['name' => 'Fotel', 'slug' => 'fotel-testowy', 'category' => 'Dom', 'description' => 'Pierwszy opis'],
        ['name' => 'Ławka', 'slug' => 'lawka-testowa', 'category' => 'Ogród', 'description' => 'Drugi opis'],
        ['name' => 'Ukryty', 'slug' => 'ukryty-testowy', 'visible' => false],
        ['name' => 'Figura', 'slug' => 'figura-testowa', 'saleType' => 'garden_figure', 'shopVisible' => true, 'shopStatus' => 'Dostępny'],
        ['name' => 'Figura nieaktywna', 'slug' => 'figura-nieaktywna', 'saleType' => 'garden_figure', 'shopVisible' => false],
    ];
    $productsFile = $dataDirectory . '/products.json';
    file_put_contents($productsFile, json_encode(['products' => $products], JSON_THROW_ON_ERROR));
    $before = sitemap_test_run($directory . '/sitemap.php');

    $products[0]['description'] = 'Zmieniona treść tylko jednego produktu';
    file_put_contents($productsFile, json_encode(['products' => $products], JSON_THROW_ON_ERROR));
    touch($productsFile, time() + 86400);
    $after = sitemap_test_run($directory . '/sitemap.php');

    sitemap_test_assert(array_keys($before) === array_keys($after), 'Zmiana treści produktu zmieniła zestaw adresów.');
    sitemap_test_assert($before === $after, 'Zmiana produktu lub mtime katalogu zmieniła lastmod innych adresów.');
    foreach ($after as $lastmod) {
        sitemap_test_assert($lastmod === null, 'Bez wiarygodnej daty lastmod musi być pominięty.');
    }
    foreach (['/', '/dom', '/ogrod', '/sklep/figury-ogrodowe', '/produkt/fotel-testowy', '/produkt/lawka-testowa', '/sklep/figury-ogrodowe/produkt/figura-testowa'] as $path) {
        sitemap_test_assert(array_key_exists('https://mgoutlet.pl' . $path, $after), 'Brakuje adresu ' . $path);
    }
    sitemap_test_assert(!array_key_exists('https://mgoutlet.pl/produkt/ukryty-testowy', $after), 'Ukryty produkt trafił do sitemap.');
    sitemap_test_assert(!array_key_exists('https://mgoutlet.pl/sklep/figury-ogrodowe/produkt/figura-nieaktywna', $after), 'Nieaktywna figura trafiła do sitemap.');
    echo 'PASS: sitemap XML, unchanged URLs, independent and omitted lastmod' . PHP_EOL;
} finally {
    foreach ([$productsFile ?? '', $directory . '/sitemap.php', $directory . '/catalog.php'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($dataDirectory);
    rmdir($directory);
}
