<?php
/**
 * FitPal Rider Chat Modal (shared include)
 *
 * The chat modal markup for the rider role. Rendered on every
 * authenticated rider page by rider/includes/header.php. Two tabs:
 * Customer and Kitchen.
 *
 * ---------------------------------------------------------------------
 * CONTRACT
 * ---------------------------------------------------------------------
 * The consumer must have, before requiring this include:
 *
 *   - $assetBase (string) — set by rider/includes/header.php.
 *
 * The modal itself is inert until JS opens it. Open/close/tabs/send/
 * read are owned by rider/assets/ui/js/rider-chat-modal.js and the
 * message endpoint the header exposes as
 * window.RIDER_CHAT_ENDPOINT.
 *
 * ---------------------------------------------------------------------
 * TRIGGERS
 * ---------------------------------------------------------------------
 * Any element on any authenticated rider page can open the modal by
 * carrying:
 *
 *   data-rider-chat-open
 *   data-rider-chat-order-id="<order_id>"
 *   data-rider-chat-recipient="customer|restaurant_account"
 *   data-rider-chat-subtitle="<display string>"   (optional)
 *
 * The two primary consumers are:
 *   - The deliveries page's Active tab, for orders in 'picking_up'
 *     (message kitchen) and 'delivering' (message customer).
 *   - The assignment panel, for the same two channels on live rows.
 *
 * ---------------------------------------------------------------------
 * WHEN THE MODAL IS NOT RENDERED
 * ---------------------------------------------------------------------
 * The modal is not rendered inside any specific order's markup; it
 * is chrome that lives once per page. The trigger that opens it is
 * what gets suppressed when an order is not messageable:
 *
 *   - A rider_pending order exposes only Accept and Decline in the
 *     panel and has no Message button. A rider who has not yet
 *     accepted an order is not a party to that order's conversation
 *     yet.
 *
 *   - A cancelled or refunded order exposes no Message button
 *     anywhere. The order was closed before the rider was ever
 *     assigned, or was closed by the restaurant before pickup.
 *
 *   - A failed order exposes no Message button either. The status
 *     is produced by the shared order-transaction layer's
 *     sweepFailedDeliveries() when a rider does not complete a
 *     delivery within FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS of
 *     the order entering 'delivering'. A failed order is terminal:
 *     the ledger is settled (the rider's accept-time debit is kept
 *     in place; no delivery credit was written) and neither the
 *     customer nor the kitchen has anything to say about it.
 *
 *   - A delivered order exposes the Message button only for the
 *     one-hour post-delivery grace window, matching the customer
 *     and restaurant sides. Once the window elapses, the deliveries
 *     page's History tab renders the delivered order without a
 *     Message action.
 *
 * The message handler enforces the same rule server-side: for a
 * failed order, the kitchen channel falls through to the "assigned
 * to you" refusal and the customer channel falls through to the
 * "after you have accepted" refusal. The two sides therefore agree
 * by construction.
 *
 * ---------------------------------------------------------------------
 * FALLBACK ICONS
 * ---------------------------------------------------------------------
 * Every icon reference carries an onerror fallback to a shared icon
 * that is guaranteed to exist, so a future icon rename cannot leave
 * the modal with a broken image.
 *
 * @package FitPal
 * @version 1.2 — Docblock records why a failed order never triggers
 *                this modal and how the client-side suppression
 *                agrees with the server-side refusal. No markup
 *                change from the previous revision.
 *
 *                (1.1: ensured $assetBase is available and added
 *                fallback for the close button icon. 1.0: initial
 *                modal.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('rider');
?>
<div id="riderChatModal" class="modal" style="display: none;" role="dialog" aria-modal="true"
    aria-labelledby="riderChatTitle">
    <div class="modal-overlay"></div>
    <div class="modal-content rider-chat-modal-content">
        <div class="modal-header">
            <div>
                <p class="heading-5 modal-title" id="riderChatTitle">Order Messages</p>
                <p class="modal-subtitle" id="riderChatSubtitle"></p>
            </div>
            <button type="button" class="modal-close" id="riderChatClose" aria-label="Close messages">&times;</button>
        </div>

        <div class="rider-chat-tabs" role="tablist">
            <button type="button" class="rider-chat-tab active" data-recipient="customer" role="tab"
                aria-selected="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt=""
                    class="rider-chat-tab-icon" width="16" height="16"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg'">
                <span>Customer</span>
            </button>
            <button type="button" class="rider-chat-tab" data-recipient="restaurant_account" role="tab"
                aria-selected="false">
                <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt=""
                    class="rider-chat-tab-icon" width="16" height="16"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant-fill.svg'">
                <span>Kitchen</span>
            </button>
        </div>

        <div class="rider-chat-body" id="riderChatMessages" role="log" aria-live="polite">
            <div class="rider-chat-loading"><span>Loading messages…</span></div>
        </div>

        <form class="rider-chat-form" id="riderChatForm">
            <input type="hidden" id="riderChatOrderId" value="">
            <input type="hidden" id="riderChatRecipient" value="customer">
            <input type="text" id="riderChatInput" class="rider-chat-input" placeholder="Type your message…"
                maxlength="500" autocomplete="off" required>
            <button type="submit" class="rider-chat-send" aria-label="Send message">
                <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt="Send"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-right-long-line.svg'">
            </button>
        </form>
    </div>
</div>