/**
 * FitPal Rider Deliveries JavaScript
 *
 * Handles:
 *   - Delivery status updates (accept / picked up / delivered)
 *   - Chat modal open/close
 *   - Chat tab switching
 *   - Message loading and sending
 *
 * @package FitPal
 * @version 2.0 — Adds confirm copy for accept_order. All three
 *                actions (accept_order, picked_up, delivered) post
 *                to rider-handler.php through the same click handler.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var CFG = window.FITPAL_RIDER_DELIVERIES || {};
        var CSRF_TOKEN = CFG.csrfToken || '';

        // ============================================
        // DELIVERY STATUS UPDATES
        // ============================================
        var statusButtons = document.querySelectorAll('.delivery-status-btn');

        statusButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var orderId = parseInt(this.dataset.orderId, 10) || 0;
                var action = this.dataset.action || '';

                if (orderId <= 0 || action === '') return;

                var confirmMessage;
                if (action === 'accept_order') {
                    confirmMessage = 'Accept this delivery? You will be responsible for picking it up and delivering it to the customer.';
                } else if (action === 'delivered') {
                    confirmMessage = 'Confirm that this order has been delivered to the customer?';
                } else {
                    confirmMessage = 'Confirm that you have picked up this order from the restaurant?';
                }

                if (!window.confirm(confirmMessage)) return;

                this.disabled = true;
                var originalText = this.textContent;
                this.textContent = 'Updating…';

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
                            setTimeout(function () { window.location.reload(); }, 800);
                        } else {
                            showToast((data && data.message) || 'Could not update delivery', 'error');
                            btn.disabled = false;
                            btn.textContent = originalText;
                        }
                    })
                    .catch(function () {
                        showToast('Network error. Please try again.', 'error');
                        btn.disabled = false;
                        btn.textContent = originalText;
                    });
            });
        });

        // ============================================
        // CHAT MODAL
        // ============================================
        var chatModal = document.getElementById('riderChatModal');
        var chatClose = document.getElementById('riderChatClose');
        var chatSubtitle = document.getElementById('riderChatSubtitle');
        var chatMessages = document.getElementById('riderChatMessages');
        var chatForm = document.getElementById('riderChatForm');
        var chatInput = document.getElementById('riderChatInput');
        var chatOrderIdInput = document.getElementById('riderChatOrderId');
        var chatRecipientInput = document.getElementById('riderChatRecipient');
        var chatTabs = document.querySelectorAll('.rider-chat-tab');

        var currentOrderId = 0;
        var currentRecipient = 'customer';
        var pollTimer = null;
        var knownMessageIds = {};

        function openChatModal(orderId, customerName, restaurantName) {
            if (!chatModal) return;

            currentOrderId = orderId;
            currentRecipient = 'customer';

            if (chatOrderIdInput) chatOrderIdInput.value = String(orderId);
            if (chatRecipientInput) chatRecipientInput.value = 'customer';
            if (chatSubtitle) {
                chatSubtitle.textContent = 'Order #' + orderId + ' — ' +
                    customerName + ' / ' + restaurantName;
            }

            chatTabs.forEach(function (tab) {
                tab.classList.toggle('active', tab.dataset.recipient === 'customer');
            });

            knownMessageIds = {};

            document.body.style.overflow = 'hidden';
            chatModal.style.display = 'flex';
            void chatModal.offsetWidth;
            chatModal.classList.add('active');

            loadMessages();
            startPolling();

            setTimeout(function () { if (chatInput) chatInput.focus(); }, 100);
        }

        function closeChatModal() {
            if (!chatModal) return;

            chatModal.classList.remove('active');
            setTimeout(function () {
                if (!chatModal.classList.contains('active')) {
                    chatModal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }, 200);

            stopPolling();
            currentOrderId = 0;
        }

        document.querySelectorAll('.delivery-chat-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var orderId = parseInt(this.dataset.orderId, 10) || 0;
                var customerName = this.dataset.customerName || 'Customer';
                var restaurantName = this.dataset.restaurantName || 'Restaurant';
                if (orderId > 0) openChatModal(orderId, customerName, restaurantName);
            });
        });

        if (chatClose) chatClose.addEventListener('click', closeChatModal);
        if (chatModal) {
            chatModal.addEventListener('click', function (e) {
                if (e.target === chatModal || e.target.classList.contains('modal-overlay')) {
                    closeChatModal();
                }
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && chatModal && chatModal.classList.contains('active')) {
                closeChatModal();
            }
        });

        // ============================================
        // CHAT TABS
        // ============================================
        chatTabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var recipient = this.dataset.recipient || 'customer';
                if (recipient === currentRecipient) return;

                currentRecipient = recipient;
                if (chatRecipientInput) chatRecipientInput.value = recipient;

                chatTabs.forEach(function (t) {
                    t.classList.toggle('active', t === tab);
                });

                knownMessageIds = {};
                loadMessages();
            });
        });

        // ============================================
        // CHAT FORM SUBMIT
        // ============================================
        if (chatForm) {
            chatForm.addEventListener('submit', function (e) {
                e.preventDefault();

                var message = (chatInput.value || '').trim();
                if (message === '' || currentOrderId <= 0) return;

                chatInput.value = '';
                chatInput.disabled = true;

                var body = new URLSearchParams();
                body.append('csrf_token', CSRF_TOKEN);
                body.append('action', 'send_message');
                body.append('order_id', String(currentOrderId));
                body.append('recipient_type', currentRecipient);
                body.append('content', message);

                fetch('../backend/handlers/message-handler.php', {
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
                        chatInput.disabled = false;
                        if (data && data.status === 'success') {
                            if (data.message_data) {
                                appendMessage(data.message_data);
                            } else {
                                loadMessages();
                            }
                            chatInput.focus();
                        } else {
                            showToast((data && data.message) || 'Could not send message', 'error');
                            chatInput.value = message;
                        }
                    })
                    .catch(function () {
                        chatInput.disabled = false;
                        showToast('Network error. Please try again.', 'error');
                        chatInput.value = message;
                    });
            });
        }

        // ============================================
        // MESSAGE LOADING
        // ============================================
        function loadMessages() {
            if (currentOrderId <= 0 || !chatMessages) return;

            var body = new URLSearchParams();
            body.append('csrf_token', CSRF_TOKEN);
            body.append('action', 'get_messages');
            body.append('order_id', String(currentOrderId));
            body.append('with_type', currentRecipient);

            fetch('../backend/handlers/message-handler.php', {
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
                        renderMessages(data.messages || []);
                    } else {
                        renderEmpty('Could not load messages.');
                    }
                })
                .catch(function () {
                    renderEmpty('Network error.');
                });
        }

        function renderMessages(messages) {
            if (!chatMessages) return;

            if (messages.length === 0) {
                renderEmpty('No messages yet. Start the conversation.');
                return;
            }

            chatMessages.innerHTML = '';
            messages.forEach(function (msg) {
                appendMessage(msg, true);
            });

            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        function renderEmpty(text) {
            if (!chatMessages) return;
            chatMessages.innerHTML = '<div class="rider-chat-empty">' + escapeHtml(text) + '</div>';
        }

        function appendMessage(msg, skipScroll) {
            if (!chatMessages || !msg) return;

            var msgId = msg.message_id || (Date.now() + Math.random());
            if (knownMessageIds[msgId]) return;
            knownMessageIds[msgId] = true;

            var empty = chatMessages.querySelector('.rider-chat-empty');
            if (empty) empty.remove();

            var isSent = msg.is_sent === true ||
                (msg.sender_type === 'delivery_rider' && msg.is_own === true) ||
                msg.is_own === true;

            var div = document.createElement('div');
            div.className = 'rider-chat-message ' + (isSent ? 'rider-chat-message-sent' : 'rider-chat-message-received');

            var senderLabel = '';
            if (!isSent) {
                senderLabel = msg.sender_label || formatSenderType(msg.sender_type);
            }

            var timeStr = formatMessageTime(msg.created_at);

            var html = '';
            if (senderLabel) {
                html += '<span class="rider-chat-message-sender">' + escapeHtml(senderLabel) + '</span>';
            }
            html += '<span class="rider-chat-message-text">' + escapeHtml(msg.content || '') + '</span>';
            html += '<span class="rider-chat-message-time">' + escapeHtml(timeStr) + '</span>';

            div.innerHTML = html;
            chatMessages.appendChild(div);

            if (!skipScroll) {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
        }

        function formatSenderType(type) {
            return ({
                'customer': 'Customer',
                'restaurant_account': 'Kitchen',
                'delivery_rider': 'You',
                'administrator': 'Support',
                'system': 'System'
            })[type] || 'User';
        }

        function formatMessageTime(dateStr) {
            if (!dateStr) return '';
            var ts = new Date(dateStr.replace(' ', 'T'));
            if (isNaN(ts.getTime())) return dateStr;
            return ts.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        }

        // ============================================
        // POLLING
        // ============================================
        function startPolling() {
            stopPolling();
            pollTimer = setInterval(function () {
                if (currentOrderId > 0 && chatModal && chatModal.classList.contains('active')) {
                    loadMessages();
                }
            }, 8000);
        }

        function stopPolling() {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        }

        // ============================================
        // HELPERS
        // ============================================
        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = String(text == null ? '' : text);
            return div.innerHTML;
        }

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
                error: ['#fee2e2', '#991b1b'],
                info: ['#dbeafe', '#1e40af']
            };
            var colors = palette[type] || palette.info;
            toast.style.background = colors[0];
            toast.style.color = colors[1];
            toast.textContent = message;

            void toast.offsetWidth;
            toast.style.transform = 'translateX(0)';

            clearTimeout(toast._timer);
            toast._timer = setTimeout(function () {
                toast.style.transform = 'translateX(120%)';
            }, 2800);
        }
    });
})();