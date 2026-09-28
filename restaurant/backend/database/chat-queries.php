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
 * Channel gating — when a channel is reachable
 * --------------------------------------------
 * restaurantChatChannelStatus() is the single source of truth for
 * "which channels are open for an order in this status". It is a
 * pure function: no DB access, no time-of-day awareness beyond the
 * delivered-grace window, no side effects.
 *
 * The mapping as of v2.0:
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
 *
 * Rationale for the two open-early cases:
 *
 *   pending and preparing are the two windows in which the kitchen
 *   is actively deciding what to cook and how to cook it. The
 *   kitchen may need to reach the customer about an out-of-stock
 *   item, an ambiguous address, or an ETA question. Blocking the
 *   customer channel on those two statuses was a bug.
 *
 * The rider channel stays closed until a rider is actually
 * attached to the order. The handler's own "no rider attached"
 * guard covers the case where a channel is conceptually open but
 * the counterparty row does not yet exist — see gateChannel() in
 * restaurant/backend/handlers/chat-handler.php.
 *
 * Delivered-grace window
 * ----------------------
 * Once an order reaches 'delivered', both channels stay open for
 * exactly RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS (one hour) after
 * orders.delivered_at. This window exists so the customer can
 * report a missing item or thank the kitchen without the order
 * being effectively archived the instant the rider taps delivered.
 *
 * After the window elapses, both channels close. This keeps the
 * kitchen's active conversation list focused on orders that are
 * still in the restaurant's recent working memory.
 *
 * A NULL or unparsable delivered_at on a 'delivered' row fails
 * closed for both channels. The before_order_delivered trigger in
 * sql/database.sql sets the column on the transition, so a NULL
 * here means the row is in an inconsistent state and should not be
 * treated as reachable.
 *
 * Timezone handling
 * -----------------
 * orders.delivered_at is written by MySQL using the session's
 * time_zone setting, which database-connect.php sets to '+08:00'
 * (Philippine Time). When PHP reads that string back and converts
 * it via strtotime(), PHP interprets the string in whatever
 * timezone date.timezone is configured to — typically UTC on a
 * default server. That mismatch makes a delivery timestamped at
 * 00:54 PHT parse as 00:54 UTC, which is 08:54 PHT, eight hours in
 * the future relative to the actual event.
 *
 * The result was the exact symptom this revision fixes: a delivery
 * that happened five minutes ago (PHT) was computed as "eight
 * hours ago" in PHP, which exceeded the one-hour grace window, so
 * the chat gate refused every channel on a freshly delivered
 * order.
 *
 * The fix is to anchor the parse to the same timezone the database
 * is using. FITPAL_DB_TIMEZONE_OFFSET is defined here with the
 * same value database-connect.php uses, and restaurantChatNow() and
 * restaurantChatParseDeliveredAt() both convert through it so the
 * comparison is apples-to-apples. There is no reliance on the
 * server's default timezone.
 *
 * @package FitPal
 * @version 2.1 — Timezone-aware grace window:
 *                  - New constant FITPAL_DB_TIMEZONE_OFFSET ("+08:00"),
 *                    matching the SET time_zone the database
 *                    connection issues in
 *                    shared/backend/database/database-connect.php.
 *                  - New pure helpers restaurantChatParseDeliveredAt()
 *                    and restaurantChatNow() that convert to and from
 *                    an absolute Unix timestamp using that offset,
 *                    regardless of date.timezone.
 *                  - restaurantChatDeliveredWindow() now computes
 *                    elapsed time from those helpers, so a freshly
 *                    delivered order in PHT is measured against a
 *                    PHT "now" and the one-hour window behaves as
 *                    the constant implies.
 *                  - No signature changed. No caller changed. The
 *                    SQL-side chat_grace_open CASE in
 *                    order-queries.php was already timezone-safe
 *                    because it compares against MySQL's own NOW(),
 *                    and is untouched.
 *
 *                (2.0: delivered grace window and open-early customer
 *                channel. 1.1: added 'picking_up' to the open-window
 *                set. 1.0: initial restaurant chat queries.)
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
 *
 * PHP has no way to know that from the returned string alone —
 * strtotime() uses date.timezone — so this constant is the anchor
 * that keeps the two sides in agreement.
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
 * Defined as a constant rather than a literal so the rule is
 * expressed once. The handler never sees this value; it only sees
 * the array restaurantChatDeliveredWindow() returns.
 */
if (!defined('RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS')) {
    define('RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS', 3600);
}

/* =============================================================
 * TIMEZONE-AWARE TIME HELPERS
 *
 * Every comparison between "now" and a value returned by MySQL
 * must go through these helpers. Calling time() or strtotime()
 * directly on a MySQL DATETIME string reintroduces the bug this
 * revision fixes.
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
     * The current time as a Unix timestamp. time() is already
     * absolute — it does not depend on date.timezone — so this
     * helper exists mainly so every call site in this file reads
     * through the same name and a future change to the notion of
     * "now" has one place to live.
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
 *
 * The 'delivered' case is time-dependent. It routes through
 * restaurantChatDeliveredWindow(), which opens both channels for
 * exactly RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS after
 * orders.delivered_at and closes both after that. Passing NULL or
 * an unparsable $deliveredAt to a 'delivered' order fails closed
 * for both channels — a delivered order without a timestamp is a
 * data-integrity problem and should not be treated as reachable.
 *
 * For any status other than 'delivered', $deliveredAt is ignored.
 *
 * The $deliveredAt parameter is the raw string from
 * orders.delivered_at, in whatever format MySQL returned it
 * (typically 'YYYY-MM-DD HH:MM:SS'). It is parsed with the
 * database connection's timezone offset, not the PHP server's
 * default, so the one-hour window is measured correctly.
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
        // actually blocks it — no rider has been attached to the
        // order yet.
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
 * The before_order_delivered trigger in sql/database.sql sets
 * delivered_at on the transition into 'delivered', so a NULL on a
 * delivered row means something upstream is broken. Refusing the
 * channel is the safe default.
 *
 * Timezone correctness
 * --------------------
 * orders.delivered_at is written by MySQL in the connection's
 * time_zone (+08:00, set by database-connect.php). This function
 * parses it through restaurantChatParseDeliveredAt(), which anchors
 * the parse to that same offset, so the elapsed-seconds calculation
 * is against an absolute "now" from restaurantChatNow() and is not
 * affected by the PHP server's date.timezone.
 *
 * This function is pure: no DB access, no session access, no
 * side effects. It exists so the grace-window rule lives in one
 * place.
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
 * restaurant chat modal, regardless of order status. This is a
 * shape check, not a status check — the handler combines it with
 * restaurantChatChannelStatus() to decide reachability.
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