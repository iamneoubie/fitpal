<?php
/**
 * FitPal Customer Review Page
 *
 * Write-review wizard for a delivered order. Three tabs, whose
 * content is driven by what the order actually contains.
 *
 * ---------------------------------------------------------------------
 * PRODUCT IMAGE RESOLUTION
 * ---------------------------------------------------------------------
 * The raw `dietary_information.images` value is a folder path, not a
 * browser-loadable URL. This page resolves it through the helpers in
 * product-queries.php, exactly the way menu.php, orders.php, and
 * product-detail.php do:
 *
 *     getProductImageBasePath()    resolves the folder
 *     getProductPrimaryFilename()  finds the first image file
 *
 * The project-root URL prefix is derived from the page's own
 * $assetBase so the URL is correct at any deployment depth.
 *
 * ---------------------------------------------------------------------
 * RIDER PROFILE PICTURE RESOLUTION
 * ---------------------------------------------------------------------
 * The `delivery_rider_profile.profile_picture` column stores a
 * project-root-relative path (e.g.
 * 'shared/uploads/rider/profiles/4/10_02_2026_0.jpg'). This page
 * prepends the project-root URL derived from $assetBase, so the
 * browser request matches a file on disk.
 *
 * @package FitPal
 * @version 4.1 — Fixes product image and rider profile picture
 *                resolution.
 *
 *                (4.0: three tabs, per-subject comments, Back/Next
 *                navigation. 3.0: inlined the reads. 2.0: four-tab
 *                wizard. 1.0: initial single-product form.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    header('Location: sign-in.php');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderId <= 0) {
    $_SESSION['order_error'] = 'Invalid order.';
    header('Location: orders.php');
    exit;
}

require_once __DIR__ . '/../backend/database/customer-connect.php';
require_once __DIR__ . '/../backend/database/customer-order-queries.php';

// FIX: product-queries.php is required so the image-resolution
// helpers (getProductImageBasePath, getProductPrimaryFilename) are
// available.
require_once __DIR__ . '/../backend/database/product-queries.php';

$customerId = (int)$_SESSION['customer_id'];

/* =============================================================
 * LOCAL READS
 * ============================================================= */

if (!function_exists('reviewGetRatableBranches')) {
    function reviewGetRatableBranches(PDO $db, int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $stmt = $db->prepare(
            "SELECT DISTINCT
                rb.restaurant_branch_id AS branch_id,
                rb.branch_name,
                r.business_name AS restaurant_name
             FROM queue_item qi
             JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
             JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
             WHERE qi.order_id = :order_id
             ORDER BY r.business_name ASC, rb.branch_name ASC"
        );
        $stmt->execute([':order_id' => $orderId]);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'branch_id'       => (int)$row['branch_id'],
                'branch_name'     => (string)$row['branch_name'],
                'restaurant_name' => (string)$row['restaurant_name'],
            ];
        }
        return $out;
    }
}

if (!function_exists('reviewGetRatableRider')) {
    function reviewGetRatableRider(PDO $db, int $orderId): array|false
    {
        if ($orderId <= 0) {
            return false;
        }

        $stmt = $db->prepare(
            "SELECT
                dr.delivery_rider_id AS rider_id,
                dr.first_name,
                dr.middle_name,
                dr.last_name,
                drp.vehicle_type,
                drp.profile_picture
             FROM orders o
             JOIN delivery_rider dr ON o.delivery_rider_id = dr.delivery_rider_id
             LEFT JOIN delivery_rider_profile drp
                    ON dr.delivery_rider_id = drp.delivery_rider_id
             WHERE o.order_id = :order_id
               AND o.delivery_rider_id IS NOT NULL
             LIMIT 1"
        );
        $stmt->execute([':order_id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        $parts = array_filter([
            (string)($row['first_name']  ?? ''),
            (string)($row['middle_name'] ?? ''),
            (string)($row['last_name']   ?? ''),
        ]);
        $displayName = trim(implode(' ', $parts));
        if ($displayName === '') {
            $displayName = 'Rider';
        }

        return [
            'rider_id'        => (int)$row['rider_id'],
            'display_name'    => $displayName,
            'vehicle_type'    => $row['vehicle_type'] !== null
                ? (string)$row['vehicle_type']
                : null,
            'profile_picture' => $row['profile_picture'] !== null
                ? (string)$row['profile_picture']
                : null,
        ];
    }
}

if (!function_exists('reviewDecodeComments')) {
    /**
     * Decode the per-subject comments out of a feedback_content
     * string. Returns an empty array when the column is NULL, the
     * string is empty, or the JSON does not carry a `comments` map.
     *
     * @return array<string, string>  Keyed by "type:id".
     */
    function reviewDecodeComments(?string $content): array
    {
        if ($content === null || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }

        $comments = $decoded['comments'] ?? null;
        if (!is_array($comments)) {
            return [];
        }

        $out = [];
        foreach ($comments as $key => $text) {
            if (!is_string($key) || !is_string($text)) {
                continue;
            }
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            $out[$key] = $text;
        }
        return $out;
    }
}

if (!function_exists('reviewGetExistingFeedback')) {
    function reviewGetExistingFeedback(PDO $db, int $orderId, int $customerId): array|false
    {
        if ($orderId <= 0 || $customerId <= 0) {
            return false;
        }

        $stmt = $db->prepare(
            "SELECT
                f.feedback_id,
                f.feedback_content,
                f.date_posted,
                (
                    SELECT COUNT(*)
                      FROM rating r
                     WHERE r.feedback_id = f.feedback_id
                ) AS rating_count
             FROM feedback f
             WHERE f.order_id = :order_id
               AND f.feedback_from_type = 'customer'
               AND f.feedback_from_id = :customer_id
             LIMIT 1"
        );
        $stmt->execute([
            ':order_id'    => $orderId,
            ':customer_id' => $customerId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        $rawContent = $row['feedback_content'] !== null
            ? (string)$row['feedback_content']
            : null;

        return [
            'feedback_id'      => (int)$row['feedback_id'],
            'feedback_content' => $rawContent,
            'comments'         => reviewDecodeComments($rawContent),
            'date_posted'      => (string)$row['date_posted'],
            'rating_count'     => (int)$row['rating_count'],
        ];
    }
}

if (!function_exists('reviewGetExistingRatings')) {
    /**
     * Ratings on an existing envelope, grouped by type and each
     * carrying its subject id and a display label.
     *
     * @return array{
     *     product: array<int, array{subject_id:int, score:int, subject_label:string}>,
     *     restaurant: array<int, array{subject_id:int, score:int, subject_label:string}>,
     *     rider: array<int, array{subject_id:int, score:int, subject_label:string}>
     * }
     */
    function reviewGetExistingRatings(PDO $db, int $feedbackId): array
    {
        $grouped = ['product' => [], 'restaurant' => [], 'rider' => []];

        if ($feedbackId <= 0) {
            return $grouped;
        }

        $stmt = $db->prepare(
            "SELECT
                r.rating_id,
                r.rating_type,
                r.score,
                r.queue_item_id,
                r.branch_id,
                r.rider_id,
                p.name AS product_name,
                rb.branch_name,
                r2.business_name AS restaurant_name,
                CONCAT_WS(' ',
                    dr.first_name,
                    NULLIF(dr.middle_name, ''),
                    dr.last_name
                ) AS rider_name
             FROM rating r
             LEFT JOIN queue_item qi ON r.queue_item_id = qi.queue_item_id
             LEFT JOIN product p     ON qi.product_id = p.product_id
             LEFT JOIN restaurant_branch rb ON r.branch_id = rb.restaurant_branch_id
             LEFT JOIN restaurant r2 ON rb.restaurant_id = r2.restaurant_id
             LEFT JOIN delivery_rider dr ON r.rider_id = dr.delivery_rider_id
             WHERE r.feedback_id = :feedback_id
             ORDER BY r.rating_id ASC"
        );
        $stmt->execute([':feedback_id' => $feedbackId]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $type = (string)$row['rating_type'];
            if (!isset($grouped[$type])) {
                continue;
            }

            $subjectId = 0;
            $label     = '';

            if ($type === 'product') {
                $subjectId = (int)($row['queue_item_id'] ?? 0);
                $label     = (string)($row['product_name'] ?? 'Product');
            } elseif ($type === 'restaurant') {
                $subjectId = (int)($row['branch_id'] ?? 0);
                $branch    = (string)($row['branch_name'] ?? '');
                $resto     = (string)($row['restaurant_name'] ?? '');
                $label     = trim($resto . ($branch !== '' ? ' • ' . $branch : ''));
                if ($label === '') {
                    $label = 'Restaurant';
                }
            } elseif ($type === 'rider') {
                $subjectId = (int)($row['rider_id'] ?? 0);
                $label     = trim((string)($row['rider_name'] ?? ''));
                if ($label === '') {
                    $label = 'Rider';
                }
            }

            $grouped[$type][] = [
                'subject_id'    => $subjectId,
                'score'         => (int)$row['score'],
                'subject_label' => $label,
            ];
        }

        return $grouped;
    }
}

/* =============================================================
 * PAGE DATA
 * ============================================================= */

$ownership = getOrderOwnership($database_connection, $orderId, $customerId);
if (!$ownership) {
    $_SESSION['order_error'] = 'Order not found.';
    header('Location: orders.php');
    exit;
}

if ($ownership['order_status'] !== 'delivered') {
    $_SESSION['order_error'] = 'You can only review delivered orders.';
    header('Location: orders.php');
    exit;
}

$existingFeedback = reviewGetExistingFeedback(
    $database_connection,
    $orderId,
    $customerId
);
$alreadyReviewed = ($existingFeedback !== false);

$productItems = [];
$branchItems  = [];
$riderItem    = false;

if (!$alreadyReviewed) {
    $productItems = getOrderItemsWithCustomizations($database_connection, $orderId);
    $branchItems  = reviewGetRatableBranches($database_connection, $orderId);
    $riderItem    = reviewGetRatableRider($database_connection, $orderId);
}

$hasProducts = !empty($productItems);
$hasBranches = !empty($branchItems);
$hasRider    = ($riderItem !== false);

$existingRatingsByType = ['product' => [], 'restaurant' => [], 'rider' => []];
$existingComments      = [];

if ($alreadyReviewed) {
    $existingRatingsByType = reviewGetExistingRatings(
        $database_connection,
        (int)$existingFeedback['feedback_id']
    );
    $existingComments = $existingFeedback['comments'] ?? [];
}

/* =============================================================
 * HELPERS
 * ============================================================= */

// FIX: project-root URL and fallback URLs are computed here, before
// header.php is included, so the page-local helpers below can close
// over them.

// ---------------------------------------------------------------------
// PROJECT-ROOT URL
//
// $assetBase ends with 'shared/'. Trimming that suffix yields the URL
// of the directory that contains shared/. Every project-root-relative
// path can then be appended to this prefix.
// ---------------------------------------------------------------------
$projectRootUrl = '';

/**
 * Compute the project-root URL prefix from the page's own asset base.
 * Defined as a function so it can be called before $assetBase exists
 * (header.php provides $assetBase).
 *
 * @param string $assetBase
 * @return string
 */
if (!function_exists('reviewProjectRootUrl')) {
    function reviewProjectRootUrl(string $assetBase): string
    {
        if ($assetBase === '') {
            return '';
        }
        $trimmed = preg_replace('#shared/$#', '', $assetBase);
        return is_string($trimmed) ? $trimmed : '';
    }
}

if (!function_exists('reviewItemImage')) {
    /**
     * Resolve a product's raw dietary_information.images value into a
     * browser-loadable URL.
     *
     * Mirrors resolveOrderItemImageUrl() in orders.php and
     * resolveCartImageUrl() in cart.php. The raw column value is a
     * folder path; the reader resolves it against the folder shapes
     * that actually exist on disk and returns:
     *
     *     image_base       resolved folder (project-root-relative)
     *     primary filename first image-*.{ext} inside it
     *
     * The browser URL is the project root URL + image_base + filename.
     *
     * @param string $rawPath   Raw dietary_information.images value.
     * @param string $assetBase The page's asset base, ending in 'shared/'.
     * @return string
     */
    function reviewItemImage(string $rawPath, string $assetBase): string
    {
        $fallback = $assetBase . 'assets/images/icons/restaurant.svg';

        if ($rawPath === '') {
            return $fallback;
        }

        $imageBase    = getProductImageBasePath($rawPath);
        $primaryImage = getProductPrimaryFilename($rawPath);

        if ($imageBase === '' || $primaryImage === '') {
            return $fallback;
        }

        $projectRoot = reviewProjectRootUrl($assetBase);
        if ($projectRoot === '') {
            return $fallback;
        }

        return htmlspecialchars(
            $projectRoot . $imageBase . $primaryImage,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('reviewRiderImage')) {
    /**
     * Resolve a rider's stored profile_picture path into a
     * browser-loadable URL.
     *
     * The column stores a project-root-relative path such as
     * 'shared/uploads/rider/profiles/4/10_02_2026_0.jpg'. The page
     * prepends the project root URL derived from $assetBase, exactly
     * the way customer/includes/header.php resolves the customer's own
     * profile picture.
     *
     * @param string|null $path     Raw profile_picture value.
     * @param string      $assetBase The page's asset base, ending in 'shared/'.
     * @return string
     */
    function reviewRiderImage(?string $path, string $assetBase): string
    {
        $fallback = $assetBase . 'assets/images/icons/riding-line.svg';

        if ($path === null || $path === '') {
            return $fallback;
        }

        $projectRoot = reviewProjectRootUrl($assetBase);
        if ($projectRoot === '') {
            return $fallback;
        }

        return htmlspecialchars(
            $projectRoot . $path,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('reviewScoreStars')) {
    function reviewScoreStars(int $score): string
    {
        $score = max(0, min(5, $score));
        $out   = '';
        for ($i = 1; $i <= 5; $i++) {
            $out .= ($i <= $score) ? '★' : '☆';
        }
        return $out;
    }
}

require_once __DIR__ . '/../includes/header.php';

// $assetBase and $csrfToken are now available from header.php.
$projectRootUrl = reviewProjectRootUrl($assetBase);
?>

<link rel="stylesheet" href="../assets/css/review.css">

<div class="content review-page" id="reviewPage" data-order-id="<?php echo $orderId; ?>"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
    data-handler-url="../backend/handlers/feedback-handler.php">

    <div class="container">

        <!-- ============================================
             PAGE HEADER
             ============================================ -->
        <div class="page-title-header">
            <div class="page-title-header-top">
                <a href="orders.php" class="back-btn">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="Back"
                        class="back-btn-icon" width="20" height="20">
                    <span>Back to Orders</span>
                </a>
                <h1>Write a Review</h1>
            </div>
        </div>

        <!-- ============================================
             FLASH MESSAGES
             ============================================ -->
        <?php if (isset($_SESSION['review_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['review_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['review_success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['review_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['review_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['review_error']); ?>
        </div>
        <?php endif; ?>

        <!-- ============================================
             ORDER META STRIP
             ============================================ -->
        <div class="review-order-meta">
            <div class="review-order-meta-block">
                <span class="review-order-meta-label">Order</span>
                <span class="review-order-meta-value">#<?php echo $orderId; ?></span>
            </div>

            <div class="review-order-meta-block">
                <span class="review-order-meta-label">Products</span>
                <span class="review-order-meta-value">
                    <?php echo $hasProducts ? count($productItems) : 0; ?>
                </span>
            </div>

            <div class="review-order-meta-block">
                <span class="review-order-meta-label">Branches</span>
                <span class="review-order-meta-value">
                    <?php echo $hasBranches ? count($branchItems) : 0; ?>
                </span>
            </div>

            <div class="review-order-meta-block">
                <span class="review-order-meta-label">Rider</span>
                <span class="review-order-meta-value">
                    <?php echo $hasRider ? 'Yes' : 'No'; ?>
                </span>
            </div>
        </div>

        <?php if ($alreadyReviewed): ?>

        <!-- ============================================
             ALREADY-REVIEWED STATE
             ============================================ -->
        <div class="review-complete-banner">
            <div class="review-complete-icon" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt=""
                    onerror="this.onerror=null; this.style.display='none';">
            </div>
            <div class="review-complete-body">
                <p class="review-complete-title">You already reviewed this order</p>
                <p class="review-complete-text">
                    Submitted on
                    <?php
                    $posted = (string)($existingFeedback['date_posted'] ?? '');
                    $ts     = strtotime($posted);
                    echo htmlspecialchars(
                        $ts !== false ? date('M d, Y g:i A', $ts) : $posted,
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    ?>. You can view what you left below.
                </p>
            </div>
        </div>

        <?php foreach (['product' => 'Products', 'restaurant' => 'Restaurant', 'rider' => 'Rider'] as $typeKey => $typeLabel): ?>
        <?php if (!empty($existingRatingsByType[$typeKey])): ?>
        <section class="review-summary-section">
            <h2 class="review-summary-title"><?php echo $typeLabel; ?></h2>
            <div class="review-summary-list">
                <?php foreach ($existingRatingsByType[$typeKey] as $row): ?>
                <?php
                $subjectId  = (int)$row['subject_id'];
                $commentKey = $typeKey . ':' . $subjectId;
                $comment    = $existingComments[$commentKey] ?? '';
                ?>
                <div class="review-summary-row">
                    <div class="review-summary-head">
                        <span class="review-summary-label">
                            <?php echo htmlspecialchars((string)$row['subject_label'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <span class="review-summary-stars" aria-label="<?php echo (int)$row['score']; ?> out of 5">
                            <?php echo reviewScoreStars((int)$row['score']); ?>
                        </span>
                    </div>
                    <?php if ($comment !== ''): ?>
                    <p class="review-summary-comment">
                        <?php echo nl2br(htmlspecialchars($comment, ENT_QUOTES, 'UTF-8')); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        <?php endforeach; ?>

        <?php if (empty($existingRatingsByType['product']) && empty($existingRatingsByType['restaurant']) && empty($existingRatingsByType['rider'])): ?>
        <p class="review-summary-empty">
            No ratings were recorded on this review.
        </p>
        <?php endif; ?>

        <div class="review-complete-actions">
            <a href="orders.php" class="btn btn-primary">Back to Orders</a>
        </div>

        <?php else: ?>

        <!-- ============================================
             WIZARD STATE — three tabs
             ============================================ -->

        <nav class="review-tabs" id="reviewTabs" role="tablist" aria-label="Review sections">
            <button type="button" class="review-tab active" role="tab" aria-selected="true"
                aria-controls="review-panel-products" data-tab-target="products">
                Products
                <span class="review-tab-count"><?php echo $hasProducts ? count($productItems) : 0; ?></span>
            </button>

            <button type="button" class="review-tab" role="tab" aria-selected="false"
                aria-controls="review-panel-restaurant" data-tab-target="restaurant">
                Restaurant
                <span class="review-tab-count"><?php echo $hasBranches ? count($branchItems) : 0; ?></span>
            </button>

            <button type="button" class="review-tab" role="tab" aria-selected="false" aria-controls="review-panel-rider"
                data-tab-target="rider">
                Rider
                <span class="review-tab-count"><?php echo $hasRider ? 1 : 0; ?></span>
            </button>
        </nav>

        <!-- ============================================
             TAB: PRODUCTS
             ============================================ -->
        <section class="review-panel active" id="review-panel-products" role="tabpanel" data-tab-panel="products">
            <div class="review-panel-header">
                <h2 class="review-panel-title">Rate the products</h2>
                <p class="review-panel-subtitle">
                    Rate any product and leave a comment. Skipped products are simply not submitted.
                </p>
            </div>

            <?php if (!$hasProducts): ?>
            <div class="review-panel-empty">
                <img src="<?php echo $assetBase; ?>assets/images/icons/file-warning-line.svg" alt=""
                    class="review-panel-empty-icon" width="28" height="28"
                    onerror="this.onerror=null; this.style.display='none';">
                <p>No products were recorded on this order.</p>
            </div>
            <?php else: ?>
            <div class="review-card-list">
                <?php foreach ($productItems as $item):
                    $queueItemId = (int)($item['queue_item_id'] ?? 0);
                    if ($queueItemId <= 0) continue;
                    $productName = (string)($item['product_name'] ?? 'Product');
                    $quantity    = (int)($item['quantity'] ?? 0);
                    $unitPrice   = (float)($item['unit_price'] ?? 0);

                    // FIX: resolve the raw dietary_information.images
                    // folder path into a browser-loadable URL.
                    $itemImage = reviewItemImage(
                        (string)($item['product_image'] ?? ''),
                        $assetBase
                    );
                ?>
                <article class="review-card" data-rating-type="product" data-rating-id="<?php echo $queueItemId; ?>"
                    data-rating-label="<?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="review-card-head">
                        <div class="review-card-image">
                            <img src="<?php echo $itemImage; ?>"
                                alt="<?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
                        </div>
                        <div class="review-card-info">
                            <p class="review-card-name">
                                <?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <p class="review-card-meta">
                                <?php echo $quantity; ?> × ₱<?php echo number_format($unitPrice, 2); ?>
                            </p>
                        </div>
                    </div>

                    <div class="review-stars" role="radiogroup"
                        aria-label="Rate <?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="button" class="review-star" data-score="1" aria-label="1 star">★</button>
                        <button type="button" class="review-star" data-score="2" aria-label="2 stars">★</button>
                        <button type="button" class="review-star" data-score="3" aria-label="3 stars">★</button>
                        <button type="button" class="review-star" data-score="4" aria-label="4 stars">★</button>
                        <button type="button" class="review-star" data-score="5" aria-label="5 stars">★</button>
                    </div>

                    <div class="review-card-comment">
                        <label class="review-card-comment-label"
                            for="review-comment-product-<?php echo $queueItemId; ?>">
                            Comment
                            <span class="review-card-comment-hint">(optional)</span>
                        </label>
                        <textarea id="review-comment-product-<?php echo $queueItemId; ?>"
                            class="review-card-textarea review-comment-input" rows="3" maxlength="1000"
                            placeholder="How was this product?"></textarea>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="review-panel-nav">
                <span class="review-nav-spacer"></span>
                <button type="button" class="btn btn-primary review-nav-btn review-nav-next"
                    data-next-target="restaurant">
                    Next: Restaurant
                </button>
            </div>
        </section>

        <!-- ============================================
             TAB: RESTAURANT
             ============================================ -->
        <section class="review-panel" id="review-panel-restaurant" role="tabpanel" data-tab-panel="restaurant">
            <div class="review-panel-header">
                <h2 class="review-panel-title">Rate the restaurant</h2>
                <p class="review-panel-subtitle">
                    <?php if ($hasBranches && count($branchItems) > 1): ?>
                    This order came from <?php echo count($branchItems); ?> branches. Rate any you want.
                    <?php else: ?>
                    Rate the restaurant that prepared your order.
                    <?php endif; ?>
                </p>
            </div>

            <?php if (!$hasBranches): ?>
            <div class="review-panel-empty">
                <img src="<?php echo $assetBase; ?>assets/images/icons/file-warning-line.svg" alt=""
                    class="review-panel-empty-icon" width="28" height="28"
                    onerror="this.onerror=null; this.style.display='none';">
                <p>No restaurant was recorded on this order.</p>
            </div>
            <?php else: ?>
            <div class="review-card-list">
                <?php foreach ($branchItems as $branch):
                    $branchId = (int)($branch['branch_id'] ?? 0);
                    if ($branchId <= 0) continue;
                    $branchName  = (string)($branch['branch_name'] ?? 'Branch');
                    $restoName   = (string)($branch['restaurant_name'] ?? 'Restaurant');
                    $displayName = $restoName . ' • ' . $branchName;
                ?>
                <article class="review-card" data-rating-type="restaurant" data-rating-id="<?php echo $branchId; ?>"
                    data-rating-label="<?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="review-card-head">
                        <div class="review-card-image review-card-image-icon">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt=""
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant-fill.svg'">
                        </div>
                        <div class="review-card-info">
                            <p class="review-card-name">
                                <?php echo htmlspecialchars($restoName, ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <p class="review-card-meta">
                                <?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="review-stars" role="radiogroup"
                        aria-label="Rate <?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="button" class="review-star" data-score="1" aria-label="1 star">★</button>
                        <button type="button" class="review-star" data-score="2" aria-label="2 stars">★</button>
                        <button type="button" class="review-star" data-score="3" aria-label="3 stars">★</button>
                        <button type="button" class="review-star" data-score="4" aria-label="4 stars">★</button>
                        <button type="button" class="review-star" data-score="5" aria-label="5 stars">★</button>
                    </div>

                    <div class="review-card-comment">
                        <label class="review-card-comment-label"
                            for="review-comment-restaurant-<?php echo $branchId; ?>">
                            Comment
                            <span class="review-card-comment-hint">(optional)</span>
                        </label>
                        <textarea id="review-comment-restaurant-<?php echo $branchId; ?>"
                            class="review-card-textarea review-comment-input" rows="3" maxlength="1000"
                            placeholder="How was the food and packaging?"></textarea>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="review-panel-nav">
                <button type="button" class="btn btn-neutral review-nav-btn review-nav-back"
                    data-back-target="products">
                    Back: Products
                </button>
                <span class="review-nav-spacer"></span>
                <button type="button" class="btn btn-primary review-nav-btn review-nav-next" data-next-target="rider">
                    Next: Rider
                </button>
            </div>
        </section>

        <!-- ============================================
             TAB: RIDER
             ============================================ -->
        <section class="review-panel" id="review-panel-rider" role="tabpanel" data-tab-panel="rider">
            <div class="review-panel-header">
                <h2 class="review-panel-title">Rate the rider</h2>
                <p class="review-panel-subtitle">
                    Your rating helps the rider and the platform.
                </p>
            </div>

            <?php if (!$hasRider): ?>
            <div class="review-panel-empty">
                <img src="<?php echo $assetBase; ?>assets/images/icons/riding-line.svg" alt=""
                    class="review-panel-empty-icon" width="28" height="28"
                    onerror="this.onerror=null; this.style.display='none';">
                <p>This order was not delivered by a rider, so there is no rider to rate.</p>
            </div>
            <?php else:
                $riderId     = (int)$riderItem['rider_id'];
                $riderName   = (string)$riderItem['display_name'];
                $vehicleType = (string)($riderItem['vehicle_type'] ?? '');

                // FIX: resolve the rider's stored profile_picture path
                // into a browser-loadable URL.
                $riderImage  = reviewRiderImage(
                    $riderItem['profile_picture'] ?? null,
                    $assetBase
                );
            ?>
            <div class="review-card-list">
                <article class="review-card" data-rating-type="rider" data-rating-id="<?php echo $riderId; ?>"
                    data-rating-label="<?php echo htmlspecialchars($riderName, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="review-card-head">
                        <div class="review-card-image">
                            <img src="<?php echo $riderImage; ?>" alt=""
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/riding-line.svg'">
                        </div>
                        <div class="review-card-info">
                            <p class="review-card-name">
                                <?php echo htmlspecialchars($riderName, ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <?php if ($vehicleType !== ''): ?>
                            <p class="review-card-meta">
                                <?php echo htmlspecialchars(ucfirst($vehicleType), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="review-stars" role="radiogroup"
                        aria-label="Rate <?php echo htmlspecialchars($riderName, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="button" class="review-star" data-score="1" aria-label="1 star">★</button>
                        <button type="button" class="review-star" data-score="2" aria-label="2 stars">★</button>
                        <button type="button" class="review-star" data-score="3" aria-label="3 stars">★</button>
                        <button type="button" class="review-star" data-score="4" aria-label="4 stars">★</button>
                        <button type="button" class="review-star" data-score="5" aria-label="5 stars">★</button>
                    </div>

                    <div class="review-card-comment">
                        <label class="review-card-comment-label" for="review-comment-rider-<?php echo $riderId; ?>">
                            Comment
                            <span class="review-card-comment-hint">(optional)</span>
                        </label>
                        <textarea id="review-comment-rider-<?php echo $riderId; ?>"
                            class="review-card-textarea review-comment-input" rows="3" maxlength="1000"
                            placeholder="How was the delivery?"></textarea>
                    </div>
                </article>
            </div>
            <?php endif; ?>

            <p class="review-submit-error" id="reviewSubmitError" role="alert" aria-live="polite"></p>

            <div class="review-panel-nav">
                <button type="button" class="btn btn-neutral review-nav-btn review-nav-back"
                    data-back-target="restaurant">
                    Back: Restaurant
                </button>
                <span class="review-nav-spacer"></span>
                <button type="button" class="btn btn-primary review-submit-btn" id="reviewSubmitBtn"
                    data-label-default="Submit Review" data-label-busy="Submitting…">
                    Submit Review
                </button>
            </div>
        </section>

        <?php endif; ?>
    </div>
</div>

<script>
window.FITPAL_REVIEW = {
    orderId: <?php echo $orderId; ?>,
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    handlerUrl: '../backend/handlers/feedback-handler.php',
    alreadyReviewed: <?php echo $alreadyReviewed ? 'true' : 'false'; ?>,
    tabOrder: ['products', 'restaurant', 'rider']
};
</script>
<script src="../assets/ui/js/review.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>