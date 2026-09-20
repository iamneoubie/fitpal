<?php
/**
 * FitPal Restaurant Sign-Out Handler
 *
 * Clears every restaurant-scoped session key, expires the session
 * cookie, regenerates the session ID, and redirects to the restaurant
 * sign-in page with no-cache headers so the browser cannot resurrect
 * a cached dashboard.
 *
 * Other role sessions in the same browser (customer, rider, admin)
 * are preserved.
 *
 * @package FitPal
 * @version 1.2 — Explicit cookie expiry + no-cache headers.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/* --------------------------------------------------------------
 * CLEAR ONLY RESTAURANT-SCOPED KEYS
 * -------------------------------------------------------------- */

$restaurantKeys = [
    'restaurant_account_id',
    'restaurant_id',
    'restaurant_branch_id',
    'restaurant_branch_code',
    'restaurant_branch_name',
    'restaurant_scope',
    'restaurant_role',
    'business_name',
    'login_scope',
    'login_error',
    'registration_success',
    'restaurant_registration_error',
];

foreach ($restaurantKeys as $key) {
    unset($_SESSION[$key]);
}

/* --------------------------------------------------------------
 * USER METADATA IS ONLY REMOVED IF NO OTHER ROLE OWNS IT
 *
 * If the same browser is also signed in as customer / rider / admin,
 * the shared user_role / user_name / user_email keys may be in use by
 * that other role. Only clear them when no other role session exists.
 * -------------------------------------------------------------- */

$otherRoleStillActive =
    !empty($_SESSION['customer_id']) ||
    !empty($_SESSION['delivery_rider_id']) ||
    !empty($_SESSION['administrator_id']);

if (!$otherRoleStillActive) {
    unset(
        $_SESSION['user_role'],
        $_SESSION['user_name'],
        $_SESSION['user_email']
    );
}

/* --------------------------------------------------------------
 * REGENERATE SESSION ID
 * -------------------------------------------------------------- */

session_regenerate_id(true);

/* --------------------------------------------------------------
 * EXPIRE THE SESSION COOKIE
 * -------------------------------------------------------------- */

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $params['path'] ?: '/',
            'domain'   => $params['domain'] ?: '',
            'secure'   => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}

/* --------------------------------------------------------------
 * REDIRECT
 * -------------------------------------------------------------- */

header('Location: ../../pages/sign-in.php?logged_out=1');
exit;