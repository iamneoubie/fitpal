/**
 * FitPal Admin Dashboard JavaScript
 *
 * Wires up the analytics card on admin/pages/dashboard.php:
 *
 *   1. Sub-tab switching
 *      Four buttons with [data-chart] toggle four panels with
 *      [data-chart-panel]. The selected button and panel both
 *      receive .active; the button also carries aria-selected.
 *
 *   2. Stacked-bar fill heights
 *      Each .admin-chart-bar carries data-bar-height (its own
 *      overall height as a percentage of the chart ceiling). Each
 *      child .admin-chart-bar-fill carries data-fill-height (its
 *      share of the parent bar, as a percentage). Both are applied
 *      as inline CSS heights. The PHP has already rounded every
 *      percentage, so no math is done here.
 *
 *   3. Horizontal performer bars
 *      Each .admin-performer-bar carries data-bar-width (a
 *      percentage of the row's maximum). Applied as an inline CSS
 *      width.
 *
 *   4. Chart tooltip
 *      Hovering or focusing a bar shows a tooltip with the column's
 *      data-day, data-amount, and data-detail. One tooltip element
 *      is created per chart container, not one per page, because
 *      each panel has its own chart. The tooltip is positioned
 *      relative to its chart container.
 *
 * Nothing here touches the network, and nothing here renders
 * markup that the page did not already declare. The tooltip is the
 * only element this file creates, and it is a plain div with no
 * SVG.
 *
 * @package FitPal
 * @version 1.0 — Initial implementation. Replaces the empty
 *                placeholder that shipped with the admin dashboard.
 */
(function () {
    'use strict';

    // ============================================================
    // SUB-TABS
    // ============================================================

    function initChartSubtabs() {
        var subtabs = document.querySelectorAll('.admin-chart-subtab');
        var panels  = document.querySelectorAll('.admin-chart-panel');

        if (subtabs.length === 0 || panels.length === 0) {
            return;
        }

        function activate(key) {
            var i;

            for (i = 0; i < subtabs.length; i++) {
                var isActive = subtabs[i].getAttribute('data-chart') === key;
                subtabs[i].classList.toggle('active', isActive);
                subtabs[i].setAttribute('aria-selected', isActive ? 'true' : 'false');
            }

            for (i = 0; i < panels.length; i++) {
                var panelActive = panels[i].getAttribute('data-chart-panel') === key;
                panels[i].classList.toggle('active', panelActive);
            }
        }

        for (var i = 0; i < subtabs.length; i++) {
            subtabs[i].addEventListener('click', function (e) {
                e.preventDefault();
                activate(this.getAttribute('data-chart'));
            });
        }
    }

    // ============================================================
    // STACKED BAR HEIGHTS
    // ============================================================

    function initBarHeights() {
        // Overall bar height.
        var bars = document.querySelectorAll('.admin-chart-bar[data-bar-height]');
        for (var i = 0; i < bars.length; i++) {
            var barPct = parseFloat(bars[i].getAttribute('data-bar-height'));
            if (isNaN(barPct)) {
                continue;
            }
            bars[i].style.height = clampPercent(barPct) + '%';
        }

        // Each fill's share of its bar.
        var fills = document.querySelectorAll('.admin-chart-bar-fill[data-fill-height]');
        for (var j = 0; j < fills.length; j++) {
            var fillPct = parseFloat(fills[j].getAttribute('data-fill-height'));
            if (isNaN(fillPct)) {
                continue;
            }
            fills[j].style.height = clampPercent(fillPct) + '%';
        }
    }

    function clampPercent(value) {
        if (value < 0) {
            return 0;
        }
        if (value > 100) {
            return 100;
        }
        return value;
    }

    // ============================================================
    // HORIZONTAL PERFORMER BARS
    // ============================================================

    function initPerformerBars() {
        var bars = document.querySelectorAll('.admin-performer-bar[data-bar-width]');
        for (var i = 0; i < bars.length; i++) {
            var pct = parseFloat(bars[i].getAttribute('data-bar-width'));
            if (isNaN(pct)) {
                continue;
            }
            bars[i].style.width = clampPercent(pct) + '%';
        }
    }

    // ============================================================
    // TOOLTIP
    // ============================================================
    //
    // One tooltip per chart container. The container is the nearest
    // .admin-weekly-chart ancestor of the hovered bar, because that
    // element is the positioned parent the tooltip is placed
    // against.
    //
    // The tooltip element is created on first use and reused for
    // every bar in the same chart. Its id is set so the aria-
    // describedby wiring below has a stable target.

    var tooltipCounter = 0;

    function getChartContainer(el) {
        return el.closest('.admin-weekly-chart');
    }

    function getOrCreateTooltip(chart) {
        var tooltip = chart.querySelector('.admin-chart-tooltip');
        if (tooltip) {
            return tooltip;
        }

        tooltip = document.createElement('div');
        tooltip.className = 'admin-chart-tooltip';
        tooltip.setAttribute('role', 'status');
        tooltip.setAttribute('aria-live', 'polite');
        tooltipCounter += 1;
        tooltip.id = 'adminChartTooltip-' + tooltipCounter;
        chart.appendChild(tooltip);
        return tooltip;
    }

    function buildTooltipMarkup(bar) {
        var day    = bar.getAttribute('data-day') || '';
        var amount = bar.getAttribute('data-amount') || '';
        var detail = bar.getAttribute('data-detail') || '';

        if (amount === '' && detail === '') {
            return '';
        }

        var text = '';
        if (day !== '') {
            text += day + ': ';
        }
        if (amount !== '') {
            text += amount;
        }
        if (detail !== '') {
            text += (text !== '' ? ' — ' : '') + detail;
        }
        return text;
    }

    function positionTooltip(chart, tooltip, bar) {
        var chartRect = chart.getBoundingClientRect();
        var barRect   = bar.getBoundingClientRect();

        var left = barRect.left - chartRect.left + (barRect.width / 2);
        var top  = barRect.top - chartRect.top;

        tooltip.style.left = left + 'px';
        tooltip.style.top  = top + 'px';

        // Clamp so the tooltip does not overflow the chart's own
        // left and right edges. Done after the raw position is set
        // so the browser has a measurable width to clamp against.
        var tooltipRect = tooltip.getBoundingClientRect();
        var overflowRight = (left + (tooltipRect.width / 2)) - chartRect.width;
        if (overflowRight > 0) {
            tooltip.style.left = (left - overflowRight) + 'px';
        }
        if (left - (tooltipRect.width / 2) < 0) {
            tooltip.style.left = (tooltipRect.width / 2) + 'px';
        }

        tooltip.style.transform = 'translate(-50%, calc(-100% - 10px))';
    }

    function showTooltip(bar) {
        var chart = getChartContainer(bar);
        if (!chart) {
            return;
        }

        var text = buildTooltipMarkup(bar);
        if (text === '') {
            return;
        }

        var tooltip = getOrCreateTooltip(chart);
        tooltip.textContent = text;
        tooltip.classList.add('is-visible');

        // Position after making visible so the element has a real
        // width to measure against.
        positionTooltip(chart, tooltip, bar);

        bar.setAttribute('aria-describedby', tooltip.id);
    }

    function hideTooltip(bar) {
        var chart = getChartContainer(bar);
        if (!chart) {
            return;
        }
        var tooltip = chart.querySelector('.admin-chart-tooltip');
        if (!tooltip) {
            return;
        }
        tooltip.classList.remove('is-visible');
        bar.removeAttribute('aria-describedby');
    }

    function initTooltips() {
        var bars = document.querySelectorAll('.admin-chart-bar[data-bar-height]');

        for (var i = 0; i < bars.length; i++) {
            (function (bar) {
                bar.addEventListener('mouseenter', function () {
                    showTooltip(bar);
                });
                bar.addEventListener('mouseleave', function () {
                    hideTooltip(bar);
                });
                bar.addEventListener('focus', function () {
                    showTooltip(bar);
                });
                bar.addEventListener('blur', function () {
                    hideTooltip(bar);
                });
            })(bars[i]);
        }
    }

    // ============================================================
    // INIT
    // ============================================================

    document.addEventListener('DOMContentLoaded', function () {
        initChartSubtabs();
        initBarHeights();
        initPerformerBars();
        initTooltips();
    });
})();