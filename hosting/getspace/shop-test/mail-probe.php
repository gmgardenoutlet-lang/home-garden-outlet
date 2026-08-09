<?php
declare(strict_types=1);

require __DIR__ . '/../admin/lib.php';
boot_admin();
header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo '{"error":"method_not_allowed"}';
    exit;
}

$marker = STORAGE_DIR . '/.mail-probe-20260809';
if (is_file($marker)) {
    http_response_code(409);
    echo '{"error":"already_attempted"}';
    exit;
}

$available = function_exists('mail');
$accepted = false;
if ($available) {
    $headers = [
        'From: Home & Garden Outlet <biuro@mgoutlet.pl>',
        'Reply-To: biuro@mgoutlet.pl',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    $accepted = @mail(
        'biuro@mgoutlet.pl',
        'TEST MGOUTLET - PHP MAIL',
        'To jest jednorazowy test techniczny wysyłki e-mail z mgoutlet.pl.',
        implode("\r\n", $headers)
    );
}

@file_put_contents($marker, gmdate(DATE_ATOM) . PHP_EOL, LOCK_EX);
echo json_encode(['mail_available' => $available, 'accepted' => $accepted], JSON_UNESCAPED_SLASHES);
