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
 * ---------------------------------------------------------------------
 * PRODUCT IMAGE RESOLUTION
 * ---------------------------------------------------------------------
 * The `dietary_information.images` column stores a folder path. The
 * actual on-disk location of that folder varies by deployment. This
 * query layer resolves the raw path into two fields the landing page
 * can use to build a correct image URL:
 *
 *   - image_base      project-root-relative folder path (or '')
 *   - primary_image   first image filename inside that folder (or '')
 *
 * The resolution logic is shared with the customer-facing menu,
 * product detail, and cart pages, and lives in
 * customer/backend/database/product-queries.php. Requiring that file
 * here ensures the landing page uses the exact same folder-discovery
 * rules as every other page that renders a product image.
 *
 * Pure data-access layer. No $_POST, no $_GET, no header(), no echo.
 *
 * @package FitPal
 * @version 2.0 — getFeaturedProducts() now resolves the raw
 *                `dietary_information.images` value into the
 *                `image_base` and `primary_image` fields by using
 *                the helper functions in product-queries.php. This
 *                makes the landing page's featured-product cards
 *                render images identically to the customer menu and
 *                product detail pages.
 *
 *                (1.0: initial landing page queries.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../customer/backend/database/product-queries.php';

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
            COALESCE(di.images, '')       AS raw_image_path
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
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as &$product) {
        $rawPath = (string)($product['raw_image_path'] ?? '');

        $product['image_base']    = getProductImageBasePath($rawPath);
        $product['primary_image'] = getProductPrimaryFilename($rawPath);

        unset($product['raw_image_path']);
    }
    unset($product);

    return $products;
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