/**
 * FitPal Rider Assignment Panel — client behaviour.
 *
 * Owns the bottom-anchored assignment panel that appears on every
 * authenticated rider page.
 *
 * ---------------------------------------------------------------------
 * TEXT-SPAN LOOKUP — THE BUG THAT COST SIX REVISIONS
 * ---------------------------------------------------------------------
 * The availability pill renders three children:
 *
 *     .assignment-panel-status-dot     the colored dot
 *     .assignment-panel-status-text    the state label
 *     .assignment-panel-status-action  the action label
 *
 * The button itself has id="assignmentPanelStatus". The three
 * children have CLASSES, not ids. rider/includes/assignment-panel.php
 * emits them without an id attribute.
 *
 * Every prior revision of this file looked the two text spans up by
 * an id — `document.getElementById('assignmentPanelStatusText')` —
 * that does not exist in the markup. Both lookups returned null.
 * Both `statusTextEl` and `statusActionEl` were therefore null for
 * the entire lifetime of the page.
 *
 * The consequence, which is exactly what the rider observed:
 *
 *   - `statusBtn.classList.add(...)` ran, because `statusBtn` was
 *     found correctly (the button does have an id). The pill's
 *     background and text color changed.
 *
 *   - `statusTextEl.textContent = label` was guarded by
 *     `if (statusTextEl)` and never ran, because `statusTextEl`
 *     was null. The label text never changed.
 *
 *   - `statusActionEl.textContent = action` was guarded the same
 *     way and never ran either.
 *
 * So the pill changed color but not text, exactly as the rider
 * reported. The JS was running. The class was being written. The
 * text writes were silently skipped behind `if (el)` guards on
 * null references.
 *
 * The fix: look the two spans up by their class name, scoped to
 * the pill button. The button is found by id (which exists); the
 * two spans inside it are found by `querySelector` against their
 * class names (which exist). Both lookups now return the real
 * elements, both writes run, and the label text changes on the
 * frame the toggle response lands.
 *
 * ---------------------------------------------------------------------
 * DIRECT-RESPONSE PILL UPDATE
 * ---------------------------------------------------------------------
 * When the toggle succeeds, the pill reads the exact strings the
 * server returned:
 *
 *     data.status_text     → the pill's visible label
 *     data.status_action   → the hover-action label
 *     data.status_class    → the pill's modifier class
 *
 * No module-scope variable is consulted for the label. No label is
 * derived on the client. The handler's response is the pill.
 *
 * ---------------------------------------------------------------------
 * DIAGNOSTIC LOG
 * ---------------------------------------------------------------------
 * Every toggle response is logged to the browser console with the
 * prefix `[FitPal assignment-panel]`. Left in on purpose.
 *
 * ---------------------------------------------------------------------
 * ROW SHAPE, POLL, STATE PRESERVATION, CUSTOMIZATION DROPDOWN,
 * AVAILABILITY-CHANGE EVENT, INITIAL STATE SEED, CONFIG
 * ---------------------------------------------------------------------
 * All sections below are unchanged from the previous revision
 * except for the two element lookups at the top of the file.
 *
 * @package FitPal
 * @version 9.2 — Fixes the text-span element lookups. The two
 *                spans inside the availability pill are now found
 *                by class name (`querySelector`) rather than by an
 *                id that the markup never carried. This is the
 *                revision that actually makes the pill's text
 *                change on a toggle; every previous revision wrote
 *                the class correctly and silently skipped the text
 *                writes because the element references were null.
 *
 *                (9.1: direct-response pill update. 9.0:
 *                toggle-poll race guard. 8.4: authoritative toggle
 *                response. 8.3: wrapper-attribute seed. 8.2:
 *                availability-changed event. 8.1: row-pills
 *                container. 8.0: queue-panel row shape.)
 */
(function () {
    'use strict';

    var panel   = document.getElementById('assignmentPanel');
    var wrapper = document.getElementById('assignmentPanelWrapper');
    if (!panel || !wrapper) return;

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
    var CONFIRM_PENDING_TIMEOUT_MS = 15000;

    var AVAILABILITY_EVENT_NAME = 'rider:availability-changed';

    var toggleBtn = document.getElementById('assignmentPanelToggle');
    var header    = document.getElementById('assignmentPanelHeader');
    var countBadge = document.getElementById('assignmentCountBadge');
    var listEl    = document.getElementById('assignmentList');
    var emptyState = document.getElementById('assignmentEmptyState');
    var offlineHint = document.getElementById('assignmentOfflineHint');
    var ineligibleHint = document.getElementById('assignmentIneligibleHint');

    // The availability pill. The button carries an id; the two
    // text spans inside it carry classes only. They are looked up
    // relative to the button.
    var statusBtn = document.getElementById('assignmentPanelStatus');
    var statusTextEl = statusBtn
        ? statusBtn.querySelector('.assignment-panel-status-text')
        : null;
    var statusActionEl = statusBtn
        ? statusBtn.querySelector('.assignment-panel-status-action')
        : null;

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

    var ICONS = {
        accept:      'verified-fill.svg',
        decline:     'close-circle-fill.svg',
        picked_up:   'package.svg',
        delivered:   'verified-badge-fill.svg',
        message:     'contact-us-line.svg',
        call:        'phone-fill.svg',
        expand:      'arrow-drop-down-line.svg',
        fallback:    'restaurant.svg',
        fallbackAlt: 'community-general.svg',
        info:        'information-fill.svg',
        pillFallback:'file-warning-fill.svg'
    };

    function iconUrl(file) {
        return ASSET_BASE + 'assets/images/icons/' + file;
    }

    var isOpen   = false;
    var online   = false;
    var eligible = true;

    var availabilityConfirmPending = false;
    var availabilityConfirmTimer   = null;

    var dismissedOfferIds = Object.create(null);
    var notifyOrderId = 0;

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function escapeAttr(text) {
        return escapeHtml(text).replace(/"/g, '&quot;');
    }

    function formatCurrency(amount) {
        var n = parseFloat(amount || 0);
        return '\u20B1' + n.toFixed(2);
    }

    function readInitialStateFromWrapper() {
        var fallbackEligible = true;
        var fallbackOnline   = false;

        var rawEligible = wrapper.getAttribute('data-rider-eligible');
        var rawOnline   = wrapper.getAttribute('data-rider-online');

        var seededEligible;
        var seededOnline;

        if (rawEligible === '1') {
            seededEligible = true;
        } else if (rawEligible === '0') {
            seededEligible = false;
        } else {
            seededEligible = fallbackEligible;
        }

        if (rawOnline === '1') {
            seededOnline = true;
        } else if (rawOnline === '0') {
            seededOnline = false;
        } else {
            seededOnline = fallbackOnline;
        }

        return {
            eligible: seededEligible,
            online:   seededOnline
        };
    }

    function markAvailabilityConfirmPending() {
        availabilityConfirmPending = true;

        if (availabilityConfirmTimer !== null) {
            clearTimeout(availabilityConfirmTimer);
        }

        availabilityConfirmTimer = setTimeout(function () {
            availabilityConfirmPending = false;
            availabilityConfirmTimer   = null;
        }, CONFIRM_PENDING_TIMEOUT_MS);
    }

    function clearAvailabilityConfirmPending() {
        availabilityConfirmPending = false;

        if (availabilityConfirmTimer !== null) {
            clearTimeout(availabilityConfirmTimer);
            availabilityConfirmTimer = null;
        }
    }

    /**
     * Paint the pill directly from the toggle handler's response.
     *
     * The handler sends three strings:
     *
     *     status_text     the label, e.g. "Online"
     *     status_action   the hover action, e.g. "Go Offline"
     *     status_class    the modifier, e.g. "is-online"
     *
     * This function writes those strings into the pill. The two
     * text spans are located by class name relative to the pill
     * button; they have no id of their own.
     *
     * If a field is missing from the response, it is derived from
     * data.online / data.eligible, and if those are also missing,
     * from the caller's fallbackOnline.
     *
     * Returns the previous value of the module-scope `online`, so
     * the caller can decide whether to dispatch the change event.
     *
     * @param {Object}  data            handler response
     * @param {boolean} fallbackOnline  caller's best guess
     * @returns {boolean}               the previous online value
     */
    function paintPillFromResponse(data, fallbackOnline) {
        var previousOnline = online;

        var isOnline;
        var isEligible;

        if (data && typeof data.online === 'boolean') {
            isOnline = data.online;
        } else {
            isOnline = !!fallbackOnline;
        }

        if (data && typeof data.eligible === 'boolean') {
            isEligible = data.eligible;
        } else {
            isEligible = eligible;
        }

        online   = isOnline;
        eligible = isEligible;

        var label;
        var action;
        var stateClass;

        if (data && typeof data.status_text === 'string' && data.status_text !== '') {
            label = data.status_text;
        } else if (!isEligible) {
            label = 'Inactive';
        } else if (isOnline) {
            label = 'Online';
        } else {
            label = 'Offline';
        }

        if (data && typeof data.status_action === 'string') {
            action = data.status_action;
        } else if (!isEligible) {
            action = '';
        } else if (isOnline) {
            action = 'Go Offline';
        } else {
            action = 'Go Online';
        }

        if (data && typeof data.status_class === 'string' && data.status_class !== '') {
            stateClass = data.status_class;
        } else if (!isEligible) {
            stateClass = 'is-inactive';
        } else if (isOnline) {
            stateClass = 'is-online';
        } else {
            stateClass = 'is-offline';
        }

        if (statusBtn) {
            statusBtn.classList.remove('is-online', 'is-offline', 'is-inactive');
            statusBtn.classList.add(stateClass);

            statusBtn.setAttribute(
                'aria-label',
                'Availability: ' + label
                + (action !== '' ? ' \u2014 press to ' + action.toLowerCase() : '')
            );
        }

        if (statusTextEl) {
            statusTextEl.textContent = label;
        }

        if (statusActionEl) {
            statusActionEl.textContent = action;
        }

        applyEligibilityState();

        return previousOnline;
    }

    /**
     * Fallback paint when the response carries no state at all.
     * Uses the module-scope state, which was set by a previous
     * authoritative response or the wrapper seed.
     */
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

    function dispatchAvailabilityChanged(newOnline) {
        if (typeof window.CustomEvent !== 'function') return;

        try {
            var evt = new CustomEvent(AVAILABILITY_EVENT_NAME, {
                detail: {
                    online: !!newOnline,
                    eligible: !!eligible
                }
            });
            document.dispatchEvent(evt);
        } catch (err) {
            // Swallow.
        }
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

    function showModal(modal) {
        if (!modal) return;
        modal.classList.add('active');
    }

    function hideModal(modal) {
        if (!modal) return;
        modal.classList.remove('active');
    }

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

    function initAvailabilityPill() {
        if (!statusBtn) return;

        statusBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            openAvailabilityModal();
        });
    }

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
                        if (window.console && console.log) {
                            console.log('[FitPal assignment-panel] toggle response:', data);
                        }

                        availabilityConfirmBtn.disabled = false;
                        availabilityConfirmBtn.textContent = originalText;

                        if (data && data.status === 'success') {
                            var previousOnline = paintPillFromResponse(
                                data,
                                (target === 1)
                            );

                            closeAvailabilityModal();

                            markAvailabilityConfirmPending();

                            fetchNow();

                            if (previousOnline !== online) {
                                dispatchAvailabilityChanged(online);
                            }
                            return;
                        }

                        if (availabilityTextEl) {
                            availabilityTextEl.textContent = (data && data.message)
                                ? data.message
                                : 'Could not update your availability. Please try again.';
                        }
                    })
                    .catch(function (err) {
                        availabilityConfirmBtn.disabled = false;
                        availabilityConfirmBtn.textContent = originalText;

                        if (window.console && console.error) {
                            console.error('[FitPal assignment-panel] toggle error:', err);
                        }

                        if (availabilityTextEl) {
                            availabilityTextEl.textContent =
                                'A network error occurred. Please try again.';
                        }
                    });
            });
        }
    }

    function openRiderChat(orderId, recipient, subtitle) {
        var chat = window.FitPalRiderChat;

        if (!chat || typeof chat.open !== 'function') {
            if (window.console && console.warn) {
                console.warn(
                    '[assignment-panel] window.FitPalRiderChat.open is not available.'
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

    function renderPaymentPill(pill) {
        if (!pill || typeof pill !== 'object') {
            return '';
        }

        var icon  = String(pill.icon  || ICONS.pillFallback);
        var label = String(pill.label || '');
        var slug  = String(pill.slug  || 'other');

        if (label === '') {
            return '';
        }

        return (
            '<span class="assignment-row-payment assignment-row-payment-' + escapeAttr(slug) + '">' +
                '<img src="' + escapeHtml(iconUrl(icon)) + '" alt="" ' +
                     'class="assignment-row-payment-icon" width="14" height="14" ' +
                     'onerror="this.onerror=null; this.src=\'' +
                         escapeHtml(iconUrl(ICONS.pillFallback)) + '\'">' +
                '<span class="assignment-row-payment-label">' + escapeHtml(label) + '</span>' +
            '</span>'
        );
    }

    function primaryItemImage(items) {
        if (!Array.isArray(items)) return '';

        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            if (!item || typeof item !== 'object') continue;

            var url = String(item.image_url || '');
            if (url !== '') return url;
        }
        return '';
    }

    function primaryItemName(items) {
        if (!Array.isArray(items)) return 'Order item';

        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            if (!item || typeof item !== 'object') continue;

            var name = String(item.product_name || '');
            if (name !== '') return name;
        }
        return 'Order item';
    }

    function renderPrimaryThumb(items) {
        var fallbackIcon = iconUrl(ICONS.fallback);
        var fallbackAlt  = iconUrl(ICONS.fallbackAlt);

        var image = primaryItemImage(items);
        if (image === '') {
            image = fallbackIcon;
        }

        var name = primaryItemName(items);

        return (
            '<span class="assignment-row-thumb" aria-hidden="true">' +
                '<img src="' + escapeHtml(image) + '" alt="' + escapeHtml(name) + '" ' +
                     'class="assignment-row-thumb-image" width="44" height="44" ' +
                     'loading="lazy" ' +
                     'onerror="this.onerror=null; this.src=\'' + fallbackAlt + '\'">' +
            '</span>'
        );
    }

    function collectOrderCustomizations(items) {
        var modifications = [];
        var instructions  = [];

        var seenMods = Object.create(null);
        var seenNote = Object.create(null);

        if (!Array.isArray(items)) {
            return { modifications: modifications, instructions: instructions };
        }

        items.forEach(function (item) {
            if (!item || typeof item !== 'object') return;

            if (Array.isArray(item.customizations)) {
                item.customizations.forEach(function (cust) {
                    if (!cust || typeof cust !== 'object') return;

                    var name = String(cust.ingredient_name || '');
                    if (name === '') return;

                    var isRemoved = !!cust.is_removed;
                    var key = name + '::' + (isRemoved ? '1' : '0');

                    if (seenMods[key]) return;
                    seenMods[key] = true;

                    modifications.push({
                        name:      name,
                        isRemoved: isRemoved,
                        modifier:  parseFloat(cust.price_modifier || 0)
                    });
                });
            }

            var note = String(item.special_instructions || '').trim();
            if (note !== '' && !seenNote[note]) {
                seenNote[note] = true;
                instructions.push(note);
            }
        });

        return {
            modifications: modifications,
            instructions:  instructions
        };
    }

    function renderModificationRow(mod) {
        var symbol;
        var kindClass;
        var priceHtml = '';
        var nameHtml  = escapeHtml(mod.name);

        if (mod.isRemoved) {
            symbol    = '\u2212';
            kindClass = 'cart-customs-remove';
            nameHtml += ' <span class="cart-customs-removed">(removed)</span>';
        } else {
            symbol    = '+';
            kindClass = 'cart-customs-add';

            if (!isNaN(mod.modifier) && mod.modifier !== 0) {
                var sign  = mod.modifier > 0 ? '+' : '\u2212';
                var value = Math.abs(mod.modifier).toFixed(2);
                priceHtml = '<span class="cart-customs-price">'
                          + '(' + sign + '\u20B1' + value + ')'
                          + '</span>';
            }
        }

        return (
            '<li class="cart-customs-row ' + kindClass + '">' +
                '<span class="cart-customs-symbol">' + symbol + '</span>' +
                '<span class="cart-customs-name">' + nameHtml + '</span>' +
                priceHtml +
            '</li>'
        );
    }

    function renderCustomsBlock(orderId, items) {
        var bundle = collectOrderCustomizations(items);

        var hasMods = bundle.modifications.length > 0;
        var hasNote = bundle.instructions.length  > 0;

        if (!hasMods && !hasNote) {
            return '';
        }

        var panelId = 'riderAssignmentCustoms' + orderId;

        var listHtml = '';
        if (hasMods) {
            listHtml = '<ul class="cart-customs-list">'
                     + bundle.modifications.map(renderModificationRow).join('')
                     + '</ul>';
        }

        var notesHtml = '';
        if (hasNote) {
            notesHtml = bundle.instructions.map(function (note) {
                return (
                    '<div class="queue-customs-notes">' +
                        '<p class="queue-customs-notes-label">Special Instructions</p>' +
                        '<p class="queue-customs-notes-text">' + escapeHtml(note) + '</p>' +
                    '</div>'
                );
            }).join('');
        }

        return (
            '<div class="assignment-row-customs">' +
                '<button type="button" ' +
                        'class="assignment-row-customs-toggle" ' +
                        'data-customs-toggle="' + escapeAttr(panelId) + '" ' +
                        'aria-expanded="false" ' +
                        'aria-controls="' + escapeAttr(panelId) + '">' +
                    '<span>Customized</span>' +
                    '<img src="' + escapeHtml(iconUrl(ICONS.expand)) + '" ' +
                         'alt="" class="assignment-row-customs-toggle-icon" ' +
                         'width="14" height="14" ' +
                         'onerror="this.style.display=\'none\';">' +
                '</button>' +
                '<div class="assignment-row-customs-panel" ' +
                     'id="' + escapeAttr(panelId) + '" hidden>' +
                    listHtml +
                    notesHtml +
                '</div>' +
            '</div>'
        );
    }

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

    function rowMetaLines(row) {
        var lines = [];

        var restaurantName = row.restaurant_name || '\u2014';
        var branchName     = row.branch_name     || '';
        var branchAddress  = row.branch_address  || '';

        var restaurantValue = restaurantName;
        if (branchName !== '' && branchName !== restaurantName) {
            restaurantValue += ' \u2014 ' + branchName;
        }

        lines.push(['Restaurant', restaurantValue]);

        if (branchAddress !== '') {
            lines.push(['Pickup at', branchAddress]);
        }

        lines.push(['Customer', row.customer_name || '\u2014']);
        lines.push(['Deliver to', row.destination || '\u2014']);
        lines.push(['Items', String(row.item_count || 0)]);
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

    function rowSummaryHtml(row) {
        var restaurant = escapeHtml(row.restaurant_name || 'Restaurant');
        var customer   = escapeHtml(row.customer_name   || 'Customer');
        var itemCount  = parseInt(row.item_count, 10) || 0;

        return restaurant + ' &rarr; ' + customer
             + ' &bull; ' + itemCount + ' item' + (itemCount === 1 ? '' : 's')
             + ' &bull; ' + escapeHtml(formatCurrency(row.order_total));
    }

    function renderRow(row, collapsed) {
        var status       = row.status || '';
        var badgeClass   = row.status_badge || 'badge-secondary';
        var statusLabel  = row.status_label || status;

        var classes = 'assignment-row';
        if (collapsed) classes += ' is-collapsed';

        var items = Array.isArray(row.items) ? row.items : [];

        var thumbHtml   = renderPrimaryThumb(items);
        var paymentHtml = renderPaymentPill(row.payment_pill);
        var customsHtml = renderCustomsBlock(row.order_id, items);

        return (
            '<article class="' + classes + '" ' +
                     'data-order-id="' + row.order_id + '" ' +
                     'data-order-status="' + escapeHtml(status) + '">' +

                '<header class="assignment-row-top">' +
                    thumbHtml +
                    '<span class="assignment-row-order">Order #' + row.order_id + '</span>' +

                    '<div class="assignment-row-pills">' +
                        '<span class="assignment-row-status ' + escapeHtml(badgeClass) + '">' +
                            escapeHtml(statusLabel) +
                        '</span>' +
                        paymentHtml +
                    '</div>' +

                    '<div class="assignment-row-actions">' +
                        rowActions(row) +
                    '</div>' +
                '</header>' +

                '<div class="assignment-row-meta-line">' +
                    '<p class="assignment-row-summary">' +
                        rowSummaryHtml(row) +
                    '</p>' +
                    '<button type="button" class="assignment-row-expand" ' +
                            'aria-expanded="' + (collapsed ? 'false' : 'true') + '">' +
                        '<img src="' + escapeHtml(iconUrl(ICONS.expand)) + '" alt="" ' +
                             'class="assignment-row-expand-icon" width="14" height="14">' +
                        '<span>' + (collapsed ? 'Details' : 'Hide') + '</span>' +
                    '</button>' +
                '</div>' +

                '<div class="assignment-row-details">' +
                    '<div class="assignment-row-meta">' +
                        rowMetaLines(row) +
                    '</div>' +
                    customsHtml +
                '</div>' +
            '</article>'
        );
    }

    function snapshotRowState() {
        var state = Object.create(null);
        if (!listEl) return state;

        var rows = listEl.querySelectorAll('.assignment-row[data-order-id]');

        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var id  = row.getAttribute('data-order-id');
            if (!id) continue;

            var collapsed = row.classList.contains('is-collapsed');

            var customsOpen = false;
            var toggle = row.querySelector('.assignment-row-customs-toggle');
            if (toggle && toggle.getAttribute('aria-expanded') === 'true') {
                var panelId = toggle.getAttribute('data-customs-toggle');
                if (panelId) {
                    var panelEl = document.getElementById(panelId);
                    if (panelEl && !panelEl.hidden) {
                        customsOpen = true;
                    }
                }
            }

            state[id] = {
                collapsed:   collapsed,
                customsOpen: customsOpen
            };
        }

        return state;
    }

    function applyRowState(row, entry, defaultCollapsed) {
        if (!row) return;

        var collapsed = entry ? !!entry.collapsed : !!defaultCollapsed;
        var customsOpen = entry ? !!entry.customsOpen : false;

        if (collapsed) {
            row.classList.add('is-collapsed');
        } else {
            row.classList.remove('is-collapsed');
        }

        var expandBtn = row.querySelector('.assignment-row-expand');
        if (expandBtn) {
            expandBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            var labelEl = expandBtn.querySelector('span');
            if (labelEl) {
                labelEl.textContent = collapsed ? 'Details' : 'Hide';
            }
        }

        var toggle = row.querySelector('.assignment-row-customs-toggle');
        if (!toggle) return;

        var panelId = toggle.getAttribute('data-customs-toggle');
        if (!panelId) return;

        var panelEl = document.getElementById(panelId);
        if (!panelEl) return;

        toggle.setAttribute('aria-expanded', customsOpen ? 'true' : 'false');
        panelEl.hidden = !customsOpen;
    }

    function replaceRows(rows) {
        if (!listEl) return;

        var previousState = snapshotRowState();

        listEl.innerHTML = '';

        if (!Array.isArray(rows) || rows.length === 0) {
            return;
        }

        var html = rows.map(function (row) {
            var id = String(row.order_id);
            var entry = Object.prototype.hasOwnProperty.call(previousState, id)
                ? previousState[id]
                : undefined;

            var defaultCollapsed = (row.status !== 'rider_pending');
            var collapsedForRender = entry
                ? !!entry.collapsed
                : defaultCollapsed;

            return renderRow(row, collapsedForRender);
        }).join('');

        listEl.innerHTML = html;

        var renderedRows = listEl.querySelectorAll('.assignment-row[data-order-id]');

        for (var i = 0; i < renderedRows.length; i++) {
            var rowEl = renderedRows[i];
            var id    = rowEl.getAttribute('data-order-id');
            var entry = id && Object.prototype.hasOwnProperty.call(previousState, id)
                ? previousState[id]
                : undefined;

            var status = rowEl.getAttribute('data-order-status') || '';
            var defaultCollapsed = (status !== 'rider_pending');

            applyRowState(rowEl, entry, defaultCollapsed);
        }
    }

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
            var customsToggle = event.target.closest('.assignment-row-customs-toggle');
            if (customsToggle) {
                event.preventDefault();
                event.stopPropagation();
                toggleCustomsPanel(customsToggle);
                return;
            }

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

    function toggleCustomsPanel(toggleBtn) {
        var panelId = toggleBtn.getAttribute('data-customs-toggle');
        if (!panelId) return;

        var panelEl = document.getElementById(panelId);
        if (!panelEl) return;

        var expanded = toggleBtn.getAttribute('aria-expanded') === 'true';

        toggleBtn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        panelEl.hidden = expanded;
    }

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

                if (!availabilityConfirmPending) {
                    var previousOnline = paintPillFromResponse(data, online);

                    if (previousOnline !== online) {
                        dispatchAvailabilityChanged(online);
                    }
                } else {
                    clearAvailabilityConfirmPending();
                }

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

    document.addEventListener('DOMContentLoaded', function () {
        var seeded = readInitialStateFromWrapper();
        eligible = seeded.eligible;
        online   = seeded.online;

        setPanelOpen(false);

        applyEligibilityState();
        applyAvailabilityState();

        initToggle();
        initAvailabilityPill();
        initAvailabilityModal();
        initNotificationModal();
        initRowDelegation();

        fetchNow();
        setInterval(fetchNow, POLL_INTERVAL_MS);
    });
})();