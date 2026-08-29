<?php
/**
 * FitPal Customer Menu Page
 *
 * Displays restaurants and their menu items with dietary filtering and pagination.
 * Queue panel replaces alert-based feedback with persistent cart display.
 *
 * @package FitPal
 * @version 5.3 - Cancel order with modal confirmation
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/header.php';

// Use product queries for menu and product operations
require_once __DIR__ . '/../backend/database/product-queries.php';
require_once __DIR__ . '/../backend/database/order-queries.php';

// ============================================
// CONFIGURATION - Products per page
// ============================================
$perPage = 10;

// Get filters from GET
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$selectedTags = isset($_GET['tags']) && is_array($_GET['tags']) ? array_filter($_GET['tags']) : [];
$restaurantId = isset($_GET['restaurant_id']) ? max(0, (int)$_GET['restaurant_id']) : 0;
$minPrice = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? max(0, (float)$_GET['min_price']) : 0.0;
$maxPrice = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? max(0, (float)$_GET['max_price']) : 0.0;

// Fetch menu data with pagination
$menuData = getMenuDataPaginated($database_connection, $page, $perPage, $selectedTags, $search, $restaurantId, $minPrice, $maxPrice);
$restaurants = $menuData['restaurants'] ?? [];
$totalProducts = $menuData['totalProducts'] ?? 0;
$totalPages = $menuData['totalPages'] ?? 1;

// Get all dietary tags for filter checkboxes
$allDietaryTags = getDistinctDietaryTags($database_connection);

// Get all restaurants for the restaurant switcher
$allRestaurants = getAllRestaurants($database_connection);

$isLoggedIn = isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id']);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Fetch the customer's active order for the fixed order tracker
$activeOrder = null;
if ($isLoggedIn) {
    $activeOrder = getActiveOrder($database_connection, (int)$_SESSION['customer_id']);
}

/**
 * Map an order status to how many of the 4 tracker steps are completed.
 */
function getTrackerStepIndex(string $status): int {
    return match ($status) {
        'pending' => 1,
        'confirmed' => 2,
        'preparing' => 2,
        'out_for_delivery' => 3,
        'delivered' => 4,
        default => 1,
    };
}

/**
 * Human-readable label for an order status shown in the tracker.
 */
function getTrackerStatusLabel(string $status): string {
    return match ($status) {
        'pending' => 'Order Placed',
        'confirmed' => 'Confirmed',
        'preparing' => 'Preparing',
        'out_for_delivery' => 'Out for Delivery',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

// Build query string for pagination links (preserve filters)
function buildQueryString(array $params = []): string {
    $base = [];
    if (isset($_GET['search']) && !isset($params['search'])) {
        $params['search'] = $_GET['search'];
    }
    if (isset($_GET['tags']) && !isset($params['tags'])) {
        $params['tags'] = $_GET['tags'];
    }
    if (isset($_GET['restaurant_id']) && !isset($params['restaurant_id'])) {
        $params['restaurant_id'] = $_GET['restaurant_id'];
    }
    if (isset($_GET['min_price']) && !isset($params['min_price'])) {
        $params['min_price'] = $_GET['min_price'];
    }
    if (isset($_GET['max_price']) && !isset($params['max_price'])) {
        $params['max_price'] = $_GET['max_price'];
    }
    if (isset($_GET['per_page']) && !isset($params['per_page'])) {
        $params['per_page'] = $_GET['per_page'];
    }
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

/**
 * Truncate text to a specified length with ellipsis.
 */
function truncateText(string $text, int $length = 60): string {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . '...';
}

/**
 * Check if any filters are applied
 */
function hasActiveFilters(): bool {
    return !empty($_GET['search']) || 
           !empty($_GET['tags']) || 
           (isset($_GET['restaurant_id']) && (int)$_GET['restaurant_id'] > 0) ||
           (isset($_GET['min_price']) && (float)$_GET['min_price'] > 0) ||
           (isset($_GET['max_price']) && (float)$_GET['max_price'] > 0);
}
?>
<link rel="stylesheet" href="../assets/css/menu.css">
<link rel="stylesheet" href="../assets/css/menu-filter.css">
<link rel="stylesheet" href="../assets/css/menu-product.css">
<link rel="stylesheet" href="../assets/css/queue-panel.css">

<div class="content menu-page<?php echo $activeOrder ? ' has-order-tracker' : ''; ?>">
    <div class="container">

        <!-- Sticky Filter Bar -->
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
                        <div class="filter-dropdown">
                            <button type="button" class="filter-dropdown-toggle" id="dietaryToggle"
                                aria-expanded="false" aria-haspopup="true">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/equalizer-line.svg"
                                    alt="Dietary tags" class="filter-icon">
                                <span class="filter-dropdown-label">Dietary</span>
                                <span
                                    class="filter-dropdown-badge <?php echo !empty($selectedTags) ? 'has-selection' : ''; ?>">
                                    <?php echo !empty($selectedTags) ? count($selectedTags) : ''; ?>
                                </span>
                                <svg class="filter-dropdown-arrow" width="12" height="8" viewBox="0 0 12 8" fill="none"
                                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <polyline points="1,1 6,6 11,1"></polyline>
                                </svg>
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

                        <!-- Restaurant Dropdown -->
                        <div class="filter-dropdown filter-restaurant">
                            <button type="button" class="filter-dropdown-toggle" id="restaurantToggle"
                                aria-expanded="false" aria-haspopup="true">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt="Restaurant"
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
                                <svg class="filter-dropdown-arrow" width="12" height="8" viewBox="0 0 12 8" fill="none"
                                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <polyline points="1,1 6,6 11,1"></polyline>
                                </svg>
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
                            <span class="price-label">Price: </span>
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
            </form>
        </div>

        <!-- Results Info -->
        <?php if (!empty($restaurants)): ?>
        <div class="results-info">
            <span class="text-muted">Showing <?php echo count($restaurants); ?> restaurants •
                <?php echo $totalProducts; ?> products</span>
            <span class="text-muted" style="margin-left: 12px; font-size: 13px; color: var(--gray-400);">
                (<?php echo $perPage; ?> per page)
            </span>
        </div>
        <?php endif; ?>

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
                    <?php elseif (!empty($selectedTags)): ?>
                    No products match your dietary preferences
                    <?php elseif ($restaurantId > 0): ?>
                    No products available for this restaurant
                    <?php else: ?>
                    Try adjusting your filters or search terms.
                    <?php endif; ?>
                </p>
                <?php if (hasActiveFilters()): ?>
                <a href="menu.php" class="btn btn-outline btn-sm" style="margin-top: 12px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                    Clear Filters
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
                                        <?php 
                                        $displayTags = array_slice($product['dietary_tags'], 0, 5);
                                        foreach ($displayTags as $tag): ?>
                                        <span
                                            class="tag dietary-tag"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tag)), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($product['dietary_tags']) > 5): ?>
                                        <span class="tag tag-more">+<?php echo count($product['dietary_tags']) - 5; ?>
                                            more</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Allergens -->
                                <div class="product-allergens-section">
                                    <span class="allergen-label">Allergens:</span>
                                    <div class="product-allergens-tags">
                                        <?php if (!empty($product['allergens'])): 
                                        $displayAllergens = array_slice($product['allergens'], 0, 5);
                                        foreach ($displayAllergens as $allergen): ?>
                                        <span
                                            class="tag allergen-tag"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $allergen)), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($product['allergens']) > 5): ?>
                                        <span class="tag tag-more">+<?php echo count($product['allergens']) - 5; ?>
                                            more</span>
                                        <?php endif; ?>
                                        <?php else: ?>
                                        <span class="tag-none">None</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Product Actions -->
                            <div class="product-actions">
                                <?php if ($isLoggedIn && $product['stock'] > 0): ?>
                                <form method="POST" action="#" class="add-to-cart-form" style="width: 100%;"
                                    onsubmit="return handleAddToQueue(this, event);">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                                    <div class="action-row">
                                        <div class="quantity-control">
                                            <button type="button" class="qty-btn qty-minus"
                                                aria-label="Decrease quantity">-</button>
                                            <input type="number" name="quantity" value="1" min="1"
                                                max="<?php echo $product['stock']; ?>" class="qty-input">
                                            <button type="button" class="qty-btn qty-plus"
                                                aria-label="Increase quantity">+</button>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm add-btn">Add</button>
                                    </div>
                                </form>
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
            <?php if ($totalPages > 1): ?>
            <nav class="pagination" role="navigation" aria-label="Product pagination">
                <ul class="pagination-list">
                    <?php if ($page > 1): ?>
                    <li class="pagination-item">
                        <a href="<?php echo htmlspecialchars(buildQueryString(['page' => $page-1]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="pagination-link">&larr; Previous</a>
                    </li>
                    <?php endif; ?>

                    <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        if ($startPage > 1) {
                            echo '<li class="pagination-item"><a href="' . htmlspecialchars(buildQueryString(['page' => 1]), ENT_QUOTES, 'UTF-8') . '" class="pagination-link">1</a></li>';
                            if ($startPage > 2) {
                                echo '<li class="pagination-item pagination-ellipsis"><span>...</span></li>';
                            }
                        }
                        for ($i = $startPage; $i <= $endPage; $i++) {
                            $activeClass = $i === $page ? 'active' : '';
                            echo '<li class="pagination-item">';
                            if ($i === $page) {
                                echo '<span class="pagination-link active">' . $i . '</span>';
                            } else {
                                echo '<a href="' . htmlspecialchars(buildQueryString(['page' => $i]), ENT_QUOTES, 'UTF-8') . '" class="pagination-link">' . $i . '</a>';
                            }
                            echo '</li>';
                        }
                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) {
                                echo '<li class="pagination-item pagination-ellipsis"><span>...</span></li>';
                            }
                            echo '<li class="pagination-item"><a href="' . htmlspecialchars(buildQueryString(['page' => $totalPages]), ENT_QUOTES, 'UTF-8') . '" class="pagination-link">' . $totalPages . '</a></li>';
                        }
                        ?>

                    <?php if ($page < $totalPages): ?>
                    <li class="pagination-item">
                        <a href="<?php echo htmlspecialchars(buildQueryString(['page' => $page+1]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="pagination-link">Next &rarr;</a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>

            <?php endif; ?>
        </section>
    </div>

    <!-- Fixed Order Tracker -->
    <?php if ($activeOrder): ?>
    <?php
        $trackerStep = getTrackerStepIndex($activeOrder['order_status']);
        $trackerStatusLabel = getTrackerStatusLabel($activeOrder['order_status']);
        $trackerStatusClass = 'tracker-status-' . $activeOrder['order_status'];
    ?>
    <div class="order-tracker" id="orderTracker" role="status" aria-live="polite">
        <div class="tracker-container">
            <div class="tracker-info">
                <span class="tracker-order-id">Order #<?php echo (int)$activeOrder['order_id']; ?></span>
                <span class="tracker-status <?php echo htmlspecialchars($trackerStatusClass, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($trackerStatusLabel, ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <span class="tracker-restaurant">
                    <?php echo htmlspecialchars($activeOrder['restaurant_name'] . ' — ' . $activeOrder['branch_name'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            <div class="tracker-progress" aria-hidden="true">
                <?php for ($i = 1; $i <= 4; $i++): ?>
                <span class="progress-step
                        <?php echo $i < $trackerStep ? 'completed' : ''; ?>
                        <?php echo $i === $trackerStep ? 'current' : ''; ?>"></span>
                <?php if ($i < 4): ?>
                <span class="progress-line <?php echo $i < $trackerStep ? 'completed' : ''; ?>"></span>
                <?php endif; ?>
                <?php endfor; ?>
            </div>
            <div class="tracker-action">
                <a href="order-details.php?id=<?php echo (int)$activeOrder['order_id']; ?>"
                    class="btn btn-primary btn-sm">Track Order</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
         QUEUE PANEL - Persistent Cart
         ============================================ -->
    <div class="queue-panel" id="queuePanel" role="dialog" aria-label="Your order queue">
        <!-- Panel Header -->
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
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6,9 12,15 18,9"></polyline>
                </svg>
            </button>
        </div>

        <!-- Panel Body -->
        <div class="queue-panel-body">
            <div class="queue-items-container" id="queueItemsContainer">
                <!-- Items rendered by JavaScript -->
            </div>

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
                    <button type="button" class="queue-btn-cancel" id="queueCancelBtn">
                        Cancel Order
                    </button>
                    <a href="checkout.php" class="queue-btn-checkout" id="queueCheckoutBtn" disabled>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z" />
                            <line x1="3" y1="6" x2="21" y2="6" />
                            <path d="M16 10a4 4 0 0 1-8 0" />
                        </svg>
                        Checkout
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================
         MODAL - Remove Item Confirmation
         ============================================ -->
    <div class="queue-modal" id="queueRemoveModal" style="display:none;">
        <div class="queue-modal-overlay"></div>
        <div class="queue-modal-content">
            <div class="queue-modal-header">
                <span class="queue-modal-title">Remove Item</span>
                <button type="button" class="queue-modal-close" id="queueModalClose">&times;</button>
            </div>
            <div class="queue-modal-body">
                <p>Are you sure you want to remove <strong id="queueModalItemName"></strong> from your order?</p>
            </div>
            <div class="queue-modal-footer">
                <button type="button" class="queue-modal-btn-cancel" id="queueModalCancel">Cancel</button>
                <button type="button" class="queue-modal-btn-confirm" id="queueModalConfirm">Remove</button>
            </div>
        </div>
    </div>

    <!-- ============================================
         MODAL - Cancel Entire Order Confirmation
         ============================================ -->
    <div class="queue-modal" id="queueCancelModal" style="display:none;">
        <div class="queue-modal-overlay"></div>
        <div class="queue-modal-content">
            <div class="queue-modal-header">
                <span class="queue-modal-title">Cancel Order</span>
                <button type="button" class="queue-modal-close" id="queueCancelModalClose">&times;</button>
            </div>
            <div class="queue-modal-body">
                <p>Are you sure you want to cancel your entire order?</p>
                <p style="font-size: var(--font-size-sm); color: var(--gray-500); margin-top: 4px;">
                    This will remove <strong id="queueCancelItemCount">0</strong> items from your queue.
                </p>
            </div>
            <div class="queue-modal-footer">
                <button type="button" class="queue-modal-btn-cancel" id="queueCancelModalCancel">Cancel</button>
                <button type="button" class="queue-modal-btn-confirm" id="queueCancelModalConfirm">Yes, Cancel
                    Order</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================
     SCRIPTS
     ============================================ -->
<script src="../assets/ui/js/menu.js" defer></script>
<script src="../assets/ui/js/queue-panel.js" defer></script>

<script>
/**
 * Handle add to queue from product card forms
 */
(function() {
    'use strict';

    window.handleAddToQueue = function(form, event) {
        if (event) {
            event.preventDefault();
        }

        var productCard = form.closest('.product-card');
        if (!productCard) {
            console.warn('Product card not found');
            return false;
        }

        var productId = productCard.dataset.productId;
        var name = productCard.dataset.productName || 'Product';
        var price = parseFloat(productCard.dataset.productPrice) || 0;
        var stock = parseInt(productCard.dataset.productStock) || 999;
        var image = productCard.dataset.productImage || '';
        var restaurantName = productCard.dataset.restaurantName || '';
        var branchName = productCard.dataset.branchName || '';
        var quantityInput = form.querySelector('input[name="quantity"]');
        var quantity = parseInt(quantityInput ? quantityInput.value : 1, 10) || 1;

        if (quantity < 1) quantity = 1;
        if (quantity > stock) quantity = stock;

        if (typeof window.addToQueue === 'function') {
            window.addToQueue(
                parseInt(productId, 10),
                name,
                price,
                quantity,
                image,
                stock,
                restaurantName,
                branchName
            );

            var btn = form.querySelector('.add-btn');
            if (btn) {
                var originalText = btn.textContent;
                btn.textContent = '✓ Added';
                btn.style.background = 'var(--success)';
                btn.style.color = 'white';
                setTimeout(function() {
                    btn.textContent = originalText;
                    btn.style.background = '';
                    btn.style.color = '';
                }, 1500);
            }
        } else {
            form.submit();
        }

        return false;
    };

})();
</script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>