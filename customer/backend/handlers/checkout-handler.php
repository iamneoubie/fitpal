<?php
/**
 * FitPal Set Checkout Address Handler
 *
 * Persists the customer's currently-selected delivery address for
 * the checkout flow in the session. This is intentionally session-
 * scoped rather than DB-persisted because "which address am I using
 * for this order" is a transient, per-checkout concern — it should
 * not overwrite the customer's stored default.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/address-queries.php';

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
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