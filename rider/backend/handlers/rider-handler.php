<?php
/**
 * FitPal Rider Handler
 *
 * Actions:
 *   toggle_availability, update_profile, upload_picture,
 *   accept_assignment, decline_assignment, delivered,
 *   request_withdrawal
 *
 * Assignment model
 * ----------------
 * The kitchen assigns a rider, moving the order to 'rider_pending'.
 * The rider accepts (order → 'delivering') or declines (order →
 * 'preparing', rider freed). This handler is the only entry point for
 * those transitions.
 *
 * Availability model
 * ------------------
 * toggle_availability is the ONLY action in this file that writes
 * delivery_rider_profile.is_available. accept_assignment and
 * delivered deliberately do NOT touch it: a rider who was online when
 * they accepted an order stays online when they finish it. The
 * "one active delivery per rider" rule is enforced at assignment
 * time by the kitchen's order-queries.php::assignRiderToOrder(), not
 * by flipping this flag.
 *
 * Payout model
 * ------------
 * delivered runs inside a single transaction that (a) flips the
 * order to 'delivered' and (b) credits the rider's financial_account
 * via creditRiderForDelivery() from rider-queries.php. If either
 * write fails, both roll back. creditRiderForDelivery() is
 * idempotent per order, so a retry after a transient failure cannot
 * double-pay.
 *
 * @package FitPal
 * @version 5.1 — Moves the delivery payout constant to file scope.
 *                PHP does not allow `const` inside a function body;
 *                the previous revision declared it inside
 *                handleDelivered(), which is a compile-time error
 *                ("syntax error, unexpected token const") that took
 *                the entire handler offline — every action on this
 *                endpoint returned a 500 with an HTML error body,
 *                which the dashboard's fetch() could not parse as
 *                JSON. Moving the constant to file scope after
 *                declare(strict_types=1) restores the whole file.
 *
 *                No behavior change for any action. The value is
 *                still 50.00 and is still the single point of truth
 *                on the write path. The dashboard / earnings stats
 *                SQL in rider-queries.php still assumes the same flat
 *                rate; if the schedule ever changes, both places
 *                must move together.
 *
 *                (5.0: Delivery payout — handleDelivered() runs the
 *                order update and rider credit in one transaction
 *                with a FOR UPDATE lock on the order row. 4.1: CSRF
 *                validation reads rider_csrf_token instead of the
 *                shared csrf_token. 4.0: Introduced
 *                accept_assignment and decline_assignment; removed
 *                picked_up; tightened delivered; made
 *                toggle_availability refuse while a delivery is
 *                active.)
 */

declare(strict_types=1);

/**
 * Flat amount credited to a rider for each completed delivery.
 *
 * File-scope constant because PHP forbids `const` inside a function
 * body. Referenced by handleDelivered() below. The dashboard and
 * earnings stats SQL in rider-queries.php assume the same value, so
 * if this number ever changes, the queries that compute
 * today_earnings, week_earnings, month_earnings, and total_earnings
 * must change in lockstep — or be rewritten to SUM(transaction.amount)
 * for the delivered rows.
 */
const RIDER_DELIVERY_PAYOUT = 50.00;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_start();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['delivery_rider_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/rider-queries.php';

// Own the rider role's CSRF bootstrap. The helper is idempotent and
// stores the token under 'rider_csrf_token' — never the shared
// 'csrf_token' key. Requiring it here means this handler does not
// depend on the page that rendered the form having already generated
// the token, and it gives the mismatch branch below a key it can
// rotate.
require_once __DIR__ . '/../../includes/rider-csrf-token.php';

if (
    !isset($_POST['csrf_token'], $_SESSION['rider_csrf_token']) ||
    !hash_equals((string)$_SESSION['rider_csrf_token'], (string)$_POST['csrf_token'])
) {
    // Rotate the rider's own token so the next render generates a
    // fresh one. Without this the key stays set, getRiderCsrfToken()
    // returns the same stale value, and the client is stuck
    // re-submitting a token the handler has already rejected.
    // Only the rider's key is cleared — never the shared
    // 'csrf_token' key.
    unset($_SESSION['rider_csrf_token']);

    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$riderId = (int)$_SESSION['delivery_rider_id'];
$action  = (string)($_POST['action'] ?? '');

$response = ['status' => 'error', 'message' => 'Invalid action'];

try {
    switch ($action) {
        case 'toggle_availability':
            $response = handleToggleAvailability($database_connection, $riderId);
            break;

        case 'update_profile':
            $response = handleUpdateProfile($database_connection, $riderId);
            break;

        case 'upload_picture':
            $response = handleUploadPicture($database_connection, $riderId);
            break;

        case 'accept_assignment':
        case 'accept_order': // legacy alias, remove after one release
            $response = handleAcceptAssignment($database_connection, $riderId);
            break;

        case 'decline_assignment':
            $response = handleDeclineAssignment($database_connection, $riderId);
            break;

        case 'delivered':
            $response = handleDelivered($database_connection, $riderId);
            break;

        case 'request_withdrawal':
            $response = handleWithdrawal($database_connection, $riderId);
            break;
    }
} catch (PDOException $e) {
    error_log('Rider handler DB error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'A system error occurred. Please try again.'];
} catch (Throwable $e) {
    error_log('Rider handler error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'A system error occurred. Please try again.'];
}

ob_end_clean();
echo json_encode($response);
exit;

// ----------------------------------------------------------------

function handleToggleAvailability(PDO $db, int $riderId): array
{
    $isAvailable = (int)($_POST['is_available'] ?? 0) === 1 ? 1 : 0;

    $applied = setRiderAvailability($db, $riderId, $isAvailable);

    if (!$applied) {
        return [
            'status'  => 'error',
            'message' => 'You cannot go offline while you have an active delivery.',
        ];
    }

    return [
        'status'       => 'success',
        'message'      => $isAvailable
            ? 'You are now online and ready to accept deliveries.'
            : 'You are now offline.',
        'is_available' => $isAvailable,
    ];
}

function handleUpdateProfile(PDO $db, int $riderId): array
{
    $contactNumber = trim((string)($_POST['contact_number'] ?? ''));

    if ($contactNumber !== '' && !preg_match('/^09\d{9}$/', $contactNumber)) {
        return ['status' => 'error', 'message' => 'Invalid contact number (use 09XXXXXXXXX).'];
    }

    updateRiderContact($db, $riderId, $contactNumber);

    return ['status' => 'success', 'message' => 'Profile updated successfully.'];
}

function handleUploadPicture(PDO $db, int $riderId): array
{
    if (empty($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
        return ['status' => 'error', 'message' => 'No file uploaded or upload failed.'];
    }

    $file = $_FILES['profile_picture'];

    if ($file['size'] > 2 * 1024 * 1024) {
        return ['status' => 'error', 'message' => 'File must be under 2 MB.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (!isset($allowed[$mime])) {
        return ['status' => 'error', 'message' => 'Only JPG, PNG, WEBP, or GIF allowed.'];
    }

    $ext = $allowed[$mime];

    $projectRoot = realpath(__DIR__ . '/../../..');
    if ($projectRoot === false) {
        return ['status' => 'error', 'message' => 'Upload path unavailable.'];
    }

    $uploadDir = $projectRoot . '/shared/uploads/rider-profiles';

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            return ['status' => 'error', 'message' => 'Could not create upload directory.'];
        }
    }

    $filename = 'rider_' . $riderId . '_' . time() . '.' . $ext;
    $fullPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        return ['status' => 'error', 'message' => 'Could not save file.'];
    }

    $relativePath = 'shared/uploads/rider-profiles/' . $filename;

    $stmt = $db->prepare(
        "UPDATE delivery_rider_profile
            SET profile_picture = :pic
          WHERE delivery_rider_id = :rider_id"
    );
    $stmt->execute([
        ':pic'      => $relativePath,
        ':rider_id' => $riderId,
    ]);

    return [
        'status'  => 'success',
        'message' => 'Profile picture updated.',
        'path'    => $relativePath,
    ];
}

/**
 * Accept the kitchen's assignment.
 *
 * Eligibility gate: verified, online, fewer than two committed
 * orders, and the order is actually sitting in rider_pending with
 * this rider's id on it. acceptOrder() performs the state change.
 *
 * The rider's is_available flag is NOT touched here. Availability is
 * the rider's own toggle and must survive across deliveries.
 */
function handleAcceptAssignment(PDO $db, int $riderId): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    $profile = getRiderProfile($db, $riderId);
    if (!$profile || (string)($profile['verification_status'] ?? '') !== 'verified') {
        return ['status' => 'error', 'message' => 'Your account must be verified before accepting orders.'];
    }

    if ((int)($profile['is_available'] ?? 0) !== 1) {
        return ['status' => 'error', 'message' => 'Go online first to accept orders.'];
    }

    if (hasActiveOrder($db, $riderId) >= 2) {
        return [
            'status'  => 'error',
            'message' => 'You already have the maximum number of active orders. Complete one before accepting another.',
        ];
    }

    $accepted = acceptOrder($db, $riderId, $orderId);

    if (!$accepted) {
        return [
            'status'  => 'error',
            'message' => 'This assignment is no longer available. The kitchen may have reassigned or cancelled it.',
        ];
    }

    return [
        'status'  => 'success',
        'message' => 'Assignment accepted. Head to the restaurant for pickup.',
    ];
}

/**
 * Decline the kitchen's assignment.
 *
 * Returns the order to the kitchen's Preparing tab with no rider
 * attached. The rider's availability is untouched.
 */
function handleDeclineAssignment(PDO $db, int $riderId): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    $declined = declineOrder($db, $riderId, $orderId);

    if (!$declined) {
        return [
            'status'  => 'error',
            'message' => 'This assignment is no longer available to decline.',
        ];
    }

    return [
        'status'  => 'success',
        'message' => 'Assignment declined. The kitchen will choose another rider.',
    ];
}

/**
 * Mark a delivering order as delivered and credit the rider.
 *
 * Runs both writes inside one transaction:
 *   1. orders.order_status → 'delivered', delivered_at → NOW()
 *   2. insert a completed deposit into the rider's financial account
 *
 * The order row is locked FOR UPDATE before the update, so a
 * concurrent cancel / reassign cannot race the delivered transition.
 * If either write fails, the whole transaction rolls back. The
 * rider credit itself is idempotent per order (see
 * creditRiderForDelivery in rider-queries.php), so a retry after a
 * transient failure cannot double-pay.
 *
 * The payout amount lives in the file-scope RIDER_DELIVERY_PAYOUT
 * constant. The dashboard / earnings stats SQL in rider-queries.php
 * assumes the same flat rate; if the schedule ever changes, both
 * places must move together.
 */
function handleDelivered(PDO $db, int $riderId): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    $db->beginTransaction();

    try {
        // Lock the order row so a concurrent cancel / reassign cannot
        // race the delivered transition.
        $check = $db->prepare(
            "SELECT order_id
               FROM orders
              WHERE order_id = :order_id
                AND delivery_rider_id = :rider_id
                AND order_status = 'delivering'
              LIMIT 1
              FOR UPDATE"
        );
        $check->execute([
            ':order_id' => $orderId,
            ':rider_id' => $riderId,
        ]);

        if ($check->fetchColumn() === false) {
            $db->rollBack();
            return ['status' => 'error', 'message' => 'Order not eligible for delivery.'];
        }

        $update = $db->prepare(
            "UPDATE orders
                SET order_status = 'delivered',
                    delivered_at = NOW(),
                    updated_at   = NOW()
              WHERE order_id = :order_id
                AND delivery_rider_id = :rider_id
                AND order_status = 'delivering'"
        );
        $update->execute([
            ':order_id' => $orderId,
            ':rider_id' => $riderId,
        ]);

        if ($update->rowCount() === 0) {
            $db->rollBack();
            return ['status' => 'error', 'message' => 'Could not complete the delivery. Please try again.'];
        }

        // Credit the rider. The trigger on `transaction` moves the
        // balance; this call only inserts the row. If the order was
        // already credited (e.g. an admin override ran first), the
        // function returns false and nothing further happens.
        creditRiderForDelivery($db, $riderId, $orderId, RIDER_DELIVERY_PAYOUT);

        $db->commit();

        return [
            'status'  => 'success',
            'message' => 'Delivery marked as complete! '
                       . '₱' . number_format(RIDER_DELIVERY_PAYOUT, 2)
                       . ' added to your wallet.',
        ];

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function handleWithdrawal(PDO $db, int $riderId): array
{
    $amount = (float)($_POST['amount'] ?? 0);

    if ($amount < 100) {
        return ['status' => 'error', 'message' => 'Minimum withdrawal is ₱100.00.'];
    }

    $db->beginTransaction();

    try {
        $txnId = requestRiderWithdrawal($db, $riderId, $amount);

        if ($txnId === false) {
            $db->rollBack();
            return ['status' => 'error', 'message' => 'Insufficient balance or invalid amount.'];
        }

        $db->commit();

        return [
            'status'         => 'success',
            'message'        => 'Withdrawal request submitted. Processed within 24 hours.',
            'transaction_id' => $txnId,
            'amount'         => $amount,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}