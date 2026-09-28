/**
 * FitPal Restaurant Kitchen JavaScript
 *
 * Wires the kitchen page's interactive surfaces:
 *
 *   - The Start Preparing, Cancel, Assign Rider, and Reassign Rider
 *     buttons. Each posts to order-handler.php, then reloads the
 *     page so the tab counts and the tab the order belongs to stay
 *     accurate.
 *   - The generic confirm modal for destructive actions.
 *   - The rider-selection modal, whose roster is fetched fresh from
 *     the server on open and polled while the modal stays open.
 *   - The card collapse toggle. Each kitchen card is three bands:
 *     a header, a summary, and a details body that is collapsed by
 *     default. The chevron in the header toggles the details body.
 *   - The sign-out guard helper: checkActiveOrders() posts to
 *     order-handler.php's active_orders_count action and calls back
 *     with the result. logout.js uses this to refuse sign-out
 *     while live orders are open.
 *
 * Card collapse state
 * -------------------
 * This file owns the USER INTERACTION for expanding and collapsing
 * a card. It does NOT own preserving that state across a poll —
 * kitchen-realtime.js now snapshots the expanded set from the DOM
 * before each poll's mutations and reapplies it afterward, so this
 * file never needs to remember anything.
 *
 * This file's role is exactly two click listeners: one for the
 * chevron toggle button, one for the header band. Both toggle the
 * .is-expanded class and the details band's hidden attribute in
 * direct response to the user's click. Nothing else.
 *
 * Realtime rider roster
 * ---------------------
 * The modal's roster is the only thing in this file that polls.
 * The kitchen's live order list is owned by kitchen-realtime.js
 * and this file does not touch it.
 *
 * The roster poll is deliberately simple:
 *
 *   - Started when the modal opens.
 *   - Stopped when the modal closes, when the page begins a
 *     reload, or when the tab is hidden.
 *   - One immediate fetch when the tab returns to foreground,
 *     then the interval resumes.
 *   - Every fetch goes through order-handler.php with the same
 *     csrf_token the rest of the file uses.
 *   - On open, the modal shows the server-rendered fallback until
 *     the first fetch lands. Once a fetch succeeds, the fallback
 *     is replaced. A fetch that fails leaves the fallback in
 *     place — the modal is never blank.
 *
 * Signature guard
 * ---------------
 * A poll that returns the same rider_id set as the previous poll
 * does NOT re-render the list. This is what keeps an idle modal
 * from flickering every four seconds. The guard compares a sorted
 * join of the ids in the current DOM against a sorted join of the
 * ids in the new payload. Only a difference (a rider added or
 * removed) triggers a re-render.
 *
 * Not owned by this file
 * ----------------------
 *   - Live order polling and the new-order pill are owned by
 *     kitchen-realtime.js.
 *   - The chat modal is owned by kitchen-realtime.js.
 *   - Tab switching is a server-side navigation via anchor links.
 *     This file never hides or shows cards.
 *
 * Config
 * ------
 * Every value this file needs is read from data-* attributes on
 * #kitchenPage, which kitchen.php sets from the session. This file
 * does not read window globals set by an inline <script>.
 *
 * No CSS is defined here. No DOM markup is fabricated with a
 * hard-coded SVG — the roster rows use the same <img> icons the
 * server-rendered fallback does. No window.alert / confirm /
 * prompt — every message goes through a modal or a toast.
 *
 * @package FitPal
 * @version 9.0 — Removed all logic related to preserving card
 *                collapse state across a poll.
 *                  - No `expandedIds` Set.
 *                  - No `window.FITPAL_KITCHEN_CARD_STATE` hook.
 *                  - No `applyExpandedState()`.
 *                  - No `forgetCard()`.
 *                  - No reapply after poll.
 *                kitchen-realtime.js now owns preserving the
 *                expanded state via a snapshot/reapply pair taken
 *                directly from the DOM around its own mutation
 *                phase. This file's only responsibility for the
 *                card collapse is the user's click.
 *
 *                (8.0: introduced expandedIds and the card state
 *                hook. 7.0: realtime rider roster in the
 *                assignment modal. 6.0: full rewrite for the
 *                paginated, step-by-step kitchen page. 5.0:
 *                order-handler polling moved out. 4.1: data-icon on
 *                the confirm modal. 4.0: chat moved to
 *                kitchen-realtime.js. 3.0: initial kitchen JS.)
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var page = document.getElementById('kitchenPage');
        if (!page) return;

        var SCOPE       = page.dataset.scope || 'branch';
        var CSRF_TOKEN  = page.dataset.csrfToken || '';
        var HANDLER_URL = page.dataset.handlerUrl || '../backend/handlers/order-handler.php';
        var BRANCH_ID   = parseInt(page.dataset.branchId, 10) || 0;

        // Owner view has no interactive elements. Exit before wiring
        // anything.
        if (SCOPE !== 'branch') {
            return;
        }

        /* ============================================
           DOM REFERENCES
           ============================================ */

        var orderList      = document.getElementById('kitchenOrderList');
        var riderModal     = document.getElementById('riderModal');
        var riderListEl    = document.getElementById('riderList');
        var riderOrderIdEl = document.getElementById('riderModalOrderId');
        var riderActionEl  = document.getElementById('riderModalAction');
        var riderNoteEl    = document.getElementById('riderModalNote');
        var riderCurrentEl = document.getElementById('riderModalCurrentRider');
        var riderTitleEl   = document.getElementById('riderModalTitle');
        var confirmModal   = document.getElementById('confirmModal');
        var confirmMsgEl   = document.getElementById('confirmModalMessage');
        var confirmBtn     = document.getElementById('confirmModalBtn');

        // The action the confirm modal will run when the user clicks
        // its Confirm button. Null when the modal is closed.
        var pendingAction = null;

        // One request at a time. Set while a POST is in flight so a
        // double-click cannot fire two writes.
        var isSubmitting = false;

        /* ============================================
           CARD COLLAPSE — USER INTERACTION ONLY
           ============================================ */

        /**
         * Toggle a single card's expanded state in response to a
         * user click.
         *
         * The state IS the DOM. No JS-side Set is kept. The polling
         * script reads the DOM directly when it needs to preserve
         * state across a re-render.
         *
         * @param {HTMLElement} card
         */
        function toggleCard(card) {
            if (!card) return;

            var details = card.querySelector('.kitchen-order-details');
            var toggle  = card.querySelector('.kitchen-order-toggle');

            if (card.classList.contains('is-expanded')) {
                card.classList.remove('is-expanded');
                if (details) details.hidden = true;
                if (toggle)  toggle.setAttribute('aria-expanded', 'false');
            } else {
                card.classList.add('is-expanded');
                if (details) details.hidden = false;
                if (toggle)  toggle.setAttribute('aria-expanded', 'true');
            }
        }

        if (orderList) {
            // Chevron toggle. The button is the only target that
            // expands or collapses the card when the click lands on
            // the toggle control itself.
            orderList.addEventListener('click', function (e) {
                var toggle = e.target.closest('.kitchen-order-toggle');
                if (!toggle) return;
                if (e.target.closest('.kitchen-action-btn')) return;

                e.preventDefault();
                e.stopPropagation();

                var card = toggle.closest('.kitchen-order-card');
                toggleCard(card);
            });

            // Header band. Clicking anywhere on the header that is
            // not the toggle and not an action button also toggles
            // the card.
            orderList.addEventListener('click', function (e) {
                if (e.target.closest('.kitchen-order-toggle')) return;
                if (e.target.closest('.kitchen-action-btn')) return;

                var header = e.target.closest('.kitchen-order-header');
                if (!header) return;

                e.preventDefault();
                e.stopPropagation();

                var card = header.closest('.kitchen-order-card');
                toggleCard(card);
            });
        }

        /* ============================================
           RIDER ROSTER STATE
           ============================================ */

        var activeRiderOrderId = 0;
        var riderPollTimer = null;
        var RIDER_POLL_MS  = 4000;
        var lastRosterSignature = '';
        var riderFetchInFlight = false;

        /* ============================================
           MODAL HELPERS
           ============================================ */

        function openModal(modal) {
            if (!modal) return;
            document.body.style.overflow = 'hidden';
            modal.classList.add('is-open');
        }

        function closeModal(modal) {
            if (!modal) return;
            modal.classList.remove('is-open');
            document.body.style.overflow = '';
        }

        document.querySelectorAll('[data-close-modal]').forEach(function (el) {
            el.addEventListener('click', function () {
                var target = this.dataset.closeModal;
                if (target === 'rider-modal')   closeRiderModal();
                if (target === 'confirm-modal') closeModal(confirmModal);
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (riderModal && riderModal.classList.contains('is-open'))     closeRiderModal();
            if (confirmModal && confirmModal.classList.contains('is-open')) closeModal(confirmModal);
        });

        /* ============================================
           ACTION DISPATCH (delegated)
           ============================================ */

        if (orderList) {
            orderList.addEventListener('click', function (e) {
                var btn = e.target.closest('.kitchen-action-btn');
                if (!btn || btn.disabled) return;

                var action  = btn.dataset.action || '';
                var orderId = parseInt(btn.dataset.orderId, 10) || 0;
                if (orderId <= 0) return;

                if (action === 'assign_rider' || action === 'reassign_rider') {
                    var isReassign   = btn.dataset.reassign === '1';
                    var currentRider = btn.dataset.currentRider || '';
                    openRiderModal(
                        orderId,
                        isReassign ? 'reassign_rider' : 'assign_rider',
                        currentRider
                    );
                    return;
                }

                if (action === 'cancel_order') {
                    askConfirm(
                        orderId,
                        'cancel_order',
                        'Cancel this order? The customer will be notified. '
                            + 'Once a rider is assigned, the kitchen can no longer cancel.'
                    );
                    return;
                }

                if (action === 'start_preparing') {
                    submitAction(orderId, 'start_preparing', {});
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

        function openRiderModal(orderId, actionName, currentRiderName) {
            if (riderOrderIdEl) riderOrderIdEl.value = String(orderId);
            if (riderActionEl)  riderActionEl.value  = actionName;

            activeRiderOrderId = orderId;

            var isReassign = (actionName === 'reassign_rider');

            if (riderTitleEl) {
                riderTitleEl.textContent = isReassign
                    ? 'Reassign Rider'
                    : 'Assign a Rider';
            }

            if (riderNoteEl && riderCurrentEl) {
                if (isReassign && currentRiderName !== '') {
                    riderCurrentEl.textContent = currentRiderName;
                    riderNoteEl.hidden = false;
                } else {
                    riderCurrentEl.textContent = '';
                    riderNoteEl.hidden = true;
                }
            }

            if (riderListEl) {
                riderListEl.innerHTML =
                    '<li class="kitchen-rider-empty">Loading riders…</li>';
            }

            lastRosterSignature = '';

            openModal(riderModal);

            fetchAvailableRiders(orderId).then(function (riders) {
                if (riders === null) {
                    restoreRiderFallback();
                    return;
                }
                renderRiderList(riders);
                startRiderPoll();
            });
        }

        function closeRiderModal() {
            stopRiderPoll();
            activeRiderOrderId = 0;
            closeModal(riderModal);
        }

        function restoreRiderFallback() {
            if (!riderListEl) return;
            var fallback = document.getElementById('riderModalFallback');
            if (!fallback) {
                riderListEl.innerHTML =
                    '<li class="kitchen-rider-empty">No riders available right now.</li>';
                return;
            }
            riderListEl.innerHTML = fallback.innerHTML;
        }

        /* ============================================
           RIDER ROSTER — FETCH
           ============================================ */

        function fetchAvailableRiders(orderId) {
            if (riderFetchInFlight) return Promise.resolve(null);
            riderFetchInFlight = true;

            var body = new URLSearchParams();
            body.append('csrf_token', CSRF_TOKEN);
            body.append('action', 'available_riders');
            if (orderId > 0) {
                body.append('order_id', String(orderId));
            }

            return fetch(HANDLER_URL, {
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
                            console.error(
                                '[kitchen] available_riders non-JSON:',
                                res.status,
                                text.slice(0, 200)
                            );
                            return null;
                        }
                    });
                })
                .then(function (data) {
                    if (!data || data.status !== 'success') {
                        return null;
                    }
                    return Array.isArray(data.riders) ? data.riders : [];
                })
                .catch(function (err) {
                    console.error('[kitchen] available_riders failed:', err);
                    return null;
                })
                .finally(function () {
                    riderFetchInFlight = false;
                });
        }

        /* ============================================
           RIDER ROSTER — RENDER
           ============================================ */

        function rosterSignature(riders) {
            if (!Array.isArray(riders) || riders.length === 0) {
                return '';
            }
            var ids = riders
                .map(function (r) { return parseInt(r.rider_id, 10) || 0; })
                .filter(function (id) { return id > 0; })
                .sort(function (a, b) { return a - b; });
            return ids.join(',');
        }

        function renderRiderList(riders) {
            if (!riderListEl) return;

            var signature = rosterSignature(riders);
            if (signature === lastRosterSignature) {
                return;
            }
            lastRosterSignature = signature;

            if (!Array.isArray(riders) || riders.length === 0) {
                riderListEl.innerHTML =
                    '<li class="kitchen-rider-empty">No riders available right now. ' +
                    'Everyone is offline or at the active-order cap.</li>';
                return;
            }

            var assetBase = page.dataset.assetBase || '../../shared/';

            var frag = document.createDocumentFragment();

            riders.forEach(function (rider) {
                var riderId = parseInt(rider.rider_id, 10) || 0;
                if (riderId <= 0) return;

                var name = String(rider.name || ('Rider #' + riderId));
                var meta = String(rider.meta || '');
                var rating = String(rider.rating || '0.0');
                var slotLabel = String(rider.slot_label || '');

                var li = document.createElement('li');
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'kitchen-rider-option';
                btn.setAttribute('data-rider-id', String(riderId));
                btn.setAttribute('data-rider-name', name);

                var nameEl = document.createElement('span');
                nameEl.className = 'kitchen-rider-name';
                nameEl.textContent = name;
                btn.appendChild(nameEl);

                var metaEl = document.createElement('span');
                metaEl.className = 'kitchen-rider-meta';
                metaEl.textContent = meta;

                if (slotLabel !== '') {
                    var capEl = document.createElement('span');
                    capEl.className = 'kitchen-rider-capacity';
                    capEl.textContent = ' (' + slotLabel + ')';
                    metaEl.appendChild(capEl);
                }

                btn.appendChild(metaEl);

                var ratingEl = document.createElement('span');
                ratingEl.className = 'kitchen-rider-rating';

                var star = document.createElement('img');
                star.src = assetBase + 'assets/images/icons/star-fill.svg';
                star.alt = 'Rating';
                star.width = 14;
                star.height = 14;
                star.onerror = function () {
                    this.onerror = null;
                    this.src = assetBase + 'assets/images/icons/star-empty.svg';
                };
                ratingEl.appendChild(star);

                var ratingText = document.createElement('span');
                ratingText.textContent = rating;
                ratingEl.appendChild(ratingText);

                btn.appendChild(ratingEl);

                li.appendChild(btn);
                frag.appendChild(li);
            });

            riderListEl.innerHTML = '';
            riderListEl.appendChild(frag);
        }

        /* ============================================
           RIDER ROSTER — POLL
           ============================================ */

        function startRiderPoll() {
            stopRiderPoll();
            riderPollTimer = setInterval(function () {
                if (!riderModal || !riderModal.classList.contains('is-open')) return;
                if (document.hidden) return;
                if (riderFetchInFlight) return;

                fetchAvailableRiders(activeRiderOrderId).then(function (riders) {
                    if (riders === null) return;
                    renderRiderList(riders);
                });
            }, RIDER_POLL_MS);
        }

        function stopRiderPoll() {
            if (riderPollTimer) {
                clearInterval(riderPollTimer);
                riderPollTimer = null;
            }
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState !== 'visible') return;
            if (!riderModal || !riderModal.classList.contains('is-open')) return;

            fetchAvailableRiders(activeRiderOrderId).then(function (riders) {
                if (riders === null) return;
                renderRiderList(riders);
            });
        });

        /* ============================================
           RIDER LIST — CLICK
           ============================================ */

        if (riderListEl) {
            riderListEl.addEventListener('click', function (e) {
                var option = e.target.closest('.kitchen-rider-option');
                if (!option || option.disabled) return;

                var riderId = parseInt(option.dataset.riderId, 10) || 0;
                var orderId = parseInt(
                    riderOrderIdEl ? riderOrderIdEl.value : '0',
                    10
                ) || 0;
                var actionName = riderActionEl
                    ? riderActionEl.value
                    : 'assign_rider';

                if (riderId <= 0 || orderId <= 0) return;

                option.disabled = true;

                stopRiderPoll();
                closeModal(riderModal);

                submitAction(orderId, actionName, { rider_id: riderId });
            });
        }

        /* ============================================
           SUBMIT
           ============================================ */

        function submitAction(orderId, action, extraFields) {
            if (isSubmitting) return;
            isSubmitting = true;

            var card = orderList
                ? orderList.querySelector(
                    '.kitchen-order-card[data-order-id="' + orderId + '"]'
                )
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
                            console.error(
                                '[kitchen] Non-JSON response:',
                                res.status,
                                text.slice(0, 200)
                            );
                            return {
                                status: 'error',
                                message: 'Server returned an unexpected response.'
                            };
                        }
                    });
                })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        showKitchenToast(
                            data.message || 'Done.',
                            'success'
                        );
                        setTimeout(function () {
                            window.location.reload();
                        }, 350);
                        return;
                    }

                    if (card) card.classList.remove('is-updating');
                    isSubmitting = false;

                    showKitchenToast(
                        (data && data.message) || 'Could not complete the action.',
                        'error'
                    );
                })
                .catch(function (err) {
                    console.error('[kitchen] Action failed:', err);
                    if (card) card.classList.remove('is-updating');
                    isSubmitting = false;
                    showKitchenToast('Network error. Please try again.', 'error');
                });
        }

        /* ============================================
           TOAST
           ============================================ */

        function showKitchenToast(message, type) {
            var toast = document.getElementById('kitchenToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'kitchenToast';
                toast.className = 'kitchen-toast';
                document.body.appendChild(toast);
            }

            toast.classList.remove('is-success', 'is-error');
            toast.classList.add(type === 'error' ? 'is-error' : 'is-success');
            toast.textContent = message;
            toast.classList.add('is-visible');

            clearTimeout(toast._timer);
            toast._timer = setTimeout(function () {
                toast.classList.remove('is-visible');
            }, 2600);
        }

        /* ============================================
           SIGN-OUT GUARD HELPER
           ============================================ */

        window.checkActiveOrders = function (callback) {
            if (typeof callback !== 'function') return;

            if (BRANCH_ID <= 0) {
                callback(null);
                return;
            }

            var body = new URLSearchParams();
            body.append('csrf_token', CSRF_TOKEN);
            body.append('action', 'active_orders_count');

            fetch(HANDLER_URL, {
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
                        callback({
                            count:      parseInt(data.count, 10) || 0,
                            has_active: !!data.has_active
                        });
                    } else {
                        callback(null);
                    }
                })
                .catch(function () {
                    callback(null);
                });
        };

    });
})();