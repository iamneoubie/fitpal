<?php
/**
 * Cart Database Queries
 * Updated for new database schema with customization_data JSON in cart.
 *
 * @package FitPal
 * @version 2.1
 */

declare(strict_types=1);

/**
 * Get customer's cart items with customizations parsed from JSON.
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
            c.customization_data,
            p.name AS product_name, 
            p.description, 
            p.stock AS product_stock,
            p.is_customizable,
            p.customization_type,
            p.base_price,
            rb.branch_name,
            rb.restaurant_branch_id,
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

    // Parse customization_data JSON into a readable array
    foreach ($items as &$item) {
        $item['customizations'] = [];
        if (!empty($item['customization_data'])) {
            $customizations = json_decode($item['customization_data'], true);
            if (is_array($customizations)) {
                // Filter out notes, keep ingredient customizations
                foreach ($customizations as $cust) {
                    if (isset($cust['type']) && $cust['type'] === 'notes') {
                        continue;
                    }
                    // Add ingredient name if ingredient_id is present
                    if (isset($cust['ingredient_id']) && $cust['ingredient_id'] > 0) {
                        // We could optionally fetch ingredient name here, but we'll just pass the raw data
                        // The frontend can display ingredient_id or we can join names later
                        $item['customizations'][] = $cust;
                    }
                }
            }
        }
        // Remove the raw JSON to keep response clean
        unset($item['customization_data']);
    }

    return $items;
}

/**
 * Get cart items grouped by branch with customizations parsed from JSON.
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
            c.customization_data,
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
        $branchId = (int)$item['restaurant_branch_id'];
        if (!isset($grouped[$branchId])) {
            $grouped[$branchId] = [
                'branch_id' => $branchId,
                'branch_name' => $item['branch_name'],
                'restaurant_id' => (int)$item['restaurant_id'],
                'restaurant_name' => $item['restaurant_name'],
                'items' => [],
                'subtotal' => 0
            ];
        }

        // Parse customizations
        $customizations = [];
        if (!empty($item['customization_data'])) {
            $customizations = json_decode($item['customization_data'], true);
            if (!is_array($customizations)) {
                $customizations = [];
            }
            // Filter notes
            $customizations = array_filter($customizations, function($c) {
                return !(isset($c['type']) && $c['type'] === 'notes');
            });
        }

        $grouped[$branchId]['items'][] = [
            'cart_id' => (int)$item['cart_id'],
            'product_id' => (int)$item['product_id'],
            'product_name' => $item['product_name'],
            'quantity' => (int)$item['quantity'],
            'price' => (float)$item['price'],
            'total' => (float)$item['price'] * (int)$item['quantity'],
            'is_customizable' => (bool)($item['is_customizable'] ?? false),
            'customizations' => $customizations
        ];
        $grouped[$branchId]['subtotal'] += (float)$item['price'] * (int)$item['quantity'];
    }

    return $grouped;
}