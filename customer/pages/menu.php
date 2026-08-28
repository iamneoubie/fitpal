<?php
/**
 * FitPal Customer Menu Page
 *
 * Displays restaurants and their menu items with dietary filtering and pagination.
 *
 * @package FitPal
 * @version 2.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/customer-queries.php';

// Get filters from GET
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$selectedTags = isset($_GET['tags']) && is_array($_GET['tags']) ? array_filter($_GET['tags']) : [];
$perPage = 10;

// Fetch menu data
$menuData = getMenuDataPaginated($database_connection, $page, $perPage, $selectedTags, $search);
$restaurants = $menuData['restaurants'];
$totalProducts = $menuData['totalProducts'];
$totalPages = $menuData['totalPages'];

// Get all dietary tags for filter checkboxes
$allDietaryTags = getDistinctDietaryTags($database_connection);

$isLoggedIn = isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id']);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Build query string for pagination links (preserve filters)
function buildQueryString(array $params = []): string {
    $base = [];
    if (isset($_GET['search']) && !isset($params['search'])) {
        $params['search'] = $_GET['search'];
    }
    if (isset($_GET['tags']) && !isset($params['tags'])) {
        $params['tags'] = $_GET['tags'];
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

<div class="content menu-page">
    <div class="container">

        <div class="menu-header">
            <p class="heading-2">Explore <span>Menu</span></p>
            <p class="text-muted">Find meals that match your dietary needs</p>
        </div>

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

        <!-- Filter Form -->
        <form method="GET" action="" class="filter-form" id="filterForm">
            <!-- Search on top -->
            <div class="filter-search-row">
                <div class="search-wrapper">
                    <input type="text" name="search" id="menuSearch" 
                           placeholder="Search restaurants or dishes..."
                           value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" 
                           class="form-control">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if (!empty($search) || !empty($selectedTags)): ?>
                <a href="<?php echo htmlspecialchars(buildQueryString(['page'=>1, 'search'=>'', 'tags'=>[]]), ENT_QUOTES, 'UTF-8'); ?>" 
                   class="btn btn-outline btn-sm">Clear</a>
                <?php endif; ?>
            </div>

            <!-- Dietary Tags Filter (bottom) -->
            <div class="filter-tags-row">
                <span class="filter-label">Dietary Tags:</span>
                <div class="filter-options">
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

            <input type="hidden" name="page" value="1">
        </form>

        <!-- Results Info -->
        <?php if (!empty($restaurants)): ?>
        <div class="results-info">
            <span class="text-muted">Showing <?php echo count($restaurants); ?> restaurants • <?php echo $totalProducts; ?> products</span>
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
                            <div class="product-card"
                                 data-product-id="<?php echo $product['id']; ?>"
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
                                    <p class="heading-6"><?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php if (!empty($product['description'])): ?>
                                    <p class="product-description"><?php echo htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php endif; ?>
                                    
                                    <div class="product-meta">
                                        <span class="product-price">&#8369;<?php echo number_format($product['price'], 2); ?></span>
                                        <?php if ($product['calories']): ?>
                                        <span class="product-calories"><?php echo (int)$product['calories']; ?> kcal</span>
                                        <?php endif; ?>
                                        <span class="product-stock <?php echo $product['stock'] > 0 ? 'in-stock' : 'out-of-stock'; ?>">
                                            <?php echo $product['stock'] > 0 ? 'In stock' : 'Out of stock'; ?>
                                        </span>
                                    </div>
                                    
                                    <?php if (!empty($product['dietary_tags'])): ?>
                                    <div class="product-tags">
                                        <?php foreach ($product['dietary_tags'] as $tag): ?>
                                        <span class="tag dietary-tag"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tag)), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($product['allergens'])): ?>
                                    <div class="product-allergens">
                                        <span class="allergen-label">Allergens:</span>
                                        <?php foreach ($product['allergens'] as $allergen): ?>
                                        <span class="tag allergen-tag"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $allergen)), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="product-actions">
                                    <?php if ($isLoggedIn && $product['stock'] > 0): ?>
                                    <form method="POST" action="../backend/handlers/add-to-cart-handler.php" class="add-to-cart-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars(buildQueryString(['page' => $page]), ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="quantity-control">
                                            <button type="button" class="qty-btn qty-minus" aria-label="Decrease quantity">−</button>
                                            <input type="number" name="quantity" value="1" min="1" max="<?php echo $product['stock']; ?>" class="qty-input">
                                            <button type="button" class="qty-btn qty-plus" aria-label="Increase quantity">+</button>
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
                                echo '<li class="pagination-item pagination-ellipsis"><span>…</span></li>';
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
                                echo '<li class="pagination-item pagination-ellipsis"><span>…</span></li>';
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
</div>

<script src="../assets/ui/js/menu.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>