/**
 * FitPal Customer Wallet JavaScript
 * Version 1.1
 *
 * Handles:
 *   - Recharge amount modal (validation, quick amounts)
 *   - QR payment modal (initiate → confirm, or cancel)
 *   - Success modal (new balance display)
 *   - Back button navigation
 *   - Pending-recharge cleanup on any QR dismissal
 *
 * @package FitPal
 * @version 1.1
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        var CFG = window.FITPAL_WALLET || {};
        var CSRF_TOKEN   = CFG.csrfToken || '';
        var MIN_RECHARGE = Number(CFG.minRecharge) || 50;
        var MAX_RECHARGE = Number(CFG.maxRecharge) || 50000;

        // ============================================
        // DOM REFERENCES
        // ============================================
        var rechargeBtn            = document.getElementById('rechargeBtn');
        var amountModal            = document.getElementById('rechargeAmountModal');
        var closeAmountModal       = document.getElementById('closeRechargeAmountModal');
        var cancelAmountModal      = document.getElementById('cancelRechargeAmountModal');
        var proceedToQrBtn         = document.getElementById('proceedToQrBtn');
        var amountInput            = document.getElementById('rechargeAmountInput');
        var amountError            = document.getElementById('rechargeAmountError');
        var quickAmountBtns        = document.querySelectorAll('.quick-amount-btn');

        var qrModal                = document.getElementById('rechargeQrModal');
        var closeQrModal           = document.getElementById('closeRechargeQrModal');
        var cancelQrModal          = document.getElementById('cancelRechargeQrModal');
        var confirmQrBtn           = document.getElementById('confirmRechargeQrBtn');
        var qrAmountEl             = document.getElementById('qrRechargeAmount');

        var successModal           = document.getElementById('rechargeSuccessModal');
        var closeSuccessModal      = document.getElementById('closeRechargeSuccessModal');
        var successAmountEl        = document.getElementById('rechargeSuccessAmount');
        var successBalanceEl       = document.getElementById('rechargeSuccessBalance');
        var doneBtn                = document.getElementById('rechargeDoneBtn');

        var balanceEl              = document.getElementById('walletBalanceAmount');
        var backBtn                = document.getElementById('walletBackBtn');

        // ============================================
        // STATE
        // ============================================
        var pendingAmount          = 0;
        var pendingTransactionId   = null;
        var isAmountModalOpen      = false;
        var isQrModalOpen          = false;
        var isSuccessModalOpen     = false;

        // Guards against the cancel path firing after a successful
        // confirmation, which would try to delete an already-completed
        // transaction. Set true right before confirm_qr succeeds.
        var pendingWasConfirmed    = false;

        // ============================================
        // HELPERS
        // ============================================
        function formatPeso(value) {
            var n = Number(value) || 0;
            return '₱' + n.toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function openModal(modal) {
            if (!modal) return;
            modal.style.display = 'flex';
            void modal.offsetWidth;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modal) {
            if (!modal) return;
            modal.classList.remove('active');
            document.body.style.overflow = '';
            setTimeout(function() {
                if (!modal.classList.contains('active')) {
                    modal.style.display = 'none';
                }
            }, 250);
        }

        function setAmountError(msg) {
            if (!amountError) return;
            amountError.textContent = msg || '';
            amountError.classList.toggle('visible', !!msg);
        }

        function validAmount(raw) {
            var n = Number(raw);
            if (!isFinite(n) || isNaN(n)) {
                return { ok: false, message: 'Please enter a valid amount.' };
            }
            if (n < MIN_RECHARGE) {
                return { ok: false, message: 'Minimum recharge is ' + formatPeso(MIN_RECHARGE) + '.' };
            }
            if (n > MAX_RECHARGE) {
                return { ok: false, message: 'Maximum recharge is ' + formatPeso(MAX_RECHARGE) + '.' };
            }
            return { ok: true, value: Math.round(n * 100) / 100 };
        }

        // ============================================
        // BACK BUTTON
        // ============================================
        if (backBtn) {
            backBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var fallback = this.getAttribute('data-fallback-href') || '';
                if (fallback) {
                    window.location.href = fallback;
                    return;
                }
                if (window.history.length > 1 && document.referrer !== '') {
                    window.history.back();
                } else {
                    window.location.href = 'dashboard.php';
                }
            });
        }

        // ============================================
        // PENDING RECHARGE CLEANUP
        //
        // Called whenever the QR modal closes without a successful
        // confirmation. Fire-and-forget; the UI doesn't wait.
        // ============================================
        function cancelPendingQr() {
            if (!pendingTransactionId) return;
            if (pendingWasConfirmed) return;

            var txnId = pendingTransactionId;

            // Clear local state immediately so a second close doesn't
            // fire a duplicate cancel for the same ID.
            pendingTransactionId = null;
            pendingAmount        = 0;

            var body = new URLSearchParams();
            body.append('csrf_token', CSRF_TOKEN);
            body.append('action', 'cancel_qr');
            body.append('transaction_id', String(txnId));

            fetch('../backend/handlers/wallet-handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin'
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data || data.status !== 'success') {
                    console.warn('[wallet] Could not cancel pending recharge:', data);
                }
            })
            .catch(function(err) {
                console.warn('[wallet] Could not cancel pending recharge:', err);
            });
        }

        // ============================================
        // AMOUNT MODAL
        // ============================================
        function openAmountModal() {
            if (!amountModal || isAmountModalOpen) return;
            amountInput.value = '';
            setAmountError('');
            openModal(amountModal);
            isAmountModalOpen = true;
            setTimeout(function() { amountInput.focus(); }, 100);
        }

        function closeAmountModalHandler() {
            if (!amountModal || !isAmountModalOpen) return;
            closeModal(amountModal);
            isAmountModalOpen = false;
        }

        if (rechargeBtn) {
            rechargeBtn.addEventListener('click', openAmountModal);
        }
        if (closeAmountModal) {
            closeAmountModal.addEventListener('click', closeAmountModalHandler);
        }
        if (cancelAmountModal) {
            cancelAmountModal.addEventListener('click', closeAmountModalHandler);
        }
        if (amountModal) {
            amountModal.addEventListener('click', function(e) {
                if (e.target === amountModal || e.target.classList.contains('modal-overlay')) {
                    closeAmountModalHandler();
                }
            });
        }

        if (amountInput) {
            amountInput.addEventListener('input', function() {
                var cleaned = this.value.replace(/[^0-9.]/g, '');
                var parts   = cleaned.split('.');
                if (parts.length > 2) {
                    cleaned = parts[0] + '.' + parts.slice(1).join('');
                }
                if (this.value !== cleaned) {
                    this.value = cleaned;
                }
                setAmountError('');
            });
            amountInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    proceedToQrBtn.click();
                }
            });
        }

        quickAmountBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var amount = this.getAttribute('data-amount') || '';
                if (amountInput) {
                    amountInput.value = amount;
                    setAmountError('');
                    amountInput.focus();
                }
            });
        });

        // ============================================
        // QR MODAL
        // ============================================
        function openQrModal() {
            if (!qrModal || isQrModalOpen) return;
            if (qrAmountEl) qrAmountEl.textContent = formatPeso(pendingAmount);
            openModal(qrModal);
            isQrModalOpen = true;
        }

        /**
         * Close the QR modal.
         *
         * @param {boolean} [skipCancel=false]  When true, do not fire
         *   the server-side cancel. Used after a successful confirm,
         *   where the transaction has already moved to completed.
         */
        function closeQrModalHandler(skipCancel) {
            if (!qrModal || !isQrModalOpen) return;

            closeModal(qrModal);
            isQrModalOpen = false;

            if (!skipCancel) {
                cancelPendingQr();
            }
        }

        if (closeQrModal) {
            closeQrModal.addEventListener('click', function() {
                closeQrModalHandler(false);
            });
        }
        if (cancelQrModal) {
            cancelQrModal.addEventListener('click', function() {
                closeQrModalHandler(false);
            });
        }
        if (qrModal) {
            qrModal.addEventListener('click', function(e) {
                if (e.target === qrModal || e.target.classList.contains('modal-overlay')) {
                    closeQrModalHandler(false);
                }
            });
        }

        // ============================================
        // SUCCESS MODAL
        // ============================================
        function openSuccessModal(amount, newBalance) {
            if (!successModal || isSuccessModalOpen) return;
            if (successAmountEl)  successAmountEl.textContent  = formatPeso(amount);
            if (successBalanceEl) successBalanceEl.textContent = formatPeso(newBalance);
            openModal(successModal);
            isSuccessModalOpen = true;
        }

        function closeSuccessModalHandler() {
            if (!successModal || !isSuccessModalOpen) return;
            closeModal(successModal);
            isSuccessModalOpen = false;
        }

        if (closeSuccessModal) {
            closeSuccessModal.addEventListener('click', closeSuccessModalHandler);
        }
        if (doneBtn) {
            doneBtn.addEventListener('click', function() {
                closeSuccessModalHandler();
                // Reload so the transaction list and balance reflect the deposit.
                setTimeout(function() { window.location.reload(); }, 300);
            });
        }
        if (successModal) {
            successModal.addEventListener('click', function(e) {
                if (e.target === successModal || e.target.classList.contains('modal-overlay')) {
                    closeSuccessModalHandler();
                    setTimeout(function() { window.location.reload(); }, 300);
                }
            });
        }

        // ============================================
        // PROCEED TO QR  (initiate_qr)
        // ============================================
        if (proceedToQrBtn) {
            proceedToQrBtn.addEventListener('click', function() {
                var check = validAmount(amountInput ? amountInput.value : '');
                if (!check.ok) {
                    setAmountError(check.message);
                    if (amountInput) amountInput.focus();
                    return;
                }

                pendingAmount = check.value;
                proceedToQrBtn.disabled = true;
                proceedToQrBtn.textContent = 'Processing...';

                var body = new URLSearchParams();
                body.append('csrf_token', CSRF_TOKEN);
                body.append('action', 'initiate_qr');
                body.append('amount', String(pendingAmount));

                fetch('../backend/handlers/wallet-handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                    credentials: 'same-origin'
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data && data.status === 'success') {
                        pendingTransactionId = data.transaction_id;
                        closeAmountModalHandler();
                        openQrModal();
                    } else {
                        setAmountError((data && data.message) || 'Could not start the recharge.');
                    }
                })
                .catch(function() {
                    setAmountError('Network error. Please try again.');
                })
                .finally(function() {
                    proceedToQrBtn.disabled = false;
                    proceedToQrBtn.textContent = 'Proceed to Payment';
                });
            });
        }

        // ============================================
        // CONFIRM QR  (confirm_qr)
        // ============================================
        if (confirmQrBtn) {
            confirmQrBtn.addEventListener('click', function() {
                if (!pendingTransactionId) {
                    closeQrModalHandler(false);
                    return;
                }

                confirmQrBtn.disabled = true;
                confirmQrBtn.textContent = 'Confirming...';

                var body = new URLSearchParams();
                body.append('csrf_token', CSRF_TOKEN);
                body.append('action', 'confirm_qr');
                body.append('transaction_id', String(pendingTransactionId));

                fetch('../backend/handlers/wallet-handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                    credentials: 'same-origin'
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data && data.status === 'success') {
                        var newBalance = Number(data.new_balance) || 0;
                        if (balanceEl) balanceEl.textContent = formatPeso(newBalance);

                        // Mark confirmed BEFORE closing so the close handler
                        // does not fire a cancel for a completed transaction.
                        pendingWasConfirmed = true;
                        closeQrModalHandler(true);

                        openSuccessModal(pendingAmount, newBalance);

                        pendingTransactionId = null;
                        pendingWasConfirmed  = false;
                    } else {
                        // Confirmation failed. The pending row is cancelled
                        // by closeQrModalHandler so it doesn't linger.
                        closeQrModalHandler(false);
                        openAmountModal();
                        setAmountError((data && data.message) || 'Could not confirm the payment.');
                    }
                })
                .catch(function() {
                    closeQrModalHandler(false);
                    openAmountModal();
                    setAmountError('Network error. Please try again.');
                })
                .finally(function() {
                    confirmQrBtn.disabled = false;
                    confirmQrBtn.textContent = "I've Paid";
                });
            });
        }

        // ============================================
        // PAGE TEARDOWN SAFETY NET
        //
        // If the user closes the tab or navigates away while the QR
        // modal is open, the pending transaction would be orphaned.
        // sendBeacon is the only transport guaranteed to survive
        // unload, so use it here.
        // ============================================
        window.addEventListener('beforeunload', function() {
            if (!pendingTransactionId || pendingWasConfirmed) return;

            var body = new URLSearchParams();
            body.append('csrf_token', CSRF_TOKEN);
            body.append('action', 'cancel_qr');
            body.append('transaction_id', String(pendingTransactionId));

            var url = '../backend/handlers/wallet-handler.php';
            if (navigator.sendBeacon) {
                navigator.sendBeacon(url, body);
            } else {
                fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                    credentials: 'same-origin',
                    keepalive: true
                });
            }
        });

        // ============================================
        // ESCAPE KEY
        // ============================================
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            if (isSuccessModalOpen) closeSuccessModalHandler();
            else if (isQrModalOpen) closeQrModalHandler(false);
            else if (isAmountModalOpen) closeAmountModalHandler();
        });

        console.log('Wallet v1.1 initialized');
    });
})();