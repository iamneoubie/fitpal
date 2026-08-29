<?php
/**
 * FitPal Get Queue Handler
 * 
 * AJAX endpoint to retrieve the customer's current queue (cart).
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
    echo json_encode(['status' => 'error', 'message' => 'Not logged in', 'queue' => []]);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/cart-queries.php';

$customerId = (int)$_SESSION['customer_id'];

try {
    // Get cart items
    $cartItems = getCustomerCart($database_connection, $customerId);
    
    $queue = [];
    foreach ($cartItems as $item) {
        $queue[] = [
            'product_id' => (int)$item['product_id'],
            'name' => $item['product_name'],
            'price' => (float)$item['price'],
            'quantity' => (int)$item['quantity'],
            'image' => $item['product_image'] ?? '',
            'stock' => (int)$item['product_stock'],
            'restaurant_name' => $item['restaurant_name'] ?? '',
            'branch_name' => $item['branch_name'] ?? '',
        ];
    }
    
    echo json_encode([
        'status' => 'success',
        'queue' => $queue,
        'count' => count($queue)
    ]);
    
} catch (PDOException $e) {
    error_log('Get queue error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error', 'queue' => []]);
}