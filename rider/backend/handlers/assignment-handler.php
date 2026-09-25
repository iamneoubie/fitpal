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
 *   list     → full snapshot for a rider: counts + assignment rows
 *              + current delta cursor. Sent once when the panel is
 *              first rendered.
 *   poll     → delta fetch. The client sends since_order_id (the
 *              highest order_id it already holds) and the handler
 *              returns only rows above it. An idle poll returns an
 *              empty rows array after one indexed lookup.
 *   accept   → accept a rider_pending assignment. Delegates to
 *              acceptOrder() in rider-queries.php. No state
 *              transitions live here.
 *   decline  → decline a rider_pending assignment. Delegates to
 *              declineOrder() in rider-queries.php.
 *   dismiss  → client-side dismissal of the notification modal for
 *              one order. Recorded in the session so the same order
 *              does not re-open the modal on every subsequent poll
 *              for this browser session. Does NOT change the
 *              assignment — the row stays in the panel list until
 *              the rider accepts or declines it.
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
 *     re-exports acceptOrder() and declineOrder() from
 *     rider-queries.php so the write path is a single source of
 *     truth.
 *
 * Session contract
 * ----------------
 * This handler writes exactly one session key of its own:
 *   $_SESSION['rider_dismissed_assignment_ids'] — array of order_id
 *   values the rider has dismissed the modal for. Cleared on
 *   sign-out (see rider/backend/handlers/sign-out-handler.php).
 *
 * It never touches `delivery_rider_id`, `rider_csrf_token`, or any
 * other role's keys.
 *
 * @package FitPal
 * @version 1.0
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
    // Rotate the rider's own token so the next render generates a
    // fresh one. Never touch the shared 'csrf_token' key.
    unset($_SESSION['rider_csrf_token']);

    ob_end_clean();
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

/* --------------------------------------------------------------
 * DISPATCH
 * -------------------------------------------------------------- */

$action = (string)($_POST['action'] ?? '');
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
 *   - online:     bool — current availability flag, so the panel
 *                 can render the "you are offline" hint without a
 *                 second request.
 *   - counts:     {pending, active, total} for the header badge.
 *   - rows:       every live assignment.
 *   - max_id:     highest order_id in rows — the client's initial
 *                 delta cursor.
 *
 * One round trip on panel mount. After this the client uses `poll`.
 */
function handleList(PDO $db, int $riderId): array
{
    $eligible = panelRiderIsEligible($db, $riderId);

    $counts = ['pending' => 0, 'active' => 0, 'total' => 0];
    $rows   = [];
    $maxId  = 0;
    $online = false;

    if ($eligible) {
        // Read the rider's availability so the panel can show the
        // "offline" hint without a separate call. This is a READ —
        // the panel never writes is_available.
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
    // rider_pending assignment. The list stays small; a stale id
    // cannot suppress a future modal for an unrelated order because
    // order_ids are unique.
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
 * holds. The handler returns only rows above it. An idle poll
 * returns rows: [] and max_id unchanged — the client draws nothing.
 *
 * Also returns the current counts and online flag so the panel can
 * keep the header badge and the offline hint in sync without
 * needing its own separate polling endpoint.
 */
function handlePoll(PDO $db, int $riderId): array
{
    $sinceOrderId = (int)($_POST['since_order_id'] ?? 0);

    if (!panelRiderIsEligible($db, $riderId)) {
        return [
            'status'   => 'success',
            'eligible' => false,
            'online'   => false,
            'counts'   => ['pending' => 0, 'active' => 0, 'total' => 0],
            'rows'     => [],
            'max_id'   => $sinceOrderId,
        ];
    }

    $profile = getRiderProfile($db, $riderId);
    $online  = $profile && (int)($profile['is_available'] ?? 0) === 1;

    $counts = getPanelAssignmentCounts($db, $riderId);
    $rows   = getPanelAssignmentsSince($db, $riderId, $sinceOrderId);

    // Compute the client's next cursor. Start from its current one
    // and raise it to the highest order_id in this batch. If the
    // batch is empty, the cursor stays where the client had it.
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
 * Delegates the state transition to acceptOrder() from
 * rider-queries.php — the same function the rider's own deliveries
 * page uses. This handler owns no transition SQL.
 *
 * Runs inside a transaction so the read-back after the transition
 * sees a consistent row and the response is shaped from the same
 * snapshot the DB now holds.
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

    // The rider has now decided, so any past dismissal of this
    // order's modal is moot. Clear it.
    clearDismissedId($orderId);

    return [
        'status'  => 'success',
        'message' => 'Assignment accepted. Head to the restaurant for pickup.',
        'row'     => $row ? shapeAssignmentRow($row) : null,
    ];
}

/**
 * Decline a rider_pending assignment.
 *
 * Delegates to declineOrder(). The row disappears from the panel
 * on the next render because the order returns to 'preparing' with
 * no rider attached.
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
        'status'  => 'success',
        'message' => 'Assignment declined. The kitchen will choose another rider.',
        'order_id' => $orderId,
    ];
}

/**
 * Dismiss the notification modal for one assignment.
 *
 * Recorded in the session so the modal does not re-open on every
 * subsequent poll for this browser session. The assignment itself is
 * untouched — the row remains in the panel list until the rider
 * accepts or declines it. If the rider later opens the panel by hand
 * they can still act on it from there.
 *
 * The dismissal is per-order, so two different assignments in the
 * same poll both need their own dismiss call (the modal only ever
 * shows one at a time).
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

    // Keep the array small. Even in a pathological session, we never
    // need more than a handful.
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

    if ($status === 'rider_pending') {
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
 * The order_id being dismissed and then accepted is a normal flow —
 * the rider closes the modal to think, opens the panel, and accepts
 * from there.
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