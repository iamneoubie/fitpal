<?php
/**
 * FitPal Get Branch Handler
 *
 * AJAX endpoint to get branch details and products. Runs on the
 * customer session (PHPSESSID_CUSTOMER), separate from every other
 * role's session.
 *
 * The endpoint is read-only — it fetches data for display and does
 * not mutate any state — so it does not require a CSRF token. It
 * does require an authenticated customer.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL (Option B)
 * ---------------------------------------------------------------------
 * The handler bootstraps the customer session before doing
 * anything else. Because the request that reaches this handler
 * carries only the customer cookie, the customer session is the
 * only session this code can see. The auth guard reads
 * $_SESSION['customer_id'], which is guaranteed to be the
 * customer's own.
 *
 * @package FitPal
 * @version 2.0 — Per-role session migration (Option B). The
 *                handler bootstraps the customer session as its
 *                first executable statement. No logic changed; no
 *                SQL moved. No CSRF token was required before this
 *                revision and none is required now — the endpoint
 *                is read-only.
 *
 *                (1.0: initial version.)
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
// AUTHENTICATION
// ---------------------------------------------------------------------

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ---------------------------------------------------------------------
// DEPENDENCIES
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/branch-queries.php';

// ---------------------------------------------------------------------
// INPUT
// ---------------------------------------------------------------------

$branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

if ($branchId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid branch ID']);
    exit;
}

// ---------------------------------------------------------------------
// DISPATCH
// ---------------------------------------------------------------------

try {
    $branch = getBranchWithProducts($database_connection, $branchId);

    if (!$branch) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Branch not found']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode($branch);
    exit;

} catch (PDOException $e) {
    error_log('Get branch error: ' . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error occurred']);
    exit;
}