<?php
declare(strict_types=1);

// Existing OAuth plus the per-product sales workspace.
final class AllegroSafeException extends RuntimeException
{
    public function __construct(public readonly string $kind)
    {
        $messages = [
            'config' => 'Konfiguracja Allegro jest niekompletna lub niebezpieczna.',
            'disabled' => 'OAuth Allegro jest wyłączony. Wymagana konfiguracja aplikacji Device i osobna zgoda na włączenie.',
            'storage' => 'Nie można bezpiecznie odczytać lub zapisać prywatnych danych Allegro.',
            'state' => 'Nieprawidłowe lub wygasłe połączenie Allegro. Rozpocznij je ponownie.',
            'session' => 'Połączenie nie należy do tej sesji administratora lub zostało wykorzystane.',
            'denied' => 'Zgoda Allegro nie została udzielona. Rozpocznij połączenie ponownie.',
            'auth' => 'Allegro odrzuciło uwierzytelnienie. Połącz konto ponownie.',
            'network' => 'Nie udało się potwierdzić odpowiedzi Allegro. Nie ponawiamy automatycznie operacji.',
            'refresh' => 'Nie udało się bezpiecznie odnowić tokena. Połącz konto ponownie.',
            'policy' => 'Żądanie poza dozwolonym zakresem pilota Allegro.',
            'identity' => 'Nie można potwierdzić tożsamości połączonego konta. Połącz je ponownie.',
        ];
        parent::__construct($messages[$kind] ?? $messages['config']);
    }
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/oauth.php';


final class AllegroPilotWriteException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message) {parent::__construct($message);}
}

/** Per-product offers; credentials and bindings stay in the existing vault. */
function allegro_pilot_length(string $value): int
{
    $n=preg_match_all('/./us',$value);
    allegro_pilot_need($n!==false,'Nieprawidłowy tekst UTF-8.');return $n;
}
function allegro_pilot_need(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
function allegro_pilot_id(mixed $id): string
{
    allegro_pilot_need(is_string($id) && preg_match('/^[A-Za-z0-9-]{1,80}$/D', $id) === 1, 'Nieprawidłowy identyfikator Allegro.');
    return $id;
}
function allegro_pilot_api(array $config, string $method, string $path, string $access, array $body = [], ?callable $transport = null): array
{
    if (!allegro_pilot_path($method, $path)) throw new AllegroSafeException('policy');
    allegro_validate_config($config, true);
    $request = ['method'=>$method, 'url'=>allegro_hosts($config['environment'])['api'].$path,
        'headers'=>['Accept: application/vnd.allegro.public.v1+json','Accept-Language: pl-PL','Authorization: Bearer '.$access], 'body'=>''];
    if ($method !== 'GET') {
        $request['headers'][]='Content-Type: application/vnd.allegro.public.v1+json';
        $request['body']=json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
    try {$response=($transport ?? 'allegro_curl')($request);}
    catch (Throwable $e) {
        if ($method==='GET') {
            $problem=$e instanceof AllegroPilotResponseLimitException ? 'przekroczony limit odpowiedzi (1048576 bajtów)' : 'błąd cURL/transportu';
            throw new RuntimeException('Allegro GET '.$path.' — '.$problem.'.');
        }
        throw $e;
    }
    $status=$response['status'] ?? 0;
    if ($method==='GET') {
        $error=static fn($problem)=>new RuntimeException('Allegro GET '.$path.' — '.$problem.'.');
        if (!is_string($response['body'] ?? null)) throw $error('błąd cURL/transportu: brak odpowiedzi');
        if (strlen($response['body'])>allegro_pilot_response_limit($method,$path)) throw $error('przekroczony limit odpowiedzi (1048576 bajtów)');
        if (!in_array($status,[200,201,202,303],true)) throw $error('HTTP '.(int)$status);
        if (preg_match('~^/sale/product-offers/([0-9]{1,32})/operations/[A-Za-z0-9-]{1,80}$~D',$path,$operation)) {
            if (!in_array($status,[202,303],true)) throw $error('nieoczekiwany status operacji HTTP '.(int)$status);
            $headers=$response['headers'] ?? [];
            if (!is_array($headers)) throw $error('nieprawidłowe nagłówki operacji');
            if ($status===303) {
                $location=$headers['location'] ?? null;
                if (!is_string($location)) throw $error('brak prawidłowego nagłówka Location');
                allegro_pilot_location($config,$location,$operation[1],false);
            } elseif (isset($headers['location'])) {
                if (!is_string($headers['location'])) throw $error('nieprawidłowy nagłówek Location');
                $pending=allegro_pilot_location($config,$headers['location'],$operation[1],true);
                if ($pending!==$path) throw $error('Location wskazuje inną operację');
            }
            // Operation completion is conveyed by status and Location, not a JSON document.
            return ['status'=>$status,'data'=>[],'headers'=>$headers];
        }
        if ($status!==200) throw $error('nieoczekiwany status zasobu HTTP '.(int)$status);
        try {$data=json_decode($response['body'],true,32,JSON_THROW_ON_ERROR);}
        catch (Throwable $e) {throw $error('niepoprawny JSON');}
        if (!is_array($data)) throw $error('niepoprawny JSON');
        return ['status'=>$status,'data'=>$data,'headers'=>$response['headers'] ?? []];
    }
    if (!in_array($status,[200,201,202,303],true) || !is_string($response['body'] ?? null) || strlen($response['body'])>131072) {
        $codes=[];
        if (in_array($status,[400,422],true) && is_string($response['body'] ?? null)) {
            $errors=json_decode($response['body'],true);
            foreach (array_slice(is_array($errors['errors'] ?? null)?$errors['errors']:[],0,5) as $error) {
                $code=$error['code'] ?? ''; $path=$error['path'] ?? ($error['details']['path'] ?? '');
                if (is_string($code) && preg_match('/^[A-Za-z0-9_.-]{1,100}$/D',$code)) {
                    $codes[]=$code.(is_string($path) && preg_match('/^[A-Za-z0-9_.\[\]-]{1,150}$/D',$path)?' ['.$path.']':'');
                }
            }
        }
        throw new AllegroPilotWriteException((int)$status,'Allegro odrzuciło żądanie lub wynik jest niepewny (HTTP '.(int)$status.'). '.implode('; ',$codes).' Nie ponawiaj tworzenia; sprawdź status.');
    }
    try { $data=$response['body']==='' ? [] : json_decode($response['body'],true,32,JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { throw new AllegroSafeException('network'); }
    if (!is_array($data)) throw new AllegroSafeException('network');
    return ['status'=>$status,'data'=>$data,'headers'=>$response['headers'] ?? []];
}
function allegro_pilot_access(array $config, array &$vault, string $key, ?callable $transport): string
{
    allegro_pilot_need(allegro_scope_matches((string)($vault['tokens']['scope'] ?? '')), 'Brakuje uprawnień sprzedaży. Potwierdź ponowną autoryzację Device Flow; obecne połączenie zostaje zachowane.');
    return allegro_refresh_locked($config,$vault,$key,time(),$transport);
}
function allegro_pilot_products(array $catalog): array
{
    $out=[]; $seen=[];
    foreach ($catalog['products'] ?? [] as $p) {
        $id=$p['productId'] ?? '';
        if (is_string($id) && isset($seen[$id])) throw new RuntimeException('Powtórzony productId w katalogu.');
        if (is_string($id)) $seen[$id]=true;
        if (is_string($id) && product_id_valid($id) && catalog_is_public($p) && catalog_is_active_figure_shop_product($p)) $out[$id]=$p;
    }
    return $out;
}
function allegro_pilot_fingerprint(array $product): string
{
    return hash('sha256',json_encode($product,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}
/** Browse only children returned by the API; posted IDs never define a path. */
function allegro_pilot_category(callable $get, array $state = [], string $selected = ''): array
{
    $path=$state['path'] ?? [];
    $parent=$path===[] ? null : $path[count($path)-1]['id'];
    $read=static function(?string $parent)use($get): array {
        $data=$get($parent===null ? '/sale/categories' : '/sale/categories?parent.id='.$parent);
        allegro_pilot_need(isset($data['categories']) && is_array($data['categories']),'Nie można potwierdzić drzewa kategorii Allegro.');
        $rows=[];$ids=[];
        foreach ($data['categories'] as $row) {
            allegro_pilot_need(is_array($row) && is_string($row['id'] ?? null)
                && preg_match('~^'.ALLEGRO_PILOT_CATEGORY_ID_PATTERN.'$~D',$row['id'])===1 && is_string($row['name'] ?? null)
                && trim($row['name'])!=='' && is_bool($row['leaf'] ?? null) && !isset($ids[$row['id']]),'API nie potwierdziło id, name i leaf kategorii.');
            $ids[$row['id']]=true;$rows[]=['id'=>$row['id'],'name'=>$row['name'],'leaf'=>$row['leaf']];
        }
        return $rows;
    };
    if ($state===[]) {
        allegro_pilot_need($selected==='','Rozpocznij wybór kategorii od listy głównej.');
        return ['path'=>[],'options'=>$read(null),'leaf'=>false];
    }
    allegro_pilot_need(empty($state['leaf']) && count($path)<32,'Rozpocznij wybór kategorii ponownie.');
    allegro_pilot_need(in_array($selected,array_column($state['options'] ?? [],'id'),true),'Wybierz kategorię z wyświetlonej listy.');
    $rows=$read($parent);
    $matches=array_values(array_filter($rows,static fn($row)=>$row['id']===$selected));
    allegro_pilot_need(count($matches)===1,'Wybranej kategorii nie ma już w drzewie Allegro. Rozpocznij wybór ponownie.');
    $cat=$matches[0];$path[]=$cat;
    allegro_pilot_need(count(array_unique(array_column($path,'id')))===count($path),'Nieprawidłowy cykl w drzewie kategorii Allegro.');
    return ['path'=>$path,'options'=>$cat['leaf'] ? [] : $read($cat['id']),'leaf'=>$cat['leaf']];
}
function allegro_pilot_metadata(array $config, array $state = [], string $selected = '', ?callable $transport = null): array
{
    return allegro_locked($config,static function($v,$k)use($config,$state,$selected,$transport){
        $token=allegro_pilot_access($config,$v,$k,$transport);
        allegro_pilot_need($state===[] || ($state['seller'] ?? '')===($v['account']['id'] ?? null),'Połączone konto zmieniło się. Rozpocznij wybór ponownie.');
        $get=static fn($p)=>allegro_pilot_api($config,'GET',$p,$token,[],$transport)['data'];
        $navigation=allegro_pilot_category($get,$state,$selected);
        $navigation+=['seller'=>$v['account']['id'],'at'=>time(),'environment'=>$config['environment']];
        if (!$navigation['leaf']) return $navigation;
        $cat=$navigation['path'][count($navigation['path'])-1];
        $category=$cat['id'];
        $params=$get('/sale/categories/'.$category.'/parameters');
        allegro_pilot_need(isset($params['parameters']) && is_array($params['parameters']),'Brak parametrów kategorii.');
        $meta=['category'=>$cat,'categoryPath'=>$navigation['path'],'parameters'=>$params['parameters'],'seller'=>$v['account']['id'],'at'=>time(),
            'environment'=>$config['environment']];
        foreach (['shippingRates'=>'/sale/shipping-rates','returnPolicies'=>'/after-sales-service-conditions/return-policies',
            'impliedWarranties'=>'/after-sales-service-conditions/implied-warranties','responsibleProducers'=>'/sale/responsible-producers',
            'responsiblePersons'=>'/sale/responsible-persons'] as $field=>$path) {
            $data=$get($path);$meta[$field]=$data[$field] ?? [];
            allegro_pilot_need(is_array($meta[$field]),'Nieprawidłowe ustawienia konta Allegro.');
        }
        return $meta;
    });
}
function allegro_pilot_selected(array $meta, string $list, mixed $id, bool $optional = false): ?string
{
    if ($optional && $id==='') return null;
    $id=allegro_pilot_id($id);
    allegro_pilot_need(in_array($id,array_column($meta[$list] ?? [],'id'),true),'Wybierz istniejące ustawienie konta: '.$list.'.');
    return $id;
}
/** Resolve only catalogue images beneath uploads, with bounded binary reads and no symlink escape. */
function allegro_pilot_image_file(string $image): array
{
    $url=parse_url($image);$path=$url['path'] ?? '';
    allegro_pilot_need(is_array($url) && (!isset($url['scheme']) || $url['scheme']==='https')
        && (!isset($url['host']) || $url['host']==='mgoutlet.pl') && !isset($url['user']) && !isset($url['pass'])
        && !isset($url['port']) && !isset($url['query']) && !isset($url['fragment'])
        && str_starts_with($path,'/uploads/') && !str_contains(rawurldecode($path),'..')
        && !str_contains(rawurldecode($path),chr(92)) && !str_contains(rawurldecode($path),"\0"),'Zdjęcie musi pochodzić z lokalnego katalogu uploads wybranej figury.');
    $base=realpath(SITE_ROOT.'/uploads');$file=realpath(SITE_ROOT.rawurldecode($path));
    allegro_pilot_need($base!==false && $file!==false && is_file($file)
        && str_starts_with($file,$base.DIRECTORY_SEPARATOR),'Nie można odczytać lokalnego zdjęcia figury.');
    $size=@filesize($file);
    // Local memory cap, independent of Allegro's 26 Mpx resolution limit.
    allegro_pilot_need(is_int($size) && $size>0 && $size<=10485760,'Zdjęcie przekracza lokalny limit 10 MiB lub jest puste.');
    $bytes=@file_get_contents($file,false,null,0,10485761);
    allegro_pilot_need(is_string($bytes) && strlen($bytes)===$size,'Nie można bezpiecznie odczytać całego zdjęcia.');
    $info=@getimagesizefromstring($bytes);$mime=$info['mime'] ?? '';
    $extensions=['image/jpeg'=>['jpg','jpeg'],'image/png'=>['png'],'image/webp'=>['webp']];
    allegro_pilot_need(isset($extensions[$mime]) && in_array(strtolower(pathinfo($file,PATHINFO_EXTENSION)),$extensions[$mime],true),'Nieobsługiwane lub niezgodne zdjęcie — użyj JPEG, PNG lub WebP.');
    allegro_pilot_need(max($info[0],$info[1])>=500 && $info[0]*$info[1]<=26000000,'Zdjęcie wymaga dłuższego boku co najmniej 500 px i rozdzielczości do 26 Mpx.');
    $public='https://mgoutlet.pl/'.implode('/',array_map('rawurlencode',explode('/',ltrim(rawurldecode($path),'/'))));
    return ['bytes'=>$bytes,'mime'=>$mime,'url'=>$public];
}
function allegro_pilot_image_upload(array $config, array $image, string $access, ?callable $transport): string
{
    allegro_validate_config($config,true);
    $url=($config['environment']==='PROD'?'https://upload.allegro.pl':'https://upload.allegro.pl.allegrosandbox.pl').'/sale/images';
    allegro_http_policy('POST',$url);
    $request=['method'=>'POST','url'=>$url,'headers'=>['Accept: application/vnd.allegro.public.v1+json',
        'Accept-Language: pl-PL','Content-Type: '.$image['mime'],'Authorization: Bearer '.$access],'body'=>$image['bytes']];
    try {$response=($transport ?? 'allegro_curl')($request);}
    catch (Throwable $e) {throw new RuntimeException('Upload zdjęcia Allegro: błąd transportu. Szkic nie został wysłany.');}
    allegro_pilot_need(($response['status'] ?? 0)===201,'Upload zdjęcia Allegro — HTTP '.(int)($response['status'] ?? 0).'. Szkic nie został wysłany.');
    allegro_pilot_need(is_string($response['body'] ?? null) && strlen($response['body'])<=131072,'Upload zdjęcia: nieprawidłowa odpowiedź. Szkic nie został wysłany.');
    try {$data=json_decode($response['body'],true,16,JSON_THROW_ON_ERROR);}
    catch (Throwable $e) {throw new RuntimeException('Upload zdjęcia: niepoprawny JSON. Szkic nie został wysłany.');}
    $location=is_array($data)?($data['location'] ?? ''):'';
    allegro_pilot_need(is_string($location) && strlen($location)<=2048
        && preg_match('~^https://a\.allegroimg\.(?:com|allegrosandbox\.pl)/original/[A-Za-z0-9/-]+$~D',$location)===1,'Upload zdjęcia: niepotwierdzony adres Allegro. Szkic nie został wysłany.');
    return $location;
}
function allegro_pilot_uploaded_payload(array $config, array $payload, array $product, string $access, ?callable $transport): array
{
    $sources=catalog_images($product);
    allegro_pilot_need(count($sources)>=1 && count($sources)<=16,'Wymagane od 1 do 16 zdjęć wybranej figury.');
    $urls=[];
    // Validate every source before the first upload; paths cannot come from POST.
    foreach ($sources as $source) {$image=allegro_pilot_image_file($source);$urls[]=$image['url'];}
    allegro_pilot_need(($payload['images'] ?? [])===$urls && ($payload['productSet'][0]['product']['images'] ?? [])===$urls,'Zdjęcia podglądu nie odpowiadają wybranemu produktowi. Przygotuj nowy podgląd.');
    $uploaded=[];
    foreach ($sources as $source) $uploaded[]=allegro_pilot_image_upload($config,allegro_pilot_image_file($source),$access,$transport);
    $payload['images']=$uploaded;$payload['productSet'][0]['product']['images']=$uploaded;
    return $payload;
}
function allegro_pilot_gpsr_template(): string
{
    return "Informacje dotyczące bezpieczeństwa produktu\n\nProdukt przeznaczony do celów dekoracyjnych. Nie jest zabawką.\n\nFigurę należy ustawiać na stabilnym, równym podłożu o odpowiedniej nośności.\n\nNależy zachować ostrożność podczas ustawiania i przenoszenia figury, szczególnie w przypadku dużych i ciężkich modeli.\n\nNie należy podnosić ani przenosić figury za wystające elementy dekoracyjne, takie jak skrzydła, dłonie czy inne delikatne części.\n\nNależy zabezpieczyć figurę przed przypadkowym przewróceniem, szczególnie w miejscach dostępnych dla dzieci.\n\nNie należy wspinać się na figurę, siadać na niej ani wykorzystywać jej jako podpory.\n\nNależy unikać silnych uderzeń mechanicznych, które mogą doprowadzić do pęknięć lub odłamania fragmentów rzeźby.\n\nW przypadku uszkodzeń mogących powodować zagrożenie należy zaprzestać użytkowania produktu do czasu usunięcia zagrożenia.";
}
function allegro_pilot_handling_note(): string
{
    return 'Ze względu na konieczność odpowiedniego przygotowania i zabezpieczenia figur do transportu czas realizacji zamówienia i nadania przesyłki wynosi od 2 do 7 dni roboczych od potwierdzenia płatności.';
}
function allegro_pilot_receipt_note(): string
{
    return "Ze względu na wagę i charakter figur betonowych prosimy, jeśli to możliwe, o sprawdzenie przesyłki przy kurierze.\n\nW razie widocznych uszkodzeń zalecamy wykonanie zdjęć i sporządzenie protokołu szkody.\n\nJeśli uszkodzenie zauważysz po odbiorze, zgłoś je przez Allegro i dołącz zdjęcia produktu oraz opakowania.\n\nBrak sprawdzenia przesyłki przy kurierze lub protokołu szkody nie ogranicza prawa do reklamacji.";
}
function allegro_pilot_painting_note(): string
{
    return "Ważna informacja o ręcznym malowaniu\n\nFigury ogrodowe są ręcznie malowane, dlatego poszczególne egzemplarze mogą nieznacznie różnić się od siebie odcieniem kolorów, intensywnością barw oraz rozmieszczeniem patyny i dekoracyjnych przecierek.\n\nZdjęcia przedstawiają przykładowe wykończenie danego modelu. Każda figura zachowuje charakter i ogólną kolorystykę prezentowanego produktu.\n\nDrobne różnice wynikające z ręcznego malowania są naturalną cechą tego rodzaju dekoracji i nie stanowią same w sobie wady wykonania.";
}
function allegro_pilot_description(array $product): string
{
    $plain=static fn($text)=>trim(html_entity_decode(strip_tags(preg_replace('~</?(?:p|div|br|li|h[1-6])\b[^>]*>~i',"\n",(string)$text)),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    $short=$plain($product['description'] ?? '');$long=$plain($product['longDescription'] ?? '');
    $text=$long!=='' && ($short==='' || str_contains($long,$short)) ? $long : trim($short."\n\n".$long);
    // The catalogue is never written; append the standard section only once.
    if (!str_contains($text,'Ważna informacja o ręcznym malowaniu')) $text=trim($text."\n\n".allegro_pilot_painting_note());
    return $text;
}
function allegro_pilot_description_html(string $text): string
{
    $parts=preg_split('/\R\s*\R/u',trim($text));$html='';
    foreach ($parts as $part) {
        $tag=trim($part)==='Ważna informacja o ręcznym malowaniu' ? 'h2' : 'p';
        $html.='<'.$tag.'>'.htmlspecialchars(trim($part),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</'.$tag.'>';
    }
    return $html;
}
function allegro_pilot_parameter_values(mixed $raw): array
{
    $rows=is_array($raw) ? $raw : [$raw];
    allegro_pilot_need(count($rows)<=100,'Zbyt wiele wartości parametru.');$out=[];
    foreach ($rows as $value) {
        allegro_pilot_need(is_string($value) && strlen($value)<=2000,'Nieprawidłowa wartość parametru.');
        if (trim($value)!=='') $out[]=trim($value);
    }
    return $out;
}
function allegro_pilot_parameter_required(array $p, array $values): bool
{
    $required=!empty($p['required']) || !empty($p['requiredForProduct']);
    if (!empty($p['requiredIf'])) {
        $condition=true;
        foreach ($p['requiredIf']['parametersWithValue'] ?? [] as $c) $condition=$condition && array_intersect(allegro_pilot_parameter_values($values[$c['id']] ?? ''),$c['oneOfValueIds'] ?? [])!==[];
        foreach ($p['requiredIf']['parametersWithoutValue'] ?? [] as $c) $condition=$condition && allegro_pilot_parameter_values($values[$c['id']] ?? '')===[];
        $required=$required || $condition;
    }
    return $required;
}
function allegro_pilot_parameter_row(array $p, mixed $raw, array $values): ?array
{
    $list=allegro_pilot_parameter_values($raw);$name=(string)$p['name'];$r=$p['restrictions'] ?? [];
    allegro_pilot_need(!allegro_pilot_parameter_required($p,$values) || $list!==[],'Brak wymaganego parametru: '.$name.'. Sprawdź wymagania API; nie wpisuj fikcyjnych numerów.');
    if ($list===[]) return null;
    $type=$p['type'];$row=['id'=>(string)$p['id']];
    if ($type==='dictionary') {
        allegro_pilot_need(count($list)===count(array_unique($list)) && (count($list)===1 || !empty($r['multipleChoices'])),'Niedozwolona liczba wartości: '.$name);
        foreach ($list as $value) {
            $matches=array_values(array_filter($p['dictionary'] ?? [],static fn($d)=>($d['id'] ?? null)===$value));
            allegro_pilot_need(count($matches)===1,'Wybierz wartość ze słownika: '.$name);
            $depends=$p['options']['dependsOnParameterId'] ?? null;$allowed=$matches[0]['dependsOnValueIds'] ?? [];
            allegro_pilot_need($allowed===[] || (is_string($depends) && array_intersect(allegro_pilot_parameter_values($values[$depends] ?? ''),$allowed)!==[]),'Wartość nie pasuje do parametru nadrzędnego: '.$name);
        }
        $row['valuesIds']=$list;
    } else {
        allegro_pilot_need(in_array($type,['string','integer','float'],true),'Nieobsługiwany typ parametru: '.$name);
        $range=!empty($r['range']) && in_array($type,['integer','float'],true);
        allegro_pilot_need(count($list)===($range?2:1),'Uzupełnij '.($range?'obie granice zakresu':'jedną wartość').': '.$name);
        foreach ($list as $value) {
            if ($type!=='string') {
                allegro_pilot_need(preg_match($type==='integer'?'/^-?[0-9]+$/D':'/^-?[0-9]+(?:\.[0-9]+)?$/D',$value)===1,'Nieprawidłowa liczba: '.$name);
                allegro_pilot_need(!isset($r['min']) || (float)$value>=(float)$r['min'],'Wartość poniżej minimum: '.$name);
                allegro_pilot_need(!isset($r['max']) || (float)$value<=(float)$r['max'],'Wartość powyżej maksimum: '.$name);
                if (isset($r['precision'])) allegro_pilot_need(strlen(explode('.',$value)[1] ?? '')<=(int)$r['precision'],'Zbyt duża liczba miejsc po przecinku: '.$name);
            }
            if (isset($r['minLength'])) allegro_pilot_need(allegro_pilot_length($value)>=$r['minLength'],'Zbyt krótka wartość: '.$name);
            if (isset($r['maxLength'])) allegro_pilot_need(allegro_pilot_length($value)<=$r['maxLength'],'Zbyt długa wartość: '.$name);
        }
        if ($range) {
            allegro_pilot_need((float)$list[0]<=(float)$list[1],'Odwrócony zakres: '.$name);
            $row['rangeValue']=['from'=>$list[0],'to'=>$list[1]];
        } else $row['values']=$list;
    }
    return $row;
}
function allegro_pilot_cn(array $product, array $p): string
{
    // Only an explicit catalogue material, never a product name or colour.
    $material=trim((string)($product['material'] ?? ''));
    if (!preg_match('/^(?:beton|beton architektoniczny|beton malowany)$/iu',$material)
        || !preg_match('/^Kod taryfy celnej(?:\s*\(CN\))?$/iu',trim((string)$p['name']))) return '';
    $value='68109900';
    if ($p['type']==='dictionary') {
        $matches=array_values(array_filter($p['dictionary'] ?? [],static fn($d)=>preg_replace('/\s/u','',(string)($d['value'] ?? ''))==='68109900'));
        if (count($matches)!==1) return '';
        $value=$matches[0]['id'];
    }
    try {allegro_pilot_parameter_row($p,$value,[(string)$p['id']=>$value]);}
    catch (Throwable $e) {return '';}
    return $value;
}
/** Re-fetch ancestors from the API on back/restart, including from a leaf. */
function allegro_pilot_category_return(array $config, array $state, bool $restart, ?callable $transport=null): array
{
    $path=$state['path'] ?? $state['categoryPath'] ?? [];
    if ($restart) $path=[];else array_pop($path);
    $next=allegro_pilot_metadata($config,[],'',$transport);
    foreach ($path as $node) {
        $next=allegro_pilot_metadata($config,$next,$node['id'],$transport);
        allegro_pilot_need(!isset($next['category']),'Drzewo kategorii zmieniło się. Wybierz kategorię od początku.');
    }
    return $next;
}
function allegro_pilot_payload(array $product, array $meta, array $input): array
{
    allegro_pilot_need(time()-($meta['at'] ?? 0)<=900,'Odśwież parametry i ustawienia Allegro.');
    foreach (['name','price','quantity','description','safety','stock_confirmed','gpsr_confirmed','shipping','return','warranty','producer','person'] as $field) {
        allegro_pilot_need(!isset($input[$field]) || is_string($input[$field]),'Nieprawidłowy format pola formularza.');
    }
    $name=trim((string)($input['name'] ?? ''));
    allegro_pilot_need(allegro_pilot_length($name)>=12 && allegro_pilot_length($name)<=75 && count(preg_split('/\s+/u',$name))>=3,'Tytuł wymaga 12–75 znaków i co najmniej 3 słów.');
    $price=trim((string)($input['price'] ?? ''));
    allegro_pilot_need(preg_match('/^[0-9]{1,8}(?:[.,][0-9]{1,2})?$/D',$price)===1 && (float)str_replace(',','.',$price)>0,'Potwierdź prawidłową cenę w PLN.');
    $amount=number_format((float)str_replace(',','.',$price),2,'.','');
    $quantity=$input['quantity'] ?? '';
    allegro_pilot_need(is_string($quantity) && preg_match('/^[1-9][0-9]{0,3}$/D',$quantity)===1 && ($input['stock_confirmed'] ?? '')==='yes','Potwierdź rzeczywistą liczbę dostępnych sztuk.');
    $description=trim((string)($input['description'] ?? ''));
    allegro_pilot_need($description!=='' && allegro_pilot_length($description)<=15000,'Uzupełnij opis, maksymalnie 15000 znaków.');
    $images=[];
    foreach (catalog_images($product) as $source) $images[]=allegro_pilot_image_file($source)['url'];
    allegro_pilot_need(count($images)>=1 && count($images)<=16,'Wymagane od 1 do 16 zdjęć produktu.');
    $handlingInfo=$input['handling_info'] ?? allegro_pilot_handling_note();
    allegro_pilot_need(is_string($handlingInfo) && allegro_pilot_length($handlingInfo)<=2000,'Informacja o realizacji w podglądzie może mieć do 2000 znaków.');
    $receipt=$input['receipt_info'] ?? allegro_pilot_receipt_note();
    if (is_string($receipt)) $receipt=str_replace(["\r\n","\r"],"\n",$receipt);
    allegro_pilot_need(is_string($receipt) && allegro_pilot_length($receipt)<=650,'Informacje o odbiorze mogą mieć maksymalnie 650 znaków (limit Allegro).');
    allegro_pilot_need(($input['handling_confirmed'] ?? '')==='yes','Potwierdź maksymalny czas nadania: 7 dni roboczych (P7D).');
    $values=$input['parameters'] ?? [];allegro_pilot_need(is_array($values),'Nieprawidłowe parametry.');
    $productParams=[];$offerParams=[];
    foreach ($meta['parameters'] as $p) {
        $id=(string)$p['id'];$row=allegro_pilot_parameter_row($p,$values[$id] ?? '',$values);
        if ($row===null) continue;
        if (!empty($p['requiredForProduct']) || !empty($p['options']['describesProduct'])) $productParams[]=$row;
        else $offerParams[]=$row;
    }
    $producer=allegro_pilot_selected($meta,'responsibleProducers',$input['producer'] ?? '');
    $person=allegro_pilot_selected($meta,'responsiblePersons',$input['person'] ?? '',true);
    $safety=trim((string)($input['safety'] ?? ''));
    allegro_pilot_need($safety!=='' && allegro_pilot_length($safety)<=5000 && ($input['gpsr_confirmed'] ?? '')==='yes','Potwierdź producenta, osobę odpowiedzialną (jeśli wymagana) i rzeczywiste informacje bezpieczeństwa GPSR.');
    $set=['product'=>['name'=>$name,'category'=>['id'=>$meta['category']['id']],'parameters'=>$productParams,'images'=>$images],
        'responsibleProducer'=>['type'=>'ID','id'=>$producer],'safetyInformation'=>['type'=>'TEXT','description'=>$safety]];
    if ($person!==null) $set['responsiblePerson']=['id'=>$person];
    return ['name'=>$name,'category'=>['id'=>$meta['category']['id']],'parameters'=>$offerParams,'productSet'=>[$set],
        'sellingMode'=>['format'=>'BUY_NOW','price'=>['amount'=>$amount,'currency'=>'PLN']],
        'stock'=>['available'=>(int)$quantity,'unit'=>'UNIT'],'publication'=>['status'=>'INACTIVE'],
        'external'=>['id'=>$product['productId']],'language'=>'pl-PL','images'=>$images,
        'description'=>['sections'=>[['items'=>[['type'=>'TEXT','content'=>allegro_pilot_description_html($description)]]]]],
        'delivery'=>['shippingRates'=>['id'=>allegro_pilot_selected($meta,'shippingRates',$input['shipping'] ?? '')],'handlingTime'=>'P7D','additionalInfo'=>trim($receipt)],
        'afterSalesServices'=>['returnPolicy'=>['id'=>allegro_pilot_selected($meta,'returnPolicies',$input['return'] ?? '')],
            'impliedWarranty'=>['id'=>allegro_pilot_selected($meta,'impliedWarranties',$input['warranty'] ?? '')]]];
}
function allegro_pilot_location(array $config, string $location, string $id, bool $operation): string
{
    $prefix=allegro_hosts($config['environment'])['api'];
    $path=str_starts_with($location,$prefix.'/') ? substr($location,strlen($prefix)) : $location;
    $pattern='~^/sale/product-offers/'.preg_quote($id,'~').($operation?'/operations/[A-Za-z0-9-]{1,80}':'').'$~D';
    allegro_pilot_need(preg_match($pattern,$path)===1,'Nieprawidłowa lokalizacja operacji Allegro.');return $path;
}
function allegro_pilot_bind_response(array $config, array &$record, array $response): void
{
    $id=$response['data']['id'] ?? $record['offerId'] ?? '';
    allegro_pilot_need(is_string($id) && preg_match('/^[0-9]{1,32}$/D',$id)===1,'Niepewny wynik Allegro. Nie twórz kolejnego szkicu.');
    if (isset($record['offerId'])) allegro_pilot_need($record['offerId']===$id,'Niezgodna oferta Allegro.');
    $record['offerId']=$id;
    if ($response['status']===202) {
        $record['operation']=allegro_pilot_location($config,(string)($response['headers']['location'] ?? ''),$id,true);
        $record['nextCheck']=time()+max(1,min(300,(int)($response['headers']['retry-after'] ?? 5)));
        $record['status']='PROCESSING';
    }
}
function allegro_pilot_observe(array $config, array &$record, string $access, ?callable $transport): void
{
    $candidate=$record;
    if (!empty($candidate['operation'])) {
        allegro_pilot_need(time()>=($candidate['nextCheck'] ?? 0),'Zaczekaj przed sprawdzeniem operacji.');
        $response=allegro_pilot_api($config,'GET',$candidate['operation'],$access,[],$transport);
        if ($response['status']===202) {$candidate['nextCheck']=time()+max(1,min(300,(int)($response['headers']['retry-after'] ?? 5)));$record=$candidate;return;}
        allegro_pilot_need($response['status']===303,'Operacja nie została zakończona.');
        allegro_pilot_location($config,(string)($response['headers']['location'] ?? ''),$candidate['offerId'],false);
        unset($candidate['operation']);
    }
    $remote=allegro_pilot_api($config,'GET','/sale/product-offers/'.$candidate['offerId'],$access,[],$transport)['data'];
    allegro_pilot_need(($remote['id'] ?? '')===$candidate['offerId'] && ($remote['external']['id'] ?? '')===$candidate['productId'],'Nie można potwierdzić powiązania oferty z productId.');
    if (isset($candidate['sourcePayload'],$candidate['payload']['delivery']['handlingTime'])) {
        allegro_pilot_need(in_array($remote['delivery']['handlingTime'] ?? '',['P7D','PT168H'],true) && $candidate['payload']['delivery']['handlingTime']==='P7D','Nie potwierdzono deklarowanego czasu wysyłki w Allegro. Sprawdź szkic przed publikacją.');
    }
    $status=$remote['publication']['status'] ?? '';
    allegro_pilot_need(in_array($status,['INACTIVE','ACTIVE','ACTIVATING','ENDED'],true),'Nieznany status oferty.');
    $candidate['status']=$status;$candidate['observed']=$remote;
    $record=$candidate;
}
function allegro_pilot_write(array $config, array $preview, bool $publish, ?callable $transport = null, ?array $product = null): void
{
    allegro_locked($config,static function($v,$k)use($config,$preview,$publish,$transport,$product){
        $access=allegro_pilot_access($config,$v,$k,$transport);
        allegro_pilot_need(($preview['seller'] ?? '')===($v['account']['id'] ?? '') && ($preview['environment'] ?? '')===$config['environment'] && time()-($preview['at'] ?? 0)<=900,'Podgląd wygasł lub konto się zmieniło.');
        $pid=$preview['payload']['external']['id'];
        $record=$v['offers'][$pid] ?? [];
        allegro_pilot_need(preg_match('/^prd_[a-f0-9]{32}$/D',$pid)===1,'Nieprawidłowy productId.');
        if (!$publish) {
            allegro_pilot_need(empty($record),'Szkic już utworzono albo wynik jest niepewny. Użyj sprawdzania statusu zamiast ponawiać.');
            $probe=allegro_pilot_api($config,'GET','/sale/offers?external.id='.$pid,$access,[],$transport);$found=$probe['data'];
            allegro_pilot_need($probe['status']===200 && isset($found['offers']) && is_array($found['offers']) && is_int($found['totalCount'] ?? null),'Brak pewnego wyniku kontroli duplikatów.');
            allegro_pilot_need($found['totalCount']===0 && $found['offers']===[],'Oferta z tym productId już istnieje w Allegro. Nie twórz duplikatu.');
            $product ??= allegro_pilot_products(load_catalog())[$pid] ?? [];
            allegro_pilot_need(($product['productId'] ?? '')===$pid && allegro_pilot_fingerprint($product)===$preview['fingerprint'],'Produkt zmienił się. Przygotuj nowy podgląd.');
            $uploaded=allegro_pilot_uploaded_payload($config,$preview['payload'],$product,$access,$transport);
            allegro_pilot_need(time()-($preview['at'] ?? 0)<=900,'Podgląd wygasł podczas uploadu. Przygotuj nowy podgląd.');
            $record=['productId'=>$pid,'status'=>'UNKNOWN','fingerprint'=>$preview['fingerprint'],'payload'=>$uploaded,
                'sourcePayload'=>$preview['payload'],'attemptedAt'=>time()];
            $v['offers'][$pid]=$record;
            // Leave room for IDs, operation URLs and recovery evidence before creating an offer.
            allegro_pilot_need(strlen(json_encode($v,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))<8300000,'Brak miejsca w lokalnym zapisie ofert. Żądanie utworzenia oferty nie zostało wysłane.');
            allegro_write_vault($config,$k,$v); // Crash/timeout cannot re-enter creation.
            try {
                $response=allegro_pilot_api($config,'POST','/sale/product-offers',$access,$record['payload'],$transport);
                allegro_pilot_bind_response($config,$record,$response);
                if ($response['status']===201) allegro_pilot_observe($config,$record,$access,$transport);
            } catch (Throwable $e) {
                $record['failure']=['code'=>'CREATE_FAILED_OR_UNCERTAIN','at'=>time()];
                if ($e instanceof AllegroPilotWriteException) $record['failure']['httpStatus']=$e->httpStatus;
                throw $e;
            } finally {unset($record['observed']);$v['offers'][$pid]=$record;allegro_write_vault($config,$k,$v);}
        } else {
            allegro_pilot_need(isset($record['offerId']) && empty($record['operation']) && empty($record['activationAttempted']),'Sprawdź status; publikacja nie może być ponawiana w ciemno.');
            allegro_pilot_observe($config,$record,$access,$transport);
            allegro_pilot_need($record['status']==='INACTIVE','Publikować można wyłącznie potwierdzony szkic INACTIVE.');
            allegro_pilot_need($preview['payload']===($record['sourcePayload'] ?? $record['payload']),'Podgląd różni się od utworzonego szkicu. Nie publikujemy innych danych niż potwierdzone.');
            $remote=$record['observed'];$payload=$record['payload'];
            foreach (['name','stock','sellingMode','category'] as $field) {
                if ($field==='stock') allegro_pilot_need(($remote[$field]['available'] ?? null)===$payload[$field]['available'],'Ilość w Allegro zmieniła się.');
                elseif ($field==='sellingMode') allegro_pilot_need(($remote[$field]['price'] ?? [])===$payload[$field]['price'],'Cena w Allegro zmieniła się.');
                elseif ($field==='category') allegro_pilot_need(($remote[$field]['id'] ?? '')===$payload[$field]['id'],'Kategoria szkicu zmieniła się.');
                else allegro_pilot_need(($remote[$field] ?? null)===$payload[$field],'Dane szkicu zmieniły się. Sprawdź ofertę w Allegro.');
            }
            allegro_pilot_need($record['fingerprint']===$preview['fingerprint'],'Produkt w katalogu zmienił się od utworzenia szkicu.');
            $record['activationAttempted']=true;$record['status']='UNKNOWN';unset($record['observed']);
            $v['offers'][$pid]=$record;allegro_write_vault($config,$k,$v);
            try {
                $response=allegro_pilot_api($config,'PATCH','/sale/product-offers/'.$record['offerId'],$access,['publication'=>['status'=>'ACTIVE']],$transport);
                allegro_pilot_bind_response($config,$record,$response);
                if ($response['status']===200) allegro_pilot_observe($config,$record,$access,$transport);
            } finally {unset($record['observed']);$v['offers'][$pid]=$record;allegro_write_vault($config,$k,$v);}
        }
    });
}
/** Two independent manual reads (at least a minute apart), plus a fresh read on confirmation. */
function allegro_pilot_unlock_check(array $config, string $pid, bool $unlock=false, string $nonce='', ?callable $transport=null, ?int $now=null): void
{
    $now ??= time();
    allegro_locked($config,static function($v,$k)use($config,$pid,$unlock,$nonce,$transport,$now){
        $access=allegro_pilot_access($config,$v,$k,$transport);$record=$v['offers'][$pid] ?? [];
        allegro_pilot_need(($record['productId'] ?? '')===$pid && preg_match('/^prd_[a-f0-9]{32}$/D',$pid)===1
            && ($record['status'] ?? '')==='UNKNOWN' && empty($record['offerId']) && empty($record['operation'])
            && empty($record['activationAttempted']),'Można odblokować tylko niepewną próbę tworzenia bez ID oferty lub operacji.');
        $checks=$record['absenceChecks'] ?? [];
        if (!$unlock && $checks!==[] && ($record['absenceSeller'] ?? '')!==($v['account']['id'] ?? null)) {
            $checks=[];unset($record['absenceChecks'],$record['recoveryNonce'],$record['absenceSeller']);
        }
        if ($unlock) allegro_pilot_need(count($checks)===2 && $now-$checks[1]>=0 && $now-$checks[1]<=300
            && isset($record['recoveryNonce']) && hash_equals($record['recoveryNonce'],$nonce)
            && ($record['absenceSeller'] ?? '')===($v['account']['id'] ?? null),'Wymagane dwie świeże kontrole oraz świadome potwierdzenie odblokowania.');
        elseif ($checks!==[]) allegro_pilot_need($now-$checks[count($checks)-1]>=60,'Odczekaj co najmniej 60 sekund przed niezależną kolejną kontrolą.');
        try {
            // No status filter: documented default includes INACTIVE, ACTIVE, ACTIVATING and ENDED.
            $response=allegro_pilot_api($config,'GET','/sale/offers?external.id='.$pid,$access,[],$transport);
            $found=$response['data'];
            allegro_pilot_need($response['status']===200 && isset($found['offers'],$found['totalCount'])
                && is_array($found['offers']) && is_int($found['totalCount']) && $found['totalCount']>=0,'Niejednoznaczny wynik kontroli ofert — blokada pozostaje.');
            if ($found['totalCount']!==0 || $found['offers']!==[] || (isset($found['count']) && $found['count']!==0)) {
                $ids=[];
                foreach ($found['offers'] as $offer) {
                    $id=$offer['id'] ?? '';
                    if (is_string($id) && preg_match('/^[0-9]{1,32}$/D',$id)) $ids[]=$id;
                }
                throw new RuntimeException('Brak odblokowania: Allegro zwróciło ofertę lub niejednoznaczny wynik.'.($ids!==[]?' ID: '.implode(', ',array_slice($ids,0,20)).'.':''));
            }
        } catch (Throwable $e) {
            unset($record['absenceChecks'],$record['recoveryNonce'],$record['absenceSeller']);
            $v['offers'][$pid]=$record;allegro_write_vault($config,$k,$v);throw $e;
        }
        if ($unlock) {
            // Keep only safe diagnostics, not response bodies, headers or credentials.
            $v['pilotFailures'][]=['productId'=>$pid,'status'=>'UNKNOWN','fingerprint'=>$record['fingerprint'] ?? '',
                'attemptedAt'=>$record['attemptedAt'] ?? null,'reopenedAt'=>$now,'absenceChecks'=>$checks,
                'failureCode'=>'CREATE_FAILED_OR_UNCERTAIN','httpStatus'=>is_int($record['failure']['httpStatus'] ?? null)?$record['failure']['httpStatus']:null];
            unset($v['offers'][$pid]);allegro_write_vault($config,$k,$v);return;
        }
        if (count($checks)>=2 || ($checks!==[] && $now-$checks[0]>600)) $checks=[];
        $checks[]=$now;$record['absenceChecks']=$checks;$record['absenceSeller']=$v['account']['id'];
        $record['recoveryNonce']=bin2hex(random_bytes(24));$v['offers'][$pid]=$record;allegro_write_vault($config,$k,$v);
    });
}
function allegro_pilot_check(array $config, ?callable $transport = null, string $pid = ''): void
{
    allegro_locked($config,static function($v,$k)use($config,$transport,$pid){
        allegro_pilot_need(preg_match('/^prd_[a-f0-9]{32}$/D',$pid)===1,'Wybierz produkt do sprawdzenia.');
        $access=allegro_pilot_access($config,$v,$k,$transport);$record=$v['offers'][$pid] ?? [];
        allegro_pilot_need(!empty($record),'Brak szkicu pilota.');
        if (empty($record['offerId'])) {
            $found=allegro_pilot_api($config,'GET','/sale/offers?external.id='.$record['productId'],$access,[],$transport)['data'];
            allegro_pilot_need(($found['totalCount'] ?? 0)===1 && count($found['offers'] ?? [])===1,'Wynik tworzenia nadal niepewny. Sprawdź sygnaturę productId w Moim Allegro. Nie twórz kolejnego szkicu.');
            $record['offerId']=(string)$found['offers'][0]['id'];
            allegro_pilot_need(preg_match('/^[0-9]{1,32}$/D',$record['offerId'])===1,'Nieprawidłowe ID oferty.');
        }
        allegro_pilot_observe($config,$record,$access,$transport);
        // Only safe publication fields are needed, not a second copy of the remote catalogue.
        unset($record['observed']);$v['offers'][$pid]=$record;allegro_write_vault($config,$k,$v);
    });
}
function allegro_pilot_action(array $config, string $action): void
{
    if ($action==='pilot-check') {
        $id=$_POST['product_id'] ?? '';
        allegro_pilot_need(is_string($id),'Nieprawidłowy productId.');
        allegro_pilot_check($config,null,$id);return;
    }
    $id=$_POST['product_id'] ?? '';
    allegro_pilot_need(is_string($id) && preg_match('/^prd_[a-f0-9]{32}$/D',$id)===1,'Nieprawidłowy productId.');
    if (in_array($action,['pilot-unlock-check','pilot-unlock'],true)) {
        $unlock=$action==='pilot-unlock';$nonce=$_POST['recovery_nonce'] ?? '';
        allegro_pilot_need(is_string($nonce) && (!$unlock || ($_POST['unlock_confirm'] ?? '')==='yes'),'Potwierdź świadome odblokowanie próby.');
        allegro_pilot_unlock_check($config,$id,$unlock,$nonce);
        if ($unlock) unset($_SESSION['allegro_pilot_preview'],$_SESSION['allegro_pilot_meta'],$_SESSION['allegro_pilot_categories']);
        return;
    }
    $products=allegro_pilot_products(load_catalog());
    allegro_pilot_need(is_string($id) && isset($products[$id]),'Wybierz dostępną figurę z trwałym productId.');
    $product=$products[$id];
    if (in_array($action,['pilot-category-back','pilot-category-restart'],true)) {
        $state=$_SESSION['allegro_pilot_categories'] ?? $_SESSION['allegro_pilot_meta'] ?? [];
        $nonce=$_POST['navigation_nonce'] ?? '';
        allegro_pilot_need(($state['productId'] ?? '')===$id && ($state['environment'] ?? '')===$config['environment']
            && time()-($state['at'] ?? 0)<=900 && is_string($nonce) && isset($state['nonce'])
            && hash_equals($state['nonce'],$nonce),'Wybór kategorii wygasł. Rozpocznij ponownie dla wybranej figury.');
        allegro_locked($config,static function($v,$k)use($state){allegro_pilot_need(($state['seller'] ?? '')===($v['account']['id'] ?? null),'Połączone konto zmieniło się.');});
        $result=allegro_pilot_category_return($config,$state,$action==='pilot-category-restart');
        allegro_pilot_need(!empty($result['options']),'Allegro nie zwróciło dostępnych kategorii. Spróbuj ponownie.');
        unset($_SESSION['allegro_pilot_preview'],$_SESSION['allegro_pilot_meta'],$_SESSION['allegro_pilot_categories']);
        $_SESSION['allegro_pilot_categories']=$result+['productId'=>$id,'nonce'=>bin2hex(random_bytes(24))];return;
    }
    if ($action==='pilot-load') {
        $state=[];$selected=$_POST['category_id'] ?? '';
        allegro_pilot_need(is_string($selected),'Nieprawidłowy wybór kategorii.');
        if ($selected!=='') {
            $state=$_SESSION['allegro_pilot_categories'] ?? [];$nonce=$_POST['navigation_nonce'] ?? '';
            allegro_pilot_need(($state['productId'] ?? '')===$id && ($state['environment'] ?? '')===$config['environment']
                && time()-($state['at'] ?? 0)<=900 && is_string($nonce) && isset($state['nonce'])
                && hash_equals($state['nonce'],$nonce),'Wybór kategorii wygasł. Rozpocznij ponownie dla wybranej figury.');
        }
        $result=allegro_pilot_metadata($config,$state,$selected)+['productId'=>$id];
        allegro_pilot_need(isset($result['category']) || !empty($result['options']),'Allegro nie zwróciło dostępnych kategorii. Spróbuj ponownie.');
        unset($_SESSION['allegro_pilot_preview'],$_SESSION['allegro_pilot_meta'],$_SESSION['allegro_pilot_categories']);
        if (isset($result['category'])) $_SESSION['allegro_pilot_meta']=$result+['nonce'=>bin2hex(random_bytes(24))];
        else $_SESSION['allegro_pilot_categories']=$result+['nonce'=>bin2hex(random_bytes(24))];
        return;
    }
    if ($action==='pilot-preview') {
        $meta=$_SESSION['allegro_pilot_meta'] ?? [];
        allegro_pilot_need(($meta['productId'] ?? '')===$id && ($meta['environment'] ?? '')===$config['environment'],'Pobierz ustawienia dla wybranego produktu.');
        unset($_SESSION['allegro_pilot_preview']);
        if (is_string($_POST['description'] ?? null)) $_SESSION['allegro_pilot_meta']['description']=$_POST['description'];
        foreach (['safety','handling_info','receipt_info'] as $field) if (is_string($_POST[$field] ?? null)) $_SESSION['allegro_pilot_meta'][$field]=$_POST[$field];
        $formValues=$_POST['parameters'] ?? [];
        allegro_pilot_need(is_array($formValues),'Nieprawidłowe parametry.');
        foreach ($formValues as $raw) allegro_pilot_parameter_values($raw);
        $_SESSION['allegro_pilot_meta']['formValues']=$formValues;
        $_SESSION['allegro_pilot_preview']=['payload'=>allegro_pilot_payload($product,$meta,$_POST),
            'fingerprint'=>allegro_pilot_fingerprint($product),'at'=>time(),'seller'=>$meta['seller'],
            'environment'=>$config['environment'],'handlingInfo'=>is_string($_POST['handling_info'] ?? null)?$_POST['handling_info']:allegro_pilot_handling_note(),'nonce'=>bin2hex(random_bytes(24))];return;
    }
    if (in_array($action,['pilot-create','pilot-publish'],true)) {
        $preview=$_SESSION['allegro_pilot_preview'] ?? []; $nonce=$_POST['preview_nonce'] ?? '';
        allegro_pilot_need(is_string($nonce) && isset($preview['nonce']) && hash_equals($preview['nonce'],$nonce),'Podgląd został wykorzystany lub wygasł.');
        allegro_pilot_need(($preview['payload']['external']['id'] ?? '')===$id && ($preview['fingerprint'] ?? '')===allegro_pilot_fingerprint($product),'Katalog zmienił się. Przygotuj nowy podgląd.');
        allegro_pilot_need(($_POST['confirm'] ?? '')===($action==='pilot-create'?'DRAFT':'PUBLISH'),'Wymagane osobne potwierdzenie operacji.');
        if ($action==='pilot-publish') {
            allegro_pilot_need(($_POST['stock_confirmed'] ?? '')==='yes' && ($_POST['publish_quantity'] ?? '')===(string)$preview['payload']['stock']['available'],'Ponownie potwierdź dostępną liczbę sztuk.');
        }
        unset($_SESSION['allegro_pilot_preview']); // Consume before transport; double click cannot replay.
        allegro_pilot_write($config,$preview,$action==='pilot-publish',null,$product);
        // A fresh nonce allows a separate publication confirmation, never replays draft creation.
        $preview['nonce']=bin2hex(random_bytes(24));$_SESSION['allegro_pilot_preview']=$preview;return;
    }
    throw new AllegroSafeException('policy');
}
function allegro_pilot_form_start(string $action, string $id=''): void
{
    echo '<form method="post" action="/admin/allegro/"'.($action==='pilot-preview'?' id="pilot-preview-form"':'').'><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="'.e($action).'"><input type="hidden" name="product_id" value="'.e($id).'">';
}
function allegro_pilot_select(string $field, string $label, array $rows, bool $optional=false): void
{
    echo '<p><label>'.e($label).' <select name="'.e($field).'"'.($optional?'':' required').'><option value="">Wybierz'.($optional?' / nie dotyczy':'').'</option>';
    foreach ($rows as $row) if (is_string($row['id'] ?? null)) echo '<option value="'.e($row['id']).'">'.e((string)($row['name'] ?? $row['id'])).'</option>';
    echo '</select></label></p>';
    if (!$optional && $rows===[]) echo '<p>Brak ustawienia na koncie Allegro — uzupełnij je w Moim Allegro, potem odśwież.</p>';
}
function allegro_pilot_parameter_control(array $p, array $product, array $parameters, array $values=[]): void
{
    $id=(string)$p['id'];$r=$p['restrictions'] ?? [];$value=allegro_pilot_cn($product,$p);
    $defaults=[];
    foreach ($parameters as $other) $defaults[(string)$other['id']]=allegro_pilot_cn($product,$other);
    $defaults[$id]=$value;
    $defaults=array_replace($defaults,$values);
    $selected=allegro_pilot_parameter_values($defaults[$id] ?? '');
    $required=allegro_pilot_parameter_required($p,$defaults);
    $conditional=!empty($p['requiredIf']);$base=!empty($p['required']) || !empty($p['requiredForProduct']);
    $label=$base?'Wymagane':($conditional?'Wymagane warunkowo':'Opcjonalne');
    echo '<div data-allegro-param="'.e($id).'"><p><label>'.e((string)$p['name']).' — <span data-requirement>'.e($label).'</span>';
    echo !empty($p['unit']) ? ' ['.e((string)$p['unit']).'] ' : ' ';
    $name='parameters['.$id.']';$req=$required?' required':'';
    if ($p['type']==='dictionary') {
        $multiple=!empty($r['multipleChoices']);
        echo '<select name="'.e($name.($multiple?'[]':'')).'"'.($multiple?' multiple':'').$req.'>';
        if (!$multiple) echo '<option value="">Wybierz / brak</option>';
        foreach ($p['dictionary'] ?? [] as $d) echo '<option value="'.e((string)$d['id']).'"'.(in_array($d['id'],$selected,true)?' selected':'').'>'.e((string)$d['value']).'</option>';
        echo '</select>';
    } else {
        $numeric=in_array($p['type'],['integer','float'],true);$range=$numeric && !empty($r['range']);
        for ($n=0;$n<($range?2:1);$n++) {
            echo ($range?($n===0?'od ':' do '):'').'<input name="'.e($name.($range?'[]':'')).'" type="'.($numeric?'number':'text').'" value="'.e($selected[$n] ?? '').'"'.$req;
            if ($numeric) {
                echo ' step="'.($p['type']==='integer'?'1':(isset($r['precision'])?e((string)pow(10,-(int)$r['precision'])):'any')).'"';
                foreach (['min','max'] as $key) if (isset($r[$key])) echo ' '.$key.'="'.e((string)$r[$key]).'"';
            } else foreach (['minLength'=>'minlength','maxLength'=>'maxlength'] as $key=>$attr) if (isset($r[$key])) echo ' '.$attr.'="'.e((string)$r[$key]).'"';
            echo '>';
        }
    }
    echo '</label></p>';
    $reasons=[];
    if (!empty($p['required'])) $reasons[]='API: required=true — wymagany w ofercie';
    if (!empty($p['requiredForProduct'])) $reasons[]='API: requiredForProduct=true — wymagany do utworzenia produktu';
    $lookup=array_column($parameters,null,'id');
    foreach ($p['requiredIf']['parametersWithValue'] ?? [] as $c) {
        $parent=$lookup[$c['id']] ?? ['name'=>$c['id']];$dictionary=array_column($parent['dictionary'] ?? [],'value','id');
        $names=array_map(static fn($v)=>$dictionary[$v] ?? $v,$c['oneOfValueIds'] ?? []);
        $reasons[]=$parent['name'].' = '.implode(' / ',$names);
    }
    foreach ($p['requiredIf']['parametersWithoutValue'] ?? [] as $c) $reasons[]='brak wartości: '.($lookup[$c['id']]['name'] ?? $c['id']);
    if ($conditional) echo '<p>Warunek API (wszystkie zależności jednocześnie): '.e(implode('; ',$reasons)).'. <span data-condition-status>'.($required?'Teraz wymagane.':'Teraz opcjonalne.').'</span></p>';
    elseif ($reasons!==[]) echo '<p>'.e(implode('; ',$reasons)).'.</p>';
    echo '<p>'.(!empty($p['options']['describesProduct']) || !empty($p['requiredForProduct'])?'Parametr produktu.':'Parametr oferty.').'</p>';
    if (preg_match('/EAN|GTIN/iu',(string)$p['name'])) echo '<p>Wymagalność wynika z API. Nie wpisuj fikcyjnego EAN/GTIN.</p>';
    if ($value!=='') echo '<p>Kod sugerowany dla dekoracyjnych figur betonowych — potwierdź przed użyciem. Możesz zmienić wartość ręcznie.</p>';
    if (preg_match('/producent|marka/iu',(string)$p['name'])) echo '<p>Używaj tylko prawdziwych danych i dostępnych wartości słownika. „Producent nieznany” nie zastępuje danych GPSR.</p>';
    echo '</div>';
}
/** Served as same-origin authenticated JS by index.php, respecting the existing CSP. */
function allegro_pilot_form_script(): string
{
    return <<<'JS'
'use strict';
function allegroPilotValues(raw) {
    return (Array.isArray(raw) ? raw : [raw]).filter(v => typeof v === 'string').map(v => v.trim()).filter(v => v !== '');
}
function allegroPilotRequired(p, values) {
    let required = Boolean(p.required || p.requiredForProduct);
    if (p.requiredIf && Object.keys(p.requiredIf).length) {
        const c = p.requiredIf;
        const active = (c.parametersWithValue || []).every(v => allegroPilotValues(values[v.id] || '').some(x => (v.oneOfValueIds || []).includes(x)))
            && (c.parametersWithoutValue || []).every(v => allegroPilotValues(values[v.id] || '').length === 0);
        required = required || active;
    }
    return required;
}
function allegroPilotDescription(text) {
    return text.trim().split(/\r?\n\s*\r?\n/).map(part => {
        const tag = part.trim() === 'Ważna informacja o ręcznym malowaniu' ? 'h2' : 'p';
        const node = document.createElement(tag); node.textContent = part.trim(); return node;
    });
}
for (const select of document.querySelectorAll('[data-gpsr-template]')) {
    const text = select.closest('form').querySelector('[name="safety"]');
    select.addEventListener('change', () => {
        if (select.value === 'figure') text.value = text.dataset.gpsrText;
        // Manual mode leaves edits untouched, including an intentionally empty value.
        text.focus();
    });
}
for (const group of document.querySelectorAll('[data-allegro-parameters]')) {
    const parameters = JSON.parse(group.dataset.allegroParameters);
    const rows = new Map([...group.querySelectorAll('[data-allegro-param]')].map(row => [row.dataset.allegroParam, row]));
    const controls = row => [...row.querySelectorAll('input,select')];
    const values = () => Object.fromEntries([...rows].map(([id,row]) => [id, controls(row).flatMap(control => control.multiple
        ? [...control.selectedOptions].map(o => o.value) : [control.value])]));
    function update() {
        // Dictionary choices can depend on another API parameter. Never invent a value.
        for (let pass = 0; pass < parameters.length; pass++) {
            let changed = false; const current = values();
            for (const p of parameters) {
                const parent = p.options && p.options.dependsOnParameterId;
                if (p.type !== 'dictionary' || !parent) continue;
                const select = controls(rows.get(String(p.id)))[0];
                for (const option of select.options) {
                    const entry = (p.dictionary || []).find(d => d.id === option.value);
                    const allowed = entry && entry.dependsOnValueIds || [];
                    option.disabled = allowed.length > 0 && !allegroPilotValues(current[parent] || '').some(v => allowed.includes(v));
                    if (option.disabled && option.selected) { option.selected = false; changed = true; }
                }
            }
            if (!changed) break;
        }
        const current = values();
        for (const p of parameters) {
            const row = rows.get(String(p.id)); const required = allegroPilotRequired(p, current);
            for (const control of controls(row)) control.required = required;
            const conditional = p.requiredIf && Object.keys(p.requiredIf).length;
            row.querySelector('[data-requirement]').textContent = p.required || p.requiredForProduct ? 'Wymagane' : conditional ? 'Wymagane warunkowo' : 'Opcjonalne';
            const status = row.querySelector('[data-condition-status]');
            if (status) status.textContent = required ? 'Teraz wymagane.' : 'Teraz opcjonalne.';
        }
    }
    group.addEventListener('input', update); group.addEventListener('change', update); update();
    const form = group.closest('form'); const description = form.querySelector('[name="description"]');
    const preview = form.querySelector('[data-allegro-description-preview]');
    if (description && preview) description.addEventListener('input', () => preview.replaceChildren(...allegroPilotDescription(description.value)));
}
// Layout and counters only: this code never fetches, submits or mutates sales state automatically.
{
    const workspace = document.querySelector?.('.allegro-workspace');
    if (workspace) {
        const tabs = [...workspace.querySelectorAll('[data-allegro-tab]')];
        const panels = [...workspace.querySelectorAll('[data-allegro-panel]')];
        function activate(key, focus = false) {
            if (!panels.some(p => p.dataset.allegroPanel === key)) return;
            for (const p of panels) p.hidden = p.dataset.allegroPanel !== key;
            for (const tab of tabs) {
                const active = tab.dataset.allegroTab === key;
                tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1;
                if (active && focus) tab.focus();
            }
            try { sessionStorage.setItem('allegro-pilot-tab', key); } catch {}
        }
        for (const tab of tabs) {
            tab.addEventListener('click', () => activate(tab.dataset.allegroTab));
            tab.addEventListener('keydown', event => {
                const index = tabs.indexOf(tab);
                let next;
                if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
                if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = tabs.length - 1;
                if (next !== undefined) { event.preventDefault(); activate(tabs[next].dataset.allegroTab, true); }
            });
        }
        let initial = 'description';
        try { initial = sessionStorage.getItem('allegro-pilot-tab') || initial; } catch {}
        activate(initial); document.body.classList.add('allegro-compact');
        const receipt = workspace.querySelector('[data-receipt-limit]');
        if (receipt) {
            const counter = workspace.querySelector('[data-receipt-count]');
            const warning = workspace.querySelector('[data-receipt-warning]');
            const updateReceipt = () => {
                const count = Array.from(receipt.value.replace(/\r\n?/g, '\n')).length;
                const over = count > Number(receipt.dataset.receiptLimit);
                counter.textContent = `${count} / 650`;
                warning.textContent = over ? 'Przekroczono limit 650 znaków. Skróć tekst lub przywróć nową wersję domyślną.' : '';
                receipt.setCustomValidity(over ? 'Informacje o odbiorze mogą mieć maksymalnie 650 znaków.' : '');
                receipt.setAttribute('aria-invalid', String(over));
            };
            receipt.addEventListener('input', updateReceipt); updateReceipt();
            workspace.querySelector('[data-receipt-reset]').addEventListener('click', () => {
                if (receipt.value !== receipt.dataset.receiptDefault && !window.confirm('Zastąpić wpisany tekst nową wersją domyślną? Twoje zmiany zostaną zastąpione.')) return;
                receipt.value = receipt.dataset.receiptDefault;
                receipt.dispatchEvent(new Event('input', {bubbles: true})); receipt.focus();
            });
        }
        const form = workspace.querySelector('#pilot-preview-form');
        if (form) {
            const reveal = element => {
                const panel = element.closest('[data-allegro-panel]');
                if (panel) activate(panel.dataset.allegroPanel);
                element.focus(); element.scrollIntoView({block: 'nearest'});
            };
            const updateSummary = () => {
                const price = form.elements.namedItem('price'); const quantity = form.elements.namedItem('quantity');
                workspace.querySelector('[data-live-price]').textContent = price?.value || '—';
                workspace.querySelector('[data-live-quantity]').textContent = quantity?.value || '—';
                const missing = [...form.elements].filter(el => el.required && el.validity && !el.validity.valid).length;
                workspace.querySelector('[data-required-summary]').textContent = missing ? `Wymagają uzupełnienia lub poprawy: ${missing} pól.` : 'Wymagane pola uzupełnione. Sprawdź podgląd.';
            };
            workspace.addEventListener('input', updateSummary); workspace.addEventListener('change', updateSummary); updateSummary();
            // Validate hidden panels deliberately, then reveal the first invalid field before focusing it.
            form.noValidate = true;
            form.addEventListener('submit', event => {
                const invalid = [...form.elements].find(el => el.willValidate && !el.validity.valid);
                if (invalid) {event.preventDefault(); reveal(invalid); invalid.reportValidity(); return;}
                try {sessionStorage.setItem('allegro-pilot-tab', 'preview');} catch {}
            });
            const serverError = workspace.querySelector('.flash-error');
            if (serverError) {
                const text = serverError.textContent; let target;
                for (const group of workspace.querySelectorAll('[data-allegro-parameters]')) {
                    for (const p of JSON.parse(group.dataset.allegroParameters)) {
                        if (text.includes(p.name)) target = [...form.elements].find(el => el.name === `parameters[${p.id}]` || el.name === `parameters[${p.id}][]`);
                    }
                }
                const messages = {name: 'Tytuł', price: 'cenę', quantity: 'liczbę', description: 'opis', shipping: 'shippingRates',
                    return: 'returnPolicies', warranty: 'impliedWarranties', producer: 'producenta', safety: 'bezpieczeństwa',
                    gpsr_confirmed: 'GPSR', handling_confirmed: 'czas nadania', receipt_info: '650 znaków'};
                if (!target) for (const [name, fragment] of Object.entries(messages)) if (text.includes(fragment)) {target = form.elements.namedItem(name); break;}
                if (target) {target.setAttribute('aria-invalid', 'true'); reveal(target);}
            }
        }
    }
}

JS;
}
function allegro_pilot_category_buttons(array $state): void
{
    foreach (['pilot-category-back'=>'Wróć o poziom','pilot-category-restart'=>'Wybierz kategorię od początku'] as $action=>$label) {
        if ($action==='pilot-category-back' && ($state['path'] ?? $state['categoryPath'] ?? [])===[]) continue;
        allegro_pilot_form_start($action,$state['productId']);
        echo '<input type="hidden" name="navigation_nonce" value="'.e($state['nonce']).'"><button class="btn btn-secondary" type="submit">'.e($label).'</button></form>';
    }
}
/** Module-local layout only; no global admin styles or sales state are changed. */
function allegro_pilot_styles(): string
{
    return <<<'CSS'
.allegro-workspace{max-width:none!important;margin:0!important;padding:12px!important;min-height:100vh;color:#29342d}
.allegro-workspace *{box-sizing:border-box}.allegro-workspace [hidden]{display:none!important}
.allegro-workspace .allegro-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.allegro-workspace h1{font-size:20px;margin:0}.allegro-workspace h2{font-size:17px;margin:0 0 10px}.allegro-workspace h3{font-size:15px;margin:12px 0 7px}
.allegro-workspace p{margin:6px 0}.allegro-workspace label{font-size:13px}.allegro-workspace input,.allegro-workspace select,.allegro-workspace textarea{width:100%;max-width:100%;padding:8px;font:inherit;min-width:0}
.allegro-workspace input[type=checkbox]{width:auto;min-height:0;padding:0;margin-right:5px;vertical-align:middle}.allegro-workspace input:not([type=checkbox]),.allegro-workspace select{min-height:34px}.allegro-workspace textarea{resize:vertical}.allegro-workspace .btn{min-height:34px;padding:7px 9px;font-size:13px;white-space:normal}
.allegro-workspace .card{padding:12px;margin:0;min-width:0;box-shadow:none}.allegro-workspace .allegro-notices:empty{display:none}
.allegro-workspace .flash{margin:0;padding:9px 12px}.allegro-workspace .allegro-notices{max-height:100px;overflow:auto;flex-shrink:0}
.allegro-workspace .allegro-connection{font-size:13px}.allegro-workspace summary{cursor:pointer}.allegro-workspace .allegro-connection-body{padding:12px;max-height:65vh;overflow:auto;background:#fff;border:1px solid #dce5da;border-radius:8px}
.allegro-workspace .allegro-status{display:flex;gap:12px;align-items:center;flex-wrap:wrap;font-size:13px}
.allegro-workspace .allegro-pilot{min-width:0}.allegro-workspace .allegro-toolbar{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;padding:9px 0}
.allegro-workspace .allegro-grid{display:grid;grid-template-columns:minmax(225px,270px) minmax(0,1fr) minmax(225px,265px);gap:12px;min-height:0}
.allegro-workspace .allegro-product,.allegro-workspace .allegro-editor,.allegro-workspace .allegro-actions{min-width:0}.allegro-workspace .allegro-product{font-size:13px;line-height:1.35}
.allegro-workspace .allegro-images{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0}.allegro-workspace .allegro-images img{object-fit:contain;width:30px;height:30px;border:1px solid #dce5da;border-radius:5px;background:#f6f3ec}
.allegro-workspace .allegro-images img:first-child{width:100%;height:80px}.allegro-workspace .allegro-path{font-size:12px;overflow-wrap:anywhere}
.allegro-workspace .allegro-actions .btn{width:100%;margin:4px 0}.allegro-workspace .allegro-actions form{margin:8px 0}
.allegro-workspace .allegro-tabbar{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid #dce5da;padding-bottom:8px}
.allegro-workspace [role=tab]{border:1px solid #dce5da;background:#f6f3ec;color:#244533;border-radius:5px;padding:8px;cursor:pointer;font-size:12px}
.allegro-workspace [role=tab][aria-selected=true]{background:#244533;color:#fff;border-color:#244533}
.allegro-workspace [role=tab]:focus-visible{outline:3px solid #b28b45}.allegro-workspace .allegro-tab-panel{padding:10px 2px}
.allegro-workspace .allegro-counter{font-size:12px;display:flex;justify-content:space-between;gap:8px;align-items:center}
.allegro-workspace .allegro-counter button{font-size:12px;cursor:pointer}.allegro-workspace .allegro-warning{color:#a63d36;font-weight:600}
.allegro-workspace [aria-invalid=true]{outline:2px solid #a63d36}.allegro-workspace pre{white-space:pre-wrap;overflow-wrap:anywhere}
.allegro-workspace .allegro-summary{font-size:13px;overflow-wrap:anywhere}.allegro-workspace .allegro-summary strong{display:block;margin:5px 0}
@media(min-width:1100px){
 body.allegro-compact{height:100dvh;overflow:hidden}.allegro-compact .allegro-workspace{height:100dvh;min-height:0;display:flex;flex-direction:column;gap:7px}
 .allegro-compact .allegro-pilot{flex:1;min-height:0;display:flex;flex-direction:column}.allegro-compact .allegro-grid{flex:1;min-height:0}
 .allegro-compact .allegro-product,.allegro-compact .allegro-actions{overflow:auto}
 .allegro-compact .allegro-editor{display:flex;flex-direction:column;overflow:hidden}.allegro-compact #pilot-preview-form{display:flex;flex-direction:column;min-height:0;height:100%;margin:0}
 .allegro-compact .allegro-tab-panel{overflow:auto;min-height:0;flex:1}.allegro-compact .allegro-tabbar{flex-shrink:0}
 .allegro-compact .allegro-connection[open] .allegro-connection-body{position:absolute;left:12px;right:12px;z-index:20;box-shadow:0 12px 30px #0003}
}
@media(max-width:1099px){.allegro-workspace .allegro-grid{grid-template-columns:1fr}.allegro-workspace{padding:8px!important}.allegro-workspace .allegro-actions{position:static}.allegro-workspace .allegro-tabbar{position:sticky;top:0;background:#fff;z-index:2}}
CSS;
}
function allegro_pilot_render(array $config, array $status): void
{
    echo '<section class="allegro-pilot"><header class="allegro-toolbar"><h2>Figury ogrodowe — Allegro</h2>';
    if (empty($status['sales_ready'])) {echo '</header><p>Obecny token nie ma wszystkich uprawnień sprzedaży. Użyj świadomej ponownej autoryzacji powyżej. Dotychczasowe połączenie pozostaje zachowane do udanego zakończenia nowej zgody.</p></section>';return;}
    try {$products=allegro_pilot_products(load_catalog());}
    catch (Throwable $e) {echo '</header><p>Nie można bezpiecznie odczytać katalogu produktów.</p></section>';return;}
    $offers=allegro_locked($config,static fn($v,$k)=>$v['offers']);
    $navigation=$_SESSION['allegro_pilot_categories'] ?? [];
    $meta=$_SESSION['allegro_pilot_meta'] ?? [];$id=$meta['productId'] ?? '';
    $ready=isset($products[$id]) && ($meta['environment'] ?? '')===$config['environment'];
    $hint=$_GET['productId'] ?? $_POST['product_id'] ?? '';
    $hint=is_string($hint) && (isset($products[$hint]) || isset($offers[$hint])) ? $hint : '';
    if ($hint!=='') {
        $ready=$ready && $id===$hint;
        if (($navigation['productId'] ?? '')!==$hint) $navigation=[];
    }
    $selectedId=$hint!==''?$hint:($ready?$id:($navigation['productId'] ?? array_key_first($offers) ?? ''));
    $record=$offers[$selectedId] ?? [];
    echo '<div class="allegro-status"><span>Oferta: <strong data-offer-status>'.e((string)($record['status'] ?? 'BRAK SZKICU')).'</strong></span>';
    if (isset($record['offerId']) && preg_match('/^[0-9]{1,32}$/D',(string)$record['offerId'])) {
        $host=$config['environment']==='PROD'?'https://allegro.pl':'https://allegro.pl.allegrosandbox.pl';
        echo '<a target="_blank" rel="noopener noreferrer" href="'.e($host.'/oferta/'.$record['offerId']).'">Otwórz ofertę w Allegro · '.e($record['offerId']).'</a>';
    }
    echo '</div></header><div class="allegro-grid"><aside class="card allegro-product">';
    foreach ($offers as $savedId=>$savedRecord) {
        echo '<p><a href="/admin/allegro/?productId='.e($savedId).'">'.e((string)($products[$savedId]['name'] ?? $savedRecord['payload']['name'] ?? $savedId)).' - '.e($savedRecord['status'] ?? 'UNKNOWN').' - '.e($savedRecord['offerId'] ?? 'bez ID').'</a></p>';
    }
    $p=$products[$selectedId] ?? null;
    allegro_pilot_form_start('pilot-load');
    echo '<p><label>Figura <select name="product_id" required><option value="">Wybierz figurę</option>';
    foreach ($products as $pid=>$product) echo '<option value="'.e($pid).'"'.($pid===$selectedId?' selected':'').'>'.e((string)$product['name']).' — '.e(catalog_sale_price_text($product)).'</option>';
    echo '</select></label></p><button class="btn" type="submit">Wybierz kategorię Allegro</button></form>';
    if ($p!==null) {
        echo '<h3>'.e((string)$p['name']).'</h3><div class="allegro-images">';
        foreach (catalog_images($p) as $image) if (str_starts_with($image,'/uploads/') && !str_contains($image,'..')) echo '<img src="'.e($image).'" alt="'.e((string)$p['name']).'" loading="lazy">';
        echo '</div>';
    }
    if ($ready) {
        echo '<p class="allegro-path">Ścieżka: '.e(implode(' → ',array_column($meta['categoryPath'] ?? [$meta['category']],'name'))).'</p><p class="allegro-path">Kategoria Allegro: '.e((string)$meta['category']['name']).' ('.e((string)$meta['category']['id']).') — najniższy poziom.</p>';
        allegro_pilot_category_buttons($meta);
        $price=catalog_sale_price_text($p);preg_match('/^\s*([0-9]+(?:[.,][0-9]{1,2})?)\s*(?:zł|PLN)?\s*$/u',$price,$priceMatch);
        echo '<p>Cena katalogowa: '.e($price).'</p><p><label>Cena PLN <input form="pilot-preview-form" name="price" value="'.e($priceMatch[1] ?? '').'" required data-summary-price></label></p>';
        echo '<p><label>Dostępne sztuki <input form="pilot-preview-form" name="quantity" type="number" min="1" max="9999" required data-summary-quantity></label></p><p><label><input form="pilot-preview-form" type="checkbox" name="stock_confirmed" value="yes" required> Potwierdzam rzeczywistą ilość oraz cenę.</label></p>';
    } elseif (($navigation['productId'] ?? '')===$selectedId && isset($products[$selectedId]) && ($navigation['environment'] ?? '')===$config['environment']) {
        echo '<p class="allegro-path">Wybrana ścieżka: '.e(implode(' → ',array_column($navigation['path'],'name')) ?: 'Kategorie główne').'</p>';
        allegro_pilot_category_buttons($navigation);
        if ($navigation['options']===[]) echo '<p>API nie zwróciło podkategorii. Rozpocznij wybór ponownie; oferta pozostaje zablokowana.</p>';
        else {
            allegro_pilot_form_start('pilot-load',$navigation['productId']);
            echo '<input type="hidden" name="navigation_nonce" value="'.e($navigation['nonce']).'">';
            allegro_pilot_select('category_id','Kategoria Allegro',$navigation['options']);
            echo '<button class="btn" type="submit">Wybierz kategorię / pobierz podkategorie lub parametry</button></form>';
        }
    } elseif ($record!==[]) {
        $saved=$record['sourcePayload'] ?? $record['payload'] ?? [];
        echo '<p>Produkt: '.e((string)($record['productId'] ?? '')).'</p><p>Cena: '.e((string)($saved['sellingMode']['price']['amount'] ?? '—')).' PLN · Ilość: '.e((string)($saved['stock']['available'] ?? '—')).'</p><p>Zapisana oferta pozostaje bez zmian.</p>';
    }
    echo '</aside><section class="card allegro-editor">';
    $tabs=['description'=>'Opis produktu','parameters'=>'Parametry Allegro','delivery'=>'Dostawa i zwroty','gpsr'=>'GPSR i bezpieczeństwo','preview'=>'Podgląd oferty'];
    if ($ready) allegro_pilot_form_start('pilot-preview',$id);
    echo '<nav class="allegro-tabbar" role="tablist" aria-label="Dane oferty">';
    foreach ($tabs as $key=>$label) echo '<button type="button" role="tab" id="tab-'.$key.'" aria-controls="panel-'.$key.'" aria-selected="'.($key==='description'?'true':'false').'" data-allegro-tab="'.$key.'">'.e($label).'</button>';
    echo '</nav>';
    foreach ($tabs as $key=>$label) {
        echo '<section class="allegro-tab-panel" role="tabpanel" id="panel-'.$key.'" aria-labelledby="tab-'.$key.'" data-allegro-panel="'.$key.'"><h3>'.e($label).'</h3>';
        if (!$ready) {
            if ($key==='preview' && $record!==[]) echo '<p>Status zapisanej oferty: '.e((string)$record['status']).'.</p><p>Sprawdzenie statusu jest osobną, ręczną operacją. Panel nie ponawia tworzenia ani publikacji.</p>';
            else echo '<p>Wybierz figurę i kategorię, aby przygotować nowy podgląd. Zapisanej oferty nie zmieniamy.</p>';
        } elseif ($key==='description') {
            $description=$meta['description'] ?? allegro_pilot_description($p);
            echo '<p><label>Tytuł oferty <input name="name" value="'.e((string)$p['name']).'" minlength="12" maxlength="75" required></label></p><p><label>Opis <textarea name="description" rows="8" maxlength="15000" required>'.e($description).'</textarea></label></p>';
            echo '<details><summary>Podgląd opisu produktu Allegro — informacje o dostawie w osobnej zakładce</summary><div data-allegro-description-preview>'.allegro_pilot_description_html($description).'</div></details>';
            echo '<details><summary>Tekst do sekcji O sprzedającym — poza opisem oferty</summary><p>Dane showroomu umieść w O sprzedającym; nie są wysyłane w opisie produktu. Odbiór osobisty ustaw osobno tylko wtedy, gdy jest rzeczywiście dostępny.</p><h3>Home &amp; Garden Outlet – showroom pod Wrocławiem</h3><p>Home &amp; Garden Outlet prowadzi showroom w Kębłowicach koło Wrocławia, w klimatycznej przestrzeni dawnej stodoły. Dostępne na miejscu figury można obejrzeć na żywo i przyjrzeć się szczegółom ich wykończenia.</p></details>';
        } elseif ($key==='parameters') {
            echo '<p>Wymagalność i ograniczenia parametrów pochodzą z API.</p><div data-allegro-parameters="'.e(json_encode($meta['parameters'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'">';
            foreach ($meta['parameters'] as $param) allegro_pilot_parameter_control($param,$p,$meta['parameters'],$meta['formValues'] ?? []);
            echo '</div><noscript><p>Bez JavaScript wymagalność warunkowa zostanie sprawdzona po wysłaniu podglądu przez PHP.</p></noscript>';
        } elseif ($key==='delivery') {
            allegro_pilot_select('shipping','Cennik dostawy',$meta['shippingRates']);
            allegro_pilot_select('return','Warunki zwrotów',$meta['returnPolicies']);
            allegro_pilot_select('warranty','Warunki reklamacji',$meta['impliedWarranties']);
            echo '<h3>Czas nadania: do 7 dni roboczych</h3><p>delivery.handlingTime=P7D. To termin nadania, nie doręczenia.</p><textarea name="handling_info" rows="3" maxlength="2000">'.e($meta['handling_info'] ?? allegro_pilot_handling_note()).'</textarea><p><label><input type="checkbox" name="handling_confirmed" value="yes" required> Potwierdzam nadanie w maksymalnie 7 dni roboczych.</label></p>';
            $receipt=$meta['receipt_info'] ?? allegro_pilot_receipt_note();
            echo '<h3>Ważna informacja dotycząca odbioru przesyłki</h3><p>Informacje o dostawie poza opisem produktu. Limit Allegro: 650 znaków.</p><textarea name="receipt_info" rows="8" data-receipt-limit="650" data-receipt-default="'.e(allegro_pilot_receipt_note()).'" aria-describedby="receipt-count receipt-warning">'.e($receipt).'</textarea><div class="allegro-counter"><output id="receipt-count" data-receipt-count aria-live="polite">'.allegro_pilot_length(str_replace(["\r\n","\r"],"\n",$receipt)).' / 650</output><button type="button" data-receipt-reset>Przywróć domyślny tekst</button></div><p id="receipt-warning" class="allegro-warning" data-receipt-warning role="status"></p>';
        } elseif ($key==='gpsr') {
            allegro_pilot_select('producer','Odpowiedzialny producent (GPSR)',$meta['responsibleProducers']);
            allegro_pilot_select('person','Osoba odpowiedzialna w UE, jeśli wymagana',$meta['responsiblePersons'],true);
            echo '<p><label>Wybierz szablon informacji bezpieczeństwa <select data-gpsr-template><option value="">Wybierz szablon</option><option value="figure">Figura ogrodowa</option><option value="manual">Wpisz ręcznie</option></select></label></p><p>Szablon jest propozycją, nie instrukcją producenta. Możesz go zmienić lub usunąć; potwierdzenie GPSR pozostaje osobne.</p><p><label>Rzeczywiste informacje bezpieczeństwa GPSR <textarea name="safety" data-gpsr-text="'.e(allegro_pilot_gpsr_template()).'" required maxlength="5000">'.e($meta['safety'] ?? '').'</textarea></label></p><p><label><input type="checkbox" name="gpsr_confirmed" value="yes" required> Potwierdzam zgodność z rzeczywistym produktem i informacjami producenta.</label></p>';
        } elseif ($key==='preview') {
            $preview=$_SESSION['allegro_pilot_preview'] ?? [];
            if (($preview['payload']['external']['id'] ?? '')===$id) {
                $payload=$preview['payload'];
                echo '<p>'.e($payload['name']).' · '.e($payload['sellingMode']['price']['amount']).' PLN · '.e((string)$payload['stock']['available']).' szt.</p><h3>Opis produktu</h3>'.allegro_pilot_description_html($meta['description'] ?? allegro_pilot_description($p));
                echo '<h3>Czas nadania: do 7 dni roboczych</h3><p>'.e($preview['handlingInfo'] ?? allegro_pilot_handling_note()).'</p><h3>Odbiór przesyłki</h3><p>'.e($payload['delivery']['additionalInfo'] ?? '').'</p><details><summary>Wszystkie dane oferty</summary><pre>'.e(json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</pre></details><p>Podgląd nie zmienia statusu oferty. Zapis i publikacja wymagają osobnego potwierdzenia.</p>';
            } else echo '<p>Uzupełnij wymagane dane i wybierz „Podgląd oferty”. Żadna oferta nie zostanie wtedy utworzona ani opublikowana.</p>';
        }
        echo '</section>';
    }
    if ($ready) echo '</form>';
    echo '</section><aside class="card allegro-actions"><h3>Podsumowanie</h3><div class="allegro-summary"><strong>'.e((string)($p['name'] ?? $record['payload']['name'] ?? 'Wybierz figurę')).'</strong><p>Cena: <span data-live-price>'.e((string)($record['payload']['sellingMode']['price']['amount'] ?? '—')).'</span> PLN · Ilość: <span data-live-quantity>'.e((string)($record['payload']['stock']['available'] ?? '—')).'</span></p><p data-required-summary aria-live="polite">Uzupełnij wymagane dane.</p></div>';
    if ($ready) echo '<button class="btn" type="submit" form="pilot-preview-form">Podgląd oferty</button>';
    $preview=$_SESSION['allegro_pilot_preview'] ?? [];
    $canCreate=$record===[];$canPublish=($record['status'] ?? '')==='INACTIVE' && isset($record['offerId']) && empty($record['operation']) && empty($record['activationAttempted']);
    if ($ready && ($preview['payload']['external']['id'] ?? '')===$id && ($canCreate || $canPublish)) {
        allegro_pilot_form_start($canPublish?'pilot-publish':'pilot-create',$id);
        echo '<input type="hidden" name="preview_nonce" value="'.e($preview['nonce']).'">';
        if ($canPublish) echo '<p><label>Potwierdź dostępne sztuki <input type="number" name="publish_quantity" min="1" max="9999" required></label></p><p><label><input type="checkbox" name="stock_confirmed" value="yes" required> Ilość jest nadal dostępna.</label></p>';
        echo '<p><label><input type="checkbox" name="confirm" value="'.($canPublish?'PUBLISH':'DRAFT').'" required> '.($canPublish?'Zgadzam się opublikować tę jedną ofertę.':'Zgadzam się utworzyć jeden szkic INACTIVE.').'</label></p><button class="btn" type="submit">'.($canPublish?'Opublikuj ofertę':'Utwórz szkic').'</button></form>';
    }
    if ($record!==[]) {
        echo '<p>Status: <strong>'.e((string)$record['status']).'</strong></p>';
        if (($record['status'] ?? '')==='UNKNOWN' && empty($record['offerId']) && empty($record['operation']) && empty($record['activationAttempted'])) {
            $checks=$record['absenceChecks'] ?? [];
            echo '<p>Kontrole braku oferty: '.count($checks).'/2, odstęp minimum 60 sekund.</p>';
            allegro_pilot_form_start('pilot-unlock-check',$record['productId']);echo '<button class="btn" type="submit">Sprawdź brak oferty i odblokuj próbę</button></form>';
            if (count($checks)===2 && isset($record['recoveryNonce'])) {
                allegro_pilot_form_start('pilot-unlock',$record['productId']);
                echo '<input type="hidden" name="recovery_nonce" value="'.e($record['recoveryNonce']).'"><p><label><input type="checkbox" name="unlock_confirm" value="yes" required> Potwierdzam świadome odblokowanie po dwóch kontrolach.</label></p><button class="btn" type="submit">Odblokuj nieudaną próbę</button></form>';
            }
        }
        allegro_pilot_form_start('pilot-check',$record['productId']);echo '<button class="btn btn-secondary" type="submit">Sprawdź końcowy status / odzyskaj ID</button></form>';
    }
    echo '<p>Operacje wykonywane są wyłącznie po ręcznym zatwierdzeniu.</p></aside></div></section>';
}
