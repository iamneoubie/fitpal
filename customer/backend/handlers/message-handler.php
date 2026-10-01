<?php
/**
 * FitPal Customer Order Tracking Message Handler
 *
 * AJAX endpoint for the customer chat modal on the order tracking
 * page. Runs on the customer session (PHPSESSID_CUSTOMER), separate
 * from every other role's session.
 *
 * ---------------------------------------------------------------------
 * ACTIONS
 * ---------------------------------------------------------------------
 *   get    → return messages for a channel (full load or delta)
 *   send   → insert a new customer message
 *   read   → mark all messages from a counterparty as read
 *
 * --------------------------------------------------------------------- * DIRECTION — WHO IS "SENT"
 * ---------------------------------------------------------------------
 * This handler runs on the customer session. Every request it
 * answers is a customer's request. Therefore:
 *
 *     sender_type === 'customer'  →  the message is the customer's
 *                                    own; direction = 'sent'
 *
 *     sender_type === anything else
 *         (restaurant_account, delivery_rider, administrator,
 *          system)                 →  the message came from the
 *                                    other side; direction =
 *                                    'received'
 *
 * The previous revision keyed direction on
 * `sender_type === 'restaurant_account'`. That happened to be
 * correct on the restaurant channel and wrong on the rider channel,
 * where the customer's own messages are `sender_type = 'customer'`
 * and the rider's messages are `sender_type = 'delivery_rider'`.
 * The result was that on the rider tab, the customer's own
 * messages were tagged `direction = 'received'` and rendered as
 * white left-aligned bubbles, while the rider's messages were
 * tagged `direction = 'received'` too and looked identical.
 *
 * This revision decides direction on `sender_type === 'customer'`,
 * which is the only predicate that is correct on both channels.
 *
 * ---------------------------------------------------------------------
 * CHAT GATING
 * ---------------------------------------------------------------------
 * The read path and the send path are gated by the same predicate,
 * getRiderMessagingState() from tracking-queries.php. Both channels
 * (kitchen and rider) route through it so the two cannot drift.
 *
 * ---------------------------------------------------------------------
 * RESPONSE SHAPE
 * ---------------------------------------------------------------------
 * Every message object carries:
 *
 *   message_id  int
 *   direction   "sent" | "received"      ← see above
 *   sender      display string ("You", "Restaurant", "Rider")
 *   content     string
 *   time        "g:i A" formatted timestamp
 *
 * The top-level response carries max_id — the highest message_id in
 * the batch — so the client can advance its delta cursor.
 *
 * ---------------------------------------------------------------------
 * DEPLOYMENT NOTE
 * ---------------------------------------------------------------------
 * Requires getRiderMessagingState() from tracking-queries.php.
 * Deploy tracking-queries.php BEFORE this file.
 *
 * @package FitPal
 * @version 5.0 — direction is decided on `sender_type === 'customer'`
 *                instead of `sender_type === 'restaurant_account'`.
 *                This is the fix for the rider channel returning
 *                direction = 'received' for the customer's own
 *                messages.
 *
 *                Every other line — the actions, the channel
 *                gating, the refusal copy, the response shape, the
 *                CSRF contract, the auth guard — is byte-identical
 *                to v4.0.
 *
 *                (4.0: rider-channel read path gated identically
 *                to the send path. 3.0: documentation only. 2.0:
 *                per-role session migration. 1.3: chat gating and
 *                delta polling. 1.2: CSRF validated against
 *                customer_csrf_token.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$customerId = (int)$_SESSION['customer_id'];

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/tracking-queries.php';

// ---------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['customer_csrf_token'] ?? '');

if (
    $sessToken === ''
    || $givenToken === ''
    || !hash_equals($sessToken, $givenToken)
) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$action = (string)($_POST['action'] ?? '');

// ---------------------------------------------------------------------
// DISPATCH
// ---------------------------------------------------------------------

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
 * REFUSAL COPY
 * ----------------------------------------------------------------- */

function riderChannelRefusalMessage(string $state): string
{
    return match ($state) {
        'not_accepted'  => 'You can message the rider once they have accepted your order.',
        'terminal'      => 'This order is closed and can no longer be discussed.',
        'window_closed' => 'The messaging window for this delivered order has ended.',
        'no_rider'      => 'This order does not have a rider assigned.',
        'not_found'     => 'Order not found.',
        default         => '',
    };
}

function kitchenChannelRefusalMessage(string $orderStatus, bool $withinGrace): string
{
    if (in_array($orderStatus, ['cancelled', 'refunded', 'failed'], true)) {
        return 'This order is closed and can no longer be discussed with the kitchen.';
    }

    if ($orderStatus === 'delivered' && !$withinGrace) {
        return 'The messaging window for this delivered order has ended.';
    }

    return '';
}

/* -----------------------------------------------------------------
 * HANDLERS
 * ----------------------------------------------------------------- */

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

    $order = getTrackableOrder($db, $orderId, $customerId);
    if (!$order) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        return;
    }

    $orderStatus = (string)$order['order_status'];

    if ($channel === 'delivery_rider') {
        $state = getRiderMessagingState($db, $orderId);

        if ($state !== 'open') {
            echo json_encode([
                'status'  => 'error',
                'message' => riderChannelRefusalMessage($state),
                'channel' => $channel,
                'reason'  => $state,
            ]);
            return;
        }
    } elseif ($channel === 'restaurant_account') {
        $withinGrace = customerOrderDeliveredWithinGrace($order);

        $refusal = kitchenChannelRefusalMessage($orderStatus, $withinGrace);
        if ($refusal !== '') {
            echo json_encode([
                'status'  => 'error',
                'message' => $refusal,
                'channel' => $channel,
                'reason'  => in_array($orderStatus, ['cancelled', 'refunded', 'failed'], true)
                    ? 'terminal'
                    : 'window_closed',
            ]);
            return;
        }
    }

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

        $formatted[] = shapeMessage($m, $channel);
    }

    echo json_encode([
        'status'   => 'success',
        'messages' => $formatted,
        'max_id'   => $maxId,
    ]);
}

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

    $orderStatus = (string)$order['order_status'];

    if ($channel === 'restaurant_account') {
        $withinGrace = customerOrderDeliveredWithinGrace($order);
        $refusal     = kitchenChannelRefusalMessage($orderStatus, $withinGrace);

        if ($refusal !== '') {
            echo json_encode([
                'status'  => 'error',
                'message' => $refusal,
                'channel' => $channel,
                'reason'  => in_array($orderStatus, ['cancelled', 'refunded', 'failed'], true)
                    ? 'terminal'
                    : 'window_closed',
            ]);
            return;
        }
    } elseif ($channel === 'delivery_rider') {
        $state = getRiderMessagingState($db, $orderId);

        if ($state !== 'open') {
            echo json_encode([
                'status'  => 'error',
                'message' => riderChannelRefusalMessage($state),
                'channel' => $channel,
                'reason'  => $state,
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

    $row = getMessageById($db, $messageId, $orderId);
    if (!$row) {
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
        'message_data' => shapeMessage($row, $channel),
        'max_id'       => $messageId,
    ]);
}

function handleMarkRead(PDO $db, int $customerId): void
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    $channel = (string)($_POST['channel'] ?? '');

    $allowed = ['restaurant_account', 'delivery_rider'];
    if ($orderId <= 0 || !in_array($channel, $allowed, true)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
        return;
    }

    $order = getTrackableOrder($db, $orderId, $customerId);
    if (!$order) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        return;
    }

    $orderStatus = (string)$order['order_status'];

    if ($channel === 'delivery_rider') {
        $state = getRiderMessagingState($db, $orderId);
        if ($state !== 'open') {
            echo json_encode([
                'status'  => 'error',
                'message' => riderChannelRefusalMessage($state),
                'channel' => $channel,
                'reason'  => $state,
            ]);
            return;
        }
    } elseif ($channel === 'restaurant_account') {
        $withinGrace = customerOrderDeliveredWithinGrace($order);
        $refusal     = kitchenChannelRefusalMessage($orderStatus, $withinGrace);

        if ($refusal !== '') {
            echo json_encode([
                'status'  => 'error',
                'message' => $refusal,
                'channel' => $channel,
                'reason'  => in_array($orderStatus, ['cancelled', 'refunded', 'failed'], true)
                    ? 'terminal'
                    : 'window_closed',
            ]);
            return;
        }
    }

    markOrderMessagesRead($db, $orderId, $channel);

    echo json_encode(['status' => 'success']);
}

/* -----------------------------------------------------------------
 * SHAPING
 * ----------------------------------------------------------------- */

/**
 * Shape a raw message row into the JSON payload the modal consumes.
 *
 * Direction is decided on `sender_type === 'customer'`. This handler
 * runs on the customer session, so a row whose sender_type is
 * 'customer' is the customer's own message and reads as 'sent'.
 * Every other sender_type — restaurant_account, delivery_rider,
 * administrator, system — reads as 'received'.
 *
 * @param array<string, mixed> $row
 * @param string $channel  Used only for the label on the received
 *                         side ("Restaurant" or "Rider").
 * @return array<string, mixed>
 */
function shapeMessage(array $row, string $channel): array
{
    $senderType = (string)($row['sender_type'] ?? '');
    $isSent     = ($senderType === 'customer');

    $receivedLabel = ($channel === 'delivery_rider') ? 'Rider' : 'Restaurant';

    return [
        'message_id' => (int)($row['message_id'] ?? 0),
        'direction'  => $isSent ? 'sent' : 'received',
        'sender'     => $isSent ? 'You' : $receivedLabel,
        'content'    => (string)($row['content'] ?? ''),
        'time'       => formatMessageTime((string)($row['created_at'] ?? '')),

        'sender_type' => $senderType,
        'created_at'  => (string)($row['created_at'] ?? ''),
        'is_read'     => (int)($row['is_read'] ?? 0) === 1,
    ];
}

/* -----------------------------------------------------------------
 * HELPERS
 * ----------------------------------------------------------------- */

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

function senderDisplayName(string $channel): string
{
    return $channel === 'delivery_rider' ? 'Rider' : 'Restaurant';
}