<?php
/**
 * FitPal Rider Handler
 *
 * The rider-side dispatch endpoint for every rider action that is
 * not the assignment panel's own flow:
 *
 *   toggle_availability   → rider's own online / offline flag
 *   update_profile        → contact number
 *   upload_picture        → profile picture
 *   accept_assignment     → rider_pending → picking_up, then forward
 *                           to the shared handler
 *   accept_order          → legacy alias for accept_assignment
 *   decline_assignment    → rider_pending → preparing, rider cleared
 *   mark_picked_up        → picking_up → delivering, then forward to
 *                           the shared handler for the COD
 *                           collection write
 *   delivered             → forward to the shared handler, which runs
 *                           the delivered transition and writes the
 *                           credit pair
 *   request_withdrawal    → asks for a payout of the rider's balance
 *   check_sign_out        → read-only guard for the sign-out button
 *
 * ---------------------------------------------------------------------
 * TWO ACCEPT PATHS, ONE SHAPE
 * ---------------------------------------------------------------------
 * There are two accept endpoints in the rider role:
 *
 *   rider/backend/handlers/assignment-handler.php   (panel path)
 *   rider/backend/handlers/rider-handler.php        (deliveries path,
 *                                                    this file)
 *
 * Both must do the same thing, in the same order:
 *
 *   1. Run the four local guards.
 *   2. Verify the order is in rider_pending for this rider.
 *   3. Open a transaction.
 *   4. Call acceptOrder() to move the order to picking_up.
 *   5. Commit.
 *   6. Forward to the shared handler for the (no-op under v2.4.0)
 *      shared-handler record.
 *
 * The previous revision of this file performed steps 1, 2, and 6,
 * and skipped 3, 4, and 5. The order stayed at rider_pending forever
 * when accepted from the deliveries page, and every retry returned
 * success without moving anything. The panel's accept path had the
 * same bug and was fixed in file 2 of the v2.4.0 revision. This file
 * closes the second half.
 *
 * ---------------------------------------------------------------------
 * TWO PICKUP PATHS, ONE SHAPE
 * ---------------------------------------------------------------------
 * Same two endpoints, same reasoning. The pickup action moves the
 * order from picking_up to delivering and then forwards to the
 * shared handler so the COD collection row is written.
 *
 * ---------------------------------------------------------------------
 * WHERE THE MONEY RULES LIVE
 * ---------------------------------------------------------------------
 * This file does not write any ledger row.
 *
 * The accept path writes no ledger row at all under v2.4.0.
 * The delivery credit pair and the COD collection write are both
 * written by:
 *
 *     shared/backend/handlers/order-transaction-handler.php
 *
 * which reads the fee schedule in:
 *
 *     shared/backend/database/fee-queries.php
 *
 * ---------------------------------------------------------------------
 * CONCURRENT-ORDER CAP
 * ---------------------------------------------------------------------
 * The cap of 3 applies to the orders the rider has ACTUALLY
 * ACCEPTED — the ones in picking_up and delivering. An order in
 * rider_pending is a kitchen offer the rider has not yet decided on.
 *
 * ---------------------------------------------------------------------
 * AVAILABILITY MODEL
 * ---------------------------------------------------------------------
 * toggle_availability is the ONLY action in this file that writes
 * delivery_rider_profile.is_available. accept_assignment,
 * mark_picked_up, and delivered do NOT touch it.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL
 * ---------------------------------------------------------------------
 * The handler bootstraps the rider session before doing anything
 * else. The auth guard reads $_SESSION['delivery_rider_id'] and the
 * CSRF check reads $_SESSION['rider_csrf_token'].
 *
 * @package FitPal
 * @version 9.0 — The deliveries-page accept, pickup, and delivered
 *                paths are now shaped the same way the panel's
 *                paths are shaped:
 *
 *                - handleAcceptAssignment() runs the transition
 *                  itself before forwarding to the shared handler.
 *                - handleMarkPickedUp() runs the transition, then
 *                  forwards so the COD collection row is written.
 *                - handleDelivered() forwards to the shared handler
 *                  for both the transition and the credit pair. The
 *                  local call to creditRiderForDelivery() is gone;
 *                  that function no longer exists under v2.4.0.
 *
 *                (8.0: accept_assignment and delivered delegated
 *                their money movement to the shared handler. 7.2:
 *                committed-order cap on accept. 7.1: added
 *                check_sign_out. 7.0: picking_up intermediate
 *                status. 6.x: upload layout. 5.1: delivery payout
 *                constant at file scope. 5.0: delivery payout.
 *                4.1: rider_csrf_token. 4.0: accept + decline.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('rider');

ob_start();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['delivery_rider_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/rider-assignment-queries.php';

// Own the rider role's CSRF bootstrap.
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
            handleDeliveredDelegation();
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
// HANDLERS
// ----------------------------------------------------------------

/**
 * Answer the two questions the sign-out button needs before it can
 * let the rider leave.
 *
 * Read-only. Does not change availability, does not touch any order.
 *
 * @param PDO $db
 * @param int $riderId
 * @return array<string, mixed>
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
 *
 * @param string $uploadDir
 * @param string $ext
 * @return string
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
 *
 * @param PDO $db
 * @param int $riderId
 * @return array<string, mixed>
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

/**
 * Accept a rider_pending assignment.
 *
 * Three writes, in order:
 *
 *   1. Local guards — verified, online, not at cap, order still in
 *      rider_pending for this rider.
 *   2. Status transition, in this handler's own transaction.
 *      acceptOrder() moves the order from rider_pending to
 *      picking_up. If it returns false, the transaction rolls back
 *      and this handler refuses.
 *   3. Shared-handler handoff. Under v2.4.0 the shared handler's
 *      accept branch performs no ledger write; it verifies and
 *      returns. The forward keeps the client-visible response shape
 *      stable.
 *
 * @param PDO $db
 * @param int $riderId
 * @return array<string, mixed>
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

    if (riderAtConcurrentCap($db, $riderId)) {
        return [
            'status'  => 'error',
            'message' => 'You already have '
                       . RIDER_CONCURRENT_CAP
                       . ' accepted orders in progress. '
                       . 'Finish at least one before accepting another.',
        ];
    }

    $check = $db->prepare(
        "SELECT 1 FROM orders
          WHERE order_id = :order_id
            AND delivery_rider_id = :rider_id
            AND order_status = 'rider_pending'
          LIMIT 1"
    );
    $check->execute([
        ':order_id' => $orderId,
        ':rider_id' => $riderId,
    ]);
    if ($check->fetchColumn() === false) {
        return [
            'status'  => 'error',
            'message' => 'This assignment is no longer available. The kitchen may have reassigned or cancelled it.',
        ];
    }

    $db->beginTransaction();

    try {
        $accepted = acceptOrder($db, $riderId, $orderId);

        if (!$accepted) {
            $db->rollBack();
            return [
                'status'  => 'error',
                'message' => 'This assignment is no longer available. The kitchen may have reassigned or cancelled it.',
            ];
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    // The shared handler runs in this same process and the same
    // rider session. Under v2.4.0 its accept branch is a no-op on
    // the ledger; it verifies and returns. The forward keeps the
    // client-visible response shape stable.
    forwardToSharedHandler('rider_accept_assignment', $orderId);

    // forwardToSharedHandler always exits; this line is unreachable.
    return ['status' => 'error', 'message' => 'Unexpected state.'];
}

function handleDeclineAssignment(PDO $db, int $riderId): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    $db->beginTransaction();

    try {
        $declined = declineOrder($db, $riderId, $orderId);

        if (!$declined) {
            $db->rollBack();
            return [
                'status'  => 'error',
                'message' => 'This assignment is no longer available to decline.',
            ];
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return [
        'status'  => 'success',
        'message' => 'Assignment declined. The kitchen will choose another rider.',
    ];
}

/**
 * Mark the order as physically picked up.
 *
 * Two writes, in order:
 *
 *   1. Status transition, in this handler's own transaction.
 *      markOrderPickedUp() moves the order from picking_up to
 *      delivering.
 *   2. Shared-handler handoff. The shared handler writes the COD
 *      collection row for this order (and skips the write for
 *      Online and Wallet orders).
 *
 * @param PDO $db
 * @param int $riderId
 * @return array<string, mixed>
 */
function handleMarkPickedUp(PDO $db, int $riderId): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    $db->beginTransaction();

    try {
        $picked = markOrderPickedUp($db, $riderId, $orderId);

        if (!$picked) {
            $db->rollBack();
            return [
                'status'  => 'error',
                'message' => 'Could not mark this order as picked up. '
                           . 'It may already be in transit or was reassigned.',
            ];
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    forwardToSharedHandler('rider_mark_picked_up', $orderId);

    // forwardToSharedHandler always exits; this line is unreachable.
    return ['status' => 'error', 'message' => 'Unexpected state.'];
}

/**
 * Close a delivering order as delivered.
 *
 * Delegates the transition and the credit pair to the shared handler.
 * The local credit call that existed before v2.4.0 is gone; that
 * function no longer exists in the query layer.
 *
 * @return never
 */
function handleDeliveredDelegation(): never
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order.']);
        exit;
    }

    forwardToSharedHandler('rider_mark_delivered', $orderId);

    // forwardToSharedHandler always exits; this line is unreachable.
    exit;
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

/**
 * Forward the current request to the shared order-transaction
 * handler with a specific shared-action name.
 *
 * The shared handler runs in this same process and the same rider
 * session. It re-validates the rider role, re-checks the CSRF token,
 * performs its own guards, and terminates the request with a JSON
 * body.
 *
 * @param string $sharedAction
 * @param int    $orderId
 * @return never
 */
function forwardToSharedHandler(string $sharedAction, int $orderId): never
{
    $endpoint = __DIR__ . '/../../../shared/backend/handlers/order-transaction-handler.php';

    if (!is_file($endpoint)) {
        error_log('Rider handler: shared order-transaction handler is missing at ' . $endpoint);
        echo json_encode([
            'status'  => 'error',
            'message' => 'The order service is temporarily unavailable. Please try again.',
        ]);
        exit;
    }

    // The shared handler reads $_POST directly. Set the action name
    // it dispatches on.
    $_POST['action'] = $sharedAction;

    require $endpoint;

    // The shared handler always exits; this line is unreachable.
    exit;
}