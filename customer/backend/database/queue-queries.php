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
 *   Pages that render queue data, and any future handler that wants
 *   to reuse the enrichment logic, cannot require
 *   queue-handler.php. That file runs a full request dispatch at
 *   load time — the switch, the CSRF check, the exit path.
 *   Including it from a page would hijack the request.
 *
 *   This file only declares functions. It is safe to require from
 *   anywhere.
 *
 * The session queue itself lives in $_SESSION['order_queue']. That
 * is a request-layer concern and is NOT touched by this file. The
 * handler reads and writes it.
 *
 * ---------------------------------------------------------------------
 * TWO SHAPES OF customization_data (v6.1.0)
 * ---------------------------------------------------------------------
 * queueEnrich() is the canonical producer of a session queue line.
 * Every writer of $_SESSION['order_queue'] — the customer queue
 * handler's `add` and `sync` actions, and the cart handler's
 * `push_to_queue` action — routes each line through it.
 *
 * The customization payload that arrives in `$item['customization_data']`
 * comes in TWO different shapes depending on which writer supplied it:
 *
 *   1. FLAT ARRAY — a JSON array of entries.
 *
 *        [
 *          {"ingredient_id":5, "selected_option":"selected", "quantity":1, ...},
 *          {"type":"notes", "notes":"Less salt please."}
 *        ]
 *
 *      Supplied by:
 *        - queue-handler.php's `add` action, which receives the flat
 *          array from product-detail.js and re-encodes it verbatim
 *        - customer-order-handler.php's `reorder` action, whose
 *          buildReorderLine() re-encodes the parsed array it built
 *
 *   2. WRAPPED OBJECT — a JSON object carrying the array under a
 *      "customizations" key.
 *
 *        {"customizations": [
 *          {"ingredient_id":5, "selected_option":"selected", "quantity":1, ...},
 *          {"type":"notes", "notes":"Less salt please."}
 *        ]}
 *
 *      Supplied by:
 *        - cart-handler.php's `push_to_queue` action, which copies
 *          the raw `cart.customization_data` column value — the
 *          shape buildCartCustomizationPayload() wrote at add time
 *
 * Both shapes carry the same entries. The difference is only the
 * wrapper.
 *
 * Before v6.1.0, queueEnrich() iterated over the decoded value
 * directly. A flat array iterated over its entries (correct). A
 * wrapped object iterated over ONE element — the array itself —
 * and every entry was silently dropped because the loop expected
 * `$entry['ingredient_id']` and got an array-of-entries instead.
 *
 * The symptom was exactly what a customer saw on the cart path: a
 * customized cart row pushed to the queue arrived without its
 * customizations, with no "Customized" pill and no dropdown,
 * while the same product reordered from orders.php arrived
 * correctly tagged.
 *
 * The unwrap below is the fix. It normalizes both shapes to the
 * flat array of entries before the price loop and the enrichment
 * loop run, so every writer produces a line the panel renders
 * identically.
 *
 * ---------------------------------------------------------------------
 * QUEUE LINE SHAPE
 * ---------------------------------------------------------------------
 * Every line carries, at minimum:
 *
 *     product_id              int
 *     name                    string
 *     price                   float   effective unit price
 *     base_price              float
 *     quantity                int
 *     image                   string  BROWSER-LOADABLE URL
 *     stock                   int
 *     restaurant_name         string
 *     branch_name             string
 *     restaurant_branch_id    int
 *     is_customizable         bool
 *     customization_data      string|null  raw JSON as supplied
 *     customizations          array        ENRICHED display array
 *
 * The `customizations` array is what the queue panel reads. Each
 * entry has ingredient_id, ingredient_name, selected_option,
 * quantity, price_modifier. A {type:'notes', notes:'...'} entry may
 * also be present.
 *
 * The `customization_data` field is preserved verbatim so the
 * shared order-transaction layer's createOrderFromQueue() can read
 * it. That function reads the notes entry to write
 * queue_item.custom_instructions and the ingredient entries to
 * write customization_instance rows.
 *
 * ---------------------------------------------------------------------
 * IMAGE URL RESOLUTION
 * ---------------------------------------------------------------------
 * The `image` field is a browser-loadable URL, not a raw database
 * path. The raw value stored in dietary_information.images is
 * resolved through the helpers in product-queries.php and the
 * project-root URL prefix derived from $_SERVER['SCRIPT_NAME'].
 *
 * ---------------------------------------------------------------------
 * getProductCompositionRules() COLLISION GUARD
 * ---------------------------------------------------------------------
 * Both this file and cart-queries.php declare a function named
 * getProductCompositionRules(). Whichever file is loaded first
 * declares the canonical version; the second file's declaration is
 * skipped by the function_exists() guard.
 *
 * @package FitPal
 * @version 6.1.0 — queueEnrich() unwraps the {"customizations":[...]}
 *                  shape before iterating, so a cart-pushed line's
 *                  customizations are no longer silently dropped.
 *                  Both shapes now normalize to the same flat array.
 *
 *                  (6.0.0: queueEnrich() produces the enriched
 *                  `customizations` array itself. 5.0.0: collision
 *                  guard. 4.0.0: image URL resolution.)
 */

declare(strict_types=1);

require_once __DIR__ . '/product-queries.php';

/**
 * Fetch product details needed to enrich a queued item.
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
 * Fetch the composition rules for a product.
 *
 * COLLISION GUARD: cart-queries.php declares a function with the
 * same name. Whichever file loads first declares the canonical
 * version; this guard makes the second file's declaration a
 * silent no-op.
 *
 * @param PDO $db
 * @param int $productId
 * @return array<int, array{
 *     ingredient_id: int,
 *     price_modifier: float,
 *     min_quantity: int,
 *     max_quantity: int,
 *     is_required: int,
 *     is_default: int,
 *     default_quantity: int
 * }>
 */
if (!function_exists('getProductCompositionRules')) {
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
            $rules[(int)$r['ingredient_id']] = [
                'ingredient_id'    => (int)$r['ingredient_id'],
                'price_modifier'   => (float)$r['price_modifier'],
                'min_quantity'     => (int)$r['min_quantity'],
                'max_quantity'     => (int)$r['max_quantity'],
                'is_required'      => (int)($r['is_required']      ?? 0),
                'is_default'       => (int)($r['is_default']       ?? 0),
                'default_quantity' => (int)($r['default_quantity'] ?? 0),
            ];
        }
        return $rules;
    }
}

/**
 * Batch-load ingredient names for a set of ingredient IDs.
 *
 * @param PDO $db
 * @param array<int, int> $ingredientIds
 * @return array<int, string>
 */
function queueLoadIngredientNames(PDO $db, array $ingredientIds): array
{
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $ingredientIds),
        static fn(int $id): bool => $id > 0
    )));

    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT ingredient_id, name
           FROM ingredient
          WHERE ingredient_id IN ($placeholders)"
    );
    $stmt->execute($ids);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $out[(int)$row['ingredient_id']] = (string)$row['name'];
    }
    return $out;
}

/**
 * Derive the project-root URL prefix for the current request.
 *
 * @return string
 */
function queueProjectRootUrl(): string
{
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';

    if ($scriptPath === '') {
        return '';
    }

    $dirPath  = dirname($scriptPath);
    $segments = array_filter(explode('/', $dirPath));
    $depth    = count($segments);

    if ($depth <= 0) {
        return './';
    }

    return str_repeat('../', $depth);
}

/**
 * Build a browser-loadable image URL from a raw
 * dietary_information.images value.
 *
 * @param string $imageFolder
 * @return string
 */
function queueResolveImageUrl(string $imageFolder): string
{
    if ($imageFolder === '') {
        return '';
    }

    $imageBase    = getProductImageBasePath($imageFolder);
    $primaryImage = getProductPrimaryFilename($imageFolder);

    if ($imageBase === '' || $primaryImage === '') {
        return '';
    }

    $projectRootUrl = queueProjectRootUrl();

    if ($projectRootUrl === '') {
        return '';
    }

    return $projectRootUrl . $imageBase . $primaryImage;
}

/**
 * Normalize a customization payload to a flat array of entries.
 *
 * Accepts both shapes that reach this file:
 *
 *   - a flat array of entries
 *       [{ingredient_id:5, ...}, {type:'notes', ...}]
 *
 *   - a wrapped object carrying the array under "customizations"
 *       {"customizations": [{ingredient_id:5, ...}, {type:'notes', ...}]}
 *
 * Returns an empty array when the input is null, empty, not
 * decodable, or not an array. Never throws.
 *
 * This is the exact normalization parseCartCustomizations() in
 * cart-queries.php performs before iterating. Both files must agree,
 * because both consume the same underlying JSON written by
 * buildCartCustomizationPayload().
 *
 * @param mixed $raw  A JSON string, an array, or null.
 * @return array<int, array<string, mixed>>
 */
function queueNormalizeCustomizationPayload(mixed $raw): array
{
    if ($raw === null || $raw === '' || $raw === []) {
        return [];
    }

    $decoded = null;
    if (is_array($raw)) {
        $decoded = $raw;
    } elseif (is_string($raw)) {
        $attempt = json_decode($raw, true);
        if (is_array($attempt)) {
            $decoded = $attempt;
        }
    }

    if (!is_array($decoded) || empty($decoded)) {
        return [];
    }

    // Unwrap the {"customizations":[...]} shape. A flat array does
    // not have this key, so the check is a safe no-op for it.
    if (isset($decoded['customizations']) && is_array($decoded['customizations'])) {
        $decoded = $decoded['customizations'];
    }

    // Filter out entries that are not arrays. Every subsequent read
    // assumes an array.
    $out = [];
    foreach ($decoded as $entry) {
        if (is_array($entry)) {
            $out[] = $entry;
        }
    }

    return $out;
}

/**
 * Enrich a queued item with live product data from the database.
 *
 * Effective price is computed as:
 *     product.base_price
 *   + Σ(composition.price_modifier × requested_quantity)
 * for every composition row where the client indicated the
 * ingredient is present. The server is the single source of truth
 * for money — the client's price_modifier is ignored.
 *
 * Returns null when the product is missing, inactive, or the
 * requested quantity cannot be satisfied by the current stock.
 *
 * ---------------------------------------------------------------------
 * INPUT NORMALIZATION
 * ---------------------------------------------------------------------
 * The `customization_data` field on $item may be supplied in either
 * of the two shapes described in the file header. It is normalized
 * to a flat array of entries by queueNormalizeCustomizationPayload()
 * before the price loop and the enrichment loop run.
 *
 * Without this normalization, a cart-pushed line — whose
 * customization_data carries the {"customizations":[...]} wrapper
 * — would iterate over its own wrapper object as a single entry and
 * drop every ingredient. That was the observed failure: a
 * customized cart row arrived in the queue panel with no
 * "Customized" pill.
 *
 * ---------------------------------------------------------------------
 * RETURNED SHAPE
 * ---------------------------------------------------------------------
 * The returned line carries both:
 *
 *   customization_data   the value the caller supplied, unchanged.
 *                        createOrderFromQueue() reads it to write
 *                        customization_instance rows and
 *                        queue_item.custom_instructions.
 *
 *   customizations       the enriched display array the queue panel
 *                        reads. Each entry has ingredient_id,
 *                        ingredient_name, selected_option,
 *                        quantity, price_modifier. A notes entry
 *                        may also be present.
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

    // Normalize the customization payload to a flat array of
    // entries. This is where the wrapped shape produced by the cart
    // handler is unwrapped. See the docblock above.
    $customizations = queueNormalizeCustomizationPayload(
        $item['customization_data'] ?? null
    );

    $rules = getProductCompositionRules($db, $productId);

    $basePrice = (float)($p['base_price'] ?? 0);
    if ($basePrice <= 0) {
        $basePrice = (float)$p['price'];
    }

    $unitPrice = $basePrice;

    // Batch-load ingredient names for every ingredient referenced by
    // this line's customizations. One query per enrichment call.
    $ingredientIds = [];
    foreach ($customizations as $cust) {
        if (($cust['type'] ?? '') === 'notes') continue;
        $iid = (int)($cust['ingredient_id'] ?? 0);
        if ($iid > 0) $ingredientIds[] = $iid;
    }
    $ingredientNames = queueLoadIngredientNames($db, $ingredientIds);

    // Build the enriched display array alongside the price
    // calculation.
    $enrichedCustomizations = [];

    foreach ($customizations as $cust) {
        // Notes entries are carried verbatim. They are not part of
        // the price computation.
        if (($cust['type'] ?? '') === 'notes') {
            $text = isset($cust['notes']) ? trim((string)$cust['notes']) : '';
            if ($text !== '') {
                $enrichedCustomizations[] = [
                    'type'  => 'notes',
                    'notes' => $text,
                ];
            }
            continue;
        }

        $ingredientId = (int)($cust['ingredient_id'] ?? 0);
        if ($ingredientId <= 0) continue;

        $option = (string)($cust['selected_option'] ?? 'selected');
        $qty    = (int)($cust['quantity'] ?? 1);
        if ($qty < 1) $qty = 1;

        $name = $ingredientNames[$ingredientId]
             ?? ('Ingredient #' . $ingredientId);

        $modifier = 0.0;
        $appliedQty = $qty;

        if (isset($rules[$ingredientId])) {
            $rule = $rules[$ingredientId];

            if ($option !== 'remove') {
                $modifier = (float)$rule['price_modifier'];

                $maxQty = (int)$rule['max_quantity'];
                if ($maxQty > 0 && $appliedQty > $maxQty) {
                    $appliedQty = $maxQty;
                }

                $unitPrice += $modifier * $appliedQty;
            } else {
                $modifier = 0.0;
            }
        } else {
            // Ingredient no longer in the composition. Keep it in
            // the display array marked as removed, do not affect the
            // price.
            $option     = 'remove';
            $modifier   = 0.0;
            $appliedQty = 1;
        }

        $enrichedCustomizations[] = [
            'ingredient_id'   => $ingredientId,
            'ingredient_name' => $name,
            'selected_option' => $option,
            'quantity'        => $appliedQty,
            'price_modifier'  => $modifier,
        ];
    }

    if ($unitPrice < 0) {
        $unitPrice = 0.0;
    }

    $imageFolder = (string)($p['product_image'] ?? '');
    $imageUrl    = queueResolveImageUrl($imageFolder);

    return [
        'product_id'           => (int)$p['product_id'],
        'name'                 => (string)$p['name'],
        'price'                => round($unitPrice, 2),
        'base_price'           => $basePrice,
        'quantity'             => $quantity,
        'image'                => $imageUrl,
        'stock'                => $maxStock,
        'restaurant_name'      => (string)$p['restaurant_name'],
        'branch_name'          => (string)$p['branch_name'],
        'restaurant_branch_id' => (int)$p['restaurant_branch_id'],
        'is_customizable'      => (bool)$p['is_customizable'],
        'customization_data'   => $item['customization_data'] ?? null,
        'customizations'       => $enrichedCustomizations,
    ];
}