<?php
/**
 * FitPal Restaurant Kitchen Order Queries
 *
 * Pure data-access layer for the restaurant kitchen page. Every query
 * against orders, queue_item, customization_instance, and the rider
 * tables that the kitchen needs to read or write.
 *
 * Scope rules:
 *   - This file contains SQL and pure shapers only.
 *   - No formatting beyond what a shaper does; no HTML, no session
 *     access, no side effects beyond the writes below.
 *   - Status transitions and rider assignment live here as write
 *     functions. The handler that calls them owns all validation.
 *
 * Assignment model
 * ----------------
 * Assigning a rider does NOT immediately move the order to
 * 'delivering'. The kitchen sets the rider on the order and moves it
 * to 'rider_pending'. The rider confirms in their own portal, at
 * which point the order becomes 'picking_up'. The rider then taps
 * "Mark Picked Up" to move it to 'delivering', and finally "Mark
 * Delivered" to close it. Declining at rider_pending returns the
 * order to 'preparing' with no rider attached.
 *
 * STEP-BY-STEP LIFECYCLE (enforced by assignRiderToOrder below)
 * -------------------------------------------------------------
 * A rider may only be attached to an order that is already being
 * prepared. The kitchen's sequence is strict:
 *
 *     pending   --Start Preparing-->   preparing
 *     preparing --Assign Rider---->    rider_pending
 *     rider_pending --(rider accepts)--> picking_up
 *     picking_up --(rider marks picked up)--> delivering
 *     delivering --(rider marks delivered)--> delivered
 *
 * assignRiderToOrder() and reassignRiderToOrder() refuse any order
 * still in 'pending'. The kitchen must press Start Preparing first.
 *
 * Availability vs. assignment
 * ---------------------------
 * `delivery_rider_profile.is_available` is the rider's OWN toggle.
 * It is set at sign-in (forced offline), toggled by the rider from
 * the dashboard, and cleared on sign-out. The kitchen NEVER writes
 * it.
 *
 * Concurrent-order cap
 * --------------------
 * A rider may hold at most 3 orders at once, counting across the
 * three live delivery-facing statuses:
 *
 *     rider_pending  — kitchen asked; rider has not yet decided
 *     picking_up     — rider accepted; en route to / at the
 *                      restaurant; food not yet in hand
 *     delivering     — rider has the food; en route to customer
 *
 * The cap is enforced in three places that must always agree:
 *   1. assignRiderToOrder() and reassignRiderToOrder(), under a
 *      FOR UPDATE lock on the target rider's profile row. This is
 *      the authoritative check.
 *   2. The before_order_rider_assign SQL trigger, which is the
 *      last-resort guard if a race slips past the app's lock.
 *   3. getAvailableRidersForBranch(), which hides riders already
 *      at the cap so the kitchen's picker never shows a rider who
 *      cannot accept another assignment.
 *
 * Rider roster shapers
 * --------------------
 * shapeAvailableRiderRow() and shapeAvailableRiderList() are pure
 * helpers that turn raw rows from getAvailableRidersForBranch()
 * into the JSON shape the kitchen's rider modal consumes.
 *
 * Kitchen order card
 * ------------------
 * Every reader that feeds the kitchen card pulls a consistent set of
 * columns so the card can render, without extra round trips:
 *
 *   - branch address (pickup block)
 *   - rider name, contact, vehicle type, and vehicle plate
 *   - customer name, contact, and destination
 *   - item count, subtotal
 *   - the delivered-grace window flag on completed rows
 *
 * The grace flag (`chat_grace_open`) is computed in SQL. Its window
 * length is derived from RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS,
 * which is defined in PHP and then inlined as a numeric literal into
 * the shared SELECT fragment at load time. Inlining is what keeps
 * the fragment compatible with PDO's native prepare mode, which
 * refuses any statement that mixes named and positional placeholders.
 *
 * Grace constant
 * --------------
 * RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS is defined here and in
 * chat-queries.php with the same value. Neither file requires the
 * other; whichever loads first wins, and both files guard their
 * define() so a double-load is a no-op.
 *
 * @package FitPal
 * @version 6.1 — Mixed-placeholder bug fix:
 *                  - KITCHEN_ORDER_SELECT_COLUMNS no longer emits
 *                    :grace_seconds. The grace window length is
 *                    inlined into the fragment as a numeric
 *                    literal at load time, computed from
 *                    RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS.
 *                  - All four order readers drop the
 *                    :grace_seconds bind value from their
 *                    parameter lists. The two live readers become
 *                    fully positional. The two completed readers
 *                    stay all-named (they never used a positional
 *                    placeholder for the CASE).
 *                  - The chat_grace_open column still returns 1 or
 *                    0, computed identically to the previous
 *                    revision.
 *                  - No other function changed.
 *
 *                (6.0: kitchen card render data. 5.0: rider
 *                roster shapers. 4.0: pagination and sign-out
 *                guard. 3.1: per-rider concurrent-order cap raised
 *                from 1 to 3; added 'picking_up'. 3.0: double-
 *                booking fix for a cap of 1. 2.0: rider_pending
 *                handoff. 1.0: initial kitchen queries.)
 */

declare(strict_types=1);

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

/**
 * Post-delivery chat grace window, in seconds.
 *
 * Must match RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS in
 * restaurant/backend/database/chat-queries.php. Defined here as well
 * so this file can compute the derived `chat_grace_open` column in
 * SQL without requiring chat-queries.php. The two files do not
 * require each other; whichever loads first defines the constant,
 * and both guard the define() so a double-load is a no-op.
 *
 * The value is inlined into KITCHEN_ORDER_SELECT_COLUMNS below as a
 * numeric literal at load time. It is never passed through a
 * prepared-statement placeholder, because mixing named and
 * positional placeholders is a hard error under PDO's native
 * prepare mode (ATTR_EMULATE_PREPARES = false).
 */
if (!defined('RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS')) {
    define('RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS', 3600);
}

/* =============================================================
 * SHARED SELECT FRAGMENT
 *
 * Every kitchen order reader returns the same column set so the card
 * renderer never has to branch on origin. The fragment is a string
 * constant, not a function, because concatenating it into a
 * prepared statement at build time is what keeps the four readers
 * in lockstep.
 *
 * Why the grace window is inlined
 * -------------------------------
 * The grace window value comes from RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS
 * and is a fixed integer with no user input. It is inlined into the
 * SQL as a numeric literal so the fragment never contains a named
 * placeholder. This is what makes the fragment safe to concatenate
 * into a statement that also uses positional `?` placeholders —
 * PDO's native prepare mode refuses any statement that mixes the
 * two, and the error it raises (SQLSTATE[HY093]) is opaque at the
 * call site. Inlining removes the hazard entirely.
 *
 * The value is fetched from the constant at file load time. Because
 * the define() above runs before this fragment is built, the literal
 * in the SQL string reflects the current value of the constant, and
 * a change to the constant propagates without any other edit.
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
 * READS — NON-PAGINATED (kept for the poll path)
 * ============================================================= */

/**
 * Fetch the kitchen list for a single branch.
 *
 * Fully positional placeholders. The chat_grace_open CASE expression
 * is inside the shared fragment and no longer carries a named
 * placeholder, so the statement is unambiguous under PDO's native
 * prepare mode.
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

/**
 * Fetch closed orders for a branch — delivered, cancelled, or
 * refunded. Limited to orders whose closing timestamp is recent.
 *
 * All-named placeholders. No positional placeholder appears in this
 * statement, so the named binds work as expected under PDO's native
 * prepare mode.
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

/**
 * Paginated kitchen list for one branch.
 *
 * Fully positional placeholders, including the LIMIT and OFFSET
 * tail. The chat_grace_open CASE expression is inside the shared
 * fragment and no longer carries a named placeholder.
 *
 * @param PDO           $db
 * @param int           $branchId
 * @param array<string> $statuses
 * @param int           $page
 * @param int           $perPage
 * @return array{items:array<int,array<string,mixed>>,total:int,totalPages:int,page:int,perPage:int}
 */
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

    // ---- Total count ----
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

    // ---- One page of rows ----
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

/**
 * Paginated closed-order list for one branch.
 *
 * All-named placeholders. No positional placeholder appears in this
 * statement.
 *
 * @param PDO $db
 * @param int $branchId
 * @param int $page
 * @param int $perPage
 * @return array{items:array<int,array<string,mixed>>,total:int,totalPages:int,page:int,perPage:int}
 */
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

    // ---- Total count ----
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

    // ---- One page of rows ----
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

/**
 * Fetch the items of a single order, scoped to a branch.
 *
 * All-named placeholders.
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

/* =============================================================
 * READS — COUNTS AND SIGN-OUT GUARD
 * ============================================================= */

/**
 * Return the per-tab counts in one query.
 *
 * All-named placeholders.
 *
 * @param PDO $db
 * @param int $branchId
 * @return array{
 *     new:int,
 *     preparing:int,
 *     waiting_on_rider:int,
 *     out_for_delivery:int,
 *     recent:int,
 *     total_live:int
 * }
 */
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

/**
 * True when the branch has at least one order in a live status.
 *
 * Fully positional placeholders.
 *
 * @param PDO $db
 * @param int $branchId
 * @return bool
 */
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

/**
 * Count of live orders for the branch.
 *
 * Fully positional placeholders.
 *
 * @param PDO $db
 * @param int $branchId
 * @return int
 */
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
 * Count a rider's concurrent live orders.
 *
 * All-named placeholders.
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $excludeOrderId
 * @return int
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
            AND order_status IN ('rider_pending','picking_up','delivering')
            AND order_id <> :exclude_order_id"
    );
    $stmt->execute([
        ':rider_id'         => $riderId,
        ':exclude_order_id' => $excludeOrderId,
    ]);
    return (int)$stmt->fetchColumn();
}

/**
 * Compatibility shim over riderActiveOrderCount().
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $excludeOrderId
 * @return bool
 */
function riderHasActiveDelivery(PDO $db, int $riderId, int $excludeOrderId = 0): bool
{
    return riderActiveOrderCount($db, $riderId, $excludeOrderId) > 0;
}

/**
 * Fetch verified, available riders for a given branch.
 *
 * All-named placeholders.
 *
 * @param PDO $db
 * @param int $branchId
 * @param int $excludeOrderId
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
            ) AS rider_city,
            (
                SELECT COUNT(*)
                  FROM orders o2
                 WHERE o2.delivery_rider_id = dr.delivery_rider_id
                   AND o2.order_status IN ('rider_pending','picking_up','delivering')
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
                   AND o4.order_status IN ('rider_pending','picking_up','delivering')
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

/**
 * Counts of orders per kitchen-relevant status for a single branch.
 *
 * All-named placeholders.
 *
 * @param PDO $db
 * @param int $branchId
 * @return array{
 *     pending:int,
 *     preparing:int,
 *     rider_pending:int,
 *     picking_up:int,
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

/**
 * Fetch the ownership and current state of an order for a branch.
 *
 * All-named placeholders.
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
 * Summary for the owner kitchen view.
 *
 * All-named placeholders.
 *
 * @param PDO $db
 * @param int $restaurantId
 * @return array{
 *     pending:int,
 *     preparing:int,
 *     rider_pending:int,
 *     picking_up:int,
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

/**
 * Per-branch breakdown for the owner kitchen view.
 *
 * All-named placeholders.
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
 * WRITES
 * ============================================================= */

/**
 * Move an order from 'pending' to 'preparing'.
 *
 * All-named placeholders.
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
 * All-named placeholders.
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
 * All-named placeholders.
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

/**
 * Reassign an order from its current rider to a new rider.
 *
 * All-named placeholders.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $branchId
 * @param int $newRiderId
 * @return array{previous_rider_id:int, new_rider_id:int}|false
 */
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
 * Release a rider from an assignment. Stub retained for a future
 * cleanup hook. See the previous revision's docblock.
 */
function releaseRiderFromOrder(PDO $db, int $riderId, int $excludeOrderId = 0): void
{
    // Intentionally empty.
}

/* =============================================================
 * PRESENTATION HELPERS (pure — no DB access)
 * ============================================================= */

/**
 * Shape a raw available-rider row into the JSON payload the
 * kitchen's rider-selection modal consumes.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
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

/**
 * Shape a list of raw available-rider rows.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function shapeAvailableRiderList(array $rows): array
{
    return array_map('shapeAvailableRiderRow', $rows);
}

/**
 * Shape a raw kitchen order row into the summary-line fields the
 * collapsed card body renders.
 *
 * A card in its collapsed state shows one line with the facts the
 * kitchen needs to identify the order at a glance:
 *
 *   Restaurant Name • Branch Name • Customer Name • Rider Name • Total
 *
 * Every one of those facts is already present on the row returned
 * by the order readers (see KITCHEN_ORDER_SELECT_COLUMNS). The
 * shaper does not query anything; it reads fields off the row and
 * normalises the ones that can be null or empty.
 *
 * Fields returned:
 *
 *   order_id        int      primary key
 *   restaurant_name string   "Green Bowl Cafe"
 *   branch_name     string   "Main Branch"
 *   customer_name   string   "Peter Parker", or "Customer" when blank
 *   rider_name      string   "Carlos Dela Cruz", or "" when none
 *   order_total     float    subtotal as stored on the row
 *   is_delivered    bool     true when status = 'delivered'
 *   chat_grace_open bool     true only when a delivered order is
 *                            still inside the grace window
 *
 * The shaper does not decide what the summary line should look
 * like. It only normalises the values. The summary-line template
 * lives in the card renderer, one place, so the server-rendered
 * fallback and the polled payload always agree.
 *
 * @param array<string, mixed> $row
 * @return array{
 *     order_id:int,
 *     restaurant_name:string,
 *     branch_name:string,
 *     customer_name:string,
 *     rider_name:string,
 *     order_total:float,
 *     is_delivered:bool,
 *     chat_grace_open:bool
 * }
 */
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