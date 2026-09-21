/**
 * FitPal Customer Order Tracking JavaScript
 * Version 1.0
 *
 * Handles the chat modal: tab switching between Restaurant and Rider,
 * loading messages, sending a message, and marking incoming messages
 * as read when a channel is opened.
 *
 * @package FitPal
 * @version 1.0
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var page = document.getElementById('trackingPage');
        if (!page) return;

        var CSRF_TOKEN = page.dataset.csrfToken || '';
        var ORDER_ID   = parseInt(page.dataset.orderId, 10) || 0;

        var modal         = document.getElementById('customerChatModal');
        var chatOpenBtn   = document.getElementById('chatOpenBtn');
        var chatCloseBtn  = document.getElementById('customerChatClose');
        var tabs          = document.querySelectorAll('.customer-chat-tab');
        var body          = document.getElementById('customerChatMessages');
        var form          = document.getElementById('customerChatForm');
        var recipientInput= document.getElementById('customerChatRecipient');
        var input         = document.getElementById('customerChatInput');

        if (!modal || !body || !form || !input) return;

        var activeChannel = 'restaurant_account';

        // ============================================
        // MODAL OPEN / CLOSE
        // ============================================
        function openModal() {
            document.body.style.overflow = 'hidden';
            modal.style.display = 'flex';
            void modal.offsetWidth;
            modal.classList.add('active');
            loadMessages(activeChannel);
            setTimeout(function () { input.focus(); }, 120);
        }

        function closeModal() {
            modal.classList.remove('active');
            setTimeout(function () {
                if (!modal.classList.contains('active')) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }, 220);
        }

        if (chatOpenBtn) {
            chatOpenBtn.addEventListener('click', function (e) {
                e.preventDefault();
                openModal();
            });
        }

        if (chatCloseBtn) {
            chatCloseBtn.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        }

        if (modal) {
            var overlay = modal.querySelector('.modal-overlay');
            if (overlay) {
                overlay.addEventListener('click', closeModal);
            }
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeModal();
            }
        });

        // ============================================
        // TABS
        // ============================================
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var channel = this.dataset.recipient || 'restaurant_account';
                if (channel === activeChannel) return;

                tabs.forEach(function (t) { t.classList.remove('active'); });
                this.classList.add('active');

                activeChannel = channel;
                if (recipientInput) recipientInput.value = channel;

                loadMessages(channel);
            });
        });

        // ============================================
        // LOAD MESSAGES
        // ============================================
        function loadMessages(channel) {
            body.innerHTML = '<div class="customer-chat-loading"><span>Loading messages…</span></div>';

            var formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('action', 'get');
            formData.append('order_id', String(ORDER_ID));
            formData.append('channel', channel);

            fetch('../backend/handlers/message-handler.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.status === 'success') {
                    renderMessages(data.messages || []);
                    markRead(channel);
                } else {
                    renderEmpty((data && data.message) || 'Could not load messages.');
                }
            })
            .catch(function () {
                renderEmpty('Network error. Please try again.');
            });
        }

        function renderMessages(messages) {
            if (!messages.length) {
                renderEmpty('No messages yet. Say hello!');
                return;
            }

            body.innerHTML = '';

            messages.forEach(function (msg) {
                var wrapper = document.createElement('div');
                wrapper.className = 'customer-chat-message customer-chat-message-' + msg.direction;

                var sender = document.createElement('span');
                sender.className = 'customer-chat-message-sender';
                sender.textContent = msg.sender;

                var content = document.createElement('span');
                content.textContent = msg.content;

                var time = document.createElement('span');
                time.className = 'customer-chat-message-time';
                time.textContent = msg.time;

                wrapper.appendChild(sender);
                wrapper.appendChild(content);
                wrapper.appendChild(time);
                body.appendChild(wrapper);
            });

            body.scrollTop = body.scrollHeight;
        }

        function renderEmpty(text) {
            body.innerHTML = '<div class="customer-chat-empty"><span>' +
                escapeHtml(text) + '</span></div>';
        }

        function escapeHtml(text) {
            var d = document.createElement('div');
            d.textContent = String(text == null ? '' : text);
            return d.innerHTML;
        }

        // ============================================
        // SEND MESSAGE
        // ============================================
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var content = input.value.trim();
            if (content === '') return;

            var submitBtn = form.querySelector('.customer-chat-send');
            if (submitBtn) submitBtn.disabled = true;

            var formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('action', 'send');
            formData.append('order_id', String(ORDER_ID));
            formData.append('channel', activeChannel);
            formData.append('content', content);

            fetch('../backend/handlers/message-handler.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.status === 'success') {
                    input.value = '';
                    loadMessages(activeChannel);
                } else {
                    console.warn('[tracking] send failed:', data);
                }
            })
            .catch(function (err) {
                console.warn('[tracking] send failed:', err);
            })
            .finally(function () {
                if (submitBtn) submitBtn.disabled = false;
            });
        });

        // ============================================
        // MARK READ
        // ============================================
        function markRead(channel) {
            var formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('action', 'read');
            formData.append('order_id', String(ORDER_ID));
            formData.append('channel', channel);

            fetch('../backend/handlers/message-handler.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            }).catch(function () { /* silent */ });
        }

    });
})();