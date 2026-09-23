/**
 * FitPal Customer Profile JavaScript
 * Version 3.3 — Prefer window.FITPAL_CSRF_TOKEN over the DOM query
 *                when resolving the customer role's CSRF token. The
 *                page now bootstraps that global from $csrfToken,
 *                which comes from header.php (via includes/csrf_token.php)
 *                and is the customer role's own session key,
 *                customer_csrf_token — never the shared csrf_token.
 *
 * - Tabs, profile edit mode
 * - Multi-step address modal (add / edit)
 * - Delete confirmation
 * - Deep-link hash is consumed once on load and stripped from the URL
 * - Back navigation wired to a whitelisted origin slug, with a
 *   history.back() fallback when no origin was recorded
 * - Field-level input filters ported from sign-up.js
 *
 * @package FitPal
 * @version 3.3
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        // ============================================
        // DOM ELEMENTS
        // ============================================
        var tabs         = document.querySelectorAll('.profile-tab');
        var tabContents  = document.querySelectorAll('.profile-tab-content');

        var editProfileBtn = document.getElementById('editProfileBtn');
        var cancelEditBtn  = document.getElementById('cancelEditBtn');
        var profileActions = document.getElementById('profileActions');
        var profileForm    = document.getElementById('profileForm');
        var formInputs     = profileForm ? profileForm.querySelectorAll('input, select') : [];

        var addAddressBtn       = document.getElementById('addAddressBtn');
        var addressModal        = document.getElementById('addressModal');
        var closeAddressModal   = document.getElementById('closeAddressModal');
        var addressForm         = document.getElementById('addressForm');
        var addressModalTitle   = document.getElementById('addressModalTitle');
        var addressId           = document.getElementById('addressId');
        var saveAddressBtn      = document.getElementById('saveAddressBtn');

        var modalStep1 = document.getElementById('modalStep1');
        var modalStep2 = document.getElementById('modalStep2');
        var modalStep3 = document.getElementById('modalStep3');
        var modalSteps = [modalStep1, modalStep2, modalStep3];

        var progressSteps = document.querySelectorAll('.modal-progress .progress-step');
        var progressLines = document.querySelectorAll('.modal-progress .progress-line');
        var nextStepBtns  = document.querySelectorAll('.btn-next-step');
        var prevStepBtns  = document.querySelectorAll('.btn-prev-step');

        var deleteAddressModal = document.getElementById('deleteAddressModal');
        var cancelDeleteModal  = document.getElementById('cancelDeleteModal');
        var confirmDeleteModal = document.getElementById('confirmDeleteModal');

        var editAddressBtns   = document.querySelectorAll('.edit-address');
        var deleteAddressBtns = document.querySelectorAll('.delete-address');

        var textFieldsForNames = [
            document.getElementById('block'),
            document.getElementById('barangay'),
            document.getElementById('city'),
            document.getElementById('province'),
            document.getElementById('region')
        ];
        var postalCodeField = document.getElementById('postal_code');

        // ============================================
        // STATE
        // ============================================
        var isEditing        = false;
        var deleteAddressId  = null;
        var currentModalStep = 1;
        var totalModalSteps  = 3;

        // ============================================
        // CSRF TOKEN RESOLUTION
        //
        // Priority:
        //   1. window.FITPAL_CSRF_TOKEN — bootstrapped by profile.php
        //      from $csrfToken (customer role's own session key,
        //      customer_csrf_token, set via header.php).
        //   2. The address form's own hidden csrf_token input.
        //   3. The first csrf_token input on the page.
        //
        // This never reads the shared csrf_token session key — the
        // customer role is not allowed to touch it. See general.md.
        // ============================================
        function resolveCsrfToken() {
            if (typeof window.FITPAL_CSRF_TOKEN === 'string' && window.FITPAL_CSRF_TOKEN !== '') {
                return window.FITPAL_CSRF_TOKEN;
            }

            if (addressForm) {
                var formInput = addressForm.querySelector('input[name="csrf_token"]');
                if (formInput && formInput.value) {
                    return formInput.value;
                }
            }

            var fallback = document.querySelector('input[name="csrf_token"]');
            return fallback ? fallback.value : '';
        }

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
        // BACK NAVIGATION
        // ============================================
        var profileBackBtn = document.getElementById('profileBackBtn');
        if (profileBackBtn) {
            profileBackBtn.addEventListener('click', function(e) {
                e.preventDefault();

                var fallback = this.getAttribute('data-fallback-href') || '';
                if (fallback !== '') {
                    window.location.href = fallback;
                    return;
                }

                if (window.history.length > 1 && document.referrer !== '') {
                    window.history.back();
                } else {
                    window.location.href = 'menu.php';
                }
            });
        }

        // ============================================
        // TABS
        // ============================================
        function switchTab(tabId) {
            tabs.forEach(function(tab) {
                tab.classList.remove('active');
                if (tab.dataset.tab === tabId) {
                    tab.classList.add('active');
                }
            });

            tabContents.forEach(function(content) {
                content.classList.remove('active');
                if (content.id === 'tab-' + tabId) {
                    content.classList.add('active');
                }
            });
        }

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                switchTab(this.dataset.tab);
            });
        });

        // ============================================
        // PROFILE EDIT MODE
        // ============================================
        if (editProfileBtn) {
            editProfileBtn.addEventListener('click', function() {
                isEditing = true;
                this.style.display = 'none';
                if (profileActions) profileActions.classList.remove('is-hidden');
                formInputs.forEach(function(input) {
                    input.disabled = false;
                });
            });
        }

        if (cancelEditBtn) {
            cancelEditBtn.addEventListener('click', function() {
                isEditing = false;
                if (editProfileBtn) editProfileBtn.style.display = 'inline-flex';
                if (profileActions) profileActions.classList.add('is-hidden');
                formInputs.forEach(function(input) {
                    input.disabled = true;
                });
                window.location.reload();
            });
        }

        // ============================================
        // INPUT FILTERS
        // ============================================

        /**
         * Restrict a text field to letters, spaces, hyphens and
         * apostrophes, and auto-capitalize the first letter of each word.
         *
         * @param {HTMLElement|null} input
         */
        function setupNameField(input) {
            if (!input) return;

            input.addEventListener('input', function() {
                var start = this.selectionStart;
                var end   = this.selectionEnd;

                var filtered    = this.value.replace(/[^A-Za-z\s\-']/g, '');
                var capitalized = filtered.replace(/\b\w/g, function(ch) {
                    return ch.toUpperCase();
                });

                if (this.value !== capitalized) {
                    this.value = capitalized;
                    var newStart = Math.min(start, this.value.length);
                    this.setSelectionRange(newStart, newStart);
                }
            });

            input.addEventListener('blur', function() {
                if (this.value.length > 0) {
                    var capitalized = this.value.replace(/\b\w/g, function(ch) {
                        return ch.toUpperCase();
                    });
                    if (this.value !== capitalized) {
                        this.value = capitalized;
                    }
                }
            });
        }

        /**
         * Restrict a field to digits only.
         *
         * @param {HTMLElement|null} input
         */
        function setupDigitsOnlyField(input) {
            if (!input) return;

            input.addEventListener('input', function() {
                var cleaned = this.value.replace(/[^0-9]/g, '');
                if (this.value !== cleaned) {
                    this.value = cleaned;
                }
            });

            input.addEventListener('paste', function(e) {
                var paste = (e.clipboardData || window.clipboardData).getData('text');
                if (!/^[0-9]+$/.test(paste)) {
                    e.preventDefault();
                    var cleaned = paste.replace(/[^0-9]/g, '');
                    if (cleaned) {
                        document.execCommand('insertText', false, cleaned);
                    }
                }
            });
        }

        textFieldsForNames.forEach(setupNameField);
        setupDigitsOnlyField(postalCodeField);

        // ============================================
        // MODAL STEP NAVIGATION
        // ============================================
        function goToModalStep(step) {
            if (step > currentModalStep && !validateModalStep(currentModalStep)) {
                return;
            }

            currentModalStep = step;

            modalSteps.forEach(function(el, index) {
                if (!el) return;
                var stepNumber = index + 1;
                if (stepNumber === step) {
                    el.classList.add('active');
                    el.style.display = 'block';
                } else {
                    el.classList.remove('active');
                    el.style.display = 'none';
                }
            });

            progressSteps.forEach(function(el, index) {
                var n = index + 1;
                el.classList.remove('active', 'completed');
                if (n === step) {
                    el.classList.add('active');
                } else if (n < step) {
                    el.classList.add('completed');
                }
            });

            progressLines.forEach(function(el, index) {
                el.classList.remove('completed');
                if (index + 1 < step) {
                    el.classList.add('completed');
                }
            });

            if (step === 3) {
                updateAddressPreview();
            }

            var currentStepEl = document.getElementById('modalStep' + step);
            if (currentStepEl) {
                var firstInput = currentStepEl.querySelector('input, select');
                if (firstInput) {
                    setTimeout(function() { firstInput.focus(); }, 100);
                }
            }
        }

        function validateModalStep(step) {
            if (step === 1) {
                var block = document.getElementById('block');
                if (!block || !block.value.trim()) {
                    showNotification('Block/Street is required.', 'error');
                    if (block) block.focus();
                    return false;
                }
            }
            if (step === 2) {
                var city = document.getElementById('city');
                if (!city || !city.value.trim()) {
                    showNotification('City is required.', 'error');
                    if (city) city.focus();
                    return false;
                }
            }
            return true;
        }

        function updateAddressPreview() {
            var previewText = document.getElementById('addressPreviewText');
            if (!previewText) return;

            var getVal = function(id) {
                var el = document.getElementById(id);
                return el ? el.value : '';
            };

            var label      = getVal('address_label');
            var block      = getVal('block');
            var barangay   = getVal('barangay');
            var city       = getVal('city');
            var province   = getVal('province');
            var region     = getVal('region');
            var postalCode = getVal('postal_code');
            var country    = getVal('country') || 'Philippines';

            var parts = [];
            if (block)      parts.push(block);
            if (barangay)   parts.push(barangay);
            if (city)       parts.push(city);
            if (province)   parts.push(province);
            if (region)     parts.push(region);
            if (postalCode) parts.push(postalCode);
            if (country)    parts.push(country);

            if (parts.length > 0) {
                var labelText = label ? '[' + label + '] ' : '';
                previewText.textContent = labelText + parts.join(', ');
            } else {
                previewText.textContent = 'Fill in all fields to see preview';
            }
        }

        nextStepBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var nextStep = parseInt(this.dataset.next, 10);
                if (nextStep <= totalModalSteps) {
                    goToModalStep(nextStep);
                }
            });
        });

        prevStepBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var prevStep = parseInt(this.dataset.prev, 10);
                if (prevStep >= 1) {
                    goToModalStep(prevStep);
                }
            });
        });

        // ============================================
        // ADDRESS MODAL OPEN/CLOSE
        //
        // Lock scroll FIRST, while the modal is hidden, so the page
        // reflows to fill the space the scrollbar was using before
        // anything is visible. On close, unlock only after the modal
        // is fully hidden so the reverse reflow is not visible either.
        // ============================================
        function openAddressModal(title, addressData) {
            addressData = addressData || null;
            if (addressModalTitle) addressModalTitle.textContent = title;

            goToModalStep(1);

            if (addressData && addressData.customer_address_id) {
                if (addressId) addressId.value = addressData.customer_address_id || '';

                setFieldValue('address_label', addressData.label       || '');
                setFieldValue('block',         addressData.block       || '');
                setFieldValue('barangay',      addressData.barangay    || '');
                setFieldValue('city',          addressData.city        || '');
                setFieldValue('province',      addressData.province    || '');
                setFieldValue('region',        addressData.region      || '');
                setFieldValue('postal_code',   addressData.postal_code || '');
                setFieldValue('country',       addressData.country     || 'Philippines');

                if (addressForm) {
                    var actionInput = addressForm.querySelector('input[name="action"]');
                    if (actionInput) actionInput.value = 'update_address';
                }
            } else {
                if (addressForm) addressForm.reset();
                setFieldValue('country', 'Philippines');
                if (addressForm) {
                    var actionInput2 = addressForm.querySelector('input[name="action"]');
                    if (actionInput2) actionInput2.value = 'add_address';
                }
            }

            lockBodyScroll();

            if (addressModal) addressModal.classList.add('active');

            setTimeout(updateAddressPreview, 100);
        }

        function setFieldValue(id, value) {
            var el = document.getElementById(id);
            if (el) el.value = value;
        }

        function closeAddressModalHandler() {
            if (addressModal) addressModal.classList.remove('active');

            setTimeout(function() {
                if (!addressModal || !addressModal.classList.contains('active')) {
                    if (addressModal) addressModal.style.display = '';
                    unlockBodyScroll();
                }
            }, 0);

            if (addressForm) addressForm.reset();
            setFieldValue('country', 'Philippines');
            goToModalStep(1);
        }

        if (addAddressBtn) {
            addAddressBtn.addEventListener('click', function(e) {
                e.preventDefault();
                openAddressModal('Add New Address');
            });
        }

        if (closeAddressModal) {
            closeAddressModal.addEventListener('click', closeAddressModalHandler);
        }

        if (addressModal) {
            addressModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeAddressModalHandler();
                }
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;

            if (addressModal && addressModal.classList.contains('active')) {
                closeAddressModalHandler();
            }
            if (deleteAddressModal && deleteAddressModal.classList.contains('active')) {
                closeDeleteModal();
            }
        });

        document.querySelectorAll('#addressForm .form-control').forEach(function(input) {
            input.addEventListener('input', function() {
                if (currentModalStep === 3) updateAddressPreview();
            });
            input.addEventListener('change', function() {
                if (currentModalStep === 3) updateAddressPreview();
            });
        });

        // ============================================
        // EDIT ADDRESS
        // ============================================
        editAddressBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.dataset.id;
                openAddressModal('Edit Address', { customer_address_id: id });
            });
        });

        // ============================================
        // DELETE ADDRESS
        // ============================================
        function openDeleteModal(addressIdValue) {
            deleteAddressId = addressIdValue;

            lockBodyScroll();

            if (deleteAddressModal) deleteAddressModal.classList.add('active');
        }

        function closeDeleteModal() {
            if (deleteAddressModal) deleteAddressModal.classList.remove('active');

            setTimeout(function() {
                if (!deleteAddressModal || !deleteAddressModal.classList.contains('active')) {
                    unlockBodyScroll();
                }
            }, 0);

            deleteAddressId = null;
        }

        deleteAddressBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (this.disabled) return;
                openDeleteModal(this.dataset.id);
            });
        });

        if (cancelDeleteModal) {
            cancelDeleteModal.addEventListener('click', closeDeleteModal);
        }

        if (deleteAddressModal) {
            deleteAddressModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeDeleteModal();
                }
            });
        }

        if (confirmDeleteModal) {
            confirmDeleteModal.addEventListener('click', function() {
                if (!deleteAddressId) return;

                var formData = new FormData();
                formData.append('csrf_token', resolveCsrfToken());
                formData.append('action', 'delete_address');
                formData.append('address_id', deleteAddressId);

                fetch('../backend/handlers/address-handler.php', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.status === 'success') {
                        showNotification('Address deleted successfully', 'success');

                        if (window.location.hash) {
                            history.replaceState(
                                null,
                                '',
                                window.location.pathname + window.location.search
                            );
                        }

                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        showNotification(data.message || 'Failed to delete address', 'error');
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    showNotification('Network error. Please try again.', 'error');
                })
                .finally(function() {
                    closeDeleteModal();
                });
            });
        }

        // ============================================
        // ADDRESS FORM SUBMISSION
        // ============================================
        if (addressForm) {
            addressForm.addEventListener('submit', function(e) {
                e.preventDefault();

                var block = document.getElementById('block');
                var city  = document.getElementById('city');

                if (!block || !block.value.trim()) {
                    showNotification('Block/Street is required.', 'error');
                    goToModalStep(1);
                    if (block) block.focus();
                    return;
                }
                if (!city || !city.value.trim()) {
                    showNotification('City is required.', 'error');
                    goToModalStep(2);
                    if (city) city.focus();
                    return;
                }

                var formData = new FormData(this);

                if (saveAddressBtn) {
                    saveAddressBtn.disabled = true;
                    saveAddressBtn.textContent = 'Saving...';
                }

                fetch('../backend/handlers/address-handler.php', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.status === 'success') {
                        showNotification('Address saved successfully', 'success');
                        closeAddressModalHandler();

                        if (window.location.hash) {
                            history.replaceState(
                                null,
                                '',
                                window.location.pathname + window.location.search
                            );
                        }

                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        showNotification(data.message || 'Failed to save address', 'error');
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    showNotification('Network error. Please try again.', 'error');
                })
                .finally(function() {
                    if (saveAddressBtn) {
                        saveAddressBtn.disabled = false;
                        saveAddressBtn.textContent = 'Save Address';
                    }
                });
            });
        }

        // ============================================
        // NOTIFICATION SYSTEM
        // ============================================
        function showNotification(message, type) {
            var existing = document.querySelector('.profile-notification');
            if (existing) existing.remove();

            var notification = document.createElement('div');
            notification.className = 'profile-notification ' + type;
            notification.setAttribute('role', 'alert');

            var iconFile = type === 'success' ? 'verified-fill.svg' : 'file-warning-fill.svg';

            var iconSpan = document.createElement('span');
            iconSpan.className = 'notification-icon';

            var iconImg = document.createElement('img');
            iconImg.src = '../../shared/assets/images/icons/' + iconFile;
            iconImg.alt = type;

            iconSpan.appendChild(iconImg);

            var messageSpan = document.createElement('span');
            messageSpan.className = 'notification-message';
            messageSpan.textContent = message;

            notification.appendChild(iconSpan);
            notification.appendChild(messageSpan);
            document.body.appendChild(notification);

            setTimeout(function() {
                notification.style.animation = 'fadeOut 0.3s ease';
                setTimeout(function() {
                    notification.remove();
                }, 300);
            }, 3000);
        }

        // ============================================
        // INITIAL SETUP
        // ============================================
        var firstActiveTab = document.querySelector('.profile-tab.active');
        if (!firstActiveTab) {
            var firstTab = document.querySelector('.profile-tab');
            if (firstTab) {
                firstTab.classList.add('active');
                var firstTabId = firstTab.dataset.tab;
                var firstContent = document.getElementById('tab-' + firstTabId);
                if (firstContent) firstContent.classList.add('active');
            }
        }

        if (modalStep1) {
            modalStep1.classList.add('active');
            modalStep1.style.display = 'block';
        }
        if (modalStep2) modalStep2.style.display = 'none';
        if (modalStep3) modalStep3.style.display = 'none';

        // ============================================
        // DEEP-LINK HANDLING (consumed once)
        // ============================================
        (function consumeDeepLink() {
            if (!window.location.hash) return;

            var hash = window.location.hash.toLowerCase().replace(/^#/, '');

            var isAddressesHash =
                hash === 'addresses' ||
                hash === 'add-address' ||
                hash === 'addaddress' ||
                hash === 'edit-address' ||
                hash === 'editaddress';

            if (!isAddressesHash) return;

            history.replaceState(
                null,
                '',
                window.location.pathname + window.location.search
            );

            var addressesTab = document.getElementById('tabBtnAddresses');
            if (addressesTab) {
                addressesTab.click();
            } else {
                var fallback = document.querySelector('.profile-tab[data-tab="addresses"]');
                if (fallback) fallback.click();
            }

            if (hash === 'add-address' || hash === 'addaddress') {
                setTimeout(function() {
                    var btn = document.getElementById('addAddressBtn');
                    if (btn) btn.click();
                }, 250);
            }
        })();
    });
})();