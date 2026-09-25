/**
 * FitPal Rider Chat Modal
 *
 * The real chat implementation for the rider role. Owns the modal
 * markup that rider/includes/rider-chat-modal.php renders once per
 * authenticated page, and owns the delegated listener that opens it.
 *
 * Replaces the previous revision
 * ------------------------------
 * The previous revision of this file was a byte-for-byte copy of
 * rider/assets/ui/js/assignment-panel.js. header.php loaded both
 * files on every authenticated rider page, so both scripts called
 * init() on DOMContentLoaded, both bound a delegated click listener
 * to #assignmentList, and both bound the three notification-modal
 * buttons. The two copies fought over their rowsById maps: whichever
 * copy rendered a row was not always the copy whose closure owned
 * the click handler, so removeRow(orderId) looked up an id that did
 * not exist in that closure's map and silently did nothing.
 *
 * Nothing in this file touches the assignment panel, the assignment
 * list, or the notification modal. The panel script is the sole
 * owner of those.
 *
 * Trigger contract
 * ----------------
 * Any element carrying [data-rider-chat-open] opens the chat modal.
 * The element also carries:
 *
 *   data-rider-chat-order-id   the order id
 *   data-rider-chat-recipient  "customer" | "restaurant_account"
 *   data-rider-chat-subtitle   display string for the modal header
 *
 * The listener is installed on document, not on the panel list, so
 * it works for triggers rendered by any script and at any time —
 * including the Message button on rider/pages/deliveries.php, which
 * uses the same attributes.
 *
 * Tabs vs trigger recipient
 * -------------------------
 * A trigger carries a recipient that reflects the order's current
 * status (rider_pending → restaurant_account, delivering →
 * customer). When the modal opens, that recipient becomes the
 * initially-active tab. The rider can still switch tabs inside the
 * modal; the trigger's recipient only sets the starting point.
 *
 * Delta poll
 * ----------
 * The client keeps a per-order cursor at lastMaxId[orderId]. On
 * every poll it sends since_id = lastMaxId[orderId] so the server
 * returns only new rows. On the first open for an order the cursor
 * is 0, so the full history is fetched.
 *
 * Scroll lock
 * -----------
 * openChat() sets document.body.style.overflow = 'hidden' directly.
 * No padding compensation, because rider/assets/css/header.css
 * reserves a permanent scrollbar gutter on <html>. The layout
 * viewport is the same width whether the body scrollbar is visible
 * or not, so hiding the scrollbar does not shift the page and does
 * not leave an unpainted strip behind the modal overlay.
 *
 * @package FitPal
 * @version 1.0 — First real implementation. Replaces the copy of
 *                assignment-panel.js that was living under this
 *                filename.
 */

(function () {
    'use strict';

    // ============================================================
    // STATE
    // ============================================================
    var modal, modalOverlay, closeBtn;
    var tabsEl, tabButtons, bodyEl, formEl;
    var orderIdInput, recipientInput, messageInput, sendBtn;
    var subtitleEl;

    var endpoint   = '';
    var csrfToken  = '';

    var initialized = false;
    var isOpen      = false;
    var orderId     = 0;
    var recipient   = 'customer';

    // Per-order delta cursor. { [orderId]: maxMessageId }
    var lastMaxId = Object.create(null);

    // Poll timer while the modal is open.
    var pollTimer = null;
    var POLL_MS = 4000;

    // Guard against overlapping fetches.
    var isFetching = false;
    var isSending  = false;

    // ============================================================
    // INIT
    // ============================================================
    function init() {
        if (initialized) return;

        modal         = document.getElementById('riderChatModal');
        if (!modal) return;

        modalOverlay  = modal.querySelector('.modal-overlay');
        closeBtn      = document.getElementById('riderChatClose');
        tabsEl        = modal.querySelector('.rider-chat-tabs');
        bodyEl        = document.getElementById('riderChatMessages');
        formEl        = document.getElementById('riderChatForm');
        orderIdInput  = document.getElementById('riderChatOrderId');
        recipientInput = document.getElementById('riderChatRecipient');
        messageInput  = document.getElementById('riderChatInput');
        sendBtn       = formEl ? formEl.querySelector('.rider-chat-send') : null;
        subtitleEl    = document.getElementById('riderChatSubtitle');

        tabButtons = modal.querySelectorAll('.rider-chat-tab');

        // Endpoint. The modal markup does not carry it, so build it
        // relative to this script. The script is loaded from
        // rider/assets/ui/js/, and the handler lives at
        // rider/backend/handlers/message-handler.php — one directory
        // up from the assets tree.
        endpoint = '../../rider/backend/handlers/message-handler.php';

        csrfToken = window.RIDER_CSRF_TOKEN || '';

        // ---- Delegated open listener (document scope) ----
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('[data-rider-chat-open]');
            if (!trigger) return;

            e.preventDefault();
            e.stopPropagation();

            var oid = parseInt(trigger.getAttribute('data-rider-chat-order-id'), 10) || 0;
            if (!oid) return;

            openChat({
                orderId:   oid,
                recipient: trigger.getAttribute('data-rider-chat-recipient') || 'customer',
                subtitle:  trigger.getAttribute('data-rider-chat-subtitle') || ''
            });
        });

        // ---- Close controls ----
        if (closeBtn) closeBtn.addEventListener('click', closeChat);
        if (modalOverlay) modalOverlay.addEventListener('click', closeChat);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) closeChat();
        });

        // ---- Tab switching ----
        tabButtons.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var newRecipient = tab.getAttribute('data-recipient') || 'customer';
                if (newRecipient === recipient) return;

                recipient = newRecipient;
                if (recipientInput) recipientInput.value = recipient;

                tabButtons.forEach(function (t) {
                    var isActive = t === tab;
                    t.classList.toggle('active', isActive);
                    t.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });

                // Reset the visible thread and fetch the new one.
                // Do NOT reset lastMaxId[orderId] — the cursor is
                // per (order, recipient) server-side, but the client
                // only tracks one cursor per order. On a tab switch
                // we do a full fetch by passing since_id = 0 and
                // then re-seed the cursor from the response's max_id.
                renderLoading();
                fetchMessages(0);
            });
        });

        // ---- Send ----
        if (formEl) {
            formEl.addEventListener('submit', function (e) {
                e.preventDefault();
                sendMessage();
            });
        }

        initialized = true;
    }

    // ============================================================
    // OPEN / CLOSE
    // ============================================================
    function openChat(opts) {
        if (!modal) return;

        orderId   = opts.orderId || 0;
        recipient = opts.recipient === 'restaurant_account' ? 'restaurant_account' : 'customer';

        if (!orderId) return;

        // Header subtitle
        if (subtitleEl) {
            subtitleEl.textContent = opts.subtitle || ('Order #' + orderId);
        }

        // Hidden fields
        if (orderIdInput)   orderIdInput.value   = String(orderId);
        if (recipientInput) recipientInput.value = recipient;

        // Tabs — reflect the recipient that opened the modal
        tabButtons.forEach(function (t) {
            var isActive = (t.getAttribute('data-recipient') || '') === recipient;
            t.classList.toggle('active', isActive);
            t.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        // Body
        renderLoading();

        // Lock body scroll
        document.body.style.overflow = 'hidden';

        // Show
        modal.style.display = 'flex';
        void modal.offsetWidth;
        modal.classList.add('active');
        isOpen = true;

        // Fetch the thread
        fetchMessages(0);

        // Start poll
        startPoll();

        // Focus the input
        if (messageInput) {
            setTimeout(function () { messageInput.focus(); }, 120);
        }
    }

    function closeChat() {
        if (!modal) return;

        stopPoll();

        modal.classList.remove('active');
        setTimeout(function () {
            if (!modal.classList.contains('active')) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        }, 200);

        isOpen = false;
        // Do not clear orderId/recipient here — a re-open may fire
        // before the timeout completes, and clearing would break it.
    }

    // ============================================================
    // POLL
    // ============================================================
    function startPoll() {
        stopPoll();
        pollTimer = setInterval(function () {
            if (document.hidden) return;
            if (!isOpen) return;
            var cursor = lastMaxId[orderId] || 0;
            fetchMessages(cursor);
        }, POLL_MS);
    }

    function stopPoll() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    // ============================================================
    // FETCH
    // ============================================================
    function fetchMessages(sinceId) {
        if (!orderId) return;
        if (isFetching) return;

        isFetching = true;

        var fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('action', 'get_messages');
        fd.append('order_id', String(orderId));
        fd.append('with_type', recipient);
        fd.append('since_id', String(sinceId || 0));

        fetch(endpoint, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                isFetching = false;

                if (!data || data.status !== 'success') {
                    if (sinceId === 0) {
                        renderEmpty(
                            (data && data.message) ||
                            'Could not load messages.'
                        );
                    }
                    return;
                }

                var messages = Array.isArray(data.messages) ? data.messages : [];

                // Seed / advance the cursor
                var serverMax = parseInt(data.max_id, 10) || 0;
                if (serverMax > (lastMaxId[orderId] || 0)) {
                    lastMaxId[orderId] = serverMax;
                }

                if (sinceId === 0) {
                    // Full replace — tab switch or first open
                    bodyEl.innerHTML = '';
                    if (messages.length === 0) {
                        renderEmpty('No messages yet. Start the conversation.');
                        return;
                    }
                }

                var wasNearBottom = isNearBottom();
                appendMessages(messages);
                if (wasNearBottom || sinceId === 0) {
                    scrollToBottom();
                }
            })
            .catch(function () {
                isFetching = false;
                if (sinceId === 0) {
                    renderEmpty('Network error. Please try again.');
                }
            });
    }

    function appendMessages(messages) {
        if (!bodyEl) return;
        if (!messages.length) return;

        // Remove any empty / loading placeholder before appending.
        var placeholder = bodyEl.querySelector('.rider-chat-empty, .rider-chat-loading');
        if (placeholder) placeholder.remove();

        var frag = document.createDocumentFragment();

        messages.forEach(function (msg) {
            var bubble = document.createElement('div');

            var direction = msg.direction === 'sent' ? 'sent' : 'received';
            bubble.className = 'rider-chat-message rider-chat-message-' + direction;

            if (direction === 'received' && msg.sender) {
                var senderEl = document.createElement('span');
                senderEl.className = 'rider-chat-message-sender';
                senderEl.textContent = msg.sender;
                bubble.appendChild(senderEl);
            }

            var textEl = document.createElement('span');
            textEl.className = 'rider-chat-message-text';
            textEl.textContent = msg.content || '';
            bubble.appendChild(textEl);

            if (msg.time) {
                var timeEl = document.createElement('span');
                timeEl.className = 'rider-chat-message-time';
                timeEl.textContent = msg.time;
                bubble.appendChild(timeEl);
            }

            frag.appendChild(bubble);
        });

        bodyEl.appendChild(frag);
    }

    // ============================================================
    // SEND
    // ============================================================
    function sendMessage() {
        if (isSending) return;
        if (!orderId) return;
        if (!messageInput) return;

        var content = (messageInput.value || '').trim();
        if (!content) return;
        if (content.length > 500) return;

        isSending = true;
        if (sendBtn) sendBtn.disabled = true;

        var fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('action', 'send_message');
        fd.append('order_id', String(orderId));
        fd.append('recipient_type', recipient);
        fd.append('content', content);

        fetch(endpoint, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                isSending = false;
                if (sendBtn) sendBtn.disabled = false;

                if (!data || data.status !== 'success') {
                    showChatToast(
                        (data && data.message) || 'Could not send the message.',
                        'error'
                    );
                    return;
                }

                // Clear the input on success
                messageInput.value = '';

                // Advance the cursor so the next poll does not fetch
                // this message again.
                var serverMax = parseInt(data.max_id, 10) || 0;
                if (serverMax > (lastMaxId[orderId] || 0)) {
                    lastMaxId[orderId] = serverMax;
                }

                // Render locally for immediate feedback
                if (data.message_data) {
                    appendMessages([data.message_data]);
                    scrollToBottom();
                } else {
                    // Fallback: pull the latest batch
                    fetchMessages(lastMaxId[orderId] || 0);
                }
            })
            .catch(function () {
                isSending = false;
                if (sendBtn) sendBtn.disabled = false;
                showChatToast('Network error. Please try again.', 'error');
            });
    }

    // ============================================================
    // RENDER HELPERS
    // ============================================================
    function renderLoading() {
        if (!bodyEl) return;
        bodyEl.innerHTML =
            '<div class="rider-chat-loading"><span>Loading messages…</span></div>';
    }

    function renderEmpty(text) {
        if (!bodyEl) return;
        var el = document.createElement('div');
        el.className = 'rider-chat-empty';
        var span = document.createElement('span');
        span.textContent = text;
        el.appendChild(span);
        bodyEl.appendChild(el);
    }

    function isNearBottom() {
        if (!bodyEl) return true;
        var threshold = 80;
        return (bodyEl.scrollHeight - bodyEl.scrollTop - bodyEl.clientHeight) < threshold;
    }

    function scrollToBottom() {
        if (!bodyEl) return;
        bodyEl.scrollTop = bodyEl.scrollHeight;
    }

    // ============================================================
    // TOAST
    // ============================================================
    function showChatToast(message, type) {
        var toast = document.getElementById('riderChatToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'riderChatToast';
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