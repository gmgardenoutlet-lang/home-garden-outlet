<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
require __DIR__ . '/lib.php';
boot_admin();
require_login();
if (empty($_SESSION['olx_binding'])) $_SESSION['olx_binding'] = bin2hex(random_bytes(32));
$binding = hash('sha256', (string)$_SESSION['olx_binding']);
$config = null;
$ready = false;
$message = '';
$status = 'Niepołączone';

try {
    $config = olx_config();
    $preflight = olx_preflight($config);
    $ready = true;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_csrf();
        $action = post_text('action');
        if ($action === 'connect') {
            $url = olx_start($config, $binding, time());
            header('Location: ' . $url, true, 303);
            exit;
        } elseif ($action === 'disconnect' && post_text('confirm_disconnect') === 'yes') {
            olx_disconnect($config);
            unset($_SESSION['olx_binding']);
            flash('success', 'Lokalne połączenie OLX zostało usunięte. Ogłoszenia na OLX pozostają bez zmian.');
        } elseif ($action === 'check') {
            // Exercises on-demand automatic refresh without publishing anything.
            olx_check_account($config, time());
            flash('success', 'Autoryzowany GET konta OLX zakończył się sukcesem. Ważny token nie był odświeżany.');
        } else {
            throw new RuntimeException('Nieprawidłowa operacja lub brak potwierdzenia.');
        }
        header('Location: /admin/olx/', true, 303);
        exit;
    }
    $status = olx_status($config);
} catch (Throwable $e) {
    // Never display arbitrary exception bodies, paths or remote OAuth responses.
    $message = $e instanceof OlxSafeException ? $e->getMessage()
        : ($ready ? 'Operacja OLX nie powiodła się. Sprawdź sesję formularza lub rozpocznij połączenie ponownie.' : olx_error()->getMessage());
    if ($ready) {
        try { $status = olx_status($config); } catch (Throwable $ignored) { $status = 'Błąd autoryzacji'; }
    }
}
header('Referrer-Policy: no-referrer');
$flashes = pull_flashes();
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>OLX — Home &amp; Garden Outlet</title><link rel="stylesheet" href="/admin/style.css"></head>
<body><main class="narrow"><section class="card"><p><a href="/admin/">Wróć do panelu</a></p><h1>OLX</h1>
<p>Status konta: <strong><?= e($status) ?></strong></p>
<p>Konfiguracja: <?= $ready ? 'Gotowa' : 'Niekompletna lub zablokowana' ?>. Sekrety i tokeny są dostępne wyłącznie po stronie serwera.</p>
<p>Publikacja produktów pozostaje wyłączona na tym etapie.</p>
<?php if ($ready): ?><p>Preflight: konfiguracja, zapis magazynu, sodium i cURL — OK.</p><?php endif; ?>
<?php if ($message !== ''): ?><div class="flash flash-error"><?= e($message) ?></div><?php endif; ?>
<?php foreach ($flashes as $flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
<?php if ($ready): ?>
<form method="post" action="/admin/olx/"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="connect"><p><button class="btn" type="submit">Połącz konto OLX</button></p></form>
<?php if (in_array($status, ['Połączone', 'Wymaga ponownego połączenia'], true)): ?>
<form method="post" action="/admin/olx/"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="check"><p><button class="btn btn-secondary" type="submit">Sprawdź połączenie OLX (GET)</button></p></form>
<?php endif; ?>
<form method="post" action="/admin/olx/"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="disconnect"><p><label><input type="checkbox" name="confirm_disconnect" value="yes" required> Potwierdzam usunięcie lokalnych tokenów i oczekujących połączeń.</label></p><button class="btn btn-danger" type="submit">Rozłącz konto OLX</button><p>Nie kończy to ogłoszeń ani nie cofa zgody w ustawieniach konta OLX.</p></form>
<?php endif; ?></section></main></body></html>
