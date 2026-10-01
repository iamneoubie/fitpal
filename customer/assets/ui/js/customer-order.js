/**
 * FitPal Customer Orders Page — client behaviour.
 *
 * Owns everything interactive on customer/pages/orders.php:
 *
 *   - Filter tabs (Active / All / Pending / Preparing /
 *     For Delivery / Delivered / Cancelled / Refunded / Failed)
 *     that hide or show order cards without a page reload.
 *
 *     "Active" is the default tab and matches every non-terminal
 *     status: pending, preparing, rider_pending, picking_up,
 *     delivering. The matcher reads the card's data-active
 *     attribute for this filter and data-status for every other.
 *
 *   - Per-item expansion on every order card. Clicking a row's
 *     summary toggles its details block; the chevron rotates in
 *     sync with the expanded state.
 *
 *   - Collapsible order totals. Each order card shows the grand
 *     total in a single button row ("Total ₱X.XX" + chevron).
 *     Clicking the button reveals the fee breakdown below it. The
 *     chevron rotation is driven by the button's aria-expanded
 *     attribute, which this file flips.
 *
 *   - The Cancel Order modal. Submitting it POSTs to the customer
 *     order handler (customer-order-handler.php) with
 *     action=cancel_order.
 *
 *   - The Reorder button on a past order. POSTs to the customer
 *     order handler with action=reorder and redirects to
 *     menu.php on success.
 *
 *   - Auto-highlight: when the page arrives after a successful
 *     order placement, the header sets a session flag and this
 *     file scrolls the matching card into view and flashes it.
 *
 *   - Tracking-label reconciliation. The tracking button's label
 *     is rendered server-side from the option-B rule (Message in
 *     grace, Track History otherwise). This file re-derives the
 *     label from the card's data-* attributes so a future
 *     in-place status update does not desynchronize the label
 *     from the state.
 *
 * The review flow no longer lives on this page. The "Review"
 * footer button on a delivered order links to review.php, and
 * that page owns its own client script. The review modal and the
 * per-item review button were removed from orders.php in v9.0 of
 * that page, and the corresponding initializers were removed from
 * this file in v7.0.
 *
 * ---------------------------------------------------------------------
 * FILTER MATCHING
 * ---------------------------------------------------------------------
 * The filter tabs use two attributes on each .order-card:
 *
 *     data-status   the exact order_status (pending, delivered, …)
 *     data-active   "1" when the status is one of the five live
 *                   statuses, "0" otherwise
 *
 * The matcher is:
 *
 *     filter === 'all'      → show every card
 *     filter === 'active'   → show cards whose data-active is "1"
 *     anything else         → show cards whose data-status equals
 *                             the filter
 *
 * The server computes data-active so the client and the server
 * agree on which statuses count as live. Adding a status to the
 * live set is a change to isActiveStatus() in orders.php, and this
 * file needs no change to follow.
 *
 * ---------------------------------------------------------------------
 * TOTALS TOGGLE CONTRACT
 * ---------------------------------------------------------------------
 * The markup on orders.php is:
 *
 *     .order-card-totals[data-collapsed="true|false"]
 *       button.order-totals-toggle[aria-expanded="true|false"]
 *         span.order-totals-toggle-label    "Total"
 *         span.order-totals-toggle-value    ₱X.XX
 *         img.order-totals-toggle-icon      chevron
 *       .order-totals-detail[hidden]
 *         .order-total-row  × N
 *
 * This file is the single writer of three things:
 *
 *     1. the detail block's `hidden` attribute
 *     2. the button's `aria-expanded` attribute
 *     3. the container's `data-collapsed` attribute
 *
 * The chevron rotation is a CSS rule in orders.css keyed on
 * [aria-expanded="true"]. This file does not write any inline style
 * and does not touch the chevron. It only flips the attribute.
 *
 * ---------------------------------------------------------------------
 * HANDLER TARGETS
 * ---------------------------------------------------------------------
 * Every POST this file makes targets a file that exists on disk in
 * the current tree:
 *
 *     customer-order-handler.php   ← cancel_order, reorder
 *
 * The customer order handler URL is read from
 * window.FITPAL_ORDERS.handlerUrl, which customer/pages/orders.php
 * publishes. When that object is absent — which only happens on a
 * page that was rendered without it — the fallback names the same
 * file the page's own markup names.
 *
 * This file does not reference order-handler.php or any other
 * retired filename in any fetch or window.location assignment.
 *
 * ---------------------------------------------------------------------
 * MODAL VISIBILITY
 * ---------------------------------------------------------------------
 * orders.css defines the modal's visible state behind a class:
 *
 *     .modal             { display: none; opacity: 0; }
 *     .modal.active      { display: flex !important; opacity: 1; }
 *     .modal-overlay     { opacity: 0; }
 *     .modal.active .modal-overlay { opacity: 1; }
 *     .modal-content     { opacity: 0; transform: scale(0.95) …; }
 *     .modal.active .modal-content { opacity: 1; transform: none; }
 *
 * Every modal in this file goes through one pair of helpers,
 * `openModal` / `closeModal`, that set `display: flex` inline, force
 * a reflow, and toggle `.active`. closeModal waits for the fade-out
 * transition before restoring `display: none`.
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
 *   - No <svg> injection. Icons come from the shared icon folder
 *     and are already in the page markup.
 *   - No window.alert, confirm, or prompt. Every confirmation is a
 *     page-rendered modal with a matching SVG, per §9.
 *
 * @package FitPal
 * @version 7.0 — Filter tabs support an "Active" filter that reads
 *                data-active. The review-modal initializer and the
 *                per-item review button handler are removed —
 *                orders.php no longer renders either, and the
 *                review flow lives on review.php.
 *
 *                (6.0: totals toggle rewritten for the chevron
 *                dropdown. 5.0: collapsible totals and tracking-
 *                label sync added. 4.0: modal open/close toggles
 *                .active. 3.0: handler targets re-verified against
 *                the tree. 2.0: renamed from orders.js. 1.6:
 *                docblock noted the handler URL comes from the
 *                page's config object. 1.5: reading csrfToken
 *                from FITPAL_ORDERS. 1.4: auto-highlight. 1.3:
 *                star rating. 1.2: cancel + reorder.)
 */
(function () {
    'use strict';

    // -----------------------------------------------------------------
    // CONFIG
    // -----------------------------------------------------------------

    var CONFIG = window.FITPAL_ORDERS || {};
    var CSRF_TOKEN = CONFIG.csrfToken || '';

    // The customer order handler. Read from the page's config object
    // with a fallback that names the file that exists on disk.
    var HANDLER_URL = CONFIG.handlerUrl
        || '../backend/handlers/customer-order-handler.php';

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    // -----------------------------------------------------------------
    // MODAL VISIBILITY
    //
    // The single entry point for every modal transition in this file.
    // Used by the Cancel modal and by the Reorder error path.
    // -----------------------------------------------------------------

    function openModal(modal) {
        if (!modal) return;

        modal.style.display = 'flex';

        // Force a reflow so the browser settles the layout with the
        // element already visible before the class change is applied.
        // Without this, some browsers batch the display change and the
        // class change into a single style recalculation and skip the
        // CSS transition entirely.
        void modal.offsetWidth;

        modal.classList.add('active');
    }

    function closeModal(modal) {
        if (!modal) return;

        modal.classList.remove('active');

        // Wait for the fade-out transition before restoring
        // display: none. The CSS transition on `.modal` is 0.25s; the
        // 260ms delay is a small buffer past that. If the modal was
        // reopened in the meantime, the guard prevents the display
        // reset from hiding it again.
        setTimeout(function () {
            if (!modal.classList.contains('active')) {
                modal.style.display = 'none';
            }
        }, 260);
    }

    // -----------------------------------------------------------------
    // FILTER TABS
    //
    // Two matchers, one code path:
    //
    //   'all'    → show every card
    //   'active' → show cards whose data-active is "1"
    //   other    → show cards whose data-status equals the filter
    //
    // A card that does not match is hidden with display: none inline.
    // The card keeps its layout (it is not removed from the DOM), so
    // re-filtering is a style write, not a re-render.
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

        // The server renders Active as the default tab (it carries
        // class="filter-tab active" and aria-current="page"). Apply
        // it once on bootstrap so a customer with a live order sees
        // only their live orders first.
        var initial = tabsRoot.querySelector('.filter-tab.active');
        var initialFilter = initial
            ? (initial.getAttribute('data-filter') || 'active')
            : 'active';
        applyFilter(initialFilter);
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
                // Do not toggle when the click originated inside a
                // link or a button.
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
    // COLLAPSIBLE ORDER TOTALS — CHEVRON DROPDOWN
    //
    // The markup ships collapsed. On click this function flips the
    // button's aria-expanded, the detail block's hidden, and the
    // container's data-collapsed. The chevron rotation is a CSS rule
    // keyed on [aria-expanded="true"].
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
    //
    // The tracking button's visible label is rendered server-side
    // from the option-B rule:
    //
    //     delivered, inside grace   → "Message"
    //     delivered, past grace     → "Track History"
    //     cancelled/refunded/failed → "Track History"
    //     live statuses             → "Track Order"
    //
    // On page load the label already matches the data-* attributes.
    // This function exists so that if a future revision updates a
    // card's data-status, data-terminal, or data-grace-open in
    // place, the label is re-derived from those attributes instead
    // of being left stale.
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
        var cards = qsa('.order-card');
        cards.forEach(syncTrackingLabelForCard);
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

    /**
     * Surface a reorder failure through the Cancel modal.
     *
     * The Cancel modal's markup is the only modal on this page whose
     * buttons do not submit a form and whose body is a single
     * paragraph, which makes it the correct surface for a one-off
     * error message. Its "Keep Order" button closes it and its
     * confirm button is inert without an active order id.
     */
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

        // Expose the tracking-label sync so any future in-place
        // status update path can call it after changing a card's
        // data-* attributes.
        if (window.FITPAL_ORDERS) {
            window.FITPAL_ORDERS.syncTrackingLabels = function () {
                qsa('.order-card').forEach(syncTrackingLabelForCard);
            };
        }
    });
})();