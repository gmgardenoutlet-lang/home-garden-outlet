<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
shop_test_boot();
header('X-Robots-Tag: noindex, nofollow, noarchive');
?><!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Status płatności | Home &amp; Garden Outlet</title><?php shop_test_stylesheets(); ?></head><body><?php shop_test_header(); ?><main class="order-result"><section class="success-box"><p class="eyebrow">Płatność</p><h1>Sprawdzamy status płatności</h1><p>Status widoczny w adresie nie potwierdza płatności. Ostateczne potwierdzenie następuje wyłącznie po zweryfikowanym powiadomieniu Paynow.</p></section></main><?php shop_test_footer(); ?></body></html>
