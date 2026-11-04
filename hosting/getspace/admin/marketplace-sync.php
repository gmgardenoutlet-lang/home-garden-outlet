<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/marketplace-status.php';

/** Separate private cache: never a publication record or an input to publishing. */
function admin_marketplace_cache_root(): string {return dirname(__DIR__,2).'/private_marketplace';}
function admin_marketplace_cache_context(?array $allegro=null,?array $olx=null): string
{
    require_once __DIR__.'/allegro/lib.php';require_once __DIR__.'/olx/publish-lib.php';
    $parts=[];
    try {$allegro ??= allegro_config();$snapshot=admin_allegro_list_snapshot($allegro);
        $parts['allegro']=[$allegro['environment'],$allegro['client_id']??'', $snapshot['account_hash']??''];}
    catch(Throwable $e) {$parts['allegro']='unavailable';}
    try {$olx ??= olx_config();$parts['olx']=[$olx['client_id']??'', $olx['dir']??''];}
    catch(Throwable $e) {$parts['olx']='unavailable';}
    return hash('sha256',json_encode($parts,JSON_THROW_ON_ERROR));
}
function admin_marketplace_cache_read(?string $root=null): array
{
    $root ??= admin_marketplace_cache_root();$file=$root.'/statuses.json';
    if (is_link($root) || is_link($file)) throw new RuntimeException('Niebezpieczny cache statusów.');
    if (!is_file($file)) return [];
    require_once __DIR__.'/allegro/lib.php';require_once __DIR__.'/olx/publish-lib.php';
    if (!allegro_private_path($file) || !olx_outside_webroot($file)) throw new RuntimeException('Niebezpieczny cache statusów.');
    if (filesize($file)>4000000) throw new RuntimeException('Cache statusów przekracza limit.');
    $value=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
    if (!is_array($value) || ($value['version']??null)!==1) throw new RuntimeException('Niepoprawny cache statusów.');
    return $value;
}
function admin_marketplace_cache_write(string $root,array $value): void
{
    $bytes=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    if (strlen($bytes)>4000000 || is_link($root.'/statuses.json')) throw new RuntimeException('Nie można zapisać cache statusów.');
    $temp=tempnam($root,'.statuses-');
    if ($temp===false) throw new RuntimeException('Nie można zapisać cache statusów.');
    try {
        if (!chmod($temp,0600) || file_put_contents($temp,$bytes)!==strlen($bytes) || !rename($temp,$root.'/statuses.json')) throw new RuntimeException('Nie można zapisać cache statusów.');
    } finally {if(is_file($temp))unlink($temp);}
}
function admin_marketplace_failure(Throwable $e): string
{
    // Never echo exception messages, API bodies or credentials.
    if (preg_match('/HTTP (429|[45][0-9]{2})\b/',$e->getMessage(),$m)) return 'HTTP '.$m[1];
    return 'Brak potwierdzenia: połączenie, transport lub niezgodna odpowiedź.';
}
function admin_marketplace_olx_observation(string $id,array $binding,array $response,int $now): array
{
    if (($binding['productId']??'')!==$id || ($binding['external_id']??'')!==$id) throw new RuntimeException('binding');
    $http=$response['http']??0;
    if ($http!==200) throw new RuntimeException('HTTP '.(int)$http);
    $ad=$response['data']['data']??null;
    if (!is_array($ad) || !is_scalar($ad['id']??null) || (string)$ad['id']!==(string)$binding['advert_id']
        || (isset($ad['external_id']) && $ad['external_id']!==$id)) throw new RuntimeException('binding');
    $status=$ad['status']??null;
    $labels=['active'=>['active','Wystawione'],'limited'=>['limited','Limit ogłoszeń'],
        'new'=>['publishing','Oczekuje'],'unconfirmed'=>['publishing','Oczekuje'],'unpaid'=>['publishing','Oczekuje na płatność'],
        'outdated'=>['inactive','Wygasłe'],'inactive'=>['inactive','Nieaktywne'],'finished'=>['inactive','Zakończone'],
        'removed'=>['inactive','Usunięte'],'removed_by_user'=>['inactive','Usunięte'],
        'removed_by_moderator'=>['error','Usunięte przez moderację'],'moderated'=>['error','Odrzucone'],
        'blocked'=>['error','Zablokowane'],'disabled'=>['publishing','Weryfikacja / moderacja']];
    if (!is_string($status) || !isset($labels[$status])) throw new RuntimeException('status');
    $row=admin_marketplace_unknown('olx');[$row['key'],$row['label']]=$labels[$status];
    $row['id']=(string)$ad['id'];$row['updated']=$now;$row['detail']='Status potwierdzony przez API OLX.';
    foreach (['published_at','valid_to'] as $field) if (isset($ad[$field])) {
        if (!is_string($ad[$field]) || strlen($ad[$field])>100 || strtotime($ad[$field])===false) throw new RuntimeException('date');
        $row[$field]=$ad[$field];
    }
    if (isset($ad['url'])) {
        if (!is_string($ad['url']) || !preg_match('~^https://(?:www\.)?olx\.pl/[^\s<>]*$~D',$ad['url'])) throw new RuntimeException('url');
        $row['url']=$ad['url'];$row['action']='Otwórz OLX';
    }
    // These are explicit owner observations, not inferred from dates or the mere presence of a URL.
    if ($status==='active' && isset($row['valid_to']) && strtotime($row['valid_to'])<=$now) {
        $row['key']='limited';$row['label']='Rozbieżność statusu — sprawdź OLX';$row['detail']='API: active, ale termin ważności już minął.';
    }
    if ($status!=='active' && in_array($row['id'],['110883622','1108900157'],true)) {
        $row['key']='limited';$row['label']='Rozbieżność statusu — sprawdź OLX';
        $row['detail']='API: '.$labels[$status][1].'. Właściciel wcześniej potwierdził publiczną aktywność. Nie wymuszamy active.';
    }
    return $row;
}
function admin_marketplace_allegro_page(array $ids,array $data,array $found,int $offset): array
{
    $offers=$data['offers']??null;$total=$data['totalCount']??null;
    if (!is_array($offers) || !array_is_list($offers) || count($offers)>100 || !is_int($total) || $total<0 || $total>100000
        || $offset+count($offers)>$total || ($offers===[] && $offset<$total)) throw new RuntimeException('pagination');
    foreach ($offers as $offer) {
        $id=$offer['id']??null;$external=$offer['external']['id']??null;$status=$offer['publication']['status']??null;
        if (!is_string($id) || !preg_match('/^[0-9]{1,32}$/D',$id) || !is_string($external) || !in_array($external,$ids,true)
            || !in_array($status,['ACTIVE','INACTIVE','ACTIVATING','ENDED'],true)) throw new RuntimeException('binding');
        foreach ($found as $matches) if (isset($matches[$id])) throw new RuntimeException('duplicate page');
        $found[$external][$id]=['id'=>$id,'status'=>$status];
    }
    return [$found,$offset+count($offers),$total];
}
function admin_marketplace_allegro_observation(string $id,array $matches,int $now): array
{
    $row=admin_marketplace_unknown('allegro');$row['url']='/admin/allegro/?productId='.$id;$row['updated']=$now;
    if (count($matches)!==1) {$row['detail']=count($matches)>1?'Wiele ofert z tą samą sygnaturą — powiązanie niejednoznaczne.':'API nie zwróciło jednoznacznego powiązania.';return $row;}
    $offer=reset($matches);$row['id']=$offer['id'];
    [$row['key'],$row['label']]=match($offer['status']) {'ACTIVE'=>['active','Wystawione'],'INACTIVE'=>['draft','Szkic'],'ACTIVATING'=>['publishing','Publikowanie'],default=>['inactive','Zakończone']};
    $row['url']='https://allegro.pl/oferta/'.$offer['id'];$row['action']='Otwórz Allegro';$row['detail']='Status potwierdzony przez API Allegro (PROD).';return $row;
}
/** One bounded GET per platform per click. Continuations are explicit authenticated POSTs. */
function admin_marketplace_sync(array $products,?array $allegro=null,?array $olx=null,?callable $allegroGet=null,?callable $olxGet=null,?string $root=null,?int $now=null): array
{
    require_once __DIR__.'/allegro/lib.php';require_once __DIR__.'/olx/publish-lib.php';
    $now ??= time();$root ??= admin_marketplace_cache_root();
    if (is_link($root) || !allegro_private_path(is_dir($root)?$root:dirname($root)) || !olx_outside_webroot(is_dir($root)?$root:dirname($root))) throw new RuntimeException('Niebezpieczna lokalizacja cache.');
    $mask=umask(0077);
    try {if (!is_dir($root) && !mkdir($root,0700,true)) throw new RuntimeException('Brak cache.');
        if (is_link($root.'/sync.lock')) throw new RuntimeException('Niebezpieczny lock.');$lock=fopen($root.'/sync.lock','c');
    } finally {umask($mask);}
    if (!$lock) throw new RuntimeException('Nie można otworzyć blokady.');
    try {
        if (!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Odświeżanie już trwa.');
        if (!chmod($root,0700) || !chmod($root.'/sync.lock',0600)) throw new RuntimeException('Nie można zabezpieczyć cache.');
        $cache=admin_marketplace_cache_read($root);
        $context=admin_marketplace_cache_context($allegro,$olx);if (($cache['context']??null)!==$context) $cache=['context'=>$context];
        if (($cache['next_at']??0)>$now) throw new RuntimeException('Odczekaj przed kolejną partią (limit odświeżania).');
        $ids=[];foreach($products as $product) { $id=$product['productId']??'';if(is_string($id)&&preg_match('/^prd_[a-f0-9]{32}$/D',$id)) {if(isset($ids[$id]))throw new RuntimeException('Powtórzony productId.');$ids[$id]=true;} }
        $ids=array_keys($ids);sort($ids);$catalogHash=hash('sha256',implode('\n',$ids));
        if (empty($cache['work']) || ($cache['work']['catalog']??'')!==$catalogHash || !empty($cache['work']['done'])) {
            $cache['work']=['catalog'=>$catalogHash,'ids'=>$ids,'olx_index'=>0,'allegro_index'=>0,'offset'=>0,'found'=>[],
                'olx'=>0,'allegro'=>0,'updated'=>0,'errors'=>0,'done'=>false,
                'counters_version'=>2,'olx_active'=>0,'allegro_offers'=>0,'allegro_active'=>0];
        }
        $cache['version']=1;$cache['next_at']=$now+5;admin_marketplace_cache_write($root,$cache);
        $work=&$cache['work'];
        // Compare the actual previous display, not a missing cache entry. Unknown -> unknown is not a changed status.
        $baseline=[];
        foreach (admin_product_marketplaces($products,$allegro,$olx) as $index=>$row) $baseline[$products[$index]['productId']??'']=$row;
        $save=static function(string $platform,string $id,array $row)use(&$cache,&$work,$baseline) {
            $previous=$cache['rows'][$id][$platform]??[];
            if (!isset($previous['updated'])) $previous=$baseline[$id][$platform]??admin_marketplace_unknown($platform);
            if (($previous['key']??null)!==$row['key'] || ($previous['id']??null)!==$row['id'] || ($previous['label']??null)!==$row['label']) $work['updated']++;
            $cache['rows'][$id][$platform]=$row;
        };
        $fail=static function(string $platform,array $affected,Throwable $e)use(&$cache,&$work,$now){
            $work['errors']++;foreach($affected as $id){$cache['rows'][$id][$platform]??=admin_marketplace_unknown($platform);
                $cache['rows'][$id][$platform]['refresh_error']=admin_marketplace_failure($e);$cache['rows'][$id][$platform]['attempted_at']=$now;}
        };
        // Skip unlinked products without contacting OLX; publication records are never written.
        try {
            $olx ??= olx_config();
            if (is_link($olx['dir']) || is_link($olx['dir'].'/publications') || !olx_outside_webroot($olx['dir'])) throw new RuntimeException('storage');
            while ($work['olx_index']<count($ids)) {
                $id=$ids[$work['olx_index']];
                try {
                    $binding=olx_publication_status($olx,$id);$advert=$binding['advert_id']??'';
                    if ($advert==='') {$work['olx_index']++;continue;}
                    if (!is_scalar($advert) || !preg_match('/^[1-9][0-9]{0,31}$/D',(string)$advert)
                        || ($binding['productId']??'')!==$id || ($binding['external_id']??'')!==$id) throw new RuntimeException('binding');
                    $response=$olxGet ? $olxGet('GET','/adverts/'.$advert,[]) : olx_advert_http($olx,'GET','/adverts/'.$advert);
                    $save('olx',$id,admin_marketplace_olx_observation($id,$binding,$response,$now));$work['olx']++;
                    if (($work['counters_version']??0)===2 && $response['data']['data']['status']==='active') $work['olx_active']++;
                } catch(Throwable $e) {$fail('olx',[$id],$e);if(str_contains($e->getMessage(),'HTTP 429'))$cache['next_at']=$now+60;}
                $work['olx_index']++;break;
            }
        } catch(Throwable $e) {$fail('olx',array_slice($ids,$work['olx_index']),$e);$work['olx_index']=count($ids);}
        admin_marketplace_cache_write($root,$cache);
        if ($work['allegro_index']<count($ids)) {
            $group=array_slice($ids,$work['allegro_index'],20);
            try {
                $allegro ??= allegro_config();if (($allegro['environment']??'')!=='PROD') throw new RuntimeException('PROD required');
                $path='/sale/offers?'.implode('&',array_map(static fn($id)=>'external.id='.$id,$group)).'&limit=100&offset='.$work['offset'];
                if (!allegro_pilot_path('GET',$path)) throw new RuntimeException('policy');
                if ($allegroGet) $data=$allegroGet($path);
                else {
                    allegro_validate_config($allegro,true);
                    $data=allegro_locked($allegro,static function(array $vault,string $key)use($allegro,$path){
                        if (!in_array('allegro:api:sale:offers:read',preg_split('/\s+/',(string)($vault['tokens']['scope']??'')),true)) throw new RuntimeException('scope');
                        $token=allegro_refresh_locked($allegro,$vault,$key,time(),null);
                        return allegro_pilot_api($allegro,'GET',$path,$token)['data'];
                    });
                }
                [$found,$offset,$total]=admin_marketplace_allegro_page($group,$data,$work['found'],$work['offset']);
                if ($work['offset']>0 && ($work['total']??null)!==$total) throw new RuntimeException('pagination changed');
                $work['found']=$found;$work['offset']=$offset;$work['total']=$total;
                if ($offset===$total) {
                    // Count offers, including ambiguous matches, only after validating the whole paginated group.
                    if (($work['counters_version']??0)===2) foreach ($found as $matches) foreach ($matches as $offer) {
                        $work['allegro_offers']++;
                        if ($offer['status']==='ACTIVE') $work['allegro_active']++;
                    }
                    foreach($group as $id){$save('allegro',$id,admin_marketplace_allegro_observation($id,$found[$id]??[],$now));$work['allegro']++;}
                    $work['allegro_index']+=count($group);$work['found']=[];$work['offset']=0;unset($work['total']);
                }
            } catch(Throwable $e) {$fail('allegro',$group,$e);$work['allegro_index']+=count($group);$work['offset']=0;$work['found']=[];unset($work['total']);
                if(str_contains($e->getMessage(),'HTTP 429'))$cache['next_at']=$now+60;}
        }
        $work['done']=$work['olx_index']>=count($ids) && $work['allegro_index']>=count($ids);
        if ($work['done']) { $cache['finished_at']=$now;if($work['errors']===0)$cache['last_success']=$now;$cache['next_at']=max($cache['next_at'],$now+60); }
        admin_marketplace_cache_write($root,$cache);return $cache;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function admin_marketplace_apply_cache(array $products,array $rows,array $cache,?string $context=null): array
{
    if (($cache['context']??null)!==($context??admin_marketplace_cache_context())) return $rows;
    foreach($products as $index=>$product) foreach(['allegro','olx'] as $platform) {
        $entry=$cache['rows'][$product['productId']??''][$platform]??null;
        if (!is_array($entry))continue;
        if (isset($entry['updated'])) $rows[$index][$platform]=$entry;
        if (!empty($entry['refresh_error'])) $rows[$index][$platform]['detail'].=' Aktualizacja nie powiodła się: '.$entry['refresh_error'];
    }
    return $rows;
}

/** Older in-progress caches cannot reconstruct these counters reliably; keep their progress, never guess. */
function admin_marketplace_sync_summary(array $work): array
{
    if (($work['counters_version']??0)!==2) return [
        'OLX: sprawdzone ogłoszenia: '.(int)($work['olx']??0).'.',
        'Allegro: sprawdzone produkty: '.(int)($work['allegro']??0).'.',
        'Starszy zapis: pełne liczniki ofert, aktywnych ofert i zmian będą dostępne od następnego pełnego odświeżenia. Błędy: '.(int)($work['errors']??0).'.'];
    return [
        'OLX: sprawdzone ogłoszenia: '.(int)$work['olx'].'. Aktywne według API: '.(int)$work['olx_active'].'.',
        'Allegro: sprawdzone produkty: '.(int)$work['allegro'].'. Znalezione oferty: '.(int)$work['allegro_offers'].', w tym aktywne: '.(int)$work['allegro_active'].'.',
        'Łącznie zmienione statusy: '.(int)$work['updated'].'. Błędy: '.(int)$work['errors'].'.'];
}
