<?php
/**
 * FitPal Place Order Handler
 * Version 4.2 - Checkout integration with address selection
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
    $_SESSION['order_error'] = 'Please sign in to place an order.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/cart-queries.php';
require_once __DIR__ . '/../database/customer-queries.php';

// CSRF validation
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['order_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/checkout.php');
    exit;
}

// Input validation
$addressId = isset($_POST['address_id']) ? (int)$_POST['address_id'] : 0;
$paymentMethod = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'COD';
$customerId = (int)$_SESSION['customer_id'];

// Validate payment method
$validPaymentMethods = ['COD', 'Wallet', 'Online'];
if (!in_array($paymentMethod, $validPaymentMethods, true)) {
    $_SESSION['order_error'] = 'Invalid payment method selected.';
    header('Location: ../../pages/checkout.php');
    exit;
}

// Fetch the selected address
try {
    $stmt = $database_connection->prepare(
        "SELECT block, barangay, city, province, region, postal_code, country
         FROM customer_address
         WHERE customer_address_id = :address_id"
    );
    $stmt->execute([':address_id' => $addressId]);
    $addressData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$addressData) {
        throw new RuntimeException('Invalid address selected.');
    }
    // Build full address string
    $addressParts = array_filter([
        $addressData['block'] ?? '',
        $addressData['barangay'] ?? '',
        $addressData['city'] ?? '',
        $addressData['province'] ?? '',
        $addressData['region'] ?? '',
        $addressData['postal_code'] ?? '',
        $addressData['country'] ?? 'Philippines'
    ]);
    $fullAddress = implode(', ', $addressParts);
    if (empty($fullAddress)) {
        throw new RuntimeException('Incomplete address. Please update your address.');
    }
} catch (PDOException $e) {
    error_log('Address fetch error: ' . $e->getMessage());
    $_SESSION['order_error'] = 'Could not retrieve address. Please try again.';
    header('Location: ../../pages/checkout.php');
    exit;
} catch (RuntimeException $e) {
    $_SESSION['order_error'] = $e->getMessage();
    header('Location: ../../pages/checkout.php');
    exit;
}

// Proceed with order creation using the full address string
try {
    $database_connection->beginTransaction();

    // Get cart items grouped by branch (should all be same branch)
    $cartGrouped = getCartGroupedByBranch($database_connection, $customerId);
    if (empty($cartGrouped)) {
        throw new RuntimeException('Your cart is empty.');
    }

    // Ensure all items are from the same branch (should already be validated in checkout)
    $branchIds = array_keys($cartGrouped);
    if (count($branchIds) > 1) {
        throw new RuntimeException('All items must be from the same restaurant branch.');
    }
    $branchId = (int)$branchIds[0];
    $branchInfo = $cartGrouped[$branchId];
    $cartItems = $branchInfo['items'];

    // Calculate subtotal
    $subtotal = 0;
    foreach ($cartItems as $item) {
        $subtotal += (float)$item['price'] * (int)$item['quantity'];
    }

    // Delivery fee
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
        ':address' => $fullAddress,
        ':payment_method' => $paymentMethod,
        ':subtotal' => $subtotal,
        ':delivery_charge' => $deliveryFee,
        ':total_amount' => $totalAmount
    ]);
    $orderId = (int)$database_connection->lastInsertId();

    // Insert queue items with customizations
    foreach ($cartItems as $item) {
        $productId = (int)$item['product_id'];
        $quantity = (int)$item['quantity'];
        $price = (float)$item['price'];
        $customizations = $item['customizations'] ?? [];

        // Insert queue item
        $queueStmt = $database_connection->prepare(
            "INSERT INTO queue_item
                (order_id, branch_id, product_id, queue_quantity, unit_price, total_price, is_customized,
                 base_price_snapshot, final_price)
             VALUES
                (:order_id, :branch_id, :product_id, :quantity, :unit_price, :total_price, :is_customized,
                 :base_price, :final_price)"
        );
        $itemTotal = $price * $quantity;
        $isCustomized = !empty($customizations);
        $basePrice = $price; // snapshot
        $finalPrice = $price; // Will be updated if customizations add price

        $queueStmt->execute([
            ':order_id' => $orderId,
            ':branch_id' => $branchId,
            ':product_id' => $productId,
            ':quantity' => $quantity,
            ':unit_price' => $price,
            ':total_price' => $itemTotal,
            ':is_customized' => $isCustomized ? 1 : 0,
            ':base_price' => $basePrice,
            ':final_price' => $finalPrice
        ]);
        $queueItemId = (int)$database_connection->lastInsertId();

        // Insert customizations from cart.customization_data (if any)
        // We need to fetch customization_data from cart for this item
        // For simplicity, we assume cart already has customization_data stored as JSON (from add-to-cart)
        // We'll retrieve it from the cart table
        $cartCustomStmt = $database_connection->prepare(
            "SELECT customization_data FROM cart WHERE customer_id = :customer_id AND product_id = :product_id"
        );
        $cartCustomStmt->execute([
            ':customer_id' => $customerId,
            ':product_id' => $productId
        ]);
        $cartRow = $cartCustomStmt->fetch(PDO::FETCH_ASSOC);
        if ($cartRow && !empty($cartRow['customization_data'])) {
            $customizationsData = json_decode($cartRow['customization_data'], true);
            if (is_array($customizationsData)) {
                foreach ($customizationsData as $cust) {
                    // Skip notes or non-ingredient entries
                    if (isset($cust['type']) && $cust['type'] === 'notes') continue;
                    $ingredientId = (int)($cust['ingredient_id'] ?? 0);
                    if ($ingredientId <= 0) continue;
                    $qty = (int)($cust['quantity'] ?? 1);
                    $priceAtTime = (float)($cust['price_modifier'] ?? 0);
                    $caloriesAtTime = (int)($cust['calories'] ?? 0);
                    $isRemoved = (isset($cust['selected_option']) && $cust['selected_option'] === 'remove') ? 1 : 0;

                    $insCustStmt = $database_connection->prepare(
                        "INSERT INTO customization_instance
                            (queue_item_id, ingredient_id, quantity, price_at_time, calories_at_time, is_removed, custom_text)
                         VALUES
                            (:queue_item_id, :ingredient_id, :quantity, :price_at_time, :calories_at_time, :is_removed, :custom_text)"
                    );
                    $insCustStmt->execute([
                        ':queue_item_id' => $queueItemId,
                        ':ingredient_id' => $ingredientId,
                        ':quantity' => $qty,
                        ':price_at_time' => $priceAtTime,
                        ':calories_at_time' => $caloriesAtTime,
                        ':is_removed' => $isRemoved,
                        ':custom_text' => $cust['notes'] ?? null
                    ]);
                }
            }
        }

        // Decrease product stock (already handled in add-to-cart? Actually we need to decrease now)
        // We'll decrement stock here to avoid double deduction (add-to-cart only reserves? It currently does not decrement)
        // So we need to decrement stock now.
        $stockStmt = $database_connection->prepare(
            "UPDATE product SET stock = stock - :quantity WHERE product_id = :product_id AND stock >= :quantity"
        );
        $stockStmt->execute([
            ':product_id' => $productId,
            ':quantity' => $quantity
        ]);
        if ($stockStmt->rowCount() === 0) {
            throw new RuntimeException('Stock insufficient for product: ' . $productId);
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