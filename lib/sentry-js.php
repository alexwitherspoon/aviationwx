<?php
/**
 * Sentry JavaScript SDK Integration
 * 
 * Provides helper functions for injecting Sentry JavaScript SDK
 * into HTML pages for frontend error tracking and performance monitoring.
 */

/**
 * Render Sentry JavaScript SDK initialization script
 * 
 * Outputs the Sentry Browser SDK initialization in a script tag.
 * Only outputs in production with valid DSN. Silent no-op otherwise.
 * 
 * Features:
 * - Error tracking (unhandled exceptions, promise rejections)
 * - Performance monitoring (page load, AJAX calls, user interactions)
 * - Service Worker error tracking
 * - User context (hashed IP for privacy)
 * - Release tracking (Git SHA)
 * 
 * @return void Echoes script tag directly
 */
function renderSentryJsInit(): void {
    // Only initialize in production
    if (defined('APP_ENV') && APP_ENV !== 'production') {
        return;
    }
    
    $dsn = getenv('SENTRY_DSN');
    if (empty($dsn) || trim($dsn) === '') {
        return;
    }
    
    $environment = getenv('SENTRY_ENVIRONMENT') ?: getenv('APP_ENV') ?: 'production';
    $release = getenv('SENTRY_RELEASE') ?: getenv('GIT_SHA') ?: 'unknown';
    $tracesSampleRate = (float)(getenv('SENTRY_SAMPLE_RATE_TRACES') ?: 0.05);
    $replaySampleRate = 0.0; // Replays off by default (high quota usage)
    
    // Get current airport ID if available (from global scope)
    $airportId = $GLOBALS['airportId'] ?? null;
    
    // Hash IP address for privacy-conscious user identification
    $userIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userHash = substr(hash('sha256', $userIp . 'aviationwx-salt'), 0, 16);
    
    // Escape values for JavaScript
    $dsnEscaped = htmlspecialchars($dsn, ENT_QUOTES, 'UTF-8');
    $environmentEscaped = htmlspecialchars($environment, ENT_QUOTES, 'UTF-8');
    $releaseEscaped = htmlspecialchars($release, ENT_QUOTES, 'UTF-8');
    $userHashEscaped = htmlspecialchars($userHash, ENT_QUOTES, 'UTF-8');
    $airportIdEscaped = $airportId ? htmlspecialchars($airportId, ENT_QUOTES, 'UTF-8') : null;
    
    echo <<<HTML
    <!-- Sentry Browser SDK -->
    <script
        src="https://browser.sentry-cdn.com/8.47.0/bundle.min.js"
        integrity="sha384-CjF7FqQ0cVDPJvL6kcz+RG5xRNDBtG0SZ9G5GHlT8r0xLz+TU8gNzqKH8JlZx9dQ"
        crossorigin="anonymous"
    ></script>
    <script>
        (function() {
            "use strict";
            
            if (!window.Sentry) {
                console.warn('[Sentry] SDK failed to load from CDN');
                return;
            }
            
            Sentry.init({
                dsn: "{$dsnEscaped}",
                environment: "{$environmentEscaped}",
                release: "{$releaseEscaped}",
                
                // Performance Monitoring
                integrations: [
                    Sentry.browserTracingIntegration({
                        // Track navigation (page loads)
                        tracePropagationTargets: [
                            "localhost",
                            /^\//,  // Relative URLs
                            /^https:\/\/.*\.aviationwx\.org/  // Our domains
                        ],
                    }),
                    // Track fetch/XHR requests
                    Sentry.httpClientIntegration(),
                ],
                
                // Sample rates
                tracesSampleRate: {$tracesSampleRate},
                replaysSessionSampleRate: {$replaySampleRate},
                replaysOnErrorSampleRate: 0.0,  // No replays even on errors (quota)
                
                // Ignore known noise
                ignoreErrors: [
                    // Browser extensions
                    'top.GLOBALS',
                    'chrome-extension://',
                    'moz-extension://',
                    // Third-party scripts
                    /fb_xd_fragment/,
                    /google-analytics/,
                    /gtag/,
                    // Network errors (expected)
                    'NetworkError',
                    'Failed to fetch',
                    'Load failed',
                ],
                
                // Filter events before sending
                beforeSend(event, hint) {
                    // Don't send if error is from browser extension
                    if (event.exception && event.exception.values) {
                        const firstException = event.exception.values[0];
                        if (firstException && firstException.stacktrace && firstException.stacktrace.frames) {
                            const frames = firstException.stacktrace.frames;
                            if (frames.some(frame => frame.filename && (
                                frame.filename.includes('chrome-extension://') ||
                                frame.filename.includes('moz-extension://')
                            ))) {
                                return null;  // Drop event
                            }
                        }
                    }
                    
                    return event;
                },
            });
            
            // Set user context (hashed IP for privacy)
            Sentry.setUser({ id: "{$userHashEscaped}" });
            
            // Set tags for filtering
HTML;
    
    if ($airportIdEscaped) {
        echo "\n            Sentry.setTag('airport_id', '{$airportIdEscaped}');";
    }
    
    echo <<<HTML

            Sentry.setTag('page_type', 'airport_dashboard');
            
            // Track Service Worker errors
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.addEventListener('error', function(error) {
                    Sentry.captureException(error, {
                        tags: { service_worker: true }
                    });
                });
            }
            
            console.log('[Sentry] Initialized (JS SDK)');
        })();
    </script>

HTML;
}

/**
 * Check if Sentry JS should be initialized
 * 
 * Helper function to check conditions without outputting anything.
 * 
 * @return bool True if Sentry JS should initialize
 */
function shouldInitializeSentryJs(): bool {
    if (defined('APP_ENV') && APP_ENV !== 'production') {
        return false;
    }
    
    $dsn = getenv('SENTRY_DSN');
    return !empty($dsn) && trim($dsn) !== '';
}
