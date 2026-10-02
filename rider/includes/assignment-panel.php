<?php
/**
 * FitPal Rider Assignment Panel
 *
 * Renders the fixed bottom-anchored assignment panel. The panel is
 * collapsed to a 56px header bar by default; clicking the header
 * (or the chevron) slides the body up.
 *
 * ---------------------------------------------------------------------
 * ROW SHAPE — QUEUE-PANEL PARITY
 * ---------------------------------------------------------------------
 * Each assignment row is now a single horizontal line modeled on the
 * customer's queue panel row, not the previous three-band stack.
 *
 *     +----------------------------------------------------------------+
 *     | [img]  Order #7   [ Status | Payment Pill ]       [actions]  |
 *     |        36px                                                 |
 *     +----------------------------------------------------------------+
 *     | Asian Fusion Fit -> Peter Parker . 1 item . P400.00            |
 *     |                                                [chevron]       |
 *     +----------------------------------------------------------------+
 *     | (expanded)                                                     |
 *     |   Restaurant   Asian Fusion Fit — Main Branch                  |
 *     |   Pickup at    ...                                             |
 *     |   Customer     Peter Parker                                    |
 *     |   Deliver to   12-A Sunrise St., ...                           |
 *     |   Items        1                                               |
 *     |   Total        P400.00                                         |
 *     |   [Customized chevron]  (only when the order is customized)    |
 *     |   [Special Instructions block]  (inside the dropdown)          |
 *     +----------------------------------------------------------------+
 *
 * The first line is the header band: it carries the item thumbnail,
 * the order number, the combined status and payment pill, and the
 * three action buttons. It is always visible.
 *
 * The second line is the meta band: it carries a one-line summary of
 * the order and the expand chevron. It is always visible.
 *
 * The third band is the details block: it carries the pickup and
 * drop-off lines, the item count and total, and the customization
 * dropdown when the order carries one. It is hidden until the row is
 * expanded.
 *
 * The single thumbnail in the header replaces the previous row of up
 * to four thumbnails, and its presence at the top of the row
 * satisfies the requirement that a collapsed row still shows a
 * picture of the order. A rider no longer has to open the row to
 * remember which order it is.
 *
 * ---------------------------------------------------------------------
 * DROPDOWNS
 * ---------------------------------------------------------------------
 * The row has exactly one disclosure control: the expand chevron on
 * the meta line. Inside the details block there is a second,
 * independent disclosure: the "Customized" chevron, which opens the
 * modifications list and the special instructions for the order.
 * It is only rendered when the order carries at least one
 * modification or a non-empty special-instructions value.
 *
 * The panel previously had three stacked disclosure controls per
 * row — the row chevron, the "Customized" toggle, and the item
 * thumbnails strip itself. That is now two: the row chevron and,
 * when present, the "Customized" chevron. Fewer controls on a row
 * means less for a rider to scan and less state to preserve across
 * the five-second poll.
 *
 * ---------------------------------------------------------------------
 * SERVER-SIDE FIRST PAINT
 * ---------------------------------------------------------------------
 * This file renders the row shape on the initial page load. The
 * panel's poll re-renders the list every five seconds with the same
 * shape; assignment-panel.js is responsible for emitting exactly
 * what this file emits. The two MUST stay in sync — any change to
 * the row markup must be applied to both files in the same commit.
 *
 * ---------------------------------------------------------------------
 * INITIAL STATE ON THE WRAPPER
 * ---------------------------------------------------------------------
 * The panel wrapper (#assignmentPanelWrapper) carries three data
 * attributes that describe the rider's state at first paint:
 *
 *     data-endpoint       the assignment handler URL
 *     data-rider-eligible "1" when the rider is verified and active,
 *                         "0" otherwise
 *     data-rider-online   "1" when the rider is currently available,
 *                         "0" otherwise
 *
 * The panel's JS reads data-rider-online and data-rider-eligible on
 * DOMContentLoaded and seeds its local `online` and `eligible`
 * variables from those values before the first fetchNow() call. That
 * seed matters for two reasons:
 *
 *   1. The status pill renders in its correct initial state without
 *      waiting for the first poll tick. Without the seed, the pill
 *      would briefly render as Offline (the JS default) on every
 *      page load for an already-online rider, then flip to Online
 *      when the first poll landed.
 *
 *   2. The first availability-changed event the panel might fire —
 *      on the first poll, if the server reports a different value
 *      from the seed — carries a truthful from-value. A consumer
 *      listening for the event sees a real transition, not a
 *      phantom one caused by the JS default differing from the
 *      server's first-paint state.
 *
 * When the attributes are absent (a partially-deployed state), the
 * JS keeps its existing defaults and behaves exactly as before. The
 * two files are therefore not order-dependent.
 *
 * ---------------------------------------------------------------------
 * WRAPPER CONTRACT
 * ---------------------------------------------------------------------
 * The markup this file emits is the exact structure the CSS and JS
 * in this role target:
 *
 *   #assignmentPanelWrapper.assignment-panel-wrapper
 *     #assignmentPanel.assignment-panel.open|closed
 *       #assignmentPanelInner.assignment-panel-inner
 *         #assignmentPanelHeader.assignment-panel-header
 *           .assignment-panel-title
 *           #assignmentPanelStatus.assignment-panel-status
 *           .assignment-panel-meta
 *             #assignmentCountBadge.assignment-count-badge
 *             #assignmentPanelToggle.assignment-panel-toggle
 *         .assignment-panel-body
 *           #assignmentOfflineHint.assignment-offline-hint
 *           #assignmentIneligibleHint.assignment-ineligible-hint
 *           #assignmentList.assignment-list
 *             .assignment-row[.is-collapsed]
 *
 * ---------------------------------------------------------------------
 * DEPLOYMENT
 * ---------------------------------------------------------------------
 * This include is designed to be required by the rider header on
 * every authenticated page. The panel positions itself fixed at the
 * bottom of the viewport, so it floats above the page content
 * regardless of where the include is emitted in the document.
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES APPLIED
 * ---------------------------------------------------------------------
 *  - No inline CSS. assignment-panel.css is loaded by the rider
 *    header.
 *  - No inline JS. assignment-panel.js is loaded by the rider
 *    header.
 *  - No SQL in this file. All data access goes through
 *    rider/backend/database/rider-assignment-queries.php and
 *    rider/backend/database/product-queries.php.
 *  - No hard-coded <svg>. Icons come from
 *    shared/assets/images/icons/.
 *  - Every modal contains an <img> icon.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 3.2 — Adds data-rider-eligible and data-rider-online to
 *                the panel wrapper. The panel's JS reads them on
 *                DOMContentLoaded to seed its local state from the
 *                server's first paint, so the status pill renders
 *                in the correct state from the first frame and the
 *                first availability-changed event carries a
 *                truthful from-value.
 *
 *                (3.1: status badge and payment pill grouped in a
 *                .assignment-row-pills container. 3.0: row shape
 *                relaid out to match the customer queue panel.
 *                2.1: restored panel wrapper. 2.0: inline card
 *                render.)
 */

declare(strict_types=1);

if (!isset($database_connection) || !($database_connection instanceof PDO)) {
    require_once __DIR__ . '/../../shared/backend/database/database-connect.php';
}

require_once __DIR__ . '/../backend/database/rider-assignment-queries.php';
require_once __DIR__ . '/../backend/database/product-queries.php';

$riderId = (int)($_SESSION['delivery_rider_id'] ?? 0);
if ($riderId <= 0) {
    return;
}

$assetBase = $assetBase ?? '../../shared/';

$riderAssignments = getPanelAssignments($database_connection, $riderId);
$panelCounts      = getPanelAssignmentCounts($database_connection, $riderId);
$panelEligible    = panelRiderIsEligible($database_connection, $riderId);
$panelMaxOrderId  = getPanelMaxOrderId($database_connection, $riderId);

$riderProfile         = getRiderProfile($database_connection, $riderId);
$riderIsOnline        = $riderProfile ? (int)($riderProfile['is_available'] ?? 0) === 1 : false;

$statusText   = $panelEligible ? ($riderIsOnline ? 'Online' : 'Offline') : 'Inactive';
$statusAction = $panelEligible ? ($riderIsOnline ? 'Go Offline' : 'Go Online') : '';
$statusClass  = $panelEligible
    ? ($riderIsOnline ? 'is-online' : 'is-offline')
    : 'is-inactive';

$endpoint = '../backend/handlers/assignment-handler.php';

/* ----------------------------------------------------------------
 * HELPERS LOCAL TO THIS FILE
 *
 * These are the server-side equivalents of the JS renderer's
 * helpers. They produce the exact same markup so the first paint
 * and the poll render identical rows.
 * ---------------------------------------------------------------- */

/**
 * Build the payment-method pill payload for one order.
 *
 * @param string $paymentMethod
 * @return array{icon: string, label: string, slug: string}
 */
function assignmentPanelPaymentPill(string $paymentMethod): array
{
    return match ($paymentMethod) {
        'COD' => [
            'icon'  => 'coin-line.svg',
            'label' => 'Cash on Delivery',
            'slug'  => 'cod',
        ],
        'Wallet' => [
            'icon'  => 'wallet-fill.svg',
            'label' => 'Wallet',
            'slug'  => 'wallet',
        ],
        'Online' => [
            'icon'  => 'qr-code-line.svg',
            'label' => 'Online Payment',
            'slug'  => 'online',
        ],
        default => [
            'icon'  => 'coin-line.svg',
            'label' => $paymentMethod !== '' ? $paymentMethod : '—',
            'slug'  => 'other',
        ],
    };
}

/**
 * Collect every modification and every special instruction for one
 * order, deduplicated.
 *
 * @param array<int, array<string, mixed>> $items
 * @return array{modifications: array<int, array<string, mixed>>, instructions: array<int, string>}
 */
function assignmentPanelCollectCustomizations(array $items): array
{
    $modifications = [];
    $instructions  = [];

    $seenMods = [];
    $seenNote = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemCustomizations = $item['customizations'] ?? [];
        if (is_array($itemCustomizations)) {
            foreach ($itemCustomizations as $cust) {
                if (!is_array($cust)) {
                    continue;
                }

                $name = trim((string)($cust['ingredient_name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $isRemoved = (bool)($cust['is_removed'] ?? false);
                $key       = $name . '::' . ($isRemoved ? '1' : '0');

                if (isset($seenMods[$key])) {
                    continue;
                }
                $seenMods[$key] = true;

                $modifications[] = [
                    'name'      => $name,
                    'isRemoved' => $isRemoved,
                    'modifier'  => (float)($cust['price_at_time'] ?? 0),
                ];
            }
        }

        $notes = trim((string)($item['custom_instructions'] ?? ''));
        if ($notes !== '' && !isset($seenNote[$notes])) {
            $seenNote[$notes] = true;
            $instructions[]   = $notes;
        }
    }

    return [
        'modifications' => $modifications,
        'instructions'  => $instructions,
    ];
}

/**
 * Return the first item's resolved image URL for the row thumbnail,
 * or '' when the order has no items or the URL could not be
 * resolved.
 *
 * @param array<int, array<string, mixed>> $items
 * @return string
 */
function assignmentPanelPrimaryImage(array $items): string
{
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $url = trim((string)($item['product_image_url'] ?? ''));
        if ($url !== '') {
            return $url;
        }
    }
    return '';
}

/**
 * Return the first item's product name for the thumbnail alt text.
 *
 * @param array<int, array<string, mixed>> $items
 * @return string
 */
function assignmentPanelPrimaryName(array $items): string
{
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string)($item['product_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }
    return 'Order item';
}

/**
 * Render one modification row using the cart/queue-panel class
 * names so the dropdown reads identically to the customer's queue
 * panel dropdown.
 *
 * @param array{name: string, isRemoved: bool, modifier: float} $mod
 * @return string
 */
function assignmentPanelRenderModification(array $mod): string
{
    $name = htmlspecialchars($mod['name'], ENT_QUOTES, 'UTF-8');

    if ($mod['isRemoved']) {
        $symbol    = '&minus;';
        $kindClass = 'cart-customs-remove';
        $name     .= ' <span class="cart-customs-removed">(removed)</span>';
        $priceHtml = '';
    } else {
        $symbol    = '+';
        $kindClass = 'cart-customs-add';
        $priceHtml = '';

        if ($mod['modifier'] != 0.0) {
            $sign  = $mod['modifier'] > 0 ? '+' : '&minus;';
            $value = number_format(abs($mod['modifier']), 2);
            $priceHtml = '<span class="cart-customs-price">('
                       . $sign . '&#8369;' . $value
                       . ')</span>';
        }
    }

    return '<li class="cart-customs-row ' . $kindClass . '">'
         .   '<span class="cart-customs-symbol">' . $symbol . '</span>'
         .   '<span class="cart-customs-name">' . $name . '</span>'
         .   $priceHtml
         . '</li>';
}
?>
<div class="assignment-panel-wrapper" id="assignmentPanelWrapper"
    data-endpoint="<?php echo htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8'); ?>"
    data-rider-eligible="<?php echo $panelEligible ? '1' : '0'; ?>"
    data-rider-online="<?php echo $riderIsOnline ? '1' : '0'; ?>">

    <div class="assignment-panel closed" id="assignmentPanel" data-max-order-id="<?php echo $panelMaxOrderId; ?>">
        <div class="assignment-panel-inner" id="assignmentPanelInner">

            <div class="assignment-panel-header" id="assignmentPanelHeader">
                <div class="assignment-panel-title">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt=""
                        class="assignment-panel-title-icon" width="18" height="18"
                        onerror="this.onerror=null; this.style.display='none';">
                    <span class="assignment-panel-title-text">Assignments</span>
                </div>

                <button type="button" class="assignment-panel-status <?php echo $statusClass; ?>"
                    id="assignmentPanelStatus"
                    aria-label="Availability: <?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="assignment-panel-status-dot" aria-hidden="true"></span>
                    <span
                        class="assignment-panel-status-text"><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span
                        class="assignment-panel-status-action"><?php echo htmlspecialchars($statusAction, ENT_QUOTES, 'UTF-8'); ?></span>
                </button>

                <div class="assignment-panel-meta">
                    <span class="assignment-count-badge" id="assignmentCountBadge"
                        <?php echo (int)$panelCounts['total'] === 0 ? 'style="display:none;"' : ''; ?>>
                        <?php echo (int)$panelCounts['total']; ?>
                    </span>
                    <button type="button" class="assignment-panel-toggle" id="assignmentPanelToggle"
                        aria-expanded="false" aria-label="Toggle assignments">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg" alt=""
                            class="assignment-panel-toggle-icon" width="20" height="20"
                            onerror="this.onerror=null; this.style.display='none';">
                    </button>
                </div>
            </div>

            <div class="assignment-panel-body">
                <div class="assignment-offline-hint" id="assignmentOfflineHint"
                    <?php echo ($riderIsOnline || !$panelEligible) ? 'hidden' : ''; ?>>
                    <img src="<?php echo $assetBase; ?>assets/images/icons/information-fill.svg" alt=""
                        onerror="this.onerror=null; this.style.display='none';">
                    <span>You are offline. Go online to receive assignments.</span>
                </div>

                <div class="assignment-ineligible-hint" id="assignmentIneligibleHint"
                    <?php echo $panelEligible ? 'hidden' : ''; ?>>
                    <img src="<?php echo $assetBase; ?>assets/images/icons/error-warning-fill.svg" alt=""
                        onerror="this.onerror=null; this.style.display='none';">
                    <span>Your account is not verified. Go to your profile to complete verification.</span>
                </div>

                <div class="assignment-list" id="assignmentList">
                    <?php if (empty($riderAssignments)): ?>
                    <div class="assignment-empty-state" id="assignmentEmptyState"
                        <?php echo $panelEligible ? '' : 'hidden'; ?>>
                        <div class="assignment-empty-icon" aria-hidden="true">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/riding-line.svg" alt="No assignments"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/info-card-line.svg'">
                        </div>
                        <p class="assignment-empty-title">No assignments right now</p>
                        <p class="assignment-empty-text">
                            When the kitchen offers you an order, it will appear here. Keep this page open.
                        </p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($riderAssignments as $row):
                        $orderId     = (int)($row['order_id'] ?? 0);
                        $status      = (string)($row['order_status'] ?? '');
                        $badge       = panelStatusBadge($status);
                        $statusLabel = panelStatusLabel($status);
                        $collapsed   = ($status !== 'rider_pending');

                        $rowClasses = 'assignment-row';
                        if ($collapsed) $rowClasses .= ' is-collapsed';

                        $branchAddress = implode(', ', array_filter([
                            (string)($row['branch_block']       ?? ''),
                            (string)($row['branch_barangay']    ?? ''),
                            (string)($row['branch_city']        ?? ''),
                            (string)($row['branch_province']    ?? ''),
                            (string)($row['branch_region']      ?? ''),
                            (string)($row['branch_postal_code'] ?? ''),
                            (string)($row['branch_country']     ?? ''),
                        ]));

                        $messageChannel = panelMessageChannel($status);

                        $restaurantName = (string)($row['restaurant_name'] ?? '');
                        $branchName     = (string)($row['branch_name']     ?? '');
                        $restaurantLine = $restaurantName;
                        if ($branchName !== '' && $branchName !== $restaurantName) {
                            $restaurantLine .= ' — ' . $branchName;
                        }

                        // Fetch the order's items with their images,
                        // customizations, and special instructions.
                        $items = getRiderOrderItemsWithDetails($database_connection, $orderId);

                        $primaryImage = assignmentPanelPrimaryImage($items);
                        $primaryName  = assignmentPanelPrimaryName($items);
                        $fallbackIcon = $assetBase . 'assets/images/icons/restaurant.svg';
                        $fallbackAlt  = $assetBase . 'assets/images/icons/community-general.svg';

                        if ($primaryImage === '') {
                            $primaryImage = $fallbackIcon;
                        }

                        $itemCount  = (int)($row['item_count'] ?? 0);
                        $orderTotal = (float)($row['order_total'] ?? 0);

                        $summaryParts = [
                            $restaurantName !== '' ? $restaurantName : 'Restaurant',
                            '&rarr;',
                            (string)($row['customer_name'] ?? 'Customer'),
                            '&bull;',
                            $itemCount . ' item' . ($itemCount === 1 ? '' : 's'),
                            '&bull;',
                            '&#8369;' . number_format($orderTotal, 2),
                        ];
                        $summaryLine = implode(' ', $summaryParts);

                        $paymentPill = assignmentPanelPaymentPill((string)($row['payment_method'] ?? ''));
                        $customs     = assignmentPanelCollectCustomizations($items);
                        $hasCustoms  = !empty($customs['modifications'])
                                    || !empty($customs['instructions']);
                    ?>
                    <article class="<?php echo $rowClasses; ?>" data-order-id="<?php echo $orderId; ?>"
                        data-order-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">

                        <!-- ============================================
                             HEADER BAND
                             Thumbnail + order id + status/payment pills + actions.
                             Always visible.
                             ============================================ -->
                        <header class="assignment-row-top">
                            <span class="assignment-row-thumb" aria-hidden="true">
                                <img src="<?php echo htmlspecialchars($primaryImage, ENT_QUOTES, 'UTF-8'); ?>"
                                    alt="<?php echo htmlspecialchars($primaryName, ENT_QUOTES, 'UTF-8'); ?>"
                                    class="assignment-row-thumb-image" width="44" height="44" loading="lazy"
                                    onerror="this.onerror=null; this.src='<?php echo $fallbackAlt; ?>'">
                            </span>

                            <span class="assignment-row-order">Order #<?php echo $orderId; ?></span>

                            <div class="assignment-row-pills">
                                <span
                                    class="assignment-row-status <?php echo htmlspecialchars($badge, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </span>

                                <span
                                    class="assignment-row-payment assignment-row-payment-<?php echo htmlspecialchars($paymentPill['slug'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($paymentPill['icon'], ENT_QUOTES, 'UTF-8'); ?>"
                                        alt="" class="assignment-row-payment-icon" width="14" height="14"
                                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                                    <span
                                        class="assignment-row-payment-label"><?php echo htmlspecialchars($paymentPill['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </span>
                            </div>

                            <div class="assignment-row-actions">
                                <?php if ($status === 'rider_pending'): ?>
                                <button type="button" class="assignment-action-btn is-danger" data-row-action="decline"
                                    data-order-id="<?php echo $orderId; ?>" title="Decline" aria-label="Decline">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/close-circle-fill.svg" alt=""
                                        class="assignment-action-icon" width="16" height="16"
                                        onerror="this.onerror=null; this.style.display='none';">
                                </button>
                                <button type="button" class="assignment-action-btn is-primary" data-row-action="accept"
                                    data-order-id="<?php echo $orderId; ?>" title="Accept" aria-label="Accept">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt=""
                                        class="assignment-action-icon" width="16" height="16"
                                        onerror="this.onerror=null; this.style.display='none';">
                                </button>
                                <?php elseif ($status === 'picking_up'): ?>
                                <button type="button" class="assignment-action-btn is-primary"
                                    data-row-action="mark_picked_up" data-order-id="<?php echo $orderId; ?>"
                                    title="Mark Picked Up" aria-label="Mark Picked Up">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt=""
                                        class="assignment-action-icon" width="16" height="16"
                                        onerror="this.onerror=null; this.style.display='none';">
                                </button>
                                <?php elseif ($status === 'delivering'): ?>
                                <button type="button" class="assignment-action-btn is-primary"
                                    data-row-action="mark_delivered" data-order-id="<?php echo $orderId; ?>"
                                    title="Mark Delivered" aria-label="Mark Delivered">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/verified-badge-fill.svg"
                                        alt="" class="assignment-action-icon" width="16" height="16"
                                        onerror="this.onerror=null; this.style.display='none';">
                                </button>
                                <?php endif; ?>

                                <?php if ($messageChannel !== null): ?>
                                <button type="button" class="assignment-action-btn is-neutral" data-row-action="message"
                                    data-order-id="<?php echo $orderId; ?>"
                                    data-channel="<?php echo htmlspecialchars($messageChannel, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-subtitle="Order #<?php echo $orderId; ?>" title="Message" aria-label="Message">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg" alt=""
                                        class="assignment-action-icon" width="16" height="16"
                                        onerror="this.onerror=null; this.style.display='none';">
                                </button>
                                <?php endif; ?>

                                <?php if (!empty($row['customer_contact'])): ?>
                                <a class="assignment-action-btn is-neutral"
                                    href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', (string)$row['customer_contact']), ENT_QUOTES, 'UTF-8'); ?>"
                                    title="Call" aria-label="Call">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/phone-fill.svg" alt=""
                                        class="assignment-action-icon" width="16" height="16"
                                        onerror="this.onerror=null; this.style.display='none';">
                                </a>
                                <?php endif; ?>
                            </div>
                        </header>

                        <!-- ============================================
                             META BAND
                             One-line summary + expand chevron.
                             Always visible.
                             ============================================ -->
                        <div class="assignment-row-meta-line">
                            <p class="assignment-row-summary">
                                <?php echo $summaryLine; ?>
                            </p>
                            <button type="button" class="assignment-row-expand"
                                aria-expanded="<?php echo $collapsed ? 'false' : 'true'; ?>">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg" alt=""
                                    class="assignment-row-expand-icon" width="14" height="14"
                                    onerror="this.onerror=null; this.style.display='none';">
                                <span><?php echo $collapsed ? 'Details' : 'Hide'; ?></span>
                            </button>
                        </div>

                        <!-- ============================================
                             DETAILS BLOCK
                             Pickup and drop-off lines, item count, total,
                             and the customization dropdown.
                             Hidden until the row is expanded.
                             ============================================ -->
                        <div class="assignment-row-details">
                            <div class="assignment-row-meta">
                                <p class="assignment-row-line">
                                    <span class="label">Restaurant</span>
                                    <span
                                        class="value"><?php echo htmlspecialchars($restaurantLine, ENT_QUOTES, 'UTF-8'); ?></span>
                                </p>
                                <?php if ($branchAddress !== ''): ?>
                                <p class="assignment-row-line">
                                    <span class="label">Pickup at</span>
                                    <span
                                        class="value"><?php echo htmlspecialchars($branchAddress, ENT_QUOTES, 'UTF-8'); ?></span>
                                </p>
                                <?php endif; ?>
                                <p class="assignment-row-line">
                                    <span class="label">Customer</span>
                                    <span
                                        class="value"><?php echo htmlspecialchars((string)($row['customer_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                                </p>
                                <p class="assignment-row-line">
                                    <span class="label">Deliver to</span>
                                    <span
                                        class="value"><?php echo htmlspecialchars((string)($row['destination_address'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                                </p>
                                <p class="assignment-row-line">
                                    <span class="label">Items</span>
                                    <span class="value"><?php echo $itemCount; ?></span>
                                </p>
                                <p class="assignment-row-line">
                                    <span class="label">Total</span>
                                    <span
                                        class="value assignment-row-total">&#8369;<?php echo number_format($orderTotal, 2); ?></span>
                                </p>
                            </div>

                            <?php if ($hasCustoms): ?>
                            <div class="assignment-row-customs">
                                <button type="button" class="assignment-row-customs-toggle"
                                    data-customs-toggle="riderAssignmentCustoms<?php echo $orderId; ?>"
                                    aria-expanded="false" aria-controls="riderAssignmentCustoms<?php echo $orderId; ?>">
                                    <span>Customized</span>
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg"
                                        alt="" class="assignment-row-customs-toggle-icon" width="14" height="14"
                                        onerror="this.style.display='none';">
                                </button>

                                <div class="assignment-row-customs-panel"
                                    id="riderAssignmentCustoms<?php echo $orderId; ?>" hidden>
                                    <?php if (!empty($customs['modifications'])): ?>
                                    <ul class="cart-customs-list">
                                        <?php foreach ($customs['modifications'] as $mod): ?>
                                        <?php echo assignmentPanelRenderModification($mod); ?>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php endif; ?>

                                    <?php if (!empty($customs['instructions'])): ?>
                                    <?php foreach ($customs['instructions'] as $note): ?>
                                    <div class="queue-customs-notes">
                                        <p class="queue-customs-notes-label">Special Instructions</p>
                                        <p class="queue-customs-notes-text">
                                            <?php echo nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')); ?>
                                        </p>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </article>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- ASSIGNMENT NOTIFICATION MODAL                                -->
<!-- ============================================================ -->
<div class="assignment-notify-modal" id="assignmentNotifyModal" role="dialog" aria-modal="true"
    aria-labelledby="assignmentNotifyTitle">
    <div class="assignment-notify-overlay" data-assignment-notify-dismiss></div>
    <div class="assignment-notify-content">
        <div class="assignment-notify-icon" aria-hidden="true">
            <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt=""
                onerror="this.onerror=null; this.style.display='none';">
        </div>
        <p class="assignment-notify-kicker">New assignment</p>
        <p class="assignment-notify-title" id="assignmentNotifyTitle">A new order is waiting</p>
        <p class="assignment-notify-text">Accept to start the pickup, or decline to pass it back to the kitchen.</p>

        <dl class="assignment-notify-meta">
            <div class="assignment-notify-meta-row">
                <dt>Order</dt>
                <dd id="assignmentNotifyOrderId">—</dd>
            </div>
            <div class="assignment-notify-meta-row">
                <dt>Restaurant</dt>
                <dd id="assignmentNotifyRestaurant">—</dd>
            </div>
            <div class="assignment-notify-meta-row">
                <dt>Customer</dt>
                <dd id="assignmentNotifyCustomer">—</dd>
            </div>
            <div class="assignment-notify-meta-row">
                <dt>Total</dt>
                <dd id="assignmentNotifyTotal">—</dd>
            </div>
        </dl>

        <div class="assignment-notify-actions">
            <button type="button" class="assignment-notify-btn assignment-notify-btn-decline"
                id="assignmentNotifyDeclineBtn">Decline</button>
            <button type="button" class="assignment-notify-btn assignment-notify-btn-accept"
                id="assignmentNotifyAcceptBtn">Accept</button>
        </div>

        <button type="button" class="assignment-notify-dismiss" id="assignmentNotifyDismissBtn"
            data-assignment-notify-dismiss>Decide later</button>
    </div>
</div>

<!-- ============================================================ -->
<!-- AVAILABILITY MODAL                                           -->
<!-- ============================================================ -->
<div class="assignment-availability-modal" id="assignmentAvailabilityModal" data-variant="primary" data-icon="online"
    role="dialog" aria-modal="true" aria-labelledby="assignmentAvailabilityTitle">
    <div class="assignment-availability-overlay" data-assignment-availability-dismiss></div>
    <div class="assignment-availability-content">
        <div class="assignment-availability-icon">
            <img src="<?php echo $assetBase; ?>assets/images/icons/riding-fill.svg" alt=""
                class="assignment-availability-icon-online" onerror="this.onerror=null; this.style.display='none';">
            <img src="<?php echo $assetBase; ?>assets/images/icons/close-circle-fill.svg" alt=""
                class="assignment-availability-icon-offline" onerror="this.onerror=null; this.style.display='none';">
            <img src="<?php echo $assetBase; ?>assets/images/icons/error-warning-fill.svg" alt=""
                class="assignment-availability-icon-blocked" onerror="this.onerror=null; this.style.display='none';">
        </div>
        <p class="assignment-availability-title" id="assignmentAvailabilityTitle">Go online?</p>
        <p class="assignment-availability-text" id="assignmentAvailabilityText">
            You will start receiving new assignments immediately.
        </p>
        <div class="assignment-availability-actions">
            <button type="button" class="assignment-availability-btn assignment-availability-btn-cancel"
                id="assignmentAvailabilityCancelBtn" data-assignment-availability-dismiss>Cancel</button>
            <button type="button" class="assignment-availability-btn assignment-availability-btn-confirm"
                id="assignmentAvailabilityConfirmBtn" data-confirm-mode="online">Go Online</button>
        </div>
    </div>
</div>