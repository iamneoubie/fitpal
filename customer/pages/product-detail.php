<?php
/**
 * FitPal Product Detail Page
 * Version 5.9 - Removed unit_price fallback; use only price_modifier
 *
 * @package FitPal
 * @version 5.9
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

// ===== CHECK PRODUCT EXISTS =====
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

    // ===== FETCH FULL PRODUCT DATA =====
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
        $ingredientRows = $compStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($ingredientRows)) {
            $hasCustomizations = true;
            $groupedIngredients = [];

            foreach ($ingredientRows as $row) {
                $compId = $row['composition_id'];
                if (!isset($groupedIngredients[$compId])) {
                    $maxQty = (int)($row['max_quantity_per_item'] ?? 1);
                    $isRequired = (bool)($row['is_required'] ?? false);
                    $groupedIngredients[$compId] = [
                        'id' => $compId,
                        'type' => 'choice',
                        'is_required' => $isRequired,
                        'max_selections' => $maxQty,
                        'display_order' => (int)($row['display_order'] ?? 0),
                        'is_unchangeable' => false,
                        'is_modifier' => false,
                        'is_multi_choice' => false,
                        'ingredients' => []
                    ];
                }

                // Use ONLY price_modifier from product_composition – no fallback to unit_price
                $priceModifier = (float)($row['price_modifier'] ?? 0);

                $groupedIngredients[$compId]['ingredients'][] = [
                    'id' => (int)$row['ingredient_id'],
                    'name' => $row['ingredient_name'],
                    'price_modifier' => $priceModifier,
                    'is_default' => (bool)($row['is_default'] ?? false),
                    'is_active' => (bool)($row['is_active'] ?? true),
                    'max_quantity' => (int)($row['max_quantity'] ?? 1),
                    'default_quantity' => (int)($row['default_quantity'] ?? 0),
                    'min_quantity' => (int)($row['min_quantity'] ?? 0),
                    'calories' => (int)($row['ingredient_calories'] ?? 0)
                ];
            }

            // ===== POST-PROCESS: GROUP BY DISPLAY_ORDER =====
            $displayOrderGroups = [];
            foreach ($groupedIngredients as $compId => $group) {
                $order = $group['display_order'];
                if (!isset($displayOrderGroups[$order])) {
                    $displayOrderGroups[$order] = [];
                }
                $displayOrderGroups[$order][] = $compId;
            }

            $processedComponents = [];
            foreach ($displayOrderGroups as $order => $compIds) {
                // Check if this group has multiple ingredients (choice group)
                $isChoiceGroup = count($compIds) > 1;

                // Get all ingredients from all components in this group
                $allIngredients = [];
                $isRequired = false;
                $maxSelections = 1;
                $hasDefault = false;
                $groupName = '';

                foreach ($compIds as $cid) {
                    $group = $groupedIngredients[$cid];
                    if ($group['is_required']) {
                        $isRequired = true;
                    }
                    if ($group['max_selections'] > $maxSelections) {
                        $maxSelections = $group['max_selections'];
                    }
                    foreach ($group['ingredients'] as $ing) {
                        $allIngredients[] = $ing;
                        if ($ing['is_default']) {
                            $hasDefault = true;
                        }
                    }
                }

                // Generate group label
                if (!empty($allIngredients)) {
                    $firstIng = $allIngredients[0];
                    $groupName = ucwords(str_replace('_', ' ', $firstIng['name']));
                    if ($isChoiceGroup) {
                        $groupName .= ' Choice';
                    }
                }

                if ($isChoiceGroup) {
                    // CHOICE GROUP: Multiple options, pick one
                    // Find the default option's price modifier to normalize all options
                    $defaultPriceMod = 0;
                    foreach ($allIngredients as $ing) {
                        if ($ing['is_default']) {
                            $defaultPriceMod = $ing['price_modifier'];
                            break;
                        }
                    }

                    // Adjust all ingredients relative to the default
                    $adjustedIngredients = [];
                    foreach ($allIngredients as $ing) {
                        $adjustedIng = $ing;
                        $adjustedIng['price_modifier'] = $ing['price_modifier'] - $defaultPriceMod;
                        $adjustedIngredients[] = $adjustedIng;
                    }

                    $processedComponents[] = [
                        'id' => implode('_', $compIds),
                        'type' => 'choice',
                        'is_required' => $isRequired,
                        'max_selections' => 1,
                        'display_order' => $order,
                        'is_unchangeable' => false,
                        'is_modifier' => false,
                        'is_choice_group' => true,
                        'has_default' => $hasDefault,
                        'ingredients' => $adjustedIngredients,
                        'label' => $groupName
                    ];
                } else {
                    // SINGLE INGREDIENT: Check if it's an unchangeable base or a modifier
                    $singleGroup = $groupedIngredients[$compIds[0]];
                    $count = count($singleGroup['ingredients']);

                    // Get the first ingredient (there should be only one)
                    $ing = $singleGroup['ingredients'][0];
                    $minQty = $ing['min_quantity'] ?? 0;
                    $maxQtyPerItem = $singleGroup['max_selections'];
                    $isReq = $singleGroup['is_required'];

                    // Determine if unchangeable: single ingredient, required, min_quantity=1, max_quantity_per_item=1
                    if ($count === 1 && $isReq && $maxQtyPerItem === 1 && $minQty === 1) {
                        // Unchangeable base component (e.g., Seaweed)
                        $ingCopy = $singleGroup['ingredients'];
                        $ingCopy[0]['price_modifier'] = 0;

                        $processedComponents[] = [
                            'id' => $singleGroup['id'],
                            'type' => 'choice',
                            'is_required' => true,
                            'max_selections' => 1,
                            'display_order' => $order,
                            'is_unchangeable' => true,
                            'is_modifier' => false,
                            'is_choice_group' => false,
                            'has_default' => true,
                            'ingredients' => $ingCopy,
                            'label' => ucwords(str_replace('_', ' ', $ing['name']))
                        ];
                    } else {
                        // Modifier (optional add-on or with adjustable quantity)
                        $processedComponents[] = [
                            'id' => $singleGroup['id'],
                            'type' => 'modifier',
                            'is_required' => $isReq,
                            'max_selections' => $maxQtyPerItem,
                            'display_order' => $order,
                            'is_unchangeable' => false,
                            'is_modifier' => true,
                            'is_choice_group' => false,
                            'has_default' => false,
                            'ingredients' => $singleGroup['ingredients'],
                            'label' => ucwords(str_replace('_', ' ', $ing['name']))
                        ];
                    }
                }
            }

            $components = $processedComponents;
        }
    }

    // ===== FETCH RELATED PRODUCTS =====
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
        ':product_id' => $productId
    ]);
    $relatedProducts = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Product detail error: ' . $e->getMessage());
    header('Location: menu.php');
    exit;
}

// ===== INCLUDE HEADER =====
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/product-queries.php';
require_once __DIR__ . '/../backend/database/order-queries.php';

$isLoggedIn = isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$dietaryTags = !empty($product['dietary_tags']) ? explode(',', $product['dietary_tags']) : [];
$allergens = !empty($product['allergens']) ? explode(',', $product['allergens']) : [];

$basePrice = (float)($product['base_price'] ?? $product['price'] ?? 0);
$formattedPrice = '₱' . number_format($basePrice, 2);
$baseCalories = (int)($product['calories'] ?? 0);

$inStock = (int)($product['stock'] ?? 0) > 0 && (int)($product['is_active'] ?? 0) === 1;

$productImage = !empty($product['product_image'])
    ? htmlspecialchars($product['product_image'], ENT_QUOTES, 'UTF-8')
    : $assetBase . 'assets/images/icons/restaurant.svg';
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
                <svg class="alert-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                    <polyline points="22 4 12 14.01 9 11.01" />
                </svg>
                <?php echo htmlspecialchars($_SESSION['cart_success'], ENT_QUOTES, 'UTF-8'); ?>
                <?php unset($_SESSION['cart_success']); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['cart_error'])): ?>
            <div class="alert alert-danger" role="alert">
                <svg class="alert-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2">
                    <circle cx="12" cy="12" r="10" />
                    <line x1="12" y1="8" x2="12" y2="12" />
                    <line x1="12" y1="16" x2="12.01" y2="16" />
                </svg>
                <?php echo htmlspecialchars($_SESSION['cart_error'], ENT_QUOTES, 'UTF-8'); ?>
                <?php unset($_SESSION['cart_error']); ?>
            </div>
            <?php endif; ?>

            <!-- Back Button -->
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

            <!-- Product Detail Card -->
            <div class="product-detail-card">
                <div class="product-image-wrapper">
                    <div class="product-image-container">
                        <img src="<?php echo $productImage; ?>"
                            alt="<?php echo htmlspecialchars($product['product_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            class="product-image"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
                        <?php if (!$inStock): ?>
                        <div class="product-badge out-of-stock-badge">Out of Stock</div>
                        <?php elseif ((int)($product['stock'] ?? 0) < 10): ?>
                        <div class="product-badge low-stock-badge">Only <?php echo (int)($product['stock'] ?? 0); ?>
                            left</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="product-info-section">
                    <div class="product-header">
                        <h1 class="product-title">
                            <?php echo htmlspecialchars($product['product_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h1>
                        <div class="product-restaurant">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                                <polyline points="9 22 9 12 15 12 15 22" />
                            </svg>
                            <a
                                href="menu.php?restaurant_id=<?php echo (int)($product['restaurant_branch_id'] ?? 0); ?>">
                                <?php echo htmlspecialchars($product['restaurant_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <span class="separator">•</span>
                            <span><?php echo htmlspecialchars($product['branch_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>

                    <!-- Price & Calories -->
                    <div class="product-price-section">
                        <span class="product-price-large" id="productBasePrice"><?php echo $formattedPrice; ?></span>
                        <?php if ($hasCustomizations): ?>
                        <span class="price-note">Base price</span>
                        <?php endif; ?>
                    </div>

                    <!-- Description -->
                    <?php if (!empty($product['description'])): ?>
                    <div class="product-description">
                        <h3 class="section-label">Description</h3>
                        <p><?php echo nl2br(htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8')); ?></p>
                    </div>
                    <?php endif; ?>

                    <!-- Quick Info Tags -->
                    <div class="product-quick-info">
                        <?php if (!empty($dietaryTags)): ?>
                        <div class="quick-tags">
                            <?php foreach (array_slice($dietaryTags, 0, 3) as $tag): ?>
                            <span
                                class="tag dietary-tag"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tag)), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endforeach; ?>
                            <?php if (count($dietaryTags) > 3): ?>
                            <span class="tag tag-more">+<?php echo count($dietaryTags) - 3; ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ((int)($product['calories'] ?? 0) > 0): ?>
                        <span class="calories-badge"><?php echo (int)$product['calories']; ?> kcal</span>
                        <?php endif; ?>
                    </div>

                    <!-- ============================================
                         ACTION CONTROLS
                         ============================================ -->
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

                            <!-- ROW A: Quantity + Total -->
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
                                            max="<?php echo (int)($product['stock'] ?? 0); ?>" class="qty-input">
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
                                            id="mainTotalPrice">₱<?php echo number_format($basePrice, 2); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- ROW B: Buttons -->
                            <div class="action-row action-row-b">
                                <?php if ($hasCustomizations): ?>
                                <button type="button" class="action-btn customize-btn" id="customizeBtn">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2">
                                        <path d="M12 2L2 7l10 5 10-5-10-5z" />
                                        <path d="M2 17l10 5 10-5" />
                                        <path d="M2 12l10 5 10-5" />
                                    </svg>
                                    <span>Customize</span>
                                </button>
                                <?php endif; ?>
                                <button type="submit" class="action-btn add-to-cart-btn" id="addToCartBtn">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2">
                                        <circle cx="9" cy="21" r="1" />
                                        <circle cx="20" cy="21" r="1" />
                                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                                    </svg>
                                    <span>Add to Cart</span>
                                </button>
                                <button type="submit" class="action-btn add-to-order-btn" id="addToOrderBtn">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2">
                                        <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z" />
                                        <line x1="3" y1="6" x2="21" y2="6" />
                                        <path d="M16 10a4 4 0 0 1-8 0" />
                                    </svg>
                                    <span>Add to Order</span>
                                </button>
                            </div>
                        </form>
                        <?php elseif (!$isLoggedIn): ?>
                        <div class="action-row action-control-login">
                            <a href="sign-in.php" class="action-btn btn-primary">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2">
                                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                                    <polyline points="10 17 15 12 10 7" />
                                    <line x1="15" y1="12" x2="3" y2="12" />
                                </svg>
                                <span>Login to Order</span>
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="action-row action-control-disabled">
                            <button class="action-btn btn-disabled" disabled>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2">
                                    <circle cx="12" cy="12" r="10" />
                                    <line x1="12" y1="8" x2="12" y2="12" />
                                    <line x1="12" y1="16" x2="12.01" y2="16" />
                                </svg>
                                <span>Out of Stock</span>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Related Products -->
            <?php if (!empty($relatedProducts)): ?>
            <section class="related-products">
                <h2 class="related-title">You might also like</h2>
                <div class="related-grid">
                    <?php foreach ($relatedProducts as $related): ?>
                    <a href="product-detail.php?id=<?php echo (int)$related['product_id']; ?>" class="related-card">
                        <div class="related-image">
                            <?php if (!empty($related['product_image'])): ?>
                            <img src="<?php echo htmlspecialchars($related['product_image'], ENT_QUOTES, 'UTF-8'); ?>"
                                alt="<?php echo htmlspecialchars($related['product_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                loading="lazy"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
                            <?php else: ?>
                            <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt="Restaurant icon"
                                loading="lazy">
                            <?php endif; ?>
                        </div>
                        <div class="related-info">
                            <p class="related-name">
                                <?php echo htmlspecialchars($related['product_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                            <p class="related-price">₱<?php echo number_format((float)($related['price'] ?? 0), 2); ?>
                            </p>
                            <?php if (!empty($related['calories'])): ?>
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
            <!-- Back to Main -->
            <div class="back-nav">
                <button type="button" class="back-btn" id="backToMainBtn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 18 9 12 15 6" />
                    </svg>
                    <span>Back to Product</span>
                </button>
                <span class="customize-step-title">Customize Your Order</span>
            </div>

            <!-- Customization Card -->
            <div class="customization-card">
                <div class="customization-product-summary">
                    <div class="customization-product-image">
                        <img src="<?php echo $productImage; ?>"
                            alt="<?php echo htmlspecialchars($product['product_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
                    </div>
                    <div class="customization-product-info">
                        <p class="customization-product-name">
                            <?php echo htmlspecialchars($product['product_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="customization-product-price" id="customizeBasePrice"><?php echo $formattedPrice; ?>
                        </p>
                    </div>
                </div>

                <div class="customization-options-container" id="customizationContainer">
                    <h3 class="section-label">Customize Your Order</h3>
                    <?php foreach ($components as $index => $component): 
                        $componentType = $component['type'] ?? 'choice';
                        $isRequired = $component['is_required'] ?? false;
                        $maxSelections = $component['max_selections'] ?? 1;
                        $ingredients = $component['ingredients'] ?? [];
                        $isUnchangeable = $component['is_unchangeable'] ?? false;
                        $isModifier = $component['is_modifier'] ?? false;
                        $isChoiceGroup = $component['is_choice_group'] ?? false;
                        $hasDefault = $component['has_default'] ?? false;
                        $groupLabel = $component['label'] ?? 'Option';
                    ?>
                    <div class="customization-group" data-component-id="<?php echo $index; ?>"
                        data-component-type="<?php echo $componentType; ?>"
                        data-required="<?php echo $isRequired ? 'true' : 'false'; ?>"
                        data-max="<?php echo $maxSelections; ?>">

                        <div class="customization-header">
                            <span class="customization-label">
                                <?php echo htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="badge-wrapper">
                                <?php if ($componentType === 'choice'): ?>
                                <?php if ($isUnchangeable): ?>
                                <span class="badge unchangeable-badge">Unchangeable</span>
                                <?php elseif ($isChoiceGroup): ?>
                                <?php if ($isRequired): ?>
                                <span class="badge required-badge">Choose one</span>
                                <?php else: ?>
                                <span class="badge optional-badge">Optional – Pick one</span>
                                <?php endif; ?>
                                <?php elseif ($isRequired): ?>
                                <span class="badge required-badge">Choose one</span>
                                <?php else: ?>
                                <span class="badge optional-badge">Optional – Pick one</span>
                                <?php endif; ?>
                                <?php elseif ($componentType === 'modifier'): ?>
                                <?php if ($isRequired && $maxSelections > 0): ?>
                                <span class="badge required-badge">Required</span>
                                <?php else: ?>
                                <span class="badge optional-badge">Add or remove</span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="customization-options" data-component-id="<?php echo $index; ?>">
                            <?php if ($componentType === 'choice'): ?>
                            <?php if ($isUnchangeable || (!$isChoiceGroup && count($ingredients) === 1)): ?>
                            <?php $ing = $ingredients[0]; ?>
                            <div class="static-ingredient">
                                <span
                                    class="static-name"><?php echo htmlspecialchars($ing['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if (!$isUnchangeable): ?>
                                <span class="static-price">
                                    <?php if ($ing['price_modifier'] > 0): ?>
                                    +₱<?php echo number_format($ing['price_modifier'], 2); ?>
                                    <?php elseif ($ing['price_modifier'] < 0): ?>
                                    -₱<?php echo number_format(abs($ing['price_modifier']), 2); ?>
                                    <?php endif; ?>
                                </span>
                                <?php endif; ?>
                                <span class="static-calories"><?php echo $ing['calories']; ?> kcal</span>
                                <input type="hidden" name="customization_<?php echo $index; ?>"
                                    value="<?php echo $ing['id']; ?>"
                                    data-price-modifier="<?php echo $isUnchangeable ? 0 : $ing['price_modifier']; ?>"
                                    data-calories="<?php echo $ing['calories']; ?>">
                            </div>
                            <?php else: ?>
                            <fieldset class="customization-radio-group">
                                <legend class="sr-only">
                                    <?php echo htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8'); ?></legend>
                                <?php if (!$isRequired): ?>
                                <label class="radio-option">
                                    <input type="radio" name="customization_<?php echo $index; ?>" value=""
                                        data-price-modifier="0" data-calories="0"
                                        <?php echo !$hasDefault ? 'checked' : ''; ?>>
                                    <span class="option-name">None</span>
                                    <span class="option-calories">0 kcal</span>
                                </label>
                                <?php endif; ?>
                                <?php foreach ($ingredients as $ing): 
                                            $isDefault = $ing['is_default'] ?? false;
                                            $priceMod = $ing['price_modifier'] ?? 0;
                                            $calories = $ing['calories'] ?? 0;
                                            $label = $ing['name'];
                                            if ($priceMod > 0) {
                                                $label .= ' (+₱' . number_format($priceMod, 2) . ')';
                                            } elseif ($priceMod < 0) {
                                                $label .= ' (-₱' . number_format(abs($priceMod), 2) . ')';
                                            }
                                        ?>
                                <label class="radio-option <?php echo $isDefault ? 'selected' : ''; ?>">
                                    <input type="radio" name="customization_<?php echo $index; ?>"
                                        value="<?php echo $ing['id']; ?>" data-price-modifier="<?php echo $priceMod; ?>"
                                        data-calories="<?php echo $calories; ?>"
                                        <?php echo $isDefault ? 'checked' : ''; ?>>
                                    <span
                                        class="option-name"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if ($priceMod != 0): ?>
                                    <span
                                        class="option-price"><?php echo ($priceMod > 0 ? '+' : '') . '₱' . number_format(abs($priceMod), 2); ?></span>
                                    <?php endif; ?>
                                    <span class="option-calories"><?php echo $calories; ?> kcal</span>
                                </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <?php endif; ?>
                            <?php elseif ($componentType === 'modifier'): ?>
                            <?php foreach ($ingredients as $ing): 
                                    $isDefault = $ing['is_default'] ?? false;
                                    $defaultQty = $ing['default_quantity'] ?? 0;
                                    $priceMod = $ing['price_modifier'] ?? 0;
                                    $calories = $ing['calories'] ?? 0;
                                    $minQty = $ing['min_quantity'] ?? 0;
                                    $maxQty = $ing['max_quantity'] ?? 1;
                                    $displayQty = $isDefault ? ($defaultQty > 0 ? $defaultQty : $minQty) : $minQty;
                                    $isRequired = ($minQty > 0);
                                ?>
                            <div class="customization-option modifier-option <?php echo $displayQty > 0 ? 'selected' : ''; ?>"
                                data-ingredient-id="<?php echo $ing['id']; ?>"
                                data-price-modifier="<?php echo $priceMod; ?>" data-calories="<?php echo $calories; ?>"
                                data-min-qty="<?php echo $minQty; ?>" data-max-qty="<?php echo $maxQty; ?>">
                                <div class="modifier-info">
                                    <span
                                        class="option-name"><?php echo htmlspecialchars($ing['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if ($priceMod > 0): ?>
                                    <span class="option-price">+₱<?php echo number_format($priceMod, 2); ?></span>
                                    <?php elseif ($priceMod < 0): ?>
                                    <span class="option-price">-₱<?php echo number_format(abs($priceMod), 2); ?></span>
                                    <?php endif; ?>
                                    <span class="option-calories"><?php echo $calories; ?> kcal</span>
                                    <?php if ($isRequired): ?>
                                    <span class="required-badge-small">Required</span>
                                    <?php endif; ?>
                                </div>
                                <div class="modifier-controls">
                                    <button type="button" class="modifier-btn modifier-minus"
                                        data-ingredient-id="<?php echo $ing['id']; ?>"
                                        data-min-qty="<?php echo $minQty; ?>"
                                        aria-label="Remove <?php echo htmlspecialchars($ing['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php echo $displayQty <= $minQty ? 'disabled' : ''; ?>>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <line x1="5" y1="12" x2="19" y2="12" />
                                        </svg>
                                    </button>
                                    <span class="modifier-quantity"
                                        data-ingredient-id="<?php echo $ing['id']; ?>"><?php echo $displayQty; ?></span>
                                    <button type="button" class="modifier-btn modifier-plus"
                                        data-ingredient-id="<?php echo $ing['id']; ?>"
                                        data-max-qty="<?php echo $maxQty; ?>"
                                        aria-label="Add <?php echo htmlspecialchars($ing['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php echo $displayQty >= $maxQty ? 'disabled' : ''; ?>>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <line x1="12" y1="5" x2="12" y2="19" />
                                            <line x1="5" y1="12" x2="19" y2="12" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php else: // checkbox type ?>
                            <?php foreach ($ingredients as $ing): 
                                    $isDefault = $ing['is_default'] ?? false;
                                    $priceMod = $ing['price_modifier'] ?? 0;
                                    $calories = $ing['calories'] ?? 0;
                                ?>
                            <label
                                class="customization-option checkbox-option <?php echo $isDefault ? 'selected' : ''; ?>">
                                <input type="checkbox" name="customization_<?php echo $index; ?>[]"
                                    value="<?php echo $ing['id']; ?>" data-price-modifier="<?php echo $priceMod; ?>"
                                    data-calories="<?php echo $calories; ?>" <?php echo $isDefault ? 'checked' : ''; ?>>
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
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Special Instructions -->
                    <div class="customization-notes-global">
                        <label for="globalNotes" class="customization-label">Special Instructions</label>
                        <textarea id="globalNotes" class="customization-textarea"
                            placeholder="Any additional notes for this order? (e.g., 'extra crispy', 'light sauce')"></textarea>
                    </div>
                </div>

                <!-- Customization Action Controls - VERTICAL STACK -->
                <div class="customization-actions">
                    <div class="customization-total">
                        <div class="customization-total-item">
                            <span class="customization-total-label">Total:</span>
                            <span id="customizeTotalPrice">₱<?php echo number_format($basePrice, 2); ?></span>
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