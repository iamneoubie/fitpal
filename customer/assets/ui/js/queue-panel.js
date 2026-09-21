/**
 * FitPal Customer Queue Panel JavaScript
 *
 * The queue panel is the UI for the session-based order queue. The
 * server (queue-handler.php) is the single source of truth.
 *
 * @package FitPal
 * @version 6.1 — Remove button uses shared cancel icon (no inline SVG).
 */
(function () {
    'use strict';

    var wrapper      = document.getElementById('queuePanelWrapper');
    var panel        = document.getElementById('queuePanel');
    var panelInner   = document.getElementById('queuePanelInner');
    var itemsBox     = document.getElementById('queueItemsContainer');
    var toggleBtn    = document.getElementById('queuePanelToggle');
    var panelHeader  = document.getElementById('queuePanelHeader');
    var badge        = document.getElementById('queueItemCount');
    var grandTotalEl = document.getElementById('queueGrandTotal');
    var countLabelEl = document.getElementById('queueItemCountLabel');
    var footerCountEl= document.getElementById('queueFooterItemCount');
    var checkoutBtn  = document.getElementById('queueCheckoutBtn');
    var cancelBtn    = document.getElementById('queueCancelBtn');
    var emptyEl      = document.getElementById('queueEmptyState');

    var removeModal        = document.getElementById('queueRemoveModal');
    var removeModalCancel  = document.getElementById('queueModalCancel');
    var removeModalConfirm = document.getElementById('queueModalConfirm');
    var removeModalName    = document.getElementById('queueModalItemName');

    var cancelModal        = document.getElementById('queueCancelModal');
    var cancelModalCancel  = document.getElementById('queueCancelModalCancel');
    var cancelModalConfirm = document.getElementById('queueCancelModalConfirm');

    var queue       = [];
    var isOpen      = false;
    var pendingRm   = null;
    var syncTimer   = null;
    var initialized = false;

    // ----------------------------------------------------------------
    // NETWORK
    // ----------------------------------------------------------------
    function csrfToken() {
        if (window.FITPAL_CSRF_TOKEN) return window.FITPAL_CSRF_TOKEN;
        var el = document.querySelector('input[name="csrf_token"]');
        return el ? el.value : '';
    }

    function post(payload) {
        return fetch('../backend/handlers/queue-handler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: JSON.stringify(Object.assign({ csrf_token: csrfToken() }, payload))
        }).then(function (r) {
            return r.text().then(function (text) {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('[queue-panel] Non-JSON response:', r.status, text.slice(0, 200));
                    return { status: 'error', message: 'Server returned ' + r.status };
                }
            });
        });
    }

    function refreshQueue() {
        return post({ action: 'get' })
            .then(function (data) {
                if (data && data.status === 'success') {
                    queue = data.queue || [];
                    render();
                }
            })
            .catch(function () { /* leave empty on failure */ });
    }

    function scheduleSync() {
        clearTimeout(syncTimer);
        syncTimer = setTimeout(function () {
            post({ action: 'sync', queue: queue }).then(function (data) {
                if (data && data.status === 'success') {
                    queue = data.queue;
                    render();
                }
            }).catch(function () { /* keep optimistic local state */ });
        }, 300);
    }

    function rowKey(item) {
        if (item && typeof item.line_key === 'string' && item.line_key !== '') {
            return item.line_key;
        }
        return 'p::' + (item && item.product_id ? item.product_id : '0');
    }

    // ----------------------------------------------------------------
    // RENDER
    // ----------------------------------------------------------------
    function render() {
        var totalItems = 0;
        var totalPrice = 0;

        queue.forEach(function (item) {
            var q = item.quantity || 0;
            totalItems += q;
            totalPrice += (item.price || 0) * q;
        });

        if (badge) {
            badge.textContent   = totalItems;
            badge.style.display = totalItems > 0 ? 'inline-flex' : 'none';
        }

        if (grandTotalEl)  grandTotalEl.textContent  = '₱' + totalPrice.toFixed(2);

        var label = totalItems + ' item' + (totalItems === 1 ? '' : 's');
        if (countLabelEl)  countLabelEl.textContent  = label;
        if (footerCountEl) footerCountEl.textContent = label;

        if (emptyEl) emptyEl.style.display = totalItems === 0 ? 'block' : 'none';

        if (itemsBox) {
            if (totalItems === 0) {
                itemsBox.innerHTML = '';
            } else {
                itemsBox.innerHTML = queue.map(renderItem).join('');
                bindItemEvents();
            }
        }

        if (checkoutBtn) checkoutBtn.disabled = totalItems === 0;
        if (cancelBtn)   cancelBtn.disabled   = totalItems === 0;

        updateVisibility(totalItems);
    }

    function renderItem(item, index) {
        var itemTotal = (item.price || 0) * (item.quantity || 0);
        var assetBase = window.FITPAL_ASSET_BASE || '../../shared/';
        var fallback  = assetBase + 'assets/images/icons/restaurant.svg';
        var cancelIcon= assetBase + 'assets/images/icons/remove-circle-line.svg';
        var img       = (item.image && item.image.trim() !== '') ? item.image : fallback;
        var key       = rowKey(item);

        return ''
            + '<div class="queue-item" data-index="' + index + '"'
            +      ' data-product-id="' + (item.product_id || 0) + '"'
            +      ' data-line-key="' + escapeAttr(key) + '">'
            +   '<div class="queue-item-image">'
            +     '<img src="' + escapeAttr(img) + '" alt="' + escapeAttr(item.name || '') + '"'
            +          ' onerror="this.onerror=null; this.src=\'' + fallback + '\'">'
            +   '</div>'
            +   '<div class="queue-item-info">'
            +     '<p class="queue-item-name">' + escapeHtml(item.name || 'Product') + '</p>'
            +     '<div class="queue-item-meta">'
            +       '<span class="queue-item-price">₱' + (item.price || 0).toFixed(2) + '</span>'
            +       (item.restaurant_name
                        ? '<span class="queue-item-restaurant">• ' + escapeHtml(item.restaurant_name) + '</span>'
                        : '')
            +     '</div>'
            +   '</div>'
            +   '<div class="queue-item-actions">'
            +     '<div class="queue-item-qty">'
            +       '<button type="button" class="qty-btn qty-minus" data-index="' + index + '" aria-label="Decrease">−</button>'
            +       '<input type="number" class="qty-input" value="' + (item.quantity || 1) + '"'
            +              ' min="1" max="' + (item.stock || 999) + '" data-index="' + index + '">'
            +       '<button type="button" class="qty-btn qty-plus" data-index="' + index + '" aria-label="Increase">+</button>'
            +     '</div>'
            +     '<button type="button" class="queue-item-remove" data-index="' + index + '" aria-label="Remove">'
            +       '<img src="' + cancelIcon + '" alt="" class="queue-item-remove-icon" width="32" height="32"'
            +            ' onerror="this.style.display=\'none\'">'
            +     '</button>'
            +     '<span class="queue-item-total">₱' + itemTotal.toFixed(2) + '</span>'
            +   '</div>'
            + '</div>';
    }

    function escapeHtml(text) {
        var d = document.createElement('div');
        d.textContent = String(text == null ? '' : text);
        return d.innerHTML;
    }

    function escapeAttr(text) {
        return escapeHtml(text).replace(/"/g, '&quot;');
    }

    // ----------------------------------------------------------------
    // VISIBILITY / PANEL
    // ----------------------------------------------------------------
    function updateVisibility(totalItems) {
        if (!wrapper) return;

        wrapper.style.display = 'block';
        wrapper.classList.remove('has-items', 'empty');
        wrapper.classList.add(totalItems > 0 ? 'has-items' : 'empty');
        wrapper.setAttribute('aria-hidden', totalItems > 0 ? 'false' : 'true');

        if (totalItems === 0 && isOpen) closePanel();
    }

    function openPanel() {
        if (queue.length === 0) return;
        isOpen = true;
        if (panel) {
            panel.classList.remove('closed');
            panel.classList.add('open');
        }
        if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
    }

    function closePanel() {
        isOpen = false;
        if (panel) {
            panel.classList.remove('open');
            panel.classList.add('closed');
        }
        if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
    }

    function togglePanel() {
        if (queue.length === 0) return;
        isOpen ? closePanel() : openPanel();
    }

    // ----------------------------------------------------------------
    // ITEM EVENT BINDING
    // ----------------------------------------------------------------
    function bindItemEvents() {
        document.querySelectorAll('#queueItemsContainer .qty-minus').forEach(function (btn) {
            var b = btn.cloneNode(true);
            btn.parentNode.replaceChild(b, btn);
            b.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                var i = parseInt(this.dataset.index, 10);
                var item = queue[i];
                if (!item) return;
                if (item.quantity <= 1) { openRemoveModal(i); return; }
                item.quantity -= 1;
                render();
                scheduleSync();
            });
        });

        document.querySelectorAll('#queueItemsContainer .qty-plus').forEach(function (btn) {
            var b = btn.cloneNode(true);
            btn.parentNode.replaceChild(b, btn);
            b.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                var i = parseInt(this.dataset.index, 10);
                var item = queue[i];
                if (!item) return;
                var max = item.stock || 999;
                if (item.quantity >= max) return;
                item.quantity += 1;
                render();
                scheduleSync();
            });
        });

        document.querySelectorAll('#queueItemsContainer .qty-input').forEach(function (input) {
            var el = input.cloneNode(true);
            input.parentNode.replaceChild(el, input);
            el.addEventListener('change', function () {
                var i = parseInt(this.dataset.index, 10);
                var item = queue[i];
                if (!item) return;
                var v = parseInt(this.value, 10) || 1;
                var max = item.stock || 999;
                if (v < 1) v = 1;
                if (v > max) v = max;
                item.quantity = v;
                this.value = v;
                render();
                scheduleSync();
            });
        });

        document.querySelectorAll('#queueItemsContainer .queue-item-remove').forEach(function (btn) {
            var b = btn.cloneNode(true);
            btn.parentNode.replaceChild(b, btn);
            b.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                openRemoveModal(parseInt(this.dataset.index, 10));
            });
        });
    }

    // ----------------------------------------------------------------
    // MODALS
    // ----------------------------------------------------------------
    function openRemoveModal(index) {
        var item = queue[index];
        if (!item) return;
        pendingRm = index;
        if (removeModalName) removeModalName.textContent = item.name || 'this item';
        if (removeModal) {
            removeModal.classList.add('active');
            removeModal.style.display = 'flex';
        }
    }

    function closeRemoveModal() {
        if (removeModal) {
            removeModal.classList.remove('active');
            removeModal.style.display = 'none';
        }
        pendingRm = null;
    }

    function confirmRemove() {
        if (pendingRm === null) return;
        var item = queue[pendingRm];
        if (item) {
            post({ action: 'remove', line_key: rowKey(item), index: pendingRm })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        queue = data.queue;
                        render();
                    }
                });
        }
        closeRemoveModal();
    }

    function openCancelModal() {
        if (queue.length === 0) return;
        if (cancelModal) {
            cancelModal.classList.add('active');
            cancelModal.style.display = 'flex';
        }
    }

    function closeCancelModal() {
        if (cancelModal) {
            cancelModal.classList.remove('active');
            cancelModal.style.display = 'none';
        }
    }

    function confirmCancelOrder() {
        post({ action: 'clear' }).then(function (data) {
            if (data && data.status === 'success') {
                queue = [];
                render();
            }
        });
        closeCancelModal();
    }

    // ----------------------------------------------------------------
    // PUBLIC API — used by menu.js
    // ----------------------------------------------------------------
    window.addToQueue = function (productId, name, price, quantity, image, stock, restaurantName, branchName) {
        quantity = quantity || 1;

        post({
            action: 'add',
            product_id: productId,
            quantity: quantity
        })
        .then(function (data) {
            if (data && data.status === 'success') {
                queue = data.queue;
                render();
                openPanel();
                showToast((name || 'Item') + ' added to order', 'success');
            } else {
                showToast((data && data.message) || 'Could not add to order', 'error');
            }
        })
        .catch(function () {
            showToast('Network error. Please try again.', 'error');
        });
    };

    // ----------------------------------------------------------------
    // TOAST
    // ----------------------------------------------------------------
    function showToast(message, type) {
        var toast = document.getElementById('queueToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'queueToast';
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

    // ----------------------------------------------------------------
    // EVENT WIRING
    // ----------------------------------------------------------------
    function init() {
        if (initialized) return;
        initialized = true;

        refreshQueue();

        if (panelHeader) {
            panelHeader.addEventListener('click', function (e) {
                if (e.target.closest('button')) return;
                togglePanel();
            });
        }

        if (toggleBtn) {
            toggleBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                togglePanel();
            });
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                openCancelModal();
            });
        }

        if (checkoutBtn) {
            checkoutBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (queue.length === 0) return;
                window.location.href = 'checkout.php';
            });
        }

        if (removeModalCancel) {
            removeModalCancel.addEventListener('click', function (e) {
                e.preventDefault(); closeRemoveModal();
            });
        }
        if (removeModalConfirm) {
            removeModalConfirm.addEventListener('click', function (e) {
                e.preventDefault(); confirmRemove();
            });
        }
        if (cancelModalCancel) {
            cancelModalCancel.addEventListener('click', function (e) {
                e.preventDefault(); closeCancelModal();
            });
        }
        if (cancelModalConfirm) {
            cancelModalConfirm.addEventListener('click', function (e) {
                e.preventDefault(); confirmCancelOrder();
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (removeModal && removeModal.classList.contains('active')) closeRemoveModal();
            if (cancelModal && cancelModal.classList.contains('active')) closeCancelModal();
        });

        [removeModal, cancelModal].forEach(function (modal) {
            if (!modal) return;
            var overlay = modal.querySelector('.queue-modal-overlay');
            if (overlay) {
                overlay.addEventListener('click', function () {
                    if (modal === removeModal) closeRemoveModal();
                    else closeCancelModal();
                });
            }
        });

        console.log('Queue Panel v6.1 initialized');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();