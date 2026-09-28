<?php
/**
 * FitPal Rider Assignment Queries
 *
 * Pure data-access layer for the rider's Assignment Panel — the
 * bottom-anchored panel that mirrors the kitchen's rider_pending
 * handoff on the rider side. Every query the panel needs: list
 * currently assigned orders, poll for new assignments (delta by
 * order_id), resolve the counterparty for the message / call
 * buttons, and the small reads that gate the notification modal.
 *
 * Scope rules
 * -----------
 *   - SQL only. No formatting, no HTML, no session writes, no echo.
 *   - No $_POST access. The handler that calls these owns all
 *     request parsing and validation.
 *   - No writes to delivery_rider_profile.is_available. Availability
 *     is the rider's own toggle and lives entirely on the dashboard
 *     endpoint. The panel never touches it.
 *
 * Assignment model (recap)
 * ------------------------
 * The kitchen sets orders.delivery_rider_id and moves the order to
 * 'rider_pending'. The rider accepts — moving the order to
 * 'picking_up' — or declines (order → 'preparing', rider cleared).
 *
 * The panel therefore surfaces three live statuses:
 *
 *   rider_pending  — awaiting the rider's accept / decline decision
 *   picking_up     — accepted; rider is en route to or at the
 *                    restaurant; not yet moving to the customer
 *   delivering     — rider has the food; en route to the customer
 *
 * All three count as the rider's "live assignments" for the
 * concurrent-order cap of 3.
 *
 * Accept/decline write path
 * -------------------------
 * acceptOrder(), declineOrder(), and markOrderPickedUp() in
 * rider-queries.php are the write path. This file only reads. The
 * panel handler in assignment-handler.php invokes the write
 * functions after validating that the rider is eligible and
 * online.
 *
 * Delta polling
 * -------------
 * getPanelAssignments() and getPanelAssignmentsSince() share a SELECT
 * shape. The first returns every live assignment for the rider; the
 * second returns only rows with order_id > :since_order_id. The
 * client sends since_order_id = the highest order_id it already
 * holds, so an idle rider's poll touches one indexed lookup on
 * orders.delivery_rider_id and transfers an empty array.
 *
 * @package FitPal
 * @version 1.1 — Adds 'picking_up' across every live-status list in
 *                this file:
 *                  - getPanelAssignments() and
 *                    getPanelAssignmentsSince() include it.
 *                  - getPanelAssignmentCounts() reports a separate
 *                    'picking_up' bucket so the panel header badge
 *                    can break the total down if it wants to.
 *                  - panelStatusLabel() and panelStatusBadge() gain
 *                    a 'picking_up' case.
 *                  - panelMessageChannel() maps 'picking_up' to
 *                    'restaurant_account' so the rider stays in
 *                    contact with the kitchen during pickup, not
 *                    the customer.
 *                  - panelShouldNotify() is unchanged — the
 *                    notification modal is still only for a NEW
 *                    rider_pending offer, not for a status the
 *                    rider already moved the order into
 *                    themselves.
 *
 *                (1.0: initial assignment queries.)
 */

declare(strict_types=1);

require_once __DIR__ . '/rider-queries.php';

/* =============================================================
 * ASSIGNMENT LIST — FULL LOAD
 * ============================================================= */

/**
 * Every live assignment for a rider.
 *
 * "Live" means the order is in a state the rider is responsible for
 * right now:
 *   - 'rider_pending' — the kitchen has asked; the rider has not
 *     yet accepted or declined.
 *   - 'picking_up'    — the rider accepted; en route to or at the
 *     restaurant; food not yet in hand.
 *   - 'delivering'    — the rider has the food and is en route to
 *     the customer.
 *
 * Delivered / cancelled / refunded orders are not returned. Those
 * belong to the deliveries page's history section, not the panel.
 *
 * The return rows carry everything the panel needs to render a row
 * without a second round trip: order meta, customer name and
 * contact, kitchen name and contact, the per-order item count and
 * order total, and the restaurant_branch_id so the message routing
 * can resolve the kitchen account server-side.
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $limit
 * @return array<int, array<string, mixed>>
 */
function getPanelAssignments(PDO $db, int $riderId, int $limit = 20): array
{
    if ($riderId <= 0) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.order_status,
            o.order_date,
            o.destination_address,
            o.payment_method,
            c.customer_id,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
            c.contact_number AS customer_contact,
            rb.restaurant_branch_id,
            rb.branch_name,
            r.restaurant_id,
            r.business_name AS restaurant_name,
            (
                SELECT ra.contact_number
                  FROM restaurant_account ra
                 WHERE ra.restaurant_id = r.restaurant_id
                   AND ra.is_active = 1
                 ORDER BY FIELD(ra.role, 'owner', 'manager', 'staff') ASC,
                          ra.restaurant_account_id ASC
                 LIMIT 1
            ) AS kitchen_contact,
            (
                SELECT COUNT(*)
                  FROM queue_item qi
                 WHERE qi.order_id = o.order_id
            ) AS item_count,
            (
                SELECT COALESCE(SUM(qi.queue_quantity
                                    * COALESCE(qi.final_price, qi.unit_price)), 0)
                  FROM queue_item qi
                 WHERE qi.order_id = o.order_id
            ) AS order_total
         FROM orders o
         JOIN customer c ON o.customer_id = c.customer_id
         JOIN queue_item qi0 ON qi0.order_id = o.order_id
         JOIN restaurant_branch rb ON qi0.branch_id = rb.restaurant_branch_id
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         WHERE o.delivery_rider_id = :rider_id
           AND o.order_status IN ('rider_pending', 'picking_up', 'delivering')
         GROUP BY o.order_id
         ORDER BY
            FIELD(o.order_status, 'rider_pending', 'picking_up', 'delivering') ASC,
            o.order_date ASC
         LIMIT :limit"
    );
    $stmt->bindValue(':rider_id', $riderId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =============================================================
 * ASSIGNMENT LIST — DELTA POLL
 * ============================================================= */

/**
 * Only assignments whose order_id is strictly greater than
 * $sinceOrderId.
 *
 * This is the polling path. The client sends the highest order_id
 * it already holds; the server returns rows that came after it. An
 * idle rider's poll therefore hits one indexed lookup on
 * (delivery_rider_id, order_id) and returns an empty array.
 *
 * The SELECT shape is deliberately identical to
 * getPanelAssignments() so a row fetched via the delta path renders
 * with the exact same JSON as one fetched via the full path.
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $sinceOrderId
 * @return array<int, array<string, mixed>>
 */
function getPanelAssignmentsSince(PDO $db, int $riderId, int $sinceOrderId): array
{
    if ($riderId <= 0) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.order_status,
            o.order_date,
            o.destination_address,
            o.payment_method,
            c.customer_id,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
            c.contact_number AS customer_contact,
            rb.restaurant_branch_id,
            rb.branch_name,
            r.restaurant_id,
            r.business_name AS restaurant_name,
            (
                SELECT ra.contact_number
                  FROM restaurant_account ra
                 WHERE ra.restaurant_id = r.restaurant_id
                   AND ra.is_active = 1
                 ORDER BY FIELD(ra.role, 'owner', 'manager', 'staff') ASC,
                          ra.restaurant_account_id ASC
                 LIMIT 1
            ) AS kitchen_contact,
            (
                SELECT COUNT(*)
                  FROM queue_item qi
                 WHERE qi.order_id = o.order_id
            ) AS item_count,
            (
                SELECT COALESCE(SUM(qi.queue_quantity
                                    * COALESCE(qi.final_price, qi.unit_price)), 0)
                  FROM queue_item qi
                 WHERE qi.order_id = o.order_id
            ) AS order_total
         FROM orders o
         JOIN customer c ON o.customer_id = c.customer_id
         JOIN queue_item qi0 ON qi0.order_id = o.order_id
         JOIN restaurant_branch rb ON qi0.branch_id = rb.restaurant_branch_id
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         WHERE o.delivery_rider_id = :rider_id
           AND o.order_id > :since_order_id
           AND o.order_status IN ('rider_pending', 'picking_up', 'delivering')
         GROUP BY o.order_id
         ORDER BY o.order_id ASC"
    );
    $stmt->execute([
        ':rider_id'       => $riderId,
        ':since_order_id' => $sinceOrderId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =============================================================
 * SINGLE-ROW READS
 * ============================================================= */

/**
 * Fetch one assignment row, scoped to the rider.
 *
 * Returns false if the order is not currently assigned to this
 * rider, or if it is not in a live state. The panel uses this after
 * an accept, a decline, or a pickup to build the response payload,
 * so the row it renders is always the row the server actually has.
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $orderId
 * @return array<string, mixed>|false
 */
function getPanelAssignmentRow(PDO $db, int $riderId, int $orderId): array|false
{
    if ($riderId <= 0 || $orderId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.order_status,
            o.order_date,
            o.destination_address,
            o.payment_method,
            c.customer_id,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
            c.contact_number AS customer_contact,
            rb.restaurant_branch_id,
            rb.branch_name,
            r.restaurant_id,
            r.business_name AS restaurant_name,
            (
                SELECT ra.contact_number
                  FROM restaurant_account ra
                 WHERE ra.restaurant_id = r.restaurant_id
                   AND ra.is_active = 1
                 ORDER BY FIELD(ra.role, 'owner', 'manager', 'staff') ASC,
                          ra.restaurant_account_id ASC
                 LIMIT 1
            ) AS kitchen_contact,
            (
                SELECT COUNT(*)
                  FROM queue_item qi
                 WHERE qi.order_id = o.order_id
            ) AS item_count,
            (
                SELECT COALESCE(SUM(qi.queue_quantity
                                    * COALESCE(qi.final_price, qi.unit_price)), 0)
                  FROM queue_item qi
                 WHERE qi.order_id = o.order_id
            ) AS order_total
         FROM orders o
         JOIN customer c ON o.customer_id = c.customer_id
         JOIN queue_item qi0 ON qi0.order_id = o.order_id
         JOIN restaurant_branch rb ON qi0.branch_id = rb.restaurant_branch_id
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         WHERE o.order_id = :order_id
           AND o.delivery_rider_id = :rider_id
           AND o.order_status IN ('rider_pending', 'picking_up', 'delivering')
         GROUP BY o.order_id
         LIMIT 1"
    );
    $stmt->execute([
        ':order_id' => $orderId,
        ':rider_id' => $riderId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: false;
}

/**
 * The highest order_id currently assigned to the rider in a live
 * state. 0 if the rider has no live assignments.
 *
 * Used by the panel to seed its delta cursor on first load without
 * an extra round trip. Also used after accept / decline / pickup to
 * advance the cursor locally.
 *
 * @param PDO $db
 * @param int $riderId
 * @return int
 */
function getPanelMaxOrderId(PDO $db, int $riderId): int
{
    if ($riderId <= 0) {
        return 0;
    }

    $stmt = $db->prepare(
        "SELECT COALESCE(MAX(order_id), 0)
           FROM orders
          WHERE delivery_rider_id = :rider_id
            AND order_status IN ('rider_pending', 'picking_up', 'delivering')"
    );
    $stmt->execute([':rider_id' => $riderId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Total count of live assignments for the badge on the panel header.
 *
 * The three buckets are broken out separately so the panel can
 * render a per-bucket subtitle if it ever wants to (e.g.
 * "1 pending • 2 in progress"). The 'total' bucket is the sum and
 * is what the badge shows today.
 *
 * @param PDO $db
 * @param int $riderId
 * @return array{pending:int, picking_up:int, active:int, total:int}
 */
function getPanelAssignmentCounts(PDO $db, int $riderId): array
{
    if ($riderId <= 0) {
        return ['pending' => 0, 'picking_up' => 0, 'active' => 0, 'total' => 0];
    }

    $stmt = $db->prepare(
        "SELECT
            SUM(CASE WHEN order_status = 'rider_pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN order_status = 'picking_up'    THEN 1 ELSE 0 END) AS picking_up,
            SUM(CASE WHEN order_status = 'delivering'    THEN 1 ELSE 0 END) AS active,
            COUNT(*) AS total
         FROM orders
         WHERE delivery_rider_id = :rider_id
           AND order_status IN ('rider_pending', 'picking_up', 'delivering')"
    );
    $stmt->execute([':rider_id' => $riderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'pending'    => (int)($row['pending']    ?? 0),
        'picking_up' => (int)($row['picking_up'] ?? 0),
        'active'     => (int)($row['active']     ?? 0),
        'total'      => (int)($row['total']      ?? 0),
    ];
}

/* =============================================================
 * CHAT / CALL COUNTERPARTY RESOLUTION
 * ============================================================= */

/**
 * Resolve the counterparty id for the message button on a panel row.
 *
 * Two channels, matching the existing rider message-handler.php
 * contract:
 *
 *   - 'restaurant_account' → the first active restaurant account
 *     tied to the order's branch. Used when the rider needs to
 *     reach the kitchen about pickup — during 'rider_pending',
 *     'picking_up', and 'delivering' the kitchen is a valid
 *     recipient.
 *
 *   - 'customer' → the customer on the order. Used once the rider
 *     is on the road and needs to coordinate drop-off
 *     ('delivering').
 *
 * Returns 0 when the counterparty cannot be resolved. The panel
 * hides the Message button in that case, so the rider never sends
 * into the void.
 *
 * @param PDO $db
 * @param int $orderId
 * @param string $channel
 * @return int
 */
function resolvePanelMessageRecipient(PDO $db, int $orderId, string $channel): int
{
    if ($orderId <= 0) {
        return 0;
    }

    if ($channel === 'customer') {
        $stmt = $db->prepare(
            "SELECT customer_id
               FROM orders
              WHERE order_id = :order_id
              LIMIT 1"
        );
        $stmt->execute([':order_id' => $orderId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    if ($channel === 'restaurant_account') {
        $stmt = $db->prepare(
            "SELECT ra.restaurant_account_id
               FROM queue_item qi
               JOIN restaurant_branch rb ON rb.restaurant_branch_id = qi.branch_id
               JOIN restaurant_account ra ON ra.restaurant_id = rb.restaurant_id
              WHERE qi.order_id = :order_id
                AND ra.is_active = 1
              ORDER BY FIELD(ra.role, 'owner', 'manager', 'staff', 'cashier', 'kitchen') ASC,
                       ra.restaurant_account_id ASC
              LIMIT 1"
        );
        $stmt->execute([':order_id' => $orderId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    return 0;
}

/* =============================================================
 * NOTIFICATION MODAL GATING
 * ============================================================= */

/**
 * True when the rider should see the assignment notification modal
 * for a given order.
 *
 * The modal only fires when ALL of the following hold:
 *   - the order is in 'rider_pending' (kitchen has asked; the rider
 *     has not yet accepted or declined), and
 *   - the rider is verified, and
 *   - the rider is online (is_available = 1).
 *
 * Rationale: an offline rider should never be interrupted. The
 * kitchen's own availability filter already prevents assigning to
 * an offline rider, but a race can still let one through; this
 * check closes that window.
 *
 * Note that 'picking_up' and 'delivering' are NOT notification
 * triggers. Those statuses are ones the rider moved the order INTO
 * themselves — no modal is appropriate. The notification is only
 * for a fresh offer the kitchen just sent.
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $orderId
 * @return bool
 */
function panelShouldNotify(PDO $db, int $riderId, int $orderId): bool
{
    if ($riderId <= 0 || $orderId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT
            o.order_status,
            drp.verification_status,
            drp.is_available
         FROM orders o
         JOIN delivery_rider_profile drp
              ON drp.delivery_rider_id = o.delivery_rider_id
         WHERE o.order_id = :order_id
           AND o.delivery_rider_id = :rider_id
         LIMIT 1"
    );
    $stmt->execute([
        ':order_id' => $orderId,
        ':rider_id' => $riderId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false;
    }

    if ((string)$row['order_status'] !== 'rider_pending') {
        return false;
    }
    if ((string)$row['verification_status'] !== 'verified') {
        return false;
    }
    if ((int)$row['is_available'] !== 1) {
        return false;
    }

    return true;
}

/**
 * True when a rider is eligible to use the panel at all.
 *
 * Only verified, active riders see the assignment list. Pending,
 * denied, and suspended riders see a one-line status strip instead
 * of a list, and receive no notification modal.
 *
 * @param PDO $db
 * @param int $riderId
 * @return bool
 */
function panelRiderIsEligible(PDO $db, int $riderId): bool
{
    if ($riderId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT 1
           FROM delivery_rider dr
           JOIN delivery_rider_profile drp
                ON dr.delivery_rider_id = drp.delivery_rider_id
          WHERE dr.delivery_rider_id = :rider_id
            AND dr.is_active = 1
            AND drp.verification_status = 'verified'
          LIMIT 1"
    );
    $stmt->execute([':rider_id' => $riderId]);
    return $stmt->fetchColumn() !== false;
}

/* =============================================================
 * PRESENTATION HELPERS (pure — no DB access)
 * ============================================================= */

/**
 * Human-readable label for an order status as shown in the panel.
 *
 * @param string $status
 * @return string
 */
function panelStatusLabel(string $status): string
{
    return match ($status) {
        'rider_pending' => 'Awaiting Your Decision',
        'picking_up'    => 'Head to Pickup',
        'delivering'    => 'In Transit',
        default         => ucfirst($status),
    };
}

/**
 * Badge class for an order status as shown in the panel.
 *
 * rider_pending and picking_up share badge-warning (they both need
 * the rider's eye), delivering uses badge-primary (rider is
 * executing).
 *
 * @param string $status
 * @return string
 */
function panelStatusBadge(string $status): string
{
    return match ($status) {
        'rider_pending' => 'badge-warning',
        'picking_up'    => 'badge-warning',
        'delivering'    => 'badge-primary',
        default         => 'badge-secondary',
    };
}

/**
 * Which message channel the panel should offer for a given order
 * status, or null if messaging is not appropriate.
 *
 *   rider_pending → talk to the kitchen (accept/decline context)
 *   picking_up    → talk to the kitchen (pickup coordination)
 *   delivering    → talk to the customer (drop-off coordination)
 *
 * The panel currently shows only one channel per row. Once the
 * rider has the food in hand, the customer becomes the useful
 * contact; before that, the kitchen is.
 *
 * @param string $status
 * @return string|null
 */
function panelMessageChannel(string $status): ?string
{
    return match ($status) {
        'rider_pending' => 'restaurant_account',
        'picking_up'    => 'restaurant_account',
        'delivering'    => 'customer',
        default         => null,
    };
}