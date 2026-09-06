<?php
/**
 * FitPal Address Handler
 * Version 1.1 - Fixed customer_address_id update
 *
 * @package FitPal
 * @version 1.1
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Authentication check
if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

// CSRF validation
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$customerId = (int)$_SESSION['customer_id'];
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'add_address':
            handleAddAddress($database_connection, $customerId);
            break;
        case 'update_address':
            handleUpdateAddress($database_connection, $customerId);
            break;
        case 'delete_address':
            handleDeleteAddress($database_connection, $customerId);
            break;
        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log('Address handler error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred']);
}

function handleAddAddress(PDO $db, int $customerId): void
{
    $label = trim($_POST['label'] ?? '');
    $block = trim($_POST['block'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $region = trim($_POST['region'] ?? '');
    $postalCode = trim($_POST['postal_code'] ?? '');
    $country = trim($_POST['country'] ?? 'Philippines');

    if (empty($block) || empty($city)) {
        echo json_encode(['status' => 'error', 'message' => 'Block/Street and City are required']);
        return;
    }

    $db->beginTransaction();

    try {
        // Insert address
        $stmt = $db->prepare(
            "INSERT INTO customer_address (label, block, barangay, city, province, region, postal_code, country)
             VALUES (:label, :block, :barangay, :city, :province, :region, :postal_code, :country)"
        );
        $stmt->execute([
            ':label' => $label ?: null,
            ':block' => $block,
            ':barangay' => $barangay ?: null,
            ':city' => $city,
            ':province' => $province ?: null,
            ':region' => $region ?: null,
            ':postal_code' => $postalCode ?: null,
            ':country' => $country
        ]);
        $addressId = (int)$db->lastInsertId();

        // ===== FIX: Update customer's default address =====
        $stmt = $db->prepare(
            "UPDATE customer SET customer_address_id = :address_id WHERE customer_id = :customer_id"
        );
        $stmt->execute([
            ':address_id' => $addressId,
            ':customer_id' => $customerId
        ]);

        $db->commit();
        echo json_encode(['status' => 'success', 'message' => 'Address added successfully', 'address_id' => $addressId]);

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

    $label = trim($_POST['label'] ?? '');
    $block = trim($_POST['block'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $region = trim($_POST['region'] ?? '');
    $postalCode = trim($_POST['postal_code'] ?? '');
    $country = trim($_POST['country'] ?? 'Philippines');

    if (empty($block) || empty($city)) {
        echo json_encode(['status' => 'error', 'message' => 'Block/Street and City are required']);
        return;
    }

    // Verify address exists
    $stmt = $db->prepare(
        "SELECT customer_address_id FROM customer_address WHERE customer_address_id = :address_id"
    );
    $stmt->execute([':address_id' => $addressId]);
    if (!$stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Address not found']);
        return;
    }

    $db->beginTransaction();

    try {
        // Update address
        $stmt = $db->prepare(
            "UPDATE customer_address 
             SET label = :label, block = :block, barangay = :barangay, city = :city,
                 province = :province, region = :region, postal_code = :postal_code, country = :country
             WHERE customer_address_id = :address_id"
        );
        $stmt->execute([
            ':label' => $label ?: null,
            ':block' => $block,
            ':barangay' => $barangay ?: null,
            ':city' => $city,
            ':province' => $province ?: null,
            ':region' => $region ?: null,
            ':postal_code' => $postalCode ?: null,
            ':country' => $country,
            ':address_id' => $addressId
        ]);

        // ===== FIX: Ensure customer points to this address =====
        $stmt = $db->prepare(
            "UPDATE customer SET customer_address_id = :address_id WHERE customer_id = :customer_id"
        );
        $stmt->execute([
            ':address_id' => $addressId,
            ':customer_id' => $customerId
        ]);

        $db->commit();
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

    // Check if this is the customer's current address
    $stmt = $db->prepare(
        "SELECT customer_address_id FROM customer WHERE customer_id = :customer_id"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentAddressId = (int)($result['customer_address_id'] ?? 0);

    if ($addressId === $currentAddressId) {
        echo json_encode(['status' => 'error', 'message' => 'Cannot delete your default address']);
        return;
    }

    $db->beginTransaction();

    try {
        $stmt = $db->prepare(
            "DELETE FROM customer_address WHERE customer_address_id = :address_id"
        );
        $stmt->execute([':address_id' => $addressId]);

        $db->commit();
        echo json_encode(['status' => 'success', 'message' => 'Address deleted successfully']);

    } catch (PDOException $e) {
        $db->rollBack();
        throw $e;
    }
}