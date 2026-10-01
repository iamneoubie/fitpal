<?php
/**
 * FitPal Set Checkout Address Handler
 *
 * Persists the customer's currently-selected delivery address for
 * the checkout flow in the customer session. Runs on the customer
 * session (PHPSESSID_CUSTOMER), separate from every other role's
 * session.
 *
 * This is intentionally session-scoped rather than DB-persisted
 * because "which address am I using for this order" is a
 * transient, per-checkout concern — it should not overwrite the
 * customer's stored default.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL (Option B)
 * ---------------------------------------------------------------------
 * The handler bootstraps the customer session before doing
 * anything else. Because the request that reaches this handler
 * carries only the customer cookie, the customer session is the
 * only session this code can see. The CSRF check reads
 * $_SESSION['customer_csrf_token'], and the write at the end
 * targets $_SESSION['checkout_address_id'] — both inside the
 * customer session, guaranteed to be the customer's own.
 *
 * @package FitPal
 * @version 2.0 — Per-role session migration (Option B). The
 *                handler bootstraps the customer session as its
 *                first executable statement. The obsolete
 *                cross-role commentary in the CSRF block is
 *                replaced with a note about the structural
 *                isolation that per-role sessions provide. No
 *                logic changed; no SQL moved.
 *
 *                (1.1: validated against customer_csrf_token with
 *                hash_equals; explicit empty-token rejection.)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// SESSION BOOTSTRAP
//
// Must run before any other include that might touch the session.
// This handler belongs to the customer context.
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

// ---------------------------------------------------------------------
// RESPONSE HEADERS
// ---------------------------------------------------------------------

header('Content-Type: application/json');

// ---------------------------------------------------------------------
// AUTHENTICATION
// ---------------------------------------------------------------------

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

// ---------------------------------------------------------------------
// DEPENDENCIES
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/address-queries.php';

// ---------------------------------------------------------------------
// CSRF
//
// Validated against the customer context's own key,
// 'customer_csrf_token', inside the customer session. Under
// Option B this key lives in a session that only requests bearing
// the customer cookie can reach, so the token is guaranteed to be
// the customer's own. The key name keeps the {role}_ prefix as a
// naming convention, not as a collision guard.
// ---------------------------------------------------------------------

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['customer_csrf_token'] ?? '');

if ($sessToken === '' || $givenToken === '' || !hash_equals($sessToken, $givenToken)) {
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$customerId = (int)$_SESSION['customer_id'];
$addressId  = (int)($_POST['address_id'] ?? 0);

if ($addressId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Address ID required']);
    exit;
}

// Ownership check — getAddressById() is scoped to the customer.
$address = getAddressById($database_connection, $addressId, $customerId);
if (!$address) {
    echo json_encode(['status' => 'error', 'message' => 'Address not found']);
    exit;
}

$_SESSION['checkout_address_id'] = $addressId;

echo json_encode([
    'status'     => 'success',
    'message'    => 'Checkout address updated',
    'address_id' => $addressId,
]);