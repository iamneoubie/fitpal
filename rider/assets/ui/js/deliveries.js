/**
 * FitPal Rider Deliveries JavaScript
 *
 * Scope
 * -----
 * This file drives ONLY the delivery-status confirm modal on the
 * deliveries page:
 *
 *   - Accept Assignment   → confirm → rider-handler.php (accept_assignment)
 *   - Decline Assignment  → confirm → rider-handler.php (decline_assignment)
 *   - Mark Delivered      → confirm → rider-handler.php (delivered)
 *
 * Everything chat-related (open/close/tabs/send/read/delta poll)
 * is owned by rider/assets/ui/js/rider-chat-modal.js and is loaded
 * once per authenticated rider page by the shared include. This
 * file does not reference #riderChatModal, its tabs, its input, or
 * its handler at all.
 *
 * Tab behaviour
 * -------------
 * The three tabs on this page are real anchors (?tab=active|assigned|
 * history). Clicking one performs a server-side render of the target
 * tab, so no client-side tab switching is needed here. The panel
 * enter animation is a pure CSS concern; this file has no role in it.
 *
 * Confirm flow
 * ------------
 * Every .delivery-status-btn carries its own copy and presentation
 * hints in data-confirm-* attributes:
 *
 *   data-confirm-title     — the modal heading
 *   data-confirm-message   — the modal body
 *   data-confirm-label     — the confirm button label
 *   data-confirm-variant   — "primary" or "danger"
 *   data-confirm-icon      — "accept" | "decline" | "delivered"
 *
 * The click handler copies those onto #riderConfirmModal as
 * data-variant and data-icon. CSS reads the modal attributes and
 * picks the correct icon and button colour. The JS never swaps
 * classes on the button itself, so the button is never without a
 * valid style between frames.
 *
 * The confirm button is disabled while a request is in flight so a
 * double-click cannot fire two accept/decline/delivered POSTs.
 *
 * @package FitPal
 * @version 5.1 — No structural change. The revision to the page
 *                (three tabs, Active as default) does not touch the
 *                confirm-modal flow. The file is re-output here to
 *                confirm that the confirm handler still binds to
 *                .delivery-status-btn across all tabs — the
 *                delegated scan happens once on DOMContentLoaded
 *                and the buttons exist in the DOM regardless of
 *                which tab is active (only one panel renders at a
 *                time, but each panel's buttons are inside that
 *                panel's markup, so a full page load re-scans).
 *
 *                (5.0: chat handling removed; shared
 *                rider-chat-modal.js owns every chat behaviour.
 *                4.2: data-icon added. 4.1: confirm modal button
 *                colour driven by modal attributes.)
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var CFG = window.FITPAL_RIDER_DELIVERIES || {};
        var CSRF_TOKEN = CFG.csrfToken || '';

        // ============================================
        // CONFIRM MODAL
        // ============================================
        var confirmModal     = document.getElementById('riderConfirmModal');
        var confirmTitleEl   = document.getElementById('riderConfirmTitle');
        var confirmMessageEl = document.getElementById('riderConfirmMessage');
        var confirmBtn       = document.getElementById('riderConfirmBtn');

        // Callback stored while the modal is open. Null when closed.
        var pendingConfirm = null;

        function openConfirmModal(opts) {
            if (!confirmModal || !confirmBtn) return;

            if (confirmTitleEl)   confirmTitleEl.textContent   = opts.title   || 'Confirm';
            if (confirmMessageEl) confirmMessageEl.textContent = opts.message || '';

            confirmBtn.textContent = opts.label || 'Confirm';
            confirmBtn.disabled    = false;

            // Presentation is expressed entirely on the modal. CSS
            // reads these two attributes to pick the icon and the
            // confirm button colour.
            confirmModal.dataset.variant = opts.variant === 'danger' ? 'danger' : 'primary';
            confirmModal.dataset.icon    = opts.icon || 'accept';

            pendingConfirm = typeof opts.onConfirm === 'function' ? opts.onConfirm : null;

            document.body.style.overflow = 'hidden';
            confirmModal.style.display = 'flex';
            void confirmModal.offsetWidth;
            confirmModal.classList.add('is-open');

            setTimeout(function () { confirmBtn.focus(); }, 80);
        }

        function closeConfirmModal() {
            if (!confirmModal) return;

            confirmModal.classList.remove('is-open');
            setTimeout(function () {
                if (!confirmModal.classList.contains('is-open')) {
                    confirmModal.style.display = 'none';
                    document.body.style.overflow = '';
                    confirmModal.dataset.variant = 'primary';
                    confirmModal.dataset.icon    = 'accept';
                }
            }, 220);

            pendingConfirm = null;
        }

        if (confirmModal) {
            confirmModal.querySelectorAll('[data-close-confirm]').forEach(function (el) {
                el.addEventListener('click', closeConfirmModal);
            });
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                var fn = pendingConfirm;
                if (typeof fn !== 'function') {
                    closeConfirmModal();
                    return;
                }

                // Keep the modal open while the request runs so the
                // rider sees the button reflect the in-flight state.
                // The callback closes it on completion.
                confirmBtn.disabled    = true;
                confirmBtn.textContent = 'Processing…';

                fn(function done() {
                    closeConfirmModal();
                }, function failed() {
                    confirmBtn.disabled    = false;
                    confirmBtn.textContent = confirmBtn.dataset.originalLabel || 'Confirm';
                });
            });
        }

        // ============================================
        // DELIVERY STATUS UPDATES
        // ============================================
        var statusButtons = document.querySelectorAll('.delivery-status-btn');

        statusButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var orderId = parseInt(this.dataset.orderId, 10) || 0;
                var action  = this.dataset.action || '';

                if (orderId <= 0 || action === '') return;

                var title   = this.dataset.confirmTitle   || 'Confirm this action?';
                var message = this.dataset.confirmMessage || '';
                var label   = this.dataset.confirmLabel   || 'Confirm';
                var variant = this.dataset.confirmVariant || 'primary';
                var icon    = this.dataset.confirmIcon    || 'accept';

                var clickedButton = this;

                openConfirmModal({
                    title:   title,
                    message: message,
                    label:   label,
                    variant: variant,
                    icon:    icon,
                    onConfirm: function (done, failed) {
                        submitDeliveryAction(clickedButton, orderId, action, done, failed);
                    }
                });
            });
        });

        function submitDeliveryAction(btn, orderId, action, done, failed) {
            var originalText = btn.textContent;

            btn.disabled    = true;
            btn.textContent = 'Updating…';

            var body = new URLSearchParams();
            body.append('csrf_token', CSRF_TOKEN);
            body.append('action', action);
            body.append('order_id', String(orderId));

            fetch('../backend/handlers/rider-handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString(),
                credentials: 'same-origin'
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        showToast(data.message || 'Delivery updated', 'success');
                        // Close the modal first so the page behind it
                        // is not covered while we reload.
                        if (typeof done === 'function') done();
                        setTimeout(function () { window.location.reload(); }, 700);
                        return;
                    }

                    btn.disabled    = false;
                    btn.textContent = originalText;

                    showToast((data && data.message) || 'Could not update delivery', 'error');
                    if (typeof failed === 'function') failed();
                })
                .catch(function () {
                    btn.disabled    = false;
                    btn.textContent = originalText;

                    showToast('Network error. Please try again.', 'error');
                    if (typeof failed === 'function') failed();
                });
        }

        // ============================================
        // TOAST
        // ============================================
        function showToast(message, type) {
            var toast = document.getElementById('riderToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'riderToast';
                toast.style.cssText = [
                    'position:fixed', 'top:80px', 'right:20px',
                    'padding:12px 20px', 'border-radius:8px',
                    'font-size:14px', 'font-weight:500', 'z-index:9999',
                    'transform:translateX(120%)',
                    'transition:transform .3s cubic-bezier(.4,0,.2,1)',
                    'max-width:360px', 'box-shadow:0 4px 16px rgba(0,0,0,.15)'
                ].join(';');
                document.body.appendChild(toast);
            }

            var palette = {
                success: ['#d1fae5', '#065f46'],
                error:   ['#fee2e2', '#991b1b'],
                info:    ['#dbeafe', '#1e40af']
            };
            var colors = palette[type] || palette.info;
            toast.style.background = colors[0];
            toast.style.color      = colors[1];
            toast.textContent      = message;

            void toast.offsetWidth;
            toast.style.transform = 'translateX(0)';

            clearTimeout(toast._timer);
            toast._timer = setTimeout(function () {
                toast.style.transform = 'translateX(120%)';
            }, 2800);
        }

        // ============================================
        // KEYBOARD
        // ============================================
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (confirmModal && confirmModal.classList.contains('is-open')) {
                closeConfirmModal();
            }
        });

    });
})();