/**
 * FitPal Customer Orders Page — client behaviour.
 *
 * Owns everything interactive on customer/pages/orders.php:
 *
 *   - Filter tabs (All / Pending / Preparing / Delivering /
 *     Delivered / Cancelled / Refunded / Failed) that hide or show
 *     order cards without a page reload.
 *
 *   - Per-item expansion on every order card. Clicking a row's
 *     summary toggles its details block; the chevron rotates in
 *     sync with the expanded state.
 *
 *   - The Cancel Order modal. Submitting it POSTs to the customer
 *     order handler (customer-order-handler.php) with
 *     action=cancel_order. That handler forwards the request to
 *     the shared order-transaction handler, which decides the
 *     final status (COD → 'cancelled', Wallet or Online →
 *     'refunded') and issues the refund ledger for Wallet and
 *     Online orders.
 *
 *   - The Write Review modal. Submitting it POSTs to
 *     feedback-handler.php with action=submit_review.
 *
 *   - The Reorder button on a past order. POSTs to the customer
 *     order handler with action=reorder and redirects to
 *     menu.php on success.
 *
 *   - Auto-highlight: when the page arrives after a successful
 *     order placement, the header sets a session flag and this
 *     file scrolls the matching card into view and flashes it.
 *
 * ---------------------------------------------------------------------
 * HANDLER TARGETS
 * ---------------------------------------------------------------------
 * Every POST this file makes targets a file that exists on disk in
 * the current tree:
 *
 *     customer-order-handler.php   ← cancel_order, reorder
 *     feedback-handler.php         ← submit_review
 *
 * The customer order handler URL is read from
 * window.FITPAL_ORDERS.handlerUrl, which customer/pages/orders.php
 * publishes. When that object is absent — which only happens on a
 * page that was rendered without it — the fallback names the same
 * file the page's own markup names. The feedback form's action is
 * read from its own markup via form.getAttribute('action').
 *
 * This file does not reference order-handler.php or any other
 * retired filename in any fetch, form submit, or window.location
 * assignment.
 *
 * ---------------------------------------------------------------------
 * MODAL VISIBILITY
 * ---------------------------------------------------------------------
 * orders.css defines the modal's visible state behind a class, not
 * behind a bare inline `display` flip:
 *
 *     .modal             { display: none; opacity: 0; }
 *     .modal.active      { display: flex !important; opacity: 1; }
 *     .modal-overlay     { opacity: 0; }
 *     .modal.active .modal-overlay { opacity: 1; }
 *     .modal-content     { opacity: 0; transform: scale(0.95) …; }
 *     .modal.active .modal-content { opacity: 1; transform: none; }
 *
 * Setting `modal.style.display = 'flex'` alone overrides the
 * `.modal { display: none }` rule, but every opacity and transform
 * transition is still keyed on `.active`. Without that class the
 * modal is in the DOM at full size and full pointer-events, but
 * completely transparent — the user sees nothing happen.
 *
 * Every modal in this file therefore goes through one pair of
 * helpers, `openModal` / `closeModal`, that:
 *
 *   - set `display: flex` inline (so the element enters the layout)
 *   - force a reflow (`void modal.offsetWidth`) so the class change
 *     is applied to a settled layout
 *   - toggle `.active` (so the CSS-driven opacity and transform
 *     transitions run)
 *
 * closeModal additionally waits for the fade-out transition before
 * restoring `display: none`, so the modal does not snap out mid-
 * animation.
 *
 * ---------------------------------------------------------------------
 * Config
 * ---------------------------------------------------------------------
 * The page writes window.FITPAL_ORDERS before loading this script:
 *
 *     window.FITPAL_ORDERS = {
 *         csrfToken:  '<the customer's own csrf token>',
 *         assetBase:  '<project-root-relative asset prefix>',
 *         handlerUrl: '../backend/handlers/customer-order-handler.php'
 *     };
 *
 * The handlerUrl is the customer role's own handler; the page does
 * not talk to the shared order-transaction handler directly, because
 * the shared handler is role-aware and the customer role's own
 * handler already forwards cancel and reorder to it.
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
 * @version 4.0 — Modal open/close now toggles the `.active` class
 *                in addition to the inline `display` property.
 *
 *                Before this revision, openModal() set
 *                `modal.style.display = 'flex'` and stopped. The
 *                Cancel modal, the Review modal, and the Reorder
 *                error path were therefore all silently invisible:
 *                the CSS rule `.modal { opacity: 0 }` remained in
 *                force because `.modal.active { opacity: 1 }` was
 *                never triggered, so the user clicked Cancel and
 *                nothing visible happened.
 *
 *                One pair of helpers, `openModal` / `closeModal`,
 *                now owns every modal transition in this file. All
 *                three call sites route through them, so no future
 *                modal can reintroduce the missing-class defect.
 *
 *                Every handler target, every filter, every
 *                expansion rule, and every fetch body is unchanged
 *                from v3.0.
 *
 *                (3.0: handler targets re-verified against the
 *                tree. 2.0: renamed from orders.js. 1.6: docblock
 *                noted the handler URL comes from the page's config
 *                object. 1.5: reading csrfToken from FITPAL_ORDERS.
 *                1.4: auto-highlight. 1.3: star rating. 1.2: cancel
 *                + reorder.)
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
    // Both helpers are defined once and used by the Cancel modal, the
    // Review modal, and the Reorder error path.
    //
    // See the file header for why `.active` must be toggled in
    // addition to the inline `display` flip.
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
    // -----------------------------------------------------------------

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
                var status = (card.getAttribute('data-status') || '').trim();
                var matches = (filter === 'all') || (status === filter);

                if (matches) {
                    card.style.display = '';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            if (noResults) {
                noResults.style.display = (visibleCount === 0 && filter !== 'all') ? '' : 'none';
            }
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (other) {
                    other.classList.remove('active');
                });
                tab.classList.add('active');

                applyFilter(tab.getAttribute('data-filter') || 'all');
            });
        });
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
                // link or a button (e.g. the Write Review button).
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
    // REVIEW MODAL
    // -----------------------------------------------------------------

    function initReviewModal() {
        var modal = qs('#reviewModal');
        var closeBtn = qs('#closeReviewModal');
        var cancelBtn = qs('#cancelReviewBtn');
        var form = qs('#reviewForm');
        var productNameEl = qs('#reviewProductName');
        var orderIdInput = qs('#reviewOrderId');
        var productIdInput = qs('#reviewProductId');
        var ratingInput = qs('#ratingValue');
        var starContainer = qs('#starRatingContainer');
        var ratingError = qs('#ratingError');
        var submitBtn = qs('#submitReviewBtn');
        if (!modal || !form || !starContainer) return;

        function openReviewModal(orderId, productId, productName) {
            if (orderIdInput) orderIdInput.value = String(orderId);
            if (productIdInput) productIdInput.value = String(productId);
            if (productNameEl) productNameEl.textContent = productName || '';
            if (ratingInput) ratingInput.value = '';
            if (ratingError) ratingError.textContent = '';

            var stars = qsa('.star', starContainer);
            stars.forEach(function (s) { s.classList.remove('active'); });

            openModal(modal);
        }

        function closeReviewModal() {
            closeModal(modal);
        }

        qsa('.review-item-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var orderId = parseInt(btn.getAttribute('data-order-id') || '0', 10);
                var productId = parseInt(btn.getAttribute('data-product-id') || '0', 10);
                var productName = btn.getAttribute('data-product-name') || '';

                if (orderId <= 0 || productId <= 0) return;

                openReviewModal(orderId, productId, productName);
            });
        });

        if (closeBtn) closeBtn.addEventListener('click', closeReviewModal);
        if (cancelBtn) cancelBtn.addEventListener('click', closeReviewModal);

        var overlay = qs('.modal-overlay', modal);
        if (overlay) overlay.addEventListener('click', closeReviewModal);

        qsa('.star', starContainer).forEach(function (star) {
            star.addEventListener('click', function () {
                var value = parseInt(star.getAttribute('data-value') || '0', 10);
                if (value < 1 || value > 5) return;

                if (ratingInput) ratingInput.value = String(value);
                if (ratingError) ratingError.textContent = '';

                qsa('.star', starContainer).forEach(function (s) {
                    var sVal = parseInt(s.getAttribute('data-value') || '0', 10);
                    if (sVal <= value) {
                        s.classList.add('active');
                    } else {
                        s.classList.remove('active');
                    }
                });
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (ratingInput && !ratingInput.value) {
                if (ratingError) ratingError.textContent = 'Please select a rating.';
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Submitting…';
            }

            var body = new FormData(form);

            fetch(form.getAttribute('action'), {
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

                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit Review';
                    }

                    if (ratingError) {
                        ratingError.textContent = (data && data.message)
                            ? data.message
                            : 'Could not submit the review. Please try again.';
                    }
                })
                .catch(function () {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit Review';
                    }

                    if (ratingError) {
                        ratingError.textContent =
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
        initCancelModal();
        initReviewModal();
        initReorder();
        initAutoHighlight();
    });
})();