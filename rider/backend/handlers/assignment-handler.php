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
 *   list            → full snapshot for a rider, with each order's
 *                     item list and payment-method pill attached.
 *                     Now also returns the rider's `is_available`
 *                     flag so the panel can reflect the availability
 *                     state in real time.
 *   poll            → delta fetch, with each new or updated order's
 *                     item list and payment-method pill attached.
 *                     Now also returns the rider's `is_available`
 *                     flag.
 *   accept          → accept a rider_pending assignment. Runs
 *                     acceptOrder() which transitions the order,
 *                     writes the rider liability, and writes the
 *                     COD collection row, all inside one transaction.
 *                     Then forwards to the shared order-transaction
 *                     handler for the sweeper.
 *   decline         → decline a rider_pending assignment.
 *   mark_picked_up  → picking_up → delivering. Pure status
 *                     transition; the liability and collection rows
 *                     were written at accept.
 *   mark_delivered  → close a delivering order as delivered.
 *                     Delegates to the shared handler.
 *   dismiss         → notification-dismissal bookkeeping.
 *
 * ---------------------------------------------------------------------
 * WHY THE RIDER'S `is_available` FLAG IS RETURNED
 * ---------------------------------------------------------------------
 * The rider's availability is toggled by the assignment panel's own
 * availability modal, which posts to rider-handler.php with
 * action=toggle_availability. The panel's poll (this file's `list`
 * action) then fetches the current state to keep the UI in sync.
 *
 * Before this revision, the `list` action did NOT return
 * `is_available` at all. The panel's JS was already reading
 * `data.online` from the response, and the server was not providing
 * it, so `online` stayed undefined and the pill never updated
 * without a page refresh.
 *
 * The fix is to fetch `is_available` from
 * `delivery_rider_profile` on every `list` and `poll` call and
 * return it as `online` in the response.
 *
 * ---------------------------------------------------------------------
 * RESPONSE SHAPE
 * ---------------------------------------------------------------------
 * JSON. Business-rule refusals return HTTP 200 with
 * {status:'error', message:'...'}; only auth failures return 401
 * and CSRF mismatches return 403.
 *
 * @package FitPal
 * @version 3.2 — `handleList` and `handlePoll` now fetch and return
 *                the rider's `is_available` flag from the database,
 *                so the assignment panel's availability pill updates
 *                in real time without a page refresh.
 *
 *                (3.1: payment pill per row. 3.0: item list per
 *                row. 2.3: acceptOrder writes collection. 2.2:
 *                mark_picked_up forwards. 2.1: accept runs
 *                transition. 2.0: accept + delivered delegate.
 *                1.2: committed-order cap. 1.1: picking_up. 1.0:
 *                initial.)
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
require_once __DIR__ . '/../database/product-queries.php';

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

    $shapedRows = [];
    foreach ($rows as $row) {
        $orderId = (int)($row['order_id'] ?? 0);

        $items = $orderId > 0
            ? getRiderOrderItemsWithDetails($db, $orderId)
            : [];

        $shapedRows[] = shapeAssignmentRow($row, $items);
    }

    return [
        'status'    => 'success',
        'eligible'  => $eligible,
        'online'    => $online,
        'counts'    => $counts,
        'rows'      => $shapedRows,
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

    $shapedRows = [];
    foreach ($rows as $row) {
        $orderId = (int)($row['order_id'] ?? 0);

        $items = $orderId > 0
            ? getRiderOrderItemsWithDetails($db, $orderId)
            : [];

        $shapedRows[] = shapeAssignmentRow($row, $items);
    }

    return [
        'status'   => 'success',
        'eligible' => true,
        'online'   => $online,
        'counts'   => $counts,
        'rows'     => $shapedRows,
        'max_id'   => $maxId,
    ];
}

/**
 * Accept a rider_pending assignment.
 *
 * The status transition, the rider liability write, and the COD
 * collection write all happen inside acceptOrder(). This handler
 * wraps them in one transaction. The subsequent forward to the
 * shared handler is for the opportunistic failed-delivery sweep;
 * the accept path there is a pure verification.
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
 * Pure status transition. The rider liability column and the COD
 * collection row were already written by acceptOrder(). The
 * forward to the shared handler is for the opportunistic sweep;
 * the shared handler's pickup branch verifies the order and
 * returns.
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

/**
 * Build the payment-method pill payload for one order.
 *
 * Mirrors the mapping used by the kitchen board's
 * kitchenPaymentMeta() in restaurant/backend/handlers/
 * kitchen-order-handler.php, so the same method renders with the
 * same icon, label, and colour slug on both surfaces.
 *
 * @param string $paymentMethod
 * @return array{icon: string, label: string, slug: string}
 */
function riderPaymentPill(string $paymentMethod): array
{
    return match ($paymentMethod) {
        'COD' => [
            'icon'  => 'coin-line.svg',
            'label' => 'Cash on Delivery',
            'slug'  => 'cod',
        ],
        'Wallet' => [
            'icon'  => 'wallet-fill.svg',
            'label' => 'Wallet',
            'slug'  => 'wallet',
        ],
        'Online' => [
            'icon'  => 'qr-code-line.svg',
            'label' => 'Online Payment',
            'slug'  => 'online',
        ],
        default => [
            'icon'  => 'coin-line.svg',
            'label' => $paymentMethod !== '' ? $paymentMethod : '—',
            'slug'  => 'other',
        ],
    };
}

/**
 * Shape one panel row for JSON.
 *
 * Attaches the order's item list as `items` on the shaped row and
 * a payment-method pill as `payment_pill`. Each item carries its
 * product name, quantity, browser-loadable image URL, customization
 * rows, and the customer's special instructions.
 *
 * The panel row shaper normalizes the item list so a caller never
 * has to defend against a missing key. An order with no items
 * produces an empty `items` array.
 *
 * @param array<string, mixed> $row
 * @param array<int, array<string, mixed>> $items
 * @return array<string, mixed>
 */
function shapeAssignmentRow(array $row, array $items = []): array
{
    $status        = (string)($row['order_status'] ?? '');
    $paymentMethod = (string)($row['payment_method'] ?? '');

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
        'payment_method'   => $paymentMethod,
        'payment_pill'     => riderPaymentPill($paymentMethod),
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
        'items'            => shapeAssignmentItems($items),
    ];
}

/**
 * Shape one order's item list for the panel.
 *
 * Each item exposes the fields the panel's renderer reads:
 *
 *   product_name          string
 *   quantity              int
 *   image_url             string  browser-loadable, or '' when the
 *                                 image could not be resolved
 *   is_customized         bool
 *   customizations        array   one row per ingredient change
 *   special_instructions  string  the customer's notes, or ''
 *
 * @param array<int, array<string, mixed>> $items
 * @return array<int, array<string, mixed>>
 */
function shapeAssignmentItems(array $items): array
{
    if (empty($items)) {
        return [];
    }

    $shaped = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $customizations = [];

        if (isset($item['customizations']) && is_array($item['customizations'])) {
            foreach ($item['customizations'] as $cust) {
                if (!is_array($cust)) {
                    continue;
                }

                $name = trim((string)($cust['ingredient_name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $customizations[] = [
                    'ingredient_name' => $name,
                    'quantity'        => (int)($cust['quantity'] ?? 1),
                    'is_removed'      => (bool)($cust['is_removed'] ?? false),
                    'price_modifier'  => (float)($cust['price_at_time'] ?? 0),
                ];
            }
        }

        $notes = $item['custom_instructions'] ?? null;

        $shaped[] = [
            'product_name'         => (string)($item['product_name'] ?? 'Item'),
            'quantity'             => (int)($item['quantity'] ?? 0),
            'image_url'            => (string)($item['product_image_url'] ?? ''),
            'is_customized'        => (bool)($item['is_customized'] ?? false),
            'customizations'       => $customizations,
            'special_instructions' => $notes !== null
                ? (string)$notes
                : '',
        ];
    }

    return $shaped;
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