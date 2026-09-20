/**
 * FitPal Admin — Riders Page
 *
 * Responsibilities:
 *
 *   1. Backdrop click closes the detail modal by navigating to the
 *      URL in data-close-url on the backdrop.
 *
 *   2. Image error handling, capture phase (error does not bubble):
 *      - .admin-cell-avatar img  → replace image with the rider's
 *        initial from data-initial.
 *      - .admin-doc-image img    → replace wrapper content with the
 *        empty-state block.
 *      - .admin-table-empty-icon img → swap in data-fallback-src.
 *
 *   3. Escape key closes the modal by navigating to the same
 *      data-close-url.
 *
 *   4. Tab switching inside the detail modal (Information /
 *      Documents / Deliveries).
 *
 *   5. Row verification and bulk-action logic (guarded: only runs
 *      when #ridersTable and .row-checkbox elements are present in
 *      the DOM, which the current riders.php does not render).
 *
 * @package FitPal
 * @version 2.1 — Added modal tab switching, which was previously
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
        // IMAGE ERROR — CONTEXT-SPECIFIC FALLBACKS
        // ============================================
        document.addEventListener('error', function (e) {
            var img = e.target;
            if (!img || img.tagName !== 'IMG') return;

            // --- 1. Avatar fallback: replace image with initial ---
            var avatarCell = img.closest('.admin-cell-avatar');
            if (avatarCell && avatarCell.contains(img)) {
                if (img.dataset.fallbackApplied === '1') return;
                img.dataset.fallbackApplied = '1';

                var initial = img.getAttribute('data-initial') || '';
                avatarCell.textContent = initial;
                return;
            }

            // --- 2. Document preview fallback: empty-state block ---
            var docImage = img.closest('.admin-doc-image');
            if (docImage && docImage.contains(img)) {
                if (docImage.dataset.fallbackApplied === '1') return;
                docImage.dataset.fallbackApplied = '1';

                docImage.innerHTML =
                    '<div class="admin-doc-empty">' +
                    '<span>Image could not be loaded.</span>' +
                    '</div>';
                return;
            }

            // --- 3. Empty-state icon fallback: swap src ---
            var emptyIcon = img.closest('.admin-table-empty-icon');
            if (emptyIcon && emptyIcon.contains(img)) {
                if (img.dataset.fallbackApplied === '1') return;

                var fallback = emptyIcon.getAttribute('data-fallback-src');
                if (!fallback) return;

                img.dataset.fallbackApplied = '1';
                img.src = fallback;
                return;
            }
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
        // ROW VERIFICATION + BULK ACTIONS (guarded)
        // ============================================
        var CFG = window.FITPAL_ADMIN_RIDERS || {};
        var HANDLER_URL = CFG.handlerUrl || '../backend/handlers/admin-handler.php';
        var CSRF = CFG.csrfToken || '';

        var table     = document.getElementById('ridersTable');
        var selectAll = document.getElementById('selectAll');
        var bulkBar   = document.getElementById('bulkBar');
        var bulkCount = document.getElementById('bulkCount');
        var bulkClear = document.getElementById('bulkClear');

        if (!table) return;

        var rowCheckboxes = Array.prototype.slice.call(
            table.querySelectorAll('.row-checkbox')
        );

        function refreshBulkBar() {
            var selected = rowCheckboxes.filter(function (cb) { return cb.checked; });
            var count = selected.length;

            if (bulkCount) bulkCount.textContent = String(count);
            if (bulkBar)   bulkBar.hidden = count === 0;

            rowCheckboxes.forEach(function (cb) {
                var row = cb.closest('tr');
                if (row) row.classList.toggle('is-selected', cb.checked);
            });

            if (selectAll) {
                selectAll.checked = count > 0 && count === rowCheckboxes.length;
                selectAll.indeterminate = count > 0 && count < rowCheckboxes.length;
            }
        }

        rowCheckboxes.forEach(function (cb) {
            cb.addEventListener('change', refreshBulkBar);
        });

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                var on = selectAll.checked;
                rowCheckboxes.forEach(function (cb) { cb.checked = on; });
                refreshBulkBar();
            });
        }

        if (bulkClear) {
            bulkClear.addEventListener('click', function () {
                rowCheckboxes.forEach(function (cb) { cb.checked = false; });
                refreshBulkBar();
            });
        }

        table.addEventListener('click', function (e) {
            var btn = e.target.closest('.row-btn');
            if (!btn) return;

            e.preventDefault();

            var action = btn.getAttribute('data-action');
            var id     = btn.getAttribute('data-id');
            var status = btn.getAttribute('data-status');
            var name   = btn.getAttribute('data-name') || 'this rider';

            if (!action || !id || !status) return;

            var verb = statusLabel(status);
            if (!window.confirm('Mark "' + name + '" as ' + verb + '?')) return;

            var original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="btn-label">Saving…</span>';

            var formData = new FormData();
            formData.append('csrf_token', CSRF);
            formData.append('action', action);
            formData.append('rider_id', id);
            formData.append('status', status);

            fetch(HANDLER_URL, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) {
                return res.text().then(function (text) {
                    try { return JSON.parse(text); }
                    catch (err) { throw new Error('Server returned: ' + text.slice(0, 200)); }
                });
            })
            .then(function (data) {
                if (data && data.status === 'success') {
                    window.location.reload();
                } else {
                    btn.disabled = false;
                    btn.innerHTML = original;
                    window.alert((data && data.message) || 'Could not update status.');
                }
            })
            .catch(function (err) {
                console.error('[riders.js] verify failed:', err);
                btn.disabled = false;
                btn.innerHTML = original;
                window.alert('Network error. Please try again.');
            });
        });

        if (bulkBar) {
            bulkBar.addEventListener('click', function (e) {
                var btn = e.target.closest('.bulk-btn[data-bulk-status]');
                if (!btn) return;

                e.preventDefault();

                var targetStatus = btn.getAttribute('data-bulk-status');
                var selected = rowCheckboxes
                    .filter(function (cb) { return cb.checked; })
                    .map(function (cb) { return cb.value; });

                if (selected.length === 0) return;

                var verb = statusLabel(targetStatus);
                if (!window.confirm('Mark ' + selected.length + ' rider(s) as ' + verb + '?')) return;

                var buttons = bulkBar.querySelectorAll('.bulk-btn');
                buttons.forEach(function (b) { b.disabled = true; });

                var promises = selected.map(function (id) {
                    var formData = new FormData();
                    formData.append('csrf_token', CSRF);
                    formData.append('action', 'verify_rider');
                    formData.append('rider_id', id);
                    formData.append('status', targetStatus);

                    return fetch(HANDLER_URL, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    }).then(function (res) { return res.json(); });
                });

                Promise.all(promises)
                    .then(function (results) {
                        var failed = results.filter(function (r) {
                            return !r || r.status !== 'success';
                        });
                        if (failed.length > 0) {
                            window.alert(
                                failed.length + ' of ' + selected.length +
                                ' updates failed. Reloading to reflect the current state.'
                            );
                        }
                        window.location.reload();
                    })
                    .catch(function (err) {
                        console.error('[riders.js] bulk action failed:', err);
                        buttons.forEach(function (b) { b.disabled = false; });
                        window.alert('Network error. Please try again.');
                    });
            });
        }

        function statusLabel(status) {
            switch (status) {
                case 'verified':  return 'Verified';
                case 'denied':    return 'Denied';
                case 'suspended': return 'Suspended';
                case 'pending':   return 'Pending';
                default:          return status;
            }
        }

        refreshBulkBar();
    });
})();