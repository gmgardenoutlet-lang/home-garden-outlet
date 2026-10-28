<?php
declare(strict_types=1);
// Strict cookie may be absent. Never start or replace an administrator session here.
require __DIR__ . '/lib.php';
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
$handle = '';
$config = null;
$message = 'Powrót z Allegro został sprawdzony. Trwa powrót do istniejącej sesji administratora.';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') throw new AllegroSafeException('state');
    foreach (['state', 'code', 'error'] as $field) {
        if (isset($_GET[$field]) && !is_string($_GET[$field])) throw new AllegroSafeException('state');
    }
    $config = allegro_config();
    $handle = allegro_callback($config, (string)($_GET['state'] ?? ''), (string)($_GET['code'] ?? ''), isset($_GET['error']), time());
} catch (Throwable $e) {
    if (is_array($config)) {
        try { allegro_log($config, 'oauth_callback', ['errorCode' => $e instanceof AllegroSafeException ? $e->kind : 'state']); }
        catch (Throwable $ignored) { /* No raw exceptions in the callback response or log. */ }
    }
    http_response_code(400);
    $message = 'Nie można potwierdzić powrotu z Allegro. Wróć do panelu i rozpocznij połączenie ponownie.';
}
?>
<!doctype html><html lang="pl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<?php if ($handle !== ''): ?><meta http-equiv="refresh" content="0;url=/admin/allegro/?resume=<?= htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
<title>Połączenie Allegro</title><h1>Połączenie Allegro</h1>
<p><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
<p><a href="/admin/allegro/<?= $handle !== '' ? '?resume=' . htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') : '' ?>">Wróć do panelu Allegro</a></p></html>
