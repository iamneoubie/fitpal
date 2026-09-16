<?php
/**
 * FitPal Queue (session staging) Database Queries
 *
 * Read/write helpers used by queue-handler.php. The session queue
 * itself lives in $_SESSION['order_queue']; these functions handle
 * the database side (enriching, resolving products, committing into
 * the persistent cart).
 *
 * @package FitPal
 * @version 1.1 — Added base_price to getProductForQueue; fixed missing comma
 */

declare(strict_types=1);

/**
 * Fetch product details needed to enrich a queued item.
 *
 * Includes `base_price` so the queue handler can compute the effective
 * unit price (base + customization modifiers) without a second query.
 *
 * @param PDO $db
 * @param int $productId
 * @return array<string, mixed>|false
 */
function getProductForQueue(PDO $db, int $productId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            p.product_id,
            p.name,
            p.price,
            p.base_price,
            p.stock,
            p.is_active,
            p.is_customizable,
            p.restaurant_branch_id,
            rb.branch_name,
            r.business_name AS restaurant_name,
            COALESCE(di.images, '') AS product_image
         FROM product p
         JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
         WHERE p.product_id = :product_id
           AND p.is_active = 1
           AND rb.is_active = 1
           AND r.is_active = 1"
    );
    $stmt->execute([':product_id' => $productId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}