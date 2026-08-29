<?php
/**
 * FitPal Sync Queue Handler
 * 
 * AJAX endpoint to sync queue items with the database.
 * 
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Check if logged in
if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

// CSRF validation
$token = $input['csrf_token'] ?? '';
if (empty($token) || $token !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/cart-queries.php';

$customerId = (int)$_SESSION['customer_id'];
$queue = $input['queue'] ?? [];

try {
    // Start transaction
    $database_connection->beginTransaction();
    
    // Get current cart items
    $currentItems = getCustomerCart($database_connection, $customerId);
    $currentIds = array_column($currentItems, 'product_id');
    
    // New item IDs from queue
    $newIds = array_column($queue, 'product_id');
    
    // Remove items not in queue
    foreach ($currentItems as $item) {
        if (!in_array($item['product_id'], $newIds)) {
            $stmt = $database_connection->prepare(
                "DELETE FROM cart WHERE customer_id = ? AND product_id = ?"
            );
            $stmt->execute([$customerId, $item['product_id']]);
        }
    }
    
    // Update or insert items
    foreach ($queue as $item) {
        $productId = (int)$item['product_id'];
        $quantity = (int)$item['quantity'];
        
        if ($quantity <= 0) {
            // Remove if quantity is 0
            $stmt = $database_connection->prepare(
                "DELETE FROM cart WHERE customer_id = ? AND product_id = ?"
            );
            $stmt->execute([$customerId, $productId]);
            continue;
        }
        
        // Check if exists
        $exists = in_array($productId, $currentIds);
        
        if ($exists) {
            // Update
            $stmt = $database_connection->prepare(
                "UPDATE cart SET quantity = ? WHERE customer_id = ? AND product_id = ?"
            );
            $stmt->execute([$quantity, $customerId, $productId]);
        } else {
            // Insert
            $stmt = $database_connection->prepare(
                "INSERT INTO cart (customer_id, product_id, quantity, price) 
                 SELECT ?, ?, ?, price FROM product WHERE product_id = ?"
            );
            $stmt->execute([$customerId, $productId, $quantity, $productId]);
        }
    }
    
    $database_connection->commit();
    
    echo json_encode(['status' => 'success', 'message' => 'Queue synced']);
    
} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Sync queue error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}