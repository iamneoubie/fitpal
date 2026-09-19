<?php
/**
 * FitPal Rider Handler
 *
 * Actions:
 *   toggle_availability, update_profile, upload_picture,
 *   picked_up, delivered, request_withdrawal
 *
 * @package FitPal
 * @version 2.1 — No closing PHP tag (prevents accidental output).
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Buffer output — prevents any stray whitespace from being sent early.
ob_start();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['delivery_rider_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/rider-queries.php';

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$riderId = (int)$_SESSION['delivery_rider_id'];
$action  = (string)($_POST['action'] ?? '');

$response = ['status' => 'error', 'message' => 'Invalid action'];

try {
    switch ($action) {
        case 'toggle_availability':
            $response = handleToggleAvailability($database_connection, $riderId);
            break;
        case 'update_profile':
            $response = handleUpdateProfile($database_connection, $riderId);
            break;
        case 'upload_picture':
            $response = handleUploadPicture($database_connection, $riderId);
            break;
        case 'picked_up':
            $response = handlePickedUp($database_connection, $riderId);
            break;
        case 'delivered':
            $response = handleDelivered($database_connection, $riderId);
            break;
        case 'request_withdrawal':
            $response = handleWithdrawal($database_connection, $riderId);
            break;
    }
} catch (PDOException $e) {
    error_log('Rider handler DB error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'A system error occurred. Please try again.'];
} catch (Throwable $e) {
    error_log('Rider handler error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'A system error occurred. Please try again.'];
}

ob_end_clean();
echo json_encode($response);
exit;

// ----------------------------------------------------------------

function handleToggleAvailability(PDO $db, int $riderId): array
{
    $isAvailable = (int)($_POST['is_available'] ?? 0) === 1 ? 1 : 0;

    setRiderAvailability($db, $riderId, $isAvailable);

    return [
        'status'       => 'success',
        'message'      => $isAvailable
            ? 'You are now online and ready to accept deliveries.'
            : 'You are now offline.',
        'is_available' => $isAvailable,
    ];
}

function handleUpdateProfile(PDO $db, int $riderId): array
{
    $contactNumber = trim((string)($_POST['contact_number'] ?? ''));

    if ($contactNumber !== '' && !preg_match('/^09\d{9}$/', $contactNumber)) {
        return ['status' => 'error', 'message' => 'Invalid contact number (use 09XXXXXXXXX).'];
    }

    updateRiderContact($db, $riderId, $contactNumber);

    return ['status' => 'success', 'message' => 'Profile updated successfully.'];
}

function handleUploadPicture(PDO $db, int $riderId): array
{
    if (empty($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
        return ['status' => 'error', 'message' => 'No file uploaded or upload failed.'];
    }

    $file = $_FILES['profile_picture'];

    if ($file['size'] > 2 * 1024 * 1024) {
        return ['status' => 'error', 'message' => 'File must be under 2 MB.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (!isset($allowed[$mime])) {
        return ['status' => 'error', 'message' => 'Only JPG, PNG, WEBP, or GIF allowed.'];
    }

    $ext = $allowed[$mime];

    // Project root is fitpal/. Handlers live at fitpal/rider/backend/handlers/.
    // So project root is __DIR__ . '/../../..'
    $projectRoot = realpath(__DIR__ . '/../../..');
    if ($projectRoot === false) {
        return ['status' => 'error', 'message' => 'Upload path unavailable.'];
    }

    $uploadDir = $projectRoot . '/shared/uploads/rider-profiles';

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            return ['status' => 'error', 'message' => 'Could not create upload directory.'];
        }
    }

    $filename = 'rider_' . $riderId . '_' . time() . '.' . $ext;
    $fullPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        return ['status' => 'error', 'message' => 'Could not save file.'];
    }

    $relativePath = 'shared/uploads/rider-profiles/' . $filename;

    $stmt = $db->prepare(
        "UPDATE delivery_rider_profile
            SET profile_picture = :pic
          WHERE delivery_rider_id = :rider_id"
    );
    $stmt->execute([
        ':pic'      => $relativePath,
        ':rider_id' => $riderId,
    ]);

    return [
        'status'  => 'success',
        'message' => 'Profile picture updated.',
        'path'    => $relativePath,
    ];
}

function handlePickedUp(PDO $db, int $riderId): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    $check = $db->prepare(
        "SELECT 1 FROM orders
         WHERE order_id = :order_id
           AND delivery_rider_id = :rider_id
           AND order_status = 'preparing'
         LIMIT 1"
    );
    $check->execute([':order_id' => $orderId, ':rider_id' => $riderId]);
    if ($check->fetchColumn() === false) {
        return ['status' => 'error', 'message' => 'Order not eligible for pickup.'];
    }

    $update = $db->prepare(
        "UPDATE orders
            SET order_status = 'delivering',
                updated_at = NOW()
          WHERE order_id = :order_id
            AND delivery_rider_id = :rider_id"
    );
    $update->execute([':order_id' => $orderId, ':rider_id' => $riderId]);

    return ['status' => 'success', 'message' => 'Order picked up. Head to the customer now!'];
}

function handleDelivered(PDO $db, int $riderId): array
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order.'];
    }

    $check = $db->prepare(
        "SELECT 1 FROM orders
         WHERE order_id = :order_id
           AND delivery_rider_id = :rider_id
           AND order_status = 'delivering'
         LIMIT 1"
    );
    $check->execute([':order_id' => $orderId, ':rider_id' => $riderId]);
    if ($check->fetchColumn() === false) {
        return ['status' => 'error', 'message' => 'Order not eligible for delivery.'];
    }

    $update = $db->prepare(
        "UPDATE orders
            SET order_status = 'delivered',
                delivered_at = NOW(),
                updated_at = NOW()
          WHERE order_id = :order_id
            AND delivery_rider_id = :rider_id"
    );
    $update->execute([':order_id' => $orderId, ':rider_id' => $riderId]);

    return ['status' => 'success', 'message' => 'Delivery marked as complete!'];
}

function handleWithdrawal(PDO $db, int $riderId): array
{
    $amount = (float)($_POST['amount'] ?? 0);

    if ($amount < 100) {
        return ['status' => 'error', 'message' => 'Minimum withdrawal is ₱100.00.'];
    }

    $db->beginTransaction();

    try {
        $txnId = requestRiderWithdrawal($db, $riderId, $amount);

        if ($txnId === false) {
            $db->rollBack();
            return ['status' => 'error', 'message' => 'Insufficient balance or invalid amount.'];
        }

        $db->commit();

        return [
            'status'         => 'success',
            'message'        => 'Withdrawal request submitted. Processed within 24 hours.',
            'transaction_id' => $txnId,
            'amount'         => $amount,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}