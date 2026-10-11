<?php
/**
 * FitPal Restaurant Product Edit / Create Page
 *
 * A single page that serves both the "add a new product" flow and
 * the "edit an existing product" flow. The presence of ?id=<n> in
 * the query string selects which flow runs.
 *
 * ---------------------------------------------------------------------
 * LAYOUT — THREE TABS
 * ---------------------------------------------------------------------
 * Details / Images / Customization.
 *
 * ---------------------------------------------------------------------
 * CATEGORY FIELD
 * ---------------------------------------------------------------------
 * dietary_information.category is CHECK-constrained in the schema
 * to one of four values or NULL. The Category field is therefore a
 * <select> whose options are exactly those four values plus an
 * empty "— None —" option.
 *
 * ---------------------------------------------------------------------
 * IMAGE SLOTS
 * ---------------------------------------------------------------------
 * Five slots, each mapping to image-N.* inside the folder the
 * reader actually used. Slot URLs are built from
 * productMediaSlotBasePath().
 *
 * ---------------------------------------------------------------------
 * MODAL ICONS
 * ---------------------------------------------------------------------
 * Each modal's icon is chosen to match the modal's meaning:
 *
 *   validation   error-warning-line.svg   a field-level error
 *   success      save-fill.svg            a save completed
 *   error        error-warning-line.svg   a save did not complete
 *   leave        question-fill.svg        an unsaved-changes prompt
 *
 * "save-fill" is used for the success modal instead of a badge
 * icon, because the badge icons in this project all mean "this
 * account is verified", not "this save completed".
 *
 * ---------------------------------------------------------------------
 * COMPOSITION ROW MARKUP
 * ---------------------------------------------------------------------
 * Each ingredient row is a .composition-row card. Inputs are
 * wrapped in .composition-field so each one can carry a micro
 * label that is shown only on narrow viewports, where the desktop
 * header row is hidden.
 *
 * @package FitPal
 * @version 3.2 — Modal icons corrected. Success uses save-fill.
 *                Composition rows carry micro-labels and are
 *                wrapped in .composition-field for mobile.
 *
 *                (3.1: category select. 3.0: slot URLs from
 *                productMediaSlotBasePath. 2.2: legacy fallback.
 *                2.1: server-rendered previews. 2.0: tabbed
 *                layout. 1.0: single upload area.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('restaurant');

if (empty($_SESSION['restaurant_account_id'])) {
    header('Location: sign-in.php');
    exit;
}

$accountScope = (string)($_SESSION['restaurant_scope'] ?? '');
$restaurantId = (int)($_SESSION['restaurant_id'] ?? 0);

if ($accountScope !== 'owner' || $restaurantId <= 0) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/product-queries.php';
require_once __DIR__ . '/../backend/handlers/product-media-handler.php';

// $assetBase and $csrfToken are provided by header.php.

$editMode  = false;
$product   = null;
$loadError = '';

$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($productId > 0) {
    try {
        $product = getProductForOwner($database_connection, $productId, $restaurantId);
    } catch (PDOException $e) {
        error_log('Product edit load error: ' . $e->getMessage());
        $loadError = 'Could not load this product. Please try again.';
    }

    if ($product === null) {
        header('Location: products.php');
        exit;
    }

    $editMode = true;
}

/* --------------------------------------------------------------
 * AUTHORIZED CATEGORIES
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
 * FORM SHAPE
 * -------------------------------------------------------------- */

$form = [
    'product_id'       => $editMode ? (int)$product['product_id'] : 0,
    'name'             => $editMode ? (string)$product['name'] : '',
    'description'      => $editMode ? (string)($product['description'] ?? '') : '',
    'category'         => $editMode ? (string)($product['category']    ?? '') : '',
    'price'            => $editMode ? (string)$product['price'] : '',
    'stock'            => $editMode ? (string)$product['stock'] : '',
    'base_price'       => $editMode ? (string)$product['base_price'] : '',
    'dietary_tags'     => $editMode ? (string)($product['dietary_tags'] ?? '') : '',
    'allergens'        => $editMode ? (string)($product['allergens']    ?? '') : '',
    'calories'         => $editMode && $product['calories'] !== null ? (string)$product['calories'] : '',
    'protein'          => $editMode && $product['protein']  !== null ? (string)$product['protein']  : '',
    'carbs'            => $editMode && $product['carbs']    !== null ? (string)$product['carbs']    : '',
    'fat'              => $editMode && $product['fat']      !== null ? (string)$product['fat']      : '',
    'is_customizable'  => $editMode ? ((int)$product['is_customizable'] === 1) : false,
    'is_active'        => $editMode ? ((int)$product['is_active'] === 1) : true,
];

if (
    $form['category'] !== '' &&
    !in_array($form['category'], FITPAL_PRODUCT_CATEGORIES, true)
) {
    $form['category'] = '';
}

/* --------------------------------------------------------------
 * COMPOSITION
 * -------------------------------------------------------------- */

$initialComposition = [];

if ($editMode) {
    try {
        $initialComposition = getProductCompositionForOwner(
            $database_connection,
            (int)$product['product_id']
        );
    } catch (PDOException $e) {
        error_log('Product composition load error: ' . $e->getMessage());
        $initialComposition = [];
    }
}

$compositionPayload = [];
foreach ($initialComposition as $row) {
    $compositionPayload[] = [
        'ingredient_id'    => (int)$row['ingredient_id'],
        'ingredient_name'  => (string)$row['ingredient_name'],
        'is_default'       => ((int)$row['is_default'] === 1),
        'default_quantity' => (int)$row['default_quantity'],
        'min_quantity'     => (int)$row['min_quantity'],
        'max_quantity'     => (int)$row['max_quantity'],
        'price_modifier'   => (float)$row['price_modifier'],
        'display_order'    => (int)$row['display_order'],
        'is_required'      => ((int)$row['is_required'] === 1),
    ];
}

/* --------------------------------------------------------------
 * IMAGE SLOTS
 * -------------------------------------------------------------- */

$projectRootUrl = preg_replace('#shared/$#', '', $assetBase);
if (!is_string($projectRootUrl)) {
    $projectRootUrl = '';
}

$slotBasePath = $editMode ? productMediaSlotBasePath($productId) : '';

$imageSlots = [];
for ($slot = 1; $slot <= 5; $slot++) {
    $slotFilename = '';
    $slotUrl      = '';

    if ($editMode) {
        $slotFilename = productMediaSlotFilename($productId, $slot);
        if (
            $slotFilename !== '' &&
            $slotBasePath  !== '' &&
            $projectRootUrl !== ''
        ) {
            $slotUrl = $projectRootUrl . $slotBasePath . $slotFilename;
        }
    }

    $imageSlots[] = [
        'index'    => $slot,
        'filename' => $slotFilename,
        'url'      => $slotUrl,
    ];
}

$pageTitle   = $editMode ? 'Edit Product' : 'Add New Product';
$submitLabel = $editMode ? 'Save Changes' : 'Create Product';
?>
<div class="content restaurant-product-edit-page" id="productEditPage"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
    data-handler-url="../backend/handlers/product-handler.php"
    data-asset-base="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>"
    data-edit-mode="<?php echo $editMode ? '1' : '0'; ?>" data-product-id="<?php echo (int)$form['product_id']; ?>"
    data-initial-composition="<?php echo htmlspecialchars(json_encode($compositionPayload), ENT_QUOTES, 'UTF-8'); ?>">

    <div class="container">

        <header class="page-title-header">
            <div class="page-title-header-top">
                <a href="products.php" class="back-btn" id="backBtn">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="Back"
                        class="back-btn-icon" width="20" height="20"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-left-long-line.svg'">
                    <span>Back to Products</span>
                </a>
                <h1><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            </div>
        </header>

        <?php if (!empty($loadError)): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================
             TAB BAR
             ============================================================ -->
        <nav class="product-tabs" role="tablist" aria-label="Product sections">
            <button type="button" class="product-tab active" role="tab" aria-selected="true" aria-controls="tab-details"
                data-tab="details">
                <span class="product-tab-index">1</span>
                <span class="product-tab-label">Details</span>
            </button>
            <button type="button" class="product-tab" role="tab" aria-selected="false" aria-controls="tab-images"
                data-tab="images">
                <span class="product-tab-index">2</span>
                <span class="product-tab-label">Images</span>
            </button>
            <button type="button" class="product-tab" role="tab" aria-selected="false" aria-controls="tab-customization"
                data-tab="customization">
                <span class="product-tab-index">3</span>
                <span class="product-tab-label">Customization</span>
            </button>
        </nav>

        <form id="productForm" class="product-form" enctype="multipart/form-data" novalidate>

            <!-- ============================================================
                 TAB 1: DETAILS
                 ============================================================ -->
            <section class="product-tab-panel active" id="tab-details" role="tabpanel" aria-labelledby="tab-details">

                <section class="form-section">
                    <h2 class="form-section-title">Product Details</h2>

                    <div class="form-group">
                        <label for="name" class="form-label">Product Name <span class="req">*</span></label>
                        <input type="text" id="name" name="name" class="form-control" required maxlength="100"
                            placeholder="e.g. Garden Harvest Bowl"
                            value="<?php echo htmlspecialchars($form['name'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="form-error" id="nameError"></div>
                    </div>

                    <div class="form-group">
                        <label for="description" class="form-label">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="4" maxlength="5000"
                            placeholder="Describe what makes this product special."><?php echo htmlspecialchars($form['description'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <div class="form-error" id="descriptionError"></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="category" class="form-label">Category</label>
                            <select id="category" name="category" class="form-control">
                                <option value="">— None —</option>
                                <?php foreach (FITPAL_PRODUCT_CATEGORIES as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $form['category'] === $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-hint">
                                Leave blank or pick the closest fit.
                            </div>
                            <div class="form-error" id="categoryError"></div>
                        </div>

                        <div class="form-group">
                            <label for="stock" class="form-label">Stock <span class="req">*</span></label>
                            <input type="number" id="stock" name="stock" class="form-control" required min="0" step="1"
                                inputmode="numeric"
                                value="<?php echo htmlspecialchars($form['stock'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="stockError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="price" class="form-label">Price (₱) <span class="req">*</span></label>
                            <input type="number" id="price" name="price" class="form-control" required min="0"
                                step="0.01" inputmode="decimal"
                                value="<?php echo htmlspecialchars($form['price'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="priceError"></div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <label class="switch-field">
                                <input type="checkbox" id="is_active" name="is_active" value="1"
                                    <?php echo $form['is_active'] ? 'checked' : ''; ?>>
                                <span class="switch-track" aria-hidden="true"></span>
                                <span class="switch-label">Active</span>
                            </label>
                        </div>
                    </div>
                </section>

                <section class="form-section">
                    <h2 class="form-section-title">Nutrition &amp; Tags</h2>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="dietary_tags" class="form-label">Dietary Tags</label>
                            <input type="text" id="dietary_tags" name="dietary_tags" class="form-control"
                                maxlength="200" placeholder="vegan,gluten_free"
                                value="<?php echo htmlspecialchars($form['dietary_tags'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-hint">Comma-separated. Leave blank if none apply.</div>
                        </div>

                        <div class="form-group">
                            <label for="allergens" class="form-label">Allergens</label>
                            <input type="text" id="allergens" name="allergens" class="form-control" maxlength="200"
                                placeholder="nuts,dairy"
                                value="<?php echo htmlspecialchars($form['allergens'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-hint">Comma-separated. Leave blank if none.</div>
                        </div>
                    </div>

                    <div class="form-row form-row-4">
                        <div class="form-group">
                            <label for="calories" class="form-label">Calories</label>
                            <input type="number" id="calories" name="calories" class="form-control" min="0" step="1"
                                inputmode="numeric"
                                value="<?php echo htmlspecialchars($form['calories'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="caloriesError"></div>
                        </div>

                        <div class="form-group">
                            <label for="protein" class="form-label">Protein (g)</label>
                            <input type="number" id="protein" name="protein" class="form-control" min="0" step="0.1"
                                inputmode="decimal"
                                value="<?php echo htmlspecialchars($form['protein'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="proteinError"></div>
                        </div>

                        <div class="form-group">
                            <label for="carbs" class="form-label">Carbs (g)</label>
                            <input type="number" id="carbs" name="carbs" class="form-control" min="0" step="0.1"
                                inputmode="decimal"
                                value="<?php echo htmlspecialchars($form['carbs'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="carbsError"></div>
                        </div>

                        <div class="form-group">
                            <label for="fat" class="form-label">Fat (g)</label>
                            <input type="number" id="fat" name="fat" class="form-control" min="0" step="0.1"
                                inputmode="decimal"
                                value="<?php echo htmlspecialchars($form['fat'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="fatError"></div>
                        </div>
                    </div>
                </section>
            </section>

            <!-- ============================================================
                 TAB 2: IMAGES
                 ============================================================ -->
            <section class="product-tab-panel" id="tab-images" role="tabpanel" aria-labelledby="tab-images" hidden>

                <section class="form-section images-section">
                    <h2 class="form-section-title">Product Images</h2>
                    <p class="form-section-intro">
                        Up to five images. Slot 1 is the thumbnail the customer
                        sees on the menu. Slots 2 through 5 appear on the product
                        detail page in this order. Each image must be under 5&nbsp;MB
                        (JPG, PNG, WEBP, GIF).
                    </p>

                    <div class="image-slot-grid" id="imageSlotGrid">
                        <?php foreach ($imageSlots as $slot):
                            $idx       = (int)$slot['index'];
                            $hasImage  = $slot['url'] !== '';
                            $isMain    = ($idx === 1);
                        ?>
                        <div class="image-slot <?php echo $hasImage ? 'has-image' : 'is-empty'; ?> <?php echo $isMain ? 'is-main' : ''; ?>"
                            data-slot="<?php echo $idx; ?>" data-has-existing="<?php echo $hasImage ? '1' : '0'; ?>"
                            data-existing-url="<?php echo htmlspecialchars($slot['url'], ENT_QUOTES, 'UTF-8'); ?>">

                            <div class="image-slot-frame">
                                <img class="image-slot-preview"
                                    src="<?php echo $hasImage ? htmlspecialchars($slot['url'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                                    alt="" loading="lazy" style="<?php echo $hasImage ? '' : 'display: none;'; ?>"
                                    onerror="this.onerror=null; this.style.display='none'; this.parentElement.classList.add('is-broken');">

                                <div class="image-slot-empty">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/image-upload-fill.svg" alt=""
                                        class="image-slot-empty-icon" width="22" height="22"
                                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/image-line.svg'">
                                    <span class="image-slot-empty-label">Add image</span>
                                </div>

                                <?php if ($isMain): ?>
                                <span class="image-slot-badge image-slot-badge-main">Main</span>
                                <?php else: ?>
                                <span class="image-slot-badge image-slot-badge-index"><?php echo $idx; ?></span>
                                <?php endif; ?>

                                <span class="image-slot-badge image-slot-badge-new" hidden>New</span>

                                <button type="button" class="image-slot-remove" aria-label="Remove image"
                                    <?php echo $hasImage ? '' : 'hidden'; ?>>
                                    &times;
                                </button>

                                <label class="image-slot-hit" for="image-file-<?php echo $idx; ?>"
                                    aria-label="Choose image for slot <?php echo $idx; ?>"></label>

                                <input type="file" id="image-file-<?php echo $idx; ?>"
                                    name="image_file[<?php echo $idx; ?>]" class="image-slot-input"
                                    accept="image/jpeg,image/png,image/webp,image/gif" hidden
                                    data-slot="<?php echo $idx; ?>">

                                <input type="hidden" name="image_op[<?php echo $idx; ?>]"
                                    id="image-op-<?php echo $idx; ?>" value="keep" data-slot="<?php echo $idx; ?>">
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-error" id="imagesError"></div>
                </section>
            </section>

            <!-- ============================================================
                 TAB 3: CUSTOMIZATION
                 ============================================================ -->
            <section class="product-tab-panel" id="tab-customization" role="tabpanel"
                aria-labelledby="tab-customization" hidden>

                <section class="form-section" id="compositionSection">
                    <header class="form-section-header">
                        <h2 class="form-section-title">Customization</h2>
                        <label class="switch-field">
                            <input type="checkbox" id="is_customizable" name="is_customizable" value="1"
                                <?php echo $form['is_customizable'] ? 'checked' : ''; ?>>
                            <span class="switch-track" aria-hidden="true"></span>
                            <span class="switch-label">Customizable</span>
                        </label>
                    </header>

                    <p class="form-section-intro">
                        When a product is customizable, customers may adjust the
                        ingredients that make it up. Each ingredient you add below
                        appears as a choice, a quantity, or a toggle on the product
                        page.
                    </p>

                    <div class="form-group" id="basePriceGroup" style="display: none;">
                        <label for="base_price" class="form-label">
                            Base Price (₱) <span class="req">*</span>
                        </label>
                        <input type="number" id="base_price" name="base_price" class="form-control" min="0" step="0.01"
                            inputmode="decimal"
                            value="<?php echo htmlspecialchars($form['base_price'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="form-hint">
                            The price of the default build. Ingredient price modifiers
                            are applied on top of this.
                        </div>
                        <div class="form-error" id="basePriceError"></div>
                    </div>

                    <div class="composition-editor" id="compositionEditor" style="display: none;">

                        <!-- Column header row (hidden on mobile). -->
                        <div class="composition-header" aria-hidden="true">
                            <span class="composition-col composition-col-ingredient">Ingredient</span>
                            <span class="composition-col composition-col-min">Min</span>
                            <span class="composition-col composition-col-max">Max</span>
                            <span class="composition-col composition-col-default">Default</span>
                            <span class="composition-col composition-col-default-qty">Qty</span>
                            <span class="composition-col composition-col-price">Price ±</span>
                            <span class="composition-col composition-col-order">Order</span>
                            <span class="composition-col composition-col-flags">Flags</span>
                            <span class="composition-col composition-col-actions"></span>
                        </div>

                        <div class="composition-rows" id="compositionRows"></div>

                        <button type="button" class="btn btn-outline btn-sm add-ingredient-btn" id="addIngredientBtn">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/add-line.svg" alt="" class="btn-icon"
                                width="14" height="14"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/add.svg'">
                            <span>Add Ingredient</span>
                        </button>

                        <div class="form-error" id="compositionError"></div>
                    </div>
                </section>
            </section>

            <!-- ============================================================
                 SHARED ACTIONS
                 ============================================================ -->
            <section class="form-section form-section-actions">
                <button type="button" class="btn btn-secondary" id="cancelBtn">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <span id="submitBtnLabel">
                        <?php echo htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </button>
            </section>
        </form>
    </div>

    <!-- ============================================================
         MODALS
         ============================================================ -->

    <!-- Validation: a single field-level error. -->
    <div class="modal" id="validationModal" role="dialog" aria-modal="true" style="display: none;">
        <div class="modal-overlay" data-close-modal="validation-modal"></div>
        <div class="modal-content modal-content-narrow">
            <div class="modal-icon modal-icon-danger" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/error-warning-line.svg" alt=""
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/error-warning-fill.svg'">
            </div>
            <h2 class="modal-title" id="validationTitle">Something needs attention</h2>
            <p class="modal-message" id="validationMessage"></p>
            <div class="modal-actions modal-actions-single">
                <button type="button" class="modal-btn modal-btn-confirm" data-close-modal="validation-modal">
                    Got it
                </button>
            </div>
        </div>
    </div>

    <!-- Success: the save completed. -->
    <div class="modal" id="successModal" role="dialog" aria-modal="true" style="display: none;">
        <div class="modal-overlay"></div>
        <div class="modal-content modal-content-narrow">
            <div class="modal-icon modal-icon-primary" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/save-fill.svg" alt=""
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/save-empty.svg'">
            </div>
            <h2 class="modal-title" id="successTitle">Saved</h2>
            <p class="modal-message" id="successMessage">Product saved.</p>
            <div class="modal-actions modal-actions-single">
                <button type="button" class="modal-btn modal-btn-confirm" id="successOkBtn">
                    Continue
                </button>
            </div>
        </div>
    </div>

    <!-- Error: the save did not complete. -->
    <div class="modal" id="errorModal" role="dialog" aria-modal="true" style="display: none;">
        <div class="modal-overlay" data-close-modal="error-modal"></div>
        <div class="modal-content modal-content-narrow">
            <div class="modal-icon modal-icon-danger" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/error-warning-line.svg" alt=""
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/error-warning-fill.svg'">
            </div>
            <h2 class="modal-title" id="errorTitle">Something went wrong</h2>
            <p class="modal-message" id="errorMessage"></p>
            <div class="modal-actions modal-actions-single">
                <button type="button" class="modal-btn modal-btn-confirm" data-close-modal="error-modal">
                    OK
                </button>
            </div>
        </div>
    </div>

    <!-- Leave: unsaved-changes prompt. -->
    <div class="modal" id="leaveModal" role="dialog" aria-modal="true" style="display: none;">
        <div class="modal-overlay" data-close-modal="leave-modal"></div>
        <div class="modal-content modal-content-narrow">
            <div class="modal-icon modal-icon-warning" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/question-fill.svg" alt=""
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
            </div>
            <h2 class="modal-title">Discard changes?</h2>
            <p class="modal-message">
                You have unsaved changes. Leave this page and discard them?
            </p>
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" data-close-modal="leave-modal">
                    Stay
                </button>
                <button type="button" class="modal-btn modal-btn-danger" id="confirmLeaveBtn">
                    Discard &amp; leave
                </button>
            </div>
        </div>
    </div>

</div>

<script>
window.FITPAL_PRODUCT_EDIT_CONFIG = {
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    handlerUrl: '../backend/handlers/product-handler.php',
    assetBase: '<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>',
    editMode: <?php echo $editMode ? 'true' : 'false'; ?>,
    productId: <?php echo (int)$form['product_id']; ?>
};
</script>
<script src="../assets/ui/js/product-edit.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>