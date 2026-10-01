/**
 * FitPal Kitchen Realtime — client behaviour.
 *
 * Owns the live order board on restaurant/pages/kitchen.php:
 *
 *   - A delta poll against the kitchen order handler
 *     (kitchen-order-handler.php). Every tick sends since_order_id,
 *     the highest order id the client already holds, and the tab
 *     the user is on. The server returns:
 *       rows      new cards to prepend
 *       updated   cards whose status changed since last poll
 *       removed   order ids that are no longer live
 *       counts    per-tab counts recomputed server-side
 *       page      the paginated slice for the requested tab
 *       max_id    highest order id in the live set, the new cursor
 *   - The new-order pill that appears when a new live order is
 *     available for the current tab.
 *   - Collapsed-state preservation. The board re-renders cards from
 *     the server on every poll, so the client reapplies the user's
 *     expanded / collapsed choice for each card across re-renders.
 *
 * Config
 * ------
 * The page writes a set of data attributes on #kitchenPage:
 *
 *     data-handler-url       → the poll endpoint
 *     data-chat-url          → the chat endpoint (not polled here;
 *                              the chat modal opens it on demand)
 *     data-active-tab        → the tab the user is on right now
 *     data-active-page       → the page of that tab
 *     data-per-page          → page size
 *     data-max-order-id      → the initial delta cursor
 *
 * The handler URL is the kitchen order handler
 * (kitchen-order-handler.php). Its previous name was
 * order-handler.php, and both the page and this file were renamed
 * together so the poll target and the endpoint stay in sync.
 *
 * Statuses that leave the board
 * -----------------------------
 * A card leaves the live board when the server reports it in the
 * `removed` array. The server decides that by checking whether the
 * order is still in one of the live statuses
 * ('pending','preparing','rider_pending','picking_up','delivering').
 * A status of 'delivered','cancelled','refunded', or 'failed'
 * therefore removes the card. The 'failed' case is the outcome
 * produced by the shared order-transaction layer's
 * sweepFailedDeliveries() when a rider does not complete a delivery
 * in time.
 *
 * This file does not need to know the full set of removed statuses;
 * it only needs to react to whatever the server lists in `removed`.
 * The list is included here so a future change to the live status
 * set is easy to find.
 *
 * Rules honored
 * -------------
 *   - No CSS in this file.
 *   - No <svg> injection.
 *   - No window.alert / confirm / prompt.
 *
 * @package FitPal
 * @version 2.0 — Poll target resolved from the page's
 *                data-handler-url, which now points at
 *                kitchen-order-handler.php. Docblock records that
 *                'failed' is one of the closed statuses the server
 *                reports in `removed`.
 *
 *                (1.6: cursor advanced from max_id. 1.5:
 *                collapsed-state preservation. 1.4: new-order
 *                pill. 1.3: page + counts from the server. 1.2:
 *                delta poll. 1.1: initial poll.)
 */
(function () {
    'use strict';

    var page = document.getElementById('kitchenPage');
    if (!page) return;

    // -----------------------------------------------------------------
    // CONFIG
    // -----------------------------------------------------------------

    var HANDLER_URL = page.getAttribute('data-handler-url')
        || '../backend/handlers/kitchen-order-handler.php';

    var INITIAL_TAB = page.getAttribute('data-active-tab') || 'new';
    var PER_PAGE = parseInt(page.getAttribute('data-per-page') || '5', 10);
    if (PER_PAGE < 1) PER_PAGE = 5;

    var POLL_INTERVAL_MS = 5000;

    // The order id the client already holds. Seeds from the initial
    // server render and advances on every response.
    var sinceOrderId = parseInt(page.getAttribute('data-max-order-id') || '0', 10);

    var currentTab = INITIAL_TAB;
    var currentPage = parseInt(page.getAttribute('data-active-page') || '1', 10);

    // Tracks which cards the user has expanded, keyed by order id.
    // Reapplied after every re-render so the server-rendered
    // "details collapsed by default" state does not clobber the
    // user's choice.
    var expandedOrderIds = Object.create(null);

    // -----------------------------------------------------------------
    // ELEMENT HANDLES
    // -----------------------------------------------------------------

    var orderList = document.getElementById('kitchenOrderList');
    var newOrderPill = document.getElementById('kitchenNewOrderPill');
    var newOrderCountEl = document.getElementById('kitchenNewOrderCount');
    var newOrderPluralEl = document.getElementById('kitchenNewOrderPlural');

    var pendingNewOrders = 0;

    // -----------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function cardIdFromHtml(html) {
        // Cheap parse: the renderer emits data-order-id as the first
        // attribute on the outer <article>. Reading it via DOMParser
        // would be heavier than a single regex and the attribute is
        // machine-generated, so this stays reliable without pulling
        // in a full parse tree.
        var match = html.match(/data-order-id="(\d+)"/);
        return match ? parseInt(match[1], 10) : 0;
    }

    /**
     * Reapply the user's collapsed/expanded choice to a freshly
     * rendered card. The server always renders the details band
     * hidden; if the user had expanded it before the poll, this
     * puts it back.
     */
    function applyCollapsedState(card) {
        var orderId = parseInt(card.getAttribute('data-order-id') || '0', 10);
        if (orderId <= 0) return;

        var shouldBeOpen = !!expandedOrderIds[orderId];
        var toggleBtn = qs('.kitchen-order-toggle', card);
        var details = qs('.kitchen-order-details', card);

        if (!toggleBtn || !details) return;

        if (shouldBeOpen) {
            details.hidden = false;
            card.classList.add('is-open');
            toggleBtn.setAttribute('aria-expanded', 'true');
        } else {
            details.hidden = true;
            card.classList.remove('is-open');
            toggleBtn.setAttribute('aria-expanded', 'false');
        }
    }

    /**
     * Wire a card's expand toggle. Called both on initial render and
     * after every poll re-render.
     *
     * The delegated listener is attached once at the board level in
     * initExpandDelegation() below, so this function only needs to
     * reapply the collapsed state — nothing is re-bound here.
     */
    function rehydrateCard(card) {
        applyCollapsedState(card);
    }

    /**
     * Refresh the tab-count badges from the server-provided counts
     * object.
     */
    function applyCounts(counts) {
        if (!counts || typeof counts !== 'object') return;

        Object.keys(counts).forEach(function (key) {
            var el = qs('[data-tab-count="' + key + '"]');
            if (!el) return;

            var n = parseInt(counts[key], 10);
            el.textContent = String(isNaN(n) ? 0 : n);
        });
    }

    /**
     * Replace the entire order list for the current tab with the
     * server-provided page slice.
     *
     * This is the path used when the user switches tabs or when the
     * server reports that the current page's content changed in a
     * way the incremental rows/updated/removed sets do not capture
     * (e.g. a card moved between tabs).
     */
    function replacePage(pageItems) {
        if (!orderList || !Array.isArray(pageItems)) return;

        // Preserve which cards were expanded before the replace.
        qsa('.kitchen-order-card', orderList).forEach(function (card) {
            var orderId = parseInt(card.getAttribute('data-order-id') || '0', 10);
            if (orderId > 0 && card.classList.contains('is-open')) {
                expandedOrderIds[orderId] = true;
            }
        });

        orderList.innerHTML = '';

        if (pageItems.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'kitchen-empty-state';
            empty.innerHTML = '<p class="kitchen-empty-title">No orders</p>' +
                              '<p class="kitchen-empty-text">There is nothing to show in this tab.</p>';
            orderList.appendChild(empty);
            return;
        }

        pageItems.forEach(function (item) {
            if (!item || !item.html) return;

            var wrapper = document.createElement('div');
            wrapper.innerHTML = item.html.trim();

            var card = wrapper.firstElementChild;
            if (!card) return;

            orderList.appendChild(card);
            rehydrateCard(card);
        });
    }

    // -----------------------------------------------------------------
    // TAB SWITCHING
    // -----------------------------------------------------------------

    function initTabSwitching() {
        qsa('[data-tab-link]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();

                var tab = link.getAttribute('data-tab') || 'new';
                if (tab === currentTab) return;

                currentTab = tab;
                currentPage = 1;

                qsa('[data-tab-link]').forEach(function (other) {
                    other.classList.remove('active');
                    other.setAttribute('aria-selected', 'false');
                });
                link.classList.add('active');
                link.setAttribute('aria-selected', 'true');

                clearNewOrderPill();
                fetchNow();
            });
        });
    }

    // -----------------------------------------------------------------
    // PAGINATION
    // -----------------------------------------------------------------

    function initPaginationLinks() {
        document.addEventListener('click', function (event) {
            var link = event.target.closest('[data-page-link]');
            if (!link) return;

            var targetPage = parseInt(link.getAttribute('data-page') || '1', 10);
            if (targetPage <= 0) return;
            if (link.classList.contains('is-disabled')) return;

            event.preventDefault();
            currentPage = targetPage;
            clearNewOrderPill();
            fetchNow();
        });
    }

    // -----------------------------------------------------------------
    // EXPAND / COLLAPSE DELEGATION
    // -----------------------------------------------------------------

    function initExpandDelegation() {
        document.addEventListener('click', function (event) {
            var toggle = event.target.closest('.kitchen-order-toggle');
            if (!toggle) return;

            var card = toggle.closest('.kitchen-order-card');
            if (!card) return;

            var details = qs('.kitchen-order-details', card);
            if (!details) return;

            var orderId = parseInt(card.getAttribute('data-order-id') || '0', 10);
            var expanded = toggle.getAttribute('aria-expanded') === 'true';

            if (expanded) {
                details.hidden = true;
                card.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
                if (orderId > 0) delete expandedOrderIds[orderId];
            } else {
                details.hidden = false;
                card.classList.add('is-open');
                toggle.setAttribute('aria-expanded', 'true');
                if (orderId > 0) expandedOrderIds[orderId] = true;
            }
        });
    }

    // -----------------------------------------------------------------
    // NEW-ORDER PILL
    // -----------------------------------------------------------------

    function showNewOrderPill(count) {
        if (!newOrderPill) return;

        pendingNewOrders = count;
        if (count <= 0) {
            newOrderPill.hidden = true;
            return;
        }

        if (newOrderCountEl) newOrderCountEl.textContent = String(count);
        if (newOrderPluralEl) newOrderPluralEl.textContent = count === 1 ? '' : 's';

        newOrderPill.hidden = false;
    }

    function clearNewOrderPill() {
        pendingNewOrders = 0;
        if (newOrderPill) newOrderPill.hidden = true;
    }

    // -----------------------------------------------------------------
    // POLL
    // -----------------------------------------------------------------

    function buildPollBody() {
        var body = new FormData();
        body.append('action', 'poll');
        body.append('since_order_id', String(sinceOrderId));
        body.append('tab', currentTab);
        body.append('page', String(currentPage));

        var csrf = page.getAttribute('data-csrf-token') || '';
        body.append('csrf_token', csrf);

        return body;
    }

    function fetchNow() {
        fetch(HANDLER_URL, {
            method: 'POST',
            body: buildPollBody(),
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 'success') return;

                if (typeof data.max_id === 'number' && data.max_id > sinceOrderId) {
                    sinceOrderId = data.max_id;
                }

                if (data.counts) {
                    applyCounts(data.counts);
                }

                if (data.page && Array.isArray(data.page.items)) {
                    replacePage(data.page.items);
                }

                // A failed order and every other closed status is
                // listed in `removed`. Clearing the pill on a
                // non-empty removed set is not necessary because the
                // page swap already reflects the current tab, but
                // tracking it here keeps the pill honest if a future
                // revision ever switches to incremental rendering.
                if (Array.isArray(data.removed) && data.removed.length > 0) {
                    clearNewOrderPill();
                }
            })
            .catch(function () {
                // Silent fail. The next tick retries.
            });
    }

    // -----------------------------------------------------------------
    // BOOTSTRAP
    // -----------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', function () {
        initTabSwitching();
        initPaginationLinks();
        initExpandDelegation();

        qsa('.kitchen-order-card', orderList).forEach(function (card) {
            rehydrateCard(card);
        });

        fetchNow();
        setInterval(fetchNow, POLL_INTERVAL_MS);
    });
})();