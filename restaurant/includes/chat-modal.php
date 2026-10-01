<?php
/**
 * FitPal Restaurant Chat Modal (shared include)
 *
 * The chat modal markup for the kitchen page. Rendered by
 * restaurant/pages/kitchen.php on the branch view. Two tabs:
 * Customer and Rider.
 *
 * ---------------------------------------------------------------------
 * CONTRACT
 * ---------------------------------------------------------------------
 * The consumer must have, before requiring this include:
 *
 *   - $assetBase (string) — set by restaurant/includes/header.php.
 *
 * The modal itself is inert until JS opens it. Open/close/tabs/send/
 * read are owned by restaurant/assets/ui/js/kitchen-realtime.js and
 * the chat handler endpoint the page exposes.
 *
 * ---------------------------------------------------------------------
 * TRIGGERS
 * ---------------------------------------------------------------------
 * Any element on the kitchen page can open the modal by carrying:
 *
 *   data-restaurant-chat-open
 *   data-restaurant-chat-order-id="<order_id>"
 *   data-restaurant-chat-counterparty="customer|delivery_rider"
 *   data-restaurant-chat-subtitle="<display string>"   (optional)
 *
 * The kitchen page renders one Message button per order card; the
 * rider tab inside the modal is disabled for orders that have no
 * rider attached, so the trigger only needs to say which channel
 * to open first.
 *
 * ---------------------------------------------------------------------
 * WHEN THE MODAL IS NOT RENDERED
 * ---------------------------------------------------------------------
 * The kitchen page decides per card whether the Message button is
 * emitted. Two cases suppress the button, and because the button is
 * the only trigger, the modal has no reason to exist for those
 * cards:
 *
 *   - A closed order. The 'cancelled', 'refunded', and 'failed'
 *     statuses all suppress the button because
 *     restaurantChatChannelStatus() reports both channels as closed
 *     for them.
 *
 *   - A delivered order past the one-hour chat grace window. The
 *     query layer's chat_grace_open flag is 0, so the button is
 *     suppressed and the customer and rider tabs are both closed.
 *
 * The 'failed' status is produced by the shared order-transaction
 * layer's sweepFailedDeliveries() when a rider does not complete a
 * delivery within FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS of the
 * order entering 'delivering'. The kitchen page treats it the same
 * way it treats 'cancelled' and 'refunded': the order is closed and
 * neither channel is reachable.
 *
 * The chat handler enforces the same rule server-side through
 * gateChannel(): even if a client somehow reached this modal for a
 * failed order, the send would be refused with the "this order is
 * closed" copy. The client-side suppression and the server-side
 * refusal therefore agree by construction.
 *
 * @package FitPal
 * @version 1.1 — Docblock records why a failed order never reaches
 *                this modal and how the client-side suppression
 *                agrees with the server-side gate. No markup change
 *                from the previous revision.
 *
 *                (1.0: initial modal.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('restaurant');

?>
<div id="restaurantChatModal" class="modal" style="display: none;" role="dialog" aria-modal="true"
    aria-labelledby="restaurantChatTitle">
    <div class="modal-overlay"></div>
    <div class="modal-content restaurant-chat-modal-content">
        <div class="modal-header">
            <div>
                <p class="heading-5 modal-title" id="restaurantChatTitle">Order Messages</p>
                <p class="modal-subtitle" id="restaurantChatSubtitle"></p>
            </div>
            <button type="button" class="modal-close" id="restaurantChatClose"
                aria-label="Close messages">&times;</button>
        </div>

        <div class="restaurant-chat-tabs" role="tablist">
            <button type="button" class="restaurant-chat-tab active" data-counterparty="customer" role="tab"
                aria-selected="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt=""
                    class="restaurant-chat-tab-icon" width="16" height="16"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg'">
                <span>Customer</span>
                <span class="restaurant-chat-tab-badge" id="restaurantChatCustomerBadge" style="display:none;">0</span>
            </button>
            <button type="button" class="restaurant-chat-tab" data-counterparty="delivery_rider" role="tab"
                aria-selected="false">
                <img src="<?php echo $assetBase; ?>assets/images/icons/riding-fill.svg" alt=""
                    class="restaurant-chat-tab-icon" width="16" height="16"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/car-fill.svg'">
                <span>Rider</span>
                <span class="restaurant-chat-tab-badge" id="restaurantChatRiderBadge" style="display:none;">0</span>
            </button>
        </div>

        <div class="restaurant-chat-body" id="restaurantChatMessages" role="log" aria-live="polite">
            <div class="restaurant-chat-loading"><span>Loading messages…</span></div>
        </div>

        <form class="restaurant-chat-form" id="restaurantChatForm">
            <input type="hidden" id="restaurantChatOrderId" value="">
            <input type="hidden" id="restaurantChatCounterparty" value="customer">
            <input type="text" id="restaurantChatInput" class="restaurant-chat-input" placeholder="Type your message…"
                maxlength="500" autocomplete="off" required>
            <button type="submit" class="restaurant-chat-send" aria-label="Send message">
                <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt="Send"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-right-long-line.svg'">
            </button>
        </form>
    </div>
</div>