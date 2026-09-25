/**
 * FitPal Rider Dashboard JavaScript
 *
 * Handles:
 *   - Chart tooltip positioning (weekly earnings chart)
 *   - Toast notifications (kept for the shared .rider-toast element,
 *     which any page that loads this file can reuse)
 *
 * Availability ownership
 * ----------------------
 * This file does NOT bind to an availability toggle. The rider's
 * online / offline toggle lives in the assignment panel
 * (rider/includes/assignment-panel.php), and its click handler is
 * owned by rider/assets/ui/js/assignment-panel.js. The dashboard
 * page no longer renders a toggle of its own, and this file no
 * longer binds to #availabilityToggle.
 *
 * Removing the old block closes a real bug surface. The old code
 * flipped a data-available attribute, swapped button classes, and
 * rewrote the greeting text on its own. If the assignment panel's
 * availability pill also fired during the same session, the two
 * independent views of the flag could disagree — the dashboard
 * button showed "Go Offline" while the panel pill still showed
 * "Go Online" until the next poll, and vice versa. There is now
 * exactly one writer of availability on the client: the assignment
 * panel's JS. The dashboard only reads.
 *
 * @package FitPal
 * @version 4.0 — Removed the #availabilityToggle block. The
 *                dashboard page no longer renders a toggle, and the
 *                assignment panel is the single control surface for
 *                availability. The chart tooltip block and the
 *                showToast() helper are unchanged.
 *
 *                (3.0: bound the toggle and swapped icon paths to
 *                match the current dashboard markup.)
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

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