/**
 * Webcam history player — mobile scroll lock (safety / layout)
 *
 * Applies the standard html+body fixed pattern used when the overlay is open.
 * Kept in one module so Node tests can assert the full apply/release contract.
 *
 * @module webcam-player-scroll-lock
 */
/* global module */
(function (global) {
    'use strict';

    /**
     * Lock page scroll and return the scroll position to restore later.
     *
     * @returns {number} Y offset in pixels (saved scroll position)
     */
    function applyWebcamScrollLock() {
        const y = window.scrollY || window.pageYOffset || document.documentElement.scrollTop || 0;
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
        document.body.style.position = 'fixed';
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.width = '100%';
        document.body.style.top = '-' + y + 'px';
        return y;
    }

    /**
     * Undo {@link applyWebcamScrollLock}. Caller should restore scroll after layout settles.
     *
     * @returns {void}
     */
    function releaseWebcamScrollLock() {
        document.documentElement.style.overflow = '';
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.left = '';
        document.body.style.right = '';
        document.body.style.width = '';
        document.body.style.top = '';
    }

    const api = {
        applyWebcamScrollLock: applyWebcamScrollLock,
        releaseWebcamScrollLock: releaseWebcamScrollLock
    };

    if (typeof module !== 'undefined' && typeof module.exports === 'object') {
        module.exports = api;
    } else {
        global.AviationWX = global.AviationWX || {};
        global.AviationWX.webcamPlayerScrollLock = {
            apply: applyWebcamScrollLock,
            release: releaseWebcamScrollLock
        };
    }
})(typeof window !== 'undefined' ? window : globalThis);
