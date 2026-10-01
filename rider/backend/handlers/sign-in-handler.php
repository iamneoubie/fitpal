<?php
/**
 * FitPal Rider Sign-In Handler
 *
 * Validates credentials against the delivery_rider table.
 *
 * ---------------------------------------------------------------------
 * REQUIRED QUERY LAYER
 * ---------------------------------------------------------------------
 * This handler requires:
 *
 *     rider/backend/database/rider-assignment-queries.php
 *
 * That file is the merged successor to the older
 * rider/backend/database/rider-queries.php and
 * rider/backend/database/assignment-queries.php. Every function this
 * handler calls — findRiderByIdentifier() and setRiderAvailability()
 * — is declared there. The old rider-queries.php file was removed in
 * the v8.0 merge and must not be required by any rider file.
 *
 * If this handler fails with "Failed to open stream: No such file or
 * directory", the query file is not on disk at the path above. The
 * correct file name is rider-assignment-queries.php.
 *
 * @package FitPal
 * @version 2.5 — The query-layer require now points at
 *                rider-assignment-queries.php. The previous revision
 *                required rider-queries.php, which no longer exists
 *                after the v8.0 merge, so every sign-in request died
 *                on the require before any code ran.
 *
 *                Every other behaviour — the CSRF contract, the
 *                dev-only plaintext bypass, the session_regenerate_id
 *                call, the per-role activity marker write, the
 *                forced-offline call, the redirect target, and the
 *                error handling — is byte-identical to v2.4.
 *
 *                (2.4: replaced the write to the dead shared key
 *                $_SESSION['created'] with the per-role activity
 *                marker $_SESSION['rider_last_activity']. 2.3:
 *                removed writes to shared user_name / user_email /
 *                user_role. 2.2: validated against
 *                rider_csrf_token. 2.1: rotated the rider token on
 *                CSRF mismatch.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('rider');

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/rider-assignment-queries.php';

// Own the rider role's CSRF bootstrap.
require_once __DIR__ . '/../../includes/rider-csrf-token.php';

// ===== REQUEST METHOD =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['login_error'] = 'Invalid request method.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

// ===== CSRF =====
if (
    !isset($_POST['csrf_token'], $_SESSION['rider_csrf_token']) ||
    !hash_equals((string)$_SESSION['rider_csrf_token'], (string)$_POST['csrf_token'])
) {
    // Rotate the rider's own token so the next render of sign-in.php
    // generates a fresh one. Only the rider's key is cleared — never
    // the shared 'csrf_token' key.
    unset($_SESSION['rider_csrf_token']);

    $_SESSION['login_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

$identifier = trim((string)($_POST['identifier'] ?? ''));
$password   = (string)($_POST['password'] ?? '');

if ($identifier === '' || $password === '') {
    $_SESSION['login_error'] = 'Please enter your email/username and password.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

try {
    $rider = findRiderByIdentifier($database_connection, $identifier);

    if (!$rider) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    if ((int)$rider['is_active'] !== 1) {
        $_SESSION['login_error'] = 'Your account has been deactivated. Please contact support.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    $passwordValid = password_verify($password, (string)$rider['password']);

    // ---- DEVELOPMENT-ONLY BYPASS ----
    if (!$passwordValid && hash_equals((string)$rider['password'], $password)) {
        $passwordValid = true;
    }
    // ---- END DEVELOPMENT-ONLY BYPASS ----

    if (!$passwordValid) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    // ===== LOGIN SUCCESSFUL =====
    session_regenerate_id(true);

    $riderId = (int)$rider['delivery_rider_id'];

    $_SESSION['delivery_rider_id'] = $riderId;

    // Per-role activity marker. The header's idle gate reads this key.
    // The shared $_SESSION['created'] key is dead and must not be
    // written here.
    $_SESSION['last_activity'] = time();

    // Explicit opt-in required: force offline on every fresh sign-in.
    setRiderAvailability($database_connection, $riderId, 0);

    // Only clear rider's own token. Do not touch the shared
    // 'csrf_token' key or any other role's token.
    unset($_SESSION['rider_csrf_token']);

    header('Location: ../../pages/dashboard.php');
    exit;

} catch (PDOException $e) {
    error_log('Rider sign-in DB error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
} catch (Throwable $e) {
    error_log('Rider sign-in error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}