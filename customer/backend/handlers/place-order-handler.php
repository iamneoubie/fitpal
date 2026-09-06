<?php
/**
 * FitPal Place Order Handler
 * Version 4.1 - Move customizations from cart.customization_data to customization_instance
 * 
 * @package FitPal
 * @version 4.1
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

try {
    $database_connection->beginTransaction();

    // Get cart items grouped by branch with customization_data
    $cartStmt = $database_connection->prepare(
        "SELECT 
            c.product_id,
            c.quantity,
            c.price,
            c.customization_data,
            p.name AS product_name,
            p.price AS unit_price,
            p.base_price,
            p.restaurant_branch_id,
            rb.restaurant_id,
            rb.branch_name,
            r.business_name AS restaurant_name
        FROM cart c
        JOIN product p ON c.product_id = p.product_id
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        WHERE c.customer_id = :customer_id"
    );
    $cartStmt->execute([':customer_id' => $customerId]);
    $cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cartItems)) {
        throw new RuntimeException('Your cart is empty.');
    }

    // Check branch consistency
    $branchIds = array_unique(array_column($cartItems, 'restaurant_branch_id'));
    if (count($branchIds) > 1) {
        throw new RuntimeException('All items must be from the same restaurant branch.');
    }

    $branchId = (int)$branchIds[0];
    
    // Calculate totals
    $subtotal = 0;
    foreach ($cartItems as $item) {
        $subtotal += (float)$item['price'] * (int)$item['quantity'];
    }
    
    $deliveryFee = $subtotal > 500 ? 0 : 50.00;
    $totalAmount = $subtotal + $deliveryFee;

    // Create order
    $orderStmt = $database_connection->prepare(
        "INSERT INTO orders 
            (customer_id, destination_address, payment_method, subtotal, delivery_charge, total_amount, order_status)
         VALUES 
            (:customer_id, :address, :payment_method, :subtotal, :delivery_charge, :total_amount, 'pending')"
    );
    $orderStmt->execute([
        ':customer_id' => $customerId,
        ':address' => $address,
        ':payment_method' => $paymentMethod,
        ':subtotal' => $subtotal,
        ':delivery_charge' => $deliveryFee,
        ':total_amount' => $totalAmount
    ]);
    $orderId = (int)$database_connection->lastInsertId();

    // Insert queue items and create customization_instance records
    foreach ($cartItems as $item) {
        // Insert queue item
        $queueStmt = $database_connection->prepare(
            "INSERT INTO queue_item 
                (order_id, branch_id, product_id, queue_quantity, unit_price, total_price, is_customized)
             VALUES 
                (:order_id, :branch_id, :product_id, :quantity, :unit_price, :total_price, :is_customized)"
        );
        
        $itemTotal = (float)$item['price'] * (int)$item['quantity'];
        $isCustomized = !empty($item['customization_data']) ? 1 : 0;
        
        $queueStmt->execute([
            ':order_id' => $orderId,
            ':branch_id' => $branchId,
            ':product_id' => (int)$item['product_id'],
            ':quantity' => (int)$item['quantity'],
            ':unit_price' => (float)$item['price'],
            ':total_price' => $itemTotal,
            ':is_customized' => $isCustomized
        ]);
        
        $queueItemId = (int)$database_connection->lastInsertId();
        
        // FIXED: Create customization_instance records from JSON data
        if (!empty($item['customization_data'])) {
            $customizations = json_decode($item['customization_data'], true);
            if (is_array($customizations)) {
                foreach ($customizations as $cust) {
                    // Skip notes
                    if (isset($cust['type']) && in_array($cust['type'], ['notes', 'component_notes'])) {
                        continue;
                    }
                    
                    $ingredientId = (int)($cust['ingredient_id'] ?? 0);
                    if ($ingredientId > 0) {
                        // Get price for this ingredient at order time
                        $ingPriceStmt = $database_connection->prepare(
                            "SELECT unit_price, calories FROM ingredient WHERE ingredient_id = :ingredient_id"
                        );
                        $ingPriceStmt->execute([':ingredient_id' => $ingredientId]);
                        $ingData = $ingPriceStmt->fetch(PDO::FETCH_ASSOC);
                        
                        $priceAtTime = (float)($cust['price_modifier'] ?? $ingData['unit_price'] ?? 0);
                        $caloriesAtTime = (int)($ingData['calories'] ?? 0);
                        $quantity = (int)($cust['quantity'] ?? 1);
                        
                        // Insert into customization_instance (links to queue_item)
                        $custInstanceStmt = $database_connection->prepare(
                            "INSERT INTO customization_instance 
                                (queue_item_id, ingredient_id, quantity, price_at_time, calories_at_time, is_removed, custom_text)
                             VALUES 
                                (:queue_item_id, :ingredient_id, :quantity, :price_at_time, :calories_at_time, 0, :custom_text)"
                        );
                        $custInstanceStmt->execute([
                            ':queue_item_id' => $queueItemId,
                            ':ingredient_id' => $ingredientId,
                            ':quantity' => $quantity,
                            ':price_at_time' => $priceAtTime,
                            ':calories_at_time' => $caloriesAtTime,
                            ':custom_text' => $cust['notes'] ?? null
                        ]);
                    }
                }
            }
        }
    }

    // Clear cart
    $clearStmt = $database_connection->prepare("DELETE FROM cart WHERE customer_id = :customer_id");
    $clearStmt->execute([':customer_id' => $customerId]);

    $database_connection->commit();

    $_SESSION['order_success'] = 'Order #' . $orderId . ' placed successfully!';
    header('Location: ../../pages/order-confirmation.php?id=' . $orderId);
    exit;

} catch (RuntimeException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    $_SESSION['order_error'] = $e->getMessage();
    header('Location: ../../pages/checkout.php');
    exit;

} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Place order error: ' . $e->getMessage());
    $_SESSION['order_error'] = 'A system error occurred. Please try again.';
    header('Location: ../../pages/checkout.php');
    exit;
}