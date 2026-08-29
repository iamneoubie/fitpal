/**
 * FitPal Customer Queue Panel JavaScript
 * 
 * Manages the persistent queue panel with real-time updates,
 * item editing, and modal-confirmed removal.
 * 
 * @package FitPal
 * @version 2.3 - Fixed cancel order modal binding
 */

(function() {
    'use strict';

    // ============================================
    // DOM REFERENCES
    // ============================================
    var queuePanel = document.getElementById('queuePanel');
    var queueItemsContainer = document.getElementById('queueItemsContainer');
    var queueToggle = document.getElementById('queuePanelToggle');
    var panelHeader = document.getElementById('queuePanelHeader');
    var itemCountBadge = document.getElementById('queueItemCount');
    var grandTotalEl = document.getElementById('queueGrandTotal');
    var itemCountLabelEl = document.getElementById('queueItemCountLabel');
    var footerItemCountEl = document.getElementById('queueFooterItemCount');
    var checkoutBtn = document.getElementById('queueCheckoutBtn');
    var cancelBtn = document.getElementById('queueCancelBtn');
    var queueEmpty = document.getElementById('queueEmptyState');

    // Remove Item Modal
    var removeModal = document.getElementById('queueRemoveModal');
    var removeModalClose = document.getElementById('queueModalClose');
    var removeModalCancel = document.getElementById('queueModalCancel');
    var removeModalConfirm = document.getElementById('queueModalConfirm');
    var removeModalItemName = document.getElementById('queueModalItemName');

    // Cancel Order Modal
    var cancelModal = document.getElementById('queueCancelModal');
    var cancelModalClose = document.getElementById('queueCancelModalClose');
    var cancelModalCancel = document.getElementById('queueCancelModalCancel');
    var cancelModalConfirm = document.getElementById('queueCancelModalConfirm');
    var cancelItemCount = document.getElementById('queueCancelItemCount');

    var isOpen = false;
    var currentQueue = [];
    var pendingRemoveIndex = -1;

    // ============================================
    // API HELPERS
    // ============================================
    function getQueue() {
        var stored = sessionStorage.getItem('fitpal_queue');
        if (stored) {
            try {
                return JSON.parse(stored);
            } catch (e) {
                return [];
            }
        }
        return [];
    }

    function saveQueue(queue) {
        sessionStorage.setItem('fitpal_queue', JSON.stringify(queue));
        currentQueue = queue;
        renderQueue();
        updateCheckoutButton();
    }

    function updateCheckoutButton() {
        var totalItems = currentQueue.reduce(function(sum, item) {
            return sum + (item.quantity || 0);
        }, 0);
        
        if (checkoutBtn) {
            checkoutBtn.disabled = totalItems === 0;
        }
        if (cancelBtn) {
            cancelBtn.disabled = totalItems === 0;
        }
    }

    function loadQueueFromServer() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '../backend/handlers/get-queue-handler.php', true);
        xhr.withCredentials = true;
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.status === 'success') {
                            var queue = data.queue || [];
                            var localQueue = getQueue();
                            var merged = mergeQueues(localQueue, queue);
                            saveQueue(merged);
                        } else {
                            var local = getQueue();
                            if (local.length > 0) {
                                saveQueue(local);
                            } else {
                                renderQueue();
                            }
                        }
                    } catch (e) {
                        console.warn('Queue parse error:', e);
                        var local = getQueue();
                        if (local.length > 0) {
                            saveQueue(local);
                        } else {
                            renderQueue();
                        }
                    }
                } else {
                    var local = getQueue();
                    if (local.length > 0) {
                        saveQueue(local);
                    } else {
                        renderQueue();
                    }
                }
            }
        };
        xhr.send();
    }

    function mergeQueues(local, server) {
        var merged = [];
        var serverIds = {};

        server.forEach(function(item) {
            serverIds[item.product_id] = true;
            merged.push(item);
        });

        local.forEach(function(item) {
            if (!serverIds[item.product_id]) {
                merged.push(item);
            }
        });

        return merged;
    }

    // ============================================
    // RENDER QUEUE
    // ============================================
    function renderQueue() {
        var queue = currentQueue;
        var totalItems = 0;
        var totalPrice = 0;

        queue.forEach(function(item) {
            var qty = item.quantity || 0;
            totalItems += qty;
            totalPrice += (item.price || 0) * qty;
        });

        // Update badge
        if (itemCountBadge) {
            if (totalItems > 0) {
                itemCountBadge.textContent = totalItems;
                itemCountBadge.style.display = 'inline-flex';
            } else {
                itemCountBadge.style.display = 'none';
            }
        }

        // Update total in footer
        var totalFormatted = '₱' + totalPrice.toFixed(2);
        if (grandTotalEl) grandTotalEl.textContent = totalFormatted;

        // Update item count labels
        var itemLabel = totalItems + ' item' + (totalItems !== 1 ? 's' : '');
        if (itemCountLabelEl) itemCountLabelEl.textContent = itemLabel;
        if (footerItemCountEl) footerItemCountEl.textContent = itemLabel;

        // Show/hide empty state
        if (queueEmpty) {
            queueEmpty.style.display = totalItems === 0 ? 'block' : 'none';
        }

        // Render items
        if (queueItemsContainer) {
            if (totalItems === 0) {
                queueItemsContainer.innerHTML = '';
                if (queueEmpty) queueEmpty.style.display = 'block';
                return;
            }

            var html = '';
            queue.forEach(function(item, index) {
                var itemTotal = (item.price || 0) * (item.quantity || 0);
                var imageSrc = item.image || '../assets/images/icons/restaurant.svg';

                html += `
                    <div class="queue-item" data-index="${index}" data-product-id="${item.product_id}">
                        <div class="queue-item-image">
                            <img src="${imageSrc}" alt="${escapeHtml(item.name || 'Product')}" 
                                onerror="this.onerror=null; this.src='../assets/images/icons/restaurant.svg'">
                        </div>
                        <div class="queue-item-info">
                            <p class="queue-item-name">${escapeHtml(item.name || 'Product')}</p>
                            <div class="queue-item-meta">
                                <span class="queue-item-price">₱${(item.price || 0).toFixed(2)}</span>
                                ${item.restaurant_name ? `<span class="queue-item-restaurant">• ${escapeHtml(item.restaurant_name)}</span>` : ''}
                            </div>
                        </div>
                        <div class="queue-item-actions">
                            <div class="queue-item-qty">
                                <button type="button" class="qty-btn qty-minus" data-index="${index}" 
                                    aria-label="Decrease quantity">−</button>
                                <input type="number" class="qty-input" value="${item.quantity || 1}" 
                                    min="0" max="${item.stock || 999}" data-index="${index}">
                                <button type="button" class="qty-btn qty-plus" data-index="${index}" 
                                    aria-label="Increase quantity">+</button>
                            </div>
                            <button type="button" class="queue-item-remove" data-index="${index}" 
                                aria-label="Remove item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" 
                                    stroke="currentColor" stroke-width="2">
                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                            </button>
                            <span class="queue-item-total">₱${itemTotal.toFixed(2)}</span>
                        </div>
                    </div>
                `;
            });

            queueItemsContainer.innerHTML = html;
            bindItemEvents();
        }

        // Auto-open panel if items > 0
        if (totalItems > 0 && !isOpen) {
            openPanel();
        }

        updateCheckoutButton();
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ============================================
    // BIND ITEM EVENTS
    // ============================================
    function bindItemEvents() {
        // Quantity minus buttons
        document.querySelectorAll('.qty-minus').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var index = parseInt(this.dataset.index, 10);
                var input = this.parentElement.querySelector('.qty-input');
                if (input) {
                    var val = parseInt(input.value, 10) || 1;
                    var newVal = val - 1;
                    
                    if (newVal === 0) {
                        showRemoveModal(index);
                        return;
                    }
                    
                    if (newVal > 0) {
                        input.value = newVal;
                        updateItemQuantity(index, newVal);
                    }
                }
            });
        });

        // Quantity plus buttons
        document.querySelectorAll('.qty-plus').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var index = parseInt(this.dataset.index, 10);
                var input = this.parentElement.querySelector('.qty-input');
                if (input) {
                    var val = parseInt(input.value, 10) || 1;
                    var max = parseInt(input.max, 10) || 999;
                    if (val < max) {
                        input.value = val + 1;
                        updateItemQuantity(index, val + 1);
                    }
                }
            });
        });

        // Quantity input changes
        document.querySelectorAll('.qty-input').forEach(function(input) {
            input.addEventListener('change', function() {
                var index = parseInt(this.dataset.index, 10);
                var val = parseInt(this.value, 10) || 1;
                var max = parseInt(this.max, 10) || 999;
                
                if (val < 0) val = 0;
                if (val > max) val = max;
                
                if (val === 0) {
                    showRemoveModal(index);
                    return;
                }
                
                this.value = val;
                updateItemQuantity(index, val);
            });

            input.addEventListener('blur', function() {
                var val = parseInt(this.value, 10) || 1;
                if (val < 1) val = 1;
                this.value = val;
            });
        });

        // Remove buttons - show modal
        document.querySelectorAll('.queue-item-remove').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var index = parseInt(this.dataset.index, 10);
                showRemoveModal(index);
            });
        });

        // Keyboard shortcut: Enter on quantity input
        document.querySelectorAll('.qty-input').forEach(function(input) {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.blur();
                }
            });
        });
    }

    // ============================================
    // REMOVE ITEM MODAL
    // ============================================
    function showRemoveModal(index) {
        if (index < 0 || index >= currentQueue.length) return;
        
        var item = currentQueue[index];
        if (!item) return;
        
        pendingRemoveIndex = index;
        removeModalItemName.textContent = item.name || 'this item';
        removeModal.classList.add('active');
        removeModal.style.display = 'flex';
    }

    function closeRemoveModal() {
        removeModal.classList.remove('active');
        removeModal.style.display = 'none';
        pendingRemoveIndex = -1;
    }

    function confirmRemove() {
        if (pendingRemoveIndex >= 0 && pendingRemoveIndex < currentQueue.length) {
            currentQueue.splice(pendingRemoveIndex, 1);
            saveQueue(currentQueue);
            syncWithServer();
            
            if (currentQueue.length === 0) {
                closePanel();
            }
        }
        closeRemoveModal();
    }

    // ============================================
    // CANCEL ORDER MODAL
    // ============================================
    function showCancelModal() {
        var totalItems = currentQueue.reduce(function(sum, item) {
            return sum + (item.quantity || 0);
        }, 0);
        
        if (totalItems === 0) return;
        
        if (cancelItemCount) {
            cancelItemCount.textContent = totalItems;
        }
        cancelModal.classList.add('active');
        cancelModal.style.display = 'flex';
    }

    function closeCancelModal() {
        cancelModal.classList.remove('active');
        cancelModal.style.display = 'none';
    }

    function confirmCancelOrder() {
        if (currentQueue.length > 0) {
            currentQueue = [];
            saveQueue(currentQueue);
            syncWithServer();
            closePanel();
        }
        closeCancelModal();
    }

    // ============================================
    // QUEUE OPERATIONS
    // ============================================
    function updateItemQuantity(index, quantity) {
        if (index < 0 || index >= currentQueue.length) return;

        var item = currentQueue[index];
        if (!item) return;

        if (quantity < 1) {
            quantity = 1;
        }

        if (item.stock && quantity > item.stock) {
            quantity = item.stock;
        }

        item.quantity = quantity;

        var inputs = document.querySelectorAll('.qty-input');
        if (inputs[index]) {
            inputs[index].value = quantity;
        }

        saveQueue(currentQueue);
        syncWithServer();
    }

    function syncWithServer() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '../backend/handlers/sync-queue-handler.php', true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.withCredentials = true;
        xhr.send(JSON.stringify({
            queue: currentQueue,
            csrf_token: document.querySelector('input[name="csrf_token"]')?.value || ''
        }));
    }

    // ============================================
    // PANEL CONTROLS
    // ============================================
    function openPanel() {
        isOpen = true;
        queuePanel.classList.remove('closed');
        queuePanel.classList.add('open');
        if (queueToggle) {
            queueToggle.setAttribute('aria-expanded', 'true');
        }
    }

    function closePanel() {
        isOpen = false;
        queuePanel.classList.remove('open');
        queuePanel.classList.add('closed');
        if (queueToggle) {
            queueToggle.setAttribute('aria-expanded', 'false');
        }
    }

    function togglePanel() {
        if (isOpen) {
            closePanel();
        } else {
            openPanel();
        }
    }

    // ============================================
    // ADD ITEM TO QUEUE
    // ============================================
    window.addToQueue = function(productId, name, price, quantity, image, stock, restaurantName, branchName) {
        quantity = quantity || 1;

        var existingIndex = -1;
        currentQueue.forEach(function(item, index) {
            if (item.product_id === productId) {
                existingIndex = index;
            }
        });

        if (existingIndex >= 0) {
            var newQty = (currentQueue[existingIndex].quantity || 0) + quantity;
            var maxStock = currentQueue[existingIndex].stock || 999;
            if (newQty > maxStock) {
                newQty = maxStock;
                showToast('Stock limit reached for this item.', 'warning');
            }
            updateItemQuantity(existingIndex, newQty);
        } else {
            var newItem = {
                product_id: productId,
                name: name,
                price: price,
                quantity: quantity,
                image: image || '',
                stock: stock || 999,
                restaurant_name: restaurantName || '',
                branch_name: branchName || ''
            };
            currentQueue.push(newItem);
            saveQueue(currentQueue);
            syncWithServer();
            openPanel();
        }

        // Visual feedback
        queuePanel.style.borderColor = 'var(--accent)';
        setTimeout(function() {
            queuePanel.style.borderColor = '';
        }, 500);
    };

    // ============================================
    // TOAST NOTIFICATION
    // ============================================
    function showToast(message, type) {
        var toast = document.getElementById('queueToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'queueToast';
            toast.style.cssText = `
                position: fixed;
                top: 80px;
                right: 20px;
                padding: 12px 20px;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 500;
                z-index: 9999;
                transform: translateX(120%);
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                max-width: 360px;
                box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            `;
            document.body.appendChild(toast);
        }

        var colors = {
            success: '#d1fae5',
            error: '#fee2e2',
            warning: '#fef3c7',
            info: '#dbeafe'
        };
        var textColors = {
            success: '#065f46',
            error: '#991b1b',
            warning: '#92400e',
            info: '#1e40af'
        };

        toast.style.background = colors[type] || colors.info;
        toast.style.color = textColors[type] || textColors.info;
        toast.textContent = message;

        toast.style.transform = 'translateX(0)';

        clearTimeout(toast._timeout);
        toast._timeout = setTimeout(function() {
            toast.style.transform = 'translateX(120%)';
        }, 3000);
    }

    // ============================================
    // EVENT BINDING
    // ============================================
    function init() {
        loadQueueFromServer();

        // Panel toggle
        if (panelHeader) {
            panelHeader.addEventListener('click', function(e) {
                if (e.target.closest('button')) return;
                togglePanel();
            });
        }

        if (queueToggle) {
            queueToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                togglePanel();
            });
        }

        // Cancel order button - shows modal
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                showCancelModal();
            });
        }

        // Checkout button
        if (checkoutBtn) {
            checkoutBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (currentQueue.length > 0) {
                    window.location.href = 'checkout.php';
                }
            });
        }

        // ============================================
        // REMOVE ITEM MODAL BINDING
        // ============================================
        if (removeModalClose) {
            removeModalClose.addEventListener('click', closeRemoveModal);
        }
        if (removeModalCancel) {
            removeModalCancel.addEventListener('click', closeRemoveModal);
        }
        if (removeModalConfirm) {
            removeModalConfirm.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                confirmRemove();
            });
        }
        var removeOverlay = removeModal ? removeModal.querySelector('.queue-modal-overlay') : null;
        if (removeOverlay) {
            removeOverlay.addEventListener('click', closeRemoveModal);
        }

        // ============================================
        // CANCEL ORDER MODAL BINDING - FIXED
        // ============================================
        if (cancelModalClose) {
            cancelModalClose.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                closeCancelModal();
            });
        }
        if (cancelModalCancel) {
            cancelModalCancel.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                closeCancelModal();
            });
        }
        if (cancelModalConfirm) {
            cancelModalConfirm.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                confirmCancelOrder();
            });
        }
        var cancelOverlay = cancelModal ? cancelModal.querySelector('.queue-modal-overlay') : null;
        if (cancelOverlay) {
            cancelOverlay.addEventListener('click', function(e) {
                closeCancelModal();
            });
        }

        // Close modals on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (removeModal && removeModal.classList.contains('active')) {
                    closeRemoveModal();
                }
                if (cancelModal && cancelModal.classList.contains('active')) {
                    closeCancelModal();
                }
            }
        });

        // Listen for add-to-cart form submissions
        document.addEventListener('submit', function(e) {
            var form = e.target.closest('.add-to-cart-form');
            if (form) {
                var productId = form.querySelector('input[name="product_id"]')?.value;
                var quantity = form.querySelector('input[name="quantity"]')?.value;
                var productCard = form.closest('.product-card');

                if (productId && productCard) {
                    e.preventDefault();

                    var name = productCard.dataset.productName || 'Product';
                    var price = parseFloat(productCard.dataset.productPrice) || 0;
                    var stock = parseInt(productCard.dataset.productStock) || 999;
                    var restaurantName = productCard.dataset.restaurantName || '';
                    var image = productCard.querySelector('.product-image img')?.src || '';

                    window.addToQueue(
                        parseInt(productId, 10),
                        name,
                        price,
                        parseInt(quantity, 10) || 1,
                        image,
                        stock,
                        restaurantName
                    );

                    showToast(name + ' added to queue!', 'success');
                }
            }
        });

        // Save queue on page unload
        window.addEventListener('beforeunload', function() {
            saveQueue(currentQueue);
        });

        console.log('Queue Panel v2.3 - Fixed cancel modal');
    }

    // ============================================
    // INITIALIZE
    // ============================================
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();