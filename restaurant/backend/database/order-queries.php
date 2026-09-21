<?php
/**
 * FitPal Restaurant Kitchen Order Queries
 *
 * Pure data-access layer for the restaurant kitchen page. Every query
 * against orders, queue_item, customization_instance, and the rider
 * tables that the kitchen needs to read or write.
 *
 * Scope rules:
 *   - This file contains SQL only.
 *   - No formatting, no HTML, no session access, no side effects
 *     beyond the writes below.
 *   - Status transitions and rider assignment live here as write
 *     functions. The handler that calls them owns all validation.
 *
 * @package FitPal
 * @version 1.2 — getAvailableRidersForBranch() excludes riders who
 *                already have an active delivery. assignRiderToOrder()
 *                no longer rolls back on a no-op UPDATE, so rider
 *                availability is always updated when the order is
 *                eligible.
 */

declare(strict_types=1);

/* =============================================================
 * READS
 * ============================================================= */

/**
 * Fetch the kitchen list for a single branch.
 *
 * One row per order, filtered to the given statuses. Each row carries
 * the customer name, destination address, item count, computed
 * subtotal, and the assigned rider (if any). The caller is expected
 * to call getKitchenOrderItems() per order for the item detail.
 *
 * @param PDO           $db
 * @param int           $branchId
 * @param array<string> $statuses
 * @return array<int, array<string, mixed>>
 */
function getBranchKitchenOrders(PDO $db, int $branchId, array $statuses = ['pending', 'preparing']): array
{
    if ($branchId <= 0 || empty($statuses)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    $sql = "SELECT
                o.order_id,
                o.order_status,
                o.payment_method,
                o.destination_address,
                o.order_date,
                o.delivered_at,
                o.delivery_rider_id,
                c.customer_id,
                c.first_name  AS customer_first_name,
                c.last_name   AS customer_last_name,
                c.contact_number AS customer_contact,
                dr.first_name AS rider_first_name,
                dr.last_name  AS rider_last_name,
                COALESCE(SUM(qi.queue_quantity), 0) AS item_count,
                COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS subtotal
            FROM orders o
            JOIN customer c ON o.customer_id = c.customer_id
            LEFT JOIN delivery_rider dr ON o.delivery_rider_id = dr.delivery_rider_id
            JOIN queue_item qi ON o.order_id = qi.order_id
            WHERE qi.branch_id = ?
              AND o.order_status IN ($placeholders)
            GROUP BY o.order_id
            ORDER BY o.order_date ASC";

    $params = array_merge([$branchId], array_values($statuses));

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch the items of a single order, scoped to a branch, with their
 * customizations. The branch scoping prevents an order from leaking
 * across branches when a multi-branch order exists.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $branchId
 * @return array<int, array<string, mixed>>
 */
function getKitchenOrderItems(PDO $db, int $orderId, int $branchId): array
{
    if ($orderId <= 0 || $branchId <= 0) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT
            qi.queue_item_id,
            qi.product_id,
            qi.queue_quantity AS quantity,
            qi.unit_price,
            qi.final_price,
            qi.is_customized,
            qi.base_price_snapshot,
            qi.custom_instructions,
            p.name AS product_name,
            COALESCE(di.images, '') AS product_image
         FROM queue_item qi
         JOIN product p ON qi.product_id = p.product_id
         LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
         WHERE qi.order_id = :order_id
           AND qi.branch_id = :branch_id
         ORDER BY qi.queue_item_id ASC"
    );
    $stmt->execute([
        ':order_id'  => $orderId,
        ':branch_id' => $branchId,
    ]);
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
         WHERE ci.queue_item_id IN ($placeholders)
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
 * Fetch verified, available riders for a given branch.
 *
 * A rider is eligible for assignment only when ALL of the following
 * hold:
 *
 *   1. delivery_rider.is_active = 1
 *   2. delivery_rider_profile.verification_status = 'verified'
 *   3. delivery_rider_profile.is_available = 1
 *   4. The rider is NOT attached to any order whose status is
 *      'preparing' or 'delivering'. A rider can remain flagged
 *      is_available = 1 in the profile while mid-delivery if the
 *      previous assignment never flipped it back. This filter
 *      makes the restaurant view authoritative regardless of that
 *      stale flag.
 *
 * Riders are ordered so that riders whose default address city
 * matches the branch's city appear first, then by rating, then by
 * fewest completed deliveries. That ordering is presentation only —
 * it does not widen the eligibility set.
 *
 * @param PDO $db
 * @param int $branchId
 * @return array<int, array<string, mixed>>
 */
function getAvailableRidersForBranch(PDO $db, int $branchId): array
{
    if ($branchId <= 0) {
        return [];
    }

    $cityStmt = $db->prepare(
        "SELECT city FROM restaurant_branch WHERE restaurant_branch_id = :branch_id LIMIT 1"
    );
    $cityStmt->execute([':branch_id' => $branchId]);
    $branchCity = (string)($cityStmt->fetchColumn() ?: '');

    $stmt = $db->prepare(
        "SELECT
            dr.delivery_rider_id,
            dr.first_name,
            dr.last_name,
            dr.contact_number,
            drp.vehicle_type,
            drp.vehicle_plate,
            drp.average_rating,
            drp.total_deliveries,
            drp.is_available AS is_online,
            (
                SELECT ra.city
                  FROM delivery_rider_address ra
                 WHERE ra.delivery_rider_id = dr.delivery_rider_id
                 ORDER BY ra.is_default DESC, ra.delivery_rider_address_id ASC
                 LIMIT 1
            ) AS rider_city
         FROM delivery_rider dr
         JOIN delivery_rider_profile drp ON dr.delivery_rider_id = drp.delivery_rider_id
         WHERE dr.is_active = 1
           AND drp.verification_status = 'verified'
           AND drp.is_available = 1
           AND NOT EXISTS (
                SELECT 1
                  FROM orders o2
                 WHERE o2.delivery_rider_id = dr.delivery_rider_id
                   AND o2.order_status IN ('preparing','delivering')
           )
         ORDER BY
            CASE WHEN (
                SELECT ra.city
                  FROM delivery_rider_address ra
                 WHERE ra.delivery_rider_id = dr.delivery_rider_id
                 ORDER BY ra.is_default DESC, ra.delivery_rider_address_id ASC
                 LIMIT 1
            ) = :branch_city THEN 0 ELSE 1 END ASC,
            drp.average_rating DESC,
            drp.total_deliveries ASC
         LIMIT 30"
    );
    $stmt->execute([':branch_city' => $branchCity]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Counts of orders per kitchen-relevant status for a single branch.
 *
 * @param PDO $db
 * @param int $branchId
 * @return array{pending:int, preparing:int, delivering:int, delivered_today:int}
 */
function getKitchenOrderCounts(PDO $db, int $branchId): array
{
    $empty = [
        'pending'         => 0,
        'preparing'       => 0,
        'delivering'      => 0,
        'delivered_today' => 0,
    ];

    if ($branchId <= 0) {
        return $empty;
    }

    $stmt = $db->prepare(
        "SELECT
            SUM(CASE WHEN o.order_status = 'pending'    THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN o.order_status = 'preparing'  THEN 1 ELSE 0 END) AS preparing,
            SUM(CASE WHEN o.order_status = 'delivering' THEN 1 ELSE 0 END) AS delivering,
            SUM(CASE WHEN o.order_status = 'delivered'
                      AND DATE(o.delivered_at) = CURDATE() THEN 1 ELSE 0 END) AS delivered_today
         FROM orders o
         JOIN queue_item qi ON o.order_id = qi.order_id
         WHERE qi.branch_id = :branch_id
           AND o.order_status IN ('pending','preparing','delivering','delivered')"
    );
    $stmt->execute([':branch_id' => $branchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return $empty;
    }

    return [
        'pending'         => (int)($row['pending'] ?? 0),
        'preparing'       => (int)($row['preparing'] ?? 0),
        'delivering'      => (int)($row['delivering'] ?? 0),
        'delivered_today' => (int)($row['delivered_today'] ?? 0),
    ];
}

/**
 * Fetch the ownership and current state of an order for a branch.
 *
 * Returns false if the order does not exist or contains no items in
 * the given branch. Used by the kitchen handler before any write, so
 * a branch account cannot mutate another branch's order.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $branchId
 * @return array{order_id:int, order_status:string, delivery_rider_id:?int, branch_id:int}|false
 */
function getKitchenOrderOwnership(PDO $db, int $orderId, int $branchId): array|false
{
    if ($orderId <= 0 || $branchId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.order_status,
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
        'delivery_rider_id' => $row['delivery_rider_id'] !== null
            ? (int)$row['delivery_rider_id']
            : null,
        'branch_id'         => (int)$row['branch_id'],
    ];
}

/**
 * Return true if the given rider is currently attached to an order
 * whose status is 'preparing' or 'delivering'. Used by the handler
 * as a final eligibility check immediately before assignment.
 *
 * @param PDO $db
 * @param int $riderId
 * @return bool
 */
function riderHasActiveDelivery(PDO $db, int $riderId): bool
{
    if ($riderId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT 1
           FROM orders
          WHERE delivery_rider_id = :rider_id
            AND order_status IN ('preparing','delivering')
          LIMIT 1"
    );
    $stmt->execute([':rider_id' => $riderId]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Summary for the owner kitchen view. Aggregates across every branch
 * of a restaurant: order counts per status and revenue windows.
 *
 * Revenue is derived from queue_item (queue_quantity × COALESCE(final_price, unit_price)),
 * matching the totals policy in the schema.
 *
 * @param PDO $db
 * @param int $restaurantId
 * @return array{
 *     pending:int,
 *     preparing:int,
 *     delivering:int,
 *     delivered_today:int,
 *     revenue_today:float,
 *     revenue_7d:float,
 *     revenue_30d:float
 * }
 */
function getOwnerKitchenSummary(PDO $db, int $restaurantId): array
{
    $empty = [
        'pending'         => 0,
        'preparing'       => 0,
        'delivering'      => 0,
        'delivered_today' => 0,
        'revenue_today'   => 0.0,
        'revenue_7d'      => 0.0,
        'revenue_30d'     => 0.0,
    ];

    if ($restaurantId <= 0) {
        return $empty;
    }

    $stmt = $db->prepare(
        "SELECT
            SUM(CASE WHEN o.order_status = 'pending'    THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN o.order_status = 'preparing'  THEN 1 ELSE 0 END) AS preparing,
            SUM(CASE WHEN o.order_status = 'delivering' THEN 1 ELSE 0 END) AS delivering,
            SUM(CASE WHEN o.order_status = 'delivered'
                      AND DATE(o.delivered_at) = CURDATE() THEN 1 ELSE 0 END) AS delivered_today,

            COALESCE(SUM(CASE
                WHEN o.order_status = 'delivered'
                 AND DATE(o.delivered_at) = CURDATE()
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS revenue_today,

            COALESCE(SUM(CASE
                WHEN o.order_status = 'delivered'
                 AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS revenue_7d,

            COALESCE(SUM(CASE
                WHEN o.order_status = 'delivered'
                 AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS revenue_30d

         FROM orders o
         JOIN queue_item qi ON o.order_id = qi.order_id
         JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
         WHERE rb.restaurant_id = :restaurant_id"
    );
    $stmt->execute([':restaurant_id' => $restaurantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return $empty;
    }

    return [
        'pending'         => (int)($row['pending'] ?? 0),
        'preparing'       => (int)($row['preparing'] ?? 0),
        'delivering'      => (int)($row['delivering'] ?? 0),
        'delivered_today' => (int)($row['delivered_today'] ?? 0),
        'revenue_today'   => (float)($row['revenue_today'] ?? 0),
        'revenue_7d'      => (float)($row['revenue_7d'] ?? 0),
        'revenue_30d'     => (float)($row['revenue_30d'] ?? 0),
    ];
}

/**
 * Per-branch breakdown for the owner kitchen view.
 *
 * @param PDO $db
 * @param int $restaurantId
 * @return array<int, array<string, mixed>>
 */
function getOwnerBranchBreakdown(PDO $db, int $restaurantId): array
{
    if ($restaurantId <= 0) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT
            rb.restaurant_branch_id,
            rb.branch_name,
            rb.branch_code,
            rb.city,
            rb.is_active,
            COALESCE(SUM(CASE WHEN o.order_status = 'pending'   THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN o.order_status = 'preparing' THEN 1 ELSE 0 END), 0) AS preparing,
            COALESCE(SUM(CASE WHEN o.order_status = 'delivering' THEN 1 ELSE 0 END), 0) AS delivering,
            COALESCE(SUM(CASE
                WHEN o.order_status = 'delivered'
                 AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS revenue_7d
         FROM restaurant_branch rb
         LEFT JOIN queue_item qi ON qi.branch_id = rb.restaurant_branch_id
         LEFT JOIN orders o ON qi.order_id = o.order_id
         WHERE rb.restaurant_id = :restaurant_id
         GROUP BY rb.restaurant_branch_id
         ORDER BY rb.is_active DESC, rb.branch_name ASC"
    );
    $stmt->execute([':restaurant_id' => $restaurantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =============================================================
 * WRITES
 *
 * Every write is a single UPDATE. State-transition guards live in
 * the WHERE clause so the update is a no-op if the current status
 * does not match what the handler expected. The handler has already
 * verified ownership and state; the WHERE clause is the last line
 * of defense against a race.
 * ============================================================= */

/**
 * Move an order from 'pending' to 'preparing'.
 *
 * Returns true if a row was updated. Returns false if the order was
 * not in 'pending' at the time of the update, which the handler
 * should surface as "already processed."
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $branchId
 * @return bool
 */
function setOrderPreparing(PDO $db, int $orderId, int $branchId): bool
{
    $stmt = $db->prepare(
        "UPDATE orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
            SET o.order_status = 'preparing',
                o.updated_at   = NOW()
          WHERE o.order_id = :order_id
            AND qi.branch_id = :branch_id
            AND o.order_status = 'pending'"
    );
    $stmt->execute([
        ':order_id'  => $orderId,
        ':branch_id' => $branchId,
    ]);
    return $stmt->rowCount() > 0;
}

/**
 * Cancel an order on behalf of the restaurant.
 *
 * Only allowed while the order is 'pending'. Once a restaurant has
 * started preparing, cancellation is no longer offered by the
 * kitchen — the customer's cancel flow and the admin flow own that
 * case.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $branchId
 * @return bool  True if a row was updated.
 */
function setOrderCancelledByRestaurant(PDO $db, int $orderId, int $branchId): bool
{
    $stmt = $db->prepare(
        "UPDATE orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
            SET o.order_status = 'cancelled',
                o.cancelled_by = 'restaurant',
                o.updated_at   = NOW()
          WHERE o.order_id = :order_id
            AND qi.branch_id = :branch_id
            AND o.order_status = 'pending'"
    );
    $stmt->execute([
        ':order_id'  => $orderId,
        ':branch_id' => $branchId,
    ]);
    return $stmt->rowCount() > 0;
}

/**
 * Assign a rider to an order.
 *
 * Does not change the order status. The kitchen assigns a rider
 * first, then presses "Mark Ready" to move the order to
 * 'delivering'.
 *
 * Read the current order state first, then update, then flip the
 * rider to unavailable. The read-first pattern is what avoids the
 * previous bug: MySQL's rowCount() returns 0 for a no-op UPDATE
 * (assigning the same rider twice), and the previous code treated
 * that as a failure and rolled back the entire transaction,
 * including the rider-availability update. This version only
 * returns false when the order is genuinely not assignable.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $branchId
 * @param int $riderId
 * @return bool  True if the assignment ran, false otherwise.
 */
function assignRiderToOrder(PDO $db, int $orderId, int $branchId, int $riderId): bool
{
    if ($orderId <= 0 || $branchId <= 0 || $riderId <= 0) {
        return false;
    }

    $db->beginTransaction();

    try {
        $current = getKitchenOrderOwnership($db, $orderId, $branchId);

        if ($current === false) {
            $db->rollBack();
            return false;
        }

        if (!in_array($current['order_status'], ['pending', 'preparing'], true)) {
            $db->rollBack();
            return false;
        }

        $orderStmt = $db->prepare(
            "UPDATE orders o
             JOIN queue_item qi ON qi.order_id = o.order_id
                SET o.delivery_rider_id = :rider_id,
                    o.updated_at        = NOW()
              WHERE o.order_id = :order_id
                AND qi.branch_id = :branch_id
                AND o.order_status IN ('pending','preparing')"
        );
        $orderStmt->execute([
            ':rider_id'  => $riderId,
            ':order_id'  => $orderId,
            ':branch_id' => $branchId,
        ]);

        $riderStmt = $db->prepare(
            "UPDATE delivery_rider_profile
                SET is_available = 0
              WHERE delivery_rider_id = :rider_id
                AND is_available = 1"
        );
        $riderStmt->execute([':rider_id' => $riderId]);

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
 * Move an order from 'preparing' to 'delivering'. Requires that a
 * rider has already been assigned.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $branchId
 * @return bool  True if a row was updated.
 */
function setOrderDelivering(PDO $db, int $orderId, int $branchId): bool
{
    $stmt = $db->prepare(
        "UPDATE orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
            SET o.order_status = 'delivering',
                o.updated_at   = NOW()
          WHERE o.order_id = :order_id
            AND qi.branch_id = :branch_id
            AND o.order_status = 'preparing'
            AND o.delivery_rider_id IS NOT NULL"
    );
    $stmt->execute([
        ':order_id'  => $orderId,
        ':branch_id' => $branchId,
    ]);
    return $stmt->rowCount() > 0;
}