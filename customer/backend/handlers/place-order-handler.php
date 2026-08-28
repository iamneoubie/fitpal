<?php
/**
 * FitPal Place Order Handler
 *
 * Processes order creation from cart with ACID transaction support.
 * Thin coordinator - validation only, database work delegated to order-queries.php.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authentication check
if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    $_SESSION['order_error'] = 'Please sign in to place an order.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/order-queries.php';
require_once __DIR__ . '/../database/cart-queries.php';
require_once __DIR__ . '/../database/branch-queries.php';

// CSRF validation
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['order_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/checkout.php');
    exit;
}

// Input validation
$address = trim($_POST['address'] ?? '');
$paymentMethod = trim($_POST['payment_method'] ?? '');
$customerId = (int)$_SESSION['customer_id'];

if (empty($address)) {
    $_SESSION['order_error'] = 'Please enter a delivery address.';
    header('Location: ../../pages/checkout.php');
    exit;
}

$validPaymentMethods = ['COD', 'Wallet', 'Online'];
if (!in_array($paymentMethod, $validPaymentMethods, true)) {
    $_SESSION['order_error'] = 'Invalid payment method selected.';
    header('Location: ../../pages/checkout.php');
    exit;
}

// Quick check if cart is empty (no transaction needed yet)
$cartItems = getCustomerCart($database_connection, $customerId);
if (empty($cartItems)) {
    $_SESSION['order_error'] = 'Your cart is empty.';
    header('Location: ../../pages/menu.php');
    exit;
}

// Check branch consistency before transaction (quick validation)
$branchIds = getCartBranchIds($database_connection, $customerId);
if (count($branchIds) > 1) {
    $_SESSION['order_error'] = 'All items in your cart must be from the same restaurant branch.';
    header('Location: ../../pages/checkout.php');
    exit;
}

// Process order (ACID compliant with row locking)
try {
    $orderId = createOrderFromCart(
        $database_connection,
        $customerId,
        $address,
        $paymentMethod
    );

    $_SESSION['order_success'] = 'Order #' . $orderId . ' placed successfully.';
    header('Location: ../../pages/order-confirmation.php?id=' . $orderId);
    exit;

} catch (RuntimeException $e) {
    // Business logic errors (insufficient stock, branch mismatch, empty cart, etc.)
    $_SESSION['order_error'] = $e->getMessage();
    header('Location: ../../pages/checkout.php');
    exit;

} catch (PDOException $e) {
    // Database errors
    error_log('Order database error: ' . $e->getMessage());
    $_SESSION['order_error'] = 'A system error occurred. Please try again.';
    header('Location: ../../pages/checkout.php');
    exit;
}