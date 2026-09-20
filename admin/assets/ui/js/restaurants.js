/**
 * FitPal Admin Restaurants Page
 *
 * Handles:
 * - Actions dropdown toggling
 * - View Details modal population
 * - Contextual bulk selection (only when checkboxes are present)
 * - Bulk action submission
 * - Single row action submission (Approve/Deny/Suspend/Reinstate)
 *
 * @package FitPal
 * @version 2.0
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        const cfg = window.FITPAL_ADMIN_RESTAURANTS || {};
        const csrfToken = cfg.csrfToken || '';
        const handlerUrl = cfg.handlerUrl || '../backend/handlers/admin-handler.php';
        const showBulkBar = cfg.showBulkBar || false;

        // Elements
        const verifyForm = document.getElementById('verifyForm');
        const verifyRestaurantId = document.getElementById('verifyRestaurantId');
        const verifyStatus = document.getElementById('verifyStatus');
        
        const bulkBar = document.getElementById('bulkBar');
        const bulkCount = document.getElementById('bulkCount');
        const bulkClear = document.getElementById('bulkClear');
        
        const detailsModal = document.getElementById('detailsModal');
        const modalRestName = document.getElementById('modalRestName');
        const modalRestOwner = document.getElementById('modalRestOwner');
        const modalRestEmail = document.getElementById('modalRestEmail');
        const modalRestPhone = document.getElementById('modalRestPhone');
        const modalRestBranches = document.getElementById('modalRestBranches');
        const modalRestSubmitted = document.getElementById('modalRestSubmitted');

        // ============================================
        // ACTIONS DROPDOWN
        // ============================================
        document.querySelectorAll('.actions-trigger').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                const dropdown = this.closest('.actions-dropdown');
                const isOpen = dropdown.classList.contains('open');
                
                // Close all others
                document.querySelectorAll('.actions-dropdown.open').forEach(d => d.classList.remove('open'));
                
                if (!isOpen) {
                    dropdown.classList.add('open');
                }
            });
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function () {
            document.querySelectorAll('.actions-dropdown.open').forEach(d => d.classList.remove('open'));
        });

        // ============================================
        // VIEW DETAILS MODAL
        // ============================================
        document.querySelectorAll('.view-details-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                modalRestName.textContent = this.dataset.name;
                modalRestOwner.textContent = this.dataset.owner;
                modalRestEmail.textContent = this.dataset.email;
                modalRestPhone.textContent = this.dataset.phone;
                modalRestBranches.textContent = this.dataset.branches;
                modalRestSubmitted.textContent = this.dataset.submitted;
                
                detailsModal.classList.add('active');
            });
        });

        window.closeDetailsModal = function () {
            detailsModal.classList.remove('active');
        };

        // Close on overlay click
        detailsModal.querySelector('.admin-modal-overlay').addEventListener('click', closeDetailsModal);

        // ============================================
        // SINGLE ROW ACTIONS (from dropdown)
        // ============================================
        document.querySelectorAll('.action-item[data-action="verify_restaurant"]').forEach(btn => {
            btn.addEventListener('click', function () {
                const id = this.dataset.id;
                const status = this.dataset.status;
                const name = this.dataset.name;
                
                if (!id || !status) return;

                if (confirm(`Are you sure you want to mark "${name}" as ${status}?`)) {
                    verifyRestaurantId.value = id;
                    verifyStatus.value = status;
                    verifyForm.submit();
                }
            });
        });

        // ============================================
        // CONTEXTUAL BULK SELECTION
        // ============================================
        if (showBulkBar && bulkBar) {
            
            // Create checkboxes dynamically only if we are on a bulk-action tab
            const table = document.getElementById('restaurantsTable');
            if (table) {
                // Add header checkbox
                const headerRow = table.querySelector('thead tr');
                if (headerRow) {
                    const th = document.createElement('th');
                    th.className = 'col-check';
                    th.innerHTML = '<input type="checkbox" id="selectAll" aria-label="Select all">';
                    headerRow.prepend(th);
                }

                // Add body checkboxes
                table.querySelectorAll('tbody tr').forEach(row => {
                    const td = document.createElement('td');
                    td.className = 'col-check';
                    td.innerHTML = '<input type="checkbox" class="row-checkbox">';
                    row.prepend(td);
                });
            }

            const selectAll = document.getElementById('selectAll');
            const rowCheckboxes = document.querySelectorAll('.row-checkbox');
            const bulkActionBtns = document.querySelectorAll('.bulk-btn[data-bulk-status]');

            function updateBulkBar() {
                const checked = document.querySelectorAll('.row-checkbox:checked');
                const count = checked.length;
                
                bulkCount.textContent = count;
                
                if (count > 0) {
                    bulkBar.classList.add('active');
                } else {
                    bulkBar.classList.remove('active');
                }
                
                if (selectAll) {
                    selectAll.checked = count > 0 && count === rowCheckboxes.length;
                    selectAll.indeterminate = count > 0 && count < rowCheckboxes.length;
                }
            }

            // Select All handler
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    rowCheckboxes.forEach(cb => cb.checked = this.checked);
                    updateBulkBar();
                });
            }

            // Row checkbox handlers
            rowCheckboxes.forEach(cb => {
                cb.addEventListener('change', updateBulkBar);
            });

            // Clear bulk selection
            if (bulkClear) {
                bulkClear.addEventListener('click', function () {
                    rowCheckboxes.forEach(cb => cb.checked = false);
                    if (selectAll) selectAll.checked = false;
                    updateBulkBar();
                });
            }

            // Bulk action submission
            bulkActionBtns.forEach(btn => {
                btn.addEventListener('click', function () {
                    const status = this.dataset.bulkStatus;
                    const checked = document.querySelectorAll('.row-checkbox:checked');
                    
                    if (checked.length === 0) return;

                    if (confirm(`Are you sure you want to apply this action to ${checked.length} item(s)?`)) {
                        // Since we don't have a single form that handles bulk, 
                        // we'll create one dynamically to submit all IDs.
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = handlerUrl;

                        const csrfInput = document.createElement('input');
                        csrfInput.type = 'hidden';
                        csrfInput.name = 'csrf_token';
                        csrfInput.value = csrfToken;
                        form.appendChild(csrfInput);

                        const actionInput = document.createElement('input');
                        actionInput.type = 'hidden';
                        actionInput.name = 'action';
                        actionInput.value = 'verify_restaurant_bulk';
                        form.appendChild(actionInput);

                        const statusInput = document.createElement('input');
                        statusInput.type = 'hidden';
                        statusInput.name = 'status';
                        statusInput.value = status;
                        form.appendChild(statusInput);

                        checked.forEach(cb => {
                            const row = cb.closest('tr');
                            const id = row.dataset.rowId;
                            const idInput = document.createElement('input');
                            idInput.type = 'hidden';
                            idInput.name = 'restaurant_ids[]';
                            idInput.value = id;
                            form.appendChild(idInput);
                        });

                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        }

    });
})();