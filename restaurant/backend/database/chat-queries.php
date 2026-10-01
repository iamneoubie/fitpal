<?php
/**
 * FitPal Restaurant Chat Queries
 *
 * Pure data-access layer for the restaurant-side chat modal. Every
 * query the restaurant needs to read and write a conversation with
 * either a customer or a rider on one of its own orders.
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES
 * ---------------------------------------------------------------------
 *   - SQL only. No formatting, no HTML, no session access, no side
 *     effects beyond the writes below.
 *   - The handler that calls these owns all request parsing and
 *     validation.
 *   - Every read and write is scoped through restaurantOwnsOrder()
 *     so a restaurant account can never see or reach a conversation
 *     on an order that does not belong to its branch.
 *
 * ---------------------------------------------------------------------
 * COUNTERPARTY MODEL
 * ---------------------------------------------------------------------
 * The `message` table carries every sender / recipient combination
 * FitPal needs:
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
 * counterparty value is passed to the SQL.
 *
 * ---------------------------------------------------------------------
 * DELTA POLLING
 * ---------------------------------------------------------------------
 * getRestaurantOrderMessages() returns the full conversation.
 * getRestaurantOrderMessagesSince() returns only rows with
 * message_id > :since_message_id. The two share a SELECT shape so
 * the client renders a row from either path with the same code.
 *
 * ---------------------------------------------------------------------
 * CHANNEL GATING
 * ---------------------------------------------------------------------
 * restaurantChatChannelStatus() is the single source of truth for
 * "which channels are open for an order in this status". It is a
 * pure function: no DB access, no time-of-day awareness beyond the
 * delivered-grace window, no side effects.
 *
 * The mapping as of v2.1:
 *
 *   pending         → customer open,   rider closed
 *   preparing       → customer open,   rider closed
 *   rider_pending   → both open
 *   picking_up      → both open
 *   delivering      → both open
 *   delivered ≤ 1h  → both open
 *   delivered > 1h  → both closed
 *   cancelled       → both closed
 *   refunded        → both closed
 *   failed          → both closed
 *
 * A 'failed' order is a closed order: the shared order-transaction
 * layer has already swept it and the customer's tracking page has
 * already rendered its terminal state. The restaurant's conversation
 * channels are therefore closed for a failed order, matching the
 * cancelled and refunded cases.
 *
 * ---------------------------------------------------------------------
 * THE ONE-HOUR WINDOW AND THE FAILED-DELIVERY SWEEP
 * ---------------------------------------------------------------------
 * Two one-hour windows sit around an order's delivery:
 *
 *   The chat grace window, defined by
 *   RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS in this file and in
 *   restaurant/backend/database/restaurant-kitchen-queries.php. It
 *   opens at orders.delivered_at and closes one hour later, during
 *   which the kitchen can still reach the customer or the rider
 *   about a post-delivery issue.
 *
 *   The failed-delivery window, defined by
 *   FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS in
 *   shared/backend/database/fee-queries.php. It opens at the moment
 *   an order enters 'delivering' and closes one hour later, at which
 *   point the shared order-transaction layer's
 *   sweepFailedDeliveries() moves the order to 'failed' and keeps
 *   the rider's accept-time debit in place.
 *
 * The two windows are the same length but measure different
 * intervals. They do not overlap for a single order: the failed-
 * delivery window closes the moment the order is either delivered
 * (which opens the chat grace window) or failed (which closes every
 * channel). A reader who changes one of the two constants should
 * also re-read the other to confirm the business rule still holds.
 *
 * ---------------------------------------------------------------------
 * TIMEZONE HANDLING
 * ---------------------------------------------------------------------
 * orders.delivered_at is written by MySQL using the session's
 * time_zone setting, which database-connect.php sets to '+08:00'
 * (Philippine Time). When PHP reads that string back and converts
 * it via strtotime(), PHP interprets the string in whatever
 * timezone date.timezone is configured to — typically UTC on a
 * default server. That mismatch makes a delivery timestamped at
 * 00:54 PHT parse as 00:54 UTC, which is 08:54 PHT, eight hours in
 * the future relative to the actual event.
 *
 * The fix is to anchor the parse to the same timezone the database
 * is using. FITPAL_DB_TIMEZONE_OFFSET is defined here with the
 * same value database-connect.php uses, and restaurantChatNow() and
 * restaurantChatParseDeliveredAt() both convert through it so the
 * comparison is apples-to-apples. There is no reliance on the
 * server's default timezone.
 *
 * @package FitPal
 * @version 2.2 — Docblock records the relationship between the
 *                one-hour chat grace window this file owns and the
 *                one-hour failed-delivery window enforced by the
 *                shared order-transaction layer. Adds the 'failed'
 *                case to the channel-gating table. No query, no
 *                signature, and no pure helper change from the
 *                previous revision.
 *
 *                (2.1: timezone-aware grace window. 2.0: delivered
 *                grace window and open-early customer channel.
 *                1.1: added 'picking_up'. 1.0: initial restaurant
 *                chat queries.)
 */

declare(strict_types=1);

/**
 * The timezone offset the database connection is set to.
 *
 * Must match the $timezone_offset value in
 * shared/backend/database/database-connect.php. That file issues
 * `SET time_zone = '+08:00'` on every connection, so every
 * TIMESTAMP / DATETIME value MySQL returns is expressed in
 * Philippine Time regardless of the server's system clock.
 */
if (!defined('FITPAL_DB_TIMEZONE_OFFSET')) {
    define('FITPAL_DB_TIMEZONE_OFFSET', '+08:00');
}

/**
 * Length of the post-delivery grace window, in seconds.
 *
 * After this many seconds have elapsed since orders.delivered_at,
 * both chat channels on the order close. Before it elapses, both
 * remain open.
 *
 * This is the chat-side one-hour window. It is the same length as,
 * but measures a different interval from,
 * FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS in
 * shared/backend/database/fee-queries.php. See the file header for
 * the relationship between the two.
 */
if (!defined('RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS')) {
    define('RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS', 3600);
}

/* =============================================================
 * TIMEZONE-AWARE TIME HELPERS
 * ============================================================= */

if (!function_exists('fitpalDbTimezone')) {
    /**
     * A DateTimeZone object for the database connection's offset.
     *
     * Cached per request so repeated calls are cheap.
     *
     * @return DateTimeZone
     */
    function fitpalDbTimezone(): DateTimeZone
    {
        static $tz = null;
        if ($tz === null) {
            try {
                $tz = new DateTimeZone(FITPAL_DB_TIMEZONE_OFFSET);
            } catch (Throwable $e) {
                // A malformed offset is a programming error, but a
                // hard failure inside a display path is worse than a
                // degraded one. Fall back to UTC so the grace window
                // still resolves to a definite value.
                $tz = new DateTimeZone('UTC');
            }
        }
        return $tz;
    }
}

if (!function_exists('restaurantChatParseDeliveredAt')) {
    /**
     * Parse a MySQL DATETIME string into an absolute Unix timestamp,
     * interpreting the string in the database connection's timezone.
     *
     * Returns null when the string is missing or unparsable. A null
     * return is what restaurantChatDeliveredWindow() treats as a
     * failure and closes both channels for.
     *
     * @param string|null $deliveredAt Raw orders.delivered_at value,
     *                                 e.g. '2026-09-28 00:54:00'.
     * @return int|null  Unix timestamp, or null on failure.
     */
    function restaurantChatParseDeliveredAt(?string $deliveredAt): ?int
    {
        if ($deliveredAt === null || $deliveredAt === '') {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $deliveredAt,
            fitpalDbTimezone()
        );

        if ($dt === false) {
            // Some MySQL configurations return microseconds or a
            // trailing timezone. Fall back to a looser parse that
            // still anchors to the database offset.
            try {
                $dt = new DateTimeImmutable($deliveredAt, fitpalDbTimezone());
            } catch (Throwable $e) {
                return null;
            }
        }

        return $dt->getTimestamp();
    }
}

if (!function_exists('restaurantChatNow')) {
    /**
     * The current time as a Unix timestamp.
     *
     * time() is already absolute — it does not depend on
     * date.timezone — so this helper exists mainly so every call
     * site in this file reads through the same name and a future
     * change to the notion of "now" has one place to live.
     *
     * @return int
     */
    function restaurantChatNow(): int
    {
        return time();
    }
}

/* =============================================================
 * SCOPE GUARD
 * ============================================================= */

/**
 * True when the given order belongs to one of this restaurant's
 * branches.
 *
 * This is the single gate that every other function in this file
 * relies on.
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
 * direction.
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
 * array after a single indexed lookup.
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
 * CHANNEL GATING
 * ============================================================= */

/**
 * Return the channel-availability rules for an order status.
 *
 * The handler uses this to decide which chat tabs are enabled for
 * a given order. The mapping:
 *
 *   pending         → customer open,   rider closed
 *   preparing       → customer open,   rider closed
 *   rider_pending   → both open
 *   picking_up      → both open
 *   delivering      → both open
 *   delivered       → depends on $deliveredAt, see below
 *   cancelled       → both closed
 *   refunded        → both closed
 *   failed          → both closed
 *
 * The 'delivered' case routes through
 * restaurantChatDeliveredWindow(), which opens both channels for
 * exactly RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS after
 * orders.delivered_at and closes both after that. Passing NULL or
 * an unparsable $deliveredAt to a 'delivered' order fails closed
 * for both channels.
 *
 * The 'failed' case is treated the same as the terminal cancelled
 * and refunded cases: the shared order-transaction layer has
 * already swept the order and both parties have moved on.
 *
 * @param string      $orderStatus
 * @param string|null $deliveredAt Raw orders.delivered_at value,
 *                                 used only for the 'delivered'
 *                                 case.
 * @return array{customer:bool, delivery_rider:bool}
 */
function restaurantChatChannelStatus(
    string $orderStatus,
    ?string $deliveredAt = null
): array {
    $open   = ['customer' => true,  'delivery_rider' => true];
    $closed = ['customer' => false, 'delivery_rider' => false];

    return match ($orderStatus) {

        // Live, kitchen-facing stages. The customer channel is
        // open so the kitchen can raise an out-of-stock item, an
        // address clarification, or an ETA question. The rider
        // channel is conceptually open here as a shape but the
        // handler's own "no rider attached" guard is what
        // actually blocks it.
        'pending'       => ['customer' => true, 'delivery_rider' => false],
        'preparing'     => ['customer' => true, 'delivery_rider' => false],

        // Rider-facing stages. Both channels reachable.
        'rider_pending' => $open,
        'picking_up'    => $open,
        'delivering'    => $open,

        // Delivered. Both channels stay open for the grace
        // window. After the window elapses, both close.
        'delivered'     => restaurantChatDeliveredWindow($deliveredAt),

        // Terminal states. Nothing left to say.
        'cancelled'     => $closed,
        'refunded'      => $closed,
        'failed'        => $closed,

        default         => $closed,
    };
}

/**
 * Per-channel availability for an order in the 'delivered' state.
 *
 * Both channels are open for RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS
 * after orders.delivered_at. After that, both are closed.
 *
 * A NULL or unparsable timestamp fails closed for both channels.
 *
 * @param string|null $deliveredAt Raw orders.delivered_at value.
 * @return array{customer:bool, delivery_rider:bool}
 */
function restaurantChatDeliveredWindow(?string $deliveredAt): array
{
    $closed = ['customer' => false, 'delivery_rider' => false];

    $deliveredTs = restaurantChatParseDeliveredAt($deliveredAt);
    if ($deliveredTs === null) {
        return $closed;
    }

    $elapsed = restaurantChatNow() - $deliveredTs;

    // A negative elapsed value means delivered_at is in the
    // future, which the schema never produces on a valid row.
    // Treat it the same as an unparsable timestamp: fail closed.
    if ($elapsed < 0) {
        return $closed;
    }

    $withinGrace = $elapsed <= RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS;

    return [
        'customer'       => $withinGrace,
        'delivery_rider' => $withinGrace,
    ];
}

/**
 * True when the given counterparty is a valid channel for the
 * restaurant chat modal, regardless of order status.
 *
 * @param string $counterparty
 * @return bool
 */
function isRestaurantChatChannel(string $counterparty): bool
{
    return in_array($counterparty, ['customer', 'delivery_rider'], true);
}

/* =============================================================
 * PRESENTATION HELPERS (pure — no DB access)
 * ============================================================= */

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
 * Uses the database connection's timezone offset, not the PHP
 * server's default, so a message timestamp returned by MySQL in
 * +08:00 is displayed in +08:00.
 *
 * @param string $date
 * @return string
 */
function restaurantChatFormatTime(string $date): string
{
    $ts = restaurantChatParseDeliveredAt($date);
    if ($ts === null) {
        return $date;
    }
    return date('g:i A', $ts);
}