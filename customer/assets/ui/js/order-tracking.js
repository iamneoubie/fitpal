/**
 * FitPal Customer Order Tracking — client behaviour.
 *
 * Owns three surfaces on customer/pages/order-tracking.php:
 *
 *   1. The customer chat modal.
 *      Two channels:
 *        - 'restaurant_account' — talk to the kitchen.
 *        - 'delivery_rider'     — talk to the rider; only becomes
 *                                 live once the rider has accepted.
 *      Each channel is loaded once on first open (a full fetch
 *      through message-handler.php's `get` action), then polled
 *      with `since_id` to pick up new messages. Sending posts to
 *      message-handler.php's `send` action; marking read posts to
 *      the `read` action.
 *
 *   2. The real-time status poll.
 *      Every few seconds, sends `get_tracking_status` to the
 *      customer order handler with the client's current revision.
 *      When the server reports that the revision has changed, the
 *      client reconciles the chat modal's gating flags in place —
 *      it does NOT reload the page.
 *
 *   3. Origin-aware chat modal opening.
 *
 * ---------------------------------------------------------------------
 * CLASS-NAME CONTRACT
 * ---------------------------------------------------------------------
 * This file emits three shapes of node. The exact class strings are
 * the boundary between this file and order-tracking.css. Both must
 * agree, and both must agree with the docblock in
 * customer/pages/order-tracking.php.
 *
 * Sent (customer's own message):
 *
 *   <div class="customer-chat-message customer-chat-message-sent">
 *     <span class="customer-chat-message-sender">You</span>
 *     <span class="customer-chat-message-text">…</span>
 *     <span class="customer-chat-message-time">…</span>
 *   </div>
 *
 * Received (from the restaurant or the rider):
 *
 *   <div class="customer-chat-message customer-chat-message-received">
 *     <span class="customer-chat-message-sender">Restaurant</span>
 *     <span class="customer-chat-message-text">…</span>
 *     <span class="customer-chat-message-time">…</span>
 *   </div>
 *
 * System (server refusal, channel-closed notice):
 *
 *   <div class="customer-chat-system">…</div>
 *
 * The previous revision of this file emitted the bare classes
 * `sent` and `received`, which the CSS at the time did not target.
 * The two files drifted and both bubbles rendered identically. This
 * revision pins the modifier to the base class as a compound
 * class name, which is the exact string order-tracking.css now
 * targets.
 *
 * The `direction` field on every entry the server returns is set
 * by message-handler.php's shapeMessage(). This file trusts it
 * verbatim. A `direction === 'sent'` entry is rendered with the
 * sent modifier; every other entry is rendered with the received
 * modifier.
 *
 * ---------------------------------------------------------------------
 * MODAL VISIBILITY
 * ---------------------------------------------------------------------
 * order-tracking.css defines the modal's visible state behind a
 * class, not behind a bare inline `display` flip:
 *
 *     .modal             { display: none; opacity: 0; }
 *     .modal.active      { display: flex !important; opacity: 1; }
 *
 * Every open in this file goes through one pair of helpers,
 * `openModal` / `closeModal`, that set `display: flex` inline, force
 * a reflow, and toggle `.active`. closeModal waits for the fade-out
 * transition before restoring `display: none`.
 *
 * ---------------------------------------------------------------------
 * LOADING STATE
 * ---------------------------------------------------------------------
 * The loading placeholder is shown exactly once per channel: on the
 * very first load for that channel. After that, the modal never
 * returns to the loading placeholder.
 *
 * Two pieces of state drive this:
 *
 *   hasLoadedMessages  — an object keyed by channel. Set to true
 *                        the first time a `get` response arrives,
 *                        success or failure.
 *   messageCursor      — the highest message_id the client holds
 *                        per channel.
 *
 * ---------------------------------------------------------------------
 * CHANNEL GATING (client side)
 * ---------------------------------------------------------------------
 * The tracking page publishes two data attributes on #trackingPage:
 *
 *     data-can-message-kitchen="1|0"
 *     data-can-message-rider="1|0"
 *
 * The chat modal's tab bar only ever renders tabs whose flag is 1.
 * The real-time status poll re-reads the flags on every revision
 * change and updates the local state. If a channel becomes
 * unavailable while the modal is open, the tab is removed from the
 * DOM and the modal switches to the other channel (or closes).
 *
 * ---------------------------------------------------------------------
 * Config
 * ---------------------------------------------------------------------
 * The tracking page writes these data attributes on #trackingPage:
 *
 *     data-order-id            the order this page tracks
 *     data-csrf-token          the customer's own CSRF token
 *     data-default-chat-tab    'restaurant_account' or
 *                              'delivery_rider'
 *     data-can-message-kitchen '1' or '0'
 *     data-can-message-rider   '1' or '0'
 *     data-order-status        the current order_status
 *     data-revision            the initial poll revision
 *     data-handler-url         the customer order handler
 *
 * ---------------------------------------------------------------------
 * Rules honored
 * ---------------------------------------------------------------------
 *   - No CSS in this file.
 *   - No <svg> injection.
 *   - No window.alert / confirm / prompt.
 *
 * @package FitPal
 * @version 5.0 — Class names emitted by appendMessageNode() are now
 *                the exact strings order-tracking.css targets:
 *                `customer-chat-message-sent` and
 *                `customer-chat-message-received`, each on the same
 *                node as the base `.customer-chat-message` class.
 *
 *                The inner nodes are now `customer-chat-message-sender`,
 *                `customer-chat-message-text`, and
 *                `customer-chat-message-time`, which the CSS has
 *                rules for. The previous revision emitted
 *                `customer-chat-bubble` and `customer-chat-text`,
 *                which had no CSS rules of their own.
 *
 *                This is the fix for the rider channel also
 *                mis-anchoring: the handler now returns
 *                `direction = 'sent'` for the customer's own
 *                messages on both channels, and this file renders
 *                every `direction === 'sent'` entry with the sent
 *                modifier.
 *
 *                The loading-state fix from v4.1 is retained:
 *                hasLoadedMessages[channel] and
 *                pollInFlight[channel] gate the placeholder so it
 *                can only appear once per channel.
 *
 *                (4.1: loading-state fix. 4.0: modal open/close
 *                toggles .active; real-time poll no longer
 *                reloads; channel availability tracked in local
 *                state; send refusals rendered as system
 *                messages; Escape handler added. 3.0: poll
 *                endpoint fallback renamed. 2.0: origin-aware
 *                open. 1.5: chat-modal origin opening. 1.4: chat
 *                polling. 1.3: chat gating by order status. 1.2:
 *                real-time poll. 1.1: initial tracking JS.)
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

    var POLL_INTERVAL_MS = 6000;

    var currentRevision = page.getAttribute('data-revision') || '';

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

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function fetchJson(url, body) {
        return fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).then(function (res) { return res.json(); });
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
    // REAL-TIME STATUS POLL
    // -----------------------------------------------------------------

    function applyTrackingState(state) {
        if (!state || typeof state !== 'object') return;

        if (typeof state.can_message_kitchen === 'boolean') {
            canMessageKitchen = state.can_message_kitchen;
        }
        if (typeof state.can_message_rider === 'boolean') {
            canMessageRider = state.can_message_rider;
        }

        if (typeof state.order_status === 'string' && state.order_status !== '') {
            ORDER_STATUS = state.order_status;
        }

        page.setAttribute('data-can-message-kitchen', canMessageKitchen ? '1' : '0');
        page.setAttribute('data-can-message-rider',   canMessageRider   ? '1' : '0');
        page.setAttribute('data-order-status', ORDER_STATUS);

        if (chatOpen) {
            reconcileOpenChat();
        }
    }

    function pollStatus() {
        var body = new FormData();
        body.append('action', 'get_tracking_status');
        body.append('csrf_token', CSRF_TOKEN);
        body.append('order_id', String(ORDER_ID));
        body.append('current_revision', currentRevision);

        fetchJson(POLL_ENDPOINT, body)
            .then(function (data) {
                if (!data || data.status !== 'success') return;

                if (typeof data.revision === 'string' && data.revision !== '') {
                    if (currentRevision !== '' && data.revision !== currentRevision) {
                        currentRevision = data.revision;
                        if (data.tracking_state) {
                            applyTrackingState(data.tracking_state);
                        } else if (typeof data.order_status === 'string') {
                            applyTrackingState({
                                order_status: data.order_status
                            });
                        }
                    } else {
                        currentRevision = data.revision;
                    }
                }

                if (data.tracking_state) {
                    applyTrackingState(data.tracking_state);
                }
            })
            .catch(function () {
                // Silent fail. The next tick retries.
            });
    }

    function initStatusPoll() {
        if (ORDER_STATUS === 'delivered'
            || ORDER_STATUS === 'cancelled'
            || ORDER_STATUS === 'refunded'
            || ORDER_STATUS === 'failed') {
            if (ORDER_STATUS !== 'delivered') {
                return;
            }
        }

        setInterval(pollStatus, POLL_INTERVAL_MS);
    }

    // -----------------------------------------------------------------
    // CHAT MODAL
    // -----------------------------------------------------------------

    var activeChannel = DEFAULT_CHAT_TAB;
    var messageCursor = { restaurant_account: 0, delivery_rider: 0 };
    var messagePollerTimer = null;
    var chatOpen = false;

    var hasLoadedMessages = { restaurant_account: false, delivery_rider: false };
    var pollInFlight      = { restaurant_account: false, delivery_rider: false };

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

    /**
     * Append one message node to the chat body.
     *
     * The outer node carries two class names:
     *   - .customer-chat-message
     *   - .customer-chat-message-sent OR .customer-chat-message-received
     *
     * The inner nodes are:
     *   - .customer-chat-message-sender
     *   - .customer-chat-message-text
     *   - .customer-chat-message-time
     *
     * These strings are the boundary between this file and
     * order-tracking.css. Both files must agree.
     *
     * @param {{direction:string, sender:string, content:string, time:string}} entry
     */
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

        if (pollInFlight[activeChannel]) return;
        pollInFlight[activeChannel] = true;

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
                // Silent. The next tick retries.
            })
            .finally(function () {
                pollInFlight[channel] = false;
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