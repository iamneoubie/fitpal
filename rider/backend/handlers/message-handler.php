<?php
/**
 * FitPal Rider Message Handler
 *
 * Actions:
 *   get_messages  → fetch conversation with customer or kitchen
 *   send_message  → send a message to customer or kitchen
 *   mark_read     → flip is_read on every inbound message in the
 *                   (order, channel) pair addressed to this rider
 *
 * ---------------------------------------------------------------------
 * RESPONSE SHAPE
 * ---------------------------------------------------------------------
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
 * ---------------------------------------------------------------------
 * DELTA FETCH
 * ---------------------------------------------------------------------
 * When `since_id` is present and > 0, only rows with message_id
 * strictly greater than it are returned. When it is absent or zero,
 * the full conversation is returned. Both paths select the same
 * columns and produce the same JSON.
 *
 * ---------------------------------------------------------------------
 * MESSAGING WINDOWS
 * ---------------------------------------------------------------------
 * Two channels, each with its own window. Every window below is
 * enforced on the SEND path only. The read path (get_messages,
 * mark_read) is unbounded — the conversation is history and
 * remains readable after the order is closed.
 *
 * KITCHEN (restaurant_account)
 *
 *   Open in all three live delivery-facing statuses:
 *
 *       rider_pending  — kitchen asked; rider has not yet decided
 *       picking_up     — rider accepted; en route to / at the
 *                        restaurant; food not yet in hand
 *       delivering     — rider has the food; en route to the
 *                        customer
 *
 *   Also open in 'delivered' for ONE HOUR after delivered_at.
 *   During the grace window the rider may need to confirm a wrong
 *   drop-off, a missing item, or a payment question with the
 *   kitchen before the customer closes the conversation.
 *
 * CUSTOMER
 *
 *   Open in all three post-accept statuses:
 *
 *       picking_up     — rider accepted; en route to / at the
 *                        restaurant; food not yet in hand
 *       delivering     — rider has the food; en route to the
 *                        customer
 *       delivered      — order arrived; the customer may still
 *                        need to report a missing item
 *
 *   The 'delivered' window is bounded to ONE HOUR after
 *   delivered_at, matching the customer and restaurant sides.
 *
 *   'rider_pending' stays excluded on the customer side. During
 *   'rider_pending' the rider has not accepted yet — they may
 *   still decline — and messaging the customer before accepting
 *   would let an unconfirmed rider contact a customer about an
 *   order they may not take.
 *
 * CLOSED ORDERS
 *
 *   A 'cancelled', 'refunded', or 'failed' order is closed on both
 *   channels. Every send handler for those three statuses falls
 *   through to the same refusal path.
 *
 *   The 'failed' status is produced by the shared order-transaction
 *   layer's sweepFailedDeliveries() when a rider does not complete
 *   a delivery within FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS of
 *   the order entering 'delivering'. A failed order is terminal:
 *   the kitchen has already been notified, the customer's tracking
 *   page has already rendered the failed state, and the ledger for
 *   the order is settled (the rider's accept-time debit is kept in
 *   place; no delivery credit was written). No further
 *   conversation on the order is possible.
 *
 *   Refusal on a failed order is therefore intentional, not an
 *   oversight of the status filter. Both guard branches below
 *   name the three closed statuses explicitly for that reason.
 *
 * ---------------------------------------------------------------------
 * ONE-HOUR WINDOW CONSTANT
 * ---------------------------------------------------------------------
 * The post-delivery window is FITPAL_RIDER_MESSAGE_GRACE_SECONDS,
 * defined in shared/backend/database/fee-queries.php alongside
 * FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS. Both windows are
 * one hour, so the two windows that bracket a delivery have the
 * same length and are easy to reason about together. This handler
 * requires fee-queries.php so the constant is guaranteed to be
 * defined before any guard checks it.
 *
 * A defensive local definition is provided in case this file is
 * ever included in a context where fee-queries.php was not loaded,
 * which should not happen on the rider side but costs nothing.
 *
 * ---------------------------------------------------------------------
 * READ MARKER
 * ---------------------------------------------------------------------
 * mark_read flips is_read = 1 on every `message` row whose
 * (order_id, sender_type, recipient_type) targets this rider on the
 * given channel and whose is_read is still 0.
 *
 * The read path has no status gate and no time gate. This matches
 * get_messages: the conversation is history and remains readable
 * after the order is closed, so marking it read after the fact is
 * correct — a rider who opens a delivered order's chat panel and
 * reads the tail of the conversation should clear the flag.
 *
 * The write is scoped three ways:
 *
 *   - order_id = the order the client sent
 *   - recipient_type = 'delivery_rider'
 *   - recipient_id  = the authenticated rider's own id
 *
 * A rider cannot mark another rider's messages read, and cannot
 * mark a message read on an order they are not assigned to,
 * because the assignment guard at the top of handleMarkRead()
 * refuses the request when the order does not belong to them.
 *
 * ---------------------------------------------------------------------
 * PLACEHOLDER RULE
 * ---------------------------------------------------------------------
 * PDO::ATTR_EMULATE_PREPARES is false, so a native prepare rejects
 * the same named placeholder used twice in one statement. Every
 * query below that needs the same value in more than one position
 * uses distinct placeholder names.
 *
 * @package FitPal
 * @version 1.7 — The customer and kitchen channels now both permit
 *                the 'delivered' status, bounded by the one-hour
 *                post-delivery window.
 *
 *                - handleSendMessage() reads delivered_at for a
 *                  delivered order and refuses the send when the
 *                  window has elapsed.
 *                - The kitchen allow-list gains 'delivered'. The
 *                  customer allow-list keeps 'delivered' and now
 *                  enforces the time bound.
 *                - fee-queries.php is required so the shared
 *                  FITPAL_RIDER_MESSAGE_GRACE_SECONDS constant is
 *                  available to the guards.
 *                - The refusal messages name the window so the
 *                  rider knows why the send was rejected.
 *                - get_messages and mark_read are byte-identical to
 *                  v1.6.
 *
 *                (1.6: added mark_read. 1.5: docblock records the
 *                failed-order refusal on the send path. 1.4:
 *                'picking_up' added to the customer-window
 *                allow-list. 1.3: 'picking_up' added to the
 *                restaurant_account allow-list. 1.2: response shape
 *                aligned with the shared rider chat modal. 1.1:
 *                CSRF validated against rider_csrf_token.)
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

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

// Pull in the shared fee schedule so the one-hour window constant
// is defined. This is the same file the shared order-transaction
// layer reads for the failed-delivery grace, so the two windows
// cannot drift apart.
require_once __DIR__ . '/../../../shared/backend/database/fee-queries.php';

// Defensive: if fee-queries.php is ever edited to drop the
// constant, define a local fallback so this handler does not fatal
// on an undefined constant. The shared value remains the source of
// truth whenever it is present.
if (!defined('FITPAL_RIDER_MESSAGE_GRACE_SECONDS')) {
    define('FITPAL_RIDER_MESSAGE_GRACE_SECONDS', 3600);
}

// Own the rider role's CSRF bootstrap. Idempotent; stores the token
// under 'rider_csrf_token' — never the shared 'csrf_token' key.
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

        case 'mark_read':
            $response = handleMarkRead($database_connection, $riderId);
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
// HANDLERS
// ----------------------------------------------------------------

/**
 * Fetch the conversation on one channel, optionally as a delta.
 *
 * The read path has no status gate and no time gate. The
 * conversation is history and remains readable after the order is
 * closed, so a rider reviewing a past delivery can still see what
 * was said. Bounding the read path would make a delivered order's
 * chat panel appear empty the moment the one-hour window closed,
 * which would be wrong: the messages are still there and the rider
 * should still be able to look at them.
 *
 * @param PDO $db
 * @param int $riderId
 * @return array<string, mixed>
 */
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
            'message_id' => $mid,
            'direction'  => $isOwn ? 'sent' : 'received',
            'sender'     => $isOwn ? 'You' : formatSenderLabel((string)$r['sender_type']),
            'content'    => (string)$r['content'],
            'time'       => $time,

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

/**
 * Send one message on one channel.
 *
 * The send path enforces the messaging window. Both guards below
 * refuse a closed order — 'cancelled', 'refunded', or 'failed' —
 * because the shared order-transaction layer has already settled
 * the order and neither the customer nor the kitchen has anything
 * further to say about it.
 *
 * The 'delivered' status is handled as a bounded window: the rider
 * may send for one hour after delivered_at. Once the window
 * elapses, both channels refuse with a message that names the
 * window, so the rider knows the send was rejected on purpose.
 *
 * @param PDO $db
 * @param int $riderId
 * @return array<string, mixed>
 */
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

    // Verify rider is assigned. Read the status AND delivered_at in
    // one query so the time-bound check below needs no second
    // round-trip.
    $orderStmt = $db->prepare(
        "SELECT order_id, customer_id, order_status, delivered_at
         FROM orders
         WHERE order_id = :order_id AND delivery_rider_id = :rider_id
         LIMIT 1"
    );
    $orderStmt->execute([':order_id' => $orderId, ':rider_id' => $riderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return ['status' => 'error', 'message' => 'Order not found.'];
    }

    $status      = (string)$order['order_status'];
    $deliveredAt = $order['delivered_at'] !== null
        ? (string)$order['delivered_at']
        : '';

    // ---- Post-delivery time gate ----------------------------------
    //
    // A delivered order is messageable for one hour. Outside the
    // window, both channels refuse. This check runs once, before
    // the per-channel status checks, so the two channels share
    // exactly the same time bound.
    //
    // The window is measured from delivered_at, not from the
    // moment the message is sent. An order delivered 90 minutes
    // ago is outside the window even if the rider has never
    // opened the chat panel before.
    if ($status === 'delivered') {
        if ($deliveredAt === '') {
            // A delivered order must carry delivered_at; the
            // schema's chk_delivered_at_biconditional CHECK
            // enforces it. This branch is defensive only.
            return [
                'status'  => 'error',
                'message' => 'This order cannot be messaged right now.',
            ];
        }

        $deliveredTs = strtotime($deliveredAt);
        if ($deliveredTs === false) {
            return [
                'status'  => 'error',
                'message' => 'This order cannot be messaged right now.',
            ];
        }

        $elapsed = time() - $deliveredTs;
        if ($elapsed >= FITPAL_RIDER_MESSAGE_GRACE_SECONDS) {
            return [
                'status'  => 'error',
                'message' => 'The 1-hour messaging window for this order has closed.',
            ];
        }
    }

    // ---- Kitchen channel ------------------------------------------
    //
    // Open in all three live delivery-facing statuses, plus the
    // one-hour post-delivery window checked above.
    //
    // During 'picking_up' the rider has accepted and is en route
    // to or at the restaurant; they must be able to reach the
    // kitchen about the pickup.
    //
    // During 'delivered' (within the hour) the rider may need to
    // confirm a wrong drop-off, a missing item, or a payment
    // question before the customer closes the conversation.
    //
    // Closed orders ('cancelled', 'refunded', 'failed') fall
    // through to the refusal below.
    if ($recipientType === 'restaurant_account') {
        $allowedKitchen = ['rider_pending', 'picking_up', 'delivering', 'delivered'];
        if (!in_array($status, $allowedKitchen, true)) {
            return [
                'status'  => 'error',
                'message' => 'You can only message the kitchen for an order that is assigned to you.',
            ];
        }
    }

    // ---- Customer channel -----------------------------------------
    //
    // Open from the moment the rider accepts — the order is in
    // 'picking_up' — all the way through 'delivered', bounded by
    // the one-hour window checked above.
    //
    // 'rider_pending' stays excluded: the rider has not accepted
    // yet and may still decline.
    //
    // Closed orders ('cancelled', 'refunded', 'failed') fall
    // through to the refusal below.
    elseif ($recipientType === 'customer') {
        $allowedCustomer = ['picking_up', 'delivering', 'delivered'];
        if (!in_array($status, $allowedCustomer, true)) {
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

/**
 * Flip is_read on every inbound message addressed to this rider on
 * the given channel.
 *
 * Inbound means: the row's recipient_type is 'delivery_rider' and
 * its recipient_id is this rider's own id. The counterpart party is
 * the channel — 'customer' rows were sent by the customer, and
 * 'restaurant_account' rows were sent by the kitchen.
 *
 * The write is scoped by order_id, recipient_type, and
 * recipient_id. A rider cannot mark another rider's messages read,
 * and cannot mark a message read on an order they are not assigned
 * to, because the assignment guard at the top refuses when the
 * order does not belong to them.
 *
 * The read path has no status gate and no time gate. This matches
 * get_messages: the conversation is history and remains readable
 * after the order is closed, so marking it read after the fact is
 * correct.
 *
 * Fire-and-forget from the client. A failure here never affects
 * the visible conversation.
 *
 * @param PDO $db
 * @param int $riderId
 * @return array<string, mixed>
 */
function handleMarkRead(PDO $db, int $riderId): array
{
    $orderId  = (int)($_POST['order_id'] ?? 0);
    $withType = (string)($_POST['with_type'] ?? 'customer');

    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    if (!in_array($withType, ['customer', 'restaurant_account'], true)) {
        return ['status' => 'error', 'message' => 'Invalid recipient type.'];
    }

    // Verify the rider is assigned to this order. Same guard as
    // handleGetMessages and handleSendMessage.
    $check = $db->prepare(
        "SELECT 1 FROM orders
         WHERE order_id = :order_id AND delivery_rider_id = :rider_id
         LIMIT 1"
    );
    $check->execute([':order_id' => $orderId, ':rider_id' => $riderId]);
    if ($check->fetchColumn() === false) {
        return ['status' => 'error', 'message' => 'Order not found.'];
    }

    // Flip is_read on every row the other party sent to this rider.
    //
    // The recipient side is always the rider on this handler, so the
    // recipient_type filter is a constant and the recipient_id
    // filter is the authenticated rider. The sender side is the
    // channel the client asked for.
    $update = $db->prepare(
        "UPDATE message
            SET is_read = 1
          WHERE order_id = :order_id
            AND sender_type = :sender_type
            AND recipient_type = 'delivery_rider'
            AND recipient_id = :recipient_id
            AND is_read = 0"
    );
    $update->execute([
        ':order_id'     => $orderId,
        ':sender_type'  => $withType,
        ':recipient_id' => $riderId,
    ]);

    $updated = $update->rowCount();

    return [
        'status'  => 'success',
        'updated' => $updated,
    ];
}

/**
 * Human-readable label for a sender_type value.
 *
 * Used by handleGetMessages to name the counterpart in each message
 * row. Kept in one place so the mapping cannot drift between the
 * get and send paths.
 *
 * @param string $type
 * @return string
 */
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