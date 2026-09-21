/**
 * FitPal Admin Restaurants Page
 *
 * Responsibilities owned by this file:
 *
 *   1. Backdrop click closes the detail modal by navigating to the
 *      URL in data-close-url on the backdrop.
 *
 *   2. Empty-state icon fallback: if the search-line icon inside
 *      .admin-table-empty-icon fails to load, the wrapper's
 *      data-fallback-src is swapped in.
 *
 *   3. Escape key closes the modal by navigating to the same
 *      data-close-url.
 *
 *   4. Tab switching inside the detail modal (Info / Branches /
 *      Accounts).
 *
 *   5. Existing dropdown, modal-population, and bulk-action logic.
 *      The DOM nodes this expects are not currently rendered by
 *      restaurants.php, so this block stays inert until they are.
 *
 * @package FitPal
 * @version 3.1 — Added modal tab switching, which was previously
 *                only present in dashboard.js and therefore never
 *                registered on this page.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // BACKDROP CLICK — NAVIGATE TO data-close-url
        // ============================================
        document.addEventListener('click', function (e) {
            var backdrop = e.target.closest('.admin-modal-backdrop[data-close-url]');
            if (!backdrop) return;

            var url = backdrop.getAttribute('data-close-url');
            if (!url) return;

            window.location.href = url;
        }, true);

        // ============================================
        // IMAGE ERROR — SWAP IN data-fallback-src
        // ============================================
        document.addEventListener('error', function (e) {
            var img = e.target;
            if (!img || img.tagName !== 'IMG') return;

            var wrapper = img.closest('.admin-table-empty-icon');
            if (!wrapper) return;

            if (img.dataset.fallbackApplied === '1') return;

            var fallback = wrapper.getAttribute('data-fallback-src');
            if (!fallback) return;

            img.dataset.fallbackApplied = '1';
            img.src = fallback;
        }, true);

        // ============================================
        // ESCAPE KEY — NAVIGATE TO data-close-url
        // ============================================
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;

            var openModal = document.querySelector('.admin-modal.is-open');
            if (!openModal) return;

            var backdrop = openModal.querySelector('.admin-modal-backdrop[data-close-url]');
            if (backdrop) {
                var url = backdrop.getAttribute('data-close-url');
                if (url) {
                    window.location.href = url;
                    return;
                }
            }

            var closeLink = openModal.querySelector('.admin-modal-close');
            if (closeLink) {
                closeLink.click();
            }
        });

        // ============================================
        // TAB SWITCHING INSIDE THE DETAIL MODAL
        // ============================================
        document.addEventListener('click', function (e) {
            var tab = e.target.closest('.admin-modal-tab');
            if (!tab) return;
            e.preventDefault();

            var tabsContainer = tab.closest('.admin-modal-tabs');
            if (!tabsContainer) return;

            var panelGroup = tabsContainer.parentElement;
            var targetId = tab.getAttribute('data-tab-target');
            if (!targetId) return;

            tabsContainer.querySelectorAll('.admin-modal-tab').forEach(function (t) {
                t.classList.toggle('active', t === tab);
            });

            panelGroup.querySelectorAll('.admin-modal-tab-panel').forEach(function (p) {
                p.classList.toggle('active', p.id === targetId);
            });
        });

        // ============================================
        // LEGACY DROPDOWN / MODAL / BULK LOGIC (guarded)
        // ============================================
        var cfg = window.FITPAL_ADMIN_RESTAURANTS || {};
        var csrfToken = cfg.csrfToken || '';
        var handlerUrl = cfg.handlerUrl || '../backend/handlers/admin-handler.php';
        var showBulkBar = cfg.showBulkBar || false;

        var verifyForm          = document.getElementById('verifyForm');
        var verifyRestaurantId  = document.getElementById('verifyRestaurantId');
        var verifyStatus        = document.getElementById('verifyStatus');

        var bulkBar   = document.getElementById('bulkBar');
        var bulkCount = document.getElementById('bulkCount');
        var bulkClear = document.getElementById('bulkClear');

        var detailsModal      = document.getElementById('detailsModal');
        var modalRestName     = document.getElementById('modalRestName');
        var modalRestOwner    = document.getElementById('modalRestOwner');
        var modalRestEmail    = document.getElementById('modalRestEmail');
        var modalRestPhone    = document.getElementById('modalRestPhone');
        var modalRestBranches = document.getElementById('modalRestBranches');
        var modalRestSubmitted = document.getElementById('modalRestSubmitted');

        // ---- Actions dropdown ----
        var actionsTriggers = document.querySelectorAll('.actions-trigger');
        if (actionsTriggers.length > 0) {
            actionsTriggers.forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var dropdown = this.closest('.actions-dropdown');
                    if (!dropdown) return;
                    var isOpen = dropdown.classList.contains('open');

                    document.querySelectorAll('.actions-dropdown.open').forEach(function (d) {
                        d.classList.remove('open');
                    });

                    if (!isOpen) {
                        dropdown.classList.add('open');
                    }
                });
            });

            document.addEventListener('click', function () {
                document.querySelectorAll('.actions-dropdown.open').forEach(function (d) {
                    d.classList.remove('open');
                });
            });
        }

        // ---- View-details modal population ----
        var viewButtons = document.querySelectorAll('.view-details-btn');
        if (viewButtons.length > 0 && detailsModal) {
            viewButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (modalRestName)      modalRestName.textContent      = this.dataset.name;
                    if (modalRestOwner)     modalRestOwner.textContent     = this.dataset.owner;
                    if (modalRestEmail)     modalRestEmail.textContent     = this.dataset.email;
                    if (modalRestPhone)     modalRestPhone.textContent     = this.dataset.phone;
                    if (modalRestBranches)  modalRestBranches.textContent  = this.dataset.branches;
                    if (modalRestSubmitted) modalRestSubmitted.textContent = this.dataset.submitted;

                    detailsModal.classList.add('active');
                });
            });

            window.closeDetailsModal = function () {
                detailsModal.classList.remove('active');
            };

            var overlay = detailsModal.querySelector('.admin-modal-overlay');
            if (overlay) {
                overlay.addEventListener('click', window.closeDetailsModal);
            }
        }

        // ---- Single-row verify actions ----
        var singleActionBtns = document.querySelectorAll('.action-item[data-action="verify_restaurant"]');
        if (singleActionBtns.length > 0 && verifyForm) {
            singleActionBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id     = this.dataset.id;
                    var status = this.dataset.status;
                    var name   = this.dataset.name;

                    if (!id || !status) return;

                    if (confirm('Are you sure you want to mark "' + name + '" as ' + status + '?')) {
                        if (verifyRestaurantId) verifyRestaurantId.value = id;
                        if (verifyStatus)       verifyStatus.value       = status;
                        verifyForm.submit();
                    }
                });
            });
        }

        // ---- Contextual bulk selection ----
        if (showBulkBar && bulkBar) {

            var table = document.getElementById('restaurantsTable');
            if (table) {
                var headerRow = table.querySelector('thead tr');
                if (headerRow) {
                    var th = document.createElement('th');
                    th.className = 'col-check';
                    th.innerHTML = '<input type="checkbox" id="selectAll" aria-label="Select all">';
                    headerRow.prepend(th);
                }

                table.querySelectorAll('tbody tr').forEach(function (row) {
                    var td = document.createElement('td');
                    td.className = 'col-check';
                    td.innerHTML = '<input type="checkbox" class="row-checkbox">';
                    row.prepend(td);
                });
            }

            var selectAll = document.getElementById('selectAll');
            var rowCheckboxes = document.querySelectorAll('.row-checkbox');
            var bulkActionBtns = document.querySelectorAll('.bulk-btn[data-bulk-status]');

            function updateBulkBar() {
                var checked = document.querySelectorAll('.row-checkbox:checked');
                var count = checked.length;

                if (bulkCount) bulkCount.textContent = count;

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

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    rowCheckboxes.forEach(function (cb) { cb.checked = this.checked; }.bind(this));
                    updateBulkBar();
                });
            }

            rowCheckboxes.forEach(function (cb) {
                cb.addEventListener('change', updateBulkBar);
            });

            if (bulkClear) {
                bulkClear.addEventListener('click', function () {
                    rowCheckboxes.forEach(function (cb) { cb.checked = false; });
                    if (selectAll) selectAll.checked = false;
                    updateBulkBar();
                });
            }

            bulkActionBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var status = this.dataset.bulkStatus;
                    var checked = document.querySelectorAll('.row-checkbox:checked');

                    if (checked.length === 0) return;

                    if (confirm('Are you sure you want to apply this action to ' + checked.length + ' item(s)?')) {
                        var form = document.createElement('form');
                        form.method = 'POST';
                        form.action = handlerUrl;

                        var csrfInput = document.createElement('input');
                        csrfInput.type = 'hidden';
                        csrfInput.name = 'csrf_token';
                        csrfInput.value = csrfToken;
                        form.appendChild(csrfInput);

                        var actionInput = document.createElement('input');
                        actionInput.type = 'hidden';
                        actionInput.name = 'action';
                        actionInput.value = 'verify_restaurant_bulk';
                        form.appendChild(actionInput);

                        var statusInput = document.createElement('input');
                        statusInput.type = 'hidden';
                        statusInput.name = 'status';
                        statusInput.value = status;
                        form.appendChild(statusInput);

                        checked.forEach(function (cb) {
                            var row = cb.closest('tr');
                            var id = row ? row.dataset.rowId : '';
                            if (!id) return;
                            var idInput = document.createElement('input');
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