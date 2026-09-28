<?php
/**
 * FitPal Rider Handler
 *
 * Actions:
 *   toggle_availability, update_profile, upload_picture,
 *   accept_assignment, decline_assignment,
 *   mark_picked_up, delivered,
 *   request_withdrawal,
 *   check_sign_out
 *
 * Order lifecycle
 * ---------------
 *     rider_pending  --accept_assignment-->  picking_up
 *     picking_up     --mark_picked_up----->  delivering
 *     delivering     --delivered---------->  delivered
 *
 * Accepting no longer puts the order in transit. The rider must
 * take a second explicit action ("Mark Picked Up") once they have
 * the food in hand. No step may be skipped:
 *
 *   - accept_assignment only accepts from 'rider_pending'
 *   - mark_picked_up    only accepts from 'picking_up'
 *   - delivered         only accepts from 'delivering'
 *
 * Concurrent-order cap
 * --------------------
 * The cap of 3 applies to the orders the rider has ACTUALLY
 * ACCEPTED — the ones in 'picking_up' and 'delivering'. An order in
 * 'rider_pending' is a kitchen offer the rider has not yet decided
 * on; it does not occupy a delivery slot. A rider with 3 pending
 * offers can accept all 3. Once all 3 are accepted, the rider is at
 * the cap and must finish at least one before accepting a 4th.
 *
 * The same cap is enforced by the restaurant's assignRiderToOrder()
 * under a FOR UPDATE lock and by the SQL trigger
 * before_order_rider_assign — but only for committed orders.
 *
 * Availability model
 * ------------------
 * toggle_availability is the ONLY action in this file that writes
 * delivery_rider_profile.is_available. accept_assignment,
 * mark_picked_up, and delivered deliberately do NOT touch it: a
 * rider who was online when they accepted an order stays online
 * when they finish it. Going offline is refused while the rider
 * has any order in 'rider_pending', 'picking_up', or 'delivering'
 * — enforced by setRiderAvailability() in rider-queries.php.
 *
 * Sign-out eligibility
 * --------------------
 * check_sign_out is a read-only action. It answers two questions:
 *
 *   1. Does the rider have any live order right now?
 *   2. Is the rider currently online?
 *
 * A rider may only sign out when BOTH are false:
 *
 *   - every order is finished (no 'rider_pending', no 'picking_up',
 *     no 'delivering'), and
 *   - the rider is offline (is_available = 0).
 *
 * The client calls this before opening the sign-out confirmation
 * modal. When the answer is "not yet", the client opens a blocking
 * modal instead and tells the rider exactly which condition is
 * unmet.
 *
 * The check is deliberately read-only. It does NOT flip the rider
 * offline on the way out. Sign-out must never be a hidden state
 * change — the rider decides when to go offline, and they do that
 * from the assignment panel, not from the sign-out button.
 *
 * @package FitPal
 * @version 7.2 — The accept_assignment guard message now names the
 *                cap as "accepted orders" to match the committed-
 *                order count riderAtConcurrentCap() enforces.
 *                A rider with 3 pending offers but no accepted
 *                orders is no longer refused. No other action
 *                changed.
 *
 *                (7.1: adds check_sign_out. 7.0: picking_up
 *                intermediate status. 6.1: per-rider, per-day
 *                upload layout. 6.0: upload URL resolution.
 *                5.2: upload UPDATE delegated to rider-queries.php.
 *                5.1: delivery payout constant at file scope.
 *                5.0: delivery payout. 4.1: rider_csrf_token.
 *                4.0: accept + decline.)
 */

declare(strict_types=1);

/**
 * Flat amount credited to a rider for each completed delivery.
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
// 'csrf_token' key.
require_once __DIR__ . '/../../includes/rider-csrf-token.php';

if (
    !isset($_POST['csrf_token'], $_SESSION['rider_csrf_token']) ||
    !hash_equals((string)$_SESSION['rider_csrf_token'], (string)$_POST['csrf_token'])
) {
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
        case 'accept_order':
            $response = handleAcceptAssignment($database_connection, $riderId);
            break;

        case 'decline_assignment':
            $response = handleDeclineAssignment($database_connection, $riderId);
            break;

        case 'mark_picked_up':
            $response = handleMarkPickedUp($database_connection, $riderId);
            break;

        case 'delivered':
            $response = handleDelivered($database_connection, $riderId);
            break;

        case 'request_withdrawal':
            $response = handleWithdrawal($database_connection, $riderId);
            break;

        case 'check_sign_out':
            $response = handleCheckSignOut($database_connection, $riderId);
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

/**
 * Answer the two questions the sign-out button needs before it can
 * let the rider leave:
 *
 *   can_sign_out  true only when the rider has no live orders AND
 *                 is offline.
 *   is_online     the rider's current availability flag.
 *   active_count  number of orders in rider_pending / picking_up /
 *                 delivering.
 *
 * Read-only. Does not change availability, does not touch any
 * order. The client uses the three fields to decide whether to
 * open the normal sign-out confirmation or a blocking modal that
 * names the unmet condition.
 */
function handleCheckSignOut(PDO $db, int $riderId): array
{
    $profile = getRiderProfile($db, $riderId);

    if (!$profile) {
        return ['status' => 'error', 'message' => 'Rider not found.'];
    }

    $isOnline    = (int)($profile['is_available'] ?? 0) === 1;
    $activeCount = hasActiveOrder($db, $riderId);

    $canSignOut = (!$isOnline) && ($activeCount === 0);

    return [
        'status'       => 'success',
        'can_sign_out' => $canSignOut,
        'is_online'    => $isOnline,
        'active_count' => $activeCount,
    ];
}

function handleToggleAvailability(PDO $db, int $riderId): array
{
    $isAvailable = (int)($_POST['is_available'] ?? 0) === 1 ? 1 : 0;

    $applied = setRiderAvailability($db, $riderId, $isAvailable);

    if (!$applied) {
        return [
            'status'  => 'error',
            'message' => 'You cannot go offline while you have active orders. '
                       . 'Finish all your deliveries first.',
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

/**
 * Build the next MM_DD_YYYY_<n>.<ext> filename for a destination
 * folder.
 */
function buildRiderUploadFilename(string $uploadDir, string $ext): string
{
    $dayPrefix = date('m_d_Y');

    $existing = @scandir($uploadDir);
    if ($existing === false) {
        $existing = [];
    }

    $usedIndexes  = [];
    $prefixLength = strlen($dayPrefix) + 1;

    foreach ($existing as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (strpos($entry, $dayPrefix . '_') !== 0) {
            continue;
        }

        $rest   = substr($entry, $prefixLength);
        $dotPos = strpos($rest, '.');
        if ($dotPos === false) {
            continue;
        }

        $counterPart = substr($rest, 0, $dotPos);
        if ($counterPart === '' || !ctype_digit($counterPart)) {
            continue;
        }

        $usedIndexes[(int)$counterPart] = true;
    }

    $nextIndex = 0;
    while (isset($usedIndexes[$nextIndex])) {
        $nextIndex++;
    }

    return $dayPrefix . '_' . $nextIndex . '.' . $ext;
}

/**
 * Receive a profile picture upload, move the file into place, and
 * store its project-root-relative path.
 */
function handleUploadPicture(PDO $db, int $riderId): array
{
    if (empty($_FILES['profile_picture']) || !is_array($_FILES['profile_picture'])) {
        return ['status' => 'error', 'message' => 'No file uploaded.'];
    }

    $file = $_FILES['profile_picture'];

    if (!isset($file['error']) || is_array($file['error'])) {
        return ['status' => 'error', 'message' => 'Invalid upload payload.'];
    }

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['status' => 'error', 'message' => 'No file uploaded.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [
            'status'  => 'error',
            'message' => 'Upload failed (code ' . (int)$file['error'] . ').',
        ];
    }

    if (!isset($file['size']) || (int)$file['size'] <= 0) {
        return ['status' => 'error', 'message' => 'Uploaded file is empty.'];
    }

    if ((int)$file['size'] > 2 * 1024 * 1024) {
        return ['status' => 'error', 'message' => 'File must be under 2 MB.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return ['status' => 'error', 'message' => 'Could not inspect the uploaded file.'];
    }

    $mime = (string)finfo_file($finfo, (string)$file['tmp_name']);
    finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (!isset($allowed[$mime])) {
        return [
            'status'  => 'error',
            'message' => 'Unsupported file type. Use JPG, PNG, WEBP, or GIF.',
        ];
    }

    $ext = $allowed[$mime];

    $projectRoot = realpath(__DIR__ . '/../../..');
    if ($projectRoot === false) {
        return ['status' => 'error', 'message' => 'Server storage path unavailable.'];
    }

    $relativeDir = 'shared/uploads/rider/profiles/' . $riderId;
    $uploadDir   = $projectRoot . '/' . $relativeDir;

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return ['status' => 'error', 'message' => 'Could not create upload directory.'];
        }
    }

    $filename = buildRiderUploadFilename($uploadDir, $ext);

    $fullPath     = $uploadDir . '/' . $filename;
    $relativePath = $relativeDir . '/' . $filename;

    if (!move_uploaded_file((string)$file['tmp_name'], $fullPath)) {
        return ['status' => 'error', 'message' => 'Could not save the uploaded file.'];
    }

    try {
        updateRiderProfilePicture($db, $riderId, $relativePath);
    } catch (Throwable $e) {
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
        throw $e;
    }

    $url = '';

    $projectRootFs  = $projectRoot;
    $documentRootFs = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])
        ? realpath($_SERVER['DOCUMENT_ROOT'])
        : false;

    if ($projectRootFs !== false && $documentRootFs !== false) {
        $projectRootFs  = str_replace('\\', '/', $projectRootFs);
        $documentRootFs = rtrim(str_replace('\\', '/', $documentRootFs), '/');

        $urlPrefix = '';

        if ($projectRootFs === $documentRootFs) {
            $urlPrefix = '';
        } elseif (strpos($projectRootFs, $documentRootFs . '/') === 0) {
            $urlPrefix = substr($projectRootFs, strlen($documentRootFs));
        } else {
            $urlPrefix = '';
        }

        $url = $urlPrefix . '/' . ltrim($relativePath, '/');
    }

    return [
        'status'  => 'success',
        'message' => 'Profile picture updated.',
        'path'    => $relativePath,
        'url'     => $url,
    ];
}

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

    if (riderAtConcurrentCap($db, $riderId)) {
        return [
            'status'  => 'error',
            'message' => 'You already have '
                       . RIDER_CONCURRENT_CAP
                       . ' accepted orders in progress. '
                       . 'Finish at least one before accepting another.',
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
        'message' => 'Assignment accepted. Head to the restaurant to pick up the order.',
        'order_status' => 'picking_up',
    ];
}

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

function handleMarkPickedUp(PDO $db, int $riderId): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    $picked = markOrderPickedUp($db, $riderId, $orderId);

    if (!$picked) {
        return [
            'status'  => 'error',
            'message' => 'Could not mark this order as picked up. '
                       . 'It may already be in transit or was reassigned.',
        ];
    }

    return [
        'status'       => 'success',
        'message'      => 'Order picked up. Head to the customer.',
        'order_status' => 'delivering',
    ];
}

function handleDelivered(PDO $db, int $riderId): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    $db->beginTransaction();

    try {
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
            return [
                'status'  => 'error',
                'message' => 'Order is not eligible to be marked delivered. '
                           . 'Confirm the pickup step first.',
            ];
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