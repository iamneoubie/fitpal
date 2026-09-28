/**
 * FitPal Admin Dashboard JavaScript
 *
 * Responsibilities:
 *   - Apply bar heights from data-bar-height attributes to both
 *     single and stacked bar charts.
 *   - Apply horizontal performer-bar widths from data-bar-width
 *     attributes.
 *   - Chart tooltip on hover, focus, and touch.
 *   - Sub-tab switching between the four analytics panels.
 *
 * The shared admin-modal.js is loaded alongside this file for any
 * modal behaviour. This file owns only dashboard behaviour.
 *
 * @package FitPal
 * @version 5.0 — Adds stacked-bar fill heights, performer-bar
 *                widths, and the four sub-tab chart panels. The
 *                tooltip now reads its body from a data-detail
 *                attribute when present so a stacked bar can show
 *                a breakdown, not just the total.
 *
 *                (4.0: removed modal logic, moved to
 *                admin-modal.js.)
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // BAR HEIGHTS — vertical charts
        // ============================================

        document.querySelectorAll('.admin-chart-bar[data-bar-height]').forEach(function (bar) {
            var value = bar.getAttribute('data-bar-height');
            if (value === null || value === '') return;

            var pct = parseFloat(value);
            if (isNaN(pct)) return;

            bar.style.height = pct + '%';
        });

        // ============================================
        // STACKED FILL HEIGHTS — fills inside a bar
        // ============================================

        document.querySelectorAll('.admin-chart-bar-fill[data-fill-height]').forEach(function (fill) {
            var value = fill.getAttribute('data-fill-height');
            if (value === null || value === '') return;

            var pct = parseFloat(value);
            if (isNaN(pct)) return;

            fill.style.height = pct + '%';
        });

        // ============================================
        // HORIZONTAL PERFORMER BARS
        // ============================================

        document.querySelectorAll('.admin-performer-bar[data-bar-width]').forEach(function (bar) {
            var value = bar.getAttribute('data-bar-width');
            if (value === null || value === '') return;

            var pct = parseFloat(value);
            if (isNaN(pct)) return;

            bar.style.width = pct + '%';
        });

        // ============================================
        // CHART TOOLTIP
        // ============================================

        var charts = document.querySelectorAll('.admin-weekly-chart');
        var tooltip = document.getElementById('adminChartTooltip');

        if (charts.length > 0 && tooltip) {
            var activeBar = null;
            var activeChart = null;

            function showTooltipFor(bar) {
                var column = bar.closest('.admin-chart-column');
                if (!column) return;

                var chart = bar.closest('.admin-weekly-chart');
                if (!chart) return;

                var day    = column.dataset.day    || '';
                var amount = column.dataset.amount || '';
                var detail = column.dataset.detail || '';

                if (detail !== '') {
                    tooltip.textContent = day + ' — ' + amount + ' · ' + detail;
                } else {
                    tooltip.textContent = day + ' — ' + amount;
                }

                tooltip.classList.add('is-visible');
                activeBar = bar;
                activeChart = chart;
                positionTooltip();
            }

            function hideTooltip() {
                tooltip.classList.remove('is-visible');
                activeBar = null;
                activeChart = null;
            }

            function positionTooltip() {
                if (!activeBar || !activeChart) return;

                var chartRect = activeChart.getBoundingClientRect();
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
                tooltip.style.top  = top  + 'px';
            }

            // Re-attach listeners every time a bar becomes visible
            // (a chart panel may be hidden when the page loads).
            document.querySelectorAll('.admin-chart-bar').forEach(function (bar) {
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

            window.adminChartHideTooltip = hideTooltip;
        }

        // ============================================
        // SUB-TAB SWITCHING
        // ============================================

        var subtabs = document.querySelectorAll('.admin-chart-subtab');
        var panels  = document.querySelectorAll('.admin-chart-panel');

        subtabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var key = this.dataset.chart;
                if (!key) return;

                subtabs.forEach(function (t) {
                    var isActive = t.dataset.chart === key;
                    t.classList.toggle('active', isActive);
                    t.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });

                panels.forEach(function (p) {
                    p.classList.toggle('active', p.dataset.chartPanel === key);
                });

                // The tooltip is shared across the whole chart body.
                // Hide it when the panel changes so it does not float
                // over the new panel.
                if (typeof window.adminChartHideTooltip === 'function') {
                    window.adminChartHideTooltip();
                }
            });
        });

    });
})();