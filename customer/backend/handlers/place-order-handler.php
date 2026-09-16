<?php
/**
 * FitPal Place Order Handler
 *
 * Delegates the entire atomic order creation to
 * order-queries::createOrderFromCart().
 *
 * @package FitPal
 * @version 5.1 — Clears the session queue on success (so the menu
 *                page's queue panel is empty after a real order), and
 *                passes $customerId to getAddressById() (scoped since
 *                address-queries v2.1).
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    $_SESSION['order_error'] = 'Please sign in to place an order.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/address-queries.php';
require_once __DIR__ . '/../database/order-queries.php';

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['order_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/checkout.php');
    exit;
}

$addressId     = isset($_POST['address_id']) ? (int)$_POST['address_id'] : 0;
$paymentMethod = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'COD';
$customerId    = (int)$_SESSION['customer_id'];

$validPaymentMethods = ['COD', 'Wallet', 'Online'];
if (!in_array($paymentMethod, $validPaymentMethods, true)) {
    $_SESSION['order_error'] = 'Invalid payment method selected.';
    header('Location: ../../pages/checkout.php');
    exit;
}

// ---- Resolve the destination address (scoped to this customer) ----
$addressData = getAddressById($database_connection, $addressId, $customerId);
if (!$addressData) {
    $_SESSION['order_error'] = 'Invalid address selected.';
    header('Location: ../../pages/checkout.php');
    exit;
}

$addressParts = array_filter([
    $addressData['block']       ?? '',
    $addressData['barangay']    ?? '',
    $addressData['city']        ?? '',
    $addressData['province']    ?? '',
    $addressData['region']      ?? '',
    $addressData['postal_code'] ?? '',
    $addressData['country']     ?? 'Philippines',
]);
$fullAddress = implode(', ', $addressParts);

if ($fullAddress === '') {
    $_SESSION['order_error'] = 'Incomplete address. Please update your address.';
    header('Location: ../../pages/checkout.php');
    exit;
}

// ---- Atomic order creation ----
try {
    $orderId = createOrderFromCart(
        $database_connection,
        $customerId,
        $fullAddress,
        $paymentMethod
    );

    // The order is now real. Clear everything that represented the
    // pre-order staging: the session queue (menu page panel), the
    // cached checkout address, and any cart error banners.
    unset($_SESSION['order_queue']);
    unset($_SESSION['checkout_address_id']);
    unset($_SESSION['checkout_error']);
    unset($_SESSION['cart_success']);

    $_SESSION['order_success'] = 'Order #' . $orderId . ' placed successfully!';
    header('Location: ../../pages/order-confirmation.php?id=' . $orderId);
    exit;

} catch (RuntimeException $e) {
    $_SESSION['order_error'] = $e->getMessage();
    header('Location: ../../pages/checkout.php');
    exit;

} catch (PDOException $e) {
    error_log('Place order error: ' . $e->getMessage());
    $_SESSION['order_error'] = 'A system error occurred. Please try again.';
    header('Location: ../../pages/checkout.php');
    exit;
}