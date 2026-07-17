/**
 * Shared density altitude performance display helpers (canonical module).
 *
 * Presentation only: reflects API tier and best_end. Scoring lives in PHP.
 *
 * Used by:
 * - public/js/airport-dashboard.js
 * - public/js/embed-helpers.js
 */

(function(window) {
    'use strict';

    const FALLBACK_TOOLTIP = 'Runway data unavailable. Indicator based on density altitude relative to field elevation only. Verify all performance calculations using your AFM.';

    /**
     * @param {object|null|undefined} performance
     * @returns {string}
     */
    function performanceTier(performance) {
        return performance && performance.tier ? String(performance.tier) : 'normal';
    }

    /**
     * @param {object|null|undefined} performance
     * @returns {string}
     */
    function operationalEndLabel(performance) {
        if (!performance) {
            return '';
        }
        const bestEnd = performance.best_end && typeof performance.best_end === 'object'
            ? performance.best_end
            : null;
        if (!bestEnd) {
            return '';
        }
        const endId = bestEnd.end_id ? String(bestEnd.end_id).trim() : '';
        if (endId === '') {
            return '';
        }
        const rwyId = bestEnd.rwy_id ? String(bestEnd.rwy_id).trim() : '';
        if (rwyId !== '' && rwyId !== 'config') {
            return `RWY ${endId} (${rwyId})`;
        }
        return `RWY ${endId}`;
    }

    /**
     * @param {object|null|undefined} performance
     * @returns {string}
     */
    function selectionBasisNote(performance) {
        if (!performance || !performance.selection_basis) {
            return '';
        }
        const endLabel = operationalEndLabel(performance);
        if (endLabel === '') {
            return ' Based on the best runway at this airport.';
        }
        return ` Based on ${endLabel}, the best runway at this airport.`;
    }

    /**
     * @param {string} tier
     * @param {object|null|undefined} performance
     * @returns {string}
     */
    function tooltip(tier, performance) {
        if (performance && performance.fallback) {
            return FALLBACK_TOOLTIP;
        }
        const basisNote = selectionBasisNote(performance);
        if (tier === 'warning') {
            return 'Density altitude is dangerously high for average GA aircraft. Verify performance numbers before flight.' + basisNote;
        }
        if (tier === 'caution') {
            return 'Density altitude is higher than normal. Verify performance numbers before flight.' + basisNote;
        }
        return '';
    }

    /**
     * @param {string} tier
     * @returns {string}
     */
    function emoji(tier) {
        if (tier === 'warning') {
            return '🚩';
        }
        if (tier === 'caution') {
            return '⚠️';
        }
        return '';
    }

    /**
     * @param {string} tier
     * @returns {string}
     */
    function valueClass(tier) {
        return (tier === 'caution' || tier === 'warning') ? 'density-altitude-warning' : '';
    }

    /**
     * @param {number|null|undefined} densityAltitudeFt
     * @param {string} tier
     * @param {object|null|undefined} performance
     * @param {string} distUnit
     * @returns {string}
     */
    function ariaLabel(densityAltitudeFt, tier, performance, distUnit) {
        if (densityAltitudeFt === null || densityAltitudeFt === undefined) {
            return 'Density altitude unavailable';
        }
        const ariaValue = distUnit === 'm'
            ? Math.round(Number(densityAltitudeFt) * 0.3048)
            : Math.round(Number(densityAltitudeFt));
        const unitLabel = distUnit === 'm' ? 'meters' : 'feet';
        const base = `Density altitude ${ariaValue.toLocaleString()} ${unitLabel}`;
        if (performance && performance.fallback) {
            return `${base}. Runway data unavailable; indicator based on density altitude relative to field elevation only. Verify all performance calculations using your AFM.`;
        }
        if (tier === 'warning') {
            return `${base}. Warning: dangerously high for average GA aircraft; verify performance numbers before flight.${selectionBasisNote(performance)}`;
        }
        if (tier === 'caution') {
            return `${base}. Caution: higher than normal; verify performance numbers before flight.${selectionBasisNote(performance)}`;
        }
        return base;
    }

    /**
     * Dashboard display: separate numeric value and emoji (unit rendered elsewhere).
     *
     * @param {number|null|undefined} densityAltitudeFt
     * @param {object|null|undefined} performance
     * @param {{ distUnit?: string, formatValue?: function(number): (number|string) }} [options]
     * @returns {{ value: (number|string), emoji: string, className: string, title: string, ariaLabel: string }}
     */
    function formatDashboardDisplay(densityAltitudeFt, performance, options) {
        const distUnit = (options && options.distUnit) || 'ft';
        const formatValue = (options && options.formatValue)
            || ((ft) => (ft === null || ft === undefined || ft === '--' ? '--' : Math.round(Number(ft))));
        const value = formatValue(densityAltitudeFt);
        const tier = performanceTier(performance);
        const tierEmoji = (tier === 'caution' || tier === 'warning') ? emoji(tier) : '';

        return {
            value,
            emoji: tierEmoji,
            className: valueClass(tier),
            title: tooltip(tier, performance),
            ariaLabel: ariaLabel(densityAltitudeFt, tier, performance, distUnit)
        };
    }

    /**
     * Embed display: combined distance text and optional emoji.
     *
     * @param {number|null|undefined} densityAltitudeFt
     * @param {object|null|undefined} performance
     * @param {string} distUnit
     * @param {function(number, string, boolean): string} formatEmbedDistFn
     * @returns {{ text: string, className: string, title: string, ariaLabel: string }}
     */
    function formatEmbedDisplay(densityAltitudeFt, performance, distUnit, formatEmbedDistFn) {
        const base = formatEmbedDistFn(densityAltitudeFt, distUnit, true);
        if (base === '--') {
            return {
                text: base,
                className: '',
                title: '',
                ariaLabel: 'Density altitude unavailable'
            };
        }
        const tier = performanceTier(performance);
        const tierEmoji = (tier === 'caution' || tier === 'warning') ? emoji(tier) : '';
        const text = tierEmoji ? `${base} ${tierEmoji}` : base;

        return {
            text,
            className: valueClass(tier),
            title: tooltip(tier, performance),
            ariaLabel: ariaLabel(densityAltitudeFt, tier, performance, distUnit)
        };
    }

    const api = {
        FALLBACK_TOOLTIP,
        performanceTier,
        operationalEndLabel,
        selectionBasisNote,
        tooltip,
        emoji,
        valueClass,
        ariaLabel,
        formatDashboardDisplay,
        formatEmbedDisplay
    };

    window.AviationWX = window.AviationWX || {};
    window.AviationWX.densityAltitudePerformance = api;

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
}(typeof window !== 'undefined' ? window : {}));
