<?php
/**
 * Order Database Queries with Full ACID Transaction Support
 *
 * @package FitPal
 * @version 1.1
 */

declare(strict_types=1);

require_once __DIR__ . '/branch-queries.php';

/**
 * Create order from cart with stock validation (ACID compliant)
 * Uses FOR UPDATE row locking to prevent race conditions.
 *
 * Business Rules:
 * - All products must be from the same restaurant branch
 * - Stock is validated and locked for all products
 * - Delivery fee is calculated based on subtotal
 *
 * @param PDO $db Database connection
 * @param int $customerId Customer ID
 * @param string $address Delivery address
 * @param string $paymentMethod Payment method
 * @return int Order ID
 * @throws RuntimeException If stock is insufficient, branch mismatch, or any other business error occurs
 * @throws PDOException If database error occurs
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
            "SELECT product_id, name, stock, is_active, price, restaurant_branch_id
             FROM product 
             WHERE product_id IN ({$placeholders}) 
             FOR UPDATE"
        );
        $stockStmt->execute($productIds);

        $products = [];
        while ($row = $stockStmt->fetch(PDO::FETCH_ASSOC)) {
            $products[$row['product_id']] = $row;
        }

        // 4. Validate all products exist, are active, and have sufficient stock
        $totalPrice = 0;
        foreach ($cartItems as $item) {
            $productId = $item['product_id'];
            $quantity = (int)$item['quantity'];

            if (!isset($products[$productId])) {
                throw new RuntimeException('Product not found: ' . $productId);
            }

            $product = $products[$productId];

            // Verify branch consistency (redundant but safe)
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

            $totalPrice += (float)$product['price'] * $quantity;
        }

        // 5. Calculate delivery fee (business rule)
        $deliveryFee = $totalPrice > 500 ? 0 : 50.00;
        $grandTotal = $totalPrice + $deliveryFee;

        // 6. Create order record
        $orderStmt = $db->prepare(
            "INSERT INTO orders 
                (customer_id, destination_address, payment_method, subtotal, delivery_charge, total_amount, order_status)
             VALUES 
                (:customer_id, :address, :payment_method, :subtotal, :delivery_charge, :total_amount, 'pending')"
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

        // 7. Insert queue items and update stock (atomic)
        foreach ($cartItems as $item) {
            $productId = $item['product_id'];
            $quantity = (int)$item['quantity'];
            $product = $products[$productId];

            // Add to queue
            $queueStmt = $db->prepare(
                "INSERT INTO queue_item 
                    (order_id, branch_id, product_id, queue_quantity, unit_price)
                 VALUES 
                    (:order_id, :branch_id, :product_id, :quantity, :price)"
            );
            $queueStmt->execute([
                ':order_id' => $orderId,
                ':branch_id' => $branchId,
                ':product_id' => $productId,
                ':quantity' => $quantity,
                ':price' => $product['price']
            ]);

            // Decrease stock with verification
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

            // Verify stock was actually updated (prevents race condition)
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
 * Cancel order and restore stock (ACID compliant)
 *
 * @param PDO $db Database connection
 * @param int $orderId Order ID
 * @param string $cancelledBy Who cancelled (customer, restaurant, rider, admin)
 * @return bool True on success
 * @throws RuntimeException If order cannot be cancelled
 */
function cancelOrderAndRestoreStock(PDO $db, int $orderId, string $cancelledBy): bool
{
    $db->beginTransaction();

    try {
        // Check if order can be cancelled
        $checkStmt = $db->prepare(
            "SELECT order_status FROM orders WHERE order_id = :order_id"
        );
        $checkStmt->execute([':order_id' => $orderId]);
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new RuntimeException('Order not found.');
        }

        $cancellableStatuses = ['pending', 'preparing'];
        if (!in_array($order['order_status'], $cancellableStatuses, true)) {
            throw new RuntimeException(
                'Order cannot be cancelled in its current state: ' . $order['order_status']
            );
        }

        // Lock and get queue items
        $queueStmt = $db->prepare(
            "SELECT product_id, queue_quantity 
             FROM queue_item 
             WHERE order_id = :order_id 
             FOR UPDATE"
        );
        $queueStmt->execute([':order_id' => $orderId]);
        $items = $queueStmt->fetchAll(PDO::FETCH_ASSOC);

        // Restore stock for each item
        foreach ($items as $item) {
            $restoreStmt = $db->prepare(
                "UPDATE product SET stock = stock + :quantity WHERE product_id = :product_id"
            );
            $restoreStmt->execute([
                ':product_id' => $item['product_id'],
                ':quantity' => $item['queue_quantity']
            ]);
        }

        // Update order status
        $updateStmt = $db->prepare(
            "UPDATE orders 
             SET order_status = 'cancelled', cancelled_by = :cancelled_by 
             WHERE order_id = :order_id"
        );
        $updateStmt->execute([
            ':order_id' => $orderId,
            ':cancelled_by' => $cancelledBy
        ]);

        $db->commit();
        return true;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Get order details with all items
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
            c.first_name,
            c.last_name,
            c.email
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

    // Get order items
    $itemStmt = $db->prepare(
        "SELECT 
            qi.queue_item_id,
            qi.product_id,
            qi.queue_quantity AS quantity,
            qi.unit_price,
            qi.total_price,
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
    $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    return $order;
}

/**
 * Get restaurant branch for an order
 *
 * @param PDO $db Database connection
 * @param int $orderId Order ID
 * @return array|false Branch information or false if not found
 */
function getOrderBranch(PDO $db, int $orderId): array|false
{
    $stmt = $db->prepare(
        "SELECT 
            rb.restaurant_branch_id,
            rb.branch_name,
            rb.branch_code,
            rb.barangay,
            rb.city,
            rb.province,
            r.business_name AS restaurant_name,
            r.restaurant_id
        FROM queue_item qi
        JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        WHERE qi.order_id = :order_id
        LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get orders by customer ID with pagination
 *
 * @param PDO $db Database connection
 * @param int $customerId Customer ID
 * @param int $limit Number of orders to return
 * @param int $offset Offset for pagination
 * @return array List of orders
 */
function getCustomerOrders(PDO $db, int $customerId, int $limit = 10, int $offset = 0): array
{
    $stmt = $db->prepare(
        "SELECT 
            o.order_id,
            o.order_status,
            o.total_amount,
            o.order_date,
            o.delivery_charge,
            o.subtotal,
            COUNT(qi.queue_item_id) AS item_count,
            rb.branch_name
        FROM orders o
        LEFT JOIN queue_item qi ON o.order_id = qi.order_id
        LEFT JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
        WHERE o.customer_id = :customer_id
        GROUP BY o.order_id
        ORDER BY o.order_date DESC
        LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Count total orders for a customer
 *
 * @param PDO $db Database connection
 * @param int $customerId Customer ID
 * @return int Total order count
 */
function countCustomerOrders(PDO $db, int $customerId): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total FROM orders WHERE customer_id = :customer_id"
    );
    $stmt->execute([':customer_id' => $customerId]);
    return (int)$stmt->fetchColumn();
}


/**
 * Get customer's active order (for the tracker)
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