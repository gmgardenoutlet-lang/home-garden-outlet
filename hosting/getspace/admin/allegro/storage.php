<?php
declare(strict_types=1);

/** Pure, idempotent migration. Legacy fields remain an immutable historical snapshot. */
function allegro_offer_migrate(array $vault): array
{
    if (isset($vault['offersVersion'])) {
        if ($vault['offersVersion'] !== 2 || !is_array($vault['offers'] ?? null)) throw new AllegroSafeException('storage');
    } else {
        if (isset($vault['offers'])) throw new AllegroSafeException('storage');
        $vault['offers'] = [];
        if (!empty($vault['pilot'])) {
            $legacy = $vault['pilot'];
            if (!is_array($legacy) || !preg_match('/^prd_[a-f0-9]{32}$/D', (string)($legacy['productId'] ?? ''))) throw new AllegroSafeException('storage');
            $vault['offers'][$legacy['productId']] = $legacy;
        }
        $vault['offersVersion'] = 2;
    }
    $ids = [];
    foreach ($vault['offers'] as $pid => $record) {
        if (!preg_match('/^prd_[a-f0-9]{32}$/D', (string)$pid) || !is_array($record) || ($record['productId'] ?? null) !== $pid) throw new AllegroSafeException('storage');
        if (isset($record['offerId'])) {
            $id = $record['offerId'];
            if (!is_string($id) || !preg_match('/^[0-9]{1,32}$/D', $id) || isset($ids[$id])) throw new AllegroSafeException('storage');
            $ids[$id] = true;
        }
    }
    return $vault;
}

function allegro_storage_dir(array $config): string
{
    allegro_validate_config($config);
    $root = (string)realpath($config['storage_root']);
    $dir = $root . '/' . strtolower($config['environment']);
    $mask = umask(0077);
    try {
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700)) || !allegro_private_path($dir)
            || !chmod($dir, 0700)) throw new AllegroSafeException('storage');
    } finally { umask($mask); }
    return $dir;
}

function allegro_open_private(string $file)
{
    if (is_link($file)) throw new AllegroSafeException('storage');
    $mask = umask(0077);
    try { $stream = fopen($file, 'c+b'); }
    finally { umask($mask); }
    if ($stream === false || !chmod($file, 0600)) throw new AllegroSafeException('storage');
    return $stream;
}

function allegro_read_vault(array $config, string $key): array
{
    $file = allegro_storage_dir($config) . '/account.enc';
    if (!file_exists($file)) return allegro_context($config) + ['status' => 'disconnected'];
    if (is_link($file) || !is_file($file) || filesize($file) > 8388648) throw new AllegroSafeException('storage');
    $bytes = file_get_contents($file);
    if (!is_string($bytes) || strlen($bytes) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        throw new AllegroSafeException('storage');
    }
    $plain = sodium_crypto_secretbox_open(substr($bytes, 24), substr($bytes, 0, 24), $key);
    if ($plain === false) throw new AllegroSafeException('storage');
    try { $vault = json_decode($plain, true, 32, JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { throw new AllegroSafeException('storage'); }
    if (!is_array($vault)) throw new AllegroSafeException('storage');
    foreach (allegro_context($config) as $field => $expected) {
        if (($vault[$field] ?? null) !== $expected) throw new AllegroSafeException('storage');
    }
    return allegro_offer_migrate($vault);
}

/** Caller holds account.lock across read, mutation, transport and this atomic replacement. */
function allegro_write_vault(array $config, string $key, array $vault): void
{
    $dir = allegro_storage_dir($config);
    $file = $dir . '/account.enc';
    if (is_link($file)) throw new AllegroSafeException('storage');
    $vault = allegro_offer_migrate(array_replace($vault, allegro_context($config)));
    $nonce = random_bytes(24);
    try { $plain = json_encode($vault, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
    catch (Throwable $e) { throw new AllegroSafeException('storage'); }
    if (strlen($plain) > 8388608) throw new AllegroSafeException('storage');
    $bytes = $nonce . sodium_crypto_secretbox($plain, $nonce, $key);
    $mask = umask(0077);
    try { $temp = tempnam($dir, '.allegro-'); }
    finally { umask($mask); }
    if ($temp === false) throw new AllegroSafeException('storage');
    try {
        if (!chmod($temp, 0600)) throw new AllegroSafeException('storage');
        $stream = fopen($temp, 'wb');
        if ($stream === false) throw new AllegroSafeException('storage');
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)
                || (function_exists('fsync') && !fsync($stream))) throw new AllegroSafeException('storage');
        } finally { fclose($stream); }
        $renamed = false;
        // Windows may briefly deny replacement while a reader holds the old file.
        // Retry only the local atomic rename; never remove the old vault first.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (@rename($temp, $file)) { $renamed = true; break; }
            if (PHP_OS_FAMILY !== 'Windows') break;
            usleep(10000);
        }
        if (!$renamed) throw new AllegroSafeException('storage');
    } finally { if (is_file($temp)) unlink($temp); }
}

function allegro_locked(array $config, callable $operation)
{
    $key = allegro_validate_config($config);
    $lock = allegro_open_private(allegro_storage_dir($config) . '/account.lock');
    try {
        if (!flock($lock, LOCK_EX)) throw new AllegroSafeException('storage');
        $vault = allegro_read_vault($config, $key);
        if (!isset($vault['offersVersion'])) $vault = allegro_offer_migrate($vault);
        return $operation($vault, $key);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

/** Only fixed event/error codes and validated IDs are loggable. Arbitrary context is discarded. */
function allegro_log(array $config, string $event, array $context = []): void
{
    $events = ['device_start', 'device_pending', 'device_denied', 'device_expired', 'device_connected', 'refresh', 'disconnect', 'account_read', 'http'];
    $errors = ['ok', 'config', 'disabled', 'storage', 'state', 'session', 'denied', 'auth', 'network', 'refresh', 'policy', 'identity'];
    $record = ['timestamp' => gmdate('c'), 'operation' => in_array($event, $events, true) ? $event : 'http',
        'environment' => $config['environment'], 'correlationId' => bin2hex(random_bytes(12)),
        'errorCode' => in_array($context['errorCode'] ?? '', $errors, true) ? $context['errorCode'] : 'ok'];
    if (is_int($context['httpStatus'] ?? null) && $context['httpStatus'] >= 0 && $context['httpStatus'] <= 599) $record['httpStatus'] = $context['httpStatus'];
    if (preg_match('/^[0-9]{1,32}$/D', (string)($context['sellerId'] ?? ''))) $record['sellerId'] = $context['sellerId'];
    $dir = allegro_storage_dir($config);
    $lock = allegro_open_private($dir . '/log.lock');
    try {
        if (!flock($lock, LOCK_EX)) throw new AllegroSafeException('storage');
        $file = $dir . '/technical.jsonl';
        if (is_link($file) || is_link($file . '.1')) throw new AllegroSafeException('storage');
        clearstatcache(true, $file);
        if (is_file($file) && filesize($file) >= 262144 && !rename($file, $file . '.1')) throw new AllegroSafeException('storage');
        $stream = allegro_open_private($file);
        try {
            $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
            if (fseek($stream, 0, SEEK_END) !== 0 || fwrite($stream, $line) !== strlen($line) || !fflush($stream)) {
                throw new AllegroSafeException('storage');
            }
        } finally { fclose($stream); }
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
