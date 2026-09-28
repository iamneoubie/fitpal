/**
 * FitPal Admin Dashboard JavaScript
 *
 * This page loads the shared admin-modal.js module for any modal
 * behaviour. This file only owns dashboard-specific logic.
 *
 * @package FitPal
 * @version 4.0 — Removed all modal logic. The page now relies on
 *                the shared admin-modal.js module for any modal
 *                interactions.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // CHART BAR HEIGHTS
        // ============================================
        document.querySelectorAll('.admin-chart-bar[data-bar-height]').forEach(function (bar) {
            var value = bar.getAttribute('data-bar-height');
            if (value === null || value === '') return;

            var pct = parseFloat(value);
            if (isNaN(pct)) return;

            bar.style.height = pct + '%';
        });

        // ============================================
        // CHART TOOLTIP
        // ============================================
        var chart = document.querySelector('.admin-weekly-chart');
        var tooltip = document.getElementById('adminChartTooltip');

        if (chart && tooltip) {
            var bars = chart.querySelectorAll('.admin-chart-bar');
            var activeBar = null;

            function showTooltipFor(bar) {
                var column = bar.closest('.admin-chart-column');
                if (!column) return;
                var day = column.dataset.day || '';
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
                var chartRect = chart.getBoundingClientRect();
                var barRect = activeBar.getBoundingClientRect();
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
                tooltip.style.top = top + 'px';
            }

            bars.forEach(function (bar) {
                bar.addEventListener('mouseenter', function () { showTooltipFor(this); });
                bar.addEventListener('mouseleave', hideTooltip);
                bar.addEventListener('focus', function () { showTooltipFor(this); });
                bar.addEventListener('blur', hideTooltip);
                bar.addEventListener('touchstart', function (e) {
                    e.preventDefault();
                    showTooltipFor(this);
                }, { passive: false });
            });

            document.addEventListener('touchstart', function (e) {
                if (!activeBar) return;
                if (e.target.closest('.admin-chart-bar')) return;
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
    });
})();