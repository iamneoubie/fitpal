<?php
/**
 * FitPal Admin Handler
 *
 * Dispatches every admin mutation by the `action` POST field.
 *
 * Response shapes
 * ---------------
 * Two shapes, chosen per action:
 *
 *   JSON      — for actions whose only consumer is a fetch() from
 *               profile.js:
 *                 update_profile
 *                 upload_picture
 *
 *   Redirect  — for every other action. Those actions originate
 *               from a native form submit on a page that renders
 *               flashes. The handler flashes to the session and
 *               redirects back to the calling page.
 *
 * The JSON actions must never redirect, even on success: profile.js
 * reads the response body with JSON.parse() and would treat an HTML
 * redirect target as a failure. The redirect actions must never
 * return JSON: their forms expect a page navigation and would show
 * the raw JSON in the browser otherwise.
 *
 * Missing-handler note
 * --------------------
 * The dev-server log line
 *     [404] /admin/backend/handlers/admin-handler.php
 * followed by a 302 to the site's index means the handler file
 * was not present at that path on disk. The fix is simply to have
 * this file in place; the relative path the profile page and JS
 * already use resolves to exactly this location.
 *
 * This file contains NO SQL. Every read and write goes through
 * admin-queries.php.
 *
 * @package FitPal
 * @version 5.1 — update_profile now responds with JSON, matching
 *                what profile.js has always expected. The previous
 *                revision redirected on update_profile, which the
 *                fetch followed to profile.php and then parsed as
 *                HTML — producing the "non-JSON response (200)"
 *                error the profile page reported. upload_picture
 *                already responded with JSON and is unchanged.
 *
 *                Every other action keeps its redirect-and-flash
 *                behavior, because every other action originates
 *                from a native form submit on a page that renders
 *                flashes.
 *
 *                (5.0: Adds handleUploadPicture(). 4.0: Removed
 *                the `check_sign_out` action.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/admin-queries.php';

// Own the admin role's CSRF bootstrap.
require_once __DIR__ . '/../../includes/admin-csrf-token.php';

// ------------------------------------------------------------------
// Determine whether the current request expects a JSON response.
//
// profile.js sends fetch() POSTs that carry neither an
// X-Requested-With header nor an Accept: application/json header,
// because the FormData body is built for the multipart case. The
// reliable signal is the action name itself: the two JSON actions
// are update_profile and upload_picture. Everything else is a
// native form submit and must return a redirect.
// ------------------------------------------------------------------

$action  = (string)($_POST['action'] ?? '');
$isJsonAction = in_array($action, ['update_profile', 'upload_picture'], true);

// ------------------------------------------------------------------
// Auth
// ------------------------------------------------------------------

if (empty($_SESSION['administrator_id'])) {
    if ($isJsonAction) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
        exit;
    }
    header('Location: ../../pages/sign-in.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isJsonAction) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        exit;
    }
    header('Location: ../../pages/dashboard.php');
    exit;
}

// ------------------------------------------------------------------
// Redirect target (only used by the redirect actions)
// ------------------------------------------------------------------

$redirect = (string)($_POST['redirect_to'] ?? 'dashboard.php');

$allowedRedirects = [
    'dashboard.php', 'customers.php', 'riders.php', 'restaurants.php', 'profile.php',
];
if (!in_array($redirect, $allowedRedirects, true)) {
    $redirect = 'dashboard.php';
}

$redirectUrl = '../../pages/' . $redirect;

// ------------------------------------------------------------------
// CSRF
//
// Per-role. The admin role validates against its own session key,
// 'admin_csrf_token', never the shared 'csrf_token'. On mismatch
// the admin's own token is rotated so the next render generates a
// fresh one — the shared key is deliberately left alone.
// ------------------------------------------------------------------

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['admin_csrf_token'] ?? '');

if ($sessToken === '' || $givenToken === '' || !hash_equals($sessToken, $givenToken)) {
    unset($_SESSION['admin_csrf_token']);

    if ($isJsonAction) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Security validation failed. Please try again.']);
        exit;
    }

    $_SESSION['admin_error'] = 'Security validation failed. Please try again.';
    header('Location: ' . $redirectUrl);
    exit;
}

$adminId = (int)$_SESSION['administrator_id'];

// ------------------------------------------------------------------
// Dispatch
// ------------------------------------------------------------------

try {
    switch ($action) {

        /* ---------------------------------------------------------
         * JSON actions — the profile page's fetch() endpoints
         * --------------------------------------------------------- */

        case 'update_profile':
            handleUpdateProfile($database_connection, $adminId);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'success',
                'message' => 'Profile updated successfully.',
            ]);
            exit;

        case 'upload_picture':
            $response = handleUploadPicture($database_connection, $adminId);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($response);
            exit;

        /* ---------------------------------------------------------
         * Redirect actions — native form submits with a flash
         * --------------------------------------------------------- */

        case 'change_password':
            handleChangePassword($database_connection, $adminId);
            $_SESSION['admin_success'] = 'Password changed successfully.';
            break;

        case 'toggle_customer':
            handleToggleCustomer($database_connection);
            $_SESSION['admin_success'] = 'Customer status updated.';
            break;

        case 'set_rider_verification':
            handleSetRiderVerification($database_connection, $adminId);
            $_SESSION['admin_success'] = 'Rider verification status updated.';
            break;

        case 'toggle_rider':
            handleToggleRider($database_connection);
            $_SESSION['admin_success'] = 'Rider status updated.';
            break;

        case 'set_restaurant_verification':
            handleSetRestaurantVerification($database_connection, $adminId);
            $_SESSION['admin_success'] = 'Restaurant verification status updated.';
            break;

        case 'toggle_restaurant':
            handleToggleRestaurant($database_connection);
            $_SESSION['admin_success'] = 'Restaurant status updated.';
            break;

        default:
            $_SESSION['admin_error'] = 'Unknown action.';
    }
} catch (PDOException $e) {
    error_log('Admin handler DB error: ' . $e->getMessage());

    if ($isJsonAction) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'A database error occurred. Please try again.']);
        exit;
    }

    $_SESSION['admin_error'] = 'A database error occurred. Please try again.';

} catch (RuntimeException $e) {
    if ($isJsonAction) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }

    $_SESSION['admin_error'] = $e->getMessage();

} catch (Throwable $e) {
    error_log('Admin handler error: ' . $e->getMessage());

    if ($isJsonAction) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred.']);
        exit;
    }

    $_SESSION['admin_error'] = 'An unexpected error occurred.';
}

header('Location: ' . $redirectUrl);
exit;

/* =============================================================
 * ACTION HANDLERS
 * ============================================================= */

/**
 * Save the admin's editable profile fields and return success.
 *
 * The response shape is decided by the caller: this function either
 * returns normally (success) or throws a RuntimeException with a
 * user-readable message (validation failure). The dispatch switch
 * turns both outcomes into JSON because update_profile is a JSON
 * action.
 */
function handleUpdateProfile(PDO $db, int $adminId): void
{
    $firstName  = trim((string)($_POST['first_name'] ?? ''));
    $middleName = trim((string)($_POST['middle_name'] ?? ''));
    $lastName   = trim((string)($_POST['last_name'] ?? ''));
    $contact    = trim((string)($_POST['contact_number'] ?? ''));

    if (strlen($firstName) < 2) {
        throw new RuntimeException('First name must be at least 2 characters.');
    }
    if (strlen($lastName) < 2) {
        throw new RuntimeException('Last name must be at least 2 characters.');
    }

    if ($contact !== '' && !preg_match('/^09\d{9}$/', $contact)) {
        throw new RuntimeException('Contact number must be a valid PH mobile (09XXXXXXXXX).');
    }

    updateAdminProfile($db, $adminId, $firstName, $middleName, $lastName, $contact);
}

/**
 * Receive an uploaded admin profile picture, move it into place,
 * store the resulting project-root-relative path, and return a
 * JSON-shaped array.
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
 * The relative path follows the same convention as the customer
 * role's picture upload:
 *     shared/uploads/admin/profiles/{admin_id}/{MM_DD_YYYY}_{n}.{ext}
 *
 * @param PDO $db
 * @param int $adminId
 * @return array{status:string, message:string, path?:string}
 */
function handleUploadPicture(PDO $db, int $adminId): array
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

    $relativeDir = 'shared/uploads/admin/profiles/' . $adminId;
    $uploadDir   = $projectRoot . '/' . $relativeDir;

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return ['status' => 'error', 'message' => 'Could not create upload directory.'];
        }
    }

    $dayPrefix = date('m_d_Y');
    $existing  = @scandir($uploadDir) ?: [];

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
        updateAdminProfilePicture($db, $adminId, $relativePath);
    } catch (Throwable $e) {
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
        throw $e;
    }

    return [
        'status'  => 'success',
        'message' => 'Profile picture updated.',
        'path'    => $relativePath,
    ];
}

function handleChangePassword(PDO $db, int $adminId): void
{
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (strlen($new) < 8 || strlen($new) > 20) {
        throw new RuntimeException('New password must be 8–20 characters.');
    }
    if (!preg_match('/^[A-Za-z0-9]+$/', $new)) {
        throw new RuntimeException('Password can only contain letters and numbers.');
    }
    if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
        throw new RuntimeException('Password must contain at least one letter and one number.');
    }
    if ($new !== $confirm) {
        throw new RuntimeException('New password and confirmation do not match.');
    }

    $stored = getAdminPasswordHash($db, $adminId);

    $valid = password_verify($current, $stored);
    if (!$valid && hash_equals($stored, $current)) {
        $valid = true;
    }
    if (!$valid) {
        throw new RuntimeException('Current password is incorrect.');
    }

    $hashed = password_hash($new, PASSWORD_BCRYPT);
    updateAdminPassword($db, $adminId, $hashed);
}

function handleToggleCustomer(PDO $db): void
{
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $activate   = (string)($_POST['activate'] ?? '') === '1';
    if ($customerId <= 0) {
        throw new RuntimeException('Invalid customer.');
    }
    setCustomerActiveStatus($db, $customerId, $activate);
}

function handleSetRiderVerification(PDO $db, int $adminId): void
{
    $riderId = (int)($_POST['rider_id'] ?? 0);
    $status  = (string)($_POST['status'] ?? '');
    if ($riderId <= 0) {
        throw new RuntimeException('Invalid rider.');
    }
    if (!setRiderVerificationStatus($db, $riderId, $status, $adminId)) {
        throw new RuntimeException('Invalid verification status.');
    }
}

function handleToggleRider(PDO $db): void
{
    $riderId  = (int)($_POST['rider_id'] ?? 0);
    $activate = (string)($_POST['activate'] ?? '') === '1';
    if ($riderId <= 0) {
        throw new RuntimeException('Invalid rider.');
    }
    setRiderActiveStatus($db, $riderId, $activate);
}

function handleSetRestaurantVerification(PDO $db, int $adminId): void
{
    $restaurantId = (int)($_POST['restaurant_id'] ?? 0);
    $status       = (string)($_POST['status'] ?? '');
    if ($restaurantId <= 0) {
        throw new RuntimeException('Invalid restaurant.');
    }
    if (!setRestaurantVerificationStatus($db, $restaurantId, $status, $adminId)) {
        throw new RuntimeException('Invalid verification status.');
    }
}

function handleToggleRestaurant(PDO $db): void
{
    $restaurantId = (int)($_POST['restaurant_id'] ?? 0);
    $activate     = (string)($_POST['activate'] ?? '') === '1';
    if ($restaurantId <= 0) {
        throw new RuntimeException('Invalid restaurant.');
    }
    setRestaurantActiveStatus($db, $restaurantId, $activate);
}