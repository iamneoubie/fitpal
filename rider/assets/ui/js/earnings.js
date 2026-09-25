/**
 * FitPal Rider Earnings Page JavaScript
 *
 * Handles:
 *   - Withdrawal modal open / close (class-based, no inline styles)
 *   - Amount input sanitization (digits + one decimal point)
 *   - Quick-amount buttons (fill the input, do not submit)
 *   - Client-side validation before submit
 *   - Submit via rider-handler.php (request_withdrawal)
 *   - Class-based toast for success and error feedback
 *
 * Modal flow
 * ----------
 *   1. User opens the modal from #withdrawBtnHero.
 *   2. User types an amount, or taps a quick-amount button.
 *   3. User clicks Request Withdrawal.
 *   4. Client validates: amount is a number, ≥ ₱100, ≤ balance.
 *      If invalid, the error paragraph gets a message and the
 *      submit does not fire.
 *   5. If valid, POST to rider-handler.php. On success the modal
 *      closes, a toast fires, and the page reloads to refresh the
 *      balance and transaction list.
 *
 * Reads its configuration (CSRF token, balance) from data-*
 * attributes on #riderEarningsPage, matching the customer checkout
 * page pattern. Does not depend on a global window object.
 *
 * No inline CSS anywhere. Toast styling lives in
 * rider-earnings.css under .rider-toast and its variants.
 *
 * @package FitPal
 * @version 3.0 — Class-based toast. Removed all inline cssText.
 *                Removed withdrawModal.style.display writes; the
 *                .active class alone drives visibility, matching
 *                the customer wallet modal. Config still read from
 *                data-* attributes on #riderEarningsPage.
 *
 *                (2.0: modal open/close using shared .modal
 *                selectors. 1.2: reads modal input as text.
 *                1.0: initial.)
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var pageEl = document.getElementById('riderEarningsPage');
        if (!pageEl) return;

        var CSRF_TOKEN = pageEl.dataset.csrfToken || '';
        var BALANCE    = parseFloat(pageEl.dataset.balance) || 0;

        // ============================================
        // DOM REFERENCES
        // ============================================
        var withdrawModal  = document.getElementById('withdrawModal');
        var withdrawClose  = document.getElementById('withdrawClose');
        var withdrawCancel = document.getElementById('withdrawCancel');
        var withdrawSubmit = document.getElementById('withdrawSubmit');
        var withdrawAmount = document.getElementById('withdrawAmount');
        var withdrawError  = document.getElementById('withdrawError');

        var withdrawBtns = [
            document.getElementById('withdrawBtnHero')
        ].filter(Boolean);

        var quickBtns = document.querySelectorAll('.rider-withdraw-quick-btn');

        // ============================================
        // HELPERS
        // ============================================

        function setError(message) {
            if (!withdrawError) return;
            withdrawError.textContent = message || '';
            withdrawError.classList.toggle('visible', !!message);
        }

        // Lock scroll first, then add .active. The shared
        // .modal { display: none } / .modal.active { display: flex }
        // rules handle visibility — no inline display writes.
        function openModal() {
            if (!withdrawModal) return;

            if (BALANCE < 100) {
                showToast('You need at least ₱100.00 to withdraw.', 'error');
                return;
            }

            document.body.style.overflow = 'hidden';
            void withdrawModal.offsetWidth;
            withdrawModal.classList.add('active');

            if (withdrawAmount) {
                withdrawAmount.value = '';
                setTimeout(function () { withdrawAmount.focus(); }, 80);
            }
            setError('');
        }

        // Remove .active first; release scroll lock only after the
        // fade-out completes so the page behind does not jump.
        function closeModal() {
            if (!withdrawModal) return;

            withdrawModal.classList.remove('active');
            setTimeout(function () {
                if (!withdrawModal.classList.contains('active')) {
                    document.body.style.overflow = '';
                }
            }, 250);
        }

        // ============================================
        // OPEN / CLOSE
        // ============================================

        withdrawBtns.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                openModal();
            });
        });

        if (withdrawClose)  withdrawClose.addEventListener('click', closeModal);
        if (withdrawCancel) withdrawCancel.addEventListener('click', closeModal);

        if (withdrawModal) {
            withdrawModal.addEventListener('click', function (e) {
                if (e.target === withdrawModal ||
                    e.target.classList.contains('modal-overlay')) {
                    closeModal();
                }
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' &&
                withdrawModal &&
                withdrawModal.classList.contains('active')) {
                closeModal();
            }
        });

        // ============================================
        // AMOUNT INPUT SANITIZATION
        // ============================================

        if (withdrawAmount) {
            withdrawAmount.addEventListener('input', function () {
                var cleaned = this.value.replace(/[^0-9.]/g, '');
                var parts = cleaned.split('.');
                if (parts.length > 2) {
                    cleaned = parts[0] + '.' + parts.slice(1).join('');
                }
                if (this.value !== cleaned) {
                    this.value = cleaned;
                }
                setError('');
            });

            withdrawAmount.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (withdrawSubmit) withdrawSubmit.click();
                }
            });
        }

        // ============================================
        // QUICK AMOUNTS — fill the input, do not submit
        // ============================================

        quickBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var amount = this.getAttribute('data-amount') || '';
                if (withdrawAmount) {
                    withdrawAmount.value = amount;
                    withdrawAmount.focus();
                }
                setError('');
            });
        });

        // ============================================
        // SUBMIT
        // ============================================

        if (withdrawSubmit) {
            withdrawSubmit.addEventListener('click', function (e) {
                e.preventDefault();
                setError('');

                var raw    = withdrawAmount ? withdrawAmount.value.trim() : '';
                var amount = parseFloat(raw);

                if (!raw || isNaN(amount) || amount <= 0) {
                    setError('Please enter a valid amount.');
                    return;
                }
                if (amount < 100) {
                    setError('Minimum withdrawal is ₱100.00.');
                    return;
                }
                if (amount > BALANCE) {
                    setError('Amount exceeds your available balance.');
                    return;
                }

                withdrawSubmit.disabled    = true;
                withdrawSubmit.textContent = 'Processing…';

                var body = new URLSearchParams();
                body.append('csrf_token', CSRF_TOKEN);
                body.append('action', 'request_withdrawal');
                body.append('amount', amount.toFixed(2));

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
                            closeModal();
                            showToast(data.message || 'Withdrawal request submitted.', 'success');
                            setTimeout(function () {
                                window.location.reload();
                            }, 1200);
                        } else {
                            setError((data && data.message) || 'Could not process withdrawal.');
                            withdrawSubmit.disabled    = false;
                            withdrawSubmit.textContent = 'Request Withdrawal';
                        }
                    })
                    .catch(function () {
                        setError('Network error. Please try again.');
                        withdrawSubmit.disabled    = false;
                        withdrawSubmit.textContent = 'Request Withdrawal';
                    });
            });
        }

        // ============================================
        // TOAST — class-based, no inline styles
        // ============================================

        function showToast(message, type) {
            var toast = document.getElementById('riderToast');

            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'riderToast';
                toast.className = 'rider-toast';
                document.body.appendChild(toast);
            }

            // Reset variant classes so back-to-back toasts never carry
            // a stale color from the previous call.
            toast.classList.remove('success', 'error', 'info', 'is-visible');

            if (type === 'success')      toast.classList.add('success');
            else if (type === 'error')   toast.classList.add('error');
            else                         toast.classList.add('info');

            toast.textContent = message;

            // Force a reflow so the transform transition fires even if
            // the toast was visible a moment ago.
            void toast.offsetWidth;
            toast.classList.add('is-visible');

            clearTimeout(toast._timer);
            toast._timer = setTimeout(function () {
                toast.classList.remove('is-visible');
            }, 2800);
        }
    });
})();