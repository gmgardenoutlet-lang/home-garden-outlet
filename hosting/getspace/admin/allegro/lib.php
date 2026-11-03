<?php
declare(strict_types=1);

// Existing OAuth plus the single-product sales pilot.
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


/** One product pilot; all credentials and the single offer binding stay in the existing vault. */
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
    $response=($transport ?? 'allegro_curl')($request);
    $status=$response['status'] ?? 0;
    if (!in_array($status,[200,201,202,303],true) || !is_string($response['body'] ?? null) || strlen($response['body'])>131072) {
        if ($method==='GET') {
            throw new RuntimeException('Allegro GET '.$path.' — HTTP '.(int)$status.'. Nie udało się pobrać danych.');
        }
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
        throw new RuntimeException('Allegro odrzuciło żądanie lub wynik jest niepewny (HTTP '.(int)$status.'). '.implode('; ',$codes).' Nie ponawiaj tworzenia; sprawdź status.');
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
                && preg_match('/^[0-9]{1,32}$/D',$row['id'])===1 && is_string($row['name'] ?? null)
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
    foreach (catalog_images($product) as $image) {
        $path=parse_url($image,PHP_URL_PATH);
        $host=parse_url($image,PHP_URL_HOST);
        allegro_pilot_need(is_string($path) && str_starts_with($path,'/uploads/') && !str_contains($path,'..')
            && ($host===null || $host==='mgoutlet.pl') && preg_match('~\.(?:jpe?g|png|webp)$~i',$path)===1
            && is_file(SITE_ROOT.rawurldecode($path)), 'Zdjęcia muszą istnieć w publicznym katalogu uploads MG Outlet.');
        $images[]='https://mgoutlet.pl/'.implode('/',array_map('rawurlencode',explode('/',ltrim(rawurldecode($path),'/'))));
    }
    allegro_pilot_need(count($images)>=1 && count($images)<=16,'Wymagane od 1 do 16 zdjęć produktu.');
    $values=$input['parameters'] ?? [];allegro_pilot_need(is_array($values),'Nieprawidłowe parametry.');
    $productParams=[];$offerParams=[];
    foreach ($meta['parameters'] as $p) {
        $id=(string)$p['id'];$value=$values[$id] ?? '';
        allegro_pilot_need(is_string($value) && strlen($value)<=2000,'Nieprawidłowa wartość parametru.');$value=trim($value);
        $required=!empty($p['required']) || !empty($p['requiredForProduct']);
        if (!empty($p['requiredIf'])) {
            $condition=true;
            foreach ($p['requiredIf']['parametersWithValue'] ?? [] as $c) $condition=$condition && in_array($values[$c['id']] ?? '',$c['oneOfValueIds'] ?? [],true);
            foreach ($p['requiredIf']['parametersWithoutValue'] ?? [] as $c) $condition=$condition && empty($values[$c['id']] ?? '');
            $required=$required || $condition;
        }
        allegro_pilot_need(!$required || $value!=='','Brak wymaganego parametru: '.(string)$p['name'].'. Nie dodawaj fikcyjnego EAN/GTIN.');
        if ($value==='') continue;
        $row=['id'=>$id];
        if ($p['type']==='dictionary') {
            allegro_pilot_need(in_array($value,array_column($p['dictionary'] ?? [],'id'),true),'Wybierz wartość ze słownika: '.$p['name']);
            $row['valuesIds']=[$value];
        } else {
            if (in_array($p['type'],['integer','float'],true)) {
                allegro_pilot_need(preg_match($p['type']==='integer'?'/^-?[0-9]+$/D':'/^-?[0-9]+(?:\.[0-9]+)?$/D',$value)===1,'Nieprawidłowa liczba: '.$p['name']);
                $restrictions=$p['restrictions'] ?? [];
                allegro_pilot_need(!isset($restrictions['min']) || (float)$value>=(float)$restrictions['min'],'Wartość poniżej minimum: '.$p['name']);
                allegro_pilot_need(!isset($restrictions['max']) || (float)$value<=(float)$restrictions['max'],'Wartość powyżej maksimum: '.$p['name']);
            }
            if (isset($p['restrictions']['minLength'])) allegro_pilot_need(allegro_pilot_length($value)>=$p['restrictions']['minLength'],'Zbyt krótka wartość: '.$p['name']);
            if (isset($p['restrictions']['maxLength'])) allegro_pilot_need(allegro_pilot_length($value)<=$p['restrictions']['maxLength'],'Zbyt długa wartość: '.$p['name']);
            $row['values']=[$value];
        }
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
        'description'=>['sections'=>[['items'=>[['type'=>'TEXT','content'=>'<p>'.htmlspecialchars($description,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>']]]]],
        'delivery'=>['shippingRates'=>['id'=>allegro_pilot_selected($meta,'shippingRates',$input['shipping'] ?? '')]],
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
    if (!empty($record['operation'])) {
        allegro_pilot_need(time()>=($record['nextCheck'] ?? 0),'Zaczekaj przed sprawdzeniem operacji.');
        $response=allegro_pilot_api($config,'GET',$record['operation'],$access,[],$transport);
        if ($response['status']===202) {$record['nextCheck']=time()+max(1,min(300,(int)($response['headers']['retry-after'] ?? 5)));return;}
        allegro_pilot_need($response['status']===303,'Operacja nie została zakończona.');
        allegro_pilot_location($config,(string)($response['headers']['location'] ?? ''),$record['offerId'],false);
        unset($record['operation']);
    }
    $remote=allegro_pilot_api($config,'GET','/sale/product-offers/'.$record['offerId'],$access,[],$transport)['data'];
    allegro_pilot_need(($remote['id'] ?? '')===$record['offerId'] && ($remote['external']['id'] ?? '')===$record['productId'],'Nie można potwierdzić powiązania oferty z productId.');
    $status=$remote['publication']['status'] ?? '';
    allegro_pilot_need(in_array($status,['INACTIVE','ACTIVE','ACTIVATING','ENDED'],true),'Nieznany status oferty.');
    $record['status']=$status;$record['observed']=$remote;
}
function allegro_pilot_write(array $config, array $preview, bool $publish, ?callable $transport = null): void
{
    allegro_locked($config,static function($v,$k)use($config,$preview,$publish,$transport){
        $access=allegro_pilot_access($config,$v,$k,$transport);
        allegro_pilot_need(($preview['seller'] ?? '')===($v['account']['id'] ?? '') && ($preview['environment'] ?? '')===$config['environment'] && time()-($preview['at'] ?? 0)<=900,'Podgląd wygasł lub konto się zmieniło.');
        $pid=$preview['payload']['external']['id'];
        $record=$v['pilot'] ?? [];
        allegro_pilot_need(empty($record) || ($record['productId'] ?? '')===$pid,'Pilot obejmuje tylko jedną figurę. Inna figura jest już powiązana.');
        if (!$publish) {
            allegro_pilot_need(empty($record),'Szkic już utworzono albo wynik jest niepewny. Użyj sprawdzania statusu zamiast ponawiać.');
            $found=allegro_pilot_api($config,'GET','/sale/offers?external.id='.$pid,$access,[],$transport)['data'];
            allegro_pilot_need(isset($found['offers']) && is_array($found['offers']) && isset($found['totalCount']),'Brak pewnego wyniku kontroli duplikatów.');
            allegro_pilot_need((int)$found['totalCount']===0 && $found['offers']===[],'Oferta z tym productId już istnieje w Allegro. Nie twórz duplikatu.');
            $record=['productId'=>$pid,'status'=>'UNKNOWN','fingerprint'=>$preview['fingerprint'],'payload'=>$preview['payload']];
            $v['pilot']=$record;allegro_write_vault($config,$k,$v); // Crash/timeout cannot re-enter creation.
            try {
                $response=allegro_pilot_api($config,'POST','/sale/product-offers',$access,$preview['payload'],$transport);
                allegro_pilot_bind_response($config,$record,$response);
                if ($response['status']===201) allegro_pilot_observe($config,$record,$access,$transport);
            } finally {unset($record['observed']);$v['pilot']=$record;allegro_write_vault($config,$k,$v);}
        } else {
            allegro_pilot_need(isset($record['offerId']) && empty($record['operation']) && empty($record['activationAttempted']),'Sprawdź status; publikacja nie może być ponawiana w ciemno.');
            allegro_pilot_observe($config,$record,$access,$transport);
            allegro_pilot_need($record['status']==='INACTIVE','Publikować można wyłącznie potwierdzony szkic INACTIVE.');
            allegro_pilot_need($preview['payload']===$record['payload'],'Podgląd różni się od utworzonego szkicu. Nie publikujemy innych danych niż potwierdzone.');
            $remote=$record['observed'];$payload=$record['payload'];
            foreach (['name','stock','sellingMode','category'] as $field) {
                if ($field==='stock') allegro_pilot_need(($remote[$field]['available'] ?? null)===$payload[$field]['available'],'Ilość w Allegro zmieniła się.');
                elseif ($field==='sellingMode') allegro_pilot_need(($remote[$field]['price'] ?? [])===$payload[$field]['price'],'Cena w Allegro zmieniła się.');
                elseif ($field==='category') allegro_pilot_need(($remote[$field]['id'] ?? '')===$payload[$field]['id'],'Kategoria szkicu zmieniła się.');
                else allegro_pilot_need(($remote[$field] ?? null)===$payload[$field],'Dane szkicu zmieniły się. Sprawdź ofertę w Allegro.');
            }
            allegro_pilot_need($record['fingerprint']===$preview['fingerprint'],'Produkt w katalogu zmienił się od utworzenia szkicu.');
            $record['activationAttempted']=true;$record['status']='UNKNOWN';unset($record['observed']);
            $v['pilot']=$record;allegro_write_vault($config,$k,$v);
            try {
                $response=allegro_pilot_api($config,'PATCH','/sale/product-offers/'.$record['offerId'],$access,['publication'=>['status'=>'ACTIVE']],$transport);
                allegro_pilot_bind_response($config,$record,$response);
                if ($response['status']===200) allegro_pilot_observe($config,$record,$access,$transport);
            } finally {unset($record['observed']);$v['pilot']=$record;allegro_write_vault($config,$k,$v);}
        }
    });
}
function allegro_pilot_check(array $config, ?callable $transport = null): void
{
    allegro_locked($config,static function($v,$k)use($config,$transport){
        $access=allegro_pilot_access($config,$v,$k,$transport);$record=$v['pilot'] ?? [];
        allegro_pilot_need(!empty($record),'Brak szkicu pilota.');
        if (empty($record['offerId'])) {
            $found=allegro_pilot_api($config,'GET','/sale/offers?external.id='.$record['productId'],$access,[],$transport)['data'];
            allegro_pilot_need(($found['totalCount'] ?? 0)===1 && count($found['offers'] ?? [])===1,'Wynik tworzenia nadal niepewny. Sprawdź sygnaturę productId w Moim Allegro. Nie twórz kolejnego szkicu.');
            $record['offerId']=(string)$found['offers'][0]['id'];
            allegro_pilot_need(preg_match('/^[0-9]{1,32}$/D',$record['offerId'])===1,'Nieprawidłowe ID oferty.');
        }
        allegro_pilot_observe($config,$record,$access,$transport);
        // Only safe publication fields are needed, not a second copy of the remote catalogue.
        unset($record['observed']);$v['pilot']=$record;allegro_write_vault($config,$k,$v);
    });
}
function allegro_pilot_action(array $config, string $action): void
{
    if ($action==='pilot-check') {allegro_pilot_check($config);return;}
    $products=allegro_pilot_products(load_catalog());
    $id=$_POST['product_id'] ?? '';
    allegro_pilot_need(is_string($id) && isset($products[$id]),'Wybierz dostępną figurę z trwałym productId.');
    $product=$products[$id];
    if ($action==='pilot-load') {
        $state=[];$selected=$_POST['category_id'] ?? '';
        allegro_pilot_need(is_string($selected),'Nieprawidłowy wybór kategorii.');
        if ($selected!=='') {
            $state=$_SESSION['allegro_pilot_categories'] ?? [];$nonce=$_POST['navigation_nonce'] ?? '';
            allegro_pilot_need(($state['productId'] ?? '')===$id && ($state['environment'] ?? '')===$config['environment']
                && time()-($state['at'] ?? 0)<=900 && is_string($nonce) && isset($state['nonce'])
                && hash_equals($state['nonce'],$nonce),'Wybór kategorii wygasł. Rozpocznij ponownie dla wybranej figury.');
        }
        unset($_SESSION['allegro_pilot_preview'],$_SESSION['allegro_pilot_meta'],$_SESSION['allegro_pilot_categories']);
        $result=allegro_pilot_metadata($config,$state,$selected)+['productId'=>$id];
        if (isset($result['category'])) $_SESSION['allegro_pilot_meta']=$result;
        else $_SESSION['allegro_pilot_categories']=$result+['nonce'=>bin2hex(random_bytes(24))];
        return;
    }
    if ($action==='pilot-preview') {
        $meta=$_SESSION['allegro_pilot_meta'] ?? [];
        allegro_pilot_need(($meta['productId'] ?? '')===$id && ($meta['environment'] ?? '')===$config['environment'],'Pobierz ustawienia dla wybranego produktu.');
        unset($_SESSION['allegro_pilot_preview']);
        $_SESSION['allegro_pilot_preview']=['payload'=>allegro_pilot_payload($product,$meta,$_POST),
            'fingerprint'=>allegro_pilot_fingerprint($product),'at'=>time(),'seller'=>$meta['seller'],
            'environment'=>$config['environment'],'nonce'=>bin2hex(random_bytes(24))];return;
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
        allegro_pilot_write($config,$preview,$action==='pilot-publish');
        // A fresh nonce allows a separate publication confirmation, never replays draft creation.
        $preview['nonce']=bin2hex(random_bytes(24));$_SESSION['allegro_pilot_preview']=$preview;return;
    }
    throw new AllegroSafeException('policy');
}
function allegro_pilot_form_start(string $action, string $id=''): void
{
    echo '<form method="post" action="/admin/allegro/"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="'.e($action).'"><input type="hidden" name="product_id" value="'.e($id).'">';
}
function allegro_pilot_select(string $field, string $label, array $rows, bool $optional=false): void
{
    echo '<p><label>'.e($label).' <select name="'.e($field).'"'.($optional?'':' required').'><option value="">Wybierz'.($optional?' / nie dotyczy':'').'</option>';
    foreach ($rows as $row) if (is_string($row['id'] ?? null)) echo '<option value="'.e($row['id']).'">'.e((string)($row['name'] ?? $row['id'])).'</option>';
    echo '</select></label></p>';
    if (!$optional && $rows===[]) echo '<p>Brak ustawienia na koncie Allegro — uzupełnij je w Moim Allegro, potem odśwież.</p>';
}
function allegro_pilot_render(array $config, array $status): void
{
    echo '<section class="card"><h2>Pierwsza figura — pilot Allegro</h2>';
    if (empty($status['sales_ready'])) {echo '<p>Obecny token nie ma wszystkich uprawnień sprzedaży. Użyj świadomej ponownej autoryzacji powyżej. Dotychczasowe połączenie pozostaje zachowane do udanego zakończenia nowej zgody.</p></section>';return;}
    try {$products=allegro_pilot_products(load_catalog());}
    catch (Throwable $e) {echo '<p>Nie można bezpiecznie odczytać katalogu produktów.</p></section>';return;}
    $record=allegro_locked($config,static fn($v,$k)=>$v['pilot'] ?? []);
    if ($record!==[]) {
        echo '<p>Produkt: '.e((string)($record['productId'] ?? '')).' · Status: '.e((string)($record['status'] ?? 'UNKNOWN')).'</p>';
        if (isset($record['offerId']) && preg_match('/^[0-9]{1,32}$/D',(string)$record['offerId'])) {
            $host=$config['environment']==='PROD'?'https://allegro.pl':'https://allegro.pl.allegrosandbox.pl';
            echo '<p><a target="_blank" rel="noopener noreferrer" href="'.e($host.'/oferta/'.$record['offerId']).'">Otwórz ofertę w Allegro</a></p>';
        }
        allegro_pilot_form_start('pilot-check');echo '<button class="btn" type="submit">Sprawdź końcowy status / odzyskaj ID</button></form>';
    }
    if ($products===[]) {echo '<p>Brak dostępnej figury z poprawnym trwałym productId. Katalog nie jest modyfikowany przez integrację.</p></section>';return;}
    allegro_pilot_form_start('pilot-load');
    echo '<p><label>Figura <select name="product_id" required><option value="">Wybierz figurę</option>';
    foreach ($products as $id=>$p) echo '<option value="'.e($id).'">'.e((string)$p['name']).' — '.e(catalog_sale_price_text($p)).'</option>';
    echo '</select></label></p><button class="btn" type="submit">Wybierz kategorię Allegro</button></form>';
    $navigation=$_SESSION['allegro_pilot_categories'] ?? [];$navigationId=$navigation['productId'] ?? '';
    if (isset($products[$navigationId]) && ($navigation['environment'] ?? '')===$config['environment']) {
        echo '<h3>'.e((string)$products[$navigationId]['name']).'</h3>';
        echo '<p>Wybrana ścieżka: '.e(implode(' → ',array_column($navigation['path'],'name')) ?: 'Kategorie główne').'</p>';
        if ($navigation['options']===[]) echo '<p>API nie zwróciło podkategorii. Rozpocznij wybór ponownie; oferta pozostaje zablokowana.</p>';
        else {
            allegro_pilot_form_start('pilot-load',$navigationId);
            echo '<input type="hidden" name="navigation_nonce" value="'.e($navigation['nonce']).'">';
            allegro_pilot_select('category_id','Kategoria Allegro',$navigation['options']);
            echo '<button class="btn" type="submit">Wybierz kategorię / pobierz podkategorie lub parametry</button></form>';
        }
    }
    $meta=$_SESSION['allegro_pilot_meta'] ?? []; $id=$meta['productId'] ?? '';
    if (!isset($products[$id])) {echo '</section>';return;}
    $p=$products[$id];
    echo '<h3>'.e((string)$p['name']).'</h3><p>Ścieżka: '.e(implode(' → ',array_column($meta['categoryPath'] ?? [$meta['category']],'name'))).'</p><p>Kategoria Allegro: '.e((string)$meta['category']['name']).' ('.e((string)$meta['category']['id']).'), potwierdzony najniższy poziom.</p>';
    foreach (catalog_images($p) as $image) {
        if (str_starts_with($image,'/uploads/') && !str_contains($image,'..')) echo '<img src="'.e($image).'" alt="" style="max-width:160px;height:auto">';
    }
    allegro_pilot_form_start('pilot-preview',$id);
    $price=catalog_sale_price_text($p);preg_match('/^\s*([0-9]+(?:[.,][0-9]{1,2})?)\s*(?:zł|PLN)?\s*$/u',$price,$priceMatch);
    echo '<p><label>Tytuł oferty <input name="name" value="'.e((string)$p['name']).'" minlength="12" maxlength="75" required></label></p>';
    echo '<p><label>Opis <textarea name="description" rows="7" maxlength="15000" required>'.e(strip_tags((string)($p['description'] ?? ''))).'</textarea></label></p>';
    echo '<p>Cena katalogowa: '.e($price).'</p><p><label>Cena PLN <input name="price" value="'.e($priceMatch[1] ?? '').'" required></label></p>';
    echo '<p><label>Dostępne sztuki <input name="quantity" type="number" min="1" max="9999" required></label></p><p><label><input type="checkbox" name="stock_confirmed" value="yes" required> Potwierdzam rzeczywistą ilość oraz cenę.</label></p>';
    foreach ($meta['parameters'] as $param) {
        $required=!empty($param['required']) || !empty($param['requiredForProduct']);
        echo '<p><label>'.e((string)$param['name']).($required?' — wymagany':'').(!empty($param['requiredForProduct'])?' (dla produktu)':'').(!empty($param['unit'])?' ['.e((string)$param['unit']).']':'').' ';
        $field='parameters['.(string)$param['id'].']';
        if ($param['type']==='dictionary') {
            echo '<select name="'.e($field).'"'.($required?' required':'').'><option value="">Wybierz / brak</option>';
            foreach ($param['dictionary'] ?? [] as $value) echo '<option value="'.e((string)$value['id']).'">'.e((string)$value['value']).'</option>';
            echo '</select>';
        } else echo '<input name="'.e($field).'"'.($required?' required':'').'>';
        echo '</label></p>';
        if (preg_match('/EAN|GTIN/i',(string)$param['name'])) echo '<p>GTIN: requiredForProduct='.(!empty($param['requiredForProduct'])?'TAK':'NIE').', wymagany w ofercie='.(!empty($param['required'])?'TAK':'NIE').'. Nie wpisuj fikcyjnego numeru. Przy braku dozwolonej wartości oferta jest zablokowana.</p>';
    }
    allegro_pilot_select('shipping','Cennik dostawy',$meta['shippingRates']);
    allegro_pilot_select('return','Warunki zwrotów',$meta['returnPolicies']);
    allegro_pilot_select('warranty','Warunki reklamacji',$meta['impliedWarranties']);
    allegro_pilot_select('producer','Odpowiedzialny producent (GPSR)',$meta['responsibleProducers']);
    allegro_pilot_select('person','Osoba odpowiedzialna w UE, jeśli wymagana',$meta['responsiblePersons'],true);
    echo '<p><label>Rzeczywiste informacje bezpieczeństwa GPSR <textarea name="safety" required maxlength="5000"></textarea></label></p><p><label><input type="checkbox" name="gpsr_confirmed" value="yes" required> Potwierdzam producenta i zgodność danych GPSR; wskazałem osobę odpowiedzialną, jeśli wymagana.</label></p><button class="btn" type="submit">Podgląd oferty</button></form>';
    $preview=$_SESSION['allegro_pilot_preview'] ?? [];
    if (($preview['payload']['external']['id'] ?? '')===$id) {
        $payload=$preview['payload'];
        echo '<h3>Podgląd</h3><p>'.e($payload['name']).' · '.e($payload['sellingMode']['price']['amount']).' PLN · '.e((string)$payload['stock']['available']).' szt.</p><p>Stan szkicu: INACTIVE. Utworzenie szkicu nie publikuje oferty.</p><details><summary>Wszystkie dane oferty</summary><pre style="white-space:pre-wrap">'.e(json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</pre></details>';
        $publish=isset($record['offerId']);
        allegro_pilot_form_start($publish?'pilot-publish':'pilot-create',$id);
        echo '<input type="hidden" name="preview_nonce" value="'.e($preview['nonce']).'">';
        if ($publish) echo '<p><label>Potwierdź liczbę dostępnych sztuk <input type="number" name="publish_quantity" min="1" max="9999" required></label></p><p><label><input type="checkbox" name="stock_confirmed" value="yes" required> Ilość jest nadal dostępna.</label></p>';
        echo '<p><label><input type="checkbox" name="confirm" value="'.($publish?'PUBLISH':'DRAFT').'" required> '.($publish?'Zgadzam się opublikować tę jedną ofertę w Allegro.':'Zgadzam się utworzyć ten jeden szkic INACTIVE.').'</label></p><button class="btn" type="submit">'.($publish?'Opublikuj ofertę':'Utwórz szkic').'</button></form>';
    }
    echo '</section>';
}
