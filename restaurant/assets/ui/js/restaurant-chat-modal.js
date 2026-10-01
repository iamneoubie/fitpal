/**
 * FitPal Restaurant Chat Modal — client behaviour.
 *
 * Owns the interactive surface of restaurant/includes/chat-modal.php,
 * the two-tab chat modal that restaurant/pages/kitchen.php renders
 * on the branch view:
 *
 *     Customer tab   counterparty = 'customer'
 *     Rider tab      counterparty = 'delivery_rider'
 *
 * ---------------------------------------------------------------------
 * WHAT THIS FILE OWNS
 * ---------------------------------------------------------------------
 *   - Opening and closing the modal.
 *   - Switching between the two channel tabs.
 *   - Loading the full conversation on first open.
 *   - Delta-polling for new messages every few seconds while open.
 *   - Sending messages and appending the server-returned row.
 *   - Marking the conversation read on open and on tab switch.
 *   - Publishing window.FitPalRestaurantChat, the documented
 *     interface any caller on the kitchen page can use to open a
 *     conversation programmatically.
 *
 * ---------------------------------------------------------------------
 * WHAT THIS FILE DOES NOT OWN
 * ---------------------------------------------------------------------
 *   - The modal markup. It lives in restaurant/includes/chat-modal.php.
 *   - The message endpoint. It lives at
 *     restaurant/backend/handlers/chat-handler.php.
 *   - The status gating on which channels are reachable for a given
 *     order. That decision is made server-side by
 *     restaurantChatChannelStatus() and enforced by gateChannel() in
 *     chat-handler.php. This client renders whichever channels the
 *     server says are open; it does not try to duplicate the rule.
 *   - CSRF issuance. window.RESTAURANT_CSRF_TOKEN is set by
 *     restaurant/includes/header.php from the restaurant session's
 *     own key.
 *
 * ---------------------------------------------------------------------
 * DOM CONTRACT
 * ---------------------------------------------------------------------
 * Reads these IDs from restaurant/includes/chat-modal.php:
 *
 *   #restaurantChatModal         the .modal wrapper; .active shows it
 *   #restaurantChatTitle         heading text
 *   #restaurantChatSubtitle      per-open subtitle
 *   #restaurantChatClose         close button
 *   #restaurantChatMessages      the log region
 *   #restaurantChatForm          the send form
 *   #restaurantChatOrderId       hidden input; current order id
 *   #restaurantChatCounterparty  hidden input; current channel
 *   #restaurantChatInput         the text input
 *
 * Tab buttons carry class .restaurant-chat-tab and attribute
 * data-counterparty.
 *
 * ---------------------------------------------------------------------
 * TRIGGER CONTRACT
 * ---------------------------------------------------------------------
 * Any element on the kitchen page can open the modal by carrying:
 *
 *   data-restaurant-chat-open
 *   data-restaurant-chat-order-id="<order_id>"
 *   data-restaurant-chat-counterparty="customer|delivery_rider"
 *   data-restaurant-chat-subtitle="<display string>"   (optional)
 *
 * The kitchen page's card renderer emits exactly these attributes
 * on the Message button. A delegated listener on document matches
 * them so the button works whether it was rendered server-side or
 * re-rendered by the realtime poll.
 *
 * A programmatic caller can also use:
 *
 *   window.FitPalRestaurantChat.open({
 *       orderId:     <int>,
 *       counterparty: 'customer' | 'delivery_rider',
 *       subtitle:    '<display string>'
 *   });
 *
 * ---------------------------------------------------------------------
 * MESSAGE SHAPE
 * ---------------------------------------------------------------------
 * The handler returns each message as:
 *
 *     {
 *         message_id: int,
 *         direction:  "sent" | "received",
 *         sender:     "You" | "Customer" | "Rider" | ...,
 *         content:    string,
 *         time:       "g:i A" formatted
 *     }
 *
 * and the top-level response carries max_id — the highest
 * message_id in the batch. This file uses max_id as its delta
 * cursor, sending it back as since_message_id on the next poll.
 *
 * The send response carries the newly inserted row in the same
 * shape under message_data, so the appended node matches exactly
 * what a subsequent delta fetch would have returned.
 *
 * ---------------------------------------------------------------------
 * NO CSS, NO SVG
 * ---------------------------------------------------------------------
 * Every visual state this file produces is a class the CSS in
 * restaurant/assets/css/orders.css already styles:
 *
 *   .active                     on the modal, to show it
 *   .restaurant-chat-tab.active on the selected channel tab
 *   .restaurant-chat-loading    placeholder while the first load runs
 *   .restaurant-chat-empty      placeholder when a channel has none
 *   .restaurant-chat-message    base class on every message row
 *   .restaurant-chat-message-sent      own messages
 *   .restaurant-chat-message-received  other party's messages
 *   .restaurant-chat-message-sender    sender label line
 *   .restaurant-chat-message-time      the timestamp line
 *
 * No <svg> is injected. All text is set via textContent, so no HTML
 * is parsed from any server string.
 *
 * @package FitPal
 * @version 1.0 — First version of this file. Fills the gap left by
 *                the kitchen page's Message button, whose
 *                data-restaurant-chat-open trigger was documented in
 *                kitchen-order.js as being handled by "the realtime
 *                script" but was never actually implemented by any
 *                restaurant JS. Without this file the button is a
 *                no-op.
 */
(function () {
    'use strict';

    // -----------------------------------------------------------------
    // CONFIG
    // -----------------------------------------------------------------

    var CSRF_TOKEN = window.RESTAURANT_CSRF_TOKEN || '';

    var CHAT_ENDPOINT = window.RESTAURANT_CHAT_ENDPOINT
        || '../backend/handlers/chat-handler.php';

    var CHAT_POLL_INTERVAL_MS = 5000;

    var DEFAULT_COUNTERPARTY = 'customer';

    var ALLOWED_COUNTERPARTIES = ['customer', 'delivery_rider'];

    // -----------------------------------------------------------------
    // STATE
    // -----------------------------------------------------------------

    var currentOrderId     = 0;
    var currentCounterparty = DEFAULT_COUNTERPARTY;
    var sinceMessageId     = 0;

    var chatPollTimer = null;

    // -----------------------------------------------------------------
    // ELEMENT HANDLES
    //
    // Resolved once on DOMContentLoaded. The modal is a shared
    // include, so it is present on the kitchen branch view after
    // header.php and kitchen.php have rendered.
    // -----------------------------------------------------------------

    var modal              = null;
    var messagesBody       = null;
    var chatForm           = null;
    var orderInput         = null;
    var counterpartyInput  = null;
    var chatInput          = null;
    var chatSubtitle       = null;
    var chatClose          = null;
    var chatTabs           = [];
    var sendBtn            = null;

    // -----------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------

    function isAllowedCounterparty(value) {
        return ALLOWED_COUNTERPARTIES.indexOf(value) !== -1;
    }

    function chatPost(action, payload) {
        var body = new FormData();
        body.append('action', action);
        body.append('csrf_token', CSRF_TOKEN);

        if (payload && typeof payload === 'object') {
            Object.keys(payload).forEach(function (key) {
                body.append(key, String(payload[key]));
            });
        }

        return fetch(CHAT_ENDPOINT, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).then(function (res) { return res.json(); });
    }

    // -----------------------------------------------------------------
    // TABS
    // -----------------------------------------------------------------

    function setActiveTab(counterparty) {
        if (!isAllowedCounterparty(counterparty)) return;

        currentCounterparty = counterparty;
        if (counterpartyInput) counterpartyInput.value = counterparty;

        chatTabs.forEach(function (tab) {
            var isActive = (tab.getAttribute('data-counterparty') === counterparty);
            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
    }

    // -----------------------------------------------------------------
    // RENDER
    // -----------------------------------------------------------------

    function showLoading() {
        if (!messagesBody) return;
        messagesBody.innerHTML = '';

        var wrap = document.createElement('div');
        wrap.className = 'restaurant-chat-loading';

        var span = document.createElement('span');
        span.textContent = 'Loading messages\u2026';

        wrap.appendChild(span);
        messagesBody.appendChild(wrap);
    }

    function showEmpty() {
        if (!messagesBody) return;
        messagesBody.innerHTML = '';

        var wrap = document.createElement('div');
        wrap.className = 'restaurant-chat-empty';

        var span = document.createElement('span');
        span.textContent = 'No messages yet.';

        wrap.appendChild(span);
        messagesBody.appendChild(wrap);
    }

    function clearPlaceholder() {
        if (!messagesBody) return;
        var placeholder = messagesBody.querySelector(
            '.restaurant-chat-loading, .restaurant-chat-empty'
        );
        if (placeholder) placeholder.remove();
    }

    function appendMessageRow(m) {
        if (!messagesBody) return;

        var isSent = (m.direction === 'sent');

        var row = document.createElement('div');
        row.className = 'restaurant-chat-message '
            + (isSent ? 'restaurant-chat-message-sent' : 'restaurant-chat-message-received');

        var sender = document.createElement('span');
        sender.className = 'restaurant-chat-message-sender';
        sender.textContent = m.sender || (isSent ? 'You' : 'Them');
        row.appendChild(sender);

        var bubble = document.createElement('span');
        bubble.className = 'restaurant-chat-bubble';
        bubble.textContent = m.content || '';
        row.appendChild(bubble);

        var time = document.createElement('span');
        time.className = 'restaurant-chat-message-time';
        time.textContent = m.time || '';
        row.appendChild(time);

        messagesBody.appendChild(row);
    }

    function renderMessages(messages, append) {
        if (!messagesBody) return;

        if (!append) {
            messagesBody.innerHTML = '';
        }

        if (!messages || messages.length === 0) {
            if (!append) showEmpty();
            return;
        }

        clearPlaceholder();

        messages.forEach(function (m) {
            appendMessageRow(m);
        });

        messagesBody.scrollTop = messagesBody.scrollHeight;
    }

    // -----------------------------------------------------------------
    // LOAD / POLL
    // -----------------------------------------------------------------

    function loadConversation(reset) {
        if (currentOrderId <= 0) return;

        if (reset) {
            sinceMessageId = 0;
            showLoading();
        }

        chatPost('list', {
            order_id:     currentOrderId,
            counterparty: currentCounterparty
        }).then(function (data) {
            // On the first load we ask for the full list; the poll
            // path uses a delta fetch below. The handler exposes
            // 'list' for a full read and 'poll' for a delta. Using
            // 'list' on reset keeps the initial render simple and
            // independent of the since cursor.
            if (!data || data.status !== 'success') {
                if (reset) showEmpty();
                return;
            }

            if (typeof data.max_id === 'number' && data.max_id > sinceMessageId) {
                sinceMessageId = data.max_id;
            }

            var messages = Array.isArray(data.messages) ? data.messages : [];

            if (reset) {
                renderMessages(messages, false);
            } else if (messages.length > 0) {
                renderMessages(messages, true);
            }
        }).catch(function () {
            if (reset) showEmpty();
        });
    }

    function pollConversation() {
        if (currentOrderId <= 0) return;
        if (sinceMessageId <= 0) return;

        chatPost('poll', {
            order_id:         currentOrderId,
            counterparty:     currentCounterparty,
            since_message_id: sinceMessageId
        }).then(function (data) {
            if (!data || data.status !== 'success') return;

            if (typeof data.max_id === 'number' && data.max_id > sinceMessageId) {
                sinceMessageId = data.max_id;
            }

            var messages = Array.isArray(data.messages) ? data.messages : [];
            if (messages.length > 0) {
                renderMessages(messages, true);
            }
        }).catch(function () {
            // Silent. Next tick retries.
        });
    }

    function startChatPoll() {
        stopChatPoll();
        chatPollTimer = setInterval(function () {
            if (modal && modal.classList.contains('active')) {
                pollConversation();
            }
        }, CHAT_POLL_INTERVAL_MS);
    }

    function stopChatPoll() {
        if (chatPollTimer) {
            clearInterval(chatPollTimer);
            chatPollTimer = null;
        }
    }

    // -----------------------------------------------------------------
    // READ MARKER
    //
    // Fire-and-forget. A failure here never affects the visible
    // conversation.
    // -----------------------------------------------------------------

    function markRead() {
        if (currentOrderId <= 0) return;

        chatPost('read', {
            order_id:     currentOrderId,
            counterparty: currentCounterparty
        }).catch(function () {
            // Silent. Non-critical.
        });
    }

    // -----------------------------------------------------------------
    // OPEN / CLOSE
    // -----------------------------------------------------------------

    function openChat(orderId, counterparty, subtitle) {
        if (!modal) return;

        var oid = parseInt(orderId, 10) || 0;
        if (oid <= 0) return;

        var channel = isAllowedCounterparty(counterparty)
            ? counterparty
            : DEFAULT_COUNTERPARTY;

        currentOrderId = oid;
        if (orderInput) orderInput.value = String(oid);

        setActiveTab(channel);

        if (chatSubtitle && subtitle) {
            chatSubtitle.textContent = subtitle;
        } else if (chatSubtitle) {
            chatSubtitle.textContent = 'Order #' + oid;
        }

        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        loadConversation(true);
        startChatPoll();
        markRead();

        if (chatInput) {
            setTimeout(function () { chatInput.focus(); }, 80);
        }
    }

    function closeChat() {
        if (!modal) return;

        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        modal.style.display = 'none';
        document.body.style.overflow = '';

        stopChatPoll();

        currentOrderId = 0;
        sinceMessageId = 0;

        if (messagesBody) messagesBody.innerHTML = '';
    }

    // -----------------------------------------------------------------
    // SEND
    // -----------------------------------------------------------------

    function sendMessage(content) {
        if (currentOrderId <= 0) return;

        var text = (content || '').trim();
        if (text === '') return;
        if (text.length > 500) text = text.slice(0, 500);

        if (sendBtn) sendBtn.disabled = true;

        chatPost('send', {
            order_id:     currentOrderId,
            counterparty: currentCounterparty,
            content:      text
        }).then(function (data) {
            if (sendBtn) sendBtn.disabled = false;

            if (!data || data.status !== 'success') {
                return;
            }

            if (typeof data.max_id === 'number' && data.max_id > sinceMessageId) {
                sinceMessageId = data.max_id;
            }

            if (data.message_data) {
                clearPlaceholder();
                appendMessageRow(data.message_data);
                if (messagesBody) {
                    messagesBody.scrollTop = messagesBody.scrollHeight;
                }
            }
        }).catch(function () {
            if (sendBtn) sendBtn.disabled = false;
        });
    }

    // -----------------------------------------------------------------
    // DELEGATED TRIGGER
    //
    // Any element on the kitchen page carrying
    // [data-restaurant-chat-open] opens the modal. The three data
    // attributes alongside it supply the order id, the channel, and
    // the subtitle.
    // -----------------------------------------------------------------

    function initTriggerDelegation() {
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-restaurant-chat-open]');
            if (!trigger) return;

            event.preventDefault();
            event.stopPropagation();

            var orderId = parseInt(
                trigger.getAttribute('data-restaurant-chat-order-id') || '0',
                10
            );
            if (orderId <= 0) return;

            var counterparty = trigger.getAttribute('data-restaurant-chat-counterparty')
                || DEFAULT_COUNTERPARTY;
            var subtitle = trigger.getAttribute('data-restaurant-chat-subtitle')
                || '';

            openChat(orderId, counterparty, subtitle);
        });
    }

    // -----------------------------------------------------------------
    // PUBLIC INTERFACE
    // -----------------------------------------------------------------

    function publishInterface() {
        window.FitPalRestaurantChat = {
            open: function (opts) {
                if (!opts || typeof opts !== 'object') return;

                openChat(
                    opts.orderId,
                    opts.counterparty,
                    opts.subtitle
                );
            },
            close: closeChat
        };
    }

    // -----------------------------------------------------------------
    // BOOTSTRAP
    // -----------------------------------------------------------------

    function resolveElements() {
        modal             = document.getElementById('restaurantChatModal');
        messagesBody      = document.getElementById('restaurantChatMessages');
        chatForm          = document.getElementById('restaurantChatForm');
        orderInput        = document.getElementById('restaurantChatOrderId');
        counterpartyInput = document.getElementById('restaurantChatCounterparty');
        chatInput         = document.getElementById('restaurantChatInput');
        chatSubtitle      = document.getElementById('restaurantChatSubtitle');
        chatClose         = document.getElementById('restaurantChatClose');

        chatTabs = modal
            ? Array.prototype.slice.call(
                modal.querySelectorAll('.restaurant-chat-tab')
            )
            : [];

        sendBtn = chatForm
            ? chatForm.querySelector('.restaurant-chat-send')
            : null;
    }

    function initModalChrome() {
        if (!modal) return;

        if (chatClose) {
            chatClose.addEventListener('click', function (event) {
                event.preventDefault();
                closeChat();
            });
        }

        var overlay = modal.querySelector('.modal-overlay');
        if (overlay) {
            overlay.addEventListener('click', function (event) {
                event.preventDefault();
                closeChat();
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            if (!modal.classList.contains('active')) return;
            closeChat();
        });
    }

    function initTabs() {
        chatTabs.forEach(function (tab) {
            tab.addEventListener('click', function (event) {
                event.preventDefault();

                var counterparty = tab.getAttribute('data-counterparty')
                    || DEFAULT_COUNTERPARTY;

                if (counterparty === currentCounterparty) return;

                setActiveTab(counterparty);
                loadConversation(true);
                markRead();
            });
        });
    }

    function initForm() {
        if (!chatForm) return;

        chatForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var content = chatInput ? chatInput.value : '';
            if (!content || content.trim() === '') return;

            if (chatInput) chatInput.value = '';
            sendMessage(content);
        });
    }

    function boot() {
        resolveElements();

        if (!modal) return;

        initModalChrome();
        initTabs();
        initForm();
        initTriggerDelegation();
        publishInterface();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();