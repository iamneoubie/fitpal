/**
 * FitPal Customer Order Tracking — client behaviour.
 *
 * Owns three surfaces on customer/pages/order-tracking.php:
 *
 *   1. The customer chat modal.
 *   2. The real-time status poll.
 *   3. Origin-aware chat modal opening.
 *
 * ---------------------------------------------------------------------
 * CLOSED-CHAT FORM SWAP
 * ---------------------------------------------------------------------
 * When the customer opens the chat modal on an order whose grace
 * window has expired — or whose status is terminal — the
 * message-handler's `get` action returns:
 *
 *     { status: 'error', message: '…', reason: 'window_closed' }
 *     { status: 'error', message: '…', reason: 'terminal' }
 *
 * When that happens, the message form is replaced by a static
 * notice inside the modal body.
 *
 * ---------------------------------------------------------------------
 * REAL-TIME STATUS POLL
 * ---------------------------------------------------------------------
 * Every few seconds the page sends get_tracking_payload to
 * customer-order-handler.php. The response carries a revision
 * hash. When the revision differs from the last-seen value, the
 * page patches these regions in place:
 *
 *     #trackingStatusTitle
 *     #trackingStatusDescription
 *     #trackingStatusBadge
 *     #trackingStatusIconImg
 *     #trackingTimelineCard  (per-step class changes)
 *     #trackingChatClosedWrapper
 *
 * Everything else on the page — the chat modal if it is open, the
 * order summary, scroll position, focus — is left alone.
 *
 * ---------------------------------------------------------------------
 * CLASS-NAME CONTRACT
 * ---------------------------------------------------------------------
 * This file emits three shapes of chat node. The exact class
 * strings are the boundary between this file and
 * order-tracking.css.
 *
 * Sent (customer's own message):
 *   <div class="customer-chat-message customer-chat-message-sent">
 *     <span class="customer-chat-message-sender">You</span>
 *     <span class="customer-chat-message-text">…</span>
 *     <span class="customer-chat-message-time">…</span>
 *   </div>
 *
 * Received:
 *   <div class="customer-chat-message customer-chat-message-received">
 *     <span class="customer-chat-message-sender">Restaurant</span>
 *     <span class="customer-chat-message-text">…</span>
 *     <span class="customer-chat-message-time">…</span>
 *   </div>
 *
 * System:
 *   <div class="customer-chat-system">…</div>
 *
 * ---------------------------------------------------------------------
 * MODAL VISIBILITY
 * ---------------------------------------------------------------------
 * Every open goes through openModal / closeModal, which set
 * display: flex inline, force a reflow, and toggle `.active`.
 * closeModal waits for the fade-out transition.
 *
 * ---------------------------------------------------------------------
 * CONFIG
 * ---------------------------------------------------------------------
 * #trackingPage publishes:
 *     data-order-id
 *     data-csrf-token
 *     data-default-chat-tab
 *     data-can-message-kitchen
 *     data-can-message-rider
 *     data-order-status
 *     data-revision
 *     data-status-revision
 *     data-asset-base
 *     data-handler-url
 *
 * Rules honored:
 *   - No CSS in this file.
 *   - No <svg> injection.
 *   - No window.alert / confirm / prompt.
 *
 * @package FitPal
 * @version 7.0 — The poll now patches the status card, timeline,
 *                and chat-closed notice in place when the server
 *                reports a status change. No manual reload needed
 *                to see a kitchen or rider update.
 *
 *                (6.0: closed-chat form swap. 5.0: fixed message
 *                class names. 4.1: loading-state fix. 4.0: real-
 *                time poll no longer reloads; channel availability
 *                tracked in local state.)
 */
(function () {
    'use strict';

    var page = document.getElementById('trackingPage');
    if (!page) return;

    // -----------------------------------------------------------------
    // CONFIG
    // -----------------------------------------------------------------

    var ORDER_ID    = parseInt(page.getAttribute('data-order-id') || '0', 10);
    var CSRF_TOKEN  = page.getAttribute('data-csrf-token') || '';
    var ORDER_STATUS = page.getAttribute('data-order-status') || '';
    var DEFAULT_CHAT_TAB = page.getAttribute('data-default-chat-tab') || 'restaurant_account';

    var canMessageKitchen = page.getAttribute('data-can-message-kitchen') === '1';
    var canMessageRider   = page.getAttribute('data-can-message-rider') === '1';

    var POLL_ENDPOINT = page.getAttribute('data-handler-url')
        || '../backend/handlers/customer-order-handler.php';

    var ASSET_BASE = page.getAttribute('data-asset-base')
        || window.FITPAL_ASSET_BASE
        || '../../shared/';

    var POLL_INTERVAL_MS = 6000;

    var currentRevision = page.getAttribute('data-revision') || '';
    var statusRevision  = page.getAttribute('data-status-revision') || '';

    var CLOSED_NOTICE_TEXT =
        'This conversation is closed. This order is available for ' +
        'history only. You can no longer send messages on this order.';

    // -----------------------------------------------------------------
    // ELEMENT HANDLES
    // -----------------------------------------------------------------

    var chatOpenBtn           = document.getElementById('chatOpenBtn');
    var chatOpenBtnRestaurant = document.getElementById('chatOpenBtnRestaurant');
    var chatModal             = document.getElementById('customerChatModal');
    var chatCloseBtn          = document.getElementById('customerChatClose');
    var chatSubtitle          = document.getElementById('customerChatSubtitle');
    var chatMessages          = document.getElementById('customerChatMessages');
    var chatForm              = document.getElementById('customerChatForm');
    var chatInput             = document.getElementById('customerChatInput');
    var chatRecipientField    = document.getElementById('customerChatRecipient');
    var chatOrderIdField      = document.getElementById('customerChatOrderId');

    // -----------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function fetchJson(url, body) {
        return fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).then(function (res) { return res.json(); });
    }

    function escapeHtml(text) {
        var d = document.createElement('div');
        d.textContent = String(text == null ? '' : text);
        return d.innerHTML;
    }

    function iconUrl(filename) {
        return ASSET_BASE + 'assets/images/icons/' + filename;
    }

    // -----------------------------------------------------------------
    // MODAL VISIBILITY
    // -----------------------------------------------------------------

    function openModal(modal) {
        if (!modal) return;

        document.body.style.overflow = 'hidden';

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
                document.body.style.overflow = '';
            }
        }, 260);
    }

    // -----------------------------------------------------------------
    // CLOSED-CHAT FORM SWAP
    // -----------------------------------------------------------------

    var closedChannels = { restaurant_account: false, delivery_rider: false };

    function ensureClosedNoticeNode() {
        if (!chatMessages) return null;

        var existing = qs('.customer-chat-closed-notice', chatMessages);
        if (existing) return existing;

        var notice = document.createElement('div');
        notice.className = 'customer-chat-closed-notice';

        var icon = document.createElement('img');
        icon.className = 'customer-chat-closed-icon';
        icon.alt = '';
        icon.width = 18;
        icon.height = 18;
        icon.src = iconUrl('information-fill.svg');
        icon.onerror = function () {
            if (this.getAttribute('data-fallback-applied') === '1') {
                if (this.parentNode) this.parentNode.removeChild(this);
                return;
            }
            this.setAttribute('data-fallback-applied', '1');
            this.src = iconUrl('file-warning-fill.svg');
        };

        var text = document.createElement('p');
        text.className = 'customer-chat-closed-text';
        text.textContent = CLOSED_NOTICE_TEXT;

        notice.appendChild(icon);
        notice.appendChild(text);

        chatMessages.appendChild(notice);

        if (chatMessages.scrollTop !== undefined) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        return notice;
    }

    function removeClosedNoticeNode() {
        if (!chatMessages) return;
        var node = qs('.customer-chat-closed-notice', chatMessages);
        if (node && node.parentNode) {
            node.parentNode.removeChild(node);
        }
    }

    function applyClosedState(channel) {
        if (!channel) return;
        if (closedChannels[channel] === true) return;
        closedChannels[channel] = true;

        if (chatForm) chatForm.hidden = true;

        ensureClosedNoticeNode();
    }

    function clearClosedStateForOpenChannel(channel) {
        if (!channel) return;

        if (chatForm) chatForm.hidden = false;

        var anyOtherClosed = Object.keys(closedChannels).some(function (key) {
            return key !== channel && closedChannels[key] === true;
        });

        if (!anyOtherClosed) {
            removeClosedNoticeNode();
        }
    }

    function isClosedReason(reason) {
        return reason === 'window_closed' || reason === 'terminal';
    }

    // -----------------------------------------------------------------
    // REAL-TIME STATUS POLL
    // -----------------------------------------------------------------

    function setAttributeSafe(el, name, value) {
        if (!el) return;
        el.setAttribute(name, String(value));
    }

    /**
     * Apply the full tracking payload to the page.
     *
     * Only the sections the payload describes are touched.
     *
     * @param {object} payload
     */
    function applyTrackingPayload(payload) {
        if (!payload || payload.status !== 'success') return;

        var status = payload.order_status || '';
        var meta   = payload.status_meta || {};
        var idx    = parseInt(payload.timeline_index, 10);

        ORDER_STATUS = status;

        // ---- Chat gating flags (used by the modal) ----
        if (payload.chat) {
            canMessageKitchen = !!payload.chat.can_message_kitchen;
            canMessageRider   = !!payload.chat.can_message_rider;

            setAttributeSafe(page, 'data-can-message-kitchen', canMessageKitchen ? '1' : '0');
            setAttributeSafe(page, 'data-can-message-rider',   canMessageRider   ? '1' : '0');
            setAttributeSafe(page, 'data-order-status',        status);

            if (chatOpen) {
                reconcileOpenChat();
            }
        }

        // ---- Status card ----
        var titleEl       = document.getElementById('trackingStatusTitle');
        var descriptionEl = document.getElementById('trackingStatusDescription');
        var badgeEl       = document.getElementById('trackingStatusBadge');
        var iconImg       = document.getElementById('trackingStatusIconImg');

        if (titleEl && meta.label) {
            titleEl.textContent = meta.label;
        }
        if (descriptionEl && meta.description) {
            descriptionEl.textContent = meta.description;
        }
        if (badgeEl && meta.badge && meta.label) {
            badgeEl.className   = 'badge ' + meta.badge;
            badgeEl.textContent = meta.label;
        }
        if (iconImg && meta.icon) {
            iconImg.src = iconUrl(meta.icon);
        }

        // ---- Timeline ----
        var timelineCard = document.getElementById('trackingTimelineCard');
        if (timelineCard) {
            if (payload.is_terminal) {
                timelineCard.style.display = 'none';
            } else {
                timelineCard.style.display = '';
                var steps = qsa('.tracking-step', timelineCard);
                steps.forEach(function (step, i) {
                    var state = 'pending';
                    if (!isNaN(idx) && idx >= 0) {
                        if (i < idx)       state = 'done';
                        else if (i === idx) state = 'current';
                    }
                    step.className = 'tracking-step ' + state;

                    var dotImg = qs('.tracking-step-dot img', step);
                    if (dotImg) {
                        dotImg.src = iconUrl(
                            state === 'done' ? 'verified-fill.svg' : 'time-fill.svg'
                        );
                    }
                });
            }
        }

        // ---- Chat closed notice ----
        var closedWrapper = document.getElementById('trackingChatClosedWrapper');
        if (closedWrapper && payload.chat) {
            if (!payload.chat.is_reachable && payload.chat.closed_reason) {
                closedWrapper.innerHTML =
                    '<div class="tracking-chat-closed-notice" role="status">'
                    + '<img src="' + escapeHtml(iconUrl('information-fill.svg')) + '"'
                    + ' alt="" class="tracking-chat-closed-icon" width="18" height="18"'
                    + ' onerror="this.onerror=null; this.src=\''
                    + escapeHtml(iconUrl('file-warning-fill.svg'))
                    + '\'">'
                    + '<p class="tracking-chat-closed-text">'
                    + escapeHtml(payload.chat.closed_reason)
                    + '</p>'
                    + '</div>';
            } else {
                closedWrapper.innerHTML = '';
            }
        }

        // ---- Advance the revision cursor ----
        if (typeof payload.revision === 'string' && payload.revision !== '') {
            statusRevision = payload.revision;
            setAttributeSafe(page, 'data-status-revision', statusRevision);
        }
    }

    var pollInFlight = false;

    function pollStatus() {
        if (pollInFlight) return;
        if (document.visibilityState !== 'visible') return;

        pollInFlight = true;

        var body = new FormData();
        body.append('action', 'get_tracking_payload');
        body.append('csrf_token', CSRF_TOKEN);
        body.append('order_id', String(ORDER_ID));

        fetchJson(POLL_ENDPOINT, body)
            .then(function (data) {
                if (!data || data.status !== 'success') return;

                var nextRevision = String(data.revision || '');
                if (nextRevision === '') return;

                if (nextRevision === statusRevision) {
                    return;
                }

                applyTrackingPayload(data);
            })
            .catch(function () {
                // Silent. Next tick retries.
            })
            .finally(function () {
                pollInFlight = false;
            });
    }

    var statusPollTimer = null;

    function startStatusPoll() {
        if (statusPollTimer !== null) return;
        statusPollTimer = setInterval(pollStatus, POLL_INTERVAL_MS);
    }

    function stopStatusPoll() {
        if (statusPollTimer === null) return;
        clearInterval(statusPollTimer);
        statusPollTimer = null;
    }

    function initStatusPoll() {
        if (ORDER_STATUS === 'cancelled' || ORDER_STATUS === 'refunded') {
            // Terminal non-delivered orders have nothing left to
            // watch. Delivered orders still need to watch for the
            // grace window closing so the chat-closed notice
            // appears at the right moment.
            return;
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                pollStatus();
                startStatusPoll();
            } else {
                stopStatusPoll();
            }
        });

        startStatusPoll();
    }

    // -----------------------------------------------------------------
    // CHAT MODAL
    // -----------------------------------------------------------------

    var activeChannel = DEFAULT_CHAT_TAB;
    var messageCursor = { restaurant_account: 0, delivery_rider: 0 };
    var messagePollerTimer = null;
    var chatOpen = false;

    var hasLoadedMessages = { restaurant_account: false, delivery_rider: false };
    var chatPollInFlight  = { restaurant_account: false, delivery_rider: false };

    function channelIsAvailable(channel) {
        if (channel === 'restaurant_account') return canMessageKitchen;
        if (channel === 'delivery_rider')     return canMessageRider;
        return false;
    }

    function availableChannels() {
        var out = [];
        if (canMessageKitchen) out.push('restaurant_account');
        if (canMessageRider)   out.push('delivery_rider');
        return out;
    }

    function removeChannelTab(channel) {
        if (!chatModal) return;
        var tab = chatModal.querySelector(
            '.customer-chat-tab[data-recipient="' + channel + '"]'
        );
        if (tab && tab.parentNode) {
            tab.parentNode.removeChild(tab);
        }
    }

    function setActiveTabClasses() {
        if (!chatModal) return;
        qsa('.customer-chat-tab', chatModal).forEach(function (tab) {
            var isActive = tab.getAttribute('data-recipient') === activeChannel;
            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
    }

    function reconcileOpenChat() {
        if (!canMessageKitchen) removeChannelTab('restaurant_account');
        if (!canMessageRider)   removeChannelTab('delivery_rider');

        if (channelIsAvailable(activeChannel)) {
            setActiveTabClasses();
            return;
        }

        var remaining = availableChannels();
        if (remaining.length === 0) {
            closeChatModal();
            return;
        }

        switchChannel(remaining[0]);
    }

    function setModalOpen(open) {
        if (!chatModal) return;

        if (open) {
            openModal(chatModal);
        } else {
            closeModal(chatModal);
        }

        chatOpen = !!open;
    }

    function showLoading() {
        if (!chatMessages) return;
        chatMessages.innerHTML =
            '<div class="customer-chat-loading"><span>Loading messages…</span></div>';
    }

    function showEmpty() {
        if (!chatMessages) return;
        chatMessages.innerHTML =
            '<div class="customer-chat-empty"><span>No messages yet.</span></div>';
    }

    function appendMessageNode(entry) {
        if (!chatMessages) return;

        var placeholder = qs('.customer-chat-loading, .customer-chat-empty', chatMessages);
        if (placeholder) placeholder.remove();

        var isSent = entry.direction === 'sent';
        var modifier = isSent
            ? 'customer-chat-message-sent'
            : 'customer-chat-message-received';

        var node = document.createElement('div');
        node.className = 'customer-chat-message ' + modifier;

        var sender = document.createElement('span');
        sender.className = 'customer-chat-message-sender';
        sender.textContent = entry.sender || (isSent ? 'You' : '');
        node.appendChild(sender);

        var text = document.createElement('span');
        text.className = 'customer-chat-message-text';
        text.textContent = entry.content || '';
        node.appendChild(text);

        var time = document.createElement('span');
        time.className = 'customer-chat-message-time';
        time.textContent = entry.time || '';
        node.appendChild(time);

        chatMessages.appendChild(node);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function appendSystemMessage(text) {
        if (!chatMessages) return;

        var placeholder = qs('.customer-chat-loading, .customer-chat-empty', chatMessages);
        if (placeholder) placeholder.remove();

        var node = document.createElement('div');
        node.className = 'customer-chat-system';
        node.textContent = text;

        chatMessages.appendChild(node);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function replaceMessages(entries) {
        if (!chatMessages) return;
        chatMessages.innerHTML = '';

        if (!entries || entries.length === 0) {
            showEmpty();
            return;
        }

        entries.forEach(appendMessageNode);
    }

    function loadMessages(channel, sinceId) {
        var body = new FormData();
        body.append('action', 'get');
        body.append('csrf_token', CSRF_TOKEN);
        body.append('order_id', String(ORDER_ID));
        body.append('channel', channel);
        if (sinceId && sinceId > 0) {
            body.append('since_id', String(sinceId));
        }

        var endpoint = (chatForm && chatForm.getAttribute('action'))
            ? chatForm.getAttribute('action')
            : '../backend/handlers/message-handler.php';

        var isFirstLoad = !hasLoadedMessages[channel];

        if (isFirstLoad) {
            showLoading();
        }

        fetchJson(endpoint, body)
            .then(function (data) {
                hasLoadedMessages[channel] = true;

                if (!data || data.status !== 'success') {
                    if (data && isClosedReason(data.reason)) {
                        if (chatMessages) chatMessages.innerHTML = '';
                        applyClosedState(channel);
                        return;
                    }

                    if (data && data.message) {
                        if (sinceId && sinceId > 0) {
                            appendSystemMessage(data.message);
                        } else {
                            if (chatMessages) chatMessages.innerHTML = '';
                            appendSystemMessage(data.message);
                        }
                    } else if (isFirstLoad) {
                        replaceMessages([]);
                    }
                    return;
                }

                var entries = data.messages || [];

                if (sinceId && sinceId > 0) {
                    entries.forEach(appendMessageNode);
                } else {
                    replaceMessages(entries);
                }

                if (typeof data.max_id === 'number' && data.max_id > (messageCursor[channel] || 0)) {
                    messageCursor[channel] = data.max_id;
                }
            })
            .catch(function () {
                hasLoadedMessages[channel] = true;

                if (isFirstLoad) {
                    replaceMessages([]);
                }
            });
    }

    function pollMessages() {
        if (!chatOpen) return;
        if (!channelIsAvailable(activeChannel)) {
            reconcileOpenChat();
            return;
        }

        if (closedChannels[activeChannel] === true) return;

        if (chatPollInFlight[activeChannel]) return;
        chatPollInFlight[activeChannel] = true;

        var channel = activeChannel;
        var sinceId = messageCursor[channel] || 0;

        var body = new FormData();
        body.append('action', 'get');
        body.append('csrf_token', CSRF_TOKEN);
        body.append('order_id', String(ORDER_ID));
        body.append('channel', channel);
        if (sinceId > 0) {
            body.append('since_id', String(sinceId));
        }

        var endpoint = (chatForm && chatForm.getAttribute('action'))
            ? chatForm.getAttribute('action')
            : '../backend/handlers/message-handler.php';

        fetchJson(endpoint, body)
            .then(function (data) {
                if (!data || data.status !== 'success') {
                    if (data && isClosedReason(data.reason)) {
                        applyClosedState(channel);
                        return;
                    }

                    if (data && data.message) {
                        appendSystemMessage(data.message);
                    }
                    return;
                }

                var entries = data.messages || [];
                entries.forEach(appendMessageNode);

                if (typeof data.max_id === 'number' && data.max_id > (messageCursor[channel] || 0)) {
                    messageCursor[channel] = data.max_id;
                }
            })
            .catch(function () {
                // Silent. Next tick retries.
            })
            .finally(function () {
                chatPollInFlight[channel] = false;
            });
    }

    function startMessagePolling() {
        stopMessagePolling();
        messagePollerTimer = setInterval(pollMessages, 5000);
    }

    function stopMessagePolling() {
        if (messagePollerTimer !== null) {
            clearInterval(messagePollerTimer);
            messagePollerTimer = null;
        }
    }

    function switchChannel(channel) {
        if (!channelIsAvailable(channel)) {
            var remaining = availableChannels();
            if (remaining.length === 0) {
                closeChatModal();
                return;
            }
            channel = remaining[0];
        }

        activeChannel = channel;
        if (chatRecipientField) chatRecipientField.value = channel;

        setActiveTabClasses();

        if (chatSubtitle) {
            var label = (channel === 'delivery_rider') ? 'Rider' : 'Restaurant';
            chatSubtitle.textContent = 'Order #' + ORDER_ID + ' • ' + label;
        }

        if (closedChannels[channel] === true) {
            applyClosedState(channel);
        } else {
            clearClosedStateForOpenChannel(channel);
        }

        if (!hasLoadedMessages[channel]) {
            showLoading();
        }

        loadMessages(activeChannel, messageCursor[activeChannel] || 0);

        var readBody = new FormData();
        readBody.append('action', 'read');
        readBody.append('csrf_token', CSRF_TOKEN);
        readBody.append('order_id', String(ORDER_ID));
        readBody.append('channel', channel);

        var endpoint = (chatForm && chatForm.getAttribute('action'))
            ? chatForm.getAttribute('action')
            : '../backend/handlers/message-handler.php';

        fetchJson(endpoint, readBody).catch(function () {
            // Silent fail. Read marking is best-effort.
        });
    }

    function openChatModal(channel) {
        if (!chatModal) return;

        if (!channelIsAvailable(channel)) {
            var remaining = availableChannels();
            if (remaining.length === 0) {
                return;
            }
            channel = remaining[0];
        }

        setModalOpen(true);
        switchChannel(channel);
        startMessagePolling();
    }

    function closeChatModal() {
        setModalOpen(false);
        stopMessagePolling();
    }

    function initChatModal() {
        if (!chatModal) return;

        if (chatOrderIdField) chatOrderIdField.value = String(ORDER_ID);

        if (chatOpenBtn) {
            chatOpenBtn.addEventListener('click', function () {
                openChatModal(chatOpenBtn.getAttribute('data-open-tab') || 'delivery_rider');
            });
        }
        if (chatOpenBtnRestaurant) {
            chatOpenBtnRestaurant.addEventListener('click', function () {
                openChatModal(
                    chatOpenBtnRestaurant.getAttribute('data-open-tab') || 'restaurant_account'
                );
            });
        }

        if (chatCloseBtn) {
            chatCloseBtn.addEventListener('click', closeChatModal);
        }

        var overlay = qs('.modal-overlay', chatModal);
        if (overlay) {
            overlay.addEventListener('click', closeChatModal);
        }

        qsa('.customer-chat-tab', chatModal).forEach(function (tab) {
            tab.addEventListener('click', function () {
                switchChannel(tab.getAttribute('data-recipient') || 'restaurant_account');
            });
        });

        if (chatForm) {
            chatForm.addEventListener('submit', function (event) {
                event.preventDefault();

                if (closedChannels[activeChannel] === true) {
                    applyClosedState(activeChannel);
                    return;
                }

                var content = (chatInput && chatInput.value) ? chatInput.value.trim() : '';
                if (content === '') return;

                if (!channelIsAvailable(activeChannel)) {
                    appendSystemMessage(
                        'This conversation is closed and can no longer receive messages.'
                    );
                    return;
                }

                var body = new FormData();
                body.append('action', 'send');
                body.append('csrf_token', CSRF_TOKEN);
                body.append('order_id', String(ORDER_ID));
                body.append('channel', activeChannel);
                body.append('content', content);

                var endpoint = chatForm.getAttribute('action')
                    || '../backend/handlers/message-handler.php';

                var sendBtn = qs('.customer-chat-send', chatForm);
                if (sendBtn) sendBtn.disabled = true;

                if (chatInput) chatInput.value = '';

                fetchJson(endpoint, body)
                    .then(function (data) {
                        if (sendBtn) sendBtn.disabled = false;

                        if (!data || data.status !== 'success') {
                            if (chatInput) chatInput.value = content;

                            if (data && isClosedReason(data.reason)) {
                                applyClosedState(activeChannel);
                                return;
                            }

                            appendSystemMessage(
                                (data && data.message)
                                    ? data.message
                                    : 'Your message could not be sent.'
                            );
                            return;
                        }

                        if (data.message_data) {
                            appendMessageNode(data.message_data);
                        }

                        if (typeof data.max_id === 'number'
                            && data.max_id > (messageCursor[activeChannel] || 0)) {
                            messageCursor[activeChannel] = data.max_id;
                        }
                    })
                    .catch(function () {
                        if (sendBtn) sendBtn.disabled = false;
                        if (chatInput) chatInput.value = content;
                        appendSystemMessage(
                            'A network error occurred. Please try again.'
                        );
                    });
            });
        }
    }

    // -----------------------------------------------------------------
    // ESCAPE HANDLER
    // -----------------------------------------------------------------

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (chatOpen) {
            closeChatModal();
        }
    });

    // -----------------------------------------------------------------
    // BOOTSTRAP
    // -----------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', function () {
        initStatusPoll();
        initChatModal();
    });
})();