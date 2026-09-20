<?php
/**
 * FitPal Restaurant Sign-Out Handler
 *
 * Clears only restaurant-specific session data.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset(
    $_SESSION['restaurant_account_id'],
    $_SESSION['restaurant_id'],
    $_SESSION['restaurant_branch_id'],
    $_SESSION['restaurant_branch_code'],
    $_SESSION['restaurant_branch_name'],
    $_SESSION['restaurant_scope'],
    $_SESSION['user_role'],
    $_SESSION['user_name'],
    $_SESSION['user_email'],
    $_SESSION['restaurant_role'],
    $_SESSION['business_name']
);

session_regenerate_id(true);

header('Location: ../../pages/sign-in.php');
exit;