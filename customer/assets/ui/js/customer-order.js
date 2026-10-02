/**
 * FitPal Customer Orders Page — client behaviour.
 *
 * Owns everything interactive on customer/pages/orders.php:
 *
 *   - Filter tabs (Active / All / Pending / Preparing /
 *     For Delivery / Delivered / Cancelled / Refunded / Failed)
 *     that hide or show order cards without a page reload.
 *
 *   - Per-item expansion on every order card.
 *
 *   - Collapsible order totals (chevron dropdown).
 *
 *   - The Cancel Order modal. POSTs to
 *     customer-order-handler.php with action=cancel_order.
 *
 *   - The Reorder button. POSTs to customer-order-handler.php
 *     with action=reorder.
 *
 *   - Auto-highlight of a newly placed order.
 *
 *   - Tracking-label reconciliation.
 *
 *   - REAL-TIME POLL. Every few seconds the page asks the server
 *     for the current state of every order card and patches only
 *     the cards whose revision changed. Nothing the customer is
 *     interacting with is disturbed.
 *
 * ---------------------------------------------------------------------
 * FILTER MATCHING
 * ---------------------------------------------------------------------
 *   filter === 'all'      → show every card
 *   filter === 'active'   → show cards whose data-active is "1"
 *   anything else         → show cards whose data-status equals
 *                           the filter
 *
 * ---------------------------------------------------------------------
 * TOTALS TOGGLE CONTRACT
 * ---------------------------------------------------------------------
 * This file is the single writer of:
 *     1. the detail block's `hidden` attribute
 *     2. the button's `aria-expanded` attribute
 *     3. the container's `data-collapsed` attribute
 *
 * The chevron rotation is a CSS rule in orders.css keyed on
 * [aria-expanded="true"]. This file does not write any inline style.
 *
 * ---------------------------------------------------------------------
 * POLL CONTRACT
 * ---------------------------------------------------------------------
 * Every .order-card carries a data-revision attribute. The poll
 * sends get_order_card_state, compares each returned revision to
 * the card's current attribute, and only patches the cards whose
 * revision differs.
 *
 * Patching touches only:
 *   - the card's data-* attributes
 *   - .js-order-badge  (class + text)
 *   - .js-order-actions (innerHTML rebuilt from the state flags)
 *
 * Everything else — item details, totals dropdown, scroll position,
 * focus, the cancel modal if it happens to be open — is left alone.
 *
 * The poll pauses while the tab is hidden and resumes on return.
 *
 * ---------------------------------------------------------------------
 * HANDLER TARGETS
 * ---------------------------------------------------------------------
 * Every POST goes to customer-order-handler.php, read from
 * window.FITPAL_ORDERS.handlerUrl with a documented fallback.
 *
 * ---------------------------------------------------------------------
 * MODAL VISIBILITY
 * ---------------------------------------------------------------------
 * Every modal in this file goes through openModal / closeModal,
 * which set display: flex inline, force a reflow, and toggle
 * `.active`. closeModal waits for the fade-out transition before
 * restoring display: none.
 *
 * ---------------------------------------------------------------------
 * CONFIG
 * ---------------------------------------------------------------------
 * The page writes window.FITPAL_ORDERS before loading this script:
 *
 *     window.FITPAL_ORDERS = {
 *         csrfToken:  '<the customer's own csrf token>',
 *         assetBase:  '<project-root-relative asset prefix>',
 *         handlerUrl: '../backend/handlers/customer-order-handler.php'
 *     };
 *
 * ---------------------------------------------------------------------
 * Rules honored
 * ---------------------------------------------------------------------
 *   - No CSS in this file.
 *   - No <svg> injection.
 *   - No window.alert / confirm / prompt.
 *
 * @package FitPal
 * @version 8.0 — Adds a real-time poll that patches order cards
 *                in place when their status changes. No reload
 *                required to see a kitchen or rider update.
 *
 *                (7.0: wallet-style filter tabs; review-button
 *                link removed the modal initializer. 6.0: totals
 *                toggle rewritten. 5.0: collapsible totals.)
 */
(function () {
    'use strict';

    // -----------------------------------------------------------------
    // CONFIG
    // -----------------------------------------------------------------

    var CONFIG = window.FITPAL_ORDERS || {};
    var CSRF_TOKEN = CONFIG.csrfToken || '';

    var HANDLER_URL = CONFIG.handlerUrl
        || '../backend/handlers/customer-order-handler.php';

    var POLL_INTERVAL_MS = 6000;

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    // -----------------------------------------------------------------
    // MODAL VISIBILITY
    // -----------------------------------------------------------------

    function openModal(modal) {
        if (!modal) return;

        modal.style.display = 'flex';

        void modal.offsetWidth;

        modal.classList.add('active');
    }

    function closeModal(modal) {
        if (!modal) return;

        modal.classList.remove('active');

        setTimeout(function () {
            if (!modal.classList.contains('active')) {
                modal.style.display = 'none';
            }
        }, 260);
    }

    // -----------------------------------------------------------------
    // FILTER TABS
    // -----------------------------------------------------------------

    function cardMatchesFilter(card, filter) {
        if (filter === 'all') {
            return true;
        }

        if (filter === 'active') {
            return card.getAttribute('data-active') === '1';
        }

        var status = (card.getAttribute('data-status') || '').trim();
        return status === filter;
    }

    var applyFilterRef = null;

    function initFilterTabs() {
        var tabsRoot = qs('#filterTabs');
        var ordersList = qs('#ordersList');
        var noResults = qs('#noFilterResults');
        if (!tabsRoot || !ordersList) return;

        var tabs = qsa('.filter-tab', tabsRoot);
        var cards = qsa('.order-card', ordersList);

        function applyFilter(filter) {
            var visibleCount = 0;

            cards.forEach(function (card) {
                if (cardMatchesFilter(card, filter)) {
                    card.style.display = '';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            if (noResults) {
                noResults.style.display = (visibleCount === 0) ? '' : 'none';
            }
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (other) {
                    other.classList.remove('active');
                    other.removeAttribute('aria-current');
                });
                tab.classList.add('active');
                tab.setAttribute('aria-current', 'page');

                applyFilter(tab.getAttribute('data-filter') || 'all');
            });
        });

        var initial = tabsRoot.querySelector('.filter-tab.active');
        var initialFilter = initial
            ? (initial.getAttribute('data-filter') || 'active')
            : 'active';
        applyFilter(initialFilter);

        applyFilterRef = applyFilter;
    }

    // -----------------------------------------------------------------
    // PER-ITEM EXPANSION
    // -----------------------------------------------------------------

    function initItemExpansion() {
        var summaries = qsa('.order-item-summary.is-expandable');

        summaries.forEach(function (summary) {
            var details = qs('#' + summary.getAttribute('aria-controls'));
            if (!details) return;

            function toggle() {
                var expanded = summary.getAttribute('aria-expanded') === 'true';
                summary.setAttribute('aria-expanded', expanded ? 'false' : 'true');

                if (expanded) {
                    details.hidden = true;
                    summary.classList.remove('is-open');
                } else {
                    details.hidden = false;
                    summary.classList.add('is-open');
                }
            }

            summary.addEventListener('click', function (event) {
                if (event.target.closest('a, button')) return;
                toggle();
            });

            summary.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                if (event.target.closest('a, button')) return;
                event.preventDefault();
                toggle();
            });
        });
    }

    // -----------------------------------------------------------------
    // COLLAPSIBLE ORDER TOTALS
    // -----------------------------------------------------------------

    function initTotalsToggle() {
        var toggles = qsa('.order-totals-toggle');

        toggles.forEach(function (button) {
            var targetId = button.getAttribute('aria-controls');
            if (!targetId) return;

            var detail = document.getElementById(targetId);
            if (!detail) return;

            var container = button.closest('.order-card-totals');

            button.addEventListener('click', function () {
                var isExpanded = button.getAttribute('aria-expanded') === 'true';
                var nextExpanded = !isExpanded;

                button.setAttribute('aria-expanded', nextExpanded ? 'true' : 'false');
                detail.hidden = !nextExpanded;

                if (container) {
                    container.setAttribute('data-collapsed', nextExpanded ? 'false' : 'true');
                }
            });
        });
    }

    // -----------------------------------------------------------------
    // TRACKING-LABEL RECONCILIATION
    // -----------------------------------------------------------------

    function deriveTrackingLabel(kind) {
        if (kind === 'message') return 'Message';
        if (kind === 'history') return 'Track History';
        if (kind === 'track')   return 'Track Order';
        return null;
    }

    function syncTrackingLabelForCard(card) {
        if (!card) return;

        var button = qs('.tracking-btn', card);
        if (!button) return;

        var labelEl = qs('.tracking-btn-label', button);
        if (!labelEl) return;

        var kind = button.getAttribute('data-tracking-kind') || '';

        var label = deriveTrackingLabel(kind);
        if (label === null) return;

        if (labelEl.textContent !== label) {
            labelEl.textContent = label;
        }

        button.setAttribute('data-tracking-label', label);
    }

    function initTrackingLabelSync() {
        qsa('.order-card').forEach(syncTrackingLabelForCard);
    }

    // -----------------------------------------------------------------
    // CANCEL ORDER MODAL
    // -----------------------------------------------------------------

    function initCancelModal() {
        var modal = qs('#cancelOrderModal');
        var message = qs('#cancelModalMessage');
        var noBtn = qs('#cancelModalNo');
        var yesBtn = qs('#cancelModalYes');
        if (!modal || !yesBtn) return;

        var activeOrderId = 0;

        qsa('.cancel-order-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var orderId = parseInt(btn.getAttribute('data-order-id') || '0', 10);
                if (orderId <= 0) return;

                if (message) {
                    message.textContent =
                        'Are you sure you want to cancel order #' + orderId +
                        '? This action cannot be undone.';
                }

                activeOrderId = orderId;
                openModal(modal);
            });
        });

        if (noBtn) {
            noBtn.addEventListener('click', function () {
                activeOrderId = 0;
                closeModal(modal);
            });
        }

        var overlay = qs('.modal-overlay', modal);
        if (overlay) {
            overlay.addEventListener('click', function () {
                activeOrderId = 0;
                closeModal(modal);
            });
        }

        yesBtn.addEventListener('click', function () {
            if (activeOrderId <= 0) return;

            yesBtn.disabled = true;
            var originalText = yesBtn.textContent;
            yesBtn.textContent = 'Cancelling…';

            var body = new FormData();
            body.append('csrf_token', CSRF_TOKEN);
            body.append('action', 'cancel_order');
            body.append('order_id', String(activeOrderId));

            fetch(HANDLER_URL, {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        window.location.reload();
                        return;
                    }

                    yesBtn.disabled = false;
                    yesBtn.textContent = originalText;

                    if (message) {
                        message.textContent = (data && data.message)
                            ? data.message
                            : 'Could not cancel the order. Please try again.';
                    }
                })
                .catch(function () {
                    yesBtn.disabled = false;
                    yesBtn.textContent = originalText;

                    if (message) {
                        message.textContent =
                            'A network error occurred. Please try again.';
                    }
                });
        });
    }

    // -----------------------------------------------------------------
    // REORDER
    // -----------------------------------------------------------------

    function initReorder() {
        qsa('.reorder-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var orderId = parseInt(btn.getAttribute('data-order-id') || '0', 10);
                if (orderId <= 0) return;

                var label = qs('.reorder-btn-label', btn);
                var originalText = label ? label.textContent : btn.textContent;

                btn.disabled = true;
                if (label) {
                    label.textContent = 'Loading…';
                } else {
                    btn.textContent = 'Loading…';
                }

                var body = new FormData();
                body.append('csrf_token', CSRF_TOKEN);
                body.append('action', 'reorder');
                body.append('order_id', String(orderId));

                fetch(HANDLER_URL, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin'
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && (data.status === 'success' || data.status === 'partial')) {
                            var target = data.redirect || 'menu.php';
                            window.location.href = target;
                            return;
                        }

                        btn.disabled = false;
                        if (label) {
                            label.textContent = originalText;
                        } else {
                            btn.textContent = originalText;
                        }

                        showReorderError((data && data.message)
                            ? data.message
                            : 'Could not reorder the items. Please try again.');
                    })
                    .catch(function () {
                        btn.disabled = false;
                        if (label) {
                            label.textContent = originalText;
                        } else {
                            btn.textContent = originalText;
                        }

                        showReorderError('A network error occurred. Please try again.');
                    });
            });
        });
    }

    function showReorderError(messageText) {
        var modal = qs('#cancelOrderModal');
        var message = qs('#cancelModalMessage');
        if (!modal || !message) return;

        message.textContent = messageText;
        openModal(modal);
    }

    // -----------------------------------------------------------------
    // AUTO-HIGHLIGHT OF NEWLY PLACED ORDER
    // -----------------------------------------------------------------

    function initAutoHighlight() {
        var highlightId = parseInt(window.HIGHLIGHT_ORDER_ID || '0', 10);
        if (highlightId <= 0) return;

        var card = qs('.order-card[data-order-id="' + highlightId + '"]');
        if (!card) return;

        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        card.classList.add('is-highlighted');

        setTimeout(function () {
            card.classList.remove('is-highlighted');
        }, 4000);
    }

    // -----------------------------------------------------------------
    // REAL-TIME POLL
    //
    // Polls get_order_card_state every few seconds and patches only
    // the cards whose revision changed.
    //
    // The poll pauses while the tab is hidden (visibilitychange) and
    // resumes on return. A single tick is skipped if the previous
    // one has not yet returned.
    // -----------------------------------------------------------------

    function buildBadgeClass(raw) {
        var allowed = [
            'badge-warning', 'badge-info', 'badge-primary',
            'badge-success', 'badge-danger', 'badge-secondary'
        ];
        return allowed.indexOf(raw) !== -1 ? raw : 'badge-secondary';
    }

    /**
     * Rebuild the footer action row for one card.
     *
     * Mirrors the server's render in orders.php so a state change
     * produces a footer that is identical to what a fresh page load
     * would have rendered.
     *
     * @param {object} state
     * @returns {string}  innerHTML for .js-order-actions
     */
    function buildFooterActionsHtml(state) {
        var orderId = state.order_id;
        var html = '';

        if (state.can_cancel) {
            html += '<button type="button" class="btn btn-danger btn-sm cancel-order-btn"'
                 +  ' data-order-id="' + orderId + '"'
                 +  ' data-order-status="' + state.order_status + '">'
                 +  'Cancel Order</button>';
        }

        html += '<a href="order-tracking.php?id=' + orderId + '"'
             +  ' class="btn btn-neutral btn-sm tracking-btn js-tracking-btn"'
             +  ' data-tracking-label="' + state.tracking_label + '"'
             +  ' data-tracking-kind="'  + state.tracking_kind  + '">'
             +  '<span class="tracking-btn-label js-tracking-label">'
             +  state.tracking_label
             +  '</span></a>';

        if (state.can_review) {
            html += '<a href="review.php?order_id=' + orderId + '"'
                 +  ' class="btn btn-primary btn-sm review-order-btn">Review</a>';
        }

        if (state.is_terminal) {
            html += '<a href="order-receipt.php?id=' + orderId + '"'
                 +  ' class="btn btn-neutral btn-sm">View Receipt</a>';
        }

        if (state.can_reorder) {
            html += '<button type="button" class="btn btn-primary btn-sm reorder-btn"'
                 +  ' data-order-id="' + orderId + '">'
                 +  '<span class="reorder-btn-label">Reorder</span></button>';
        }

        return html;
    }

    /**
     * Replace one card's footer actions and re-bind the cancel and
     * reorder listeners that were just thrown away with the old
     * innerHTML.
     */
    function rebindFooterActions(card) {
        // Cancel buttons
        qsa('.cancel-order-btn', card).forEach(function (btn) {
            btn.addEventListener('click', function () {
                var orderId = parseInt(btn.getAttribute('data-order-id') || '0', 10);
                if (orderId <= 0) return;

                var modal = qs('#cancelOrderModal');
                var message = qs('#cancelModalMessage');
                if (!modal) return;

                if (message) {
                    message.textContent =
                        'Are you sure you want to cancel order #' + orderId +
                        '? This action cannot be undone.';
                }

                modal.setAttribute('data-active-order-id', String(orderId));
                openModal(modal);
            });
        });

        // Reorder buttons
        qsa('.reorder-btn', card).forEach(function (btn) {
            btn.addEventListener('click', function () {
                var orderId = parseInt(btn.getAttribute('data-order-id') || '0', 10);
                if (orderId <= 0) return;

                var label = qs('.reorder-btn-label', btn);
                var originalText = label ? label.textContent : btn.textContent;

                btn.disabled = true;
                if (label) {
                    label.textContent = 'Loading…';
                } else {
                    btn.textContent = 'Loading…';
                }

                var body = new FormData();
                body.append('csrf_token', CSRF_TOKEN);
                body.append('action', 'reorder');
                body.append('order_id', String(orderId));

                fetch(HANDLER_URL, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin'
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && (data.status === 'success' || data.status === 'partial')) {
                            window.location.href = (data.redirect || 'menu.php');
                            return;
                        }

                        btn.disabled = false;
                        if (label) {
                            label.textContent = originalText;
                        } else {
                            btn.textContent = originalText;
                        }

                        showReorderError((data && data.message)
                            ? data.message
                            : 'Could not reorder the items. Please try again.');
                    })
                    .catch(function () {
                        btn.disabled = false;
                        if (label) {
                            label.textContent = originalText;
                        } else {
                            btn.textContent = originalText;
                        }

                        showReorderError('A network error occurred. Please try again.');
                    });
            });
        });
    }

    function patchCard(card, state) {
        card.setAttribute('data-status',     state.order_status);
        card.setAttribute('data-active',     state.is_active   ? '1' : '0');
        card.setAttribute('data-terminal',   state.is_terminal ? '1' : '0');
        card.setAttribute('data-grace-open', state.grace_open  ? '1' : '0');
        card.setAttribute('data-revision',   state.revision);

        var badge = qs('.js-order-badge', card);
        if (badge) {
            badge.className = 'badge js-order-badge ' + buildBadgeClass(state.badge_class);
            badge.textContent = state.badge_label;
        }

        var actions = qs('.js-order-actions', card);
        if (actions) {
            actions.innerHTML = buildFooterActionsHtml(state);
            rebindFooterActions(card);
        }
    }

    function applyCards(cards) {
        if (!Array.isArray(cards) || cards.length === 0) return;

        var patchedAny = false;

        cards.forEach(function (state) {
            var card = qs('.order-card[data-order-id="' + state.order_id + '"]');
            if (!card) return;

            var current = card.getAttribute('data-revision') || '';
            if (current === state.revision) return;

            patchCard(card, state);
            patchedAny = true;
        });

        // Re-apply the active filter so cards that just moved out
        // of (or into) the Active tab land where they belong.
        if (patchedAny && applyFilterRef) {
            var activeTab = qs('#filterTabs .filter-tab.active');
            var filter = activeTab
                ? (activeTab.getAttribute('data-filter') || 'active')
                : 'active';
            applyFilterRef(filter);
        }
    }

    function initOrdersPoll() {
        var list = qs('#ordersList');
        if (!list) return;

        var csrfToken = list.getAttribute('data-csrf-token') || CSRF_TOKEN;
        var endpoint  = list.getAttribute('data-handler-url') || HANDLER_URL;

        var timer    = null;
        var inFlight = false;

        function tick() {
            if (inFlight) return;
            if (document.visibilityState !== 'visible') return;

            inFlight = true;

            var body = new FormData();
            body.append('csrf_token', csrfToken);
            body.append('action', 'get_order_card_state');

            fetch(endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        applyCards(data.cards || []);
                    }
                })
                .catch(function () {
                    // Silent. Next tick retries.
                })
                .finally(function () {
                    inFlight = false;
                });
        }

        function start() {
            if (timer !== null) return;
            timer = setInterval(tick, POLL_INTERVAL_MS);
        }

        function stop() {
            if (timer === null) return;
            clearInterval(timer);
            timer = null;
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                tick();
                start();
            } else {
                stop();
            }
        });

        start();
    }

    // -----------------------------------------------------------------
    // BOOTSTRAP
    // -----------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', function () {
        initFilterTabs();
        initItemExpansion();
        initTotalsToggle();
        initTrackingLabelSync();
        initCancelModal();
        initReorder();
        initAutoHighlight();
        initOrdersPoll();

        if (window.FITPAL_ORDERS) {
            window.FITPAL_ORDERS.syncTrackingLabels = function () {
                qsa('.order-card').forEach(syncTrackingLabelForCard);
            };
        }
    });
})();