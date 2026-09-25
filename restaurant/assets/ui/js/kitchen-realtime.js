/**
 * FitPal Restaurant Kitchen Realtime
 *
 * Two responsibilities, both scoped to the kitchen page.
 *
 * 1. LIVE ORDER LIST
 *    Polls order-handler.php with a delta cursor (lastOrderId) and
 *    updates the DOM as orders change:
 *      - New orders are prepended to the list.
 *      - Changed statuses are reflected on the existing card (its
 *        data-order-status is updated, the badge and tab counts are
 *        redrawn, and the card slides under the correct tab).
 *      - Orders that leave the live set (delivered / cancelled /
 *        refunded) are removed from the live list. They will appear
 *        under the Recent tab on the next full page load.
 *
 *    Cadence:
 *      - 5s while the tab is visible and there are live orders.
 *      - 15s while visible and the board is empty.
 *      - Paused entirely while document.hidden.
 *      - One immediate poll on visibilitychange → visible.
 *
 *    The delta shape is defined server-side in order-handler.php's
 *    `poll` action. The client only needs `order_id`, `order_status`,
 *    and a rendered card payload. To keep the JS from having to
 *    re-render the whole card markup, the server sends the same
 *    order object that the initial page render would have produced
 *    — the JS only swaps the DOM node. That means the card HTML is
 *    assembled in PHP, once, and both the initial render and every
 *    poll share the same builder.
 *
 * 2. CHAT MODAL
 *    Open/close/tabs/send/read for #restaurantChatModal. Delta
 *    polling of messages by message_id, same contract as the
 *    customer and rider chat modals.
 *
 * @package FitPal
 * @version 1.0
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var page = document.getElementById('kitchenPage');
        if (!page) return;

        var SCOPE      = page.dataset.scope || 'branch';
        var CSRF_TOKEN = page.dataset.csrfToken || '';
        var HANDLER    = page.dataset.handlerUrl || '../backend/handlers/order-handler.php';
        var CHAT_URL   = page.dataset.chatUrl    || '../backend/handlers/chat-handler.php';
        var ASSET_BASE = page.dataset.assetBase  || '../../shared/';

        // The owner view has no interactive elements and no live
        // updates — the summary is a read-only cross-branch view.
        if (SCOPE !== 'branch') return;

        // ============================================================
        // CHAT MODAL STATE
        // ============================================================
        var chatModal          = document.getElementById('restaurantChatModal');
        var chatCloseBtn       = document.getElementById('restaurantChatClose');
        var chatSubtitleEl     = document.getElementById('restaurantChatSubtitle');
        var chatOrderIdInput   = document.getElementById('restaurantChatOrderId');
        var chatChannelInput   = document.getElementById('restaurantChatCounterparty');
        var chatBody           = document.getElementById('restaurantChatMessages');
        var chatForm           = document.getElementById('restaurantChatForm');
        var chatInput          = document.getElementById('restaurantChatInput');
        var chatTabs           = document.querySelectorAll('.restaurant-chat-tab');
        var customerBadgeEl    = document.getElementById('restaurantChatCustomerBadge');
        var riderBadgeEl       = document.getElementById('restaurantChatRiderBadge');

        var activeChannel = 'customer';
        var activeOrderId = 0;

        var channelCursor = {
            customer: 0,
            delivery_rider: 0
        };

        var renderedIds = {
            customer: Object.create(null),
            delivery_rider: Object.create(null)
        };

        var fetching = {
            customer: false,
            delivery_rider: false
        };

        var chatPollTimer = null;
        var CHAT_POLL_MS  = 5000;

        // ============================================================
        // ORDER LIST STATE
        // ============================================================
        var orderList      = document.getElementById('kitchenOrderList');
        var tabs           = document.querySelectorAll('.kitchen-tab');

        var lastOrderId    = parseInt(page.dataset.maxOrderId, 10) || 0;
        var liveCount      = parseInt(page.dataset.liveCount, 10) || 0;

        var orderPollTimer = null;
        var ORDER_POLL_MS  = 5000;
        var ORDER_IDLE_MS  = 15000;
        var lastOrderPollAt = 0;

        // ============================================================
        // HELPERS
        // ============================================================
        function escapeHtml(text) {
            var d = document.createElement('div');
            d.textContent = String(text == null ? '' : text);
            return d.innerHTML;
        }

        function getActiveFilter() {
            var active = document.querySelector('.kitchen-tab.active');
            return active ? (active.dataset.filter || 'pending') : 'pending';
        }

        function applyFilter(filter) {
            if (!orderList) return;

            var cards = orderList.querySelectorAll('.kitchen-order-card');
            var closedStatuses = ['delivered', 'cancelled', 'refunded'];
            var visible = 0;

            cards.forEach(function (card) {
                var status = card.dataset.orderStatus || '';
                var show;

                if (filter === 'recent') {
                    show = closedStatuses.indexOf(status) !== -1;
                } else {
                    show = (status === filter);
                }

                card.classList.toggle('is-hidden', !show);
                if (show) visible++;
            });

            // Empty state for the currently active tab.
            var emptyState = document.getElementById('kitchenEmptyFilterState');
            if (!emptyState && orderList) {
                emptyState = document.createElement('div');
                emptyState.id = 'kitchenEmptyFilterState';
                emptyState.className = 'kitchen-empty-state';
                emptyState.innerHTML =
                    '<p class="kitchen-empty-title">Nothing here</p>' +
                    '<p class="kitchen-empty-text">No orders currently match this tab.</p>';
                orderList.appendChild(emptyState);
            }
            if (emptyState) {
                emptyState.style.display = (visible === 0 && cards.length > 0) ? 'block' : 'none';
            }
        }

        // ============================================================
        // TAB COUNTS
        // Recompute from the DOM after every list change so the
        // badges match what the user sees without a round trip.
        // ============================================================
        function refreshTabCounts() {
            if (!orderList) return;

            var counts = {
                pending: 0,
                preparing: 0,
                rider_pending: 0,
                delivering: 0,
                recent: 0
            };

            var closedStatuses = ['delivered', 'cancelled', 'refunded'];

            orderList.querySelectorAll('.kitchen-order-card').forEach(function (card) {
                var status = card.dataset.orderStatus || '';
                if (counts.hasOwnProperty(status)) {
                    counts[status]++;
                } else if (closedStatuses.indexOf(status) !== -1) {
                    counts.recent++;
                }
            });

            tabs.forEach(function (tab) {
                var filter = tab.dataset.filter;
                var badge = tab.querySelector('.kitchen-tab-count');
                if (!badge || !filter) return;
                if (counts.hasOwnProperty(filter)) {
                    badge.textContent = String(counts[filter]);
                }
            });

            liveCount = counts.pending + counts.preparing
                      + counts.rider_pending + counts.delivering;
        }

        // ============================================================
        // LIVE ORDER POLL
        // ============================================================
        function startOrderPolling() {
            if (orderPollTimer) return;
            lastOrderPollAt = 0;
            // 1s tick; the actual poll fires only at the current
            // cadence, decided per tick from liveCount and page
            // visibility.
            orderPollTimer = setInterval(orderPollTick, 1000);
        }

        function orderPollTick() {
            if (document.hidden) return;

            var now = Date.now();
            var interval = liveCount > 0 ? ORDER_POLL_MS : ORDER_IDLE_MS;
            if (now - lastOrderPollAt < interval) return;

            lastOrderPollAt = now;
            postOrder('poll', { since_order_id: lastOrderId })
                .then(applyOrderPollResponse)
                .catch(function () { /* transient; next tick retries */ });
        }

        function postOrder(action, extra) {
            var body = new URLSearchParams();
            body.append('csrf_token', CSRF_TOKEN);
            body.append('action', action);
            if (extra) {
                Object.keys(extra).forEach(function (k) {
                    body.append(k, String(extra[k]));
                });
            }
            return fetch(HANDLER, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (res) {
                return res.json().catch(function () {
                    return { status: 'error', message: 'Invalid response' };
                });
            });
        }

        function applyOrderPollResponse(data) {
            if (!data || data.status !== 'success') return;

            var rows    = data.rows    || [];
            var removed = data.removed || [];
            var counts  = data.counts  || null;
            var maxId   = parseInt(data.max_id, 10) || lastOrderId;

            // Remove orders the server says are no longer live.
            removed.forEach(function (oid) {
                var existing = orderList.querySelector(
                    '.kitchen-order-card[data-order-id="' + oid + '"]'
                );
                if (existing) existing.remove();
            });

            // Update existing rows: the server sends the full card
            // payload again, so we just swap the node in place if
            // the status changed.
            rows.forEach(function (row) {
                if (!row || !row.order_id) return;

                var oid     = parseInt(row.order_id, 10);
                var newHtml = row.html || '';
                var existing = orderList.querySelector(
                    '.kitchen-order-card[data-order-id="' + oid + '"]'
                );

                if (existing) {
                    var newStatus = row.order_status || '';
                    var oldStatus = existing.dataset.orderStatus || '';
                    if (newStatus !== oldStatus) {
                        // Swap the card markup in place. Keep its DOM
                        // position so the user's eye does not jump.
                        var temp = document.createElement('div');
                        temp.innerHTML = newHtml;
                        var replacement = temp.firstElementChild;
                        if (replacement) {
                            existing.parentNode.replaceChild(replacement, existing);
                        }
                    }
                } else {
                    // New order. Prepend so it appears at the top of
                    // whichever tab it belongs to.
                    var temp = document.createElement('div');
                    temp.innerHTML = newHtml;
                    var newNode = temp.firstElementChild;
                    if (newNode) {
                        // Remove the static empty state if present.
                        var empty = document.getElementById('kitchenEmptyFilterState');
                        if (empty) empty.remove();

                        orderList.insertBefore(newNode, orderList.firstChild);
                    }
                }
            });

            if (maxId > lastOrderId) lastOrderId = maxId;

            if (counts) {
                // Server-provided counts are authoritative. Fall
                // back to DOM-derived counts if the response does
                // not carry them.
                liveCount = (parseInt(counts.pending, 10) || 0)
                          + (parseInt(counts.preparing, 10) || 0)
                          + (parseInt(counts.rider_pending, 10) || 0)
                          + (parseInt(counts.delivering, 10) || 0);
            } else {
                refreshTabCounts();
            }

            applyFilter(getActiveFilter());
        }

        // Kick off the poll on page load for the branch view.
        startOrderPolling();

        // ============================================================
        // TAB CLICK — reuse the existing behaviour, then reapply the
        // filter against the current DOM.
        // ============================================================
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var filter = this.dataset.filter || 'pending';
                tabs.forEach(function (t) {
                    var isActive = t === tab;
                    t.classList.toggle('active', isActive);
                    t.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });
                applyFilter(filter);
            });
        });

        // Run once so the initial tab is honoured after the server
        // render.
        applyFilter(getActiveFilter());
        refreshTabCounts();

        // ============================================================
        // CHAT MODAL — delegated open
        // ============================================================
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('[data-restaurant-chat-open]');
            if (!trigger) return;
            if (trigger.disabled || trigger.getAttribute('aria-disabled') === 'true') return;

            e.preventDefault();

            var orderId     = parseInt(trigger.getAttribute('data-restaurant-chat-order-id'), 10) || 0;
            var counterparty = trigger.getAttribute('data-restaurant-chat-counterparty') || 'customer';
            var subtitle    = trigger.getAttribute('data-restaurant-chat-subtitle') || '';

            openChatModal(orderId, counterparty, subtitle);
        });

        // ============================================================
        // CHAT MODAL — open / close
        // ============================================================
        function openChatModal(orderId, counterparty, subtitle) {
            if (!chatModal || orderId <= 0) return;

            counterparty = normaliseChannel(counterparty);

            activeOrderId = orderId;
            activeChannel = counterparty;

            chatOrderIdInput.value = String(orderId);
            chatChannelInput.value = counterparty;
            chatSubtitleEl.textContent = subtitle || ('Order #' + orderId);

            setActiveTab(counterparty);

            // Reset visible body but preserve the cursor only if the
            // channel was already loaded for this same order. A new
            // order means new conversations; wipe the cursors.
            var key = orderId + ':' + counterparty;
            if (channelCursor[counterparty] === 0 || currentCursorKey !== key) {
                channelCursor[counterparty] = 0;
                renderedIds[counterparty] = Object.create(null);
                renderLoading();
            }
            currentCursorKey = key;

            document.body.style.overflow = 'hidden';
            chatModal.style.display = 'flex';
            void chatModal.offsetWidth;
            chatModal.classList.add('active');

            loadMessages(counterparty);
            startChatPolling();

            setTimeout(function () { chatInput.focus(); }, 120);
        }

        var currentCursorKey = '';

        function closeChatModal() {
            if (!chatModal) return;

            chatModal.classList.remove('active');
            setTimeout(function () {
                if (!chatModal.classList.contains('active')) {
                    chatModal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }, 220);

            stopChatPolling();
            activeOrderId = 0;
        }

        if (chatCloseBtn) {
            chatCloseBtn.addEventListener('click', function (e) {
                e.preventDefault();
                closeChatModal();
            });
        }

        if (chatModal) {
            var overlay = chatModal.querySelector('.modal-overlay');
            if (overlay) overlay.addEventListener('click', closeChatModal);
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && chatModal &&
                chatModal.classList.contains('active')) {
                closeChatModal();
            }
        });

        // ============================================================
        // CHAT MODAL — tabs
        // ============================================================
        chatTabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                if (this.disabled) return;
                var channel = this.dataset.counterparty || 'customer';
                if (channel === activeChannel) return;

                activeChannel = channel;
                chatChannelInput.value = channel;
                setActiveTab(channel);

                channelCursor[channel] = 0;
                renderedIds[channel] = Object.create(null);
                renderLoading();

                loadMessages(channel);
            });
        });

        function setActiveTab(channel) {
            chatTabs.forEach(function (t) {
                var isActive = t.dataset.counterparty === channel;
                t.classList.toggle('active', isActive);
                t.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
        }

        function normaliseChannel(channel) {
            if (channel === 'delivery_rider') return 'delivery_rider';
            return 'customer';
        }

        // ============================================================
        // CHAT MODAL — rendering
        // ============================================================
        function renderLoading() {
            if (!chatBody) return;
            chatBody.innerHTML = '<div class="restaurant-chat-loading"><span>Loading messages…</span></div>';
        }

        function renderEmpty(text) {
            if (!chatBody) return;
            chatBody.innerHTML = '<div class="restaurant-chat-empty">' + escapeHtml(text) + '</div>';
        }

        function scrollToBottom() {
            requestAnimationFrame(function () {
                chatBody.scrollTop = chatBody.scrollHeight;
            });
        }

        function appendMessage(channel, msg, skipScroll) {
            if (!chatBody || !msg) return false;

            var mid = parseInt(msg.message_id, 10) || 0;
            if (mid > 0 && renderedIds[channel][mid]) return false;

            var div = document.createElement('div');
            div.className = 'restaurant-chat-message ' +
                (msg.direction === 'sent'
                    ? 'restaurant-chat-message-sent'
                    : 'restaurant-chat-message-received');

            if (mid > 0) {
                div.setAttribute('data-message-id', String(mid));
                renderedIds[channel][mid] = true;
            }

            if (msg.sender) {
                var sender = document.createElement('span');
                sender.className = 'restaurant-chat-message-sender';
                sender.textContent = msg.sender;
                div.appendChild(sender);
            }

            var content = document.createElement('span');
            content.textContent = msg.content || '';
            div.appendChild(content);

            if (msg.time) {
                var time = document.createElement('span');
                time.className = 'restaurant-chat-message-time';
                time.textContent = msg.time;
                div.appendChild(time);
            }

            var placeholder = chatBody.querySelector(
                '.restaurant-chat-empty, .restaurant-chat-loading'
            );
            if (placeholder) placeholder.remove();

            chatBody.appendChild(div);
            if (!skipScroll) scrollToBottom();
            return true;
        }

        function renderFull(channel, messages, maxId) {
            if (!chatBody) return;
            chatBody.innerHTML = '';

            if (!messages || messages.length === 0) {
                renderEmpty('No messages yet. Start the conversation.');
            } else {
                messages.forEach(function (m) {
                    appendMessage(channel, m, true);
                });
                scrollToBottom();
            }

            if (maxId > 0) channelCursor[channel] = maxId;
        }

        // ============================================================
        // CHAT MODAL — server calls
        // ============================================================
        function postChat(action, extra) {
            var fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('action', action);
            fd.append('order_id', String(activeOrderId));
            fd.append('counterparty', String(activeChannel));
            if (extra) {
                Object.keys(extra).forEach(function (k) {
                    fd.append(k, String(extra[k]));
                });
            }
            return fetch(CHAT_URL, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(function (res) {
                return res.json().catch(function () {
                    return { status: 'error', message: 'Invalid response' };
                });
            });
        }

        function loadMessages(channel) {
            if (fetching[channel]) return;
            fetching[channel] = true;

            var sinceId = channelCursor[channel] || 0;
            var isFirst = sinceId === 0;

            var action = isFirst ? 'list' : 'poll';
            var extra  = isFirst ? {} : { since_message_id: sinceId };

            postChat(action, extra)
                .then(function (data) {
                    if (!data || data.status !== 'success') {
                        if (isFirst) {
                            renderEmpty((data && data.message) || 'Could not load messages.');
                        }
                        return;
                    }

                    var msgs  = data.messages || [];
                    var maxId = parseInt(data.max_id, 10) || sinceId;

                    if (isFirst) {
                        renderFull(channel, msgs, maxId);
                    } else if (msgs.length > 0) {
                        msgs.forEach(function (m) {
                            appendMessage(channel, m, true);
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
            var fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('action', 'read');
            fd.append('order_id', String(activeOrderId));
            fd.append('counterparty', String(channel));

            fetch(CHAT_URL, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).catch(function () { /* silent */ });
        }

        // ============================================================
        // CHAT MODAL — send
        // ============================================================
        if (chatForm) {
            chatForm.addEventListener('submit', function (e) {
                e.preventDefault();

                var content = chatInput.value.trim();
                if (content === '') return;

                var submitBtn = chatForm.querySelector('.restaurant-chat-send');
                if (submitBtn) submitBtn.disabled = true;

                postChat('send', { content: content })
                    .then(function (data) {
                        if (!data || data.status !== 'success') {
                            showChatToast((data && data.message) || 'Could not send message.', 'error');
                            return;
                        }

                        chatInput.value = '';

                        if (data.message_data) {
                            appendMessage(activeChannel, data.message_data, false);
                        }

                        var newMax = parseInt(data.max_id, 10) || 0;
                        if (newMax > channelCursor[activeChannel]) {
                            channelCursor[activeChannel] = newMax;
                        }
                    })
                    .catch(function () {
                        showChatToast('Network error. Please try again.', 'error');
                    })
                    .finally(function () {
                        if (submitBtn) submitBtn.disabled = false;
                        chatInput.focus();
                    });
            });
        }

        // ============================================================
        // CHAT POLLING
        // ============================================================
        function startChatPolling() {
            stopChatPolling();
            chatPollTimer = setInterval(function () {
                if (!chatModal || !chatModal.classList.contains('active')) return;
                if (document.hidden) return;
                if (chatInput.value.trim() !== '') return;
                if (fetching[activeChannel]) return;
                loadMessages(activeChannel);
            }, CHAT_POLL_MS);
        }

        function stopChatPolling() {
            if (chatPollTimer) {
                clearInterval(chatPollTimer);
                chatPollTimer = null;
            }
        }

        // ============================================================
        // VISIBILITY
        // ============================================================
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState !== 'visible') return;

            // Immediate catch-up poll for the order list.
            postOrder('poll', { since_order_id: lastOrderId })
                .then(applyOrderPollResponse)
                .catch(function () { /* silent */ });

            // Immediate catch-up poll for the open chat, if any.
            if (chatModal && chatModal.classList.contains('active')) {
                loadMessages(activeChannel);
            }
        });

        // ============================================================
        // TOAST
        // ============================================================
        function showChatToast(message, type) {
            var toast = document.getElementById('kitchenChatToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'kitchenChatToast';
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
                warning: ['#fef3c7', '#92400e'],
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
    });
})();