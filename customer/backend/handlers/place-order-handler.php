<?php
/**
 * FitPal Place Order Handler
 *
 * The session order queue ($_SESSION['order_queue']) is the single
 * source of truth. This handler:
 *
 *   1. Validates the request (auth, CSRF, address, payment method).
 *   2. Calls createOrderFromQueue() to atomically insert the order,
 *      its queue_item rows, and its customization_instance rows.
 *   3. Clears the session queue on success.
 *   4. Redirects to orders.php with a success flash + highlight flag.
 *
 * There is no cart table involved anywhere in this flow.
 *
 * @package FitPal
 * @version 8.0 — Queue-authoritative; cart system deleted.
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

// ---- CSRF ----
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['order_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/checkout.php');
    exit;
}

$addressId     = isset($_POST['address_id']) ? (int)$_POST['address_id'] : 0;
$paymentMethod = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'COD';
$customerId    = (int)$_SESSION['customer_id'];

// ---- Payment method ----
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

// ---- Queue presence check ----
if (empty($_SESSION['order_queue']) || !is_array($_SESSION['order_queue'])) {
    $_SESSION['order_error'] = 'Your order is empty. Please add items before checkout.';
    header('Location: ../../pages/menu.php');
    exit;
}

// ===============================================================
// ATOMIC ORDER CREATION (queue → orders + queue_item + customizations)
// ===============================================================
try {
    $orderId = createOrderFromQueue(
        $database_connection,
        $customerId,
        $_SESSION['order_queue'],
        $fullAddress,
        $paymentMethod
    );

    // The order is now real. Clear everything that represented the
    // pre-order staging.
    unset($_SESSION['order_queue']);
    unset($_SESSION['checkout_address_id']);
    unset($_SESSION['checkout_error']);
    unset($_SESSION['queue_error']);

    $_SESSION['order_success']   = 'Order #' . $orderId . ' placed successfully!';
    $_SESSION['highlight_order'] = $orderId;

    header('Location: ../../pages/orders.php');
    exit;

} catch (RuntimeException $e) {
    $_SESSION['order_error'] = $e->getMessage();
    header('Location: ../../pages/checkout.php');
    exit;

} catch (PDOException $e) {
    error_log('Place order DB error: ' . $e->getMessage());
    $_SESSION['order_error'] = 'A system error occurred. Please try again.';
    header('Location: ../../pages/checkout.php');
    exit;
}