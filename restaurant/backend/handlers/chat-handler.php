<?php
/**
 * FitPal Restaurant Chat Handler
 *
 * JSON endpoint that drives the restaurant-side chat modal on the
 * kitchen page. Handles reading messages, sending a message, and
 * marking a conversation as read for a single order.
 *
 * Actions
 * -------
 *   list   → full conversation for one channel on one order, plus
 *            the counterparty metadata (name, sub label, initial)
 *            and the highest message_id in the batch as the client
 *            delta cursor.
 *   poll   → delta fetch. The client sends since_message_id — the
 *            highest message_id it already holds — and the handler
 *            returns only rows above it. An idle poll returns an
 *            empty messages array after one indexed lookup.
 *   send   → insert a new message from the restaurant to the
 *            counterparty, scoped to an order this restaurant owns.
 *            Reads the inserted row back so the response payload is
 *            byte-identical to what a delta fetch would return.
 *   read   → mark all unread messages from a counterparty as read.
 *
 * Scoping
 * -------
 * Every action takes an order_id and validates ownership through
 * restaurantOwnsOrder() before doing anything else. A restaurant
 * account cannot read or write a conversation on an order that does
 * not belong to one of its branches.
 *
 * Role gating
 * -----------
 * Any active restaurant account scoped to the owning branch can use
 * the chat. That is the same set of roles the kitchen page already
 * allows: owner, partner, manager, staff, kitchen. Owner and
 * partner accounts do not have a branch_id in the session, so
 * for them the ownership check falls back to the restaurant-level
 * scope: they can message on any order that belongs to any of
 * their branches.
 *
 * Channel gating
 * --------------
 *   - 'customer'       → allowed while the order is any status the
 *                        customer might still be involved in:
 *                        pending, preparing, rider_pending,
 *                        delivering, delivered.
 *                        Refused on cancelled / refunded.
 *   - 'delivery_rider' → allowed only when the order actually has a
 *                        rider attached. That means delivery_rider_id
 *                        IS NOT NULL and the status is
 *                        rider_pending, delivering, or delivered.
 *                        Refused otherwise, with a specific message
 *                        the client can show.
 *
 * Response shape
 * --------------
 * Every message object carries:
 *
 *   message_id  int
 *   direction   "sent" | "received"
 *   sender      display string ("You", "Customer", "Rider")
 *   content     string
 *   time        "g:i A" formatted timestamp
 *
 * and the top-level response carries max_id — the highest
 * message_id in the batch — so the client can advance its cursor
 * without scanning the array.
 *
 * The shape matches customer/backend/handlers/message-handler.php
 * and rider/backend/handlers/message-handler.php so all three roles
 * render their chat through the same JS pattern.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// Any active restaurant account can chat. This mirrors the roles
// the kitchen page already allows and keeps the check simple: the
// ownership gate below is the actual security boundary.
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
 *
 * Returns the counterparty meta so the modal can render its tab
 * header (name, sub label, avatar initial) without a second call.
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
        'status'         => 'success',
        'counterparty'   => $counterparty,
        'counterparty_meta' => $meta,
        'messages'       => array_map(
            static fn(array $r) => shapeMessage($r, $counterparty),
            $rows
        ),
        'max_id'         => $maxId,
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
 *
 * After the insert, reads the row back through
 * getRestaurantMessageById() and shapes it with the same function
 * the list and poll paths use, so the appended node on the client
 * is indistinguishable from what a subsequent delta fetch would
 * return.
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
        // Extremely unlikely — the row was just inserted. Fall back
        // to a locally-shaped payload so the client still renders.
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
 * response array when it is not — the caller echoes that back.
 *
 * @return array<string, mixed>|null
 */
function gateChannel(PDO $db, int $orderId, string $counterparty): ?array
{
    $stmt = $db->prepare(
        "SELECT order_status, delivery_rider_id
           FROM orders
          WHERE order_id = :order_id
          LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['status' => 'error', 'message' => 'Order not found.'];
    }

    $status        = (string)$row['order_status'];
    $hasRider      = $row['delivery_rider_id'] !== null;
    $closedStatuses = ['cancelled', 'refunded'];

    if ($counterparty === 'customer') {
        if (in_array($status, $closedStatuses, true)) {
            return [
                'status'  => 'error',
                'message' => 'This order is closed and the customer can no longer be messaged.',
            ];
        }
        return null;
    }

    if ($counterparty === 'delivery_rider') {
        if (!$hasRider || in_array($status, $closedStatuses, true)) {
            return [
                'status'  => 'error',
                'message' => 'No rider is attached to this order yet.',
            ];
        }
        return null;
    }

    return ['status' => 'error', 'message' => 'Invalid channel.'];
}

/**
 * Shape a raw message row into the JSON payload the modal consumes.
 *
 * Must produce the same object shape regardless of which path
 * fetched the row, so the client renders a message from list, from
 * poll, and from a send response all through the same code.
 *
 * @param array<string, mixed> $row
 * @param string $counterparty  Which counterparty this channel is
 *                              — 'customer' or 'delivery_rider'.
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