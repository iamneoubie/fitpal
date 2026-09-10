<?php
/**
 * FitPal Product Detail Page
 * Version 7.0
 *
 * Emits data-default-quantity on every modifier so the JS can
 * compute deltas against the true starting state. Base price and
 * base calories are both derived from composition defaults, so the
 * customize step's initial total matches the menu card.
 *
 * @package FitPal
 * @version 7.0
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== VALIDATE PRODUCT ID =====
$productId = isset($_GET['id']) ? max(1, (int)$_GET['id']) : 0;
if ($productId <= 0) {
    header('Location: menu.php');
    exit;
}

require_once __DIR__ . '/../backend/database/customer-connect.php';

try {
    $checkStmt = $database_connection->prepare(
        "SELECT p.product_id, p.is_customizable
         FROM product p
         WHERE p.product_id = :product_id AND p.is_active = 1"
    );
    $checkStmt->execute([':product_id' => $productId]);
    if (!$checkStmt->fetch()) {
        header('Location: menu.php');
        exit;
    }

    // ===== FETCH PRODUCT =====
    $stmt = $database_connection->prepare(
        "SELECT
            p.product_id,
            p.name AS product_name,
            p.description,
            p.price,
            p.stock,
            p.is_active,
            p.restaurant_branch_id,
            p.is_customizable,
            p.customization_type,
            COALESCE(NULLIF(p.base_price, 0), p.price, 0) AS base_price,
            rb.branch_name,
            rb.barangay,
            rb.city,
            rb.province,
            r.business_name AS restaurant_name,
            r.cuisine_type,
            COALESCE(di.dietary_tags, '') AS dietary_tags,
            COALESCE(di.allergens, '') AS allergens,
            di.calories,
            di.protein,
            di.carbs,
            di.fat,
            COALESCE(di.images, '') AS product_image
        FROM product p
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE p.product_id = :product_id AND p.is_active = 1"
    );
    $stmt->execute([':product_id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        header('Location: menu.php');
        exit;
    }

    // ===== FETCH CUSTOMIZATION COMPONENTS =====
    $components = [];
    $hasCustomizations = false;

    if ((bool)($product['is_customizable'] ?? false)) {
        $compStmt = $database_connection->prepare(
            "SELECT
                pc.composition_id,
                pc.product_id,
                pc.ingredient_id,
                pc.is_default,
                pc.default_quantity,
                pc.max_quantity,
                pc.price_modifier,
                pc.display_order,
                pc.is_required,
                pc.min_quantity,
                pc.max_quantity_per_item,
                i.name AS ingredient_name,
                i.unit_price,
                i.calories AS ingredient_calories,
                i.dietary_tags,
                i.allergens,
                i.is_active
            FROM product_composition pc
            JOIN ingredient i ON pc.ingredient_id = i.ingredient_id
            WHERE pc.product_id = :product_id AND i.is_active = 1
            ORDER BY pc.display_order ASC, i.name ASC"
        );
        $compStmt->execute([':product_id' => $productId]);
        $rows = $compStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            $hasCustomizations = true;

            /*
             * GROUPING LOGIC
             *
             * Every row is one ingredient belonging to a (product, display_order) group.
             * All ingredients sharing the same display_order form a single UI group.
             *
             * A group becomes one of:
             *   static   — single required ingredient, max_per_item = 1, min = 1
             *   choice   — one or more ingredients, max_per_item = 1 (radio)
             *   modifier — single ingredient, max_per_item > 1 (quantity stepper)
             *   multi    — multiple ingredients, max_per_item > 1 (checkboxes)
             */
            $byOrder = [];
            foreach ($rows as $r) {
                $order = (int)$r['display_order'];
                $byOrder[$order][] = $r;
            }

            foreach ($byOrder as $order => $groupRows) {
                $count = count($groupRows);

                $groupRequired   = false;
                $groupMaxPerItem = 1;
                $defaultCount    = 0;

                foreach ($groupRows as $r) {
                    if ((bool)$r['is_required']) {
                        $groupRequired = true;
                    }
                    $maxPer = (int)($r['max_quantity_per_item'] ?? 1);
                    if ($maxPer > $groupMaxPerItem) {
                        $groupMaxPerItem = $maxPer;
                    }
                    if ((bool)$r['is_default']) {
                        $defaultCount++;
                    }
                }

                $ingredients = [];
                foreach ($groupRows as $r) {
                    $ingredients[] = [
                        'id'               => (int)$r['ingredient_id'],
                        'name'             => $r['ingredient_name'],
                        'price_modifier'   => (float)($r['price_modifier'] ?? 0),
                        'is_default'       => (bool)$r['is_default'],
                        'calories'         => (int)($r['ingredient_calories'] ?? 0),
                        'max_quantity'     => (int)($r['max_quantity'] ?? 1),
                        'default_quantity' => (int)($r['default_quantity'] ?? 0),
                        'min_quantity'     => (int)($r['min_quantity'] ?? 0),
                    ];
                }

                if ($groupMaxPerItem === 1) {
                    if ($count === 1 && $groupRequired && (int)$ingredients[0]['min_quantity'] === 1) {
                        // Static unchangeable ingredient
                        $components[] = [
                            'kind'          => 'static',
                            'label'         => ucwords(str_replace('_', ' ', $ingredients[0]['name'])),
                            'display_order' => $order,
                            'ingredient'    => $ingredients[0],
                        ];
                    } else {
                        // Radio choice
                        usort($ingredients, function ($a, $b) {
                            if ($a['is_default'] !== $b['is_default']) {
                                return $a['is_default'] ? -1 : 1;
                            }
                            return strcmp($a['name'], $b['name']);
                        });
                        $components[] = [
                            'kind'          => 'choice',
                            'label'         => ucwords(str_replace('_', ' ', $ingredients[0]['name']))
                                                . ($count > 1 ? ' Choice' : ''),
                            'display_order' => $order,
                            'is_required'   => $groupRequired,
                            'has_default'   => $defaultCount > 0,
                            'ingredients'   => $ingredients,
                        ];
                    }
                } elseif ($count === 1) {
                    // Modifier with quantity stepper
                    $components[] = [
                        'kind'          => 'modifier',
                        'label'         => ucwords(str_replace('_', ' ', $ingredients[0]['name'])),
                        'display_order' => $order,
                        'is_required'   => $groupRequired,
                        'ingredient'    => $ingredients[0],
                    ];
                } else {
                    // Multi-select checkbox list
                    usort($ingredients, function ($a, $b) {
                        if ($a['is_default'] !== $b['is_default']) {
                            return $a['is_default'] ? -1 : 1;
                        }
                        return strcmp($a['name'], $b['name']);
                    });
                    $components[] = [
                        'kind'          => 'multi',
                        'label'         => ucwords(str_replace('_', ' ', $ingredients[0]['name'])) . ' (multiple)',
                        'display_order' => $order,
                        'is_required'   => $groupRequired,
                        'max_selections'=> $groupMaxPerItem,
                        'ingredients'   => $ingredients,
                    ];
                }
            }

            usort($components, fn($a, $b) => $a['display_order'] <=> $b['display_order']);
        }
    }

    // ===== RELATED PRODUCTS =====
    $relatedStmt = $database_connection->prepare(
        "SELECT
            p.product_id,
            p.name AS product_name,
            p.price,
            p.stock,
            COALESCE(di.dietary_tags, '') AS dietary_tags,
            di.calories,
            COALESCE(di.images, '') AS product_image
        FROM product p
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE p.restaurant_branch_id = :branch_id
        AND p.product_id != :product_id
        AND p.is_active = 1
        LIMIT 4"
    );
    $relatedStmt->execute([
        ':branch_id' => $product['restaurant_branch_id'],
        ':product_id' => $productId,
    ]);
    $relatedProducts = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Product detail error: ' . $e->getMessage());
    header('Location: menu.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/product-queries.php';
require_once __DIR__ . '/../backend/database/order-queries.php';

$isLoggedIn = isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$dietaryTags = $product['dietary_tags'] !== '' ? explode(',', $product['dietary_tags']) : [];
$allergens   = $product['allergens']    !== '' ? explode(',', $product['allergens'])    : [];

$basePrice    = (float)$product['base_price'];
$inStock      = (int)$product['stock'] > 0 && (int)$product['is_active'] === 1;

$productImage = $product['product_image'] !== ''
    ? htmlspecialchars($product['product_image'], ENT_QUOTES, 'UTF-8')
    : $assetBase . 'assets/images/icons/restaurant.svg';

/*
 * ============================================================
 * DERIVE BASE CALORIES FROM COMPOSITION DEFAULTS
 *
 * For each component, sum the calories that its *default* state
 * contributes. This is the number the customize step starts at.
 * For non-customizable products, fall back to the stored value.
 * ============================================================
 */
$baseCalories = 0;

foreach ($components as $component) {
    switch ($component['kind']) {
        case 'static':
            $ing = $component['ingredient'];
            $qty = max((int)$ing['default_quantity'], (int)$ing['min_quantity'], 1);
            $baseCalories += $ing['calories'] * $qty;
            break;

        case 'choice':
            foreach ($component['ingredients'] as $ing) {
                if ($ing['is_default']) {
                    $baseCalories += $ing['calories'];
                    break;
                }
            }
            break;

        case 'modifier':
            $ing = $component['ingredient'];
            $qty = max((int)$ing['default_quantity'], (int)$ing['min_quantity']);
            $baseCalories += $ing['calories'] * $qty;
            break;

        case 'multi':
            foreach ($component['ingredients'] as $ing) {
                if ($ing['is_default']) {
                    $qty = max((int)$ing['default_quantity'], (int)$ing['min_quantity']);
                    $baseCalories += $ing['calories'] * $qty;
                }
            }
            break;
    }
}

if (!$hasCustomizations) {
    $baseCalories = (int)$product['calories'];
}

$formattedPrice = '₱' . number_format($basePrice, 2);
?>

<link rel="stylesheet" href="../assets/css/product-detail.css">

<div class="content product-detail-page" id="productDetailPage" data-base-price="<?php echo $basePrice; ?>"
    data-base-calories="<?php echo $baseCalories; ?>">

    <!-- ============================================
         STEP 1: MAIN VIEW
         ============================================ -->
    <div class="product-step product-step-main" id="stepMain">
        <div class="container">

            <?php if (isset($_SESSION['cart_success'])): ?>
            <div class="alert alert-success" role="alert">
                <?php echo htmlspecialchars($_SESSION['cart_success'], ENT_QUOTES, 'UTF-8'); ?>
                <?php unset($_SESSION['cart_success']); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['cart_error'])): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($_SESSION['cart_error'], ENT_QUOTES, 'UTF-8'); ?>
                <?php unset($_SESSION['cart_error']); ?>
            </div>
            <?php endif; ?>

            <div class="back-nav">
                <a href="menu.php<?php echo isset($_GET['restaurant_id']) ? '?restaurant_id=' . (int)$_GET['restaurant_id'] : ''; ?>"
                    class="back-btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 18 9 12 15 6" />
                    </svg>
                    <span>Back to Menu</span>
                </a>
            </div>

            <div class="product-detail-card">
                <div class="product-image-wrapper">
                    <div class="product-image-container">
                        <img src="<?php echo $productImage; ?>"
                            alt="<?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                            class="product-image"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
                        <?php if (!$inStock): ?>
                        <div class="product-badge out-of-stock-badge">Out of Stock</div>
                        <?php elseif ((int)$product['stock'] < 10): ?>
                        <div class="product-badge low-stock-badge">
                            Only <?php echo (int)$product['stock']; ?> left
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="product-info-section">
                    <div class="product-header">
                        <h1 class="product-title">
                            <?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </h1>
                        <div class="product-restaurant">
                            <a href="menu.php?restaurant_id=<?php echo (int)$product['restaurant_branch_id']; ?>">
                                <?php echo htmlspecialchars($product['restaurant_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <span class="separator">&bull;</span>
                            <span><?php echo htmlspecialchars($product['branch_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>

                    <div class="product-price-section">
                        <span class="product-price-large" id="productBasePrice"><?php echo $formattedPrice; ?></span>
                        <?php if ($hasCustomizations): ?>
                        <span class="price-note">Base price</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($product['description'] !== ''): ?>
                    <div class="product-description">
                        <h3 class="section-label">Description</h3>
                        <p><?php echo nl2br(htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8')); ?></p>
                    </div>
                    <?php endif; ?>

                    <div class="product-quick-info">
                        <?php if (!empty($dietaryTags)): ?>
                        <div class="quick-tags">
                            <?php foreach (array_slice($dietaryTags, 0, 3) as $tag): ?>
                            <span class="tag dietary-tag">
                                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tag)), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php endforeach; ?>
                            <?php if (count($dietaryTags) > 3): ?>
                            <span class="tag tag-more">+<?php echo count($dietaryTags) - 3; ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($baseCalories > 0): ?>
                        <span class="calories-badge" id="mainCaloriesBadge"><?php echo $baseCalories; ?> kcal</span>
                        <?php endif; ?>
                    </div>

                    <div class="action-control" id="actionControl">
                        <?php if ($isLoggedIn && $inStock): ?>
                        <form method="POST" action="../backend/handlers/add-to-cart-handler.php"
                            class="action-control-form" id="actionControlForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="product_id" value="<?php echo $productId; ?>">
                            <input type="hidden" name="redirect" value="menu.php">
                            <input type="hidden" name="customizations" id="customizationsData" value="">
                            <input type="hidden" name="total_price" id="totalPriceInput"
                                value="<?php echo $basePrice; ?>">
                            <input type="hidden" name="total_calories" id="totalCaloriesInput"
                                value="<?php echo $baseCalories; ?>">

                            <div class="action-row action-row-a">
                                <div class="action-col action-col-qty">
                                    <div class="quantity-control">
                                        <button type="button" class="qty-btn qty-minus" aria-label="Decrease quantity">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2">
                                                <line x1="5" y1="12" x2="19" y2="12" />
                                            </svg>
                                        </button>
                                        <input type="number" name="quantity" id="productQuantity" value="1" min="1"
                                            max="<?php echo (int)$product['stock']; ?>" class="qty-input">
                                        <button type="button" class="qty-btn qty-plus" aria-label="Increase quantity">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2">
                                                <line x1="12" y1="5" x2="12" y2="19" />
                                                <line x1="5" y1="12" x2="19" y2="12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                                <div class="action-col action-col-total">
                                    <div class="action-total">
                                        <span class="action-total-label">Total:</span>
                                        <span class="action-total-price"
                                            id="mainTotalPrice"><?php echo $formattedPrice; ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="action-row action-row-b">
                                <?php if ($hasCustomizations): ?>
                                <button type="button" class="action-btn customize-btn" id="customizeBtn">
                                    <span>Customize</span>
                                </button>
                                <?php endif; ?>
                                <button type="submit" class="action-btn add-to-cart-btn" id="addToCartBtn">
                                    <span>Add to Cart</span>
                                </button>
                                <button type="submit" class="action-btn add-to-order-btn" id="addToOrderBtn">
                                    <span>Add to Order</span>
                                </button>
                            </div>
                        </form>
                        <?php elseif (!$isLoggedIn): ?>
                        <div class="action-row action-control-login">
                            <a href="sign-in.php" class="action-btn btn-primary">
                                <span>Login to Order</span>
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="action-row action-control-disabled">
                            <button class="action-btn btn-disabled" disabled>
                                <span>Out of Stock</span>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($relatedProducts)): ?>
            <section class="related-products">
                <h2 class="related-title">You might also like</h2>
                <div class="related-grid">
                    <?php foreach ($relatedProducts as $related): ?>
                    <a href="product-detail.php?id=<?php echo (int)$related['product_id']; ?>" class="related-card">
                        <div class="related-image">
                            <img src="<?php echo $related['product_image'] !== ''
                                ? htmlspecialchars($related['product_image'], ENT_QUOTES, 'UTF-8')
                                : $assetBase . 'assets/images/icons/restaurant.svg'; ?>"
                                alt="<?php echo htmlspecialchars($related['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                loading="lazy"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
                        </div>
                        <div class="related-info">
                            <p class="related-name">
                                <?php echo htmlspecialchars($related['product_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <p class="related-price">₱<?php echo number_format((float)$related['price'], 2); ?></p>
                            <?php if ($related['calories'] !== null): ?>
                            <p class="related-calories"><?php echo (int)$related['calories']; ?> kcal</p>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================
         STEP 2: CUSTOMIZATION VIEW
         ============================================ -->
    <?php if ($hasCustomizations && $isLoggedIn && $inStock): ?>
    <div class="product-step product-step-customize" id="stepCustomize" style="display:none;">
        <div class="container">
            <div class="back-nav">
                <button type="button" class="back-btn" id="backToMainBtn">
                    <span>Back to Product</span>
                </button>
                <span class="customize-step-title">Customize Your Order</span>
            </div>

            <div class="customization-card">
                <div class="customization-product-summary">
                    <div class="customization-product-image">
                        <img src="<?php echo $productImage; ?>"
                            alt="<?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
                    </div>
                    <div class="customization-product-info">
                        <p class="customization-product-name">
                            <?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="customization-product-price"><?php echo $formattedPrice; ?></p>
                    </div>
                </div>

                <div class="customization-options-container" id="customizationContainer">
                    <h3 class="section-label">Customize Your Order</h3>

                    <?php foreach ($components as $index => $component):
                        $kind       = $component['kind'];
                        $label      = $component['label'];
                        $isRequired = $component['is_required'] ?? false;
                    ?>
                    <div class="customization-group" data-component-id="<?php echo $index; ?>"
                        data-component-kind="<?php echo $kind; ?>"
                        data-required="<?php echo $isRequired ? 'true' : 'false'; ?>">

                        <div class="customization-header">
                            <span
                                class="customization-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="badge-wrapper">
                                <?php if ($kind === 'static'): ?>
                                <span class="badge unchangeable-badge">Included</span>
                                <?php elseif ($kind === 'choice'): ?>
                                <span class="badge <?php echo $isRequired ? 'required-badge' : 'optional-badge'; ?>">
                                    <?php echo $isRequired ? 'Choose one' : 'Optional &ndash; Pick one'; ?>
                                </span>
                                <?php elseif ($kind === 'modifier'): ?>
                                <span class="badge <?php echo $isRequired ? 'required-badge' : 'optional-badge'; ?>">
                                    <?php echo $isRequired ? 'Required' : 'Add or remove'; ?>
                                </span>
                                <?php elseif ($kind === 'multi'): ?>
                                <span class="badge optional-badge">Pick any</span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="customization-options">

                            <?php if ($kind === 'static'):
                                $ing = $component['ingredient'];
                                $qty = max((int)$ing['default_quantity'], (int)$ing['min_quantity'], 1);
                            ?>
                            <div class="static-ingredient" data-ingredient-id="<?php echo $ing['id']; ?>">
                                <span
                                    class="static-name"><?php echo htmlspecialchars($ing['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="static-calories"><?php echo $ing['calories'] * $qty; ?> kcal</span>
                                <input type="hidden" name="customization_<?php echo $index; ?>"
                                    value="<?php echo $ing['id']; ?>" data-is-default="1"
                                    data-price-modifier="<?php echo $ing['price_modifier']; ?>"
                                    data-calories="<?php echo $ing['calories']; ?>"
                                    data-default-quantity="<?php echo $qty; ?>">
                            </div>

                            <?php elseif ($kind === 'choice'): ?>
                            <fieldset class="customization-radio-group">
                                <legend class="sr-only"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </legend>

                                <?php if (!$isRequired): ?>
                                <label class="radio-option<?php echo !$component['has_default'] ? ' selected' : ''; ?>">
                                    <input type="radio" name="customization_<?php echo $index; ?>" value=""
                                        data-is-default="0" data-price-modifier="0" data-calories="0"
                                        <?php echo !$component['has_default'] ? 'checked' : ''; ?>>
                                    <span class="option-name">None</span>
                                    <span class="option-calories">0 kcal</span>
                                </label>
                                <?php endif; ?>

                                <?php foreach ($component['ingredients'] as $ing):
                                    $priceMod  = (float)$ing['price_modifier'];
                                    $calories  = (int)$ing['calories'];
                                    $isDefault = (bool)$ing['is_default'];
                                ?>
                                <label class="radio-option<?php echo $isDefault ? ' selected' : ''; ?>">
                                    <input type="radio" name="customization_<?php echo $index; ?>"
                                        value="<?php echo $ing['id']; ?>"
                                        data-is-default="<?php echo $isDefault ? '1' : '0'; ?>"
                                        data-price-modifier="<?php echo $priceMod; ?>"
                                        data-calories="<?php echo $calories; ?>"
                                        <?php echo $isDefault ? 'checked' : ''; ?>>
                                    <span
                                        class="option-name"><?php echo htmlspecialchars($ing['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if ($priceMod > 0): ?>
                                    <span class="option-price">+₱<?php echo number_format($priceMod, 2); ?></span>
                                    <?php elseif ($priceMod < 0): ?>
                                    <span class="option-price">-₱<?php echo number_format(abs($priceMod), 2); ?></span>
                                    <?php endif; ?>
                                    <span class="option-calories"><?php echo $calories; ?> kcal</span>
                                </label>
                                <?php endforeach; ?>
                            </fieldset>

                            <?php elseif ($kind === 'modifier'):
                                $ing        = $component['ingredient'];
                                $minQty     = (int)$ing['min_quantity'];
                                $maxQty     = (int)$ing['max_quantity'];
                                $defQty     = (int)$ing['default_quantity'];

                                // CHANGED: startQty is the value PHP puts in the DOM and the
                                // value JS reads back from data-default-quantity. Clamped
                                // into [minQty, maxQty] so the stepper always reflects what
                                // the user can actually do.
                                $startQty = $defQty;
                                if ($startQty < $minQty) $startQty = $minQty;
                                if ($startQty > $maxQty) $startQty = $maxQty;

                                $isSelected = $startQty > 0;
                            ?>
                            <div class="customization-option modifier-option<?php echo $isSelected ? ' selected' : ''; ?>"
                                data-ingredient-id="<?php echo $ing['id']; ?>"
                                data-price-modifier="<?php echo $ing['price_modifier']; ?>"
                                data-calories="<?php echo $ing['calories']; ?>" data-min-qty="<?php echo $minQty; ?>"
                                data-max-qty="<?php echo $maxQty; ?>" data-default-quantity="<?php echo $startQty; ?>">
                                <!-- CHANGED: attribute added -->
                                <div class="modifier-info">
                                    <span
                                        class="option-name"><?php echo htmlspecialchars($ing['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if ($ing['price_modifier'] > 0): ?>
                                    <span
                                        class="option-price">+₱<?php echo number_format($ing['price_modifier'], 2); ?></span>
                                    <?php elseif ($ing['price_modifier'] < 0): ?>
                                    <span
                                        class="option-price">-₱<?php echo number_format(abs($ing['price_modifier']), 2); ?></span>
                                    <?php endif; ?>
                                    <span class="option-calories"><?php echo $ing['calories']; ?> kcal</span>
                                    <?php if ($isRequired): ?>
                                    <span class="required-badge-small">Required</span>
                                    <?php endif; ?>
                                </div>
                                <div class="modifier-controls">
                                    <button type="button" class="modifier-btn modifier-minus"
                                        <?php echo $startQty <= $minQty ? 'disabled' : ''; ?>>−</button>
                                    <span class="modifier-quantity"><?php echo $startQty; ?></span>
                                    <button type="button" class="modifier-btn modifier-plus"
                                        <?php echo $startQty >= $maxQty ? 'disabled' : ''; ?>>+</button>
                                </div>
                            </div>

                            <?php elseif ($kind === 'multi'): ?>
                            <?php foreach ($component['ingredients'] as $ing):
                                $isDefault = (bool)$ing['is_default'];
                            ?>
                            <label
                                class="customization-option checkbox-option<?php echo $isDefault ? ' selected' : ''; ?>">
                                <input type="checkbox" name="customization_<?php echo $index; ?>[]"
                                    value="<?php echo $ing['id']; ?>"
                                    data-is-default="<?php echo $isDefault ? '1' : '0'; ?>"
                                    data-price-modifier="<?php echo $ing['price_modifier']; ?>"
                                    data-calories="<?php echo $ing['calories']; ?>"
                                    data-default-quantity="<?php echo $isDefault ? max((int)$ing['default_quantity'], 1) : 0; ?>"
                                    <?php echo $isDefault ? 'checked' : ''; ?>>
                                <span
                                    class="option-name"><?php echo htmlspecialchars($ing['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($ing['price_modifier'] > 0): ?>
                                <span
                                    class="option-price">+₱<?php echo number_format($ing['price_modifier'], 2); ?></span>
                                <?php elseif ($ing['price_modifier'] < 0): ?>
                                <span
                                    class="option-price">-₱<?php echo number_format(abs($ing['price_modifier']), 2); ?></span>
                                <?php endif; ?>
                                <span class="option-calories"><?php echo $ing['calories']; ?> kcal</span>
                            </label>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="customization-notes-global">
                        <label for="globalNotes" class="customization-label">Special Instructions</label>
                        <textarea id="globalNotes" class="customization-textarea"
                            placeholder="Any additional notes for this order?"></textarea>
                    </div>
                </div>

                <div class="customization-actions">
                    <div class="customization-total">
                        <div class="customization-total-item">
                            <span class="customization-total-label">Total:</span>
                            <span id="customizeTotalPrice"><?php echo $formattedPrice; ?></span>
                        </div>
                        <div class="customization-total-item">
                            <span class="customization-total-label">Calories:</span>
                            <span id="customizeTotalCalories"><?php echo $baseCalories; ?> kcal</span>
                        </div>
                    </div>
                    <div class="customization-buttons">
                        <button type="button" class="btn btn-outline" id="cancelCustomizeBtn">Cancel</button>
                        <button type="button" class="btn btn-primary" id="applyCustomizeBtn">
                            <span>Apply and Add to Order</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="../assets/ui/js/product-detail.js" defer></script>
<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>