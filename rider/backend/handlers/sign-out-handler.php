<?php
/**
 * FitPal Rider Sign-Out Handler
 *
 * Clears only rider-specific session data. Does NOT call
 * session_destroy(), so any other role sessions in the same browser
 * (customer, admin, restaurant) remain intact.
 *
 * @package FitPal
 * @version 1.1 — File renamed from sign-out-handlers.php to match
 *                customer convention and the header.php link.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token         = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
$expectedToken = $_SESSION['csrf_token'] ?? '';

if ($token !== '' && $expectedToken !== '' && !hash_equals($expectedToken, $token)) {
    error_log('Rider sign-out: invalid CSRF token attempt');
}

unset(
    $_SESSION['delivery_rider_id'],
    $_SESSION['user_role'],
    $_SESSION['user_name'],
    $_SESSION['user_email']
);

session_regenerate_id(true);

header('Location: ../../pages/sign-in.php');
exit;