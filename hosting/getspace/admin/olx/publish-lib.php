<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/prepare-lib.php';

function olx_publication_file(array $config, string $id): string
{
    if (!preg_match('/^prd_[a-f0-9]{32}$/D', $id)) throw olx_error('storage');
    $dir = $config['dir'] . '/publications';
    olx_validate_config($config);
    if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700)) || !chmod($dir,0700) || !olx_outside_webroot($dir)) throw olx_error('storage');
    return $dir . '/' . $id . '.json';
}

function olx_publication_label(array $state): string
{
    if (empty($state)) return 'NIE WYSTAWIONO';
    if (($state['phase'] ?? '') === 'publishing') return 'PUBLIKOWANIE';
    if (($state['phase'] ?? '') === 'uncertain') return 'BŁĄD — WYNIK NIEPEWNY, PONOWNA PUBLIKACJA ZABLOKOWANA';
    if (($state['phase'] ?? '') === 'error') return 'BŁĄD';
    $status = (string)($state['status'] ?? '');
    return ['active'=>'AKTYWNE','new'=>'OCZEKUJE','unconfirmed'=>'OCZEKUJE','unpaid'=>'OCZEKUJE NA PŁATNOŚĆ',
        'moderated'=>'MODERACJA — ODRZUCONE','blocked'=>'MODERACJA — ZABLOKOWANE','disabled'=>'MODERACJA — WERYFIKACJA',
        'removed_by_moderator'=>'MODERACJA — USUNIĘTE','outdated'=>'WYGASŁE','inactive'=>'NIEAKTYWNE',
        'removed_by_user'=>'NIEAKTYWNE','removed'=>'NIEAKTYWNE','limited'=>'NIEAKTYWNE','finished'=>'NIEAKTYWNE'][$status] ?? ('OCZEKUJE — ' . ($status ?: 'status niepotwierdzony'));
}

/** Read-only UI lookup: never creates a directory or contacts OLX. */
function olx_publication_status(array $config, string $id): array
{
    if (!preg_match('/^prd_[a-f0-9]{32}$/D', $id)) return [];
    return olx_json_read($config['dir'].'/publications/'.$id.'.json') ?? [];
}

function olx_publication_audit(string $file, array $state, string $operation): void
{
    // Fixed metadata allowlist; no payload, contact, remote body or credentials.
    $entry=['productId'=>$state['productId'],'timestamp'=>time(),'operation'=>$operation];
    foreach(['http','advert_id','error_code'] as $key) if(isset($state[$key])) $entry[$key]=$state[$key];
    $log=$file.'.audit';
    if(is_link($log)) throw olx_error('storage');
    $mask=umask(0077);
    try { $handle=fopen($log,'ab'); } finally { umask($mask); }
    if(!$handle) throw olx_error('storage');
    try {
        if(!chmod($log,0600) || fwrite($handle,json_encode($entry,JSON_THROW_ON_ERROR)."\n")===false || !fflush($handle)) throw olx_error('storage');
    } finally { fclose($handle); }
}

/** Exclusive, nonblocking process lock. Persisted publishing survives PHP crashes. */
function olx_publication_locked(array $config, string $id, callable $operation)
{
    $file = olx_publication_file($config,$id);
    $mask=umask(0077);
    if(is_link($file.'.lock')) throw olx_error('storage');
    try { $lock=fopen($file.'.lock','c'); } finally { umask($mask); }
    if (!$lock) throw olx_error('storage');
    try {
        if (!chmod($file.'.lock',0600) || !flock($lock,LOCK_EX|LOCK_NB)) throw new OlxSafeException('Publikowanie już trwa. Nie ponawiaj operacji.');
        return $operation($file,olx_json_read($file) ?? []);
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}

/** No arbitrary URL, verb or commands. No automatic retries, including 401/5xx. */
function olx_advert_http(array $config, string $method, string $path, array $data=[]): array
{
    $valid = ($method==='POST' && $path==='/adverts') || ($method==='GET' && preg_match('~^/adverts(?:/[1-9][0-9]*)?$~D',$path));
    if (!$valid || ($method==='GET' && array_diff(array_keys($data),['external_id','limit','offset']))) throw new OlxSafeException('Niedozwolona operacja OLX.');
    $token=olx_access_token($config,time());
    $url='https://www.olx.pl/api/partner'.$path;
    if ($method==='GET' && $data) $url.='?'.http_build_query($data,'','&',PHP_QUERY_RFC3986);
    $ch=curl_init($url);
    $headers=['Authorization: Bearer '.$token,'Version: 2.0','Accept: application/json'];
    if($method==='POST') $headers[]='Content-Type: application/json';
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>$headers];
    if($method==='POST') $options += [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data,JSON_THROW_ON_ERROR)];
    curl_setopt_array($ch,$options);
    $body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return ['http'=>$status,'data'=>is_string($body)&&strlen($body)<2000000 ? json_decode($body,true) : null];
}

function olx_publication_errors(array $response): array
{
    // Never reflect OLX messages: some validation messages echo submitted PII.
    $labels=['title'=>'Tytuł','description'=>'Opis','price'=>'Cena','location'=>'Lokalizacja','city_id'=>'Miasto','district_id'=>'Dzielnica','images'=>'Zdjęcia','category_id'=>'Kategoria','contact'=>'Kontakt','attributes'=>'Parametr','params'=>'Parametr','external_url'=>'Link zewnętrzny','product_safety_regulation'=>'Bezpieczeństwo produktu'];
    $errors=[];
    $body=is_array($response['data']['error']??null)?$response['data']:$response;
    $error=is_array($body['error']??null)?$body['error']:[];
    // Match documented, exact descriptions only. Never persist arbitrary remote text.
    $known=[
        'Partner is not allowed to use external URL'=>'Link zewnętrzny: OLX nie zezwala tej aplikacji na external_url. Pole należy pominąć.',
        "Category with given ID doesn't exists"=>'Kategoria: OLX nie rozpoznaje wybranego ID kategorii.',
        'Image error: Remote file not exists'=>'Zdjęcia: OLX nie może pobrać co najmniej jednego pliku. Sprawdź dostępność zdjęć dla serwerów OLX.',
        'Image error: Image limit exceeded'=>'Zdjęcia: przekroczono limit zdjęć wybranej kategorii OLX.',
        'Error while decoding JSON data: Syntax error'=>'Format danych: OLX odrzucił strukturę żądania JSON. Wymagana poprawka integracji.',
        'Invalid owner in token'=>'Połączenie OLX: token nie wskazuje poprawnego właściciela konta.',
    ];
    $detail=$error['detail']??null;
    if(is_string($detail) && isset($known[$detail])) $errors[]=$known[$detail];
    $raw=$error['validation'] ?? $body['validation'] ?? $body['errors'] ?? [];
    if(!is_array($raw)) return ['OLX odrzucił dane. Sprawdź pola szkicu.'];
    foreach(array_slice($raw,0,40) as $entry) {
        if(!is_array($entry)) continue;
        $field=(string)($entry['field']??'');
        if(!preg_match('~^[a-zA-Z0-9_./\[\]-]{1,100}$~D',$field)) $field='';
        $root=preg_split('~[./\[]~',$field)[0];
        $label=$field==='district/city_id'?'Lokalizacja':($labels[$root]??'Pole');
        $errors[]=$label.($field!==''?' ('.$field.')':'').': OLX odrzucił wartość. Popraw pole i ponownie zweryfikuj szkic.';
    }
    return $errors ?: ['OLX odrzucił żądanie (HTTP 400) bez rozpoznanego błędu pola. Przyczyna nie została ustalona; nie ponawiaj publikacji bez sprawdzenia integracji.'];
}

function olx_publication_record(array $base, array $advert): array
{
    if (!preg_match('/^[1-9][0-9]*$/D',(string)($advert['id']??''))) throw new OlxSafeException('Nie potwierdzono ID ogłoszenia.');
    if(isset($advert['external_id']) && $advert['external_id']!==($base['external_id']??null)) throw new OlxSafeException('Nie potwierdzono tożsamości ogłoszenia.');
    $record=$base;
    $record['advert_id']=(string)$advert['id']; $record['phase']='created';
    foreach(['status','created_at','activated_at','valid_to'] as $field) if(is_scalar($advert[$field]??null) && strlen((string)$advert[$field])<=100) $record[$field]=(string)$advert[$field];
    $url=$advert['url']??'';
    if(is_string($url) && preg_match('~^https://(?:www\.)?olx\.pl/[^\s<>]*$~D',$url)) $record['url']=$url;
    return $record;
}

/** Fresh server-side build; no frontend payload. GET config bypasses cache. */
function olx_publication_prepare(array $config, array $catalog, string $id): array
{
    $product=olx_find_product($catalog,$id);
    $draft=olx_json_read(olx_draft_file($config,$id));
    if(!$draft) throw new OlxSafeException('Najpierw zapisz szkic OLX.');
    olx_access_token($config,time());
    $categoryId=(int)($draft['selectedCategoryId']??0);
    if($categoryId<1) throw new OlxSafeException('Brak kategorii OLX.');
    $category=olx_config_get($config,'/categories/'.$categoryId);
    $definitions=olx_config_get($config,'/categories/'.$categoryId.'/attributes');
    $cityId=(int)($draft['location']['city_id']??0);
    if($cityId<1) throw new OlxSafeException('Brak lokalizacji OLX.');
    $errors=olx_validate_draft($product,$draft,$category,$definitions,
        olx_config_get($config,'/cities/'.$cityId),olx_config_get($config,'/cities/'.$cityId.'/districts'),olx_config_get($config,'/currencies'));
    if($errors) throw new OlxSafeException(implode("\n",$errors));
    $payload=olx_payload_preview($product,$draft,$definitions);
    $payload['auto_extend_enabled']=false;
    return ['product'=>$product,'draft'=>$draft,'category'=>$category,'definitions'=>$definitions,'payload'=>$payload];
}

function olx_publication_fingerprint(array $prepared): string
{
    return hash('sha256',json_encode([$prepared['product'],$prepared['draft'],$prepared['payload']],JSON_THROW_ON_ERROR));
}

function olx_publish_safety_input(array $input): array
{
    if(($input['safety_confirm']??'')!=='yes') throw new OlxSafeException('Potwierdź sprawdzenie danych bezpieczeństwa produktu.');
    $result=[];
    if(($input['placed_before_2024']??'')==='yes') $result['placed_before_2024']=true;
    foreach(['manufacturer','contact_person'] as $party) {
        foreach(['name','country','address','email'] as $field) {
            $value=$input[$party.'_'.$field]??'';
            if(!is_string($value) || strlen($value)>2000 || preg_match('/[<>\x00-\x1f]/',$value)) throw new OlxSafeException('Uzupełnij poprawne dane: '.$party.' / '.$field.'.');
            $value=trim($value);
            if($value==='') continue;
            if($field==='country' && !preg_match('/^[A-Z]{2}$/D',$value)) throw new OlxSafeException('Kraj wymaga dwuliterowego kodu ISO.');
            if($field==='email' && !filter_var($value,FILTER_VALIDATE_EMAIL)) throw new OlxSafeException('Nieprawidłowy e-mail danych bezpieczeństwa.');
            $result[$party][$field]=$value;
        }
    }
    $warning=$input['warning_and_safety']??'';
    if(!is_string($warning) || olx_text_length($warning)>9000 || strip_tags($warning)!==$warning) throw new OlxSafeException('Nieprawidłowe informacje bezpieczeństwa produktu.');
    if(trim($warning)!=='') $result['warning_and_safety']=trim($warning);
    return $result;
}

function olx_publish_prepare_with_safety(array $config,array $catalog,string $id,array $safety): array
{
    $prepared=olx_publication_prepare($config,$catalog,$id);
    // Validate session data again; no arbitrary JSON from the browser.
    $input=['safety_confirm'=>'yes','warning_and_safety'=>$safety['warning_and_safety']??'','placed_before_2024'=>!empty($safety['placed_before_2024'])?'yes':''];
    foreach(['manufacturer','contact_person'] as $party) foreach(['name','country','address','email'] as $field) $input[$party.'_'.$field]=$safety[$party][$field]??'';
    $validated=olx_publish_safety_input($input);
    if($validated) $prepared['payload']['product_safety_regulation']=$validated;
    foreach($prepared['payload']['images'] as $image) {
        $ch=curl_init($image['url']);
        curl_setopt_array($ch,[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $ok=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE); curl_close($ch);
        if($ok===false || $http!==200 || !preg_match('~^image/(?:jpeg|png|webp)(?:;|$)~i',$type)) throw new OlxSafeException('Zdjęcie nie jest obecnie dostępne jako poprawny obraz produktu.');
    }
    return $prepared;
}

/** Only publish.php's final, CSRF-protected confirmation invokes this function. */
function olx_publish_one(array $config, string $id, string $approvedHash, callable $prepare, ?callable $transport=null, ?callable $freshness=null): array
{
    return olx_publication_locked($config,$id,static function($file,$state) use($config,$id,$approvedHash,$prepare,$transport,$freshness) {
        if(!empty($state['advert_id']) || in_array($state['phase']??'',['publishing','uncertain','created'],true)) throw new OlxSafeException('Ogłoszenie istnieje lub wynik poprzedniej próby wymaga wyjaśnienia. Drugi POST zablokowany.');
        $prepared=$prepare();
        if(!hash_equals(olx_publication_fingerprint($prepared),$approvedHash)) throw new OlxSafeException('Produkt lub szkic zmienił się po podglądzie. Otwórz i zatwierdź nowy podgląd.');
        $payload=$prepared['payload'];
        if(($payload['external_id']??'')!==$id || ($payload['auto_extend_enabled']??null)!==false) throw new OlxSafeException('Nieprawidłowa tożsamość lub odnawianie ogłoszenia.');
        $send=$transport??static fn($m,$p,$d=[])=>olx_advert_http($config,$m,$p,$d);
        // Check for existing remote adverts even after local state loss; duplicates fail closed.
        $lookup=$send('GET','/adverts',['external_id'=>$id,'limit'=>100,'offset'=>0]);
        if(($lookup['http']??0)!==200 || !is_array($lookup['data']['data']??null)) throw new OlxSafeException('Nie potwierdzono braku istniejącego ogłoszenia OLX.');
        if($lookup['data']['data']) {
            $rows=$lookup['data']['data'];
            if(count($rows)===1 && ($rows[0]['external_id']??'')===$id) {
                $state=olx_publication_record(['productId'=>$id,'external_id'=>$id,'reconciled_at'=>time()],$rows[0]);
                olx_json_write($file,$state);
                olx_publication_audit($file,$state,'existing_advert');
            }
            throw new OlxSafeException('OLX zwrócił istniejące ogłoszenia. Nie wysłano POST. Sprawdź powiązanie.');
        }
        if($freshness && !$freshness($prepared)) throw new OlxSafeException('Produkt lub szkic zmienił się podczas walidacji. Zatwierdź nowy podgląd.');
        $state=['productId'=>$id,'external_id'=>$id,'phase'=>'publishing','attempt_at'=>time()];
        // Durable barrier BEFORE the request. Never expires automatically.
        olx_json_write($file,$state);
        olx_publication_audit($file,$state,'publish_start');
        try { $reply=$send('POST','/adverts',$payload); }
        catch(Throwable $e) { $reply=['http'=>0,'data'=>null]; }
        $http=(int)($reply['http']??0); $state['http']=$http;
        if($http===400) {
            $state['phase']='error'; $state['error_code']='validation';
            $state['errors']=olx_publication_errors(is_array($reply['data']??null)?$reply['data']:[]);
            olx_json_write($file,$state); olx_publication_audit($file,$state,'publish_validation_error'); return $state;
        }
        $advert=$reply['data']['data']??null;
        if($http!==200 || !is_array($advert) || !preg_match('/^[1-9][0-9]*$/D',(string)($advert['id']??''))) {
            $state['phase']='uncertain'; $state['error_code']='unknown_result';
            olx_json_write($file,$state); olx_publication_audit($file,$state,'publish_uncertain'); return $state;
        }
        $state['published_at']=time();
        $state=olx_publication_record($state,$advert);
        olx_json_write($file,$state); // Preserve ID before any follow-up GET.
        olx_publication_audit($file,$state,'publish_created');
        try {
            $get=$send('GET','/adverts/'.$state['advert_id']);
            $confirmed=$get['data']['data']??null;
            if(($get['http']??0)===200 && is_array($confirmed) && (string)($confirmed['id']??'')===$state['advert_id']) {
                $state=olx_publication_record($state,$confirmed); $state['verified_at']=time();
            } else $state['error_code']='verification_pending';
        } catch(Throwable $e) { $state['error_code']='verification_pending'; }
        olx_json_write($file,$state);
        return $state;
    });
}
