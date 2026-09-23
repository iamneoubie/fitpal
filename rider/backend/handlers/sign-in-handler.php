<?php
/**
 * FitPal Rider Sign-In Handler
 *
 * Validates credentials against the delivery_rider table.
 * Contains no SQL — all data access goes through rider-queries.php.
 *
 * ---------------------------------------------------------------------
 * AVAILABILITY POLICY
 * ---------------------------------------------------------------------
 * Every successful sign-in forces is_available = 0. A rider must
 * explicitly toggle availability after logging in. This prevents the
 * rider from inheriting an "online" flag from a previous session and
 * matches the expectation that a rider only receives assignments
 * after opting in.
 * ---------------------------------------------------------------------
 *
 * ---------------------------------------------------------------------
 * DEVELOPMENT-ONLY BYPASS
 * ---------------------------------------------------------------------
 * Seed data stores plaintext passwords ("rider123"). password_verify()
 * only accepts bcrypt hashes, so the normal check fails against seeds.
 * The bypass below accepts the stored value as plaintext, matching the
 * customer handler's pattern. REMOVE before any non-local deployment.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 2.2 — Owns its own CSRF bootstrap and rotates the rider
 *                token on mismatch.
 *
 *                require_once on includes/rider-csrf-token.php makes
 *                this handler the authoritative reader of
 *                'rider_csrf_token' rather than an incidental one
 *                that only worked because the page which rendered
 *                the form had already called getRiderCsrfToken().
 *
 *                On the CSRF-mismatch branch the rider's token is
 *                now unset before redirecting, mirroring
 *                admin/sign-in-handler.php v1.5. Without that
 *                rotation, getRiderCsrfToken() on the next render of
 *                sign-in.php saw the key still set and returned the
 *                same stale value, so a user who hit a mismatch was
 *                stuck re-submitting the dead token until the
 *                session was cleared manually.
 *
 *                Only the rider's own key is touched. The shared
 *                'csrf_token' key is never read, written, or cleared
 *                by this file — other roles in the same PHP session
 *                may still depend on it.
 *
 *                (2.1: Validates against rider_csrf_token (own key)
 *                instead of the shared csrf_token, so a sign-in by
 *                another role in the same browser session can no
 *                longer delete/rotate the token this form relied on.
 *                Only unsets its own token key on success.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/rider-queries.php';

// Own the rider role's CSRF bootstrap. The helper is idempotent and
// stores the token under 'rider_csrf_token' — never the shared
// 'csrf_token' key.
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
    // generates a fresh one. Without this the key stays set,
    // getRiderCsrfToken() returns the same stale value, and the user
    // is stuck in a validation loop. Only the rider's key is cleared
    // — never the shared 'csrf_token' key.
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
    $_SESSION['user_role']         = 'rider';
    $_SESSION['user_name']         = trim(
        ($rider['first_name'] ?? '') . ' ' . ($rider['last_name'] ?? '')
    );
    $_SESSION['user_email']        = (string)($rider['email'] ?? '');
    $_SESSION['created']           = time();

    // Explicit opt-in required: force offline on every fresh sign-in.
    setRiderAvailability($database_connection, $riderId, 0);

    // Only clear rider's own token. Do not touch the shared
    // 'csrf_token' key or any other role's token — another role in
    // this same browser session may still be relying on it.
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