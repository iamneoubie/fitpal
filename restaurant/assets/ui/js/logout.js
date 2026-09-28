/**
 * FitPal Restaurant Logout Confirmation + Active-Order Guard
 *
 * Two responsibilities, both scoped to the restaurant role.
 *
 * 1. LOGOUT CONFIRMATION
 *    Intercepts clicks on [data-logout-trigger] elements and opens
 *    the #logoutModal rendered by restaurant/includes/header.php.
 *    The modal's confirm anchor navigates to sign-out-handler.php;
 *    Cancel closes the modal. Identical in behaviour to the
 *    customer and rider logout handlers.
 *
 * 2. ACTIVE-ORDER GUARD
 *    Before opening the confirmation modal, this file asks the
 *    kitchen handler whether the branch still has live orders.
 *    A live order is anything in one of these statuses:
 *
 *        pending, preparing, rider_pending, picking_up, delivering
 *
 *    If the branch has at least one, the confirmation modal is NOT
 *    opened. Instead, a blocking modal is shown that names the
 *    count of active orders and refuses the sign-out. There is a
 *    single OK button; there is nothing to cancel.
 *
 *    The guard is only active on pages where
 *    window.checkActiveOrders is defined — that is, the branch
 *    kitchen page (see restaurant/assets/ui/js/orders.js). On any
 *    other restaurant page the guard is skipped and the
 *    confirmation modal opens as before. This keeps the guard
 *    scoped to the page where it matters and avoids a spurious
 *    network call from every other page that carries a logout
 *    button.
 *
 * Fail-open policy
 * ----------------
 * If the server is unreachable, the guard cannot decide. This
 * file follows the rider and customer precedent: do not block the
 * user on an infrastructure failure. The guard fires only when the
 * server explicitly says has_active is true. A null callback
 * result — network error, non-JSON response, or an unset helper —
 * opens the confirmation modal as before.
 *
 * Blocking modal structure
 * ------------------------
 * The blocking modal is built once on first need and cached on
 * window. It uses the same overlay + content shape as the logout
 * modal so the visual language is consistent. Its icon is an <img>
 * whose src points at an existing shared icon file; no hard-coded
 * <svg> is used. Its single OK button is neutral (black background,
 * white text) per the general button rules. The button carries a
 * data-close-block-modal attribute so the open / close handlers
 * can be wired once.
 *
 * No window.alert, confirm, or prompt. Every message goes through
 * a modal.
 *
 * @package FitPal
 * @version 2.0 — Adds the active-order guard:
 *                  - Before opening #logoutModal, calls
 *                    window.checkActiveOrders() when it exists.
 *                  - If the branch has live orders, opens a
 *                    blocking modal with the count and a single
 *                    OK button. Does not open #logoutModal.
 *                  - If the guard cannot decide (no helper, or
 *                    the server is unreachable), opens
 *                    #logoutModal as before.
 *                  - Blocking modal is built once and cached on
 *                    window.__fitpalRestaurantBlockModal.
 *                  - Same Escape / overlay / cancel semantics as
 *                    the existing confirmation modal.
 *
 *                (1.0: initial logout handler.)
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var modal = document.getElementById('logoutModal');
        if (!modal) return;

        var confirmBtn  = modal.querySelector('.logout-btn-confirm');
        var cancelNodes = modal.querySelectorAll('[data-logout-cancel]');
        var triggers    = document.querySelectorAll('[data-logout-trigger]');

        /* ============================================================
           CONFIRMATION MODAL
           ============================================================ */

        function openModal() {
            document.body.style.overflow = 'hidden';
            modal.style.display = 'flex';
            void modal.offsetWidth;
            modal.classList.add('active');
            if (confirmBtn) setTimeout(function () { confirmBtn.focus(); }, 80);
        }

        function closeModal() {
            modal.classList.remove('active');
            setTimeout(function () {
                if (!modal.classList.contains('active')) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }, 220);
        }

        cancelNodes.forEach(function (node) {
            node.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (modal.classList.contains('active')) {
                closeModal();
            }
            var blockModal = window.__fitpalRestaurantBlockModal;
            if (blockModal && blockModal.classList.contains('active')) {
                closeBlockModal();
            }
        });

        /* ============================================================
           TRIGGER DISPATCH
           Every [data-logout-trigger] first asks the guard. If the
           guard says "yes, blocked", the blocking modal opens. If
           the guard says "no" or "cannot decide", the confirmation
           modal opens.
           ============================================================ */

        triggers.forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();

                if (typeof window.checkActiveOrders !== 'function') {
                    // Not on a page that supports the guard. Open
                    // the confirmation modal directly.
                    openModal();
                    return;
                }

                // Prevent rapid double-clicks from firing two
                // guard requests in flight at once.
                if (trigger.dataset.guardPending === '1') return;
                trigger.dataset.guardPending = '1';

                window.checkActiveOrders(function (result) {
                    trigger.dataset.guardPending = '';

                    if (result && result.has_active && result.count > 0) {
                        openBlockModal(result.count);
                        return;
                    }

                    // result is null (network error) OR has_active is
                    // false. Fail open: let the user sign out.
                    openModal();
                });
            });
        });

        /* ============================================================
           BLOCKING MODAL
           Built once on first need, cached on window so repeated
           triggers do not stack copies. Uses the same visual
           language as the confirmation modal.
           ============================================================ */

        var BLOCK_MODAL_ID = 'restaurantBlockLogoutModal';

        function ensureBlockModal() {
            if (window.__fitpalRestaurantBlockModal) {
                return window.__fitpalRestaurantBlockModal;
            }

            var existing = document.getElementById(BLOCK_MODAL_ID);
            if (existing) {
                window.__fitpalRestaurantBlockModal = existing;
                return existing;
            }

            // Reuse the asset base the page published. Falls back to
            // the shared path from the restaurant pages directory.
            var assetBase = '';
            var kitchenPage = document.getElementById('kitchenPage');
            if (kitchenPage && kitchenPage.dataset.assetBase) {
                assetBase = kitchenPage.dataset.assetBase;
            } else {
                assetBase = '../../shared/';
            }

            var wrapper = document.createElement('div');
            wrapper.id = BLOCK_MODAL_ID;
            wrapper.className = 'logout-modal';
            wrapper.style.display = 'none';
            wrapper.setAttribute('role', 'dialog');
            wrapper.setAttribute('aria-modal', 'true');
            wrapper.setAttribute('aria-labelledby', 'restaurantBlockLogoutTitle');

            // Overlay
            var overlay = document.createElement('div');
            overlay.className = 'logout-modal-overlay';
            overlay.setAttribute('data-block-logout-cancel', '');
            wrapper.appendChild(overlay);

            // Content
            var content = document.createElement('div');
            content.className = 'logout-modal-content';

            var iconWrap = document.createElement('div');
            iconWrap.className = 'logout-modal-icon';
            iconWrap.setAttribute('aria-hidden', 'true');

            var icon = document.createElement('img');
            icon.src = assetBase + 'assets/images/icons/error-warning-line.svg';
            icon.alt = '';
            icon.width = 28;
            icon.height = 28;
            icon.onerror = function () {
                this.onerror = null;
                this.src = assetBase + 'assets/images/icons/information-fill.svg';
            };
            iconWrap.appendChild(icon);
            content.appendChild(iconWrap);

            var title = document.createElement('p');
            title.className = 'logout-modal-title';
            title.id = 'restaurantBlockLogoutTitle';
            title.textContent = "You can't sign out right now";
            content.appendChild(title);

            var text = document.createElement('p');
            text.className = 'logout-modal-text';
            text.id = 'restaurantBlockLogoutText';
            content.appendChild(text);

            var actions = document.createElement('div');
            actions.className = 'logout-modal-actions';

            var okBtn = document.createElement('button');
            okBtn.type = 'button';
            okBtn.className = 'logout-btn-cancel';
            okBtn.setAttribute('data-block-logout-cancel', '');
            okBtn.textContent = 'OK';
            actions.appendChild(okBtn);

            content.appendChild(actions);
            wrapper.appendChild(content);

            document.body.appendChild(wrapper);

            window.__fitpalRestaurantBlockModal = wrapper;
            return wrapper;
        }

        function openBlockModal(activeCount) {
            var blockModal = ensureBlockModal();
            if (!blockModal) return;

            var count   = parseInt(activeCount, 10) || 0;
            var textEl  = blockModal.querySelector('#restaurantBlockLogoutText');

            if (textEl) {
                if (count === 1) {
                    textEl.textContent =
                        'You have 1 active order. Finish it before signing out, '
                        + 'or hand it off to another staff member.';
                } else {
                    textEl.textContent =
                        'You have ' + count + ' active orders. Finish them before '
                        + 'signing out, or hand them off to another staff member.';
                }
            }

            document.body.style.overflow = 'hidden';
            blockModal.style.display = 'flex';
            void blockModal.offsetWidth;
            blockModal.classList.add('active');

            // Wire the close handlers the first time this modal is
            // opened. Guarded so re-opening does not re-bind.
            if (blockModal.dataset.handlersBound !== '1') {
                blockModal.dataset.handlersBound = '1';

                blockModal.querySelectorAll('[data-block-logout-cancel]').forEach(function (node) {
                    node.addEventListener('click', function (e) {
                        e.preventDefault();
                        closeBlockModal();
                    });
                });
            }
        }

        function closeBlockModal() {
            var blockModal = window.__fitpalRestaurantBlockModal;
            if (!blockModal) return;

            blockModal.classList.remove('active');
            setTimeout(function () {
                if (!blockModal.classList.contains('active')) {
                    blockModal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }, 220);
        }

        /* ============================================================
           BACK / FORWARD CACHE
           If the browser restores the page from bfcache after the
           user navigated away, close any modal that was left open
           so the page does not come back in a half-modal state.
           ============================================================ */

        window.addEventListener('pageshow', function (e) {
            if (!e.persisted) return;
            if (modal.classList.contains('active')) {
                closeModal();
            }
            var blockModal = window.__fitpalRestaurantBlockModal;
            if (blockModal && blockModal.classList.contains('active')) {
                closeBlockModal();
            }
        });
    });
})();