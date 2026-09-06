<?php
/**
 * FitPal Get Product Customizations
 * 
 * AJAX endpoint to get product customization options.
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

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

if ($productId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid product ID']);
    exit;
}

try {
    // Get product with customization data
    $stmt = $database_connection->prepare(
        "SELECT 
            p.product_id,
            p.name AS product_name,
            p.is_customizable,
            p.customization_type,
            p.base_price,
            p.price
        FROM product p
        WHERE p.product_id = :product_id
        AND p.is_active = 1"
    );
    $stmt->execute([':product_id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        echo json_encode(['status' => 'error', 'message' => 'Product not found']);
        exit;
    }
    
    $response = [
        'status' => 'success',
        'product' => [
            'id' => (int)$product['product_id'],
            'name' => $product['product_name'],
            'is_customizable' => (bool)$product['is_customizable'],
            'customization_type' => $product['customization_type'],
            'base_price' => (float)($product['base_price'] ?? $product['price']),
            'current_price' => (float)$product['price']
        ],
        'components' => []
    ];
    
    // Get customization components if product is customizable
    if ($product['is_customizable']) {
        $compStmt = $database_connection->prepare(
            "SELECT 
                pc.product_composition_id,
                pc.composition_type,
                pc.is_required,
                pc.max_selections,
                pc.allowed_ingredients
            FROM product_composition pc
            WHERE pc.product_id = :product_id
            ORDER BY pc.composition_type, pc.product_composition_id"
        );
        $compStmt->execute([':product_id' => $productId]);
        $components = $compStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($components as &$comp) {
            $comp['ingredients'] = [];
            
            // Get ingredients for this component
            $ingStmt = $database_connection->prepare(
                "SELECT 
                    i.ingredient_id,
                    i.name AS ingredient_name,
                    i.price_modifier,
                    i.is_active,
                    pci.is_default
                FROM product_composition_ingredient pci
                JOIN ingredient i ON pci.ingredient_id = i.ingredient_id
                WHERE pci.product_composition_id = :comp_id
                AND i.is_active = 1
                ORDER BY pci.is_default DESC, i.name"
            );
            $ingStmt->execute([':comp_id' => $comp['product_composition_id']]);
            $comp['ingredients'] = $ingStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse allowed ingredients if stored as JSON or comma-separated
            if (!empty($comp['allowed_ingredients'])) {
                $allowed = json_decode($comp['allowed_ingredients'], true);
                if (is_array($allowed)) {
                    $comp['allowed_ingredients'] = $allowed;
                } else {
                    $comp['allowed_ingredients'] = array_map('trim', explode(',', $comp['allowed_ingredients']));
                }
            }
            
            unset($comp['product_composition_id']); // Keep for reference but rename
        }
        
        $response['components'] = $components;
    }
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log('Get product customizations error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred']);
}