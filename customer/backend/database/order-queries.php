<?php
/**
 * FitPal Order Database Queries
 *
 * Feature file for orders, queue_item, and customization_instance.
 * It owns every query against those tables, plus the pure helpers
 * that operate on rows from them.
 *
 * Why pure helpers live here and not in order-handler.php:
 *
 *   Pages that render order data (orders.php, order-receipt.php)
 *   need to call functions like buildReorderLine(). They cannot
 *   include order-handler.php, because that file runs a full
 *   request dispatch at load time — the switch, the CSRF check,
 *   the echo/exit path. Including it from a page would hijack the
 *   request.
 *
 *   This file only declares functions. It is safe to require from
 *   anywhere.
 *
 * Totals policy: `orders` does not store subtotal, delivery_charge,
 * total_amount, or special_instructions. Those values are computed
 * on read from queue_item plus the fee schedule (see getOrderTotals()).
 *
 * Payment policy:
 *   COD    — records a `payment` transaction with status 'pending'.
 *            No wallet movement at placement. On cancel, the
 *            pending row is flipped to 'failed' and no refund is
 *            issued.
 *   Wallet — requires sufficient balance. Records a `completed`
 *            payment transaction. The after_transaction_insert
 *            trigger deducts from financial_account.balance. On
 *            cancel, a completed refund is issued against the same
 *            wallet account, restoring the balance.
 *   Online — records a `payment` transaction with status 'pending'.
 *            No wallet movement at placement. The payment is
 *            simulated as taking place on an external channel, so
 *            financial_account.balance is not touched. On cancel, a
 *            completed refund is issued against the customer's
 *            wallet account, so the money the customer paid
 *            externally is converted into FitPal wallet balance
 *            rather than lost.
 *
 * Cancellation policy:
 *   Only 'pending' orders are cancellable by the customer. Once the
 *   kitchen accepts an order and moves it to 'preparing', the order
 *   is locked from the customer's side. See
 *   cancelOrderAsCustomer() for the guard clause that enforces this
 *   atomically.
 *
 * Refund policy: see refundOrderToWallet().
 *
 * @package FitPal
 * @version 8.0 — Online payment is no longer treated as a wallet
 *                movement at placement, and its refund no longer
 *                depends on the existence of a wallet-side payment
 *                row.
 *
 *                createOrderFromQueue():
 *                  - The transaction status for 'Online' is now
 *                    'pending', matching 'COD'. A pending row is
 *                    bookkeeping only; the trigger on
 *                    `transaction` does not move the balance for
 *                    pending rows. This means an Online order
 *                    never debits the customer's wallet.
 *
 *                refundOrderToWallet():
 *                  - The refund amount is now read from the order's
 *                    own total (via a queue_item sum) rather than
 *                    from a sum of prior wallet-side payment rows.
 *                    Before this revision the Online path summed
 *                    payment rows that would not exist under the
 *                    new placement rule, so the refund would have
 *                    been zero and the money would have vanished.
 *                  - The COD short-circuit is preserved. COD still
 *                    issues no refund and still flips any pending
 *                    payment row to 'failed'.
 *                  - The Wallet path still refunds the order total
 *                    against the customer's wallet account,
 *                    reversing the placement debit.
 *                  - The idempotency guard is preserved. A second
 *                    completed refund row for the same order is
 *                    still refused, so a cancelled order cannot
 *                    credit the wallet twice.
 *
 *                (7.2: getActiveOrder() treats 'rider_pending' and
 *                'picking_up' as active. 7.1: cancelOrderAsCustomer()
 *                guard tightened to 'pending' only. 7.0: raw SQL
 *                from order-handler.php moved here; reorder line
 *                builder co-located because this file is safe to
 *                require from pages.)
 */

declare(strict_types=1);

require_once __DIR__ . '/branch-queries.php';
require_once __DIR__ . '/fee-queries.php';

/* ---------------------------------------------------------------
 * CREATION
 * --------------------------------------------------------------- */

/**
 * Create an order from a session order queue, atomically, and record
 * the corresponding payment transaction.
 *
 * The queue may span multiple branches. Fees are not stored on the
 * order; they are recomputed on read from queue_item and the fee
 * schedule.
 *
 * Each queue item is expected to be the enriched shape produced by
 * queue-handler.php's queueEnrich(), or the shape written by
 * cart-handler.php's handlePushToQueue():
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

        // ---- Compute the order total ----
        $subtotal = 0.0;
        foreach ($cartItems as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }

        $fees = calculateOrderFees(count($distinctBranches), $subtotal);

        $orderTotal = round(
            $subtotal + $fees['delivery_fee'] + $fees['service_fee'] + $fees['vat'],
            2
        );

        // ---- Resolve and lock the customer's financial account ----
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

        // ---- Record the payment transaction ----
        //
        // Status rules:
        //   COD    → 'pending'. No wallet movement; the payment is
        //            collected in cash on delivery.
        //   Online → 'pending'. No wallet movement; the payment is
        //            simulated as taking place on an external
        //            channel. A pending row is bookkeeping only and
        //            the transaction trigger does not touch
        //            financial_account.balance. On cancel, a
        //            completed refund is issued against the
        //            customer's wallet account so the externally
        //            paid amount is converted into FitPal wallet
        //            balance rather than lost.
        //   Wallet → 'completed'. The trigger deducts the order
        //            total from the customer's wallet account. On
        //            cancel, a completed refund reverses it.
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

        $db->commit();
        return $orderId;

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/* ---------------------------------------------------------------
 * CANCELLATION
 * --------------------------------------------------------------- */

/**
 * Fetch the fields needed to decide whether a customer can cancel
 * an order, scoped to the owner.
 *
 * Returns false if the order does not belong to $customerId.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $customerId
 * @return array{order_id:int, order_status:string, payment_method:string}|false
 */
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

/**
 * Move a customer's order to its final cancelled/refunded state.
 *
 * The guard clause in the UPDATE ensures the transition only happens
 * from 'pending'. If the kitchen has already moved the order to
 * 'preparing' — or a concurrent request beat this one — rowCount() is
 * 0 and this returns false. The caller should treat that as "already
 * processed" rather than retrying.
 *
 * This is the atomic check: the status predicate runs inside the same
 * UPDATE that performs the transition, so there is no window between
 * a read-and-decide and the write.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $customerId
 * @param string $finalStatus  'cancelled' or 'refunded'
 * @return bool True if the row was updated.
 */
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

/**
 * Refund a cancelled order to the customer's wallet, when applicable.
 *
 * Behaviour by original payment method:
 *
 *   COD    — no refund transaction. Any pending `payment` transaction
 *            is marked 'failed'. Nothing ever moved through the
 *            wallet for a COD order, so nothing is refunded.
 *
 *   Wallet — the customer's wallet was debited at placement by the
 *            after_transaction_insert trigger on the completed
 *            `payment` row. A completed `refund` row is now written,
 *            and the trigger credits the wallet back. Net wallet
 *            change across the order's life: zero.
 *
 *   Online — the customer's wallet was NOT debited at placement
 *            (the `payment` row is written 'pending' by
 *            createOrderFromQueue()). The payment is simulated as
 *            taking place on an external channel. A completed
 *            `refund` row is now written against the customer's
 *            wallet account, and the trigger credits the wallet by
 *            the order total. Net wallet change: the externally
 *            paid amount is converted into FitPal wallet balance.
 *
 * Amount source
 * -------------
 * The refund amount is read from the order's own total, computed
 * from queue_item rows. It is NOT read from a prior wallet-side
 * `payment` row, because an Online order has no completed
 * wallet-side payment row to sum. Before this revision the Online
 * path summed payment rows, saw zero, and returned without issuing
 * a refund — the money the customer paid externally would have
 * vanished.
 *
 * Idempotency: if a completed refund already exists for this order,
 * returns false and does nothing. A second cancel request cannot
 * credit the wallet twice.
 *
 * @param PDO $db
 * @param int $orderId
 * @return bool True if a refund was issued or COD cleanup ran.
 * @throws RuntimeException On invalid state.
 */
function refundOrderToWallet(PDO $db, int $orderId): bool
{
    $db->beginTransaction();

    try {
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
        $customerId    = (int)$order['customer_id'];

        // ---- Idempotency guard ----
        //
        // If a completed refund already exists for this order, this
        // is a duplicate cancel. Credit the wallet nothing more.
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

        // ---- Resolve and lock the customer's wallet account ----
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
            $db->rollBack();
            throw new RuntimeException('Customer financial account not found.');
        }

        // ---- COD short-circuit ----
        //
        // COD never moved money through the wallet. Flip any
        // pending payment row to 'failed' as cleanup so the
        // bookkeeping does not show a phantom pending payment
        // forever, and return without issuing a refund.
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

        // ---- Refund amount = the order's own total ----
        //
        // Read from queue_item, the same source getOrderTotals()
        // uses. This is the amount the customer paid for the
        // order, regardless of payment method.
        //
        // Note: the fee schedule (delivery, service, VAT) is
        // computed on read by getOrderTotals(). The refund here
        // uses the item subtotal only, matching the amount the
        // `payment` row was created with at placement time. If
        // the fee policy ever changes so that the stored payment
        // amount and the stored item subtotal diverge, this
        // function must be updated to match.
        $amountStmt = $db->prepare(
            "SELECT COALESCE(
                        SUM(queue_quantity * COALESCE(final_price, unit_price)),
                        0
                    )
               FROM queue_item
              WHERE order_id = :order_id"
        );
        $amountStmt->execute([':order_id' => $orderId]);
        $refundAmount = (float)$amountStmt->fetchColumn();

        if ($refundAmount <= 0) {
            // A valid order always has at least one queue_item.
            // A zero sum here means the order row exists but its
            // items do not, which is a data-integrity problem and
            // not something to silently credit the wallet for.
            $db->commit();
            return false;
        }

        // ---- Issue the refund ----
        //
        // For Wallet: this credits back the placement debit.
        // For Online: this credits the externally-paid amount
        //             into the wallet so the money is not lost.
        // Both cases write the same shape of `refund` row and
        // rely on the after_transaction_insert trigger to
        // credit the wallet.
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

        $db->commit();
        return true;

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/* ---------------------------------------------------------------
 * READS
 * --------------------------------------------------------------- */

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
 *
 * An order is considered "active" for the whole span from placement
 * to delivery. That includes the two rider-facing statuses added in
 * schema v1.3.0:
 *
 *     rider_pending  — kitchen has proposed a rider; rider may
 *                      still decline
 *     picking_up     — rider accepted; en route to / at the
 *                      restaurant; food not yet in hand
 *
 * Both are live, both are visible to the customer on orders.php and
 * order-tracking.php, and both belong in the dashboard's "current
 * order" card. Leaving them out caused the card to briefly blank out
 * while an order was sitting in one of those two statuses.
 */
function getActiveOrder(PDO $db, int $customerId): array|false
{
    $activeStatuses = [
        'pending',
        'preparing',
        'rider_pending',
        'picking_up',
        'delivering',
    ];
    $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));

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

/* ---------------------------------------------------------------
 * REORDER
 * --------------------------------------------------------------- */

/**
 * Fetch the items from a past order, shaped for re-adding to the
 * session queue. Only returns lines that belong to the given
 * customer.
 *
 * Returns one row per queue_item with its original customizations
 * attached. Does NOT validate availability — buildReorderLine()
 * handles that, so the handler can report per-line reasons.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $customerId
 * @return array<int, array<string, mixed>>
 */
function getReorderableItems(PDO $db, int $orderId, int $customerId): array
{
    $ownerStmt = $db->prepare(
        "SELECT 1 FROM orders
          WHERE order_id = :order_id AND customer_id = :customer_id"
    );
    $ownerStmt->execute([
        ':order_id'    => $orderId,
        ':customer_id' => $customerId,
    ]);
    if ($ownerStmt->fetchColumn() === false) {
        return [];
    }

    $itemStmt = $db->prepare(
        "SELECT
            qi.queue_item_id,
            qi.product_id,
            qi.branch_id,
            qi.queue_quantity AS quantity,
            qi.unit_price,
            qi.final_price,
            qi.is_customized,
            qi.base_price_snapshot,
            p.name AS product_name
         FROM queue_item qi
         JOIN product p ON qi.product_id = p.product_id
         WHERE qi.order_id = :order_id
         ORDER BY qi.queue_item_id ASC"
    );
    $itemStmt->execute([':order_id' => $orderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

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
            ci.custom_text
         FROM customization_instance ci
         WHERE ci.queue_item_id IN ({$placeholders})
         ORDER BY ci.queue_item_id ASC, ci.instance_id ASC"
    );
    $custStmt->execute($queueItemIds);

    $custByItem = [];
    while ($row = $custStmt->fetch(PDO::FETCH_ASSOC)) {
        $custByItem[(int)$row['queue_item_id']][] = $row;
    }

    foreach ($items as &$item) {
        $qiId = (int)$item['queue_item_id'];
        $item['customizations'] = $custByItem[$qiId] ?? [];
    }
    unset($item);

    return $items;
}

/**
 * Build a single enriched queue line for reorder, or push a reason
 * into $skipped and return null.
 *
 * Re-implements the pricing/validation logic from queue-handler.php's
 * queueEnrich() rather than including that file (which is a script,
 * not a library). The rules must stay in sync:
 *
 *   - product exists, is_active, stock > 0
 *   - branch and restaurant are active
 *   - customizations are matched against current product_composition
 *   - quantities are clamped to max_quantity
 *   - removed ingredients are skipped
 *   - unit price = base_price + Σ(modifier × qty)
 *
 * Lives here rather than in order-handler.php because pages that
 * render order data cannot require the handler (it runs at load time).
 *
 * @param PDO                  $db
 * @param array<string, mixed> $item
 * @param array<int, array{name:string, reason:string}> $skipped  Mutated in place.
 * @param string               $name  Product name for skip messages.
 * @return array<string, mixed>|null
 */
function buildReorderLine(
    PDO $db,
    array $item,
    array &$skipped,
    string $name
): ?array {
    $productId = (int)($item['product_id'] ?? 0);
    $quantity  = (int)($item['quantity'] ?? 0);

    if ($productId <= 0 || $quantity <= 0) {
        $skipped[] = ['name' => $name, 'reason' => 'Invalid product'];
        return null;
    }

    // Availability probe. Kept separate from the enrichment query so
    // the skip reason can be precise: "no longer on the menu" reads
    // differently from "out of stock" or "restaurant is closed".
    $checkStmt = $db->prepare(
        "SELECT
            p.is_active,
            p.stock,
            rb.is_active AS branch_active,
            r.is_active  AS restaurant_active
         FROM product p
         JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
         JOIN restaurant r         ON rb.restaurant_id       = r.restaurant_id
         WHERE p.product_id = :product_id
         LIMIT 1"
    );
    $checkStmt->execute([':product_id' => $productId]);
    $check = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$check) {
        $skipped[] = ['name' => $name, 'reason' => 'No longer on the menu'];
        return null;
    }
    if (!(int)$check['is_active']) {
        $skipped[] = ['name' => $name, 'reason' => 'No longer available'];
        return null;
    }
    if (!(int)$check['branch_active'] || !(int)$check['restaurant_active']) {
        $skipped[] = ['name' => $name, 'reason' => 'Restaurant is closed'];
        return null;
    }
    if ((int)$check['stock'] <= 0) {
        $skipped[] = ['name' => $name, 'reason' => 'Out of stock'];
        return null;
    }

    // Full product row for pricing + presentation.
    $prodStmt = $db->prepare(
        "SELECT
            p.product_id, p.name, p.price, p.base_price, p.stock,
            p.is_customizable, p.restaurant_branch_id,
            rb.branch_name,
            r.business_name AS restaurant_name,
            COALESCE(di.images, '') AS product_image
         FROM product p
         JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
         JOIN restaurant r         ON rb.restaurant_id       = r.restaurant_id
         LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
         WHERE p.product_id = :product_id
           AND p.is_active = 1
           AND rb.is_active = 1
           AND r.is_active = 1
         LIMIT 1"
    );
    $prodStmt->execute([':product_id' => $productId]);
    $p = $prodStmt->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        $skipped[] = ['name' => $name, 'reason' => 'No longer on the menu'];
        return null;
    }

    $maxStock = (int)$p['stock'];
    if ($quantity > $maxStock) {
        $quantity = $maxStock;
    }

    // Decode customizations.
    $customizations = [];
    if (!empty($item['customization_data'])) {
        $decoded = is_string($item['customization_data'])
            ? json_decode($item['customization_data'], true)
            : $item['customization_data'];
        if (is_array($decoded)) {
            $customizations = $decoded;
        }
    }

    // Current composition rules.
    $rules = [];
    $ruleStmt = $db->prepare(
        "SELECT ingredient_id, price_modifier, min_quantity, max_quantity,
                is_required, is_default, default_quantity
           FROM product_composition
          WHERE product_id = :product_id"
    );
    $ruleStmt->execute([':product_id' => $productId]);
    while ($r = $ruleStmt->fetch(PDO::FETCH_ASSOC)) {
        $rules[(int)$r['ingredient_id']] = $r;
    }

    $basePrice = (float)($p['base_price'] ?? 0);
    if ($basePrice <= 0) {
        $basePrice = (float)$p['price'];
    }

    $unitPrice = $basePrice;

    foreach ($customizations as $cust) {
        if (!is_array($cust)) continue;
        if (($cust['type'] ?? '') === 'notes') continue;

        $ingredientId = (int)($cust['ingredient_id'] ?? 0);
        if ($ingredientId <= 0) continue;

        // Ingredient was dropped from the composition since the
        // original order. Skip it — do not add its modifier.
        if (!isset($rules[$ingredientId])) continue;

        $option = (string)($cust['selected_option'] ?? 'selected');
        if ($option === 'remove') continue;

        $requestedQty = (int)($cust['quantity'] ?? 0);
        if ($requestedQty <= 0) continue;

        $rule     = $rules[$ingredientId];
        $modifier = (float)$rule['price_modifier'];
        $maxQty   = (int)$rule['max_quantity'];
        if ($maxQty > 0 && $requestedQty > $maxQty) {
            $requestedQty = $maxQty;
        }

        $unitPrice += $modifier * $requestedQty;
    }

    if ($unitPrice < 0) {
        $unitPrice = 0.0;
    }

    return [
        'product_id'           => (int)$p['product_id'],
        'name'                 => (string)$p['name'],
        'price'                => round($unitPrice, 2),
        'base_price'           => $basePrice,
        'quantity'             => $quantity,
        'image'                => (string)$p['product_image'],
        'stock'                => $maxStock,
        'restaurant_name'      => (string)$p['restaurant_name'],
        'branch_name'          => (string)$p['branch_name'],
        'restaurant_branch_id' => (int)$p['restaurant_branch_id'],
        'is_customizable'      => (bool)$p['is_customizable'],
        'customization_data'   => $item['customization_data'] ?? null,
    ];
}