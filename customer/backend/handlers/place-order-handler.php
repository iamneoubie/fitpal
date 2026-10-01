<?php
/**
 * FitPal Place Order Handler
 *
 * Runs on the customer session (PHPSESSID_CUSTOMER), separate from
 * every other role's session.
 *
 * The session order queue ($_SESSION['order_queue']) is the single
 * source of truth. This handler:
 *
 *   1. Validates the request (auth, CSRF, address, payment method).
 *   2. Calls createOrderFromQueue() to atomically insert the order,
 *      its queue_item rows, and its customization_instance rows, and
 *      to write the customer payment transaction.
 *   3. Clears the session queue on success.
 *   4. Redirects to orders.php with a success flash + highlight flag.
 *
 * There is no cart table involved anywhere in this flow.
 *
 * ---------------------------------------------------------------------
 * SESSION ERROR KEYS MUST MATCH THE PAGE THAT READS THEM
 * ---------------------------------------------------------------------
 * The previous revision wrote every failure to
 * $_SESSION['order_error'] and then redirected to whichever page
 * the failure pointed at. The checkout page reads
 * $_SESSION['checkout_error']. The orders page reads
 * $_SESSION['order_error']. The sign-in page reads
 * $_SESSION['login_error']. None of those are the same key.
 *
 * The consequence was that a failure during order placement
 * redirected the browser back to checkout.php, the checkout page
 * ran its flash block, found no checkout_error to display, and
 * rendered as if nothing had happened. The customer saw the
 * checkout page again with no visible error and no order placed.
 *
 * This revision sets each redirect's flash on the key the
 * destination page actually reads:
 *
 *     Redirect to checkout.php  → $_SESSION['checkout_error']
 *     Redirect to orders.php    → $_SESSION['order_error']
 *     Redirect to sign-in.php   → $_SESSION['login_error']
 *
 * No other behaviour changed.
 *
 * ---------------------------------------------------------------------
 * WHERE createOrderFromQueue LIVES
 * ---------------------------------------------------------------------
 * createOrderFromQueue() is declared in the shared order-transaction
 * layer:
 *
 *     shared/backend/database/order-transaction-queries.php
 *
 * This handler reaches it transitively through the require of
 * customer-order-queries.php (which itself requires the shared
 * layer).
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL
 * ---------------------------------------------------------------------
 * The handler bootstraps the customer session before doing
 * anything else. The auth guard reads $_SESSION['customer_id'], the
 * CSRF check reads $_SESSION['customer_csrf_token'], the queue is
 * read from $_SESSION['order_queue'], the double-submit guard
 * writes to $_SESSION['_place_order_guard'], and every flash
 * message writes to a key the destination page reads — every one
 * of those keys lives in the customer session, guaranteed to be
 * the customer's own.
 *
 * ---------------------------------------------------------------------
 * DOUBLE-SUBMIT GUARD
 * ---------------------------------------------------------------------
 * The guard stores a hash of the current queue in the session. On
 * the first request the hash is absent, so we set it and continue.
 * On any subsequent request with the same queue, the guard refuses
 * the duplicate and redirects to orders.php. The hash is cleared on
 * success and on every failure path that returns to checkout.php,
 * so a customer who fixes an error and retries is not blocked.
 *
 * ---------------------------------------------------------------------
 * MONEY MOVEMENT BY PAYMENT METHOD
 * ---------------------------------------------------------------------
 * createOrderFromQueue() writes one `payment` transaction against
 * the customer's financial account:
 *
 *     COD    → status 'pending'. No wallet movement.
 *     Online → status 'pending'. No wallet movement.
 *     Wallet → status 'completed'. Wallet debited by the
 *              after_transaction_insert trigger.
 *
 * Every one of those outcomes is decided inside the shared layer.
 * This handler never touches the ledger.
 *
 * @package FitPal
 * @version 9.3 — Flash-message keys now match the pages that read
 *                them.
 *
 *                The previous revision wrote every failure to
 *                $_SESSION['order_error'] and redirected to
 *                whichever page the failure pointed at. The
 *                checkout page reads $_SESSION['checkout_error'],
 *                so a failure during order placement redirected
 *                back to checkout.php with an invisible error and
 *                no order placed. This revision sets each
 *                redirect's flash on the key its destination page
 *                actually reads.
 *
 *                No other line changed. The require chain, the
 *                auth guard, the CSRF check, the address
 *                resolution, the queue presence check, the
 *                double-submit guard, the createOrderFromQueue()
 *                call, the success flash, and the exception
 *                handling are byte-identical to v9.2.
 *
 *                (9.2: require block re-verified. 9.1: renamed
 *                query layer. 9.0: per-role session migration.
 *                8.2: double-submit guard. 8.1: CSRF validated
 *                against customer_csrf_token. 8.0:
 *                queue-authoritative; cart system deleted.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

// ---------------------------------------------------------------------
// AUTHENTICATION
//
// Unauthenticated redirect goes to the sign-in page, which reads
// $_SESSION['login_error']. Set that key, not order_error.
// ---------------------------------------------------------------------

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    $_SESSION['login_error'] = 'Please sign in to place an order.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

// ---------------------------------------------------------------------
// DEPENDENCIES
//
// customer-order-queries.php transitively requires
// shared/backend/database/order-transaction-queries.php, which
// declares createOrderFromQueue() and requires the shared fee
// schedule in the same directory. address-queries.php supplies the
// scoped address lookup used below.
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/address-queries.php';
require_once __DIR__ . '/../database/customer-order-queries.php';

// ---------------------------------------------------------------------
// CSRF
//
// Validated against the customer context's own key,
// 'customer_csrf_token', inside the customer session. On any
// failure the flash goes to checkout.php, which reads
// $_SESSION['checkout_error'].
// ---------------------------------------------------------------------

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['customer_csrf_token'] ?? '');

if (
    $sessToken === ''
    || $givenToken === ''
    || !hash_equals($sessToken, $givenToken)
) {
    $_SESSION['checkout_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/checkout.php');
    exit;
}

$addressId     = isset($_POST['address_id']) ? (int)$_POST['address_id'] : 0;
$paymentMethod = isset($_POST['payment_method']) ? trim((string)$_POST['payment_method']) : 'COD';
$customerId    = (int)$_SESSION['customer_id'];

// ---------------------------------------------------------------------
// PAYMENT METHOD
// ---------------------------------------------------------------------

$validPaymentMethods = ['COD', 'Wallet', 'Online'];
if (!in_array($paymentMethod, $validPaymentMethods, true)) {
    $_SESSION['checkout_error'] = 'Invalid payment method selected.';
    header('Location: ../../pages/checkout.php');
    exit;
}

// ---------------------------------------------------------------------
// RESOLVE THE DESTINATION ADDRESS (scoped to this customer)
// ---------------------------------------------------------------------

$addressData = getAddressById($database_connection, $addressId, $customerId);
if (!$addressData) {
    $_SESSION['checkout_error'] = 'Invalid address selected.';
    header('Location: ../../pages/checkout.php');
    exit;
}

$addressParts = array_filter([
    $addressData['block']       ?? '',
    $addressData['barangay']    ?? '',
    $addressData['city']        ?? '',
    $addressData['province']    ?? '',
    $addressData['region']      ?? '',
    $addressData['postal_code'] ?? '',
    $addressData['country']     ?? 'Philippines',
]);
$fullAddress = implode(', ', $addressParts);

if ($fullAddress === '') {
    $_SESSION['checkout_error'] = 'Incomplete address. Please update your address.';
    header('Location: ../../pages/checkout.php');
    exit;
}

// ---------------------------------------------------------------------
// QUEUE PRESENCE CHECK
//
// Empty queue redirects back to checkout.php, not menu.php. The
// customer is already on checkout and a redirect to the menu would
// lose the reason for the failure. checkout.php reads
// $_SESSION['checkout_error'].
// ---------------------------------------------------------------------

if (empty($_SESSION['order_queue']) || !is_array($_SESSION['order_queue'])) {
    $_SESSION['checkout_error'] = 'Your order is empty. Please add items before checkout.';
    header('Location: ../../pages/checkout.php');
    exit;
}

// ---------------------------------------------------------------------
// DOUBLE-SUBMIT GUARD
// ---------------------------------------------------------------------

$queueHash = hash('sha256', json_encode($_SESSION['order_queue']));
$guardKey  = '_place_order_guard';

if (isset($_SESSION[$guardKey]) && $_SESSION[$guardKey] === $queueHash) {
    $_SESSION['order_error'] = 'This order has already been submitted. Please check your orders.';
    header('Location: ../../pages/orders.php');
    exit;
}

$_SESSION[$guardKey] = $queueHash;

// ===============================================================
// ATOMIC ORDER CREATION (shared layer: orders + queue_item +
// customization_instance + customer payment transaction)
// ===============================================================

try {
    $orderId = createOrderFromQueue(
        $database_connection,
        $customerId,
        $_SESSION['order_queue'],
        $fullAddress,
        $paymentMethod
    );

    // The order is now real. Clear everything that represented the
    // pre-order staging, including the double-submit guard.
    unset($_SESSION['order_queue']);
    unset($_SESSION['checkout_address_id']);
    unset($_SESSION['checkout_error']);
    unset($_SESSION['queue_error']);
    unset($_SESSION[$guardKey]);

    $_SESSION['order_success']   = 'Order #' . $orderId . ' placed successfully!';
    $_SESSION['highlight_order'] = $orderId;

    header('Location: ../../pages/orders.php');
    exit;

} catch (RuntimeException $e) {
    unset($_SESSION[$guardKey]);
    $_SESSION['checkout_error'] = $e->getMessage();
    header('Location: ../../pages/checkout.php');
    exit;

} catch (PDOException $e) {
    unset($_SESSION[$guardKey]);
    error_log('Place order DB error: ' . $e->getMessage());
    $_SESSION['checkout_error'] = 'A system error occurred. Please try again.';
    header('Location: ../../pages/checkout.php');
    exit;
}