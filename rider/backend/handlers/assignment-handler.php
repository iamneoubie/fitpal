<?php
/**
 * FitPal Rider Assignment Handler
 *
 * JSON endpoint that drives the rider's Assignment Panel. Every
 * request from the panel and from the panel's notification modal
 * lands here.
 *
 * Actions
 * -------
 *   list            → full snapshot for a rider: counts + assignment
 *                     rows + current delta cursor. Sent once when
 *                     the panel is first rendered.
 *   poll            → delta fetch. The client sends since_order_id
 *                     (the highest order_id it already holds) and
 *                     the handler returns only rows above it. An
 *                     idle poll returns an empty rows array after
 *                     one indexed lookup.
 *   accept          → accept a rider_pending assignment. Delegates
 *                     to acceptOrder() in rider-queries.php, which
 *                     moves the order to 'picking_up'.
 *   decline         → decline a rider_pending assignment. Delegates
 *                     to declineOrder().
 *   mark_picked_up  → move a picking_up order to 'delivering'.
 *                     Delegates to markOrderPickedUp() in
 *                     rider-queries.php.
 *   dismiss         → client-side dismissal of the notification
 *                     modal for one order. Recorded in the session
 *                     so the same order does not re-open the modal
 *                     on every subsequent poll for this browser
 *                     session. Does NOT change the assignment.
 *
 * Concurrent-order cap (v1.2)
 * ---------------------------
 * The cap of 3 applies to the orders the rider has ACTUALLY
 * ACCEPTED — the ones in 'picking_up' and 'delivering'. An order in
 * 'rider_pending' is a kitchen offer the rider has not yet decided
 * on; it does not occupy a delivery slot. This is what lets a rider
 * with 3 pending offers accept all 3.
 *
 * handleAccept()'s guard calls riderAtConcurrentCap(), which now
 * counts committed orders only. The same cap number is enforced by
 * the restaurant's assignRiderToOrder() and by the SQL trigger
 * before_order_rider_assign — but only for committed orders, so a
 * rider can be assigned pending offers above the cap without the
 * trigger refusing.
 *
 * Conventions
 * -----------
 *   - CSRF validated against rider_csrf_token (the rider role's own
 *     session key). Never touches the shared csrf_token.
 *   - Every response is JSON with a `status` field. Business-rule
 *     refusals are HTTP 200 with status:'error'; only missing auth
 *     returns 401 and CSRF failure returns 403.
 *   - No SQL lives in this file. All data access goes through
 *     rider/backend/database/assignment-queries.php, which in turn
 *     re-exports the write functions from rider-queries.php.
 *
 * Session contract
 * ----------------
 * This handler writes exactly one session key of its own:
 *   $_SESSION['rider_dismissed_assignment_ids'] — array of order_id
 *   values the rider has dismissed the modal for.
 *
 * It never touches `delivery_rider_id`, `rider_csrf_token`, or any
 * other role's keys.
 *
 * @package FitPal
 * @version 1.2 — The accept guard now uses the committed-order cap,
 *                so a rider can accept all of their pending offers.
 *
 *                - handleAccept() calls riderAtConcurrentCap(),
 *                  which now counts only 'picking_up' + 'delivering'.
 *                  A rider with 3 'rider_pending' offers but no
 *                  accepted orders is no longer refused.
 *                - The refusal message now names the cap as
 *                  "accepted orders" rather than "active orders",
 *                  so the copy matches what the count actually is.
 *                - No change to list, poll, decline, mark_picked_up,
 *                  or dismiss.
 *
 *                (1.1: added the picking_up status. 1.0: initial
 *                assignment handler.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_start();

header('Content-Type: application/json; charset=utf-8');

/* --------------------------------------------------------------
 * AUTH
 * -------------------------------------------------------------- */

if (empty($_SESSION['delivery_rider_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$riderId = (int)$_SESSION['delivery_rider_id'];

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/assignment-queries.php';

// Own the rider role's CSRF bootstrap. Idempotent; stores the token
// under 'rider_csrf_token' — never the shared 'csrf_token' key.
require_once __DIR__ . '/../../includes/rider-csrf-token.php';

/* --------------------------------------------------------------
 * CSRF
 * -------------------------------------------------------------- */

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['rider_csrf_token'] ?? '');

if (
    $sessToken === '' ||
    $givenToken === '' ||
    !hash_equals($sessToken, $givenToken)
) {
    unset($_SESSION['rider_csrf_token']);

    ob_end_clean();
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

/* --------------------------------------------------------------
 * DISPATCH
 * -------------------------------------------------------------- */

$action   = (string)($_POST['action'] ?? '');
$response = ['status' => 'error', 'message' => 'Invalid action'];

try {
    switch ($action) {

        case 'list':
            $response = handleList($database_connection, $riderId);
            break;

        case 'poll':
            $response = handlePoll($database_connection, $riderId);
            break;

        case 'accept':
            $response = handleAccept($database_connection, $riderId);
            break;

        case 'decline':
            $response = handleDecline($database_connection, $riderId);
            break;

        case 'mark_picked_up':
            $response = handleMarkPickedUp($database_connection, $riderId);
            break;

        case 'dismiss':
            $response = handleDismiss($riderId);
            break;
    }
} catch (PDOException $e) {
    error_log('Assignment handler DB error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'A system error occurred. Please try again.'];
} catch (Throwable $e) {
    error_log('Assignment handler error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'A system error occurred. Please try again.'];
}

ob_end_clean();
echo json_encode($response);
exit;

/* =============================================================
 * HANDLERS
 * ============================================================= */

/**
 * Full snapshot for the panel.
 *
 * Returns:
 *   - eligible:   bool — whether the rider can see the panel list
 *                 at all (verified + active account).
 *   - online:     bool — current availability flag.
 *   - counts:     {pending, picking_up, active, total}.
 *   - rows:       every live assignment.
 *   - max_id:     highest order_id in rows — the client's initial
 *                 delta cursor.
 *   - dismissed:  order_ids the rider has dismissed the modal for.
 */
function handleList(PDO $db, int $riderId): array
{
    $eligible = panelRiderIsEligible($db, $riderId);

    $counts = ['pending' => 0, 'picking_up' => 0, 'active' => 0, 'total' => 0];
    $rows   = [];
    $maxId  = 0;
    $online = false;

    if ($eligible) {
        $profile = getRiderProfile($db, $riderId);
        $online  = $profile && (int)($profile['is_available'] ?? 0) === 1;

        $counts = getPanelAssignmentCounts($db, $riderId);
        $rows   = getPanelAssignments($db, $riderId, 20);
        $maxId  = getPanelMaxOrderId($db, $riderId);
    }

    $dismissed = $_SESSION['rider_dismissed_assignment_ids'] ?? [];
    if (!is_array($dismissed)) {
        $dismissed = [];
    }

    // Prune dismissed ids that no longer correspond to a live
    // rider_pending assignment. A dismissed id for an order that
    // has since been accepted, declined, or delivered is stale.
    $livePendingIds = [];
    foreach ($rows as $r) {
        if ((string)$r['order_status'] === 'rider_pending') {
            $livePendingIds[(int)$r['order_id']] = true;
        }
    }
    $dismissed = array_values(array_filter(
        $dismissed,
        static fn($id) => isset($livePendingIds[(int)$id])
    ));
    $_SESSION['rider_dismissed_assignment_ids'] = $dismissed;

    return [
        'status'    => 'success',
        'eligible'  => $eligible,
        'online'    => $online,
        'counts'    => $counts,
        'rows'      => array_map('shapeAssignmentRow', $rows),
        'max_id'    => $maxId,
        'dismissed' => array_values(array_map('intval', $dismissed)),
    ];
}

/**
 * Delta poll.
 *
 * The client sends since_order_id = the highest order_id it already
 * holds. The handler returns only rows above it, plus current
 * counts and the online flag so the panel header stays in sync
 * without needing a separate polling endpoint.
 */
function handlePoll(PDO $db, int $riderId): array
{
    $sinceOrderId = (int)($_POST['since_order_id'] ?? 0);

    if (!panelRiderIsEligible($db, $riderId)) {
        return [
            'status'   => 'success',
            'eligible' => false,
            'online'   => false,
            'counts'   => ['pending' => 0, 'picking_up' => 0, 'active' => 0, 'total' => 0],
            'rows'     => [],
            'max_id'   => $sinceOrderId,
        ];
    }

    $profile = getRiderProfile($db, $riderId);
    $online  = $profile && (int)($profile['is_available'] ?? 0) === 1;

    $counts = getPanelAssignmentCounts($db, $riderId);
    $rows   = getPanelAssignmentsSince($db, $riderId, $sinceOrderId);

    $maxId = $sinceOrderId;
    foreach ($rows as $r) {
        $oid = (int)$r['order_id'];
        if ($oid > $maxId) {
            $maxId = $oid;
        }
    }

    return [
        'status'   => 'success',
        'eligible' => true,
        'online'   => $online,
        'counts'   => $counts,
        'rows'     => array_map('shapeAssignmentRow', $rows),
        'max_id'   => $maxId,
    ];
}

/**
 * Accept a rider_pending assignment.
 *
 * The order moves to 'picking_up' — NOT to 'delivering'. The rider
 * must then call mark_picked_up to advance it.
 *
 * Guards (in order):
 *   - rider must be eligible (verified + active account)
 *   - rider must be online
 *   - rider must not already be at the concurrent-order cap of 3
 *     COMMITTED orders ('picking_up' + 'delivering').
 *
 * A rider with 3 pending offers but no accepted orders is NOT at
 * the cap. The rider can accept all 3. Once all 3 are accepted
 * (and therefore in 'picking_up'), the rider is at the cap and
 * must finish at least one before accepting a 4th.
 */
function handleAccept(PDO $db, int $riderId): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    if (!panelRiderIsEligible($db, $riderId)) {
        return [
            'status'  => 'error',
            'message' => 'Your account must be verified before accepting orders.',
        ];
    }

    $profile = getRiderProfile($db, $riderId);
    if (!$profile || (int)($profile['is_available'] ?? 0) !== 1) {
        return [
            'status'  => 'error',
            'message' => 'Go online first to accept orders.',
        ];
    }

    if (riderAtConcurrentCap($db, $riderId)) {
        return [
            'status'  => 'error',
            'message' => 'You already have '
                       . RIDER_CONCURRENT_CAP
                       . ' accepted orders in progress. '
                       . 'Finish at least one before accepting another.',
        ];
    }

    $db->beginTransaction();

    try {
        $accepted = acceptOrder($db, $riderId, $orderId);

        if (!$accepted) {
            $db->rollBack();
            return [
                'status'  => 'error',
                'message' => 'This assignment is no longer available. The kitchen may have reassigned or cancelled it.',
            ];
        }

        $row = getPanelAssignmentRow($db, $riderId, $orderId);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    clearDismissedId($orderId);

    return [
        'status'  => 'success',
        'message' => 'Assignment accepted. Head to the restaurant to pick up the order.',
        'row'     => $row ? shapeAssignmentRow($row) : null,
    ];
}

/**
 * Decline a rider_pending assignment.
 */
function handleDecline(PDO $db, int $riderId): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    $db->beginTransaction();

    try {
        $declined = declineOrder($db, $riderId, $orderId);

        if (!$declined) {
            $db->rollBack();
            return [
                'status'  => 'error',
                'message' => 'This assignment is no longer available to decline.',
            ];
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    clearDismissedId($orderId);

    return [
        'status'   => 'success',
        'message'  => 'Assignment declined. The kitchen will choose another rider.',
        'order_id' => $orderId,
    ];
}

/**
 * Move a picking_up order to delivering.
 *
 * The rider tapped "Mark Picked Up" on the panel row. Delegates to
 * markOrderPickedUp(), which only fires from 'picking_up'.
 *
 * A rider at the concurrent-order cap is unaffected — an order in
 * 'picking_up' and the same order in 'delivering' count the same
 * way against the cap. Only the bucket label changes.
 */
function handleMarkPickedUp(PDO $db, int $riderId): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    $db->beginTransaction();

    try {
        $picked = markOrderPickedUp($db, $riderId, $orderId);

        if (!$picked) {
            $db->rollBack();
            return [
                'status'  => 'error',
                'message' => 'Could not mark this order as picked up. '
                           . 'It may already be in transit or was reassigned.',
            ];
        }

        $row = getPanelAssignmentRow($db, $riderId, $orderId);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return [
        'status'  => 'success',
        'message' => 'Order picked up. Head to the customer.',
        'row'     => $row ? shapeAssignmentRow($row) : null,
    ];
}

/**
 * Dismiss the notification modal for one assignment.
 *
 * Recorded in the session so the modal does not re-open on every
 * subsequent poll for this browser session. The assignment itself is
 * untouched — the row remains in the panel list until the rider
 * accepts or declines it.
 */
function handleDismiss(int $riderId): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    if (!isset($_SESSION['rider_dismissed_assignment_ids']) ||
        !is_array($_SESSION['rider_dismissed_assignment_ids'])) {
        $_SESSION['rider_dismissed_assignment_ids'] = [];
    }

    if (!in_array($orderId, $_SESSION['rider_dismissed_assignment_ids'], true)) {
        $_SESSION['rider_dismissed_assignment_ids'][] = $orderId;
    }

    if (count($_SESSION['rider_dismissed_assignment_ids']) > 50) {
        $_SESSION['rider_dismissed_assignment_ids'] =
            array_slice($_SESSION['rider_dismissed_assignment_ids'], -50);
    }

    return ['status' => 'success'];
}

/* =============================================================
 * HELPERS
 * ============================================================= */

/**
 * Shape a raw assignment DB row into the JSON payload the panel
 * consumes. Keeps the mapping in one place so list and poll return
 * identical objects.
 *
 * Chat / call affordances per status:
 *
 *   rider_pending  → Message Kitchen  + Call Kitchen
 *   picking_up     → Message Kitchen  + Call Kitchen
 *   delivering     → Message Customer + Call Customer
 *
 * The panel does not currently show both a customer and a kitchen
 * contact on the same row. Once the rider has the food in hand, the
 * customer becomes the useful contact; before that, the kitchen is.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function shapeAssignmentRow(array $row): array
{
    $status = (string)($row['order_status'] ?? '');

    $messageChannel = panelMessageChannel($status);
    $messageEnabled = false;
    $callNumber     = '';
    $callLabel      = '';

    if ($status === 'rider_pending' || $status === 'picking_up') {
        // Before pickup, the useful contact is the kitchen. Chat is
        // available; Call uses the kitchen's number when present.
        $messageEnabled = ($messageChannel !== null);
        $kitchenPhone   = (string)($row['kitchen_contact'] ?? '');
        if ($kitchenPhone !== '') {
            $callNumber = $kitchenPhone;
            $callLabel  = 'Call Kitchen';
        }
    } elseif ($status === 'delivering') {
        // In transit: the customer is the counterparty. Chat and
        // Call both target them.
        $messageEnabled = true;
        $customerPhone  = (string)($row['customer_contact'] ?? '');
        if ($customerPhone !== '') {
            $callNumber = $customerPhone;
            $callLabel  = 'Call Customer';
        }
    }

    return [
        'order_id'         => (int)($row['order_id'] ?? 0),
        'status'           => $status,
        'status_label'     => panelStatusLabel($status),
        'status_badge'     => panelStatusBadge($status),
        'order_date'       => (string)($row['order_date'] ?? ''),
        'customer_name'    => (string)($row['customer_name'] ?? 'Customer'),
        'customer_contact' => (string)($row['customer_contact'] ?? ''),
        'restaurant_name'  => (string)($row['restaurant_name'] ?? ''),
        'branch_name'      => (string)($row['branch_name'] ?? ''),
        'destination'      => (string)($row['destination_address'] ?? ''),
        'item_count'       => (int)($row['item_count'] ?? 0),
        'order_total'      => (float)($row['order_total'] ?? 0),
        'message_channel'  => $messageChannel,
        'message_enabled'  => $messageEnabled,
        'call_number'      => $callNumber,
        'call_label'       => $callLabel,
    ];
}

/**
 * Remove an order_id from the session's dismissed list.
 *
 * Called after accept and after decline, so a stale dismissal does
 * not linger in the session after the rider has actually decided.
 */
function clearDismissedId(int $orderId): void
{
    if (!isset($_SESSION['rider_dismissed_assignment_ids']) ||
        !is_array($_SESSION['rider_dismissed_assignment_ids'])) {
        return;
    }

    $filtered = array_values(array_filter(
        $_SESSION['rider_dismissed_assignment_ids'],
        static fn($id) => (int)$id !== $orderId
    ));

    $_SESSION['rider_dismissed_assignment_ids'] = $filtered;
}