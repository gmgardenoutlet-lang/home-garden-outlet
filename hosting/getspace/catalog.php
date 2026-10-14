<?php
declare(strict_types=1);

const CATALOG_PRODUCTS_FILE = __DIR__ . '/data/products.json';
const CATALOG_SITE_URL = 'https://mgoutlet.pl';

function catalog_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function catalog_normalize(string $value): string
{
    $value = trim($value);
    $value = strtr($value, [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
    ]);
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    return strtolower($value);
}

function catalog_has_value($value): bool
{
    $normalized = trim(catalog_normalize((string)$value));
    return $normalized !== ''
        && $normalized !== 'brak'
        && $normalized !== 'xxx'
        && $normalized !== '-'
        && !in_array($normalized, ['niedostepny', 'niedostepna', 'niedostepne'], true)
        && strpos($normalized, 'do uzupelnienia') === false;
}

function catalog_slugify(string $value): string
{
    $value = catalog_normalize($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'produkt';
    return trim($value, '-') ?: 'produkt';
}

/**
 * A closed allow-list for the general public catalogue feed. Adding an admin
 * field to products.json must never expose it automatically.
 */
function catalog_public_product_fields(): array
{
    return [
        'name', 'category', 'catalogPrice', 'outletPrice', 'grossPrice', 'currency',
        'status', 'condition', 'dimensions', 'visible', 'productStatus', 'image',
        'gallery', 'imageAlt', 'description', 'longDescription', 'material', 'color',
        'availability', 'featured', 'order', 'slug', 'seoTitle', 'seoDescription',
        'keywords', 'tags', 'productType', 'saleType', 'shopVisible', 'shopStatus',
    ];
}

function catalog_public_product_record(array $product): array
{
    return array_intersect_key($product, array_fill_keys(catalog_public_product_fields(), true));
}

function catalog_append_internal_note(array &$product, string $field, string $removedText): void
{
    $note = "Treść robocza przeniesiona z pola {$field}:\n{$removedText}";
    catalog_append_internal_note_text($product, $note);
}

function catalog_append_internal_note_text(array &$product, string $note): void
{
    $note = trim($note);
    $existing = trim((string)($product['internalNote'] ?? ''));
    if ($note === '' || ($existing !== '' && strpos($existing, $note) !== false)) {
        return;
    }
    $product['internalNote'] = $existing === '' ? $note : $existing . "\n\n" . $note;
}

/**
 * Applies only individually reviewed copy corrections. Exact replacements keep
 * the operation deliberately narrow: edited source data stops matching and is
 * never rewritten by a broad phrase filter.
 */
function catalog_apply_reviewed_product_fixes(array $product): array
{
    $slug = catalog_slugify((string)($product['slug'] ?? $product['name'] ?? ''));
    $replacements = [
        'zestaw-2-krzesel-altoona-szary' => [
            'longDescription' => [[
                'Oferowany komplet jest produktem outletowym / ekspozycyjnym. Na przesłanych zdjęciach nie widać istotnych uszkodzeń tapicerki ani konstrukcji. W przypadku weluru odcień powierzchni może zmieniać się zależnie od kierunku ułożenia włosia oraz oświetlenia.',
                'Oferowany komplet jest produktem outletowym / ekspozycyjnym. W przypadku weluru odcień powierzchni może zmieniać się zależnie od kierunku ułożenia włosia oraz oświetlenia.',
            ]],
        ],
        'fotel-bujany-oulu-boucle-bezowy' => [
            'longDescription' => [[
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Na przesłanych zdjęciach nie widać istotnych uszkodzeń konstrukcji ani tapicerki. Możliwe są drobne ślady wynikające z ekspozycji lub magazynowania.',
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym.',
            ]],
        ],
        'sofa-rozkladana-glomma-niebieska' => [
            'longDescription' => [
                [
                    'W komplecie znajdują się również 2 poduszki dekoracyjne widoczne na przesłanych zdjęciach.',
                    'W komplecie znajdują się również 2 poduszki dekoracyjne.',
                ],
                [
                    'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Na przesłanych zdjęciach nie widać istotnych uszkodzeń konstrukcji ani tapicerki. Możliwe są drobne ślady związane z ekspozycją lub magazynowaniem.',
                    'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym.',
                ],
            ],
        ],
        'zestaw-4-krzesel-sanilac-bezowoszary' => [
            'longDescription' => [[
                'Oferowane krzesła są produktami outletowymi / ekspozycyjnymi. Na przesłanych zdjęciach nie widać istotnych uszkodzeń konstrukcji ani tapicerki. Welur może zmieniać wizualnie odcień w zależności od kierunku ułożenia włosia oraz rodzaju oświetlenia.',
                'Oferowane krzesła są produktami outletowymi / ekspozycyjnymi. Welur może zmieniać wizualnie odcień w zależności od kierunku ułożenia włosia oraz rodzaju oświetlenia.',
            ]],
        ],
        'zestaw-2-krzesel-covelo-bezowoszary' => [
            'longDescription' => [[
                'Oferowany komplet jest produktem outletowym / ekspozycyjnym. Na przesłanych zdjęciach nie widać istotnych uszkodzeń konstrukcji ani tapicerki. Widoczne różnice w odcieniu powierzchni wynikają między innymi z charakterystycznego sposobu układania się włosia weluru pod wpływem dotyku i światła.',
                'Oferowany komplet jest produktem outletowym / ekspozycyjnym. Widoczne różnice w odcieniu powierzchni wynikają między innymi z charakterystycznego sposobu układania się włosia weluru pod wpływem dotyku i światła.',
            ]],
        ],
        'szafka-lazienkowa-rosell-40-cm' => [
            'longDescription' => [[
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Na przesłanych zdjęciach nie widać istotnych uszkodzeń konstrukcyjnych ani wyraźnych uszkodzeń rattanowych frontów. Przed sprzedażą zalecana jest standardowa kontrola szuflady, zawiasów i powierzchni mebla.',
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym.',
            ]],
            'material' => [[
                'Płyta pilśniowa, rattan, żelazo  Uwaga: w szczegółowej specyfikacji Beliani materiał główny podano jako „płyta pilśniowa”, natomiast w opisie produktu użyto określenia „płyta wiórowa”.',
                'Płyta pilśniowa, rattan, żelazo',
            ]],
        ],
        'szafka-pod-umywalke-rosell-86-cm-2' => [
            'longDescription' => [[
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Na przesłanych zdjęciach nie widać istotnych uszkodzeń konstrukcyjnych. Przed publikacją warto jedynie wykonać końcową kontrolę powierzchni blatu, frontów oraz mocowań.',
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym.',
            ]],
        ],
        'zestaw-2-krzesel-day-zielone-boucle' => [
            'longDescription' => [[
                'Oferowany komplet jest produktem outletowym / ekspozycyjnym. Na przesłanych zdjęciach nie widać istotnych uszkodzeń konstrukcji ani wyraźnych uszkodzeń tapicerki. Mogą występować drobne ślady związane z ekspozycją.',
                'Oferowany komplet jest produktem outletowym / ekspozycyjnym.',
            ]],
        ],
        'witryna-tingledale-czarna-40-cm' => [
            'longDescription' => [[
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Na przesłanych zdjęciach widoczne są drobne ślady ekspozycji, jednak nie widać istotnych uszkodzeń konstrukcyjnych ani uszkodzeń szklanego frontu.',
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Widoczne są drobne ślady ekspozycji.',
            ]],
        ],
        'krzeslo-ogrodowe-adirondack-czerwone' => [
            'longDescription' => [[
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Na przesłanych zdjęciach widoczne są drobne ślady ekspozycyjne. Nie widać istotnych uszkodzeń konstrukcyjnych. Przed publikacją warto dodatkowo sprawdzić wszystkie połączenia oraz powierzchnię siedziska i podłokietników.',
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Widoczne są drobne ślady ekspozycyjne.',
            ]],
        ],
        'zestaw-2-krzesel-unity-boucle-bezowoszare' => [
            'longDescription' => [[
                'Oferowany zestaw jest produktem outletowym / ekspozycyjnym. Na przesłanych zdjęciach widoczne są drobne ślady ekspozycyjne, jednak nie widać istotnych uszkodzeń konstrukcyjnych. Przed publikacją warto sprawdzić tapicerkę obu krzeseł oraz stabilność nóg.',
                'Oferowany zestaw jest produktem outletowym / ekspozycyjnym. Widoczne są drobne ślady ekspozycyjne.',
            ]],
        ],
        'hamak-ogrodowy-treviso-drewniany-bezowy' => [
            'longDescription' => [[
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Na drewnianej konstrukcji widoczne są drobne ślady użytkowania i ekspozycji. Na podstawie przesłanych zdjęć nie widać istotnych uszkodzeń konstrukcyjnych.',
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Na drewnianej konstrukcji widoczne są drobne ślady użytkowania i ekspozycji.',
            ]],
            'dimensions' => [[
                'Hamak ogrodowy TREVISO to wolnostojący model ze stabilnym drewnianym stelażem, dzięki któremu nie wymaga mocowania do drzew ani słupów. Zakrzywiona konstrukcja z drewna modrzewiowego oraz beżowa powierzchnia do leżenia tworzą wygodne miejsce do odpoczynku w ogrodzie lub na tarasie.  Powierzchnia hamaka wykonana jest z bawełny i według danych producenta może być zdejmowana oraz prana. Model przeznaczony jest do użytkowania na zewnątrz, jednak dla zachowania dobrego stanu producent zaleca zabezpieczanie go przed intensywnymi opadami i przechowywanie pod przykryciem, gdy nie jest używany.  Hamak wyposażony jest w drewniane rozpórki, liny oraz metalowy system mocowania do stelaża. Maksymalne dopuszczalne obciążenie wynosi 150 kg.  Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Na drewnianej konstrukcji widoczne są drobne ślady użytkowania i ekspozycji. Na podstawie przesłanych zdjęć nie widać istotnych uszkodzeń konstrukcyjnych.  Dodatkowe informacje: - model: TREVISO - typ: hamak ogrodowy ze stelażem - kolor: beżowy / brązowy - odcień tkaniny: złamana biel - materiał stelaża: drewno modrzewiowe - materiał powierzchni do leżenia: 100% bawełna - maksymalne obciążenie: 150 kg - zdejmowany materiał: tak - materiał nadający się do prania: tak - odporność tkaniny na promieniowanie słoneczne: klasa 4 według ISO 105-B05 - montaż według źródła: wymaga kompletnego montażu - waga produktu: 43 kg - stan: outletowy / ekspozycyjny',
                'Szerokość: 415 cm Głębokość: 124 cm Wysokość: 122 cm Wysokość siedziska: 57 cm',
            ]],
        ],
        'zestaw-modulow-kuchennych-ogrodowych-venosa' => [
            'longDescription' => [[
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Jeden z modułów jest wybrakowany — brakuje dwóch wsporników. Zgodnie z przekazaną informacją brak ten nie przeszkadza w użytkowaniu. Miejsce brakujących elementów jest widoczne na przesłanych zdjęciach, dlatego warto uczciwie zaznaczyć to w ofercie.',
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Jeden z modułów jest wybrakowany — brakuje dwóch wsporników. Brak ten nie przeszkadza w użytkowaniu.',
            ]],
        ],
        'regal-johnson-3-polki-jasne-drewno' => [
            'longDescription' => [[
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Na przesłanych zdjęciach nie widać wyraźnych uszkodzeń. Przed publikacją warto jednak dodatkowo sprawdzić stan półek, narożników i krawędzi.',
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym.',
            ]],
        ],
        'zestaw-2-krzesel-melrose-oliwkowe' => [
            'longDescription' => [[
                'Oferowany zestaw jest produktem outletowym / ekspozycyjnym. Na tapicerce widoczne są miejscowe ślady ekspozycyjne oraz różnice w odcieniu. Część tych zmian może wynikać z charakterystycznego dla weluru ułożenia włosia, jednak przed publikacją warto dodatkowo sprawdzić tapicerkę po wyczyszczeniu i przeczesaniu w jednym kierunku.',
                'Oferowany zestaw jest produktem outletowym / ekspozycyjnym. Na tapicerce widoczne są miejscowe ślady ekspozycyjne oraz różnice w odcieniu. Część tych zmian może wynikać z charakterystycznego dla weluru ułożenia włosia.',
            ]],
        ],
        'zestaw-2-krzesel-piseco-ciemnozielone' => [
            'longDescription' => [[
                'Oferowany zestaw jest produktem outletowym / ekspozycyjnym. Na zdjęciach widoczne są miejscowe ślady ekspozycyjne oraz różnice w ułożeniu włosia weluru. Na przesłanych zdjęciach nie widać oczywistych poważnych uszkodzeń konstrukcyjnych.',
                'Oferowany zestaw jest produktem outletowym / ekspozycyjnym. Widoczne są miejscowe ślady ekspozycyjne oraz różnice w ułożeniu włosia weluru.',
            ]],
            'dimensions' => [[
                'Szerokość: 48 cm Głębokość: Do weryfikacji — na stronie Ceneo występują niejednoznaczne dane dotyczące głębokości Wysokość: 97 cm Powierzchnia siedziska: 48 x 42 cm Wysokość siedziska: 49 cm Grubość tapicerki: 13 — jednostka nie została jednoznacznie podana w danych Ceneo Waga: 7 kg Maksymalne obciążenie: 180 kg według danych Ceneo',
                'Szerokość: 48 cm Głębokość całkowita: 50 cm Wysokość: 97 cm Powierzchnia siedziska: 48 x 42 cm Głębokość siedziska: 42 cm Wysokość siedziska: 49 cm Waga: 7 kg Maksymalne obciążenie: 180 kg według danych Ceneo',
            ]],
        ],
        'zestaw-2-krzesel-clayton-zielone-welurowe' => [
            'longDescription' => [[
                'Oferowany zestaw jest produktem outletowym / ekspozycyjnym. Na welurowej powierzchni widoczne są ślady użytkowania ekspozycyjnego oraz miejscowe różnice w ułożeniu włosia. W przypadku weluru kierunek ułożenia włókien oraz oświetlenie mogą wpływać na widoczny odcień tapicerki. Na przesłanych zdjęciach nie widać rozdarć tapicerki ani oczywistych poważnych uszkodzeń konstrukcyjnych.',
                'Oferowany zestaw jest produktem outletowym / ekspozycyjnym. Na welurowej powierzchni widoczne są ślady użytkowania ekspozycyjnego oraz miejscowe różnice w ułożeniu włosia. W przypadku weluru kierunek ułożenia włókien oraz oświetlenie mogą wpływać na widoczny odcień tapicerki.',
            ]],
        ],
        'zestaw-4-krzesel-magalia-szary-welur' => [
            'name' => [[
                'Zestaw 2 krzeseł do jadalni MAGALIA Welur Jasnobeżowy',
                'Zestaw 4 krzeseł do jadalni MAGALIA — szary welur',
            ]],
            'longDescription' => [[
                'Produkt ma charakter outletowy / ekspozycyjny. Na powierzchni tapicerki widoczne są naturalne ślady ułożenia weluru oraz delikatne ślady ekspozycji. Na podstawie przesłanych zdjęć nie potwierdzono uszkodzeń konstrukcyjnych.',
                'Produkt ma charakter outletowy / ekspozycyjny. Na powierzchni tapicerki widoczne są naturalne ślady ułożenia weluru oraz delikatne ślady ekspozycji.',
            ]],
        ],
        'zestaw-4-krzesel-cisco-z-jasnobezowa-tapicerka-i-czarnymi-nogami-krzesla-do-jadalni-w-home-garden-outlet-pod-wroclawiem' => [
            'longDescription' => [[
                'Oferowany komplet obejmuje 4 krzesła. Produkt jest outletowy. Na przesłanych zdjęciach widoczne są ślady ekspozycyjne oraz miejscowe zabrudzenia / przebarwienia jasnej tapicerki, szczególnie na siedziskach i dolnych partiach oparć. Nie widać dużych uszkodzeń konstrukcyjnych, ale przed sprzedażą warto sprawdzić stabilność krzeseł, stan tapicerki oraz dokręcenie śrub.',
                'Oferowany komplet obejmuje 4 krzesła. Produkt jest outletowy. Widoczne są ślady ekspozycyjne oraz miejscowe zabrudzenia / przebarwienia jasnej tapicerki, szczególnie na siedziskach i dolnych partiach oparć.',
            ]],
        ],
        'zestaw-4-krzesel-onaga-boucle-biale' => [
            'longDescription' => [[
                'Oferowany komplet obejmuje 4 krzesła. Produkt jest outletowy. Na przesłanych zdjęciach widoczne są drobne ślady ekspozycyjne / miejscowe zabrudzenia tapicerki, szczególnie przy krawędzi siedziska. Nie widać dużych uszkodzeń konstrukcyjnych, ale przed sprzedażą warto sprawdzić stabilność krzeseł, stan tapicerki oraz kompletność zestawu.',
                'Oferowany komplet obejmuje 4 krzesła. Produkt jest outletowy. Widoczne są drobne ślady ekspozycyjne / miejscowe zabrudzenia tapicerki, szczególnie przy krawędzi siedziska.',
            ]],
        ],
        'biurko-caddo-biale-polki' => [
            'longDescription' => [[
                'Na przesłanych zdjęciach biurko jest złożone i wygląda na kompletne. Nie widać dużych uszkodzeń, ale jako produkt outletowy powinno zostać sprawdzone pod kątem stabilności, stanu blatu, półek, krawędzi i ewentualnych drobnych śladów ekspozycyjnych.',
                'Produkt jest outletowy.',
            ]],
        ],
        'fotel-biurowy-palmdale-boucle' => [
            'longDescription' => [[
                'Na przesłanych zdjęciach nie widać dużych uszkodzeń. Produkt jest outletowy, dlatego przed sprzedażą warto sprawdzić stan tapicerki, działanie regulacji wysokości, mechanizm odchylenia oraz kółka.',
                'Produkt jest outletowy.',
            ]],
        ],
        'lampa-wiszaca-led-bodri-czarna' => [
            'longDescription' => [[
                'Produkt outletowy. Na przesłanych zdjęciach nie widać jednoznacznych dużych uszkodzeń, ale przed sprzedażą warto sprawdzić działanie oświetlenia LED, stan przewodów, kompletność elementów montażowych oraz ogólny stan wizualny lampy.',
                'Produkt outletowy.',
            ]],
        ],
        'lampa-wiszaca-krysztalowa-srebrna' => [
            'longDescription' => [[
                'Na przesłanych zdjęciach widać lampę wiszącą na łańcuchach, z okrągłą/opływową oprawą i kilkoma rzędami ozdobnych kryształków. Przed sprzedażą warto dodatkowo sprawdzić kompletność elementów dekoracyjnych, stan oprawy oraz instalacji elektrycznej.',
                'Lampa jest zawieszana na łańcuchach i ma okrągłą/opływową oprawę z kilkoma rzędami ozdobnych kryształków.',
            ]],
        ],
        'lustro-scienne-massilly-czarne' => [
            'longDescription' => [[
                'Lustro jest przeznaczone do zawieszenia na ścianie. Według danych producenta posiada haczyki montażowe z tyłu produktu. Na przesłanych zdjęciach nie widać wyraźnych uszkodzeń, ale jako produkt outletowy powinno zostać obejrzane na miejscu przed zakupem.',
                'Lustro jest przeznaczone do zawieszenia na ścianie. Według danych producenta posiada haczyki montażowe z tyłu produktu.',
            ]],
        ],
        'stol-rozkladany-avis-czarny' => [
            'longDescription' => [
                [
                    'Model ma funkcję rozkładania — długość blatu można zwiększyć ze 140 cm do 190 cm.',
                    'Model ma funkcję rozkładania — długość blatu można zwiększyć ze 160 cm do 210 cm.',
                ],
                [
                    'Blat wykonany jest z MDF, a konstrukcja została uzupełniona stalowymi nogami. Na przesłanych zdjęciach widać czarny blat z jasną krawędzią oraz linię łączenia blatu wynikającą z funkcji rozkładania. Nie widać jednoznacznych uszkodzeń, ale jako produkt outletowy powinien zostać obejrzany na miejscu przed zakupem.',
                    'Blat wykonany jest z MDF, a konstrukcja została uzupełniona stalowymi nogami. Czarny blat ma jasną krawędź oraz linię łączenia wynikającą z funkcji rozkładania.',
                ],
            ],
            'dimensions' => [[
                'Szerokość: 90 cm Głębokość / długość: 140 / 190 cm Wysokość: 76 cm Wysokość nóżek: 74 cm',
                'Szerokość: 90 cm Głębokość / długość: 160 / 210 cm Wysokość: 76 cm Wysokość nóżek: 74 cm',
            ]],
            'imageAlt' => [[
                'Czarny stół do jadalni rozkładany AVIS 140/190 × 90 cm z metalowymi nogami, dostępny w Home & Garden Outlet pod Wrocławiem.',
                'Czarny stół do jadalni rozkładany AVIS 160/210 × 90 cm z metalowymi nogami, dostępny w Home & Garden Outlet pod Wrocławiem.',
            ]],
            'seoDescription' => [[
                'Czarny stół rozkładany AVIS 140/190 × 90 cm z blatem MDF i stalowymi nogami. Home & Garden Outlet pod Wrocławiem.',
                'Czarny stół rozkładany AVIS 160/210 × 90 cm z blatem MDF i stalowymi nogami. Home & Garden Outlet pod Wrocławiem.',
            ]],
        ],
        'konsola-birson-czarna-120-cm' => [
            'longDescription' => [[
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Na zdjęciach widoczne są drobne ślady ekspozycji, miejscowe zabrudzenia i niewielkie ślady powierzchniowe. Nie widać uszkodzeń konstrukcyjnych wpływających na użytkowanie.',
                'Oferowany egzemplarz jest produktem outletowym / ekspozycyjnym. Widoczne są drobne ślady ekspozycji, miejscowe zabrudzenia i niewielkie ślady powierzchniowe.',
            ]],
        ],
        'zestaw-2-krzesel-wellston-ciemnoszary-welur' => [
            'longDescription' => [[
                'Oferowany komplet jest produktem outletowym / ekspozycyjnym. Na zdjęciach widoczne są drobne ślady ekspozycji oraz naturalne zmiany odcienia wynikające z kierunku ułożenia włosia weluru. Nie widać istotnych uszkodzeń konstrukcyjnych.',
                'Oferowany komplet jest produktem outletowym / ekspozycyjnym. Widoczne są drobne ślady ekspozycji oraz naturalne zmiany odcienia wynikające z kierunku ułożenia włosia weluru.',
            ]],
        ],
        'zestaw-2-krzesel-mayetta-ciemnozielone' => [
            'longDescription' => [[
                'Oferowany zestaw jest produktem outletowym / ekspozycyjnym. Na zdjęciach widoczne są drobne ślady ekspozycyjne oraz lekkie miejscowe przybrudzenia tapicerki. Nie widać poważnych uszkodzeń konstrukcyjnych.',
                'Oferowany zestaw jest produktem outletowym / ekspozycyjnym. Widoczne są drobne ślady ekspozycyjne oraz lekkie miejscowe przybrudzenia tapicerki.',
            ]],
        ],
        'lozko-dzieciece-cossaye-domek' => [
            'longDescription' => [[
                'Oferowany egzemplarz jest produktem outletowym. Na zdjęciach widoczne są drobne ślady ekspozycyjne, miejscowe obtarcia oraz niewielkie odpryski lakieru przy niektórych łączeniach konstrukcji. Wady mają charakter wizualny i powinny zostać pokazane klientowi przed zakupem.',
                'Oferowany egzemplarz jest produktem outletowym. Widoczne są drobne ślady ekspozycyjne, miejscowe obtarcia oraz niewielkie odpryski lakieru przy niektórych łączeniach konstrukcji. Wady mają charakter wizualny.',
            ]],
        ],
        'wanna-hawes-hydromasaz-led-czarna' => [
            'longDescription' => [[
                'Przed sprzedażą należy potwierdzić działanie hydromasażu, LED, panelu sterowania, baterii, odpływu oraz szczelność instalacji. Jeżeli wanna nie była testowana z wodą, warto oznaczyć ją jako technicznie niesprawdzoną.',
                'Elementy hydromasażu i oświetlenia LED są nowe.',
            ], [
                ' Te informacje powinny pozostać widoczne w opisie dla klienta.',
                '',
            ], [
                '- stan: outletowy, z widocznymi defektami',
                '',
            ]],
        ],
        'wentylator-sufitowy-zarqa-oswietlenie-led' => [
            'longDescription' => [
                [
                    'Model przeznaczony jest do montażu sufitowego. Zgodnie z opisem źródłowym montaż powinien zostać wykonany przez wykwalifikowanego elektryka. Na zdjęciach widoczne są przewody montażowe, dlatego przed sprzedażą warto sprawdzić stan instalacji, kompletność zestawu oraz działanie oświetlenia i wentylatora.',
                    'Model przeznaczony jest do montażu sufitowego. Zgodnie z opisem źródłowym montaż powinien zostać wykonany przez wykwalifikowanego elektryka.',
                ],
                [
                    'Produkt outletowy. Nie widać jednoznacznych dużych uszkodzeń na zdjęciach, ale przed zakupem zalecamy obejrzenie lampy na miejscu, szczególnie pod kątem kompletności elementów dekoracyjnych, łopatek, przewodów i pilota.',
                    'Oferowany egzemplarz jest nowy.',
                ],
                [
                    '- obecność pilota w komplecie: do potwierdzenia',
                    '',
                ],
            ],
        ],
        'stolik-pomocniczy-wallis-szklo-hartowane-brazowy' => [
            'material' => [['brązowy', 'żelazo / szkło hartowane']],
            'color' => [['żelazo/ szkło hartowane', 'brązowy']],
        ],
        'zestaw-2-lamp-sciennych-lorenta-czarno-zlote' => [
            'dimensions' => [['wysokość 12 szerokość 11 długość 12 głębokość 12 wymiary 12x11x12', '12 × 11 × 12']],
        ],
    ];

    $appliedChange = false;
    foreach (($replacements[$slug] ?? []) as $field => $pairs) {
        $value = (string)($product[$field] ?? '');
        foreach ($pairs as [$from, $to]) {
            if ($from === '' || strpos($value, $from) === false) {
                continue;
            }
            $value = str_replace($from, $to, $value);
            catalog_append_internal_note($product, $field, $from);
            $appliedChange = true;
        }
        $product[$field] = trim($value);
    }

    if ($appliedChange && $slug === 'wanna-hawes-hydromasaz-led-czarna') {
        catalog_append_internal_note_text($product, 'Decyzja właściciela: hydromasaż i LED są nowe; ich działanie nie było testowane.');
    }
    if ($appliedChange && $slug === 'wentylator-sufitowy-zarqa-oswietlenie-led') {
        catalog_append_internal_note_text($product, 'Decyzja właściciela: produkt jest nowy; działanie światła i wentylatora nie było testowane.');
    }

    return $product;
}

// Listing cards retain complete public condition paragraphs, without truncation.
// Technical bullet lists contribute only their condition lines, not dimensions.
function catalog_listing_state_notes(array $product, bool $review = false): string|array
{
    // Presentation only: never modify the record or its owner-written description.
    $longDescription = (string)($product['longDescription'] ?? '');
    if (($product['slug'] ?? '') === 'wanna-hawes-hydromasaz-led-czarna') {
        $longDescription = str_replace([
            ' Te informacje powinny pozostać widoczne w opisie dla klienta.',
            '- stan: outletowy, z widocznymi defektami',
        ], '', $longDescription);
    }
    $canonical = static function (string $text): string {
        $text = strtolower(strtr($text, ['Ą'=>'ą','Ć'=>'ć','Ę'=>'ę','Ł'=>'ł','Ń'=>'ń','Ó'=>'ó','Ś'=>'ś','Ź'=>'ź','Ż'=>'ż']));
        return trim(preg_replace('/[.!?]+$/u', '', preg_replace('/\s+/u', ' ', $text) ?? $text) ?? $text);
    };
    $matches = static fn(string $pattern, string $text): bool => preg_match($pattern, $text) === 1;
    $technicalKey = '/^(?:model|typ|kształt|kolor(?: .*)?|materiał(?:y)?(?: .*)?|wykończenie(?: .*)?|waga(?: .*)?|maksymalne obciążenie(?: .*)?|wymiary|długość(?: .*)?|szerokość(?: .*)?|wysokość(?: .*)?|głębokość(?: .*)?|montaż(?: .*)?|w zestawie(?: .*)?|liczba(?: .*)?|miejsce(?: .*)?|pojemność|napięcie|pobór mocy|ogrzewanie|pompa(?: .*)?|poziom hałasu|dysze(?: .*)?|numer artykułu(?: .*)?|tapicerka|skład(?: .*)?|wypełnienie|konstrukcja(?: .*)?|nóżki|podłokietniki|oparcie|wzór|gęstość(?: .*)?|mechanizm(?: .*)?|funkcja(?: .*)?|cechy dodatkowe|zawartość zestawu|odporność|przeznaczenie|styl|forma|orientacja|wykonanie|sposób zawieszenia|elementy ozdobne|szuflada|otwarte półki|otwarta półka(?: .*)?|obrót|regulacja(?: .*)?|podstawa(?: .*)?|automatyczna blokada(?: .*)?|sztaplowane|zestaw|element \d+)$/iu';
    $stateKey = '/^(?:stan|wady|wada|defekt|uszkodzenia|braki|uwaga o stanie|użytkowanie|historia egzemplarza)$/iu';
    $damage = '/uszkod|defekt|ubyt|pękni|pekni|odprysk|wgniec|otarci|zarys|przybrudz|zabrudz|przebarwi|plam|urwan|złaman|wyłaman|wybrak|niekompletn|niespraw|nie działa|nie dziala|\bwad[ayę]\b/iu';
    $missing = '/brakuje|brakują|brakujace|brakujące|\bbrak\s+(?!wad\b|uszkodzeń\b|defektów\b)\S+/iu';
    $ambiguousWord = '/\brys(?:y|ami|ach)\b|przetar|napraw|\bwad[ayę]\b/iu';
    $conditionEvidence = '/ślady|śladów|slady|sladow|(?:Oferowan\S*|Ten egzemplarz|Jest to egzemplarz|Produkt|Komplet|Egzemplarz)[^.!?]*(?:outletow|ekspozycyjn)|różnice w fakturze, kolorze lub kształcie|(?:elementy|egzemplarz|produkt|części|część|oświetlenie|LED)[^.!?]*(?:są nowe|jest now[yae]|są now[ey])|(?:egzemplarz|komplet)[^.!?]*zmontowan/iu';
    $continuation = '/^(?:Na (?:zdjęciach|przesłanych zdjęciach)|Zdjęcia przedstawiają|Możliwe są|Widoczne|W przypadku weluru|Welur może|Część tych|Wady |Brak ten|Nie (?:wpływa|przeszkadza)|Defekt został|Przed zakupem|Jest to naturalna cecha)/iu';
    $pureInstruction = '/^(?:(?:Krzesła|Stół|Fotel|Produkt|Model|Sofa|Mebel|Zestaw) (?:są|jest) częściowo zmontowan|(?:Według (?:danych|producenta|strony źródłowej)|Skład tapicerki|Montaż |Wymaga (?:kompletnego )?montażu))/iu';
    $manufacturerProtection = '/^Model został zaprojektowany do użytkowania na zewnątrz, a producent deklaruje odporność na wodę, zabrudzenia, promieniowanie UV oraz wiatr\.$/iu';
    $promotional = '/^(?:To (?:dobry wybór|propozycja)|(?:Ławka|Model|Dekoracja|Donica|Figura|Stół|Fotel) (?:dobrze |może |sprawdzi)|Dzięki temu|Stonowane wykończenie|Kontrastowe wykończenie)/iu';
    $generic = '/^(?:outletowy|outletowa|outletowe|outletowy\s*\/\s*ekspozycyjny|outletowa\s*\/\s*ekspozycyjna|ekspozycyjny)$/iu';
    $existing = [(string)($product['description'] ?? ''), (string)($product['condition'] ?? '')];
    $selected = $ambiguous = $excluded = [];
    $technicalSection = false;
    $paragraphs = preg_split('/\n\s*\n/u', trim(str_replace(["\r\n", "\r"], "\n", $longDescription))) ?: [];
    foreach ($paragraphs as $paragraphIndex => $paragraph) {
        $context = false;
        foreach (explode("\n", $paragraph) as $lineIndex => $line) {
            $trimmed = preg_replace('/^[-•]\s*/u', '', trim($line)) ?? trim($line);
            if ($trimmed === '') continue;
            if ($matches('/^(?:Dodatkowe informacje|Najważniejsze cechy|W zestawie)\s*:$/iu', $trimmed)) {
                $technicalSection = true;
                continue;
            }
            foreach (preg_split('/;\s*|(?<=[.!?])\s+(?=[A-ZĄĆĘŁŃÓŚŹŻ])/u', $trimmed) ?: [] as $unit) {
                $text = trim($unit);
                if ($text === '') continue;
                $pair = [];
                $paired = preg_match('/^([^:]{1,90}):\s*(.*)$/u', $text, $pair) === 1;
                $key = $paired ? trim($pair[1]) : '';
                $value = $paired ? trim($pair[2]) : $text;
                $technical = $paired && $matches($technicalKey, $key);
                $state = $paired && $matches($stateKey, $key);
                $scan = preg_replace('/złaman(?:a|ej|ą|e)\s+biel(?:i|ą)?/iu', '', $text) ?? $text;
                $hard = $matches($damage, $scan) || $matches($missing, $scan);
                $evidence = $hard || $matches($conditionEvidence, $text) || $state;
                $unclear = $matches($ambiguousWord, $text);
                $physicalDamage = $matches($missing, $scan) || $matches($damage, preg_replace('/zarys\S*|\brysy\b/iu', '', $scan) ?? $scan);
                $specimenClaim = $matches('/widoczn[^.!?]*(?:rysy|zarys|przetar)|(?:ma|posiada|występują|są)\s+(?:rysy|zarysowania|przetarcia)/iu', $text);
                $design = !$physicalDamage && !$specimenClaim && (
                    ($matches('/^(?:.*(?:kolorystyka|wykończenie|kolorystyce)|Powierzchni[aeę]|Brązow[ae]|Szaro-czarn[ae]|Szare|Grafitowa|Ciemnoszaro-beżowe)/iu', $text) && $matches('/przetar|zarys/iu', $text) && $matches('/podkreśl|nadaj|zdobi|wykończ/iu', $text))
                    || ($matches('/\brysy\s+(?:kobiecej\s+)?(?:twarzy|pyska)\b|spokojne rysy|zarysowane usta/iu', $text) && $matches('/łagodne|delikatnie|harmonijn|starannie|dekoracyjn|spokojne|wyraziste|artystyczn|rzeźbiarsk|podkreśl|nadaj/iu', $text))
                    || $matches('/chronią?[^.!?]*przed zarysowaniem\.?$/iu', $text)
                );
                if ($design || $matches($manufacturerProtection, $text) || ($technical && !$evidence && !$unclear && !$matches('/\bnow[yae]\b/iu', $value)) || ($matches($pureInstruction, $text) && !$evidence && !$unclear)) {
                    $excluded[] = ['text'=>$text, 'reason'=>$design ? 'design' : 'technical'];
                    continue;
                }
                if (!$evidence && !$unclear && !($context && $matches($continuation, $text))) {
                    if ($context && !$technicalSection && !$matches($promotional, $text)) {
                        $ambiguous[] = ['text'=>$text, 'reason'=>'Unclassified continuation of a condition statement'];
                    } else {
                        $excluded[] = ['text'=>$text, 'reason'=>'Not specimen-condition information'];
                        continue;
                    }
                }
                if ($technical && ($evidence || $unclear || $matches('/\bnow[yae]\b/iu', $value))) {
                    $ambiguous[] = ['text'=>$text, 'reason'=>'Technical field also carries condition information; retained intact'];
                } elseif ($unclear && !$evidence) {
                    $ambiguous[] = ['text'=>$text, 'reason'=>'Ambiguous condition wording; retained intact'];
                }
                $normal = $canonical($value);
                $present = array_merge($existing, array_column($selected, 'text'));
                $exactRepeat = $genericRepeat = false;
                foreach ($present as $already) {
                    foreach (preg_split('/\n+|;\s*|(?<=[.!?])\s+(?=[A-ZĄĆĘŁŃÓŚŹŻ])/u', $already) ?: [] as $part) {
                        if ($canonical(preg_replace('/^[-•]\s*/u', '', $part) ?? $part) === $canonical($text)) $exactRepeat = true;
                    }
                    if ($matches($generic, $value) && ($canonical($already) === $normal
                        || ($matches('/outletow/iu', $value) && $matches('/ekspozycyjn/iu', $value) && $matches('/outletow/iu', $already) && $matches('/ekspozycyjn/iu', $already)))) $genericRepeat = true;
                }
                $proseRepeat = $matches('/^(?:Produkt (?:jest )?outletowy|Jest to egzemplarz outletowy)\.?$/iu', $text)
                    && in_array('outletowy', array_map($canonical, $existing), true);
                $moduleClaim = [];
                $moduleRepeat = false;
                if ($state && preg_match('/^(?:w jednym module|jeden z modułów) brakuje (.+?), co (nie przeszkadza w użytkowaniu)\.?$/iu', $value, $moduleClaim) === 1) {
                    $statementParagraphs = [];
                    foreach ($selected as $item) {
                        $group = explode(':', $item['group'])[0];
                        $statementParagraphs[$group] = trim(($statementParagraphs[$group] ?? '') . ' ' . $item['text']);
                    }
                    foreach (array_merge($existing, array_values($statementParagraphs)) as $already) {
                        if ($matches('/jeden z modułów|w jednym module/iu', $already) && str_contains($canonical($already), $canonical('brakuje ' . $moduleClaim[1])) && str_contains($canonical($already), $canonical($moduleClaim[2]))) $moduleRepeat = true;
                    }
                }
                if ($exactRepeat || $genericRepeat || $proseRepeat || $moduleRepeat) {
                    $excluded[] = ['text'=>$text, 'reason'=>'Duplicate already visible on the card'];
                    $context = true;
                    continue;
                }
                $selected[] = ['text'=>$text, 'group'=>"$paragraphIndex:$lineIndex", 'bullet'=>$matches('/^[-•]\s*/u', trim($line))];
                $context = true;
            }
        }
    }
    $groups = [];
    foreach ($selected as $item) {
        $last = count($groups) - 1;
        if ($last >= 0 && $groups[$last]['group'] === $item['group']) $groups[$last]['text'] .= ' ' . $item['text'];
        else $groups[] = $item;
    }
    $text = implode("\n\n", array_map(static fn(array $x): string => ($x['bullet'] ? '- ' : '') . $x['text'], $groups));
    return $review ? ['text'=>$text, 'ambiguous'=>$ambiguous, 'excluded'=>$excluded] : $text;
}

function catalog_sale_price_text(array $product): string
{
    $field = catalog_is_figure_shop_product($product) ? 'grossPrice' : 'outletPrice';
    return catalog_has_value($product[$field] ?? '') ? trim((string)$product[$field]) : '';
}

function catalog_sale_price_caption(array $product): string
{
    return catalog_is_figure_shop_product($product) ? 'Cena' : 'Cena outletowa';
}

function catalog_confirmed_brand(array $product): string
{
    return catalog_has_value($product['brand'] ?? '') ? trim((string)$product['brand']) : '';
}

function catalog_load(): array
{
    if (!is_file(CATALOG_PRODUCTS_FILE)) {
        return [];
    }

    $data = json_decode((string)file_get_contents(CATALOG_PRODUCTS_FILE), true);
    return is_array($data) && isset($data['products']) && is_array($data['products'])
        ? $data['products']
        : [];
}

// Explicit owner-approved aliases; never infer identity from similar names.
function catalog_legacy_figure_shop_slug(string $slug): ?string
{
    return [
        'rzezba-ogrodowa-twarz-mala-dostepne-w-roznych-barwach' => 'figura-ogrodowa-twarz-czarna-artystyczne-wykonczenie',
        'rzezba-betonowa-do-ogrodu-dekoracyjna-glowa-120-cm' => 'figura-ogrodowa-twarz-kobiety-114-cm-czarna-z-miedzianym-motywem-winorosli',
        'rzezba-betonowa-do-ogrodu-z-siedziskiem-dekoracyjna-glowa-120-cm' => 'figura-ogrodowa-twarz-kobiety-z-zamknietymi-oczami-i-siedziskiem-114-cm-szaro-brazowa',
    ][$slug] ?? null;
}

function catalog_is_public(array $product): bool
{
    return ($product['visible'] ?? true) !== false
        && catalog_normalize((string)($product['productStatus'] ?? '')) !== 'ukryty';
}

function catalog_is_figure_shop_product(array $product): bool
{
    return ($product['saleType'] ?? '') === 'garden_figure';
}

function catalog_is_active_figure_shop_product(array $product): bool
{
    return catalog_is_figure_shop_product($product)
        && !empty($product['shopVisible'])
        && ($product['shopStatus'] ?? '') === 'Dostępny'
        && (($product['merchantSupplyModel'] ?? '') !== 'supplier' || ($product['producerAvailability'] ?? '') === 'Dostępny u producenta')
        && catalog_normalize((string)($product['productStatus'] ?? '')) !== 'rezerwacja'
        && catalog_normalize((string)($product['status'] ?? '')) !== 'rezerwacja'
        && !in_array((string)($product['productStatus'] ?? ''), ['Sprzedany', 'Ukryty'], true)
        && !in_array((string)($product['status'] ?? ''), ['Sprzedane', 'Sprzedany'], true);
}

function catalog_is_indexable_figure_shop_product(array $product): bool
{
    return catalog_is_figure_shop_product($product)
        && !empty($product['shopVisible'])
        && ($product['shopStatus'] ?? '') === 'Dostępny'
        && catalog_normalize((string)($product['productStatus'] ?? '')) !== 'ukryty';
}

function catalog_figure_shop_product_url(array $product): string
{
    $source = catalog_has_value($product['slug'] ?? '')
        ? (string)$product['slug']
        : (string)($product['name'] ?? 'produkt');

    return '/sklep/figury-ogrodowe/produkt/' . rawurlencode(catalog_slugify($source));
}

function catalog_display_status(array $product): string
{
    $managementStatus = catalog_normalize((string)($product['productStatus'] ?? ''));
    if ($managementStatus === 'sprzedany') {
        return 'Sprzedane';
    }
    if ($managementStatus === 'rezerwacja') {
        return 'Rezerwacja';
    }
    return catalog_has_value($product['status'] ?? '') ? trim((string)$product['status']) : 'Dostępne od ręki';
}

function catalog_products_with_slugs(): array
{
    $products = catalog_load();
    $used = [];

    foreach ($products as $index => &$product) {
        $product = catalog_apply_reviewed_product_fixes($product);
        $source = catalog_has_value($product['slug'] ?? '')
            ? (string)$product['slug']
            : (string)($product['name'] ?? 'produkt');
        $base = catalog_slugify($source);
        $used[$base] = ($used[$base] ?? 0) + 1;
        $product['_publicSlug'] = $used[$base] > 1 ? $base . '-' . $used[$base] : $base;
        $product['_catalogIndex'] = $index;
    }
    unset($product);

    return $products;
}

function catalog_find_product(string $slug): ?array
{
    $product = catalog_find_product_record($slug);

    return $product !== null && catalog_is_public($product) ? $product : null;
}

function catalog_find_product_record(string $slug): ?array
{
    $slug = catalog_slugify($slug);
    foreach (catalog_products_with_slugs() as $product) {
        if (($product['_publicSlug'] ?? '') === $slug) {
            return $product;
        }
    }
    return null;
}

function catalog_image_path($value): string
{
    $path = trim((string)$value);
    if ($path === '' || strpos($path, '..') !== false) {
        return '/product-table.jpeg';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return '/' . ltrim($path, '/');
}

function catalog_images(array $product): array
{
    $paths = [$product['image'] ?? ''];
    foreach (($product['gallery'] ?? []) as $item) {
        $paths[] = is_array($item) ? ($item['image'] ?? '') : $item;
    }

    $result = [];
    foreach ($paths as $path) {
        if (catalog_has_value($path)) {
            $result[] = catalog_image_path($path);
        }
    }
    return array_values(array_unique($result ?: ['/product-table.jpeg']));
}

function catalog_absolute_url(string $path): string
{
    return preg_match('#^https?://#i', $path)
        ? $path
        : CATALOG_SITE_URL . '/' . ltrim($path, '/');
}

function catalog_price_number($value): ?float
{
    $cleaned = str_replace([' ', ','], ['', '.'], (string)$value);
    if (!preg_match('/\d+(?:\.\d+)?/', $cleaned, $matches)) {
        return null;
    }
    return (float)$matches[0];
}

function catalog_shorten(string $value, int $maxLength = 160): string
{
    $text = trim((string)preg_replace('/\s+/', ' ', $value));
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') <= $maxLength) {
        return $text;
    }
    if (!function_exists('mb_substr')) {
        return strlen($text) <= $maxLength ? $text : rtrim(substr($text, 0, $maxLength - 1)) . '…';
    }
    $short = mb_substr($text, 0, $maxLength - 1, 'UTF-8');
    $space = mb_strrpos($short, ' ', 0, 'UTF-8');
    if ($space !== false && $space > 100) {
        $short = mb_substr($short, 0, $space, 'UTF-8');
    }
    return rtrim($short, " .,\t\n\r\0\x0B") . '…';
}

function catalog_seo(array $product): array
{
    $name = catalog_has_value($product['name'] ?? '') ? trim((string)$product['name']) : 'Produkt outletowy';
    $category = catalog_has_value($product['category'] ?? '') ? trim((string)$product['category']) : 'Meble do domu i ogrodu';
    $description = catalog_has_value($product['seoDescription'] ?? '')
        ? (string)$product['seoDescription']
        : ((catalog_has_value($product['description'] ?? '') ? (string)$product['description'] : $name)
            . ' ' . $category . ' dostępne w showroomie Home & Garden Outlet pod Wrocławiem.');

    return [
        'slug' => (string)($product['_publicSlug'] ?? catalog_slugify((string)($product['slug'] ?? $name))),
        'title' => catalog_has_value($product['seoTitle'] ?? '')
            ? trim((string)$product['seoTitle'])
            : $name . ' | Home & Garden Outlet',
        'description' => catalog_shorten($description),
        'imageAlt' => catalog_has_value($product['imageAlt'] ?? '')
            ? trim((string)$product['imageAlt'])
            : $name . ' dostępny w Home & Garden Outlet pod Wrocławiem',
    ];
}

function catalog_is_figure_decorations_category($value): bool
{
    return catalog_normalize((string)$value) === 'figury i dekoracje ogrodowe';
}

function catalog_is_garden_equipment_category($value): bool
{
    return in_array(catalog_normalize((string)$value), ['wyposazenie ogrodu', 'ogrod'], true);
}

function catalog_category_url(array $product): string
{
    $category = $product['category'] ?? '';
    if (catalog_is_figure_decorations_category($category)) {
        // There is no public thematic listing for this data category yet.
        return '/';
    }

    return catalog_is_garden_equipment_category($category) ? '/ogrod' : '/dom';
}
