<?php
/**
 * FitPal Customer Menu Page
 *
 * Displays restaurants and their menu items with dietary filtering and pagination.
 *
 * @package FitPal
 * @version 3.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/header.php';

// Use product queries for menu and product operations
require_once __DIR__ . '/../backend/database/product-queries.php';
require_once __DIR__ . '/../backend/database/order-queries.php';

// Get filters from GET
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$selectedTags = isset($_GET['tags']) && is_array($_GET['tags']) ? array_filter($_GET['tags']) : [];
$restaurantId = isset($_GET['restaurant_id']) ? max(0, (int)$_GET['restaurant_id']) : 0;
$minPrice = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? max(0, (float)$_GET['min_price']) : 0.0;
$maxPrice = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? max(0, (float)$_GET['max_price']) : 0.0;
$perPage = 10;

// Fetch menu data
$menuData = getMenuDataPaginated($database_connection, $page, $perPage, $selectedTags, $search, $restaurantId, $minPrice, $maxPrice);
$restaurants = $menuData['restaurants'];
$totalProducts = $menuData['totalProducts'];
$totalPages = $menuData['totalPages'];

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
 *
 * @param string $status Order status
 * @return int Number of completed steps (0-4)
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
 *
 * @param string $status Order status
 * @return string
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
?>
<link rel="stylesheet" href="../assets/css/menu.css">

<div class="content menu-page<?php echo $activeOrder ? ' has-order-tracker' : ''; ?>">
    <div class="container">

        <!-- <div class="menu-header">
            <p class="heading-2">Explore <span>Menu</span></p>
            <p class="text-muted">Find meals that match your dietary needs</p>
        </div> -->

        <!-- Flash Messages -->
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

        <!-- Sticky Filter Bar -->
        <div class="menu-filters" id="menuFilters">
            <form method="GET" action="" class="filter-form" id="filterForm">
                <!-- Top Row: Search + Controls -->
                <div class="filter-top-row">
                    <!-- Left: Search -->
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

                    <!-- Right: Controls -->
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

                        <!-- Clear Filters -->
                        <?php if (!empty($search) || !empty($selectedTags) || $restaurantId > 0 || $minPrice > 0 || $maxPrice > 0): ?>
                        <a href="<?php echo htmlspecialchars(buildQueryString(['page'=>1, 'search'=>'', 'tags'=>[], 'restaurant_id'=>0, 'min_price'=>'', 'max_price'=>'']), ENT_QUOTES, 'UTF-8'); ?>"
                            class="btn btn-outline btn-sm clear-btn">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/reset.svg" alt="Clear filters"
                                class="btn-icon">
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <input type="hidden" name="page" value="1">
            </form>
        </div>
        <!-- /Sticky Filter Bar -->

        <!-- Results Info -->
        <?php if (!empty($restaurants)): ?>
        <div class="results-info">
            <span class="text-muted">Showing <?php echo count($restaurants); ?> restaurants •
                <?php echo $totalProducts; ?> products</span>
        </div>
        <?php endif; ?>

        <!-- Restaurant List -->
        <section class="restaurant-list" aria-label="Restaurants and menu items">
            <?php if (empty($restaurants)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt="No results">
                </div>
                <p class="heading-4">No products found</p>
                <p class="text-muted">Try adjusting your filters or search terms.</p>
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
                        <div class="product-card" data-product-id="<?php echo $product['id']; ?>"
                            data-dietary="<?php echo htmlspecialchars(implode(',', $product['dietary_tags']), ENT_QUOTES, 'UTF-8'); ?>"
                            data-allergens="<?php echo htmlspecialchars(implode(',', $product['allergens']), ENT_QUOTES, 'UTF-8'); ?>"
                            data-product-name="<?php echo htmlspecialchars(strtolower($product['name']), ENT_QUOTES, 'UTF-8'); ?>"
                            data-restaurant-name="<?php echo htmlspecialchars(strtolower($restaurant['name']), ENT_QUOTES, 'UTF-8'); ?>">

                            <!-- Product Image -->
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

                            <div class="product-info">
                                <p class="heading-6">
                                    <?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php if (!empty($product['description'])): ?>
                                <p class="product-description">
                                    <?php echo htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php endif; ?>

                                <div class="product-meta">
                                    <span
                                        class="product-price">&#8369;<?php echo number_format($product['price'], 2); ?></span>
                                    <?php if ($product['calories']): ?>
                                    <span class="product-calories"><?php echo (int)$product['calories']; ?> kcal</span>
                                    <?php endif; ?>
                                    <span
                                        class="product-stock <?php echo $product['stock'] > 0 ? 'in-stock' : 'out-of-stock'; ?>">
                                        <?php echo $product['stock'] > 0 ? 'In stock' : 'Out of stock'; ?>
                                    </span>
                                </div>

                                <?php if (!empty($product['dietary_tags'])): ?>
                                <div class="product-tags">
                                    <?php foreach ($product['dietary_tags'] as $tag): ?>
                                    <span
                                        class="tag dietary-tag"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tag)), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($product['allergens'])): ?>
                                <div class="product-allergens">
                                    <span class="allergen-label">Allergens:</span>
                                    <?php foreach ($product['allergens'] as $allergen): ?>
                                    <span
                                        class="tag allergen-tag"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $allergen)), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="product-actions">
                                <?php if ($isLoggedIn && $product['stock'] > 0): ?>
                                <form method="POST" action="../backend/handlers/add-to-cart-handler.php"
                                    class="add-to-cart-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                    <input type="hidden" name="redirect"
                                        value="<?php echo htmlspecialchars(buildQueryString(['page' => $page]), ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="quantity-control">
                                        <button type="button" class="qty-btn qty-minus"
                                            aria-label="Decrease quantity">-</button>
                                        <input type="number" name="quantity" value="1" min="1"
                                            max="<?php echo $product['stock']; ?>" class="qty-input">
                                        <button type="button" class="qty-btn qty-plus"
                                            aria-label="Increase quantity">+</button>
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm add-btn">Add</button>
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
                        // Show page numbers with ellipsis for many pages
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

    <!-- Fixed Order Tracker (GrabFood-style) -->
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
</div>

<script src="../assets/ui/js/menu.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>