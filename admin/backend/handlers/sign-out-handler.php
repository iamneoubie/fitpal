<?php
/**
 * FitPal Admin Sign-Out Handler
 *
 * Clears only admin-specific session data. Other role sessions
 * (customer, rider, restaurant) remain intact.
 *
 * @package FitPal
 * @version 1.2 — The unset list now targets admin's own namespaced
 *                keys after the sign-in handler was migrated to the
 *                {role}_ prefix convention in v1.6.
 *
 *                Removed from the unset list:
 *                    user_role   — no longer written by admin sign-in
 *                    user_name   — replaced by admin_name
 *                    user_email  — replaced by admin_email
 *
 *                Added to the unset list:
 *                    admin_name  — new admin-scoped display key
 *                    admin_email — new admin-scoped contact key
 *
 *                Previously this handler unset the generic user_*
 *                trio along with administrator_id, admin_role, and
 *                admin_csrf_token. Because those three generic keys
 *                were also written and read by the customer, rider,
 *                and restaurant roles — all sharing the same PHP
 *                session — an admin signing out wiped the customer's
 *                $_SESSION['user_name'] and left the customer
 *                dashboard greeting empty until the next customer
 *                page reload re-derived it. Removing them from this
 *                handler's unset list closes that reverse-direction
 *                collision.
 *
 *                The shared 'csrf_token' key is still deliberately
 *                left alone. Admin never read it and never wrote it;
 *                touching it here would risk breaking another role
 *                whose form is already rendered in this same browser
 *                session.
 *
 *                (1.1: Added admin_csrf_token to the unset list so
 *                the next admin sign-in generates a fresh token via
 *                sign-in.php instead of inheriting the previous
 *                administrator's token.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset(
    $_SESSION['administrator_id'],
    $_SESSION['admin_role'],
    $_SESSION['admin_name'],
    $_SESSION['admin_email'],
    $_SESSION['admin_csrf_token']
);

session_regenerate_id(true);

header('Location: ../../pages/sign-in.php');
exit;