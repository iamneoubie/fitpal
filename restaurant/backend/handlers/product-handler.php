<?php
/**
 * FitPal Restaurant Product Handler
 *
 * The single HTTP entry point for every product mutation an owner
 * performs: create a new product, edit an existing one, delete a
 * product, and fetch the ingredient master list for the edit form.
 *
 * ---------------------------------------------------------------------
 * ACTIONS
 * ---------------------------------------------------------------------
 *   create_product     insert a product + dietary_information +
 *                      optional product_composition rows + move
 *                      uploaded images, all inside one transaction
 *
 *   update_product     update an existing product the caller owns,
 *                      replace its composition rows, replace any
 *                      images the owner changed
 *
 *   delete_product     hard-delete a product the caller owns and
 *                      remove its media folder from disk
 *
 *   get_ingredients    return the master ingredient list for the
 *                      edit form. Read-only, no transaction.
 *
 * ---------------------------------------------------------------------
 * CATEGORY ALLOWLIST
 * ---------------------------------------------------------------------
 * dietary_information.category is CHECK-constrained in the schema to
 * one of four values, or NULL:
 *
 *     'Appetizer', 'Main', 'Dessert', 'Beverage'
 *
 * validateProductInput() enforces this allowlist before the
 * transaction opens, and the edit form renders Category as a select
 * so the invalid set is unreachable from the UI. A value that fails
 * the allowlist is reported as a first-class field error and never
 * reaches the database.
 *
 * ---------------------------------------------------------------------
 * AUTHORIZATION
 * ---------------------------------------------------------------------
 * Every action requires:
 *   - an authenticated restaurant session
 *   - restaurant_scope = 'owner' (owner or partner only)
 *
 * ---------------------------------------------------------------------
 * CSRF
 * ---------------------------------------------------------------------
 * Validated against the restaurant role's own session key,
 * restaurant_csrf_token. The shared key csrf_token is never read,
 * written, or cleared.
 *
 * ---------------------------------------------------------------------
 * IMAGE HANDLING — SLOT SHAPE
 * ---------------------------------------------------------------------
 * The edit form posts images as a per-slot map. This handler
 * rebuilds the map from $_POST and $_FILES and passes it to
 * storeProductMediaSlots() in product-media-handler.php.
 *
 * ---------------------------------------------------------------------
 * TRANSACTION SHAPE — CREATE
 * ---------------------------------------------------------------------
 * $productId is declared as 0 before the transaction opens, so the
 * catch block can safely test it after any failure.
 *
 * @package FitPal
 * @version 2.2 — validateProductInput() enforces the
 *                dietary_information.category allowlist before the
 *                transaction opens.
 *
 *                (2.1: $productId declared before transaction.
 *                2.0: slot-aware images. 1.0: single upload area.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('restaurant');

header('Content-Type: application/json; charset=utf-8');

/* --------------------------------------------------------------
 * AUTHORIZED CATEGORY VALUES
 * Must match the CHECK on dietary_information.category.
 * -------------------------------------------------------------- */

if (!defined('FITPAL_PRODUCT_CATEGORIES')) {
    define('FITPAL_PRODUCT_CATEGORIES', [
        'Appetizer',
        'Main',
        'Dessert',
        'Beverage',
    ]);
}

/* --------------------------------------------------------------
 * AUTHENTICATION
 * -------------------------------------------------------------- */

if (empty($_SESSION['restaurant_account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$restaurantId = (int)($_SESSION['restaurant_id'] ?? 0);
$accountScope = (string)($_SESSION['restaurant_scope'] ?? '');

if ($restaurantId <= 0) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'No restaurant is associated with this account.',
    ]);
    exit;
}

if ($accountScope !== 'owner') {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Only owners can manage products.',
    ]);
    exit;
}

/* --------------------------------------------------------------
 * DEPENDENCIES
 * -------------------------------------------------------------- */

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/product-queries.php';
require_once __DIR__ . '/../../includes/restaurant-csrf-token.php';
require_once __DIR__ . '/product-media-handler.php';

/* --------------------------------------------------------------
 * CSRF
 * -------------------------------------------------------------- */

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['restaurant_csrf_token'] ?? '');

if (
    $sessToken === '' ||
    $givenToken === '' ||
    !hash_equals($sessToken, $givenToken)
) {
    unset($_SESSION['restaurant_csrf_token']);

    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

/* --------------------------------------------------------------
 * DISPATCH
 * -------------------------------------------------------------- */

$action = (string)($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'get_ingredients':
            handleGetIngredients($database_connection);
            break;

        case 'create_product':
            handleCreateProduct($database_connection, $restaurantId);
            break;

        case 'update_product':
            handleUpdateProduct($database_connection, $restaurantId);
            break;

        case 'delete_product':
            handleDeleteProduct($database_connection, $restaurantId);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            exit;
    }
} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Product handler DB error: ' . $e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'message' => 'A database error occurred. Please try again.',
    ]);
    exit;
} catch (Throwable $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Product handler error: ' . $e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'message' => 'A system error occurred. Please try again.',
    ]);
    exit;
}

/* =============================================================
 * HANDLERS
 * ============================================================= */

function handleGetIngredients(PDO $db): never
{
    $rows = getAllActiveIngredients($db);

    $ingredients = array_map(
        static function (array $row): array {
            return [
                'ingredient_id' => (int)$row['ingredient_id'],
                'name'          => (string)$row['name'],
                'description'   => (string)($row['description'] ?? ''),
                'unit_price'    => (float)$row['unit_price'],
                'calories'      => (int)($row['calories'] ?? 0),
                'protein'       => (float)($row['protein'] ?? 0),
                'carbs'         => (float)($row['carbs'] ?? 0),
                'fat'           => (float)($row['fat'] ?? 0),
                'dietary_tags'  => (string)($row['dietary_tags'] ?? ''),
                'allergens'     => (string)($row['allergens'] ?? ''),
            ];
        },
        $rows
    );

    echo json_encode([
        'status'      => 'success',
        'ingredients' => $ingredients,
    ]);
    exit;
}

function handleCreateProduct(PDO $db, int $restaurantId): never
{
    $productId = 0;

    $branchId = getOwnerPrimaryBranchId($db, $restaurantId);

    if ($branchId <= 0) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'No active branch found for this restaurant.',
        ]);
        exit;
    }

    $input  = collectProductInput($_POST);
    $errors = validateProductInput($input);

    if (!empty($errors)) {
        echo json_encode([
            'status'  => 'error',
            'field'   => array_key_first($errors),
            'message' => reset($errors),
        ]);
        exit;
    }

    $composition = normalizeCompositionPayload(
        $input['composition'],
        $input['is_customizable']
    );

    $ingredientIdCheck = validateCompositionIngredientIds($db, $composition);
    if ($ingredientIdCheck !== null) {
        echo json_encode([
            'status'  => 'error',
            'field'   => 'composition',
            'message' => $ingredientIdCheck,
        ]);
        exit;
    }

    $slotMap = collectSlotMapFromPost($_POST, $_FILES);

    if (!slotMapHasAnyImage($slotMap)) {
        echo json_encode([
            'status'  => 'error',
            'field'   => 'images',
            'message' => 'Please upload at least one product image.',
        ]);
        exit;
    }

    $db->beginTransaction();

    try {
        $dietaryInformationId = createDietaryInformation($db, [
            'images'       => null, // set after upload
            'category'     => $input['category']     !== '' ? $input['category']     : null,
            'dietary_tags' => $input['dietary_tags'] !== '' ? $input['dietary_tags'] : null,
            'allergens'    => $input['allergens']    !== '' ? $input['allergens']    : null,
            'calories'     => $input['calories']     !== '' ? (int)$input['calories']     : null,
            'protein'      => $input['protein']      !== '' ? (float)$input['protein']    : null,
            'carbs'        => $input['carbs']        !== '' ? (float)$input['carbs']      : null,
            'fat'          => $input['fat']          !== '' ? (float)$input['fat']        : null,
        ]);

        $productId = createProduct($db, $branchId, $dietaryInformationId, [
            'name'               => $input['name'],
            'description'        => $input['description'],
            'price'              => $input['price'],
            'stock'              => $input['stock'],
            'is_customizable'    => $input['is_customizable'],
            'customization_type' => $input['is_customizable'] ? 'structured' : 'none',
            'base_price'         => $input['base_price'],
            'is_active'          => 1,
        ]);

        $mediaResult = storeProductMediaSlots($productId, $slotMap);

        if ($mediaResult['status'] !== 'success') {
            $db->rollBack();
            echo json_encode([
                'status'  => 'error',
                'field'   => 'images',
                'message' => $mediaResult['message'],
            ]);
            exit;
        }

        setProductImageFolder(
            $db,
            $dietaryInformationId,
            $mediaResult['folder']
        );

        if (!empty($composition)) {
            saveProductComposition($db, $productId, $composition);
        }

        $db->commit();

        echo json_encode([
            'status'     => 'success',
            'message'    => 'Product created successfully.',
            'product_id' => $productId,
            'redirect'   => 'products.php',
        ]);
        exit;

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($productId > 0) {
            deleteProductMediaFolder($productId);
        }
        throw $e;
    }
}

function handleUpdateProduct(PDO $db, int $restaurantId): never
{
    $productId = (int)($_POST['product_id'] ?? 0);

    if ($productId <= 0) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Invalid product id.',
        ]);
        exit;
    }

    $existing = getProductForOwner($db, $productId, $restaurantId);
    if ($existing === null) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Product not found.',
        ]);
        exit;
    }

    $input  = collectProductInput($_POST);
    $errors = validateProductInput($input);

    if (!empty($errors)) {
        echo json_encode([
            'status'  => 'error',
            'field'   => array_key_first($errors),
            'message' => reset($errors),
        ]);
        exit;
    }

    $composition = normalizeCompositionPayload(
        $input['composition'],
        $input['is_customizable']
    );

    $ingredientIdCheck = validateCompositionIngredientIds($db, $composition);
    if ($ingredientIdCheck !== null) {
        echo json_encode([
            'status'  => 'error',
            'field'   => 'composition',
            'message' => $ingredientIdCheck,
        ]);
        exit;
    }

    $slotMap = collectSlotMapFromPost($_POST, $_FILES);

    if (!slotMapLeavesAnyImage($slotMap, $productId)) {
        echo json_encode([
            'status'  => 'error',
            'field'   => 'images',
            'message' => 'Please keep or add at least one product image.',
        ]);
        exit;
    }

    $dietaryInformationId = (int)$existing['dietary_information_id'];

    $db->beginTransaction();

    try {
        $imageFolder = (string)$existing['images'];

        if (slotMapHasAnyChange($slotMap)) {
            $mediaResult = storeProductMediaSlots($productId, $slotMap);

            if ($mediaResult['status'] !== 'success') {
                $db->rollBack();
                echo json_encode([
                    'status'  => 'error',
                    'field'   => 'images',
                    'message' => $mediaResult['message'],
                ]);
                exit;
            }

            $imageFolder = $mediaResult['folder'];
        }

        updateDietaryInformation($db, $dietaryInformationId, [
            'images'       => $imageFolder !== '' ? $imageFolder : null,
            'category'     => $input['category']     !== '' ? $input['category']     : null,
            'dietary_tags' => $input['dietary_tags'] !== '' ? $input['dietary_tags'] : null,
            'allergens'    => $input['allergens']    !== '' ? $input['allergens']    : null,
            'calories'     => $input['calories']     !== '' ? (int)$input['calories']     : null,
            'protein'      => $input['protein']      !== '' ? (float)$input['protein']    : null,
            'carbs'        => $input['carbs']        !== '' ? (float)$input['carbs']      : null,
            'fat'          => $input['fat']          !== '' ? (float)$input['fat']        : null,
        ]);

        updateProduct($db, $productId, $restaurantId, [
            'name'               => $input['name'],
            'description'        => $input['description'],
            'price'              => $input['price'],
            'stock'              => $input['stock'],
            'is_customizable'    => $input['is_customizable'],
            'customization_type' => $input['is_customizable'] ? 'structured' : 'none',
            'base_price'         => $input['base_price'],
            'is_active'          => $input['is_active'],
        ]);

        saveProductComposition($db, $productId, $composition);

        $db->commit();

        echo json_encode([
            'status'     => 'success',
            'message'    => 'Product updated successfully.',
            'product_id' => $productId,
            'redirect'   => 'products.php',
        ]);
        exit;

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function handleDeleteProduct(PDO $db, int $restaurantId): never
{
    $productId = (int)($_POST['product_id'] ?? 0);

    if ($productId <= 0) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Invalid product id.',
        ]);
        exit;
    }

    $existing = getProductForOwner($db, $productId, $restaurantId);
    if ($existing === null) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Product not found.',
        ]);
        exit;
    }

    $activeCheck = $db->prepare(
        "SELECT COUNT(*) AS active_count
           FROM queue_item qi
           JOIN orders o ON o.order_id = qi.order_id
          WHERE qi.product_id = :product_id
            AND o.order_status IN ('pending','preparing','rider_pending',
                                   'picking_up','delivering')"
    );
    $activeCheck->execute([':product_id' => $productId]);
    $activeCount = (int)$activeCheck->fetchColumn();

    if ($activeCount > 0) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'This product has active orders. '
                       . 'Wait for those orders to finish before deleting it.',
        ]);
        exit;
    }

    $dietaryInformationId = (int)$existing['dietary_information_id'];

    $db->beginTransaction();

    try {
        $deleted = deleteProduct(
            $db,
            $productId,
            $restaurantId,
            $dietaryInformationId
        );

        if (!$deleted) {
            $db->rollBack();
            echo json_encode([
                'status'  => 'error',
                'message' => 'Could not delete the product.',
            ]);
            exit;
        }

        $db->commit();

        echo json_encode([
            'status'  => 'success',
            'message' => 'Product deleted.',
        ]);
        exit;

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/* =============================================================
 * IMAGE SLOT MAP
 * ============================================================= */

function collectSlotMapFromPost(array $post, array $files): array
{
    $ops = isset($post['image_op']) && is_array($post['image_op'])
        ? $post['image_op']
        : [];
    $filesBySlot = isset($files['image_file']) && is_array($files['image_file'])
        ? $files['image_file']
        : [];

    $out = [];

    for ($slot = 1; $slot <= 5; $slot++) {
        $mode = (string)($ops[$slot] ?? 'keep');
        if (!in_array($mode, ['keep', 'remove', 'replace'], true)) {
            $mode = 'keep';
        }

        if ($mode === 'replace') {
            $file = slotFileFromFilesArray($filesBySlot, $slot);
            if ($file === null) {
                $out[$slot] = ['mode' => 'keep'];
                continue;
            }
            $out[$slot] = [
                'mode' => 'replace',
                'file' => $file,
            ];
            continue;
        }

        $out[$slot] = ['mode' => $mode];
    }

    return $out;
}

function slotFileFromFilesArray(array $filesBySlot, int $slot): ?array
{
    if (!isset($filesBySlot['name'][$slot])) {
        return null;
    }

    $name    = (string)($filesBySlot['name'][$slot]     ?? '');
    $type    = (string)($filesBySlot['type'][$slot]     ?? '');
    $tmpName = (string)($filesBySlot['tmp_name'][$slot] ?? '');
    $error   = (int)   ($filesBySlot['error'][$slot]    ?? UPLOAD_ERR_NO_FILE);
    $size    = (int)   ($filesBySlot['size'][$slot]     ?? 0);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($tmpName === '') {
        return null;
    }

    return [
        'name'     => $name,
        'type'     => $type,
        'tmp_name' => $tmpName,
        'error'    => $error,
        'size'     => $size,
    ];
}

function slotMapHasAnyImage(array $slotMap): bool
{
    foreach ($slotMap as $op) {
        if (($op['mode'] ?? '') === 'replace') {
            return true;
        }
    }
    return false;
}

function slotMapHasAnyChange(array $slotMap): bool
{
    foreach ($slotMap as $op) {
        $mode = $op['mode'] ?? 'keep';
        if ($mode === 'replace' || $mode === 'remove') {
            return true;
        }
    }
    return false;
}

function slotMapLeavesAnyImage(array $slotMap, int $productId): bool
{
    foreach ($slotMap as $slotIndex => $op) {
        $mode = $op['mode'] ?? 'keep';

        if ($mode === 'replace') {
            return true;
        }
        if (
            $mode === 'keep' &&
            productMediaSlotFilename($productId, (int)$slotIndex) !== ''
        ) {
            return true;
        }
    }
    return false;
}

/* =============================================================
 * INPUT HELPERS
 * ============================================================= */

function collectProductInput(array $post): array
{
    return [
        'name'            => trim((string)($post['name'] ?? '')),
        'description'     => trim((string)($post['description'] ?? '')),
        'category'        => trim((string)($post['category'] ?? '')),
        'price'           => trim((string)($post['price'] ?? '')),
        'base_price'      => trim((string)($post['base_price'] ?? '')),
        'stock'           => trim((string)($post['stock'] ?? '')),
        'dietary_tags'    => trim((string)($post['dietary_tags'] ?? '')),
        'allergens'       => trim((string)($post['allergens'] ?? '')),
        'calories'        => trim((string)($post['calories'] ?? '')),
        'protein'         => trim((string)($post['protein'] ?? '')),
        'carbs'           => trim((string)($post['carbs'] ?? '')),
        'fat'             => trim((string)($post['fat'] ?? '')),
        'is_customizable' => !empty($post['is_customizable']) ? 1 : 0,
        'is_active'       => isset($post['is_active'])
            ? (int)(bool)$post['is_active']
            : 1,
        'composition'     => (string)($post['composition'] ?? '[]'),
    ];
}

function validateProductInput(array $input): array
{
    $errors = [];

    if ($input['name'] === '') {
        $errors['name'] = 'Product name is required.';
    } elseif (mb_strlen($input['name']) < 2) {
        $errors['name'] = 'Product name must be at least 2 characters.';
    } elseif (mb_strlen($input['name']) > 100) {
        $errors['name'] = 'Product name must be at most 100 characters.';
    }

    if (mb_strlen($input['description']) > 5000) {
        $errors['description'] = 'Description is too long (max 5000 characters).';
    }

    // Category is a nullable enum in the schema. An empty value
    // maps to NULL. A non-empty value must be one of the four
    // allowed strings, exactly and case-sensitively.
    if ($input['category'] !== '') {
        $allowed = FITPAL_PRODUCT_CATEGORIES;
        if (!in_array($input['category'], $allowed, true)) {
            $errors['category'] = 'Category must be one of: '
                . implode(', ', $allowed)
                . ' (or leave it blank).';
        }
    }

    if ($input['price'] === '') {
        $errors['price'] = 'Price is required.';
    } elseif (!is_numeric($input['price'])) {
        $errors['price'] = 'Price must be a number.';
    } elseif ((float)$input['price'] < 0) {
        $errors['price'] = 'Price cannot be negative.';
    } elseif ((float)$input['price'] > 99999.99) {
        $errors['price'] = 'Price is too large.';
    }

    if ($input['stock'] === '') {
        $errors['stock'] = 'Stock is required.';
    } elseif (!preg_match('/^\d+$/', $input['stock'])) {
        $errors['stock'] = 'Stock must be a whole number.';
    } elseif ((int)$input['stock'] > 99999) {
        $errors['stock'] = 'Stock is too large.';
    }

    if ($input['is_customizable']) {
        if ($input['base_price'] === '') {
            $errors['base_price'] = 'Base price is required for customizable products.';
        } elseif (!is_numeric($input['base_price'])) {
            $errors['base_price'] = 'Base price must be a number.';
        } elseif ((float)$input['base_price'] < 0) {
            $errors['base_price'] = 'Base price cannot be negative.';
        }
    }

    foreach (['calories', 'protein', 'carbs', 'fat'] as $numField) {
        if ($input[$numField] === '') continue;
        if (!is_numeric($input[$numField])) {
            $errors[$numField] = ucfirst($numField) . ' must be a number.';
            continue;
        }
        if ((float)$input[$numField] < 0) {
            $errors[$numField] = ucfirst($numField) . ' cannot be negative.';
        }
    }

    return $errors;
}

function normalizeCompositionPayload(string $json, int $isCustomizable): array
{
    if (!$isCustomizable) {
        return [];
    }

    $raw = trim($json);
    if ($raw === '' || $raw === '[]') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];

    foreach ($decoded as $entry) {
        if (!is_array($entry)) continue;

        $ingredientId = (int)($entry['ingredient_id'] ?? 0);
        if ($ingredientId <= 0) continue;

        $min = max(0, (int)($entry['min_quantity']     ?? 0));
        $max = max(0, (int)($entry['max_quantity']     ?? 1));
        $def = max(0, (int)($entry['default_quantity'] ?? 0));

        if ($max < $min) {
            $max = $min;
        }
        if ($def < $min) {
            $def = $min;
        }
        if ($def > $max) {
            $def = $max;
        }

        $out[] = [
            'ingredient_id'    => $ingredientId,
            'is_default'       => !empty($entry['is_default']) ? 1 : 0,
            'default_quantity' => $def,
            'min_quantity'     => $min,
            'max_quantity'     => $max,
            'price_modifier'   => (float)($entry['price_modifier'] ?? 0),
            'display_order'    => (int)($entry['display_order']    ?? 0),
            'is_required'      => !empty($entry['is_required']) ? 1 : 0,
        ];
    }

    return $out;
}

function validateCompositionIngredientIds(PDO $db, array $composition): ?string
{
    if (empty($composition)) {
        return null;
    }

    $ids = array_values(array_unique(array_map(
        static fn(array $row): int => (int)$row['ingredient_id'],
        $composition
    )));

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $db->prepare(
        "SELECT ingredient_id
           FROM ingredient
          WHERE ingredient_id IN ($placeholders)"
    );
    $stmt->execute($ids);
    $found = array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );

    $missing = array_diff($ids, $found);

    if (!empty($missing)) {
        return 'One or more ingredients are not in the master list. '
             . 'Please refresh the page and try again.';
    }

    return null;
}