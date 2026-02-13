<?php
/**
 * Sentry Error Tracking Integration
 * 
 * Initializes Sentry SDK for error tracking and performance monitoring.
 * Only activates in production with valid DSN.
 * 
 * Features:
 * - Automatic error capture (error, critical, alert, emergency)
 * - Performance transaction tracing (sampled)
 * - Service tagging (web, scheduler, workers)
 * - Context enrichment (airport IDs, sources)
 * - Privacy: Keeps operational data (IPs, UAs, URLs)
 */

require_once __DIR__ . '/config.php';

/**
 * Initialize Sentry error tracking
 * 
 * Only activates in production with valid DSN.
 * Filters events by severity and samples traces.
 * 
 * @return bool True if Sentry was initialized
 */
function initSentry(): bool {
    // Don't initialize in test mode
    if (isTestMode()) {
        return false;
    }
    
    // Check if Sentry SDK is available
    if (!class_exists('\Sentry\init')) {
        return false;
    }
    
    $dsn = getenv('SENTRY_DSN');
    if (empty($dsn) || trim($dsn) === '') {
        // No DSN configured - silently skip
        // This allows local dev without Sentry
        return false;
    }
    
    $environment = getenv('SENTRY_ENVIRONMENT') ?: getenv('APP_ENV') ?: 'production';
    $release = getenv('SENTRY_RELEASE') ?: getenv('GIT_SHA') ?: 'unknown';
    $errorSampleRate = (float)(getenv('SENTRY_SAMPLE_RATE_ERRORS') ?: 1.0);
    $tracesSampleRate = (float)(getenv('SENTRY_SAMPLE_RATE_TRACES') ?: 0.05);
    
    try {
        \Sentry\init([
            'dsn' => $dsn,
            'environment' => $environment,
            'release' => $release,
            'sample_rate' => $errorSampleRate,
            'traces_sample_rate' => $tracesSampleRate,
            
            // Tag by service for filtering
            'tags' => [
                'service' => php_sapi_name() === 'cli' ? 'cli' : 'web',
            ],
            
            // Filter out low-severity events
            'before_send' => function (\Sentry\Event $event): ?\Sentry\Event {
                $level = $event->getLevel();
                
                // Don't send info/debug to Sentry (keep in local logs only)
                if ($level === \Sentry\Severity::info() || $level === \Sentry\Severity::debug()) {
                    return null;
                }
                
                // Keep all operational data - this is a public service
                // IPs, User-Agents, and URLs are essential for debugging
                return $event;
            },
        ]);
        
        return true;
    } catch (Exception $e) {
        // If Sentry init fails, don't break the app
        error_log('Sentry initialization failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check if Sentry is initialized and available
 * 
 * @return bool True if Sentry is ready
 */
function isSentryAvailable(): bool {
    return defined('SENTRY_INITIALIZED') && SENTRY_INITIALIZED === true;
}

/**
 * Set service context for current operation
 * 
 * Tags events by service type for filtering in Sentry dashboard.
 * 
 * @param string $service Service name (scheduler, worker-weather, worker-webcam, worker-notam, web)
 * @param array $context Additional context (airport_id, camera_index, etc.)
 * @return void
 */
function sentrySetServiceContext(string $service, array $context = []): void {
    if (!isSentryAvailable()) {
        return;
    }
    
    \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($service, $context): void {
        $scope->setTag('service', $service);
        
        if (isset($context['airport_id'])) {
            $scope->setTag('airport_id', $context['airport_id']);
        }
        
        if (isset($context['camera_index'])) {
            $scope->setTag('camera_index', (string)$context['camera_index']);
        }
        
        if (isset($context['weather_source'])) {
            $scope->setTag('weather_source', $context['weather_source']);
        }
        
        if (isset($context['endpoint'])) {
            $scope->setTag('endpoint', $context['endpoint']);
        }
        
        // Add all context as additional data
        if (!empty($context)) {
            $scope->setContext('service_context', $context);
        }
    });
}

/**
 * Start a performance transaction
 * 
 * Used for tracing critical operations (API calls, aggregations, etc.).
 * 
 * @param string $operation Operation type (http.server, scheduler.iteration, etc.)
 * @param string $name Transaction name
 * @param array $tags Additional tags
 * @return \Sentry\Tracing\Transaction|null Transaction object or null if unavailable
 */
function sentryStartTransaction(string $operation, string $name, array $tags = []): ?\Sentry\Tracing\Transaction {
    if (!isSentryAvailable()) {
        return null;
    }
    
    $transaction = \Sentry\startTransaction([
        'op' => $operation,
        'name' => $name,
        'tags' => $tags,
    ]);
    
    \Sentry\SentrySdk::getCurrentHub()->setSpan($transaction);
    
    return $transaction;
}

/**
 * Finish a performance transaction
 * 
 * @param \Sentry\Tracing\Transaction|null $transaction Transaction to finish
 * @return void
 */
function sentryFinishTransaction(?\Sentry\Tracing\Transaction $transaction): void {
    if ($transaction !== null) {
        $transaction->finish();
    }
}

// Initialize Sentry early in bootstrap
if (!defined('SENTRY_INITIALIZED')) {
    $initialized = initSentry();
    define('SENTRY_INITIALIZED', $initialized);
    
    if ($initialized) {
        // Register shutdown function to flush any pending events
        register_shutdown_function(function() {
            // Check for fatal errors
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                \Sentry\captureMessage(
                    "Fatal error: {$error['message']} in {$error['file']}:{$error['line']}",
                    \Sentry\Severity::fatal()
                );
            }
        });
    }
}
