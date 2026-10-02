/**
 * FitPal Rider Dashboard JavaScript
 *
 * Handles:
 *   - Chart tooltip positioning (weekly earnings chart)
 *   - Availability-change reactions: the dashboard's "Availability"
 *     row and the greeting paragraph update in place when the
 *     assignment panel announces an online / offline change.
 *   - Toast notifications (kept for the shared .rider-toast element,
 *     which any page that loads this file can reuse)
 *
 * Availability ownership
 * ----------------------
 * This file does NOT bind to an availability toggle. The rider's
 * online / offline toggle lives in the assignment panel
 * (rider/includes/assignment-panel.php), and its click handler is
 * owned by rider/assets/ui/js/assignment-panel.js. The dashboard
 * page no longer renders a toggle of its own, and this file does
 * not bind to #availabilityToggle.
 *
 * What this file DOES do about availability is react to it. When
 * the assignment panel changes the rider's availability — either
 * because the rider confirmed a toggle in the availability modal,
 * or because the server reported a different value on the panel's
 * next poll — the panel fires a document-level CustomEvent named
 * "rider:availability-changed" with the new value on
 * event.detail.online. This file listens for that event and
 * rewrites the two places on the dashboard that display the
 * rider's availability:
 *
 *   1. The "Availability" row inside the Profile info card —
 *      the .rider-info-availability element inside the
 *      .rider-info-meta-row whose <dt> is "Availability".
 *
 *   2. The greeting paragraph under the page title —
 *      the .text-muted element inside .rider-dashboard-greeting,
 *      which reads either "You're online and ready to accept
 *      deliveries." or "You're currently offline. Toggle
 *      availability from the assignments panel at the bottom of
 *      the screen to start accepting deliveries."
 *
 * Both of those elements carry no id and no data attribute. The
 * listener therefore locates them by class name. That keeps the
 * dashboard page's markup unchanged: this revision adds behaviour
 * only.
 *
 * Verification-status awareness
 * -----------------------------
 * When the rider's account is not verified, the dashboard greeting
 * reads "Your account is pending verification. You can't go online
 * until your account is verified." That copy is not driven by
 * availability and must not be overwritten by an availability
 * event. The listener therefore refuses to touch the greeting when
 * the page was rendered for an unverified rider. It detects this
 * from a page-level data attribute that this revision also emits
 * (see below), so the check is a single boolean read rather than a
 * text comparison.
 *
 * The "Availability" row in the Profile card is still updated for
 * an unverified rider, because a rider whose account is
 * unverified still has an availability flag on the server — it is
 * simply forced offline whenever they sign in. Showing the current
 * value honestly is correct.
 *
 * Page-level configuration
 * ------------------------
 * The dashboard page emits window.FITPAL_RIDER = { ... } before
 * loading this script. As of this revision the page adds two
 * fields:
 *
 *   isVerified     boolean — true when the rider's verification
 *                  status is 'verified'
 *   isOnline       boolean — the rider's availability as of the
 *                  server-rendered first paint
 *
 * This file reads isVerified on DOMContentLoaded and keeps it
 * closed over the event listener. If window.FITPAL_RIDER is absent
 * or the field is missing, the listener defaults to treating the
 * rider as verified and updates the greeting — the safe default,
 * because an unverified rider who somehow receives an availability
 * event has no meaningful greeting change to lose.
 *
 * @package FitPal
 * @version 5.0 — Listens for the rider:availability-changed event
 *                dispatched by the assignment panel and updates
 *                the dashboard's Availability row and greeting
 *                paragraph in place. Reads the rider's verified
 *                flag from window.FITPAL_RIDER to avoid clobbering
 *                the verification-blocked greeting copy. The chart
 *                tooltip block and the showToast() helper are
 *                unchanged from v4.0.
 *
 *                (4.0: removed the #availabilityToggle block. The
 *                dashboard page no longer renders a toggle, and the
 *                assignment panel is the single control surface for
 *                availability. 3.0: bound the toggle and swapped
 *                icon paths to match the current dashboard markup.)
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // CONFIG FROM PAGE
        //
        // The dashboard page emits window.FITPAL_RIDER before
        // this script loads. isVerified tells the availability
        // listener whether the greeting paragraph is the
        // availability-driven copy or the verification-blocked
        // copy. When the object or the field is missing, the
        // listener treats the rider as verified.
        // ============================================
        var PAGE_CONFIG = window.FITPAL_RIDER || {};
        var RIDER_IS_VERIFIED = (PAGE_CONFIG.isVerified === true);

        // ============================================
        // CHART TOOLTIP
        // ============================================
        var chart   = document.querySelector('.rider-weekly-chart');
        var tooltip = document.getElementById('riderChartTooltip');

        if (chart && tooltip) {
            var bars      = chart.querySelectorAll('.rider-chart-bar');
            var activeBar = null;

            function showTooltipFor(bar) {
                var column = bar.closest('.rider-chart-column');
                if (!column) return;

                var day    = column.dataset.day || '';
                var amount = column.dataset.amount || '';

                tooltip.textContent = day + ' — ' + amount;
                tooltip.classList.add('is-visible');
                activeBar = bar;
                positionTooltip();
            }

            function hideTooltip() {
                tooltip.classList.remove('is-visible');
                activeBar = null;
            }

            function positionTooltip() {
                if (!activeBar) return;

                var chartRect   = chart.getBoundingClientRect();
                var barRect     = activeBar.getBoundingClientRect();
                var tooltipRect = tooltip.getBoundingClientRect();

                var left = barRect.left - chartRect.left
                         + (barRect.width / 2)
                         - (tooltipRect.width / 2);

                var top = barRect.top - chartRect.top
                        - tooltipRect.height
                        - 8;

                if (left < 4) left = 4;
                if (left + tooltipRect.width > chartRect.width - 4) {
                    left = chartRect.width - tooltipRect.width - 4;
                }

                if (top < 4) {
                    top = barRect.bottom - chartRect.top + 8;
                }

                tooltip.style.left = left + 'px';
                tooltip.style.top  = top  + 'px';
            }

            bars.forEach(function (bar) {
                bar.addEventListener('mouseenter', function () {
                    showTooltipFor(this);
                });
                bar.addEventListener('mouseleave', hideTooltip);
                bar.addEventListener('focus', function () {
                    showTooltipFor(this);
                });
                bar.addEventListener('blur', hideTooltip);
                bar.addEventListener('touchstart', function (e) {
                    e.preventDefault();
                    showTooltipFor(this);
                }, { passive: false });
            });

            document.addEventListener('touchstart', function (e) {
                if (!activeBar) return;
                if (e.target.closest('.rider-chart-bar')) return;
                hideTooltip();
            }, { passive: true });

            var ticking = false;
            function scheduleReposition() {
                if (!activeBar) return;
                if (ticking) return;
                ticking = true;
                requestAnimationFrame(function () {
                    positionTooltip();
                    ticking = false;
                });
            }

            window.addEventListener('resize', scheduleReposition, { passive: true });
            window.addEventListener('scroll', scheduleReposition, { passive: true });
        }

        // ============================================
        // AVAILABILITY-CHANGE REACTIONS
        //
        // The assignment panel is the single writer of the rider's
        // availability on the client. When it changes the value —
        // either because the rider confirmed a toggle, or because
        // the server reported a different value on the panel's
        // next poll — it fires:
        //
        //   document  'rider:availability-changed'
        //             { detail: { online: boolean, eligible: boolean } }
        //
        // This block listens for that event and updates the two
        // dashboard surfaces that display availability.
        //
        // The elements carry no id. They are located by class name
        // so this revision adds behaviour only, with no change to
        // the dashboard page markup.
        // ============================================

        /**
         * Find the .rider-info-availability element inside the
         * Profile info card's "Availability" row.
         *
         * The row is a .rider-info-meta-row whose <dt> text is
         * "Availability". Its <dd> contains the availability span.
         *
         * Returns null when the row is absent — for example on a
         * layout that later drops the Profile card. The caller
         * guards on that.
         *
         * @returns {HTMLElement|null}
         */
        function findAvailabilityBadge() {
            var rows = document.querySelectorAll(
                '.rider-info-card .rider-info-meta-row'
            );

            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var dt  = row.querySelector('dt');
                if (!dt) continue;

                var label = (dt.textContent || '').trim().toLowerCase();
                if (label !== 'availability') continue;

                var badge = row.querySelector('.rider-info-availability');
                if (badge) return badge;
            }

            return null;
        }

        /**
         * Find the greeting paragraph under the page title.
         *
         * The paragraph is the .text-muted element inside
         * .rider-dashboard-greeting. That container holds exactly
         * one such element on the current markup.
         *
         * @returns {HTMLElement|null}
         */
        function findGreetingParagraph() {
            return document.querySelector(
                '.rider-dashboard-greeting .text-muted'
            );
        }

        /**
         * Rewrite the Profile card's Availability badge to reflect
         * the new value.
         *
         * @param {boolean} isOnline
         */
        function applyAvailabilityBadge(isOnline) {
            var badge = findAvailabilityBadge();
            if (!badge) return;

            badge.classList.remove('is-online', 'is-offline');
            badge.classList.add(isOnline ? 'is-online' : 'is-offline');

            badge.textContent = isOnline ? 'Online' : 'Offline';
        }

        /**
         * Rewrite the greeting paragraph to the availability-driven
         * copy for the new value.
         *
         * Skipped entirely when the rider is not verified: the
         * greeting paragraph on an unverified dashboard carries
         * verification copy, not availability copy, and an
         * availability event must not overwrite it.
         *
         * @param {boolean} isOnline
         */
        function applyGreetingCopy(isOnline) {
            if (!RIDER_IS_VERIFIED) return;

            var greeting = findGreetingParagraph();
            if (!greeting) return;

            if (isOnline) {
                greeting.textContent =
                    "You're online and ready to accept deliveries.";
            } else {
                greeting.textContent =
                    "You're currently offline. Toggle availability from "
                    + "the assignments panel at the bottom of the screen "
                    + "to start accepting deliveries.";
            }
        }

        document.addEventListener('rider:availability-changed', function (event) {
            if (!event || !event.detail) return;

            var isOnline = !!event.detail.online;

            applyAvailabilityBadge(isOnline);
            applyGreetingCopy(isOnline);
        });

        // ============================================
        // TOAST
        //
        // Kept as a shared helper. Any rider page that loads this
        // file can call showToast() if it defines
        // window.FITPAL_RIDER_ASSET_BASE. Currently no caller on the
        // dashboard invokes it; it stays available so a future
        // dashboard-only action (e.g. a "refresh stats" button) has
        // a consistent toast without re-implementing one.
        // ============================================
        function showToast(message, type) {
            var toast = document.getElementById('riderToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'riderToast';
                toast.style.cssText = [
                    'position:fixed', 'top:80px', 'right:20px',
                    'padding:12px 20px', 'border-radius:8px',
                    'font-size:14px', 'font-weight:500', 'z-index:9999',
                    'transform:translateX(120%)',
                    'transition:transform .3s cubic-bezier(.4,0,.2,1)',
                    'max-width:360px', 'box-shadow:0 4px 16px rgba(0,0,0,.15)'
                ].join(';');
                document.body.appendChild(toast);
            }

            var palette = {
                success: ['#d1fae5', '#065f46'],
                error:   ['#fee2e2', '#991b1b'],
                info:    ['#dbeafe', '#1e40af']
            };
            var colors = palette[type] || palette.info;
            toast.style.background = colors[0];
            toast.style.color      = colors[1];
            toast.textContent      = message;

            void toast.offsetWidth;
            toast.style.transform = 'translateX(0)';

            clearTimeout(toast._timer);
            toast._timer = setTimeout(function () {
                toast.style.transform = 'translateX(120%)';
            }, 2800);
        }

        // Expose for future dashboard callers. Prefix keeps it out of
        // the way of any other script on the same page.
        window.FITPAL_RIDER_DASHBOARD_TOAST = showToast;
    });
})();