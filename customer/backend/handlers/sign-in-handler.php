<?php
/**
 * FitPal Customer Sign-In Handler
 *
 * @package FitPal
 * @version 2.1 — Validates against customer_csrf_token (own key)
 *                instead of the shared csrf_token, so a sign-in by
 *                another role in the same browser session can no
 *                longer delete/rotate the token this form relied on.
 *                Only unsets its own token key on success. See
 *                sign-in.php v1.2 for the full explanation.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/customer-queries.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['login_error'] = 'Invalid request method.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

if (!isset($_POST['csrf_token'], $_SESSION['customer_csrf_token']) ||
    !hash_equals($_SESSION['customer_csrf_token'], $_POST['csrf_token'])) {
    $_SESSION['login_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

$identifier = trim($_POST['identifier'] ?? '');
$password   = $_POST['password'] ?? '';

if ($identifier === '' || $password === '') {
    $_SESSION['login_error'] = 'Please enter your email/username and password.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

try {
    $customer = findCustomerByIdentifier($database_connection, $identifier);

    if (!$customer) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    if ((int)$customer['is_active'] !== 1) {
        $_SESSION['login_error'] = 'Your account has been deactivated. Please contact support.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    $isPasswordValid = false;

    if (password_verify($password, $customer['password'])) {
        $isPasswordValid = true;
    }

    // ============================================================
    // SECURITY WARNING — DEVELOPMENT-ONLY CODE
    // ============================================================
    //
    // WHAT THIS DOES:
    //   If the normal password_verify() check fails, this fallback
    //   compares the submitted password directly against the value
    //   stored in customer.password using a plain string equality.
    //
    //   In effect: whoever submitted the request can log in as ANY
    //   customer simply by pasting that customer's stored password
    //   hash (from the database) into the password field. No
    //   knowledge of the original plaintext password is required.
    //
    // WHY IT EXISTS:
    //   The seed data (sql/sample/seed-data.sql) stores passwords as
    //   PLAINTEXT ("user123", "owner123", etc.) instead of bcrypt
    //   hashes. During early development, password_verify() always
    //   returns false against those rows, which blocks login. This
    //   block lets the demo work without re-seeding.
    //
    // WHY IT IS DANGEROUS:
    //   - It is a complete authentication bypass. Anyone who can
    //     read a single row from customer.password — via SQL
    //     injection, a database backup leak, an error page, or a
    //     debug dump — can log in as that customer with no further
    //     effort.
    //   - It defeats the entire purpose of password hashing. bcrypt's
    //     slowness is meant to make offline cracking expensive. This
    //     block removes the need to crack at all.
    //   - It is not gated by any environment check, build flag, or
    //     configuration value. It is active in every environment
    //     that runs this file as-is, including production.
    //
    // WHEN TO REMOVE:
    //   Before this project is deployed anywhere other than a local
    //   development machine. Specifically:
    //     - Before pushing to any shared/staging/production server.
    //     - Before any demo where the database is reachable by
    //       anyone other than the developer.
    //     - Before any submission that includes a live database.
    //
    // HOW TO REMOVE PROPERLY:
    //   1. Delete this entire `if (!$isPasswordValid) { ... }` block.
    //   2. Re-seed the database with real hashes. The correct way is
    //      to run each seed password through password_hash() and
    //      store the result. See the note below for a one-time
    //      migration approach.
    //   3. Verify that sign-in works with the seeded plaintext
    //      passwords through password_verify() alone.
    //
    // IF YOU ABSOLUTELY MUST KEEP IT FOR LOCAL DEV:
    //   Gate it behind an environment variable that does NOT exist
    //   in production. For example:
    //
    //     if (!$isPasswordValid && getenv('FITPAL_DEV_BYPASS') === '1') {
    //         // ... bypass logic ...
    //     }
    //
    //   Then set FITPAL_DEV_BYPASS=1 only in your local shell or a
    //   .env file that is gitignored. Never commit the value. Never
    //   set it on a shared or production host. This makes the bypass
    //   inert by default and requires an explicit, deliberate action
    //   to enable — but it is still a footgun and the safest option
    //   is removal.
    //
    // RELATED:
    //   - customer/backend/database/customer-queries.php : findCustomerByIdentifier()
    //   - sql/sample/seed-data.sql : the plaintext passwords that
    //     make this bypass necessary in the first place.
    //   - customer/backend/handlers/sign-up-handler.php : the
    //     correct pattern — it uses password_hash() before insert.
    //
    // ============================================================
    // Development bypass — accept a stored hash pasted in as plaintext.
    // ============================================================
    if (!$isPasswordValid) {
        $clean = trim($password);
        if (hash_equals((string)$customer['password'], $clean)) {
            $isPasswordValid = true;
        }
    }
    // ============================================================
    // END DEVELOPMENT-ONLY BLOCK
    // ============================================================

    if (!$isPasswordValid) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    session_regenerate_id(true);

    $_SESSION['customer_id']    = (int)$customer['customer_id'];
    $_SESSION['user_role']      = 'customer';
    $_SESSION['user_name']      = trim($customer['first_name'] . ' ' . $customer['last_name']);
    $_SESSION['user_email']     = $customer['email'];
    $_SESSION['user_username']  = $customer['username'];
    $_SESSION['created']        = time();

    // Only clear customer's own token. Do not touch the shared
    // 'csrf_token' key or any other role's token — another role in
    // this same browser session may still be relying on it.
    unset($_SESSION['customer_csrf_token']);

    header('Location: ../../pages/dashboard.php');
    exit;

} catch (PDOException $e) {
    error_log('Customer sign-in error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}