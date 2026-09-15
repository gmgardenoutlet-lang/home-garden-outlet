<?php
declare(strict_types=1);

require __DIR__ . '/../hosting/getspace/catalog.php';

function catalog_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function catalog_test_occurrences(string $text, string $needle): int
{
    return substr_count($text, $needle);
}

catalog_test_assert(!catalog_has_value('Niedostępne') && !catalog_has_value('niedostępna'), 'Nieprawidłowa cena katalogowa jest uznawana za wartość publiczną.');
catalog_test_assert(catalog_sale_price_text(['saleType' => 'garden_figure', 'grossPrice' => '110 zł', 'outletPrice' => '']) === '110 zł', 'Figura poza sklepem nie używa ceny brutto sklepu.');
catalog_test_assert(catalog_sale_price_text(['saleType' => 'showroom', 'grossPrice' => '999 zł', 'outletPrice' => '400 zł']) === '400 zł', 'Produkt showroomu nie używa ceny outletowej.');
catalog_test_assert(catalog_confirmed_brand(['brand' => 'Beliani']) === 'Beliani' && catalog_confirmed_brand([]) === '', 'Marka nie jest opcjonalna lub nie pochodzi z danych produktu.');

$piseco = catalog_apply_reviewed_product_fixes([
    'slug' => 'zestaw-2-krzesel-piseco-ciemnozielone',
    'longDescription' => 'Oferowany zestaw jest produktem outletowym / ekspozycyjnym. Na zdjęciach widoczne są miejscowe ślady ekspozycyjne oraz różnice w ułożeniu włosia weluru. Na przesłanych zdjęciach nie widać oczywistych poważnych uszkodzeń konstrukcyjnych.',
    'dimensions' => 'Szerokość: 48 cm Głębokość: Do weryfikacji — na stronie Ceneo występują niejednoznaczne dane dotyczące głębokości Wysokość: 97 cm Powierzchnia siedziska: 48 x 42 cm Wysokość siedziska: 49 cm Grubość tapicerki: 13 — jednostka nie została jednoznacznie podana w danych Ceneo Waga: 7 kg Maksymalne obciążenie: 180 kg według danych Ceneo',
]);
catalog_test_assert(!str_contains($piseco['longDescription'], 'przesłanych zdjęciach') && str_contains($piseco['longDescription'], 'ślady ekspozycyjne'), 'PISECO traci wadę albo zachowuje roboczą uwagę.');
catalog_test_assert($piseco['dimensions'] === 'Szerokość: 48 cm Głębokość całkowita: 50 cm Wysokość: 97 cm Powierzchnia siedziska: 48 x 42 cm Głębokość siedziska: 42 cm Wysokość siedziska: 49 cm Waga: 7 kg Maksymalne obciążenie: 180 kg według danych Ceneo', 'PISECO nie ma wyłącznie potwierdzonych wymiarów.');

$wallis = catalog_apply_reviewed_product_fixes([
    'slug' => 'stolik-pomocniczy-wallis-szklo-hartowane-brazowy',
    'material' => 'brązowy',
    'color' => 'żelazo/ szkło hartowane',
]);
catalog_test_assert($wallis['material'] === 'żelazo / szkło hartowane' && $wallis['color'] === 'brązowy', 'WALLIS nadal ma zamienione materiał i kolor.');

$mersey = catalog_apply_reviewed_product_fixes([
    'slug' => 'zestaw-2-lamp-sciennych-lorenta-czarno-zlote',
    'dimensions' => 'wysokość 12 szerokość 11 długość 12 głębokość 12 wymiary 12x11x12',
]);
catalog_test_assert($mersey['dimensions'] === '12 × 11 × 12', 'MERSEY nie zachowuje zatwierdzonego zapisu wymiarów.');

$avisOld = [
    'slug' => 'stol-rozkladany-avis-czarny',
    'name' => 'Stół do jadalni rozkładany AVIS — czarny, 160/210 × 90 cm',
    'description' => 'Czarny stół do jadalni rozkładany AVIS 160/210 × 90 cm.',
    'longDescription' => 'Model ma funkcję rozkładania — długość blatu można zwiększyć ze 140 cm do 190 cm.',
    'dimensions' => 'Szerokość: 90 cm Głębokość / długość: 140 / 190 cm Wysokość: 76 cm Wysokość nóżek: 74 cm',
    'imageAlt' => 'Czarny stół do jadalni rozkładany AVIS 140/190 × 90 cm z metalowymi nogami, dostępny w Home & Garden Outlet pod Wrocławiem.',
    'seoDescription' => 'Czarny stół rozkładany AVIS 140/190 × 90 cm z blatem MDF i stalowymi nogami. Home & Garden Outlet pod Wrocławiem.',
];
$avis = catalog_apply_reviewed_product_fixes($avisOld);
catalog_test_assert(str_contains($avis['longDescription'], 'ze 160 cm do 210 cm') && str_contains($avis['dimensions'], '160 / 210 cm') && str_contains($avis['imageAlt'], '160/210') && str_contains($avis['seoDescription'], '160/210'), 'AVIS nadal zawiera stare długości w polu klienckim.');
catalog_test_assert($avis['name'] === $avisOld['name'] && $avis['description'] === $avisOld['description'], 'AVIS zmienił już prawidłowe pola.');

$avisCustom = catalog_apply_reviewed_product_fixes([
    'slug' => 'stol-rozkladany-avis-czarny',
    'dimensions' => 'Własny nowy opis wymiarów zapisany w panelu',
    'longDescription' => 'Własny opis właściciela zapisany później w panelu.',
]);
catalog_test_assert($avisCustom['dimensions'] === 'Własny nowy opis wymiarów zapisany w panelu' && $avisCustom['longDescription'] === 'Własny opis właściciela zapisany później w panelu.', 'Późniejsza edycja AVIS w panelu została nadpisana przez korektę runtime.');

$magalia = catalog_apply_reviewed_product_fixes([
    'slug' => 'zestaw-4-krzesel-magalia-szary-welur',
    'name' => 'Zestaw 2 krzeseł do jadalni MAGALIA Welur Jasnobeżowy',
    'status' => 'Sprzedane',
    'description' => 'Zestaw 4 szarych krzeseł.',
    'color' => 'Szary, czarny, złoty',
]);
catalog_test_assert($magalia['name'] === 'Zestaw 4 krzeseł do jadalni MAGALIA — szary welur' && $magalia['status'] === 'Sprzedane' && $magalia['slug'] === 'zestaw-4-krzesel-magalia-szary-welur', 'MAGALIA ma zły wariant albo straciła status/adres.');

$rosell = catalog_apply_reviewed_product_fixes([
    'slug' => 'szafka-lazienkowa-rosell-40-cm',
    'material' => 'Płyta pilśniowa, rattan, żelazo  Uwaga: w szczegółowej specyfikacji Beliani materiał główny podano jako „płyta pilśniowa”, natomiast w opisie produktu użyto określenia „płyta wiórowa”.',
]);
catalog_test_assert($rosell['material'] === 'Płyta pilśniowa, rattan, żelazo', 'ROSELL 40 nie ma potwierdzonej płyty pilśniowej i pozostałych materiałów.');

$trevisoOldDimensions = 'Hamak ogrodowy TREVISO to wolnostojący model ze stabilnym drewnianym stelażem, dzięki któremu nie wymaga mocowania do drzew ani słupów. Zakrzywiona konstrukcja z drewna modrzewiowego oraz beżowa powierzchnia do leżenia tworzą wygodne miejsce do odpoczynku w ogrodzie lub na tarasie.  Powierzchnia hamaka wykonana jest z bawełny i według danych producenta może być zdejmowana oraz prana. Model przeznaczony jest do użytkowania na zewnątrz, jednak dla zachowania dobrego stanu producent zaleca zabezpieczanie go przed intensywnymi opadami i przechowywanie pod przykryciem, gdy nie jest używany.  Hamak wyposażony jest w drewniane rozpórki, liny oraz metalowy system mocowania do stelaża. Maksymalne dopuszczalne obciążenie wynosi 150 kg.  Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Na drewnianej konstrukcji widoczne są drobne ślady użytkowania i ekspozycji. Na podstawie przesłanych zdjęć nie widać istotnych uszkodzeń konstrukcyjnych.  Dodatkowe informacje: - model: TREVISO - typ: hamak ogrodowy ze stelażem - kolor: beżowy / brązowy - odcień tkaniny: złamana biel - materiał stelaża: drewno modrzewiowe - materiał powierzchni do leżenia: 100% bawełna - maksymalne obciążenie: 150 kg - zdejmowany materiał: tak - materiał nadający się do prania: tak - odporność tkaniny na promieniowanie słoneczne: klasa 4 według ISO 105-B05 - montaż według źródła: wymaga kompletnego montażu - waga produktu: 43 kg - stan: outletowy / ekspozycyjny';
$treviso = catalog_apply_reviewed_product_fixes(['slug' => 'hamak-ogrodowy-treviso-drewniany-bezowy', 'dimensions' => $trevisoOldDimensions]);
catalog_test_assert($treviso['dimensions'] === 'Szerokość: 415 cm Głębokość: 124 cm Wysokość: 122 cm Wysokość siedziska: 57 cm', 'TREVISO ma błędne lub nadmiarowe wymiary.');

$hawes = catalog_apply_reviewed_product_fixes([
    'slug' => 'wanna-hawes-hydromasaz-led-czarna',
    'longDescription' => 'Oferowany egzemplarz posiada widoczne defekty: rysy, uszkodzenie obudowy i ubytek przy narożniku. Przed sprzedażą należy potwierdzić działanie hydromasażu, LED, panelu sterowania, baterii, odpływu oraz szczelność instalacji. Jeżeli wanna nie była testowana z wodą, warto oznaczyć ją jako technicznie niesprawdzoną.',
]);
catalog_test_assert(str_contains($hawes['longDescription'], 'widoczne defekty') && str_contains($hawes['longDescription'], 'ubytek przy narożniku'), 'HAWES stracił opis rzeczywistych uszkodzeń.');
catalog_test_assert(str_contains($hawes['longDescription'], 'Elementy hydromasażu i oświetlenia LED są nowe.') && !str_contains($hawes['longDescription'], 'technicznie niesprawdzoną') && str_contains($hawes['internalNote'], 'ich działanie nie było testowane'), 'HAWES nie pokazuje potwierdzonej nowości elementów, ujawnia brak testu publicznie albo nie zachowuje go wewnętrznie.');
$hawesTwice = catalog_apply_reviewed_product_fixes($hawes);
catalog_test_assert($hawesTwice === $hawes && catalog_test_occurrences($hawesTwice['internalNote'], 'Decyzja właściciela: hydromasaż i LED są nowe; ich działanie nie było testowane.') === 1, 'Korekta HAWES nie jest idempotentna lub duplikuje notatkę.');

$zarqa = catalog_apply_reviewed_product_fixes([
    'slug' => 'wentylator-sufitowy-zarqa-oswietlenie-led',
    'longDescription' => 'Model przeznaczony jest do montażu sufitowego. Zgodnie z opisem źródłowym montaż powinien zostać wykonany przez wykwalifikowanego elektryka. Na zdjęciach widoczne są przewody montażowe, dlatego przed sprzedażą warto sprawdzić stan instalacji, kompletność zestawu oraz działanie oświetlenia i wentylatora. Produkt outletowy. Nie widać jednoznacznych dużych uszkodzeń na zdjęciach, ale przed zakupem zalecamy obejrzenie lampy na miejscu, szczególnie pod kątem kompletności elementów dekoracyjnych, łopatek, przewodów i pilota. - obecność pilota w komplecie: do potwierdzenia',
]);
catalog_test_assert(str_contains($zarqa['longDescription'], 'Oferowany egzemplarz jest nowy.') && !str_contains($zarqa['longDescription'], 'sprawdzić') && !str_contains($zarqa['longDescription'], 'do potwierdzenia') && str_contains($zarqa['longDescription'], 'wykwalifikowanego elektryka'), 'ZARQA nie pokazuje potwierdzonej nowości, nadal ma publiczny dopisek o testach albo straciła informację montażową.');
catalog_test_assert(str_contains($zarqa['internalNote'], 'światła i wentylatora nie było testowane'), 'Brak testu ZARQA nie trafił wyłącznie do notatki wewnętrznej.');

$public = catalog_public_product_record([
    'name' => 'Produkt publiczny',
    'slug' => 'produkt-publiczny',
    'internalNote' => 'SENTINEL-PRIVATE-NOTE',
    'googleText' => 'SENTINEL-INTERNAL-GOOGLE',
    'futureAdminSecret' => 'SENTINEL-FUTURE-FIELD',
]);
catalog_test_assert($public === ['name' => 'Produkt publiczny', 'slug' => 'produkt-publiczny'], 'Publiczna lista pól ujawnia notatkę, pole wewnętrzne lub nowe pole administracyjne.');

echo "PASS: catalog presentation tests\n";
