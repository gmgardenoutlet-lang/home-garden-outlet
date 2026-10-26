<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';

/** Stage C transport has no method argument and rejects every non-config path. */
function olx_config_get(array $config, string $path, array $query = [], ?callable $transport = null): array
{
    if (!preg_match('~^/(?:categories(?:/suggestion|/[1-9][0-9]*(?:/attributes)?)?|cities(?:/[1-9][0-9]*(?:/districts)?)?|currencies|regions)$~D', $path)) {
        throw new OlxSafeException('ETAP C dopuszcza wyłącznie odczyty konfiguracji OLX.');
    }
    $allowed = $path === '/categories/suggestion' ? ['q'] : ($path === '/cities' ? ['limit', 'offset'] : ($path === '/categories' ? ['parent_id'] : []));
    if (array_diff(array_keys($query), $allowed)) throw new OlxSafeException('Nieprawidłowe parametry odczytu OLX.');
    if ($transport) return $transport($path, $query);
    $token = olx_access_token($config, time());
    $ch = curl_init('https://www.olx.pl/api/partner' . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''));
    curl_setopt_array($ch, [CURLOPT_HTTPGET => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 35, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Version: 2.0', 'Authorization: Bearer ' . $token]]);
    $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if (!is_string($body) || strlen($body) > 12000000 || $status !== 200) {
        throw new OlxSafeException('Odczyt konfiguracji OLX nie powiódł się (HTTP ' . $status . '). Spróbuj ponownie.');
    }
    $data = json_decode($body, true);
    if (!is_array($data)) throw new OlxSafeException('OLX zwrócił nieprawidłową konfigurację.');
    return isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
}

function olx_private_folder(array $config, string $kind): string
{
    olx_validate_config($config);
    if (!in_array($kind, ['cache', 'drafts', 'settings'], true)) throw olx_error('storage');
    $path = $config['dir'] . '/' . $kind;
    if (is_link($path) || (!is_dir($path) && !mkdir($path, 0700))) throw olx_error('storage');
    if (!olx_outside_webroot($path) || !chmod($path, 0700)) throw olx_error('storage');
    return $path;
}

function olx_json_read(string $file): ?array
{
    if (!is_file($file)) return null;
    if (is_link($file) || filesize($file) > 16000000) throw olx_error('storage');
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data)) throw olx_error('storage');
    return $data;
}

function olx_json_write(string $file, array $data): void
{
    if (is_link($file)) throw olx_error('storage');
    $bytes = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $temp = tempnam(dirname($file), '.prepare-');
    if ($temp === false) throw olx_error('storage');
    try {
        if (!chmod($temp, 0600) || file_put_contents($temp, $bytes) !== strlen($bytes) || !rename($temp, $file)) throw olx_error('storage');
    } finally { if (is_file($temp)) unlink($temp); }
}

/** Cache contains only allowlisted public configuration, never a token/account response. */
function olx_cached_get(array $config, string $path, array $query = [], bool $force = false, ?callable $transport = null): array
{
    $file = olx_private_folder($config, 'cache') . '/' . hash('sha256', $path . json_encode($query)) . '.json';
    try { $cached = olx_json_read($file); } catch (OlxSafeException $e) { $cached = null; }
    if (!$force && $cached && ($cached['expires'] ?? 0) > time()) return $cached['data'];
    $data = olx_config_get($config, $path, $query, $transport);
    olx_json_write($file, ['path' => $path, 'fetched_at' => time(), 'expires' => time() + ($path === '/cities' ? 604800 : 86400), 'data' => $data]);
    return $data;
}

function olx_category_index(array $rows): array
{
    $index = [];
    foreach ($rows as $row) {
        if (is_array($row) && (int)($row['id'] ?? 0) > 0 && is_string($row['name'] ?? null)) $index[(int)$row['id']] = $row;
    }
    return $index;
}

function olx_category_path(array $category, array $index): string
{
    $parts = [(string)$category['name']]; $seen = [(int)$category['id'] => true];
    while (($parent = (int)($category['parent_id'] ?? 0)) > 0 && isset($index[$parent]) && !isset($seen[$parent])) {
        $seen[$parent] = true; $category = $index[$parent]; array_unshift($parts, $category['name']);
    }
    return implode(' → ', $parts);
}

function olx_categories(array $config, bool $force = false): array
{
    $rows = olx_cached_get($config, '/categories', [], $force);
    $index = olx_category_index($rows);
    if (!$index) throw new OlxSafeException('Brak kategorii OLX.');
    $visited = [];
    for ($pass=0; $pass<20; $pass++) {
        $parents = array_fill_keys(array_map('intval', array_column($index,'parent_id')),true); $changed=false;
        foreach ($index as $row) if (empty($row['is_leaf']) && !isset($parents[(int)$row['id']]) && !isset($visited[(int)$row['id']])) {
            $visited[(int)$row['id']] = true;
            $children = olx_category_index(olx_cached_get($config,'/categories',['parent_id'=>(int)$row['id']],$force));
            foreach($children as $key=>$child) if (!isset($index[$key])) { $index[$key]=$child; $changed=true; }
        }
        if (!$changed) break;
    }
    return $index;
}

function olx_cities_page(array $config, int $page, bool $force = false): array
{
    if ($page < 0 || $page > 150) throw new OlxSafeException('Nieprawidłowa strona miast.');
    return olx_cached_get($config, '/cities', ['limit' => 1000, 'offset' => $page * 1000], $force);
}

function olx_find_product(array $catalog, string $id): array
{
    if (!preg_match('/^prd_[a-f0-9]{32}$/D', $id)) throw new OlxSafeException('Nieprawidłowy productId.');
    $found = array_values(array_filter($catalog['products'], static fn($p) => ($p['productId'] ?? '') === $id));
    if (count($found) !== 1) throw new OlxSafeException('Nie znaleziono jednoznacznego produktu.');
    return $found[0];
}

function olx_product_images(array $product): array
{
    $images = [];
    foreach (array_merge([$product['image'] ?? ''], is_array($product['gallery'] ?? null) ? $product['gallery'] : []) as $item) {
        $url = is_array($item) ? ($item['image'] ?? $item['url'] ?? '') : $item;
        if (!is_string($url)) continue;
        if (str_starts_with($url, '/uploads/')) $url = 'https://mgoutlet.pl' . $url;
        if (preg_match('~^https://mgoutlet\.pl/uploads/[a-zA-Z0-9_./%-]+\.(?:webp|jpe?g|png)$~iD', $url) && !str_contains($url, '..')) $images[] = $url;
    }
    return array_values(array_unique($images));
}

function olx_sale_price(string $value): ?float
{
    $value = str_replace(["\u{00a0}", ' '], '', trim($value));
    $value = preg_replace('/(?:zł|PLN)$/ui', '', $value);
    $value = str_replace(',', '.', (string)$value);
    return preg_match('/^\d+(?:\.\d{1,2})?$/D', $value) ? (float)$value : null;
}

function olx_default_draft(array $product): array
{
    return ['productId' => $product['productId'], 'selectedCategoryId' => 0,
        'titleOverride' => (string)$product['name'],
        'descriptionOverride' => trim(strip_tags((string)(($product['longDescription'] ?? '') ?: ($product['description'] ?? '')))),
        'priceOverride' => olx_sale_price((string)($product['outletPrice'] ?? '')), 'currency' => (string)($product['currency'] ?? 'PLN'),
        // Photos need explicit administrator selection, not silent truncation.
        'selectedImages' => [], 'attributeValues' => [], 'location' => [],
        'contact' => ['name' => 'Home & Garden Outlet', 'phone' => '+48 577 210 777'], 'preparedStatus' => 'incomplete'];
}

function olx_draft_file(array $config, string $id): string
{
    if (!preg_match('/^prd_[a-f0-9]{32}$/D', $id)) throw new OlxSafeException('Nieprawidłowy productId.');
    return olx_private_folder($config, 'drafts') . '/' . $id . '.json';
}

function olx_clean_draft(array $product, array $input): array
{
    $draft = olx_default_draft($product);
    foreach (['titleOverride' => 2000, 'descriptionOverride' => 20000, 'currency' => 3] as $field => $max) {
        if (!is_string($input[$field] ?? null) || strlen($input[$field]) > $max * 4) throw new OlxSafeException('Nieprawidłowe pole szkicu: ' . $field);
        $draft[$field] = trim($input[$field]);
    }
    $draft['priceOverride'] = olx_sale_price(is_scalar($input['priceOverride'] ?? null) ? (string)$input['priceOverride'] : '');
    $draft['selectedCategoryId'] = is_scalar($input['selectedCategoryId'] ?? null) ? (int)$input['selectedCategoryId'] : 0;
    $images = $input['selectedImages'] ?? [];
    if (!is_array($images) || count($images) > 100 || count(array_filter($images,'is_string')) !== count($images) || array_diff($images, olx_product_images($product))) throw new OlxSafeException('Nieprawidłowy wybór zdjęć produktu.');
    $draft['selectedImages'] = array_values(array_unique($images));
    $attributes = $input['attributeValues'] ?? [];
    if (!is_array($attributes) || count($attributes) > 150) throw new OlxSafeException('Nieprawidłowe parametry.');
    foreach ($attributes as $code => $value) {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', (string)$code)) throw new OlxSafeException('Nieprawidłowy kod parametru.');
        foreach (is_array($value) ? $value : [$value] as $v) if (!is_scalar($v) || strlen((string)$v) > 5000) throw new OlxSafeException('Nieprawidłowa wartość parametru.');
        if (is_array($value)) $attributes[$code] = array_values(array_filter($value, static fn($v) => $v !== ''));
    }
    $draft['attributeValues'] = $attributes;
    $draft['location'] = ['city_id' => (int)($input['city_id'] ?? 0), 'district_id' => (int)($input['district_id'] ?? 0)];
    foreach (['name', 'phone'] as $field) {
        if (!is_string($input['contact_' . $field] ?? null) || strlen($input['contact_' . $field]) > 200) throw new OlxSafeException('Nieprawidłowy kontakt.');
        $draft['contact'][$field] = trim($input['contact_' . $field]);
    }
    return $draft;
}

function olx_text_length(string $text): int { return preg_match_all('/./us', $text, $unused) ?: 0; }

function olx_validate_draft(array $product, array $draft, ?array $category, array $attributes, array $city = [], array $districts = [], array $currencies = []): array
{
    $errors = [];
    foreach (['titleOverride' => ['Tytuł', 16, 150], 'descriptionOverride' => ['Opis', 80, 9000]] as $field => [$label, $min, $max]) {
        $text = (string)$draft[$field]; $len = olx_text_length($text);
        if ($len < $min || $len > $max) $errors[] = "$label musi mieć {$min}–{$max} znaków (obecnie $len).";
        if (preg_match('/([!?.\-,=+#%&@*_><:()|])\1\1/u', $text)) $errors[] = "$label zawiera trzykrotnie powtórzoną interpunkcję.";
        $letters = preg_match_all('/\p{L}/u', $text); $upper = preg_match_all('/\p{Lu}/u', $text);
        if ($letters && $upper / $letters > .5) $errors[] = "$label zawiera ponad 50% wielkich liter.";
        if (preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}|(?:\+?48[ .-]*)?(?:\d[ .-]*){9}(?!\d)/ui', $text)) $errors[] = "$label zawiera e-mail lub możliwy numer telefonu — sprawdź tekst.";
        if ($text !== strip_tags($text)) $errors[] = "$label musi być zwykłym tekstem.";
    }
    if (!$category || empty($category['is_leaf']) || (int)$category['id'] !== (int)$draft['selectedCategoryId']) $errors[] = 'Wybierz i zatwierdź końcową kategorię OLX.';
    if (!is_numeric($draft['priceOverride']) || (float)$draft['priceOverride'] <= 0) $errors[] = 'Podaj dodatnią cenę sprzedaży OLX.';
    $codes = array_column($currencies, 'code');
    if (!$codes || !in_array($draft['currency'], $codes, true)) $errors[] = 'Waluta nie została potwierdzona przez OLX.';
    if (!$city || (int)($city['id'] ?? 0) !== (int)($draft['location']['city_id'] ?? 0)) $errors[] = 'Nie wybrano potwierdzonego miasta OLX.';
    if ($districts && !in_array((int)($draft['location']['district_id'] ?? 0), array_map('intval', array_column($districts, 'id')), true)) $errors[] = 'Wybierz dzielnicę tego miasta.';
    if (!$districts && !empty($draft['location']['district_id'])) $errors[] = 'Ta lokalizacja nie ma potwierdzonej dzielnicy.';
    if (trim($draft['contact']['name']) === '') $errors[] = 'Brak nazwy kontaktu.';
    if (!$draft['selectedImages']) $errors[] = 'Wybierz zdjęcia do OLX.';
    if (array_diff($draft['selectedImages'], olx_product_images($product))) $errors[] = 'Wybrane zdjęcie nie należy do produktu.';
    if ($category && (!isset($category['photos_limit']) || count($draft['selectedImages']) > (int)$category['photos_limit'])) $errors[] = 'Liczba zdjęć przekracza limit OLX lub limit nie jest znany.';
    if (!preg_match('/^prd_[a-f0-9]{32}$/D', (string)$product['productId']) || $draft['productId'] !== $product['productId']) $errors[] = 'Nieprawidłowy trwały external_id.';
    $known = [];
    foreach ($attributes as $attr) {
        $code = (string)($attr['code'] ?? ''); if ($code === '') { $errors[] = 'Nierozpoznana definicja atrybutu OLX.'; continue; }
        $known[] = $code; $rules = $attr['validation'] ?? []; $label = (string)($attr['label'] ?? $code);
        if ($code === 'price' || ($rules['type'] ?? '') === 'price') {
            $value = $draft['priceOverride'];
        } else { $value = $draft['attributeValues'][$code] ?? ''; }
        $missing = $value === '' || $value === null || $value === [];
        if (!empty($rules['required']) && $missing) $errors[] = 'Brakuje parametru: ' . $label . '.';
        if ($missing) continue;
        if (!in_array($rules['type'] ?? 'attribute',['attribute','price','salary','boolean','text','number','integer'],true)) $errors[] = 'Nieobsługiwany typ parametru: ' . $label . '.';
        if (($rules['type'] ?? 'attribute') === 'salary' || $code === 'delivery') { $errors[] = 'Parametr wymaga osobnej obsługi: ' . $label . '.'; continue; }
        $unsupported = array_diff(array_keys($rules), ['type','required','numeric','min','max','allow_multiple_values','boolean','min_length','max_length','pattern']);
        if ($unsupported) $errors[] = 'Nieobsługiwane ograniczenia ' . $label . ': ' . implode(', ', $unsupported) . '.';
        if (is_array($value) && empty($rules['allow_multiple_values'])) $errors[] = $label . ': dozwolona tylko jedna wartość.';
        $enum = array_map('strval', array_column($attr['values'] ?? [], 'code'));
        foreach (is_array($value) ? $value : [$value] as $v) {
            if ($enum && !in_array((string)$v, $enum, true)) $errors[] = $label . ': wybierz wartość ze słownika OLX.';
            if ((!empty($rules['boolean']) || ($rules['type']??'')==='boolean') && !in_array((string)$v, ['true','false','0','1'], true)) $errors[] = $label . ': wymagana wartość logiczna.';
            if (!empty($rules['numeric']) || in_array($rules['type']??'', ['number','integer'],true)) {
                if (!is_numeric($v)) $errors[] = $label . ': wymagana liczba.';
                else foreach (['min' => -1, 'max' => 1] as $bound => $direction) {
                    if (isset($rules[$bound]) && is_numeric($rules[$bound]) && ((float)$v - (float)$rules[$bound]) * $direction > 0) $errors[] = $label . ': przekroczono ' . $bound . ' ' . $rules[$bound] . '.';
                }
            } else {
                foreach (['min_length' => -1, 'max_length' => 1, 'min'=>-1, 'max'=>1] as $bound => $direction) if (isset($rules[$bound]) && is_numeric($rules[$bound]) && (olx_text_length((string)$v) - (int)$rules[$bound]) * $direction > 0) $errors[] = $label . ': nieprawidłowa długość.';
            }
            if (!empty($rules['pattern']) && @preg_match('~' . str_replace('~','\~',(string)$rules['pattern']) . '~u', (string)$v) !== 1) $errors[] = $label . ': nieprawidłowy format.';
        }
    }
    if (array_diff(array_keys($draft['attributeValues']), $known)) $errors[] = 'Szkic zawiera parametry z innej kategorii.';
    return array_values(array_unique($errors));
}

/** Pure payload builder. There is deliberately no sender for this payload. */
function olx_payload_preview(array $product, array $draft, array $definitions): array
{
    $attrs = [];
    foreach ($definitions as $definition) {
        $code = (string)($definition['code'] ?? '');
        if ($code === 'price' || ($definition['validation']['type'] ?? '') === 'price') continue;
        $value = $draft['attributeValues'][$code] ?? '';
        if ($value === '' || $value === []) continue;
        $attrs[] = ['code' => $code] + (is_array($value) ? ['values' => array_values(array_map('strval', $value))] : ['value' => (string)$value]);
    }
    $location = ['city_id' => (int)($draft['location']['city_id'] ?? 0)];
    if (!empty($draft['location']['district_id'])) $location['district_id'] = (int)$draft['location']['district_id'];
    return ['title' => $draft['titleOverride'], 'description' => $draft['descriptionOverride'], 'category_id' => $draft['selectedCategoryId'],
        'advertiser_type' => 'business', 'external_id' => $product['productId'],
        'external_url' => 'https://mgoutlet.pl/produkt/' . rawurlencode((string)$product['slug']) . '/',
        'contact' => $draft['contact'], 'location' => $location, 'price' => ['value' => $draft['priceOverride'], 'currency' => $draft['currency'], 'negotiable' => false],
        'images' => array_map(static fn($url) => ['url' => $url], $draft['selectedImages']), 'attributes' => $attrs];
}
