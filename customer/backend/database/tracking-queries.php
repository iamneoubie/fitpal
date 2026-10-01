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
 * ---------------------------------------------------------------------
 * WHERE getOrderItemsWithCustomizations AND getOrderTotals LIVE
 * ---------------------------------------------------------------------
 * This file does not declare those two functions. It re-exports them
 * by requiring the customer order query layer, which is where they
 * are declared:
 *
 *     customer/backend/database/customer-order-queries.php
 *
 * That file in turn requires the shared order-transaction layer, so
 * the customer-scoped reads and the shared cross-role functions are
 * both available after this file's single require_once at the top.
 *
 * Every caller of this file continues to call
 * getOrderItemsWithCustomizations() and getOrderTotals() by name;
 * only the file that declares them changed.
 *
 * ---------------------------------------------------------------------
 * RIDER MESSAGING STATE — ONE PREDICATE, FOUR CALLERS
 * ---------------------------------------------------------------------
 * getRiderMessagingState() answers a single question with a single
 * return value: can the customer use the rider channel on this order
 * right now, and if not, what closed it?
 *
 * The four callers, all of which must agree:
 *
 *   order-tracking.php          decides whether to render the rider
 *                               tab and whether the Message button
 *                               is live or disabled
 *   customer-order-handler.php  reports the state to the tracking
 *                               page's real-time poll so the client
 *                               can reconcile an open chat modal
 *   message-handler.php         gates the read path and the send
 *                               path on the rider channel
 *   orders.php                  decides the tracking-button label
 *                               ("Message" vs "Track Order")
 *
 * The four cannot drift. riderHasAcceptedOrder() is a thin wrapper
 * over getRiderMessagingState(), so any existing caller of the
 * older bool function keeps working and keeps agreeing with the
 * newer state function by construction.
 *
 * The possible return values:
 *
 *   'open'          rider assigned, order in picking_up or
 *                   delivering, or delivered within the one-hour
 *                   grace window. Messages flow both ways.
 *
 *   'not_accepted'  rider assigned, order still in rider_pending.
 *                   The kitchen has proposed a rider but the rider
 *                   has not yet agreed. Messaging before accept is
 *                   noise; the rider might decline.
 *
 *   'no_rider'      no rider on the order. Defensive: the page
 *                   would not render a rider tab for this state,
 *                   but a direct caller could reach it.
 *
 *   'terminal'      order is cancelled, refunded, or failed. The
 *                   order is closed; the ledger is settled; no
 *                   further conversation is possible.
 *
 *   'window_closed' order is delivered and more than one hour has
 *                   passed. The post-delivery thank-you /
 *                   missing-item window has ended.
 *
 *   'not_found'     order does not exist.
 *
 * The 'failed' status is produced by the shared order-transaction
 * layer's sweepFailedDeliveries() when a rider does not complete a
 * delivery within FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS of the
 * order entering 'delivering'. A failed order is terminal.
 *
 * ---------------------------------------------------------------------
 * PDO PLACEHOLDER RULE
 * ---------------------------------------------------------------------
 * This project sets PDO::ATTR_EMULATE_PREPARES => false. With native
 * prepares, a named placeholder may be bound only once per statement.
 * Every query here that needs the same value in more than one
 * position uses distinct placeholder names.
 *
 * ---------------------------------------------------------------------
 * TIMEZONE ROUND TRIP
 * ---------------------------------------------------------------------
 * Two independent facts about this project:
 *
 *   1. MySQL is connected with the session time_zone set to '+08:00'
 *      by shared/backend/database/database-connect.php, so every
 *      DATETIME value returned to PHP is a Philippine wall-clock
 *      string regardless of the server's system clock.
 *
 *   2. PHP's date.timezone is UTC on the deployed server, so
 *      date() renders a Unix timestamp eight hours behind the
 *      Philippine wall-clock moment that timestamp represents.
 *
 * The parse side and the render side of the round trip both go
 * through customerChatParseTimestamp() and
 * customerChatFormatTimestamp(), which anchor to
 * FITPAL_CUSTOMER_DB_TIMEZONE_OFFSET ('+08:00'). The result is that
 * a MySQL DATETIME string survives the round trip unchanged
 * regardless of what date.timezone happens to be on the server.
 *
 * No $_POST, no header(), no echo.
 *
 * @package FitPal
 * @version 4.0 — Adds getRiderMessagingState(). Rewrites
 *                riderHasAcceptedOrder() as a thin delegate to it so
 *                the two cannot disagree. Every other function is
 *                byte-identical to the previous revision.
 *
 *                (3.0: requires the renamed customer order query
 *                layer. 2.3: timezone round trip. 2.2: grace window
 *                in the customer's timezone. 2.1: open-chat-window
 *                wrapper. 2.0: 'picking_up' support and one-hour
 *                grace. 1.2: chat gating and delta fetch. 1.1:
 *                distinct placeholders in getOrderMessages.)
 */

declare(strict_types=1);

require_once __DIR__ . '/customer-order-queries.php';

/**
 * The timezone offset the database connection is set to.
 *
 * Must match the $timezone_offset value in
 * shared/backend/database/database-connect.php. That file issues
 * `SET time_zone = '+08:00'` on every connection, so every
 * TIMESTAMP / DATETIME value MySQL returns is expressed in
 * Philippine Time regardless of the server's system clock.
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
 * restaurant-side restaurant_chat_delivered_window() window.
 */
if (!defined('CUSTOMER_CHAT_DELIVERED_GRACE_SECONDS')) {
    define('CUSTOMER_CHAT_DELIVERED_GRACE_SECONDS', 3600);
}

/* =============================================================
 * TIMEZONE-AWARE TIME HELPERS
 *
 * Every comparison or render of a value returned by MySQL must go
 * through these helpers. Calling time(), strtotime(), or date()
 * directly on a MySQL DATETIME string reintroduces the display bug
 * that a previous revision fixed.
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
     * This is the RENDER side of the round trip. Rendering through
     * this helper converts the timestamp into the '+08:00' offset
     * before formatting, so the printed string matches the
     * wall-clock moment the timestamp actually represents,
     * regardless of what date.timezone is set to on the server.
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
     * The current time as a Unix timestamp.
     *
     * time() is already absolute — it does not depend on
     * date.timezone — so this helper exists mainly so every call
     * site in this file reads through the same name and a future
     * change to the notion of "now" has one place to live.
     *
     * @return int
     */
    function customerChatNow(): int
    {
        return time();
    }
}

/* =============================================================
 * SCOPE GUARD
 * ============================================================= */

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

/* =============================================================
 * TRACKING READS
 * ============================================================= */

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
 * Delegates to the customer order query layer, which declares this
 * function and re-exports it.
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
 * Delegates to the customer order query layer, which declares this
 * function and re-exports it.
 *
 * @param PDO $db
 * @param int $orderId
 * @return array<string, mixed>|false
 */
function getTrackingOrderTotals(PDO $db, int $orderId): array|false
{
    return getOrderTotals($db, $orderId);
}

/* =============================================================
 * CHAT GATING
 * ============================================================= */

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
 * Timezone correctness
 * --------------------
 * delivered_at is written by MySQL in the connection's time_zone
 * ('+08:00'). This function parses it through
 * customerChatParseTimestamp(), which anchors the parse to that
 * same offset, so the elapsed-seconds calculation is against an
 * absolute "now" from customerChatNow() and is not affected by the
 * PHP server's date.timezone.
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
 * The single predicate for the rider channel.
 *
 * Returns one of:
 *
 *   'open'          rider assigned, order in picking_up or
 *                   delivering, or delivered within the grace
 *                   window. Both read and send are permitted.
 *   'not_accepted'  rider assigned, order still in rider_pending.
 *                   The rider has not agreed yet.
 *   'no_rider'      no rider assigned to the order.
 *   'terminal'      order is cancelled, refunded, or failed.
 *   'window_closed' order is delivered and the grace window has
 *                   passed.
 *   'not_found'     order does not exist.
 *
 * This function is the authoritative answer to "can the customer
 * use the rider channel on this order right now?". Every caller
 * that needs that answer reads it from here, so the tab rendering,
 * the read gate, the send gate, the button label, and the client's
 * real-time reconciliation all agree by construction.
 *
 * The read is a single indexed lookup on the primary key. No joins
 * are needed because the three inputs to the decision — status,
 * delivery_rider_id, delivered_at — all live on the orders row.
 *
 * Timezone correctness
 * --------------------
 * The grace-window decision routes through
 * customerOrderDeliveredWithinGrace(), which parses delivered_at
 * in the database connection's '+08:00' offset. This function does
 * not re-implement that check.
 *
 * @param PDO $db
 * @param int $orderId
 * @return string
 */
function getRiderMessagingState(PDO $db, int $orderId): string
{
    if ($orderId <= 0) {
        return 'not_found';
    }

    $stmt = $db->prepare(
        "SELECT order_id, order_status, delivery_rider_id, delivered_at
           FROM orders
          WHERE order_id = :order_id
          LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return 'not_found';
    }

    $status       = (string)$row['order_status'];
    $hasRider     = $row['delivery_rider_id'] !== null
        && (int)$row['delivery_rider_id'] > 0;

    // ---- Terminal states first ----

    if (in_array($status, ['cancelled', 'refunded', 'failed'], true)) {
        return 'terminal';
    }

    // ---- No rider ----

    if (!$hasRider) {
        return 'no_rider';
    }

    // ---- Live rider states ----

    if (in_array($status, ['picking_up', 'delivering'], true)) {
        return 'open';
    }

    // ---- Delivered: depends on the grace window ----

    if ($status === 'delivered') {
        $within = customerOrderDeliveredWithinGrace([
            'order_status' => $status,
            'delivered_at' => $row['delivered_at'],
        ]);
        return $within ? 'open' : 'window_closed';
    }

    // ---- rider_pending, and any status not otherwise handled ----
    //
    // 'rider_pending' is the only non-terminal, non-live status the
    // rider channel reaches. Every other non-terminal status
    // ('pending', 'preparing') has no rider on the order by the
    // schema's own design and would have been caught by the
    // hasRider check above. A status that reaches this point
    // without matching one of the earlier branches is therefore
    // rider_pending.
    return 'not_accepted';
}

/**
 * True when the assigned rider has actually accepted the order.
 *
 * A thin delegate to getRiderMessagingState(), so the two cannot
 * disagree. Retained because several callers were written against
 * this bool and rewriting them would broaden the change for no
 * benefit.
 *
 * Prefer getRiderMessagingState() in new code: it carries the
 * reason a channel is closed, which the client uses to choose the
 * right copy for the refusal system line.
 *
 * @param PDO $db
 * @param int $orderId
 * @return bool
 */
function riderHasAcceptedOrder(PDO $db, int $orderId): bool
{
    return getRiderMessagingState($db, $orderId) === 'open';
}

/**
 * True when the order is in a state that still permits messages to
 * the kitchen.
 *
 * Cancelled and refunded orders are closed. A delivered order stays
 * open for the one-hour grace window, then closes. Every other
 * status — from 'pending' through 'delivering' — is open for
 * kitchen messaging. A 'failed' order is closed: the ledger for the
 * order is settled and no further conversation is possible.
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

    if (in_array($status, ['cancelled', 'refunded', 'failed'], true)) {
        return false;
    }

    if ($status === 'delivered') {
        return customerOrderDeliveredWithinGrace($row);
    }

    return true;
}

/* =============================================================
 * LIVE STATUS SNAPSHOT (real-time polling)
 * ============================================================= */

/**
 * Return the fields the tracking page's poll needs to decide
 * whether anything has changed, plus the current chat gating
 * flags so the client can reconcile an open chat modal.
 *
 * The poll is deliberately small — a single indexed read on the
 * primary key. The client compares the returned `revision` to what
 * it already has, and only re-reads the tracking state when the
 * revision changes.
 *
 * The revision is derived from order_status, delivered_at, and
 * delivery_rider_id, so it changes on any event the customer cares
 * about: the kitchen moving the order forward, a rider being
 * assigned or reassigned, the rider accepting, the rider picking
 * up, the order being delivered, or the order being
 * cancelled/refunded.
 *
 * `updated_at` is deliberately excluded. It is touched by transient
 * bookkeeping writes that do not change what the customer sees.
 *
 * The `tracking_state` sub-array carries the two channel flags and
 * the order status, so the client does not need a second request
 * to reconcile an open chat modal. This is the data the poll's
 * caller passes to applyTrackingState().
 *
 * Returns null when the order does not belong to the customer or
 * does not exist.
 *
 * @param PDO $db
 * @param int $orderId
 * @param int $customerId
 * @return array{
 *     order_id:int,
 *     order_status:string,
 *     revision:string,
 *     tracking_state:array{
 *         order_status:string,
 *         can_message_kitchen:bool,
 *         can_message_rider:bool,
 *         rider_state:string
 *     }
 * }|null
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

    $status = (string)$row['order_status'];

    $revision = sha1(implode('|', [
        $status,
        (string)($row['delivered_at'] ?? ''),
        (string)($row['delivery_rider_id'] ?? ''),
    ]));

    // Kitchen flag: same predicate order-tracking.php uses to
    // decide whether to render the kitchen tab.
    $kitchenOpen = !in_array($status, ['cancelled', 'refunded', 'failed'], true)
        && ($status !== 'delivered'
            || customerOrderDeliveredWithinGrace($row));

    // Rider flag: the state function is the single authority.
    $riderState = getRiderMessagingState($db, $orderId);
    $riderOpen  = ($riderState === 'open');

    return [
        'order_id'     => (int)$row['order_id'],
        'order_status' => $status,
        'revision'     => $revision,
        'tracking_state' => [
            'order_status'        => $status,
            'can_message_kitchen' => $kitchenOpen,
            'can_message_rider'   => $riderOpen,
            'rider_state'         => $riderState,
        ],
    ];
}

/* =============================================================
 * MESSAGES — FULL LOAD
 * ============================================================= */

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

/* =============================================================
 * MESSAGES — DELTA FETCH
 * ============================================================= */

/**
 * Fetch only the messages for a channel whose message_id is strictly
 * greater than $sinceId.
 *
 * This is the polling path. The client sends the highest message_id
 * it already holds; the server returns only rows that came after
 * it. An idle conversation with no new messages returns an empty
 * array after a single indexed lookup.
 *
 * When $sinceId is 0 the function returns the whole conversation,
 * which is why the initial fetch can also route through this
 * function and get a full list in one call.
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

/* =============================================================
 * MESSAGES — WRITES AND READS
 * ============================================================= */

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

/* =============================================================
 * STATUS TIMELINE (pure — no DB access)
 * ============================================================= */

/**
 * Ordered list of tracking steps for the progress bar.
 *
 * Six steps: pending, preparing, rider_pending, picking_up,
 * delivering, delivered.
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
        'failed' => [
            'label'       => 'Failed',
            'badge'       => 'badge-danger',
            'icon'        => 'error-warning-fill.svg',
            'description' => 'This order could not be completed.',
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
 * PHP server's date.timezone.
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
 * offset, render through the DB offset.
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