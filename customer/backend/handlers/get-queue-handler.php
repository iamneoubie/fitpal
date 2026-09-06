<?php
/**
 * FitPal Get Queue Handler
 * 
 * AJAX endpoint to retrieve the customer's current queue (cart).
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
    echo json_encode(['status' => 'error', 'message' => 'Not logged in', 'queue' => []]);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

$customerId = (int)$_SESSION['customer_id'];

try {
    // Get cart items with product details - Updated for new PRODUCT columns
    $stmt = $database_connection->prepare(
        "SELECT 
            c.product_id,
            p.name AS product_name,
            p.description,
            c.quantity,
            c.price,
            p.stock AS product_stock,
            p.is_customizable,
            p.customization_type,
            p.base_price,
            COALESCE(di.images, '') AS product_image,
            r.business_name AS restaurant_name,
            rb.branch_name
        FROM cart c
        JOIN product p ON c.product_id = p.product_id
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE c.customer_id = :customer_id
        ORDER BY c.added_at DESC"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $queue = [];
    foreach ($cartItems as $item) {
        // Check for customizations on this item
        $customizations = [];
        if ($item['is_customizable']) {
            $custStmt = $database_connection->prepare(
                "SELECT 
                    ci.customization_instance_id,
                    ci.selected_option,
                    ci.customization_notes,
                    ci.ingredient_id,
                    i.name AS ingredient_name,
                    i.price_modifier,
                    pc.composition_type,
                    pc.is_required,
                    pc.max_selections
                FROM customization_instance ci
                JOIN product_composition pc ON ci.product_composition_id = pc.product_composition_id
                LEFT JOIN ingredient i ON ci.ingredient_id = i.ingredient_id
                WHERE ci.cart_id = c.cart_id
                AND ci.cart_id = :cart_id"
            );
            $custStmt->execute([':cart_id' => $item['cart_id'] ?? 0]);
            $customizations = $custStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $queue[] = [
            'product_id' => (int)$item['product_id'],
            'name' => $item['product_name'],
            'description' => $item['description'] ?? '',
            'price' => (float)($item['price'] ?? 0),
            'quantity' => (int)($item['quantity'] ?? 0),
            'image' => $item['product_image'] ?? '',
            'stock' => (int)($item['product_stock'] ?? 999),
            'restaurant_name' => $item['restaurant_name'] ?? '',
            'branch_name' => $item['branch_name'] ?? '',
            'is_customizable' => (bool)($item['is_customizable'] ?? false),
            'customization_type' => $item['customization_type'] ?? null,
            'base_price' => (float)($item['base_price'] ?? 0),
            'customizations' => $customizations
        ];
    }
    
    echo json_encode([
        'status' => 'success',
        'queue' => $queue,
        'count' => count($queue)
    ]);
    
} catch (PDOException $e) {
    error_log('Get queue error: ' . $e->getMessage());
    echo json_encode([
        'status' => 'error', 
        'message' => 'Database error: ' . $e->getMessage(), 
        'queue' => []
    ]);
}