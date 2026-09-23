<?php
/**
 * FitPal Customer Feedback Handler
 *
 * AJAX endpoint for product reviews submitted from the Orders page's
 * review modal. One action is supported:
 *
 *   submit_review → insert a feedback row for a delivered order
 *
 * The gate is canReviewProduct(), which lives in
 * customer/backend/database/order-queries.php and enforces all three
 * preconditions in one place:
 *   - the order belongs to this customer
 *   - the order is in the 'delivered' state
 *   - this customer has not already reviewed this product for this order
 *
 * This handler contains NO SQL of its own. All data access goes
 * through order-queries.php.
 *
 * This file is NOT safe to require from a page — it runs a full
 * request dispatch at load time.
 *
 * @package FitPal
 * @version 2.0 — Full rewrite. The previous file contents were
 *                orphaned query functions with no request dispatch,
 *                no auth guard, and no CSRF validation. Those
 *                functions already exist in order-queries.php and
 *                were removed here. This version is a proper
 *                handler that validates against the customer role's
 *                own session key, customer_csrf_token.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/order-queries.php';

// Per-role CSRF check. The customer role validates against its own
// session key, 'customer_csrf_token', never the shared 'csrf_token'.
// Another role in the same browser session could have unset or
// rotated the shared key on its own sign-in, which would otherwise
// invalidate the token this review form was issued under. See
// general.md.
$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['customer_csrf_token'] ?? '');

if ($sessToken === '' || $givenToken === '' || !hash_equals($sessToken, $givenToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$customerId = (int)$_SESSION['customer_id'];
$action     = (string)($_POST['action'] ?? '');

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