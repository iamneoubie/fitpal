<?php
/**
 * FitPal Customer Order Queries
 *
 * Customer-scoped data-access layer for orders, queue_item, and
 * customization_instance. Owns every query the customer pages need
 * to render an order and every pure row-shaper those pages call.
 *
 * ---------------------------------------------------------------------
 * WHAT MOVED OUT OF THIS FILE
 * ---------------------------------------------------------------------
 * The previous revision of this file lived at
 * customer/backend/database/order-queries.php and carried four
 * things that did not belong to a single role:
 *
 *   1. Order creation from a session queue (createOrderFromQueue).
 *      Every role's order-creation path funnels through the shared
 *      layer now. A customer-side, restaurant-side, and admin-side
 *      variant would each write the same ledger rows; three copies
 *      is three chances to diverge.
 *
 *   2. Customer cancellation (cancelOrderAsCustomer).
 *   3. Refund issuance (refundOrderToWallet).
 *   4. Fee math via the fee schedule (calculateOrderFees).
 *
 * All four now live in:
 *
 *     shared/backend/database/order-transaction-queries.php
 *     shared/backend/database/fee-queries.php
 *
 * Every caller that used to reach the old names still reaches them;
 * the names and signatures are unchanged, only the file that
 * declares them is different. Every page and handler that
 * previously required `order-queries.php` requires
 * `customer-order-queries.php` now, and this file re-requires the
 * shared layer so a caller that only knows about this file still
 * gets the shared functions.
 *
 * ---------------------------------------------------------------------
 * SCOPE
 * ---------------------------------------------------------------------
 * This file contains:
 *   - Reads scoped to a single customer.
 *   - Reads scoped to a single order (the caller has already
 *     verified ownership through the shared layer).
 *   - Pure shapers that operate on rows returned by those reads.
 *
 * This file does NOT contain:
 *   - Any SQL that writes orders, transactions, or financial
 *     accounts. Those are shared writes and live in the shared
 *     layer.
 *   - Any SQL that writes queue_item or customization_instance
 *     for order creation. Order creation is a shared write and
 *     lives in the shared layer.
 *   - $_POST, header(), echo, session access.
 *   - HTML, formatting helpers, render helpers.
 *
 * ---------------------------------------------------------------------
 * TOTALS POLICY
 * ---------------------------------------------------------------------
 * The `orders` table does not store subtotal, delivery_charge,
 * total_amount, or any of the fee fields. Every total in this file
 * is computed on read by calling getOrderTotals() in the shared
 * order-transaction layer, which in turn reads the fee schedule in
 * shared/backend/database/fee-queries.php. A change to the fee
 * policy is therefore a change in exactly one file and every
 * reader agrees on the numbers.
 *
 * ---------------------------------------------------------------------
 * PLACEHOLDER RULE
 * ---------------------------------------------------------------------
 * The project runs with PDO::ATTR_EMULATE_PREPARES = false. Native
 * prepares reject a repeated named placeholder in one statement.
 * Every query in this file that needs the same value in more than
 * one position uses distinct placeholder names.
 *
 * @package FitPal
 * @version 2.0 — Renamed from order-queries.php to
 *                customer-order-queries.php.
 *
 *                Cross-role code removed:
 *                  - createOrderFromQueue, cancelOrderAsCustomer,
 *                    refundOrderToWallet, and getOrderTotals were
 *                    removed from this file; they now live in
 *                    shared/backend/database/order-transaction-queries.php
 *                    and are pulled in by the require_once at the
 *                    top.
 *                  - No other function in this file changed name or
 *                    signature. Every customer page that already
 *                    calls getOrderDetails(), getActiveOrder(),
 *                    getOrderTotals(), getOrderItemsSummary(),
 *                    getOrderItemsWithCustomizations(),
 *                    getReorderableItems(), or buildReorderLine()
 *                    keeps working after its require path is
 *                    updated to this file.
 *
 *                (1.0: initial customer order query layer.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/backend/database/order-transaction-queries.php';

/* =============================================================
 * READS — SINGLE ORDER
 * ============================================================= */

/**
 * Get an order with its items and customizations.
 *
 * Total-related fields (subtotal, delivery_charge, total_amount,
 * service_fee, vat) are attached by calling getOrderTotals() in the
 * shared layer rather than read from the table, because the orders
 * table does not store them.
 *
 * The caller is responsible for having verified that the order
 * belongs to the authenticated customer before calling this. The
 * customer-facing handlers do that with getOrderOwnership() from
 * the shared layer.
 *
 * @param PDO $db
 * @param int $orderId
 * @return array<string, mixed>|false
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
        $order['delivery_charge'] = $totals['delivery_fee']
                                  + $totals['service_fee']
                                  + $totals['vat'];
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
 * Get the branch a given order was placed from (via its queue items).
 *
 * @param PDO $db
 * @param int $orderId
 * @return array<string, mixed>|false
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
 * Get the customer's current active order, if any.
 *
 * An order is "active" for the whole span from placement to
 * delivery. That includes the two rider-facing statuses:
 *
 *     rider_pending  — kitchen has proposed a rider; the rider may
 *                      still decline
 *     picking_up     — rider accepted; en route to / at the
 *                      restaurant; food not yet in hand
 *
 * Both are live, both are visible to the customer on orders.php and
 * order-tracking.php, and both belong in the dashboard's "current
 * order" card.
 *
 * @param PDO $db
 * @param int $customerId
 * @return array<string, mixed>|false
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
        $row['delivery_charge'] = $totals['delivery_fee']
                                + $totals['service_fee']
                                + $totals['vat'];
        $row['total_amount']    = $totals['total'];
    } else {
        $row['subtotal']        = 0.0;
        $row['delivery_charge'] = 0.0;
        $row['total_amount']    = 0.0;
    }

    return $row;
}

/* =============================================================
 * READS — ORDER ITEMS
 * ============================================================= */

/**
 * Get order items summary for display.
 *
 * @param PDO $db
 * @param int $orderId
 * @return array<int, array<string, mixed>>
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
 * Get order items with full customization details and per-item
 * review status. Per-product data source for the Orders page.
 *
 * Review status
 * -------------
 * A product is considered reviewed for this order when the order
 * has a feedback envelope carrying a product-type rating whose
 * queue_item_id belongs to this order and whose queue_item points
 * at this product_id.
 *
 * The schema does not store product_id on `feedback`. The correct
 * path is:
 *
 *     queue_item → rating → feedback
 *
 * The rating table's chk_rating_subject_matches_type CHECK
 * constraint guarantees that a row with rating_type = 'product' has
 * a non-NULL queue_item_id and NULL branch_id / rider_id, so
 * filtering on rating_type alone is sufficient.
 *
 * @param PDO $db
 * @param int $orderId
 * @return array<int, array<string, mixed>>
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

    $productIds       = array_values(array_unique(array_column($items, 'product_id')));
    $prodPlaceholders = implode(',', array_fill(0, count($productIds), '?'));

    // Reviewed-product lookup. See docblock for the schema shape.
    // Positional ? is used throughout because native PDO prepares
    // reject mixing named and positional placeholders in one
    // statement.
    $revStmt = $db->prepare(
        "SELECT DISTINCT qi.product_id
           FROM queue_item qi
           JOIN rating r
             ON r.queue_item_id = qi.queue_item_id
            AND r.rating_type  = 'product'
           JOIN feedback f
             ON f.feedback_id = r.feedback_id
          WHERE f.order_id = ?
            AND qi.product_id IN ({$prodPlaceholders})"
    );
    $revStmt->execute(array_merge([$orderId], $productIds));
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
 * Check whether a customer can review a specific product in an
 * order.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $productId
 * @param int $customerId
 * @return bool
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
        ':customer_id' => $customerId,
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
        ':product_id' => $productId,
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
        ':product_id' => $productId,
    ]);

    return !$stmt->fetch();
}

/* =============================================================
 * REORDER
 * ============================================================= */

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
 * Re-implements the pricing and validation rules used by the
 * customer queue flow. The rules must stay in sync with the queue
 * layer:
 *
 *   - product exists, is_active, stock > 0
 *   - branch and restaurant are active
 *   - customizations are matched against current
 *     product_composition
 *   - quantities are clamped to max_quantity
 *   - removed ingredients are skipped
 *   - unit price = base_price + Σ(modifier × qty)
 *
 * Lives here rather than in a handler because pages that render
 * order data cannot require a handler file (a handler runs a full
 * request dispatch at load time).
 *
 * @param PDO   $db
 * @param array $item
 * @param array $skipped  Mutated in place.
 * @param string $name    Product name for skip messages.
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

    // Availability probe. Kept separate from the enrichment query
    // so the skip reason can be precise.
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