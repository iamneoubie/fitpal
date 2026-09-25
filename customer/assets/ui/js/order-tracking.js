/**
 * FitPal Customer Order Tracking JavaScript
 * Version 2.0 — Chat gating + delta polling.
 *
 * Handles:
 *   - Chat modal open / close with the correct tab preselected
 *     (each Message button on the page carries data-open-tab).
 *   - Tab switching between Restaurant and Rider.
 *   - Initial full message load, then a delta poll that fetches
 *     only new messages.
 *   - Send a message; append it optimistically; advance the delta
 *     cursor so the next poll does not duplicate it.
 *   - Mark incoming messages as read when a channel is opened.
 *   - Suspend polling while the tab is hidden or while the user is
 *     typing, and resume on visibility return / empty input.
 *
 * Delta polling — why and how
 * ---------------------------
 * The earlier version polled every 8 seconds and redrew the whole
 * message list on every tick. That was wasteful even for idle
 * conversations (a full SELECT with the recipient joins each tick)
 * and produced a visible scroll reset on mobile.
 *
 * This version keeps a per-channel cursor, `lastMessageId`. The
 * initial load fetches the full conversation and the server returns
 * the highest message_id it saw (`max_id`). Every subsequent poll
 * sends `since_id=lastMessageId`; the server returns an empty array
 * when nothing new arrived and the client does nothing — no DOM
 * writes, no scroll, no re-render. When something did arrive, only
 * the new rows are appended.
 *
 * The cursor is per-channel. Switching tabs resets the message list
 * but not the cursor: the cursor for each channel is kept in a small
 * map so a switch back to a channel already loaded can still delta
 * correctly.
 *
 * Poll lifecycle
 * --------------
 *   - Polling starts on modal open.
 *   - Polling stops on modal close.
 *   - Polling pauses while document.hidden is true and resumes with
 *     one immediate fetch when the tab returns to foreground.
 *   - Polling pauses while the user is actively typing (input has
 *     content) and resumes on submit or when the input becomes empty.
 *   - Interval: 5 seconds.
 *
 * @package FitPal
 * @version 2.0
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // CONFIG / DOM
        // ============================================
        var page = document.getElementById('trackingPage');
        if (!page) return;

        var CSRF_TOKEN  = page.dataset.csrfToken || '';
        var ORDER_ID    = parseInt(page.dataset.orderId, 10) || 0;
        var DEFAULT_TAB = page.dataset.defaultChatTab || 'restaurant_account';
        var CAN_MSG_KITCHEN = page.dataset.canMessageKitchen === '1';
        var CAN_MSG_RIDER   = page.dataset.canMessageRider === '1';

        var modal          = document.getElementById('customerChatModal');
        var chatCloseBtn   = document.getElementById('customerChatClose');
        var tabs           = document.querySelectorAll('.customer-chat-tab');
        var body           = document.getElementById('customerChatMessages');
        var form           = document.getElementById('customerChatForm');
        var recipientInput = document.getElementById('customerChatRecipient');
        var input          = document.getElementById('customerChatInput');

        var riderOpenBtn      = document.getElementById('chatOpenBtn');
        var restaurantOpenBtn = document.getElementById('chatOpenBtnRestaurant');

        if (!modal || !body || !form || !input) return;

        // ============================================
        // STATE
        // ============================================
        var activeChannel = DEFAULT_TAB;

        // Highest message_id the client already holds, per channel.
        // 0 means "nothing loaded yet, do a full fetch on first poll".
        var channelCursor = {
            restaurant_account: 0,
            delivery_rider:     0,
        };

        // Guards against re-entrant fetches for the same channel.
        var fetching = {
            restaurant_account: false,
            delivery_rider:     false,
        };

        // Set of message_id values already rendered. Used to dedupe
        // anything that races between an optimistic append and the
        // next delta fetch.
        var renderedIds = {
            restaurant_account: Object.create(null),
            delivery_rider:     Object.create(null),
        };

        var pollTimer = null;
        var POLL_INTERVAL_MS = 5000;

        // ============================================
        // HELPERS
        // ============================================
        function findTabButton(channel) {
            for (var i = 0; i < tabs.length; i++) {
                if (tabs[i].dataset.recipient === channel) return tabs[i];
            }
            return null;
        }

        function tabIsAvailable(channel) {
            if (channel === 'restaurant_account') return CAN_MSG_KITCHEN;
            if (channel === 'delivery_rider')     return CAN_MSG_RIDER;
            return false;
        }

        function setActiveTabButton(channel) {
            tabs.forEach(function (tab) {
                var isActive = tab.dataset.recipient === channel;
                tab.classList.toggle('active', isActive);
                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
        }

        function scrollToBottom() {
            // rAF so the browser has painted the appended node first.
            requestAnimationFrame(function () {
                body.scrollTop = body.scrollHeight;
            });
        }

        function escapeHtml(text) {
            var d = document.createElement('div');
            d.textContent = String(text == null ? '' : text);
            return d.innerHTML;
        }

        function renderEmpty(text) {
            body.innerHTML = '<div class="customer-chat-empty"><span>' +
                escapeHtml(text) + '</span></div>';
        }

        function renderLoading() {
            body.innerHTML = '<div class="customer-chat-loading"><span>Loading messages…</span></div>';
        }

        // ============================================
        // MESSAGE RENDERING
        // ============================================

        /**
         * Append a single message node to the chat body.
         *
         * Skips silently if the message_id has already been rendered
         * for the current channel. The caller is responsible for
         * removing any loading/empty placeholder before the first
         * append.
         *
         * @param {string} channel
         * @param {Object} msg  { message_id, direction, sender, content, time }
         * @param {boolean} skipScroll
         * @returns {boolean} True if a node was actually added.
         */
        function appendMessage(channel, msg, skipScroll) {
            if (!msg) return false;

            var mid = parseInt(msg.message_id, 10) || 0;
            if (mid > 0 && renderedIds[channel][mid]) {
                return false;
            }

            var wrapper = document.createElement('div');
            wrapper.className = 'customer-chat-message customer-chat-message-' +
                (msg.direction === 'sent' ? 'sent' : 'received');
            if (mid > 0) {
                wrapper.setAttribute('data-message-id', String(mid));
                renderedIds[channel][mid] = true;
            }

            var sender = document.createElement('span');
            sender.className = 'customer-chat-message-sender';
            sender.textContent = msg.sender || '';

            var content = document.createElement('span');
            content.textContent = msg.content || '';

            var time = document.createElement('span');
            time.className = 'customer-chat-message-time';
            time.textContent = msg.time || '';

            wrapper.appendChild(sender);
            wrapper.appendChild(content);
            wrapper.appendChild(time);
            body.appendChild(wrapper);

            if (!skipScroll) scrollToBottom();
            return true;
        }

        /**
         * Render the response of a full load. Clears the body first.
         *
         * @param {string} channel
         * @param {Array}  messages
         * @param {number} maxId
         */
        function renderFull(channel, messages, maxId) {
            body.innerHTML = '';

            if (!messages.length) {
                renderEmpty('No messages yet. Say hello!');
            } else {
                messages.forEach(function (msg) {
                    appendMessage(channel, msg, true);
                });
                scrollToBottom();
            }

            if (maxId > 0) {
                channelCursor[channel] = maxId;
            }
        }

        // ============================================
        // SERVER CALLS
        // ============================================

        /**
         * Fetch messages for a channel.
         *
         * When sinceId > 0, the server returns only messages newer
         * than it. When sinceId is 0, the server returns the full
         * conversation. Both responses carry max_id so the cursor
         * advances on the client.
         *
         * @param {string} channel
         * @param {number} sinceId
         * @returns {Promise<{status:string, messages:Array, max_id:number, message?:string}>}
         */
        function fetchMessages(channel, sinceId) {
            var formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('action', 'get');
            formData.append('order_id', String(ORDER_ID));
            formData.append('channel', channel);
            if (sinceId > 0) {
                formData.append('since_id', String(sinceId));
            }

            return fetch('../backend/handlers/message-handler.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(function (res) { return res.json(); })
            .catch(function () {
                return { status: 'error', message: 'Network error.' };
            });
        }

        /**
         * Load a channel's messages into the body.
         *
         * If the channel has already been loaded (cursor > 0) this
         * is a delta poll — new rows are appended, no placeholder
         * churn, no scroll reset when nothing new arrived.
         *
         * If the channel has not been loaded yet (cursor === 0) this
         * is the initial full fetch.
         *
         * @param {string}  channel
         * @param {Object}  [opts]
         * @param {boolean} [opts.showLoading=false] Show the loading
         *   placeholder (used when opening the modal).
         */
        function loadMessages(channel, opts) {
            opts = opts || {};
            if (fetching[channel]) return;
            fetching[channel] = true;

            var isFirstLoad = channelCursor[channel] === 0;

            if (isFirstLoad && opts.showLoading) {
                renderLoading();
            }

            fetchMessages(channel, channelCursor[channel])
                .then(function (data) {
                    if (!data || data.status !== 'success') {
                        if (isFirstLoad) {
                            renderEmpty((data && data.message) || 'Could not load messages.');
                        }
                        return;
                    }

                    var msgs = data.messages || [];
                    var maxId = parseInt(data.max_id, 10) || channelCursor[channel];

                    if (isFirstLoad) {
                        renderFull(channel, msgs, maxId);
                    } else if (msgs.length > 0) {
                        msgs.forEach(function (msg) {
                            appendMessage(channel, msg, true);
                        });
                        scrollToBottom();
                        if (maxId > channelCursor[channel]) {
                            channelCursor[channel] = maxId;
                        }
                    } else if (maxId > channelCursor[channel]) {
                        // No new rows but the server reported a higher
                        // max_id (e.g. rows added by another sender
                        // this client can't see). Keep the cursor in
                        // sync anyway.
                        channelCursor[channel] = maxId;
                    }

                    // Only mark-read on the currently visible channel.
                    if (channel === activeChannel) {
                        markRead(channel);
                    }
                })
                .finally(function () {
                    fetching[channel] = false;
                });
        }

        /**
         * Mark incoming messages as read for the given channel.
         * Fire-and-forget; failures are silent because read state is
         * cosmetic and the next open will retry.
         */
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

        // ============================================
        // MODAL OPEN / CLOSE
        // ============================================
        function openModal(channel) {
            if (!tabIsAvailable(channel)) {
                // Fall back to the other channel if the requested one
                // is not available for this order.
                channel = tabIsAvailable('restaurant_account')
                    ? 'restaurant_account'
                    : 'delivery_rider';
            }

            activeChannel = channel;
            recipientInput.value = channel;
            setActiveTabButton(channel);

            document.body.style.overflow = 'hidden';
            modal.style.display = 'flex';
            void modal.offsetWidth;
            modal.classList.add('active');

            loadMessages(channel, { showLoading: true });
            startPolling();

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

            stopPolling();
        }

        if (riderOpenBtn) {
            riderOpenBtn.addEventListener('click', function (e) {
                e.preventDefault();
                openModal(this.dataset.openTab || 'delivery_rider');
            });
        }

        if (restaurantOpenBtn) {
            restaurantOpenBtn.addEventListener('click', function (e) {
                e.preventDefault();
                openModal(this.dataset.openTab || 'restaurant_account');
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
                if (!tabIsAvailable(channel)) return;

                activeChannel = channel;
                recipientInput.value = channel;
                setActiveTabButton(channel);

                // Reset the visible body so the user does not see the
                // previous channel's messages while the first delta
                // for this channel is in flight.
                if (channelCursor[channel] === 0) {
                    renderLoading();
                } else {
                    // Channel has history on the client. Do a full
                    // repaint from the server (cheap for the current
                    // size) so the visible list matches this channel.
                    channelCursor[channel] = 0;
                    renderedIds[channel] = Object.create(null);
                    renderLoading();
                }

                loadMessages(channel, { showLoading: false });
            });
        });

        // ============================================
        // SEND
        // ============================================
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var content = input.value.trim();
            if (content === '') return;

            // Guard: the server will refuse a send to a channel that
            // is not yet open. Short-circuit here so the user gets
            // immediate feedback instead of a failed round trip.
            if (!tabIsAvailable(activeChannel)) {
                return;
            }

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

                        // Strip the placeholder if this was the first
                        // message in the conversation.
                        var placeholder = body.querySelector('.customer-chat-empty');
                        if (placeholder) placeholder.remove();

                        if (data.message_data) {
                            appendMessage(activeChannel, data.message_data, false);
                        }

                        var newMax = parseInt(data.max_id, 10) || 0;
                        if (newMax > channelCursor[activeChannel]) {
                            channelCursor[activeChannel] = newMax;
                        }
                    } else {
                        showInlineError((data && data.message) || 'Could not send message.');
                    }
                })
                .catch(function () {
                    showInlineError('Network error. Please try again.');
                })
                .finally(function () {
                    if (submitBtn) submitBtn.disabled = false;
                    input.focus();
                });
        });

        /**
         * Show a transient inline error above the input row. Does not
         * use window.alert, matching the rest of the customer role.
         */
        function showInlineError(message) {
            var toast = document.getElementById('trackingToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'trackingToast';
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

            toast.style.background = '#fee2e2';
            toast.style.color      = '#991b1b';
            toast.textContent      = message;

            void toast.offsetWidth;
            toast.style.transform = 'translateX(0)';

            clearTimeout(toast._timer);
            toast._timer = setTimeout(function () {
                toast.style.transform = 'translateX(120%)';
            }, 2800);
        }

        // ============================================
        // POLLING
        // ============================================
        function startPolling() {
            stopPolling();
            pollTimer = setInterval(function () {
                if (!modal.classList.contains('active')) return;
                if (document.hidden) return;
                if (input.value.trim() !== '') return;   // user is typing
                if (fetching[activeChannel]) return;     // a poll is still in flight

                loadMessages(activeChannel, { showLoading: false });
            }, POLL_INTERVAL_MS);
        }

        function stopPolling() {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        }

        // Refresh once when the tab returns to foreground, then
        // resume the interval. Skipping this would leave the modal
        // showing stale messages until the next 5-second tick after
        // the user returns.
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' &&
                modal.classList.contains('active')) {
                loadMessages(activeChannel, { showLoading: false });
            }
        });

        // ============================================
        // TYPING PAUSE / RESUME
        // ============================================
        input.addEventListener('focus', function () {
            // Nothing to do on focus — the interval checks the value
            // each tick, so a user who is simply focused but not
            // typing still gets polls.
        });

        input.addEventListener('input', function () {
            // Pause polls the moment there is content in the box.
            // The interval itself checks `input.value.trim() !== ''`
            // before every tick, so no timer manipulation is needed.
        });

        input.addEventListener('blur', function () {
            // On blur with an empty box, resume normal polling on the
            // next tick. If the user clears the box without blurring,
            // the next tick also resumes because the interval reads
            // the input value directly.
        });

    });
})();