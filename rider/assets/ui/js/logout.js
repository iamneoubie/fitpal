/**
 * FitPal Rider Logout Confirmation + Sign-Out Guard
 *
 * Two responsibilities, both scoped to the rider role.
 *
 * 1. SIGN-OUT GUARD
 *    Before opening the confirmation modal, this file asks the
 *    server whether the rider is allowed to sign out. The server
 *    answers with three fields:
 *
 *        can_sign_out   true only when the rider is offline AND
 *                       has no live orders
 *        is_online      the rider's current availability flag
 *        active_count   number of orders in rider_pending /
 *                       picking_up / delivering
 *
 *    When can_sign_out is true, the normal confirmation modal
 *    opens.
 *
 *    When can_sign_out is false, a blocking modal opens instead.
 *    It has a single OK button and its body text names the
 *    condition that is unmet:
 *
 *      active_count > 0  →  "You have N active orders. Finish them
 *                            before signing out."
 *      is_online         →  "You're still online. Go offline from
 *                            the assignments panel before signing
 *                            out."
 *
 *    The guard is a read-only pre-flight. It never flips the rider
 *    offline, never cancels an order, and never touches any state.
 *    The rider decides what to do about the block; the guard just
 *    tells them what the block is.
 *
 * 2. LOGOUT CONFIRMATION
 *    Intercepts clicks on [data-logout-trigger] elements and opens
 *    the #logoutModal rendered by rider/includes/header.php once
 *    the guard has cleared the rider. The modal's confirm anchor
 *    navigates to sign-out-handler.php; Cancel closes the modal.
 *
 * Fail-open policy
 * ----------------
 * If the server is unreachable, the guard cannot decide. This file
 * follows the restaurant role's precedent: do not block the user
 * on an infrastructure failure. A null callback result — network
 * error, non-JSON response, or a status other than success — opens
 * the confirmation modal as if the rider were eligible. The
 * server-side sign-out-handler.php is still the authority on
 * whether the session is destroyed; the guard is a UX layer that
 * catches the common case, not a security boundary.
 *
 * No window.alert, confirm, or prompt. Every message goes through
 * a modal.
 *
 * @package FitPal
 * @version 2.0 — Adds the sign-out guard:
 *                  - New checkSignOutEligibility() that POSTs
 *                    action=check_sign_out to rider-handler.php.
 *                  - Triggers now run the guard before opening
 *                    #logoutModal.
 *                  - When the guard reports not-eligible, opens
 *                    #riderBlockSignOutModal with copy that names
 *                    the unmet condition.
 *                  - Fail-open: a guard that cannot reach the
 *                    server opens #logoutModal as before.
 *                  - Double-click guard on the trigger so two
 *                    pre-flight requests cannot run at once.
 *
 *                (1.0: initial logout handler.)
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var modal = document.getElementById('logoutModal');
        if (!modal) return;

        var blockModal = document.getElementById('riderBlockSignOutModal');
        var blockText  = document.getElementById('riderBlockSignOutText');

        var confirmBtn  = modal.querySelector('.logout-btn-confirm');
        var cancelNodes = modal.querySelectorAll('[data-logout-cancel]');
        var triggers    = document.querySelectorAll('[data-logout-trigger]');

        // The rider role's own CSRF token and handler endpoint. Both
        // are published by rider/includes/header.php. The hard-coded
        // fallbacks keep the script working on a page that somehow
        // did not bootstrap the globals — the path is relative to
        // the current page, which is always inside rider/pages/.
        var CSRF_TOKEN = window.RIDER_CSRF_TOKEN || '';
        var ENDPOINT   = window.RIDER_HANDLER_ENDPOINT
                      || '../backend/handlers/rider-handler.php';

        // ============================================================
        // CONFIRMATION MODAL
        // ============================================================

        function openModal() {
            document.body.style.overflow = 'hidden';
            modal.style.display = 'flex';
            void modal.offsetWidth;
            modal.classList.add('active');
            if (confirmBtn) setTimeout(function () { confirmBtn.focus(); }, 80);
        }

        function closeModal() {
            modal.classList.remove('active');
            setTimeout(function () {
                if (!modal.classList.contains('active')) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }, 220);
        }

        cancelNodes.forEach(function (node) {
            node.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        });

        // ============================================================
        // BLOCK MODAL
        // ============================================================

        function openBlockModal(data) {
            if (!blockModal) {
                // The block modal is missing from the header for some
                // reason. Fall back to opening the confirmation
                // modal — the server still refuses nothing at this
                // point, and a missing modal should not trap the
                // rider on the page.
                openModal();
                return;
            }

            var activeCount = parseInt(data.active_count, 10) || 0;
            var isOnline    = !!data.is_online;

            var text = '';

            if (activeCount > 0) {
                text = 'You have ' + activeCount + ' active order'
                     + (activeCount === 1 ? '' : 's')
                     + '. Finish '
                     + (activeCount === 1 ? 'it' : 'them')
                     + ' before signing out.';
            } else if (isOnline) {
                text = "You're still online. Go offline from the assignments "
                     + "panel at the bottom of the screen, then try again.";
            } else {
                // Defensive: the server said can_sign_out is false
                // but neither condition reads as the reason. This
                // branch should never fire in practice.
                text = 'You cannot sign out right now. Please try again in a moment.';
            }

            if (blockText) blockText.textContent = text;

            document.body.style.overflow = 'hidden';
            blockModal.style.display = 'flex';
            void blockModal.offsetWidth;
            blockModal.classList.add('active');
        }

        function closeBlockModal() {
            if (!blockModal) return;
            blockModal.classList.remove('active');
            setTimeout(function () {
                if (!blockModal.classList.contains('active')) {
                    blockModal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }, 220);
        }

        if (blockModal) {
            blockModal.querySelectorAll('[data-block-signout-cancel]').forEach(function (node) {
                node.addEventListener('click', function (e) {
                    e.preventDefault();
                    closeBlockModal();
                });
            });
        }

        // ============================================================
        // SIGN-OUT ELIGIBILITY CHECK
        //
        // POSTs action=check_sign_out to rider-handler.php and hands
        // the parsed result to the callback. On any failure to get a
        // usable answer, the callback receives null and the caller
        // fails open.
        // ============================================================

        function checkSignOutEligibility(callback) {
            var fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('action', 'check_sign_out');

            fetch(ENDPOINT, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            })
            .then(function (r) {
                return r.json().catch(function () { return null; });
            })
            .then(function (data) {
                if (data && data.status === 'success') {
                    callback(data);
                } else {
                    callback(null);
                }
            })
            .catch(function () {
                callback(null);
            });
        }

        // ============================================================
        // TRIGGER DISPATCH
        // ============================================================

        triggers.forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();

                // Double-click guard: two pre-flight requests must
                // not be in flight at once. The attribute is cleared
                // in the callback, whatever the outcome.
                if (trigger.dataset.guardPending === '1') return;
                trigger.dataset.guardPending = '1';

                checkSignOutEligibility(function (result) {
                    trigger.dataset.guardPending = '';

                    if (result && result.can_sign_out === false) {
                        openBlockModal(result);
                        return;
                    }

                    // result is null (network failure) OR
                    // can_sign_out is true. Open the confirmation
                    // modal in either case — the guard fails open.
                    openModal();
                });
            });
        });

        // ============================================================
        // KEYBOARD
        // ============================================================

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;

            if (modal.classList.contains('active')) {
                closeModal();
            }
            if (blockModal && blockModal.classList.contains('active')) {
                closeBlockModal();
            }
        });

        // ============================================================
        // BACK-FORWARD-CACHE RESTORE
        //
        // If the browser restores this page after the rider navigated
        // away, close any modal that was left open so the page does
        // not come back in a half-modal state.
        // ============================================================

        window.addEventListener('pageshow', function (e) {
            if (!e.persisted) return;

            if (modal.classList.contains('active')) {
                closeModal();
            }
            if (blockModal && blockModal.classList.contains('active')) {
                closeBlockModal();
            }
        });
    });
})();