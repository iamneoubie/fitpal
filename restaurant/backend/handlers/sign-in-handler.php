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
 * @version 3.0 — Replaced the write to the dead shared key
 *                $_SESSION['created'] with the per-role activity
 *                marker $_SESSION['restaurant_last_activity']. The
 *                header's idle gate reads this key; the shared
 *                $_SESSION['created'] key is no longer used anywhere.
 *
 *                session_regenerate_id(true) remains — it is the one
 *                legal rotation point for the restaurant role and
 *                happens exactly once per sign-in.
 *
 *                (2.1: wrote the display name under the restaurant
 *                role's own key, restaurant_name. 2.0: validated
 *                against restaurant_csrf_token.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('restaurant');

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/restaurant-queries.php';

// Own the restaurant role's CSRF bootstrap.
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
    // sign-in.php generates a fresh one. Only the restaurant's key is
    // cleared — never the shared 'csrf_token' key.
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

    // Per-role activity marker. The header's idle gate reads this key.
    // The shared $_SESSION['created'] key is dead and must not be
    // written here.
    $_SESSION['restaurant_last_activity'] = time();

    recordRestaurantLogin($database_connection, (int)$account['restaurant_account_id']);

    // Only clear restaurant's own token. Do not touch the shared
    // 'csrf_token' key or any other role's token.
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