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
 * @version 1.1
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/restaurant-queries.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['login_error'] = 'Invalid request method.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

if (
    !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])
) {
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
    $_SESSION['user_role']              = 'restaurant';
    $_SESSION['user_name']              = trim(
        ($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? '')
    );
    $_SESSION['user_email']             = (string)($account['email'] ?? '');
    $_SESSION['restaurant_role']        = (string)($account['role'] ?? 'owner');
    $_SESSION['business_name']          = (string)($account['business_name'] ?? '');
    $_SESSION['created']                = time();

    recordRestaurantLogin($database_connection, (int)$account['restaurant_account_id']);

    unset($_SESSION['csrf_token']);

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