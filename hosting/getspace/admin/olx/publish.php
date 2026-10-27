<?php
declare(strict_types=1);
require __DIR__.'/../lib.php';
require __DIR__.'/publish-lib.php';
boot_admin(); require_login();
header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store');
$config=olx_config();
$id=is_string($_GET['productId']??null)?$_GET['productId']:'';
$message=''; $prepared=null; $state=[]; $path=''; $approved=false;
try {
    $product=olx_find_product(load_catalog(),$id);
    $file=olx_publication_file($config,$id); $state=olx_json_read($file)??[];
    if(($_SERVER['REQUEST_METHOD']??'')==='POST') {
        try { require_csrf(); }
        catch(Throwable $e) { http_response_code(403); throw new OlxSafeException('Sesja formularza wygasła. Odśwież stronę.'); }
        $action=post_text('action');
        if($action==='publish') {
            $approval=$_SESSION['olx_publish_approval'][$id]??[];
            unset($_SESSION['olx_publish_approval'][$id]);
            if(post_text('confirm')!=='yes' || empty($approval['nonce']) || !hash_equals($approval['nonce'],post_text('nonce')) || ($approval['expires']??0)<time()) throw new OlxSafeException('Potwierdzenie wygasło lub nie zostało zaznaczone. Otwórz podgląd ponownie.');
            $safety=$approval['safety'];
            $state=olx_publish_one($config,$id,$approval['hash'],static function() use($config,$id,$safety) {
                return olx_publish_prepare_with_safety($config,load_catalog(),$id,$safety);
            },null,static function($prepared) use($config,$id) {
                return $prepared['product']===olx_find_product(load_catalog(),$id)
                    && $prepared['draft']===olx_json_read(olx_draft_file($config,$id));
            });
            header('Location: /admin/olx/publish.php?productId='.rawurlencode($id),true,303); exit;
        }
        if($action==='reconcile') {
            $state=olx_publication_locked($config,$id,static function($file,$state) use($config,$id) {
                $result=olx_advert_http($config,'GET','/adverts',['external_id'=>$id,'limit'=>100,'offset'=>0]);
                $rows=$result['data']['data']??null;
                if(($result['http']??0)!==200 || !is_array($rows)) throw new OlxSafeException('Nie udało się odczytać ogłoszeń. Blokada pozostaje.');
                if(count($rows)!==1 || ($rows[0]['external_id']??'')!==$id) throw new OlxSafeException('Nie potwierdzono jednego ogłoszenia. Blokada pozostaje; pusty wynik nie jest dowodem, że opóźnione tworzenie zakończyło się bez ogłoszenia.');
                $state=olx_publication_record($state+['productId'=>$id,'external_id'=>$id],$rows[0]);
                olx_json_write($file,$state); // Keep recovered ID even if the subsequent GET fails.
                olx_publication_audit($file,$state,'reconcile');
                $get=olx_advert_http($config,'GET','/adverts/'.$state['advert_id']);
                if(($get['http']??0)===200 && (string)($get['data']['data']['id']??'')===$state['advert_id']) {
                    $state=olx_publication_record($state,$get['data']['data']); $state['verified_at']=time();
                }
                olx_json_write($file,$state); return $state;
            });
            $message='Sprawdzono istniejące ogłoszenie. Nie wykonano POST.';
        } elseif($action==='review') {
            if(!empty($state['advert_id']) || in_array($state['phase']??'',['publishing','uncertain','created'],true)) throw new OlxSafeException('Publikacja jest zablokowana. Sprawdź istniejące ogłoszenie.');
            $safety=olx_publish_safety_input($_POST);
            $prepared=olx_publish_prepare_with_safety($config,load_catalog(),$id,$safety);
            $categories=olx_categories($config);
            $path=olx_category_path($prepared['category'],$categories);
            $_SESSION['olx_publish_approval'][$id]=['hash'=>olx_publication_fingerprint($prepared),'safety'=>$safety,'nonce'=>bin2hex(random_bytes(32)),'expires'=>time()+600];
            $approved=true;
        } elseif($action!=='reconcile') throw new OlxSafeException('Niedozwolona operacja.');
    }
} catch(Throwable $e) { $message=$e instanceof OlxSafeException?$e->getMessage():'Operacja nie powiodła się. Ponowna publikacja może być zablokowana; sprawdź stan.'; }
$approval=$_SESSION['olx_publish_approval'][$id]??[];
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>OLX — publikacja jednego produktu</title><link rel="stylesheet" href="/admin/style.css"><style>.olx-publication label{display:block;margin:12px 0;overflow-wrap:anywhere}.olx-publication input:not([type=checkbox]),.olx-publication textarea{display:block;width:100%;max-width:100%;min-width:0}.olx-publication input[type=checkbox]{width:20px;min-height:20px}.olx-publication fieldset{min-width:0;margin:16px 0}.olx-publication .btn{white-space:normal}.olx-publication code{overflow-wrap:anywhere}</style></head><body><main class="container olx-publication">
<p><a href="/admin/olx/">OLX</a> · <a href="/admin/olx/prepare.php?productId=<?= e($id) ?>">Wróć do edycji szkicu</a></p>
<h1>Wystaw jeden produkt na OLX</h1><p>Sprzedaż ani edycja produktu w sklepie nie zmieniają ogłoszenia OLX. Automatyczne odnawianie wyłączone.</p>
<?php if($message): ?><section class="card" role="alert"><p style="white-space:pre-wrap"><?= e($message) ?></p></section><?php endif; ?>
<section class="card"><h2>OLX: <?= e(olx_publication_label($state)) ?></h2>
<?php foreach(['advert_id'=>'ID OLX','external_id'=>'External ID','status'=>'Rzeczywisty status OLX','created_at'=>'Utworzono w OLX','activated_at'=>'Aktywowano','valid_to'=>'Ważne do','published_at'=>'Timestamp naszej publikacji'] as $field=>$label): if(isset($state[$field])): ?><p><?= e($label) ?>: <?= e((string)$state[$field]) ?></p><?php endif; endforeach; ?>
<?php if(!empty($state['url'])): ?><p><a href="<?= e($state['url']) ?>" rel="noopener noreferrer" target="_blank">Ogłoszenie OLX</a></p><?php endif; ?>
<?php foreach($state['errors']??[] as $error): ?><p><?= e($error) ?></p><?php endforeach; ?>
<?php if($state): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn btn-secondary" name="action" value="reconcile">Sprawdź stan w OLX — tylko odczyt</button></form><?php endif; ?></section>
<?php if(isset($product) && empty($state['advert_id']) && !in_array($state['phase']??'',['publishing','uncertain','created'],true)): ?>
<section class="card"><h2><?= e($product['name']) ?></h2><p>productId / external_id: <code><?= e($id) ?></code></p>
<?php if(!$approved): ?>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><h3>Dane bezpieczeństwa produktu do sprawdzenia przed publikacją</h3><p>Uzupełnij rzeczywiste dane wymagane dla tego produktu. Aktualny schemat OLX oznacza poniższe pola jako opcjonalne; wymagania konkretnej kategorii sprawdza również OLX. Nie zgadujemy danych producenta. Puste pola pomijamy w żądaniu.</p>
<label><input type="checkbox" name="placed_before_2024" value="yes"> Produkt wprowadzono na rynek przed 2024 r. — zaznacz tylko, jeśli możesz to potwierdzić.</label>
<?php foreach(['manufacturer'=>'Producent','contact_person'=>'Osoba odpowiedzialna w UE'] as $key=>$label): ?><fieldset><legend><?= e($label) ?></legend><?php foreach(['name'=>'Nazwa','country'=>'Kraj (kod ISO, np. PL)','address'=>'Adres','email'=>'E-mail'] as $field=>$label): ?><label><?= e($label) ?><input name="<?= e($key.'_'.$field) ?>" maxlength="500"></label><?php endforeach; ?></fieldset><?php endforeach; ?>
<label>Informacje i ostrzeżenia bezpieczeństwa<textarea name="warning_and_safety" maxlength="9000"></textarea></label>
<label><input type="checkbox" name="safety_confirm" value="yes" required> Sprawdziłem wymagania bezpieczeństwa dla tego produktu i podaję prawdziwe dane.</label>
<button class="btn" name="action" value="review">Sprawdź szkic i pokaż finalny podgląd</button></form>
<?php else: ?><h3>GOTOWE — finalny podgląd</h3><p>Kategoria: <?= e($path) ?></p>
<?php foreach($prepared['payload']['images'] as $image): ?><img src="<?= e($image['url']) ?>" alt="Wybrane zdjęcie produktu" style="max-width:120px;max-height:120px"><?php endforeach; ?>
<pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= e(json_encode($prepared['payload'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="nonce" value="<?= e($approval['nonce']) ?>">
<p><strong>Czy na pewno chcesz utworzyć ogłoszenie OLX dla tego produktu?</strong></p><label><input type="checkbox" name="confirm" value="yes" required> Tak, zatwierdzam ten produkt i cały powyższy podgląd.</label>
<button class="btn" name="action" value="publish">POTWIERDŹ I WYSTAW NA OLX</button></form>
<?php endif; ?></section><?php endif; ?></main></body></html>
