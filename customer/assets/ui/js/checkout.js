/**
 * FitPal Customer Checkout JavaScript
 *
 * Handles:
 *   - Payment method selection with a committed-state model.
 *     A click on Wallet or Online opens a confirmation modal BEFORE
 *     the choice is committed. Cancelling the modal reverts the
 *     radio group to the last committed method, so the visible
 *     selection never disagrees with what will be submitted.
 *   - Address selection modal (persists choice to session)
 *   - Place Order → confirmation modal → hidden form submit
 *   - Back-forward-cache restore forces a reload so the server
 *     re-renders against the current session's checkout address
 *
 * ---------------------------------------------------------------------
 * PAYMENT METHOD COMMIT MODEL
 * ---------------------------------------------------------------------
 * The radio group is a controlled input. The browser's native label
 * click toggles the radio visually before any JS runs, which means
 * an early return from a validation branch would leave the radio
 * showing a method that was never accepted.
 *
 * Two pieces of state resolve this:
 *
 *   committedPaymentMethod
 *     The method that will actually be submitted. Only ever set by
 *     a code path that has fully accepted the choice.
 *
 *   The DOM
 *     Kept in sync with committedPaymentMethod by commitPaymentMethod()
 *     and revertPaymentMethod().
 *
 * Clicking a payment option does not commit. It opens the appropriate
 * modal when the method needs confirmation (Wallet balance check,
 * Online QR). Cancelling any of those modals calls revertPaymentMethod(),
 * which restores the radio group to the committed value. Confirming
 * calls commitPaymentMethod().
 *
 * COD needs no confirmation and commits immediately.
 *
 * ---------------------------------------------------------------------
 * ADDRESS-SELECTION ENDPOINT
 * ---------------------------------------------------------------------
 * The address-selection POST goes to the endpoint the checkout page
 * publishes on #checkoutPage as data-checkout-handler-url. When the
 * attribute is missing — which only happens on a page that was
 * rendered without it — the fallback names the same endpoint the
 * checkout page's own markup names. This file does not carry a
 * second, different endpoint.
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
 *  - No window.alert, confirm, or prompt. Every message goes through
 *    a modal or a toast.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 8.1 — Restores the v8.0 controlled-payment model, which
 *                the v3.0 rewrite had dropped. The v3.0 rewrite
 *                wrote radio.checked and the .selected class
 *                directly in the click handler, so cancelling the
 *                wallet-insufficient modal or the Online QR modal
 *                left the just-clicked radio stuck in the checked
 *                position. Clicking Place Order afterwards re-read
 *                the stuck radio and reopened the same modal — the
 *                modal loop that made checkout appear not to
 *                proceed.
 *
 *                The v3.0 rewrite also dropped the modal lifecycle
 *                (active class, scroll lock, fade timer), the
 *                Escape-key handler, and the bfcache reload. All
 *                four are restored here.
 *
 *                One improvement from v3.0 is kept: the
 *                address-selection endpoint is read from
 *                #checkoutPage's data-checkout-handler-url
 *                attribute, with a single fallback that names the
 *                same endpoint the page's markup names. The v8.0
 *                file hard-coded the endpoint in the fetch call.
 *
 *                Everything else is byte-identical to v8.0:
 *                commitPaymentMethod, revertPaymentMethod, the
 *                payment-option click handler, updateAddressDisplay,
 *                persistCheckoutAddress, openAddressModal,
 *                closeAddressModalHandler, the address-list
 *                delegated handler, the QR modal, the wallet modal,
 *                the confirm modal, the Place Order button, the
 *                Escape handler, the pageshow handler, and the
 *                initial setup.
 *
 *                (8.0: controlled payment model. 7.0: config from
 *                data-* attributes; address delegated handler;
 *                textContent-only writes.)
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // CONFIG (from data-* attributes)
        // ============================================
        var page = document.getElementById('checkoutPage');
        if (!page) return;

        var ORDER_TOTAL         = parseFloat(page.dataset.total)         || 0;
        var WALLET_BALANCE      = parseFloat(page.dataset.walletBalance) || 0;
        var HAS_ADDRESS         = page.dataset.hasAddress === '1';
        var CSRF_TOKEN          = page.dataset.csrfToken || '';
        var INITIAL_PAYMENT     = page.dataset.initialPaymentMethod || 'COD';

        // The address-selection POST goes to this endpoint. It is
        // published on #checkoutPage by the checkout page itself.
        // When the attribute is missing — which happens only on a
        // page rendered without it — the fallback names the same
        // endpoint the checkout page's own markup names.
        var CHECKOUT_ADDRESS_ENDPOINT =
            page.dataset.checkoutHandlerUrl
            || '../backend/handlers/checkout-handler.php';

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

        // The payment method that will be submitted. Only ever set by
        // a code path that has fully accepted the choice. The radio
        // group is kept in sync with this value by the two helpers
        // below, not by the browser's native label click.
        var committedPaymentMethod = 'COD';

        var paymentOptions = document.querySelectorAll('.payment-option');

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
        // PAYMENT METHOD — COMMIT / REVERT
        //
        // The single source of truth for what is selected. Every code
        // path that changes the payment method goes through one of
        // these two functions. Nothing else writes radio.checked or
        // touches the .selected class on a payment option.
        // ============================================

        /**
         * Commit a payment method. Updates the hidden field and forces
         * the radio group and the option cards into the state that
         * matches this method.
         */
        function commitPaymentMethod(method) {
            if (!method) return;

            committedPaymentMethod = method;

            if (hiddenPaymentMethod) {
                hiddenPaymentMethod.value = method;
            }

            var i, opt, radio;
            for (i = 0; i < paymentOptions.length; i++) {
                opt = paymentOptions[i];
                radio = opt.querySelector('input[type="radio"]');
                if (!radio) continue;

                if (radio.value === method) {
                    radio.checked = true;
                    opt.classList.add('selected');
                } else {
                    radio.checked = false;
                    opt.classList.remove('selected');
                }
            }
        }

        /**
         * Revert the visible selection to the committed method.
         *
         * Called whenever a confirmation modal is dismissed without
         * committing — the user changed their mind, or the method
         * could not be used (insufficient wallet balance) and the
         * modal is being closed.
         */
        function revertPaymentMethod() {
            commitPaymentMethod(committedPaymentMethod);
        }

        // ============================================
        // PAYMENT METHOD — CLICK HANDLING
        //
        // A click on a payment option never commits by itself. It
        // decides whether the method needs confirmation, and either
        // commits immediately (COD) or opens the relevant modal.
        //
        // Any modal that opens as a result is responsible for calling
        // commitPaymentMethod() on confirm or revertPaymentMethod() on
        // cancel. Until one of those fires, the visible radio group is
        // forced back to the committed method so the click's native
        // toggle cannot leak through.
        // ============================================
        for (var p = 0; p < paymentOptions.length; p++) {
            (function (option) {
                option.addEventListener('click', function () {
                    var radio = this.querySelector('input[type="radio"]');
                    if (!radio) return;

                    var method = radio.value;

                    // The native label click has already toggled the
                    // radio and the option's visual state by the time
                    // this handler runs. Force the DOM back to the
                    // committed method immediately. Each branch below
                    // is then free to either commit the new choice or
                    // open a modal that will commit or revert later.
                    revertPaymentMethod();

                    if (method === 'Wallet' && WALLET_BALANCE < ORDER_TOTAL) {
                        openWalletModal();
                        return;
                    }

                    if (method === 'Online') {
                        openQrModal();
                        return;
                    }

                    // COD and Wallet-with-sufficient-balance need no
                    // confirmation. Commit immediately.
                    commitPaymentMethod(method);
                });
            })(paymentOptions[p]);
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

            fetch(CHECKOUT_ADDRESS_ENDPOINT, {
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
        //
        // Confirming commits 'Online'. Cancelling reverts.
        // ============================================
        function openQrModal() {
            if (!qrModal || isQrOpen) return;
            openModal(qrModal);
            isQrOpen = true;
        }

        function closeQrModalHandler(commit) {
            if (!qrModal || !isQrOpen) return;
            closeModal(qrModal);
            isQrOpen = false;

            if (commit) {
                commitPaymentMethod('Online');
            } else {
                revertPaymentMethod();
            }
        }

        if (closeQrModal) {
            closeQrModal.addEventListener('click', function (e) {
                e.preventDefault();
                closeQrModalHandler(false);
            });
        }
        if (cancelQrModal) {
            cancelQrModal.addEventListener('click', function (e) {
                e.preventDefault();
                closeQrModalHandler(false);
            });
        }
        if (qrModal) {
            qrModal.addEventListener('click', function (e) {
                if (e.target === qrModal || e.target.classList.contains('modal-overlay')) {
                    closeQrModalHandler(false);
                }
            });
        }

        if (confirmQrPayment) {
            confirmQrPayment.addEventListener('click', function (e) {
                e.preventDefault();
                closeQrModalHandler(true);
                openConfirmModal();
            });
        }

        // ============================================
        // WALLET MODAL
        //
        // This modal only opens when the wallet cannot cover the
        // order, so there is no confirm path. Every way of closing
        // it reverts the payment method to whatever was committed
        // before the click. The "Recharge Wallet" anchor navigates
        // away, which is a full page unload — no revert needed there.
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
            revertPaymentMethod();
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

                var method = committedPaymentMethod;

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
            if (isQrOpen)      closeQrModalHandler(false);
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
        //
        // The server renders the payment group with COD checked. The
        // committed state is seeded from the same server-rendered
        // value (data-initial-payment-method) and forced onto the DOM
        // so the visible state and the committed state start in
        // agreement even if a browser restore or a stale form value
        // left the radio group in a different position.
        // ============================================
        if (currentAddressId) {
            updateAddressDisplay(currentAddressId);
        }

        commitPaymentMethod(INITIAL_PAYMENT);
    });
})();