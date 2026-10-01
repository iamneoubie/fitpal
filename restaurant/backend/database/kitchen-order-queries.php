<?php
/**
 * FitPal Restaurant Kitchen Queries
 *
 * Kitchen-scoped data-access layer for orders, queue_item,
 * customization_instance, and the rider tables on the branch board.
 * Every read and write the kitchen page and its poll endpoint need.
 *
 * ---------------------------------------------------------------------
 * WHAT MOVED OUT OF THIS FILE
 * ---------------------------------------------------------------------
 * The previous revision of this file lived at
 * restaurant/backend/database/order-queries.php and carried two
 * things that belonged to the shared layer:
 *
 *   1. The refund money movement (refundCustomerForCancelledOrder).
 *      Issuing a `refund` row and relying on the trigger to credit
 *      the customer is a cross-role write. It now lives in
 *      shared/backend/database/order-transaction-queries.php as
 *      refundOrderToWallet(), and the restaurant cancellation
 *      endpoint POSTs to the shared handler instead of running the
 *      refund inline.
 *
 *   2. The status transition decision for a kitchen cancel
 *      ('cancelled' vs 'refunded' by payment method). That decision
 *      is the same decision the customer and rider cancel paths
 *      make, so it now lives in the shared layer as
 *      cancelOrderAsRestaurant(). This file re-exports it through
 *      the require_once at the top so the kitchen page's own
 *      ownership-read helpers still work, but it is no longer
 *      declared here.
 *
 * Every caller that used to reach the removed names still reaches
 * them; the names and signatures are unchanged, only the file that
 * declares them is different. Every kitchen-side caller also has the
 * shared layer available after the require_once below.
 *
 * ---------------------------------------------------------------------
 * WHAT STAYS
 * ---------------------------------------------------------------------
 *   - Reads for the kitchen board: live orders, completed orders,
 *     per-order items with customizations, tab counts, sign-out
 *     guard count.
 *   - Kitchen-only writes: setOrderPreparing,
 *     setOrderCancelledByRestaurant (thin wrapper), assignRiderToOrder,
 *     reassignRiderToOrder, releaseRiderFromOrder.
 *   - Rider roster reads for the assign-rider modal:
 *     getAvailableRidersForBranch, riderActiveOrderCount.
 *   - Pure shapers used by both the page and the poll endpoint:
 *     shapeAvailableRiderRow, shapeAvailableRiderList,
 *     shapeKitchenOrderSummaryRow.
 *
 * ---------------------------------------------------------------------
 * THE KITCHEN CARD RENDERER IS NOT HERE
 * ---------------------------------------------------------------------
 * renderKitchenCard() and its render helpers (kitchenStatusLabel,
 * kitchenStatusBadge, kitchenMoney, kitchenDate,
 * kitchenBranchAddressLine, kitchenRiderVehicleLine) live in exactly
 * two files, because they are consumed by exactly two separate
 * request paths that never load in the same PHP request:
 *
 *     restaurant/pages/kitchen.php
 *     restaurant/backend/handlers/order-handler.php
 *
 * This file is included by both of them, so it must not declare any
 * function that either declares. See the changelog for the earlier
 * fatal "Cannot redeclare" incident that this rule prevents.
 *
 * ---------------------------------------------------------------------
 * CONCURRENT-ORDER CAP
 * ---------------------------------------------------------------------
 * A rider may hold at most 3 orders at once, counting only the
 * orders the rider has ACTUALLY ACCEPTED — 'picking_up' and
 * 'delivering'. An order in 'rider_pending' is a kitchen offer the
 * rider has not yet decided on; it does not occupy a slot.
 *
 * The cap is enforced in three places:
 *   - Here, in assignRiderToOrder and reassignRiderToOrder, under a
 *     FOR UPDATE lock on the rider's profile row.
 *   - In the shared order-transaction layer, on the rider side.
 *   - In the schema trigger before_order_rider_assign.
 *
 * All three count the committed set, so the rule agrees across every
 * caller.
 *
 * @package FitPal
 * @version 8.0 — Renamed from order-queries.php to
 *                restaurant-kitchen-queries.php.
 *
 *                Removed:
 *                  - refundCustomerForCancelledOrder() — now lives
 *                    in shared/backend/database/order-transaction-
 *                    queries.php as part of refundOrderToWallet().
 *                  - cancelOrderAsRestaurant() — now lives in the
 *                    shared layer. The kitchen page and its handler
 *                    use it via the require_once below.
 *
 *                Retained with no signature change:
 *                  - getBranchKitchenOrders, getBranchCompletedOrders,
 *                    getBranchKitchenOrdersPaginated,
 *                    getBranchCompletedOrdersPaginated,
 *                    getKitchenOrderItems, getKitchenTabCounts,
 *                    hasActiveOrdersForBranch,
 *                    countActiveOrdersForBranch,
 *                    riderActiveOrderCount, riderHasActiveDelivery,
 *                    getAvailableRidersForBranch,
 *                    getKitchenOrderCounts, getKitchenOrderOwnership,
 *                    getOwnerKitchenSummary,
 *                    getOwnerBranchBreakdown, setOrderPreparing,
 *                    setOrderCancelledByRestaurant,
 *                    assignRiderToOrder, reassignRiderToOrder,
 *                    releaseRiderFromOrder,
 *                    shapeAvailableRiderRow,
 *                    shapeAvailableRiderList,
 *                    shapeKitchenOrderSummaryRow.
 *
 *                (7.3: removed presentation helpers. 7.2: refund
 *                money movement. 7.1: cancelled_by fix. 7.0:
 *                refunded status. 6.x: kitchen card data and rider
 *                roster shapers. 5.x: pagination and the
 *                concurrent-order cap. 3.0: rider_pending handoff.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/backend/database/order-transaction-queries.php';

/* =============================================================
 * LIVE-STATUS CONSTANTS
 * ============================================================= */

if (!defined('LIVE_RIDER_STATUSES')) {
    define('LIVE_RIDER_STATUSES', ['rider_pending', 'picking_up', 'delivering']);
}

if (!defined('LIVE_BOARD_STATUSES')) {
    define('LIVE_BOARD_STATUSES', ['pending', 'preparing', 'rider_pending', 'picking_up', 'delivering']);
}

if (!defined('SIGN_OUT_GUARD_STATUSES')) {
    define('SIGN_OUT_GUARD_STATUSES', ['pending', 'preparing', 'rider_pending', 'picking_up', 'delivering']);
}

if (!defined('RIDER_CONCURRENT_CAP')) {
    define('RIDER_CONCURRENT_CAP', 3);
}

if (!defined('KITCHEN_DEFAULT_PER_PAGE')) {
    define('KITCHEN_DEFAULT_PER_PAGE', 5);
}

if (!defined('RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS')) {
    define('RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS', 3600);
}

/* =============================================================
 * SHARED SELECT FRAGMENT
 * ============================================================= */

if (!defined('KITCHEN_ORDER_SELECT_COLUMNS')) {
    $graceSeconds = (int)RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS;

    define('KITCHEN_ORDER_SELECT_COLUMNS', "
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
        dr.contact_number AS rider_contact,

        drp.vehicle_type  AS rider_vehicle_type,
        drp.vehicle_plate AS rider_vehicle_plate,

        rb.branch_name,
        rb.branch_code,
        rb.block    AS branch_block,
        rb.barangay AS branch_barangay,
        rb.city     AS branch_city,
        rb.province AS branch_province,

        r.business_name AS restaurant_name,

        CASE
            WHEN o.order_status = 'delivered'
             AND o.delivered_at IS NOT NULL
             AND o.delivered_at >= DATE_SUB(NOW(), INTERVAL $graceSeconds SECOND)
            THEN 1 ELSE 0
        END AS chat_grace_open
    ");
}

if (!defined('KITCHEN_ORDER_JOINS')) {
    define('KITCHEN_ORDER_JOINS', "
        JOIN customer c ON o.customer_id = c.customer_id
        LEFT JOIN delivery_rider dr ON o.delivery_rider_id = dr.delivery_rider_id
        LEFT JOIN delivery_rider_profile drp ON drp.delivery_rider_id = dr.delivery_rider_id
        JOIN queue_item qi ON o.order_id = qi.order_id
        JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
    ");
}

/* =============================================================
 * READS — NON-PAGINATED
 * ============================================================= */

function getBranchKitchenOrders(PDO $db, int $branchId, array $statuses = ['pending', 'preparing']): array
{
    if ($branchId <= 0 || empty($statuses)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    $sql = "SELECT "
         . KITCHEN_ORDER_SELECT_COLUMNS . ",
                COALESCE(SUM(qi.queue_quantity), 0) AS item_count,
                COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS subtotal
            FROM orders o "
         . KITCHEN_ORDER_JOINS . "
            WHERE qi.branch_id = ?
              AND o.order_status IN ($placeholders)
            GROUP BY o.order_id
            ORDER BY o.order_date ASC";

    $params = array_merge([$branchId], array_values($statuses));

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getBranchCompletedOrders(PDO $db, int $branchId, int $limit = 30): array
{
    if ($branchId <= 0) {
        return [];
    }

    $sql = "SELECT "
         . KITCHEN_ORDER_SELECT_COLUMNS . ",
                COALESCE(SUM(qi.queue_quantity), 0) AS item_count,
                COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS subtotal
         FROM orders o "
         . KITCHEN_ORDER_JOINS . "
         WHERE qi.branch_id = :branch_id
           AND o.order_status IN ('delivered','cancelled','refunded')
         GROUP BY o.order_id
         ORDER BY
            CASE
                WHEN o.order_status = 'delivered' THEN COALESCE(o.delivered_at, o.order_date)
                ELSE COALESCE(o.updated_at, o.order_date)
            END DESC
         LIMIT :limit";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =============================================================
 * READS — PAGINATED
 * ============================================================= */

function getBranchKitchenOrdersPaginated(
    PDO $db,
    int $branchId,
    array $statuses = ['pending', 'preparing'],
    int $page = 1,
    int $perPage = KITCHEN_DEFAULT_PER_PAGE
): array {
    if ($branchId <= 0 || empty($statuses)) {
        return [
            'items'      => [],
            'total'      => 0,
            'totalPages' => 1,
            'page'       => 1,
            'perPage'    => $perPage,
        ];
    }

    if ($page    < 1) $page    = 1;
    if ($perPage < 1) $perPage = KITCHEN_DEFAULT_PER_PAGE;

    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    $countSql = "SELECT COUNT(DISTINCT o.order_id)
                   FROM orders o
                   JOIN queue_item qi ON o.order_id = qi.order_id
                  WHERE qi.branch_id = ?
                    AND o.order_status IN ($placeholders)";

    $countStmt = $db->prepare($countSql);
    $countStmt->execute(array_merge([$branchId], array_values($statuses)));
    $total = (int)$countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
    if ($page > $totalPages) $page = $totalPages;

    $offset = ($page - 1) * $perPage;

    $sql = "SELECT "
         . KITCHEN_ORDER_SELECT_COLUMNS . ",
                COALESCE(SUM(qi.queue_quantity), 0) AS item_count,
                COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS subtotal
            FROM orders o "
         . KITCHEN_ORDER_JOINS . "
            WHERE qi.branch_id = ?
              AND o.order_status IN ($placeholders)
            GROUP BY o.order_id
            ORDER BY o.order_date ASC
            LIMIT ? OFFSET ?";

    $stmt = $db->prepare($sql);

    $execValues = array_merge(
        [$branchId],
        array_values($statuses),
        [$perPage, $offset]
    );

    $stmt->execute($execValues);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'items'      => $items,
        'total'      => $total,
        'totalPages' => $totalPages,
        'page'       => $page,
        'perPage'    => $perPage,
    ];
}

function getBranchCompletedOrdersPaginated(
    PDO $db,
    int $branchId,
    int $page = 1,
    int $perPage = KITCHEN_DEFAULT_PER_PAGE
): array {
    if ($branchId <= 0) {
        return [
            'items'      => [],
            'total'      => 0,
            'totalPages' => 1,
            'page'       => 1,
            'perPage'    => $perPage,
        ];
    }

    if ($page    < 1) $page    = 1;
    if ($perPage < 1) $perPage = KITCHEN_DEFAULT_PER_PAGE;

    $countStmt = $db->prepare(
        "SELECT COUNT(DISTINCT o.order_id)
           FROM orders o
           JOIN queue_item qi ON o.order_id = qi.order_id
          WHERE qi.branch_id = :branch_id
            AND o.order_status IN ('delivered','cancelled','refunded')"
    );
    $countStmt->execute([':branch_id' => $branchId]);
    $total = (int)$countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
    if ($page > $totalPages) $page = $totalPages;

    $offset = ($page - 1) * $perPage;

    $sql = "SELECT "
         . KITCHEN_ORDER_SELECT_COLUMNS . ",
                COALESCE(SUM(qi.queue_quantity), 0) AS item_count,
                COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS subtotal
         FROM orders o "
         . KITCHEN_ORDER_JOINS . "
         WHERE qi.branch_id = :branch_id
           AND o.order_status IN ('delivered','cancelled','refunded')
         GROUP BY o.order_id
         ORDER BY
            CASE
                WHEN o.order_status = 'delivered' THEN COALESCE(o.delivered_at, o.order_date)
                ELSE COALESCE(o.updated_at, o.order_date)
            END DESC
         LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'items'      => $items,
        'total'      => $total,
        'totalPages' => $totalPages,
        'page'       => $page,
        'perPage'    => $perPage,
    ];
}

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

/* =============================================================
 * READS — COUNTS AND SIGN-OUT GUARD
 * ============================================================= */

function getKitchenTabCounts(PDO $db, int $branchId): array
{
    $empty = [
        'new'              => 0,
        'preparing'        => 0,
        'waiting_on_rider' => 0,
        'out_for_delivery' => 0,
        'recent'           => 0,
        'total_live'       => 0,
    ];

    if ($branchId <= 0) {
        return $empty;
    }

    $stmt = $db->prepare(
        "SELECT
            SUM(CASE WHEN o.order_status = 'pending'       THEN 1 ELSE 0 END) AS new,
            SUM(CASE WHEN o.order_status = 'preparing'     THEN 1 ELSE 0 END) AS preparing,
            SUM(CASE WHEN o.order_status IN ('rider_pending','picking_up')
                                                    THEN 1 ELSE 0 END) AS waiting_on_rider,
            SUM(CASE WHEN o.order_status = 'delivering'    THEN 1 ELSE 0 END) AS out_for_delivery,
            SUM(CASE WHEN o.order_status IN ('delivered','cancelled','refunded')
                                                    THEN 1 ELSE 0 END) AS recent,
            SUM(CASE WHEN o.order_status IN ('pending','preparing','rider_pending','picking_up','delivering')
                                                    THEN 1 ELSE 0 END) AS total_live
         FROM orders o
         JOIN queue_item qi ON o.order_id = qi.order_id
         WHERE qi.branch_id = :branch_id"
    );
    $stmt->execute([':branch_id' => $branchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'new'              => (int)($row['new']              ?? 0),
        'preparing'        => (int)($row['preparing']        ?? 0),
        'waiting_on_rider' => (int)($row['waiting_on_rider'] ?? 0),
        'out_for_delivery' => (int)($row['out_for_delivery'] ?? 0),
        'recent'           => (int)($row['recent']           ?? 0),
        'total_live'       => (int)($row['total_live']       ?? 0),
    ];
}

function hasActiveOrdersForBranch(PDO $db, int $branchId): bool
{
    if ($branchId <= 0) {
        return false;
    }

    $statuses     = SIGN_OUT_GUARD_STATUSES;
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    $stmt = $db->prepare(
        "SELECT 1
           FROM orders o
           JOIN queue_item qi ON o.order_id = qi.order_id
          WHERE qi.branch_id = ?
            AND o.order_status IN ($placeholders)
          LIMIT 1"
    );
    $stmt->execute(array_merge([$branchId], $statuses));

    return $stmt->fetchColumn() !== false;
}

function countActiveOrdersForBranch(PDO $db, int $branchId): int
{
    if ($branchId <= 0) {
        return 0;
    }

    $statuses     = SIGN_OUT_GUARD_STATUSES;
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    $stmt = $db->prepare(
        "SELECT COUNT(DISTINCT o.order_id)
           FROM orders o
           JOIN queue_item qi ON o.order_id = qi.order_id
          WHERE qi.branch_id = ?
            AND o.order_status IN ($placeholders)"
    );
    $stmt->execute(array_merge([$branchId], $statuses));

    return (int)$stmt->fetchColumn();
}

/**
 * Count a rider's COMMITTED concurrent orders.
 *
 * Counts only 'picking_up' and 'delivering'. An order in
 * 'rider_pending' is a kitchen offer the rider has not yet decided
 * on; it does not occupy a delivery slot.
 */
function riderActiveOrderCount(PDO $db, int $riderId, int $excludeOrderId = 0): int
{
    if ($riderId <= 0) {
        return 0;
    }

    $stmt = $db->prepare(
        "SELECT COUNT(*)
           FROM orders
          WHERE delivery_rider_id = :rider_id
            AND order_status IN ('picking_up','delivering')
            AND order_id <> :exclude_order_id"
    );
    $stmt->execute([
        ':rider_id'         => $riderId,
        ':exclude_order_id' => $excludeOrderId,
    ]);
    return (int)$stmt->fetchColumn();
}

function riderHasActiveDelivery(PDO $db, int $riderId, int $excludeOrderId = 0): bool
{
    return riderActiveOrderCount($db, $riderId, $excludeOrderId) > 0;
}

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
            ) AS rider_city,
            (
                SELECT COUNT(*)
                  FROM orders o2
                 WHERE o2.delivery_rider_id = dr.delivery_rider_id
                   AND o2.order_status IN ('picking_up','delivering')
                   AND o2.order_id <> :exclude_order_id_count
            ) AS active_order_count
         FROM delivery_rider dr
         JOIN delivery_rider_profile drp ON dr.delivery_rider_id = drp.delivery_rider_id
         WHERE dr.is_active = 1
           AND drp.verification_status = 'verified'
           AND (
                drp.is_available = 1
                OR dr.delivery_rider_id IN (
                    SELECT o3.delivery_rider_id
                      FROM orders o3
                     WHERE o3.order_id = :exclude_order_id_online
                       AND o3.delivery_rider_id IS NOT NULL
                )
           )
           AND (
                SELECT COUNT(*)
                  FROM orders o4
                 WHERE o4.delivery_rider_id = dr.delivery_rider_id
                   AND o4.order_status IN ('picking_up','delivering')
                   AND o4.order_id <> :exclude_order_id_filter
           ) < 3
         ORDER BY
            active_order_count ASC,
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
        ':branch_city'                => $branchCity,
        ':exclude_order_id_online'    => $excludeOrderId,
        ':exclude_order_id_filter'    => $excludeOrderId,
        ':exclude_order_id_count'     => $excludeOrderId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getKitchenOrderCounts(PDO $db, int $branchId): array
{
    $empty = [
        'pending'         => 0,
        'preparing'       => 0,
        'rider_pending'   => 0,
        'picking_up'      => 0,
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
            SUM(CASE WHEN o.order_status = 'picking_up'    THEN 1 ELSE 0 END) AS picking_up,
            SUM(CASE WHEN o.order_status = 'delivering'    THEN 1 ELSE 0 END) AS delivering,
            SUM(CASE WHEN o.order_status = 'delivered'
                      AND DATE(o.delivered_at) = CURDATE() THEN 1 ELSE 0 END) AS delivered_today,
            SUM(CASE WHEN o.order_status = 'cancelled'
                      AND DATE(o.updated_at) = CURDATE() THEN 1 ELSE 0 END) AS cancelled_today
         FROM orders o
         JOIN queue_item qi ON o.order_id = qi.order_id
         WHERE qi.branch_id = :branch_id
           AND o.order_status IN (
                'pending','preparing','rider_pending','picking_up',
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
        'picking_up'      => (int)($row['picking_up']      ?? 0),
        'delivering'      => (int)($row['delivering']      ?? 0),
        'delivered_today' => (int)($row['delivered_today'] ?? 0),
        'cancelled_today' => (int)($row['cancelled_today'] ?? 0),
    ];
}

function getKitchenOrderOwnership(PDO $db, int $orderId, int $branchId): array|false
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

function getOwnerKitchenSummary(PDO $db, int $restaurantId): array
{
    $empty = [
        'pending'         => 0,
        'preparing'       => 0,
        'rider_pending'   => 0,
        'picking_up'      => 0,
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
            SUM(CASE WHEN o.order_status = 'picking_up'    THEN 1 ELSE 0 END) AS picking_up,
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
        'picking_up'      => (int)($row['picking_up']      ?? 0),
        'delivering'      => (int)($row['delivering']      ?? 0),
        'delivered_today' => (int)($row['delivered_today'] ?? 0),
        'revenue_today'   => (float)($row['revenue_today'] ?? 0),
        'revenue_7d'      => (float)($row['revenue_7d']    ?? 0),
        'revenue_30d'     => (float)($row['revenue_30d']   ?? 0),
    ];
}

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
            COALESCE(SUM(CASE WHEN o.order_status = 'picking_up'    THEN 1 ELSE 0 END), 0) AS picking_up,
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
 * WRITES — KITCHEN-ONLY STATE TRANSITIONS
 * ============================================================= */

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
 * Thin wrapper over the shared layer's cancelOrderAsRestaurant().
 * It exists under this name so every existing call site keeps
 * working without a signature change. The status decision and the
 * cancelled_by biconditional are handled inside the shared
 * function.
 *
 * Requires: caller-owned transaction.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $branchId
 * @return string|false The new status ('cancelled' or 'refunded')
 *                      on success, or false on failure.
 */
function setOrderCancelledByRestaurant(PDO $db, int $orderId, int $branchId): string|false
{
    return cancelOrderAsRestaurant($db, $orderId, $branchId);
}

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

        if ($current['order_status'] !== 'preparing') {
            $db->rollBack();
            return false;
        }

        if ($current['delivery_rider_id'] !== null) {
            $db->rollBack();
            return false;
        }

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

        $activeCount = riderActiveOrderCount($db, $riderId, $orderId);
        if ($activeCount >= RIDER_CONCURRENT_CAP) {
            $db->rollBack();
            return false;
        }

        $orderStmt = $db->prepare(
            "UPDATE orders o
             JOIN queue_item qi ON qi.order_id = o.order_id
                SET o.delivery_rider_id = :rider_id,
                    o.order_status      = 'rider_pending',
                    o.updated_at        = NOW()
              WHERE o.order_id = :order_id
                AND qi.branch_id = :branch_id
                AND o.order_status = 'preparing'
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

function reassignRiderToOrder(PDO $db, int $orderId, int $branchId, int $newRiderId): array|false
{
    if ($orderId <= 0 || $branchId <= 0 || $newRiderId <= 0) {
        return false;
    }

    $db->beginTransaction();

    try {
        $current = getKitchenOrderOwnership($db, $orderId, $branchId);

        if ($current === false) {
            $db->rollBack();
            return false;
        }

        if (!in_array($current['order_status'], ['preparing', 'rider_pending'], true)) {
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

        $newRiderCount = riderActiveOrderCount($db, $newRiderId, $orderId);
        if ($newRiderCount >= RIDER_CONCURRENT_CAP) {
            $db->rollBack();
            return false;
        }

        $orderStmt = $db->prepare(
            "UPDATE orders o
             JOIN queue_item qi ON qi.order_id = o.order_id
                SET o.delivery_rider_id = :new_rider_id,
                    o.order_status      = 'rider_pending',
                    o.updated_at        = NOW()
              WHERE o.order_id = :order_id
                AND qi.branch_id = :branch_id
                AND o.order_status IN ('preparing','rider_pending')
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
 * Hook for anything that must run on the previously-assigned rider
 * when the kitchen reassigns an order. Currently a no-op, retained
 * so a future change has a name to hang off without touching the
 * reassign flow itself.
 */
function releaseRiderFromOrder(PDO $db, int $riderId, int $excludeOrderId = 0): void
{
    // Intentionally empty.
}

/* =============================================================
 * PURE SHAPERS
 *
 * Consumed by both restaurant/pages/kitchen.php and
 * restaurant/backend/handlers/order-handler.php. Both entry points
 * declare their own render helpers; neither declares these, so
 * declaring them here is the one place they can safely live.
 * ============================================================= */

function shapeAvailableRiderRow(array $row): array
{
    $riderId = (int)($row['delivery_rider_id'] ?? 0);

    $first = trim((string)($row['first_name'] ?? ''));
    $last  = trim((string)($row['last_name']  ?? ''));

    $name = trim($first . ' ' . $last);
    if ($name === '') {
        $name = 'Rider #' . $riderId;
    }

    $initial = '';
    if ($first !== '') {
        $initial = strtoupper(substr($first, 0, 1));
    } elseif ($last !== '') {
        $initial = strtoupper(substr($last, 0, 1));
    } else {
        $initial = 'R';
    }

    $metaParts = [];
    $vehicle   = trim((string)($row['vehicle_type'] ?? ''));
    $plate     = trim((string)($row['vehicle_plate'] ?? ''));
    $city      = trim((string)($row['rider_city']    ?? ''));

    if ($vehicle !== '') $metaParts[] = $vehicle;
    if ($plate   !== '') $metaParts[] = $plate;
    if ($city    !== '') $metaParts[] = $city;

    $meta = implode(' • ', $metaParts);

    $activeCount = (int)($row['active_order_count'] ?? 0);
    $cap         = defined('RIDER_CONCURRENT_CAP') ? RIDER_CONCURRENT_CAP : 3;

    $slotLabel = $activeCount === 0
        ? 'Free'
        : ($activeCount . ' / ' . $cap . ' active');

    return [
        'rider_id'     => $riderId,
        'name'         => $name,
        'meta'         => $meta,
        'rating'       => number_format((float)($row['average_rating'] ?? 0), 1),
        'active_count' => $activeCount,
        'cap'          => $cap,
        'slot_label'   => $slotLabel,
        'is_online'    => (int)($row['is_online'] ?? 0) === 1,
        'initial'      => $initial,
    ];
}

function shapeAvailableRiderList(array $rows): array
{
    return array_map('shapeAvailableRiderRow', $rows);
}

function shapeKitchenOrderSummaryRow(array $row): array
{
    $restaurantName = trim((string)($row['restaurant_name'] ?? ''));
    if ($restaurantName === '') {
        $restaurantName = '—';
    }

    $branchName = trim((string)($row['branch_name'] ?? ''));
    if ($branchName === '') {
        $branchName = '—';
    }

    $customerFirst = trim((string)($row['customer_first_name'] ?? ''));
    $customerLast  = trim((string)($row['customer_last_name']  ?? ''));
    $customerName  = trim($customerFirst . ' ' . $customerLast);
    if ($customerName === '') {
        $customerName = 'Customer';
    }

    $riderFirst = trim((string)($row['rider_first_name'] ?? ''));
    $riderLast  = trim((string)($row['rider_last_name']  ?? ''));
    $riderName  = trim($riderFirst . ' ' . $riderLast);

    $orderStatus   = (string)($row['order_status'] ?? '');
    $isDelivered   = $orderStatus === 'delivered';
    $graceOpen     = (int)($row['chat_grace_open'] ?? 0) === 1;
    $orderTotal    = (float)($row['subtotal'] ?? 0);

    return [
        'order_id'        => (int)($row['order_id'] ?? 0),
        'restaurant_name' => $restaurantName,
        'branch_name'     => $branchName,
        'customer_name'   => $customerName,
        'rider_name'      => $riderName,
        'order_total'     => $orderTotal,
        'is_delivered'    => $isDelivered,
        'chat_grace_open' => $graceOpen,
    ];
}