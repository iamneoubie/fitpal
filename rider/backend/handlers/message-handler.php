<?php
/**
 * FitPal Rider Message Handler
 *
 * Actions:
 *   get_messages  → fetch conversation with customer or kitchen
 *   send_message  → send a message to customer or kitchen
 *
 * @package FitPal
 * @version 1.1 — CSRF validation now compares against the rider
 *                role's own session key, rider_csrf_token, instead of
 *                the shared csrf_token. Requires
 *                includes/rider-csrf-token.php so the handler owns
 *                its CSRF bootstrap. Uses isset() on both keys
 *                before hash_equals() so an unset session key can
 *                never be coerced to an empty string and pass
 *                validation against an empty POST value. On
 *                mismatch, rotates the rider token before returning
 *                the JSON error, mirroring rider-handler.php v4.1
 *                and the admin handlers. Only the rider's own key is
 *                touched; the shared csrf_token key and every other
 *                role's token are left alone.
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
    // Rotate the rider's own token so the next render generates a
    // fresh one. Only the rider's key is cleared — never the shared
    // 'csrf_token' key.
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

    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    if (!in_array($withType, ['customer', 'restaurant_account'], true)) {
        return ['status' => 'error', 'message' => 'Invalid recipient type.'];
    }

    // Verify the rider is assigned to this order
    $check = $db->prepare(
        "SELECT 1 FROM orders
         WHERE order_id = :order_id AND delivery_rider_id = :rider_id
         LIMIT 1"
    );
    $check->execute([':order_id' => $orderId, ':rider_id' => $riderId]);
    if ($check->fetchColumn() === false) {
        return ['status' => 'error', 'message' => 'Order not found.'];
    }

    $stmt = $db->prepare(
        "SELECT
            message_id,
            sender_type,
            sender_id,
            recipient_type,
            recipient_id,
            content,
            created_at,
            CASE
                WHEN sender_type = 'delivery_rider' THEN 1
                ELSE 0
            END AS is_own
         FROM message
         WHERE order_id = :order_id
           AND (
                (sender_type = 'delivery_rider' AND recipient_type = :with_type)
             OR (sender_type = :with_type2 AND recipient_type = 'delivery_rider')
           )
         ORDER BY created_at ASC
         LIMIT 200"
    );
    $stmt->execute([
        ':order_id'   => $orderId,
        ':with_type'  => $withType,
        ':with_type2' => $withType,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $messages = [];
    foreach ($rows as $r) {
        $isOwn = (int)$r['is_own'] === 1;
        $messages[] = [
            'message_id'   => (int)$r['message_id'],
            'sender_type'  => (string)$r['sender_type'],
            'sender_label' => formatSenderLabel((string)$r['sender_type']),
            'content'      => (string)$r['content'],
            'created_at'   => (string)$r['created_at'],
            'is_own'       => $isOwn,
            'is_sent'      => $isOwn,
        ];
    }

    return ['status' => 'success', 'messages' => $messages];
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

    // Verify rider is assigned and order is active
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

    if (!in_array($order['order_status'], ['pending', 'preparing', 'delivering'], true)) {
        return ['status' => 'error', 'message' => 'This order is no longer active.'];
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

    return [
        'status'       => 'success',
        'message'      => 'Message sent.',
        'message_data' => [
            'message_id'   => (int)$msg['message_id'],
            'sender_type'  => (string)$msg['sender_type'],
            'sender_label' => 'You',
            'content'      => (string)$msg['content'],
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