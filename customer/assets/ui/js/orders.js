/**
 * FitPal Customer Orders Page JavaScript
 * Version 5.3 — Scroll lock applied before the modal is shown, and
 *                released after it is hidden. This prevents the page
 *                from reflowing visibly when the body scrollbar
 *                disappears and reappears around the modal fade.
 *
 * @package FitPal
 * @version 5.3
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // CONFIG
        // ============================================
        const CFG = window.FITPAL_ORDERS || {};
        const CSRF_TOKEN = CFG.csrfToken || '';

        // ============================================
        // DOM REFERENCES
        // ============================================
        const filterTabs      = document.querySelectorAll('.filter-tab');
        const orderCards      = document.querySelectorAll('.order-card');
        const noFilterResults = document.getElementById('noFilterResults');

        // Cancel modal
        const cancelOrderModal   = document.getElementById('cancelOrderModal');
        const cancelModalMessage = document.getElementById('cancelModalMessage');
        const cancelModalNo      = document.getElementById('cancelModalNo');
        const cancelModalYes     = document.getElementById('cancelModalYes');

        // Review modal
        const reviewModal         = document.getElementById('reviewModal');
        const closeReviewModal    = document.getElementById('closeReviewModal');
        const cancelReviewBtn     = document.getElementById('cancelReviewBtn');
        const reviewForm          = document.getElementById('reviewForm');
        const reviewOrderId       = document.getElementById('reviewOrderId');
        const reviewProductId     = document.getElementById('reviewProductId');
        const reviewProductName   = document.getElementById('reviewProductName');
        const starRatingContainer = document.getElementById('starRatingContainer');
        const ratingValue         = document.getElementById('ratingValue');
        const ratingError         = document.getElementById('ratingError');
        const submitReviewBtn     = document.getElementById('submitReviewBtn');

        // ============================================
        // STATE
        // ============================================
        let pendingCancelOrderId = null;

        // ============================================
        // BODY SCROLL LOCK
        // ============================================
        function lockBodyScroll() {
            document.body.style.overflow = 'hidden';
        }

        function unlockBodyScroll() {
            document.body.style.overflow = '';
        }

        // ============================================
        // FILTER TABS
        // ============================================
        function applyFilter(filter) {
            let visibleCount = 0;

            orderCards.forEach(function (card) {
                const cardStatus = card.dataset.status;
                const matches = (filter === 'all' || cardStatus === filter);

                card.classList.toggle('hidden', !matches);
                if (matches) visibleCount++;
            });

            filterTabs.forEach(function (tab) {
                tab.classList.toggle('active', tab.dataset.filter === filter);
            });

            if (noFilterResults) {
                noFilterResults.style.display = visibleCount === 0 ? 'block' : 'none';
            }
        }

        filterTabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                applyFilter(this.dataset.filter);
            });
        });

        // ============================================
        // PER-ITEM EXPAND / COLLAPSE
        // ============================================
        document.querySelectorAll('.order-item-summary.is-expandable').forEach(function (summary) {
            function toggle() {
                const row = summary.closest('.order-item-row');
                const detailsId = summary.getAttribute('aria-controls');
                const details = detailsId ? document.getElementById(detailsId) : null;

                if (!details) return;

                const isExpanded = row.classList.contains('expanded');

                if (isExpanded) {
                    row.classList.remove('expanded');
                    summary.setAttribute('aria-expanded', 'false');
                    details.hidden = true;
                } else {
                    row.classList.add('expanded');
                    summary.setAttribute('aria-expanded', 'true');
                    details.hidden = false;
                }
            }

            summary.addEventListener('click', function (e) {
                if (e.target.closest('button, a')) return;
                toggle();
            });

            summary.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggle();
                }
            });
        });

        // ============================================
        // CANCEL ORDER
        //
        // Ordering matters. Lock scroll BEFORE the modal is shown, so
        // the page reflows while the modal is still off-screen. On
        // close, hide the modal first and unlock only after the
        // fade-out completes, so the reverse reflow is not visible.
        // ============================================
        function openCancelModal(orderId, orderStatus) {
            pendingCancelOrderId = orderId;

            if (cancelModalMessage) {
                cancelModalMessage.textContent = orderStatus === 'preparing'
                    ? 'This order is already being prepared. Cancelling may not be possible if the restaurant has started cooking. Do you want to proceed?'
                    : 'Are you sure you want to cancel this order? This action cannot be undone.';
            }

            // 1. Lock while the modal is hidden.
            lockBodyScroll();

            // 2. Show the modal on the now-stable page.
            cancelOrderModal.style.display = 'flex';
            void cancelOrderModal.offsetWidth;
            cancelOrderModal.classList.add('active');
        }

        function closeCancelModal() {
            cancelOrderModal.classList.remove('active');

            setTimeout(function () {
                if (!cancelOrderModal.classList.contains('active')) {
                    cancelOrderModal.style.display = 'none';
                    unlockBodyScroll();
                }
            }, 250);

            pendingCancelOrderId = null;
        }

        document.querySelectorAll('.cancel-order-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const orderId = parseInt(this.dataset.orderId, 10) || 0;
                const orderStatus = this.dataset.orderStatus || 'pending';
                if (orderId > 0) openCancelModal(orderId, orderStatus);
            });
        });

        if (cancelModalNo) {
            cancelModalNo.addEventListener('click', closeCancelModal);
        }

        if (cancelModalYes) {
            cancelModalYes.addEventListener('click', function () {
                if (!pendingCancelOrderId) return;

                cancelModalYes.disabled = true;
                cancelModalYes.textContent = 'Cancelling...';

                const orderIdAtClick = pendingCancelOrderId;

                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('action', 'cancel_order');
                formData.append('order_id', orderIdAtClick);

                fetch('../backend/handlers/order-handler.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        showToast(data.message || 'Order cancelled successfully', 'success');

                        const finalStatus = data.order_status
                            || (data.refunded ? 'refunded' : 'cancelled');

                        const card = document.querySelector(
                            '.order-card[data-order-id="' + orderIdAtClick + '"]'
                        );
                        if (card) {
                            card.dataset.status = finalStatus;

                            const badge = card.querySelector('.badge');
                            if (badge) {
                                if (finalStatus === 'refunded') {
                                    badge.className = 'badge badge-secondary';
                                    badge.textContent = 'Refunded';
                                } else {
                                    badge.className = 'badge badge-danger';
                                    badge.textContent = 'Cancelled';
                                }
                            }

                            const cancelBtn = card.querySelector('.cancel-order-btn');
                            if (cancelBtn) cancelBtn.remove();

                            const trackBtn = card.querySelector('a[href*="order-tracking"]');
                            if (trackBtn) trackBtn.remove();
                        }

                        closeCancelModal();
                        setTimeout(function () { window.location.reload(); }, 1500);
                    } else {
                        closeCancelModal();
                        showToast((data && data.message) || 'Could not cancel order', 'error');
                    }
                })
                .catch(function (err) {
                    console.error('Cancel order error:', err);
                    closeCancelModal();
                    showToast('Network error. Please try again.', 'error');
                })
                .finally(function () {
                    cancelModalYes.disabled = false;
                    cancelModalYes.textContent = 'Cancel Order';
                });
            });
        }

        if (cancelOrderModal) {
            const overlay = cancelOrderModal.querySelector('.modal-overlay');
            if (overlay) overlay.addEventListener('click', closeCancelModal);
        }

        // ============================================
        // REORDER
        // ============================================
        function handleReorder(btn) {
            const orderId = parseInt(btn.dataset.orderId, 10) || 0;
            if (orderId <= 0) return;

            const originalHTML = btn.innerHTML;
            btn.disabled = true;
            btn.classList.add('loading');
            btn.innerHTML = '<span>Adding…</span>';

            const body = new URLSearchParams();
            body.append('csrf_token', CSRF_TOKEN);
            body.append('action', 'reorder');
            body.append('order_id', String(orderId));

            fetch('../backend/handlers/order-handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString(),
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && (data.status === 'success' || data.status === 'partial')) {
                    showReorderToast(data);

                    const delay = (data.status === 'partial') ? 1600 : 700;
                    const redirect = data.redirect || 'menu.php';

                    setTimeout(function () {
                        window.location.href = redirect;
                    }, delay);

                    return;
                }

                showReorderToast(data || {
                    status: 'error',
                    message: 'Could not re-order.'
                });
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.innerHTML = originalHTML;
            })
            .catch(function (err) {
                console.error('Reorder failed:', err);
                showToast('Network error. Please try again.', 'error');
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.innerHTML = originalHTML;
            });
        }

        document.querySelectorAll('.reorder-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                handleReorder(this);
            });
        });

        // ============================================
        // REVIEW MODAL
        //
        // Same ordering rule as the cancel modal. See above.
        // ============================================
        function openReviewModal(orderId, productId, productName) {
            reviewOrderId.value   = orderId;
            reviewProductId.value = productId;

            if (reviewProductName) {
                reviewProductName.textContent = productName || '';
            }

            resetReviewForm();

            // 1. Lock while the modal is hidden.
            lockBodyScroll();

            // 2. Show the modal on the now-stable page.
            reviewModal.style.display = 'flex';
            void reviewModal.offsetWidth;
            reviewModal.classList.add('active');
        }

        function closeReviewModalHandler() {
            reviewModal.classList.remove('active');

            setTimeout(function () {
                if (!reviewModal.classList.contains('active')) {
                    reviewModal.style.display = 'none';
                    unlockBodyScroll();
                }
            }, 250);
        }

        function resetReviewForm() {
            document.querySelectorAll('.star').forEach(function (s) {
                s.classList.remove('active');
                s.style.color = '';
            });
            ratingValue.value = '';
            ratingError.textContent = '';
            const comment = document.getElementById('comment');
            if (comment) comment.value = '';
        }

        document.querySelectorAll('.review-item-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                const orderId     = parseInt(this.dataset.orderId, 10) || 0;
                const productId   = parseInt(this.dataset.productId, 10) || 0;
                const productName = this.dataset.productName || '';
                if (orderId > 0 && productId > 0) {
                    openReviewModal(orderId, productId, productName);
                }
            });
        });

        if (closeReviewModal) closeReviewModal.addEventListener('click', closeReviewModalHandler);
        if (cancelReviewBtn)  cancelReviewBtn.addEventListener('click', closeReviewModalHandler);

        if (reviewModal) {
            const overlay = reviewModal.querySelector('.modal-overlay');
            if (overlay) overlay.addEventListener('click', closeReviewModalHandler);
        }

        // Star rating
        if (starRatingContainer) {
            const stars = starRatingContainer.querySelectorAll('.star');

            stars.forEach(function (star) {
                star.addEventListener('click', function () {
                    const value = parseInt(this.dataset.value, 10);
                    ratingValue.value = value;
                    stars.forEach(function (s) {
                        s.classList.toggle('active', parseInt(s.dataset.value, 10) <= value);
                    });
                    ratingError.textContent = '';
                });

                star.addEventListener('mouseenter', function () {
                    const value = parseInt(this.dataset.value, 10);
                    stars.forEach(function (s) {
                        s.style.color = parseInt(s.dataset.value, 10) <= value ? '#ffc107' : '';
                    });
                });

                star.addEventListener('mouseleave', function () {
                    stars.forEach(function (s) { s.style.color = ''; });
                });
            });
        }

        // Review submission
        if (reviewForm) {
            reviewForm.addEventListener('submit', function (e) {
                e.preventDefault();

                if (!ratingValue.value) {
                    ratingError.textContent = 'Please select a rating';
                    return;
                }

                submitReviewBtn.disabled = true;
                submitReviewBtn.textContent = 'Submitting...';

                fetch(reviewForm.action, {
                    method: 'POST',
                    body: new FormData(reviewForm),
                    credentials: 'same-origin'
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        closeReviewModalHandler();
                        showToast('Review submitted successfully!', 'success');

                        const btn = document.querySelector(
                            '.review-item-btn[data-order-id="' + reviewOrderId.value +
                            '"][data-product-id="' + reviewProductId.value + '"]'
                        );
                        if (btn) {
                            const wrapper = btn.closest('.order-item-details-actions');
                            if (wrapper) {
                                wrapper.innerHTML = '<span class="reviewed-badge">✓ Reviewed</span>';
                            }
                        }
                    } else {
                        showToast((data && data.message) || 'Could not submit review', 'error');
                    }
                })
                .catch(function () {
                    showToast('Network error. Please try again.', 'error');
                })
                .finally(function () {
                    submitReviewBtn.disabled = false;
                    submitReviewBtn.textContent = 'Submit Review';
                });
            });
        }

        // ============================================
        // REORDER TOAST
        // ============================================
        function showReorderToast(data) {
            let toast = document.getElementById('reorderToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'reorderToast';
                toast.style.cssText = [
                    'position:fixed', 'top:80px', 'right:20px',
                    'padding:14px 18px', 'border-radius:8px',
                    'font-size:14px', 'font-weight:500', 'z-index:9999',
                    'max-width:380px', 'box-shadow:0 4px 16px rgba(0,0,0,.15)',
                    'transform:translateX(120%)',
                    'transition:transform .3s cubic-bezier(.4,0,.2,1)'
                ].join(';');
                document.body.appendChild(toast);
            }

            const palette = {
                success: ['#d1fae5', '#065f46'],
                partial: ['#fef3c7', '#92400e'],
                error:   ['#fee2e2', '#991b1b']
            };
            const colors = palette[data.status] || palette.error;
            toast.style.background = colors[0];
            toast.style.color      = colors[1];

            let html = '<div>' + escapeHtml(data.message || '') + '</div>';

            if (Array.isArray(data.skipped) && data.skipped.length > 0) {
                html += '<ul style="margin:8px 0 0 16px;padding:0;font-size:12px;font-weight:400;">';
                data.skipped.forEach(function (s) {
                    html += '<li>' + escapeHtml(s.name) + ' — ' + escapeHtml(s.reason) + '</li>';
                });
                html += '</ul>';
            }

            toast.innerHTML = html;

            void toast.offsetWidth;
            toast.style.transform = 'translateX(0)';

            clearTimeout(toast._timer);
            const dismissMs = (data.status === 'partial') ? 4000 : 3000;
            toast._timer = setTimeout(function () {
                toast.style.transform = 'translateX(120%)';
            }, dismissMs);
        }

        function escapeHtml(text) {
            const d = document.createElement('div');
            d.textContent = String(text == null ? '' : text);
            return d.innerHTML;
        }

        // ============================================
        // TOAST
        // ============================================
        function showToast(message, type) {
            let toast = document.getElementById('ordersToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'ordersToast';
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

            const palette = {
                success: ['#d1fae5', '#065f46'],
                error:   ['#fee2e2', '#991b1b'],
                warning: ['#fef3c7', '#92400e'],
                info:    ['#dbeafe', '#1e40af']
            };
            const colors = palette[type] || palette.info;
            toast.style.background = colors[0];
            toast.style.color      = colors[1];
            toast.textContent      = message;

            void toast.offsetWidth;
            toast.style.transform = 'translateX(0)';

            clearTimeout(toast._timer);
            toast._timer = setTimeout(function () {
                toast.style.transform = 'translateX(120%)';
            }, 3000);
        }

        // ============================================
        // KEYBOARD
        // ============================================
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (cancelOrderModal && cancelOrderModal.classList.contains('active')) closeCancelModal();
            if (reviewModal && reviewModal.classList.contains('active')) closeReviewModalHandler();
        });

    });
})();