/**
 * FitPal Restaurant Kitchen Realtime
 *
 * Two responsibilities, both scoped to the kitchen page.
 *
 * 1. LIVE ORDER LIST
 *    Polls order-handler.php with a delta cursor and a tab/page
 *    context. The handler returns:
 *
 *      rows     — new orders above since_order_id
 *      updated  — cards whose status changed
 *      removed  — order_ids that are no longer live
 *      counts   — per-tab bucket counts
 *      page     — the current tab's paginated slice
 *      max_id   — the highest order_id in the live set
 *
 *    What this file does with that payload:
 *
 *      - Tab badges are updated in place from `counts`.
 *      - New orders are counted into a "N new orders" pill. The
 *        pill does NOT force-prepend cards — that would shift the
 *        current page under the user's cursor. Clicking the pill
 *        reloads the current tab at page 1.
 *      - Status changes on already-rendered cards are swapped in
 *        place. If the new status does not belong to the current
 *        tab, the card is removed from the current tab's DOM
 *        (the tab's badge count already reflects the move).
 *      - Cards in `removed` are dropped. If the list becomes
 *        empty, the empty-state block is restored.
 *
 *    Cadence:
 *      - 5s while the tab is visible and there are live orders.
 *      - 15s while visible and the board is empty.
 *      - Paused entirely while document.hidden.
 *      - One immediate poll on visibilitychange → visible, and on
 *        pageshow when the page is restored from bfcache.
 *
 * 2. CHAT MODAL
 *    Open/close/tabs/send/read for #restaurantChatModal, plus a
 *    per-channel delta poll of messages. Same contract as the
 *    customer chat modal: the server returns new messages only,
 *    the client appends them without re-rendering, and the
 *    read-marker fires when a channel is on screen.
 *
 * -----------------------------------------------------------------
 * CARD COLLAPSE STATE — THE IMPORTANT PART
 * -----------------------------------------------------------------
 * Every poll re-renders card HTML from the server. The server has
 * no idea which cards the user has expanded, so the fresh HTML
 * always ships the details band collapsed.
 *
 * The fix is snapshot-and-reapply, performed by this file, around
 * the entire DOM-mutation phase of applyOrderPollResponse():
 *
 *   1. BEFORE any DOM mutation, snapshotExpandedCards() reads the
 *      data-order-id of every card that currently carries the
 *      .is-expanded class, and returns that set.
 *
 *   2. The existing code performs its normal mutations — remove
 *      closed cards, swap cards whose status changed, insert new
 *      page-slice cards, restore the empty state.
 *
 *   3. AFTER all mutations, reapplyExpandedCards(set) walks the
 *      DOM, and for every card whose order_id is in the snapshot,
 *      re-applies .is-expanded and unhides the details band.
 *
 * Because the snapshot is taken from the live DOM and the reapply
 * happens after every mutation, the state is preserved regardless
 * of which specific code path touched which card. There is no
 * per-node bookkeeping to drift out of sync and no race with the
 * user's click handlers — the snapshot runs synchronously before
 * the mutations, the reapply runs synchronously after them, and no
 * user input can land in between.
 *
 * This file is the sole owner of preserving the collapse state
 * across a poll. orders.js only toggles the class in response to a
 * click and never needs to know a poll happened.
 * -----------------------------------------------------------------
 *
 * @package FitPal
 * @version 4.0 — Snapshot-and-reapply collapse preservation:
 *                  - New snapshotExpandedCards() reads the current
 *                    .is-expanded set straight from the DOM.
 *                  - New reapplyExpandedCards() re-applies that set
 *                    after every mutation pass.
 *                  - applyOrderPollResponse() now wraps its whole
 *                    mutation phase in a snapshot/reapply pair, so
 *                    state is preserved across every path
 *                    uniformly.
 *                  - swapCard() no longer reads or writes card
 *                    state. It is a plain DOM swap.
 *                  - applyPageSlice() now guards its insert loop
 *                    against inserting a duplicate card for an
 *                    order_id that is already in the DOM, and no
 *                    longer carries its own state-preservation
 *                    logic.
 *                  - A new isPolling flag prevents a second poll
 *                    from firing while the previous one is still in
 *                    flight.
 *
 *                (3.0: poll-owned per-node state preservation.
 *                2.1: poll delegated state preservation to a hook
 *                in orders.js. 2.0: paginated kitchen page. 1.0:
 *                initial realtime script.)
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

        // The owner view has no live list and no chat. Exit early.
        if (SCOPE !== 'branch') return;

        /* ============================================================
           CARD COLLAPSE SNAPSHOT HELPERS
           ============================================================
           These are the single source of truth for preserving the
           user's expanded cards across a poll. They read and write
           only the DOM, never a JS-side set that could drift.
           ============================================================ */

        /**
         * Read every card in the current DOM that is expanded and
         * return the set of their order_ids.
         *
         * @return {Set<number>}
         */
        function snapshotExpandedCards() {
            var expanded = new Set();
            if (!orderList) return expanded;

            orderList.querySelectorAll('.kitchen-order-card.is-expanded').forEach(function (card) {
                var oid = parseInt(card.dataset.orderId, 10) || 0;
                if (oid > 0) expanded.add(oid);
            });
            return expanded;
        }

        /**
         * Re-apply the snapshot to every card currently in the DOM.
         *
         * Idempotent. Safe to call with an empty set — that simply
         * ensures every card is collapsed, which is the default the
         * server emits anyway.
         *
         * @param {Set<number>} expandedSet
         */
        function reapplyExpandedCards(expandedSet) {
            if (!orderList) return;

            orderList.querySelectorAll('.kitchen-order-card').forEach(function (card) {
                var oid = parseInt(card.dataset.orderId, 10) || 0;
                if (oid <= 0) return;

                var details = card.querySelector('.kitchen-order-details');
                var toggle  = card.querySelector('.kitchen-order-toggle');

                if (expandedSet.has(oid)) {
                    card.classList.add('is-expanded');
                    if (details) details.hidden = false;
                    if (toggle)  toggle.setAttribute('aria-expanded', 'true');
                } else {
                    card.classList.remove('is-expanded');
                    if (details) details.hidden = true;
                    if (toggle)  toggle.setAttribute('aria-expanded', 'false');
                }
            });
        }

        /* ============================================================
           ORDER LIST STATE
           ============================================================ */

        var orderList       = document.getElementById('kitchenOrderList');
        var tabCountEls     = document.querySelectorAll('[data-tab-count]');
        var newOrderPill    = document.getElementById('kitchenNewOrderPill');
        var newOrderCountEl = document.getElementById('kitchenNewOrderCount');
        var newOrderPlural  = document.getElementById('kitchenNewOrderPlural');

        var activeTab  = orderList ? (orderList.dataset.activeTab || 'new') : 'new';
        var activePage = orderList ? (parseInt(orderList.dataset.activePage, 10) || 1) : 1;

        var lastOrderId    = parseInt(page.dataset.maxOrderId, 10) || 0;
        var liveCount      = 0;
        var newOrderCount  = 0;

        // Cache of the empty-state block, so we can restore it when a
        // list empties. The page renders exactly one empty state; we
        // capture it once at init.
        var emptyStateHtml = '';
        var emptyStateTab  = '';
        if (orderList) {
            var existingEmpty = orderList.querySelector('.kitchen-empty-state');
            if (existingEmpty) {
                emptyStateHtml = existingEmpty.outerHTML;
                emptyStateTab  = existingEmpty.dataset.emptyTab || activeTab;
            }
        }

        var orderPollTimer  = null;
        var ORDER_POLL_MS   = 5000;
        var ORDER_IDLE_MS   = 15000;
        var lastOrderPollAt = 0;

        // Guards against overlapping polls. A slow response must not
        // allow a second poll to fire while the first is in flight.
        var isPolling = false;

        // Set of order_ids currently rendered in the DOM for the
        // active tab. Used only to count new arrivals for the pill.
        // Rebuilt from the DOM whenever the list changes.
        var renderedOrderIds = Object.create(null);

        function rebuildRenderedSet() {
            renderedOrderIds = Object.create(null);
            if (!orderList) return;
            orderList.querySelectorAll('.kitchen-order-card').forEach(function (card) {
                var oid = parseInt(card.dataset.orderId, 10) || 0;
                if (oid > 0) renderedOrderIds[oid] = true;
            });
        }

        if (orderList) {
            rebuildRenderedSet();
        }

        /* ============================================================
           ORDER POLL
           ============================================================ */

        function startOrderPolling() {
            if (orderPollTimer) return;
            lastOrderPollAt = 0;
            orderPollTimer = setInterval(orderPollTick, 1000);
        }

        function stopOrderPolling() {
            if (orderPollTimer) {
                clearInterval(orderPollTimer);
                orderPollTimer = null;
            }
        }

        function orderPollTick() {
            if (document.hidden) return;
            if (isPolling) return;

            var now = Date.now();
            var interval = liveCount > 0 ? ORDER_POLL_MS : ORDER_IDLE_MS;
            if (now - lastOrderPollAt < interval) return;

            lastOrderPollAt = now;
            isPolling = true;

            postOrder('poll', {
                since_order_id: lastOrderId,
                tab:            activeTab,
                page:           activePage
            })
                .then(applyOrderPollResponse)
                .catch(function () { /* transient; next tick retries */ })
                .finally(function () {
                    isPolling = false;
                });
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

            // ---------------------------------------------------------
            // SNAPSHOT: read the user's expanded cards BEFORE any DOM
            // mutation. This is the state that must survive the poll.
            // ---------------------------------------------------------
            var expandedSnapshot = snapshotExpandedCards();

            var rows    = data.rows    || [];
            var updated = data.updated || [];
            var removed = data.removed || [];
            var counts  = data.counts  || null;
            var pageData = data.page   || null;
            var maxId   = parseInt(data.max_id, 10) || lastOrderId;

            // 1. Update tab badge counts in place.
            if (counts) {
                updateTabCounts(counts);
                liveCount = parseInt(counts.total_live, 10) || 0;
            }

            // 2. Count new orders that arrived and are not already
            //    rendered on this page.
            rows.forEach(function (row) {
                if (!row || !row.order_id) return;
                var oid = parseInt(row.order_id, 10) || 0;
                if (oid <= 0) return;
                if (renderedOrderIds[oid]) return;
                if (oid > lastOrderId) {
                    newOrderCount++;
                }
            });

            if (newOrderCount > 0 && newOrderPill) {
                showNewOrderPill(newOrderCount);
            }

            // 3. Drop orders the server says are no longer live.
            removed.forEach(function (oid) {
                removeCard(oid);
            });

            // 4. Apply status changes in place.
            updated.forEach(function (row) {
                if (!row || !row.order_id) return;
                var oid = parseInt(row.order_id, 10) || 0;
                if (oid <= 0) return;

                var existing = orderList
                    ? orderList.querySelector(
                        '.kitchen-order-card[data-order-id="' + oid + '"]'
                    )
                    : null;

                if (!existing) return;

                var newStatus = row.order_status || '';
                var oldStatus = existing.dataset.orderStatus || '';

                if (newStatus === oldStatus) return;

                if (!statusBelongsToTab(newStatus, activeTab)) {
                    existing.remove();
                    delete renderedOrderIds[oid];
                    return;
                }

                if (row.html) {
                    swapCard(existing, row.html);
                } else {
                    existing.dataset.orderStatus = newStatus;
                }
            });

            // 5. If the current page's slice changed, replace the list
            //    content with the fresh slice the handler returned.
            if (pageData && Array.isArray(pageData.items)) {
                applyPageSlice(pageData);
            }

            if (maxId > lastOrderId) lastOrderId = maxId;

            // 6. If the tab's list is empty, ensure the empty-state
            //    block is rendered.
            ensureEmptyState();

            // ---------------------------------------------------------
            // REAPPLY: restore the user's expanded cards. Every node
            // in the DOM now gets its state re-set from the
            // snapshot. Cards that were expanded stay expanded.
            // ---------------------------------------------------------
            reapplyExpandedCards(expandedSnapshot);

            // The DOM has changed, so the rendered-id set may have
            // too. Rebuild it from the live DOM.
            rebuildRenderedSet();
        }

        function applyPageSlice(pageData) {
            if (!orderList) return;

            var serverItems = pageData.items || [];
            var serverIds   = Object.create(null);
            serverItems.forEach(function (item) {
                var oid = parseInt(item.order_id, 10) || 0;
                if (oid > 0) serverIds[oid] = true;
            });

            // Remove cards that are no longer on this page.
            orderList.querySelectorAll('.kitchen-order-card').forEach(function (card) {
                var oid = parseInt(card.dataset.orderId, 10) || 0;
                if (oid > 0 && !serverIds[oid]) {
                    card.remove();
                    delete renderedOrderIds[oid];
                }
            });

            // Insert any cards the server says belong on this page
            // but that are not yet in the DOM. Checked against the
            // live DOM rather than a JS mirror so a stale mirror
            // cannot produce a duplicate card.
            serverItems.forEach(function (item) {
                var oid = parseInt(item.order_id, 10) || 0;
                if (oid <= 0) return;

                var alreadyInDom = orderList.querySelector(
                    '.kitchen-order-card[data-order-id="' + oid + '"]'
                );
                if (alreadyInDom) return;

                if (!item.html) return;

                var temp = document.createElement('div');
                temp.innerHTML = item.html;
                var newNode = temp.firstElementChild;
                if (!newNode) return;

                orderList.appendChild(newNode);
                renderedOrderIds[oid] = true;
            });

            // The active page number can change server-side (a page
            // clamp). Keep our local copy in sync.
            if (typeof pageData.page === 'number' && pageData.page > 0) {
                activePage = pageData.page;
                if (orderList) orderList.dataset.activePage = String(activePage);
            }
        }

        function statusBelongsToTab(status, tab) {
            switch (tab) {
                case 'new':              return status === 'pending';
                case 'preparing':        return status === 'preparing';
                case 'waiting_on_rider': return status === 'rider_pending' || status === 'picking_up';
                case 'out_for_delivery': return status === 'delivering';
                case 'recent':           return status === 'delivered' || status === 'cancelled' || status === 'refunded';
                default:                 return false;
            }
        }

        function updateTabCounts(counts) {
            tabCountEls.forEach(function (el) {
                var key = el.dataset.tabCount;
                if (!key) return;
                if (typeof counts[key] === 'number') {
                    el.textContent = String(counts[key]);
                }
            });
        }

        /**
         * Replace a card node with a fresh HTML fragment.
         *
         * This is a plain DOM swap. It does NOT read or write the
         * card's expanded state. The outer applyOrderPollResponse()
         * snapshot/reapply pair is what preserves state across this
         * swap.
         */
        function swapCard(existingEl, html) {
            var temp = document.createElement('div');
            temp.innerHTML = html;
            var replacement = temp.firstElementChild;
            if (!replacement) return;
            existingEl.parentNode.replaceChild(replacement, existingEl);
        }

        function removeCard(orderId) {
            var oid = parseInt(orderId, 10) || 0;
            if (oid <= 0 || !orderList) return;

            var existing = orderList.querySelector(
                '.kitchen-order-card[data-order-id="' + oid + '"]'
            );
            if (existing) existing.remove();
            delete renderedOrderIds[oid];
        }

        function ensureEmptyState() {
            if (!orderList) return;

            var hasCards = orderList.querySelector('.kitchen-order-card');
            var hasEmpty = orderList.querySelector('.kitchen-empty-state');

            if (hasCards && hasEmpty) {
                hasEmpty.remove();
                return;
            }

            if (!hasCards && !hasEmpty && emptyStateHtml !== '') {
                if (emptyStateTab === activeTab) {
                    var temp = document.createElement('div');
                    temp.innerHTML = emptyStateHtml;
                    var node = temp.firstElementChild;
                    if (node) orderList.appendChild(node);
                }
            }
        }

        function showNewOrderPill(count) {
            if (!newOrderPill || !newOrderCountEl) return;

            newOrderCountEl.textContent = String(count);
            if (newOrderPlural) {
                newOrderPlural.textContent = count === 1 ? '' : 's';
            }
            newOrderPill.hidden = false;
        }

        /* ============================================================
           POLL LIFECYCLE
           ============================================================ */

        startOrderPolling();

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState !== 'visible') return;
            if (isPolling) return;

            lastOrderPollAt = 0;
            isPolling = true;

            postOrder('poll', {
                since_order_id: lastOrderId,
                tab:            activeTab,
                page:           activePage
            })
                .then(applyOrderPollResponse)
                .catch(function () { /* silent */ })
                .finally(function () {
                    isPolling = false;
                });
        });

        window.addEventListener('pageshow', function (e) {
            if (!e.persisted) return;
            if (isPolling) return;

            lastOrderPollAt = 0;
            isPolling = true;

            postOrder('poll', {
                since_order_id: lastOrderId,
                tab:            activeTab,
                page:           activePage
            })
                .then(applyOrderPollResponse)
                .catch(function () { /* silent */ })
                .finally(function () {
                    isPolling = false;
                });
        });

        /* ============================================================
           CHAT MODAL
           Open, close, tabs, send, read, delta poll.
           ============================================================ */

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
            customer:       0,
            delivery_rider: 0
        };

        var renderedIds = {
            customer:       Object.create(null),
            delivery_rider: Object.create(null)
        };

        var fetching = {
            customer:       false,
            delivery_rider: false
        };

        var chatPollTimer = null;
        var CHAT_POLL_MS  = 5000;

        function escapeHtml(text) {
            var d = document.createElement('div');
            d.textContent = String(text == null ? '' : text);
            return d.innerHTML;
        }

        function normaliseChannel(channel) {
            if (channel === 'delivery_rider') return 'delivery_rider';
            return 'customer';
        }

        function openChatModal(orderId, counterparty, subtitle) {
            if (!chatModal || orderId <= 0) return;

            counterparty = normaliseChannel(counterparty);

            activeOrderId = orderId;
            activeChannel = counterparty;

            if (chatOrderIdInput) chatOrderIdInput.value = String(orderId);
            if (chatChannelInput) chatChannelInput.value = counterparty;
            if (chatSubtitleEl)   chatSubtitleEl.textContent = subtitle || ('Order #' + orderId);

            setActiveChatTab(counterparty);

            renderChatLoading();

            document.body.style.overflow = 'hidden';
            chatModal.style.display = 'flex';
            void chatModal.offsetWidth;
            chatModal.classList.add('active');

            loadChatMessages(counterparty);
            startChatPolling();

            setTimeout(function () {
                if (chatInput) chatInput.focus();
            }, 120);
        }

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
            var chatOverlay = chatModal.querySelector('.modal-overlay');
            if (chatOverlay) chatOverlay.addEventListener('click', closeChatModal);
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && chatModal &&
                chatModal.classList.contains('active')) {
                closeChatModal();
            }
        });

        function setActiveChatTab(channel) {
            chatTabs.forEach(function (t) {
                var isActive = t.dataset.counterparty === channel;
                t.classList.toggle('active', isActive);
                t.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
        }

        chatTabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                if (this.disabled) return;
                var channel = this.dataset.counterparty || 'customer';
                if (channel === activeChannel) return;

                activeChannel = channel;
                if (chatChannelInput) chatChannelInput.value = channel;
                setActiveChatTab(channel);

                renderChatLoading();
                loadChatMessages(channel);
            });
        });

        function renderChatLoading() {
            if (!chatBody) return;
            chatBody.innerHTML =
                '<div class="restaurant-chat-loading"><span>Loading messages…</span></div>';
        }

        function renderChatEmpty(text) {
            if (!chatBody) return;
            chatBody.innerHTML = '<div class="restaurant-chat-empty">' +
                escapeHtml(text) + '</div>';
        }

        function scrollChatToBottom() {
            if (!chatBody) return;
            requestAnimationFrame(function () {
                chatBody.scrollTop = chatBody.scrollHeight;
            });
        }

        function appendChatMessage(channel, msg, skipScroll) {
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
            if (!skipScroll) scrollChatToBottom();
            return true;
        }

        function renderChatFull(channel, messages, maxId) {
            if (!chatBody) return;
            chatBody.innerHTML = '';

            if (!messages || messages.length === 0) {
                renderChatEmpty('No messages yet. Start the conversation.');
            } else {
                messages.forEach(function (m) {
                    appendChatMessage(channel, m, true);
                });
                scrollChatToBottom();
            }

            if (maxId > 0) channelCursor[channel] = maxId;
        }

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

        function loadChatMessages(channel) {
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
                            renderChatEmpty((data && data.message) || 'Could not load messages.');
                        }
                        return;
                    }

                    var msgs  = data.messages || [];
                    var maxId = parseInt(data.max_id, 10) || sinceId;

                    if (isFirst) {
                        renderChatFull(channel, msgs, maxId);
                    } else if (msgs.length > 0) {
                        msgs.forEach(function (m) {
                            appendChatMessage(channel, m, true);
                        });
                        scrollChatToBottom();
                        if (maxId > channelCursor[channel]) {
                            channelCursor[channel] = maxId;
                        }
                    } else if (maxId > channelCursor[channel]) {
                        channelCursor[channel] = maxId;
                    }

                    if (channel === activeChannel) {
                        markChatRead(channel);
                    }
                })
                .finally(function () {
                    fetching[channel] = false;
                });
        }

        function markChatRead(channel) {
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

        if (chatForm) {
            chatForm.addEventListener('submit', function (e) {
                e.preventDefault();

                var content = chatInput ? chatInput.value.trim() : '';
                if (content === '') return;

                var submitBtn = chatForm.querySelector('.restaurant-chat-send');
                if (submitBtn) submitBtn.disabled = true;

                postChat('send', { content: content })
                    .then(function (data) {
                        if (!data || data.status !== 'success') {
                            showChatToast(
                                (data && data.message) || 'Could not send message.',
                                'error'
                            );
                            return;
                        }

                        if (chatInput) chatInput.value = '';

                        if (data.message_data) {
                            appendChatMessage(activeChannel, data.message_data, false);
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
                        if (chatInput) chatInput.focus();
                    });
            });
        }

        function startChatPolling() {
            stopChatPolling();
            chatPollTimer = setInterval(function () {
                if (!chatModal || !chatModal.classList.contains('active')) return;
                if (document.hidden) return;
                if (chatInput && chatInput.value.trim() !== '') return;
                if (fetching[activeChannel]) return;
                loadChatMessages(activeChannel);
            }, CHAT_POLL_MS);
        }

        function stopChatPolling() {
            if (chatPollTimer) {
                clearInterval(chatPollTimer);
                chatPollTimer = null;
            }
        }

        /* ============================================================
           CHAT TOAST
           ============================================================ */

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

        /* ============================================================
           DELEGATED CHAT OPENER
           Any element carrying [data-restaurant-chat-open] opens
           the chat modal. The listener lives on document so it
           works regardless of which script or template rendered
           the trigger.
           ============================================================ */

        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('[data-restaurant-chat-open]');
            if (!trigger) return;
            if (trigger.disabled || trigger.getAttribute('aria-disabled') === 'true') return;

            e.preventDefault();

            var orderId      = parseInt(trigger.getAttribute('data-restaurant-chat-order-id'), 10) || 0;
            var counterparty = trigger.getAttribute('data-restaurant-chat-counterparty') || 'customer';
            var subtitle     = trigger.getAttribute('data-restaurant-chat-subtitle') || '';

            openChatModal(orderId, counterparty, subtitle);
        });
    });
})();