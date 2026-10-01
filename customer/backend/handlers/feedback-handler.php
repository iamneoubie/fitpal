<?php
/**
 * FitPal Customer Feedback Handler
 *
 * AJAX endpoint for product reviews submitted from the Orders page's
 * review modal. Runs on the customer session (PHPSESSID_CUSTOMER),
 * separate from every other role's session.
 *
 * One action is supported:
 *
 *   submit_review → insert a feedback row for a delivered order
 *
 * The gate is canReviewProduct(), which lives in
 * customer/backend/database/customer-order-queries.php (renamed from
 * order-queries.php in this revision) and enforces all three
 * preconditions in one place:
 *   - the order belongs to this customer
 *   - the order is in the 'delivered' state
 *   - this customer has not already reviewed this product for this
 *     order
 *
 * This handler contains NO SQL of its own beyond the single INSERT
 * for the feedback row itself. Every read that gates the insert goes
 * through customer-order-queries.php.
 *
 * This file is NOT safe to require from a page — it runs a full
 * request dispatch at load time.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL
 * ---------------------------------------------------------------------
 * The handler bootstraps the customer session before doing anything
 * else. The auth guard reads $_SESSION['customer_id'] and the CSRF
 * check reads $_SESSION['customer_csrf_token'], both inside the
 * customer session and guaranteed to be the customer's own.
 *
 * ---------------------------------------------------------------------
 * NOTE ON THE feedback TABLE
 * ---------------------------------------------------------------------
 * A customer review is a customer-only write. It does not move money,
 * does not change order status, and does not affect any other role's
 * ledger. It therefore belongs here and not in the shared
 * order-transaction layer.
 *
 * @package FitPal
 * @version 4.0 — Requires the renamed customer order query layer
 *                (customer-order-queries.php). The previous revision
 *                required order-queries.php, which has been renamed
 *                and stripped of cross-role money movement. No
 *                behavioural change.
 *
 *                (3.0: per-role session migration. 2.0: full
 *                rewrite. The previous file contents were orphaned
 *                query functions with no request dispatch, no auth
 *                guard, and no CSRF validation.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

// ---------------------------------------------------------------------
// RESPONSE HEADERS
// ---------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');

// ---------------------------------------------------------------------
// AUTHENTICATION
// ---------------------------------------------------------------------

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

// ---------------------------------------------------------------------
// DEPENDENCIES
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/customer-order-queries.php';

// ---------------------------------------------------------------------
// CSRF
//
// Validated against the customer context's own key,
// 'customer_csrf_token', inside the customer session.
// ---------------------------------------------------------------------

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['customer_csrf_token'] ?? '');

if (
    $sessToken === ''
    || $givenToken === ''
    || !hash_equals($sessToken, $givenToken)
) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$customerId = (int)$_SESSION['customer_id'];
$action     = (string)($_POST['action'] ?? '');

// ---------------------------------------------------------------------
// DISPATCH
// ---------------------------------------------------------------------

try {
    switch ($action) {
        case 'submit_review':
            handleSubmitReview($database_connection, $customerId);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log('Feedback handler DB error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred']);
} catch (Throwable $e) {
    error_log('Feedback handler error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred']);
}

/* -----------------------------------------------------------------
 * HANDLERS
 * ----------------------------------------------------------------- */

/**
 * Insert a product review for a delivered order.
 *
 * Expected POST fields:
 *   order_id   int
 *   product_id int
 *   rating     int   1..5
 *   comment    string  optional, max 1000 chars
 *
 * All ownership and state checks are delegated to canReviewProduct(),
 * which is the single source of truth for "may this customer review
 * this product in this order right now".
 */
function handleSubmitReview(PDO $db, int $customerId): void
{
    $orderId   = (int)($_POST['order_id']   ?? 0);
    $productId = (int)($_POST['product_id'] ?? 0);
    $rating    = (int)($_POST['rating']     ?? 0);
    $comment   = trim((string)($_POST['comment'] ?? ''));

    if ($orderId <= 0 || $productId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order or product']);
        return;
    }

    if ($rating < 1 || $rating > 5) {
        echo json_encode(['status' => 'error', 'message' => 'Rating must be between 1 and 5']);
        return;
    }

    if (strlen($comment) > 1000) {
        echo json_encode(['status' => 'error', 'message' => 'Review is too long (max 1000 characters)']);
        return;
    }

    if (!canReviewProduct($db, $orderId, $productId, $customerId)) {
        echo json_encode(['status' => 'error', 'message' => 'You cannot review this product']);
        return;
    }

    $stmt = $db->prepare(
        "INSERT INTO feedback
            (product_id, customer_id, order_id, rating, comment, date_posted)
         VALUES
            (:product_id, :customer_id, :order_id, :rating, :comment, NOW())"
    );
    $stmt->execute([
        ':product_id'  => $productId,
        ':customer_id' => $customerId,
        ':order_id'    => $orderId,
        ':rating'      => $rating,
        ':comment'     => $comment !== '' ? $comment : null,
    ]);

    echo json_encode([
        'status'  => 'success',
        'message' => 'Review submitted successfully',
    ]);
}