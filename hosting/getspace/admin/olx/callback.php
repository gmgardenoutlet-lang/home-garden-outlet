<?php
declare(strict_types=1);

// Do not boot_admin(): a missing Strict cookie must not create a replacement session.
require __DIR__ . '/lib.php';
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
$handle = '';
$message = 'Powrót z OLX został zweryfikowany. Wróć do swojej sesji administratora, aby dokończyć połączenie.';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') throw olx_error('state');
    foreach (['state', 'code', 'error'] as $field) {
        if (isset($_GET[$field]) && !is_string($_GET[$field])) throw olx_error('state');
    }
    $handle = olx_callback(olx_config(), (string)($_GET['state'] ?? ''), (string)($_GET['code'] ?? ''), isset($_GET['error']), time());
} catch (Throwable $e) {
    http_response_code(400);
    $message = 'Nie można zweryfikować powrotu z OLX. Wróć do panelu i rozpocznij połączenie ponownie.';
}
?>
<!doctype html><html lang="pl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Połączenie OLX</title><h1>Połączenie OLX</h1>
<p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
<p><a href="/admin/olx/<?= $handle !== '' ? '?resume=' . htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') : '' ?>">Wróć do panelu OLX</a></p></html>
