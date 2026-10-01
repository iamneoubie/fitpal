/**
 * FitPal Rider Chat Modal — client behaviour.
 *
 * Owns the interactive surface of rider/includes/rider-chat-modal.php,
 * the two-tab chat modal that the rider header renders on every
 * authenticated rider page:
 *
 *     Customer tab   recipient = 'customer'
 *     Kitchen tab    recipient = 'restaurant_account'
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
 *   - Publishing window.FitPalRiderChat, the documented interface the
 *     assignment panel calls to open a conversation for a row.
 *
 * ---------------------------------------------------------------------
 * WHAT THIS FILE DOES NOT OWN
 * ---------------------------------------------------------------------
 *   - The modal markup. It lives in rider/includes/rider-chat-modal.php.
 *   - The message endpoint. It lives at
 *     rider/backend/handlers/message-handler.php.
 *   - The status of any order. Order status is the shared
 *     order-transaction layer's responsibility; the rider's tracking
 *     page and the assignment panel are the surfaces that read it.
 *   - CSRF issuance. window.RIDER_CSRF_TOKEN is set by
 *     rider/includes/header.php from the rider session's own key.
 *
 * ---------------------------------------------------------------------
 * DOM CONTRACT
 * ---------------------------------------------------------------------
 * Reads these IDs from rider/includes/rider-chat-modal.php:
 *
 *   #riderChatModal       the .modal wrapper; .active shows it
 *   #riderChatTitle       heading text
 *   #riderChatSubtitle    per-open subtitle
 *   #riderChatClose       close button
 *   #riderChatMessages    the log region
 *   #riderChatForm        the send form
 *   #riderChatOrderId     hidden input; current order id
 *   #riderChatRecipient   hidden input; current channel
 *   #riderChatInput       the text input
 *
 * Tab buttons carry class .rider-chat-tab and attribute
 * data-recipient.
 *
 * ---------------------------------------------------------------------
 * PUBLIC INTERFACE — window.FitPalRiderChat
 * ---------------------------------------------------------------------
 * The modal is opened by two callers:
 *
 *   1. Any element on any rider page carrying:
 *        data-rider-chat-open
 *        data-rider-chat-order-id="<order_id>"
 *        data-rider-chat-recipient="customer|restaurant_account"
 *        data-rider-chat-subtitle="<display string>"   (optional)
 *
 *      The deliveries page uses this. The assignment panel writes
 *      these attributes onto its Message button.
 *
 *   2. A JS caller through the published interface:
 *
 *        window.FitPalRiderChat.open({
 *            orderId:   <int>,
 *            recipient: 'customer' | 'restaurant_account',
 *            subtitle:  '<display string>'
 *        });
 *
 *      The assignment panel calls this form, because the panel's row
 *      buttons are built inside the panel's own renderer rather than
 *      being static anchors.
 *
 *   Both callers reach the same internal openChat(). The published
 *   interface is the stable seam; the delegated click handler is a
 *   convenience for pages whose triggers are static.
 *
 * ---------------------------------------------------------------------
 * MESSAGE SHAPE
 * ---------------------------------------------------------------------
 * The handler returns each message as:
 *
 *     {
 *         message_id: int,
 *         direction:  "sent" | "received",
 *         sender:     "You" | "Customer" | "Kitchen" | ...,
 *         content:    string,
 *         time:       "g:i A" formatted
 *     }
 *
 * and the top-level response carries max_id — the highest
 * message_id in the batch. This file uses max_id as its delta
 * cursor, sending it back as since_id on the next poll.
 *
 * The send response carries the newly inserted row in the same
 * shape under message_data, so the appended node matches exactly
 * what a subsequent delta fetch would have returned.
 *
 * ---------------------------------------------------------------------
 * NO CSS, NO SVG
 * ---------------------------------------------------------------------
 * Every visual state this file produces is a class the CSS in
 * rider/assets/css/assignment-panel.css already styles:
 *
 *   .active                       on the modal, to show it
 *   .rider-chat-tab.active        on the selected channel tab
 *   .rider-chat-loading           placeholder while the first load runs
 *   .rider-chat-empty             placeholder when a channel has none
 *   .rider-chat-message           base class on every message row
 *   .rider-chat-message-sent      own messages
 *   .rider-chat-message-received  other party's messages
 *   .rider-chat-message-sender    sender label line
 *   .rider-chat-bubble            the content wrapper
 *   .rider-chat-message-time      the timestamp line
 *
 * No <svg> is injected. All text is set via textContent, so no HTML
 * is parsed from any server string.
 *
 * @package FitPal
 * @version 1.0 — First version of this file as the rider chat script.
 *
 *                Replaces a file that was a copy of the customer
 *                order-tracking script (own docblock said "FitPal
 *                Customer Order Tracking — client behaviour."), read
 *                the customer modal's IDs (#customerChatModal and
 *                seven others), used window.CUSTOMER_CHAT_ENDPOINT,
 *                bound clicks on [data-open-tab], and ran a status
 *                poll on #trackingPage that belonged to order
 *                tracking. The rider modal was therefore
 *                completely non-functional: no trigger reached it,
 *                no tab switched, no send posted.
 */
(function () {
    'use strict';

    // -----------------------------------------------------------------
    // CONFIG
    // -----------------------------------------------------------------

    var CSRF_TOKEN = window.RIDER_CSRF_TOKEN || '';

    var CHAT_ENDPOINT = window.RIDER_CHAT_ENDPOINT
        || '../backend/handlers/message-handler.php';

    var CHAT_POLL_INTERVAL_MS = 5000;

    var DEFAULT_RECIPIENT = 'customer';

    var ALLOWED_RECIPIENTS = ['customer', 'restaurant_account'];

    // -----------------------------------------------------------------
    // STATE
    // -----------------------------------------------------------------

    var currentOrderId = 0;
    var currentRecipient = DEFAULT_RECIPIENT;
    var sinceId = 0;

    var chatPollTimer = null;

    // -----------------------------------------------------------------
    // ELEMENT HANDLES
    //
    // Resolved once on DOMContentLoaded. The modal is a shared
    // include, so it is present on every authenticated rider page
    // after the header runs.
    // -----------------------------------------------------------------

    var modal = null;
    var messagesBody = null;
    var chatForm = null;
    var orderInput = null;
    var recipientInput = null;
    var chatInput = null;
    var chatSubtitle = null;
    var chatClose = null;
    var chatTabs = [];
    var sendBtn = null;

    // -----------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------

    function isAllowedRecipient(value) {
        return ALLOWED_RECIPIENTS.indexOf(value) !== -1;
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

    function setActiveTab(recipient) {
        if (!isAllowedRecipient(recipient)) return;

        currentRecipient = recipient;
        if (recipientInput) recipientInput.value = recipient;

        chatTabs.forEach(function (tab) {
            var isActive = (tab.getAttribute('data-recipient') === recipient);
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
        wrap.className = 'rider-chat-loading';

        var span = document.createElement('span');
        span.textContent = 'Loading messages\u2026';

        wrap.appendChild(span);
        messagesBody.appendChild(wrap);
    }

    function showEmpty() {
        if (!messagesBody) return;
        messagesBody.innerHTML = '';

        var wrap = document.createElement('div');
        wrap.className = 'rider-chat-empty';

        var span = document.createElement('span');
        span.textContent = 'No messages yet.';

        wrap.appendChild(span);
        messagesBody.appendChild(wrap);
    }

    function clearPlaceholder() {
        if (!messagesBody) return;
        var placeholder = messagesBody.querySelector(
            '.rider-chat-loading, .rider-chat-empty'
        );
        if (placeholder) placeholder.remove();
    }

    function appendMessageRow(m) {
        if (!messagesBody) return;

        var isSent = (m.direction === 'sent');

        var row = document.createElement('div');
        row.className = 'rider-chat-message '
            + (isSent ? 'rider-chat-message-sent' : 'rider-chat-message-received');

        var sender = document.createElement('span');
        sender.className = 'rider-chat-message-sender';
        sender.textContent = m.sender || (isSent ? 'You' : 'Them');
        row.appendChild(sender);

        var bubble = document.createElement('span');
        bubble.className = 'rider-chat-bubble';
        bubble.textContent = m.content || '';
        row.appendChild(bubble);

        var time = document.createElement('span');
        time.className = 'rider-chat-message-time';
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
            sinceId = 0;
            showLoading();
        }

        chatPost('get_messages', {
            order_id:  currentOrderId,
            with_type: currentRecipient,
            since_id:  sinceId
        }).then(function (data) {
            if (!data || data.status !== 'success') {
                if (reset) showEmpty();
                return;
            }

            if (typeof data.max_id === 'number' && data.max_id > sinceId) {
                sinceId = data.max_id;
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

    function startChatPoll() {
        stopChatPoll();
        chatPollTimer = setInterval(function () {
            if (modal && modal.classList.contains('active')) {
                loadConversation(false);
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
    // Fire-and-forget. The server records the read so the next visit
    // starts with a clean slate. A failure here never affects the
    // visible conversation.
    // -----------------------------------------------------------------

    function markRead() {
        if (currentOrderId <= 0) return;

        chatPost('mark_read', {
            order_id:  currentOrderId,
            with_type: currentRecipient
        }).catch(function () {
            // Silent. Non-critical.
        });
    }

    // -----------------------------------------------------------------
    // OPEN / CLOSE
    // -----------------------------------------------------------------

    function openChat(orderId, recipient, subtitle) {
        if (!modal) return;

        var oid = parseInt(orderId, 10) || 0;
        if (oid <= 0) return;

        var channel = isAllowedRecipient(recipient)
            ? recipient
            : DEFAULT_RECIPIENT;

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
        document.body.style.overflow = '';

        stopChatPoll();

        currentOrderId = 0;
        sinceId = 0;

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

        chatPost('send_message', {
            order_id:       currentOrderId,
            recipient_type: currentRecipient,
            content:        text
        }).then(function (data) {
            if (sendBtn) sendBtn.disabled = false;

            if (!data || data.status !== 'success') {
                return;
            }

            if (typeof data.max_id === 'number' && data.max_id > sinceId) {
                sinceId = data.max_id;
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
    // Any element on any rider page carrying [data-rider-chat-open]
    // opens the modal. The three data attributes alongside it supply
    // the order id, the channel, and the subtitle.
    // -----------------------------------------------------------------

    function initTriggerDelegation() {
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-rider-chat-open]');
            if (!trigger) return;

            event.preventDefault();

            var orderId = parseInt(
                trigger.getAttribute('data-rider-chat-order-id') || '0',
                10
            );
            if (orderId <= 0) return;

            var recipient = trigger.getAttribute('data-rider-chat-recipient')
                || DEFAULT_RECIPIENT;
            var subtitle  = trigger.getAttribute('data-rider-chat-subtitle')
                || '';

            openChat(orderId, recipient, subtitle);
        });
    }

    // -----------------------------------------------------------------
    // PUBLIC INTERFACE
    //
    // Published as window.FitPalRiderChat. The assignment panel calls
    // this form because its row buttons are built inside the panel's
    // own renderer rather than being static anchors.
    // -----------------------------------------------------------------

    function publishInterface() {
        window.FitPalRiderChat = {
            open: function (opts) {
                if (!opts || typeof opts !== 'object') return;

                openChat(
                    opts.orderId,
                    opts.recipient,
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
        modal           = document.getElementById('riderChatModal');
        messagesBody    = document.getElementById('riderChatMessages');
        chatForm        = document.getElementById('riderChatForm');
        orderInput      = document.getElementById('riderChatOrderId');
        recipientInput  = document.getElementById('riderChatRecipient');
        chatInput       = document.getElementById('riderChatInput');
        chatSubtitle    = document.getElementById('riderChatSubtitle');
        chatClose       = document.getElementById('riderChatClose');

        chatTabs = modal
            ? Array.prototype.slice.call(
                modal.querySelectorAll('.rider-chat-tab')
            )
            : [];

        sendBtn = chatForm
            ? chatForm.querySelector('.rider-chat-send')
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

                var recipient = tab.getAttribute('data-recipient')
                    || DEFAULT_RECIPIENT;

                if (recipient === currentRecipient) return;

                setActiveTab(recipient);
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

    document.addEventListener('DOMContentLoaded', function () {
        resolveElements();

        if (!modal) return;

        initModalChrome();
        initTabs();
        initForm();
        initTriggerDelegation();
        publishInterface();
    });
})();