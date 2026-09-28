<?php
/**
 * FitPal Rider Message Handler
 *
 * Actions:
 *   get_messages  → fetch conversation with customer or kitchen
 *   send_message  → send a message to customer or kitchen
 *
 * Response shape
 * --------------
 * The shared rider chat modal (rider-chat-modal.js) expects each
 * message to carry:
 *
 *   message_id  int
 *   direction   "sent" | "received"
 *   sender      display string ("You", "Customer", "Kitchen")
 *   content     string
 *   time        "g:i A" formatted timestamp
 *
 * and the top-level response to carry `max_id` — the highest
 * message_id in the batch. The client uses max_id as its delta
 * cursor, sending it back as `since_id` on the next poll.
 *
 * This shape matches customer/backend/handlers/message-handler.php
 * so both roles render through identical JS.
 *
 * Delta fetch
 * -----------
 * When `since_id` is present and > 0, only rows with message_id
 * strictly greater than it are returned. When it is absent or zero,
 * the full conversation is returned. Both paths select the same
 * columns and produce the same JSON, so the client renders either
 * response with the same code.
 *
 * Messaging windows
 * -----------------
 * Two channels, each with its own window.
 *
 * KITCHEN (restaurant_account)
 *
 *   Open in all three live delivery-facing statuses:
 *
 *       rider_pending  — kitchen asked; rider has not yet decided
 *       picking_up     — rider accepted; en route to / at the
 *                        restaurant; food not yet in hand
 *       delivering     — rider has the food; en route to the customer
 *
 *   'picking_up' was added in v1.3. Before that revision a rider
 *   who accepted a rider_pending offer landed in 'picking_up' and
 *   was then refused when trying to reach the kitchen about the
 *   pickup — even though the order was assigned to them and they
 *   were physically on the way. The read path (handleGetMessages)
 *   has no status gate at all, which is why the modal opened and
 *   showed the conversation but every send came back rejected.
 *
 * CUSTOMER
 *
 *   Open in all three post-accept statuses:
 *
 *       picking_up     — rider accepted; en route to / at the
 *                        restaurant; food not yet in hand
 *       delivering     — rider has the food; en route to the customer
 *       delivered      — order arrived; the customer may still need
 *                        to report a missing item
 *
 *   'picking_up' was added in v1.4. Before that revision the
 *   customer window started at 'delivering', which meant a rider
 *   who had accepted an order could not reach the customer at all
 *   until after the food was in hand — even though the customer
 *   already saw the rider's name on the tracking timeline's
 *   "Picking Up" step. A rider may legitimately need to confirm a
 *   gate code, an apartment number, or an address note before
 *   leaving the restaurant, and that conversation has to be
 *   possible during 'picking_up'.
 *
 *   'rider_pending' stays excluded on the customer side. During
 *   'rider_pending' the rider has not accepted yet — they may
 *   still decline — and messaging the customer before accepting
 *   would let an unconfirmed rider contact a customer about an
 *   order they may not take.
 *
 * @package FitPal
 * @version 1.4 — Adds 'picking_up' to the allowed statuses for
 *                customer messaging. Before this revision the
 *                customer window started at 'delivering', so a
 *                rider who had accepted an order could message the
 *                kitchen about the pickup but not the customer.
 *
 *                (1.3: added 'picking_up' to the restaurant_account
 *                allow-list. 1.2: aligned the response shape with
 *                the shared rider chat modal. 1.1: CSRF validated
 *                against rider_csrf_token.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_start();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['delivery_rider_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

// Own the rider role's CSRF bootstrap. The helper is idempotent and
// stores the token under 'rider_csrf_token' — never the shared
// 'csrf_token' key.
require_once __DIR__ . '/../../includes/rider-csrf-token.php';

if (
    !isset($_POST['csrf_token'], $_SESSION['rider_csrf_token']) ||
    !hash_equals((string)$_SESSION['rider_csrf_token'], (string)$_POST['csrf_token'])
) {
    unset($_SESSION['rider_csrf_token']);

    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$riderId = (int)$_SESSION['delivery_rider_id'];
$action  = (string)($_POST['action'] ?? '');

$response = ['status' => 'error', 'message' => 'Invalid action'];

try {
    switch ($action) {
        case 'get_messages':
            $response = handleGetMessages($database_connection, $riderId);
            break;
        case 'send_message':
            $response = handleSendMessage($database_connection, $riderId);
            break;
    }
} catch (PDOException $e) {
    error_log('Message handler DB error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'Database error occurred.'];
} catch (Throwable $e) {
    error_log('Message handler error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'An unexpected error occurred.'];
}

ob_end_clean();
echo json_encode($response);
exit;

// ----------------------------------------------------------------

function handleGetMessages(PDO $db, int $riderId): array
{
    $orderId  = (int)($_POST['order_id'] ?? 0);
    $withType = (string)($_POST['with_type'] ?? 'customer');
    $sinceId  = (int)($_POST['since_id'] ?? 0);

    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    if (!in_array($withType, ['customer', 'restaurant_account'], true)) {
        return ['status' => 'error', 'message' => 'Invalid recipient type.'];
    }

    // Verify the rider is assigned to this order.
    $check = $db->prepare(
        "SELECT 1 FROM orders
         WHERE order_id = :order_id AND delivery_rider_id = :rider_id
         LIMIT 1"
    );
    $check->execute([':order_id' => $orderId, ':rider_id' => $riderId]);
    if ($check->fetchColumn() === false) {
        return ['status' => 'error', 'message' => 'Order not found.'];
    }

    // Two paths: full history (since_id = 0) or delta (since_id > 0).
    // Both select the same columns so the client renders either
    // response with the same code.
    if ($sinceId > 0) {
        $stmt = $db->prepare(
            "SELECT
                message_id,
                sender_type,
                sender_id,
                recipient_type,
                recipient_id,
                content,
                created_at
             FROM message
             WHERE order_id = :order_id
               AND message_id > :since_id
               AND (
                    (sender_type = 'delivery_rider' AND recipient_type = :with_type)
                 OR (sender_type = :with_type2 AND recipient_type = 'delivery_rider')
               )
             ORDER BY message_id ASC
             LIMIT 200"
        );
        $stmt->execute([
            ':order_id'   => $orderId,
            ':since_id'   => $sinceId,
            ':with_type'  => $withType,
            ':with_type2' => $withType,
        ]);
    } else {
        $stmt = $db->prepare(
            "SELECT
                message_id,
                sender_type,
                sender_id,
                recipient_type,
                recipient_id,
                content,
                created_at
             FROM message
             WHERE order_id = :order_id
               AND (
                    (sender_type = 'delivery_rider' AND recipient_type = :with_type)
                 OR (sender_type = :with_type2 AND recipient_type = 'delivery_rider')
               )
             ORDER BY message_id ASC
             LIMIT 200"
        );
        $stmt->execute([
            ':order_id'   => $orderId,
            ':with_type'  => $withType,
            ':with_type2' => $withType,
        ]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $messages = [];
    $maxId    = $sinceId;

    foreach ($rows as $r) {
        $mid   = (int)$r['message_id'];
        $isOwn = ((string)$r['sender_type'] === 'delivery_rider');

        if ($mid > $maxId) {
            $maxId = $mid;
        }

        $ts   = strtotime((string)$r['created_at']);
        $time = $ts !== false ? date('g:i A', $ts) : (string)$r['created_at'];

        $messages[] = [
            // New shape consumed by rider-chat-modal.js
            'message_id' => $mid,
            'direction'  => $isOwn ? 'sent' : 'received',
            'sender'     => $isOwn ? 'You' : formatSenderLabel((string)$r['sender_type']),
            'content'    => (string)$r['content'],
            'time'       => $time,

            // Backwards-compatible fields (older callers)
            'sender_type'  => (string)$r['sender_type'],
            'sender_label' => formatSenderLabel((string)$r['sender_type']),
            'created_at'   => (string)$r['created_at'],
            'is_own'       => $isOwn,
            'is_sent'      => $isOwn,
        ];
    }

    return [
        'status'   => 'success',
        'messages' => $messages,
        'max_id'   => $maxId,
    ];
}

function handleSendMessage(PDO $db, int $riderId): array
{
    $orderId       = (int)($_POST['order_id'] ?? 0);
    $recipientType = (string)($_POST['recipient_type'] ?? '');
    $content       = trim((string)($_POST['content'] ?? ''));

    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }
    if (!in_array($recipientType, ['customer', 'restaurant_account'], true)) {
        return ['status' => 'error', 'message' => 'Invalid recipient.'];
    }
    if ($content === '' || mb_strlen($content) > 500) {
        return ['status' => 'error', 'message' => 'Message must be 1–500 characters.'];
    }

    // Verify rider is assigned and order is active.
    $orderStmt = $db->prepare(
        "SELECT order_id, customer_id, order_status
         FROM orders
         WHERE order_id = :order_id AND delivery_rider_id = :rider_id
         LIMIT 1"
    );
    $orderStmt->execute([':order_id' => $orderId, ':rider_id' => $riderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return ['status' => 'error', 'message' => 'Order not found.'];
    }

    $status = (string)$order['order_status'];

    // Kitchen messaging window.
    //
    // Open in all three live delivery-facing statuses. A rider who
    // accepted a rider_pending offer is in 'picking_up' — assigned
    // to the order, on the way to or at the restaurant — and must
    // be able to reach the kitchen about the pickup. 'delivering'
    // remains allowed: a question about the order that the kitchen
    // can answer is still a valid reason to reach them.
    if ($recipientType === 'restaurant_account') {
        if (!in_array($status, ['rider_pending', 'picking_up', 'delivering'], true)) {
            return [
                'status'  => 'error',
                'message' => 'You can only message the kitchen for an order that is assigned to you.',
            ];
        }
    }

    // Customer messaging window.
    //
    // Open from the moment the rider accepts — the order is in
    // 'picking_up' — all the way through 'delivered'. The customer
    // already sees the rider's name on the tracking timeline during
    // 'picking_up', and the rider may need to confirm a gate code,
    // an apartment number, or an address note before leaving the
    // restaurant.
    //
    // 'rider_pending' stays excluded. The rider has not accepted
    // yet and may still decline; messaging the customer before
    // accepting would let an unconfirmed rider contact a customer
    // about an order they may not take.
    elseif ($recipientType === 'customer') {
        if (!in_array($status, ['picking_up', 'delivering', 'delivered'], true)) {
            return [
                'status'  => 'error',
                'message' => 'You can only message the customer after you have accepted their order.',
            ];
        }
    }

    $recipientId = null;

    if ($recipientType === 'customer') {
        $recipientId = (int)$order['customer_id'];
    } elseif ($recipientType === 'restaurant_account') {
        $branchStmt = $db->prepare(
            "SELECT ra.restaurant_account_id
             FROM queue_item qi
             JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
             JOIN restaurant_account ra ON ra.restaurant_id = rb.restaurant_id
             WHERE qi.order_id = :order_id
               AND ra.is_active = 1
             ORDER BY FIELD(ra.role, 'owner', 'manager', 'staff') ASC
             LIMIT 1"
        );
        $branchStmt->execute([':order_id' => $orderId]);
        $recipientId = (int)($branchStmt->fetchColumn() ?: 0);
    }

    if (!$recipientId) {
        return ['status' => 'error', 'message' => 'Recipient not found.'];
    }

    $insert = $db->prepare(
        "INSERT INTO message
            (order_id, sender_type, sender_id, recipient_type, recipient_id,
             message_type, content)
         VALUES
            (:order_id, 'delivery_rider', :sender_id, :recipient_type, :recipient_id,
             'text', :content)"
    );
    $insert->execute([
        ':order_id'       => $orderId,
        ':sender_id'      => $riderId,
        ':recipient_type' => $recipientType,
        ':recipient_id'   => $recipientId,
        ':content'        => $content,
    ]);

    $messageId = (int)$db->lastInsertId();

    $fetch = $db->prepare(
        "SELECT message_id, sender_type, recipient_type, content, created_at
         FROM message
         WHERE message_id = :message_id"
    );
    $fetch->execute([':message_id' => $messageId]);
    $msg = $fetch->fetch(PDO::FETCH_ASSOC);

    $ts   = strtotime((string)$msg['created_at']);
    $time = $ts !== false ? date('g:i A', $ts) : (string)$msg['created_at'];

    return [
        'status'       => 'success',
        'message'      => 'Message sent.',
        'max_id'       => $messageId,
        'message_data' => [
            'message_id' => $messageId,
            'direction'  => 'sent',
            'sender'     => 'You',
            'content'    => (string)$msg['content'],
            'time'       => $time,

            'sender_type'  => 'delivery_rider',
            'sender_label' => 'You',
            'created_at'   => (string)$msg['created_at'],
            'is_own'       => true,
            'is_sent'      => true,
        ],
    ];
}

function formatSenderLabel(string $type): string
{
    return match ($type) {
        'customer'           => 'Customer',
        'restaurant_account' => 'Kitchen',
        'delivery_rider'     => 'You',
        'administrator'      => 'Support',
        'system'             => 'System',
        default              => 'User',
    };
}