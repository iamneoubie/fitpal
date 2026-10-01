<?php
/**
 * FitPal Customer Order Handler
 *
 * The customer-facing dispatch endpoint for order actions. Runs on
 * the customer session (PHPSESSID_CUSTOMER), separate from every
 * other role's session.
 *
 * ---------------------------------------------------------------------
 * ACTIONS
 * ---------------------------------------------------------------------
 *   cancel_order        → customer cancels a pending order. Delegates
 *                         entirely to the shared order-transaction
 *                         handler, which owns the ledger rules for
 *                         all three payment methods.
 *
 *   get_order_details   → return an order as JSON. Customer-scoped
 *                         read. Ownership is verified through the
 *                         shared layer's getOrderOwnership() before
 *                         the order is returned.
 *
 *   get_tracking_status → read-only order_status + revision for the
 *                         tracking page's real-time poll. Owned by
 *                         tracking-queries.php.
 *
 *   reorder             → rebuild the session order queue from a past
 *                         order, validating each product against the
 *                         current database state. The queue is a
 *                         session concern; only the reads go through
 *                         customer-order-queries.php.
 *
 * ---------------------------------------------------------------------
 * WHERE THE MONEY RULES LIVE
 * ---------------------------------------------------------------------
 * This file contains no money logic. It does not read the order's
 * payment method, does not choose between 'cancelled' and
 * 'refunded', and does not write any ledger row. All three of those
 * decisions belong to
 *
 *     shared/backend/handlers/order-transaction-handler.php
 *
 * and are invoked by POSTing to that endpoint with
 * action=customer_cancel_order. Keeping the decision in one place
 * means the customer, restaurant, and rider cancel paths cannot
 * drift from each other.
 *
 * ---------------------------------------------------------------------
 * DEPENDENCY PATHS
 * ---------------------------------------------------------------------
 * This file lives at:
 *
 *     fitpal/customer/backend/handlers/customer-order-handler.php
 *
 * The database connection lives at:
 *
 *     fitpal/shared/backend/database/database-connect.php
 *
 * That is three directories up from this file's own directory,
 * then into shared/. The session bootstrap lives at:
 *
 *     fitpal/shared/includes/session-bootstrap.php
 *
 * which is the same three-up depth. Every require in this file is
 * therefore of the form:
 *
 *     __DIR__ . '/../../../shared/...'
 *
 * A require that walks one level up instead of three (for example
 * '/../database/database-connect.php') resolves to
 * customer/backend/database/, which does not contain the
 * connection file and does not exist as a valid include path. The
 * previous revision of this file used that one-up path and
 * fataled on every request with:
 *
 *     Failed opening required
 *         '.../customer/backend/handlers/../database/database-connect.php'
 *
 * The corrected path is used below.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL
 * ---------------------------------------------------------------------
 * The handler bootstraps the customer session before doing anything
 * else. Because the request that reaches this handler carries only
 * the customer cookie, the customer session is the only session
 * this code can see. The auth guard reads $_SESSION['customer_id']
 * and the CSRF check reads $_SESSION['customer_csrf_token'], both
 * inside the customer session and guaranteed to be the customer's
 * own.
 *
 * ---------------------------------------------------------------------
 * RESPONSE SHAPE
 * ---------------------------------------------------------------------
 * JSON. Business-rule failures return HTTP 200 with
 * {status:'error', message:'...'}; only auth failures return 401
 * and CSRF mismatches return 403.
 *
 * @package FitPal
 * @version 7.2 — The database-connection require path is corrected
 *                to walk three directories up into shared/.
 *
 *                Before this revision the require read
 *
 *                    __DIR__ . '/../database/database-connect.php'
 *
 *                which resolved to
 *
 *                    customer/backend/database/database-connect.php
 *
 *                a file that does not exist. Every request fataled
 *                on the require before any handler body ran.
 *
 *                The corrected require walks three directories up —
 *                handlers → backend → customer → fitpal — and then
 *                into shared/backend/database/. It matches the
 *                depth and the shared/ location that the session-
 *                bootstrap require two lines below already uses.
 *
 *                Every handler body, the auth guard, the CSRF
 *                branch, the action switch, and the response shape
 *                are byte-identical to v7.1.
 *
 *                (7.1: the file requires the connection itself
 *                rather than relying on the caller. 7.0: renamed
 *                from order-handler.php; cancel delegated to the
 *                shared layer. 6.0: per-role session migration.
 *                5.3: added get_tracking_status. 5.2: cancel
 *                restricted to 'pending' only. 5.1: CSRF validated
 *                against customer_csrf_token. 5.0: raw SQL moved to
 *                order-queries.php.)
 */

declare(strict_types=1);

/* --------------------------------------------------------------
 * DATABASE CONNECTION
 *
 * Required before anything else. The path walks three directories
 * up from this file's own directory into shared/, then into
 * backend/database/.
 *
 *     customer/backend/handlers/   ← this file's __DIR__
 *     customer/backend/            ← .. (1)
 *     customer/                    ← .. (2)
 *     fitpal/                      ← .. (3)
 *     fitpal/shared/backend/database/database-connect.php
 *
 * The require_once guard inside database-connect.php makes a
 * second require from the shared order-transaction handler cheap
 * and safe.
 * -------------------------------------------------------------- */

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

/* --------------------------------------------------------------
 * SESSION BOOTSTRAP
 *
 * Must run before any include that might touch the session. This
 * handler belongs to the customer context. The path uses the same
 * three-up depth as the connection require above.
 * -------------------------------------------------------------- */

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

/* --------------------------------------------------------------
 * RESPONSE HEADERS
 * -------------------------------------------------------------- */

header('Content-Type: application/json; charset=utf-8');

/* --------------------------------------------------------------
 * AUTHENTICATION
 * -------------------------------------------------------------- */

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$customerId = (int)$_SESSION['customer_id'];

/* --------------------------------------------------------------
 * QUERY LAYER
 *
 * customer-order-queries.php lives one directory up from this
 * file's parent, at customer/backend/database/. The path is
 * therefore __DIR__ . '/../database/...', which resolves to
 *
 *     customer/backend/database/customer-order-queries.php
 *
 * and that file exists.
 *
 * That file in turn pulls in the shared order-transaction layer,
 * so the customer-scoped reads and the shared cross-role
 * functions are both available after these two requires.
 * tracking-queries.php is separate because its timezone-aware
 * helpers are tracking-specific.
 * -------------------------------------------------------------- */

require_once __DIR__ . '/../database/customer-order-queries.php';
require_once __DIR__ . '/../database/tracking-queries.php';

/* --------------------------------------------------------------
 * CSRF
 *
 * Validated against the customer context's own session key,
 * 'customer_csrf_token', inside the customer session. On mismatch
 * the customer's own token is rotated so the next page render
 * generates a fresh one; the shared 'csrf_token' key is never
 * touched.
 * -------------------------------------------------------------- */

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['customer_csrf_token'] ?? '');

if (
    $sessToken === ''
    || $givenToken === ''
    || !hash_equals($sessToken, $givenToken)
) {
    unset($_SESSION['customer_csrf_token']);

    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$action = (string)($_POST['action'] ?? '');

/* --------------------------------------------------------------
 * DISPATCH
 * -------------------------------------------------------------- */

try {
    switch ($action) {

        case 'get_order_details':
            handleGetOrderDetails($database_connection, $customerId);
            break;

        case 'get_tracking_status':
            handleGetTrackingStatus($database_connection, $customerId);
            break;

        case 'reorder':
            handleReorder($database_connection, $customerId);
            break;

        case 'cancel_order':
            handleCancelOrderDelegation();
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Customer order handler DB error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred']);
} catch (RuntimeException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Customer order handler error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred']);
}

/* =============================================================
 * HANDLERS
 * ============================================================= */

/**
 * Return order details as JSON, scoped to the customer.
 *
 * Ownership is verified through the shared layer's
 * getOrderOwnership() before any order row is fetched, so a caller
 * cannot read another customer's order by supplying its id.
 */
function handleGetOrderDetails(PDO $db, int $customerId): void
{
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order ID']);
        return;
    }

    if (!getOrderOwnership($db, $orderId, $customerId)) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        return;
    }

    $order = getOrderDetails($db, $orderId);

    if (!$order) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        return;
    }

    echo json_encode([
        'status' => 'success',
        'order'  => $order,
    ]);
}

/**
 * Read-only poll endpoint for the tracking page.
 *
 * Returns the current order_status and a revision hash for a single
 * order, scoped to the owner. The client compares the returned
 * revision to what it already holds and reloads the page only when
 * the revision differs.
 *
 * The revision is derived from order_status, delivered_at, and
 * delivery_rider_id. Any of those changing — the kitchen moving
 * the order forward, a rider being assigned or reassigned, the
 * rider accepting, the rider picking up, the order being delivered,
 * or the order being cancelled/refunded — produces a new hash.
 *
 * `updated_at` is deliberately excluded. It is touched by transient
 * bookkeeping writes that do not change what the customer sees.
 */
function handleGetTrackingStatus(PDO $db, int $customerId): void
{
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order ID']);
        return;
    }

    $snapshot = getOrderLiveSnapshot($db, $orderId, $customerId);

    if ($snapshot === null) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        return;
    }

    $currentRevision = (string)($_POST['current_revision'] ?? '');
    $changed = ($currentRevision === '') || ($currentRevision !== $snapshot['revision']);

    echo json_encode([
        'status'       => 'success',
        'order_id'     => $snapshot['order_id'],
        'order_status' => $snapshot['order_status'],
        'revision'     => $snapshot['revision'],
        'changed'      => $changed,
    ]);
}

/**
 * Rebuild the session order queue from a past order.
 *
 * For each line in the original order:
 *   1. Check the product still exists, is active, and is in stock.
 *      Branches and restaurants must be active too.
 *   2. Re-apply the original customizations against the CURRENT
 *      product_composition rules. Quantities are clamped to
 *      max_quantity; ingredients no longer in the composition are
 *      dropped.
 *   3. Compute a fresh unit price from base_price + modifiers.
 *      The historical price_at_time is only used to reconstruct
 *      what the customer asked for — never trusted as money.
 *   4. Merge with an existing queue line that has the same
 *      (product_id + customization) signature, or append.
 *
 * Failures are collected per-line into `skipped` so the UI can show
 * exactly what could not be re-added and why.
 */
function handleReorder(PDO $db, int $customerId): void
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order ID']);
        return;
    }

    $orderItems = getReorderableItems($db, $orderId, $customerId);

    if (empty($orderItems)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'That order has no items to reorder.',
        ]);
        return;
    }

    $queue = $_SESSION['order_queue'] ?? [];
    if (!is_array($queue)) {
        $queue = [];
    }

    $added   = [];
    $skipped = [];

    foreach ($orderItems as $item) {
        $productId = (int)$item['product_id'];
        $name      = (string)$item['product_name'];
        $qty       = (int)$item['quantity'];

        // Rebuild the customization payload in the shape the queue
        // expects — the same shape queue-handler.php's add action
        // accepts from product-detail.js.
        $customizations = [];
        foreach (($item['customizations'] ?? []) as $cust) {
            $customizations[] = [
                'ingredient_id'   => (int)$cust['ingredient_id'],
                'quantity'        => (int)$cust['quantity'],
                'price_modifier'  => (float)$cust['price_at_time'],
                'calories'        => (int)($cust['calories_at_time'] ?? 0),
                'selected_option' => ((int)$cust['is_removed'] === 1) ? 'remove' : 'selected',
                'notes'           => $cust['custom_text'] ?? null,
            ];
        }

        $customJson = !empty($customizations) ? json_encode($customizations) : null;

        $enriched = buildReorderLine($db, [
            'product_id'         => $productId,
            'quantity'           => $qty,
            'customization_data' => $customJson,
        ], $skipped, $name);

        if ($enriched === null) {
            continue;
        }

        $lineKey = $productId . '::' . sha1((string)$customJson);
        $enriched['customizations'] = $customizations;
        $enriched['line_key']       = $lineKey;

        // Merge with an existing line that has the same signature,
        // or append.
        $merged = false;
        foreach ($queue as &$row) {
            $rowKey = $row['line_key']
                ?? ($row['product_id'] . '::' . sha1((string)(
                    is_string($row['customization_data'] ?? null)
                        ? $row['customization_data']
                        : json_encode($row['customization_data'] ?? null)
                )));

            if ($rowKey === $lineKey) {
                $newQty = (int)$row['quantity'] + $qty;
                $max    = (int)($enriched['stock'] ?? 999);
                $row['quantity'] = min($newQty, $max);
                $row['price']    = $enriched['price'];
                $merged = true;
                break;
            }
        }
        unset($row);

        if (!$merged) {
            $queue[] = $enriched;
        }

        $added[] = [
            'name'     => $name,
            'quantity' => $qty,
        ];
    }

    $_SESSION['order_queue'] = array_values($queue);

    $addedCount   = count($added);
    $skippedCount = count($skipped);

    if ($addedCount === 0) {
        echo json_encode([
            'status'   => 'error',
            'message'  => 'None of the items from that order are available right now.',
            'added'    => [],
            'skipped'  => $skipped,
            'queue'    => $_SESSION['order_queue'],
            'redirect' => 'menu.php',
        ]);
        return;
    }

    if ($skippedCount === 0) {
        echo json_encode([
            'status'   => 'success',
            'message'  => sprintf(
                'Added %d item%s to your order.',
                $addedCount,
                $addedCount === 1 ? '' : 's'
            ),
            'added'    => $added,
            'skipped'  => [],
            'queue'    => $_SESSION['order_queue'],
            'redirect' => 'menu.php',
        ]);
        return;
    }

    echo json_encode([
        'status'   => 'partial',
        'message'  => sprintf(
            'Added %d item%s. %d item%s unavailable.',
            $addedCount,
            $addedCount === 1 ? '' : 's',
            $skippedCount,
            $skippedCount === 1 ? ' was' : 's were'
        ),
        'added'    => $added,
        'skipped'  => $skipped,
        'queue'    => $_SESSION['order_queue'],
        'redirect' => 'menu.php',
    ]);
}

/**
 * Hand a cancel request off to the shared order-transaction handler.
 *
 * This function does not read the order, does not read the payment
 * method, does not decide the final status, and does not write any
 * ledger row. It only forwards the order_id and the customer's CSRF
 * token to the shared handler and relays that handler's JSON body
 * back to the caller unchanged.
 *
 * The shared handler re-validates the customer role from the
 * session cookie and re-checks the CSRF token against
 * customer_csrf_token, so this delegation does not weaken the
 * request's authentication.
 *
 * The shared handler requires its own database connection (see its
 * own docblock). Including it here runs it in this file's scope,
 * where $database_connection was already assigned by this file's
 * own connection require at the top.
 */
function handleCancelOrderDelegation(): never
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    $token   = (string)($_POST['csrf_token'] ?? '');

    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order ID']);
        exit;
    }

    $endpoint = __DIR__ . '/../../../shared/backend/handlers/order-transaction-handler.php';

    if (!is_file($endpoint)) {
        error_log('Customer order handler: shared order-transaction handler is missing at ' . $endpoint);
        echo json_encode([
            'status'  => 'error',
            'message' => 'The order service is temporarily unavailable. Please try again.',
        ]);
        exit;
    }

    // The shared handler reads $_POST and $_SESSION directly. It
    // runs in the same PHP process and the same customer session,
    // so $_POST and $_SESSION are still the ones this request
    // arrived with. Set the action name the shared handler
    // dispatches on, then include the shared handler. The shared
    // handler terminates the request itself.
    $_POST['action'] = 'customer_cancel_order';

    require $endpoint;

    // The shared handler always exits; this line is unreachable.
    exit;
}