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
 * Assignment model
 * ----------------
 * Assigning a rider does NOT immediately move the order to
 * 'delivering'. The kitchen sets the rider on the order and moves it
 * to 'rider_pending'. The rider confirms in their own portal, at
 * which point the order becomes 'delivering'. Declining returns the
 * order to 'preparing' with no rider attached.
 *
 * Availability vs. assignment
 * ---------------------------
 * `delivery_rider_profile.is_available` is the rider's OWN toggle.
 * It is set at sign-in (forced offline), toggled by the rider from
 * the dashboard, and cleared on sign-out. The kitchen NEVER writes
 * it. A rider who is online stays online across every delivery they
 * accept — the "one active delivery per rider" rule is enforced by
 * the locking busy-check inside assignRiderToOrder(), not by
 * flipping is_available behind the rider's back.
 *
 * @package FitPal
 * @version 3.0 — Fixes the double-booking race:
 *                  - assignRiderToOrder() and reassignRiderToOrder()
 *                    now lock the target rider's profile row
 *                    (FOR UPDATE) and re-check the rider's active
 *                    workload inside the transaction. The handler's
 *                    pre-check in getAvailableRidersForBranch() is
 *                    advisory only.
 *                  - The availability flag is no longer touched by
 *                    any kitchen-side write. acceptOrder() on the
 *                    rider side no longer flips is_available either.
 *                  - releaseRiderFromOrder() is now a no-op on the
 *                    availability column; it exists only as a
 *                    forward-compatible hook for any future cleanup
 *                    the reassign path might need.
 *
 *                (2.0: Introduced the rider_pending handoff —
 *                getBranchKitchenOrders accepts any status list;
 *                getBranchCompletedOrders reads the closed bucket;
 *                assignRiderToOrder sets rider_pending and leaves
 *                the rider available; setOrderDelivering removed;
 *                riderHasActiveDelivery treats rider_pending as
 *                busy.)
 */

declare(strict_types=1);

/* =============================================================
 * READS
 * ============================================================= */

/**
 * Fetch the kitchen list for a single branch.
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
 * Fetch closed orders for a branch — delivered, cancelled, or
 * refunded. Limited to orders whose closing timestamp is recent,
 * so the page stays fast even on a large database.
 *
 * Ordering prioritises delivered_at for delivered rows and falls
 * back to order_date for rows that were never delivered.
 *
 * @param PDO $db
 * @param int $branchId
 * @param int $limit
 * @return array<int, array<string, mixed>>
 */
function getBranchCompletedOrders(PDO $db, int $branchId, int $limit = 30): array
{
    if ($branchId <= 0) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.order_status,
            o.payment_method,
            o.destination_address,
            o.order_date,
            o.delivered_at,
            o.updated_at,
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
         WHERE qi.branch_id = :branch_id
           AND o.order_status IN ('delivered','cancelled','refunded')
         GROUP BY o.order_id
         ORDER BY
            CASE
                WHEN o.order_status = 'delivered' THEN COALESCE(o.delivered_at, o.order_date)
                ELSE COALESCE(o.updated_at, o.order_date)
            END DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch the items of a single order, scoped to a branch.
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
 * This is an ADVISORY list for the rider-selection modal. It is not
 * the authoritative guard against double-booking — two kitchen tabs
 * loading this list at the same moment will both see the same free
 * rider. The real guard is inside assignRiderToOrder(), which locks
 * the rider's profile row and re-checks their workload before it
 * commits.
 *
 * A rider is eligible only when ALL of the following hold:
 *   1. delivery_rider.is_active = 1
 *   2. delivery_rider_profile.verification_status = 'verified'
 *   3. delivery_rider_profile.is_available = 1
 *   4. The rider is NOT already attached to another order that is
 *      busy — meaning an order in 'preparing', 'rider_pending', or
 *      'delivering'. The current order under edit is excluded so a
 *      reassignment does not filter out the rider already on it.
 *
 * @param PDO $db
 * @param int $branchId
 * @param int $excludeOrderId  Order whose current rider should be ignored.
 * @return array<int, array<string, mixed>>
 */
function getAvailableRidersForBranch(PDO $db, int $branchId, int $excludeOrderId = 0): array
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
           AND (
                drp.is_available = 1
                OR dr.delivery_rider_id IN (
                    SELECT o3.delivery_rider_id
                      FROM orders o3
                     WHERE o3.order_id = :exclude_order_id
                       AND o3.delivery_rider_id IS NOT NULL
                )
           )
           AND NOT EXISTS (
                SELECT 1
                  FROM orders o2
                 WHERE o2.delivery_rider_id = dr.delivery_rider_id
                   AND o2.order_status IN ('preparing','rider_pending','delivering')
                   AND o2.order_id <> :exclude_order_id_2
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
    $stmt->execute([
        ':branch_city'        => $branchCity,
        ':exclude_order_id'   => $excludeOrderId,
        ':exclude_order_id_2' => $excludeOrderId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Counts of orders per kitchen-relevant status for a single branch.
 *
 * @param PDO $db
 * @param int $branchId
 * @return array{
 *     pending:int,
 *     preparing:int,
 *     rider_pending:int,
 *     delivering:int,
 *     delivered_today:int,
 *     cancelled_today:int
 * }
 */
function getKitchenOrderCounts(PDO $db, int $branchId): array
{
    $empty = [
        'pending'         => 0,
        'preparing'       => 0,
        'rider_pending'   => 0,
        'delivering'      => 0,
        'delivered_today' => 0,
        'cancelled_today' => 0,
    ];

    if ($branchId <= 0) {
        return $empty;
    }

    $stmt = $db->prepare(
        "SELECT
            SUM(CASE WHEN o.order_status = 'pending'       THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN o.order_status = 'preparing'     THEN 1 ELSE 0 END) AS preparing,
            SUM(CASE WHEN o.order_status = 'rider_pending' THEN 1 ELSE 0 END) AS rider_pending,
            SUM(CASE WHEN o.order_status = 'delivering'    THEN 1 ELSE 0 END) AS delivering,
            SUM(CASE WHEN o.order_status = 'delivered'
                      AND DATE(o.delivered_at) = CURDATE() THEN 1 ELSE 0 END) AS delivered_today,
            SUM(CASE WHEN o.order_status = 'cancelled'
                      AND DATE(o.updated_at) = CURDATE() THEN 1 ELSE 0 END) AS cancelled_today
         FROM orders o
         JOIN queue_item qi ON o.order_id = qi.order_id
         WHERE qi.branch_id = :branch_id
           AND o.order_status IN (
                'pending','preparing','rider_pending',
                'delivering','delivered','cancelled'
           )"
    );
    $stmt->execute([':branch_id' => $branchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return $empty;
    }

    return [
        'pending'         => (int)($row['pending']         ?? 0),
        'preparing'       => (int)($row['preparing']       ?? 0),
        'rider_pending'   => (int)($row['rider_pending']   ?? 0),
        'delivering'      => (int)($row['delivering']      ?? 0),
        'delivered_today' => (int)($row['delivered_today'] ?? 0),
        'cancelled_today' => (int)($row['cancelled_today'] ?? 0),
    ];
}

/**
 * Fetch the ownership and current state of an order for a branch.
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
 * Return true if the given rider is attached to an active delivery,
 * excluding the order being reassigned.
 *
 * rider_pending counts as busy: the rider has an outstanding request
 * they have not yet accepted or declined, and should not be offered
 * a second one.
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $excludeOrderId
 * @return bool
 */
function riderHasActiveDelivery(PDO $db, int $riderId, int $excludeOrderId = 0): bool
{
    if ($riderId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT 1
           FROM orders
          WHERE delivery_rider_id = :rider_id
            AND order_status IN ('preparing','rider_pending','delivering')
            AND order_id <> :exclude_order_id
          LIMIT 1"
    );
    $stmt->execute([
        ':rider_id'         => $riderId,
        ':exclude_order_id' => $excludeOrderId,
    ]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Summary for the owner kitchen view.
 *
 * Revenue is recognised only on delivered orders. Orders that are
 * still in flight (pending through delivering) are counted in the
 * live buckets, not in revenue.
 *
 * @param PDO $db
 * @param int $restaurantId
 * @return array{
 *     pending:int,
 *     preparing:int,
 *     rider_pending:int,
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
        'rider_pending'   => 0,
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
            SUM(CASE WHEN o.order_status = 'pending'       THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN o.order_status = 'preparing'     THEN 1 ELSE 0 END) AS preparing,
            SUM(CASE WHEN o.order_status = 'rider_pending' THEN 1 ELSE 0 END) AS rider_pending,
            SUM(CASE WHEN o.order_status = 'delivering'    THEN 1 ELSE 0 END) AS delivering,
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
        'pending'         => (int)($row['pending']         ?? 0),
        'preparing'       => (int)($row['preparing']       ?? 0),
        'rider_pending'   => (int)($row['rider_pending']   ?? 0),
        'delivering'      => (int)($row['delivering']      ?? 0),
        'delivered_today' => (int)($row['delivered_today'] ?? 0),
        'revenue_today'   => (float)($row['revenue_today'] ?? 0),
        'revenue_7d'      => (float)($row['revenue_7d']    ?? 0),
        'revenue_30d'     => (float)($row['revenue_30d']   ?? 0),
    ];
}

/**
 * Per-branch breakdown for the owner kitchen view.
 *
 * Revenue in this breakdown also uses delivered-only recognition so
 * it matches the summary card above and the branch dashboard.
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
            COALESCE(SUM(CASE WHEN o.order_status = 'pending'       THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN o.order_status = 'preparing'     THEN 1 ELSE 0 END), 0) AS preparing,
            COALESCE(SUM(CASE WHEN o.order_status = 'rider_pending' THEN 1 ELSE 0 END), 0) AS rider_pending,
            COALESCE(SUM(CASE WHEN o.order_status = 'delivering'    THEN 1 ELSE 0 END), 0) AS delivering,
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
 * ============================================================= */

/**
 * Move an order from 'pending' to 'preparing'.
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
 * Allowed while the order is still pending or being prepared. Once a
 * rider has been requested the order is out of the kitchen's hands.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $branchId
 * @return bool
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
            AND o.order_status IN ('pending','preparing')"
    );
    $stmt->execute([
        ':order_id'  => $orderId,
        ':branch_id' => $branchId,
    ]);
    return $stmt->rowCount() > 0;
}

/**
 * Attach a rider to an order and move it to 'rider_pending'.
 *
 * Double-booking guard
 * --------------------
 * This is the AUTHORITATIVE check against assigning one rider to two
 * orders at the same time. It runs in three steps, all inside one
 * transaction:
 *
 *   1. Re-read the order's ownership and state, and require it to be
 *      'pending' or 'preparing' with delivery_rider_id NULL.
 *   2. Lock the target rider's profile row with FOR UPDATE. Any
 *      concurrent assignRiderToOrder() targeting the same rider
 *      blocks here until the current transaction commits or rolls
 *      back.
 *   3. Under that lock, re-check whether the rider has any OTHER
 *      order in 'preparing', 'rider_pending', or 'delivering'. The
 *      current order is excluded. If a busy row is found, roll back
 *      and return false.
 *
 * The handler's pre-check via getAvailableRidersForBranch() is only
 * there to shape the modal. Two kitchen tabs can both pass that
 * check and still race — step 2 above is what stops the second one.
 *
 * The rider's `is_available` flag is NEVER touched here. Availability
 * is the rider's own toggle; the "one active delivery at a time"
 * rule is enforced by the busy-check, not by locking the rider
 * offline.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $branchId
 * @param int $riderId
 * @return bool
 */
function assignRiderToOrder(PDO $db, int $orderId, int $branchId, int $riderId): bool
{
    if ($orderId <= 0 || $branchId <= 0 || $riderId <= 0) {
        return false;
    }

    $db->beginTransaction();

    try {
        // ---- 1. Order ownership + current state ----
        $current = getKitchenOrderOwnership($db, $orderId, $branchId);

        if ($current === false) {
            $db->rollBack();
            return false;
        }

        if (!in_array($current['order_status'], ['pending', 'preparing'], true)) {
            $db->rollBack();
            return false;
        }

        if ($current['delivery_rider_id'] !== null) {
            $db->rollBack();
            return false;
        }

        // ---- 2. Lock the target rider's profile row ----
        $riderLock = $db->prepare(
            "SELECT delivery_rider_id
               FROM delivery_rider_profile
              WHERE delivery_rider_id = :rider_id
              LIMIT 1
              FOR UPDATE"
        );
        $riderLock->execute([':rider_id' => $riderId]);

        if ($riderLock->fetchColumn() === false) {
            $db->rollBack();
            return false;
        }

        // ---- 3. Re-check workload under the lock ----
        $busy = $db->prepare(
            "SELECT 1
               FROM orders
              WHERE delivery_rider_id = :rider_id
                AND order_status IN ('preparing','rider_pending','delivering')
                AND order_id <> :order_id
              LIMIT 1"
        );
        $busy->execute([
            ':rider_id' => $riderId,
            ':order_id' => $orderId,
        ]);

        if ($busy->fetchColumn() !== false) {
            $db->rollBack();
            return false;
        }

        // ---- 4. Write ----
        $orderStmt = $db->prepare(
            "UPDATE orders o
             JOIN queue_item qi ON qi.order_id = o.order_id
                SET o.delivery_rider_id = :rider_id,
                    o.order_status      = 'rider_pending',
                    o.updated_at        = NOW()
              WHERE o.order_id = :order_id
                AND qi.branch_id = :branch_id
                AND o.order_status IN ('pending','preparing')
                AND o.delivery_rider_id IS NULL"
        );
        $orderStmt->execute([
            ':rider_id'  => $riderId,
            ':order_id'  => $orderId,
            ':branch_id' => $branchId,
        ]);

        if ($orderStmt->rowCount() === 0) {
            $db->rollBack();
            return false;
        }

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
 * Reassign an order from its current rider to a new rider.
 *
 * Double-booking guard
 * --------------------
 * Same pattern as assignRiderToOrder(). The order must currently be
 * attached to a rider and in 'pending', 'preparing', or
 * 'rider_pending'. Both the previous rider's and the new rider's
 * profile rows are locked FOR UPDATE (in ascending id order, to
 * avoid deadlock between two concurrent reassignments that touch
 * the same pair). Under the lock, the new rider's workload is
 * re-checked and the write only proceeds if they have no other
 * active order.
 *
 * The old rider is released via releaseRiderFromOrder(), which in
 * this version does not touch is_available — it exists only as a
 * forward-compatible hook.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $branchId
 * @param int $newRiderId
 * @return array{previous_rider_id:int, new_rider_id:int}|false
 *         Returns false if the order is not in a reassignable state
 *         or already has the same rider.
 */
function reassignRiderToOrder(PDO $db, int $orderId, int $branchId, int $newRiderId): array|false
{
    if ($orderId <= 0 || $branchId <= 0 || $newRiderId <= 0) {
        return false;
    }

    $db->beginTransaction();

    try {
        // ---- 1. Order ownership + current state ----
        $current = getKitchenOrderOwnership($db, $orderId, $branchId);

        if ($current === false) {
            $db->rollBack();
            return false;
        }

        if (!in_array($current['order_status'], ['pending', 'preparing', 'rider_pending'], true)) {
            $db->rollBack();
            return false;
        }

        $previousRiderId = $current['delivery_rider_id'];

        if ($previousRiderId === null) {
            $db->rollBack();
            return false;
        }

        if ($previousRiderId === $newRiderId) {
            $db->rollBack();
            return false;
        }

        // ---- 2. Lock both rider profile rows in ascending id order ----
        $lockIds = [$previousRiderId, $newRiderId];
        sort($lockIds, SORT_NUMERIC);

        $lockStmt = $db->prepare(
            "SELECT delivery_rider_id
               FROM delivery_rider_profile
              WHERE delivery_rider_id = :rider_id
              LIMIT 1
              FOR UPDATE"
        );

        foreach ($lockIds as $lockId) {
            $lockStmt->execute([':rider_id' => $lockId]);
            if ($lockStmt->fetchColumn() === false) {
                $db->rollBack();
                return false;
            }
        }

        // ---- 3. Re-check the NEW rider's workload under the lock ----
        $busy = $db->prepare(
            "SELECT 1
               FROM orders
              WHERE delivery_rider_id = :rider_id
                AND order_status IN ('preparing','rider_pending','delivering')
                AND order_id <> :order_id
              LIMIT 1"
        );
        $busy->execute([
            ':rider_id' => $newRiderId,
            ':order_id' => $orderId,
        ]);

        if ($busy->fetchColumn() !== false) {
            $db->rollBack();
            return false;
        }

        // ---- 4. Write ----
        $orderStmt = $db->prepare(
            "UPDATE orders o
             JOIN queue_item qi ON qi.order_id = o.order_id
                SET o.delivery_rider_id = :new_rider_id,
                    o.order_status      = 'rider_pending',
                    o.updated_at        = NOW()
              WHERE o.order_id = :order_id
                AND qi.branch_id = :branch_id
                AND o.order_status IN ('pending','preparing','rider_pending')
                AND o.delivery_rider_id = :previous_rider_id"
        );
        $orderStmt->execute([
            ':new_rider_id'      => $newRiderId,
            ':order_id'          => $orderId,
            ':branch_id'         => $branchId,
            ':previous_rider_id' => $previousRiderId,
        ]);

        if ($orderStmt->rowCount() === 0) {
            $db->rollBack();
            return false;
        }

        releaseRiderFromOrder($db, $previousRiderId, $orderId);

        $db->commit();

        return [
            'previous_rider_id' => $previousRiderId,
            'new_rider_id'      => $newRiderId,
        ];

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Release a rider from an assignment.
 *
 * In this version, availability is not touched. `is_available` is
 * the rider's own toggle and must not be flipped by the kitchen —
 * the "one active delivery" rule is enforced at assignment time by
 * the locking busy-check, so there is no state to reset here.
 *
 * The function is kept as a stub so that the call site in
 * reassignRiderToOrder() stays meaningful if future cleanup logic
 * (e.g. a cooldown timestamp, a notification trigger) needs a single
 * place to hang off the release path. It is designed to be called
 * inside an open transaction.
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $excludeOrderId
 * @return void
 */
function releaseRiderFromOrder(PDO $db, int $riderId, int $excludeOrderId = 0): void
{
    // Intentionally empty. See docblock.
}