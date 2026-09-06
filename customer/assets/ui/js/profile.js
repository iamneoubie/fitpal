/**
 * FitPal Customer Profile JavaScript
 * Version 2.4 - Fixed multi-step modal step display
 *
 * @package FitPal
 * @version 2.4
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        // ===== DOM ELEMENTS =====
        // Tabs
        var tabs = document.querySelectorAll('.profile-tab');
        var tabContents = document.querySelectorAll('.profile-tab-content');

        // Profile Edit
        var editProfileBtn = document.getElementById('editProfileBtn');
        var cancelEditBtn = document.getElementById('cancelEditBtn');
        var profileActions = document.getElementById('profileActions');
        var profileForm = document.getElementById('profileForm');
        var formInputs = profileForm ? profileForm.querySelectorAll('input, select') : [];

        // Address Modal - Multi-step
        var addAddressBtn = document.getElementById('addAddressBtn');
        var addressModal = document.getElementById('addressModal');
        var closeAddressModal = document.getElementById('closeAddressModal');
        var addressForm = document.getElementById('addressForm');
        var addressModalTitle = document.getElementById('addressModalTitle');
        var addressId = document.getElementById('addressId');
        var saveAddressBtn = document.getElementById('saveAddressBtn');

        // Modal steps - FIXED: Get all step elements
        var modalStep1 = document.getElementById('modalStep1');
        var modalStep2 = document.getElementById('modalStep2');
        var modalStep3 = document.getElementById('modalStep3');
        var modalSteps = [modalStep1, modalStep2, modalStep3];

        var progressSteps = document.querySelectorAll('.modal-progress .progress-step');
        var progressLines = document.querySelectorAll('.modal-progress .progress-line');
        var nextStepBtns = document.querySelectorAll('.btn-next-step');
        var prevStepBtns = document.querySelectorAll('.btn-prev-step');

        // Delete Address Modal
        var deleteAddressModal = document.getElementById('deleteAddressModal');
        var cancelDeleteModal = document.getElementById('cancelDeleteModal');
        var confirmDeleteModal = document.getElementById('confirmDeleteModal');

        var editAddressBtns = document.querySelectorAll('.edit-address');
        var deleteAddressBtns = document.querySelectorAll('.delete-address');

        // ===== STATE =====
        var isEditing = false;
        var deleteAddressId = null;
        var currentModalStep = 1;
        var totalModalSteps = 3;

        // ===== TABS =====
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
                var tabId = this.dataset.tab;
                switchTab(tabId);
            });
        });

        // ===== PROFILE EDIT MODE =====
        if (editProfileBtn) {
            editProfileBtn.addEventListener('click', function() {
                isEditing = true;
                this.style.display = 'none';
                profileActions.style.display = 'flex';
                formInputs.forEach(function(input) {
                    input.disabled = false;
                });
            });
        }

        if (cancelEditBtn) {
            cancelEditBtn.addEventListener('click', function() {
                isEditing = false;
                editProfileBtn.style.display = 'inline-flex';
                profileActions.style.display = 'none';
                formInputs.forEach(function(input) {
                    input.disabled = true;
                });
                location.reload();
            });
        }

        // ===== MODAL STEP NAVIGATION - FIXED =====
        function goToModalStep(step) {
            // Validate current step before moving forward
            if (step > currentModalStep && !validateModalStep(currentModalStep)) {
                return;
            }

            currentModalStep = step;

            // Update steps - show/hide
            modalSteps.forEach(function(el, index) {
                var stepNumber = index + 1;
                if (el) {
                    if (stepNumber === step) {
                        el.classList.add('active');
                        el.style.display = 'block';
                    } else {
                        el.classList.remove('active');
                        el.style.display = 'none';
                    }
                }
            });

            // Update progress
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

            // Update address preview on step 3
            if (step === 3) {
                updateAddressPreview();
            }

            // Focus first input in the current step
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
                    block?.focus();
                    return false;
                }
            }
            if (step === 2) {
                var city = document.getElementById('city');
                if (!city || !city.value.trim()) {
                    showNotification('City is required.', 'error');
                    city?.focus();
                    return false;
                }
            }
            return true;
        }

        function updateAddressPreview() {
            var previewText = document.getElementById('addressPreviewText');
            if (!previewText) return;

            var label = document.getElementById('address_label')?.value || '';
            var block = document.getElementById('block')?.value || '';
            var barangay = document.getElementById('barangay')?.value || '';
            var city = document.getElementById('city')?.value || '';
            var province = document.getElementById('province')?.value || '';
            var region = document.getElementById('region')?.value || '';
            var postalCode = document.getElementById('postal_code')?.value || '';
            var country = document.getElementById('country')?.value || 'Philippines';

            var parts = [];
            if (block) parts.push(block);
            if (barangay) parts.push(barangay);
            if (city) parts.push(city);
            if (province) parts.push(province);
            if (region) parts.push(region);
            if (postalCode) parts.push(postalCode);
            if (country) parts.push(country);

            if (parts.length > 0) {
                var labelText = label ? '[' + label + '] ' : '';
                previewText.textContent = labelText + parts.join(', ');
            } else {
                previewText.textContent = 'Fill in all fields to see preview';
            }
        }

        // Next step buttons
        nextStepBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var nextStep = parseInt(this.dataset.next, 10);
                if (nextStep <= totalModalSteps) {
                    goToModalStep(nextStep);
                }
            });
        });

        // Previous step buttons
        prevStepBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var prevStep = parseInt(this.dataset.prev, 10);
                if (prevStep >= 1) {
                    goToModalStep(prevStep);
                }
            });
        });

        // ===== ADDRESS MODAL =====
        function openAddressModal(title, addressData) {
            addressData = addressData || null;
            addressModalTitle.textContent = title;

            // Reset to step 1
            goToModalStep(1);

            if (addressData && addressData.customer_address_id) {
                addressId.value = addressData.customer_address_id || '';
                document.getElementById('address_label').value = addressData.label || '';
                document.getElementById('block').value = addressData.block || '';
                document.getElementById('barangay').value = addressData.barangay || '';
                document.getElementById('city').value = addressData.city || '';
                document.getElementById('province').value = addressData.province || '';
                document.getElementById('region').value = addressData.region || '';
                document.getElementById('postal_code').value = addressData.postal_code || '';
                document.getElementById('country').value = addressData.country || 'Philippines';
                addressForm.querySelector('input[name="action"]').value = 'update_address';
            } else {
                addressForm.reset();
                document.getElementById('country').value = 'Philippines';
                addressForm.querySelector('input[name="action"]').value = 'add_address';
            }

            addressModal.classList.add('active');
            document.body.style.overflow = 'hidden';

            setTimeout(function() {
                updateAddressPreview();
            }, 100);
        }

        function closeAddressModalHandler() {
            addressModal.classList.remove('active');
            document.body.style.overflow = '';
            addressForm.reset();
            document.getElementById('country').value = 'Philippines';
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

        addressModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeAddressModalHandler();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (addressModal.classList.contains('active')) {
                    closeAddressModalHandler();
                }
                if (deleteAddressModal.classList.contains('active')) {
                    closeDeleteModal();
                }
            }
        });

        // Real-time preview updates
        document.querySelectorAll('#addressForm .form-control').forEach(function(input) {
            input.addEventListener('input', function() {
                if (currentModalStep === 3) {
                    updateAddressPreview();
                }
            });
            input.addEventListener('change', function() {
                if (currentModalStep === 3) {
                    updateAddressPreview();
                }
            });
        });

        // ===== EDIT ADDRESS =====
        editAddressBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var addressIdValue = this.dataset.id;
                openAddressModal('Edit Address', { customer_address_id: addressIdValue });
            });
        });

        // ===== DELETE ADDRESS =====
        function openDeleteModal(addressIdValue) {
            deleteAddressId = addressIdValue;
            deleteAddressModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeDeleteModal() {
            deleteAddressModal.classList.remove('active');
            document.body.style.overflow = '';
            deleteAddressId = null;
        }

        deleteAddressBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (this.disabled) return;
                var addressIdValue = this.dataset.id;
                openDeleteModal(addressIdValue);
            });
        });

        if (cancelDeleteModal) {
            cancelDeleteModal.addEventListener('click', closeDeleteModal);
        }

        deleteAddressModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });

        if (confirmDeleteModal) {
            confirmDeleteModal.addEventListener('click', function() {
                if (!deleteAddressId) return;

                var formData = new FormData();
                var csrfToken = document.querySelector('input[name="csrf_token"]');
                formData.append('csrf_token', csrfToken ? csrfToken.value : '');
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

        // ===== ADDRESS FORM SUBMISSION =====
        if (addressForm) {
            addressForm.addEventListener('submit', function(e) {
                e.preventDefault();

                // Final validation
                var block = document.getElementById('block');
                var city = document.getElementById('city');
                if (!block || !block.value.trim()) {
                    showNotification('Block/Street is required.', 'error');
                    goToModalStep(1);
                    block?.focus();
                    return;
                }
                if (!city || !city.value.trim()) {
                    showNotification('City is required.', 'error');
                    goToModalStep(2);
                    city?.focus();
                    return;
                }

                var formData = new FormData(this);

                saveAddressBtn.disabled = true;
                saveAddressBtn.textContent = 'Saving...';

                fetch('../backend/handlers/address-handler.php', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.status === 'success') {
                        showNotification('Address saved successfully', 'success');
                        closeAddressModalHandler();
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
                    saveAddressBtn.disabled = false;
                    saveAddressBtn.textContent = 'Save Address';
                });
            });
        }

        // ===== NOTIFICATION SYSTEM =====
        function showNotification(message, type) {
            var existingNotification = document.querySelector('.profile-notification');
            if (existingNotification) {
                existingNotification.remove();
            }

            var notification = document.createElement('div');
            notification.className = 'profile-notification ' + type;
            notification.setAttribute('role', 'alert');

            var iconFile = type === 'success' ? 'verified-fill.svg' : 'file-warning-fill.svg';

            notification.innerHTML =
                '<span class="notification-icon">' +
                    '<img src="../../shared/assets/images/icons/' + iconFile + '" alt="' + type + '">' +
                '</span>' +
                '<span class="notification-message">' + message + '</span>';

            document.body.appendChild(notification);

            setTimeout(function() {
                notification.style.animation = 'fadeOut 0.3s ease';
                setTimeout(function() {
                    notification.remove();
                }, 300);
            }, 3000);
        }

        // ===== INITIAL SETUP =====
        // Ensure first tab is active
        var firstActiveTab = document.querySelector('.profile-tab.active');
        if (!firstActiveTab) {
            var firstTab = document.querySelector('.profile-tab');
            if (firstTab) {
                firstTab.classList.add('active');
                var firstTabId = firstTab.dataset.tab;
                var firstContent = document.getElementById('tab-' + firstTabId);
                if (firstContent) {
                    firstContent.classList.add('active');
                }
            }
        }

        // Ensure step 1 is visible initially
        if (modalStep1) {
            modalStep1.classList.add('active');
            modalStep1.style.display = 'block';
        }
        if (modalStep2) {
            modalStep2.style.display = 'none';
        }
        if (modalStep3) {
            modalStep3.style.display = 'none';
        }

        console.log('Profile JS v2.4 initialized');
    });
})();