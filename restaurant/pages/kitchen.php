<?php
/**
 * FitPal Restaurant Kitchen Page
 *
 * Two views, chosen by the signed-in account's scope:
 *
 *   Branch view (manager / staff / kitchen)
 *     - Tab bar for New, Preparing, Waiting on Rider, Out for
 *       Delivery, and Recent.
 *     - Each tab renders at most five cards. A pagination bar sits
 *       below the list and carries ?tab=<key>&page=<n>.
 *     - Each card is a collapsible article. The header band is
 *       always visible and includes the order number, first item
 *       name, and status/payment pills.
 *     - A summary band sits between the header and details. It
 *       shows the subtotal and any special instructions, and is
 *       visible only when the card is collapsed.
 *     - The details band, visible when expanded, contains the full
 *       route, the full special instructions block, the rider
 *       block, a list of items with per-item prices and
 *       customization modifiers, and a complete order totals
 *       breakdown (Subtotal, Delivery Fee, Service Fee, VAT,
 *       Total) modeled on the customer order receipt.
 *     - Step-by-step actions:
 *         New              → Start Preparing (confirm modal), Cancel
 *         Preparing        → Assign Rider, Cancel, Message
 *         Waiting          → Reassign Rider, Message
 *         Out for Delivery → Message
 *         Recent           → Message, but only while the delivered
 *                            grace window is open.
 *     - The order list is live: kitchen-realtime.js polls
 *       kitchen-order-handler.php on a delta cursor and reapplies
 *       the user's expanded / collapsed choice after every render.
 *     - The rider modal's roster is live: kitchen-order.js fetches
 *       and polls the roster while the modal stays open.
 *     - The Message button opens the shared chat modal.
 *
 *   Owner view (owner / partner)
 *     - Read-only summary across all branches.
 *
 * ---------------------------------------------------------------------
 * ACCESS CONTROL
 * ---------------------------------------------------------------------
 *   - Unauthenticated visitors are redirected to sign-in.
 *   - Any account that is not manager / staff / kitchen / owner /
 *     partner is redirected to the dashboard.
 *
 * ---------------------------------------------------------------------
 * REQUIRED QUERY LAYER
 * ---------------------------------------------------------------------
 * This page requires:
 *
 *     restaurant/backend/database/kitchen-order-queries.php
 *
 * Every function this page calls is declared there or is pulled in
 * transitively through that file. The query layer also requires
 * restaurant/backend/database/product-queries.php so
 * getKitchenOrderItems() can resolve a product thumbnail URL for
 * each item.
 *
 * The order totals (delivery fee, service fee, VAT) are computed
 * here and in the handler using calculateOrderFees() from the
 * shared fee schedule, so the kitchen sees the same breakdown the
 * customer saw at checkout.
 *
 * ---------------------------------------------------------------------
 * HANDLER ENDPOINTS
 * ---------------------------------------------------------------------
 * The #kitchenPage element publishes three endpoints on data-*
 * attributes:
 *
 *     data-handler-url  → ../backend/handlers/kitchen-order-handler.php
 *     data-chat-url     → ../backend/handlers/chat-handler.php
 *     data-asset-base   → the page's own $assetBase
 *
 * kitchen-realtime.js, kitchen-order.js, and
 * restaurant-chat-modal.js all read those attributes.
 *
 * ---------------------------------------------------------------------
 * STATUS FLOW
 * ---------------------------------------------------------------------
 *     pending → preparing → rider_pending → picking_up → delivering
 *                                                              ↓
 *                                                         delivered
 *     pending / preparing → cancelled  (COD)
 *     pending / preparing → refunded   (Wallet / Online)
 *     delivering          → failed     (rider exceeded the grace
 *                                       window)
 *
 * Kitchen-side visibility:
 *
 *   New                 pending
 *   Preparing           preparing
 *   Waiting on Rider    rider_pending + picking_up
 *   Out for Delivery    delivering
 *   Recent              delivered + cancelled + refunded + failed
 *
 * ---------------------------------------------------------------------
 * THE 'failed' STATUS
 * ---------------------------------------------------------------------
 * Produced by the shared order-transaction layer's
 * sweepFailedDeliveries(), which moves an order from 'delivering' to
 * 'failed' when the rider does not mark it delivered within
 * FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS of the moment it entered
 * 'delivering'. A failed order is a closed order that never produced
 * deliverable food revenue. It is treated the same as a cancelled
 * or refunded order by every revenue aggregate in the project.
 *
 * ---------------------------------------------------------------------
 * DELIVERED GRACE WINDOW
 * ---------------------------------------------------------------------
 * A card in the Recent tab can only offer the Message action while
 * the delivered grace window is open. That window is decided in
 * SQL by the query layer (chat_grace_open, computed from
 * delivered_at and RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS) and
 * enforced on the server by chat-handler.php's gateChannel(). The
 * Message button is rendered only when the flag is 1, so the
 * button and the gate agree by construction.
 *
 * ---------------------------------------------------------------------
 * CARD TOGGLE CONTRACT
 * ---------------------------------------------------------------------
 * The card-collapse interaction is owned by exactly one file:
 * restaurant/assets/ui/js/kitchen-order.js.
 *
 * The card carries `.is-open` when the details band is visible;
 * the toggle carries aria-expanded="true" when the details band is
 * visible; the details band is `hidden` when the card is closed.
 *
 * kitchen-realtime.js does NOT bind a click handler for the toggle.
 * After every poll that replaces the card list, it reapplies the
 * user's state by reading `.is-open` off the DOM before the
 * replacement and writing it back after.
 *
 * ---------------------------------------------------------------------
 * TEMPLATE STRUCTURE
 * ---------------------------------------------------------------------
 * restaurant/includes/header.php has already opened the DOCTYPE,
 * <head>, and <main class="main-content"> before this file runs.
 * Everything this page emits lives inside that <main>.
 *
 * Config for the client is carried on #kitchenPage via data-*
 * attributes. No inline script block sits in the body.
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES APPLIED
 * ---------------------------------------------------------------------
 *  - No inline CSS. kitchen-order.css is loaded by header.php's
 *     $pageCssMap for kitchen.php.
 *  - No inline JS. kitchen-order.js, kitchen-realtime.js, and
 *     restaurant-chat-modal.js are loaded at the bottom of the page.
 *  - No SQL. All data comes from the query layer.
 *  - Icons reference only files present under
 *     shared/assets/images/icons/.
 *  - Every modal on the page contains an <img> icon.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 17.0 — The customization list in the expanded details
 *                 band now shows price modifiers, matching the
 *                 customer order receipt format. A new confirmation
 *                 modal is shown when the kitchen presses "Start
 *                 Preparing" to prevent accidental state changes.
 *
 *                 (16.0: two-state card with totals breakdown.
 *                 15.0: summary band. 14.0: card rewritten.
 *                 13.0: full re-emission. 12.0: card rewritten.
 *                 11.3: restaurant-chat-modal.js. 11.2: path fixes.
 *                 11.1: kitchen-order.js. 11.0: path restore.
 *                 10.0: 'failed' status. 9.0: confirm modal.
 *                 8.0: card renderer synced. 7.0: collapsible
 *                 cards. 6.0: realtime rider roster. 5.0:
 *                 pagination.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('restaurant');

if (empty($_SESSION['restaurant_account_id'])) {
    header('Location: sign-in.php');
    exit;
}

$restaurantScope = (string)($_SESSION['restaurant_scope'] ?? '');
$accountRole     = (string)($_SESSION['restaurant_role'] ?? '');
$restaurantId    = (int)($_SESSION['restaurant_id'] ?? 0);
$branchId        = !empty($_SESSION['restaurant_branch_id'])
    ? (int)$_SESSION['restaurant_branch_id']
    : 0;

$isOwner       = in_array($accountRole, ['owner', 'partner'], true);
$isBranchStaff = in_array($accountRole, ['manager', 'staff', 'kitchen'], true);

if (!$isOwner && !$isBranchStaff) {
    header('Location: dashboard.php');
    exit;
}

if ($isOwner && $restaurantId <= 0) {
    header('Location: dashboard.php');
    exit;
}

if ($isBranchStaff && ($restaurantScope !== 'branch' || $branchId <= 0)) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/kitchen-order-queries.php';

// $assetBase and $csrfToken are provided by header.php.

/* --------------------------------------------------------------
 * PAGE LOCALS (formatting only — declared before they are used)
 * -------------------------------------------------------------- */

if (!function_exists('kitchenMoney')) {
    function kitchenMoney(float|string|null $amount): string
    {
        return '₱' . number_format((float)($amount ?? 0), 2);
    }
}

if (!function_exists('kitchenStatusLabel')) {
    function kitchenStatusLabel(string $status): string
    {
        return match ($status) {
            'pending'       => 'New',
            'preparing'     => 'Preparing',
            'rider_pending' => 'Waiting on Rider',
            'picking_up'    => 'Picking Up',
            'delivering'    => 'Out for Delivery',
            'delivered'     => 'Delivered',
            'cancelled'     => 'Cancelled',
            'refunded'      => 'Refunded',
            'failed'        => 'Failed',
            default         => ucfirst($status),
        };
    }
}

if (!function_exists('kitchenStatusBadge')) {
    function kitchenStatusBadge(string $status): string
    {
        return match ($status) {
            'pending'       => 'badge-warning',
            'preparing'     => 'badge-info',
            'rider_pending' => 'badge-primary',
            'picking_up'    => 'badge-primary',
            'delivering'    => 'badge-primary',
            'delivered'     => 'badge-success',
            'cancelled'     => 'badge-danger',
            'refunded'      => 'badge-secondary',
            'failed'        => 'badge-danger',
            default         => 'badge-secondary',
        };
    }
}

if (!function_exists('kitchenDate')) {
    function kitchenDate(string $date): string
    {
        $ts = strtotime($date);
        return $ts !== false ? date('M d, g:i A', $ts) : $date;
    }
}

if (!function_exists('kitchenBranchAddressLine')) {
    function kitchenBranchAddressLine(array $order): string
    {
        $parts = [];
        foreach (['branch_block', 'branch_barangay', 'branch_city', 'branch_province'] as $key) {
            $v = trim((string)($order[$key] ?? ''));
            if ($v !== '') {
                $parts[] = $v;
            }
        }
        return implode(', ', $parts);
    }
}

if (!function_exists('kitchenRiderVehicleLine')) {
    function kitchenRiderVehicleLine(array $order): string
    {
        $parts = [];
        $vehicle = trim((string)($order['rider_vehicle_type'] ?? ''));
        $plate   = trim((string)($order['rider_vehicle_plate'] ?? ''));
        if ($vehicle !== '') $parts[] = $vehicle;
        if ($plate   !== '') $parts[] = $plate;
        return implode(' • ', $parts);
    }
}

if (!function_exists('kitchenPaymentMeta')) {
    function kitchenPaymentMeta(string $method): array
    {
        return match ($method) {
            'COD'    => ['icon' => 'coin-line.svg',    'label' => 'Cash on Delivery', 'slug' => 'cod'],
            'Wallet' => ['icon' => 'wallet-fill.svg',  'label' => 'Wallet',           'slug' => 'wallet'],
            'Online' => ['icon' => 'qr-code-line.svg', 'label' => 'Online Payment',   'slug' => 'online'],
            default  => ['icon' => 'coin-line.svg',    'label' => $method,            'slug' => 'other'],
        };
    }
}

if (!function_exists('kitchenCollectSpecialInstructions')) {
    function kitchenCollectSpecialInstructions(array $items): array
    {
        $out   = [];
        $seen  = [];

        foreach ($items as $item) {
            $notes = trim((string)($item['custom_instructions'] ?? ''));
            if ($notes === '') {
                continue;
            }
            if (isset($seen[$notes])) {
                continue;
            }
            $seen[$notes] = true;
            $out[]        = $notes;
        }

        return $out;
    }
}

if (!function_exists('kitchenPrimaryItemImage')) {
    /**
     * Return the first item's resolved image URL for the card
     * thumbnail, or the shared restaurant icon when the order has
     * no items or the URL could not be resolved.
     *
     * @param array<int, array<string, mixed>> $items
     * @param string $assetBase
     * @return string
     */
    function kitchenPrimaryItemImage(array $items, string $assetBase): string
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
        return $assetBase . 'assets/images/icons/restaurant.svg';
    }
}

if (!function_exists('kitchenPrimaryItemName')) {
    /**
     * Return the first item's product name, or a fallback string
     * when the order has no items.
     *
     * @param array<int, array<string, mixed>> $items
     * @return string
     */
    function kitchenPrimaryItemName(array $items): string
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
        return 'Order Items';
    }
}

if (!function_exists('kitchenCardHtml')) {
    function kitchenCardHtml(
        array $order,
        string $assetBase,
        bool $isCompleted = false
    ): string {
        $orderId       = (int)($order['order_id'] ?? 0);
        $orderStatus   = (string)($order['order_status'] ?? '');
        $paymentMethod = (string)($order['payment_method'] ?? '');
        $items         = is_array($order['items'] ?? null) ? $order['items'] : [];
        $itemCount     = (int)($order['item_count'] ?? count($items));

        // Calculate the full order totals using the shared fee schedule.
        $branchCount = 1;
        $subtotal = 0.0;
        foreach ($items as $item) {
            $qty = (int)($item['quantity'] ?? 0);
            $price = (float)($item['final_price'] ?? $item['unit_price'] ?? 0);
            $subtotal += $qty * $price;
        }
        $fees = calculateOrderFees($branchCount, $subtotal);
        $orderTotal = round($subtotal + $fees['delivery_fee'] + $fees['service_fee'] + $fees['vat'], 2);

        $customerName = trim(
            (string)($order['customer_first_name'] ?? '') . ' ' .
            (string)($order['customer_last_name'] ?? '')
        );
        if ($customerName === '') {
            $customerName = 'Customer';
        }
        $customerContact = trim((string)($order['customer_contact'] ?? ''));
        $customerContactDigits = $customerContact !== ''
            ? preg_replace('/[^0-9+]/', '', $customerContact)
            : '';
        $destination     = (string)($order['destination_address'] ?? '');

        $assignedRiderId   = isset($order['delivery_rider_id']) && $order['delivery_rider_id'] !== null
            ? (int)$order['delivery_rider_id']
            : 0;
        $assignedRiderName = trim(
            (string)($order['rider_first_name'] ?? '') . ' ' .
            (string)($order['rider_last_name'] ?? '')
        );
        $riderContact = trim((string)($order['rider_contact'] ?? ''));
        $riderContactDigits = $riderContact !== ''
            ? preg_replace('/[^0-9+]/', '', $riderContact)
            : '';

        $restaurantName = trim((string)($order['restaurant_name'] ?? ''));
        $branchName     = trim((string)($order['branch_name'] ?? ''));
        $branchAddress  = kitchenBranchAddressLine($order);
        $riderVehicle   = kitchenRiderVehicleLine($order);

        $paymentMeta = kitchenPaymentMeta($paymentMethod);

        $specialInstructions = kitchenCollectSpecialInstructions($items);

        $canStartPreparing = !$isCompleted && $orderStatus === 'pending';
        $canCancel         = !$isCompleted && in_array($orderStatus, ['pending', 'preparing'], true);
        $canAssignRider    = !$isCompleted
            && $orderStatus === 'preparing'
            && $assignedRiderId === 0;
        $canReassignRider  = !$isCompleted
            && in_array($orderStatus, ['preparing', 'rider_pending'], true)
            && $assignedRiderId > 0;

        $graceOpen   = (int)($order['chat_grace_open'] ?? 0) === 1;
        $canMessage  = !$isCompleted || ($orderStatus === 'delivered' && $graceOpen);

        $riderBlockBadge = '';
        if (!$isCompleted) {
            if ($orderStatus === 'rider_pending') {
                $riderBlockBadge = '<span class="badge badge-warning">Awaiting confirmation</span>';
            } elseif ($orderStatus === 'picking_up') {
                $riderBlockBadge = '<span class="badge badge-primary">Picking up</span>';
            }
        }

        $closedAt = '';
        if ($isCompleted) {
            if ($orderStatus === 'delivered' && !empty($order['delivered_at'])) {
                $closedAt = (string)$order['delivered_at'];
            } elseif (!empty($order['updated_at'])) {
                $closedAt = (string)$order['updated_at'];
            } elseif (!empty($order['order_date'])) {
                $closedAt = (string)$order['order_date'];
            }
        }

        $cardClass = 'kitchen-order-card'
            . ($isCompleted ? ' kitchen-order-card-completed' : '');

        $detailsId = 'kitchenOrderDetails' . $orderId;
        $fallbackIcon = $assetBase . 'assets/images/icons/restaurant.svg';

        // First item for the header
        $primaryImage = kitchenPrimaryItemImage($items, $assetBase);
        $primaryName  = kitchenPrimaryItemName($items);

        ob_start();
        ?>
<article class="<?php echo $cardClass; ?>" data-order-id="<?php echo $orderId; ?>"
    data-order-status="<?php echo htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8'); ?>">

    <header class="kitchen-order-header">
        <div class="kitchen-order-header-left">
            <div class="kitchen-order-thumb">
                <img src="<?php echo htmlspecialchars($primaryImage, ENT_QUOTES, 'UTF-8'); ?>" alt=""
                    onerror="this.onerror=null; this.src='<?php echo $fallbackIcon; ?>'">
            </div>
            <div class="kitchen-order-header-details">
                <span class="kitchen-order-id">Order #<?php echo $orderId; ?></span>
                <span
                    class="kitchen-order-item-name"><?php echo htmlspecialchars($primaryName, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </div>

        <div class="kitchen-order-header-right">
            <span class="badge <?php echo kitchenStatusBadge($orderStatus); ?>">
                <?php echo kitchenStatusLabel($orderStatus); ?>
            </span>
            <span
                class="kitchen-payment-pill kitchen-payment-pill-<?php echo htmlspecialchars($paymentMeta['slug'], ENT_QUOTES, 'UTF-8'); ?>">
                <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($paymentMeta['icon'], ENT_QUOTES, 'UTF-8'); ?>"
                    alt="" aria-hidden="true" class="kitchen-payment-pill-icon"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                <span class="kitchen-payment-pill-label">
                    <?php echo htmlspecialchars($paymentMeta['label'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </span>
            <button type="button" class="kitchen-order-toggle" data-row-expand="1"
                aria-controls="<?php echo $detailsId; ?>" aria-expanded="false" aria-label="Toggle order details">
                <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg" alt=""
                    class="kitchen-order-toggle-icon" width="18" height="18"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg'">
            </button>
        </div>
    </header>

    <!-- Summary band: visible only when collapsed -->
    <div class="kitchen-order-summary">
        <div class="kitchen-order-summary-info">
            <span class="kitchen-order-summary-label">Subtotal</span>
            <span class="kitchen-order-summary-value"><?php echo kitchenMoney($subtotal); ?></span>
        </div>
        <?php if (!empty($specialInstructions)): ?>
        <div class="kitchen-order-summary-instructions">
            <img src="<?php echo $assetBase; ?>assets/images/icons/information-fill.svg" alt="" aria-hidden="true"
                onerror="this.onerror=null; this.style.display='none';">
            <span><?php echo htmlspecialchars(implode(' | ', $specialInstructions), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="kitchen-order-details" id="<?php echo $detailsId; ?>" hidden>

        <div class="kitchen-order-meta-line">
            <span class="kitchen-order-date">
                <?php
                echo $isCompleted && $closedAt !== ''
                    ? htmlspecialchars(kitchenDate($closedAt), ENT_QUOTES, 'UTF-8')
                    : htmlspecialchars(kitchenDate((string)($order['order_date'] ?? '')), ENT_QUOTES, 'UTF-8');
                ?>
            </span>
        </div>

        <div class="kitchen-route">
            <div class="kitchen-route-stop kitchen-route-stop-pickup">
                <div class="kitchen-route-icon kitchen-route-icon-pickup" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/community-general.svg'">
                </div>
                <div class="kitchen-route-info">
                    <span class="kitchen-route-label">Pickup</span>
                    <span class="kitchen-route-name">
                        <?php echo htmlspecialchars($restaurantName !== '' ? $restaurantName : '—', ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php if ($branchName !== ''): ?>
                    <span class="kitchen-route-sub">
                        <?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($branchAddress !== ''): ?>
                    <span class="kitchen-route-address">
                        <?php echo htmlspecialchars($branchAddress, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="kitchen-route-connector" aria-hidden="true"></div>

            <div class="kitchen-route-stop kitchen-route-stop-dropoff">
                <div class="kitchen-route-icon kitchen-route-icon-dropoff" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/location-target-fill.svg'">
                </div>
                <div class="kitchen-route-info">
                    <span class="kitchen-route-label">Drop-off</span>
                    <span class="kitchen-route-name">
                        <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php if ($customerContact !== ''): ?>
                    <?php if ($customerContactDigits !== ''): ?>
                    <a href="tel:<?php echo htmlspecialchars($customerContactDigits, ENT_QUOTES, 'UTF-8'); ?>"
                        class="kitchen-route-contact kitchen-route-contact-link">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/phone-fill.svg" alt=""
                            class="kitchen-route-contact-icon" width="14" height="14"
                            onerror="this.onerror=null; this.style.display='none';">
                        <span><?php echo htmlspecialchars($customerContact, ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                    <?php else: ?>
                    <span class="kitchen-route-contact">
                        <?php echo htmlspecialchars($customerContact, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($destination !== ''): ?>
                    <span class="kitchen-route-address">
                        <?php echo htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($specialInstructions)): ?>
        <div class="kitchen-special-instructions" role="note" aria-label="Special instructions">
            <div class="kitchen-special-instructions-head">
                <img src="<?php echo $assetBase; ?>assets/images/icons/information-fill.svg" alt=""
                    class="kitchen-special-instructions-icon" width="16" height="16"
                    onerror="this.onerror=null; this.style.display='none';">
                <span class="kitchen-special-instructions-title">Special Instructions</span>
            </div>
            <ul class="kitchen-special-instructions-list">
                <?php foreach ($specialInstructions as $note): ?>
                <li><?php echo nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="kitchen-rider-block">
            <span class="kitchen-rider-block-label">Rider</span>
            <?php if ($assignedRiderId > 0): ?>
            <div class="kitchen-rider-block-body">
                <span class="kitchen-rider-block-name">
                    <?php echo htmlspecialchars($assignedRiderName !== '' ? $assignedRiderName : ('#' . $assignedRiderId), ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php if ($riderContact !== ''): ?>
                <?php if ($riderContactDigits !== ''): ?>
                <a href="tel:<?php echo htmlspecialchars($riderContactDigits, ENT_QUOTES, 'UTF-8'); ?>"
                    class="kitchen-rider-block-contact kitchen-route-contact-link">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/phone-fill.svg" alt=""
                        class="kitchen-route-contact-icon" width="14" height="14"
                        onerror="this.onerror=null; this.style.display='none';">
                    <span><?php echo htmlspecialchars($riderContact, ENT_QUOTES, 'UTF-8'); ?></span>
                </a>
                <?php else: ?>
                <span class="kitchen-rider-block-contact">
                    <?php echo htmlspecialchars($riderContact, ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($riderVehicle !== ''): ?>
                <span class="kitchen-rider-block-vehicle">
                    <?php echo htmlspecialchars($riderVehicle, ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php endif; ?>
                <?php if ($riderBlockBadge !== ''): ?>
                <span class="kitchen-rider-block-badge">
                    <?php echo $riderBlockBadge; ?>
                </span>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="kitchen-rider-block-body">
                <span class="kitchen-rider-block-empty">
                    <?php echo $isCompleted ? '—' : 'Not assigned'; ?>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <div class="kitchen-order-items">
            <p class="kitchen-items-heading">
                <?php echo $itemCount; ?> item<?php echo $itemCount === 1 ? '' : 's'; ?>
            </p>
            <ul class="kitchen-item-list">
                <?php foreach ($items as $item):
                    $itemQty   = (int)($item['quantity'] ?? 0);
                    $itemName  = (string)($item['product_name'] ?? 'Item');
                    $customs   = $item['customizations'] ?? [];
                    $itemImage = trim((string)($item['product_image_url'] ?? ''));
                    if ($itemImage === '') {
                        $itemImage = $fallbackIcon;
                    }
                    $unitPrice = (float)($item['final_price'] ?? $item['unit_price'] ?? 0);
                    $itemTotal = $unitPrice * $itemQty;
                ?>
                <li class="kitchen-item">
                    <div class="kitchen-item-row">
                        <div class="kitchen-item-image">
                            <img src="<?php echo htmlspecialchars($itemImage, ENT_QUOTES, 'UTF-8'); ?>"
                                alt="<?php echo htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy"
                                onerror="this.onerror=null; this.src='<?php echo $fallbackIcon; ?>'">
                        </div>
                        <div class="kitchen-item-body">
                            <div class="kitchen-item-line">
                                <span class="kitchen-item-qty"><?php echo $itemQty; ?>&times;</span>
                                <span class="kitchen-item-name">
                                    <?php echo htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="kitchen-item-price"><?php echo kitchenMoney($itemTotal); ?></span>
                            </div>

                            <?php if (!empty($customs)): ?>
                            <ul class="kitchen-item-customs">
                                <?php foreach ($customs as $cust):
                                    $custName = (string)($cust['ingredient_name'] ?? '');
                                    if ($custName === '') continue;
                                    $isRemoved = (int)($cust['is_removed'] ?? 0) === 1;
                                    $custQty   = (int)($cust['quantity'] ?? 1);
                                    $priceMod  = (float)($cust['price_at_time'] ?? 0);
                                    $priceLabel = '';
                                    if ($priceMod > 0) {
                                        $priceLabel = '(+' . kitchenMoney($priceMod * $custQty) . ')';
                                    } elseif ($priceMod < 0) {
                                        $priceLabel = '(−' . kitchenMoney(abs($priceMod) * $custQty) . ')';
                                    }
                                ?>
                                <li class="kitchen-item-custom <?php echo $isRemoved ? 'is-removed' : ''; ?>">
                                    <?php if ($isRemoved): ?>
                                    <span class="kitchen-custom-mark">&minus;</span>
                                    <span class="kitchen-custom-text">
                                        <?php echo htmlspecialchars($custName, ENT_QUOTES, 'UTF-8'); ?>
                                        <em>(removed)</em>
                                    </span>
                                    <?php else: ?>
                                    <span class="kitchen-custom-mark">+</span>
                                    <span class="kitchen-custom-text">
                                        <?php echo htmlspecialchars($custName, ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if ($custQty > 1): ?> &times; <?php echo $custQty; ?><?php endif; ?>
                                    </span>
                                    <?php if ($priceLabel !== ''): ?>
                                    <span class="kitchen-custom-price"><?php echo $priceLabel; ?></span>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Full order totals breakdown -->
        <div class="kitchen-order-totals">
            <div class="kitchen-totals-row">
                <span class="kitchen-totals-label">Subtotal</span>
                <span class="kitchen-totals-value"><?php echo kitchenMoney($subtotal); ?></span>
            </div>
            <div class="kitchen-totals-row">
                <span class="kitchen-totals-label">Delivery Fee</span>
                <span class="kitchen-totals-value"><?php echo kitchenMoney($fees['delivery_fee']); ?></span>
            </div>
            <div class="kitchen-totals-row">
                <span class="kitchen-totals-label">Service Fee</span>
                <span class="kitchen-totals-value"><?php echo kitchenMoney($fees['service_fee']); ?></span>
            </div>
            <div class="kitchen-totals-row">
                <span class="kitchen-totals-label">VAT</span>
                <span class="kitchen-totals-value"><?php echo kitchenMoney($fees['vat']); ?></span>
            </div>
            <div class="kitchen-totals-row kitchen-totals-grand">
                <span class="kitchen-totals-label">Total</span>
                <span class="kitchen-totals-value"><?php echo kitchenMoney($orderTotal); ?></span>
            </div>
        </div>
    </div>

    <footer class="kitchen-order-actions">
        <?php if ($canCancel): ?>
        <button type="button" class="btn btn-danger kitchen-action-btn" data-action="cancel_order"
            data-order-id="<?php echo $orderId; ?>">
            Cancel
        </button>
        <?php endif; ?>

        <?php if ($canStartPreparing): ?>
        <button type="button" class="btn btn-primary kitchen-action-btn" data-action="start_preparing"
            data-order-id="<?php echo $orderId; ?>" data-confirm-title="Start preparing Order #<?php echo $orderId; ?>?"
            data-confirm-message="This will mark the order as being prepared. This action cannot be undone."
            data-confirm-label="Yes, start preparing">
            Start Preparing
        </button>
        <?php endif; ?>

        <?php if ($canAssignRider): ?>
        <button type="button" class="btn btn-primary kitchen-action-btn" data-action="assign_rider"
            data-order-id="<?php echo $orderId; ?>" data-reassign="0" data-toggle-modal="rider-modal">
            Assign Rider
        </button>
        <?php endif; ?>

        <?php if ($canReassignRider): ?>
        <button type="button" class="btn btn-primary kitchen-action-btn" data-action="reassign_rider"
            data-order-id="<?php echo $orderId; ?>" data-reassign="1"
            data-current-rider="<?php echo htmlspecialchars($assignedRiderName !== '' ? $assignedRiderName : ('#' . $assignedRiderId), ENT_QUOTES, 'UTF-8'); ?>"
            data-toggle-modal="rider-modal">
            Reassign Rider
        </button>
        <?php endif; ?>

        <?php if ($canMessage): ?>
        <button type="button" class="btn btn-neutral kitchen-action-btn" data-restaurant-chat-open
            data-restaurant-chat-order-id="<?php echo $orderId; ?>" data-restaurant-chat-counterparty="customer"
            data-restaurant-chat-subtitle="Order #<?php echo $orderId; ?> • <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>">
            <img src="<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg" alt="" class="btn-icon"
                width="16" height="16"
                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/contact-us-fill.svg'">
            <span>Message</span>
        </button>
        <?php endif; ?>
    </footer>
</article>
<?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('kitchenPaginationHtml')) {
    function kitchenPaginationHtml(string $tab, int $page, int $totalPages, string $assetBase): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $prevUrl = 'kitchen.php?tab=' . urlencode($tab) . '&page=' . max(1, $page - 1);
        $nextUrl = 'kitchen.php?tab=' . urlencode($tab) . '&page=' . min($totalPages, $page + 1);

        ob_start();
        ?>
<nav class="kitchen-pagination" aria-label="Order pages">
    <?php if ($page > 1): ?>
    <a href="<?php echo htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8'); ?>" class="kitchen-pagination-link"
        data-page-link data-tab="<?php echo htmlspecialchars($tab, ENT_QUOTES, 'UTF-8'); ?>"
        data-page="<?php echo $page - 1; ?>">
        <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-s-line.svg" alt=""
            class="kitchen-pagination-icon" width="14" height="14"
            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg'">
        <span>Previous</span>
    </a>
    <?php else: ?>
    <span class="kitchen-pagination-link is-disabled">
        <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-s-line.svg" alt=""
            class="kitchen-pagination-icon" width="14" height="14"
            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg'">
        <span>Previous</span>
    </span>
    <?php endif; ?>

    <span class="kitchen-pagination-info">
        Page <?php echo $page; ?> of <?php echo $totalPages; ?>
    </span>

    <?php if ($page < $totalPages): ?>
    <a href="<?php echo htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8'); ?>" class="kitchen-pagination-link"
        data-page-link data-tab="<?php echo htmlspecialchars($tab, ENT_QUOTES, 'UTF-8'); ?>"
        data-page="<?php echo $page + 1; ?>">
        <span>Next</span>
        <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt=""
            class="kitchen-pagination-icon" width="14" height="14"
            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-right-long-line.svg'">
    </a>
    <?php else: ?>
    <span class="kitchen-pagination-link is-disabled">
        <span>Next</span>
        <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt=""
            class="kitchen-pagination-icon" width="14" height="14"
            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-right-long-line.svg'">
    </span>
    <?php endif; ?>
</nav>
<?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('kitchenTabTitle')) {
    function kitchenTabTitle(string $tab): string
    {
        return match ($tab) {
            'new'              => 'New Orders',
            'preparing'        => 'Preparing',
            'waiting_on_rider' => 'Waiting on Rider',
            'out_for_delivery' => 'Out for Delivery',
            'recent'           => 'Recent Orders',
            default            => 'Orders',
        };
    }
}

if (!function_exists('kitchenTabEmptyCopy')) {
    function kitchenTabEmptyCopy(string $tab): array
    {
        return match ($tab) {
            'new' => [
                'title' => 'No new orders',
                'text'  => 'New orders will appear here as soon as they are placed.',
            ],
            'preparing' => [
                'title' => 'Nothing is being prepared',
                'text'  => 'Press Start Preparing on a new order and it will show up here.',
            ],
            'waiting_on_rider' => [
                'title' => 'No orders waiting on a rider',
                'text'  => 'Orders waiting on rider confirmation, and riders on their way to pick up, appear here.',
            ],
            'out_for_delivery' => [
                'title' => 'Nothing is out for delivery',
                'text'  => 'Orders a rider has picked up appear here until they are delivered.',
            ],
            'recent' => [
                'title' => 'No recent orders yet',
                'text'  => 'Delivered, cancelled, refunded, and failed orders appear here.',
            ],
            default => [
                'title' => 'No orders',
                'text'  => 'There is nothing to show in this tab.',
            ],
        };
    }
}

/* --------------------------------------------------------------
 * LOAD DATA FOR THE ACTIVE VIEW
 * -------------------------------------------------------------- */

$allowedTabs = ['new', 'preparing', 'waiting_on_rider', 'out_for_delivery', 'recent'];
$activeTab   = isset($_GET['tab']) ? strtolower(trim((string)$_GET['tab'])) : 'new';
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'new';
}

$requestedPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$tabStatuses = [
    'new'              => ['pending'],
    'preparing'        => ['preparing'],
    'waiting_on_rider' => ['rider_pending', 'picking_up'],
    'out_for_delivery' => ['delivering'],
];

$activeOrders    = [];
$completedOrders = [];
$kitchenCounts   = [
    'new'              => 0,
    'preparing'        => 0,
    'waiting_on_rider' => 0,
    'out_for_delivery' => 0,
    'recent'           => 0,
    'total_live'       => 0,
];
$availableRiders = [];
$ownerSummary    = null;
$ownerBranchRows = [];
$loadError       = '';
$pagination      = [
    'items'      => [],
    'total'      => 0,
    'totalPages' => 1,
    'page'       => 1,
    'perPage'    => KITCHEN_DEFAULT_PER_PAGE,
];

if ($isOwner) {

    try {
        $ownerSummary    = getOwnerKitchenSummary($database_connection, $restaurantId);
        $ownerBranchRows = getOwnerBranchBreakdown($database_connection, $restaurantId);
    } catch (PDOException $e) {
        error_log('Kitchen owner view load error: ' . $e->getMessage());
        $loadError = 'Could not load the summary. Please try again.';
    }

} else {

    try {
        $kitchenCounts = getKitchenTabCounts($database_connection, $branchId);

        if ($activeTab === 'recent') {
            $pagination = getBranchCompletedOrdersPaginated(
                $database_connection,
                $branchId,
                $requestedPage,
                KITCHEN_DEFAULT_PER_PAGE
            );

            foreach ($pagination['items'] as &$order) {
                $order['items'] = getKitchenOrderItems(
                    $database_connection,
                    (int)$order['order_id'],
                    $branchId
                );
            }
            unset($order);

            $completedOrders = $pagination['items'];
        } else {
            $statuses = $tabStatuses[$activeTab] ?? ['pending'];

            $pagination = getBranchKitchenOrdersPaginated(
                $database_connection,
                $branchId,
                $statuses,
                $requestedPage,
                KITCHEN_DEFAULT_PER_PAGE
            );

            foreach ($pagination['items'] as &$order) {
                $order['items'] = getKitchenOrderItems(
                    $database_connection,
                    (int)$order['order_id'],
                    $branchId
                );
            }
            unset($order);

            $activeOrders = $pagination['items'];
        }

        $availableRiders = getAvailableRidersForBranch($database_connection, $branchId, 0);

    } catch (PDOException $e) {
        error_log('Kitchen branch view load error: ' . $e->getMessage());
        $loadError = 'Could not load the order list. Please try again.';
    }
}

$maxLiveOrderId = 0;
if (!$isOwner) {
    try {
        $liveIdsStmt = $database_connection->prepare(
            "SELECT COALESCE(MAX(o.order_id), 0)
               FROM orders o
               JOIN queue_item qi ON qi.order_id = o.order_id
              WHERE qi.branch_id = :branch_id
                AND o.order_status IN ('pending','preparing','rider_pending','picking_up','delivering')"
        );
        $liveIdsStmt->execute([':branch_id' => $branchId]);
        $maxLiveOrderId = (int)$liveIdsStmt->fetchColumn();
    } catch (PDOException $e) {
        $maxLiveOrderId = 0;
    }
}
?>
<div class="content kitchen-page" id="kitchenPage"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
    data-scope="<?php echo $isOwner ? 'owner' : 'branch'; ?>" data-branch-id="<?php echo $branchId; ?>"
    data-asset-base="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>"
    data-handler-url="../backend/handlers/kitchen-order-handler.php"
    data-chat-url="../backend/handlers/chat-handler.php" data-max-order-id="<?php echo $maxLiveOrderId; ?>"
    data-active-tab="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>"
    data-active-page="<?php echo (int)$pagination['page']; ?>"
    data-per-page="<?php echo (int)KITCHEN_DEFAULT_PER_PAGE; ?>">

    <div class="container">

        <header class="kitchen-header">
            <div>
                <h1 class="heading-2">
                    Kitchen <span>Orders</span>
                </h1>
                <p class="text-muted">
                    <?php if ($isOwner): ?>
                    Overview of live orders and revenue across every branch.
                    <?php else: ?>
                    Manage incoming orders for
                    <strong><?php echo htmlspecialchars((string)($_SESSION['restaurant_branch_name'] ?? 'your branch'), ENT_QUOTES, 'UTF-8'); ?></strong>.
                    <?php endif; ?>
                </p>
            </div>
            <div class="kitchen-header-actions">
                <?php if (!$isOwner): ?>
                <span class="badge badge-secondary">
                    <?php echo htmlspecialchars((string)($_SESSION['restaurant_branch_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php endif; ?>
            </div>
        </header>

        <?php if (!empty($loadError)): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php endif; ?>

        <?php if ($isOwner): ?>

        <section class="kitchen-summary-grid" aria-label="Order summary">
            <div class="kitchen-summary-tile">
                <span class="kitchen-summary-count"><?php echo (int)($ownerSummary['pending'] ?? 0); ?></span>
                <span class="kitchen-summary-label">Pending</span>
            </div>
            <div class="kitchen-summary-tile">
                <span class="kitchen-summary-count"><?php echo (int)($ownerSummary['preparing'] ?? 0); ?></span>
                <span class="kitchen-summary-label">Preparing</span>
            </div>
            <div class="kitchen-summary-tile">
                <span
                    class="kitchen-summary-count"><?php echo (int)($ownerSummary['rider_pending'] ?? 0) + (int)($ownerSummary['picking_up'] ?? 0); ?></span>
                <span class="kitchen-summary-label">Waiting on Rider</span>
            </div>
            <div class="kitchen-summary-tile">
                <span class="kitchen-summary-count"><?php echo (int)($ownerSummary['delivering'] ?? 0); ?></span>
                <span class="kitchen-summary-label">Out for Delivery</span>
            </div>
            <div class="kitchen-summary-tile">
                <span class="kitchen-summary-count"><?php echo (int)($ownerSummary['delivered_today'] ?? 0); ?></span>
                <span class="kitchen-summary-label">Delivered Today</span>
            </div>
        </section>

        <section class="kitchen-revenue-grid" aria-label="Revenue summary">
            <div class="kitchen-revenue-tile">
                <span class="kitchen-revenue-label">Today</span>
                <span
                    class="kitchen-revenue-value"><?php echo kitchenMoney($ownerSummary['revenue_today'] ?? 0); ?></span>
            </div>
            <div class="kitchen-revenue-tile">
                <span class="kitchen-revenue-label">Last 7 Days</span>
                <span class="kitchen-revenue-value"><?php echo kitchenMoney($ownerSummary['revenue_7d'] ?? 0); ?></span>
            </div>
            <div class="kitchen-revenue-tile">
                <span class="kitchen-revenue-label">Last 30 Days</span>
                <span
                    class="kitchen-revenue-value"><?php echo kitchenMoney($ownerSummary['revenue_30d'] ?? 0); ?></span>
            </div>
        </section>

        <section class="kitchen-card" aria-labelledby="owner-branches-title">
            <div class="kitchen-card-header">
                <h2 class="heading-5" id="owner-branches-title">By Branch</h2>
            </div>

            <?php if (empty($ownerBranchRows)): ?>
            <div class="kitchen-empty-state">
                <p class="kitchen-empty-title">No branches yet</p>
                <p class="kitchen-empty-text">Add a branch to start tracking orders.</p>
            </div>
            <?php else: ?>
            <div class="kitchen-branch-table-wrap">
                <table class="kitchen-branch-table">
                    <thead>
                        <tr>
                            <th scope="col">Branch</th>
                            <th scope="col" class="is-numeric">Pending</th>
                            <th scope="col" class="is-numeric">Preparing</th>
                            <th scope="col" class="is-numeric">Waiting</th>
                            <th scope="col" class="is-numeric">Delivering</th>
                            <th scope="col" class="is-numeric">Revenue (7d)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ownerBranchRows as $row):
                            $waitingFolded = (int)$row['rider_pending'] + (int)$row['picking_up'];
                        ?>
                        <tr>
                            <td>
                                <span class="kitchen-branch-name">
                                    <?php echo htmlspecialchars((string)$row['branch_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="kitchen-branch-code">
                                    <?php echo htmlspecialchars((string)$row['branch_code'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td class="is-numeric"><?php echo (int)$row['pending']; ?></td>
                            <td class="is-numeric"><?php echo (int)$row['preparing']; ?></td>
                            <td class="is-numeric"><?php echo $waitingFolded; ?></td>
                            <td class="is-numeric"><?php echo (int)$row['delivering']; ?></td>
                            <td class="is-numeric"><?php echo kitchenMoney($row['revenue_7d']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <?php else: ?>

        <nav class="kitchen-tabs" role="tablist" aria-label="Order status tabs">
            <a href="kitchen.php?tab=new" class="kitchen-tab <?php echo $activeTab === 'new' ? 'active' : ''; ?>"
                role="tab" aria-selected="<?php echo $activeTab === 'new' ? 'true' : 'false'; ?>"
                aria-controls="panel-new" data-tab-link data-tab="new">
                <span>New</span>
                <span class="kitchen-tab-count" data-tab-count="new"><?php echo (int)$kitchenCounts['new']; ?></span>
            </a>

            <a href="kitchen.php?tab=preparing"
                class="kitchen-tab <?php echo $activeTab === 'preparing' ? 'active' : ''; ?>" role="tab"
                aria-selected="<?php echo $activeTab === 'preparing' ? 'true' : 'false'; ?>"
                aria-controls="panel-preparing" data-tab-link data-tab="preparing">
                <span>Preparing</span>
                <span class="kitchen-tab-count"
                    data-tab-count="preparing"><?php echo (int)$kitchenCounts['preparing']; ?></span>
            </a>

            <a href="kitchen.php?tab=waiting_on_rider"
                class="kitchen-tab <?php echo $activeTab === 'waiting_on_rider' ? 'active' : ''; ?>" role="tab"
                aria-selected="<?php echo $activeTab === 'waiting_on_rider' ? 'true' : 'false'; ?>"
                aria-controls="panel-waiting_on_rider" data-tab-link data-tab="waiting_on_rider">
                <span>Waiting on Rider</span>
                <span class="kitchen-tab-count"
                    data-tab-count="waiting_on_rider"><?php echo (int)$kitchenCounts['waiting_on_rider']; ?></span>
            </a>

            <a href="kitchen.php?tab=out_for_delivery"
                class="kitchen-tab <?php echo $activeTab === 'out_for_delivery' ? 'active' : ''; ?>" role="tab"
                aria-selected="<?php echo $activeTab === 'out_for_delivery' ? 'true' : 'false'; ?>"
                aria-controls="panel-out_for_delivery" data-tab-link data-tab="out_for_delivery">
                <span>Out for Delivery</span>
                <span class="kitchen-tab-count"
                    data-tab-count="out_for_delivery"><?php echo (int)$kitchenCounts['out_for_delivery']; ?></span>
            </a>

            <a href="kitchen.php?tab=recent" class="kitchen-tab <?php echo $activeTab === 'recent' ? 'active' : ''; ?>"
                role="tab" aria-selected="<?php echo $activeTab === 'recent' ? 'true' : 'false'; ?>"
                aria-controls="panel-recent" data-tab-link data-tab="recent">
                <span>Recent</span>
                <span class="kitchen-tab-count"
                    data-tab-count="recent"><?php echo (int)$kitchenCounts['recent']; ?></span>
            </a>
        </nav>

        <div class="kitchen-new-order-pill" id="kitchenNewOrderPill" hidden>
            <a href="kitchen.php?tab=<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>&page=1"
                class="kitchen-new-order-link">
                <img src="<?php echo $assetBase; ?>assets/images/icons/update.svg" alt="" class="kitchen-new-order-icon"
                    width="16" height="16"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/update.svg'">
                <span class="kitchen-new-order-text">
                    <strong id="kitchenNewOrderCount">0</strong>
                    new order<span id="kitchenNewOrderPlural">s</span> available
                </span>
                <span class="kitchen-new-order-cta">Refresh</span>
            </a>
        </div>

        <section class="kitchen-orders" id="kitchenOrderList"
            aria-label="<?php echo htmlspecialchars(kitchenTabTitle($activeTab), ENT_QUOTES, 'UTF-8'); ?>"
            data-active-tab="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>"
            data-active-page="<?php echo (int)$pagination['page']; ?>"
            data-total-pages="<?php echo (int)$pagination['totalPages']; ?>">

            <?php
            $panelOrders = $activeTab === 'recent' ? $completedOrders : $activeOrders;
            ?>

            <?php if (empty($panelOrders)):
                $emptyCopy = kitchenTabEmptyCopy($activeTab);
            ?>
            <div class="kitchen-empty-state"
                data-empty-tab="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>">
                <p class="kitchen-empty-title"><?php echo htmlspecialchars($emptyCopy['title'], ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <p class="kitchen-empty-text"><?php echo htmlspecialchars($emptyCopy['text'], ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>
            <?php else:
                foreach ($panelOrders as $order) {
                    echo kitchenCardHtml(
                        $order,
                        $assetBase,
                        $activeTab === 'recent'
                    );
                }
            endif; ?>

        </section>

        <?php
        echo kitchenPaginationHtml(
            $activeTab,
            (int)$pagination['page'],
            (int)$pagination['totalPages'],
            $assetBase
        );
        ?>

        <div class="kitchen-modal" id="riderModal" role="dialog" aria-modal="true" aria-labelledby="riderModalTitle">
            <div class="kitchen-modal-overlay" data-close-modal="rider-modal"></div>
            <div class="kitchen-modal-content">
                <div class="kitchen-modal-header">
                    <h2 class="heading-5" id="riderModalTitle">Assign a Rider</h2>
                    <button type="button" class="kitchen-modal-close" data-close-modal="rider-modal"
                        aria-label="Close">&times;</button>
                </div>

                <div class="kitchen-modal-body">
                    <input type="hidden" id="riderModalOrderId" value="">
                    <input type="hidden" id="riderModalAction" value="assign_rider">

                    <p class="kitchen-rider-modal-note" id="riderModalNote" hidden>
                        Currently assigned: <strong id="riderModalCurrentRider"></strong>
                    </p>

                    <?php
                    $shapedRiders = shapeAvailableRiderList($availableRiders);
                    ?>

                    <ul class="kitchen-rider-list" id="riderList">
                        <?php if (empty($shapedRiders)): ?>
                        <li class="kitchen-rider-empty">
                            No riders available right now. Everyone is offline or at the active-order cap.
                        </li>
                        <?php else: ?>
                        <?php foreach ($shapedRiders as $r): ?>
                        <li>
                            <button type="button" class="kitchen-rider-option"
                                data-rider-id="<?php echo (int)$r['rider_id']; ?>"
                                data-rider-name="<?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="kitchen-rider-name">
                                    <?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="kitchen-rider-meta">
                                    <?php echo htmlspecialchars($r['meta'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ($r['slot_label'] !== ''): ?>
                                    <span class="kitchen-rider-capacity">
                                        (<?php echo htmlspecialchars($r['slot_label'], ENT_QUOTES, 'UTF-8'); ?>)
                                    </span>
                                    <?php endif; ?>
                                </span>
                                <span class="kitchen-rider-rating">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/star-fill.svg" alt="Rating"
                                        width="14" height="14"
                                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/star-empty.svg'">
                                    <?php echo htmlspecialchars($r['rating'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </button>
                        </li>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>

                    <div id="riderModalFallback" hidden>
                        <?php if (empty($shapedRiders)): ?>
                        <div class="kitchen-rider-empty">
                            No riders available right now. Everyone is offline or at the active-order cap.
                        </div>
                        <?php else: ?>
                        <?php foreach ($shapedRiders as $r): ?>
                        <div class="kitchen-rider-option-fallback" data-rider-id="<?php echo (int)$r['rider_id']; ?>">
                            <span class="kitchen-rider-name">
                                <?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="kitchen-rider-meta">
                                <?php echo htmlspecialchars($r['meta'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($r['slot_label'] !== ''): ?>
                                <span class="kitchen-rider-capacity">
                                    (<?php echo htmlspecialchars($r['slot_label'], ENT_QUOTES, 'UTF-8'); ?>)
                                </span>
                                <?php endif; ?>
                            </span>
                            <span class="kitchen-rider-rating">
                                <?php echo htmlspecialchars($r['rating'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="kitchen-modal-footer">
                    <button type="button" class="btn btn-secondary" data-close-modal="rider-modal">Cancel</button>
                </div>
            </div>
        </div>

        <div class="kitchen-modal" id="confirmModal" role="dialog" aria-modal="true"
            aria-labelledby="confirmModalTitle">
            <div class="kitchen-modal-overlay" data-close-modal="confirm-modal"></div>
            <div class="kitchen-modal-content kitchen-confirm-content">

                <div class="kitchen-confirm-icon" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/error-warning-line.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
                </div>

                <h2 class="kitchen-confirm-title" id="confirmModalTitle">Cancel this order?</h2>

                <p class="kitchen-confirm-body" id="confirmModalMessage">
                    Are you sure?
                </p>

                <div class="kitchen-confirm-actions">
                    <button type="button" class="kitchen-btn-cancel" data-close-modal="confirm-modal">
                        Keep order
                    </button>
                    <button type="button" class="kitchen-btn-danger" id="confirmModalBtn">
                        Yes, cancel order
                    </button>
                </div>
            </div>
        </div>

        <?php
        require_once __DIR__ . '/../includes/chat-modal.php';
        ?>

        <?php endif; ?>
    </div>
</div>

<?php if (!$isOwner): ?>
<script src="../assets/ui/js/kitchen-order.js" defer></script>
<script src="../assets/ui/js/kitchen-realtime.js" defer></script>
<script src="../assets/ui/js/restaurant-chat-modal.js" defer></script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>