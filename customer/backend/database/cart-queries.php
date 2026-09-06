<?php
/**
 * Cart Database Queries
 * Updated for new database schema with customization support.
 *
 * @package FitPal
 * @version 2.0
 */

declare(strict_types=1);

/**
 * Get customer's cart items with customizations
 *
 * @param PDO $db Database connection
 * @param int $customerId Customer ID
 * @return array Cart items with product details and customizations
 */
function getCustomerCart(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT 
            c.cart_id, 
            c.product_id, 
            c.quantity, 
            c.price, 
            c.added_at,
            p.name AS product_name, 
            p.description, 
            p.stock AS product_stock,
            p.is_customizable,
            p.customization_type,
            p.base_price,
            rb.branch_name,
            r.business_name AS restaurant_name,
            di.dietary_tags, 
            di.allergens, 
            di.images AS product_image
        FROM cart c
        JOIN product p ON c.product_id = p.product_id
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE c.customer_id = :customer_id
        ORDER BY c.added_at DESC"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get customizations for each item
    foreach ($items as &$item) {
        $custStmt = $db->prepare(
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
            WHERE ci.cart_id = :cart_id"
        );
        $custStmt->execute([':cart_id' => $item['cart_id']]);
        $item['customizations'] = $custStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $items;
}

/**
 * Get cart items grouped by branch with customizations
 *
 * @param PDO $db Database connection
 * @param int $customerId Customer ID
 * @return array Cart items grouped by branch
 */
function getCartGroupedByBranch(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT 
            c.cart_id,
            c.product_id,
            c.quantity,
            c.price,
            p.name AS product_name,
            p.restaurant_branch_id,
            p.is_customizable,
            rb.branch_name,
            rb.restaurant_id,
            r.business_name AS restaurant_name
        FROM cart c
        JOIN product p ON c.product_id = p.product_id
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        WHERE c.customer_id = :customer_id
        ORDER BY rb.restaurant_id, rb.branch_name, p.name"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($items as $item) {
        $branchId = $item['restaurant_branch_id'];
        if (!isset($grouped[$branchId])) {
            $grouped[$branchId] = [
                'branch_id' => $branchId,
                'branch_name' => $item['branch_name'],
                'restaurant_id' => $item['restaurant_id'],
                'restaurant_name' => $item['restaurant_name'],
                'items' => [],
                'subtotal' => 0
            ];
        }
        
        // Get customizations for this cart item
        $custStmt = $db->prepare(
            "SELECT 
                ci.selected_option,
                ci.customization_notes,
                ci.ingredient_id,
                i.name AS ingredient_name,
                i.price_modifier,
                pc.composition_type,
                pc.is_required
            FROM customization_instance ci
            JOIN product_composition pc ON ci.product_composition_id = pc.product_composition_id
            LEFT JOIN ingredient i ON ci.ingredient_id = i.ingredient_id
            WHERE ci.cart_id = :cart_id"
        );
        $custStmt->execute([':cart_id' => $item['cart_id']]);
        $customizations = $custStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $grouped[$branchId]['items'][] = [
            'cart_id' => $item['cart_id'],
            'product_id' => $item['product_id'],
            'product_name' => $item['product_name'],
            'quantity' => $item['quantity'],
            'price' => (float)$item['price'],
            'total' => (float)$item['price'] * $item['quantity'],
            'is_customizable' => (bool)($item['is_customizable'] ?? false),
            'customizations' => $customizations
        ];
        $grouped[$branchId]['subtotal'] += (float)$item['price'] * $item['quantity'];
    }

    return $grouped;
}