/**
 * FitPal Kitchen Orders — client behaviour.
 *
 * Owns the interactive surface of the kitchen page
 * (restaurant/pages/kitchen.php) that is NOT the real-time board.
 * The real-time board — live order polling and the new-order pill —
 * lives in kitchen-realtime.js and is not touched here.
 *
 * ---------------------------------------------------------------------
 * WHAT THIS FILE OWNS
 * ---------------------------------------------------------------------
 *   - The card collapse toggle. The card is two bands:
 *     a header (always visible) and a details body (collapsed by
 *     default). Clicking the chevron OR the header band toggles
 *     the details body.
 *
 *   - The Start Preparing, Cancel, Assign Rider, and Reassign Rider
 *     buttons. Each posts to the handler and, on success, reloads
 *     the page so the tab counts and the tab the order belongs to
 *     stay accurate.
 *
 *   - The generic confirm modal for destructive or irreversible
 *     actions.
 *
 *   - The rider-selection modal, whose roster is fetched fresh from
 *     the server on open and polled while the modal stays open.
 *
 *   - The sign-out guard helper: window.checkActiveOrders() posts
 *     to the handler's active_orders_count action and calls back
 *     with the result. logout.js uses this to refuse sign-out while
 *     live orders are open.
 *
 *   - The toast banner used to surface a handler response.
 *
 * ---------------------------------------------------------------------
 * CARD TOGGLE — SINGLE HANDLER
 * ---------------------------------------------------------------------
 * A prior revision bound TWO delegated click listeners on
 * #kitchenOrderList: one on .kitchen-order-toggle and one on
 * .kitchen-order-header. A click on the chevron bubbled through
 * both and each listener flipped the state, so the card opened and
 * immediately closed.
 *
 * This revision keeps a single delegated listener. It resolves the
 * toggle target itself:
 *
 *   1. If the click landed on the toggle or any descendant of it,
 *      toggle the card once.
 *   2. Otherwise, if the click landed on the header band and not on
 *      a button inside it, toggle the card once.
 *   3. Otherwise, do nothing.
 *
 * toggleCard() is idempotent per gesture because the listener runs
 * exactly once for one click — the browser's event dispatch calls
 * this single listener, this file does not attach a second one
 * anywhere else on the document or on the list.
 *
 * ---------------------------------------------------------------------
 * COLLAPSED STATE AND POLLING
 * ---------------------------------------------------------------------
 * The open/closed state lives on the DOM:
 *
 *     .kitchen-order-card.is-open        the card is expanded
 *     .kitchen-order-details[hidden]     the details band is closed
 *     .kitchen-order-toggle[aria-expanded] "true" | "false"
 *
 * kitchen-realtime.js replaces the list's innerHTML on every poll
 * tick. It snapshots .is-open off the DOM before the replace and
 * reapplies it after. This file does not need to keep a second
 * JS-side Set — the DOM is the single source of truth, and
 * rehydrateKitchenCards() (called by this file's bootstrap only)
 * reads it back.
 *
 * ---------------------------------------------------------------------
 * CONFIRM MODAL
 * ---------------------------------------------------------------------
 * Any action button can opt into a confirmation step by carrying
 * the following data attributes:
 *
 *   data-confirm-title    the modal's heading
 *   data-confirm-message  the modal's body text
 *   data-confirm-label    the confirm button's label
 *
 * If an action button carries `data-confirm-title`, this file
 * intercepts its click and opens the shared confirm modal with the
 * provided content. Only after the user confirms does the original
 * action post to the handler.
 *
 * ---------------------------------------------------------------------
 * WHERE THE ACTIONS GO
 * ---------------------------------------------------------------------
 * Every action posts to the endpoint named by #kitchenPage's
 * data-handler-url attribute:
 *
 *     restaurant/backend/handlers/kitchen-order-handler.php
 *
 * ---------------------------------------------------------------------
 * BOOTSTRAP
 * ---------------------------------------------------------------------
 * The page loads this file with the `defer` attribute. A deferred
 * script runs AFTER the HTML parser finishes, AFTER
 * DOMContentLoaded has already been dispatched, and BEFORE load.
 * A listener attached to DOMContentLoaded inside this file would
 * therefore never fire and the module would never boot. This file
 * uses a readyState guard instead:
 *
 *     if (document.readyState === 'loading') {
 *         document.addEventListener('DOMContentLoaded', boot);
 *     } else {
 *         boot();
 *     }
 *
 * That is correct whether the script is `defer`, `async`, plain, or
 * injected after the document is already interactive.
 *
 * ---------------------------------------------------------------------
 * RULES HONORED
 * ---------------------------------------------------------------------
 *   - No CSS in this file.
 *   - No <svg> injection. Icons come from the shared icon folder
 *     and are used through <img> tags.
 *   - No window.alert / confirm / prompt. Every message goes
 *     through a modal or a toast.
 *
 * @package FitPal
 * @version 12.0 — Actions that carry a `data-confirm-title`
 *                 attribute now open the shared confirm modal
 *                 before proceeding. The "Start Preparing" button
 *                 uses this mechanism.
 *
 *                 (11.0: toggle consolidated. 10.0: readyState
 *                 guard. 9.0: card state handed to realtime. 8.0:
 *                 expandedIds. 7.0: realtime rider roster. 6.0:
 *                 full rewrite. 5.0: pagination.)
 */
(function () {
    'use strict';

    function boot() {

        var page = document.getElementById('kitchenPage');
        if (!page) return;

        var SCOPE       = page.dataset.scope || 'branch';
        var CSRF_TOKEN  = page.dataset.csrfToken || '';
        var HANDLER_URL = page.dataset.handlerUrl
            || '../backend/handlers/kitchen-order-handler.php';
        var BRANCH_ID   = parseInt(page.dataset.branchId, 10) || 0;

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
        var confirmTitleEl = document.getElementById('confirmModalTitle');
        var confirmMsgEl   = document.getElementById('confirmModalMessage');
        var confirmBtn     = document.getElementById('confirmModalBtn');

        var pendingAction = null;
        var isSubmitting  = false;

        /* ============================================
           CARD TOGGLE — SINGLE DELEGATED LISTENER
           ============================================ */

        /**
         * Toggle a single card's expanded state.
         *
         * Writes exactly three things, and only these three:
         *   .is-open on the card
         *   [hidden] on the details band
         *   aria-expanded on the toggle button
         *
         * The chevron rotation is a CSS rule keyed on .is-open. No
         * inline style is written.
         *
         * @param {HTMLElement} card
         */
        function toggleCard(card) {
            if (!card) return;

            var details = card.querySelector('.kitchen-order-details');
            var toggle  = card.querySelector('.kitchen-order-toggle');

            if (!details || !toggle) return;

            var willOpen = !card.classList.contains('is-open');

            if (willOpen) {
                card.classList.add('is-open');
                details.hidden = false;
                toggle.setAttribute('aria-expanded', 'true');
            } else {
                card.classList.remove('is-open');
                details.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
            }
        }

        if (orderList) {
            orderList.addEventListener('click', function (event) {
                // 1. Action buttons and anything inside a button
                //    inside the footer must never toggle.
                if (event.target.closest('.kitchen-action-btn')) {
                    return;
                }

                // 2. Chat trigger buttons must never toggle.
                if (event.target.closest('[data-restaurant-chat-open]')) {
                    return;
                }

                // 3. The chevron button. This is the intended
                //    toggle surface. Do not let the click reach any
                //    other handler in this file or in another file
                //    that might be listening on a broader surface.
                var toggle = event.target.closest('.kitchen-order-toggle');
                if (toggle) {
                    event.preventDefault();
                    event.stopPropagation();

                    var cardFromToggle = toggle.closest('.kitchen-order-card');
                    toggleCard(cardFromToggle);
                    return;
                }

                // 4. The header band. Clicks anywhere on the header
                //    that are not the chevron and not a button still
                //    toggle the card, so the header band is a large
                //    tap target for the same affordance.
                var header = event.target.closest('.kitchen-order-header');
                if (header) {
                    if (event.target.closest('button')) return;

                    event.preventDefault();

                    var cardFromHeader = header.closest('.kitchen-order-card');
                    toggleCard(cardFromHeader);
                    return;
                }
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
           ACTION DISPATCH
           ============================================ */

        if (orderList) {
            orderList.addEventListener('click', function (e) {
                var btn = e.target.closest('.kitchen-action-btn');
                if (!btn || btn.disabled) return;

                if (btn.hasAttribute('data-restaurant-chat-open')) {
                    return;
                }

                var action  = btn.dataset.action || '';
                if (action === '') return;

                var orderId = parseInt(btn.dataset.orderId, 10) || 0;
                if (orderId <= 0) return;

                e.preventDefault();

                // ----- Confirm gate -----
                // Any action button that carries data-confirm-title
                // opens the shared confirm modal first.
                var confirmTitle = btn.dataset.confirmTitle || '';
                if (confirmTitle !== '') {
                    askConfirm(action, orderId, {
                        title:   confirmTitle,
                        message: btn.dataset.confirmMessage || '',
                        label:   btn.dataset.confirmLabel   || 'Confirm'
                    });
                    return;
                }

                // ----- Direct actions -----
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
                    askConfirm(action, orderId, {
                        title:   'Cancel this order?',
                        message: 'The customer will be notified. ' +
                                 'Once a rider is assigned, the kitchen can no longer cancel.',
                        label:   'Yes, cancel order'
                    });
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

        function askConfirm(action, orderId, opts) {
            pendingAction = { orderId: orderId, action: action };
            if (confirmTitleEl) confirmTitleEl.textContent = opts.title || 'Confirm';
            if (confirmMsgEl)   confirmMsgEl.textContent   = opts.message || '';
            if (confirmBtn)     confirmBtn.textContent     = opts.label   || 'Confirm';
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
    }

    /* ============================================
       BOOTSTRAP
       ============================================ */

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();