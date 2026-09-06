<?php
/**
 * FitPal Add to Cart Handler
 *
 * Processes adding a product to the customer's cart.
 * Updated for new database schema with customization support.
 *
 * @package FitPal
 * @version 3.0
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
$customizations = isset($_POST['customizations']) ? json_decode($_POST['customizations'], true) : [];

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
    $database_connection->beginTransaction();

    // Lock the product row - include new columns
    $stmt = $database_connection->prepare(
        "SELECT stock, is_active, price, base_price, is_customizable, customization_type 
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

    // Calculate final price (base price + customization modifiers)
    $basePrice = (float)($product['base_price'] ?? $product['price']);
    $finalPrice = $basePrice;
    
    // If customizable, calculate price with modifiers
    if ($product['is_customizable'] && !empty($customizations)) {
        foreach ($customizations as $cust) {
            if (!empty($cust['ingredient_id'])) {
                $ingStmt = $database_connection->prepare(
                    "SELECT price_modifier FROM ingredient WHERE ingredient_id = :ingredient_id"
                );
                $ingStmt->execute([':ingredient_id' => $cust['ingredient_id']]);
                $ingredient = $ingStmt->fetch(PDO::FETCH_ASSOC);
                if ($ingredient) {
                    $finalPrice += (float)($ingredient['price_modifier'] ?? 0);
                }
            }
        }
    }
    
    // Use final price (or fallback to product price)
    $price = $finalPrice > 0 ? $finalPrice : (float)$product['price'];

    // Check if item already in cart
    $checkStmt = $database_connection->prepare(
        "SELECT cart_id, quantity FROM cart 
         WHERE customer_id = :customer_id AND product_id = :product_id"
    );
    $checkStmt->execute([
        ':customer_id' => $customerId,
        ':product_id' => $productId
    ]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    $cartId = null;

    if ($existing) {
        // Update existing cart item
        $cartId = $existing['cart_id'];
        $newQuantity = $existing['quantity'] + $quantity;
        $updateStmt = $database_connection->prepare(
            "UPDATE cart SET quantity = :quantity, price = :price, updated_at = NOW() 
             WHERE cart_id = :cart_id"
        );
        $updateStmt->execute([
            ':quantity' => $newQuantity,
            ':price' => $price,
            ':cart_id' => $cartId
        ]);
        
        // Delete existing customizations for this cart item
        $delCustStmt = $database_connection->prepare(
            "DELETE FROM customization_instance WHERE cart_id = :cart_id"
        );
        $delCustStmt->execute([':cart_id' => $cartId]);
    } else {
        // Insert new cart item
        $insertStmt = $database_connection->prepare(
            "INSERT INTO cart (customer_id, product_id, quantity, price, added_at) 
             VALUES (:customer_id, :product_id, :quantity, :price, NOW())"
        );
        $insertStmt->execute([
            ':customer_id' => $customerId,
            ':product_id' => $productId,
            ':quantity' => $quantity,
            ':price' => $price
        ]);
        $cartId = (int)$database_connection->lastInsertId();
    }
    
    // Insert customizations if product is customizable
    if ($product['is_customizable'] && !empty($customizations) && $cartId) {
        foreach ($customizations as $cust) {
            // Get product_composition_id
            $compStmt = $database_connection->prepare(
                "SELECT product_composition_id FROM product_composition 
                 WHERE product_id = :product_id 
                 AND composition_type = :composition_type"
            );
            $compStmt->execute([
                ':product_id' => $productId,
                ':composition_type' => $cust['composition_type'] ?? 'modifier'
            ]);
            $comp = $compStmt->fetch(PDO::FETCH_ASSOC);
            $compId = $comp ? $comp['product_composition_id'] : null;
            
            if ($compId) {
                $insertCustStmt = $database_connection->prepare(
                    "INSERT INTO customization_instance 
                        (cart_id, product_composition_id, selected_option, 
                         customization_notes, ingredient_id)
                     VALUES 
                        (:cart_id, :comp_id, :selected_option, :notes, :ingredient_id)"
                );
                $insertCustStmt->execute([
                    ':cart_id' => $cartId,
                    ':comp_id' => $compId,
                    ':selected_option' => $cust['selected_option'] ?? null,
                    ':notes' => $cust['customization_notes'] ?? null,
                    ':ingredient_id' => $cust['ingredient_id'] ?? null
                ]);
            }
        }
    }

    $database_connection->commit();
    
    $_SESSION['cart_success'] = 'Item added to cart successfully.';
    header('Location: ../../pages/menu.php');
    exit;

} catch (RuntimeException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    $_SESSION['cart_error'] = $e->getMessage();
    header('Location: ../../pages/menu.php');
    exit;

} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Add to cart database error: ' . $e->getMessage());
    $_SESSION['cart_error'] = 'A system error occurred. Please try again.';
    header('Location: ../../pages/menu.php');
    exit;
}