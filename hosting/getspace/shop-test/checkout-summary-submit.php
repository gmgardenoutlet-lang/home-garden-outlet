<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
shop_test_boot();
shop_test_require_sales();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Nieprawidłowa metoda żądania.');
    require_csrf();
    shop_test_validate_checkout_customer_input();
    $countryCode = shop_test_effective_country_code((string) ($_POST['delivery_country'] ?? 'PL'));
    $foreign = $countryCode !== 'PL';
    if (!$foreign) {
        $method = (string) ($_POST['payment_method'] ?? '');
        if (!in_array($method, ['bank_transfer', 'paynow'], true) || empty(shop_payment_methods()[$method])) throw new RuntimeException('Wybrana metoda płatności nie jest dostępna.');
        $cart = shop_test_decode_cart((string) ($_POST['cart_payload'] ?? ''), shop_test_product_map());
        foreach ($cart['items'] as $row) shop_test_resolve_item_delivery($row);
    } else {
        shop_test_decode_cart((string) ($_POST['cart_payload'] ?? ''), shop_test_product_map());
        unset($_POST['payment_method']);
    }
    $_SESSION['checkout_summary_draft'] = $_POST;
    header('Location: ' . shop_catalog_url() . '/zamowienie/podsumowanie', true, 303);
    exit;
} catch (ShopCheckoutValidationException $exception) {
    shop_test_checkout_remember_validation_error($exception->errors, $exception->oldInput);
    header('Location: ' . shop_catalog_url() . '/zamowienie', true, 303);
    exit;
} catch (Throwable $exception) {
    shop_test_checkout_remember_validation_error(['checkout' => $exception->getMessage()], $_POST);
    header('Location: ' . shop_catalog_url() . '/zamowienie', true, 303);
    exit;
}
