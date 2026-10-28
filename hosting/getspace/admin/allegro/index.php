<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
require __DIR__ . '/lib.php';
boot_admin();
require_login();
header('Referrer-Policy: no-referrer');
if (empty($_SESSION['allegro_binding'])) $_SESSION['allegro_binding'] = bin2hex(random_bytes(32));
$binding = hash('sha256', (string)$_SESSION['allegro_binding']);
$config = null;
$storageReady = false;
$oauthReady = false;
$message = '';
$autoFinish = false;
$status = ['status' => 'disconnected', 'account' => null, 'expires_at' => null, 'lastCheckedAt' => null];
$resume = is_string($_GET['resume'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $_GET['resume']) ? $_GET['resume'] : '';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        foreach (['csrf', 'action', 'resume', 'confirm_disconnect'] as $field) {
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
        if ($action === 'connect') {
            header('Location: ' . allegro_start($config, $binding, time()), true, 303);
            exit;
        } elseif ($action === 'finish') {
            allegro_finish($config, post_text('resume'), $binding, time());
            flash('success', 'Konto Allegro zostało połączone i zweryfikowane.');
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
    $status = allegro_public_status($config);
    if ($oauthReady && $resume !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $autoFinish = allegro_pending_ready($config, $resume, $binding, time());
        if (!$autoFinish) $message = 'Połączenie wygasło lub należy do innej sesji. Rozpocznij je ponownie.';
    }
} catch (Throwable $e) {
    http_response_code(400);
    $message = $e instanceof AllegroSafeException ? $e->getMessage() : 'Operacja Allegro nie powiodła się. Sprawdź sesję formularza i konfigurację.';
    if ($storageReady) {
        try { allegro_log($config, 'http', ['errorCode' => $e instanceof AllegroSafeException ? $e->kind : 'session']); }
        catch (Throwable $ignored) {}
        try { $status = allegro_public_status($config); } catch (Throwable $ignored) {}
    }
}
$flashes = pull_flashes();
$labels = ['connected' => 'Połączono', 'reconnect' => 'Wymaga ponownego połączenia', 'disconnected' => 'Nie połączono'];
$expires = is_int($status['expires_at']) ? (new DateTimeImmutable('@' . $status['expires_at']))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('d.m.Y H:i:s T') : '—';
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Allegro — Home &amp; Garden Outlet</title><link rel="stylesheet" href="/admin/style.css"><?php if ($autoFinish): ?><script src="/admin/allegro/bridge.js" defer></script><?php endif; ?></head>
<body><main class="narrow"><section class="card"><p><a href="/admin/">Wróć do panelu</a></p><h1>ALLEGRO</h1>
<p>Etap B: obsługa połączenia konta. Operacje na produktach, ofertach i zdjęciach Allegro są zablokowane.</p>
<dl>
<dt>Środowisko</dt><dd><strong><?= e(($config['environment'] ?? '') === 'PROD' ? 'PRODUKCJA' : (($config['environment'] ?? '') === 'SANDBOX' ? 'SANDBOX' : 'Nie ustalono')) ?></strong></dd>
<dt>Konfiguracja OAuth</dt><dd><?= $oauthReady ? 'Gotowa' : 'Niekompletna lub zablokowana' ?></dd>
<dt>Prywatny magazyn</dt><dd><?= $storageReady ? 'Dostępny' : 'Niedostępny lub niebezpieczna konfiguracja' ?></dd>
<dt>OAuth w konfiguracji</dt><dd><?= !empty($config['enabled']) ? 'Włączony' : 'Wyłączony' ?></dd>
<dt>Ochrona logów callbacku hostingu</dt><dd><?= !empty($config['logs_safe']) ? 'Potwierdzona w konfiguracji operatora' : 'Niepotwierdzona — BLOCKER PRZED PRODUKCYJNYM OAUTH' ?></dd>
<dt>Status</dt><dd><?= e($labels[$status['status']] ?? 'Nie można ustalić') ?></dd>
<dt>Konto</dt><dd><?= e($status['account']['login'] ?? '—') ?></dd>
<dt>Seller ID</dt><dd><?= e($status['account']['id'] ?? '—') ?></dd>
<dt>Token ważny do</dt><dd><?= e($expires) ?></dd>
<dt>Ostatnia diagnostyka</dt><dd><?= e($status['lastCheckedAt'] ?? '—') ?></dd>
</dl>
<?php if ($message !== ''): ?><div class="flash flash-error"><?= e($message) ?></div><?php endif; ?>
<?php foreach ($flashes as $flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
<?php if ($oauthReady): ?>
<?php if ($autoFinish): ?><form data-allegro-finish method="post" action="/admin/allegro/"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="finish"><input type="hidden" name="resume" value="<?= e($resume) ?>"><p>Trwa dokończenie połączenia. Kod Allegro jest ważny tylko 10 sekund. Jeśli połączenie wygaśnie, rozpocznij je ponownie.</p><button class="btn" type="submit">Dokończ połączenie Allegro</button></form><?php endif; ?>
<form method="post" action="/admin/allegro/"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="connect"><p><button class="btn" type="submit">Połącz z Allegro</button></p></form>
<?php if ($status['status'] === 'connected'): ?>
<form method="post" action="/admin/allegro/"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="check"><p><button class="btn btn-secondary" type="submit">Sprawdź połączenie — odczyt konta</button></p></form>
<?php endif; ?>
<?php endif; ?>
<?php if ($storageReady): ?>
<form method="post" action="/admin/allegro/"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="disconnect"><p><label><input type="checkbox" name="confirm_disconnect" value="yes" required> Potwierdzam usunięcie lokalnego połączenia.</label></p><button class="btn btn-danger" type="submit">Odłącz konto</button><p>Nie unieważnia zgody na Allegro i nie zmienia ofert.</p></form>
<?php endif; ?></section></main></body></html>
