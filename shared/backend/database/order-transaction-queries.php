<?php
/**
 * FitPal Shared Order-Transaction Queries
 *
 * The single home for every order + transaction SQL statement that
 * more than one role reaches. Order creation, cancellation, refund,
 * the delivery credit pair, the failed-order liability settlement,
 * and the rider's cash-collection record all live here.
 *
 * ---------------------------------------------------------------------
 * RIDER LIABILITY MODEL (v2.5.0)
 * ---------------------------------------------------------------------
 * Every order a rider accepts produces a liability figure on
 * orders.rider_liability_amount. The figure is the full order
 * total (subtotal + delivery fee + service fee + VAT) and applies
 * regardless of payment method.
 *
 *   On accept
 *       acceptOrder() in rider-assignment-queries.php calls
 *       recordRiderLiability(), which sets the column. The COD-
 *       only rider_collection row is still written by
 *       recordRiderCollection() in the same transaction.
 *
 *   On successful delivery
 *       creditDeliveryPayouts() writes the rider fee deposit, the
 *       restaurant subtotal deposit, and clears
 *       rider_liability_amount to NULL. The rider_collection row
 *       for a COD order is settled in the same call.
 *
 *   On failure
 *       settleFailedRiderLiability() writes a `payment` transaction
 *       against the rider's account for the liability amount and
 *       clears rider_liability_amount. The rider's wallet is
 *       debited by the trigger. The rider_collection row, if any,
 *       is voided.
 *
 * The rider_collection table keeps its v2.4.0 semantics: it is the
 * cash-custody record for COD orders only, and it is not a wallet
 * movement. It is written at accept time for COD, settled on
 * successful delivery, and voided on failure.
 *
 * ---------------------------------------------------------------------
 * WHY THE LIABILITY DEBIT CAN EXCEED THE RIDER'S BALANCE
 * ---------------------------------------------------------------------
 * A rider's account routinely starts at 0.00, and a single order's
 * liability can exceed 300.00. The Insufficient balance guard in
 * before_transaction_insert and before_transaction_update exempts
 * any transaction whose description begins with
 * 'Rider liability for order #'. Every other completed `payment`
 * or `withdrawal` still refuses when its amount exceeds the
 * balance. The exemption is narrow and matches only that exact
 * prefix.
 *
 * ---------------------------------------------------------------------
 * MONEY MOVEMENT: WHO WRITES WHAT
 * ---------------------------------------------------------------------
 * financial_account.balance is never written directly by this file.
 * Every balance change is driven by inserting or updating a
 * transaction row; the after_transaction_insert and
 * after_transaction_update_status triggers apply the delta.
 *
 * The ledger shapes this file writes:
 *
 *   Customer payment   `payment` on the customer account.
 *                        COD    → status 'pending'
 *                        Online → status 'pending'
 *                        Wallet → status 'completed'
 *
 *   Customer refund    `refund` on the customer account, 'completed'.
 *
 *   Rider liability    `payment` on the rider account, 'completed',
 *                      description prefix 'Rider liability for
 *                      order #'. Written only on failure.
 *
 *   Rider + restaurant
 *   delivery credits   Two `deposit` rows written atomically on
 *                      successful delivery.
 *
 * The collection row is NOT a ledger row. It does not appear in the
 * `transaction` table and no trigger reads it.
 *
 * ---------------------------------------------------------------------
 * FAILED-DELIVERY SWEEP (v2.5.0)
 * ---------------------------------------------------------------------
 * sweepFailedDeliveries() fails any order in 'picking_up' OR
 * 'delivering' whose updated_at is older than
 * FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS. The window is
 * currently 45 minutes (2700 seconds), defined in fee-queries.php.
 *
 * Because updated_at changes on every status transition, the timer
 * resets each time the rider moves the order forward. A rider has
 * 45 minutes to go from picking_up to delivering, and a fresh 45
 * minutes to go from delivering to delivered. Total allowed time is
 * up to 90 minutes.
 *
 * When the sweep fails an order:
 *   1. The order_status is set to 'failed'.
 *   2. settleFailedRiderLiability() is called, which writes the
 *      liability `payment` transaction and clears
 *      orders.rider_liability_amount.
 *   3. Any rider_collection row for the order is voided.
 *
 * All three steps run in one transaction per order. A failure in
 * any step rolls back that order's transition only; the sweep
 * continues with the next candidate.
 *
 * @package FitPal
 * @version 3.0.0 — Adds the v2.5.0 rider liability model.
 *
 *                  - recordRiderLiability() writes the liability
 *                    column on accept, for every payment method.
 *                  - creditDeliveryPayouts() clears the column on
 *                    successful delivery.
 *                  - settleFailedRiderLiability() writes the
 *                    failure debit and clears the column.
 *                  - sweepFailedDeliveries() now covers
 *                    'picking_up' as well as 'delivering', and
 *                    calls settleFailedRiderLiability() for every
 *                    order it fails.
 *                  - rider_collection semantics are unchanged.
 *
 *                  (2.5.0: collection row moved to accept time.
 *                  2.0: rider collection model. 1.1: cancelled_by.
 *                  1.0: initial shared order-transaction queries.)
 */

declare(strict_types=1);

require_once __DIR__ . '/fee-queries.php';

/* =============================================================
 * ORDER CREATION
 * ============================================================= */

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

    $subtotal = 0.0;
    foreach ($cartItems as $item) {
        $subtotal += $item['price'] * $item['quantity'];
    }

    $fees = calculateOrderFees(count($distinctBranches), $subtotal);

    $orderTotal = round(
        $subtotal + $fees['delivery_fee'] + $fees['service_fee'] + $fees['vat'],
        2
    );

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

    if ($paymentMethod === 'Wallet' && $currentBalance < $orderTotal) {
        throw new RuntimeException(
            'Insufficient wallet balance. ' .
            'Available: ₱' . number_format($currentBalance, 2) . ', ' .
            'Required: ₱' . number_format($orderTotal, 2)
        );
    }

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

    $transactionStatus = ($paymentMethod === 'Wallet') ? 'completed' : 'pending';

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

    return $orderId;
}

/* =============================================================
 * ORDER LOOKUPS SHARED ACROSS ROLES
 * ============================================================= */

function getOrderOwnership(PDO $db, int $orderId, int $customerId): array|false
{
    $stmt = $db->prepare(
        "SELECT order_id, order_status, payment_method
           FROM orders
          WHERE order_id = :order_id
            AND customer_id = :customer_id
          LIMIT 1"
    );
    $stmt->execute([
        ':order_id'    => $orderId,
        ':customer_id' => $customerId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    return [
        'order_id'       => (int)$row['order_id'],
        'order_status'   => (string)$row['order_status'],
        'payment_method' => (string)$row['payment_method'],
    ];
}

function getRestaurantOrderOwnership(PDO $db, int $orderId, int $branchId): array|false
{
    if ($orderId <= 0 || $branchId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.order_status,
            o.payment_method,
            o.delivery_rider_id,
            qi.branch_id
         FROM orders o
         JOIN queue_item qi ON o.order_id = qi.order_id
         WHERE o.order_id = :order_id
           AND qi.branch_id = :branch_id
         LIMIT 1"
    );
    $stmt->execute([
        ':order_id'  => $orderId,
        ':branch_id' => $branchId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false;
    }

    return [
        'order_id'          => (int)$row['order_id'],
        'order_status'      => (string)$row['order_status'],
        'payment_method'    => (string)$row['payment_method'],
        'delivery_rider_id' => $row['delivery_rider_id'] !== null
            ? (int)$row['delivery_rider_id']
            : null,
        'branch_id'         => (int)$row['branch_id'],
    ];
}

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

/* =============================================================
 * CANCELLATION — CUSTOMER
 * ============================================================= */

function cancelOrderAsCustomer(
    PDO $db,
    int $orderId,
    int $customerId,
    string $finalStatus
): bool {
    $stmt = $db->prepare(
        "UPDATE orders
            SET order_status = :new_status,
                cancelled_by = 'customer',
                updated_at   = NOW()
          WHERE order_id = :order_id
            AND customer_id = :customer_id
            AND order_status = 'pending'"
    );
    $stmt->execute([
        ':new_status'  => $finalStatus,
        ':order_id'    => $orderId,
        ':customer_id' => $customerId,
    ]);
    return $stmt->rowCount() > 0;
}

/* =============================================================
 * CANCELLATION — RESTAURANT
 * ============================================================= */

function cancelOrderAsRestaurant(PDO $db, int $orderId, int $branchId): string|false
{
    $orderInfo = getRestaurantOrderOwnership($db, $orderId, $branchId);

    if ($orderInfo === false) {
        return false;
    }

    $paymentMethod = $orderInfo['payment_method'];

    $newStatus = ($paymentMethod === 'Wallet' || $paymentMethod === 'Online')
        ? 'refunded'
        : 'cancelled';

    $cancelledBy = 'restaurant';

    $stmt = $db->prepare(
        "UPDATE orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
            SET o.order_status = :new_status,
                o.cancelled_by = :cancelled_by,
                o.updated_at   = NOW()
          WHERE o.order_id = :order_id
            AND qi.branch_id = :branch_id
            AND o.order_status IN ('pending','preparing')"
    );

    $stmt->bindValue(':new_status',   $newStatus,   PDO::PARAM_STR);
    $stmt->bindValue(':cancelled_by', $cancelledBy, PDO::PARAM_STR);
    $stmt->bindValue(':order_id',     $orderId,     PDO::PARAM_INT);
    $stmt->bindValue(':branch_id',    $branchId,    PDO::PARAM_INT);

    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        return $newStatus;
    }

    return false;
}

/* =============================================================
 * REFUND
 * ============================================================= */

function refundOrderToWallet(PDO $db, int $orderId): bool
{
    $orderStmt = $db->prepare(
        "SELECT order_id, customer_id, order_status, payment_method
           FROM orders
          WHERE order_id = :order_id
          FOR UPDATE"
    );
    $orderStmt->execute([':order_id' => $orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return false;
    }

    $paymentMethod = (string)$order['payment_method'];
    $customerId    = (int)$order['customer_id'];

    $existingStmt = $db->prepare(
        "SELECT 1 FROM transaction
          WHERE order_id = :order_id
            AND transaction_type = 'refund'
            AND status = 'completed'
          LIMIT 1"
    );
    $existingStmt->execute([':order_id' => $orderId]);
    if ($existingStmt->fetchColumn() !== false) {
        return false;
    }

    $accountStmt = $db->prepare(
        "SELECT fa.financial_account_id
           FROM customer_profile cp
           JOIN financial_account fa
             ON cp.financial_account_id = fa.financial_account_id
          WHERE cp.customer_id = :customer_id
          LIMIT 1
          FOR UPDATE"
    );
    $accountStmt->execute([':customer_id' => $customerId]);
    $financialAccountId = (int)$accountStmt->fetchColumn();

    if ($financialAccountId <= 0) {
        throw new RuntimeException('Customer financial account not found.');
    }

    if ($paymentMethod === 'COD') {
        $failStmt = $db->prepare(
            "UPDATE transaction
                SET status = 'failed'
              WHERE order_id = :order_id
                AND transaction_type = 'payment'
                AND status = 'pending'"
        );
        $failStmt->execute([':order_id' => $orderId]);

        return true;
    }

    $totals = getOrderTotals($db, $orderId);
    if ($totals === false || $totals['total'] <= 0) {
        return false;
    }
    $refundAmount = (float)$totals['total'];

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
        ':amount'               => $refundAmount,
        ':description'          => 'Refund for cancelled order #' . $orderId,
    ]);

    return true;
}

/* =============================================================
 * RIDER LIABILITY (v2.5.0)
 * ============================================================= */

/**
 * Set orders.rider_liability_amount for an accepted order.
 *
 * Called from acceptOrder() in rider-assignment-queries.php, in
 * the caller's transaction, right after the status transition and
 * right alongside recordRiderCollection(). For every payment
 * method the liability is the full order total: subtotal plus
 * delivery fee plus service fee plus VAT.
 *
 * Idempotent by construction: the UPDATE is a plain SET, so
 * calling it twice leaves the same value. A direct caller who
 * wants to reset the liability should not need this function;
 * creditDeliveryPayouts() and settleFailedRiderLiability() are
 * the two paths that clear it.
 *
 * Requires: caller-owned transaction.
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $orderId
 * @return float|false The recorded liability, or false when the
 *                     order is not owned by this rider or its
 *                     totals cannot be resolved.
 */
function recordRiderLiability(PDO $db, int $riderId, int $orderId): float|false
{
    if ($riderId <= 0 || $orderId <= 0) {
        return false;
    }

    $checkStmt = $db->prepare(
        "SELECT 1
           FROM orders
          WHERE order_id = :order_id
            AND delivery_rider_id = :rider_id
          LIMIT 1"
    );
    $checkStmt->execute([
        ':order_id' => $orderId,
        ':rider_id' => $riderId,
    ]);
    if ($checkStmt->fetchColumn() === false) {
        return false;
    }

    $totals = getOrderTotals($db, $orderId);
    if ($totals === false || $totals['total'] <= 0) {
        return false;
    }

    $amount = (float)$totals['total'];

    $stmt = $db->prepare(
        "UPDATE orders
            SET rider_liability_amount = :amount
          WHERE order_id = :order_id
            AND delivery_rider_id = :rider_id"
    );
    $stmt->execute([
        ':amount'   => $amount,
        ':order_id' => $orderId,
        ':rider_id' => $riderId,
    ]);

    return $amount;
}

/**
 * Write the failure settlement for a rider liability.
 *
 * Called from two places:
 *
 *   1. sweepFailedDeliveries(), immediately after the sweep has
 *      transitioned the order to 'failed'.
 *   2. Any future explicit failure or rider-side cancel path that
 *      ends an order with a liability still recorded.
 *
 * Writes a `payment` transaction against the rider's financial
 * account for the liability amount, with the description prefix
 * 'Rider liability for order #' that the transaction triggers
 * exempt from the insufficient-balance guard. Clears
 * orders.rider_liability_amount so the earnings page stops
 * counting the order as outstanding.
 *
 * If the order has no liability recorded (already cleared, or
 * never recorded), this function is a no-op. It does not throw.
 *
 * Requires: caller-owned transaction.
 *
 * @param PDO $db
 * @param int $orderId
 * @return float|false The settled amount, or false when nothing
 *                     was settled.
 */
function settleFailedRiderLiability(PDO $db, int $orderId): float|false
{
    if ($orderId <= 0) {
        return false;
    }

    $orderStmt = $db->prepare(
        "SELECT
            order_id,
            delivery_rider_id,
            payment_method,
            rider_liability_amount
           FROM orders
          WHERE order_id = :order_id
          LIMIT 1
          FOR UPDATE"
    );
    $orderStmt->execute([':order_id' => $orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return false;
    }

    $riderId       = $order['delivery_rider_id'] !== null
        ? (int)$order['delivery_rider_id']
        : 0;
    $liability     = $order['rider_liability_amount'] !== null
        ? (float)$order['rider_liability_amount']
        : 0.0;

    if ($riderId <= 0 || $liability <= 0) {
        return false;
    }

    $acctStmt = $db->prepare(
        "SELECT financial_account_id
           FROM delivery_rider_profile
          WHERE delivery_rider_id = :rider_id
          LIMIT 1
          FOR UPDATE"
    );
    $acctStmt->execute([':rider_id' => $riderId]);
    $riderAccountId = (int)$acctStmt->fetchColumn();

    if ($riderAccountId <= 0) {
        throw new RuntimeException('Rider financial account not found.');
    }

    $dupStmt = $db->prepare(
        "SELECT 1 FROM transaction
          WHERE financial_account_id = :account_id
            AND order_id = :order_id
            AND transaction_type = 'payment'
            AND description LIKE 'Rider liability for order #%'
            AND status = 'completed'
          LIMIT 1"
    );
    $dupStmt->execute([
        ':account_id' => $riderAccountId,
        ':order_id'   => $orderId,
    ]);
    if ($dupStmt->fetchColumn() !== false) {
        // Already settled. Clear the column anyway so the reader
        // stops counting it.
        $clearStmt = $db->prepare(
            "UPDATE orders
                SET rider_liability_amount = NULL
              WHERE order_id = :order_id"
        );
        $clearStmt->execute([':order_id' => $orderId]);
        return false;
    }

    $insStmt = $db->prepare(
        "INSERT INTO transaction
            (financial_account_id, order_id, amount, transaction_type,
             status, description, transaction_date)
         VALUES
            (:account_id, :order_id, :amount, 'payment',
             'completed', :description, NOW())"
    );
    $insStmt->execute([
        ':account_id'  => $riderAccountId,
        ':order_id'    => $orderId,
        ':amount'      => $liability,
        ':description' => 'Rider liability for order #' . $orderId
                       . ' ('
                       . (string)$order['payment_method']
                       . ', failed)',
    ]);

    $clearStmt = $db->prepare(
        "UPDATE orders
            SET rider_liability_amount = NULL
          WHERE order_id = :order_id"
    );
    $clearStmt->execute([':order_id' => $orderId]);

    return $liability;
}

/* =============================================================
 * RIDER COLLECTION (COD CASH CUSTODY, v2.4.0 — unchanged)
 * ============================================================= */

/**
 * Record the rider's cash responsibility for a COD order.
 *
 * Called from acceptOrder() in the rider query layer, inside the
 * caller's open transaction. Only COD orders produce a row; the
 * function reads the order's payment method itself, so a direct
 * caller cannot accidentally create a collection for an Online or
 * Wallet order.
 *
 * Idempotency: the rider_collection table carries a UNIQUE key on
 * (delivery_rider_id, order_id). A retry of accept for the same
 * pair is a no-op.
 *
 * This function is unchanged from v2.4.0. The v2.5.0 liability
 * model is orthogonal: recordRiderLiability() writes the new
 * column for every order, while this function continues to write
 * the COD-only custody row.
 *
 * Requires: caller-owned transaction.
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $orderId
 * @return int|false The rider_collection_id on success, or false
 *                   when the order is not COD.
 */
function recordRiderCollection(PDO $db, int $riderId, int $orderId): int|false
{
    if ($riderId <= 0 || $orderId <= 0) {
        return false;
    }

    $payStmt = $db->prepare(
        "SELECT payment_method
           FROM orders
          WHERE order_id = :order_id
            AND delivery_rider_id = :rider_id
          LIMIT 1"
    );
    $payStmt->execute([
        ':order_id' => $orderId,
        ':rider_id' => $riderId,
    ]);
    $paymentMethod = $payStmt->fetchColumn();

    if ($paymentMethod === false || (string)$paymentMethod !== 'COD') {
        return false;
    }

    $totals = getOrderTotals($db, $orderId);
    if ($totals === false || $totals['total'] <= 0) {
        return false;
    }

    $amount = (float)$totals['total'];

    $stmt = $db->prepare(
        "INSERT INTO rider_collection
            (delivery_rider_id, order_id, amount, status, collected_at)
         VALUES
            (:rider_id, :order_id, :amount, 'collected', NOW())
         ON DUPLICATE KEY UPDATE
            rider_collection_id = LAST_INSERT_ID(rider_collection_id)"
    );
    $stmt->execute([
        ':rider_id' => $riderId,
        ':order_id' => $orderId,
        ':amount'   => $amount,
    ]);

    $rowId = (int)$db->lastInsertId();

    return $rowId > 0 ? $rowId : false;
}

/**
 * Mark a rider's collection row as settled or void.
 *
 * Requires: caller-owned transaction.
 */
function settleRiderCollection(
    PDO $db,
    int $riderId,
    int $orderId,
    string $newStatus,
    string $note = ''
): bool {
    if ($riderId <= 0 || $orderId <= 0) {
        return false;
    }

    if (!in_array($newStatus, ['settled', 'void'], true)) {
        return false;
    }

    $stmt = $db->prepare(
        "UPDATE rider_collection
            SET status     = :new_status,
                settled_at = CASE
                    WHEN :new_status_settled = 'settled' THEN NOW()
                    ELSE settled_at
                END,
                notes = CASE
                    WHEN :note_check <> '' THEN :note_value
                    ELSE notes
                END
          WHERE delivery_rider_id = :rider_id
            AND order_id = :order_id
            AND status = 'collected'"
    );

    $stmt->execute([
        ':new_status'         => $newStatus,
        ':new_status_settled' => $newStatus,
        ':note_check'         => $note,
        ':note_value'         => $note,
        ':rider_id'           => $riderId,
        ':order_id'           => $orderId,
    ]);

    return $stmt->rowCount() > 0;
}

/* =============================================================
 * DELIVERY CREDIT PAIR
 *
 * Writes the rider fee deposit and the restaurant subtotal deposit
 * on successful delivery. Also clears orders.rider_liability_amount
 * (v2.5.0) and settles the rider_collection row for a COD order
 * (v2.4.0).
 * ============================================================= */

function creditDeliveryPayouts(PDO $db, int $riderId, int $orderId): array
{
    if ($riderId <= 0 || $orderId <= 0) {
        throw new RuntimeException('Invalid rider or order for payout.');
    }

    $riderAcctStmt = $db->prepare(
        "SELECT financial_account_id
           FROM delivery_rider_profile
          WHERE delivery_rider_id = :rider_id
          LIMIT 1
          FOR UPDATE"
    );
    $riderAcctStmt->execute([':rider_id' => $riderId]);
    $riderAccountId = (int)$riderAcctStmt->fetchColumn();

    if ($riderAccountId <= 0) {
        throw new RuntimeException('Rider financial account not found.');
    }

    $branchAcctStmt = $db->prepare(
        "SELECT rb.financial_account_id
           FROM orders o
           JOIN queue_item qi ON qi.order_id = o.order_id
           JOIN restaurant_branch rb ON rb.restaurant_branch_id = qi.branch_id
          WHERE o.order_id = :order_id
          LIMIT 1
          FOR UPDATE"
    );
    $branchAcctStmt->execute([':order_id' => $orderId]);
    $restaurantAccountId = (int)$branchAcctStmt->fetchColumn();

    if ($restaurantAccountId <= 0) {
        throw new RuntimeException('Restaurant financial account not found.');
    }

    $totals = getOrderTotals($db, $orderId);
    if ($totals === false) {
        throw new RuntimeException('Order totals could not be resolved.');
    }

    $riderPayout      = calculateRiderPayout((float)$totals['delivery_fee']);
    $restaurantPayout = calculateRestaurantPayout((float)$totals['subtotal']);

    $riderDepositId = false;
    if ($riderPayout > 0) {
        $dupRider = $db->prepare(
            "SELECT 1 FROM transaction
              WHERE financial_account_id = :account_id
                AND order_id = :order_id
                AND transaction_type = 'deposit'
                AND status = 'completed'
              LIMIT 1"
        );
        $dupRider->execute([
            ':account_id' => $riderAccountId,
            ':order_id'   => $orderId,
        ]);

        if ($dupRider->fetchColumn() === false) {
            $insRider = $db->prepare(
                "INSERT INTO transaction
                    (financial_account_id, order_id, amount, transaction_type,
                     status, description, transaction_date)
                 VALUES
                    (:account_id, :order_id, :amount, 'deposit',
                     'completed', :description, NOW())"
            );
            $insRider->execute([
                ':account_id'  => $riderAccountId,
                ':order_id'    => $orderId,
                ':amount'      => $riderPayout,
                ':description' => 'Delivery fee for order #' . $orderId,
            ]);
            $riderDepositId = (int)$db->lastInsertId();
        }
    }

    $restaurantDepositId = false;
    if ($restaurantPayout > 0) {
        $dupRest = $db->prepare(
            "SELECT 1 FROM transaction
              WHERE financial_account_id = :account_id
                AND order_id = :order_id
                AND transaction_type = 'deposit'
                AND status = 'completed'
              LIMIT 1"
        );
        $dupRest->execute([
            ':account_id' => $restaurantAccountId,
            ':order_id'   => $orderId,
        ]);

        if ($dupRest->fetchColumn() === false) {
            $insRest = $db->prepare(
                "INSERT INTO transaction
                    (financial_account_id, order_id, amount, transaction_type,
                     status, description, transaction_date)
                 VALUES
                    (:account_id, :order_id, :amount, 'deposit',
                     'completed', :description, NOW())"
            );
            $insRest->execute([
                ':account_id'  => $restaurantAccountId,
                ':order_id'    => $orderId,
                ':amount'      => $restaurantPayout,
                ':description' => 'Food revenue for order #' . $orderId,
            ]);
            $restaurantDepositId = (int)$db->lastInsertId();
        }
    }

    $collectionSettled = settleRiderCollection(
        $db,
        $riderId,
        $orderId,
        'settled',
        'Settled on successful delivery'
    );

    // v2.5.0: clear the liability column. The credit pair above is
    // what the rider earns; the liability figure exists only for
    // the period between accept and delivery, and is closed on
    // success.
    $clearLiability = $db->prepare(
        "UPDATE orders
            SET rider_liability_amount = NULL
          WHERE order_id = :order_id"
    );
    $clearLiability->execute([':order_id' => $orderId]);

    return [
        'rider'      => $riderDepositId,
        'restaurant' => $restaurantDepositId,
        'collection' => $collectionSettled,
    ];
}

/* =============================================================
 * FAILED-DELIVERY SWEEP (v2.5.0)
 *
 * Widened to cover 'picking_up' as well as 'delivering'. The
 * window is FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS from
 * fee-queries.php.
 *
 * Because the WHERE clause filters on updated_at, the timer
 * resets on every status transition. A rider has 45 minutes to go
 * from picking_up to delivering, and a fresh 45 minutes to go
 * from delivering to delivered.
 *
 * For every order the sweep fails, it calls
 * settleFailedRiderLiability() in the same per-order transaction.
 * That function writes the rider liability `payment` transaction
 * and clears the liability column.
 * ============================================================= */

function sweepFailedDeliveries(PDO $db): int
{
    $graceSeconds = FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS;

    $candidates = $db->prepare(
        "SELECT order_id, delivery_rider_id, payment_method
           FROM orders
          WHERE order_status IN ('picking_up', 'delivering')
            AND updated_at <= DATE_SUB(NOW(), INTERVAL :grace SECOND)"
    );
    $candidates->bindValue(':grace', $graceSeconds, PDO::PARAM_INT);
    $candidates->execute();

    $rows = $candidates->fetchAll(PDO::FETCH_ASSOC);
    $failedCount = 0;

    foreach ($rows as $row) {
        $orderId       = (int)$row['order_id'];
        $riderId       = $row['delivery_rider_id'] !== null
            ? (int)$row['delivery_rider_id']
            : 0;
        $paymentMethod = (string)$row['payment_method'];

        if ($orderId <= 0) {
            continue;
        }

        $db->beginTransaction();
        try {
            $update = $db->prepare(
                "UPDATE orders
                    SET order_status = 'failed',
                        updated_at   = NOW()
                  WHERE order_id = :order_id
                    AND order_status IN ('picking_up', 'delivering')
                    AND updated_at <= DATE_SUB(NOW(), INTERVAL :grace SECOND)"
            );
            $update->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $update->bindValue(':grace',    $graceSeconds, PDO::PARAM_INT);
            $update->execute();

            if ($update->rowCount() > 0) {
                $failedCount++;

                // v2.5.0: settle the rider's liability for the
                // failed order. This writes a `payment` transaction
                // against the rider's account for the liability
                // amount and clears the liability column. The
                // transaction-trigger exemption for the
                // 'Rider liability for order #' prefix is what
                // allows the debit to exceed a zero balance.
                settleFailedRiderLiability($db, $orderId);

                // v2.4.0: void the COD cash-custody row, if any.
                // A Wallet or Online order has no rider_collection
                // row, so this is a no-op for those orders.
                if ($paymentMethod === 'COD' && $riderId > 0) {
                    settleRiderCollection(
                        $db,
                        $riderId,
                        $orderId,
                        'void',
                        'Order failed; cash returned to customer'
                    );
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log(
                'Failed-delivery sweep: order #' . $orderId
                . ' could not be transitioned: ' . $e->getMessage()
            );
        }
    }

    return $failedCount;
}