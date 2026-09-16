<?php
/**
 * FitPal Order Database Queries
 *
 * Pure data-access layer for orders, queue_item, and
 * customization_instance. All multi-step operations are wrapped
 * in transactions with row locks.
 *
 * Totals policy: `orders` no longer stores subtotal, delivery_charge,
 * total_amount, or special_instructions. Those values are computed on
 * read from queue_item plus the fee schedule (see getOrderTotals()).
 *
 * @package FitPal
 * @version 4.0 — Orders no longer persist price totals.
 */

declare(strict_types=1);

require_once __DIR__ . '/branch-queries.php';
require_once __DIR__ . '/cart-queries.php';
require_once __DIR__ . '/fee-queries.php';

/**
 * Create an order from a customer's cart, atomically.
 *
 * The cart may span multiple branches. Fees are not stored; they are
 * recomputed on read from queue_item and the fee schedule.
 *
 * @param PDO $db
 * @param int $customerId
 * @param string $destinationAddress
 * @param string $paymentMethod
 * @return int New order ID
 * @throws RuntimeException On business-rule failure
 */
function createOrderFromCart(
    PDO $db,
    int $customerId,
    string $destinationAddress,
    string $paymentMethod
): int {
    $db->beginTransaction();

    try {
        $cartGrouped = getCartGroupedByBranch($db, $customerId);
        if (empty($cartGrouped)) {
            throw new RuntimeException('Your cart is empty.');
        }

        $cartItems        = [];
        $distinctBranches = [];

        foreach ($cartGrouped as $branchGroup) {
            $bid = (int)$branchGroup['branch_id'];
            if (!in_array($bid, $distinctBranches, true)) {
                $distinctBranches[] = $bid;
            }
            foreach ($branchGroup['items'] as $it) {
                $it['branch_id'] = $bid;
                $cartItems[]     = $it;
            }
        }

        $productIds   = array_column($cartItems, 'product_id');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        $stockStmt = $db->prepare(
            "SELECT product_id, name, stock, is_active, price, base_price,
                    restaurant_branch_id
             FROM product
             WHERE product_id IN ({$placeholders})
             FOR UPDATE"
        );
        $stockStmt->execute($productIds);

        $products = [];
        while ($row = $stockStmt->fetch(PDO::FETCH_ASSOC)) {
            $products[(int)$row['product_id']] = $row;
        }

        // Validate every item against product state.
        foreach ($cartItems as $item) {
            $productId = (int)$item['product_id'];
            $quantity  = (int)$item['quantity'];

            if (!isset($products[$productId])) {
                throw new RuntimeException('Product not found: ' . $productId);
            }
            $product = $products[$productId];

            if ((int)$product['restaurant_branch_id'] !== (int)$item['branch_id']) {
                throw new RuntimeException('Branch mismatch for product: ' . $product['name']);
            }
            if (!$product['is_active']) {
                throw new RuntimeException('Product is not available: ' . $product['name']);
            }
            if ((int)$product['stock'] < $quantity) {
                throw new RuntimeException(
                    'Insufficient stock for "' . $product['name'] . '". ' .
                    'Available: ' . (int)$product['stock'] . ', Requested: ' . $quantity
                );
            }
        }

        // ---- Insert order ----
        // Only identity, state, and destination are persisted. Totals
        // are derived from queue_item on read via getOrderTotals().
        $orderStmt = $db->prepare(
            "INSERT INTO orders
                (customer_id, destination_address, payment_method, order_status)
             VALUES
                (:customer_id, :address, :payment_method, 'pending')"
        );
        $orderStmt->execute([
            ':customer_id'    => $customerId,
            ':address'        => $destinationAddress,
            ':payment_method' => $paymentMethod,
        ]);
        $orderId = (int)$db->lastInsertId();

        // ---- Insert queue items ----
        foreach ($cartItems as $item) {
            $productId = (int)$item['product_id'];
            $quantity  = (int)$item['quantity'];
            $price     = (float)$item['price'];
            $product   = $products[$productId];

            $isCustomized = !empty($item['customizations']) ? 1 : 0;

            $queueStmt = $db->prepare(
                "INSERT INTO queue_item
                    (order_id, branch_id, product_id, queue_quantity, unit_price,
                     is_customized, base_price_snapshot, final_price)
                 VALUES
                    (:order_id, :branch_id, :product_id, :quantity, :unit_price,
                     :is_customized, :base_price, :final_price)"
            );
            $queueStmt->execute([
                ':order_id'      => $orderId,
                ':branch_id'     => (int)$item['branch_id'],
                ':product_id'    => $productId,
                ':quantity'      => $quantity,
                ':unit_price'    => $price,
                ':is_customized' => $isCustomized,
                ':base_price'    => (float)($product['base_price'] ?: $price),
                ':final_price'   => $price,
            ]);
            $queueItemId = (int)$db->lastInsertId();

            foreach (($item['customizations'] ?? []) as $cust) {
                if (empty($cust['ingredient_id'])) {
                    continue;
                }
                $insCust = $db->prepare(
                    "INSERT INTO customization_instance
                        (queue_item_id, ingredient_id, quantity, price_at_time,
                         calories_at_time, is_removed, custom_text)
                     VALUES
                        (:queue_item_id, :ingredient_id, :quantity, :price_at_time,
                         :calories_at_time, :is_removed, :custom_text)"
                );
                $insCust->execute([
                    ':queue_item_id'    => $queueItemId,
                    ':ingredient_id'    => (int)$cust['ingredient_id'],
                    ':quantity'         => (int)($cust['quantity'] ?? 1),
                    ':price_at_time'    => (float)($cust['price_modifier'] ?? 0),
                    ':calories_at_time' => (int)($cust['calories'] ?? 0),
                    ':is_removed'       => (($cust['selected_option'] ?? '') === 'remove') ? 1 : 0,
                    ':custom_text'      => $cust['notes'] ?? null,
                ]);
            }

            // NOTE: stock is decremented by the `after_queue_item_insert`
            // trigger. Do NOT decrement again here — doing so doubles
            // the reduction per item.
        }

        clearCart($db, $customerId);

        $db->commit();
        return $orderId;

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Get an order with its items and customizations.
 *
 * Total-related fields (subtotal, delivery_charge, total_amount) are
 * attached by getOrderTotals() rather than read from the table.
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
            o.cancelled_by,
            o.order_date,
            o.delivered_at,
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

    $order['branch'] = getOrderBranch($db, $orderId);

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

    foreach ($items as &$item) {
        $custStmt = $db->prepare(
            "SELECT
                ci.instance_id,
                ci.ingredient_id,
                ci.quantity,
                ci.price_at_time,
                ci.calories_at_time,
                ci.is_removed,
                ci.custom_text,
                i.name AS ingredient_name
             FROM customization_instance ci
             LEFT JOIN ingredient i ON ci.ingredient_id = i.ingredient_id
             WHERE ci.queue_item_id = :queue_item_id"
        );
        $custStmt->execute([':queue_item_id' => $item['queue_item_id']]);
        $item['customizations'] = $custStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($item);

    $order['items'] = $items;

    // Attach computed totals so callers that expect these keys still work.
    $totals = getOrderTotals($db, $orderId);
    if ($totals !== false) {
        $order['subtotal']        = $totals['subtotal'];
        $order['delivery_charge'] = $totals['delivery_fee'] + $totals['service_fee'] + $totals['vat'];
        $order['total_amount']    = $totals['total'];
        $order['service_fee']     = $totals['service_fee'];
        $order['vat']             = $totals['vat'];
    } else {
        $order['subtotal']        = 0.0;
        $order['delivery_charge'] = 0.0;
        $order['total_amount']    = 0.0;
        $order['service_fee']     = 0.0;
        $order['vat']             = 0.0;
    }

    return $order;
}

/**
 * Get the customer's current active order, if any.
 *
 * Totals are computed via getOrderTotals() rather than read from
 * the `orders` table.
 */
function getActiveOrder(PDO $db, int $customerId): array|false
{
    $activeStatuses = ['pending', 'preparing', 'delivering'];
    $placeholders   = implode(',', array_fill(0, count($activeStatuses), '?'));

    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.order_status,
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
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        return false;
    }

    $totals = getOrderTotals($db, (int)$row['order_id']);
    if ($totals !== false) {
        $row['subtotal']        = $totals['subtotal'];
        $row['delivery_charge'] = $totals['delivery_fee'] + $totals['service_fee'] + $totals['vat'];
        $row['total_amount']    = $totals['total'];
    } else {
        $row['subtotal']        = 0.0;
        $row['delivery_charge'] = 0.0;
        $row['total_amount']    = 0.0;
    }

    return $row;
}

/**
 * Get the branch a given order was placed from (via its queue items).
 */
function getOrderBranch(PDO $db, int $orderId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            rb.restaurant_branch_id AS branch_id,
            rb.branch_name,
            rb.branch_code,
            rb.block,
            rb.barangay,
            rb.city,
            rb.province,
            rb.region,
            rb.postal_code,
            rb.country,
            r.restaurant_id,
            r.business_name AS restaurant_name,
            r.cuisine_type,
            r.dietary_tags AS restaurant_dietary_tags
         FROM orders o
         JOIN queue_item qi ON o.order_id = qi.order_id
         JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         WHERE o.order_id = :order_id
         LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Compute an order's fee breakdown and total from its queue items.
 *
 * Single place that answers "what did this order cost". Line-item sum
 * comes from queue_item; fees come from the schedule in fee-queries.php.
 *
 * @param PDO $db
 * @param int $orderId
 * @return array{
 *     subtotal:     float,
 *     delivery_fee: float,
 *     service_fee:  float,
 *     vat:          float,
 *     total:        float,
 *     branch_count: int
 * }|false
 */
function getOrderTotals(PDO $db, int $orderId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS subtotal,
            COUNT(DISTINCT qi.branch_id) AS branch_count
         FROM queue_item qi
         WHERE qi.order_id = :order_id"
    );
    $stmt->execute([':order_id' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        return false;
    }

    $subtotal    = (float)$row['subtotal'];
    $branchCount = (int)$row['branch_count'];

    $fees = calculateOrderFees($branchCount, $subtotal);

    return [
        'subtotal'     => round($subtotal, 2),
        'delivery_fee' => $fees['delivery_fee'],
        'service_fee'  => $fees['service_fee'],
        'vat'          => $fees['vat'],
        'total'        => round(
            $subtotal + $fees['delivery_fee'] + $fees['service_fee'] + $fees['vat'],
            2
        ),
        'branch_count' => $branchCount,
    ];
}