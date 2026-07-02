/**
 * Unified outage display sync for the airport dashboard.
 *
 * Banner and supplemental fail-closed weather hide must use the same path
 * whether outage is detected client-side or confirmed via outage-status API.
 */
(function (window) {
    'use strict';

    /**
     * @typedef {object} OutageDisplayState
     * @property {boolean} maintenance
     * @property {boolean} in_outage
     * @property {boolean} limited_availability
     * @property {number} newest_timestamp
     */

    /**
     * @typedef {object} OutageDisplayHooks
     * @property {function(OutageDisplayState): void} syncBannerState
     * @property {function(boolean): void} hideSupplementalRemoteFieldsIfOutage
     */

    /**
     * Apply outage banner and supplemental fail-closed display together.
     *
     * @param {OutageDisplayState} state
     * @param {OutageDisplayHooks} hooks Injectable for unit tests
     * @returns {void}
     */
    function applyOutageDisplayState(state, hooks) {
        hooks.syncBannerState(state);
        hooks.hideSupplementalRemoteFieldsIfOutage(!!state.in_outage);
    }

    window.AviationWX = window.AviationWX || {};
    window.AviationWX.applyOutageDisplayState = applyOutageDisplayState;

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { applyOutageDisplayState: applyOutageDisplayState };
    }
})(typeof window !== 'undefined' ? window : globalThis);
