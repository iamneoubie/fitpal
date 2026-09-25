<?php
/**
 * FitPal Customer Order Tracking Queries
 *
 * Feature file for the order tracking page. It owns every query
 * against orders, queue_item, customization_instance, delivery_rider,
 * and message that the tracking page needs.
 *
 * Why the pure display helpers live here and not in a handler:
 *
 *   Pages that render tracking data (order-tracking.php) need to call
 *   functions like getTrackingStatusMeta(). They cannot include a
 *   handler, because handlers run a full request dispatch at load
 *   time. This file only declares functions. It is safe to require
 *   from anywhere.
 *
 * PDO PLACEHOLDER RULE
 * --------------------
 * This project sets PDO::ATTR_EMULATE_PREPARES => false. With native
 * prepares, a named placeholder may be bound only once per statement.
 * Every query here that needs the same value in more than one
 * position uses distinct placeholder names.
 *
 * No $_POST, no header(), no echo.
 *
 * @package FitPal
 * @version 1.2 — Adds the two chat-gating helpers the handler needs:
 *                  - riderHasAcceptedOrder() — true only when the
 *                    order is actually in transit or already
 *                    delivered with a rider attached. Used to gate
 *                    the customer's ability to message the rider.
 *                  - getOrderMessagesSince() — delta fetch for the
 *                    polling path. Returns only rows whose message_id
 *                    is strictly greater than the client's last known
 *                    id, so an idle poll transfers nothing and the
 *                    client never re-renders the whole list.
 *
 *                (1.1: Distinct placeholders in getOrderMessages.)
 */

declare(strict_types=1);

require_once __DIR__ . '/order-queries.php';

/**
 * Fetch an order for tracking, scoped to the owning customer.
 *
 * Returns false if the order does not belong to the customer.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $customerId
 * @return array<string, mixed>|false
 */
function getTrackableOrder(PDO $db, int $orderId, int $customerId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.customer_id,
            o.delivery_rider_id,
            o.destination_address,
            o.order_status,
            o.payment_method,
            o.cancelled_by,
            o.order_date,
            o.delivered_at,
            o.updated_at
         FROM orders o
         WHERE o.order_id = :order_id
           AND o.customer_id = :customer_id
         LIMIT 1"
    );
    $stmt->execute([
        ':order_id'    => $orderId,
        ':customer_id' => $customerId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Fetch the rider assigned to an order, with profile and contact info.
 *
 * @param PDO $db
 * @param int $orderId
 * @return array<string, mixed>|false
 */
function getOrderRiderDetails(PDO $db, int $orderId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            dr.delivery_rider_id,
            dr.first_name,
            dr.middle_name,
            dr.last_name,
            dr.contact_number,
            dr.email,
            drp.profile_picture,
            drp.vehicle_type,
            drp.vehicle_plate,
            drp.average_rating,
            drp.total_deliveries
         FROM orders o
         JOIN delivery_rider dr ON o.delivery_rider_id = dr.delivery_rider_id
         LEFT JOIN delivery_rider_profile drp ON dr.delivery_rider_id = drp.delivery_rider_id
         WHERE o.order_id = :order_id
         LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Fetch the restaurant branch(es) involved in an order.
 *
 * @param PDO $db
 * @param int $orderId
 * @return array<int, array<string, mixed>>
 */
function getOrderRestaurants(PDO $db, int $orderId): array
{
    $stmt = $db->prepare(
        "SELECT DISTINCT
            rb.restaurant_branch_id AS branch_id,
            rb.branch_name,
            rb.barangay,
            rb.city,
            r.restaurant_id,
            r.business_name AS restaurant_name,
            r.cuisine_type
         FROM queue_item qi
         JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         WHERE qi.order_id = :order_id
         ORDER BY r.business_name"
    );
    $stmt->execute([':order_id' => $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch the order's items with their customizations.
 *
 * @param PDO $db
 * @param int $orderId
 * @return array<int, array<string, mixed>>
 */
function getTrackingOrderItems(PDO $db, int $orderId): array
{
    return getOrderItemsWithCustomizations($db, $orderId);
}

/**
 * Fetch the order totals (subtotal, delivery, service, VAT, total).
 *
 * @param PDO $db
 * @param int $orderId
 * @return array<string, mixed>|false
 */
function getTrackingOrderTotals(PDO $db, int $orderId): array|false
{
    return getOrderTotals($db, $orderId);
}

/* ---------------------------------------------------------------
 * CHAT GATING
 * --------------------------------------------------------------- */

/**
 * True when the assigned rider has actually accepted the order —
 * meaning the order is in 'delivering' or 'delivered' AND a rider
 * is attached.
 *
 * Why this exists:
 *   The kitchen sets orders.delivery_rider_id the moment it assigns
 *   a rider, but the rider may still decline. During 'rider_pending'
 *   the customer should not be able to start a conversation with
 *   someone who may walk away. Only once the rider has accepted
 *   (order_status = 'delivering' or 'delivered') does messaging the
 *   rider become meaningful.
 *
 * @param PDO $db
 * @param int $orderId
 * @return bool
 */
function riderHasAcceptedOrder(PDO $db, int $orderId): bool
{
    if ($orderId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT 1
           FROM orders
          WHERE order_id = :order_id
            AND delivery_rider_id IS NOT NULL
            AND order_status IN ('delivering', 'delivered')
          LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    return $stmt->fetchColumn() !== false;
}

/**
 * True when the order is in a state that still permits messages to
 * the kitchen. Cancelled and refunded orders are closed: the kitchen
 * has no reason to keep talking to the customer and the customer has
 * no reason to keep talking to the kitchen. Everything else — from
 * 'pending' through 'delivered' — is open for kitchen messaging.
 *
 * @param PDO $db
 * @param int $orderId
 * @return bool
 */
function orderAllowsKitchenMessaging(PDO $db, int $orderId): bool
{
    if ($orderId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT order_status
           FROM orders
          WHERE order_id = :order_id
          LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        return false;
    }

    return !in_array((string)$status, ['cancelled', 'refunded'], true);
}

/* ---------------------------------------------------------------
 * MESSAGES — FULL LOAD
 * --------------------------------------------------------------- */

/**
 * Fetch messages for an order, filtered by recipient channel.
 *
 * The customer can talk to the restaurant and to the rider. Each
 * conversation is scoped to the customer + one counterparty.
 *
 * Uses distinct placeholder names for the same value because native
 * PDO prepares reject a repeated named placeholder.
 *
 * This function returns the full conversation and is only used for
 * the initial load when the chat modal is first opened. Subsequent
 * polls use getOrderMessagesSince().
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $customerId
 * @param string $recipientType 'restaurant_account' or 'delivery_rider'
 * @return array<int, array<string, mixed>>
 */
function getOrderMessages(
    PDO $db,
    int $orderId,
    int $customerId,
    string $recipientType
): array {
    $stmt = $db->prepare(
        "SELECT
            m.message_id,
            m.sender_type,
            m.sender_id,
            m.recipient_type,
            m.recipient_id,
            m.message_type,
            m.content,
            m.is_read,
            m.created_at
         FROM message m
         WHERE m.order_id = :order_id
           AND (
                (m.sender_type = 'customer' AND m.recipient_type = :channel_a)
             OR (m.sender_type = :channel_b AND m.recipient_type = 'customer')
           )
         ORDER BY m.created_at ASC, m.message_id ASC"
    );
    $stmt->execute([
        ':order_id'  => $orderId,
        ':channel_a' => $recipientType,
        ':channel_b' => $recipientType,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------------------------------------------------------------
 * MESSAGES — DELTA FETCH
 * --------------------------------------------------------------- */

/**
 * Fetch only the messages for a channel whose message_id is strictly
 * greater than $sinceId.
 *
 * This is the polling path. The client sends the highest message_id
 * it already holds; the handler returns only rows that came after
 * it. An idle conversation with no new messages returns an empty
 * array after a single indexed lookup on message_id — no GROUP BY,
 * no JOIN, no full-table scan. That is what keeps the 5-second poll
 * cheap enough to run while the modal is open without the tab
 * competing with the rest of the page for query time.
 *
 * When $sinceId is 0 the function returns the whole conversation,
 * which is why the initial fetch can also route through this
 * function and get a full list in one call. The two code paths
 * share the same SELECT shape so message JSON is identical either
 * way.
 *
 * Distinct placeholder names because native PDO prepares reject a
 * repeated named placeholder.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $customerId
 * @param string $recipientType
 * @param int $sinceId  Last message_id the client already holds.
 * @return array<int, array<string, mixed>>
 */
function getOrderMessagesSince(
    PDO $db,
    int $orderId,
    int $customerId,
    string $recipientType,
    int $sinceId
): array {
    $stmt = $db->prepare(
        "SELECT
            m.message_id,
            m.sender_type,
            m.sender_id,
            m.recipient_type,
            m.recipient_id,
            m.message_type,
            m.content,
            m.is_read,
            m.created_at
         FROM message m
         WHERE m.order_id = :order_id
           AND m.message_id > :since_id
           AND (
                (m.sender_type = 'customer' AND m.recipient_type = :channel_a)
             OR (m.sender_type = :channel_b AND m.recipient_type = 'customer')
           )
         ORDER BY m.message_id ASC"
    );
    $stmt->execute([
        ':order_id'  => $orderId,
        ':since_id'  => $sinceId,
        ':channel_a' => $recipientType,
        ':channel_b' => $recipientType,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------------------------------------------------------------
 * MESSAGES — WRITES AND READS
 * --------------------------------------------------------------- */

/**
 * Insert a message from the customer to a counterparty.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $customerId
 * @param string $recipientType
 * @param int $recipientId
 * @param string $content
 * @return int New message ID
 */
function createOrderMessage(
    PDO $db,
    int $orderId,
    int $customerId,
    string $recipientType,
    int $recipientId,
    string $content
): int {
    $stmt = $db->prepare(
        "INSERT INTO message
            (order_id, sender_type, sender_id, recipient_type, recipient_id,
             message_type, content, is_read, created_at)
         VALUES
            (:order_id, 'customer', :sender_id, :recipient_type, :recipient_id,
             'text', :content, 0, NOW())"
    );
    $stmt->execute([
        ':order_id'       => $orderId,
        ':sender_id'      => $customerId,
        ':recipient_type' => $recipientType,
        ':recipient_id'   => $recipientId,
        ':content'        => $content,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Fetch a single message by ID, scoped to the order.
 *
 * Used by the send path so the appended node on the client matches
 * exactly what a subsequent delta fetch would have returned.
 *
 * @param PDO $db
 * @param int $messageId
 * @param int $orderId
 * @return array<string, mixed>|false
 */
function getMessageById(PDO $db, int $messageId, int $orderId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            message_id,
            sender_type,
            sender_id,
            recipient_type,
            recipient_id,
            message_type,
            content,
            is_read,
            created_at
         FROM message
         WHERE message_id = :message_id
           AND order_id = :order_id
         LIMIT 1"
    );
    $stmt->execute([
        ':message_id' => $messageId,
        ':order_id'   => $orderId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Mark unread messages from a counterparty as read for this customer.
 *
 * @param PDO $db
 * @param int $orderId
 * @param string $recipientType
 * @return void
 */
function markOrderMessagesRead(PDO $db, int $orderId, string $recipientType): void
{
    $stmt = $db->prepare(
        "UPDATE message
            SET is_read = 1
          WHERE order_id = :order_id
            AND sender_type = :sender_type
            AND recipient_type = 'customer'
            AND is_read = 0"
    );
    $stmt->execute([
        ':order_id'    => $orderId,
        ':sender_type' => $recipientType,
    ]);
}

/**
 * Count unread messages for a given channel.
 *
 * @param PDO $db
 * @param int $orderId
 * @param string $recipientType
 * @return int
 */
function countUnreadOrderMessages(PDO $db, int $orderId, string $recipientType): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*)
           FROM message
          WHERE order_id = :order_id
            AND sender_type = :sender_type
            AND recipient_type = 'customer'
            AND is_read = 0"
    );
    $stmt->execute([
        ':order_id'    => $orderId,
        ':sender_type' => $recipientType,
    ]);
    return (int)$stmt->fetchColumn();
}

/* ---------------------------------------------------------------
 * STATUS TIMELINE (pure — no DB access)
 * --------------------------------------------------------------- */

/**
 * Ordered list of tracking steps for the progress bar.
 *
 * @return array<int, array{key:string, label:string, description:string}>
 */
function getTrackingTimelineSteps(): array
{
    return [
        [
            'key'         => 'pending',
            'label'       => 'Order Placed',
            'description' => 'We received your order and sent it to the restaurant.',
        ],
        [
            'key'         => 'preparing',
            'label'       => 'Preparing',
            'description' => 'The kitchen is preparing your food.',
        ],
        [
            'key'         => 'rider_pending',
            'label'       => 'Rider Assigned',
            'description' => 'A rider is on the way to pick up your order.',
        ],
        [
            'key'         => 'delivering',
            'label'       => 'On the Way',
            'description' => 'Your order is on the way to your address.',
        ],
        [
            'key'         => 'delivered',
            'label'       => 'Delivered',
            'description' => 'Your order has been delivered. Enjoy your meal!',
        ],
    ];
}

/**
 * Return the visual metadata for an order status.
 *
 * @param string $status
 * @return array{label:string, badge:string, icon:string, description:string}
 */
function getTrackingStatusMeta(string $status): array
{
    return match ($status) {
        'pending' => [
            'label'       => 'Order Placed',
            'badge'       => 'badge-warning',
            'icon'        => 'time-fill.svg',
            'description' => 'Your order has been placed and is waiting to be accepted.',
        ],
        'preparing' => [
            'label'       => 'Preparing',
            'badge'       => 'badge-info',
            'icon'        => 'restaurant-fill.svg',
            'description' => 'The kitchen is preparing your food.',
        ],
        'rider_pending' => [
            'label'       => 'Rider Assigned',
            'badge'       => 'badge-primary',
            'icon'        => 'riding-fill.svg',
            'description' => 'A rider has been assigned and is heading to the restaurant.',
        ],
        'delivering' => [
            'label'       => 'On the Way',
            'badge'       => 'badge-primary',
            'icon'        => 'car-fill.svg',
            'description' => 'Your order is on the way to your address.',
        ],
        'delivered' => [
            'label'       => 'Delivered',
            'badge'       => 'badge-success',
            'icon'        => 'verified-fill.svg',
            'description' => 'Your order has been delivered. Enjoy your meal!',
        ],
        'cancelled' => [
            'label'       => 'Cancelled',
            'badge'       => 'badge-danger',
            'icon'        => 'close-circle-fill.svg',
            'description' => 'This order was cancelled.',
        ],
        'refunded' => [
            'label'       => 'Refunded',
            'badge'       => 'badge-secondary',
            'icon'        => 'coin-fill.svg',
            'description' => 'This order was cancelled and refunded.',
        ],
        default => [
            'label'       => ucfirst($status),
            'badge'       => 'badge-secondary',
            'icon'        => 'information-fill.svg',
            'description' => '',
        ],
    };
}

/**
 * Return the 0-based index of the current status in the timeline.
 * Returns -1 for terminal states that are not on the timeline.
 *
 * @param string $status
 * @return int
 */
function getTrackingStepIndex(string $status): int
{
    $steps = array_column(getTrackingTimelineSteps(), 'key');
    $index = array_search($status, $steps, true);
    return $index === false ? -1 : (int)$index;
}

/**
 * Format a display name for a rider row.
 *
 * @param array<string, mixed> $rider
 * @return string
 */
function getRiderDisplayName(array $rider): string
{
    $parts = array_filter([
        $rider['first_name'] ?? '',
        $rider['middle_name'] ?? '',
        $rider['last_name'] ?? '',
    ]);
    return trim(implode(' ', $parts)) ?: 'Rider';
}

/**
 * Format a display name for a restaurant row.
 *
 * @param array<string, mixed> $restaurant
 * @return string
 */
function getRestaurantDisplayName(array $restaurant): string
{
    return (string)($restaurant['restaurant_name'] ?? 'Restaurant');
}

/**
 * Format a message timestamp for display inside the chat panel.
 *
 * @param string $date
 * @return string
 */
function formatMessageTime(string $date): string
{
    $ts = strtotime($date);
    return $ts !== false ? date('g:i A', $ts) : $date;
}

/**
 * Format a full timestamp for the tracking header.
 *
 * @param string $date
 * @return string
 */
function formatTrackingTimestamp(string $date): string
{
    $ts = strtotime($date);
    return $ts !== false ? date('M d, Y • g:i A', $ts) : $date;
}