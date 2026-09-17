/**
 * FitPal Customer Dashboard
 *
 * Responsibilities (small by design):
 *   1. Chart tooltip — a single floating element positioned over the
 *      hovered or focused bar. Works on desktop (hover) and mobile
 *      (tap), so the chart is readable without JS-only hover hacks.
 *   2. Recompute tooltip position on resize/scroll so it stays put
 *      when the layout reflows.
 *
 * Does NOT:
 *   - Manipulate bar heights (server-rendered percentages).
 *   - Fetch anything.
 *   - Touch the profile card or recent orders list.
 *
 * @package FitPal
 * @version 1.0
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        const chart   = document.querySelector('.weekly-chart');
        const tooltip = document.getElementById('chartTooltip');
        if (!chart || !tooltip) return;

        const bars = chart.querySelectorAll('.chart-bar');
        if (!bars.length) return;

        let activeBar = null;

        // ─────────────────────────────────────────
        // TOOLTIP
        // ─────────────────────────────────────────
        function showTooltipFor(bar) {
            const column = bar.closest('.chart-column');
            if (!column) return;

            const day    = column.dataset.day    || '';
            const amount = column.dataset.amount || '';

            // Empty bars still show the day + ₱0.00 so the user gets feedback.
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

            const chartRect = chart.getBoundingClientRect();
            const barRect   = activeBar.getBoundingClientRect();

            // Prefer placing above the bar top; clamp inside the chart.
            const tooltipRect = tooltip.getBoundingClientRect();

            let left = barRect.left - chartRect.left
                     + (barRect.width / 2)
                     - (tooltipRect.width / 2);

            let top = barRect.top - chartRect.top
                    - tooltipRect.height
                    - 8;

            // Clamp horizontally
            if (left < 4) left = 4;
            if (left + tooltipRect.width > chartRect.width - 4) {
                left = chartRect.width - tooltipRect.width - 4;
            }

            // If no room above, drop below the bar.
            if (top < 4) {
                top = barRect.bottom - chartRect.top + 8;
            }

            tooltip.style.left = left + 'px';
            tooltip.style.top  = top  + 'px';
        }

        // ─────────────────────────────────────────
        // EVENT BINDING
        // ─────────────────────────────────────────
        bars.forEach(function (bar) {
            bar.addEventListener('mouseenter', function () {
                showTooltipFor(this);
            });
            bar.addEventListener('mouseleave', hideTooltip);

            // Keyboard support — tabbing through bars.
            bar.addEventListener('focus', function () {
                showTooltipFor(this);
            });
            bar.addEventListener('blur', hideTooltip);

            // Touch: tap to show, tap elsewhere to hide.
            bar.addEventListener('touchstart', function (e) {
                e.preventDefault();
                showTooltipFor(this);
            }, { passive: false });
        });

        // Tap outside dismisses on mobile.
        document.addEventListener('touchstart', function (e) {
            if (!activeBar) return;
            if (e.target.closest('.chart-bar')) return;
            hideTooltip();
        }, { passive: true });

        // ─────────────────────────────────────────
        // REPOSITION ON RESIZE / SCROLL
        // rAF-throttled so rapid events don't thrash layout.
        // ─────────────────────────────────────────
        let ticking = false;
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

    });
})();