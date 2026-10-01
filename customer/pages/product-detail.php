<?php
/**
 * FitPal Product Detail Page
 *
 * Single-product view with an optional customization step. The
 * customer can add the product to their cart (persistent staging) or
 * to their session order queue (checkout staging). Both paths feed
 * the same order-placement flow.
 *
 * ---------------------------------------------------------------------
 * CUSTOMER REVIEWS SECTION
 * ---------------------------------------------------------------------
 * Below the "You might also like" grid, the page renders up to 50
 * reviews as a single-column list, shows the first 5, and reveals
 * the rest five at a time when the customer clicks Load More. The
 * remaining reviews are embedded as JSON on the wrapper's
 * data-reviews-more attribute; product-detail.js reads that payload
 * and appends cards. No HTTP requests are made after the initial
 * page load.
 *
 * Each review card carries:
 *
 *   - the reviewer's avatar circle, either their profile picture or
 *     the first letter of their first name on a primary-green
 *     background, matching the header's .user-profile-circle
 *   - their display name
 *   - the date they submitted the review
 *   - their star score
 *   - their comment text for THIS product, when they wrote one
 *
 * The section is omitted entirely when there are no reviews.
 *
 * ---------------------------------------------------------------------
 * AVATAR URL CONSTRUCTION
 * ---------------------------------------------------------------------
 * The stored profile_picture column holds a project-root-relative
 * path like "shared/uploads/customer/profiles/12/07_18_2026_0.jpg".
 * The page builds the browser URL by trimming the trailing "shared/"
 * from $assetBase and appending the stored path. When the stored
 * path is empty, the page renders the first letter of the reviewer's
 * first name inside the avatar circle.
 *
 * @package FitPal
 * @version 13.0 — Reviews section now: single-column, first 5
 *                 visible, Load More reveals 5 at a time. The full
 *                 remaining set is embedded on the list wrapper's
 *                 data-reviews-more attribute.
 *
 *                 (12.0: decoded comment + avatar with fallback
 *                 initial. 11.0: reviews section. 10.0: handler
 *                 targets verified. 9.4: money-flow docblock.)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

$productId = isset($_GET['id']) ? max(1, (int)$_GET['id']) : 0;
if ($productId <= 0) {
    header('Location: menu.php');
    exit;
}

require_once __DIR__ . '/../backend/database/customer-connect.php';
require_once __DIR__ . '/../backend/database/product-queries.php';

$product = getProductById($database_connection, $productId);
if (!$product) {
    header('Location: menu.php');
    exit;
}

$components        = [];
$hasCustomizations = false;

if ((bool)($product['is_customizable'] ?? false)) {
    $components = getProductComponentsGrouped($database_connection, $productId);
    $hasCustomizations = !empty($components);
}

$relatedProducts = getRelatedProducts(
    $database_connection,
    $productId,
    (int)$product['restaurant_branch_id'],
    4
);

// Read up to 50 reviews, split into the first page (5) and the
// remainder (the rest) for client-side Load More.
$allReviews = getProductReviews($database_connection, $productId, 50);

$reviewsPerPage = 5;
$reviewsFirst   = array_slice($allReviews, 0, $reviewsPerPage);
$reviewsMore    = array_slice($allReviews, $reviewsPerPage);

require_once __DIR__ . '/../includes/header.php';

// $csrfToken and $assetBase are provided by header.php.
// $assetBase always ends with 'shared/'.

$isLoggedIn = isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id']);

$dietaryTags = $product['dietary_tags'] !== '' ? explode(',', $product['dietary_tags']) : [];
$allergens   = $product['allergens']    !== '' ? explode(',', $product['allergens'])    : [];

$basePrice = (float)$product['base_price'];
$inStock   = (int)$product['stock'] > 0 && (int)$product['is_active'] === 1;

$productImage = $product['product_image'] !== ''
    ? htmlspecialchars($product['product_image'], ENT_QUOTES, 'UTF-8')
    : $assetBase . 'assets/images/icons/restaurant.svg';

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

if (!$hasCustomizations || $baseCalories === 0) {
    $baseCalories = (int)$product['calories'];
}

$formattedPrice = '₱' . number_format($basePrice, 2);

/**
 * Build a browser-loadable URL for a profile picture path stored in
 * customer_profile.profile_picture.
 *
 * Returns '' when the stored path is empty.
 */
function buildReviewAvatarUrl(string $storedPath, string $assetBase): string
{
    if ($storedPath === '' || $assetBase === '') {
        return '';
    }

    $projectRootUrl = preg_replace('#shared/$#', '', $assetBase);
    if (!is_string($projectRootUrl)) {
        return '';
    }

    return $projectRootUrl . $storedPath;
}

/**
 * Resolve the initial letter to draw inside an avatar circle.
 */
function buildReviewInitial(string $firstName, string $lastName): string
{
    if ($firstName !== '') {
        return strtoupper(substr($firstName, 0, 1));
    }
    if ($lastName !== '') {
        return strtoupper(substr($lastName, 0, 1));
    }
    return 'U';
}

/**
 * Render a single review card.
 *
 * Declared as a function so the first-page render and the
 * serialized "more" payload render use exactly the same markup. A
 * change to the card shape is a change to one function.
 *
 * @param array<string, mixed> $review
 * @param string $assetBase
 */
function renderReviewCard(array $review, string $assetBase): string
{
    $firstName = (string)($review['first_name'] ?? '');
    $lastName  = (string)($review['last_name']  ?? '');

    $displayName = trim($firstName . ' ' . $lastName);
    if ($displayName === '') {
        $displayName = 'Customer';
    }

    $initial = buildReviewInitial($firstName, $lastName);

    $avatarUrl = buildReviewAvatarUrl(
        (string)($review['profile_picture'] ?? ''),
        $assetBase
    );

    $score   = (int)($review['score'] ?? 0);
    $comment = (string)($review['comment'] ?? '');

    $dateRaw = (string)($review['date_posted'] ?? '');
    $dateTs  = strtotime($dateRaw);
    $dateOut = $dateTs !== false ? date('M d, Y', $dateTs) : $dateRaw;

    $avatarHtml = $avatarUrl !== ''
        ? '<img src="' . htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') . '" alt="" class="review-avatar-image" '
          . 'onerror="this.onerror=null; this.style.display=\'none\'; if (this.nextElementSibling) { this.nextElementSibling.style.display=\'flex\'; }">'
          . '<span class="review-avatar-initial" style="display: none;">'
          . htmlspecialchars($initial, ENT_QUOTES, 'UTF-8')
          . '</span>'
        : '<span class="review-avatar-initial">'
          . htmlspecialchars($initial, ENT_QUOTES, 'UTF-8')
          . '</span>';

    $starsHtml = '';
    for ($i = 1; $i <= 5; $i++) {
        $starsHtml .= '<span class="star ' . ($i <= $score ? 'filled' : '') . '">★</span>';
    }

    $commentHtml = $comment !== ''
        ? '<p class="review-comment">' . nl2br(htmlspecialchars($comment, ENT_QUOTES, 'UTF-8')) . '</p>'
        : '';

    return
        '<article class="review-item">'
        . '<div class="review-avatar" aria-hidden="true">' . $avatarHtml . '</div>'
        . '<div class="review-body">'
        . '<header class="review-header">'
        . '<span class="review-author">' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<span class="review-date">' . htmlspecialchars($dateOut, ENT_QUOTES, 'UTF-8') . '</span>'
        . '</header>'
        . '<div class="review-rating" aria-label="' . $score . ' out of 5 stars">' . $starsHtml . '</div>'
        . $commentHtml
        . '</div>'
        . '</article>';
}

// Serialize the "more" set as JSON for the client. Each entry
// carries only the fields the JS needs to build a card.
$reviewsMoreJson = json_encode(
    array_map(
        static function (array $r): array {
            return [
                'comment'         => (string)($r['comment']         ?? ''),
                'date_posted'     => (string)($r['date_posted']     ?? ''),
                'score'           => (int)   ($r['score']           ?? 0),
                'first_name'      => (string)($r['first_name']      ?? ''),
                'last_name'       => (string)($r['last_name']       ?? ''),
                'profile_picture' => (string)($r['profile_picture'] ?? ''),
            ];
        },
        $reviewsMore
    ),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

if ($reviewsMoreJson === false) {
    $reviewsMoreJson = '[]';
}
?>

<link rel="stylesheet" href="../assets/css/product-detail.css">

<div class="content product-detail-page" id="productDetailPage"
    data-base-price="<?php echo htmlspecialchars((string)$basePrice, ENT_QUOTES, 'UTF-8'); ?>"
    data-base-calories="<?php echo htmlspecialchars((string)$baseCalories, ENT_QUOTES, 'UTF-8'); ?>">

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
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="Back"
                        class="back-btn-icon" width="20" height="20">
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
                        <form method="POST" action="../backend/handlers/cart-handler.php" class="action-control-form"
                            id="actionControlForm" data-cart-url="../backend/handlers/cart-handler.php"
                            data-queue-url="../backend/handlers/queue-handler.php">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="product_id" value="<?php echo $productId; ?>">
                            <input type="hidden" name="customizations" id="customizationsData" value="">
                            <input type="hidden" name="total_price" id="totalPriceInput"
                                value="<?php echo $basePrice; ?>">
                            <input type="hidden" name="total_calories" id="totalCaloriesInput"
                                value="<?php echo $baseCalories; ?>">
                            <input type="hidden" name="action" id="queueActionInput" value="add">

                            <div class="action-row action-row-a">
                                <div class="action-col action-col-qty">
                                    <div class="quantity-control">
                                        <button type="button" class="qty-btn qty-minus" aria-label="Decrease quantity">
                                            <img src="<?php echo $assetBase; ?>assets/images/icons/subtract-line.svg"
                                                alt="" class="qty-btn-icon" width="18" height="18">
                                        </button>
                                        <input type="number" name="quantity" id="productQuantity" value="1" min="1"
                                            max="<?php echo (int)$product['stock']; ?>" class="qty-input">
                                        <button type="button" class="qty-btn qty-plus" aria-label="Increase quantity">
                                            <img src="<?php echo $assetBase; ?>assets/images/icons/add-line.svg" alt=""
                                                class="qty-btn-icon" width="18" height="18">
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
                                <button type="button" class="action-btn add-to-cart-btn" id="addToCartBtn">
                                    <span>Add to Cart</span>
                                </button>
                                <button type="button" class="action-btn add-to-order-btn" id="addToOrderBtn">
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

            <?php if (!empty($allReviews)): ?>
            <section class="product-reviews-section" aria-label="Customer reviews"
                data-reviews-per-page="<?php echo $reviewsPerPage; ?>">
                <h2 class="reviews-title">Customer Reviews</h2>

                <div class="reviews-list" id="reviewsList"
                    data-reviews-more="<?php echo htmlspecialchars($reviewsMoreJson, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php foreach ($reviewsFirst as $review): ?>
                    <?php echo renderReviewCard($review, $assetBase); ?>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($reviewsMore)): ?>
                <div class="reviews-load-more-wrap">
                    <button type="button" class="btn btn-neutral reviews-load-more" id="reviewsLoadMoreBtn">
                        Load More Reviews
                    </button>
                </div>
                <?php endif; ?>
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
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="Back"
                        class="back-btn-icon" width="20" height="20">
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
                                        <?php echo $startQty <= $minQty ? 'disabled' : ''; ?>>&minus;</button>
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
                        <button type="button" class="btn btn-outline" id="cancelCustomizeBtn">
                            <span>Cancel</span>
                        </button>
                        <button type="button" class="btn btn-dark" id="customizeAddToCartBtn">
                            <span>Add to Cart</span>
                        </button>
                        <button type="button" class="btn btn-primary" id="applyCustomizeBtn" data-queue-action="queue">
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