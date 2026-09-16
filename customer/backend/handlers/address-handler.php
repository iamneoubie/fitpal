<?php
/**
 * FitPal Address Handler
 *
 * @package FitPal
 * @version 2.2 — Invalidates $_SESSION['checkout_address_id'] on every
 *                successful add/update/delete so that checkout.php
 *                re-resolves against the current default on its next load.
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
$action     = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'add_address':    handleAddAddress($database_connection, $customerId);    break;
        case 'update_address': handleUpdateAddress($database_connection, $customerId); break;
        case 'delete_address': handleDeleteAddress($database_connection, $customerId); break;
        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log('Address handler error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred']);
}

// -----------------------------------------------------------------

function readAddressInput(): array
{
    return [
        'label'       => trim($_POST['label'] ?? ''),
        'block'       => trim($_POST['block'] ?? ''),
        'barangay'    => trim($_POST['barangay'] ?? ''),
        'city'        => trim($_POST['city'] ?? ''),
        'province'    => trim($_POST['province'] ?? ''),
        'region'      => trim($_POST['region'] ?? ''),
        'postal_code' => trim($_POST['postal_code'] ?? ''),
        'country'     => trim($_POST['country'] ?? 'Philippines'),
    ];
}

function handleAddAddress(PDO $db, int $customerId): void
{
    $data = readAddressInput();

    if ($data['block'] === '' || $data['city'] === '') {
        echo json_encode(['status' => 'error', 'message' => 'Block/Street and City are required']);
        return;
    }

    $db->beginTransaction();
    try {
        $addressId = createAddress($db, $customerId, $data);

        // Policy: a newly added address becomes the customer's default.
        // This overrides whatever createAddress() decided for is_default.
        $promoted = setCustomerDefaultAddress($db, $customerId, $addressId);
        if (!$promoted) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Could not set address as default']);
            return;
        }

        $db->commit();

        // The address landscape changed. Any previously chosen checkout
        // address is now stale by policy, so drop it. Next checkout load
        // re-resolves from the (new) default.
        unset($_SESSION['checkout_address_id']);

        echo json_encode([
            'status'     => 'success',
            'message'    => 'Address added successfully',
            'address_id' => $addressId,
        ]);
    } catch (PDOException $e) {
        $db->rollBack();
        throw $e;
    }
}

function handleUpdateAddress(PDO $db, int $customerId): void
{
    $addressId = (int)($_POST['address_id'] ?? 0);
    if ($addressId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Address ID required']);
        return;
    }

    $data = readAddressInput();
    if ($data['block'] === '' || $data['city'] === '') {
        echo json_encode(['status' => 'error', 'message' => 'Block/Street and City are required']);
        return;
    }

    // Scoped ownership check. getAddressById() now requires customerId,
    // so this only finds the address if it belongs to the current user.
    if (!getAddressById($db, $addressId, $customerId)) {
        echo json_encode(['status' => 'error', 'message' => 'Address not found']);
        return;
    }

    $db->beginTransaction();
    try {
        // updateAddress() returns false both for "wrong owner" and for
        // "identical values, no rows changed". We've already confirmed
        // ownership above, so a false here just means the values matched.
        updateAddress($db, $addressId, $customerId, $data);

        // Policy: editing an address makes it the customer's default.
        $promoted = setCustomerDefaultAddress($db, $customerId, $addressId);
        if (!$promoted) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Address not found']);
            return;
        }

        $db->commit();

        // The selected address may have had its fields changed, or its
        // default status flipped. Either way, checkout should re-resolve
        // rather than trust a cached session value.
        unset($_SESSION['checkout_address_id']);

        echo json_encode(['status' => 'success', 'message' => 'Address updated successfully']);
    } catch (PDOException $e) {
        $db->rollBack();
        throw $e;
    }
}

function handleDeleteAddress(PDO $db, int $customerId): void
{
    $addressId = (int)($_POST['address_id'] ?? 0);
    if ($addressId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Address ID required']);
        return;
    }

    // Scoped ownership check.
    if (!getAddressById($db, $addressId, $customerId)) {
        echo json_encode(['status' => 'error', 'message' => 'Address not found']);
        return;
    }

    // Policy: the customer must keep at least one address.
    // (deleteAddress() enforces this too, but checking here lets us
    // return a specific message instead of a generic failure.)
    $allAddresses = getCustomerAddresses($db, $customerId);
    if (count($allAddresses) <= 1) {
        echo json_encode(['status' => 'error', 'message' => 'You must keep at least one address']);
        return;
    }

    $db->beginTransaction();
    try {
        $deleted = deleteAddress($db, $addressId, $customerId);
        if (!$deleted) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Could not delete address']);
            return;
        }
        $db->commit();

        // The user may have deleted the address they were using for
        // checkout. Drop the cached session value so the next load picks
        // the new default (or, if none, renders the empty state).
        unset($_SESSION['checkout_address_id']);

        echo json_encode(['status' => 'success', 'message' => 'Address deleted successfully']);
    } catch (PDOException $e) {
        $db->rollBack();
        throw $e;
    }
}