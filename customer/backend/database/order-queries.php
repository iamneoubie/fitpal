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
 * Payment policy:
 *   COD    — records a `payment` transaction with status 'pending'.
 *            No wallet movement at placement. Money exchanges hands
 *            on delivery.
 *   Wallet — requires sufficient balance. Records a `completed`
 *            payment transaction. The after_transaction_insert trigger
 *            deducts from financial_account.balance automatically.
 *   Online — records a `completed` payment transaction. No wallet
 *            movement (this is a simulation).
 *
 * Refund policy (see refundOrderToWallet):
 *   COD    — marks any pending payment transaction as 'failed'.
 *            No refund transaction.
 *   Wallet — records a `completed` refund transaction. The trigger
 *            credits the wallet automatically.
 *   Online — records a `completed` refund transaction for bookkeeping.
 *            No wallet movement.
 *   Legacy — orders placed before payment recording existed have no
 *            payment transaction. Cancelling one is a clean no-op.
 *
 * Order creation reads directly from the session order queue — there
 * is no persistent cart.
 *
 * @package FitPal
 * @version 6.1 — Legacy-order cancels no longer throw during refund.
 */

declare(strict_types=1);

require_once __DIR__ . '/branch-queries.php';
require_once __DIR__ . '/fee-queries.php';

/**
 * Create an order from a session order queue, atomically, and record
 * the corresponding payment transaction.
 *
 * The queue may span multiple branches. Fees are not stored on the
 * order; they are recomputed on read from queue_item and the fee
 * schedule.
 *
 * Each queue item is expected to be the enriched shape produced by
 * queue-handler.php's queueEnrich():
 *
 *   [
 *     'product_id'           => int,
 *     'name'                 => string,
 *     'price'                => float,   // effective unit price
 *     'base_price'           => float,
 *     'quantity'             => int,
 *     'stock'                => int,
 *     'restaurant_branch_id' => int,
 *     'customization_data'   => string|array|null,
 *   ]
 *
 * @param PDO    $db
 * @param int    $customerId
 * @param array  $queue             Raw $_SESSION['order_queue']
 * @param string $destinationAddress
 * @param string $paymentMethod     One of: COD, Wallet, Online
 * @return int New order ID
 * @throws RuntimeException On business-rule failure
 */
function createOrderFromQueue(
    PDO $db,
    int $customerId,
    array $queue,
    string $destinationAddress,
    string $paymentMethod
): int {
    if (empty($queue)) {
        throw new RuntimeException('Your order is empty.');
    }

    $validMethods = ['COD', 'Wallet', 'Online'];
    if (!in_array($paymentMethod, $validMethods, true)) {
        throw new RuntimeException('Invalid payment method.');
    }

    $db->beginTransaction();

    try {
        // ---- Normalise queue into a flat list of line items ----
        $cartItems        = [];
        $distinctBranches = [];

        foreach ($queue as $qItem) {
            if (!is_array($qItem)) {
                continue;
            }

            $productId = (int)($qItem['product_id'] ?? 0);
            $quantity  = (int)($qItem['quantity']   ?? 0);
            $branchId  = (int)($qItem['restaurant_branch_id'] ?? 0);

            if ($productId <= 0 || $quantity <= 0 || $branchId <= 0) {
                continue;
            }

            $customizations = [];
            if (!empty($qItem['customization_data'])) {
                $decoded = is_string($qItem['customization_data'])
                    ? json_decode($qItem['customization_data'], true)
                    : $qItem['customization_data'];

                if (is_array($decoded)) {
                    $customizations = $decoded;
                }
            }

            if (!in_array($branchId, $distinctBranches, true)) {
                $distinctBranches[] = $branchId;
            }

            $cartItems[] = [
                'product_id'     => $productId,
                'branch_id'      => $branchId,
                'quantity'       => $quantity,
                'price'          => (float)($qItem['price'] ?? 0),
                'base_price'     => (float)($qItem['base_price'] ?? 0),
                'customizations' => $customizations,
            ];
        }

        if (empty($cartItems)) {
            throw new RuntimeException('Your order is empty.');
        }

        // ---- Lock and load every referenced product ----
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

        // ---- Validate every line against product state ----
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

        // -----------------------------------------------------------
        // Compute the order total.
        //
        // Mirrors the fee schedule in fee-queries.php so we can charge
        // the wallet before inserting anything.
        // -----------------------------------------------------------
        $subtotal = 0.0;
        foreach ($cartItems as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }

        $fees = calculateOrderFees(count($distinctBranches), $subtotal);

        $orderTotal = round(
            $subtotal + $fees['delivery_fee'] + $fees['service_fee'] + $fees['vat'],
            2
        );

        // -----------------------------------------------------------
        // Resolve and lock the customer's financial account.
        // -----------------------------------------------------------
        $accountStmt = $db->prepare(
            "SELECT fa.financial_account_id, fa.balance
               FROM customer_profile cp
               JOIN financial_account fa
                 ON cp.financial_account_id = fa.financial_account_id
              WHERE cp.customer_id = :customer_id
              LIMIT 1
              FOR UPDATE"
        );
        $accountStmt->execute([':customer_id' => $customerId]);
        $account = $accountStmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            throw new RuntimeException('No financial account found for this customer.');
        }

        $financialAccountId = (int)$account['financial_account_id'];
        $currentBalance     = (float)$account['balance'];

        // ---- Wallet balance check ----
        if ($paymentMethod === 'Wallet' && $currentBalance < $orderTotal) {
            throw new RuntimeException(
                'Insufficient wallet balance. ' .
                'Available: ₱' . number_format($currentBalance, 2) . ', ' .
                'Required: ₱' . number_format($orderTotal, 2)
            );
        }

        // ---- Insert order ----
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

        // ---- Insert queue items + their customizations ----
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
                if (!is_array($cust)) continue;
                if (($cust['type'] ?? '') === 'notes') continue;
                if (empty($cust['ingredient_id']))     continue;

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
        }

        // -----------------------------------------------------------
        // Record the payment transaction.
        //
        // The after_transaction_insert trigger adjusts the wallet
        // balance for completed transactions. COD stays 'pending'
        // because no money has actually moved yet.
        // -----------------------------------------------------------
        $transactionStatus = ($paymentMethod === 'COD') ? 'pending' : 'completed';

        $description = match ($paymentMethod) {
            'Wallet' => 'Wallet payment for order #' . $orderId,
            'Online' => 'Online payment for order #' . $orderId,
            default  => 'Cash on delivery for order #' . $orderId,
        };

        $txnStmt = $db->prepare(
            "INSERT INTO transaction
                (financial_account_id, order_id, amount, transaction_type,
                 status, description, transaction_date)
             VALUES
                (:financial_account_id, :order_id, :amount, 'payment',
                 :status, :description, NOW())"
        );
        $txnStmt->execute([
            ':financial_account_id' => $financialAccountId,
            ':order_id'             => $orderId,
            ':amount'               => $orderTotal,
            ':status'               => $transactionStatus,
            ':description'          => $description,
        ]);

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
 * Refund a cancelled order to the customer's wallet, when applicable.
 *
 * Behaviour by original payment method:
 *
 *   COD    — no refund transaction. Any pending `payment` transaction
 *            is marked 'failed' so the ledger reflects the cancel.
 *
 *   Wallet — inserts a `completed` refund transaction. The
 *            after_transaction_insert trigger credits the customer's
 *            financial_account.balance automatically.
 *
 *   Online — inserts a `completed` refund transaction for bookkeeping.
 *            No wallet movement.
 *
 * Idempotent: if a completed refund already exists for this order,
 * returns false and does nothing.
 *
 * Legacy orders (placed before payment recording existed) have no
 * payment transaction. Cancelling one is a clean no-op — nothing is
 * refunded because nothing was ever charged.
 *
 * @param PDO $db
 * @param int $orderId
 * @return bool True if a refund was issued or COD cleanup ran;
 *              false if there was nothing to refund.
 * @throws RuntimeException On invalid state (e.g. account missing).
 */
function refundOrderToWallet(PDO $db, int $orderId): bool
{
    $db->beginTransaction();

    try {
        // ---- Lock the order ----
        $orderStmt = $db->prepare(
            "SELECT order_id, customer_id, order_status, payment_method
               FROM orders
              WHERE order_id = :order_id
              FOR UPDATE"
        );
        $orderStmt->execute([':order_id' => $orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $db->rollBack();
            return false;
        }

        $paymentMethod = (string)$order['payment_method'];

        // ---- Idempotency: bail if already refunded ----
        $existingStmt = $db->prepare(
            "SELECT 1 FROM transaction
              WHERE order_id = :order_id
                AND transaction_type = 'refund'
                AND status = 'completed'
              LIMIT 1"
        );
        $existingStmt->execute([':order_id' => $orderId]);
        if ($existingStmt->fetchColumn() !== false) {
            $db->rollBack();
            return false;
        }

        // ---- Resolve the customer's financial account ----
        $accountStmt = $db->prepare(
            "SELECT fa.financial_account_id
               FROM customer_profile cp
               JOIN financial_account fa
                 ON cp.financial_account_id = fa.financial_account_id
              WHERE cp.customer_id = :customer_id
              LIMIT 1
              FOR UPDATE"
        );
        $accountStmt->execute([':customer_id' => (int)$order['customer_id']]);
        $financialAccountId = (int)$accountStmt->fetchColumn();

        if ($financialAccountId <= 0) {
            $db->rollBack();
            throw new RuntimeException('Customer financial account not found.');
        }

        // ---- Look up the original payment amount ----
        $amountStmt = $db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM transaction
              WHERE order_id = :order_id
                AND transaction_type = 'payment'
                AND status IN ('completed', 'pending')"
        );
        $amountStmt->execute([':order_id' => $orderId]);
        $paidAmount = (float)$amountStmt->fetchColumn();

        // -----------------------------------------------------------
        // COD: no money moved. Mark the pending payment as failed.
        // -----------------------------------------------------------
        if ($paymentMethod === 'COD') {
            $failStmt = $db->prepare(
                "UPDATE transaction
                    SET status = 'failed'
                  WHERE order_id = :order_id
                    AND transaction_type = 'payment'
                    AND status = 'pending'"
            );
            $failStmt->execute([':order_id' => $orderId]);

            $db->commit();
            return true;
        }

        // -----------------------------------------------------------
        // Wallet / Online with no recorded payment.
        //
        // Legacy orders placed before payment handling existed have
        // no `payment` transaction. There's nothing to refund — the
        // customer never paid — so this is a clean no-op. The order
        // has already moved to 'refunded' by the caller; that status
        // accurately reflects that no charge stands against it.
        // -----------------------------------------------------------
        if ($paidAmount <= 0) {
            $db->commit();
            return false;
        }

        // -----------------------------------------------------------
        // Wallet / Online: record a completed refund.
        //
        // For Wallet, the trigger credits the balance. For Online,
        // the trigger also credits — the customer never paid through
        // FitPal, but refunding to their wallet is the natural
        // simulation of "money returned."
        // -----------------------------------------------------------
        $refundStmt = $db->prepare(
            "INSERT INTO transaction
                (financial_account_id, order_id, amount, transaction_type,
                 status, description, transaction_date)
             VALUES
                (:financial_account_id, :order_id, :amount, 'refund',
                 'completed', :description, NOW())"
        );
        $refundStmt->execute([
            ':financial_account_id' => $financialAccountId,
            ':order_id'             => $orderId,
            ':amount'               => $paidAmount,
            ':description'          => 'Refund for cancelled order #' . $orderId,
        ]);

        $db->commit();
        return true;

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

/**
 * Get order items summary for display.
 */
function getOrderItemsSummary(PDO $db, int $orderId): array
{
    $stmt = $db->prepare(
        "SELECT
            qi.queue_item_id,
            qi.queue_quantity AS quantity,
            qi.unit_price,
            qi.final_price,
            qi.is_customized,
            p.product_id,
            p.name AS product_name,
            COALESCE(di.images, '') AS product_image
         FROM queue_item qi
         JOIN product p ON qi.product_id = p.product_id
         LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
         WHERE qi.order_id = :order_id
         ORDER BY qi.queue_item_id"
    );
    $stmt->execute([':order_id' => $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get order items with full customization details and review status.
 * Per-product data source for the Orders page.
 */
function getOrderItemsWithCustomizations(PDO $db, int $orderId): array
{
    $stmt = $db->prepare(
        "SELECT
            qi.queue_item_id,
            qi.product_id,
            qi.branch_id,
            qi.queue_quantity   AS quantity,
            qi.unit_price,
            qi.total_price,
            qi.is_customized,
            qi.base_price_snapshot,
            qi.final_price,
            qi.custom_instructions,
            p.name              AS product_name,
            p.description,
            COALESCE(di.images, '') AS product_image,
            rb.branch_name,
            r.business_name     AS restaurant_name
         FROM queue_item qi
         JOIN product p               ON qi.product_id = p.product_id
         LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
         LEFT JOIN restaurant_branch rb   ON qi.branch_id = rb.restaurant_branch_id
         LEFT JOIN restaurant r           ON rb.restaurant_id = r.restaurant_id
         WHERE qi.order_id = :order_id
         ORDER BY qi.queue_item_id ASC"
    );
    $stmt->execute([':order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        return [];
    }

    $queueItemIds = array_column($items, 'queue_item_id');
    $placeholders = implode(',', array_fill(0, count($queueItemIds), '?'));

    $custStmt = $db->prepare(
        "SELECT
            ci.queue_item_id,
            ci.ingredient_id,
            ci.quantity,
            ci.price_at_time,
            ci.calories_at_time,
            ci.is_removed,
            ci.custom_text,
            i.name AS ingredient_name
         FROM customization_instance ci
         LEFT JOIN ingredient i ON ci.ingredient_id = i.ingredient_id
         WHERE ci.queue_item_id IN ({$placeholders})
         ORDER BY ci.queue_item_id ASC, ci.instance_id ASC"
    );
    $custStmt->execute($queueItemIds);
    $allCusts = $custStmt->fetchAll(PDO::FETCH_ASSOC);

    $custByItem = [];
    foreach ($allCusts as $c) {
        $custByItem[(int)$c['queue_item_id']][] = $c;
    }

    $productIds = array_unique(array_column($items, 'product_id'));
    $prodPlaceholders = implode(',', array_fill(0, count($productIds), '?'));

    $revStmt = $db->prepare(
        "SELECT product_id
         FROM feedback
         WHERE order_id = ?
           AND product_id IN ({$prodPlaceholders})"
    );
    $revStmt->execute(array_merge([$orderId], array_values($productIds)));
    $reviewedProducts = array_map('intval', $revStmt->fetchAll(PDO::FETCH_COLUMN));

    foreach ($items as &$item) {
        $qiId = (int)$item['queue_item_id'];
        $item['customizations'] = $custByItem[$qiId] ?? [];
        $item['is_reviewed']    = in_array((int)$item['product_id'], $reviewedProducts, true);
    }
    unset($item);

    return $items;
}

/**
 * Check if a customer can review a specific product in an order.
 */
function canReviewProduct(PDO $db, int $orderId, int $productId, int $customerId): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM orders
         WHERE order_id = :order_id
           AND customer_id = :customer_id
           AND order_status = 'delivered'"
    );
    $stmt->execute([
        ':order_id'    => $orderId,
        ':customer_id' => $customerId
    ]);

    if (!$stmt->fetch()) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT 1 FROM queue_item
         WHERE order_id = :order_id AND product_id = :product_id"
    );
    $stmt->execute([
        ':order_id'   => $orderId,
        ':product_id' => $productId
    ]);

    if (!$stmt->fetch()) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT 1 FROM feedback
         WHERE order_id = :order_id AND product_id = :product_id"
    );
    $stmt->execute([
        ':order_id'   => $orderId,
        ':product_id' => $productId
    ]);

    return !$stmt->fetch();
}