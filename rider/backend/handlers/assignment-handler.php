<?php
/**
 * FitPal Rider Assignment Handler
 *
 * JSON endpoint driving the rider's Assignment Panel and the rider's
 * accept / decline / pickup / deliver flow.
 *
 * ---------------------------------------------------------------------
 * ACTIONS
 * ---------------------------------------------------------------------
 *   list            → full snapshot for a rider.
 *   poll            → delta fetch.
 *   accept          → accept a rider_pending assignment. Runs
 *                     acceptOrder() which transitions the order
 *                     AND writes the COD collection row, both inside
 *                     one transaction. Then forwards to the shared
 *                     order-transaction handler for the sweeper.
 *   decline         → decline a rider_pending assignment.
 *   mark_picked_up  → picking_up → delivering. Pure status
 *                     transition; no collection write here.
 *   mark_delivered  → close a delivering order as delivered.
 *                     Delegates to the shared handler.
 *   dismiss         → notification-dismissal bookkeeping.
 *
 * ---------------------------------------------------------------------
 * ATOMIC ACCEPT
 * ---------------------------------------------------------------------
 * acceptOrder() in rider-assignment-queries.php now performs TWO
 * writes inside this handler's transaction:
 *
 *   1. orders.order_status: rider_pending → picking_up.
 *   2. rider_collection INSERT for a COD order.
 *
 * Both commit or roll back together. The earlier design wrote the
 * collection at pickup in a separate request and left a window in
 * which an accepted order had no liability record. That window is
 * closed.
 *
 * ---------------------------------------------------------------------
 * WHERE THE MONEY RULES LIVE
 * ---------------------------------------------------------------------
 * Delivery credit pair, collection settle/void, and the failed-
 * delivery sweep all live in:
 *
 *     shared/backend/handlers/order-transaction-handler.php
 *     shared/backend/database/order-transaction-queries.php
 *
 * @package FitPal
 * @version 2.3 — acceptOrder() now writes the collection. Docs
 *                updated. mark_picked_up handler unchanged; the
 *                forward to the shared handler is retained for its
 *                opportunistic sweep.
 *
 *                (2.2: mark_picked_up forwards for the COD
 *                collection. 2.1: accept runs the status transition
 *                before forwarding. 2.0: accept and mark_delivered
 *                delegate to the shared handler. 1.2: committed-
 *                order cap on accept. 1.1: picking_up status.
 *                1.0: initial.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('rider');

ob_start();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['delivery_rider_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$riderId = (int)$_SESSION['delivery_rider_id'];

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/rider-assignment-queries.php';

require_once __DIR__ . '/../../includes/rider-csrf-token.php';

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['rider_csrf_token'] ?? '');

if (
    $sessToken === ''
    || $givenToken === ''
    || !hash_equals($sessToken, $givenToken)
) {
    unset($_SESSION['rider_csrf_token']);

    ob_end_clean();
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

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

        case 'mark_delivered':
            handleMarkDeliveredDelegation();
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
 * The status transition and the COD collection write both happen
 * inside acceptOrder(). This handler wraps them in one transaction.
 * The subsequent forward to the shared handler is for the
 * opportunistic failed-delivery sweep; the accept path there is a
 * pure verification.
 *
 * @param PDO $db
 * @param int $riderId
 * @return array<string, mixed>
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

    $check = $db->prepare(
        "SELECT 1 FROM orders
          WHERE order_id = :order_id
            AND delivery_rider_id = :rider_id
            AND order_status = 'rider_pending'
          LIMIT 1"
    );
    $check->execute([
        ':order_id' => $orderId,
        ':rider_id' => $riderId,
    ]);
    if ($check->fetchColumn() === false) {
        return [
            'status'  => 'error',
            'message' => 'This assignment is no longer available. The kitchen may have reassigned or cancelled it.',
        ];
    }

    $db->beginTransaction();

    try {
        // acceptOrder() does BOTH writes: the status transition and,
        // for a COD order, the rider_collection insert that makes
        // the rider's cash responsibility visible on the dashboard
        // and earnings page.
        $accepted = acceptOrder($db, $riderId, $orderId);

        if (!$accepted) {
            $db->rollBack();
            return [
                'status'  => 'error',
                'message' => 'This assignment is no longer available. The kitchen may have reassigned or cancelled it.',
            ];
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    // Forward to the shared handler for the failed-delivery sweep.
    // The shared handler's accept branch verifies the order and
    // returns; it performs no additional write.
    forwardToSharedHandler('rider_accept_assignment', $orderId);
}

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
 * Pure status transition. The COD collection row was already
 * written by acceptOrder(). The forward to the shared handler is
 * for the opportunistic sweep; the shared handler's pickup branch
 * verifies the order and returns.
 *
 * @param PDO $db
 * @param int $riderId
 * @return array<string, mixed>
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

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    forwardToSharedHandler('rider_mark_picked_up', $orderId);
}

function handleMarkDeliveredDelegation(): never
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order.']);
        exit;
    }

    forwardToSharedHandler('rider_mark_delivered', $orderId);

    exit;
}

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

function forwardToSharedHandler(string $sharedAction, int $orderId): never
{
    $endpoint = __DIR__ . '/../../../shared/backend/handlers/order-transaction-handler.php';

    if (!is_file($endpoint)) {
        error_log('Assignment handler: shared order-transaction handler is missing at ' . $endpoint);
        echo json_encode([
            'status'  => 'error',
            'message' => 'The order service is temporarily unavailable. Please try again.',
        ]);
        exit;
    }

    $_POST['action'] = $sharedAction;

    require $endpoint;

    exit;
}

function shapeAssignmentRow(array $row): array
{
    $status = (string)($row['order_status'] ?? '');

    $messageChannel = panelMessageChannel($status);
    $messageEnabled = false;
    $callNumber     = '';
    $callLabel      = '';

    if ($status === 'rider_pending' || $status === 'picking_up') {
        $messageEnabled = ($messageChannel !== null);
        $kitchenPhone   = (string)($row['kitchen_contact'] ?? '');
        if ($kitchenPhone !== '') {
            $callNumber = $kitchenPhone;
            $callLabel  = 'Call Kitchen';
        }
    } elseif ($status === 'delivering') {
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