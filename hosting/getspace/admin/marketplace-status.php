<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {http_response_code(404);exit;}

/** Display-only adapter. Never calls transport, token refresh, storage writers or directory/lock helpers. */
function admin_marketplace_unknown(string $module, string $detail='Brak wystarczających danych lokalnych.'): array
{
    return ['key'=>'unknown','label'=>'Status nieustalony','detail'=>$detail,'id'=>'','url'=>'/admin/'.$module.'/',
        'action'=>$module==='allegro'?'Przejdź do Allegro':'Sprawdź OLX','updated'=>null];
}
function admin_allegro_list_snapshot(?array $config=null): array
{
    require_once __DIR__.'/allegro/lib.php';
    $config ??= allegro_config();$key=allegro_validate_config($config);
    $dir=$config['storage_root'].'/'.strtolower($config['environment']);$file=$dir.'/account.enc';
    if (is_link($dir) || is_link($file)) throw new RuntimeException('Unsafe status source');
    if (!is_file($file)) return ['environment'=>$config['environment'],'pilot'=>[],'updated'=>null];
    if (!allegro_private_path($file) || @filesize($file)>131072) throw new RuntimeException('Unsafe status source');
    $bytes=@file_get_contents($file,false,null,0,131073);
    if (!is_string($bytes) || strlen($bytes)<40 || strlen($bytes)>131072) throw new RuntimeException('Invalid status source');
    $plain=sodium_crypto_secretbox_open(substr($bytes,24),substr($bytes,0,24),$key);
    if ($plain===false) throw new RuntimeException('Invalid status source');
    $vault=json_decode($plain,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($vault)) throw new RuntimeException('Invalid status source');
    foreach (allegro_context($config) as $field=>$expected) if (($vault[$field] ?? null)!==$expected) throw new RuntimeException('Wrong status context');
    $pilot=$vault['pilot'] ?? [];
    if (!is_array($pilot)) throw new RuntimeException('Invalid status source');
    // Project only public identifiers/status; credentials, payload and diagnostics never leave this function.
    return ['environment'=>$config['environment'],'pilot'=>array_intersect_key($pilot,array_flip(['productId','offerId','status'])),
        'updated'=>@filemtime($file) ?: null];
}
function admin_allegro_product_status(string $id, array $snapshot): array
{
    $out=admin_marketplace_unknown('allegro');$pilot=$snapshot['pilot'] ?? [];
    if (!preg_match('/^prd_[a-f0-9]{32}$/D',$id)) return $out;
    $out['url']='/admin/allegro/?productId='.rawurlencode($id);
    if (($snapshot['environment'] ?? '')!=='PROD') { $out['detail']='Brak potwierdzenia oferty produkcyjnej (konfiguracja niedostępna lub SANDBOX).';return $out; }
    if (($pilot['productId'] ?? '')!==$id) {$out['detail']='Brak powiązania w pojedynczym pilocie nie potwierdza braku oferty na koncie.';return $out;}
    $out['updated']=$snapshot['updated'] ?? null;
    $offer=$pilot['offerId'] ?? '';
    $valid=is_string($offer) && preg_match('/^[0-9]{1,32}$/D',$offer)===1;
    if ($valid) $out['id']=$offer;
    $status=$pilot['status'] ?? '';
    if ($status==='UNKNOWN' || in_array($status,['ERROR','FAILED'],true)) {
        $out['key']='error';$out['label']='Błąd / wynik niepewny';$out['action']='Sprawdź status';
    } elseif ($valid && in_array($status,['ACTIVE','INACTIVE','PROCESSING','ACTIVATING','ENDED'],true)) {
        [$out['key'],$out['label']]=match($status) {
            'ACTIVE'=>['active','Wystawione'],'INACTIVE'=>['draft','Szkic'],
            'PROCESSING','ACTIVATING'=>['publishing','Publikowanie'],default=>['inactive','Nieaktywne / zakończone']};
        $out['action']=$status==='ACTIVE'?'Otwórz Allegro':($status==='INACTIVE'?'Dokończ Allegro':'Sprawdź status');
        if ($status==='ACTIVE') $out['url']='https://allegro.pl/oferta/'.$offer;
    } else $out['action']='Sprawdź status';
    $out['detail']='Ostatni zapis lokalny; lista nie sprawdza bieżącego stanu przez API.';
    return $out;
}
function admin_olx_product_status(string $id, array $state, bool $draft=false, ?int $modified=null, ?int $now=null): array
{
    $out=admin_marketplace_unknown('olx');$now ??= time();
    if (!preg_match('/^prd_[a-f0-9]{32}$/D',$id)) return $out;
    $out['url']='/admin/olx/prepare.php?productId='.rawurlencode($id);$out['updated']=$modified;
    if ($state===[]) {
        if ($draft) {$out['key']='draft';$out['label']='Szkic (lokalny)';$out['action']='Dokończ OLX';$out['detail']='Zapisany szkic nie potwierdza braku innych ogłoszeń na koncie.';}
        return $out;
    }
    if (($state['productId'] ?? '')!==$id || ($state['external_id'] ?? '')!==$id) {$out['key']='error';$out['label']='Błąd powiązania';return $out;}
    $advert=(string)($state['advert_id'] ?? '');
    if (preg_match('/^[1-9][0-9]{0,31}$/D',$advert)) $out['id']=$advert;
    $phase=$state['phase'] ?? '';$status=$state['status'] ?? '';
    // Reuse the integration's label for known states, without exposing arbitrary error bodies.
    $known=['active','new','unconfirmed','unpaid','moderated','blocked','disabled','removed_by_moderator','outdated','inactive','removed_by_user','removed','limited','finished'];
    $out['detail']=in_array($status,$known,true) || in_array($phase,['publishing','uncertain','error'],true) ? olx_publication_label($state) : 'Niepotwierdzony status OLX.';
    if (in_array($phase,['uncertain','error'],true) || !empty($state['error_code'])) {$out['key']='error';$out['label']='Błąd / wynik niepewny';return $out;}
    if ($phase==='publishing') {$out['key']='publishing';$out['label']='Publikowanie';return $out;}
    if ($out['id']==='') return $out;
    if ($status==='active') {
        $verified=$state['verified_at'] ?? $state['reconciled_at'] ?? null;
        $expiry=isset($state['valid_to'])?strtotime((string)$state['valid_to']):false;
        if (!is_int($verified) || ($expiry!==false && $expiry<=$now)) {$out['detail']='Aktywność niepotwierdzona lub minął zapisany termin ważności. Sprawdź OLX.';return $out;}
        $out['key']='active';$out['label']='Wystawione';$out['updated']=$verified;
        $url=$state['url'] ?? '';
        if (is_string($url) && preg_match('~^https://(?:www\.)?olx\.pl/[^\s<>]*$~D',$url)) {$out['url']=$url;$out['action']='Otwórz OLX';}
    } elseif (in_array($status,['new','unconfirmed','unpaid'],true)) {$out['key']='publishing';$out['label']='Publikowanie';}
    elseif (in_array($status,['outdated','inactive','removed_by_user','removed','finished'],true)) {$out['key']='inactive';$out['label']='Nieaktywne';}
    elseif (in_array($status,['moderated','blocked','removed_by_moderator','disabled','limited'],true)) {$out['key']='error';$out['label']='Błąd / wymaga sprawdzenia';}
    return $out;
}
function admin_product_marketplaces(array $products, ?array $allegroConfig=null, ?array $olxConfig=null): array
{
    require_once __DIR__.'/olx/publish-lib.php';
    try {$snapshot=admin_allegro_list_snapshot($allegroConfig);} catch(Throwable $e) {$snapshot=[];}
    try {
        $olxConfig ??= olx_config();$root=$olxConfig['dir'];
        if (!is_dir($root) || is_link($root) || !olx_outside_webroot($root)) throw new RuntimeException('Unavailable status source');
        foreach (['publications','drafts'] as $folder) if (is_link($root.'/'.$folder)) throw new RuntimeException('Unsafe status source');
    } catch(Throwable $e) {$olxConfig=null;}
    $out=[];
    foreach ($products as $index=>$product) {
        $id=is_string($product['productId'] ?? null)?$product['productId']:'';
        $allegro=admin_allegro_product_status($id,$snapshot);$olx=admin_marketplace_unknown('olx');
        if ($olxConfig!==null && preg_match('/^prd_[a-f0-9]{32}$/D',$id)) {
            try {
                $file=$olxConfig['dir'].'/publications/'.$id.'.json';$draftFile=$olxConfig['dir'].'/drafts/'.$id.'.json';
                $state=@olx_publication_status($olxConfig,$id);
                if (is_file($file) && $state===[]) throw new RuntimeException('Invalid status source');
                $draft=$state===[] && is_array(@olx_json_read($draftFile));
                $olx=admin_olx_product_status($id,$state,$draft,is_file($file)?(@filemtime($file) ?: null):null);
            } catch(Throwable $e) {$olx=admin_marketplace_unknown('olx','Nie można bezpiecznie odczytać zapisanego statusu.');}
        }
        $out[$index]=['allegro'=>$allegro,'olx'=>$olx];
    }
    return $out;
}
function admin_marketplace_matches(array $row, string $filter): bool
{
    return match($filter) {
        'allegro-active'=>($row['allegro']['key'] ?? '')==='active','olx-active'=>($row['olx']['key'] ?? '')==='active',
        'allegro-draft'=>($row['allegro']['key'] ?? '')==='draft','olx-draft'=>($row['olx']['key'] ?? '')==='draft',
        'unknown'=>in_array($row['allegro']['key'] ?? '',['unknown','error'],true) || in_array($row['olx']['key'] ?? '',['unknown','error'],true),default=>true};
}
function admin_marketplace_timestamp(?int $stamp): string
{
    return $stamp ? (new DateTimeImmutable('@'.$stamp))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('d.m.Y H:i').' PL' : 'Brak daty potwierdzenia';
}
