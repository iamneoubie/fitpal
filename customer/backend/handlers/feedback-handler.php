<?php
/**
 * FitPal Customer Feedback Handler
 *
 * The customer-facing HTTP endpoint for submitting a single review
 * envelope for a delivered order.
 *
 * ---------------------------------------------------------------------
 * DEPENDENCY PATHS
 * ---------------------------------------------------------------------
 * This file lives at:
 *
 *     fitpal/customer/backend/handlers/feedback-handler.php
 *
 * The query file this handler reads from lives at:
 *
 *     fitpal/customer/backend/handlers/feedback-queries.php
 *
 * Same directory. The require is therefore `__DIR__ . '/feedback-queries.php'`.
 *
 * The previous revision used `__DIR__ . '/../database/feedback-queries.php'`,
 * which resolved to `customer/backend/database/feedback-queries.php`.
 * That directory exists (it holds cart-queries.php, product-queries.php,
 * and the other customer query files) but does not contain
 * feedback-queries.php. The require fataled before any handler body
 * ran, and the request returned an HTML error page with HTTP 200,
 * which is why the client's JSON parser surfaced its generic
 * "Server returned an unexpected response (200)" message.
 *
 * Every other line in this file is byte-identical to v5.0.
 *
 * ---------------------------------------------------------------------
 * SCHEMA MODEL
 * ---------------------------------------------------------------------
 * The `feedback` table carries one envelope per (order_id, author).
 * The `rating` table carries one row per subject the customer scored.
 * A single POST creates:
 *
 *     one  feedback row
 *     N    rating rows
 *
 * where N is between 1 and the number of ratable subjects on the
 * order (its product queue items + its branches + its rider). The
 * whole write happens in one transaction; any failure rolls back
 * every row.
 *
 * ---------------------------------------------------------------------
 * PER-SUBJECT COMMENTS
 * ---------------------------------------------------------------------
 * The `feedback` table has one feedback_content column per order
 * (unique_feedback_per_author). Per-subject comments cannot each
 * own their own feedback row. This handler stores the per-subject
 * comments inside the single envelope's feedback_content column as
 * JSON:
 *
 *     {"comments": {
 *         "product:45":   "The salmon was fresh.",
 *         "restaurant:7": "Fast prep.",
 *         "rider:3":      "Very polite."
 *     }}
 *
 * A subject that was rated but not commented gets no key. An
 * envelope with no comments at all stores NULL.
 *
 * ---------------------------------------------------------------------
 * ACTION SET
 * ---------------------------------------------------------------------
 *   submit_review
 *
 * That is the only action.
 *
 * ---------------------------------------------------------------------
 * VALIDATION RULES
 * ---------------------------------------------------------------------
 *   1.  Session has customer_id.
 *   2.  CSRF token matches customer_csrf_token.
 *   3.  order_id is a positive integer.
 *   4.  Order belongs to the authenticated customer.
 *   5.  Order status is 'delivered'.
 *   6.  No feedback envelope already exists for
 *       (order_id, 'customer', customer_id).
 *   7.  At least one rating entry is present, OR at least one
 *       comment is present.
 *   8.  Every rating entry has a recognised `type`.
 *   9.  Every rating entry's `id` is verified against the order.
 *   10. Every rating entry's `score` is an integer 1..5.
 *   11. No two rating entries share the same (type, id) pair.
 *   12. Every comment key has the shape "type:id" and the type is
 *       recognised. A comment whose subject is not on the order is
 *       dropped silently.
 *
 * ---------------------------------------------------------------------
 * RESPONSE SHAPE
 * ---------------------------------------------------------------------
 *   success   { status:'success', message, feedback_id,
 *               rating_count, comment_count }
 *   failure   { status:'error', message }
 *
 * Business-rule failures are HTTP 200 with the error body. Only two
 * cases return a non-200 status: missing session (401) and CSRF
 * mismatch (403).
 *
 * @package FitPal
 * @version 5.1 — The feedback-queries.php require now targets the
 *                same directory as this file. The previous path
 *                walked one level up into customer/backend/database/,
 *                which does not contain the file. Every other line
 *                is byte-identical to v5.0.
 *
 *                (5.0: per-subject comments stored as JSON inside
 *                the one feedback envelope.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/customer-order-queries.php';
require_once __DIR__ . '/feedback-queries.php';

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

try {
    switch ($action) {
        case 'submit_review':
            handleSubmitReview($database_connection, $customerId);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Feedback handler DB error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred. Please try again.']);
} catch (RuntimeException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Feedback handler error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred.']);
}

/* =============================================================
 * HANDLERS
 * ============================================================= */

function handleSubmitReview(PDO $db, int $customerId): void
{
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order.']);
        return;
    }

    $ownership = getOrderOwnership($db, $orderId, $customerId);
    if (!$ownership) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found.']);
        return;
    }

    if ($ownership['order_status'] !== 'delivered') {
        echo json_encode([
            'status'  => 'error',
            'message' => 'You can only review an order that has been delivered.',
        ]);
        return;
    }

    $existing = getCustomerFeedbackForOrder($db, $orderId, $customerId);
    if ($existing !== false) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'You have already submitted a review for this order.',
        ]);
        return;
    }

    $orderItems = getOrderItemsWithCustomizations($db, $orderId);
    $branches   = getOrderRatableBranches($db, $orderId);
    $rider      = getOrderRatableRider($db, $orderId);

    $allowedProductIds = [];
    foreach ($orderItems as $item) {
        $qiId = (int)($item['queue_item_id'] ?? 0);
        if ($qiId > 0) {
            $allowedProductIds[$qiId] = true;
        }
    }

    $allowedBranchIds = [];
    foreach ($branches as $branch) {
        $bid = (int)($branch['branch_id'] ?? 0);
        if ($bid > 0) {
            $allowedBranchIds[$bid] = true;
        }
    }

    $allowedRiderId = ($rider !== false)
        ? (int)($rider['rider_id'] ?? 0)
        : 0;

    $rawRatings = $_POST['ratings'] ?? [];
    if (!is_array($rawRatings)) {
        $rawRatings = [];
    }

    $normalised = [];
    $seenPairs  = [];
    $validTypes = ['product', 'restaurant', 'rider'];

    foreach ($rawRatings as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $type  = (string)($entry['type']  ?? '');
        $id    = (int)   ($entry['id']    ?? 0);
        $score = (int)   ($entry['score'] ?? 0);

        if ($score === 0 && $id === 0 && $type === '') {
            continue;
        }

        if (!in_array($type, $validTypes, true)) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Invalid rating type.',
            ]);
            return;
        }

        if ($type === 'product') {
            if ($id <= 0 || !isset($allowedProductIds[$id])) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'One of the rated products is not part of this order.',
                ]);
                return;
            }
        } elseif ($type === 'restaurant') {
            if ($id <= 0 || !isset($allowedBranchIds[$id])) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'One of the rated restaurants is not part of this order.',
                ]);
                return;
            }
        } elseif ($type === 'rider') {
            if ($allowedRiderId <= 0 || $id !== $allowedRiderId) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'The rated rider is not the rider on this order.',
                ]);
                return;
            }
        }

        if ($score < 1 || $score > 5) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Ratings must be between 1 and 5 stars.',
            ]);
            return;
        }

        $pairKey = $type . ':' . $id;
        if (isset($seenPairs[$pairKey])) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'The same subject was rated more than once.',
            ]);
            return;
        }
        $seenPairs[$pairKey] = true;

        $normalised[] = [
            'type'  => $type,
            'id'    => $id,
            'score' => $score,
        ];
    }

    $rawComments = $_POST['comments'] ?? [];
    if (!is_array($rawComments)) {
        $rawComments = [];
    }

    $normalisedComments = [];
    foreach ($rawComments as $key => $text) {
        if (!is_string($key) || !is_string($text)) {
            continue;
        }

        $trimmed = trim($text);
        if ($trimmed === '') {
            continue;
        }

        if (strlen($trimmed) > 1000) {
            $trimmed = substr($trimmed, 0, 1000);
        }

        $parts = explode(':', $key, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $type = $parts[0];
        $id   = (int)$parts[1];

        if (!in_array($type, $validTypes, true) || $id <= 0) {
            continue;
        }

        $subjectOnOrder = false;
        if ($type === 'product' && isset($allowedProductIds[$id])) {
            $subjectOnOrder = true;
        } elseif ($type === 'restaurant' && isset($allowedBranchIds[$id])) {
            $subjectOnOrder = true;
        } elseif ($type === 'rider' && $allowedRiderId > 0 && $id === $allowedRiderId) {
            $subjectOnOrder = true;
        }

        if (!$subjectOnOrder) {
            continue;
        }

        $normalisedComments[$type . ':' . $id] = $trimmed;
    }

    if (empty($normalised) && empty($normalisedComments)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Please rate at least one thing or leave at least one comment before submitting.',
        ]);
        return;
    }

    $contentJson = null;
    if (!empty($normalisedComments)) {
        $encoded = json_encode(['comments' => $normalisedComments]);
        if ($encoded === false) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Could not encode your comments. Please try again.',
            ]);
            return;
        }
        $contentJson = $encoded;
    }

    $db->beginTransaction();

    try {
        $feedbackId = createFeedback(
            $db,
            $orderId,
            'customer',
            $customerId,
            $contentJson
        );

        $ratingCount = 0;
        foreach ($normalised as $entry) {
            createRating(
                $db,
                $feedbackId,
                $entry['type'],
                $entry['id'],
                $entry['score']
            );
            $ratingCount++;
        }

        $db->commit();

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    echo json_encode([
        'status'        => 'success',
        'message'       => 'Thank you for your review.',
        'feedback_id'   => $feedbackId,
        'rating_count'  => $ratingCount,
        'comment_count' => count($normalisedComments),
    ]);
}