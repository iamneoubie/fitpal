<?php
/**
 * Order Database Queries with Full ACID Transaction Support
 * Updated for new database schema with QUEUE_ITEM changes.
 *
 * @package FitPal
 * @version 2.0
 */

declare(strict_types=1);

require_once __DIR__ . '/branch-queries.php';

/**
 * Create order from cart with stock validation (ACID compliant)
 * Updated to handle QUEUE_ITEM new columns: is_customized, base_price_snapshot, final_price
 *
 * @param PDO $db Database connection
 * @param int $customerId Customer ID
 * @param string $address Delivery address
 * @param string $paymentMethod Payment method
 * @return int Order ID
 * @throws RuntimeException If stock is insufficient, branch mismatch, or any other business error occurs
 */
function createOrderFromCart(PDO $db, int $customerId, string $address, string $paymentMethod): int
{
    $db->beginTransaction();

    try {
        // 1. Get cart items grouped by branch
        $cartGrouped = getCartGroupedByBranch($db, $customerId);
        
        if (empty($cartGrouped)) {
            throw new RuntimeException('Cart is empty.');
        }

        // 2. Validate all products are from the same branch
        $branchIds = array_keys($cartGrouped);
        
        if (count($branchIds) > 1) {
            $branchNames = array_column($cartGrouped, 'branch_name');
            throw new RuntimeException(
                'All items in your cart must be from the same restaurant branch. ' .
                'Your cart contains items from: ' . implode(', ', $branchNames)
            );
        }

        $branchId = (int)$branchIds[0];
        $branchInfo = $cartGrouped[$branchId];
        $cartItems = $branchInfo['items'];

        // 3. Lock and validate stock for ALL products
        $productIds = array_column($cartItems, 'product_id');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        $stockStmt = $db->prepare(
            "SELECT product_id, name, stock, is_active, price, base_price, is_customizable,
                    restaurant_branch_id
             FROM product 
             WHERE product_id IN ({$placeholders}) 
             FOR UPDATE"
        );
        $stockStmt->execute($productIds);

        $products = [];
        while ($row = $stockStmt->fetch(PDO::FETCH_ASSOC)) {
            $products[$row['product_id']] = $row;
        }

        // 4. Validate all products and calculate total
        $totalPrice = 0;
        foreach ($cartItems as $item) {
            $productId = $item['product_id'];
            $quantity = (int)$item['quantity'];

            if (!isset($products[$productId])) {
                throw new RuntimeException('Product not found: ' . $productId);
            }

            $product = $products[$productId];

            if ((int)$product['restaurant_branch_id'] !== $branchId) {
                throw new RuntimeException(
                    'Branch mismatch for product: ' . $product['name']
                );
            }

            if (!$product['is_active']) {
                throw new RuntimeException('Product is not available: ' . $product['name']);
            }

            if ($product['stock'] < $quantity) {
                throw new RuntimeException(
                    'Insufficient stock for "' . $product['name'] . '". ' .
                    'Available: ' . $product['stock'] . ', Requested: ' . $quantity
                );
            }

            // Use the cart price (which may include customizations)
            $totalPrice += (float)$item['price'] * $quantity;
        }

        // 5. Calculate delivery fee
        $deliveryFee = $totalPrice > 500 ? 0 : 50.00;
        $grandTotal = $totalPrice + $deliveryFee;

        // 6. Create order record - includes has_unread_messages
        $orderStmt = $db->prepare(
            "INSERT INTO orders 
                (customer_id, destination_address, payment_method, subtotal, 
                 delivery_charge, total_amount, order_status, has_unread_messages)
             VALUES 
                (:customer_id, :address, :payment_method, :subtotal, 
                 :delivery_charge, :total_amount, 'pending', 0)"
        );
        $orderStmt->execute([
            ':customer_id' => $customerId,
            ':address' => $address,
            ':payment_method' => $paymentMethod,
            ':subtotal' => $totalPrice,
            ':delivery_charge' => $deliveryFee,
            ':total_amount' => $grandTotal
        ]);

        $orderId = (int)$db->lastInsertId();

        // 7. Insert queue items with new columns
        foreach ($cartItems as $item) {
            $productId = $item['product_id'];
            $quantity = (int)$item['quantity'];
            $product = $products[$productId];
            
            // Get customizations for this cart item
            $custStmt = $db->prepare(
                "SELECT 
                    ci.customization_instance_id,
                    ci.selected_option,
                    ci.customization_notes,
                    ci.ingredient_id,
                    i.name AS ingredient_name,
                    i.price_modifier,
                    pc.composition_type,
                    pc.is_required
                FROM customization_instance ci
                JOIN product_composition pc ON ci.product_composition_id = pc.product_composition_id
                LEFT JOIN ingredient i ON ci.ingredient_id = i.ingredient_id
                WHERE ci.cart_id = :cart_id"
            );
            $custStmt->execute([':cart_id' => $item['cart_id'] ?? 0]);
            $customizations = $custStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate base and final prices
            $basePriceSnapshot = (float)($product['base_price'] ?? $product['price']);
            $finalPrice = (float)$item['price']; // Use cart price
            
            // Insert queue item with new columns
            $queueStmt = $db->prepare(
                "INSERT INTO queue_item 
                    (order_id, branch_id, product_id, queue_quantity, unit_price,
                     is_customized, base_price_snapshot, final_price)
                 VALUES 
                    (:order_id, :branch_id, :product_id, :quantity, :price,
                     :is_customized, :base_price, :final_price)"
            );
            $queueStmt->execute([
                ':order_id' => $orderId,
                ':branch_id' => $branchId,
                ':product_id' => $productId,
                ':quantity' => $quantity,
                ':price' => $product['price'],
                ':is_customized' => !empty($customizations) ? 1 : 0,
                ':base_price' => $basePriceSnapshot,
                ':final_price' => $finalPrice
            ]);
            
            $queueItemId = (int)$db->lastInsertId();
            
            // Insert customizations for this queue item
            if (!empty($customizations)) {
                foreach ($customizations as $cust) {
                    $insCustStmt = $db->prepare(
                        "INSERT INTO customization_instance 
                            (queue_item_id, product_composition_id, selected_option, 
                             customization_notes, ingredient_id)
                         VALUES 
                            (:queue_item_id, :comp_id, :selected_option, :notes, :ingredient_id)"
                    );
                    $insCustStmt->execute([
                        ':queue_item_id' => $queueItemId,
                        ':comp_id' => $cust['product_composition_id'],
                        ':selected_option' => $cust['selected_option'] ?? null,
                        ':notes' => $cust['customization_notes'] ?? null,
                        ':ingredient_id' => $cust['ingredient_id'] ?? null
                    ]);
                }
            }

            // Decrease stock
            $stockStmt = $db->prepare(
                "UPDATE product 
                 SET stock = stock - :quantity 
                 WHERE product_id = :product_id 
                 AND stock >= :quantity"
            );
            $stockStmt->execute([
                ':product_id' => $productId,
                ':quantity' => $quantity
            ]);

            if ($stockStmt->rowCount() === 0) {
                throw new RuntimeException(
                    'Stock update failed for product: ' . $product['name'] . 
                    '. The item may have been purchased by another customer.'
                );
            }
        }

        // 8. Clear cart
        $clearStmt = $db->prepare("DELETE FROM cart WHERE customer_id = :customer_id");
        $clearStmt->execute([':customer_id' => $customerId]);

        $db->commit();
        return $orderId;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Get order details with items and customizations
 *
 * @param PDO $db Database connection
 * @param int $orderId Order ID
 * @return array|false Order details with items or false if not found
 */
function getOrderDetails(PDO $db, int $orderId): array|false
{
    $stmt = $db->prepare(
        "SELECT 
            o.order_id,
            o.customer_id,
            o.destination_address,
            o.order_status,
            o.payment_method,
            o.subtotal,
            o.delivery_charge,
            o.total_amount,
            o.order_date,
            o.delivered_at,
            o.has_unread_messages,
            c.first_name,
            c.last_name,
            c.email,
            c.contact_number
        FROM orders o
        JOIN customer c ON o.customer_id = c.customer_id
        WHERE o.order_id = :order_id"
    );
    $stmt->execute([':order_id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return false;
    }

    // Get branch info
    $branch = getOrderBranch($db, $orderId);
    $order['branch'] = $branch;

    // Get order items with new QUEUE_ITEM columns
    $itemStmt = $db->prepare(
        "SELECT 
            qi.queue_item_id,
            qi.product_id,
            qi.queue_quantity AS quantity,
            qi.unit_price,
            qi.total_price,
            qi.is_customized,
            qi.base_price_snapshot,
            qi.final_price,
            p.name AS product_name,
            p.description,
            di.dietary_tags,
            di.allergens
        FROM queue_item qi
        JOIN product p ON qi.product_id = p.product_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE qi.order_id = :order_id"
    );
    $itemStmt->execute([':order_id' => $orderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get customizations for each item
    foreach ($items as &$item) {
        $custStmt = $db->prepare(
            "SELECT 
                ci.customization_instance_id,
                ci.selected_option,
                ci.customization_notes,
                ci.ingredient_id,
                i.name AS ingredient_name,
                i.price_modifier,
                pc.composition_type,
                pc.is_required
            FROM customization_instance ci
            JOIN product_composition pc ON ci.product_composition_id = pc.product_composition_id
            LEFT JOIN ingredient i ON ci.ingredient_id = i.ingredient_id
            WHERE ci.queue_item_id = :queue_item_id"
        );
        $custStmt->execute([':queue_item_id' => $item['queue_item_id']]);
        $item['customizations'] = $custStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $order['items'] = $items;

    return $order;
}

/**
 * Get active order with new columns
 *
 * @param PDO $db Database connection
 * @param int $customerId Customer ID
 * @return array|false Active order or false if none
 */
function getActiveOrder(PDO $db, int $customerId): array|false
{
    $activeStatuses = ['pending', 'confirmed', 'preparing', 'out_for_delivery'];
    $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));
    
    $stmt = $db->prepare(
        "SELECT 
            o.order_id,
            o.order_status,
            o.total_amount,
            o.order_date,
            o.has_unread_messages,
            r.business_name AS restaurant_name,
            rb.branch_name
        FROM orders o
        JOIN queue_item qi ON o.order_id = qi.order_id
        JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        WHERE o.customer_id = ?
        AND o.order_status IN ({$placeholders})
        ORDER BY o.order_date DESC
        LIMIT 1"
    );
    
    $params = array_merge([$customerId], $activeStatuses);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}