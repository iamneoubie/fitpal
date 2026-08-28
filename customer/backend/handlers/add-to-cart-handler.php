<?php
/**
 * FitPal Add to Cart Handler
 *
 * Processes adding a product to the customer's cart.
 * Uses a transaction for atomicity (ACID).
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not logged in
if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    $_SESSION['cart_error'] = 'Please sign in to add items to your cart.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

// ===== CSRF =====
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['cart_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/menu.php');
    exit;
}

// ===== Input =====
$productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
$customerId = (int)$_SESSION['customer_id'];

if ($productId <= 0 || $quantity < 1) {
    $_SESSION['cart_error'] = 'Invalid product or quantity.';
    header('Location: ../../pages/menu.php');
    exit;
}

try {
    // Start transaction
    $database_connection->beginTransaction();

    // 1. Check product exists, is active, and has sufficient stock
    $stmt = $database_connection->prepare(
        "SELECT stock, is_active FROM product WHERE product_id = :id FOR UPDATE"
    );
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product || !$product['is_active']) {
        throw new Exception('Product not available.');
    }
    if ($product['stock'] < $quantity) {
        throw new Exception('Not enough stock available.');
    }

    // 2. Insert or update cart
    // Use ON DUPLICATE KEY UPDATE to atomically adjust quantity
    $stmt = $database_connection->prepare(
        "INSERT INTO cart (customer_id, product_id, quantity, price)
         VALUES (:customer_id, :product_id, :quantity, (SELECT price FROM product WHERE product_id = :product_id2))
         ON DUPLICATE KEY UPDATE quantity = quantity + :quantity_add"
    );
    $stmt->execute([
        ':customer_id' => $customerId,
        ':product_id' => $productId,
        ':quantity' => $quantity,
        ':product_id2' => $productId,
        ':quantity_add' => $quantity
    ]);

    // Commit
    $database_connection->commit();

    $_SESSION['cart_success'] = 'Item added to cart successfully!';
    header('Location: ../../pages/menu.php');
    exit;

} catch (Exception $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Add to cart error: ' . $e->getMessage());
    $_SESSION['cart_error'] = $e->getMessage() ?: 'Could not add item to cart. Please try again.';
    header('Location: ../../pages/menu.php');
    exit;
}