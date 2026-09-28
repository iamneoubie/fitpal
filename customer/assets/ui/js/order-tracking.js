/**
 * FitPal Customer Order Tracking JavaScript
 * Version 3.0 — Real-time status polling on top of chat delta polling.
 *
 * Two independent concerns live in this file:
 *
 *   1. LIVE ORDER STATUS
 *      Polls order-handler.php's `get_tracking_status` action every
 *      few seconds. The server returns the order's current status
 *      plus a revision hash derived from order_status, delivered_at,
 *      and delivery_rider_id. When the revision differs from the one
 *      the page was rendered with, the page reloads once and picks
 *      up the new server-rendered state.
 *
 *      Why reload rather than patch the DOM in place: the tracking
 *      page's DOM is coupled to the order status in ways a small
 *      patch would have to mirror — the timeline step classes, the
 *      rider card's Message button enablement, the chat tab
 *      availability, the alert banners. Reloading once on an actual
 *      status change is simpler, always correct, and cheap because
 *      the page itself is a single indexed read with one small join.
 *
 *      The polling loop:
 *        - Uses a 6-second interval.
 *        - Pauses while document.hidden.
 *        - Resumes with one immediate fetch on visibilitychange to
 *          visible.
 *        - Stops entirely after a reload is triggered.
 *
 *   2. CHAT DELTA POLLING
 *      Per-channel cursor, initial full load, delta fetch, send with
 *      optimistic append, mark-read on channel focus. Same contract
 *      as the previous revision; unchanged below.
 *
 * Cadence rationale:
 *   Status changes are infrequent (a handful per order) but the user
 *   is watching the page. 6 seconds is fast enough to feel live and
 *   slow enough that a ten-minute track session costs about a
 *   hundred shallow reads, not a thousand.
 *
 * @package FitPal
 * @version 3.0
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // CONFIG / DOM
        // ============================================
        var page = document.getElementById('trackingPage');
        if (!page) return;

        var CSRF_TOKEN    = page.dataset.csrfToken    || '';
        var ORDER_ID      = parseInt(page.dataset.orderId, 10) || 0;
        var HANDLER_URL   = page.dataset.handlerUrl   || '../backend/handlers/order-handler.php';
        var DEFAULT_TAB   = page.dataset.defaultChatTab || 'restaurant_account';
        var CAN_MSG_KITCHEN = page.dataset.canMessageKitchen === '1';
        var CAN_MSG_RIDER   = page.dataset.canMessageRider === '1';

        var currentRevision = page.dataset.revision || '';
        var currentStatus   = page.dataset.orderStatus || '';

        // ---- Chat DOM ----
        var modal          = document.getElementById('customerChatModal');
        var chatCloseBtn   = document.getElementById('customerChatClose');
        var tabs           = document.querySelectorAll('.customer-chat-tab');
        var body           = document.getElementById('customerChatMessages');
        var form           = document.getElementById('customerChatForm');
        var recipientInput = document.getElementById('customerChatRecipient');
        var input          = document.getElementById('customerChatInput');

        var riderOpenBtn      = document.getElementById('chatOpenBtn');
        var restaurantOpenBtn = document.getElementById('chatOpenBtnRestaurant');

        // ============================================
        // LIVE STATUS POLL
        // ============================================

        var STATUS_POLL_MS = 6000;
        var statusPollTimer = null;
        var statusFetchInFlight = false;
        var hasReloaded = false;

        function fetchTrackingStatus() {
            if (statusFetchInFlight || hasReloaded) return;
            statusFetchInFlight = true;

            var body = new URLSearchParams();
            body.append('csrf_token', CSRF_TOKEN);
            body.append('action', 'get_tracking_status');
            body.append('order_id', String(ORDER_ID));
            body.append('current_revision', currentRevision);

            fetch(HANDLER_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString(),
                credentials: 'same-origin',
                cache: 'no-store'
            })
            .then(function (res) {
                return res.json().catch(function () {
                    return { status: 'error' };
                });
            })
            .then(function (data) {
                if (!data || data.status !== 'success') return;

                // Server reports the order's current status and a
                // revision hash. If they match what the page was
                // rendered with, nothing visible changed and there is
                // nothing to do.
                if (data.revision && data.revision !== currentRevision) {
                    currentRevision = data.revision;
                    hasReloaded = true;
                    window.location.reload();
                    return;
                }

                // Revision unchanged but status string differs (can
                // happen if the revision formula changes in a future
                // release). Reload defensively so the page's visible
                // status never drifts from the server.
                if (data.order_status && data.order_status !== currentStatus) {
                    currentStatus = data.order_status;
                    hasReloaded = true;
                    window.location.reload();
                }
            })
            .catch(function () {
                // Silent. The next tick retries.
            })
            .finally(function () {
                statusFetchInFlight = false;
            });
        }

        function startStatusPolling() {
            if (statusPollTimer) return;
            statusPollTimer = setInterval(function () {
                if (document.hidden) return;
                fetchTrackingStatus();
            }, STATUS_POLL_MS);
        }

        function stopStatusPolling() {
            if (statusPollTimer) {
                clearInterval(statusPollTimer);
                statusPollTimer = null;
            }
        }

        startStatusPolling();

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState !== 'visible') return;
            fetchTrackingStatus();
        });

        window.addEventListener('pageshow', function (e) {
            if (!e.persisted) return;
            fetchTrackingStatus();
        });

        window.addEventListener('beforeunload', function () {
            stopStatusPolling();
        });

        // ============================================
        // CHAT MODAL
        // ============================================
        if (!modal || !body || !form || !input) return;

        var activeChannel = DEFAULT_TAB;

        var channelCursor = {
            restaurant_account: 0,
            delivery_rider:     0,
        };

        var fetching = {
            restaurant_account: false,
            delivery_rider:     false,
        };

        var renderedIds = {
            restaurant_account: Object.create(null),
            delivery_rider:     Object.create(null),
        };

        var pollTimer = null;
        var POLL_INTERVAL_MS = 5000;

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
                        channelCursor[channel] = maxId;
                    }

                    if (channel === activeChannel) {
                        markRead(channel);
                    }
                })
                .finally(function () {
                    fetching[channel] = false;
                });
        }

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

        function openModal(channel) {
            if (!tabIsAvailable(channel)) {
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

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var channel = this.dataset.recipient || 'restaurant_account';
                if (channel === activeChannel) return;
                if (!tabIsAvailable(channel)) return;

                activeChannel = channel;
                recipientInput.value = channel;
                setActiveTabButton(channel);

                if (channelCursor[channel] === 0) {
                    renderLoading();
                } else {
                    channelCursor[channel] = 0;
                    renderedIds[channel] = Object.create(null);
                    renderLoading();
                }

                loadMessages(channel, { showLoading: false });
            });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var content = input.value.trim();
            if (content === '') return;

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

        function startPolling() {
            stopPolling();
            pollTimer = setInterval(function () {
                if (!modal.classList.contains('active')) return;
                if (document.hidden) return;
                if (input.value.trim() !== '') return;
                if (fetching[activeChannel]) return;

                loadMessages(activeChannel, { showLoading: false });
            }, POLL_INTERVAL_MS);
        }

        function stopPolling() {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' &&
                modal.classList.contains('active')) {
                loadMessages(activeChannel, { showLoading: false });
            }
        });
    });
})();