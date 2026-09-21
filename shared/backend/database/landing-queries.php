<?php
/**
 * FitPal Landing Page Database Queries
 *
 * Shared, role-agnostic queries used ONLY by the public landing page
 * at fitpal/index.php.
 *
 * This file lives under shared/ (not under any role directory) because
 * the landing page is a platform-wide entry point that must not depend
 * on any specific role's query layer.
 *
 * Pure data-access layer. No $_POST, no $_GET, no header(), no echo.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

/**
 * Fetch a random sample of active products across all active
 * restaurants and branches. Used for the "Featured Meals" section.
 *
 * @param PDO $db
 * @param int $limit
 * @return array<int, array<string, mixed>>
 */
function getFeaturedProducts(PDO $db, int $limit = 8): array
{
    $stmt = $db->prepare(
        "SELECT
            p.product_id AS id,
            p.name,
            p.description,
            p.price,
            p.stock,
            p.is_active,
            p.restaurant_branch_id,
            p.is_customizable,
            p.customization_type,
            p.base_price,
            rb.branch_name,
            rb.barangay,
            rb.city,
            rb.province,
            r.restaurant_id,
            r.business_name AS restaurant_name,
            r.cuisine_type,
            COALESCE(di.dietary_tags, '') AS dietary_tags,
            COALESCE(di.allergens, '')    AS allergens,
            di.calories,
            di.protein,
            di.carbs,
            di.fat,
            COALESCE(di.images, '')       AS product_image
         FROM product p
         JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
         JOIN restaurant r        ON rb.restaurant_id = r.restaurant_id
         LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
         WHERE p.is_active = 1
           AND rb.is_active = 1
           AND r.is_active = 1
         ORDER BY RAND()
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Aggregate public-facing platform stats for the hero section.
 *
 * @param PDO $db
 * @return array{restaurants:int, products:int, customers:int}
 */
function getPlatformStats(PDO $db): array
{
    return [
        'restaurants' => (int)$db->query(
            "SELECT COUNT(*) FROM restaurant WHERE is_active = 1"
        )->fetchColumn(),
        'products' => (int)$db->query(
            "SELECT COUNT(*) FROM product WHERE is_active = 1"
        )->fetchColumn(),
        'customers' => (int)$db->query(
            "SELECT COUNT(*) FROM customer WHERE is_active = 1"
        )->fetchColumn(),
    ];
}