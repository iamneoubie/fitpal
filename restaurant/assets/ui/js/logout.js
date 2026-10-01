/**
 * FitPal Restaurant Logout Confirmation + Active-Order Guard
 *
 * Two responsibilities, both scoped to the restaurant role.
 *
 * 1. LOGOUT CONFIRMATION
 *    Intercepts clicks on [data-logout-trigger] elements and opens
 *    the #logoutModal rendered by restaurant/includes/header.php.
 *    The modal's confirm anchor navigates to sign-out-handler.php;
 *    Cancel closes the modal.
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
 * ---------------------------------------------------------------------
 * WHAT CHANGED FROM v2.0
 * ---------------------------------------------------------------------
 * v2.0 ran the guard only when window.checkActiveOrders existed.
 * That function is published by kitchen-orders.js, which the kitchen page
 * loads and no other restaurant page does. The consequence was
 * that a branch staff member with live orders who opened
 * dashboard.php or profile.php saw the plain confirmation modal
 * and was able to sign out, bypassing the guard entirely.
 *
 * This revision reads the guard endpoint and the CSRF token from
 * two header-published globals:
 *
 *     window.RESTAURANT_SIGNOUT_GUARD_ENDPOINT
 *     window.RESTAURANT_CSRF_TOKEN
 *
 * The header emits both on every AUTHENTICATED branch page —
 * kitchen.php, dashboard.php, profile.php — so the guard now runs
 * everywhere it is supposed to. The window.checkActiveOrders
 * helper remains supported as a fallback so the kitchen page's
 * existing wiring keeps working if it is present.
 *
 * ---------------------------------------------------------------------
 * GUARD RESOLUTION ORDER
 * ---------------------------------------------------------------------
 * On each logout click the guard resolves its transport in this
 * order:
 *
 *   1. If window.checkActiveOrders is a function, use it. This
 *      preserves the kitchen page's existing behavior exactly.
 *
 *   2. Otherwise, if both header globals are present, POST
 *      action=active_orders_count to the guard endpoint, with the
 *      CSRF token in the body, and read the JSON response.
 *
 *   3. Otherwise, skip the guard and open the confirmation modal.
 *      This is the unauthenticated-page case — the header does not
 *      emit the globals there, and there is nothing to guard.
 *
 * ---------------------------------------------------------------------
 * FAIL-OPEN POLICY
 * ---------------------------------------------------------------------
 * If the server is unreachable, or returns a non-JSON body, or
 * returns a non-2xx status, or the endpoint is somehow not set,
 * the guard cannot decide. This file follows the rider and
 * customer precedent: do not block the user on an infrastructure
 * failure. The guard fires only when the server explicitly says
 * has_active is true.
 *
 * A null callback result — network error, non-JSON response, or an
 * unset helper — opens the confirmation modal as before.
 *
 * ---------------------------------------------------------------------
 * BLOCKING MODAL
 * ---------------------------------------------------------------------
 * The header renders #restaurantBlockSignOutModal on every
 * authenticated branch page. This file uses that element when it
 * is present, so the modal's markup lives in the page shell
 * alongside the confirmation modal, and both share the same
 * visual language.
 *
 * If the header did not render it — for example, on a page that
 * was rendered before the header revision landed — this file
 * builds an equivalent element on first need and caches it on
 * window.__fitpalRestaurantBlockModal. Both paths write the guard
 * message into #restaurantBlockSignOutText.
 *
 * The blocking modal's icon is an <img> pointing at an existing
 * shared icon file; no hard-coded <svg> is used. Its single OK
 * button is the shared .logout-btn-cancel style, which the logout
 * stylesheet already paints as a neutral control.
 *
 * ---------------------------------------------------------------------
 * NO window.alert, window.confirm, OR window.prompt
 * ---------------------------------------------------------------------
 * Every message goes through a modal. Every failure is surfaced in
 * the UI, never through a browser dialog.
 *
 * @package FitPal
 * @version 3.0 — Guard now runs on every authenticated branch page:
 *                  - Reads window.RESTAURANT_SIGNOUT_GUARD_ENDPOINT
 *                    and window.RESTAURANT_CSRF_TOKEN when present.
 *                  - POSTs action=active_orders_count and reads the
 *                    JSON response directly.
 *                  - Keeps window.checkActiveOrders as a fallback
 *                    so the kitchen page keeps working unchanged.
 *                  - Uses the header-rendered
 *                    #restaurantBlockSignOutModal when it exists,
 *                    and only builds one dynamically as a fallback.
 *
 *                (2.0: added the active-order guard, scoped to the
 *                kitchen page. 1.0: initial logout handler.)
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
            if (confirmBtn) {
                setTimeout(function () { confirmBtn.focus(); }, 80);
            }
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
           GUARD TRANSPORT
           Resolves a callback-style answer to
           "does this branch have live orders?".
           ============================================================ */

        /**
         * Read the header-published globals. Returns null when the
         * page is not an authenticated branch page and the header
         * did not emit them.
         */
        function readGuardConfig() {
            var endpoint = window.RESTAURANT_SIGNOUT_GUARD_ENDPOINT;
            var token    = window.RESTAURANT_CSRF_TOKEN;

            if (typeof endpoint !== 'string' || endpoint === '') return null;
            if (typeof token !== 'string' || token === '') return null;

            return { endpoint: endpoint, token: token };
        }

        /**
         * Ask the server how many live orders the branch has.
         *
         * Calls callback with one of:
         *     { has_active: true,  count: N }  → block
         *     { has_active: false, count: 0 }  → allow
         *     null                             → cannot decide, allow
         *
         * A null result is never treated as "blocked". The user is
         * never held hostage by a network error.
         */
        function fetchActiveOrderCount(callback) {
            var config = readGuardConfig();
            if (!config) {
                callback(null);
                return;
            }

            var body = new URLSearchParams();
            body.append('action', 'active_orders_count');
            body.append('csrf_token', config.token);

            fetch(config.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: body.toString()
            })
            .then(function (response) {
                if (!response.ok) {
                    // Non-2xx: fail open. Do not attempt to parse.
                    return null;
                }
                return response.json().catch(function () { return null; });
            })
            .then(function (payload) {
                if (!payload || payload.status !== 'success') {
                    callback(null);
                    return;
                }

                var count = parseInt(payload.count, 10);
                if (isNaN(count) || count < 0) {
                    callback(null);
                    return;
                }

                callback({
                    has_active: payload.has_active === true || count > 0,
                    count: count
                });
            })
            .catch(function () {
                // Network error, CORS, abort: fail open.
                callback(null);
            });
        }

        /**
         * Resolve the guard for a single logout click.
         *
         * Prefers window.checkActiveOrders when it is a function so
         * the kitchen page keeps the exact behavior it had before
         * this revision. Otherwise falls through to the header
         * globals. Otherwise skips the guard entirely.
         */
        function runGuard(callback) {
            if (typeof window.checkActiveOrders === 'function') {
                try {
                    window.checkActiveOrders(function (result) {
                        callback(result || null);
                    });
                } catch (err) {
                    callback(null);
                }
                return;
            }

            var config = readGuardConfig();
            if (!config) {
                callback(null);
                return;
            }

            fetchActiveOrderCount(callback);
        }

        /* ============================================================
           TRIGGER DISPATCH
           Every [data-logout-trigger] first asks the guard.
           ============================================================ */

        triggers.forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();

                var config = readGuardConfig();
                var hasKitchenHelper = typeof window.checkActiveOrders === 'function';

                if (!hasKitchenHelper && !config) {
                    // No transport available. Open the confirmation
                    // modal directly, matching pre-guard behavior.
                    openModal();
                    return;
                }

                // Prevent a rapid double-click from firing two
                // guard requests in flight at once.
                if (trigger.dataset.guardPending === '1') return;
                trigger.dataset.guardPending = '1';

                runGuard(function (result) {
                    trigger.dataset.guardPending = '';

                    if (result && result.has_active && result.count > 0) {
                        openBlockModal(result.count);
                        return;
                    }

                    // result is null (network error / no helper) OR
                    // has_active is false. Fail open.
                    openModal();
                });
            });
        });

        /* ============================================================
           BLOCKING MODAL
           Prefers the header-rendered #restaurantBlockSignOutModal.
           Falls back to building one on first need and caching it
           on window so repeated triggers do not stack copies.
           ============================================================ */

        var HEADER_BLOCK_MODAL_ID  = 'restaurantBlockSignOutModal';
        var FALLBACK_BLOCK_MODAL_ID = 'restaurantBlockLogoutModal';
        var BLOCK_TEXT_ID          = 'restaurantBlockSignOutText';
        var FALLBACK_BLOCK_TEXT_ID = 'restaurantBlockLogoutText';

        function ensureBlockModal() {
            // 1. Header-rendered modal, if present.
            var headerModal = document.getElementById(HEADER_BLOCK_MODAL_ID);
            if (headerModal) {
                window.__fitpalRestaurantBlockModal = headerModal;
                return headerModal;
            }

            // 2. Previously-built fallback.
            if (window.__fitpalRestaurantBlockModal) {
                return window.__fitpalRestaurantBlockModal;
            }

            // 3. Existing fallback in the DOM.
            var existing = document.getElementById(FALLBACK_BLOCK_MODAL_ID);
            if (existing) {
                window.__fitpalRestaurantBlockModal = existing;
                return existing;
            }

            // 4. Build the fallback.

            var assetBase = '../../shared/';
            var kitchenPage = document.getElementById('kitchenPage');
            if (kitchenPage && kitchenPage.dataset.assetBase) {
                assetBase = kitchenPage.dataset.assetBase;
            }

            var wrapper = document.createElement('div');
            wrapper.id = FALLBACK_BLOCK_MODAL_ID;
            wrapper.className = 'logout-modal';
            wrapper.style.display = 'none';
            wrapper.setAttribute('role', 'dialog');
            wrapper.setAttribute('aria-modal', 'true');
            wrapper.setAttribute('aria-labelledby', 'restaurantBlockLogoutTitle');

            var overlay = document.createElement('div');
            overlay.className = 'logout-modal-overlay';
            overlay.setAttribute('data-block-logout-cancel', '');
            wrapper.appendChild(overlay);

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
            text.id = FALLBACK_BLOCK_TEXT_ID;
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

        function writeBlockMessage(textEl, count) {
            if (!textEl) return;

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

        function openBlockModal(activeCount) {
            var blockModal = ensureBlockModal();
            if (!blockModal) return;

            var count = parseInt(activeCount, 10) || 0;

            // Prefer the header's text node. Fall back to the
            // fallback's text node.
            var textEl = blockModal.querySelector('#' + BLOCK_TEXT_ID)
                      || blockModal.querySelector('#' + FALLBACK_BLOCK_TEXT_ID);

            writeBlockMessage(textEl, count);

            document.body.style.overflow = 'hidden';
            blockModal.style.display = 'flex';
            void blockModal.offsetWidth;
            blockModal.classList.add('active');

            if (blockModal.dataset.handlersBound !== '1') {
                blockModal.dataset.handlersBound = '1';

                blockModal
                    .querySelectorAll('[data-block-logout-cancel], [data-block-signout-cancel]')
                    .forEach(function (node) {
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