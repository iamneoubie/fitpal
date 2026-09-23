<?php
/**
 * FitPal Customer Order Tracking Message Handler
 *
 * AJAX endpoint for the customer chat modal on the order tracking
 * page. Handles fetching messages, sending a new message, and
 * marking a conversation as read.
 *
 * Actions:
 *   get    → return messages for a channel
 *   send   → insert a new customer message
 *   read   → mark all messages from a counterparty as read
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
 * @version 1.2 — Validates against customer_csrf_token; explicit empty
 *                guards on both sides. (1.1: explicit join in
 *                resolveRecipientId.)
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
 * Return all messages for a single channel on an order.
 */
function handleGetMessages(PDO $db, int $customerId): void
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

    $messages  = getOrderMessages($db, $orderId, $customerId, $channel);
    $formatted = [];

    foreach ($messages as $m) {
        $isSent = ($m['sender_type'] === 'customer');
        $formatted[] = [
            'message_id' => (int)$m['message_id'],
            'direction'  => $isSent ? 'sent' : 'received',
            'sender'     => $isSent ? 'You' : senderDisplayName($channel),
            'content'    => (string)$m['content'],
            'time'       => formatMessageTime((string)$m['created_at']),
        ];
    }

    echo json_encode([
        'status'   => 'success',
        'messages' => $formatted,
    ]);
}

/**
 * Insert a new customer message.
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

    echo json_encode([
        'status'     => 'success',
        'message_id' => $messageId,
        'content'    => $content,
        'time'       => date('g:i A'),
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