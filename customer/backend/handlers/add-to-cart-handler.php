<?php
/**
 * FitPal Add to Cart Handler
 *
 * Processes adding a product to the customer's cart.
 * Thin coordinator - validation only, database work delegated to cart-queries.php.
 *
 * @package FitPal
 * @version 2.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authentication check
if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    $_SESSION['cart_error'] = 'Please sign in to add items to your cart.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/cart-queries.php';

// CSRF validation
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['cart_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/menu.php');
    exit;
}

// Input validation
$productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
$customerId = (int)$_SESSION['customer_id'];

if ($productId <= 0) {
    $_SESSION['cart_error'] = 'Invalid product selected.';
    header('Location: ../../pages/menu.php');
    exit;
}

if ($quantity < 1) {
    $_SESSION['cart_error'] = 'Quantity must be at least 1.';
    header('Location: ../../pages/menu.php');
    exit;
}

// Process add to cart (ACID compliant with row locking)
try {
    $success = addToCart($database_connection, $customerId, $productId, $quantity);

    if ($success) {
        $_SESSION['cart_success'] = 'Item added to cart successfully.';
    } else {
        $_SESSION['cart_error'] = 'Could not add item to cart.';
    }

    header('Location: ../../pages/menu.php');
    exit;

} catch (RuntimeException $e) {
    // Business logic errors (insufficient stock, product unavailable)
    $_SESSION['cart_error'] = $e->getMessage();
    header('Location: ../../pages/menu.php');
    exit;

} catch (PDOException $e) {
    // Database errors
    error_log('Add to cart database error: ' . $e->getMessage());
    $_SESSION['cart_error'] = 'A system error occurred. Please try again.';
    header('Location: ../../pages/menu.php');
    exit;
}