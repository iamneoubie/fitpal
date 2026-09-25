<?php
/**
 * FitPal Restaurant Chat Queries
 *
 * Pure data-access layer for the restaurant-side chat modal. Every
 * query the restaurant needs to read and write a conversation with
 * either a customer or a rider on one of its own orders.
 *
 * Scope rules
 * -----------
 *   - SQL only. No formatting, no HTML, no session access, no
 *     side effects beyond the writes below.
 *   - The handler that calls these owns all request parsing and
 *     validation.
 *   - Every read and write is scoped through restaurantOwnsOrder()
 *     so a restaurant account can never see or reach a conversation
 *     on an order that does not belong to its branch.
 *
 * Counterparty model
 * ------------------
 * The `message` table already carries every sender / recipient
 * combination FitPal needs:
 *
 *   sender_type   ∈ {customer, restaurant_account, delivery_rider,
 *                    administrator, system}
 *   recipient_type ∈ {customer, restaurant_account, delivery_rider,
 *                    administrator, all}
 *
 * For the restaurant modal, the two channels are:
 *
 *   - 'customer'          → restaurant_account ↔ customer
 *   - 'delivery_rider'    → restaurant_account ↔ delivery_rider
 *
 * Both channels share the same query shape and differ only in which
 * counterparty value is passed to the SQL. That keeps the two tabs
 * on the modal backed by one set of functions.
 *
 * Delta polling
 * -------------
 * getRestaurantOrderMessages() returns the full conversation.
 * getRestaurantOrderMessagesSince() returns only rows with
 * message_id > :since_message_id. The two share a SELECT shape so
 * the client renders a row from either path with the same code.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

/* =============================================================
 * SCOPE GUARD
 * ============================================================= */

/**
 * True when the given order belongs to one of this restaurant's
 * branches.
 *
 * This is the single gate that every other function in this file
 * relies on. The handler calls it once at the top of each action;
 * the individual query functions trust the caller to have verified
 * ownership first and do not re-check.
 *
 * Returns false if the order does not exist or belongs to another
 * restaurant.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $restaurantId
 * @return bool
 */
function restaurantOwnsOrder(PDO $db, int $orderId, int $restaurantId): bool
{
    if ($orderId <= 0 || $restaurantId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT 1
           FROM orders o
           JOIN queue_item qi ON qi.order_id = o.order_id
           JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
          WHERE o.order_id = :order_id
            AND rb.restaurant_id = :restaurant_id
          LIMIT 1"
    );
    $stmt->execute([
        ':order_id'      => $orderId,
        ':restaurant_id' => $restaurantId,
    ]);
    return $stmt->fetchColumn() !== false;
}

/* =============================================================
 * CONVERSATION LIST
 * ============================================================= */

/**
 * Fetch a full conversation between this restaurant and one
 * counterparty on a given order.
 *
 * The $counterparty argument is the OTHER party: 'customer' or
 * 'delivery_rider'. The two sides of every message row in the
 * result are (restaurant_account, counterparty) in either
 * direction — the restaurant as sender and the counterparty as
 * recipient, or vice versa.
 *
 * Uses distinct placeholder names for the same value because
 * native PDO prepares reject a repeated named placeholder.
 *
 * @param PDO    $db
 * @param int    $orderId
 * @param string $counterparty 'customer' | 'delivery_rider'
 * @return array<int, array<string, mixed>>
 */
function getRestaurantOrderMessages(
    PDO $db,
    int $orderId,
    string $counterparty
): array {
    $stmt = $db->prepare(
        "SELECT
            m.message_id,
            m.sender_type,
            m.sender_id,
            m.recipient_type,
            m.recipient_id,
            m.content,
            m.created_at,
            m.is_read
         FROM message m
         WHERE m.order_id = :order_id
           AND (
                (m.sender_type = 'restaurant_account' AND m.recipient_type = :cp_a)
             OR (m.sender_type = :cp_b AND m.recipient_type = 'restaurant_account')
           )
         ORDER BY m.message_id ASC
         LIMIT 200"
    );
    $stmt->execute([
        ':order_id' => $orderId,
        ':cp_a'     => $counterparty,
        ':cp_b'     => $counterparty,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch only the messages whose message_id is strictly greater than
 * the client's last known id.
 *
 * This is the polling path. An idle conversation returns an empty
 * array after a single indexed lookup. The SELECT shape is
 * identical to getRestaurantOrderMessages() so a row fetched via
 * either path renders with the same JSON.
 *
 * @param PDO    $db
 * @param int    $orderId
 * @param string $counterparty
 * @param int    $sinceMessageId
 * @return array<int, array<string, mixed>>
 */
function getRestaurantOrderMessagesSince(
    PDO $db,
    int $orderId,
    string $counterparty,
    int $sinceMessageId
): array {
    $stmt = $db->prepare(
        "SELECT
            m.message_id,
            m.sender_type,
            m.sender_id,
            m.recipient_type,
            m.recipient_id,
            m.content,
            m.created_at,
            m.is_read
         FROM message m
         WHERE m.order_id = :order_id
           AND m.message_id > :since_message_id
           AND (
                (m.sender_type = 'restaurant_account' AND m.recipient_type = :cp_a)
             OR (m.sender_type = :cp_b AND m.recipient_type = 'restaurant_account')
           )
         ORDER BY m.message_id ASC"
    );
    $stmt->execute([
        ':order_id'         => $orderId,
        ':since_message_id' => $sinceMessageId,
        ':cp_a'             => $counterparty,
        ':cp_b'             => $counterparty,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The highest message_id for the two-way restaurant ↔ counterparty
 * conversation on an order, or 0 if there is none.
 *
 * Used to seed the client's delta cursor on the first load so the
 * panel does not need an extra round trip.
 *
 * @param PDO    $db
 * @param int    $orderId
 * @param string $counterparty
 * @return int
 */
function getRestaurantMaxMessageId(PDO $db, int $orderId, string $counterparty): int
{
    $stmt = $db->prepare(
        "SELECT COALESCE(MAX(m.message_id), 0)
           FROM message m
          WHERE m.order_id = :order_id
            AND (
                 (m.sender_type = 'restaurant_account' AND m.recipient_type = :cp_a)
              OR (m.sender_type = :cp_b AND m.recipient_type = 'restaurant_account')
            )"
    );
    $stmt->execute([
        ':order_id' => $orderId,
        ':cp_a'     => $counterparty,
        ':cp_b'     => $counterparty,
    ]);
    return (int)$stmt->fetchColumn();
}

/* =============================================================
 * COUNTERPARTY RESOLUTION
 * ============================================================= */

/**
 * Resolve the counterparty id for a channel on an order.
 *
 *   'customer'       → orders.customer_id
 *   'delivery_rider' → orders.delivery_rider_id (0 if unassigned)
 *
 * Returns 0 when the counterparty cannot be resolved. The modal
 * hides the tab for that channel in that case so the restaurant
 * never sends into the void.
 *
 * @param PDO    $db
 * @param int    $orderId
 * @param string $counterparty
 * @return int
 */
function resolveRestaurantChatRecipient(PDO $db, int $orderId, string $counterparty): int
{
    if ($orderId <= 0) {
        return 0;
    }

    if ($counterparty === 'customer') {
        $stmt = $db->prepare(
            "SELECT customer_id FROM orders WHERE order_id = :order_id LIMIT 1"
        );
        $stmt->execute([':order_id' => $orderId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    if ($counterparty === 'delivery_rider') {
        $stmt = $db->prepare(
            "SELECT delivery_rider_id FROM orders WHERE order_id = :order_id LIMIT 1"
        );
        $stmt->execute([':order_id' => $orderId]);
        $riderId = $stmt->fetchColumn();
        return $riderId === null ? 0 : (int)$riderId;
    }

    return 0;
}

/**
 * Return display metadata for one conversation channel on an order.
 *
 * The restaurant modal uses these fields to render the header of
 * each tab (name, sub label, avatar initial) without needing to
 * fetch the customer or rider separately.
 *
 * Returns an array with empty strings when the counterparty is
 * unavailable.
 *
 * @param PDO    $db
 * @param int    $orderId
 * @param string $counterparty
 * @return array{id:int, name:string, sub:string, initial:string}
 */
function getRestaurantChatPartyMeta(PDO $db, int $orderId, string $counterparty): array
{
    $empty = ['id' => 0, 'name' => '', 'sub' => '', 'initial' => ''];

    if ($orderId <= 0) {
        return $empty;
    }

    if ($counterparty === 'customer') {
        $stmt = $db->prepare(
            "SELECT
                c.customer_id,
                c.first_name,
                c.last_name,
                c.contact_number
             FROM orders o
             JOIN customer c ON c.customer_id = o.customer_id
             WHERE o.order_id = :order_id
             LIMIT 1"
        );
        $stmt->execute([':order_id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $empty;
        }
        $name    = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
        $initial = strtoupper(substr((string)$row['first_name'], 0, 1));
        return [
            'id'      => (int)$row['customer_id'],
            'name'    => $name !== '' ? $name : 'Customer',
            'sub'     => (string)($row['contact_number'] ?? ''),
            'initial' => $initial !== '' ? $initial : 'C',
        ];
    }

    if ($counterparty === 'delivery_rider') {
        $stmt = $db->prepare(
            "SELECT
                dr.delivery_rider_id,
                dr.first_name,
                dr.last_name,
                dr.contact_number,
                drp.vehicle_type,
                drp.vehicle_plate
             FROM orders o
             JOIN delivery_rider dr ON dr.delivery_rider_id = o.delivery_rider_id
             LEFT JOIN delivery_rider_profile drp
                    ON drp.delivery_rider_id = dr.delivery_rider_id
             WHERE o.order_id = :order_id
             LIMIT 1"
        );
        $stmt->execute([':order_id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $empty;
        }
        $name    = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
        $initial = strtoupper(substr((string)$row['first_name'], 0, 1));
        $vehicle = ucfirst((string)($row['vehicle_type'] ?? ''));
        $plate   = (string)($row['vehicle_plate'] ?? '');
        $sub     = $vehicle !== ''
            ? $vehicle . ($plate !== '' ? ' • ' . $plate : '')
            : (string)($row['contact_number'] ?? '');
        return [
            'id'      => (int)$row['delivery_rider_id'],
            'name'    => $name !== '' ? $name : 'Rider',
            'sub'     => $sub,
            'initial' => $initial !== '' ? $initial : 'R',
        ];
    }

    return $empty;
}

/* =============================================================
 * WRITES
 * ============================================================= */

/**
 * Insert a message from this restaurant account to a counterparty.
 *
 * @param PDO    $db
 * @param int    $orderId
 * @param int    $senderAccountId
 * @param string $counterparty 'customer' | 'delivery_rider'
 * @param int    $recipientId
 * @param string $content
 * @return int  New message_id
 */
function createRestaurantMessage(
    PDO $db,
    int $orderId,
    int $senderAccountId,
    string $counterparty,
    int $recipientId,
    string $content
): int {
    $stmt = $db->prepare(
        "INSERT INTO message
            (order_id, sender_type, sender_id, recipient_type, recipient_id,
             message_type, content, is_read, created_at)
         VALUES
            (:order_id, 'restaurant_account', :sender_id, :recipient_type, :recipient_id,
             'text', :content, 0, NOW())"
    );
    $stmt->execute([
        ':order_id'       => $orderId,
        ':sender_id'      => $senderAccountId,
        ':recipient_type' => $counterparty,
        ':recipient_id'   => $recipientId,
        ':content'        => $content,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Fetch a single message by id, scoped to the given order.
 *
 * Used by the send path so the appended node on the client is
 * byte-identical to what a delta fetch would return.
 *
 * @param PDO $db
 * @param int $messageId
 * @param int $orderId
 * @return array<string, mixed>|false
 */
function getRestaurantMessageById(PDO $db, int $messageId, int $orderId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            message_id,
            sender_type,
            sender_id,
            recipient_type,
            recipient_id,
            content,
            created_at,
            is_read
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
 * Mark all unread messages from a counterparty as read for this
 * restaurant account.
 *
 * @param PDO    $db
 * @param int    $orderId
 * @param string $counterparty
 * @return void
 */
function markRestaurantMessagesRead(PDO $db, int $orderId, string $counterparty): void
{
    $stmt = $db->prepare(
        "UPDATE message
            SET is_read = 1
          WHERE order_id = :order_id
            AND sender_type = :sender_type
            AND recipient_type = 'restaurant_account'
            AND is_read = 0"
    );
    $stmt->execute([
        ':order_id'    => $orderId,
        ':sender_type' => $counterparty,
    ]);
}

/**
 * Count unread messages from a counterparty on a given order.
 *
 * @param PDO    $db
 * @param int    $orderId
 * @param string $counterparty
 * @return int
 */
function countRestaurantUnread(PDO $db, int $orderId, string $counterparty): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*)
           FROM message
          WHERE order_id = :order_id
            AND sender_type = :sender_type
            AND recipient_type = 'restaurant_account'
            AND is_read = 0"
    );
    $stmt->execute([
        ':order_id'    => $orderId,
        ':sender_type' => $counterparty,
    ]);
    return (int)$stmt->fetchColumn();
}

/* =============================================================
 * PRESENTATION HELPERS (pure — no DB access)
 * ============================================================= */

/**
 * True when the given counterparty is a valid channel for the
 * restaurant chat modal.
 *
 * @param string $counterparty
 * @return bool
 */
function isRestaurantChatChannel(string $counterparty): bool
{
    return in_array($counterparty, ['customer', 'delivery_rider'], true);
}

/**
 * Display label for a sender_type value, as shown in the chat body.
 *
 * @param string $senderType
 * @return string
 */
function restaurantChatSenderLabel(string $senderType): string
{
    return match ($senderType) {
        'customer'           => 'Customer',
        'delivery_rider'     => 'Rider',
        'restaurant_account' => 'You',
        'administrator'      => 'Support',
        'system'             => 'System',
        default              => 'User',
    };
}

/**
 * Format a message timestamp as "g:i A".
 *
 * @param string $date
 * @return string
 */
function restaurantChatFormatTime(string $date): string
{
    $ts = strtotime($date);
    return $ts !== false ? date('g:i A', $ts) : $date;
}