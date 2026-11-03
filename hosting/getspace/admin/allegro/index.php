<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
require __DIR__ . '/lib.php';
boot_admin();
require_login();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['pilot_form_script'] ?? '') === '1') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo allegro_pilot_form_script();
    exit;
}
header('Referrer-Policy: no-referrer');
if (empty($_SESSION['allegro_binding'])) $_SESSION['allegro_binding'] = bin2hex(random_bytes(32));
$binding = hash('sha256', (string)$_SESSION['allegro_binding']);
$config = null;
$storageReady = false;
$oauthReady = false;
$message = '';
$status = ['device' => null, 'status' => 'disconnected', 'account' => null, 'expires_at' => null, 'lastCheckedAt' => null];
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        foreach (['csrf', 'action', 'confirm_disconnect'] as $field) {
            if (isset($_POST[$field]) && !is_string($_POST[$field])) throw new AllegroSafeException('session');
        }
        require_csrf();
    }
    $config = allegro_config();
    allegro_validate_config($config);
    $storageReady = true;
    try { allegro_validate_config($config, true); $oauthReady = true; }
    catch (AllegroSafeException $e) { /* Show safe readiness indicators below, never config values. */ }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = post_text('action');
        if (str_starts_with($action, 'pilot-')) {
            allegro_pilot_action($config, $action);
            flash('success', 'Operacja pilota zakończona. Sprawdź podgląd lub końcowy status.');
        } elseif ($action === 'connect') {
            $current = allegro_public_status($config, $binding);
            if ($current['status'] === 'connected' && post_text('confirm_reauthorize') !== 'yes') throw new AllegroSafeException('session');
            allegro_device_start($config, $binding, time());
            flash('success', 'Po zatwierdzeniu wróć tutaj i sprawdź połączenie.');
        } elseif ($action === 'check-device') {
            $result = allegro_device_poll($config, $binding, time());
            $notice = ['pending' => 'Allegro czeka jeszcze na potwierdzenie konta.',
                'connected' => 'Konto Allegro zostało połączone i zweryfikowane.',
                'denied' => 'Odmówiono połączenia konta. Możesz rozpocząć ponownie.',
                'expired' => 'Kod wygasł. Rozpocznij połączenie ponownie.'];
            flash('success', $notice[$result]);
        } elseif ($action === 'disconnect' && post_text('confirm_disconnect') === 'yes') {
            allegro_disconnect($config);
            unset($_SESSION['allegro_binding']);
            flash('success', 'Usunięto lokalne tokeny i oczekujące połączenia. Zgoda na Allegro nie została cofnięta; możesz usunąć aplikację w Powiązanych aplikacjach Allegro.');
        } elseif ($action === 'check') {
            allegro_diagnose($config, time());
            flash('success', 'Potwierdzono tożsamość konta przez odczyt Allegro.');
        } else { throw new AllegroSafeException('policy'); }
        header('Location: /admin/allegro/', true, 303);
        exit;
    }
    $status = allegro_public_status($config, $binding);
} catch (Throwable $e) {
    http_response_code(400);
    $message = $e instanceof AllegroSafeException || ($e instanceof RuntimeException && str_starts_with((string)($_POST['action'] ?? ''), 'pilot-')) ? $e->getMessage() : 'Operacja Allegro nie powiodła się. Sprawdź sesję formularza i konfigurację.';
    if ($storageReady) {
        try { allegro_log($config, 'http', ['errorCode' => $e instanceof AllegroSafeException ? $e->kind : 'session']); }
        catch (Throwable $ignored) {}
        try { $status = allegro_public_status($config, $binding); } catch (Throwable $ignored) {}
    }
}
$flashes = pull_flashes();
$labels = ['connected' => 'Połączono', 'reconnect' => 'Wymaga ponownego połączenia', 'disconnected' => 'Nie połączono'];
$expires = is_int($status['expires_at']) ? (new DateTimeImmutable('@' . $status['expires_at']))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('d.m.Y H:i:s T') : '—';
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Allegro — Home &amp; Garden Outlet</title><link rel="stylesheet" href="/admin/style.css"><script src="/admin/allegro/?pilot_form_script=1" defer></script><style><?= allegro_pilot_styles() ?></style></head>
<body><main class="allegro-workspace"><header class="allegro-top"><h1>Allegro — Home &amp; Garden Outlet</h1><div class="allegro-status"><span>Połączenie: <strong><?= e($labels[$status['status']] ?? 'Nie można ustalić') ?></strong></span><span>Konto: <?= e($status['account']['login'] ?? '—') ?></span><a href="/admin/">Wróć do panelu</a></div></header>
<div class="allegro-notices" aria-live="polite"><?php if ($message !== ''): ?><div class="flash flash-error" role="alert"><?= e($message) ?></div><?php endif; ?><?php foreach ($flashes as $flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?></div>
<details class="allegro-connection"<?= $status['status'] !== 'connected' || is_array($status['device']) ? ' open' : '' ?>><summary>Połączenie i ustawienia konta</summary><div class="allegro-connection-body">
<dl>
<dt>Środowisko</dt><dd><strong><?= e(($config['environment'] ?? '') === 'PROD' ? 'PRODUKCJA' : (($config['environment'] ?? '') === 'SANDBOX' ? 'SANDBOX' : 'Nie ustalono')) ?></strong></dd>
<dt>Konfiguracja OAuth</dt><dd><?= $oauthReady ? 'Gotowa' : 'Niekompletna lub zablokowana' ?></dd>
<dt>Prywatny magazyn</dt><dd><?= $storageReady ? 'Dostępny' : 'Niedostępny lub niebezpieczna konfiguracja' ?></dd>
<dt>OAuth w konfiguracji</dt><dd><?= !empty($config['enabled']) ? 'Włączony' : 'Wyłączony' ?></dd>
<dt>Tryb połączenia</dt><dd>Device Flow — wymaga aplikacji Allegro typu Device</dd>
<dt>Status</dt><dd><?= e($labels[$status['status']] ?? 'Nie można ustalić') ?></dd>
<dt>Konto</dt><dd><?= e($status['account']['login'] ?? '—') ?></dd>
<dt>Seller ID</dt><dd><?= e($status['account']['id'] ?? '—') ?></dd>
<dt>Token ważny do</dt><dd><?= e($expires) ?></dd>
<dt>Ostatnia diagnostyka</dt><dd><?= e($status['lastCheckedAt'] ?? '—') ?></dd>
</dl>
<?php if ($oauthReady): ?>
<form method="post" action="/admin/allegro/"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="connect"><?php if ($status['status'] === 'connected'): ?><p>Uprawnienia sprzedaży: <?= !empty($status['sales_ready']) ? 'Gotowe' : 'Wymagana nowa zgoda' ?>.</p><p><label><input type="checkbox" name="confirm_reauthorize" value="yes" required> Potwierdzam ponowną autoryzację z czterema scope’ami pilota. Dotychczasowy token pozostaje do czasu zakończenia nowej zgody.</label></p><?php endif; ?><p><button class="btn" type="submit"><?= $status['status'] === 'connected' ? 'Rozszerz zgodę Allegro — Device Flow' : 'Połącz z Allegro' ?></button></p></form>
<?php if (is_array($status['device'])): $device = $status['device']; ?>
<h2>Połącz konto Allegro</h2>
<p>Kod: <strong><?= e(trim(chunk_split($device['user_code'], 3, ' '))) ?></strong></p>
<p><a class="btn" href="<?= e($device['verification_uri_complete']) ?>" target="_blank" rel="noopener noreferrer">Otwórz Allegro i potwierdź</a></p>
<p>Ważny do: <?= e(gmdate('H:i:s', $device['expires'])) ?> UTC. Po zatwierdzeniu wróć tutaj i sprawdź połączenie.</p>
<form method="post" action="/admin/allegro/"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="check-device"><button class="btn" type="submit">Sprawdź, czy konto zostało połączone</button></form>
<?php endif; ?>
<?php if ($status['status'] === 'connected'): ?>
<form method="post" action="/admin/allegro/"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="check"><p><button class="btn btn-secondary" type="submit">Sprawdź połączenie — odczyt konta</button></p></form>
<?php endif; ?>
<?php endif; ?>
<?php if ($storageReady): ?>
<form method="post" action="/admin/allegro/"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="disconnect"><p><label><input type="checkbox" name="confirm_disconnect" value="yes" required> Potwierdzam usunięcie lokalnego połączenia.</label></p><button class="btn btn-danger" type="submit">Odłącz konto</button><p>Nie unieważnia zgody na Allegro i nie zmienia ofert.</p></form>
<?php endif; ?></div></details><?php if ($storageReady && $config !== null) allegro_pilot_render($config, $status); ?></main></body></html>
