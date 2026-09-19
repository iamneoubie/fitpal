/**
 * FitPal Admin — Riders Table Interactions
 *
 * Same behaviors as restaurants.js but for rider verification.
 *
 * @package FitPal
 * @version 1.0
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

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