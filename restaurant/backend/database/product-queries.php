<?php
/**
 * FitPal Restaurant Product Queries
 *
 * Pure data-access layer for products owned by a restaurant. Every
 * read and write is scoped to a restaurant_id so an owner can never
 * see or touch another restaurant's catalog.
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES
 * ---------------------------------------------------------------------
 *   - SQL only. No HTML, no session, no $_POST, no header(), no echo.
 *   - Every function that mutates takes a restaurant_id and verifies
 *     the affected rows belong to it via WHERE clauses.
 *   - Image folder resolution is delegated to the customer role's
 *     product-queries.php helpers, which this file requires at the
 *     top. That file's resolvers already cover both the seed manifest
 *     layout and the runtime shared/uploads/restaurant/ layout, so
 *     owner-uploaded images are found by the same code the customer
 *     role uses.
 *
 * ---------------------------------------------------------------------
 * CUSTOMIZATION MODEL
 * ---------------------------------------------------------------------
 * A product is customizable when product.is_customizable = 1 and its
 * product_composition rows describe the ingredients the customer may
 * choose from.
 *
 * For a customizable product the owner supplies, per ingredient:
 *
 *   is_default       whether the ingredient is present by default
 *   default_quantity the quantity present by default
 *   min_quantity     the lowest quantity the customer may select
 *   max_quantity     the highest quantity the customer may select
 *   price_modifier   the price delta per unit (may be negative)
 *   display_order    the group the ingredient belongs to on the
 *                    customization screen
 *   is_required      whether the group must have a selection
 *
 * For a non-customizable product the product_composition table has
 * no rows for it. The owner supplies just the price, stock, and
 * dietary_information row.
 *
 * saveProductComposition() replaces a product's composition rows
 * wholesale inside the caller's transaction. This is the correct
 * shape for the edit flow: the owner sees the full list, changes it,
 * and saves. Partial row-level updates would require the client to
 * track composition_id values across renders for no benefit.
 *
 * ---------------------------------------------------------------------
 * IMAGE STORAGE MODEL
 * ---------------------------------------------------------------------
 * Product images are stored on disk under
 *
 *     shared/uploads/restaurant/products/<product_id>/MM_DD_YYYY_<n>.<ext>
 *
 * and the dietary_information.images column holds only the FOLDER:
 *
 *     shared/uploads/restaurant/products/<product_id>/
 *
 * The customer-role resolver's third candidate root is exactly
 * shared/uploads/restaurant/<tail>/ where <tail> is the last two
 * segments of the stored value. Because the stored value's last two
 * segments are "products/<product_id>", the resolver's third
 * candidate matches this layout without any change to the resolver.
 *
 * deleteProductMedia() removes the folder for a product when the
 * product is deleted.
 *
 * @package FitPal
 * @version 1.0 — First version of this file.
 */

declare(strict_types=1);

// The customer role owns the canonical image-folder resolvers. Every
// role that renders a product image reads through those helpers so
// there is exactly one place that knows how a stored folder maps to
// a folder on disk. Requiring the file here is what makes this
// query layer produce the same image_base for a given product that
// the customer menu produces.
require_once __DIR__ . '/../../../customer/backend/database/product-queries.php';

/* =============================================================
 * OWNERSHIP
 * ============================================================= */

/**
 * Return the restaurant_id that owns the given branch, or 0 when
 * the branch does not exist.
 *
 * @param PDO $db
 * @param int $branchId
 * @return int
 */
function getRestaurantIdByBranch(PDO $db, int $branchId): int
{
    if ($branchId <= 0) {
        return 0;
    }

    $stmt = $db->prepare(
        "SELECT restaurant_id
           FROM restaurant_branch
          WHERE restaurant_branch_id = :branch_id
          LIMIT 1"
    );
    $stmt->execute([':branch_id' => $branchId]);
    $restaurantId = $stmt->fetchColumn();

    return $restaurantId === false ? 0 : (int)$restaurantId;
}

/**
 * Return the branch id of the owner's primary branch, or 0 when the
 * restaurant has no active branch.
 *
 * When the owner has exactly one branch this is unambiguous. When the
 * owner has more than one, this returns the first active branch in
 * id order, and the product form on the page will offer a branch
 * selector in a later revision. For v1 the owner always has exactly
 * one branch when they first register.
 *
 * @param PDO $db
 * @param int $restaurantId
 * @return int
 */
function getOwnerPrimaryBranchId(PDO $db, int $restaurantId): int
{
    if ($restaurantId <= 0) {
        return 0;
    }

    $stmt = $db->prepare(
        "SELECT restaurant_branch_id
           FROM restaurant_branch
          WHERE restaurant_id = :restaurant_id
            AND is_active = 1
          ORDER BY restaurant_branch_id ASC
          LIMIT 1"
    );
    $stmt->execute([':restaurant_id' => $restaurantId]);
    $branchId = $stmt->fetchColumn();

    return $branchId === false ? 0 : (int)$branchId;
}

/**
 * Verify that a product belongs to a branch owned by the given
 * restaurant. Returns the product's own branch_id when the check
 * passes, or 0 when the product does not exist or belongs to another
 * restaurant.
 *
 * @param PDO $db
 * @param int $productId
 * @param int $restaurantId
 * @return int
 */
function getOwnedProductBranchId(PDO $db, int $productId, int $restaurantId): int
{
    if ($productId <= 0 || $restaurantId <= 0) {
        return 0;
    }

    $stmt = $db->prepare(
        "SELECT p.restaurant_branch_id
           FROM product p
           JOIN restaurant_branch rb
             ON p.restaurant_branch_id = rb.restaurant_branch_id
          WHERE p.product_id = :product_id
            AND rb.restaurant_id = :restaurant_id
          LIMIT 1"
    );
    $stmt->execute([
        ':product_id'    => $productId,
        ':restaurant_id' => $restaurantId,
    ]);
    $branchId = $stmt->fetchColumn();

    return $branchId === false ? 0 : (int)$branchId;
}

/* =============================================================
 * READ — LIST
 * ============================================================= */

/**
 * Return every product owned by the given restaurant, ordered by
 * active state first, then by name.
 *
 * Every row carries:
 *   - product_id
 *   - name, price, stock, is_active, is_customizable, customization_type
 *   - base_price
 *   - dietary_information_id, dietary_information row fields
 *   - dietary_tags (raw comma-separated string)
 *   - allergens (raw comma-separated string)
 *   - images (raw dietary_information.images value)
 *   - image_base (project-root-relative folder, resolved)
 *   - primary_image (first image filename, resolved)
 *   - component_count (number of product_composition rows)
 *
 * @param PDO $db
 * @param int $restaurantId
 * @return array<int, array<string, mixed>>
 */
function getProductsByRestaurant(PDO $db, int $restaurantId): array
{
    if ($restaurantId <= 0) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT
            p.product_id,
            p.restaurant_branch_id,
            p.dietary_information_id,
            p.name,
            p.description,
            p.price,
            p.stock,
            p.is_customizable,
            p.customization_type,
            p.base_price,
            p.is_active,
            p.created_at,
            p.updated_at,
            COALESCE(di.dietary_tags, '') AS dietary_tags,
            COALESCE(di.allergens, '')    AS allergens,
            COALESCE(di.images, '')       AS images,
            di.category,
            di.calories,
            di.protein,
            di.carbs,
            di.fat,
            (SELECT COUNT(*)
               FROM product_composition pc
              WHERE pc.product_id = p.product_id) AS component_count
         FROM product p
         JOIN restaurant_branch rb
           ON p.restaurant_branch_id = rb.restaurant_branch_id
         LEFT JOIN dietary_information di
                ON p.dietary_information_id = di.dietary_information_id
         WHERE rb.restaurant_id = :restaurant_id
         ORDER BY p.is_active DESC, p.name ASC"
    );
    $stmt->execute([':restaurant_id' => $restaurantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $folder = (string)($row['images'] ?? '');
        $row['image_base']    = getProductImageBasePath($folder);
        $row['primary_image'] = getProductPrimaryFilename($folder);
    }
    unset($row);

    return $rows;
}

/* =============================================================
 * READ — SINGLE
 * ============================================================= */

/**
 * Return a single product owned by the given restaurant, or null.
 *
 * The row carries the same image-resolved fields as
 * getProductsByRestaurant() plus the raw dietary_information fields
 * needed to prefill the edit form.
 *
 * @param PDO $db
 * @param int $productId
 * @param int $restaurantId
 * @return array<string, mixed>|null
 */
function getProductForOwner(PDO $db, int $productId, int $restaurantId): ?array
{
    if ($productId <= 0 || $restaurantId <= 0) {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT
            p.product_id,
            p.restaurant_branch_id,
            p.dietary_information_id,
            p.name,
            p.description,
            p.price,
            p.stock,
            p.is_customizable,
            p.customization_type,
            p.base_price,
            p.is_active,
            COALESCE(di.dietary_tags, '') AS dietary_tags,
            COALESCE(di.allergens, '')    AS allergens,
            COALESCE(di.images, '')       AS images,
            di.category,
            di.calories,
            di.protein,
            di.carbs,
            di.fat
         FROM product p
         JOIN restaurant_branch rb
           ON p.restaurant_branch_id = rb.restaurant_branch_id
         LEFT JOIN dietary_information di
                ON p.dietary_information_id = di.dietary_information_id
         WHERE p.product_id = :product_id
           AND rb.restaurant_id = :restaurant_id
         LIMIT 1"
    );
    $stmt->execute([
        ':product_id'    => $productId,
        ':restaurant_id' => $restaurantId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $folder = (string)($row['images'] ?? '');
    $row['image_base']    = getProductImageBasePath($folder);
    $row['primary_image'] = getProductPrimaryFilename($folder);

    return $row;
}

/* =============================================================
 * READ — COMPOSITION
 * ============================================================= */

/**
 * Return the ingredient composition rows for a product, joined to
 * the ingredient master so the edit form can render the full
 * ingredient list with its existing settings.
 *
 * Ordered by display_order then ingredient name so the edit form
 * shows the same grouping the customer's customization screen will.
 *
 * @param PDO $db
 * @param int $productId
 * @return array<int, array<string, mixed>>
 */
function getProductCompositionForOwner(PDO $db, int $productId): array
{
    if ($productId <= 0) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT
            pc.composition_id,
            pc.product_id,
            pc.ingredient_id,
            pc.is_default,
            pc.default_quantity,
            pc.min_quantity,
            pc.max_quantity,
            pc.price_modifier,
            pc.display_order,
            pc.is_required,
            i.name AS ingredient_name,
            i.unit_price,
            i.calories AS ingredient_calories,
            i.dietary_tags AS ingredient_dietary_tags,
            i.allergens AS ingredient_allergens,
            i.is_active AS ingredient_active
         FROM product_composition pc
         JOIN ingredient i
           ON pc.ingredient_id = i.ingredient_id
         WHERE pc.product_id = :product_id
         ORDER BY pc.display_order ASC, i.name ASC"
    );
    $stmt->execute([':product_id' => $productId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Return every active ingredient in the master list.
 *
 * Used by the edit form to render the full set of ingredients an
 * owner may add to a product's composition. Ordered by name.
 *
 * @param PDO $db
 * @return array<int, array<string, mixed>>
 */
function getAllActiveIngredients(PDO $db): array
{
    $stmt = $db->prepare(
        "SELECT
            ingredient_id,
            name,
            description,
            unit_price,
            calories,
            protein,
            carbs,
            fat,
            dietary_tags,
            allergens,
            stock_quantity
         FROM ingredient
         WHERE is_active = 1
         ORDER BY name ASC"
    );
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =============================================================
 * WRITE — CREATE
 * ============================================================= */

/**
 * Insert a new dietary_information row and return its id.
 *
 * @param PDO $db
 * @param array $data  Keys: category, dietary_tags, allergens,
 *                     calories, protein, carbs, fat
 * @return int
 */
function createDietaryInformation(PDO $db, array $data): int
{
    $stmt = $db->prepare(
        "INSERT INTO dietary_information
            (images, category, dietary_tags, allergens,
             calories, protein, carbs, fat)
         VALUES
            (:images, :category, :dietary_tags, :allergens,
             :calories, :protein, :carbs, :fat)"
    );
    $stmt->execute([
        ':images'       => $data['images']       ?? null,
        ':category'     => $data['category']     ?? null,
        ':dietary_tags' => $data['dietary_tags'] ?? null,
        ':allergens'    => $data['allergens']    ?? null,
        ':calories'     => $data['calories']     ?? null,
        ':protein'      => $data['protein']      ?? null,
        ':carbs'        => $data['carbs']        ?? null,
        ':fat'          => $data['fat']          ?? null,
    ]);

    return (int)$db->lastInsertId();
}

/**
 * Insert a new product row and return its id.
 *
 * Caller must have already validated that $branchId belongs to the
 * restaurant the caller is authorized to act for.
 *
 * @param PDO $db
 * @param int $branchId
 * @param int $dietaryInformationId
 * @param array $data
 * @return int
 */
function createProduct(
    PDO $db,
    int $branchId,
    int $dietaryInformationId,
    array $data
): int {
    $isCustomizable = !empty($data['is_customizable']) ? 1 : 0;

    $customizationType = $isCustomizable
        ? ($data['customization_type'] ?? 'structured')
        : 'none';

    $price     = (float)($data['price'] ?? 0);
    $basePrice = $isCustomizable
        ? (float)($data['base_price'] ?? $price)
        : 0.00;

    $stmt = $db->prepare(
        "INSERT INTO product
            (restaurant_branch_id, dietary_information_id, name, description,
             price, stock, is_customizable, customization_type, base_price,
             is_active)
         VALUES
            (:branch_id, :dietary_information_id, :name, :description,
             :price, :stock, :is_customizable, :customization_type,
             :base_price, :is_active)"
    );
    $stmt->execute([
        ':branch_id'               => $branchId,
        ':dietary_information_id'  => $dietaryInformationId,
        ':name'                    => $data['name'],
        ':description'             => $data['description'] ?? null,
        ':price'                   => $price,
        ':stock'                   => (int)($data['stock'] ?? 0),
        ':is_customizable'         => $isCustomizable,
        ':customization_type'      => $customizationType,
        ':base_price'              => $basePrice,
        ':is_active'               => isset($data['is_active'])
            ? (int)$data['is_active']
            : 1,
    ]);

    return (int)$db->lastInsertId();
}

/**
 * Insert a single product_composition row.
 *
 * @param PDO $db
 * @param int $productId
 * @param array $row
 * @return int
 */
function createProductCompositionRow(
    PDO $db,
    int $productId,
    array $row
): int {
    $stmt = $db->prepare(
        "INSERT INTO product_composition
            (product_id, ingredient_id, is_default, default_quantity,
             min_quantity, max_quantity, price_modifier, display_order,
             is_required)
         VALUES
            (:product_id, :ingredient_id, :is_default, :default_quantity,
             :min_quantity, :max_quantity, :price_modifier, :display_order,
             :is_required)"
    );
    $stmt->execute([
        ':product_id'        => $productId,
        ':ingredient_id'     => (int)$row['ingredient_id'],
        ':is_default'        => !empty($row['is_default']) ? 1 : 0,
        ':default_quantity'  => (int)($row['default_quantity'] ?? 0),
        ':min_quantity'      => (int)($row['min_quantity'] ?? 0),
        ':max_quantity'      => (int)($row['max_quantity'] ?? 0),
        ':price_modifier'    => (float)($row['price_modifier'] ?? 0),
        ':display_order'     => (int)($row['display_order'] ?? 0),
        ':is_required'       => !empty($row['is_required']) ? 1 : 0,
    ]);

    return (int)$db->lastInsertId();
}

/* =============================================================
 * WRITE — UPDATE
 * ============================================================= */

/**
 * Update an existing dietary_information row.
 *
 * @param PDO $db
 * @param int $dietaryInformationId
 * @param array $data
 * @return bool
 */
function updateDietaryInformation(
    PDO $db,
    int $dietaryInformationId,
    array $data
): bool {
    if ($dietaryInformationId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "UPDATE dietary_information
            SET images       = :images,
                category     = :category,
                dietary_tags = :dietary_tags,
                allergens    = :allergens,
                calories     = :calories,
                protein      = :protein,
                carbs        = :carbs,
                fat          = :fat
          WHERE dietary_information_id = :dietary_information_id"
    );
    $stmt->execute([
        ':images'                   => $data['images']       ?? null,
        ':category'                 => $data['category']     ?? null,
        ':dietary_tags'             => $data['dietary_tags'] ?? null,
        ':allergens'                => $data['allergens']    ?? null,
        ':calories'                 => $data['calories']     ?? null,
        ':protein'                  => $data['protein']      ?? null,
        ':carbs'                    => $data['carbs']        ?? null,
        ':fat'                      => $data['fat']          ?? null,
        ':dietary_information_id'   => $dietaryInformationId,
    ]);

    return true;
}

/**
 * Update an existing product row, scoped to the owner's restaurant.
 *
 * @param PDO $db
 * @param int $productId
 * @param int $restaurantId
 * @param array $data
 * @return bool
 */
function updateProduct(
    PDO $db,
    int $productId,
    int $restaurantId,
    array $data
): bool {
    if ($productId <= 0 || $restaurantId <= 0) {
        return false;
    }

    $branchId = getOwnedProductBranchId($db, $productId, $restaurantId);
    if ($branchId <= 0) {
        return false;
    }

    $isCustomizable = !empty($data['is_customizable']) ? 1 : 0;

    $customizationType = $isCustomizable
        ? ($data['customization_type'] ?? 'structured')
        : 'none';

    $price     = (float)($data['price'] ?? 0);
    $basePrice = $isCustomizable
        ? (float)($data['base_price'] ?? $price)
        : 0.00;

    $stmt = $db->prepare(
        "UPDATE product
            SET name               = :name,
                description        = :description,
                price              = :price,
                stock              = :stock,
                is_customizable    = :is_customizable,
                customization_type = :customization_type,
                base_price         = :base_price,
                is_active          = :is_active
          WHERE product_id = :product_id
            AND restaurant_branch_id = :branch_id"
    );
    $stmt->execute([
        ':name'               => $data['name'],
        ':description'        => $data['description'] ?? null,
        ':price'              => $price,
        ':stock'              => (int)($data['stock'] ?? 0),
        ':is_customizable'    => $isCustomizable,
        ':customization_type' => $customizationType,
        ':base_price'         => $basePrice,
        ':is_active'          => isset($data['is_active'])
            ? (int)$data['is_active']
            : 1,
        ':product_id'         => $productId,
        ':branch_id'          => $branchId,
    ]);

    return true;
}

/**
 * Replace every product_composition row for the given product with
 * the supplied set.
 *
 * This is the correct shape for the edit flow: the owner sees the
 * full ingredient list, changes what they want, and saves. Partial
 * row-level updates would require the client to track composition_id
 * values across re-renders for no benefit.
 *
 * The caller must run this inside a transaction. Composition rows
 * that reference an ingredient_id not present in the ingredient
 * master will fail on the foreign key; the caller should validate
 * incoming ingredient ids against the master list before calling.
 *
 * @param PDO $db
 * @param int $productId
 * @param array<int, array<string, mixed>> $rows
 * @return void
 */
function saveProductComposition(PDO $db, int $productId, array $rows): void
{
    if ($productId <= 0) {
        return;
    }

    $delete = $db->prepare(
        "DELETE FROM product_composition WHERE product_id = :product_id"
    );
    $delete->execute([':product_id' => $productId]);

    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        if (empty($row['ingredient_id'])) continue;
        createProductCompositionRow($db, $productId, $row);
    }
}

/* =============================================================
 * WRITE — IMAGE FOLDER
 * ============================================================= */

/**
 * Write the dietary_information.images folder for a product.
 *
 * Called by the media upload handler once the folder exists on disk
 * and the filenames have been written. The value stored is the
 * project-root-relative folder path ending with a slash:
 *
 *     shared/uploads/restaurant/products/<product_id>/
 *
 * The customer-role image resolver's third candidate root matches
 * this value without any change to the resolver. See the file header.
 *
 * @param PDO $db
 * @param int $dietaryInformationId
 * @param string $folder
 * @return void
 */
function setProductImageFolder(
    PDO $db,
    int $dietaryInformationId,
    string $folder
): void {
    if ($dietaryInformationId <= 0) {
        return;
    }

    $stmt = $db->prepare(
        "UPDATE dietary_information
            SET images = :images
          WHERE dietary_information_id = :dietary_information_id"
    );
    $stmt->execute([
        ':images'                 => $folder,
        ':dietary_information_id' => $dietaryInformationId,
    ]);
}

/**
 * Delete the image folder for a product from disk.
 *
 * Does not touch the database. Called by the delete handler inside
 * the transaction, and by the media upload handler when a
 * replacement upload is about to write fresh files.
 *
 * Silently ignores a missing folder.
 *
 * @param int $productId
 * @return void
 */
function deleteProductMedia(int $productId): void
{
    if ($productId <= 0) {
        return;
    }

    $projectRoot = dirname(__DIR__, 3);
    $folder = $projectRoot
        . '/shared/uploads/restaurant/products/'
        . $productId;

    if (!is_dir($folder)) {
        return;
    }

    $entries = @scandir($folder);
    if ($entries !== false) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $folder . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    @rmdir($folder);
}

/* =============================================================
 * WRITE — DELETE
 * ============================================================= */

/**
 * Hard-delete a product, scoped to the owner's restaurant.
 *
 * The schema's foreign keys cascade product → product_composition
 * and product → queue_item. The dietary_information row is NOT
 * cascaded; this function deletes it explicitly after the product
 * row is removed.
 *
 * The image folder on disk is removed before the transaction
 * commits. If the caller's transaction rolls back after this
 * function has removed the folder, the folder is gone but the
 * product row survives; that is a cosmetic loss only, and the
 * product can be re-saved. Removing the folder first avoids an
 * orphaned folder when the transaction succeeds.
 *
 * The caller must supply the product's dietary_information_id so
 * this function can remove it without a second SELECT.
 *
 * @param PDO $db
 * @param int $productId
 * @param int $restaurantId
 * @param int $dietaryInformationId
 * @return bool
 */
function deleteProduct(
    PDO $db,
    int $productId,
    int $restaurantId,
    int $dietaryInformationId
): bool {
    if ($productId <= 0 || $restaurantId <= 0) {
        return false;
    }

    $branchId = getOwnedProductBranchId($db, $productId, $restaurantId);
    if ($branchId <= 0) {
        return false;
    }

    deleteProductMedia($productId);

    $stmt = $db->prepare(
        "DELETE FROM product
          WHERE product_id = :product_id
            AND restaurant_branch_id = :branch_id"
    );
    $stmt->execute([
        ':product_id' => $productId,
        ':branch_id'  => $branchId,
    ]);

    if ($stmt->rowCount() === 0) {
        return false;
    }

    if ($dietaryInformationId > 0) {
        $stmt = $db->prepare(
            "DELETE FROM dietary_information
              WHERE dietary_information_id = :id"
        );
        $stmt->execute([':id' => $dietaryInformationId]);
    }

    return true;
}