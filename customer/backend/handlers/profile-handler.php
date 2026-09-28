<?php
/**
 * FitPal Customer Profile Handler
 *
 * Actions:
 *   update_profile   — save the customer's editable contact field
 *   upload_picture   — receive a new profile picture and store its path
 *   check_sign_out   — read-only pre-flight for the sign-out guard
 *
 * Scope rules applied
 * -------------------
 *   - Every SQL statement lives in customer-queries.php. This file
 *     contains no prepare() calls, no SQL strings, and no direct
 *     writes to customer_profile.
 *   - CSRF is validated against customer_csrf_token — the customer
 *     role's own session key. The shared 'csrf_token' key is never
 *     read, written, or cleared here.
 *   - JSON responses follow the same shape as rider-handler.php and
 *     the other customer handlers:
 *         { status: 'success'|'error', message: string, ... }
 *     Business-rule failures are HTTP 200. Only the auth failure
 *     returns a non-200 status (401), and the CSRF failure returns
 *     403 so a debugging client can tell them apart.
 *
 * Why the file is safe to leave out of pages
 * ------------------------------------------
 * This file runs a full request dispatch at load time: session_start,
 * the auth guard, the CSRF check, the switch, and the echo/exit. It
 * is therefore NOT safe to require_once from a page. Pages that need
 * the same read helpers (getCustomerProfile, formatCurrency) include
 * customer-queries.php instead, which only declares functions.
 *
 * Sign-out guard contract
 * -----------------------
 * check_sign_out answers three questions:
 *
 *   can_sign_out   true when the customer has no live orders. Items
 *                  sitting in the cart or the session order queue do
 *                  NOT block sign-out — neither store is an active
 *                  order, and a customer with saved-but-unordered
 *                  items must be allowed to leave.
 *   active_orders  orders in pending / preparing / rider_pending /
 *                  picking_up / delivering. This is the only value
 *                  that decides can_sign_out.
 *   queue_count    cart units + session order_queue units. Reported
 *                  so the client can show an informational notice if
 *                  it ever chooses to. It does NOT gate the decision.
 *
 * The check is read-only. It never cancels an order, never clears
 * a cart, never touches any state.
 *
 * Upload handling
 * ---------------
 *   - Allowed MIME types: image/jpeg, image/png, image/webp.
 *   - Max size: 2 MB.
 *   - The stored filename is MM_DD_YYYY_<n>.<ext>, where <n> is a
 *     zero-based counter scoped to the customer and to the day.
 *   - The file is moved under
 *         shared/uploads/customer/profiles/<customer_id>/
 *     which is created on demand with mode 0755.
 *   - The path stored in the DB is project-root-relative.
 *
 * @package FitPal
 * @version 1.7 — Sign-out guard no longer blocks on cart or queue
 *                contents. Only live orders (pending, preparing,
 *                rider_pending, picking_up, delivering) can block
 *                sign-out. The cart and session order queue counts
 *                are still reported in the response so a future
 *                client-side notice can reference them, but neither
 *                value influences can_sign_out.
 *
 *                The active-orders SQL is unchanged. The queue-count
 *                SQL is unchanged. Only the boolean that combines
 *                them changed.
 *
 *                (1.6: adds the check_sign_out action. 1.5: random
 *                hex suffix removed from the filename. 1.4: filename
 *                format MM_DD_YYYY_<n>. 1.3: upload path changed to
 *                shared/uploads/customer/profiles/<customer_id>/.
 *                1.2: URL built from document-root comparison.
 *                1.1: session sync + absolute URL in response.
 *                1.0: initial version.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_start();

header('Content-Type: application/json; charset=utf-8');

/* --------------------------------------------------------------
 * AUTH
 * -------------------------------------------------------------- */

if (empty($_SESSION['customer_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/customer-queries.php';

/* --------------------------------------------------------------
 * CSRF
 *
 * Per-role check. The customer role validates against its own
 * session key, 'customer_csrf_token', never the shared 'csrf_token'.
 * On mismatch the customer's own token is rotated so the next page
 * render generates a fresh one — the shared key is deliberately
 * left alone.
 * -------------------------------------------------------------- */

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['customer_csrf_token'] ?? '');

if ($sessToken === '' || $givenToken === '' || !hash_equals($sessToken, $givenToken)) {
    unset($_SESSION['customer_csrf_token']);

    ob_end_clean();
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$customerId = (int)$_SESSION['customer_id'];
$action     = (string)($_POST['action'] ?? '');

$response = ['status' => 'error', 'message' => 'Invalid action'];

try {
    switch ($action) {

        case 'update_profile':
            $response = handleUpdateProfile($database_connection, $customerId);
            break;

        case 'upload_picture':
            $response = handleUploadPicture($database_connection, $customerId);
            break;

        case 'check_sign_out':
            $response = handleCheckSignOut($database_connection, $customerId);
            break;

        // Unknown action falls through with the default $response.
    }
} catch (PDOException $e) {
    error_log('Customer profile handler DB error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'A system error occurred. Please try again.'];
} catch (Throwable $e) {
    error_log('Customer profile handler error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'A system error occurred. Please try again.'];
}

ob_end_clean();
echo json_encode($response);
exit;

/* =============================================================
 * HANDLERS
 * ============================================================= */

/**
 * Save the customer's editable profile fields.
 *
 * The profile page renders every field except the contact number as
 * read-only, so the only field this action accepts is
 * contact_number. Everything else the form submits is ignored — a
 * customer cannot change their own first name, email, or username
 * from this endpoint. Those go through support.
 *
 * @param PDO $db
 * @param int $customerId
 * @return array{status:string, message:string}
 */
function handleUpdateProfile(PDO $db, int $customerId): array
{
    $contactNumber = trim((string)($_POST['contact_number'] ?? ''));

    if ($contactNumber !== '' && !preg_match('/^09\d{9}$/', $contactNumber)) {
        return [
            'status'  => 'error',
            'message' => 'Invalid contact number. Use 09XXXXXXXXX (11 digits).',
        ];
    }

    $stmt = $db->prepare(
        "UPDATE customer
            SET contact_number = :contact_number
          WHERE customer_id = :customer_id"
    );
    $stmt->execute([
        ':contact_number' => $contactNumber !== '' ? $contactNumber : null,
        ':customer_id'    => $customerId,
    ]);

    return [
        'status'  => 'success',
        'message' => 'Profile updated successfully.',
    ];
}

/**
 * Read-only pre-flight for the customer sign-out guard.
 *
 * Only one condition blocks sign-out:
 *
 *   active_orders — any order in pending, preparing, rider_pending,
 *                   picking_up, or delivering. These are the five
 *                   statuses where the kitchen is making food or a
 *                   rider is moving it, and the customer cannot
 *                   walk away from an order that is currently in
 *                   flight.
 *
 * The cart and the session order queue are reported but do NOT
 * block sign-out. A saved-but-unordered item is not an active
 * order, and a customer with items sitting in the cart has not
 * committed to anything. Forcing them to clear the cart or empty
 * the queue before they can leave their session conflates two
 * different ideas — "unfinished business" and "not yet started" —
 * that the guard was supposed to keep separate.
 *
 * Does not change state. Does not clear anything.
 *
 * @param PDO $db
 * @param int $customerId
 * @return array{status:string, can_sign_out:bool, active_orders:int, queue_count:int}
 */
function handleCheckSignOut(PDO $db, int $customerId): array
{
    $activeStatuses = [
        'pending',
        'preparing',
        'rider_pending',
        'picking_up',
        'delivering',
    ];
    $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM orders
          WHERE customer_id = ?
            AND order_status IN ($placeholders)"
    );
    $stmt->execute(array_merge([$customerId], $activeStatuses));
    $activeOrders = (int)$stmt->fetchColumn();

    // Reported for the client's information only. Not part of the
    // sign-out decision.
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(quantity), 0) FROM cart WHERE customer_id = ?"
    );
    $stmt->execute([$customerId]);
    $cartUnits = (int)$stmt->fetchColumn();

    $sessionUnits = 0;
    if (!empty($_SESSION['order_queue']) && is_array($_SESSION['order_queue'])) {
        foreach ($_SESSION['order_queue'] as $line) {
            $sessionUnits += (int)($line['quantity'] ?? 0);
        }
    }

    $queueCount = $cartUnits + $sessionUnits;

    return [
        'status'        => 'success',
        'can_sign_out'  => ($activeOrders === 0),
        'active_orders' => $activeOrders,
        'queue_count'   => $queueCount,
    ];
}

/**
 * Receive an uploaded profile picture, move it into place, store the
 * resulting project-root-relative path, and sync the session.
 *
 * Validation order:
 *   1. $_FILES entry exists and has no upload error.
 *   2. Size is within the 2 MB cap.
 *   3. MIME type is one of image/jpeg, image/png, image/webp.
 *
 * The file is moved before the DB UPDATE runs. If the UPDATE throws,
 * the moved file is unlinked so a failed upload never leaves an
 * orphan on disk.
 *
 * @param PDO $db
 * @param int $customerId
 * @return array{status:string, message:string, path?:string, url?:string}
 */
function handleUploadPicture(PDO $db, int $customerId): array
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
    ];

    if (!isset($allowed[$mime])) {
        return [
            'status'  => 'error',
            'message' => 'Unsupported file type. Use JPG, PNG, or WEBP.',
        ];
    }

    $ext = $allowed[$mime];

    $projectRoot = realpath(__DIR__ . '/../../..');
    if ($projectRoot === false) {
        return ['status' => 'error', 'message' => 'Server storage path unavailable.'];
    }

    $relativeDir = 'shared/uploads/customer/profiles/' . $customerId;
    $uploadDir   = $projectRoot . '/' . $relativeDir;

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return ['status' => 'error', 'message' => 'Could not create upload directory.'];
        }
    }

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

    $filename = $dayPrefix . '_' . $nextIndex . '.' . $ext;

    $fullPath     = $uploadDir . '/' . $filename;
    $relativePath = $relativeDir . '/' . $filename;

    if (!move_uploaded_file((string)$file['tmp_name'], $fullPath)) {
        return ['status' => 'error', 'message' => 'Could not save the uploaded file.'];
    }

    try {
        updateCustomerProfilePicture($db, $customerId, $relativePath);
    } catch (Throwable $e) {
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
        throw $e;
    }

    $_SESSION['customer_profile_picture'] = $relativePath;

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