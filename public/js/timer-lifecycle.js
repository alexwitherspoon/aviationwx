/**
 * Timer Lifecycle Manager
 * 
 * Provides deferred lifecycle management for the timer worker system:
 * - Visibility-aware pause/resume (mobile only)
 * - Health monitoring with console logging
 * - Cross-subdomain version checking
 * - Cleanup on page unload
 * - Fallback timer system for browsers without Worker support
 * 
 * This file is loaded with defer attribute and handles non-critical
 * timer management tasks that don't need to block page rendering.
 */

(function() {
    'use strict';
    
    // ==========================================================================
    // Constants
    // ==========================================================================
    
    const VERSION_CHECK_INTERVAL = 30 * 60 * 1000; // 30 minutes
    const HEALTH_CHECK_INTERVAL = 60 * 1000; // 1 minute
    const WORKER_DEAD_THRESHOLD = 120 * 1000; // 2 minutes without ticks
    
    // ==========================================================================
    // State
    // ==========================================================================
    
    let lastWorkerTick = Date.now();
    let healthCheckInterval = null;
    let versionCheckInterval = null;
    
    // ==========================================================================
    // Mobile Detection (reuses existing function if available)
    // ==========================================================================
    
    /**
     * Check if device is mobile
     * Reuses existing isMobileDevice() if defined, otherwise uses fallback
     */
    function checkIsMobile() {
        // Use existing function if available (defined in airport.php)
        if (typeof window.isMobileDevice === 'function') {
            return window.isMobileDevice();
        }
        
        // Fallback detection
        const userAgent = navigator.userAgent || navigator.vendor || window.opera || '';
        const ua = userAgent.toLowerCase();
        const isMobileUA = /android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini|mobile/i.test(ua);
        const hasTouchScreen = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
        const isSmallScreen = window.innerWidth < 768;
        
        return isMobileUA || (hasTouchScreen && isSmallScreen);
    }
    
    // ==========================================================================
    // Visibility-Aware Pause/Resume (Mobile Only)
    // ==========================================================================
    
    /**
     * Setup visibility change handlers for mobile devices
     * Pauses timer worker when tab is hidden to conserve battery
     */
    function setupVisibilityHandlers() {
        const isMobile = checkIsMobile();
        
        if (!isMobile) {
            console.log('[TimerLifecycle] Desktop detected - timer will run in background');
            return;
        }
        
        console.log('[TimerLifecycle] Mobile detected - setting up visibility-aware pause');
        
        // Reference to the timer worker (set by airport.php)
        const getWorker = () => window.aviationwxTimerWorker;
        
        function handleVisibilityChange() {
            const worker = getWorker();
            if (!worker) {
                console.log('[TimerLifecycle] No timer worker available');
                return;
            }
            
            if (document.hidden) {
                console.log('[TimerLifecycle] Tab hidden - pausing timer worker');
                worker.postMessage({ action: 'pause' });
            } else {
                console.log('[TimerLifecycle] Tab visible - resuming timer worker');
                worker.postMessage({ action: 'resume' });
                
                // Force refresh all timers on resume to catch up
                if (typeof window.forceRefreshAllTimers === 'function') {
                    // Small delay to let worker resume first
                    setTimeout(() => {
                        window.forceRefreshAllTimers();
                    }, 100);
                }
            }
        }
        
        document.addEventListener('visibilitychange', handleVisibilityChange);
        
        // Also handle page freeze/resume events (mobile browsers)
        if ('onfreeze' in document) {
            document.addEventListener('freeze', () => {
                const worker = getWorker();
                if (worker) {
                    console.log('[TimerLifecycle] Page frozen - pausing timer worker');
                    worker.postMessage({ action: 'pause' });
                }
            });
        }
        
        if ('onresume' in document) {
            document.addEventListener('resume', () => {
                const worker = getWorker();
                if (worker) {
                    console.log('[TimerLifecycle] Page resumed - resuming timer worker');
                    worker.postMessage({ action: 'resume' });
                }
            });
        }
    }
    
    // ==========================================================================
    // Health Monitoring
    // ==========================================================================
    
    /**
     * Track worker tick for health monitoring
     * Called by the main thread when a tick message is received
     */
    window.recordWorkerTick = function() {
        lastWorkerTick = Date.now();
    };
    
    /**
     * Setup health monitoring for the timer worker
     * Logs a warning if no ticks received for too long
     */
    function setupHealthMonitoring() {
        healthCheckInterval = setInterval(() => {
            const timeSinceLastTick = Date.now() - lastWorkerTick;
            
            if (timeSinceLastTick > WORKER_DEAD_THRESHOLD) {
                console.warn('[TimerLifecycle] Timer worker appears unresponsive - no ticks for', 
                    Math.round(timeSinceLastTick / 1000), 'seconds');
                
                // Log additional diagnostic info
                const worker = window.aviationwxTimerWorker;
                if (!worker) {
                    console.warn('[TimerLifecycle] Timer worker reference is null');
                }
            }
        }, HEALTH_CHECK_INTERVAL);
    }
    
    // ==========================================================================
    // Version Checking
    // ==========================================================================
    
    /**
     * Get version cookie value
     * @returns {Object|null} Parsed version { hash, timestamp } or null
     */
    function getVersionCookie() {
        const match = document.cookie.match(/aviationwx_v=([^;]+)/);
        if (!match) return null;
        
        const parts = match[1].split('.');
        if (parts.length !== 2) return null;
        
        return {
            hash: parts[0],
            timestamp: parseInt(parts[1], 10)
        };
    }
    
    /**
     * Check version API and compare with cookie
     * Logs if a newer version is available
     */
    async function checkVersion() {
        // Don't check if page is hidden
        if (document.hidden) return;
        
        try {
            const response = await fetch('/api/v1/version.php?_=' + Date.now(), {
                cache: 'no-store'
            });
            
            if (!response.ok) {
                console.warn('[TimerLifecycle] Version API returned status:', response.status);
                return;
            }
            
            const serverVersion = await response.json();
            const cookieVersion = getVersionCookie();
            
            if (!serverVersion.hash || !cookieVersion) {
                return;
            }
            
            // Compare first 7 chars of hash (what we store in cookie)
            const serverHashShort = serverVersion.hash.substring(0, 7);
            
            if (serverHashShort !== cookieVersion.hash) {
                console.log('[TimerLifecycle] New version available on server');
                console.log('[TimerLifecycle] Cookie:', cookieVersion.hash, '→ Server:', serverHashShort);
                
                // Check for force_cleanup flag
                if (serverVersion.force_cleanup === true) {
                    console.warn('[TimerLifecycle] Server requested force_cleanup');
                    
                    // Trigger cleanup if function is available
                    if (typeof window.performFullCleanup === 'function') {
                        window.performFullCleanup('Server requested force_cleanup via version API');
                    }
                }
            }
        } catch (e) {
            // Network errors are expected when offline
            if (navigator.onLine !== false) {
                console.warn('[TimerLifecycle] Version check failed:', e.message);
            }
        }
    }
    
    /**
     * Setup periodic version checking
     */
    function setupVersionChecking() {
        // Initial check after a delay (let page settle first)
        setTimeout(checkVersion, 10000);
        
        // Periodic checks
        versionCheckInterval = setInterval(checkVersion, VERSION_CHECK_INTERVAL);
    }
    
    // ==========================================================================
    // Cleanup on Page Unload
    // ==========================================================================
    
    /**
     * Setup cleanup handlers for page unload
     */
    function setupCleanupHandlers() {
        window.addEventListener('beforeunload', () => {
            // Clear intervals
            if (healthCheckInterval) {
                clearInterval(healthCheckInterval);
            }
            if (versionCheckInterval) {
                clearInterval(versionCheckInterval);
            }
            
            // Terminate timer worker
            const worker = window.aviationwxTimerWorker;
            if (worker) {
                console.log('[TimerLifecycle] Terminating timer worker on page unload');
                worker.terminate();
            }
            
            // Clear webcam refresh intervals (legacy cleanup)
            if (window.webcamRefreshIntervals) {
                for (const intervalId of window.webcamRefreshIntervals.values()) {
                    clearInterval(intervalId);
                }
            }
            
            // Cancel format retries
            if (window.formatRetries) {
                for (const camIndex of window.formatRetries.keys()) {
                    if (typeof window.cancelFormatRetry === 'function') {
                        window.cancelFormatRetry(camIndex);
                    }
                }
            }
        });
    }
    
    // ==========================================================================
    // Fallback Timer System
    // ==========================================================================
    
    /**
     * Create a fallback timer system using setInterval
     * Used when Web Workers are not available
     * 
     * @returns {Object} Timer manager with same API as worker-based system
     */
    window.createFallbackTimerSystem = function() {
        const timers = new Map();
        let paused = false;
        
        console.log('[TimerLifecycle] Using setInterval fallback timer system');
        
        return {
            /**
             * Register a new timer
             * @param {string} id Timer identifier
             * @param {number} interval Interval in milliseconds
             * @param {Function} callback Function to call on each tick
             */
            register: function(id, interval, callback) {
                // Clear existing timer if any
                if (timers.has(id)) {
                    clearInterval(timers.get(id).intervalId);
                }
                
                const intervalId = setInterval(() => {
                    if (!paused) {
                        callback();
                    }
                }, interval);
                
                timers.set(id, { intervalId, interval, callback });
                console.log('[TimerLifecycle] Registered fallback timer:', id, 'interval:', interval + 'ms');
            },
            
            /**
             * Unregister a timer
             * @param {string} id Timer identifier
             */
            unregister: function(id) {
                if (timers.has(id)) {
                    clearInterval(timers.get(id).intervalId);
                    timers.delete(id);
                    console.log('[TimerLifecycle] Unregistered fallback timer:', id);
                }
            },
            
            /**
             * Pause all timers
             */
            pause: function() {
                paused = true;
                console.log('[TimerLifecycle] Paused fallback timers');
            },
            
            /**
             * Resume all timers
             */
            resume: function() {
                paused = false;
                console.log('[TimerLifecycle] Resumed fallback timers');
            },
            
            /**
             * Check if using fallback
             * @returns {boolean} Always true for fallback system
             */
            isFallback: function() {
                return true;
            }
        };
    };
    
    // ==========================================================================
    // Initialization
    // ==========================================================================
    
    /**
     * Initialize all lifecycle handlers
     */
    function init() {
        console.log('[TimerLifecycle] Initializing timer lifecycle manager');
        
        setupVisibilityHandlers();
        setupHealthMonitoring();
        setupVersionChecking();
        setupCleanupHandlers();
        
        console.log('[TimerLifecycle] Initialization complete');
    }
    
    // Run initialization
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
})();



