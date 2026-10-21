<?php
declare(strict_types=1);
require __DIR__ . '/../hosting/getspace/admin/google-business-support.php';
function verify(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$config = ['account_id' => '108953707036443728677', 'location_id' => '12042445876206412182'];
$payload = ['imageUrl' => 'https://mgoutlet.pl/uploads/test.jpg', 'productUrl' => 'https://mgoutlet.pl/produkt/test', 'summary' => 'Test ławki'];
$photo = gbp_publication_request($config, $payload, 'photo_upload');
$post = gbp_publication_request($config, $payload, 'post_create');
verify(str_ends_with($photo['endpoint'], '/locations/12042445876206412182/media'), 'Media endpoint');
verify($photo['method'] === 'POST' && $photo['contentType'] === 'application/json', 'Transport');
verify($photo['payload'] === ['mediaFormat' => 'PHOTO', 'sourceUrl' => $payload['imageUrl'], 'locationAssociation' => ['category' => 'PRODUCT']], 'Photo fields');
verify($post['payload']['topicType'] === 'STANDARD' && $post['payload']['languageCode'] === 'pl-PL', 'Post type/language');
verify($post['payload']['callToAction'] === ['actionType' => 'LEARN_MORE', 'url' => $payload['productUrl']], 'CTA');
verify(!isset($post['payload']['media'][0]['locationAssociation']), 'Post media must not have location association');
foreach (['bad IDs' => array_replace($config, ['account_id' => 'accounts/123']), 'empty IDs' => []] as $label => $badConfig) {
    try { gbp_publication_request($badConfig, $payload, 'photo_upload'); throw new LogicException($label); }
    catch (RuntimeException $e) { verify(!$e instanceof LogicException, 'Invalid ID accepted'); }
}
gbp_register_secret('PRIVATE_CLIENT_SECRET');
gbp_register_secret('PRIVATE_REFRESH_VALUE');
gbp_register_secret('PRIVATE_ACCESS_VALUE');
$redacted = json_encode(gbp_safe(['message' => 'PRIVATE_CLIENT_SECRET PRIVATE_REFRESH_VALUE PRIVATE_ACCESS_VALUE Bearer abcdef',
    'details' => [['reason' => 'TEST_REASON', 'metadata' => ['refresh_token' => 'unregistered', 'fieldViolations' => [['field' => 'sourceUrl']]]]] ]));
verify(!str_contains($redacted, 'PRIVATE_') && !str_contains($redacted, 'unregistered') && !str_contains($redacted, 'abcdef'), 'Credential leak');
verify(str_contains($redacted, 'TEST_REASON') && str_contains($redacted, 'sourceUrl'), 'Diagnostic details lost');

verify(function_exists('imagewebp') && function_exists('imagejpeg'), 'GD WEBP/JPEG support required');
$root = sys_get_temp_dir() . '/gbp-image-test-' . bin2hex(random_bytes(6));
mkdir($root . '/uploads', 0700, true);
$original = $root . '/uploads/test.webp';
$image = imagecreatetruecolor(300, 300);
mt_srand(42);
for ($y = 0; $y < 300; $y++) for ($x = 0; $x < 300; $x++) imagesetpixel($image, $x, $y, mt_rand(0, 0xffffff));
imagewebp($image, $original, 90);
imagedestroy($image);
$hash = hash_file('sha256', $original);
try {
    $plan = gbp_google_image('https://mgoutlet.pl/uploads/test.webp', 'https://mgoutlet.pl', $root);
    verify(!$plan['prepared'] && !is_dir($root . '/uploads/google-business'), 'Preview wrote files');
    $result = gbp_google_image('https://mgoutlet.pl/uploads/test.webp', 'https://mgoutlet.pl', $root, true);
    $jpg = $root . parse_url($result['sourceUrl'], PHP_URL_PATH);
    verify($result['prepared'] && getimagesize($jpg)['mime'] === 'image/jpeg', 'JPG conversion');
    verify(hash_file('sha256', $original) === $hash, 'Original changed');
    verify(filesize($jpg) >= 10240 && filesize($jpg) <= 5 * 1024 * 1024, 'JPG size');
    verify(gbp_google_image('https://mgoutlet.pl/uploads/test.webp', 'https://mgoutlet.pl', $root)['prepared'], 'Cache reuse');
    try { gbp_google_image('https://external.example/test.webp', 'https://mgoutlet.pl', $root); throw new LogicException('External URL accepted'); }
    catch (RuntimeException $e) { verify(!$e instanceof LogicException, 'External image accepted'); }
} finally {
    foreach (glob($root . '/uploads/google-business/*') ?: [] as $file) unlink($file);
    if (is_dir($root . '/uploads/google-business')) rmdir($root . '/uploads/google-business');
    unlink($original); rmdir($root . '/uploads'); rmdir($root);
}
echo "Google Business payloads, redaction and isolated JPG conversion: PASS\n";
