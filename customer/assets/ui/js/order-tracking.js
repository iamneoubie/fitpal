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
 * notice inside the modal body. The form is hidden with the
 * `hidden` attribute (not `display: none` inline), so no inline
 * style is written and the swap is a pure DOM operation.
 *
 * The swap is:
 *   - idempotent: re-applying for the same channel is a no-op.
 *   - reversible: switching to a channel that is still open
 *     restores the form.
 *   - final when both channels are closed: the form stays hidden
 *     for the rest of the modal's lifetime in this page view.
 *
 * The notice text is fixed:
 *
 *     "This conversation is closed. This order is available for
 *      history only. You can no longer send messages on this
 *      order."
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
 * Closed-chat form swap (this revision):
 *
 *   <div class="customer-chat-closed-notice">
 *     <img class="customer-chat-closed-icon" src="…" alt="">
 *     <p class="customer-chat-closed-text">…</p>
 *   </div>
 *
 * The `.customer-chat-closed-notice`, `.customer-chat-closed-icon`,
 * and `.customer-chat-closed-text` classes already exist in
 * order-tracking.css for the page-level notice. This file reuses
 * them so no new CSS is required. The modal-scoped instance is
 * distinguished by its parent (`#customerChatMessages`), which
 * carries its own layout; no additional class is introduced.
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
 * CONFIG
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
 *   - No <svg> injection. The closed-chat icon is loaded as an
 *     <img> from shared/assets/images/icons/; the asset base is
 *     read from window.FITPAL_ASSET_BASE or a documented fallback.
 *   - No window.alert / confirm / prompt.
 *
 * @package FitPal
 * @version 6.0 — Adds the closed-chat form swap. When the
 *                message-handler's `get` action returns
 *                reason='window_closed' or reason='terminal', the
 *                message form is hidden and a static notice is
 *                inserted inside the modal body. The swap is
 *                idempotent, reversible on channel switch, and
 *                final once both channels are closed.
 *
 *                Every other surface — the message node class
 *                names, the loading-state fix, the real-time
 *                status poll, the channel-availability tracking,
 *                the modal open/close helpers, the system message
 *                styling — is byte-identical to v5.0.
 *
 *                (5.0: fixed the runtime class-name contract on
 *                sent and received messages. 4.1: loading-state
 *                fix. 4.0: modal open/close toggles .active;
 *                real-time poll no longer reloads; channel
 *                availability tracked in local state; send
 *                refusals rendered as system messages; Escape
 *                handler added. 3.0: poll endpoint fallback
 *                renamed. 2.0: origin-aware open. 1.5: chat-modal
 *                origin opening. 1.4: chat polling. 1.3: chat
 *                gating by order status. 1.2: real-time poll.
 *                1.1: initial tracking JS.)
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

    // Asset base for the closed-chat icon. The tracking page
    // publishes window.FITPAL_ASSET_BASE from header.php; the
    // fallback names the same folder the rest of the customer
    // role uses when resolving shared/ from customer/pages/.
    var ASSET_BASE = window.FITPAL_ASSET_BASE
        || window.FITPAL_ORDERS && window.FITPAL_ORDERS.assetBase
        || '../../shared/';

    // Text of the closed-chat notice. Kept as a single constant so
    // the copy lives in exactly one place.
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
    //
    // When a channel is closed by the server, the message form is
    // hidden and a static notice is inserted at the bottom of the
    // modal body. The form is never removed from the DOM, so
    // switching to a still-open channel can restore it without a
    // re-render.
    //
    // State is tracked per channel so a channel that was observed
    // closed stays closed even if the customer navigates away from
    // it and back. This matches the server's own state: once the
    // grace window has elapsed, the server will keep refusing
    // sends on that channel for the rest of the page's lifetime.
    // -----------------------------------------------------------------

    var closedChannels = { restaurant_account: false, delivery_rider: false };

    /**
     * Ensure a closed-chat notice node exists in the modal body.
     * Returns the node. The node is created once and reused.
     */
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
        icon.src = ASSET_BASE + 'assets/images/icons/information-fill.svg';
        icon.onerror = function () {
            // If the information icon is missing, fall back to the
            // warning icon. If that is missing too, remove the img
            // entirely so the notice still reads as text.
            if (this.getAttribute('data-fallback-applied') === '1') {
                this.parentNode && this.parentNode.removeChild(this);
                return;
            }
            this.setAttribute('data-fallback-applied', '1');
            this.src = ASSET_BASE + 'assets/images/icons/file-warning-fill.svg';
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

    /**
     * Remove the closed-chat notice node if it exists.
     */
    function removeClosedNoticeNode() {
        if (!chatMessages) return;
        var node = qs('.customer-chat-closed-notice', chatMessages);
        if (node && node.parentNode) {
            node.parentNode.removeChild(node);
        }
    }

    /**
     * Apply the closed state for a channel:
     *   - hide the message form (hidden attribute, no inline style)
     *   - insert the closed notice inside the modal body
     *   - remember the channel so it stays closed on return
     */
    function applyClosedState(channel) {
        if (!channel) return;

        if (closedChannels[channel] === true) return;
        closedChannels[channel] = true;

        if (chatForm) {
            chatForm.hidden = true;
        }

        ensureClosedNoticeNode();
    }

    /**
     * Clear the closed state for a channel:
     *   - remove the closed notice (only when no other channel is
     *     also closed)
     *   - unhide the message form
     *
     * Called when the modal switches to a channel that is not in
     * the closed set.
     */
    function clearClosedStateForOpenChannel(channel) {
        if (!channel) return;

        if (chatForm) {
            chatForm.hidden = false;
        }

        // Remove the notice only if no other channel is currently
        // marked closed. If the other channel is still closed, the
        // notice stays visible behind whichever channel the user
        // is looking at, which is the desired behaviour: the
        // modal is still a closed conversation overall.
        var anyOtherClosed = Object.keys(closedChannels).some(function (key) {
            return key !== channel && closedChannels[key] === true;
        });

        if (!anyOtherClosed) {
            removeClosedNoticeNode();
        }
    }

    /**
     * True when the get-response body carries a closed reason.
     */
    function isClosedReason(reason) {
        return reason === 'window_closed' || reason === 'terminal';
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
                    // If the handler refused the channel with a
                    // closed reason, hide the form and show the
                    // closed notice instead of the usual message
                    // list. This is the moment the swap is
                    // applied.
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

        // Do not poll a channel the client has already been told
        // is closed. There is nothing to poll for.
        if (closedChannels[activeChannel] === true) return;

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
                    // A closed reason arriving on the poll means
                    // the grace window expired while the modal was
                    // open. Apply the swap.
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

        // If this channel is known to be closed, hide the form
        // and show the notice. If it is not closed, ensure the
        // form is visible and the notice is gone (unless the
        // other channel is still closed).
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

                // If the channel is known to be closed, refuse
                // the send locally and re-apply the closed state
                // (belt and braces — the form is hidden, but a
                // programmatic submit could still reach here).
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

                            // A closed reason on the send path
                            // means the window shut between the
                            // last load and this send. Apply the
                            // swap rather than just appending a
                            // system message.
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