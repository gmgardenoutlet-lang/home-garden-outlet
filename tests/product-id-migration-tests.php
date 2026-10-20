<?php
declare(strict_types=1);
$base = sys_get_temp_dir() . '/hgo-id-test-' . bin2hex(random_bytes(8));
mkdir($base . '/web/data', 0700, true);
mkdir($base . '/backups', 0700);
$file = $base . '/web/data/products.json';
$original = json_encode(['version' => 9, 'products' => [['name' => 'Fixture', 'slug' => 'unchanged-url', 'price' => 123]]]);
file_put_contents($file, $original);
$run = static function (array $options) use ($file): array {
    $command = array_merge([PHP_BINARY, __DIR__ . '/../scripts/migrate-product-ids.php', '--products=' . $file], $options);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $output];
};
$assert = static function (bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL: ' . $label); };
try {
    [$exit] = $run([]);
    $assert($exit === 0 && file_get_contents($file) === $original, 'dry run does not change file');
    [$exit] = $run(['--apply']);
    $assert($exit !== 0 && file_get_contents($file) === $original, 'apply requires maintenance/hash');
    [$exit] = $run(['--apply', '--maintenance-confirmed', '--expect-sha256=' . str_repeat('0', 64), '--backup-dir=' . $base . '/backups']);
    $assert($exit !== 0 && file_get_contents($file) === $original, 'wrong hash stops migration');
    [$exit] = $run(['--apply', '--maintenance-confirmed', '--expect-sha256=' . hash('sha256', $original), '--backup-dir=' . $base . '/web/data']);
    $assert($exit !== 0 && file_get_contents($file) === $original, 'public backup rejected');
    [$exit, $output] = $run(['--apply', '--maintenance-confirmed', '--expect-sha256=' . hash('sha256', $original), '--backup-dir=' . $base . '/backups']);
    $assert($exit === 0, 'synthetic migration applies: ' . $output);
    $assert(str_contains($output, 'Dane poza productId: BEZ ZMIAN') && str_contains($output, 'SHA256 po:'), 'post-migration verification report');
    $migrated = file_get_contents($file);
    $data = json_decode($migrated, true);
    $assert(preg_match('/^prd_[a-f0-9]{32}$/D', $data['products'][0]['productId']) === 1, 'ID format');
    unset($data['products'][0]['productId']);
    $assert($data === json_decode($original, true), 'all source data preserved');
    $backups = glob($base . '/backups/*');
    $assert(count($backups) === 1 && file_get_contents($backups[0]) === $original, 'byte-exact backup');
    [$exit] = $run(['--apply', '--maintenance-confirmed', '--expect-sha256=' . hash('sha256', $migrated), '--backup-dir=' . $base . '/backups']);
    $assert($exit === 0 && file_get_contents($file) === $migrated && count(glob($base . '/backups/*')) === 1, 'repeat migration no-op');
    echo "PASS: migration CLI dry-run, hash gate, maintenance gate, private backup, preservation, idempotence\n";
} finally {
    foreach (glob($base . '/backups/*') ?: [] as $path) unlink($path);
    foreach (glob($base . '/web/data/*') ?: [] as $path) unlink($path);
    rmdir($base . '/web/data'); rmdir($base . '/web'); rmdir($base . '/backups'); rmdir($base);
}
