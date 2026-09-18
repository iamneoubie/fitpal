/**
 * FitPal Customer Wallet JavaScript
 * Version 2.3 — Scroll lock applied before the modal is shown, and
 *                released after it is hidden. Prevents the page from
 *                reflowing visibly when the body scrollbar disappears
 *                and reappears around the modal fade.
 *
 * Handles:
 *   - Recharge amount modal (validation, quick amounts)
 *   - QR payment modal (initiate → confirm, or cancel)
 *   - Success modal (new balance display)
 *   - Back button navigation (origin-aware)
 *   - Pending-recharge cleanup on any QR dismissal
 *   - Background balance refresh with burst cooldown
 *
 * Balance refresh policy:
 *   - pageshow after the first    → refresh
 *   - visibilitychange → visible  → refresh
 *   - window focus                → refresh
 *   - top-up response             → read new_balance from the
 *     response, no extra fetch
 *
 * Bursts of refresh triggers (pageshow + visibilitychange + focus
 * on tab-return) are collapsed by a 1500 ms cooldown. The cooldown
 * only gates automatic triggers; explicit callers can pass
 * { force: true } to bypass it.
 *
 * The refresh is silent. No toast fires when the background check
 * finds a different number — the display just updates in place.
 *
 * @package FitPal
 * @version 2.3
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

        // ---- Balance refresh state ----
        var knownBalance           = Number(CFG.balance) || 0;
        var refreshing             = false;
        var lastRefreshAt          = 0;
        var isFirstPageShow        = true;

        // Collapse burst triggers (pageshow + visibilitychange + focus
        // on tab-return) into a single request. Bypassed with
        // { force: true } for explicit user-initiated refreshes.
        var REFRESH_COOLDOWN_MS    = 1500;

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

        // Lock scroll FIRST, while the modal is still hidden, so the
        // page reflows to fill the space the scrollbar was using
        // before anything is visible. Then show the modal on a page
        // that is already stable.
        function openModal(modal) {
            if (!modal) return;
            document.body.style.overflow = 'hidden';
            modal.style.display = 'flex';
            void modal.offsetWidth;
            modal.classList.add('active');
        }

        // Hide the modal first, and release the scroll lock only
        // after the fade-out completes. Releasing it earlier would
        // reflow the page behind the fading modal, which the user
        // would see as a jump.
        function closeModal(modal) {
            if (!modal) return;
            modal.classList.remove('active');
            setTimeout(function() {
                if (!modal.classList.contains('active')) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
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
        // BALANCE REFRESH
        // ============================================

        /**
         * Update the balance display. No-op if the value hasn't
         * actually moved — avoids forcing a layout pass on the
         * balance span for identical numbers.
         */
        function applyBalance(newBalance) {
            var n = Number(newBalance);
            if (!isFinite(n) || isNaN(n)) return;

            if (Math.abs(n - knownBalance) < 0.005) {
                return;
            }

            knownBalance = n;

            if (balanceEl) {
                balanceEl.textContent = formatPeso(n);
            }
        }

        /**
         * Fetch the current balance from the server.
         *
         * @param {Object}  [opts]
         * @param {boolean} [opts.force=false]  Skip the cooldown. Use
         *   for user-initiated refreshes that must always hit the
         *   server.
         */
        function refreshBalance(opts) {
            opts = opts || {};

            if (refreshing) return;

            if (!opts.force) {
                var sinceLast = Date.now() - lastRefreshAt;
                if (sinceLast < REFRESH_COOLDOWN_MS) return;
            }

            refreshing = true;

            var body = new URLSearchParams();
            body.append('csrf_token', CSRF_TOKEN);
            body.append('action', 'get_balance');

            fetch('../backend/handlers/wallet-handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString(),
                credentials: 'same-origin',
                cache: 'no-store'
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data && data.status === 'success' && typeof data.balance === 'number') {
                    applyBalance(data.balance);
                }
            })
            .catch(function() {
                // Silent. A failed background refresh should never
                // interrupt the user. The next trigger will retry.
            })
            .finally(function() {
                refreshing    = false;
                lastRefreshAt = Date.now();
            });
        }

        // ---- pageshow: any re-show after the first ----
        window.addEventListener('pageshow', function() {
            if (isFirstPageShow) {
                isFirstPageShow = false;
                return;
            }
            refreshBalance();
        });

        // ---- visibilitychange: back from a hidden tab ----
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                refreshBalance();
            }
        });

        // ---- focus: window regained focus ----
        window.addEventListener('focus', function() {
            refreshBalance();
        });

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
        // ============================================
        function cancelPendingQr() {
            if (!pendingTransactionId) return;
            if (pendingWasConfirmed) return;

            var txnId = pendingTransactionId;

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
                        applyBalance(newBalance);

                        // Suppress the next automatic refresh — the
                        // number we just learned is current. Without
                        // this, the next visibility/focus event within
                        // the cooldown window would fire a redundant
                        // get_balance that returns the same value.
                        lastRefreshAt = Date.now();

                        pendingWasConfirmed = true;
                        closeQrModalHandler(true);

                        openSuccessModal(pendingAmount, newBalance);

                        pendingTransactionId = null;
                        pendingWasConfirmed  = false;
                    } else {
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

    });
})();