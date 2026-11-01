<?php
declare(strict_types=1);
// Historical route only. Never read query values, create a session or enter Device Flow.
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
http_response_code(400);
?>
<!doctype html><html lang="pl"><meta charset="utf-8"><title>Połączenie Allegro</title>
<h1>Połączenie Allegro</h1><p>Nie można potwierdzić powrotu z Allegro. Połączenie konta rozpocznij w panelu przez Device Flow.</p>
<p><a href="/admin/allegro/">Wróć do panelu Allegro</a></p></html>
