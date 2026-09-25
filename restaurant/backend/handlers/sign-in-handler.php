<?php
/**
 * FitPal Restaurant Sign-In Handler
 *
 * Supports two sign-in modes:
 *   - Owner:  role IN ('owner', 'partner')
 *   - Branch: role IN ('manager','staff','cashier','kitchen'),
 *             scoped to a specific branch_code
 *
 * @package FitPal
 * @version 2.1 — Writes the signed-in account's display name to the
 *                restaurant role's own session key, 'restaurant_name',
 *                instead of the shared 'user_name' key. Removes the
 *                writes to the shared 'user_email' and 'user_role'
 *                keys entirely, since nothing in the restaurant role
 *                reads them and every other role runs on the same PHP
 *                session — a restaurant sign-in writing those keys
 *                would clobber whatever the rider, customer, or admin
 *                role had stored under them.
 *
 *                Owns its own CSRF bootstrap and rotates the
 *                restaurant token on mismatch.
 *
 *                require_once on includes/restaurant-csrf-token.php
 *                makes this handler the authoritative reader of
 *                'restaurant_csrf_token' rather than an incidental
 *                one that only worked because the page which
 *                rendered the form had already called
 *                getRestaurantCsrfToken().
 *
 *                On the CSRF-mismatch branch the restaurant's token
 *                is now unset before redirecting. Without that
 *                rotation, getRestaurantCsrfToken() on the next
 *                render of sign-in.php saw the key still set and
 *                returned the same stale value, so a user who hit a
 *                mismatch was stuck re-submitting the dead token
 *                until the session was cleared manually.
 *
 *                Uses isset() on both keys before hash_equals() so an
 *                unset session key can never be coerced to an empty
 *                string and pass validation against an empty POST
 *                value.
 *
 *                Only the restaurant's own key is touched. The shared
 *                'csrf_token' key is never read, written, or cleared
 *                by this file — other roles in the same PHP session
 *                may still depend on it.
 *
 *                (2.0: Validated against restaurant_csrf_token (own
 *                key) instead of the shared csrf_token, so a sign-in
 *                by another role in the same browser session can no
 *                longer delete/rotate the token this form relied on.
 *                1.2: Same key split for the sign-in form itself.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/restaurant-queries.php';

// Own the restaurant role's CSRF bootstrap. The helper is idempotent
// and stores the token under 'restaurant_csrf_token' — never the
// shared 'csrf_token' key.
require_once __DIR__ . '/../../includes/restaurant-csrf-token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['login_error'] = 'Invalid request method.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

if (
    !isset($_POST['csrf_token'], $_SESSION['restaurant_csrf_token']) ||
    !hash_equals((string)$_SESSION['restaurant_csrf_token'], (string)$_POST['csrf_token'])
) {
    // Rotate the restaurant's own token so the next render of
    // sign-in.php generates a fresh one. Without this the key stays
    // set, getRestaurantCsrfToken() returns the same stale value, and
    // the user is stuck in a validation loop. Only the restaurant's
    // key is cleared — never the shared 'csrf_token' key.
    unset($_SESSION['restaurant_csrf_token']);

    $_SESSION['login_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

$identifier = trim((string)($_POST['identifier'] ?? ''));
$password   = (string)($_POST['password'] ?? '');
$roleScope  = (string)($_POST['role_scope'] ?? 'owner');
$branchCode = trim((string)($_POST['branch_code'] ?? ''));

if ($identifier === '' || $password === '') {
    $_SESSION['login_error'] = 'Please enter your email/username and password.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

if (!in_array($roleScope, ['owner', 'branch'], true)) {
    $roleScope = 'owner';
}

$_SESSION['login_scope'] = $roleScope;

try {
    if ($roleScope === 'branch') {
        if ($branchCode === '') {
            $_SESSION['login_error'] = 'Please select a branch.';
            header('Location: ../../pages/sign-in.php');
            exit;
        }
        $account = findBranchAccountByIdentifierAndCode(
            $database_connection,
            $identifier,
            $branchCode
        );
    } else {
        $account = findRestaurantAccountByScope(
            $database_connection,
            $identifier,
            'owner'
        );
    }

    if (!$account) {
        $_SESSION['login_error'] = 'Invalid credentials or account does not have access to this scope.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    if ((int)$account['is_active'] !== 1) {
        $_SESSION['login_error'] = 'Your account has been deactivated. Please contact support.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    if ((int)$account['restaurant_active'] !== 1) {
        $_SESSION['login_error'] = 'Your restaurant account is inactive. Please contact support.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    // -------- DEVELOPMENT-ONLY BYPASS --------
    $passwordValid = password_verify($password, (string)$account['password']);
    if (!$passwordValid && hash_equals((string)$account['password'], $password)) {
        $passwordValid = true;
    }
    // -------- END DEVELOPMENT-ONLY BYPASS --------

    if (!$passwordValid) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    session_regenerate_id(true);

    $_SESSION['restaurant_account_id']  = (int)$account['restaurant_account_id'];
    $_SESSION['restaurant_id']          = (int)$account['restaurant_id'];
    $_SESSION['restaurant_branch_id']   = $account['branch_id'] !== null
        ? (int)$account['branch_id']
        : null;
    $_SESSION['restaurant_branch_code'] = (string)($account['branch_code'] ?? '');
    $_SESSION['restaurant_branch_name'] = (string)($account['branch_name'] ?? '');
    $_SESSION['restaurant_scope']       = $roleScope;
    $_SESSION['restaurant_name']        = trim(
        ($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? '')
    );
    $_SESSION['restaurant_role']        = (string)($account['role'] ?? 'owner');
    $_SESSION['business_name']          = (string)($account['business_name'] ?? '');
    $_SESSION['created']                = time();

    recordRestaurantLogin($database_connection, (int)$account['restaurant_account_id']);

    // Only clear restaurant's own token. Do not touch the shared
    // 'csrf_token' key or any other role's token — another role in
    // this same browser session may still be relying on it.
    unset($_SESSION['restaurant_csrf_token']);

    header('Location: ../../pages/dashboard.php');
    exit;

} catch (PDOException $e) {
    error_log('Restaurant sign-in DB error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
} catch (Throwable $e) {
    error_log('Restaurant sign-in error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}