/**
 * FitPal Customer Checkout JavaScript
 * Version 3.5 - Optimized modal with proper fade transitions
 *
 * @package FitPal
 * @version 3.5
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        // ============================================
        // DOM REFERENCES
        // ============================================
        var addressModal = document.getElementById('addressModal');
        var addressList = document.getElementById('addressList');
        var changeAddressBtn = document.getElementById('changeAddressBtn');
        var closeAddressModal = document.getElementById('closeAddressModal');
        var cancelAddressModal = document.getElementById('cancelAddressModal');
        var addAddressModalBtn = document.getElementById('addAddressModalBtn');
        var placeOrderBtn = document.getElementById('placeOrderBtn');
        var checkoutForm = document.getElementById('checkoutForm');
        var hiddenAddressId = document.getElementById('hiddenAddressId');
        var selectedAddressId = document.getElementById('selectedAddressId');
        var selectedAddressText = document.getElementById('selectedAddressText');
        var hiddenPaymentMethod = document.getElementById('hiddenPaymentMethod');

        // ============================================
        // STATE
        // ============================================
        var currentAddressId = selectedAddressId ? selectedAddressId.value : '';
        var isModalOpen = false;

        // ============================================
        // UPDATE ADDRESS DISPLAY
        // ============================================
        function updateAddressDisplay(addressId) {
            if (!addressId) return;

            var selectedOption = document.querySelector('.address-option[data-address-id="' + addressId + '"]');
            if (!selectedOption) return;

            var label = selectedOption.querySelector('.address-option-label');
            var text = selectedOption.querySelector('.address-option-text');
            var isDefault = selectedOption.querySelector('.badge-default') !== null;

            var displayLabel = document.querySelector('.address-display-label');
            var displayText = document.querySelector('.address-display-text');

            if (displayLabel) {
                displayLabel.innerHTML = (label ? label.textContent : 'Address');
                if (isDefault) {
                    displayLabel.innerHTML += ' <span class="badge badge-default">Default</span>';
                }
            }
            if (displayText && text) {
                displayText.textContent = text.textContent;
            }

            if (selectedAddressId) selectedAddressId.value = addressId;
            if (hiddenAddressId) hiddenAddressId.value = addressId;
            if (selectedAddressText) selectedAddressText.value = text ? text.textContent : '';

            currentAddressId = addressId;
        }

        // ============================================
        // MODAL FUNCTIONS (optimized fade - queue panel pattern)
        // ============================================
        function openAddressModal() {
            if (!addressModal || isModalOpen) return;

            // Highlight current selection
            var options = document.querySelectorAll('.address-option');
            for (var i = 0; i < options.length; i++) {
                var opt = options[i];
                opt.classList.remove('selected');
                var radio = opt.querySelector('input[type="radio"]');
                if (radio && radio.value == currentAddressId) {
                    opt.classList.add('selected');
                    radio.checked = true;
                }
            }

            // Use the active class for fade transitions
            addressModal.style.display = 'flex';
            // Force reflow for smooth animation
            void addressModal.offsetWidth;
            addressModal.classList.add('active');
            document.body.style.overflow = 'hidden';
            isModalOpen = true;
        }

        function closeAddressModalHandler() {
            if (!addressModal || !isModalOpen) return;

            // Remove active class first for fade out
            addressModal.classList.remove('active');
            document.body.style.overflow = '';

            // Wait for animation to complete before hiding
            setTimeout(function() {
                if (!addressModal.classList.contains('active')) {
                    addressModal.style.display = 'none';
                }
            }, 250);

            isModalOpen = false;
        }

        // ============================================
        // ADDRESS AUTO-SELECT (radio click = auto-confirm)
        // ============================================
        if (addressList) {
            // Event delegation for performance
            addressList.addEventListener('click', function(e) {
                var option = e.target.closest('.address-option');
                if (!option) return;

                var radio = option.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;

                    var allOptions = document.querySelectorAll('.address-option');
                    for (var i = 0; i < allOptions.length; i++) {
                        allOptions[i].classList.remove('selected');
                    }
                    option.classList.add('selected');

                    updateAddressDisplay(radio.value);
                    closeAddressModalHandler();
                }
            });

            // Handle radio change events
            addressList.addEventListener('change', function(e) {
                if (e.target && e.target.type === 'radio' && e.target.name === 'modal_address') {
                    var option = e.target.closest('.address-option');
                    if (option) {
                        var allOptions = document.querySelectorAll('.address-option');
                        for (var i = 0; i < allOptions.length; i++) {
                            allOptions[i].classList.remove('selected');
                        }
                        option.classList.add('selected');
                        updateAddressDisplay(e.target.value);
                        closeAddressModalHandler();
                    }
                }
            });
        }

        // ============================================
        // PAYMENT METHOD SELECTION
        // ============================================
        var paymentOptions = document.querySelectorAll('.payment-option');

        for (var p = 0; p < paymentOptions.length; p++) {
            (function(option) {
                option.addEventListener('click', function() {
                    var radio = this.querySelector('input[type="radio"]');
                    if (radio) {
                        radio.checked = true;
                        for (var j = 0; j < paymentOptions.length; j++) {
                            paymentOptions[j].classList.remove('selected');
                        }
                        this.classList.add('selected');
                        if (hiddenPaymentMethod) {
                            hiddenPaymentMethod.value = radio.value;
                        }
                    }
                });
            })(paymentOptions[p]);
        }

        var checkedRadio = document.querySelector('.payment-option input[type="radio"]:checked');
        if (checkedRadio) {
            checkedRadio.closest('.payment-option').classList.add('selected');
            if (hiddenPaymentMethod) {
                hiddenPaymentMethod.value = checkedRadio.value;
            }
        }

        // ============================================
        // MODAL EVENT BINDING
        // ============================================

        if (changeAddressBtn) {
            changeAddressBtn.addEventListener('click', function(e) {
                e.preventDefault();
                openAddressModal();
            });
        }

        if (closeAddressModal) {
            closeAddressModal.addEventListener('click', function(e) {
                e.preventDefault();
                closeAddressModalHandler();
            });
        }

        if (cancelAddressModal) {
            cancelAddressModal.addEventListener('click', function(e) {
                e.preventDefault();
                closeAddressModalHandler();
            });
        }

        // Click on overlay
        if (addressModal) {
            addressModal.addEventListener('click', function(e) {
                if (e.target === addressModal || e.target.classList.contains('modal-overlay')) {
                    closeAddressModalHandler();
                }
            });
        }

        // Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isModalOpen) {
                closeAddressModalHandler();
            }
        });

        // ============================================
        // ADD ADDRESS
        // ============================================
        if (addAddressModalBtn) {
            addAddressModalBtn.addEventListener('click', function(e) {
                e.preventDefault();
                closeAddressModalHandler();
                window.location.href = 'profile.php#addresses';
            });
        }

        // ============================================
        // PLACE ORDER
        // ============================================
        if (placeOrderBtn) {
            placeOrderBtn.addEventListener('click', function(e) {
                e.preventDefault();

                var addressId = hiddenAddressId ? hiddenAddressId.value : '';
                if (!addressId || addressId === '0') {
                    openAddressModal();
                    return;
                }

                this.disabled = true;
                this.classList.add('loading');
                this.textContent = 'Placing Order...';

                if (checkoutForm) {
                    checkoutForm.submit();
                }
            });
        }

        // ============================================
        // INITIAL SETUP
        // ============================================
        if (currentAddressId) {
            updateAddressDisplay(currentAddressId);
        }

        console.log('Checkout v3.5 initialized with optimized modal transitions');

    });
})();