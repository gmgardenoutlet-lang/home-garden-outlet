<?php
// A clean same-origin document restores Strict-cookie navigation without opening a session.
declare(strict_types=1);
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="pl"><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=/admin/olx/?connected=1">
<title>Powrót do panelu OLX</title><p>Powrót do panelu administratora.</p><a href="/admin/olx/?connected=1">Wróć do panelu OLX</a></html>
