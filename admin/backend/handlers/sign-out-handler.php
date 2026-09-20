<?php
/**
 * FitPal Admin Sign-Out Handler
 *
 * Clears only admin-specific session data. Other role sessions
 * (customer, rider, restaurant) remain intact.
 *
 * @package FitPal
 * @version 1.0
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
    $_SESSION['admin_role']
);

session_regenerate_id(true);

header('Location: ../../pages/sign-in.php');
exit;