<?php
/**
 * FitPal Add to Queue Handler
 * Version 4.2 - Queue-based ordering with redirect to menu.php
 * 
 * @package FitPal
 * @version 4.2
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authentication check
if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    $_SESSION['queue_error'] = 'Please sign in to add items to your queue.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

// CSRF validation
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['queue_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/menu.php');
    exit;
}

// Input validation
$productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
$totalPrice = isset($_POST['total_price']) ? (float)$_POST['total_price'] : 0;
$customerId = (int)$_SESSION['customer_id'];
$customizationsJson = isset($_POST['customizations']) ? $_POST['customizations'] : '';
$customizations = !empty($customizationsJson) ? json_decode($customizationsJson, true) : [];

// Validate quantity
if ($quantity < 1) {
    $_SESSION['queue_error'] = 'Quantity must be at least 1.';
    header('Location: ../../pages/menu.php');
    exit;
}

if ($productId <= 0) {
    $_SESSION['queue_error'] = 'Invalid product selected.';
    header('Location: ../../pages/menu.php');
    exit;
}

try {
    $database_connection->beginTransaction();

    // Lock and fetch product
    $stmt = $database_connection->prepare(
        "SELECT stock, is_active, price, base_price, is_customizable 
         FROM product 
         WHERE product_id = :product_id 
         FOR UPDATE"
    );
    $stmt->execute([':product_id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        throw new RuntimeException('Product not found.');
    }

    if (!$product['is_active']) {
        throw new RuntimeException('Product is not available.');
    }

    if ($product['stock'] < $quantity) {
        throw new RuntimeException(
            'Insufficient stock. Available: ' . $product['stock'] . 
            ', Requested: ' . $quantity
        );
    }

    // Calculate final price
    $basePrice = (float)($product['base_price'] ?? $product['price']);
    $finalPrice = $basePrice;

    // Add customization modifiers if provided
    if (!empty($customizations)) {
        foreach ($customizations as $cust) {
            if (isset($cust['price_modifier']) && isset($cust['quantity'])) {
                $finalPrice += (float)$cust['price_modifier'] * (int)$cust['quantity'];
            }
        }
    }

    $priceToUse = $totalPrice > 0 ? $totalPrice / $quantity : $finalPrice;
    if ($priceToUse <= 0) {
        $priceToUse = (float)$product['price'];
    }

    // Store customizations as JSON in cart.customization_data (this is the queue storage)
    $customizationData = !empty($customizations) ? json_encode($customizations) : null;

    // Check if item already in queue
    $checkStmt = $database_connection->prepare(
        "SELECT cart_id, quantity FROM cart 
         WHERE customer_id = :customer_id AND product_id = :product_id"
    );
    $checkStmt->execute([
        ':customer_id' => $customerId,
        ':product_id' => $productId
    ]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Update existing queue item
        $newQuantity = (int)$existing['quantity'] + $quantity;
        $updateStmt = $database_connection->prepare(
            "UPDATE cart SET quantity = :quantity, price = :price, customization_data = :customization_data
             WHERE cart_id = :cart_id"
        );
        $updateStmt->execute([
            ':quantity' => $newQuantity,
            ':price' => $priceToUse,
            ':customization_data' => $customizationData,
            ':cart_id' => $existing['cart_id']
        ]);
    } else {
        // Insert new queue item
        $insertStmt = $database_connection->prepare(
            "INSERT INTO cart (customer_id, product_id, quantity, price, added_at, customization_data) 
             VALUES (:customer_id, :product_id, :quantity, :price, NOW(), :customization_data)"
        );
        $insertStmt->execute([
            ':customer_id' => $customerId,
            ':product_id' => $productId,
            ':quantity' => $quantity,
            ':price' => $priceToUse,
            ':customization_data' => $customizationData
        ]);
    }

    $database_connection->commit();
    
    // FIXED: Use queue_success message and redirect to menu.php
    $_SESSION['queue_success'] = 'Item added to your order queue!';
    header('Location: ../../pages/menu.php');
    exit;

} catch (RuntimeException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    $_SESSION['queue_error'] = $e->getMessage();
    header('Location: ../../pages/product-detail.php?id=' . $productId);
    exit;

} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Add to queue database error: ' . $e->getMessage());
    $_SESSION['queue_error'] = 'A system error occurred. Please try again.';
    header('Location: ../../pages/product-detail.php?id=' . $productId);
    exit;
}