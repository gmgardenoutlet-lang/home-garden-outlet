<?php
declare(strict_types=1);

function product_id_valid(string $id): bool
{
    return preg_match('/^prd_[a-f0-9]{32}$/D', $id) === 1;
}

function product_id_new(array $products): string
{
    $used = array_column($products, 'productId');
    do { $id = 'prd_' . bin2hex(random_bytes(16)); } while (in_array($id, $used, true));
    return $id;
}

function product_ids_summary(array $catalog): array
{
    if (!isset($catalog['products']) || !is_array($catalog['products'])) throw new RuntimeException('Nieprawidłowy katalog produktów.');
    $seen = []; $missing = 0; $invalid = 0; $duplicates = 0;
    foreach ($catalog['products'] as $product) {
        if (!is_array($product)) throw new RuntimeException('Nieprawidłowy rekord produktu.');
        $id = $product['productId'] ?? '';
        if ($id === '') { $missing++; continue; }
        if (!is_string($id) || !product_id_valid($id)) { $invalid++; continue; }
        if (isset($seen[$id])) $duplicates++;
        $seen[$id] = true;
    }
    return ['products' => count($catalog['products']), 'missing' => $missing, 'duplicates' => $duplicates, 'invalid' => $invalid];
}

/** Pure migration: no I/O; preserves all existing fields and order. */
function product_ids_prepare(array $catalog): array
{
    if (!isset($catalog['products']) || !is_array($catalog['products'])) {
        throw new RuntimeException('Nieprawidłowy katalog produktów.');
    }
    $seen = [];
    foreach ($catalog['products'] as $product) {
        if (!is_array($product)) throw new RuntimeException('Nieprawidłowy rekord produktu.');
        $id = $product['productId'] ?? '';
        if ($id !== '' && (!is_string($id) || !product_id_valid($id) || isset($seen[$id]))) {
            throw new RuntimeException('Nieprawidłowy lub powtórzony productId; migracja zatrzymana.');
        }
        if ($id !== '') $seen[$id] = true;
    }
    foreach ($catalog['products'] as &$product) {
        if (($product['productId'] ?? '') === '') {
            do { $id = 'prd_' . bin2hex(random_bytes(16)); } while (isset($seen[$id]));
            $product['productId'] = $id;
            $seen[$id] = true;
        }
    }
    unset($product);
    return $catalog;
}
