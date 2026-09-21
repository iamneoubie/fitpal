/**
 * FitPal Customer Checkout JavaScript
 *
 * Handles:
 *   - Payment method selection (Wallet balance check, Online QR modal)
 *   - Address selection modal (persists choice to session)
 *   - Place Order → confirmation modal → hidden form submit
 *   - Back-forward-cache restore forces a reload so the server
 *     re-renders against the current session's checkout address
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES APPLIED
 * ---------------------------------------------------------------------
 *  - Configuration is read from data-* attributes on #checkoutPage,
 *    not from an inline <script> block.
 *  - No .innerHTML writes for user-supplied strings. Labels and
 *    addresses are written via .textContent.
 *  - Click and change handling on the address list are not duplicated;
 *    a single delegated handler covers both.
 *  - Console noise reduced to actionable warnings.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 7.0
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // CONFIG (from data-* attributes)
        // ============================================
        var page = document.getElementById('checkoutPage');
        if (!page) return;

        var ORDER_TOTAL    = parseFloat(page.dataset.total)         || 0;
        var WALLET_BALANCE = parseFloat(page.dataset.walletBalance) || 0;
        var HAS_ADDRESS    = page.dataset.hasAddress === '1';
        var CSRF_TOKEN     = page.dataset.csrfToken || '';

        // ============================================
        // DOM REFERENCES
        // ============================================
        var addressModal        = document.getElementById('addressModal');
        var addressList         = document.getElementById('addressList');
        var changeAddressBtn    = document.getElementById('changeAddressBtn');
        var closeAddressModal   = document.getElementById('closeAddressModal');
        var cancelAddressModal  = document.getElementById('cancelAddressModal');
        var addAddressModalBtn  = document.getElementById('addAddressModalBtn');
        var placeOrderBtn       = document.getElementById('placeOrderBtn');
        var checkoutForm        = document.getElementById('checkoutForm');
        var hiddenAddressId     = document.getElementById('hiddenAddressId');
        var selectedAddressId   = document.getElementById('selectedAddressId');
        var hiddenPaymentMethod = document.getElementById('hiddenPaymentMethod');

        // QR modal
        var qrModal          = document.getElementById('qrPaymentModal');
        var closeQrModal     = document.getElementById('closeQrModal');
        var cancelQrModal    = document.getElementById('cancelQrModal');
        var confirmQrPayment = document.getElementById('confirmQrPayment');

        // Wallet modal
        var walletModal           = document.getElementById('walletInsufficientModal');
        var closeWalletModal      = document.getElementById('closeWalletModal');
        var cancelWalletModal     = document.getElementById('cancelWalletModal');

        // Confirm modal
        var confirmOrderModal = document.getElementById('confirmOrderModal');
        var confirmCancelBtn  = document.getElementById('confirmCancelBtn');
        var confirmPlaceBtn   = document.getElementById('confirmPlaceBtn');

        // ============================================
        // STATE
        // ============================================
        var currentAddressId = selectedAddressId ? selectedAddressId.value : '';
        var isModalOpen   = false;
        var isQrOpen      = false;
        var isWalletOpen  = false;
        var isConfirmOpen = false;

        // ============================================
        // GENERIC MODAL HELPERS
        // ============================================
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
            setTimeout(function () {
                if (!modal.classList.contains('active')) {
                    modal.style.display = 'none';
                }
            }, 250);
        }

        // ============================================
        // ADDRESS DISPLAY
        //
        // Writes user-supplied text via .textContent only. The "Default"
        // badge is added as a child element rather than via innerHTML+=
        // so nothing user-controlled ever passes through an HTML parser.
        // ============================================
        function updateAddressDisplay(addressId) {
            if (!addressId) return;

            var selectedOption = document.querySelector(
                '.address-option[data-address-id="' + addressId + '"]'
            );
            if (!selectedOption) return;

            var labelEl   = selectedOption.querySelector('.address-option-label');
            var textEl    = selectedOption.querySelector('.address-option-text');
            var isDefault = selectedOption.querySelector('.badge-default') !== null;

            var displayLabel = document.querySelector('.address-display-label');
            var displayText  = document.querySelector('.address-display-text');

            if (displayLabel) {
                displayLabel.textContent = labelEl ? labelEl.textContent : 'Address';
                if (isDefault) {
                    var badge = document.createElement('span');
                    badge.className = 'badge badge-default';
                    badge.textContent = 'Default';
                    displayLabel.appendChild(document.createTextNode(' '));
                    displayLabel.appendChild(badge);
                }
            }

            if (displayText && textEl) {
                displayText.textContent = textEl.textContent;
            }

            if (selectedAddressId) selectedAddressId.value = addressId;
            if (hiddenAddressId)   hiddenAddressId.value   = addressId;

            currentAddressId = addressId;
        }

        // ============================================
        // PERSIST CHECKOUT ADDRESS
        // ============================================
        function persistCheckoutAddress(addressId) {
            if (!addressId || addressId === '0') return;

            var body = new URLSearchParams();
            body.append('csrf_token', CSRF_TOKEN);
            body.append('address_id', String(addressId));

            fetch('../backend/handlers/checkout-handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin'
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 'success') {
                    console.warn('[checkout] Could not persist address choice:', data);
                }
            })
            .catch(function (err) {
                console.warn('[checkout] Could not persist address choice:', err);
            });
        }

        // ============================================
        // ADDRESS MODAL
        // ============================================
        function openAddressModal() {
            if (!addressModal || isModalOpen) return;

            var options = document.querySelectorAll('.address-option');
            for (var i = 0; i < options.length; i++) {
                var opt = options[i];
                opt.classList.remove('selected');
                var radio = opt.querySelector('input[type="radio"]');
                if (radio && radio.value === String(currentAddressId)) {
                    opt.classList.add('selected');
                    radio.checked = true;
                }
            }

            openModal(addressModal);
            isModalOpen = true;
        }

        function closeAddressModalHandler() {
            if (!addressModal || !isModalOpen) return;
            closeModal(addressModal);
            isModalOpen = false;
        }

        // Single delegated handler. Clicking the option or the radio
        // both resolve to the same code path — no double-handling.
        if (addressList) {
            addressList.addEventListener('click', function (e) {
                var option = e.target.closest('.address-option');
                if (!option) return;

                var radio = option.querySelector('input[type="radio"]');
                if (!radio) return;

                radio.checked = true;

                var allOptions = document.querySelectorAll('.address-option');
                for (var i = 0; i < allOptions.length; i++) {
                    allOptions[i].classList.remove('selected');
                }
                option.classList.add('selected');

                updateAddressDisplay(radio.value);
                persistCheckoutAddress(radio.value);
                closeAddressModalHandler();
            });
        }

        if (changeAddressBtn) {
            changeAddressBtn.addEventListener('click', function (e) {
                e.preventDefault();
                openAddressModal();
            });
        }

        if (closeAddressModal) {
            closeAddressModal.addEventListener('click', function (e) {
                e.preventDefault();
                closeAddressModalHandler();
            });
        }

        if (cancelAddressModal) {
            cancelAddressModal.addEventListener('click', function (e) {
                e.preventDefault();
                closeAddressModalHandler();
            });
        }

        if (addressModal) {
            addressModal.addEventListener('click', function (e) {
                if (e.target === addressModal || e.target.classList.contains('modal-overlay')) {
                    closeAddressModalHandler();
                }
            });
        }

        if (addAddressModalBtn) {
            addAddressModalBtn.addEventListener('click', function (e) {
                e.preventDefault();
                closeAddressModalHandler();
                window.location.href = 'profile.php?from=checkout#add-address';
            });
        }

        // ============================================
        // QR MODAL
        // ============================================
        function openQrModal() {
            if (!qrModal || isQrOpen) return;
            openModal(qrModal);
            isQrOpen = true;
        }

        function closeQrModalHandler() {
            if (!qrModal || !isQrOpen) return;
            closeModal(qrModal);
            isQrOpen = false;
        }

        if (closeQrModal) {
            closeQrModal.addEventListener('click', function (e) {
                e.preventDefault();
                closeQrModalHandler();
            });
        }
        if (cancelQrModal) {
            cancelQrModal.addEventListener('click', function (e) {
                e.preventDefault();
                closeQrModalHandler();
            });
        }
        if (qrModal) {
            qrModal.addEventListener('click', function (e) {
                if (e.target === qrModal || e.target.classList.contains('modal-overlay')) {
                    closeQrModalHandler();
                }
            });
        }

        if (confirmQrPayment) {
            confirmQrPayment.addEventListener('click', function (e) {
                e.preventDefault();
                closeQrModalHandler();
                openConfirmModal();
            });
        }

        // ============================================
        // WALLET MODAL
        // ============================================
        function openWalletModal() {
            if (!walletModal || isWalletOpen) return;
            openModal(walletModal);
            isWalletOpen = true;
        }

        function closeWalletModalHandler() {
            if (!walletModal || !isWalletOpen) return;
            closeModal(walletModal);
            isWalletOpen = false;
        }

        if (closeWalletModal) {
            closeWalletModal.addEventListener('click', function (e) {
                e.preventDefault();
                closeWalletModalHandler();
            });
        }
        if (cancelWalletModal) {
            cancelWalletModal.addEventListener('click', function (e) {
                e.preventDefault();
                closeWalletModalHandler();
            });
        }
        if (walletModal) {
            walletModal.addEventListener('click', function (e) {
                if (e.target === walletModal || e.target.classList.contains('modal-overlay')) {
                    closeWalletModalHandler();
                }
            });
        }

        // NOTE: The "Recharge Wallet" anchor navigates to wallet.php.
        // We intentionally do NOT bind a click handler that closes the
        // modal — navigation would happen anyway, and the previous
        // handler was dead code.

        // ============================================
        // CONFIRM ORDER MODAL
        // ============================================
        function openConfirmModal() {
            if (!confirmOrderModal || isConfirmOpen) return;
            openModal(confirmOrderModal);
            isConfirmOpen = true;
        }

        function closeConfirmModalHandler() {
            if (!confirmOrderModal || !isConfirmOpen) return;
            closeModal(confirmOrderModal);
            isConfirmOpen = false;
        }

        if (confirmCancelBtn) {
            confirmCancelBtn.addEventListener('click', function (e) {
                e.preventDefault();
                closeConfirmModalHandler();
            });
        }
        if (confirmOrderModal) {
            confirmOrderModal.addEventListener('click', function (e) {
                if (e.target === confirmOrderModal || e.target.classList.contains('modal-overlay')) {
                    closeConfirmModalHandler();
                }
            });
        }

        if (confirmPlaceBtn) {
            confirmPlaceBtn.addEventListener('click', function () {
                confirmPlaceBtn.disabled = true;
                confirmPlaceBtn.textContent = 'Placing Order...';
                if (checkoutForm) {
                    checkoutForm.submit();
                }
            });
        }

        // ============================================
        // PAYMENT METHOD SELECTION
        // ============================================
        var paymentOptions = document.querySelectorAll('.payment-option');

        function selectPaymentMethod(value) {
            if (hiddenPaymentMethod) hiddenPaymentMethod.value = value;
        }

        function markSelected(selectedOption) {
            for (var i = 0; i < paymentOptions.length; i++) {
                paymentOptions[i].classList.remove('selected');
            }
            selectedOption.classList.add('selected');
        }

        for (var p = 0; p < paymentOptions.length; p++) {
            (function (option) {
                option.addEventListener('click', function () {
                    var radio = this.querySelector('input[type="radio"]');
                    if (!radio) return;

                    var method = radio.value;

                    if (method === 'Wallet' && WALLET_BALANCE < ORDER_TOTAL) {
                        // Leave the previous selection intact so a modal
                        // cancel does not silently change the method.
                        openWalletModal();
                        return;
                    }

                    if (method === 'Online') {
                        radio.checked = true;
                        markSelected(this);
                        selectPaymentMethod(method);
                        openQrModal();
                        return;
                    }

                    radio.checked = true;
                    markSelected(this);
                    selectPaymentMethod(method);
                });
            })(paymentOptions[p]);
        }

        var checkedRadio = document.querySelector('.payment-option input[type="radio"]:checked');
        if (checkedRadio) {
            checkedRadio.closest('.payment-option').classList.add('selected');
            selectPaymentMethod(checkedRadio.value);
        }

        // ============================================
        // PLACE ORDER BUTTON
        // ============================================
        if (placeOrderBtn) {
            placeOrderBtn.addEventListener('click', function (e) {
                e.preventDefault();

                if (!HAS_ADDRESS) {
                    window.location.href = 'profile.php?from=checkout#add-address';
                    return;
                }

                var addressId = hiddenAddressId ? hiddenAddressId.value : '';
                if (!addressId || addressId === '0') {
                    openAddressModal();
                    return;
                }

                var method = hiddenPaymentMethod ? hiddenPaymentMethod.value : 'COD';

                if (method === 'Wallet' && WALLET_BALANCE < ORDER_TOTAL) {
                    openWalletModal();
                    return;
                }

                if (method === 'Online') {
                    openQrModal();
                    return;
                }

                openConfirmModal();
            });
        }

        // ============================================
        // ESCAPE KEY
        // ============================================
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (isModalOpen)   closeAddressModalHandler();
            if (isQrOpen)      closeQrModalHandler();
            if (isWalletOpen)  closeWalletModalHandler();
            if (isConfirmOpen) closeConfirmModalHandler();
        });

        // ============================================
        // BACK-FORWARD-CACHE RESTORE
        // ============================================
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) {
                window.location.reload();
            }
        });

        // ============================================
        // INITIAL SETUP
        // ============================================
        if (currentAddressId) {
            updateAddressDisplay(currentAddressId);
        }
    });
})();