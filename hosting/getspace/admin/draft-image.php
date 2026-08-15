<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
boot_admin();
require_login();

$id = product_image_draft_id((string)($_GET['id'] ?? ''));
$file = basename((string)($_GET['file'] ?? ''));
$draft = $id === '' ? null : load_product_image_draft($id);
$allowed = [];
foreach ((array)($draft['images'] ?? []) as $image) {
    if (is_array($image) && isset($image['prepared'])) $allowed[] = (string)$image['prepared'];
}
if (!$draft || !in_array($file, $allowed, true)) {
    http_response_code(404);
    exit;
}
$path = product_image_draft_path($id) . '/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/webp');
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
