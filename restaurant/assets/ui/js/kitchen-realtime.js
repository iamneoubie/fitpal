/**
 * FitPal Kitchen Realtime — client behaviour.
 *
 * Owns the live order board on restaurant/pages/kitchen.php:
 *
 *   - A delta poll against the kitchen order handler
 *     (kitchen-order-handler.php).
 *   - The new-order pill that appears when a new live order arrives.
 *   - Collapsed-state preservation across poll re-renders.
 *
 * ---------------------------------------------------------------------
 * WHAT THIS FILE DOES NOT OWN
 * ---------------------------------------------------------------------
 * The card-collapse toggle. That interaction is owned by
 * kitchen-order.js, which binds exactly one delegated click listener
 * on #kitchenOrderList for it.
 *
 * This file MUST NOT bind a click handler that touches
 * .kitchen-order-toggle, .kitchen-order-header, aria-expanded on
 * the toggle, hidden on the details band, or .is-open on the card
 * in response to a click. A prior revision did, and the two
 * handlers — this file's and kitchen-order.js's — fired for the
 * same click. The card opened and immediately closed.
 *
 * What this file DOES do to the card state:
 *
 *   - Before replacing the list's innerHTML, read .is-open off each
 *     card and store it by order id.
 *   - After the replacement, apply .is-open, [hidden], and
 *     aria-expanded to the freshly rendered card to match.
 *
 * That is a state write, not a toggle. It runs exactly once per
 * replacement, in the same tick the replacement happens, and it is
 * never triggered by a user click. There is no second handler that
 * could fight kitchen-order.js.
 *
 * ---------------------------------------------------------------------
 * PAGE PROTOCOL
 * ---------------------------------------------------------------------
 * The server sends each poll's page slice as an array of
 * {order_id, order_status, html} entries. This file:
 *
 *   1. Reads the currently open cards out of the DOM.
 *   2. Replaces #kitchenOrderList's innerHTML with the new entries.
 *   3. Reapplies the open state to each new card.
 *
 * Step 3 is idempotent: it sets the same values the DOM already
 * carries for a card that was not open, and reasserts the state for
 * a card that was. Nothing about it responds to a user click.
 *
 * @package FitPal
 * @version 4.0 — Removes every write to the card's open state that
 *                could be triggered from a click path. All state
 *                restoration is now scoped to reapplyOpenState()
 *                called once per page replacement. The duplicate
 *                click handler that caused the auto-close in the
 *                previous revision is gone; this file no longer
 *                binds any click listener at all.
 *
 *                (3.0: removed duplicate click handler from v2.0.
 *                2.0: poll target from data-handler-url. 1.6: cursor
 *                advanced from max_id. 1.5: collapsed-state
 *                preservation. 1.4: new-order pill. 1.3: page +
 *                counts from the server. 1.2: delta poll. 1.1:
 *                initial poll.)
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

    var sinceOrderId = parseInt(page.getAttribute('data-max-order-id') || '0', 10);

    var currentTab = INITIAL_TAB;
    var currentPage = parseInt(page.getAttribute('data-active-page') || '1', 10);

    // The open/closed state lives on the DOM. This map is a
    // per-render snapshot taken before the innerHTML replacement,
    // read back from the DOM. It is never mutated by a click.
    var openOrderIds = Object.create(null);

    // -----------------------------------------------------------------
    // ELEMENT HANDLES
    // -----------------------------------------------------------------

    var orderList = document.getElementById('kitchenOrderList');
    var newOrderPill = document.getElementById('kitchenNewOrderPill');
    var newOrderCountEl = document.getElementById('kitchenNewOrderCount');
    var newOrderPluralEl = document.getElementById('kitchenNewOrderPlural');

    // -----------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    /**
     * Snapshot which cards are open right now, so the state survives
     * an innerHTML replacement.
     *
     * The source of truth is the .is-open class on each card. This
     * function reads that class and nothing else. It is not called
     * from a click path.
     */
    function snapshotOpenState() {
        openOrderIds = Object.create(null);

        if (!orderList) return;

        qsa('.kitchen-order-card', orderList).forEach(function (card) {
            var orderId = parseInt(card.getAttribute('data-order-id') || '0', 10);
            if (orderId <= 0) return;

            if (card.classList.contains('is-open')) {
                openOrderIds[orderId] = true;
            }
        });
    }

    /**
     * Apply the stored open state to a card that was just rendered.
     *
     * This is a state WRITE, not a toggle. It does not read the
     * current state of the fresh card's DOM — it sets the fresh
     * card's DOM to the value the snapshot holds. Running it twice
     * for the same card produces the same result.
     *
     * It never runs in response to a click. It runs once per
     * replacement, in reapplyOpenStateForList() below.
     *
     * @param {HTMLElement} card
     */
    function applyOpenState(card) {
        if (!card) return;

        var orderId = parseInt(card.getAttribute('data-order-id') || '0', 10);
        if (orderId <= 0) return;

        var details = qs('.kitchen-order-details', card);
        var toggle  = qs('.kitchen-order-toggle', card);

        if (!details || !toggle) return;

        if (openOrderIds[orderId]) {
            card.classList.add('is-open');
            details.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
        } else {
            card.classList.remove('is-open');
            details.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        }
    }

    /**
     * Apply the stored open state to every card in the list. Called
     * exactly once after each page replacement.
     */
    function reapplyOpenStateForList() {
        if (!orderList) return;
        qsa('.kitchen-order-card', orderList).forEach(applyOpenState);
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
    // NEW-ORDER PILL
    // -----------------------------------------------------------------

    function showNewOrderPill(count) {
        if (!newOrderPill) return;

        if (count <= 0) {
            newOrderPill.hidden = true;
            return;
        }

        if (newOrderCountEl) newOrderCountEl.textContent = String(count);
        if (newOrderPluralEl) newOrderPluralEl.textContent = count === 1 ? '' : 's';

        newOrderPill.hidden = false;
    }

    function clearNewOrderPill() {
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

    /**
     * Replace the entire order list with the server's page slice,
     * then reapply the open/closed state for each card.
     *
     * The snapshot is taken from the DOM before the innerHTML is
     * replaced. After the replacement, the state is written back.
     * Neither step responds to a click.
     *
     * @param {Array<{order_id:number, order_status:string, html:string}>} pageItems
     */
    function replacePage(pageItems) {
        if (!orderList || !Array.isArray(pageItems)) return;

        snapshotOpenState();

        orderList.innerHTML = '';

        if (pageItems.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'kitchen-empty-state';
            empty.innerHTML =
                '<p class="kitchen-empty-title">No orders</p>' +
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
        });

        reapplyOpenStateForList();
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
                    var counts = data.counts;
                    Object.keys(counts).forEach(function (key) {
                        var el = document.querySelector('[data-tab-count="' + key + '"]');
                        if (!el) return;
                        var n = parseInt(counts[key], 10);
                        el.textContent = String(isNaN(n) ? 0 : n);
                    });
                }

                if (data.page && Array.isArray(data.page.items)) {
                    replacePage(data.page.items);
                }

                if (Array.isArray(data.removed) && data.removed.length > 0) {
                    clearNewOrderPill();
                }

                if (Array.isArray(data.rows) && data.rows.length > 0) {
                    showNewOrderPill(data.rows.length);
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

        // Snapshot before the first poll so the server-rendered
        // cards keep their server-rendered state. The server
        // renders every card collapsed by default; this snapshot
        // therefore captures an empty set and the first poll
        // leaves the cards closed.
        snapshotOpenState();

        fetchNow();
        setInterval(fetchNow, POLL_INTERVAL_MS);
    });
})();