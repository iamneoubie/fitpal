/**
 * FitPal Restaurant Kitchen JavaScript
 *
 * Handles:
 *   - Tab filtering (pending / preparing / all) — client-side hide
 *   - Order action buttons: start_preparing, cancel_order,
 *     mark_delivering
 *   - Assign-rider modal: open, choose a rider, submit
 *   - Generic confirm modal for destructive actions
 *
 * No CSS is defined here. No DOM classes are invented here. The
 * page's already-rendered state is the only source of visuals.
 * Errors are surfaced with window.alert; success reloads the page
 * so the server re-renders counts, tabs, and cards.
 *
 * The owner view does not run any interactive path: the page sets
 * data-scope="owner", and initialize() returns early.
 *
 * @package FitPal
 * @version 1.1 — Toast removed. No new CSS classes. Errors use
 *                window.alert. Success reloads.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var page = document.getElementById('kitchenPage');
        if (!page) return;

        var SCOPE       = page.dataset.scope || 'branch';
        var CSRF_TOKEN  = page.dataset.csrfToken || '';
        var HANDLER_URL = page.dataset.handlerUrl || '../backend/handlers/order-handler.php';

        if (SCOPE !== 'branch') {
            // Owner view has no interactive elements.
            return;
        }

        /* ============================================
           DOM REFERENCES
           ============================================ */
        var tabs           = document.querySelectorAll('.kitchen-tab');
        var orderList      = document.getElementById('kitchenOrderList');
        var riderModal     = document.getElementById('riderModal');
        var riderListEl    = document.getElementById('riderList');
        var riderOrderIdEl = document.getElementById('riderModalOrderId');
        var confirmModal   = document.getElementById('confirmModal');
        var confirmMsgEl   = document.getElementById('confirmModalMessage');
        var confirmBtn     = document.getElementById('confirmModalBtn');

        var pendingAction = null; // { orderId, action }
        var isSubmitting  = false;

        /* ============================================
           TAB FILTERING
           ============================================ */
        function applyFilter(filter) {
            if (!orderList) return;

            var cards = orderList.querySelectorAll('.kitchen-order-card');
            cards.forEach(function (card) {
                var status = card.dataset.orderStatus || '';
                var show;

                if (filter === 'all') {
                    show = (status === 'pending' || status === 'preparing');
                } else {
                    show = (status === filter);
                }

                card.classList.toggle('is-hidden', !show);
            });
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var filter = this.dataset.filter || 'pending';

                tabs.forEach(function (t) {
                    var isActive = t === tab;
                    t.classList.toggle('active', isActive);
                    t.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });

                applyFilter(filter);
            });
        });

        var initialTab = document.querySelector('.kitchen-tab.active');
        if (initialTab) {
            applyFilter(initialTab.dataset.filter || 'pending');
        }

        /* ============================================
           MODAL HELPERS
           ============================================ */
        function openModal(modal) {
            if (!modal) return;
            document.body.style.overflow = 'hidden';
            modal.style.display = 'flex';
            void modal.offsetWidth;
            modal.classList.add('is-open');
        }

        function closeModal(modal) {
            if (!modal) return;
            modal.classList.remove('is-open');
            setTimeout(function () {
                if (!modal.classList.contains('is-open')) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }, 200);
        }

        document.querySelectorAll('[data-close-modal]').forEach(function (el) {
            el.addEventListener('click', function () {
                var target = this.dataset.closeModal;
                if (target === 'rider-modal')   closeModal(riderModal);
                if (target === 'confirm-modal') closeModal(confirmModal);
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (riderModal && riderModal.classList.contains('is-open'))     closeModal(riderModal);
            if (confirmModal && confirmModal.classList.contains('is-open')) closeModal(confirmModal);
        });

        /* ============================================
           ACTION BUTTON DISPATCH
           ============================================ */
        if (orderList) {
            orderList.addEventListener('click', function (e) {
                var btn = e.target.closest('.kitchen-action-btn');
                if (!btn || btn.disabled) return;

                var action  = btn.dataset.action || '';
                var orderId = parseInt(btn.dataset.orderId, 10) || 0;
                if (orderId <= 0) return;

                if (action === 'assign_rider') {
                    openRiderModal(orderId);
                    return;
                }

                if (action === 'cancel_order') {
                    askConfirm(
                        orderId,
                        'cancel_order',
                        'Cancel this order? The customer will be notified and the order cannot be restored.'
                    );
                    return;
                }

                if (action === 'start_preparing') {
                    submitAction(orderId, 'start_preparing', {});
                    return;
                }

                if (action === 'mark_delivering') {
                    submitAction(orderId, 'mark_delivering', {});
                    return;
                }
            });
        }

        /* ============================================
           CONFIRM MODAL
           ============================================ */
        function askConfirm(orderId, action, message) {
            pendingAction = { orderId: orderId, action: action };
            if (confirmMsgEl) confirmMsgEl.textContent = message;
            openModal(confirmModal);
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                if (!pendingAction) {
                    closeModal(confirmModal);
                    return;
                }
                var act = pendingAction;
                pendingAction = null;
                closeModal(confirmModal);
                submitAction(act.orderId, act.action, {});
            });
        }

        /* ============================================
           RIDER MODAL
           ============================================ */
        function openRiderModal(orderId) {
            if (riderOrderIdEl) riderOrderIdEl.value = String(orderId);
            openModal(riderModal);
        }

        if (riderListEl) {
            riderListEl.addEventListener('click', function (e) {
                var option = e.target.closest('.kitchen-rider-option');
                if (!option || option.disabled) return;

                var riderId = parseInt(option.dataset.riderId, 10) || 0;
                var orderId = parseInt(
                    riderOrderIdEl ? riderOrderIdEl.value : '0',
                    10
                ) || 0;

                if (riderId <= 0 || orderId <= 0) return;

                option.disabled = true;
                closeModal(riderModal);

                submitAction(orderId, 'assign_rider', { rider_id: riderId });
            });
        }

        /* ============================================
           SUBMIT
           ============================================ */
        function submitAction(orderId, action, extraFields) {
            if (isSubmitting) return;
            isSubmitting = true;

            var card = orderList
                ? orderList.querySelector('.kitchen-order-card[data-order-id="' + orderId + '"]')
                : null;

            if (card) card.classList.add('is-updating');

            var body = new URLSearchParams();
            body.append('csrf_token', CSRF_TOKEN);
            body.append('action', action);
            body.append('order_id', String(orderId));

            if (extraFields) {
                Object.keys(extraFields).forEach(function (key) {
                    body.append(key, String(extraFields[key]));
                });
            }

            fetch(HANDLER_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString(),
                credentials: 'same-origin'
            })
                .then(function (res) {
                    return res.text().then(function (text) {
                        try {
                            return JSON.parse(text);
                        } catch (err) {
                            console.error('[kitchen] Non-JSON response:', res.status, text.slice(0, 200));
                            return { status: 'error', message: 'Server returned an unexpected response.' };
                        }
                    });
                })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        setTimeout(function () { window.location.reload(); }, 300);
                        return;
                    }

                    if (card) card.classList.remove('is-updating');
                    isSubmitting = false;
                    window.alert((data && data.message) || 'Could not complete the action.');
                })
                .catch(function (err) {
                    console.error('[kitchen] Action failed:', err);
                    if (card) card.classList.remove('is-updating');
                    isSubmitting = false;
                    window.alert('Network error. Please try again.');
                });
        }
    });
})();