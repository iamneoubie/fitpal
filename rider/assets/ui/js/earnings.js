/**
 * FitPal Rider Earnings Page JavaScript
 *
 * Handles withdrawal modal and form submission.
 *
 * @package FitPal
 * @version 1.0
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var CFG = window.FITPAL_RIDER_EARNINGS || {};
        var CSRF_TOKEN = CFG.csrfToken || '';
        var BALANCE = parseFloat(CFG.balance) || 0;

        // ============================================
        // WITHDRAWAL MODAL
        // ============================================
        var withdrawModal = document.getElementById('withdrawModal');
        var withdrawClose = document.getElementById('withdrawClose');
        var withdrawCancel = document.getElementById('withdrawCancel');
        var withdrawForm = document.getElementById('withdrawForm');
        var withdrawAmount = document.getElementById('withdrawAmount');
        var withdrawError = document.getElementById('withdrawError');
        var withdrawSubmit = document.getElementById('withdrawSubmit');
        var withdrawBtns = [document.getElementById('withdrawBtn'), document.getElementById('withdrawBtnHero')];

        function openWithdrawModal() {
            if (!withdrawModal) return;
            if (BALANCE < 100) {
                showToast('You need at least ₱100.00 to withdraw.', 'error');
                return;
            }

            withdrawModal.style.display = 'flex';
            void withdrawModal.offsetWidth;
            withdrawModal.classList.add('active');
            document.body.style.overflow = 'hidden';

            if (withdrawAmount) {
                withdrawAmount.value = '';
                withdrawAmount.focus();
            }
            if (withdrawError) withdrawError.textContent = '';
        }

        function closeWithdrawModal() {
            if (!withdrawModal) return;

            withdrawModal.classList.remove('active');
            setTimeout(function () {
                if (!withdrawModal.classList.contains('active')) {
                    withdrawModal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }, 200);
        }

        withdrawBtns.forEach(function (btn) {
            if (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    openWithdrawModal();
                });
            }
        });

        if (withdrawClose) withdrawClose.addEventListener('click', closeWithdrawModal);
        if (withdrawCancel) withdrawCancel.addEventListener('click', closeWithdrawModal);

        if (withdrawModal) {
            withdrawModal.addEventListener('click', function (e) {
                if (e.target === withdrawModal || e.target.classList.contains('modal-overlay')) {
                    closeWithdrawModal();
                }
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && withdrawModal && withdrawModal.classList.contains('active')) {
                closeWithdrawModal();
            }
        });

        // ============================================
        // WITHDRAWAL FORM SUBMIT
        // ============================================
        if (withdrawForm) {
            withdrawForm.addEventListener('submit', function (e) {
                e.preventDefault();

                var amount = parseFloat(withdrawAmount.value);
                if (withdrawError) withdrawError.textContent = '';

                if (!amount || amount <= 0) {
                    if (withdrawError) withdrawError.textContent = 'Please enter a valid amount.';
                    return;
                }
                if (amount < 100) {
                    if (withdrawError) withdrawError.textContent = 'Minimum withdrawal is ₱100.00.';
                    return;
                }
                if (amount > BALANCE) {
                    if (withdrawError) withdrawError.textContent = 'Amount exceeds your available balance.';
                    return;
                }

                withdrawSubmit.disabled = true;
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
                            closeWithdrawModal();
                            showToast(data.message || 'Withdrawal request submitted', 'success');
                            setTimeout(function () { window.location.reload(); }, 1200);
                        } else {
                            if (withdrawError) {
                                withdrawError.textContent = (data && data.message) || 'Could not process withdrawal.';
                            }
                            withdrawSubmit.disabled = false;
                            withdrawSubmit.textContent = 'Request Withdrawal';
                        }
                    })
                    .catch(function () {
                        if (withdrawError) withdrawError.textContent = 'Network error. Please try again.';
                        withdrawSubmit.disabled = false;
                        withdrawSubmit.textContent = 'Request Withdrawal';
                    });
            });
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
                error: ['#fee2e2', '#991b1b']
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