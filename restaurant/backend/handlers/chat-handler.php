<?php
/**
 * FitPal Restaurant Chat Handler
 *
 * JSON endpoint that drives the restaurant-side chat modal on the
 * kitchen page. Handles reading messages, sending a message, and
 * marking a conversation as read for a single order.
 *
 * ---------------------------------------------------------------------
 * ACTIONS
 * ---------------------------------------------------------------------
 *   list   → full conversation for one channel on one order, plus
 *            the counterparty metadata and the highest message_id
 *            in the batch as the client delta cursor.
 *   poll   → delta fetch. The client sends since_message_id and
 *            the handler returns only rows above it.
 *   send   → insert a new message from the restaurant to the
 *            counterparty, scoped to an order this restaurant owns.
 *            Reads the inserted row back so the response payload is
 *            byte-identical to what a delta fetch would return.
 *   read   → mark all unread messages from a counterparty as read.
 *
 * ---------------------------------------------------------------------
 * SCOPING
 * ---------------------------------------------------------------------
 * Every action takes an order_id and validates ownership through
 * restaurantOwnsOrder() before doing anything else.
 *
 * ---------------------------------------------------------------------
 * ROLE GATING
 * ---------------------------------------------------------------------
 * Any active restaurant account scoped to the owning branch can use
 * the chat. That is the same set of roles the kitchen page already
 * allows: owner, partner, manager, staff, kitchen.
 *
 * ---------------------------------------------------------------------
 * CHANNEL GATING
 * ---------------------------------------------------------------------
 * Which channels are reachable for a given order is decided by
 * restaurantChatChannelStatus() in
 * restaurant/backend/database/chat-queries.php. That function is the
 * single source of truth for the status-to-channels mapping. This
 * handler calls it and refuses any channel the mapping reports as
 * closed.
 *
 * The mapping as of v1.3:
 *
 *   pending         → customer open,   rider closed
 *   preparing       → customer open,   rider closed
 *   rider_pending   → customer + rider
 *   picking_up      → customer + rider
 *   delivering      → customer + rider
 *   delivered ≤ 1h  → customer + rider
 *   delivered > 1h  → both closed
 *   cancelled       → both closed
 *   refunded        → both closed
 *   failed          → both closed
 *
 * The 'failed' status is produced by the shared order-transaction
 * layer's sweepFailedDeliveries() when a rider does not complete a
 * delivery in time. It is a closed order: the kitchen has nothing
 * left to discuss with the rider, and the customer's own tracking
 * page has already rendered the terminal state. gateChannel()
 * therefore refuses every channel for a failed order with the same
 * "this order is closed" copy the cancelled and refunded cases use.
 *
 * ---------------------------------------------------------------------
 * RESPONSE SHAPE
 * ---------------------------------------------------------------------
 * Every message object carries:
 *
 *   message_id  int
 *   direction   "sent" | "received"
 *   sender      display string ("You", "Customer", "Rider")
 *   content     string
 *   time        "g:i A" formatted timestamp
 *
 * and the top-level response carries max_id — the highest
 * message_id in the batch.
 *
 * @package FitPal
 * @version 1.3 — gateChannel()'s $isClosed predicate now names
 *                'failed' alongside 'cancelled' and 'refunded', so a
 *                failed order produces the closed-order copy rather
 *                than the generic "not reachable" fallback. No
 *                other behavioural change.
 *
 *                (1.2: delivered grace window and open-early
 *                customer channel. 1.1: delegated channel gating to
 *                restaurantChatChannelStatus(). 1.0: initial
 *                restaurant chat handler.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('restaurant');

header('Content-Type: application/json; charset=utf-8');

/* --------------------------------------------------------------
 * AUTH
 * -------------------------------------------------------------- */

if (empty($_SESSION['restaurant_account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$accountId    = (int)$_SESSION['restaurant_account_id'];
$restaurantId = (int)($_SESSION['restaurant_id'] ?? 0);
$branchId     = !empty($_SESSION['restaurant_branch_id'])
    ? (int)$_SESSION['restaurant_branch_id']
    : 0;
$accountRole  = (string)($_SESSION['restaurant_role'] ?? '');
$scope        = (string)($_SESSION['restaurant_scope'] ?? 'owner');

if ($restaurantId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'No restaurant is associated with this account.']);
    exit;
}

$allowedRoles = ['owner', 'partner', 'manager', 'staff', 'kitchen'];
if (!in_array($accountRole, $allowedRoles, true)) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Your role cannot use the kitchen chat.',
    ]);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/chat-queries.php';

// Own the restaurant role's CSRF bootstrap. Idempotent; stores the
// token under 'restaurant_csrf_token' — never the shared
// 'csrf_token' key.
require_once __DIR__ . '/../../includes/restaurant-csrf-token.php';

/* --------------------------------------------------------------
 * CSRF
 * -------------------------------------------------------------- */

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['restaurant_csrf_token'] ?? '');

if (
    $sessToken === '' ||
    $givenToken === '' ||
    !hash_equals($sessToken, $givenToken)
) {
    unset($_SESSION['restaurant_csrf_token']);

    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

/* --------------------------------------------------------------
 * DISPATCH
 * -------------------------------------------------------------- */

$action       = (string)($_POST['action'] ?? '');
$counterparty = (string)($_POST['counterparty'] ?? '');

$response = ['status' => 'error', 'message' => 'Invalid action'];

try {
    switch ($action) {

        case 'list':
            $response = handleList($database_connection, $restaurantId, $counterparty);
            break;

        case 'poll':
            $response = handlePoll($database_connection, $restaurantId, $counterparty);
            break;

        case 'send':
            $response = handleSend($database_connection, $restaurantId, $accountId, $counterparty);
            break;

        case 'read':
            $response = handleMarkRead($database_connection, $restaurantId, $counterparty);
            break;
    }
} catch (PDOException $e) {
    error_log('Restaurant chat handler DB error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'A system error occurred. Please try again.'];
} catch (Throwable $e) {
    error_log('Restaurant chat handler error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'A system error occurred. Please try again.'];
}

echo json_encode($response);
exit;

/* =============================================================
 * HANDLERS
 * ============================================================= */

/**
 * Full conversation for one channel on one order.
 */
function handleList(PDO $db, int $restaurantId, string $counterparty): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0 || !isRestaurantChatChannel($counterparty)) {
        return ['status' => 'error', 'message' => 'Invalid request.'];
    }

    if (!restaurantOwnsOrder($db, $orderId, $restaurantId)) {
        return ['status' => 'error', 'message' => 'Order not found.'];
    }

    $gate = gateChannel($db, $orderId, $counterparty);
    if ($gate !== null) {
        return $gate;
    }

    $rows  = getRestaurantOrderMessages($db, $orderId, $counterparty);
    $maxId = getRestaurantMaxMessageId($db, $orderId, $counterparty);
    $meta  = getRestaurantChatPartyMeta($db, $orderId, $counterparty);

    return [
        'status'            => 'success',
        'counterparty'      => $counterparty,
        'counterparty_meta' => $meta,
        'messages'          => array_map(
            static fn(array $r) => shapeMessage($r, $counterparty),
            $rows
        ),
        'max_id'            => $maxId,
    ];
}

/**
 * Delta fetch. Only rows above the client's cursor.
 */
function handlePoll(PDO $db, int $restaurantId, string $counterparty): array
{
    $orderId        = (int)($_POST['order_id'] ?? 0);
    $sinceMessageId = (int)($_POST['since_message_id'] ?? 0);

    if ($orderId <= 0 || !isRestaurantChatChannel($counterparty)) {
        return ['status' => 'error', 'message' => 'Invalid request.'];
    }

    if (!restaurantOwnsOrder($db, $orderId, $restaurantId)) {
        return ['status' => 'error', 'message' => 'Order not found.'];
    }

    $gate = gateChannel($db, $orderId, $counterparty);
    if ($gate !== null) {
        return $gate;
    }

    if ($sinceMessageId <= 0) {
        return [
            'status'       => 'success',
            'counterparty' => $counterparty,
            'messages'     => [],
            'max_id'       => 0,
        ];
    }

    $rows = getRestaurantOrderMessagesSince($db, $orderId, $counterparty, $sinceMessageId);

    $maxId = $sinceMessageId;
    foreach ($rows as $r) {
        $mid = (int)$r['message_id'];
        if ($mid > $maxId) {
            $maxId = $mid;
        }
    }

    return [
        'status'       => 'success',
        'counterparty' => $counterparty,
        'messages'     => array_map(
            static fn(array $r) => shapeMessage($r, $counterparty),
            $rows
        ),
        'max_id'       => $maxId,
    ];
}

/**
 * Insert a new message from the restaurant to the counterparty.
 */
function handleSend(PDO $db, int $restaurantId, int $accountId, string $counterparty): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    $content = trim((string)($_POST['content'] ?? ''));

    if ($orderId <= 0 || !isRestaurantChatChannel($counterparty)) {
        return ['status' => 'error', 'message' => 'Invalid request.'];
    }

    if ($content === '') {
        return ['status' => 'error', 'message' => 'Message cannot be empty.'];
    }

    if (strlen($content) > 500) {
        return ['status' => 'error', 'message' => 'Message is too long (max 500 characters).'];
    }

    if (!restaurantOwnsOrder($db, $orderId, $restaurantId)) {
        return ['status' => 'error', 'message' => 'Order not found.'];
    }

    $gate = gateChannel($db, $orderId, $counterparty);
    if ($gate !== null) {
        return $gate;
    }

    $recipientId = resolveRestaurantChatRecipient($db, $orderId, $counterparty);
    if ($recipientId <= 0) {
        return ['status' => 'error', 'message' => 'Recipient not available.'];
    }

    $messageId = createRestaurantMessage(
        $db,
        $orderId,
        $accountId,
        $counterparty,
        $recipientId,
        $content
    );

    $row = getRestaurantMessageById($db, $messageId, $orderId);

    if (!$row) {
        return [
            'status'       => 'success',
            'counterparty' => $counterparty,
            'message'      => 'Message sent.',
            'message_data' => [
                'message_id' => $messageId,
                'direction'  => 'sent',
                'sender'     => 'You',
                'content'    => $content,
                'time'       => date('g:i A'),
            ],
            'max_id'       => $messageId,
        ];
    }

    return [
        'status'       => 'success',
        'counterparty' => $counterparty,
        'message'      => 'Message sent.',
        'message_data' => shapeMessage($row, $counterparty),
        'max_id'       => $messageId,
    ];
}

/**
 * Mark all unread messages from the counterparty as read.
 */
function handleMarkRead(PDO $db, int $restaurantId, string $counterparty): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0 || !isRestaurantChatChannel($counterparty)) {
        return ['status' => 'error', 'message' => 'Invalid request.'];
    }

    if (!restaurantOwnsOrder($db, $orderId, $restaurantId)) {
        return ['status' => 'error', 'message' => 'Order not found.'];
    }

    markRestaurantMessagesRead($db, $orderId, $counterparty);

    return ['status' => 'success'];
}

/* =============================================================
 * HELPERS
 * ============================================================= */

/**
 * Enforce the channel-availability rules for one order.
 *
 * Returns null when the channel is allowed. Returns an error
 * response array when it is not.
 *
 * The status-to-channels mapping is owned by
 * restaurantChatChannelStatus() in the query layer. This function
 * fetches the order's current status AND its delivered_at
 * timestamp, then consults the map.
 *
 * The error copy is selected from the actual reason:
 *
 *   - cancelled / refunded / failed   → "order is closed"
 *   - delivered past the grace window → "the message window ended"
 *   - no rider attached               → "no rider yet"
 *   - anything else (defensive)       → "not reachable"
 *
 * The 'failed' case is grouped with 'cancelled' and 'refunded' so
 * the kitchen is told the same thing in all three terminal states:
 * the order is closed and no one can be reached.
 *
 * @return array<string, mixed>|null
 */
function gateChannel(PDO $db, int $orderId, string $counterparty): ?array
{
    $stmt = $db->prepare(
        "SELECT order_status, delivery_rider_id, delivered_at
           FROM orders
          WHERE order_id = :order_id
          LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['status' => 'error', 'message' => 'Order not found.'];
    }

    $status      = (string)$row['order_status'];
    $hasRider    = $row['delivery_rider_id'] !== null;
    $deliveredAt = $row['delivered_at'] !== null
        ? (string)$row['delivered_at']
        : null;

    // Single source of truth for the mapping.
    $channels = restaurantChatChannelStatus($status, $deliveredAt);

    // The three terminal statuses are treated as one case for
    // copy purposes. A delivered order past its grace window is
    // also closed; it is caught below.
    $isTerminal = in_array($status, ['cancelled', 'refunded', 'failed'], true);

    $isDeliveredPastGrace =
        ($status === 'delivered')
        && !$channels['customer']
        && !$channels['delivery_rider'];

    $isClosed = $isTerminal || $isDeliveredPastGrace;

    if ($counterparty === 'delivery_rider') {
        // No rider attached yet is a distinct, useful message.
        // Check it before the "closed" branch so a pending order
        // with no rider says "no rider yet" rather than "closed".
        if (!$hasRider) {
            return [
                'status'  => 'error',
                'message' => 'No rider is attached to this order yet.',
            ];
        }

        if ($isClosed) {
            return [
                'status'  => 'error',
                'message' => $status === 'delivered'
                    ? 'The one-hour message window for this delivered order has ended.'
                    : 'This order is closed and the rider is no longer reachable.',
            ];
        }

        if (!$channels['delivery_rider']) {
            return [
                'status'  => 'error',
                'message' => 'The rider is not reachable for this order.',
            ];
        }

        return null;
    }

    if ($counterparty === 'customer') {
        if ($isClosed) {
            return [
                'status'  => 'error',
                'message' => $status === 'delivered'
                    ? 'The one-hour message window for this delivered order has ended.'
                    : 'This order is closed and the customer can no longer be messaged.',
            ];
        }

        if (!$channels['customer']) {
            return [
                'status'  => 'error',
                'message' => 'The customer is not reachable on this order right now.',
            ];
        }

        return null;
    }

    return ['status' => 'error', 'message' => 'Invalid channel.'];
}

/**
 * Shape a raw message row into the JSON payload the modal consumes.
 *
 * @param array<string, mixed> $row
 * @param string $counterparty
 * @return array<string, mixed>
 */
function shapeMessage(array $row, string $counterparty): array
{
    $senderType = (string)($row['sender_type'] ?? '');
    $isSent     = ($senderType === 'restaurant_account');

    return [
        'message_id' => (int)($row['message_id'] ?? 0),
        'direction'  => $isSent ? 'sent' : 'received',
        'sender'     => $isSent
            ? 'You'
            : restaurantChatSenderLabel($senderType),
        'content'    => (string)($row['content'] ?? ''),
        'time'       => restaurantChatFormatTime((string)($row['created_at'] ?? '')),

        // Extra fields the modal may read; harmless if unused.
        'sender_type' => $senderType,
        'created_at'  => (string)($row['created_at'] ?? ''),
        'is_read'     => (int)($row['is_read'] ?? 0) === 1,
    ];
}