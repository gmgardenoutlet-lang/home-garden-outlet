<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
require __DIR__ . '/prepare-lib.php';
boot_admin(); require_login();
header('Referrer-Policy: no-referrer');
$catalog = load_catalog();
$groups = [];
foreach ($catalog['products'] as $item) { $group = (string)($item['category'] ?? ''); $groups[$group] = ($groups[$group] ?? 0) + 1; }
ksort($groups);
$config = olx_config();
$id = is_string($_GET['productId'] ?? null) ? $_GET['productId'] : '';
$product = $id !== '' ? olx_find_product($catalog, $id) : null;
$draft = $product ? (olx_json_read(olx_draft_file($config, $id)) ?? olx_default_draft($product)) : null;
$message = ''; $errors = []; $suggestions = []; $categories = []; $category = null; $attributes = []; $city = []; $districts = []; $currencies = [];
$cityMatches = []; $cityPage = max(0, min(150, (int)($_GET['cityPage'] ?? 0)));
$citySearch = is_string($_GET['citySearch'] ?? null) ? trim($_GET['citySearch']) : 'Kębłowice';
$settingsFile = olx_private_folder($config, 'settings') . '/mapping.json';
$settings = olx_json_read($settingsFile) ?? ['mappings' => [], 'location' => []];
$action = '';
try {
    olx_preflight($config);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_csrf(); $action = post_text('action');
        if (!in_array($action, ['refresh','map','cities','location','suggest','category','validate','save'], true)) throw new OlxSafeException('Nieprawidłowa operacja ETAPU C.');
    }
    $categories = olx_categories($config, $action === 'refresh');
    $currencies = olx_cached_get($config, '/currencies', [], $action === 'refresh');
    if ($action === 'cities') { $cityPage = max(0,min(150,(int)post_text('cityPage'))); $citySearch = post_text('citySearch'); }
    if ($action === 'cities') {
        $rows = olx_cities_page($config, $cityPage);
        foreach ($rows as $row) if (is_array($row) && ($citySearch === '' || preg_match('/' . preg_quote($citySearch,'/') . '/iu',(string)$row['name']))) $cityMatches[] = $row;
        $message = 'Pobrano stronę miast ' . ($cityPage + 1) . ' (' . count($rows) . ' wpisów). Wyników filtra: ' . count($cityMatches) . '. Możesz sprawdzić następną stronę.';
    }
    if ($action === 'map') {
        $source = post_text('sourceCategory'); $target = (int)post_text('mappingCategory');
        if (!isset($groups[$source]) || empty($categories[$target]['is_leaf'])) throw new OlxSafeException('Mapowanie wymaga istniejącej kategorii sklepu i końcowej kategorii OLX.');
        $settings['mappings'][$source] = ['category_id' => $target, 'path' => olx_category_path($categories[$target], $categories), 'confirmed_at' => time()];
        olx_json_write($settingsFile, $settings); $message = 'Zapisano mapowanie. Nie wybrano ani nie wystawiono żadnego produktu.';
    }
    if ($action === 'location') {
        $cityId = (int)post_text('city_id'); $districtId = (int)post_text('district_id');
        $selectedCity = olx_cached_get($config, '/cities/' . $cityId);
        if ((int)($selectedCity['id'] ?? 0) !== $cityId) throw new OlxSafeException('Nie potwierdzono miasta OLX.');
        $selectedDistricts = olx_cached_get($config, '/cities/' . $cityId . '/districts');
        if ($selectedDistricts && !in_array($districtId, array_map('intval',array_column($selectedDistricts,'id')),true)) throw new OlxSafeException('To miasto wymaga wybrania dzielnicy.');
        if (!$selectedDistricts && $districtId !== 0) throw new OlxSafeException('Miasto nie ma tej dzielnicy.');
        $settings['location'] = ['city_id'=>$cityId,'district_id'=>$districtId,'name'=>$selectedCity['name'],'county'=>$selectedCity['county'] ?? '', 'municipality'=>$selectedCity['municipality'] ?? ''];
        olx_json_write($settingsFile, $settings); $message = 'Zapisano domyślną lokalizację wybraną z OLX. Nie zmieniono produktów.';
    }
    if ($product) {
        if ($action === 'category') {
            $selected = (int)post_text('selectedCategoryId');
            if (empty($categories[$selected]['is_leaf'])) throw new OlxSafeException('Wybierz kategorię końcową.');
            $draft['selectedCategoryId'] = $selected; $draft['attributeValues'] = []; $draft['preparedStatus'] = 'incomplete';
            olx_json_write(olx_draft_file($config,$id), $draft); $message = 'Kategoria szkicu zatwierdzona. Uzupełnij parametry i zdjęcia.';
        }
        if (in_array($action,['validate','save'],true)) {
            $posted = $_POST;
            // Category changes require their dedicated confirmation and reset attributes.
            $posted['selectedCategoryId'] = $draft['selectedCategoryId'];
            $draft = olx_clean_draft($product,$posted);
        }
        if ($action === 'suggest') {
            $suggestions = olx_cached_get($config, '/categories/suggestion', ['q'=>(string)$product['name']], true);
            $message = 'Pobrano sugestie OLX. Żadnej kategorii nie wybrano automatycznie.';
        }
        $selected = (int)$draft['selectedCategoryId'];
        if ($selected > 0) {
            $category = olx_cached_get($config, '/categories/' . $selected);
            $attributes = olx_cached_get($config, '/categories/' . $selected . '/attributes');
        }
        if (!empty($draft['location']['city_id'])) {
            $city = olx_cached_get($config, '/cities/' . (int)$draft['location']['city_id']);
            $districts = olx_cached_get($config, '/cities/' . (int)$draft['location']['city_id'] . '/districts');
        }
        $errors = olx_validate_draft($product,$draft,$category,$attributes,$city,$districts,$currencies);
        if ($action === 'save') {
            $draft['preparedStatus'] = $errors ? 'incomplete' : 'ready_local'; $draft['updatedAt'] = time();
            olx_json_write(olx_draft_file($config,$id),$draft);
            $message = 'Zapisano wyłącznie osobny szkic OLX. Nie opublikowano ogłoszenia.';
        }
    }
} catch (Throwable $exception) {
    $message = $exception instanceof OlxSafeException ? $exception->getMessage() : 'Operacja przygotowania nie powiodła się. Sprawdź formularz i spróbuj ponownie.';
    $errors[] = 'Nie zakończono pobierania lub walidacji danych OLX.';
}
function olx_csrf_field(): void { echo '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function olx_category_select(string $name, array $categories, int $selected = 0): void {
    echo '<select name="' . e($name) . '" required><option value="">Wybierz kategorię końcową</option>';
    $options = []; foreach ($categories as $row) if (!empty($row['is_leaf'])) $options[(int)$row['id']] = olx_category_path($row,$categories);
    asort($options,SORT_NATURAL);
    foreach ($options as $key=>$label) echo '<option value="' . $key . '"' . ($key===$selected?' selected':'') . '>' . e($label) . '</option>';
    echo '</select>';
}
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>OLX — przygotowanie ogłoszenia</title><link rel="stylesheet" href="/admin/style.css"></head>
<body><main class="container"><p><a href="/admin/olx/">Wróć do OLX</a> · <a href="/admin/olx/prepare.php">Kategorie i produkty</a></p>
<h1><?= $product ? 'Ogłoszenie OLX – podgląd' : 'OLX – kategorie i szkice' ?></h1>
<div class="card"><strong>OLX: NIE WYSTAWIONO</strong><p>ETAP C: tylko przygotowanie danych. Publikacja jest wyłączona. Zapis szkicu i mapowania nie zmienia produktów ani ogłoszeń.</p></div>
<?php if ($message!==''): ?><p class="flash" id="olx-preparation-message"><?= e($message) ?></p><?php endif; ?>
<p id="olx-category-summary" data-count="<?= count($categories) ?>">Kategorie OLX: <?= count($categories) ?>. Cache konfiguracji: 24 godziny, strony miast: 7 dni.</p>
<?php if (!$product): ?>
<section class="card"><h2>Kategorie OLX</h2><form method="post"><?php olx_csrf_field(); ?><button name="action" value="refresh" class="btn">Odśwież kategorie i waluty</button></form></section>
<section class="card"><h2>Mapowanie kategorii sklepu</h2><p>Szerokie grupy sklepu mogą wymagać różnych kategorii OLX. Mapowanie jest propozycją do ręcznego zatwierdzenia w każdym szkicu.</p>
<?php foreach ($groups as $group=>$count): ?><form method="post"><p><strong><?= e($group) ?></strong> (<?= $count ?> produktów). Zapisane: <?= e($settings['mappings'][$group]['path'] ?? 'brak') ?></p><?php olx_csrf_field(); ?><input type="hidden" name="sourceCategory" value="<?= e($group) ?>"><?php olx_category_select('mappingCategory',$categories,(int)($settings['mappings'][$group]['category_id']??0)); ?><button name="action" value="map" class="btn btn-secondary">Zatwierdź mapowanie tej grupy</button></form><?php endforeach; ?></section>
<section class="card"><h2>Lokalizacja showroomu</h2><p>Adres z mgoutlet.pl: ul. Przelotowa 16, 55-080 Kębłowice. Aktualna domyślna lokalizacja: <?= e(json_encode($settings['location'],JSON_UNESCAPED_UNICODE)) ?></p>
<form method="post"><?php olx_csrf_field(); ?><label>Nazwa miasta <input name="citySearch" value="<?= e($citySearch) ?>"></label><label>Strona API od 0 <input type="number" min="0" max="150" name="cityPage" value="<?= $cityPage ?>"></label><button name="action" value="cities" class="btn">Pobierz stronę i wyszukaj nazwę</button></form>
<?php foreach ($cityMatches as $match): ?><p><?= e($match['name']) ?> — powiat <?= e($match['county']??'') ?>, gmina <?= e($match['municipality']??'') ?>, ID <?= (int)$match['id'] ?></p><?php endforeach; ?>
<form method="post"><?php olx_csrf_field(); ?><label>City ID potwierdzone w OLX <input type="number" min="1" name="city_id" required value="<?= (int)($settings['location']['city_id']??0) ?>"></label><label>District ID (0 jeżeli brak dzielnic) <input type="number" min="0" name="district_id" value="0"></label><button name="action" value="location" class="btn">Sprawdź w OLX i zatwierdź lokalizację</button></form></section>
<section class="card"><h2>Produkty — tylko przygotowanie</h2><p><?= count($catalog['products']) ?> produktów. Każdy pozostaje niewystawiony.</p>
<?php foreach ($catalog['products'] as $item): ?><p><a href="?productId=<?= e($item['productId']??'') ?>"><?= e($item['name']) ?></a> · <?= e($item['category']??'') ?> · OLX: Nie wystawiono</p><?php endforeach; ?></section>
<?php else: ?>
<section class="card"><h2>Produkt: <?= e($product['name']) ?></h2><p>Kategoria sklepu: <?= e($product['category']??'') ?>. Cena sprzedaży outletPrice: <?= e($product['outletPrice']??'') ?>. Nie używamy catalogPrice jako ceny sprzedaży.</p>
<form method="post"><?php olx_csrf_field(); ?><button name="action" value="suggest" class="btn btn-secondary">Pobierz sugerowaną kategorię OLX</button></form>
<?php foreach ($suggestions as $suggestion): ?><p>Sugerowana kategoria OLX: <?= e(implode(' → ',array_column($suggestion['path']??[],'name')) . ' → ' . ($suggestion['name']??'')) ?> (<?= (int)($suggestion['id']??0) ?>). To wyłącznie podpowiedź.</p><?php endforeach; ?>
<?php if (isset($settings['mappings'][$product['category']??''])): ?><p>Propozycja z mapowania: <?= e($settings['mappings'][$product['category']]['path']) ?>. Wybierz ją w formularzu, aby zatwierdzić.</p><?php endif; ?>
<form method="post"><?php olx_csrf_field(); ?><label>Kategoria OLX <?php olx_category_select('selectedCategoryId',$categories,(int)$draft['selectedCategoryId']); ?></label><button name="action" value="category" class="btn">Zatwierdź kategorię szkicu i pobierz parametry</button></form>
<?php if ($category): ?><p>Wybrana kategoria: <?= e(olx_category_path($category,$categories)) ?> · ID <?= (int)$category['id'] ?> · leaf: <?= !empty($category['is_leaf'])?'tak':'nie' ?></p><?php endif; ?></section>
<form method="post" class="card"><?php olx_csrf_field(); ?>
<div class="form-grid"><div class="field field-full"><label>Tytuł OLX <input name="titleOverride" value="<?= e($draft['titleOverride']) ?>"></label><small>16–150 znaków. Nazwa produktu pozostaje bez zmian.</small></div>
<div class="field"><label>Cena OLX <input name="priceOverride" value="<?= e((string)$draft['priceOverride']) ?>"></label></div><div class="field"><label>Waluta <select name="currency"><?php foreach($currencies as $currency): ?><option value="<?= e($currency['code']) ?>" <?= $draft['currency']===$currency['code']?'selected':'' ?>><?= e($currency['code']) ?></option><?php endforeach; ?></select></label></div>
<div class="field field-full"><label>Opis OLX <textarea name="descriptionOverride" rows="14"><?= e($draft['descriptionOverride']) ?></textarea></label><small>80–9000 znaków. Osobny tekst OLX; nie zmienia opisu produktu.</small></div>
<div class="field"><label>Nazwa kontaktu <input name="contact_name" value="<?= e($draft['contact']['name']) ?>"></label></div><div class="field"><label>Telefon kontaktowy <input name="contact_phone" value="<?= e($draft['contact']['phone']) ?>"></label></div>
<div class="field"><label>City ID <input type="number" min="0" name="city_id" value="<?= (int)($draft['location']['city_id']??($settings['location']['city_id']??0)) ?>"></label><small>Sprawdzany w API. Domyślna lokalizacja jest propozycją; utrwal ją przez zapis szkicu.</small></div>
<div class="field"><label>District ID <select name="district_id"><option value="0">Brak / wybierz</option><?php foreach($districts as $district): ?><option value="<?= (int)$district['id'] ?>" <?= (int)($draft['location']['district_id']??0)===(int)$district['id']?'selected':'' ?>><?= e($district['name']) ?></option><?php endforeach; ?></select></label></div></div>
<?php if ($city): ?><p>Lokalizacja: <?= e($city['name']) ?>, <?= e($city['county']??'') ?>, gmina <?= e($city['municipality']??'') ?>.</p><?php endif; ?>
<h2>Zdjęcia</h2><p>Zdjęć produktu: <?= count(olx_product_images($product)) ?>. Wybranych do OLX: <?= count($draft['selectedImages']) ?>. Limit: <?= $category && isset($category['photos_limit'])?(int)$category['photos_limit']:'wybierz kategorię' ?>. Kolejność jak w produkcie; pierwsze wybrane będzie główne. Nic nie jest usuwane z produktu.</p>
<div class="form-grid"><?php foreach(olx_product_images($product) as $i=>$image): ?><label class="field"><img src="<?= e($image) ?>" alt="Zdjęcie <?= $i+1 ?>" width="160" loading="lazy"><span><input type="checkbox" name="selectedImages[]" value="<?= e($image) ?>" <?= in_array($image,$draft['selectedImages'],true)?'checked':'' ?>> <?= $i===0?'Zdjęcie główne produktu':'Galeria ' . $i ?></span></label><?php endforeach; ?></div>
<h2>Parametry z API kategorii</h2>
<?php foreach($attributes as $attr): $code=(string)($attr['code']??''); $rules=$attr['validation']??[]; if($code==='price'||($rules['type']??'')==='price')continue; $value=$draft['attributeValues'][$code]??''; $multi=!empty($rules['allow_multiple_values']); ?>
<div class="field"><label><?= e($attr['label']??$code) ?> <?= !empty($rules['required'])?'(wymagane)':'(opcjonalne)' ?> <?= e($attr['unit']??'') ?>
<?php if (!empty($attr['values'])): ?><select name="attributeValues[<?= e($code) ?>]<?= $multi?'[]':'' ?>" <?= $multi?'multiple':'' ?>><option value="">Wybierz</option><?php foreach($attr['values'] as $choice): ?><option value="<?= e((string)$choice['code']) ?>" <?= in_array((string)$choice['code'],array_map('strval',is_array($value)?$value:[$value]),true)?'selected':'' ?>><?= e($choice['label']??$choice['code']) ?></option><?php endforeach; ?></select>
<?php elseif(!empty($rules['boolean'])): ?><select name="attributeValues[<?= e($code) ?>]"><option value="">Wybierz</option><?php foreach(['true'=>'Tak','false'=>'Nie'] as $v=>$label): ?><option value="<?= $v ?>" <?= $value===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select>
<?php elseif($multi): ?><?php foreach(array_merge(is_array($value)?$value:($value!==''?[$value]:[]),['','','']) as $part): ?><input name="attributeValues[<?= e($code) ?>][]" value="<?= e((string)$part) ?>"><?php endforeach; ?><small>Jedna wartość w każdym polu; puste pola są pomijane.</small>
<?php else: ?><input name="attributeValues[<?= e($code) ?>]" type="<?= !empty($rules['numeric'])?'number':'text' ?>" step="any" value="<?= e(is_scalar($value)?(string)$value:'') ?>"><?php endif; ?></label>
<small>Ograniczenia OLX: <?= e(json_encode($rules,JSON_UNESCAPED_UNICODE)) ?>. Dane produktu do porównania: stan <?= e($product['condition']??'') ?>; materiał <?= e($product['material']??'') ?>; kolor <?= e($product['color']??'') ?>. Wybór pozostaje ręczny.</small></div>
<?php endforeach; ?>
<p>External ID: <code><?= e($product['productId']) ?></code></p><button class="btn btn-secondary" name="action" value="validate">Sprawdź kompletność i podgląd</button> <button class="btn" name="action" value="save">Zapisz szkic OLX</button><p>Nie ma przycisku publikacji. Walidacja lokalna nie jest potwierdzeniem akceptacji przez OLX ani przeglądu wymagań bezpieczeństwa produktu dla przyszłego etapu publikacji.</p></form>
<section class="card"><h2 id="olx-validation-status">Status walidacji: <?= $errors?'⚠ Brakuje danych':'✔ Gotowe lokalnie' ?></h2><?php if($errors): ?><ul id="olx-validation-errors"><?php foreach($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul><?php endif; ?><h2>PAYLOAD PREVIEW — nie wysłano do OLX</h2><pre id="olx-payload-preview" style="white-space:pre-wrap;overflow-wrap:anywhere"><?= e(json_encode(olx_payload_preview($product,$draft,$attributes),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre></section>
<?php endif; ?></main></body></html>
