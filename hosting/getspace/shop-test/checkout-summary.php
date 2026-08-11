<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
shop_test_boot();
shop_test_require_sales();
$draft = $_SESSION['checkout_summary_draft'] ?? null;
if (!is_array($draft)) { header('Location: ' . shop_catalog_url() . '/zamowienie', true, 303); exit; }
$_POST = $draft;
try {
    shop_test_validate_checkout_customer_input();
    $customerData = shop_test_customer_from_post();
    $countryCode = $customerData['countryCode'];
    $foreign = $countryCode !== 'PL';
    $cart = shop_test_decode_cart((string) ($_POST['cart_payload'] ?? ''), shop_test_product_map());
    $items = []; $productsTotal = 0; $shippingTotal = 0; $quote = $foreign;
    foreach ($cart['items'] as $row) {
        $shipping = shop_test_resolve_item_delivery($row);
        if ($foreign) { $shipping['shippingLineCents'] = null; $shipping['shippingRequiresConfirmation'] = true; }
        $productsTotal += (int) $row['lineTotalCents'];
        if ($shipping['shippingLineCents'] === null) $quote = true; else $shippingTotal += (int) $shipping['shippingLineCents'];
        $items[] = ['name' => (string) $row['product']['name'], 'image' => (string) ($row['product']['image'] ?? ''), 'quantity' => (int) $row['quantity'], 'unitPriceCents' => (int) $row['priceCents'], 'lineTotalCents' => (int) $row['lineTotalCents']] + $shipping;
    }
    if ($foreign) $shippingTotal = null;
    $total = $shippingTotal === null ? null : $productsTotal + $shippingTotal;
} catch (Throwable $exception) { $_SESSION['checkout_old_input'] = $draft; header('Location: ' . shop_catalog_url() . '/zamowienie?summary_edit=1', true, 303); exit; }
function summary_price(?int $cents): string { return $cents === null ? 'Do indywidualnej wyceny' : number_format($cents / 100, 2, ',', ' ') . ' zł'; }
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Podsumowanie zamówienia | Home &amp; Garden Outlet</title><?php shop_test_stylesheets(); ?></head><body><?php shop_test_header('cart'); ?><main><section class="checkout-shell"><div class="checkout-form"><p class="eyebrow">Podsumowanie zamówienia</p><h1>Sprawdź dane przed złożeniem zamówienia</h1><section class="checkout-step"><h2>1. Produkty</h2><?php foreach ($items as $item): ?><p><?php if ($item['image'] !== ''): ?><img src="<?= e($item['image']) ?>" alt="" width="64" height="64"> <?php endif; ?><strong><?= e($item['name']) ?></strong> — <?= (int)$item['quantity'] ?> szt. × <?= e(summary_price($item['unitPriceCents'])) ?> = <?= e(summary_price($item['lineTotalCents'])) ?></p><?php endforeach; ?></section><section class="checkout-step"><h2>2. Dostawa</h2><?php foreach ($items as $item): ?><p><?= e((string)($item['shippingName'] ?? 'Dostawa')) ?>: <?= e(summary_price($item['shippingLineCents'] ?? null)) ?></p><?php endforeach; ?></section><section class="checkout-step"><h2>3. Dane kontaktowe</h2><p><?= e($customerData['customer']['firstName'].' '.$customerData['customer']['lastName']) ?><br><?= e($customerData['customer']['email']) ?><br><?= e($customerData['customer']['phone']) ?></p><h2>4. Adres dostawy</h2><p><?= e($customerData['deliveryAddress']['street']) ?><br><?= e($customerData['deliveryAddress']['postalCode'].' '.$customerData['deliveryAddress']['city']) ?><br><?= e($customerData['deliveryAddress']['country']) ?></p></section><?php if (!empty($customerData['invoice']['requested'])): ?><section class="checkout-step"><h2>5. Faktura</h2><p><?= e((string)($customerData['invoice']['companyName'] ?? '')) ?><br><?= e((string)($customerData['invoice']['nip'] ?? '')) ?><br><?= e((string)($customerData['invoice']['address'] ?? '')) ?></p></section><?php endif; ?><section class="checkout-step"><h2>6. Płatność</h2><p><?= $foreign ? 'Metodę płatności wybierzesz po wycenie dostawy.' : e(($draft['payment_method'] ?? '') === 'paynow' ? 'Płatność online Paynow' : 'Przelew tradycyjny') ?></p><h2>7. Razem</h2><p>Produkty: <?= e(summary_price($productsTotal)) ?><br>Dostawa: <?= e(summary_price($shippingTotal)) ?><br><strong><?= $total === null ? 'Kwota końcowa zostanie ustalona po wycenie dostawy.' : 'Razem do zapłaty: '.e(summary_price($total)) ?></strong></p></section><section class="checkout-step"><h2>8. Regulamin</h2><form method="post" action="/sklep/order"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="checkout_submission_token" value="<?= e((string)($draft['checkout_submission_token'] ?? '')) ?>"><input type="hidden" name="finalize_checkout" value="1"><label class="check"><input type="checkbox" name="terms" value="1" required><span>Akceptuję <a href="<?= e(shop_catalog_url()) ?>/regulamin" target="_blank" rel="noopener noreferrer">Regulamin</a> sklepu.</span></label><div class="shop-actions"><a class="btn btn-light" href="<?= e(shop_catalog_url()) ?>/zamowienie?summary_edit=1">Wróć i popraw dane</a><button class="btn" type="submit"><?= $foreign ? 'Złóż zamówienie do wyceny' : 'Kupuję i płacę' ?></button></div></form></section></div></section></main><?php shop_test_footer(); ?></body></html>
