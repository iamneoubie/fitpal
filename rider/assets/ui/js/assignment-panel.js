/**
 * FitPal Rider Assignment Panel — client behaviour.
 *
 * Owns the bottom-anchored assignment panel that appears on every
 * authenticated rider page.
 *
 * ---------------------------------------------------------------------
 * POLL SHAPE
 * ---------------------------------------------------------------------
 * Full-snapshot poll on every tick: action=list, replace the whole
 * list. The cost is a bounded payload (server caps at 20 rows); the
 * benefit is that every status change the panel cares about is
 * visible on the same tick it happened.
 *
 * ---------------------------------------------------------------------
 * COLLAPSE STATE PRESERVATION (v5.1)
 * ---------------------------------------------------------------------
 * replaceRows() rebuilds the list from scratch on every tick. Before
 * this revision it also reset every row's collapse state to the
 * server-derived default, so a row the user had expanded collapsed
 * again on the next 5-second tick. Users read that as "the detail
 * auto-collapses when I expand it."
 *
 * The fix reads the current DOM's is-collapsed class per
 * data-order-id before wiping, and re-applies it to the freshly
 * rendered row. A row whose state is not present in the snapshot
 * (because it was added by this tick) keeps the default the server
 * chose for its status: expanded for rider_pending, collapsed for
 * everything else.
 *
 * ---------------------------------------------------------------------
 * MARKUP CONTRACT
 * ---------------------------------------------------------------------
 * Row shape:
 *
 *   article.assignment-row [.is-collapsed]
 *     header.assignment-row-top
 *       span.assignment-row-order
 *       span.assignment-row-status
 *       div.assignment-row-actions
 *         button.assignment-action-btn.is-primary
 *           img.assignment-action-icon
 *         ...
 *     p.assignment-row-summary           visible only when .is-collapsed
 *     div.assignment-row-meta            hidden when .is-collapsed
 *       p.assignment-row-line
 *         span.label
 *         span.value
 *     button.assignment-row-expand
 *       img.assignment-row-expand-icon
 *       span
 *
 * Collapse is a class. Visibility of summary vs meta is a CSS
 * consequence of that class.
 *
 * ---------------------------------------------------------------------
 * ROW ACTION ICONS
 * ---------------------------------------------------------------------
 *   Accept         → verified-fill.svg
 *   Decline        → close-circle-fill.svg
 *   Mark Picked Up → package.svg
 *   Mark Delivered → verified-badge-fill.svg
 *   Message        → contact-us-line.svg
 *   Call           → phone-fill.svg
 *
 * ---------------------------------------------------------------------
 * CONFIG
 * ---------------------------------------------------------------------
 * The page writes these globals before loading this file:
 *
 *     window.RIDER_CSRF_TOKEN
 *     window.RIDER_ASSET_BASE
 *     window.RIDER_HANDLER_ENDPOINT
 *     window.RIDER_ASSIGNMENT_ENDPOINT   (fallback only)
 *
 * The authoritative endpoint is the wrapper's data-endpoint
 * attribute, populated server-side.
 *
 * @package FitPal
 * @version 5.1 — replaceRows() preserves each row's collapse state
 *                across re-renders. The restaurant meta line now
 *                also renders the branch name and branch address
 *                when the server provides them.
 *
 *                (5.0: full-snapshot poll. 4.0: row markup and
 *                action buttons. 3.2: modals class-driven. 3.1:
 *                endpoint from wrapper. 3.0: page endpoint globals.
 *                2.0: single endpoint. 1.4: notification modal.
 *                1.3: availability modal. 1.2: delta poll. 1.1:
 *                initial panel.)
 */
(function () {
    'use strict';

    // -----------------------------------------------------------------
    // EARLY ELEMENT HANDLES
    // -----------------------------------------------------------------

    var panel   = document.getElementById('assignmentPanel');
    var wrapper = document.getElementById('assignmentPanelWrapper');
    if (!panel || !wrapper) return;

    // -----------------------------------------------------------------
    // CONFIG
    // -----------------------------------------------------------------

    var CSRF_TOKEN = window.RIDER_CSRF_TOKEN || '';
    var ASSET_BASE = window.RIDER_ASSET_BASE || '';

    var ASSIGNMENT_ENDPOINT = '';

    var dataEndpoint = wrapper.getAttribute('data-endpoint');
    if (typeof dataEndpoint === 'string' && dataEndpoint !== '') {
        ASSIGNMENT_ENDPOINT = dataEndpoint;
    } else if (typeof window.RIDER_ASSIGNMENT_ENDPOINT === 'string' &&
               window.RIDER_ASSIGNMENT_ENDPOINT !== '') {
        ASSIGNMENT_ENDPOINT = window.RIDER_ASSIGNMENT_ENDPOINT;
    } else {
        ASSIGNMENT_ENDPOINT = '../backend/handlers/assignment-handler.php';
    }

    var RIDER_ENDPOINT = window.RIDER_HANDLER_ENDPOINT
        || '../backend/handlers/rider-handler.php';

    var POLL_INTERVAL_MS = 5000;

    // -----------------------------------------------------------------
    // REMAINING ELEMENT HANDLES
    // -----------------------------------------------------------------

    var toggleBtn = document.getElementById('assignmentPanelToggle');
    var header    = document.getElementById('assignmentPanelHeader');
    var countBadge = document.getElementById('assignmentCountBadge');
    var listEl    = document.getElementById('assignmentList');
    var emptyState = document.getElementById('assignmentEmptyState');
    var offlineHint = document.getElementById('assignmentOfflineHint');
    var ineligibleHint = document.getElementById('assignmentIneligibleHint');

    var statusBtn    = document.getElementById('assignmentPanelStatus');
    var statusTextEl = document.getElementById('assignmentPanelStatusText');
    var statusActionEl = document.getElementById('assignmentPanelStatusAction');

    var notifyModal = document.getElementById('assignmentNotifyModal');
    var notifyOrderIdEl = document.getElementById('assignmentNotifyOrderId');
    var notifyRestaurantEl = document.getElementById('assignmentNotifyRestaurant');
    var notifyCustomerEl = document.getElementById('assignmentNotifyCustomer');
    var notifyTotalEl = document.getElementById('assignmentNotifyTotal');
    var notifyAcceptBtn = document.getElementById('assignmentNotifyAcceptBtn');
    var notifyDeclineBtn = document.getElementById('assignmentNotifyDeclineBtn');
    var notifyDismissBtn = document.getElementById('assignmentNotifyDismissBtn');

    var availabilityModal = document.getElementById('assignmentAvailabilityModal');
    var availabilityTitleEl = document.getElementById('assignmentAvailabilityTitle');
    var availabilityTextEl = document.getElementById('assignmentAvailabilityText');
    var availabilityConfirmBtn = document.getElementById('assignmentAvailabilityConfirmBtn');
    var availabilityCancelBtn = document.getElementById('assignmentAvailabilityCancelBtn');

    // -----------------------------------------------------------------
    // ICON FILES
    // -----------------------------------------------------------------

    var ICONS = {
        accept:         'verified-fill.svg',
        decline:        'close-circle-fill.svg',
        picked_up:      'package.svg',
        delivered:      'verified-badge-fill.svg',
        message:        'contact-us-line.svg',
        call:           'phone-fill.svg',
        expand:         'arrow-drop-down-line.svg'
    };

    function iconUrl(file) {
        return ASSET_BASE + 'assets/images/icons/' + file;
    }

    // -----------------------------------------------------------------
    // STATE
    // -----------------------------------------------------------------

    var isOpen   = false;
    var online   = false;
    var eligible = true;

    var dismissedOfferIds = Object.create(null);
    var notifyOrderId = 0;

    // -----------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------

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

    function postAssignment(action, payload) {
        var body = new FormData();
        body.append('action', action);
        body.append('csrf_token', CSRF_TOKEN);

        if (payload && typeof payload === 'object') {
            Object.keys(payload).forEach(function (key) {
                body.append(key, String(payload[key]));
            });
        }

        return fetch(ASSIGNMENT_ENDPOINT, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).then(function (res) { return res.json(); });
    }

    function postRider(action, payload) {
        var body = new FormData();
        body.append('action', action);
        body.append('csrf_token', CSRF_TOKEN);

        if (payload && typeof payload === 'object') {
            Object.keys(payload).forEach(function (key) {
                body.append(key, String(payload[key]));
            });
        }

        return fetch(RIDER_ENDPOINT, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).then(function (res) { return res.json(); });
    }

    // -----------------------------------------------------------------
    // MODAL VISIBILITY
    // -----------------------------------------------------------------

    function showModal(modal) {
        if (!modal) return;
        modal.classList.add('active');
    }

    function hideModal(modal) {
        if (!modal) return;
        modal.classList.remove('active');
    }

    // -----------------------------------------------------------------
    // PANEL OPEN / CLOSE
    // -----------------------------------------------------------------

    function setPanelOpen(open) {
        isOpen = !!open;

        if (isOpen) {
            panel.classList.remove('closed');
            panel.classList.add('open');
        } else {
            panel.classList.remove('open');
            panel.classList.add('closed');
        }

        if (toggleBtn) {
            toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
    }

    function initToggle() {
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function (event) {
                event.stopPropagation();
                setPanelOpen(!isOpen);
            });
        }

        if (header) {
            header.addEventListener('click', function (event) {
                if (event.target.closest('#assignmentPanelStatus')) return;
                if (event.target.closest('#assignmentPanelToggle')) return;

                setPanelOpen(!isOpen);
            });
        }
    }

    // -----------------------------------------------------------------
    // AVAILABILITY PILL
    // -----------------------------------------------------------------

    function applyAvailabilityState() {
        if (!statusBtn || !statusTextEl) return;

        var state;
        var label;
        var action;

        if (!eligible) {
            state  = 'is-inactive';
            label  = 'Inactive';
            action = '';
        } else if (online) {
            state  = 'is-online';
            label  = 'Online';
            action = 'Go Offline';
        } else {
            state  = 'is-offline';
            label  = 'Offline';
            action = 'Go Online';
        }

        statusBtn.classList.remove('is-online', 'is-offline', 'is-inactive');
        statusBtn.classList.add(state);

        statusTextEl.textContent = label;
        if (statusActionEl) statusActionEl.textContent = action;

        statusBtn.setAttribute(
            'aria-label',
            'Availability: ' + label
            + (action !== '' ? ' \u2014 press to ' + action.toLowerCase() : '')
        );
    }

    function initAvailabilityPill() {
        if (!statusBtn) return;

        statusBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            openAvailabilityModal();
        });
    }

    // -----------------------------------------------------------------
    // AVAILABILITY MODAL
    // -----------------------------------------------------------------

    function setModalVariant(variant, iconKey) {
        if (!availabilityModal) return;
        availabilityModal.setAttribute('data-variant', variant);
        availabilityModal.setAttribute('data-icon', iconKey);
    }

    function setConfirmButtonTone(variant) {
        if (!availabilityConfirmBtn) return;
        availabilityConfirmBtn.classList.remove(
            'assignment-availability-btn-primary',
            'assignment-availability-btn-danger',
            'assignment-availability-btn-neutral'
        );
        availabilityConfirmBtn.classList.add(
            variant === 'danger'
                ? 'assignment-availability-btn-danger'
                : variant === 'neutral'
                    ? 'assignment-availability-btn-neutral'
                    : 'assignment-availability-btn-primary'
        );
    }

    function openAvailabilityModal() {
        if (!availabilityModal) return;

        if (!eligible) {
            setModalVariant('neutral', 'blocked');
            setConfirmButtonTone('neutral');
            if (availabilityTitleEl) availabilityTitleEl.textContent = 'Account not active';
            if (availabilityTextEl) {
                availabilityTextEl.textContent =
                    'Your account must be verified before you can go online.';
            }
            if (availabilityCancelBtn) availabilityCancelBtn.hidden = true;
            if (availabilityConfirmBtn) {
                availabilityConfirmBtn.textContent = 'OK';
                availabilityConfirmBtn.setAttribute('data-confirm-mode', 'close');
            }
            showModal(availabilityModal);
            return;
        }

        if (online) {
            setModalVariant('danger', 'offline');
            setConfirmButtonTone('danger');
            if (availabilityTitleEl) availabilityTitleEl.textContent = 'Go offline?';
            if (availabilityTextEl) {
                availabilityTextEl.textContent =
                    'You will stop receiving new assignments while you are offline.';
            }
            if (availabilityCancelBtn) availabilityCancelBtn.hidden = false;
            if (availabilityConfirmBtn) {
                availabilityConfirmBtn.textContent = 'Go Offline';
                availabilityConfirmBtn.setAttribute('data-confirm-mode', 'offline');
            }
        } else {
            setModalVariant('primary', 'online');
            setConfirmButtonTone('primary');
            if (availabilityTitleEl) availabilityTitleEl.textContent = 'Go online?';
            if (availabilityTextEl) {
                availabilityTextEl.textContent =
                    'You will start receiving new assignments immediately.';
            }
            if (availabilityCancelBtn) availabilityCancelBtn.hidden = false;
            if (availabilityConfirmBtn) {
                availabilityConfirmBtn.textContent = 'Go Online';
                availabilityConfirmBtn.setAttribute('data-confirm-mode', 'online');
            }
        }

        showModal(availabilityModal);
    }

    function closeAvailabilityModal() {
        if (!availabilityModal) return;
        hideModal(availabilityModal);
    }

    function initAvailabilityModal() {
        if (!availabilityModal) return;

        availabilityModal.addEventListener('click', function (event) {
            if (event.target.closest('[data-assignment-availability-dismiss]')) {
                closeAvailabilityModal();
            }
        });

        if (availabilityConfirmBtn) {
            availabilityConfirmBtn.addEventListener('click', function () {
                var mode = availabilityConfirmBtn.getAttribute('data-confirm-mode') || '';

                if (mode === 'close') {
                    closeAvailabilityModal();
                    return;
                }

                var target = (mode === 'offline') ? 0 : 1;

                availabilityConfirmBtn.disabled = true;
                var originalText = availabilityConfirmBtn.textContent;
                availabilityConfirmBtn.textContent = 'Saving\u2026';

                postRider('toggle_availability', { is_available: target })
                    .then(function (data) {
                        availabilityConfirmBtn.disabled = false;
                        availabilityConfirmBtn.textContent = originalText;

                        if (data && data.status === 'success') {
                            online = (target === 1);
                            applyAvailabilityState();
                            closeAvailabilityModal();
                            fetchNow();
                            return;
                        }

                        if (availabilityTextEl) {
                            availabilityTextEl.textContent = (data && data.message)
                                ? data.message
                                : 'Could not update your availability. Please try again.';
                        }
                    })
                    .catch(function () {
                        availabilityConfirmBtn.disabled = false;
                        availabilityConfirmBtn.textContent = originalText;

                        if (availabilityTextEl) {
                            availabilityTextEl.textContent =
                                'A network error occurred. Please try again.';
                        }
                    });
            });
        }
    }

    // -----------------------------------------------------------------
    // CHAT HANDOFF
    // -----------------------------------------------------------------

    function openRiderChat(orderId, recipient, subtitle) {
        var chat = window.FitPalRiderChat;

        if (!chat || typeof chat.open !== 'function') {
            if (window.console && console.warn) {
                console.warn(
                    '[assignment-panel] window.FitPalRiderChat.open is not available. '
                    + 'rider/assets/ui/js/rider-chat-modal.js must publish the chat '
                    + 'interface before the Message action can open a conversation.'
                );
            }
            return;
        }

        chat.open({
            orderId:   orderId,
            recipient: recipient,
            subtitle:  subtitle
        });
    }

    // -----------------------------------------------------------------
    // ROW MARKUP
    // -----------------------------------------------------------------

    function actionButton(opts) {
        var tone = opts.tone || 'is-neutral';
        var label = opts.label || '';
        var iconFile = ICONS[opts.icon] || ICONS.message;

        var attrs = [
            'type="button"',
            'class="assignment-action-btn ' + tone + '"',
            'title="' + escapeHtml(label) + '"',
            'aria-label="' + escapeHtml(label) + '"'
        ];

        if (opts.dataAction) {
            attrs.push('data-row-action="' + escapeHtml(opts.dataAction) + '"');
        }
        if (opts.dataOrderId) {
            attrs.push('data-order-id="' + escapeHtml(String(opts.dataOrderId)) + '"');
        }
        if (opts.dataChannel) {
            attrs.push('data-channel="' + escapeHtml(opts.dataChannel) + '"');
        }
        if (opts.dataSubtitle) {
            attrs.push('data-subtitle="' + escapeHtml(opts.dataSubtitle) + '"');
        }

        return (
            '<button ' + attrs.join(' ') + '>' +
                '<img src="' + escapeHtml(iconUrl(iconFile)) + '" alt="" ' +
                     'class="assignment-action-icon" width="16" height="16">' +
            '</button>'
        );
    }

    function rowActions(row) {
        var status = row.status || '';
        var id     = row.order_id;
        var parts  = [];

        if (status === 'rider_pending') {
            parts.push(actionButton({
                tone: 'is-danger',
                icon: 'decline',
                label: 'Decline',
                dataAction: 'decline',
                dataOrderId: id
            }));
            parts.push(actionButton({
                tone: 'is-primary',
                icon: 'accept',
                label: 'Accept',
                dataAction: 'accept',
                dataOrderId: id
            }));
        } else if (status === 'picking_up') {
            parts.push(actionButton({
                tone: 'is-primary',
                icon: 'picked_up',
                label: 'Mark Picked Up',
                dataAction: 'mark_picked_up',
                dataOrderId: id
            }));
        } else if (status === 'delivering') {
            parts.push(actionButton({
                tone: 'is-primary',
                icon: 'delivered',
                label: 'Mark Delivered',
                dataAction: 'mark_delivered',
                dataOrderId: id
            }));
        }

        if (row.message_enabled && row.message_channel) {
            parts.push(actionButton({
                tone: 'is-neutral',
                icon: 'message',
                label: 'Message',
                dataAction: 'message',
                dataOrderId: id,
                dataChannel: row.message_channel,
                dataSubtitle: 'Order #' + id + ' \u2022 ' + (row.customer_name || '')
            }));
        }

        if (row.call_number) {
            parts.push(
                '<a class="assignment-action-btn is-neutral" ' +
                   'href="tel:' + escapeHtml(row.call_number) + '" ' +
                   'title="' + escapeHtml(row.call_label || 'Call') + '" ' +
                   'aria-label="' + escapeHtml(row.call_label || 'Call') + '">' +
                    '<img src="' + escapeHtml(iconUrl(ICONS.call)) + '" alt="" ' +
                         'class="assignment-action-icon" width="16" height="16">' +
                '</a>'
            );
        }

        return parts.join('');
    }

    /**
     * Build the pickup meta lines for a row.
     *
     * The Restaurant block shows the restaurant name, the branch
     * name (when distinct from the restaurant), and the branch's
     * full address (block, barangay, city, province, region, postal
     * code, country — whichever the server provided).
     *
     * The Deliver-to block shows the destination the customer
     * supplied at checkout. It is a single free-form string.
     *
     * Item count and order total are their own rows.
     */
    function rowMetaLines(row) {
        var lines = [];

        // ---- Restaurant -------------------------------------------------
        var restaurantName = row.restaurant_name || '\u2014';
        var branchName     = row.branch_name     || '';
        var branchAddress  = row.branch_address  || '';

        // Compose the restaurant value: name, then a middle dot and
        // the branch name only when the two differ. The customer's
        // destination is a single string; the pickup point is two —
        // the business and the physical branch within it.
        var restaurantValue = restaurantName;
        if (branchName !== '' && branchName !== restaurantName) {
            restaurantValue += ' \u2014 ' + branchName;
        }

        lines.push(['Restaurant', restaurantValue]);

        // The address gets its own line so a long address does not
        // squeeze the restaurant name into ellipsis. Skip when the
        // server had no address on file.
        if (branchAddress !== '') {
            lines.push(['Pickup at', branchAddress]);
        }

        // ---- Customer ---------------------------------------------------
        lines.push(['Customer', row.customer_name || '\u2014']);

        // ---- Destination ------------------------------------------------
        lines.push(['Deliver to', row.destination || '\u2014']);

        // ---- Item count -------------------------------------------------
        lines.push(['Items', String(row.item_count || 0)]);

        // ---- Total ------------------------------------------------------
        lines.push(['Total', formatCurrency(row.order_total)]);

        return lines.map(function (pair) {
            return (
                '<p class="assignment-row-line">' +
                    '<span class="label">' + escapeHtml(pair[0]) + '</span>' +
                    '<span class="value">' + escapeHtml(pair[1]) + '</span>' +
                '</p>'
            );
        }).join('');
    }

    function rowSummaryText(row) {
        var restaurant = row.restaurant_name || 'Restaurant';
        var customer   = row.customer_name   || 'Customer';
        return restaurant + ' \u2192 ' + customer
             + ' \u2022 ' + (row.item_count || 0) + ' item'
             + ((row.item_count || 0) === 1 ? '' : 's')
             + ' \u2022 ' + formatCurrency(row.order_total);
    }

    function renderRow(row, collapsed) {
        var status       = row.status || '';
        var badgeClass   = row.status_badge || 'badge-secondary';
        var statusLabel  = row.status_label || status;

        var classes = 'assignment-row';
        if (collapsed) classes += ' is-collapsed';

        return (
            '<article class="' + classes + '" ' +
                     'data-order-id="' + row.order_id + '" ' +
                     'data-order-status="' + escapeHtml(status) + '">' +

                '<header class="assignment-row-top" tabindex="0">' +
                    '<span class="assignment-row-order">Order #' + row.order_id + '</span>' +
                    '<span class="assignment-row-status ' + escapeHtml(badgeClass) + '">' +
                        escapeHtml(statusLabel) +
                    '</span>' +
                    '<div class="assignment-row-actions">' +
                        rowActions(row) +
                    '</div>' +
                '</header>' +

                '<p class="assignment-row-summary">' +
                    escapeHtml(rowSummaryText(row)) +
                '</p>' +

                '<div class="assignment-row-meta">' +
                    rowMetaLines(row) +
                '</div>' +

                '<button type="button" class="assignment-row-expand" ' +
                        'aria-expanded="' + (collapsed ? 'false' : 'true') + '">' +
                    '<img src="' + escapeHtml(iconUrl(ICONS.expand)) + '" alt="" ' +
                         'class="assignment-row-expand-icon" width="12" height="12">' +
                    '<span>' + (collapsed ? 'Details' : 'Hide') + '</span>' +
                '</button>' +
            '</article>'
        );
    }

    /**
     * Read the collapse state of every row currently in the DOM.
     *
     * Returns a map of order_id (string) to a boolean is-collapsed.
     * replaceRows() uses this snapshot to re-apply per-row state to
     * the freshly rendered rows, so a poll never resets a row the
     * user has expanded.
     */
    function snapshotCollapseState() {
        var state = Object.create(null);
        if (!listEl) return state;

        var rows = listEl.querySelectorAll('.assignment-row[data-order-id]');
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var id  = row.getAttribute('data-order-id');
            if (!id) continue;
            state[id] = row.classList.contains('is-collapsed');
        }
        return state;
    }

    /**
     * Replace the entire list from a server-provided rows array.
     *
     * Before wiping, this reads the collapse state of every row in
     * the DOM. After rendering, it re-applies that state per
     * order_id. A row whose id is not in the snapshot is one the
     * server just added; it keeps the default collapse state for its
     * status (expanded for rider_pending, collapsed for everything
     * else).
     *
     * @param {Array} rows
     */
    function replaceRows(rows) {
        if (!listEl) return;

        var previousState = snapshotCollapseState();

        listEl.innerHTML = '';

        if (!Array.isArray(rows) || rows.length === 0) {
            return;
        }

        var html = rows.map(function (row) {
            var id = String(row.order_id);
            var collapsed;

            if (Object.prototype.hasOwnProperty.call(previousState, id)) {
                collapsed = previousState[id];
            } else {
                // Default for a newly seen row: expand rider_pending
                // (the rider has a decision to make), collapse the
                // rest.
                collapsed = (row.status !== 'rider_pending');
            }

            return renderRow(row, collapsed);
        }).join('');

        listEl.innerHTML = html;
    }

    // -----------------------------------------------------------------
    // BADGES, HINTS, EMPTY STATE
    // -----------------------------------------------------------------

    function applyCounts(counts) {
        if (!countBadge || !counts) return;
        var total = parseInt(counts.total || 0, 10);
        if (total > 0) {
            countBadge.textContent = String(total);
            countBadge.style.display = '';
        } else {
            countBadge.style.display = 'none';
        }
    }

    function applyEligibilityState() {
        if (offlineHint)    offlineHint.hidden    = (!eligible) || online;
        if (ineligibleHint) ineligibleHint.hidden = eligible;
    }

    function applyEmptyState(totalAssignments) {
        if (!emptyState) return;
        emptyState.hidden = (totalAssignments > 0) || !eligible;
    }

    // -----------------------------------------------------------------
    // ROW ACTION DELEGATION
    // -----------------------------------------------------------------

    function handleRowAction(action, orderId, btn) {
        if (orderId <= 0) return;

        if (action === 'message') {
            var channel  = btn.getAttribute('data-channel') || 'customer';
            var subtitle = btn.getAttribute('data-subtitle') || '';
            openRiderChat(orderId, channel, subtitle);
            return;
        }

        var isPostable =
            action === 'accept' ||
            action === 'decline' ||
            action === 'mark_picked_up' ||
            action === 'mark_delivered';

        if (!isPostable) return;

        var originalHtml = btn.innerHTML;
        btn.disabled = true;

        postAssignment(action, { order_id: orderId })
            .then(function (data) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;

                if (data && data.status === 'success') {
                    fetchNow();
                    return;
                }

                var message = (data && data.message)
                    ? data.message
                    : 'Could not complete that action.';
                openNotice(message);
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                openNotice('A network error occurred. Please try again.');
            });
    }

    function openNotice(message) {
        if (!notifyModal) return;

        if (notifyOrderIdEl)   notifyOrderIdEl.textContent   = '';
        if (notifyRestaurantEl) notifyRestaurantEl.textContent = '';
        if (notifyCustomerEl)  notifyCustomerEl.textContent  = message;
        if (notifyTotalEl)     notifyTotalEl.textContent     = '';

        if (notifyAcceptBtn)  notifyAcceptBtn.hidden  = true;
        if (notifyDeclineBtn) notifyDeclineBtn.hidden = true;
        if (notifyDismissBtn) {
            notifyDismissBtn.hidden = false;
            notifyDismissBtn.textContent = 'Close';
        }

        showModal(notifyModal);
    }

    function initRowDelegation() {
        if (!listEl) return;

        listEl.addEventListener('click', function (event) {
            // Order matters: the expand chevron and the action
            // buttons both live inside the row, and the row header
            // itself is clickable. Match the most specific target
            // first.
            var expandBtn = event.target.closest('.assignment-row-expand');
            if (expandBtn) {
                event.preventDefault();
                event.stopPropagation();
                toggleRowCollapse(expandBtn);
                return;
            }

            var actionBtn = event.target.closest('[data-row-action]');
            if (actionBtn) {
                event.preventDefault();
                event.stopPropagation();

                var action  = actionBtn.getAttribute('data-row-action') || '';
                var orderId = parseInt(actionBtn.getAttribute('data-order-id') || '0', 10);

                handleRowAction(action, orderId, actionBtn);
                return;
            }

            var top = event.target.closest('.assignment-row-top');
            if (top) {
                var article = top.closest('.assignment-row');
                var chevron = article
                    ? article.querySelector('.assignment-row-expand')
                    : null;
                if (chevron) toggleRowCollapse(chevron);
            }
        });

        listEl.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;

            var top = event.target.closest('.assignment-row-top');
            if (!top) return;

            event.preventDefault();

            var article = top.closest('.assignment-row');
            var chevron = article
                ? article.querySelector('.assignment-row-expand')
                : null;
            if (chevron) toggleRowCollapse(chevron);
        });
    }

    function toggleRowCollapse(chevronBtn) {
        var article = chevronBtn.closest('.assignment-row');
        if (!article) return;

        var collapsed = article.classList.toggle('is-collapsed');

        chevronBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

        var labelEl = chevronBtn.querySelector('span');
        if (labelEl) labelEl.textContent = collapsed ? 'Details' : 'Hide';
    }

    // -----------------------------------------------------------------
    // NOTIFICATION MODAL
    // -----------------------------------------------------------------

    function openNotificationModal(row) {
        if (!notifyModal) return;

        notifyOrderId = row.order_id;

        if (notifyAcceptBtn)  notifyAcceptBtn.hidden  = false;
        if (notifyDeclineBtn) notifyDeclineBtn.hidden = false;
        if (notifyDismissBtn) {
            notifyDismissBtn.hidden = false;
            notifyDismissBtn.textContent = 'Decide later';
        }

        if (notifyOrderIdEl)   notifyOrderIdEl.textContent   = String(row.order_id);

        // Restaurant line: name plus branch plus address on one
        // string. The three parts are separated by a middle dot so
        // the modal can show them without a second grid.
        if (notifyRestaurantEl) {
            var restaurantName = row.restaurant_name || '\u2014';
            var branchName     = row.branch_name     || '';
            var branchAddress  = row.branch_address  || '';

            var parts = [restaurantName];
            if (branchName !== '' && branchName !== restaurantName) {
                parts.push(branchName);
            }
            if (branchAddress !== '') {
                parts.push(branchAddress);
            }

            notifyRestaurantEl.textContent = parts.join(' \u2014 ');
        }

        if (notifyCustomerEl)  notifyCustomerEl.textContent  = row.customer_name   || '\u2014';
        if (notifyTotalEl)     notifyTotalEl.textContent     = formatCurrency(row.order_total);

        if (notifyAcceptBtn)  notifyAcceptBtn.setAttribute('data-order-id', String(row.order_id));
        if (notifyDeclineBtn) notifyDeclineBtn.setAttribute('data-order-id', String(row.order_id));
        if (notifyDismissBtn) notifyDismissBtn.setAttribute('data-order-id', String(row.order_id));

        showModal(notifyModal);
    }

    function closeNotificationModal() {
        if (!notifyModal) return;
        hideModal(notifyModal);
        notifyOrderId = 0;
    }

    function initNotificationModal() {
        if (!notifyModal) return;

        notifyModal.addEventListener('click', function (event) {
            if (event.target.closest('[data-assignment-notify-dismiss]')) {
                closeNotificationModal();
            }
        });

        if (notifyAcceptBtn) {
            notifyAcceptBtn.addEventListener('click', function () {
                var orderId = parseInt(notifyAcceptBtn.getAttribute('data-order-id') || '0', 10);
                if (orderId <= 0) return;

                notifyAcceptBtn.disabled = true;
                var original = notifyAcceptBtn.textContent;
                notifyAcceptBtn.textContent = 'Accepting\u2026';

                postAssignment('accept', { order_id: orderId })
                    .then(function (data) {
                        notifyAcceptBtn.disabled = false;
                        notifyAcceptBtn.textContent = original;

                        if (data && data.status === 'success') {
                            closeNotificationModal();
                            fetchNow();
                        } else {
                            openNotice((data && data.message) || 'Could not accept.');
                        }
                    })
                    .catch(function () {
                        notifyAcceptBtn.disabled = false;
                        notifyAcceptBtn.textContent = original;
                        openNotice('A network error occurred. Please try again.');
                    });
            });
        }

        if (notifyDeclineBtn) {
            notifyDeclineBtn.addEventListener('click', function () {
                var orderId = parseInt(notifyDeclineBtn.getAttribute('data-order-id') || '0', 10);
                if (orderId <= 0) return;

                notifyDeclineBtn.disabled = true;
                var original = notifyDeclineBtn.textContent;
                notifyDeclineBtn.textContent = 'Declining\u2026';

                postAssignment('decline', { order_id: orderId })
                    .then(function (data) {
                        notifyDeclineBtn.disabled = false;
                        notifyDeclineBtn.textContent = original;

                        if (data && data.status === 'success') {
                            closeNotificationModal();
                            fetchNow();
                        } else {
                            openNotice((data && data.message) || 'Could not decline.');
                        }
                    })
                    .catch(function () {
                        notifyDeclineBtn.disabled = false;
                        notifyDeclineBtn.textContent = original;
                        openNotice('A network error occurred. Please try again.');
                    });
            });
        }

        if (notifyDismissBtn) {
            notifyDismissBtn.addEventListener('click', function () {
                var orderId = parseInt(notifyDismissBtn.getAttribute('data-order-id') || '0', 10);
                if (orderId > 0) {
                    dismissedOfferIds[orderId] = true;
                    postAssignment('dismiss', { order_id: orderId }).catch(function () {
                        // Best-effort.
                    });
                }
                closeNotificationModal();
            });
        }
    }

    function maybeNotifyForNewOffer(rows) {
        if (!notifyModal || !online || !eligible) return;
        if (notifyOrderId > 0) return;

        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            if (row.status !== 'rider_pending') continue;
            if (dismissedOfferIds[row.order_id]) continue;

            openNotificationModal(row);
            return;
        }
    }

    // -----------------------------------------------------------------
    // POLL
    // -----------------------------------------------------------------

    function fetchNow() {
        var body = new FormData();
        body.append('action', 'list');
        body.append('csrf_token', CSRF_TOKEN);

        fetch(ASSIGNMENT_ENDPOINT, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 'success') return;

                eligible = !!data.eligible;
                online   = !!data.online;

                applyEligibilityState();
                applyAvailabilityState();

                if (Array.isArray(data.dismissed)) {
                    data.dismissed.forEach(function (id) {
                        dismissedOfferIds[parseInt(id, 10)] = true;
                    });
                }

                if (data.counts) {
                    applyCounts(data.counts);
                    applyEmptyState(parseInt(data.counts.total || 0, 10));
                }

                var rows = Array.isArray(data.rows) ? data.rows : [];
                replaceRows(rows);

                maybeNotifyForNewOffer(rows);
            })
            .catch(function () {
                // Silent. Next tick retries.
            });
    }

    // -----------------------------------------------------------------
    // BOOTSTRAP
    // -----------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', function () {
        setPanelOpen(false);

        initToggle();
        initAvailabilityPill();
        initAvailabilityModal();
        initNotificationModal();
        initRowDelegation();

        fetchNow();
        setInterval(fetchNow, POLL_INTERVAL_MS);
    });
})();