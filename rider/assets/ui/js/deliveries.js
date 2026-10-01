/**
 * FitPal Rider Deliveries JavaScript
 *
 * Scope
 * -----
 * Three responsibilities:
 *
 *   1. The confirm-modal flow for every delivery action button
 *      (Accept, Decline, Mark Picked Up, Mark Delivered).
 *
 *   2. A full-snapshot poll of the assignment handler that keeps
 *      the Active and Assigned tabs current. Every tick posts
 *      action=list to the same endpoint the assignment panel uses,
 *      filters the returned rows by status, and replaces the two
 *      live lists.
 *
 *   3. A post-delivery messaging-window guard for the History tab.
 *      A delivered order stays messageable for one hour. The
 *      server renders the History Message buttons only for orders
 *      inside that window and stamps each block with an absolute
 *      data-message-window-ends timestamp. A 30-second tick reads
 *      that timestamp and, once it has passed, removes the block's
 *      buttons and replaces them with a short muted note. A
 *      capture-phase click guard on [data-rider-chat-open] closes
 *      the race between a window expiring and the next tick firing.
 *
 * ---------------------------------------------------------------------
 * POLL SHAPE
 * ---------------------------------------------------------------------
 * Same shape as the assignment panel's poll. Every tick posts
 * action=list and re-renders the two live lists from the response.
 * The delta cursor is not used. The list is capped at 20 rows on the
 * server side, so the payload is bounded.
 *
 * The History tab is not polled and not re-rendered. It is a closed
 * set of rows. Only the messaging-window tick touches it.
 *
 * ---------------------------------------------------------------------
 * TAB SWITCHING
 * ---------------------------------------------------------------------
 * Once this file has run, tab clicks no longer reload the page.
 * They toggle which .rider-deliveries-panel has .active and which
 * .rider-deliveries-tab has .active. The anchors keep their href
 * values, so a direct link or a page refresh still lands on the
 * right panel.
 *
 * ---------------------------------------------------------------------
 * ROW MARKUP
 * ---------------------------------------------------------------------
 * The poll renders rows with the same markup the PHP page renders
 * on first paint. The card shape, the fact pills, and the action
 * buttons all match rider/pages/deliveries.php byte-for-byte so the
 * two renders produce identical DOM and there is no visual drift
 * between the first paint and the first poll.
 *
 * ---------------------------------------------------------------------
 * CHAT HANDOFF
 * ---------------------------------------------------------------------
 * Message buttons carry the delegated attributes
 * rider-chat-modal.js already listens for:
 *
 *   data-rider-chat-open
 *   data-rider-chat-order-id
 *   data-rider-chat-recipient
 *   data-rider-chat-subtitle
 *
 * The modal script opens the modal. This file does not reference
 * the chat modal's internals.
 *
 * The messaging-window guard is the one place this file reaches
 * into the chat trigger's lifecycle. It does so at the capture
 * phase, so it can stop the click before the modal script's own
 * delegated listener sees it. That is a one-way dependency: this
 * file knows the attribute name the modal script listens for, but
 * it never calls into the modal script.
 *
 * ---------------------------------------------------------------------
 * CONFIRM FLOW
 * ---------------------------------------------------------------------
 * Every .delivery-status-btn carries its own copy and presentation
 * hints in data-confirm-* attributes:
 *
 *   data-confirm-title     — the modal heading
 *   data-confirm-message   — the modal body
 *   data-confirm-label     — the confirm button label
 *   data-confirm-variant   — "primary" or "danger"
 *   data-confirm-icon      — "accept" | "decline" | "picked_up" | "delivered"
 *
 * The click handler copies those onto #riderConfirmModal as
 * data-variant and data-icon. CSS reads the modal attributes and
 * picks the correct icon and button colour.
 *
 * @package FitPal
 * @version 7.0 — Adds the post-delivery messaging-window guard.
 *
 *                - A 30-second interval reads each History actions
 *                  block's data-message-window-ends timestamp and,
 *                  when the window has closed, removes the block's
 *                  buttons and replaces them with a muted note.
 *                - A capture-phase click listener on
 *                  [data-rider-chat-open] stops any click that
 *                  targets an already-expired History block, in
 *                  case the tick has not yet fired.
 *                - The poll, the tab switching, the confirm flow,
 *                  the row renderers, and the toast are unchanged
 *                  from v6.0.
 *
 *                (6.0: full-snapshot poll of the Active and
 *                Assigned lists. 5.1: chat handling delegated to
 *                the shared rider-chat-modal.js. 5.0: chat handling
 *                removed. 4.2: data-icon added. 4.1: confirm button
 *                colour driven by modal attributes.)
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var CFG = window.FITPAL_RIDER_DELIVERIES || {};
        var CSRF_TOKEN = CFG.csrfToken || '';
        var ASSET_BASE = CFG.assetBase || '../shared/';
        var PANEL_ENDPOINT = CFG.panelEndpoint || '../backend/handlers/assignment-handler.php';
        var PAYOUT = parseFloat(CFG.payout || 0);
        var CURRENT_TAB = CFG.activeTab || 'active';

        var POLL_INTERVAL_MS = 5000;
        var MESSAGE_WINDOW_TICK_MS = 30000;

        // ============================================
        // HELPERS
        // ============================================

        function escapeHtml(str) {
            return String(str == null ? '' : str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function formatCurrency(amount) {
            var n = parseFloat(amount || 0);
            return '\u20B1' + n.toFixed(2);
        }

        function formatDate(dateStr) {
            if (!dateStr) return '\u2014';
            // Parse "YYYY-MM-DD HH:MM:SS" as local time.
            var d = new Date(String(dateStr).replace(' ', 'T'));
            if (isNaN(d.getTime())) return String(dateStr);
            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                          'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            var h = d.getHours();
            var ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12; if (h === 0) h = 12;
            var min = String(d.getMinutes()).padStart(2, '0');
            return months[d.getMonth()] + ' ' + String(d.getDate()).padStart(2, '0')
                 + ', ' + h + ':' + min + ' ' + ampm;
        }

        function iconUrl(file) {
            return ASSET_BASE + 'assets/images/icons/' + file;
        }

        // ============================================
        // STATUS LABELS AND BADGES
        //
        // Mirror the PHP helpers so the poll and the first paint
        // produce the same text.
        // ============================================

        function statusBadge(status) {
            switch (status) {
                case 'pending':       return 'badge-warning';
                case 'preparing':     return 'badge-info';
                case 'rider_pending': return 'badge-primary';
                case 'picking_up':    return 'badge-primary';
                case 'delivering':    return 'badge-primary';
                case 'delivered':     return 'badge-success';
                case 'cancelled':     return 'badge-danger';
                case 'refunded':      return 'badge-secondary';
                case 'failed':        return 'badge-danger';
                default:              return 'badge-secondary';
            }
        }

        function statusLabel(status) {
            switch (status) {
                case 'pending':       return 'Pending';
                case 'preparing':     return 'Preparing';
                case 'rider_pending': return 'Awaiting Your Confirmation';
                case 'picking_up':    return 'Head to Pickup';
                case 'delivering':    return 'In Transit';
                case 'delivered':     return 'Delivered';
                case 'cancelled':     return 'Cancelled';
                case 'refunded':      return 'Refunded';
                case 'failed':        return 'Failed';
                default:              return status.charAt(0).toUpperCase() + status.slice(1);
            }
        }

        // ============================================
        // ROW RENDER — ACTIVE CARD
        // ============================================

        function renderActiveCard(row) {
            var orderId        = row.order_id;
            var orderStatus    = row.status;
            var customerName   = row.customer_name || 'Customer';
            var customerPhone  = row.customer_contact || '';
            var destination    = row.destination || '';
            var orderDate      = row.order_date || '';
            var branchName     = row.branch_name || '';
            var restaurantName = row.restaurant_name || '';
            var itemCount      = parseInt(row.item_count || 0, 10);
            var orderTotal     = parseFloat(row.order_total || 0);
            var isPickingUp    = (orderStatus === 'picking_up');

            var routeLabelPickup = isPickingUp ? 'Pickup — head here now' : 'Pickup';

            var contactBlock = isPickingUp
                ? ('<button type="button" class="btn btn-neutral btn-sm" data-rider-chat-open '
                    + 'data-rider-chat-order-id="' + orderId + '" '
                    + 'data-rider-chat-recipient="restaurant_account" '
                    + 'data-rider-chat-subtitle="Order #' + orderId + ' \u2022 ' + escapeHtml(restaurantName) + '">'
                    + '<img src="' + escapeHtml(iconUrl('contact-us-line.svg')) + '" alt="" class="btn-icon" width="16" height="16">'
                    + '<span>Message Kitchen</span>'
                    + '</button>')
                : ((customerPhone !== ''
                    ? ('<a href="tel:' + escapeHtml(customerPhone) + '" class="btn btn-neutral btn-sm">'
                        + '<img src="' + escapeHtml(iconUrl('phone-fill.svg')) + '" alt="" class="btn-icon" width="16" height="16">'
                        + '<span>Call</span>'
                        + '</a>')
                    : '')
                    + '<button type="button" class="btn btn-neutral btn-sm" data-rider-chat-open '
                    + 'data-rider-chat-order-id="' + orderId + '" '
                    + 'data-rider-chat-recipient="customer" '
                    + 'data-rider-chat-subtitle="Order #' + orderId + ' \u2022 ' + escapeHtml(customerName) + '">'
                    + '<img src="' + escapeHtml(iconUrl('contact-us-line.svg')) + '" alt="" class="btn-icon" width="16" height="16">'
                    + '<span>Message</span>'
                    + '</button>');

            var primaryAction  = isPickingUp ? 'mark_picked_up' : 'delivered';
            var primaryLabel   = isPickingUp ? 'Mark Picked Up' : 'Mark Delivered';
            var confirmTitle   = isPickingUp ? 'Confirm pickup?' : 'Mark as delivered?';
            var confirmMessage = isPickingUp
                ? 'Only mark this after you have the food in hand. The customer will see the order move to "In Transit".'
                : 'This closes the order and recognises the earning on your account.';
            var confirmIcon    = isPickingUp ? 'picked_up' : 'delivered';

            return (
                '<article class="rider-delivery-card" data-order-id="' + orderId + '" '
                    + 'data-order-status="' + escapeHtml(orderStatus) + '">'

                + '<div class="rider-delivery-card-head">'
                    + '<div class="rider-delivery-card-id">'
                        + '<p class="rider-delivery-card-order">Order #' + orderId + '</p>'
                        + '<p class="rider-delivery-card-date">' + escapeHtml(formatDate(orderDate)) + '</p>'
                    + '</div>'
                    + '<span class="badge ' + statusBadge(orderStatus) + '">'
                        + escapeHtml(statusLabel(orderStatus))
                    + '</span>'
                + '</div>'

                + '<div class="rider-delivery-card-route">'
                    + '<div class="rider-route-stop">'
                        + '<div class="rider-route-icon rider-route-icon-pickup" aria-hidden="true">'
                            + '<img src="' + escapeHtml(iconUrl('restaurant.svg')) + '" alt="">'
                        + '</div>'
                        + '<div class="rider-route-info">'
                            + '<p class="rider-route-label">' + escapeHtml(routeLabelPickup) + '</p>'
                            + '<p class="rider-route-name">' + escapeHtml(restaurantName) + '</p>'
                            + '<p class="rider-route-address">' + escapeHtml(branchName) + '</p>'
                        + '</div>'
                    + '</div>'
                    + '<div class="rider-route-connector" aria-hidden="true"></div>'
                    + '<div class="rider-route-stop">'
                        + '<div class="rider-route-icon rider-route-icon-dropoff" aria-hidden="true">'
                            + '<img src="' + escapeHtml(iconUrl('location-fill.svg')) + '" alt="">'
                        + '</div>'
                        + '<div class="rider-route-info">'
                            + '<p class="rider-route-label">Drop-off</p>'
                            + '<p class="rider-route-name">' + escapeHtml(customerName) + '</p>'
                            + '<p class="rider-route-address">' + escapeHtml(destination) + '</p>'
                        + '</div>'
                    + '</div>'
                + '</div>'

                + '<div class="rider-delivery-card-facts">'
                    + '<span class="rider-fact">'
                        + '<span class="rider-fact-label">Items</span>'
                        + '<span class="rider-fact-value">' + itemCount + '</span>'
                    + '</span>'
                    + '<span class="rider-fact">'
                        + '<span class="rider-fact-label">Order</span>'
                        + '<span class="rider-fact-value">' + escapeHtml(formatCurrency(orderTotal)) + '</span>'
                    + '</span>'
                    + '<span class="rider-fact">'
                        + '<span class="rider-fact-label">Your Earning</span>'
                        + '<span class="rider-fact-value rider-fact-value-highlight">'
                            + escapeHtml(formatCurrency(PAYOUT))
                        + '</span>'
                    + '</span>'
                + '</div>'

                + '<div class="rider-delivery-card-actions">'
                    + contactBlock
                    + '<button type="button" class="btn btn-primary btn-sm delivery-status-btn" '
                        + 'data-order-id="' + orderId + '" '
                        + 'data-action="' + primaryAction + '" '
                        + 'data-confirm-title="' + escapeHtml(confirmTitle) + '" '
                        + 'data-confirm-message="' + escapeHtml(confirmMessage) + '" '
                        + 'data-confirm-label="' + escapeHtml(primaryLabel) + '" '
                        + 'data-confirm-variant="primary" '
                        + 'data-confirm-icon="' + confirmIcon + '">'
                        + escapeHtml(primaryLabel)
                    + '</button>'
                + '</div>'
            + '</article>');
        }

        // ============================================
        // ROW RENDER — ASSIGNED CARD
        // ============================================

        function renderAssignedCard(row) {
            var orderId        = row.order_id;
            var customerName   = row.customer_name || 'Customer';
            var destination    = row.destination || '';
            var orderDate      = row.order_date || '';
            var branchName     = row.branch_name || '';
            var restaurantName = row.restaurant_name || '';
            var itemCount      = parseInt(row.item_count || 0, 10);
            var orderTotal     = parseFloat(row.order_total || 0);

            return (
                '<article class="rider-delivery-card rider-delivery-card-assigned" '
                    + 'data-order-id="' + orderId + '" '
                    + 'data-order-status="rider_pending">'

                + '<div class="rider-delivery-card-head">'
                    + '<div class="rider-delivery-card-id">'
                        + '<p class="rider-delivery-card-order">Order #' + orderId + '</p>'
                        + '<p class="rider-delivery-card-date">' + escapeHtml(formatDate(orderDate)) + '</p>'
                    + '</div>'
                    + '<span class="badge badge-warning">Awaiting Confirmation</span>'
                + '</div>'

                + '<div class="rider-delivery-card-route">'
                    + '<div class="rider-route-stop">'
                        + '<div class="rider-route-icon rider-route-icon-pickup" aria-hidden="true">'
                            + '<img src="' + escapeHtml(iconUrl('restaurant.svg')) + '" alt="">'
                        + '</div>'
                        + '<div class="rider-route-info">'
                            + '<p class="rider-route-label">Pickup</p>'
                            + '<p class="rider-route-name">' + escapeHtml(restaurantName) + '</p>'
                            + '<p class="rider-route-address">' + escapeHtml(branchName) + '</p>'
                        + '</div>'
                    + '</div>'
                    + '<div class="rider-route-connector" aria-hidden="true"></div>'
                    + '<div class="rider-route-stop">'
                        + '<div class="rider-route-icon rider-route-icon-dropoff" aria-hidden="true">'
                            + '<img src="' + escapeHtml(iconUrl('location-fill.svg')) + '" alt="">'
                        + '</div>'
                        + '<div class="rider-route-info">'
                            + '<p class="rider-route-label">Drop-off</p>'
                            + '<p class="rider-route-name">' + escapeHtml(customerName) + '</p>'
                            + '<p class="rider-route-address">' + escapeHtml(destination) + '</p>'
                        + '</div>'
                    + '</div>'
                + '</div>'

                + '<div class="rider-delivery-card-facts">'
                    + '<span class="rider-fact">'
                        + '<span class="rider-fact-label">Items</span>'
                        + '<span class="rider-fact-value">' + itemCount + '</span>'
                    + '</span>'
                    + '<span class="rider-fact">'
                        + '<span class="rider-fact-label">Order</span>'
                        + '<span class="rider-fact-value">' + escapeHtml(formatCurrency(orderTotal)) + '</span>'
                    + '</span>'
                    + '<span class="rider-fact">'
                        + '<span class="rider-fact-label">Your Earning</span>'
                        + '<span class="rider-fact-value rider-fact-value-highlight">'
                            + escapeHtml(formatCurrency(PAYOUT))
                        + '</span>'
                    + '</span>'
                + '</div>'

                + '<div class="rider-delivery-card-actions">'
                    + '<button type="button" class="btn btn-danger btn-sm delivery-status-btn" '
                        + 'data-order-id="' + orderId + '" '
                        + 'data-action="decline_assignment" '
                        + 'data-confirm-title="Decline this assignment?" '
                        + 'data-confirm-message="The kitchen will choose another rider for this order. This cannot be undone." '
                        + 'data-confirm-label="Decline" '
                        + 'data-confirm-variant="danger" '
                        + 'data-confirm-icon="decline">'
                        + 'Decline'
                    + '</button>'
                    + '<button type="button" class="btn btn-primary btn-sm delivery-status-btn" '
                        + 'data-order-id="' + orderId + '" '
                        + 'data-action="accept_assignment" '
                        + 'data-confirm-title="Accept this assignment?" '
                        + 'data-confirm-message="You\'ll be responsible for picking up this order and delivering it to the customer. You won\'t be able to go offline until the delivery is complete." '
                        + 'data-confirm-label="Accept" '
                        + 'data-confirm-variant="primary" '
                        + 'data-confirm-icon="accept">'
                        + 'Accept Assignment'
                    + '</button>'
                + '</div>'
            + '</article>');
        }

        // ============================================
        // EMPTY STATES
        // ============================================

        function emptyActiveHtml() {
            return (
                '<div class="rider-deliveries-empty" id="deliveriesActiveEmpty">'
                    + '<div class="rider-deliveries-empty-icon">'
                        + '<img src="' + escapeHtml(iconUrl('riding-line.svg')) + '" alt="">'
                    + '</div>'
                    + '<p class="rider-deliveries-empty-title">No active delivery</p>'
                    + '<p class="rider-deliveries-empty-text">'
                        + 'When the kitchen assigns you an order and you accept it, it will appear here.'
                    + '</p>'
                + '</div>'
            );
        }

        function emptyAssignedHtml() {
            return (
                '<div class="rider-deliveries-empty" id="deliveriesAssignedEmpty">'
                    + '<div class="rider-deliveries-empty-icon">'
                        + '<img src="' + escapeHtml(iconUrl('package.svg')) + '" alt="">'
                    + '</div>'
                    + '<p class="rider-deliveries-empty-title">No pending assignments</p>'
                    + '<p class="rider-deliveries-empty-text">'
                        + 'When the kitchen assigns you an order, it will appear here for you to accept or decline.'
                    + '</p>'
                + '</div>'
            );
        }

        // ============================================
        // LIST RENDER
        // ============================================

        function replaceList(listId, rows, cardRenderer, emptyHtml) {
            var container = document.getElementById(listId);
            if (!container) return;

            if (!rows || rows.length === 0) {
                container.innerHTML = emptyHtml();
                return;
            }

            container.innerHTML = rows.map(cardRenderer).join('');
        }

        // ============================================
        // TAB SWITCHING
        // ============================================

        function setActiveTab(tabName) {
            document.querySelectorAll('.rider-deliveries-tab').forEach(function (t) {
                var isActive = t.getAttribute('data-deliveries-tab') === tabName;
                t.classList.toggle('active', isActive);
                t.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            document.querySelectorAll('.rider-deliveries-panel').forEach(function (p) {
                var isActive = p.getAttribute('data-deliveries-panel') === tabName;
                p.classList.toggle('active', isActive);
            });

            CURRENT_TAB = tabName;
        }

        function initTabDelegation() {
            document.querySelectorAll('.rider-deliveries-tab').forEach(function (tab) {
                tab.addEventListener('click', function (event) {
                    event.preventDefault();
                    var tabName = tab.getAttribute('data-deliveries-tab') || 'active';
                    setActiveTab(tabName);
                });
            });
        }

        // ============================================
        // COUNT BADGES
        // ============================================

        function setCountBadge(id, count) {
            var el = document.getElementById(id);
            if (!el) return;
            if (count > 0) {
                el.textContent = String(count);
                el.style.display = '';
            } else {
                el.style.display = 'none';
            }
        }

        // ============================================
        // POLL
        // ============================================

        function pollDeliveries() {
            var body = new FormData();
            body.append('action', 'list');
            body.append('csrf_token', CSRF_TOKEN);

            fetch(PANEL_ENDPOINT, {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || data.status !== 'success') return;

                    var allRows = Array.isArray(data.rows) ? data.rows : [];

                    var activeRows = allRows.filter(function (r) {
                        return r.status === 'picking_up' || r.status === 'delivering';
                    });

                    var assignedRows = allRows.filter(function (r) {
                        return r.status === 'rider_pending';
                    });

                    replaceList('deliveriesActiveList',   activeRows,   renderActiveCard,   emptyActiveHtml);
                    replaceList('deliveriesAssignedList', assignedRows, renderAssignedCard, emptyAssignedHtml);

                    setCountBadge('deliveriesActiveCount',   activeRows.length);
                    setCountBadge('deliveriesAssignedCount', assignedRows.length);
                })
                .catch(function () {
                    // Silent. Next tick retries.
                });
        }

        // ============================================
        // MESSAGING WINDOW GUARD — HISTORY TAB
        //
        // The History list is rendered once by the server and is
        // never re-rendered by the poll. Each .rider-history-actions
        // block that carries a data-message-window-ends attribute is
        // checked on a 30-second tick. Once the timestamp has passed,
        // the block's buttons are removed and replaced with a short
        // muted note, and the block itself is marked data-expired so
        // the tick does not try to touch it again.
        //
        // A capture-phase click listener is also installed on
        // [data-rider-chat-open]. If a rider clicks a History Message
        // button after the window has closed but before the next tick
        // has fired, this listener stops the event before the chat
        // modal's own delegated listener sees it. The two guards
        // close the same gap from opposite ends.
        // ============================================

        function expireHistoryBlock(block) {
            if (block.getAttribute('data-expired') === '1') return;
            block.setAttribute('data-expired', '1');

            var note = document.createElement('p');
            note.className = 'rider-history-window-closed';
            note.textContent = 'The 1-hour messaging window for this order has closed.';
            block.parentNode.replaceChild(note, block);
        }

        function sweepExpiredHistoryBlocks() {
            var now = Date.now();
            var blocks = document.querySelectorAll(
                '.rider-history-actions[data-message-window-ends]'
            );

            for (var i = 0; i < blocks.length; i++) {
                var block = blocks[i];
                var raw = block.getAttribute('data-message-window-ends') || '';
                var ends = parseInt(raw, 10);
                if (isNaN(ends)) continue;
                if (ends <= now) {
                    expireHistoryBlock(block);
                }
            }
        }

        function initHistoryWindowGuards() {
            // Run once immediately in case the page was left open
            // across the window boundary before this script loaded.
            sweepExpiredHistoryBlocks();

            // Then sweep on a fixed cadence.
            setInterval(sweepExpiredHistoryBlocks, MESSAGE_WINDOW_TICK_MS);

            // Capture-phase guard for the race between a window
            // expiring and the next sweep.
            document.addEventListener('click', function (event) {
                var trigger = event.target.closest('[data-rider-chat-open]');
                if (!trigger) return;

                var block = trigger.closest('.rider-history-actions[data-message-window-ends]');
                if (!block) return;

                var raw = block.getAttribute('data-message-window-ends') || '';
                var ends = parseInt(raw, 10);
                if (isNaN(ends)) return;

                if (ends <= Date.now()) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (typeof event.stopImmediatePropagation === 'function') {
                        event.stopImmediatePropagation();
                    }
                    expireHistoryBlock(block);
                }
            }, true);
        }

        // ============================================
        // CONFIRM MODAL
        // ============================================

        var confirmModal     = document.getElementById('riderConfirmModal');
        var confirmTitleEl   = document.getElementById('riderConfirmTitle');
        var confirmMessageEl = document.getElementById('riderConfirmMessage');
        var confirmBtn       = document.getElementById('riderConfirmBtn');

        var pendingConfirm = null;

        function openConfirmModal(opts) {
            if (!confirmModal || !confirmBtn) return;

            if (confirmTitleEl)   confirmTitleEl.textContent   = opts.title   || 'Confirm';
            if (confirmMessageEl) confirmMessageEl.textContent = opts.message || '';

            confirmBtn.textContent = opts.label || 'Confirm';
            confirmBtn.disabled    = false;

            confirmModal.dataset.variant = opts.variant === 'danger' ? 'danger' : 'primary';
            confirmModal.dataset.icon    = opts.icon || 'accept';

            pendingConfirm = typeof opts.onConfirm === 'function' ? opts.onConfirm : null;

            document.body.style.overflow = 'hidden';
            confirmModal.style.display = 'flex';
            void confirmModal.offsetWidth;
            confirmModal.classList.add('is-open');

            setTimeout(function () { confirmBtn.focus(); }, 80);
        }

        function closeConfirmModal() {
            if (!confirmModal) return;

            confirmModal.classList.remove('is-open');
            setTimeout(function () {
                if (!confirmModal.classList.contains('is-open')) {
                    confirmModal.style.display = 'none';
                    document.body.style.overflow = '';
                    confirmModal.dataset.variant = 'primary';
                    confirmModal.dataset.icon    = 'accept';
                }
            }, 220);

            pendingConfirm = null;
        }

        if (confirmModal) {
            confirmModal.querySelectorAll('[data-close-confirm]').forEach(function (el) {
                el.addEventListener('click', closeConfirmModal);
            });
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                var fn = pendingConfirm;
                if (typeof fn !== 'function') {
                    closeConfirmModal();
                    return;
                }

                confirmBtn.disabled    = true;
                confirmBtn.textContent = 'Processing\u2026';

                fn(function done() {
                    closeConfirmModal();
                }, function failed() {
                    confirmBtn.disabled    = false;
                    confirmBtn.textContent = confirmBtn.dataset.originalLabel || 'Confirm';
                });
            });
        }

        // Delegated click on .delivery-status-btn. Works for buttons
        // that existed at page load and for buttons rendered by the
        // poll.
        document.addEventListener('click', function (event) {
            var btn = event.target.closest('.delivery-status-btn');
            if (!btn) return;

            event.preventDefault();

            var orderId = parseInt(btn.dataset.orderId, 10) || 0;
            var action  = btn.dataset.action || '';

            if (orderId <= 0 || action === '') return;

            var title   = btn.dataset.confirmTitle   || 'Confirm this action?';
            var message = btn.dataset.confirmMessage || '';
            var label   = btn.dataset.confirmLabel   || 'Confirm';
            var variant = btn.dataset.confirmVariant || 'primary';
            var icon    = btn.dataset.confirmIcon    || 'accept';

            var clickedButton = btn;

            openConfirmModal({
                title:   title,
                message: message,
                label:   label,
                variant: variant,
                icon:    icon,
                onConfirm: function (done, failed) {
                    submitDeliveryAction(clickedButton, orderId, action, done, failed);
                }
            });
        });

        function submitDeliveryAction(btn, orderId, action, done, failed) {
            var originalText = btn.textContent;

            btn.disabled    = true;
            btn.textContent = 'Updating\u2026';

            var body = new URLSearchParams();
            body.append('csrf_token', CSRF_TOKEN);
            body.append('action', action);
            body.append('order_id', String(orderId));

            fetch('../backend/handlers/rider-handler.php', {
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
                        showToast(data.message || 'Delivery updated', 'success');
                        if (typeof done === 'function') done();
                        // Immediate refresh from the server's own view
                        // so the tabs reflect the new state without
                        // waiting for the next tick.
                        pollDeliveries();
                        return;
                    }

                    btn.disabled    = false;
                    btn.textContent = originalText;

                    showToast((data && data.message) || 'Could not update delivery', 'error');
                    if (typeof failed === 'function') failed();
                })
                .catch(function () {
                    btn.disabled    = false;
                    btn.textContent = originalText;

                    showToast('Network error. Please try again.', 'error');
                    if (typeof failed === 'function') failed();
                });
        }

        // ============================================
        // TOAST
        // ============================================

        function showToast(message, type) {
            var toast = document.getElementById('riderToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'riderToast';
                toast.style.cssText = [
                    'position:fixed', 'top:80px', 'right:20px',
                    'padding:12px 20px', 'border-radius:8px',
                    'font-size:14px', 'font-weight:500', 'z-index:9999',
                    'transform:translateX(120%)',
                    'transition:transform .3s cubic-bezier(.4,0,.2,1)',
                    'max-width:360px', 'box-shadow:0 4px 16px rgba(0,0,0,.15)'
                ].join(';');
                document.body.appendChild(toast);
            }

            var palette = {
                success: ['#d1fae5', '#065f46'],
                error:   ['#fee2e2', '#991b1b'],
                info:    ['#dbeafe', '#1e40af']
            };
            var colors = palette[type] || palette.info;
            toast.style.background = colors[0];
            toast.style.color      = colors[1];
            toast.textContent      = message;

            void toast.offsetWidth;
            toast.style.transform = 'translateX(0)';

            clearTimeout(toast._timer);
            toast._timer = setTimeout(function () {
                toast.style.transform = 'translateX(120%)';
            }, 2800);
        }

        // ============================================
        // KEYBOARD
        // ============================================

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (confirmModal && confirmModal.classList.contains('is-open')) {
                closeConfirmModal();
            }
        });

        // ============================================
        // BOOTSTRAP
        // ============================================

        initTabDelegation();
        initHistoryWindowGuards();
        pollDeliveries();
        setInterval(pollDeliveries, POLL_INTERVAL_MS);

    });
})();