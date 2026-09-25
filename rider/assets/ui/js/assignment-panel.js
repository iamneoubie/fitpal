/**
 * FitPal Rider Assignment Panel
 *
 * The bottom-anchored, collapsible panel that mirrors the kitchen's
 * rider_pending handoff on the rider side. Also drives:
 *
 *   - the per-assignment notification modal (Accept / Decline /
 *     Dismiss) that fires when a new rider_pending row arrives
 *     while the rider is online;
 *   - the availability modal that opens when the rider activates
 *     the availability pill in the panel header.
 *
 * Row layout contract (kept in sync with assignment-panel.css)
 * ------------------------------------------------------------
 * Each row is three bands inside a flex-column container:
 *
 *   .assignment-row
 *     .assignment-row-top       order # | status badge | actions
 *     .assignment-row-summary   one-line "Restaurant • Deliver to"
 *                               (visible only when .is-collapsed)
 *     .assignment-row-meta      the full label/value grid
 *                               (hidden when .is-collapsed)
 *     .assignment-row-expand    chevron toggle for the two above
 *
 * The actions sit INSIDE the top band so they align with the order
 * number on the same line. They are not a separate grid column.
 *
 * Click-to-navigate contract
 * --------------------------
 * Clicking the top band navigates to deliveries.php. The band is
 * given role="link", tabindex="0", and an aria-label so keyboard
 * users can reach it and activate it with Enter or Space.
 *
 * Panel state contract
 * --------------------
 * The panel's collapsed/expanded state is expressed by:
 *   - the .open class on #assignmentPanel (expanded)
 *   - the .closed class on #assignmentPanel (collapsed)
 *   - aria-expanded on #assignmentPanelToggle ("true"/"false")
 *   - the isOpen variable in this closure
 *
 * All four must always agree. openPanel() and closePanel() are the
 * only writers, they are idempotent, and they write all four on
 * every call.
 *
 * Availability pill contract
 * --------------------------
 * The pill (#assignmentPanelStatus) is a real <button>. Its click
 * opens #assignmentAvailabilityModal in one of three shapes,
 * decided by the two flags this file already tracks
 * (lastKnownOnline and hasLiveAssignments):
 *
 *   offline                     → Go Online      (primary)
 *   online, no live assignment  → Go Offline     (danger)
 *   online, with live assignment→ Blocked        (neutral, info-only)
 *
 * The modal's copy and its confirm button colour are expressed
 * through data-variant and data-icon on the modal element; CSS
 * reads those. The label swap on hover ("Online" → "Go Offline")
 * is a pure CSS concern: the pill holds two spans and CSS shows
 * exactly one on :hover / :focus-visible. JS only writes the
 * action text on each poll, because the copy depends on BOTH
 * flags.
 *
 * The confirm button POSTs to rider-handler.php with
 * action=toggle_availability. That is the same endpoint the
 * dashboard's Go Online / Go Offline button uses; nothing new
 * server-side.
 *
 * @package FitPal
 * @version 7.0 — Availability pill wired as a control.
 *
 *                The pill opens a styled availability modal with
 *                three shapes: Go Online (primary), Go Offline
 *                (danger), and Blocked when the rider has a live
 *                assignment (info-only, no state change). The
 *                modal's copy and icon and confirm-button colour
 *                are driven by data-variant and data-icon on the
 *                modal element; CSS owns the visuals. Confirm
 *                POSTs toggle_availability to rider-handler.php,
 *                then optimistically flips lastKnownOnline,
 *                repaints the pill, and polls so the assignment
 *                list resyncs.
 *
 *                updateStatusPill() now also writes the pill's
 *                action-label span text and its aria-label, so the
 *                hover verb is always correct for the current
 *                state without any hover-time JS.
 *
 *                No change to the notification modal, the row
 *                layout, the row delegation, or the panel
 *                open/closed logic from v6.0.
 *
 *                (6.0: panel state always in sync. 5.0: accept
 *                re-renders the row in place rather than removing
 *                it; wrapper no longer hides itself. 4.0: click-
 *                to-navigate on the row's top band. 3.0: three-
 *                band row layout with actions in the top band.)
 */

(function () {
    'use strict';

    // ============================================================
    // STATE
    // ============================================================
    var wrapper, panel, panelInner, header, toggleBtn, body;
    var badgeEl;
    var statusPillEl, statusTextEl, statusActionEl;
    var listEl, emptyEl, offlineHintEl, ineligibleHintEl;

    var notifyModal, notifyOrderIdEl, notifyRestaurantEl,
        notifyCustomerEl, notifyTotalEl,
        notifyAcceptBtn, notifyDeclineBtn, notifyDismissBtn;

    var availModal, availTitleEl, availTextEl,
        availConfirmBtn, availCancelBtn;

    var ENDPOINT = '';
    var RIDER_ID = 0;

    var initialized   = false;
    var isOpen        = false;
    var hasAutoOpened = false;

    var lastOrderId  = 0;
    var rowsById     = Object.create(null);
    var notifyQueue  = [];
    var dismissedIds = Object.create(null);

    var expandedIds  = Object.create(null);

    var pollTimer   = null;
    var POLL_LIVE_MS = 5000;
    var POLL_IDLE_MS = 15000;
    var lastKnownOnline   = false;
    var lastKnownEligible = false;
    var hasLiveAssignments = false;

    // ============================================================
    // INIT
    // ============================================================
    function init() {
        if (initialized) return;

        wrapper = document.getElementById('assignmentPanelWrapper');
        if (!wrapper) return;

        panel            = document.getElementById('assignmentPanel');
        panelInner       = document.getElementById('assignmentPanelInner');
        header           = document.getElementById('assignmentPanelHeader');
        toggleBtn        = document.getElementById('assignmentPanelToggle');
        body             = document.getElementById('assignmentPanelBody');
        badgeEl          = document.getElementById('assignmentCountBadge');
        statusPillEl     = document.getElementById('assignmentPanelStatus');
        statusTextEl     = document.getElementById('assignmentPanelStatusText');
        statusActionEl   = document.getElementById('assignmentPanelStatusAction');
        listEl           = document.getElementById('assignmentList');
        emptyEl          = document.getElementById('assignmentEmptyState');
        offlineHintEl    = document.getElementById('assignmentOfflineHint');
        ineligibleHintEl = document.getElementById('assignmentIneligibleHint');

        notifyModal        = document.getElementById('assignmentNotifyModal');
        notifyOrderIdEl    = document.getElementById('assignmentNotifyOrderId');
        notifyRestaurantEl = document.getElementById('assignmentNotifyRestaurant');
        notifyCustomerEl   = document.getElementById('assignmentNotifyCustomer');
        notifyTotalEl      = document.getElementById('assignmentNotifyTotal');
        notifyAcceptBtn    = document.getElementById('assignmentNotifyAcceptBtn');
        notifyDeclineBtn   = document.getElementById('assignmentNotifyDeclineBtn');
        notifyDismissBtn   = document.getElementById('assignmentNotifyDismissBtn');

        availModal       = document.getElementById('assignmentAvailabilityModal');
        availTitleEl     = document.getElementById('assignmentAvailabilityTitle');
        availTextEl      = document.getElementById('assignmentAvailabilityText');
        availConfirmBtn  = document.getElementById('assignmentAvailabilityConfirmBtn');
        availCancelBtn   = document.getElementById('assignmentAvailabilityCancelBtn');

        if (!panel || !listEl) return;

        ENDPOINT = wrapper.dataset.endpoint || '../../rider/backend/handlers/assignment-handler.php';
        RIDER_ID = parseInt(wrapper.dataset.riderId, 10) || 0;

        wrapper.style.display = 'block';

        isOpen = panel.classList.contains('open');
        if (!panel.classList.contains('open') &&
            !panel.classList.contains('closed')) {
            panel.classList.add('closed');
        }

        wireToggle();
        wireNotifyModal();
        wireAvailabilityPill();
        wireRowDelegation();
        wireVisibility();

        loadList();

        initialized = true;
    }

    // ============================================================
    // PANEL OPEN / CLOSE
    // ============================================================
    function wireToggle() {
        if (header) {
            header.addEventListener('click', function (e) {
                if (e.target.closest('.assignment-panel-toggle')) return;
                togglePanel();
            });
        }

        if (toggleBtn) {
            toggleBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                togglePanel();
            });
        }
    }

    function togglePanel() {
        if (isOpen) {
            closePanel();
        } else {
            openPanel();
        }
    }

    function openPanel() {
        if (!panel) return;
        if (isOpen && panel.classList.contains('open')) return;

        isOpen = true;
        panel.classList.remove('closed');
        panel.classList.add('open');
        if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
    }

    function closePanel() {
        if (!panel) return;
        if (!isOpen && panel.classList.contains('closed')) return;

        isOpen = false;
        panel.classList.remove('open');
        panel.classList.add('closed');
        if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
    }

    // ============================================================
    // VISIBILITY
    // ============================================================
    function updateVisibility() {
        if (!wrapper) return;

        if (hasLiveAssignments) {
            wrapper.classList.add('has-items');
            wrapper.classList.remove('empty');
            wrapper.setAttribute('aria-hidden', 'false');

            if (!hasAutoOpened) {
                hasAutoOpened = true;
                openPanel();
            }
        } else {
            wrapper.classList.remove('has-items');
            wrapper.classList.remove('empty');
            wrapper.setAttribute('aria-hidden', 'false');
            closePanel();
        }
    }

    // ============================================================
    // SERVER CALLS
    // ============================================================
    function csrfToken() {
        return window.RIDER_ASSIGNMENT_CSRF ||
               window.RIDER_CSRF_TOKEN ||
               '';
    }

    function post(action, extra) {
        var fd = new FormData();
        fd.append('csrf_token', csrfToken());
        fd.append('action', action);

        if (extra) {
            Object.keys(extra).forEach(function (k) {
                fd.append(k, String(extra[k]));
            });
        }

        return fetch(ENDPOINT, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .catch(function () {
            return { status: 'error', message: 'Network error.' };
        });
    }

    // ============================================================
    // AVAILABILITY PILL + MODAL
    //
    // The pill opens one of three modal shapes based on the two
    // flags the panel already tracks. The modal's copy and its
    // confirm-button colour and its visible icon are expressed by
    // data-variant and data-icon on the modal element; CSS reads
    // those.
    //
    // Confirm POSTs toggle_availability to rider-handler.php. On
    // success we optimistically flip lastKnownOnline, repaint the
    // pill (which also rewrites the hover action label), and poll
    // so the assignment list resyncs with the server.
    // ============================================================
    function wireAvailabilityPill() {
        if (!statusPillEl || !availModal) return;

        statusPillEl.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            if (!lastKnownEligible) {
                openAvailabilityModal({
                    variant: 'neutral',
                    icon: 'blocked',
                    title: 'Account not verified',
                    text: 'Your account must be verified before you can go online.',
                    confirmLabel: 'Got it',
                    hideCancel: true,
                    onConfirm: null
                });
                return;
            }

            if (!lastKnownOnline) {
                openAvailabilityModal({
                    variant: 'primary',
                    icon: 'online',
                    title: 'Go online?',
                    text: 'You will start receiving new assignments from the kitchen.',
                    confirmLabel: 'Go Online',
                    hideCancel: false,
                    onConfirm: function () {
                        submitAvailabilityToggle(1);
                    }
                });
                return;
            }

            // Online.
            if (hasLiveAssignments) {
                openAvailabilityModal({
                    variant: 'neutral',
                    icon: 'blocked',
                    title: "Finish your active delivery first",
                    text: "You can't go offline while you have an order on the road. Mark the delivery complete, then try again.",
                    confirmLabel: 'Got it',
                    hideCancel: true,
                    onConfirm: null
                });
                return;
            }

            openAvailabilityModal({
                variant: 'danger',
                icon: 'offline',
                title: 'Go offline?',
                text: 'You will stop receiving new assignments until you go online again.',
                confirmLabel: 'Go Offline',
                hideCancel: false,
                onConfirm: function () {
                    submitAvailabilityToggle(0);
                }
            });
        });

        if (availConfirmBtn) {
            availConfirmBtn.addEventListener('click', function () {
                var fn = availConfirmBtn._onConfirm;
                if (typeof fn === 'function') {
                    fn();
                    return;
                }
                // Info-only shape: just close.
                closeAvailabilityModal();
            });
        }

        if (availCancelBtn) {
            availCancelBtn.addEventListener('click', function () {
                closeAvailabilityModal();
            });
        }

        var overlay = availModal.querySelector('[data-assignment-availability-dismiss]');
        if (overlay) {
            overlay.addEventListener('click', closeAvailabilityModal);
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isAvailabilityOpen()) {
                closeAvailabilityModal();
            }
        });
    }

    function openAvailabilityModal(copy) {
        if (!availModal) return;

        availModal.dataset.variant = copy.variant || 'primary';
        availModal.dataset.icon    = copy.icon    || 'online';

        if (availTitleEl) availTitleEl.textContent = copy.title || '';
        if (availTextEl)  availTextEl.textContent  = copy.text  || '';

        if (availConfirmBtn) {
            availConfirmBtn.textContent = copy.confirmLabel || 'Confirm';
            availConfirmBtn.disabled    = false;
            availConfirmBtn._onConfirm  =
                typeof copy.onConfirm === 'function' ? copy.onConfirm : null;
        }

        if (availCancelBtn) {
            availCancelBtn.hidden = !!copy.hideCancel;
        }

        document.body.style.overflow = 'hidden';
        availModal.style.display = 'flex';
        void availModal.offsetWidth;
        availModal.classList.add('active');

        if (availConfirmBtn) {
            setTimeout(function () { availConfirmBtn.focus(); }, 80);
        }
    }

    function closeAvailabilityModal() {
        if (!availModal) return;

        availModal.classList.remove('active');
        setTimeout(function () {
            if (!availModal.classList.contains('active')) {
                availModal.style.display = 'none';
                document.body.style.overflow = '';
            }
        }, 220);

        if (availConfirmBtn) {
            availConfirmBtn._onConfirm = null;
        }
    }

    function isAvailabilityOpen() {
        return availModal && availModal.classList.contains('active');
    }

    function submitAvailabilityToggle(newValue) {
        if (availConfirmBtn) {
            availConfirmBtn.disabled = true;
            availConfirmBtn.textContent = 'Updating…';
        }

        // toggle_availability lives on rider-handler.php, not on the
        // assignment handler this file's post() helper talks to.
        var fd = new FormData();
        fd.append('csrf_token', csrfToken());
        fd.append('action', 'toggle_availability');
        fd.append('is_available', String(newValue));

        fetch('../backend/handlers/rider-handler.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.status !== 'success') {
                    // Re-enable so the rider can retry without
                    // reopening the modal.
                    if (availConfirmBtn) {
                        availConfirmBtn.disabled = false;
                        availConfirmBtn.textContent =
                            newValue === 1 ? 'Go Online' : 'Go Offline';
                    }
                    showPanelToast(
                        (data && data.message) || 'Could not update availability.',
                        'error'
                    );
                    return;
                }

                // Optimistic local flip, then a poll to resync the
                // assignment list with whatever the server now has.
                lastKnownOnline = (newValue === 1);
                updateStatusPill(lastKnownOnline, lastKnownEligible);

                closeAvailabilityModal();

                showPanelToast(
                    data.message || (newValue === 1
                        ? 'You are now online.'
                        : 'You are now offline.'),
                    'success'
                );

                lastPollAt = 0;
                post('poll', { since_order_id: lastOrderId })
                    .then(function (res) {
                        if (!res || res.status !== 'success') return;
                        applyResponseState(res, false);
                    });
            })
            .catch(function () {
                if (availConfirmBtn) {
                    availConfirmBtn.disabled = false;
                    availConfirmBtn.textContent =
                        newValue === 1 ? 'Go Online' : 'Go Offline';
                }
                showPanelToast('Network error. Please try again.', 'error');
            });
    }

    // ============================================================
    // INITIAL LOAD + POLL
    // ============================================================
    function loadList() {
        post('list').then(function (data) {
            if (!data || data.status !== 'success') {
                applyErrorState();
                return;
            }
            applyResponseState(data, true);
            startPolling();
        });
    }

    function startPolling() {
        stopPolling();
        pollTimer = setInterval(pollTick, 1000);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    var lastPollAt = 0;
    function pollTick() {
        if (document.hidden) return;

        var now = Date.now();
        var interval = hasLiveAssignments ? POLL_LIVE_MS : POLL_IDLE_MS;
        if (now - lastPollAt < interval) return;

        lastPollAt = now;
        post('poll', { since_order_id: lastOrderId })
            .then(function (data) {
                if (!data || data.status !== 'success') return;
                applyResponseState(data, false);
            });
    }

    function wireVisibility() {
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                post('poll', { since_order_id: lastOrderId })
                    .then(function (data) {
                        if (!data || data.status !== 'success') return;
                        applyResponseState(data, false);
                    });
            }
        });
    }

    // ============================================================
    // STATE APPLICATION
    // ============================================================
    function applyResponseState(data, fullReplace) {
        var eligible = !!data.eligible;
        var online   = !!data.online;
        var counts   = data.counts || { pending: 0, active: 0, total: 0 };
        var rows     = data.rows || [];
        var maxId    = parseInt(data.max_id, 10) || lastOrderId;
        var dismissed = Array.isArray(data.dismissed) ? data.dismissed : null;

        lastKnownOnline   = online;
        lastKnownEligible = eligible;

        if (dismissed !== null) {
            dismissedIds = Object.create(null);
            dismissed.forEach(function (id) {
                dismissedIds[parseInt(id, 10)] = true;
            });
        }

        updateStatusPill(online, eligible);

        if (ineligibleHintEl) ineligibleHintEl.hidden = eligible;
        if (offlineHintEl)    offlineHintEl.hidden    = (!eligible || online);

        var total = parseInt(counts.total, 10) || 0;
        hasLiveAssignments = (total > 0);

        if (badgeEl) {
            badgeEl.textContent = String(total);
            badgeEl.style.display = total > 0 ? 'inline-flex' : 'none';
        }

        if (fullReplace) {
            listEl.innerHTML = '';
            rowsById = Object.create(null);
        }

        var newRows = [];

        rows.forEach(function (row) {
            var oid = parseInt(row.order_id, 10) || 0;
            if (oid <= 0) return;

            var existing = rowsById[oid];
            if (existing) {
                updateRow(existing, row);
            } else {
                var el = buildRow(row);
                listEl.appendChild(el);
                rowsById[oid] = el;

                if (!fullReplace) {
                    newRows.push(row);
                    el.classList.add('is-new');
                    setTimeout(function () {
                        el.classList.remove('is-new');
                    }, 6000);
                }
            }
        });

        if (fullReplace) {
            Object.keys(rowsById).forEach(function (key) {
                var oid = parseInt(key, 10) || 0;
                var present = rows.some(function (r) {
                    return (parseInt(r.order_id, 10) || 0) === oid;
                });
                if (!present) {
                    var el = rowsById[oid];
                    if (el && el.parentNode) el.parentNode.removeChild(el);
                    delete rowsById[oid];
                }
            });
        }

        if (emptyEl) emptyEl.hidden = (total > 0) || !eligible;

        if (maxId > lastOrderId) lastOrderId = maxId;

        updateVisibility();

        if (!fullReplace && eligible && online) {
            newRows.forEach(function (row) {
                var oid = parseInt(row.order_id, 10) || 0;
                if (!oid) return;
                if (String(row.status) !== 'rider_pending') return;
                if (dismissedIds[oid]) return;
                if (notifyQueue.some(function (q) { return q.order_id === oid; })) return;
                if (notifyModal && notifyModal.dataset.currentOrderId === String(oid)) return;
                notifyQueue.push(row);
            });

            if (notifyQueue.length > 0 && !isNotifyOpen()) {
                openNextNotification();
            }
        }

        if (!online || !eligible) {
            notifyQueue = [];
            if (isNotifyOpen()) closeNotification();
        }
    }

    function applyErrorState() {
        hasLiveAssignments = false;
        updateVisibility();
    }

    /**
     * Update the availability pill so it always reflects the
     * current state:
     *
     *   - the base label span (what shows when not hovered/focused)
     *   - the action label span (what shows on hover / focus)
     *   - the pill's own aria-label
     *   - the .is-online / .is-offline classes
     *
     * The action text depends on BOTH the online flag and whether
     * the rider currently has a live assignment:
     *
     *   offline                      → "Go Online"
     *   online, no live assignment   → "Go Offline"
     *   online, with live assignment → "Can't Go Offline"
     *
     * CSS decides which of the two spans is visible; this function
     * only writes the text and the classes.
     */
    function updateStatusPill(online, eligible) {
        if (!statusPillEl) return;

        if (!eligible) {
            statusPillEl.classList.remove('is-online');
            statusPillEl.classList.add('is-offline');

            if (statusTextEl)   statusTextEl.textContent   = 'Inactive';
            if (statusActionEl) statusActionEl.textContent = 'Unavailable';
            statusPillEl.setAttribute('aria-label', 'Availability: Inactive');
            return;
        }

        if (online) {
            statusPillEl.classList.remove('is-offline');
            statusPillEl.classList.add('is-online');

            if (statusTextEl) statusTextEl.textContent = 'Online';

            if (hasLiveAssignments) {
                if (statusActionEl) statusActionEl.textContent = "Can't Go Offline";
            } else {
                if (statusActionEl) statusActionEl.textContent = 'Go Offline';
            }

            statusPillEl.setAttribute('aria-label', 'Availability: Online');
        } else {
            statusPillEl.classList.remove('is-online');
            statusPillEl.classList.add('is-offline');

            if (statusTextEl)   statusTextEl.textContent   = 'Offline';
            if (statusActionEl) statusActionEl.textContent = 'Go Online';
            statusPillEl.setAttribute('aria-label', 'Availability: Offline');
        }
    }

    // ============================================================
    // ROW RENDERING — three-band layout
    // ============================================================
    function buildRow(row) {
        var oid    = parseInt(row.order_id, 10) || 0;
        var status = String(row.status || '');

        var el = document.createElement('div');
        el.className = 'assignment-row';
        el.setAttribute('data-order-id', String(oid));
        el.setAttribute('data-status', status);
        el.setAttribute('role', 'listitem');

        if (!expandedIds[oid]) {
            el.classList.add('is-collapsed');
        }

        var top = document.createElement('div');
        top.className = 'assignment-row-top';
        top.setAttribute('role', 'link');
        top.setAttribute('tabindex', '0');
        top.setAttribute('aria-label', 'View order #' + oid + ' in Deliveries');

        var orderEl = document.createElement('span');
        orderEl.className = 'assignment-row-order';
        orderEl.textContent = 'Order #' + oid;
        top.appendChild(orderEl);

        var badge = document.createElement('span');
        badge.className = 'assignment-row-status ' + (row.status_badge || 'badge-secondary');
        badge.textContent = row.status_label || '';
        top.appendChild(badge);

        var actions = document.createElement('div');
        actions.className = 'assignment-row-actions';
        buildRowActions(row, actions);
        top.appendChild(actions);

        el.appendChild(top);

        var summary = document.createElement('p');
        summary.className = 'assignment-row-summary';
        var restName = String(row.restaurant_name || '—');
        var custName = String(row.customer_name || 'Customer');
        summary.textContent = restName + ' • ' + custName;
        el.appendChild(summary);

        var meta = document.createElement('div');
        meta.className = 'assignment-row-meta';
        meta.appendChild(buildMetaLine('Restaurant', restName));
        meta.appendChild(buildMetaLine('Branch',     String(row.branch_name     || '—')));
        meta.appendChild(buildMetaLine('Customer',   custName));
        meta.appendChild(buildMetaLine('Deliver to', String(row.destination     || '—')));

        var totalsLine = document.createElement('div');
        totalsLine.className = 'assignment-row-line';
        var totalsLabel = document.createElement('span');
        totalsLabel.className = 'label';
        totalsLabel.textContent = 'Total';
        var totalsValue = document.createElement('span');
        totalsValue.className = 'value assignment-row-total';
        var itemCount = parseInt(row.item_count, 10) || 0;
        totalsValue.textContent =
            '₱' + Number(row.order_total || 0).toFixed(2) +
            ' • ' + itemCount + ' item' + (itemCount === 1 ? '' : 's');
        totalsLine.appendChild(totalsLabel);
        totalsLine.appendChild(totalsValue);
        meta.appendChild(totalsLine);

        el.appendChild(meta);

        var expandBtn = document.createElement('button');
        expandBtn.type = 'button';
        expandBtn.className = 'assignment-row-expand';
        expandBtn.setAttribute('data-row-expand', '1');
        expandBtn.setAttribute('aria-expanded', expandedIds[oid] ? 'true' : 'false');
        expandBtn.setAttribute('aria-label', 'Toggle details');

        var expandIcon = document.createElement('img');
        expandIcon.className = 'assignment-row-expand-icon';
        expandIcon.alt = '';
        expandIcon.width = 12;
        expandIcon.height = 12;
        expandIcon.src = assetPath('assets/images/icons/arrow-drop-down-line.svg');
        expandIcon.onerror = function () {
            this.onerror = null;
            this.src = assetPath('assets/images/icons/arrow-down-s-line.svg');
        };
        expandBtn.appendChild(expandIcon);

        var expandText = document.createElement('span');
        expandText.textContent = 'Details';
        expandBtn.appendChild(expandText);

        el.appendChild(expandBtn);

        return el;
    }

    function buildMetaLine(label, value) {
        var line = document.createElement('div');
        line.className = 'assignment-row-line';

        var l = document.createElement('span');
        l.className = 'label';
        l.textContent = label;

        var v = document.createElement('span');
        v.className = 'value';
        v.textContent = value;

        line.appendChild(l);
        line.appendChild(v);
        return line;
    }

    function buildRowActions(row, container) {
        var status = String(row.status || '');
        var oid    = parseInt(row.order_id, 10) || 0;

        if (status === 'rider_pending') {
            container.appendChild(buildActionButton({
                label: 'Decline',
                icon: 'close-circle-fill.svg',
                iconFallbacks: ['close-circle-line.svg', 'cancel.svg'],
                variant: 'danger',
                attrs: {
                    'data-action': 'decline',
                    'data-order-id': String(oid)
                }
            }));

            container.appendChild(buildActionButton({
                label: 'Accept',
                icon: 'verified-fill.svg',
                iconFallbacks: ['verified-badge-fill.svg', 'check-line.svg'],
                variant: 'primary',
                attrs: {
                    'data-action': 'accept',
                    'data-order-id': String(oid)
                }
            }));

            if (row.message_enabled && row.message_channel) {
                container.appendChild(buildChatTrigger(row));
            }
            if (row.call_number) {
                container.appendChild(buildCallLink(row));
            }
        } else if (status === 'delivering') {
            if (row.message_enabled && row.message_channel) {
                container.appendChild(buildChatTrigger(row));
            }
            if (row.call_number) {
                container.appendChild(buildCallLink(row));
            }
        }
    }

    function buildActionButton(cfg) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'assignment-action-btn is-' + cfg.variant;
        btn.setAttribute('aria-label', cfg.label);
        btn.setAttribute('title', cfg.label);

        Object.keys(cfg.attrs).forEach(function (k) {
            btn.setAttribute(k, cfg.attrs[k]);
        });

        btn.appendChild(buildIcon(cfg.icon, cfg.iconFallbacks || []));
        return btn;
    }

    function buildChatTrigger(row) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'assignment-action-btn is-neutral';
        btn.setAttribute('aria-label', 'Message');
        btn.setAttribute('title', 'Message');

        btn.setAttribute('data-rider-chat-open', '1');
        btn.setAttribute('data-rider-chat-order-id', String(row.order_id));
        btn.setAttribute('data-rider-chat-recipient', String(row.message_channel || 'customer'));
        btn.setAttribute(
            'data-rider-chat-subtitle',
            'Order #' + row.order_id + ' • ' + (row.restaurant_name || '')
        );

        btn.appendChild(buildIcon(
            'chat-1-fill.svg',
            ['chat-fill.svg', 'chat-line.svg', 'contact-us-line.svg']
        ));
        return btn;
    }

    function buildCallLink(row) {
        var a = document.createElement('a');
        a.className = 'assignment-action-btn is-neutral';
        a.href = 'tel:' + String(row.call_number).replace(/\s+/g, '');
        a.setAttribute('aria-label', row.call_label || 'Call');
        a.setAttribute('title', row.call_label || 'Call');

        a.appendChild(buildIcon(
            'phone-fill.svg',
            ['contact-us-line.svg', 'phone-line.svg']
        ));
        return a;
    }

    function updateRow(existingEl, row) {
        var oid = parseInt(row.order_id, 10) || 0;
        if (!existingEl.classList.contains('is-collapsed')) {
            expandedIds[oid] = true;
        } else {
            delete expandedIds[oid];
        }

        var newEl = buildRow(row);
        existingEl.parentNode.replaceChild(newEl, existingEl);
        rowsById[oid] = newEl;
    }

    // ============================================================
    // ROW DELEGATION
    // ============================================================
    function wireRowDelegation() {
        if (!listEl) return;

        listEl.addEventListener('click', function (e) {
            var expandBtn = e.target.closest('[data-row-expand]');
            if (expandBtn) {
                e.preventDefault();
                e.stopPropagation();

                var rowEl = expandBtn.closest('.assignment-row');
                if (!rowEl) return;

                var oid = parseInt(rowEl.getAttribute('data-order-id'), 10) || 0;
                var nowCollapsed = rowEl.classList.toggle('is-collapsed');

                if (nowCollapsed) {
                    delete expandedIds[oid];
                } else {
                    expandedIds[oid] = true;
                }
                expandBtn.setAttribute('aria-expanded', nowCollapsed ? 'false' : 'true');
                return;
            }

            var btn = e.target.closest('[data-action]');
            if (btn && !btn.disabled) {
                e.preventDefault();
                e.stopPropagation();

                var action  = btn.getAttribute('data-action');
                var orderId = parseInt(btn.getAttribute('data-order-id'), 10) || 0;
                if (!orderId) return;

                if (action === 'accept')  return submitDecision(btn, 'accept',  orderId);
                if (action === 'decline') return submitDecision(btn, 'decline', orderId);
                return;
            }

            var topBand = e.target.closest('.assignment-row-top');
            if (topBand) {
                if (e.target.closest(
                    'button, a, [data-action], [data-row-expand], [data-rider-chat-open]'
                )) {
                    return;
                }

                e.preventDefault();
                window.location.href = 'deliveries.php';
            }
        });

        listEl.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;

            var topBand = e.target.closest('.assignment-row-top');
            if (!topBand) return;
            if (e.target !== topBand) return;

            e.preventDefault();
            window.location.href = 'deliveries.php';
        });
    }

    // ============================================================
    // ACCEPT / DECLINE
    // ============================================================
    function submitDecision(btn, action, orderId) {
        btn.disabled = true;

        post(action, { order_id: orderId })
            .then(function (data) {
                if (!data || data.status !== 'success') {
                    btn.disabled = false;
                    showPanelToast(
                        (data && data.message) || 'Could not complete the action.',
                        'error'
                    );
                    return;
                }

                if (action === 'accept') {
                    if (data.row) {
                        replaceRow(data.row);
                    } else {
                        loadList();
                    }
                } else {
                    removeRow(orderId);
                }

                if (orderId > lastOrderId) lastOrderId = orderId;
                clearNotificationFor(orderId);
                delete expandedIds[orderId];

                showPanelToast(
                    data.message || (action === 'accept'
                        ? 'Assignment accepted.'
                        : 'Assignment declined.'),
                    'success'
                );
            })
            .catch(function () {
                btn.disabled = false;
                showPanelToast('Network error. Please try again.', 'error');
            });
    }

    function replaceRow(row) {
        var oid = parseInt(row.order_id, 10) || 0;
        if (oid <= 0) return;

        var existing = rowsById[oid];

        if (existing && existing.parentNode) {
            updateRow(existing, row);
        } else {
            var el = buildRow(row);
            listEl.appendChild(el);
            rowsById[oid] = el;
        }

        hasLiveAssignments = Object.keys(rowsById).length > 0;

        if (emptyEl) emptyEl.hidden = hasLiveAssignments || !lastKnownEligible;
        updateVisibility();
        refreshCountsFromDom();
        updateStatusPill(lastKnownOnline, lastKnownEligible);
    }

    function removeRow(orderId) {
        var el = rowsById[orderId];
        if (el && el.parentNode) {
            el.parentNode.removeChild(el);
        }
        delete rowsById[orderId];

        var remaining = Object.keys(rowsById).length;
        hasLiveAssignments = remaining > 0;

        if (emptyEl) emptyEl.hidden = hasLiveAssignments || !lastKnownEligible;
        updateVisibility();
        refreshCountsFromDom();
        updateStatusPill(lastKnownOnline, lastKnownEligible);
    }

    function refreshCountsFromDom() {
        var pending = 0, active = 0;
        Object.keys(rowsById).forEach(function (key) {
            var el = rowsById[key];
            var status = el.getAttribute('data-status') || '';
            if (status === 'rider_pending') pending++;
            else if (status === 'delivering') active++;
        });

        var total = pending + active;

        if (badgeEl) {
            badgeEl.textContent = String(total);
            badgeEl.style.display = total > 0 ? 'inline-flex' : 'none';
        }
    }

    // ============================================================
    // NOTIFICATION MODAL
    // ============================================================
    function wireNotifyModal() {
        if (!notifyModal) return;

        if (notifyAcceptBtn) {
            notifyAcceptBtn.addEventListener('click', function () {
                var oid = parseInt(notifyAcceptBtn.dataset.orderId, 10) || 0;
                if (!oid) return;

                notifyAcceptBtn.disabled = true;
                if (notifyDeclineBtn) notifyDeclineBtn.disabled = true;

                post('accept', { order_id: oid }).then(function (data) {
                    notifyAcceptBtn.disabled = false;
                    if (notifyDeclineBtn) notifyDeclineBtn.disabled = false;

                    if (!data || data.status !== 'success') {
                        showPanelToast(
                            (data && data.message) || 'Could not accept the assignment.',
                            'error'
                        );
                        return;
                    }

                    if (data.row) {
                        replaceRow(data.row);
                    } else {
                        loadList();
                    }

                    if (oid > lastOrderId) lastOrderId = oid;

                    closeNotification();
                    showPanelToast(data.message || 'Assignment accepted.', 'success');
                });
            });
        }

        if (notifyDeclineBtn) {
            notifyDeclineBtn.addEventListener('click', function () {
                var oid = parseInt(notifyDeclineBtn.dataset.orderId, 10) || 0;
                if (!oid) return;

                if (notifyAcceptBtn) notifyAcceptBtn.disabled = true;
                notifyDeclineBtn.disabled = true;

                post('decline', { order_id: oid }).then(function (data) {
                    if (notifyAcceptBtn) notifyAcceptBtn.disabled = false;
                    notifyDeclineBtn.disabled = false;

                    if (!data || data.status !== 'success') {
                        showPanelToast(
                            (data && data.message) || 'Could not decline the assignment.',
                            'error'
                        );
                        return;
                    }

                    removeRow(oid);
                    if (oid > lastOrderId) lastOrderId = oid;

                    closeNotification();
                    showPanelToast(data.message || 'Assignment declined.', 'success');
                });
            });
        }

        if (notifyDismissBtn) {
            notifyDismissBtn.addEventListener('click', function () {
                var oid = parseInt(notifyDismissBtn.dataset.orderId, 10) || 0;
                if (!oid) { closeNotification(); return; }

                post('dismiss', { order_id: oid });
                dismissedIds[oid] = true;
                closeNotification();
            });
        }

        var overlay = notifyModal.querySelector('[data-assignment-notify-dismiss]');
        if (overlay) {
            overlay.addEventListener('click', closeNotification);
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isNotifyOpen()) closeNotification();
        });
    }

    function openNextNotification() {
        if (!notifyModal) return;
        if (isNotifyOpen()) return;
        if (notifyQueue.length === 0) return;

        var row = notifyQueue[0];

        if (notifyOrderIdEl)    notifyOrderIdEl.textContent    = String(row.order_id);
        if (notifyRestaurantEl) notifyRestaurantEl.textContent =
            (row.restaurant_name || '—') +
            (row.branch_name ? ' • ' + row.branch_name : '');
        if (notifyCustomerEl)   notifyCustomerEl.textContent   = row.customer_name || '—';
        if (notifyTotalEl)      notifyTotalEl.textContent      =
            '₱' + Number(row.order_total || 0).toFixed(2);

        if (notifyAcceptBtn)  notifyAcceptBtn.dataset.orderId  = String(row.order_id);
        if (notifyDeclineBtn) notifyDeclineBtn.dataset.orderId = String(row.order_id);
        if (notifyDismissBtn) notifyDismissBtn.dataset.orderId = String(row.order_id);

        notifyModal.dataset.currentOrderId = String(row.order_id);

        document.body.style.overflow = 'hidden';
        notifyModal.style.display = 'flex';
        void notifyModal.offsetWidth;
        notifyModal.classList.add('active');

        if (notifyAcceptBtn) {
            setTimeout(function () { notifyAcceptBtn.focus(); }, 120);
        }
    }

    function closeNotification() {
        if (!notifyModal) return;

        notifyModal.classList.remove('active');
        setTimeout(function () {
            if (!notifyModal.classList.contains('active')) {
                notifyModal.style.display = 'none';
                document.body.style.overflow = '';
                delete notifyModal.dataset.currentOrderId;
            }
        }, 220);

        notifyQueue.shift();

        if (notifyQueue.length > 0) {
            setTimeout(function () {
                if (!isNotifyOpen() && lastKnownOnline && lastKnownEligible) {
                    openNextNotification();
                }
            }, 350);
        }
    }

    function isNotifyOpen() {
        return notifyModal && notifyModal.classList.contains('active');
    }

    function clearNotificationFor(orderId) {
        notifyQueue = notifyQueue.filter(function (row) {
            return (parseInt(row.order_id, 10) || 0) !== orderId;
        });

        if (notifyModal &&
            notifyModal.dataset.currentOrderId === String(orderId)) {
            closeNotification();
        }
    }

    // ============================================================
    // HELPERS
    // ============================================================
    function assetPath(relative) {
        var base = window.RIDER_ASSET_BASE || '';
        if (base) {
            if (base.charAt(base.length - 1) !== '/') base += '/';
            return base + relative.replace(/^\/+/, '');
        }
        return '../../shared/' + relative.replace(/^\/+/, '');
    }

    function buildIcon(primary, fallbacks) {
        var img = document.createElement('img');
        img.alt = '';
        img.className = 'assignment-action-icon';
        img.setAttribute('width', '18');
        img.setAttribute('height', '18');

        var chain = [primary].concat(fallbacks || []);

        img.onerror = function () {
            var next = chain.shift();
            if (next) {
                img.src = assetPath('assets/images/icons/' + next);
            } else {
                img.onerror = null;
                img.style.display = 'none';
            }
        };

        img.src = assetPath('assets/images/icons/' + chain.shift());
        return img;
    }

    function showPanelToast(message, type) {
        var toast = document.getElementById('assignmentPanelToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'assignmentPanelToast';
            toast.style.cssText = [
                'position:fixed', 'top:80px', 'right:20px',
                'padding:12px 20px', 'border-radius:8px',
                'font-size:14px', 'font-weight:500', 'z-index:9999',
                'max-width:360px', 'box-shadow:0 4px 16px rgba(0,0,0,.15)',
                'transform:translateX(120%)',
                'transition:transform .3s cubic-bezier(.4,0,.2,1)'
            ].join(';');
            document.body.appendChild(toast);
        }

        var palette = {
            success: ['#d1fae5', '#065f46'],
            error:   ['#fee2e2', '#991b1b'],
            warning: ['#fef3c7', '#92400e'],
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

    // ============================================================
    // BOOT
    // ============================================================
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();