<?php
/**
 * FitPal Admin Sign-Out Handler
 *
 * Clears only admin-specific session data. Other role sessions
 * (customer, rider, restaurant) remain intact.
 *
 * @package FitPal
 * @version 1.1 — Now also clears admin_csrf_token, the admin role's
 *                own CSRF key. Previously the token survived sign-out
 *                and would be inherited by whichever admin signed in
 *                next on the same browser. Clearing it here means the
 *                next sign-in generates a fresh token via sign-in.php,
 *                matching the "logout clears the role's own auth
 *                state" contract. The shared 'csrf_token' key is
 *                deliberately left alone — other roles in the same
 *                browser session may still have forms open that
 *                depend on it.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset(
    $_SESSION['administrator_id'],
    $_SESSION['user_role'],
    $_SESSION['user_name'],
    $_SESSION['user_email'],
    $_SESSION['admin_role'],
    $_SESSION['admin_csrf_token']
);

session_regenerate_id(true);

header('Location: ../../pages/sign-in.php');
exit;