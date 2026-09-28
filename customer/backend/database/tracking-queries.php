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
 * Timezone handling
 * -----------------
 * Two independent facts about this project:
 *
 *   1. MySQL is connected with the session time_zone set to '+08:00'
 *      by shared/backend/database/database-connect.php, so every
 *      DATETIME value returned to PHP is a Philippine wall-clock
 *      string regardless of the server's system clock.
 *
 *   2. PHP's date.timezone is UTC on the deployed server. This means
 *      that when PHP's date() function formats a Unix timestamp
 *      without an explicit timezone, it renders in UTC — eight hours
 *      behind the Philippine wall-clock moment the timestamp
 *      actually represents.
 *
 * The parse side is already correct: customerChatParseTimestamp()
 * anchors a MySQL DATETIME string to '+08:00' so the resulting Unix
 * timestamp is the true absolute moment of the event.
 *
 * The display side must therefore render that timestamp back in
 * '+08:00' as well, or the customer sees a time shifted eight hours
 * behind the real moment. Every format helper in this file goes
 * through customerChatFormatTimestamp() so the round trip
 * string → timestamp → string is timezone-stable regardless of what
 * date.timezone happens to be on the server.
 *
 * No $_POST, no header(), no echo.
 *
 * @package FitPal
 * @version 2.3 — Fixes the display side of the timezone round trip:
 *                  - New helper customerChatFormatTimestamp() renders
 *                    an absolute Unix timestamp in the database
 *                    connection's timezone ('+08:00').
 *                  - formatTrackingTimestamp() and formatMessageTime()
 *                    now route through that helper instead of calling
 *                    date() directly, which was inheriting the PHP
 *                    server's date.timezone (UTC on the deployed
 *                    server) and printing every timestamp eight
 *                    hours behind the real moment.
 *                  - No query, no grace-window helper, and no
 *                    signature changed. The parse side of the
 *                    round trip (customerChatParseTimestamp) was
 *                    already correct and is untouched.
 *
 *                (2.2: timezone-correct grace window — parse side.
 *                2.1: customerOrderHasOpenChatWindow() wrapper. 2.0:
 *                'picking_up' support and 1-hour grace. 1.2: two
 *                chat-gating helpers and a delta fetch. 1.1:
 *                distinct placeholders in getOrderMessages.)
 */

declare(strict_types=1);

require_once __DIR__ . '/order-queries.php';

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
 *
 * Named with a customer- prefix so it can coexist with the
 * restaurant-side FITPAL_DB_TIMEZONE_OFFSET if a future page ever
 * requires both query layers at once.
 */
if (!defined('FITPAL_CUSTOMER_DB_TIMEZONE_OFFSET')) {
    define('FITPAL_CUSTOMER_DB_TIMEZONE_OFFSET', '+08:00');
}

/**
 * Grace window after delivery, in seconds.
 *
 * Both chat channels on the customer's order tracking page stay
 * reachable for this long after orders.delivered_at, matching the
 * restaurant-side restaurant_chat_delivered_window() window. After
 * the window elapses, both channels close.
 *
 * Must stay in sync with RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS
 * in restaurant/backend/database/chat-queries.php so the two roles
 * agree on when a delivered order's conversation ends.
 */
if (!defined('CUSTOMER_CHAT_DELIVERED_GRACE_SECONDS')) {
    define('CUSTOMER_CHAT_DELIVERED_GRACE_SECONDS', 3600);
}

/* =============================================================
 * TIMEZONE-AWARE TIME HELPERS
 *
 * Every comparison or render of a value returned by MySQL must go
 * through these helpers. Calling time(), strtotime(), or date()
 * directly on a MySQL DATETIME string reintroduces the bug this
 * revision fixes.
 * ============================================================= */

if (!function_exists('fitpalCustomerDbTimezone')) {
    /**
     * A DateTimeZone object for the database connection's offset.
     *
     * Cached per request so repeated calls are cheap.
     *
     * @return DateTimeZone
     */
    function fitpalCustomerDbTimezone(): DateTimeZone
    {
        static $tz = null;
        if ($tz === null) {
            try {
                $tz = new DateTimeZone(FITPAL_CUSTOMER_DB_TIMEZONE_OFFSET);
            } catch (Throwable $e) {
                // A malformed offset is a programming error, but a
                // hard failure inside a display path is worse than a
                // degraded one. Fall back to UTC so the grace window
                // and every format helper still resolve to a definite
                // value.
                $tz = new DateTimeZone('UTC');
            }
        }
        return $tz;
    }
}

if (!function_exists('customerChatParseTimestamp')) {
    /**
     * Parse a MySQL DATETIME string into an absolute Unix timestamp,
     * interpreting the string in the database connection's timezone.
     *
     * Returns null when the string is missing or unparsable. A null
     * return is what customerOrderDeliveredWithinGrace() treats as a
     * failure and closes the channel for.
     *
     * This is the PARSE side of the round trip. The matching render
     * side is customerChatFormatTimestamp(), which formats a Unix
     * timestamp back in the same offset. Both must be used together
     * so a MySQL DATETIME string survives the round trip unchanged
     * regardless of PHP's date.timezone setting.
     *
     * @param string|null $value  Raw MySQL DATETIME, e.g.
     *                            '2026-09-28 01:10:00'.
     * @return int|null  Unix timestamp, or null on failure.
     */
    function customerChatParseTimestamp(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $value,
            fitpalCustomerDbTimezone()
        );

        if ($dt === false) {
            // Some MySQL configurations return microseconds or a
            // trailing timezone. Fall back to a looser parse that
            // still anchors to the database offset.
            try {
                $dt = new DateTimeImmutable($value, fitpalCustomerDbTimezone());
            } catch (Throwable $e) {
                return null;
            }
        }

        return $dt->getTimestamp();
    }
}

if (!function_exists('customerChatFormatTimestamp')) {
    /**
     * Format an absolute Unix timestamp as a MySQL-shaped DATETIME
     * string expressed in the database connection's timezone.
     *
     * This is the RENDER side of the round trip and the fix for the
     * "sep 27 7:10 PM" bug. PHP's date() function defaults to the
     * server's date.timezone — UTC on this deployment — so a Unix
     * timestamp that correctly represents 2026-09-28 01:10 PHT
     * prints as 2026-09-27 17:10 UTC when handed to date() directly.
     *
     * Rendering through this helper instead converts the timestamp
     * into the '+08:00' offset before formatting, so the printed
     * string matches the wall-clock moment the timestamp actually
     * represents, regardless of what date.timezone is set to on the
     * server.
     *
     * @param int    $timestamp  Unix timestamp.
     * @param string $format     date() format string.
     * @return string
     */
    function customerChatFormatTimestamp(int $timestamp, string $format): string
    {
        try {
            $dt = (new DateTimeImmutable('@' . $timestamp))
                ->setTimezone(fitpalCustomerDbTimezone());
        } catch (Throwable $e) {
            // Extremely unlikely given the guard above, but a display
            // path must never throw. Fall back to PHP's default
            // formatter so the page still renders something.
            return date($format, $timestamp);
        }

        return $dt->format($format);
    }
}

if (!function_exists('customerChatNow')) {
    /**
     * The current time as a Unix timestamp. time() is already
     * absolute — it does not depend on date.timezone — so this
     * helper exists mainly so every call site in this file reads
     * through the same name and a future change to the notion of
     * "now" has one place to live.
     *
     * @return int
     */
    function customerChatNow(): int
    {
        return time();
    }
}

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
 * True when a delivered order is still inside the one-hour grace
 * window during which its chat channels remain reachable.
 *
 * This is the canonical grace-window check for the customer role.
 * Two callers depend on it and MUST agree:
 *
 *   - order-tracking.php  decides whether the chat modal renders
 *     the kitchen and rider tabs, and whether the Message buttons
 *     are live.
 *   - orders.php          decides which tracking-button label to
 *     render: "Message" while the chat is still open, "View
 *     Tracking" once it has closed.
 *
 * The check is:
 *
 *   1. order_status must be 'delivered'. Every other status is a
 *      live order (open for the whole lifecycle) or a terminal
 *      cancelled/refunded order (closed immediately). Only a
 *      delivered order has a window that opens and then closes.
 *
 *   2. delivered_at must be present and parsable. A NULL delivered_at
 *      on a delivered row is a data-integrity problem — the
 *      before_order_delivered trigger in sql/database.sql sets the
 *      column on the transition — so the safe default is to fail
 *      closed rather than guess at a window start.
 *
 *   3. The elapsed time since delivered_at must be non-negative and
 *      at most CUSTOMER_CHAT_DELIVERED_GRACE_SECONDS. A negative
 *      elapsed (a delivered_at in the future) also fails closed.
 *
 * Timezone correctness
 * --------------------
 * delivered_at is written by MySQL in the connection's time_zone
 * ('+08:00' via database-connect.php). This function parses it
 * through customerChatParseTimestamp(), which anchors the parse to
 * that same offset, so the elapsed-seconds calculation is against
 * an absolute "now" from customerChatNow() and is not affected by
 * the PHP server's date.timezone.
 *
 * @param array<string, mixed> $order  Row from getTrackableOrder()
 *                                     or the equivalent columns from
 *                                     orders.php's own read.
 * @return bool
 */
function customerOrderDeliveredWithinGrace(array $order): bool
{
    if (($order['order_status'] ?? '') !== 'delivered') {
        return false;
    }

    $deliveredTs = customerChatParseTimestamp(
        isset($order['delivered_at']) ? (string)$order['delivered_at'] : null
    );

    if ($deliveredTs === null) {
        return false;
    }

    $elapsed = customerChatNow() - $deliveredTs;

    if ($elapsed < 0) {
        return false;
    }

    return $elapsed <= CUSTOMER_CHAT_DELIVERED_GRACE_SECONDS;
}

/**
 * Shape-free convenience wrapper over
 * customerOrderDeliveredWithinGrace().
 *
 * orders.php iterates over a list of orders and needs to ask, per
 * row, "is this delivered order still inside its chat window?".
 * It has the status string and the delivered_at value in hand, but
 * not a pre-built row in the shape the primary helper expects.
 *
 * This wrapper takes the two values directly so the call site reads
 * naturally and does not have to fabricate an array. It delegates
 * to the single canonical function above so the two can never
 * disagree on where the window closes.
 *
 * @param string      $orderStatus   orders.order_status
 * @param string|null $deliveredAt   orders.delivered_at (MySQL DATETIME string)
 * @return bool
 */
function customerOrderHasOpenChatWindow(
    string $orderStatus,
    ?string $deliveredAt
): bool {
    return customerOrderDeliveredWithinGrace([
        'order_status' => $orderStatus,
        'delivered_at' => $deliveredAt,
    ]);
}

/**
 * True when the assigned rider has actually accepted the order.
 *
 * A rider is considered to have accepted when the order has moved
 * out of 'rider_pending' into one of the live in-transit statuses:
 *
 *     picking_up   — rider accepted; en route to / at the restaurant
 *     delivering   — rider has the food; en route to the customer
 *
 * 'delivered' is also reachable, but only for the one-hour post-
 * delivery grace window, so a delivered order stays open for a short
 * thank-you or a "missing item" message and then closes.
 *
 * During 'rider_pending' the kitchen has proposed a rider but the
 * rider may still decline, so messaging is refused.
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
        "SELECT order_id, order_status, delivery_rider_id, delivered_at
           FROM orders
          WHERE order_id = :order_id
            AND delivery_rider_id IS NOT NULL
          LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false;
    }

    $status = (string)$row['order_status'];

    if (in_array($status, ['picking_up', 'delivering'], true)) {
        return true;
    }

    if ($status === 'delivered') {
        return customerOrderDeliveredWithinGrace($row);
    }

    return false;
}

/**
 * True when the order is in a state that still permits messages to
 * the kitchen.
 *
 * Cancelled and refunded orders are closed: the kitchen has no reason
 * to keep talking to the customer and the customer has no reason to
 * keep talking to the kitchen. A delivered order stays open for the
 * one-hour grace window so a customer can report a missing item or
 * thank the kitchen, then closes.
 *
 * Every other status — from 'pending' through 'delivering' — is open
 * for kitchen messaging.
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
        "SELECT order_id, order_status, delivered_at
           FROM orders
          WHERE order_id = :order_id
          LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false;
    }

    $status = (string)$row['order_status'];

    if (in_array($status, ['cancelled', 'refunded'], true)) {
        return false;
    }

    if ($status === 'delivered') {
        return customerOrderDeliveredWithinGrace($row);
    }

    return true;
}

/* ---------------------------------------------------------------
 * LIVE STATUS SNAPSHOT (real-time polling)
 * --------------------------------------------------------------- */

/**
 * Return the four fields the tracking page's poll needs to decide
 * whether anything has changed.
 *
 * The poll is deliberately small — a single indexed read on the
 * primary key. The client compares the returned `revision` to what
 * it already has, and only reloads the page when the revision
 * changes. A poll that finds nothing new costs one round trip and
 * one indexed lookup, with no JSON body of any size.
 *
 * The revision is a string derived from order_status, delivered_at,
 * and delivery_rider_id. Any of the following events changes it:
 *
 *   - the kitchen moves the order forward (pending → preparing, etc.)
 *   - a rider is assigned or reassigned
 *   - the rider accepts (order moves to picking_up)
 *   - the rider picks up (picking_up → delivering)
 *   - the order is delivered (delivered_at is set)
 *   - the order is cancelled or refunded
 *
 * It deliberately does not include updated_at, because updated_at is
 * touched by transient bookkeeping writes that the customer does not
 * care about. A revision built from the fields the customer actually
 * sees will only change when something the customer cares about
 * changed.
 *
 * Returns null when the order does not belong to the customer or
 * does not exist.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $customerId
 * @return array{order_id:int, order_status:string, revision:string}|null
 */
function getOrderLiveSnapshot(PDO $db, int $orderId, int $customerId): ?array
{
    if ($orderId <= 0 || $customerId <= 0) {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT
            order_id,
            order_status,
            delivered_at,
            delivery_rider_id
         FROM orders
         WHERE order_id = :order_id
           AND customer_id = :customer_id
         LIMIT 1"
    );
    $stmt->execute([
        ':order_id'    => $orderId,
        ':customer_id' => $customerId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $revision = sha1(implode('|', [
        (string)$row['order_status'],
        (string)($row['delivered_at'] ?? ''),
        (string)($row['delivery_rider_id'] ?? ''),
    ]));

    return [
        'order_id'     => (int)$row['order_id'],
        'order_status' => (string)$row['order_status'],
        'revision'     => $revision,
    ];
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
 * Six steps:
 *
 *   1. pending        order placed
 *   2. preparing      kitchen accepted
 *   3. rider_pending  kitchen assigned a rider; rider deciding
 *   4. picking_up     rider accepted; en route to / at the restaurant
 *   5. delivering     rider has the food; en route to the customer
 *   6. delivered      order arrived
 *
 * The 'picking_up' step was added in v1.3.0 of the schema. Prior to
 * this revision the tracking page jumped directly from rider_pending
 * to delivering with no visual indication that the rider had
 * accepted and was on the way to pick up.
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
            'description' => 'A rider has been assigned and is deciding to accept your order.',
        ],
        [
            'key'         => 'picking_up',
            'label'       => 'Picking Up',
            'description' => 'Your rider accepted the order and is on the way to the restaurant.',
        ],
        [
            'key'         => 'delivering',
            'label'       => 'On the Way',
            'description' => 'Your order has been picked up and is on the way to your address.',
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
            'description' => 'A rider has been assigned and is deciding to accept your order.',
        ],
        'picking_up' => [
            'label'       => 'Picking Up',
            'badge'       => 'badge-primary',
            'icon'        => 'package.svg',
            'description' => 'Your rider is on the way to pick up your order from the restaurant.',
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
 * Parses the MySQL DATETIME through customerChatParseTimestamp() so
 * the string is anchored to the database connection's '+08:00'
 * offset, then renders through customerChatFormatTimestamp() so the
 * printed string is expressed in the same offset rather than the
 * PHP server's date.timezone. Without the render step, a message
 * sent at 01:10 PHT would display as 17:10 of the previous day on
 * a UTC PHP server.
 *
 * @param string $date
 * @return string
 */
function formatMessageTime(string $date): string
{
    $ts = customerChatParseTimestamp($date);
    if ($ts === null) {
        return $date;
    }
    return customerChatFormatTimestamp($ts, 'g:i A');
}

/**
 * Format a full timestamp for the tracking header.
 *
 * Same round trip as formatMessageTime(): parse through the DB
 * offset, render through the DB offset. This is the display side of
 * the fix that keeps a MySQL DATETIME string timezone-stable no
 * matter what date.timezone is set to on the server.
 *
 * @param string $date
 * @return string
 */
function formatTrackingTimestamp(string $date): string
{
    $ts = customerChatParseTimestamp($date);
    if ($ts === null) {
        return $date;
    }
    return customerChatFormatTimestamp($ts, 'M d, Y • g:i A');
}