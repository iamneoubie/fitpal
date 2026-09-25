<?php
/**
 * FitPal Rider Chat Modal (shared include)
 *
 * @package FitPal
 * @version 1.1 — Ensured $assetBase is available and added
 *                fallback for the close button icon.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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