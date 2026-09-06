<?php
/**
 * FitPal Sync Queue Handler
 * 
 * AJAX endpoint to sync queue items with the database.
 * Updated for new database schema with customization support.
 * 
 * @package FitPal
 * @version 3.0
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
    
    // Get current cart items with cart_ids
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
    
    // Remove items not in queue and their customizations
    foreach ($currentItems as $item) {
        if (!in_array($item['product_id'], $newIds)) {
            // Delete customizations first
            $delCustStmt = $database_connection->prepare(
                "DELETE ci FROM customization_instance ci
                 WHERE ci.cart_id = :cart_id"
            );
            $delCustStmt->execute([':cart_id' => $item['cart_id']]);
            
            // Delete cart item
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
        $customizations = $item['customizations'] ?? [];
        
        if ($quantity <= 0) {
            // Remove if quantity is 0
            $existingCartId = $currentMap[$productId] ?? null;
            if ($existingCartId) {
                // Delete customizations
                $delCustStmt = $database_connection->prepare(
                    "DELETE ci FROM customization_instance ci
                     WHERE ci.cart_id = :cart_id"
                );
                $delCustStmt->execute([':cart_id' => $existingCartId]);
                
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
        
        // Get product details (including new columns)
        $productStmt = $database_connection->prepare(
            "SELECT price, base_price, is_customizable FROM product WHERE product_id = :product_id"
        );
        $productStmt->execute([':product_id' => $productId]);
        $productData = $productStmt->fetch(PDO::FETCH_ASSOC);
        $price = $productData ? (float)$productData['price'] : 0;
        $basePrice = $productData ? (float)($productData['base_price'] ?? $productData['price']) : 0;
        $isCustomizable = $productData ? (bool)($productData['is_customizable'] ?? false) : false;
        
        // Check if exists
        $exists = in_array($productId, $currentIds);
        $cartId = null;
        
        if ($exists) {
            // Update existing cart item
            $cartId = $currentMap[$productId];
            $updateStmt = $database_connection->prepare(
                "UPDATE cart SET quantity = :quantity, updated_at = NOW() 
                 WHERE customer_id = :customer_id AND product_id = :product_id"
            );
            $updateStmt->execute([
                ':customer_id' => $customerId,
                ':product_id' => $productId,
                ':quantity' => $quantity
            ]);
            
            // Update customizations if product is customizable
            if ($isCustomizable) {
                // Delete existing customizations
                $delCustStmt = $database_connection->prepare(
                    "DELETE ci FROM customization_instance ci
                     WHERE ci.cart_id = :cart_id"
                );
                $delCustStmt->execute([':cart_id' => $cartId]);
            }
        } else {
            // Insert new cart item
            $insertStmt = $database_connection->prepare(
                "INSERT INTO cart (customer_id, product_id, quantity, price, added_at) 
                 VALUES (:customer_id, :product_id, :quantity, :price, NOW())"
            );
            $insertStmt->execute([
                ':customer_id' => $customerId,
                ':product_id' => $productId,
                ':quantity' => $quantity,
                ':price' => $price
            ]);
            $cartId = (int)$database_connection->lastInsertId();
        }
        
        // Insert customizations if product is customizable
        if ($isCustomizable && !empty($customizations) && $cartId) {
            foreach ($customizations as $cust) {
                // Get product_composition_id
                $compStmt = $database_connection->prepare(
                    "SELECT product_composition_id FROM product_composition 
                     WHERE product_id = :product_id 
                     AND composition_type = :composition_type"
                );
                $compStmt->execute([
                    ':product_id' => $productId,
                    ':composition_type' => $cust['composition_type'] ?? 'modifier'
                ]);
                $comp = $compStmt->fetch(PDO::FETCH_ASSOC);
                $compId = $comp ? $comp['product_composition_id'] : null;
                
                if ($compId) {
                    $insertCustStmt = $database_connection->prepare(
                        "INSERT INTO customization_instance 
                            (cart_id, product_composition_id, selected_option, 
                             customization_notes, ingredient_id)
                         VALUES 
                            (:cart_id, :comp_id, :selected_option, :notes, :ingredient_id)"
                    );
                    $insertCustStmt->execute([
                        ':cart_id' => $cartId,
                        ':comp_id' => $compId,
                        ':selected_option' => $cust['selected_option'] ?? null,
                        ':notes' => $cust['customization_notes'] ?? null,
                        ':ingredient_id' => $cust['ingredient_id'] ?? null
                    ]);
                }
            }
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