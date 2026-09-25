<?php
/**
 * FitPal Customer Order Tracking Message Handler
 *
 * AJAX endpoint for the customer chat modal on the order tracking
 * page. Handles fetching messages, sending a new message, and
 * marking a conversation as read.
 *
 * Actions:
 *   get    → return messages for a channel (full load or delta)
 *   send   → insert a new customer message
 *   read   → mark all messages from a counterparty as read
 *
 * Chat gating
 * -----------
 * Two business rules are enforced here, both backed by helpers in
 * tracking-queries.php:
 *
 *   - Kitchen messaging is allowed for every order status except
 *     'cancelled' and 'refunded'. Once an order is closed, the
 *     customer and the kitchen have nothing left to say.
 *
 *   - Rider messaging is allowed only after the assigned rider has
 *     actually accepted the order (order_status = 'delivering' or
 *     'delivered' with delivery_rider_id set). During 'rider_pending'
 *     the kitchen has proposed a rider but the rider may still
 *     decline — messaging someone who might walk away is noise, so
 *     the send is refused until the rider is on the order for real.
 *     The get action still returns the (empty) history for the rider
 *     channel in that window so the tab is harmless; only the send
 *     is gated.
 *
 * Delta fetch
 * -----------
 * The get action accepts an optional `since_id`. When it is present
 * and > 0, the handler returns only rows whose message_id is greater
 * than that value (see getOrderMessagesSince()). When it is absent
 * or zero, the full conversation is returned. Both paths select the
 * same columns so the client can render either response with the
 * same code.
 *
 * The polling client sends `since_id` on every tick after the first
 * load. An idle conversation therefore costs one indexed lookup and
 * transfers an empty JSON array; there is no GROUP BY, no JOIN, no
 * full-table scan, and no re-render on the client.
 *
 * This handler contains NO SQL of its own. All data access goes
 * through customer/backend/database/tracking-queries.php. The one
 * exception is resolveRecipientId(), which needs a join across
 * queue_item, restaurant_branch, and restaurant_account and is
 * declared here because it is a request-scoped lookup for the send
 * path, not a reusable read.
 *
 * This file is NOT safe to require from a page — it runs a full
 * request dispatch at load time.
 *
 * @package FitPal
 * @version 1.3 — Adds chat gating and delta polling:
 *                  - get: accepts optional `since_id`; routes through
 *                    getOrderMessagesSince() when present.
 *                  - send: refuses delivery_rider messages until the
 *                    rider has accepted (riderHasAcceptedOrder);
 *                    refuses restaurant_account messages on
 *                    cancelled/refunded orders
 *                    (orderAllowsKitchenMessaging).
 *                  - send: reads the inserted row back via
 *                    getMessageById() so the response payload is
 *                    identical to what a delta fetch would produce.
 *
 *                (1.2: CSRF validated against customer_csrf_token;
 *                explicit empty guards on both sides. 1.1: explicit
 *                join in resolveRecipientId.)
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$customerId = (int)$_SESSION['customer_id'];

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/tracking-queries.php';

// Per-role CSRF check. The customer role validates against its own
// session key, 'customer_csrf_token', never the shared 'csrf_token'.
// Another role in the same browser session could have unset or
// rotated the shared key on its own sign-in, which would otherwise
// invalidate the token this request was issued under. See general.md.
$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['customer_csrf_token'] ?? '');

if ($sessToken === '' || $givenToken === '' || !hash_equals($sessToken, $givenToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$action = (string)($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'get':
            handleGetMessages($database_connection, $customerId);
            break;

        case 'send':
            handleSendMessage($database_connection, $customerId);
            break;

        case 'read':
            handleMarkRead($database_connection, $customerId);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log('Message handler DB error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred']);
} catch (Throwable $e) {
    error_log('Message handler error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred']);
}

/* -----------------------------------------------------------------
 * HANDLERS
 * ----------------------------------------------------------------- */

/**
 * Return messages for a single channel on an order.
 *
 * When `since_id` is provided and > 0, returns only messages that
 * arrived after it — the polling path. When it is absent or zero,
 * returns the full conversation — the initial load path.
 */
function handleGetMessages(PDO $db, int $customerId): void
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    $channel = (string)($_POST['channel'] ?? '');
    $sinceId = (int)($_POST['since_id'] ?? 0);

    $allowed = ['restaurant_account', 'delivery_rider'];
    if ($orderId <= 0 || !in_array($channel, $allowed, true)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
        return;
    }

    if (!getTrackableOrder($db, $orderId, $customerId)) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        return;
    }

    // Delta path when the client already holds history; full path
    // otherwise. Same SELECT shape, same JSON output either way.
    if ($sinceId > 0) {
        $messages = getOrderMessagesSince($db, $orderId, $customerId, $channel, $sinceId);
    } else {
        $messages = getOrderMessages($db, $orderId, $customerId, $channel);
    }

    $formatted = [];
    $maxId     = $sinceId;

    foreach ($messages as $m) {
        $mid = (int)$m['message_id'];
        if ($mid > $maxId) {
            $maxId = $mid;
        }

        $isSent = ($m['sender_type'] === 'customer');
        $formatted[] = [
            'message_id' => $mid,
            'direction'  => $isSent ? 'sent' : 'received',
            'sender'     => $isSent ? 'You' : senderDisplayName($channel),
            'content'    => (string)$m['content'],
            'time'       => formatMessageTime((string)$m['created_at']),
        ];
    }

    echo json_encode([
        'status'   => 'success',
        'messages' => $formatted,
        'max_id'   => $maxId,
    ]);
}

/**
 * Insert a new customer message.
 *
 * Enforces the two chat-gating rules described in the file header:
 *   - restaurant_account: refused on cancelled/refunded orders.
 *   - delivery_rider:     refused until the rider has accepted.
 *
 * On success the inserted row is read back via getMessageById() and
 * returned in the same shape a subsequent delta fetch would return,
 * so the client's optimistic append is indistinguishable from the
 * server's next poll.
 */
function handleSendMessage(PDO $db, int $customerId): void
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    $channel = (string)($_POST['channel'] ?? '');
    $content = trim((string)($_POST['content'] ?? ''));

    $allowed = ['restaurant_account', 'delivery_rider'];
    if ($orderId <= 0 || !in_array($channel, $allowed, true)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
        return;
    }

    if ($content === '') {
        echo json_encode(['status' => 'error', 'message' => 'Message cannot be empty']);
        return;
    }

    if (strlen($content) > 500) {
        echo json_encode(['status' => 'error', 'message' => 'Message is too long (max 500 characters)']);
        return;
    }

    $order = getTrackableOrder($db, $orderId, $customerId);
    if (!$order) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        return;
    }

    // ---- Chat gating ----
    if ($channel === 'restaurant_account') {
        if (!orderAllowsKitchenMessaging($db, $orderId)) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'This order is closed and can no longer be discussed with the kitchen.',
            ]);
            return;
        }
    } elseif ($channel === 'delivery_rider') {
        if (!riderHasAcceptedOrder($db, $orderId)) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'You can only message the rider once they have accepted your order.',
            ]);
            return;
        }
    }

    $recipientId = resolveRecipientId($db, $order, $channel);
    if ($recipientId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Recipient not available']);
        return;
    }

    $messageId = createOrderMessage(
        $db,
        $orderId,
        $customerId,
        $channel,
        $recipientId,
        $content
    );

    // Read the inserted row back so the appended node on the client
    // is byte-identical to what a delta fetch would produce. This
    // matters for the polling flow: after an optimistic append, the
    // very next tick fetches since_id=max_id and returns nothing for
    // this message, so there is no duplicate.
    $row = getMessageById($db, $messageId, $orderId);
    if (!$row) {
        // Extremely unlikely (the row was just inserted). Fall back
        // to a locally-shaped payload so the client still renders
        // something sensible.
        echo json_encode([
            'status'       => 'success',
            'message_id'   => $messageId,
            'message_data' => [
                'message_id' => $messageId,
                'direction'  => 'sent',
                'sender'     => 'You',
                'content'    => $content,
                'time'       => date('g:i A'),
            ],
            'max_id'       => $messageId,
        ]);
        return;
    }

    echo json_encode([
        'status'       => 'success',
        'message_id'   => $messageId,
        'message_data' => [
            'message_id' => (int)$row['message_id'],
            'direction'  => 'sent',
            'sender'     => 'You',
            'content'    => (string)$row['content'],
            'time'       => formatMessageTime((string)$row['created_at']),
        ],
        'max_id'       => $messageId,
    ]);
}

/**
 * Mark all incoming messages from a channel as read.
 */
function handleMarkRead(PDO $db, int $customerId): void
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    $channel = (string)($_POST['channel'] ?? '');

    $allowed = ['restaurant_account', 'delivery_rider'];
    if ($orderId <= 0 || !in_array($channel, $allowed, true)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
        return;
    }

    if (!getTrackableOrder($db, $orderId, $customerId)) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        return;
    }

    markOrderMessagesRead($db, $orderId, $channel);

    echo json_encode(['status' => 'success']);
}

/* -----------------------------------------------------------------
 * HELPERS
 * ----------------------------------------------------------------- */

/**
 * Resolve the counterparty ID for a channel on an order.
 *
 * For 'delivery_rider' this is the assigned rider.
 * For 'restaurant_account' this is the first active restaurant
 * account tied to the order's branch(es). The customer talks to a
 * single restaurant contact per order.
 *
 * @param PDO $db
 * @param array<string, mixed> $order
 * @param string $channel
 * @return int 0 if unavailable
 */
function resolveRecipientId(PDO $db, array $order, string $channel): int
{
    if ($channel === 'delivery_rider') {
        return (int)($order['delivery_rider_id'] ?? 0);
    }

    if ($channel === 'restaurant_account') {
        $orderId = (int)($order['order_id'] ?? 0);
        if ($orderId <= 0) {
            return 0;
        }

        $stmt = $db->prepare(
            "SELECT ra.restaurant_account_id
               FROM queue_item qi
               JOIN restaurant_branch rb ON rb.restaurant_branch_id = qi.branch_id
               JOIN restaurant_account ra ON ra.restaurant_id = rb.restaurant_id
              WHERE qi.order_id = :order_id
                AND ra.is_active = 1
              ORDER BY ra.restaurant_account_id ASC
              LIMIT 1"
        );
        $stmt->execute([':order_id' => $orderId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    return 0;
}

/**
 * Human-readable label for a message sender.
 *
 * @param string $channel
 * @return string
 */
function senderDisplayName(string $channel): string
{
    return $channel === 'delivery_rider' ? 'Rider' : 'Restaurant';
}