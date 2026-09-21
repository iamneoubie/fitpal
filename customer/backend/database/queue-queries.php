<?php
/**
 * FitPal Queue (session staging) Database Queries
 *
 * Feature file for the session order queue. It owns every query the
 * queue flow needs, plus the pure enrichment function that turns a
 * raw queue item into a display-ready line.
 *
 * Why queueEnrich() lives here and not in queue-handler.php:
 *
 *   Pages that render queue data, or future handlers that want to
 *   reuse the enrichment logic (reorder already re-implements it in
 *   order-queries.php — this is the canonical version), cannot
 *   require queue-handler.php. That file runs a full request
 *   dispatch at load time — the switch, the CSRF check, the
 *   exit path. Including it from a page would hijack the request.
 *
 *   This file only declares functions. It is safe to require from
 *   anywhere.
 *
 * The session queue itself lives in $_SESSION['order_queue']. That
 * is a request-layer concern and is NOT touched by this file. The
 * handler reads and writes it.
 *
 * @package FitPal
 * @version 2.0 — Raw SQL from queue-handler.php moved here; enrichment
 *                function relocated because this file is safe to
 *                require from pages.
 */

declare(strict_types=1);

/**
 * Fetch product details needed to enrich a queued item.
 *
 * Includes `base_price` so the queue enrich can compute the effective
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

/**
 * Fetch the composition rules for a product — every ingredient that
 * can be selected or removed, with its price modifier, min/max
 * quantities, and required/default flags.
 *
 * Returns an array keyed by ingredient_id for O(1) lookup.
 *
 * @param PDO $db
 * @param int $productId
 * @return array<int, array<string, mixed>>
 */
function getProductCompositionRules(PDO $db, int $productId): array
{
    $stmt = $db->prepare(
        "SELECT ingredient_id, price_modifier, min_quantity, max_quantity,
                is_required, is_default, default_quantity
           FROM product_composition
          WHERE product_id = :product_id"
    );
    $stmt->execute([':product_id' => $productId]);

    $rules = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rules[(int)$r['ingredient_id']] = $r;
    }
    return $rules;
}

/**
 * Enrich a queued item with live product data from the database.
 *
 * Effective price is computed as:
 *     product.base_price
 *   + Σ(composition.price_modifier × requested_quantity)
 * for every composition row where the client indicated the ingredient
 * is present. The client sends {ingredient_id, quantity, selected_option}
 * and we ignore its price_modifier entirely — the server is the single
 * source of truth for money.
 *
 * Returns null when the product is missing, inactive, or the
 * requested quantity cannot be satisfied by the current stock.
 *
 * @param PDO $db
 * @param array<string, mixed> $item
 * @return array<string, mixed>|null
 */
function queueEnrich(PDO $db, array $item): ?array
{
    $productId = (int)($item['product_id'] ?? 0);
    $quantity  = (int)($item['quantity'] ?? 0);
    if ($productId <= 0 || $quantity <= 0) {
        return null;
    }

    $p = getProductForQueue($db, $productId);
    if (!$p) {
        return null;
    }

    $maxStock = (int)$p['stock'];
    if ($quantity > $maxStock) $quantity = $maxStock;
    if ($quantity <= 0) return null;

    $customizations = [];
    if (!empty($item['customization_data'])) {
        $decoded = is_string($item['customization_data'])
            ? json_decode($item['customization_data'], true)
            : $item['customization_data'];
        if (is_array($decoded)) {
            $customizations = $decoded;
        }
    }

    $rules = getProductCompositionRules($db, $productId);

    $basePrice = (float)($p['base_price'] ?? 0);
    if ($basePrice <= 0) {
        $basePrice = (float)$p['price'];
    }

    $unitPrice = $basePrice;

    foreach ($customizations as $cust) {
        if (!is_array($cust)) continue;
        if (($cust['type'] ?? '') === 'notes') continue;

        $ingredientId = (int)($cust['ingredient_id'] ?? 0);
        if ($ingredientId <= 0) continue;
        if (!isset($rules[$ingredientId])) continue;

        $option = (string)($cust['selected_option'] ?? 'selected');
        if ($option === 'remove') continue;

        $requestedQty = (int)($cust['quantity'] ?? 0);
        if ($requestedQty <= 0) continue;

        $rule     = $rules[$ingredientId];
        $modifier = (float)$rule['price_modifier'];
        $maxQty   = (int)$rule['max_quantity'];
        if ($maxQty > 0 && $requestedQty > $maxQty) {
            $requestedQty = $maxQty;
        }

        $unitPrice += $modifier * $requestedQty;
    }

    if ($unitPrice < 0) {
        $unitPrice = 0.0;
    }

    return [
        'product_id'           => (int)$p['product_id'],
        'name'                 => (string)$p['name'],
        'price'                => round($unitPrice, 2),
        'base_price'           => $basePrice,
        'quantity'             => $quantity,
        'image'                => (string)$p['product_image'],
        'stock'                => $maxStock,
        'restaurant_name'      => (string)$p['restaurant_name'],
        'branch_name'          => (string)$p['branch_name'],
        'restaurant_branch_id' => (int)$p['restaurant_branch_id'],
        'is_customizable'      => (bool)$p['is_customizable'],
        'customization_data'   => $item['customization_data'] ?? null,
    ];
}