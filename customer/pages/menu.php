<?php
/**
 * FitPal Customer Menu Page
 *
 * Displays restaurants and their menu items with dietary filtering and pagination.
 *
 * The queue panel is the session order_queue staging surface. The
 * Add to Cart button routes to cart-handler.php (persistent cart).
 * Both are parallel staging surfaces with distinct purposes:
 *   - Queue (session)  → checkout immediately
 *   - Cart (persistent) → save for later
 *
 * @package FitPal
 * @version 9.4 — CSRF token now inherited from header.php; local
 *                generation removed. (9.3: removed order tracker;
 *                filter bar fixed under header.)
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/header.php';

require_once __DIR__ . '/../backend/database/product-queries.php';
require_once __DIR__ . '/../backend/database/customer-queries.php';

// ============================================
// CONFIGURATION
// ============================================
$perPage = 10;

$page              = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search            = isset($_GET['search']) ? trim($_GET['search']) : '';
$selectedTags      = isset($_GET['tags']) && is_array($_GET['tags']) ? array_filter($_GET['tags']) : [];
$selectedAllergens = isset($_GET['allergens']) && is_array($_GET['allergens']) ? array_filter($_GET['allergens']) : [];
$restaurantId      = isset($_GET['restaurant_id']) ? max(0, (int)$_GET['restaurant_id']) : 0;
$minPrice          = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? max(0, (float)$_GET['min_price']) : 0.0;
$maxPrice          = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? max(0, (float)$_GET['max_price']) : 0.0;

// ============================================
// LOAD USER PREFERENCES
// ============================================
$userDietaryPreferences = [];
$userAllergies          = [];
$isLoggedIn             = isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id']);

if ($isLoggedIn) {
    try {
        $prefData = getCustomerDietaryProfile($database_connection, (int)$_SESSION['customer_id']);

        if ($prefData) {
            if (!empty($prefData['dietary_preferences'])) {
                $userDietaryPreferences = array_filter(
                    array_map('trim', explode(',', $prefData['dietary_preferences'])),
                    fn($v) => $v !== '' && $v !== 'none'
                );
            }
            if (!empty($prefData['allergies'])) {
                $userAllergies = array_filter(
                    array_map('trim', explode(',', $prefData['allergies'])),
                    fn($v) => $v !== '' && $v !== 'none'
                );
            }
        }
    } catch (PDOException $e) {
        error_log('Menu preferences fetch error: ' . $e->getMessage());
    }
}

// ============================================
// AUTO-APPLY USER PREFERENCES
// ============================================
$isFilterSubmitted = isset($_GET['filter_applied']) ||
                     isset($_GET['search']) ||
                     isset($_GET['tags']) ||
                     isset($_GET['allergens']) ||
                     isset($_GET['restaurant_id']) ||
                     isset($_GET['min_price']) ||
                     isset($_GET['max_price']);

if ($isLoggedIn && !$isFilterSubmitted) {
    if (!empty($userDietaryPreferences)) {
        $selectedTags = $userDietaryPreferences;
    }
    if (!empty($userAllergies)) {
        $selectedAllergens = $userAllergies;
    }
}

// ============================================
// FETCH MENU DATA
// ============================================
$menuData = getMenuDataPaginated(
    $database_connection,
    $page,
    $perPage,
    $selectedTags,
    $search,
    $restaurantId,
    $minPrice,
    $maxPrice,
    $selectedAllergens
);
$restaurants   = $menuData['restaurants'] ?? [];
$totalProducts = $menuData['totalProducts'] ?? 0;
$totalPages    = $menuData['totalPages'] ?? 1;

// ============================================
// FILTER OPTIONS
// ============================================
$allDietaryTags = [
    'vegan', 'vegetarian', 'keto', 'high_protein', 'low_carb',
    'gluten_free', 'dairy_free', 'pescatarian', 'mediterranean', 'halal',
];

$allAllergens = [
    'nuts', 'dairy', 'eggs', 'soy', 'wheat',
    'shellfish', 'fish', 'peanuts', 'sesame',
];

$allRestaurants = getAllRestaurants($database_connection);

// ============================================
// CSRF TOKEN
// ============================================
// Provided by header.php (via includes/csrf_token.php), stored under
// the customer role's own session key 'customer_csrf_token'. The
// header is required at the very top of this file, so $csrfToken is
// already populated here.

// ============================================
// HELPERS
// ============================================

function buildQueryString(array $params = []): string {
    $base = [];
    if (isset($_GET['search']) && !isset($params['search']))         { $params['search'] = $_GET['search']; }
    if (isset($_GET['tags']) && !isset($params['tags']))             { $params['tags'] = $_GET['tags']; }
    if (isset($_GET['allergens']) && !isset($params['allergens']))   { $params['allergens'] = $_GET['allergens']; }
    if (isset($_GET['restaurant_id']) && !isset($params['restaurant_id'])) { $params['restaurant_id'] = $_GET['restaurant_id']; }
    if (isset($_GET['min_price']) && !isset($params['min_price']))   { $params['min_price'] = $_GET['min_price']; }
    if (isset($_GET['max_price']) && !isset($params['max_price']))   { $params['max_price'] = $_GET['max_price']; }
    if (isset($_GET['per_page']) && !isset($params['per_page']))     { $params['per_page'] = $_GET['per_page']; }

    foreach ($params as $key => $val) {
        if (is_array($val)) {
            foreach ($val as $v) {
                $base[] = urlencode($key) . '[]=' . urlencode((string)$v);
            }
        } else {
            $base[] = urlencode($key) . '=' . urlencode((string)$val);
        }
    }
    return $base ? '?' . implode('&', $base) : '';
}

function truncateText(string $text, int $length = 60): string {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . '...';
}

function hasActiveFilters(): bool {
    return !empty($_GET['search']) ||
           !empty($_GET['tags']) ||
           !empty($_GET['allergens']) ||
           (isset($_GET['restaurant_id']) && (int)$_GET['restaurant_id'] > 0) ||
           (isset($_GET['min_price']) && (float)$_GET['min_price'] > 0) ||
           (isset($_GET['max_price']) && (float)$_GET['max_price'] > 0);
}
?>
<link rel="stylesheet" href="../assets/css/menu.css">
<link rel="stylesheet" href="../assets/css/menu-filter.css">
<link rel="stylesheet" href="../assets/css/menu-product.css">
<link rel="stylesheet" href="../assets/css/queue-panel.css">

<div class="content menu-page">
    <div class="container">

        <!-- Queue flash messages (set by queue-handler.php on non-AJAX add) -->
        <?php if (isset($_SESSION['queue_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['queue_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['queue_success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['queue_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['queue_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['queue_error']); ?>
        </div>
        <?php endif; ?>

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

        <!-- Fixed Filter Bar -->
        <div class="menu-filters" id="menuFilters">
            <form method="GET" action="" class="filter-form" id="filterForm">
                <div class="filter-top-row">
                    <!-- Search -->
                    <div class="filter-search-group">
                        <div class="search-wrapper">
                            <input type="text" name="search" id="menuSearch"
                                placeholder="Search restaurants or dishes..."
                                value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                                class="form-control">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm search-btn" aria-label="Search">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/search-line.svg" alt="Search"
                                class="btn-icon">
                        </button>
                    </div>

                    <!-- Controls -->
                    <div class="filter-controls-group">
                        <!-- Dietary Tags Dropdown -->
                        <div class="filter-dropdown filter-dietary">
                            <button type="button" class="filter-dropdown-toggle" id="dietaryToggle"
                                aria-expanded="false" aria-haspopup="true">
                                <span class="filter-dropdown-left">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/equalizer-line.svg" alt=""
                                        class="filter-icon">
                                    <span class="filter-dropdown-label">Dietary</span>
                                </span>
                                <span
                                    class="filter-dropdown-badge <?php echo !empty($selectedTags) ? 'has-selection' : ''; ?>">
                                    <?php echo !empty($selectedTags) ? count($selectedTags) : ''; ?>
                                </span>
                            </button>
                            <div class="filter-dropdown-menu" id="dietaryDropdown" role="menu">
                                <div class="filter-dropdown-header">
                                    <span class="filter-dropdown-title">Dietary Tags</span>
                                    <button type="button" class="filter-dropdown-close"
                                        aria-label="Close dietary filters">&times;</button>
                                </div>
                                <div class="filter-dropdown-options">
                                    <?php foreach ($allDietaryTags as $tag): ?>
                                    <label class="filter-check">
                                        <input type="checkbox" name="tags[]"
                                            value="<?php echo htmlspecialchars($tag, ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php echo in_array($tag, $selectedTags) ? 'checked' : ''; ?>
                                            onchange="document.getElementById('filterForm').submit()">
                                        <span><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tag)), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Allergen Filter Dropdown (EXCLUDE) -->
                        <div class="filter-dropdown filter-allergen">
                            <button type="button" class="filter-dropdown-toggle" id="allergenToggle"
                                aria-expanded="false" aria-haspopup="true">
                                <span class="filter-dropdown-left">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/list-settings-fill.svg"
                                        alt="" class="filter-icon">
                                    <span class="filter-dropdown-label">Allergens</span>
                                </span>
                                <span
                                    class="filter-dropdown-badge <?php echo !empty($selectedAllergens) ? 'has-selection' : ''; ?>">
                                    <?php echo !empty($selectedAllergens) ? count($selectedAllergens) : ''; ?>
                                </span>
                            </button>
                            <div class="filter-dropdown-menu" id="allergenDropdown" role="menu">
                                <div class="filter-dropdown-header">
                                    <span class="filter-dropdown-title">Exclude Allergens</span>
                                    <button type="button" class="filter-dropdown-close"
                                        aria-label="Close allergen filters">&times;</button>
                                </div>
                                <div class="filter-dropdown-options">
                                    <?php foreach ($allAllergens as $allergen): ?>
                                    <label class="filter-check">
                                        <input type="checkbox" name="allergens[]"
                                            value="<?php echo htmlspecialchars($allergen, ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php echo in_array($allergen, $selectedAllergens) ? 'checked' : ''; ?>
                                            onchange="document.getElementById('filterForm').submit()">
                                        <span><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $allergen)), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Restaurant Dropdown -->
                        <div class="filter-dropdown filter-restaurant">
                            <button type="button" class="filter-dropdown-toggle" id="restaurantToggle"
                                aria-expanded="false" aria-haspopup="true">
                                <span class="filter-dropdown-left">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt=""
                                        class="filter-icon">
                                    <span class="filter-dropdown-label">
                                        <?php
                                        $selectedRestaurantName = 'Restaurant';
                                        if ($restaurantId > 0) {
                                            foreach ($allRestaurants as $rest) {
                                                if ((int)$rest['restaurant_id'] === $restaurantId) {
                                                    $selectedRestaurantName = htmlspecialchars($rest['business_name'], ENT_QUOTES, 'UTF-8');
                                                    break;
                                                }
                                            }
                                        }
                                        echo $selectedRestaurantName;
                                        ?>
                                    </span>
                                </span>
                                <span
                                    class="filter-dropdown-badge <?php echo $restaurantId > 0 ? 'has-selection' : ''; ?>">
                                    <?php echo $restaurantId > 0 ? '1' : ''; ?>
                                </span>
                            </button>
                            <div class="filter-dropdown-menu" id="restaurantDropdown" role="menu">
                                <div class="filter-dropdown-header">
                                    <span class="filter-dropdown-title">Restaurant</span>
                                    <button type="button" class="filter-dropdown-close"
                                        aria-label="Close restaurant filters">&times;</button>
                                </div>
                                <div class="filter-dropdown-options filter-restaurant-options">
                                    <label class="filter-check filter-restaurant-option">
                                        <input type="radio" name="restaurant_id" value="0"
                                            <?php echo $restaurantId === 0 ? 'checked' : ''; ?>
                                            onchange="document.getElementById('filterForm').submit()">
                                        <span>All Restaurants</span>
                                    </label>
                                    <?php foreach ($allRestaurants as $rest): ?>
                                    <label class="filter-check filter-restaurant-option">
                                        <input type="radio" name="restaurant_id"
                                            value="<?php echo (int)$rest['restaurant_id']; ?>"
                                            <?php echo $restaurantId === (int)$rest['restaurant_id'] ? 'checked' : ''; ?>
                                            onchange="document.getElementById('filterForm').submit()">
                                        <span><?php echo htmlspecialchars($rest['business_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Price Range -->
                        <div class="filter-price-group">
                            <span class="price-label">Price:</span>
                            <div class="price-range-inputs">
                                <input type="number" name="min_price" class="form-control price-input" placeholder="Min"
                                    min="0" step="1"
                                    value="<?php echo $minPrice > 0 ? htmlspecialchars((string)$minPrice, ENT_QUOTES, 'UTF-8') : ''; ?>">
                                <span class="price-range-dash">&ndash;</span>
                                <input type="number" name="max_price" class="form-control price-input" placeholder="Max"
                                    min="0" step="1"
                                    value="<?php echo $maxPrice > 0 ? htmlspecialchars((string)$maxPrice, ENT_QUOTES, 'UTF-8') : ''; ?>">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm price-apply-btn">Apply</button>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="page" value="1">
                <input type="hidden" name="filter_applied" value="1">
            </form>
        </div>

        <!-- Spacer: pushes content below the fixed filter bar -->
        <div class="filter-spacer" aria-hidden="true"></div>

        <!-- Restaurant List -->
        <section class="restaurant-list" aria-label="Restaurants and menu items">
            <?php if (empty($restaurants)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/search-line.svg" alt="No results">
                </div>
                <p class="heading-4">No products found</p>
                <p class="text-muted">
                    <?php if (!empty($search)): ?>
                    No products match "<strong><?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?></strong>"
                    <?php elseif (!empty($selectedAllergens)): ?>
                    No products match your filters after excluding selected allergens
                    <?php elseif (!empty($selectedTags)): ?>
                    No products match your dietary preferences
                    <?php elseif ($restaurantId > 0): ?>
                    No products available for this restaurant
                    <?php else: ?>
                    Try adjusting your filters or search terms.
                    <?php endif; ?>
                </p>
                <?php if (hasActiveFilters()): ?>
                <a href="menu.php?filter_applied=1" class="btn btn-outline btn-sm clear-filters-btn">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/cancel.svg" alt="" class="btn-icon"
                        width="16" height="16">
                    <span>Clear Filters</span>
                </a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <?php foreach ($restaurants as $restaurant): ?>
            <div class="restaurant-card" data-restaurant-id="<?php echo $restaurant['id']; ?>">
                <div class="restaurant-header">
                    <p class="heading-4"><?php echo htmlspecialchars($restaurant['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <?php foreach ($restaurant['branches'] as $branch): ?>
                <div class="branch-section">
                    <p class="heading-5"><?php echo htmlspecialchars($branch['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <div class="product-grid">
                        <?php foreach ($branch['products'] as $product): ?>
                        <!-- PRODUCT CARD -->
                        <div class="product-card" data-product-id="<?php echo (int)$product['id']; ?>"
                            data-product-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-product-price="<?php echo (float)$product['price']; ?>"
                            data-product-stock="<?php echo (int)$product['stock']; ?>"
                            data-product-image="<?php echo !empty($product['image']) ? htmlspecialchars($product['image'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                            data-restaurant-name="<?php echo htmlspecialchars($restaurant['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-branch-name="<?php echo htmlspecialchars($branch['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-dietary="<?php echo htmlspecialchars(implode(',', $product['dietary_tags']), ENT_QUOTES, 'UTF-8'); ?>"
                            data-allergens="<?php echo htmlspecialchars(implode(',', $product['allergens']), ENT_QUOTES, 'UTF-8'); ?>">

                            <!-- Product Image -->
                            <a href="product-detail.php?id=<?php echo (int)$product['id']; ?>"
                                class="product-image-link" onclick="event.stopPropagation();">
                                <div class="product-image">
                                    <?php if (!empty($product['image'])): ?>
                                    <img src="<?php echo htmlspecialchars($product['image'], ENT_QUOTES, 'UTF-8'); ?>"
                                        alt="<?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        loading="lazy"
                                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
                                    <?php else: ?>
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg"
                                        alt="Restaurant icon" loading="lazy">
                                    <?php endif; ?>
                                </div>
                            </a>

                            <div class="product-info">
                                <!-- Product Name -->
                                <a href="product-detail.php?id=<?php echo (int)$product['id']; ?>"
                                    class="product-name-link" onclick="event.stopPropagation();">
                                    <p class="heading-6">
                                        <?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                </a>

                                <?php if (!empty($product['description'])): ?>
                                <p class="product-description">
                                    <?php echo htmlspecialchars(truncateText($product['description'], 70), ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <?php endif; ?>

                                <div class="product-meta">
                                    <span
                                        class="product-price">&#8369;<?php echo number_format($product['price'], 2); ?></span>
                                    <?php if ($product['calories']): ?>
                                    <span class="product-calories"><?php echo (int)$product['calories']; ?> kcal</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Dietary Tags -->
                                <?php if (!empty($product['dietary_tags'])): ?>
                                <div class="product-tags-section">
                                    <span class="tags-label">Dietary Tags:</span>
                                    <div class="product-tags">
                                        <?php foreach ($product['dietary_tags'] as $tag): ?>
                                        <span
                                            class="tag dietary-tag"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tag)), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Allergens -->
                                <div class="product-allergens-section">
                                    <span class="allergen-label">Allergens:</span>
                                    <div class="product-allergens-tags">
                                        <?php if (!empty($product['allergens'])): ?>
                                        <?php foreach ($product['allergens'] as $allergen): ?>
                                        <span
                                            class="tag allergen-tag"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $allergen)), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <span class="tag-none">None</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Product Actions -->
                            <div class="product-actions">
                                <?php if ($isLoggedIn && $product['stock'] > 0): ?>
                                <div class="action-row" data-product-id="<?php echo (int)$product['id']; ?>"
                                    data-cart-url="../backend/handlers/cart-handler.php"
                                    data-queue-url="../backend/handlers/queue-handler.php">

                                    <div class="quantity-control">
                                        <button type="button" class="qty-btn qty-minus" aria-label="Decrease quantity">
                                            <img src="<?php echo $assetBase; ?>assets/images/icons/subtract-line.svg"
                                                alt="" class="qty-btn-icon" width="14" height="14">
                                        </button>
                                        <input type="number" name="quantity" value="1" min="1"
                                            max="<?php echo $product['stock']; ?>" class="qty-input">
                                        <button type="button" class="qty-btn qty-plus" aria-label="Increase quantity">
                                            <img src="<?php echo $assetBase; ?>assets/images/icons/add-line.svg" alt=""
                                                class="qty-btn-icon" width="14" height="14">
                                        </button>
                                    </div>

                                    <button type="button" class="add-btn add-to-cart-btn" data-action="cart"
                                        aria-label="Add to cart" title="Add to Cart">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/cart-shopping.svg"
                                            alt="Add to cart" class="btn-icon" width="16" height="16">
                                    </button>

                                    <button type="button" class="add-btn add-to-order-btn" data-action="queue"
                                        aria-label="Add to order" title="Add to Order">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/add-line.svg"
                                            alt="Add to order" class="btn-icon" width="16" height="16">
                                    </button>
                                </div>
                                <?php elseif (!$isLoggedIn): ?>
                                <a href="sign-in.php" class="btn btn-outline btn-sm">Login to Order</a>
                                <?php else: ?>
                                <span class="btn btn-sm btn-disabled">Out of Stock</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <!-- Pagination -->
            <?php if ($totalPages > 0): ?>
            <nav class="pagination" role="navigation" aria-label="Product pagination">
                <ul class="pagination-list">
                    <?php if ($page > 1): ?>
                    <li class="pagination-item">
                        <a href="<?php echo htmlspecialchars(buildQueryString(['page' => $page - 1]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="pagination-link pagination-prev" aria-label="Previous page">Previous</a>
                    </li>
                    <?php else: ?>
                    <li class="pagination-item">
                        <span class="pagination-link pagination-prev disabled" aria-label="Previous page"
                            aria-disabled="true">Previous</span>
                    </li>
                    <?php endif; ?>

                    <?php
                    $maxVisible = 5;
                    $startPage  = max(1, $page - floor($maxVisible / 2));
                    $endPage    = min($totalPages, $startPage + $maxVisible - 1);
                    if ($endPage - $startPage + 1 < $maxVisible) {
                        $startPage = max(1, $endPage - $maxVisible + 1);
                    }

                    if ($startPage > 1): ?>
                    <li class="pagination-item">
                        <a href="<?php echo htmlspecialchars(buildQueryString(['page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="pagination-link" aria-label="Page 1">1</a>
                    </li>
                    <?php if ($startPage > 2): ?>
                    <li class="pagination-item pagination-ellipsis"><span>...</span></li>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="pagination-item">
                        <?php if ($i === $page): ?>
                        <span class="pagination-link active" aria-current="page"
                            aria-label="Page <?php echo $i; ?>"><?php echo $i; ?></span>
                        <?php else: ?>
                        <a href="<?php echo htmlspecialchars(buildQueryString(['page' => $i]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="pagination-link" aria-label="Page <?php echo $i; ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    </li>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                    <li class="pagination-item pagination-ellipsis"><span>...</span></li>
                    <?php endif; ?>
                    <li class="pagination-item">
                        <a href="<?php echo htmlspecialchars(buildQueryString(['page' => $totalPages]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="pagination-link"
                            aria-label="Page <?php echo $totalPages; ?>"><?php echo $totalPages; ?></a>
                    </li>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                    <li class="pagination-item">
                        <a href="<?php echo htmlspecialchars(buildQueryString(['page' => $page + 1]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="pagination-link pagination-next" aria-label="Next page">Next</a>
                    </li>
                    <?php else: ?>
                    <li class="pagination-item">
                        <span class="pagination-link pagination-next disabled" aria-label="Next page"
                            aria-disabled="true">Next</span>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>

            <?php endif; ?>
        </section>
    </div>

    <!-- Queue Panel -->
    <div class="queue-panel-wrapper empty" id="queuePanelWrapper" data-queue-empty="true" aria-hidden="true">
        <div class="queue-panel" id="queuePanel">
            <div class="queue-panel-inner" id="queuePanelInner">

                <div class="queue-panel-header" id="queuePanelHeader">
                    <div class="queue-panel-title">
                        <span>Your Order</span>
                        <span class="queue-item-count" id="queueItemCount" style="display:none;">0</span>
                    </div>
                    <div class="queue-panel-summary">
                        <span class="queue-item-count-label" id="queueItemCountLabel">0 items</span>
                    </div>
                    <button type="button" class="queue-panel-toggle" id="queuePanelToggle" aria-expanded="false"
                        aria-label="Toggle order panel">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg" alt=""
                            class="queue-panel-toggle-icon" width="20" height="20">
                    </button>
                </div>

                <div class="queue-panel-body">
                    <div class="queue-items-container" id="queueItemsContainer"></div>

                    <div class="queue-empty-state" id="queueEmptyState" style="display:none;">
                        <div class="queue-empty-icon">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/cart-shopping.svg" alt="Empty cart">
                        </div>
                        <p class="queue-empty-text">Your queue is empty. Start adding items!</p>
                    </div>

                    <div class="queue-footer">
                        <div class="queue-footer-total">
                            <span class="queue-footer-label">Total:</span>
                            <span class="queue-footer-grand-total" id="queueGrandTotal">₱0.00</span>
                            <span class="queue-footer-item-count" id="queueFooterItemCount">0 items</span>
                        </div>
                        <div class="queue-footer-actions">
                            <button type="button" class="queue-btn-cancel" id="queueCancelBtn">Cancel Order</button>
                            <a href="checkout.php" class="queue-btn-checkout" id="queueCheckoutBtn" disabled>
                                <span>Checkout</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Remove Item -->
    <div class="queue-modal" id="queueRemoveModal" style="display:none;">
        <div class="queue-modal-overlay"></div>
        <div class="queue-modal-content">
            <div class="queue-modal-icon">
                <img src="<?php echo $assetBase; ?>assets/images/icons/trash.svg" alt="Remove item">
            </div>
            <div class="queue-modal-body">
                <p class="queue-modal-title-text">Remove Item</p>
                <p>Are you sure you want to remove <strong id="queueModalItemName"></strong> from your order?</p>
            </div>
            <div class="queue-modal-footer">
                <button type="button" class="queue-modal-btn-cancel" id="queueModalCancel">Cancel</button>
                <button type="button" class="queue-modal-btn-confirm" id="queueModalConfirm">Remove</button>
            </div>
        </div>
    </div>

    <!-- Modal: Cancel Entire Order -->
    <div class="queue-modal" id="queueCancelModal" style="display:none;">
        <div class="queue-modal-overlay"></div>
        <div class="queue-modal-content">
            <div class="queue-modal-icon">
                <img src="<?php echo $assetBase; ?>assets/images/icons/trash.svg" alt="Cancel order">
            </div>
            <div class="queue-modal-body">
                <p class="queue-modal-title-text">Cancel Order</p>
                <p>Are you sure you want to cancel your entire order?</p>
            </div>
            <div class="queue-modal-footer">
                <button type="button" class="queue-modal-btn-cancel" id="queueCancelModalCancel">Cancel</button>
                <button type="button" class="queue-modal-btn-confirm" id="queueCancelModalConfirm">Yes</button>
            </div>
        </div>
    </div>
</div>

<script>
window.FITPAL_ASSET_BASE = '<?php echo $assetBase; ?>';
window.FITPAL_CSRF_TOKEN = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
</script>
<script src="../assets/ui/js/menu.js" defer></script>
<script src="../assets/ui/js/queue-panel.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>