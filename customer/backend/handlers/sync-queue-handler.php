<?php
/**
 * FitPal Sync Queue Handler
 * Version 4.1 - Sync using cart.customization_data JSON
 * 
 * @package FitPal
 * @version 4.1
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

$customerId = (int)$_SESSION['customer_id'];
$queue = $input['queue'] ?? [];

try {
    // Start transaction
    $database_connection->beginTransaction();
    
    // Get current cart items
    $stmt = $database_connection->prepare(
        "SELECT cart_id, product_id FROM cart WHERE customer_id = :customer_id"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $currentItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $currentMap = [];
    foreach ($currentItems as $item) {
        $currentMap[$item['product_id']] = $item['cart_id'];
    }
    $currentIds = array_keys($currentMap);
    
    // New item IDs from queue
    $newIds = array_column($queue, 'product_id');
    
    // Remove items not in queue
    foreach ($currentItems as $item) {
        if (!in_array($item['product_id'], $newIds)) {
            $deleteStmt = $database_connection->prepare(
                "DELETE FROM cart WHERE customer_id = :customer_id AND product_id = :product_id"
            );
            $deleteStmt->execute([
                ':customer_id' => $customerId,
                ':product_id' => $item['product_id']
            ]);
        }
    }
    
    // Update or insert items
    foreach ($queue as $item) {
        $productId = (int)$item['product_id'];
        $quantity = (int)$item['quantity'];
        $price = (float)($item['price'] ?? 0);
        $customizations = $item['customizations'] ?? [];
        
        if ($quantity <= 0) {
            // Remove if quantity is 0
            if (isset($currentMap[$productId])) {
                $deleteStmt = $database_connection->prepare(
                    "DELETE FROM cart WHERE customer_id = :customer_id AND product_id = :product_id"
                );
                $deleteStmt->execute([
                    ':customer_id' => $customerId,
                    ':product_id' => $productId
                ]);
            }
            continue;
        }
        
        // Get product details
        $productStmt = $database_connection->prepare(
            "SELECT price, base_price, is_customizable FROM product WHERE product_id = :product_id"
        );
        $productStmt->execute([':product_id' => $productId]);
        $productData = $productStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($productData) {
            $price = (float)$productData['price'];
        }
        
        // Store customizations as JSON
        $customizationData = !empty($customizations) ? json_encode($customizations) : null;
        
        // Check if exists
        $exists = in_array($productId, $currentIds);
        
        if ($exists) {
            // Update existing cart item
            $updateStmt = $database_connection->prepare(
                "UPDATE cart SET quantity = :quantity, price = :price, customization_data = :customization_data
                 WHERE customer_id = :customer_id AND product_id = :product_id"
            );
            $updateStmt->execute([
                ':customer_id' => $customerId,
                ':product_id' => $productId,
                ':quantity' => $quantity,
                ':price' => $price,
                ':customization_data' => $customizationData
            ]);
        } else {
            // Insert new cart item
            $insertStmt = $database_connection->prepare(
                "INSERT INTO cart (customer_id, product_id, quantity, price, added_at, customization_data) 
                 VALUES (:customer_id, :product_id, :quantity, :price, NOW(), :customization_data)"
            );
            $insertStmt->execute([
                ':customer_id' => $customerId,
                ':product_id' => $productId,
                ':quantity' => $quantity,
                ':price' => $price,
                ':customization_data' => $customizationData
            ]);
        }
    }
    
    $database_connection->commit();
    
    echo json_encode(['status' => 'success', 'message' => 'Queue synced']);
    
} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Sync queue error: ' . $e->getMessage());
    echo json_encode([
        'status' => 'error', 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}