// AviationWX Service Worker
// Provides offline support and background sync for weather data

const CACHE_VERSION = 'v3';
const CACHE_NAME = `aviationwx-${CACHE_VERSION}`;
const STATIC_CACHE = `${CACHE_NAME}-static`;
const WEATHER_CACHE = `${CACHE_NAME}-weather`;

// Timeout constants (in milliseconds)
const TIMEOUT_NORMAL = 8000;
const TIMEOUT_SLOW_CONNECTION = 15000;
const TIMEOUT_FORCED_REFRESH_NORMAL = 10000;
const TIMEOUT_FORCED_REFRESH_SLOW = 15000;

// Staleness threshold (in seconds)
const MAX_STALE_AGE_SECONDS = 10 * 60; // 10 minutes

// Assets to cache on install
// Only include files that exist - styles.min.css may not exist
const STATIC_ASSETS = [
    '/',
    '/public/css/styles.css',
    '/index.php'
];

// Request-scoped connection info cache
// Caches connection info per request to avoid multiple API calls
let requestConnectionInfo = null;

// Cross-request connection info cache with TTL
// Caches connection info across requests for short period to reduce API calls
let globalConnectionInfo = null;
let globalConnectionInfoTimestamp = 0;
const CONNECTION_INFO_TTL = 5000; // 5 seconds TTL for cross-request caching

// In-flight request deduplication
// Maps normalized request URLs to their fetch promises to avoid duplicate network requests
const inFlightRequests = new Map();

// Maximum time to keep in-flight request entries (prevents memory leaks)
// Set to 2x the longest timeout to ensure cleanup even if promise hangs
const IN_FLIGHT_REQUEST_MAX_AGE = Math.max(
    TIMEOUT_FORCED_REFRESH_SLOW * 2,
    TIMEOUT_SLOW_CONNECTION * 2
);

// Retry configuration constants (must be defined before use)
const MAX_RETRIES = 2; // Maximum number of retries for transient failures
const INITIAL_RETRY_DELAY = 1000; // Initial retry delay in milliseconds (1 second)
const MAX_RETRY_DELAY = 5000; // Maximum retry delay in milliseconds (5 seconds)
const RETRY_JITTER_FACTOR = 0.2; // Jitter factor (0-20% random variation) to prevent thundering herd

// Cache version tracking for cleanup optimization
const CLEANUP_VERSION_KEY = 'sw-cleanup-version';
const CLEANUP_VERSION_CACHE = 'sw-metadata';

// Cache size management
const WEATHER_CACHE_MAX_ENTRIES = 50; // Maximum number of weather cache entries (LRU eviction)
const CACHE_ACCESS_TIME_KEY = 'sw-cache-access-times';

/**
 * Safely detect network connection quality
 * 
 * Returns connection info with error handling for browsers that don't support
 * the Network Information API or throw errors when accessing it.
 * Uses multi-level caching: request-scoped cache and cross-request TTL cache
 * to minimize API calls.
 * 
 * @param {boolean} useCache Whether to use cached connection info (default: true)
 * @returns {Object|null} Connection object or null if unavailable/error
 */
self.getConnectionInfo = function(useCache = true) {
    // First check request-scoped cache (fastest)
    if (useCache && requestConnectionInfo !== null) {
        return requestConnectionInfo;
    }
    
    // Then check cross-request cache with TTL
    const now = Date.now();
    if (useCache && globalConnectionInfo !== null && 
        (now - globalConnectionInfoTimestamp) < CONNECTION_INFO_TTL) {
        // Use global cache and also set request cache for this request
        requestConnectionInfo = globalConnectionInfo;
        return globalConnectionInfo;
    }
    
    // Cache expired or not available - fetch fresh connection info
    try {
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
        
        // Update both caches
        if (useCache) {
            requestConnectionInfo = connection;
            globalConnectionInfo = connection;
            globalConnectionInfoTimestamp = now;
        }
        
        return connection;
    } catch (err) {
        // Some browsers may throw when accessing connection API
        console.warn('[SW] Error accessing connection API:', err);
        return null;
    }
};

/**
 * Clear request-scoped connection info cache
 * 
 * Should be called at the end of request handling to allow fresh
 * connection detection for subsequent requests.
 */
self.clearConnectionInfoCache = function() {
    requestConnectionInfo = null;
};

/**
 * Check if connection is slow based on effective type or save data preference
 * 
 * Uses cached connection info if available to avoid redundant API calls.
 * 
 * @returns {boolean} True if connection is considered slow
 */
self.isSlowConnection = function() {
    const connection = self.getConnectionInfo();
    if (!connection) {
        return false;
    }
    
    return (
        connection.effectiveType === 'slow-2g' ||
        connection.effectiveType === '2g' ||
        connection.saveData === true
    );
};

/**
 * Get appropriate timeout based on connection quality and refresh type
 * 
 * Uses cached connection info to avoid multiple API calls per request.
 * 
 * @param {boolean} isForcedRefresh Whether this is a forced refresh request
 * @returns {number} Timeout in milliseconds
 */
self.getNetworkTimeout = function(isForcedRefresh = false) {
    const slow = self.isSlowConnection();
    
    if (isForcedRefresh) {
        return slow ? TIMEOUT_FORCED_REFRESH_SLOW : TIMEOUT_FORCED_REFRESH_NORMAL;
    }
    
    return slow ? TIMEOUT_SLOW_CONNECTION : TIMEOUT_NORMAL;
};

/**
 * Check if cached weather response is stale
 * 
 * First checks custom header for timestamp (fast, no parsing needed).
 * Falls back to parsing JSON if header is not available (backward compatibility).
 * Returns null if response cannot be checked or is invalid.
 * 
 * @param {Response} cachedResponse Cached response to check
 * @returns {Promise<boolean|null>} True if stale, false if fresh, null if cannot determine
 */
self.isCachedResponseStale = async function(cachedResponse) {
    if (!cachedResponse) {
        return null;
    }
    
    // Fast path: Check newest obs_time header first (preferred method)
    const newestObsTimeHeader = cachedResponse.headers.get('X-Weather-Newest-Obs-Time');
    if (newestObsTimeHeader) {
        try {
            const newestObsTime = parseInt(newestObsTimeHeader, 10);
            if (!isNaN(newestObsTime) && newestObsTime > 0) {
                const now = Math.floor(Date.now() / 1000);
                const age = now - newestObsTime;
                // Use 10x multiplier threshold (600 seconds = 10 minutes for 60s refresh)
                // This is conservative - frontend will do per-field checks
                const staleThreshold = 600; // 10 minutes default
                return age >= staleThreshold;
            }
        } catch (err) {
            // Header exists but invalid, fall through to JSON parsing
            console.warn('[SW] Invalid X-Weather-Newest-Obs-Time header:', err);
        }
    }
    
    // Fallback: Check last_updated header (backward compatibility)
    const lastUpdatedHeader = cachedResponse.headers.get('X-Weather-Last-Updated');
    if (lastUpdatedHeader) {
        try {
            const lastUpdated = parseInt(lastUpdatedHeader, 10);
            if (!isNaN(lastUpdated) && lastUpdated > 0) {
                const cacheAge = Date.now() / 1000 - lastUpdated;
                return cacheAge > MAX_STALE_AGE_SECONDS;
            }
        } catch (err) {
            // Header exists but invalid, fall through to JSON parsing
            console.warn('[SW] Invalid X-Weather-Last-Updated header:', err);
        }
    }
    
    // Fallback: Parse JSON for backward compatibility with old cached responses
    // This is slower but ensures we can check staleness even for responses
    // cached before we added the header optimization
    try {
        const clonedResponse = cachedResponse.clone();
        const cachedData = await clonedResponse.json();
        
        if (cachedData && cachedData.weather) {
            // Try to get newest obs_time from _field_obs_time_map (preferred)
            if (cachedData.weather._field_obs_time_map && typeof cachedData.weather._field_obs_time_map === 'object') {
                const obsTimes = Object.values(cachedData.weather._field_obs_time_map).filter(t => t > 0);
                if (obsTimes.length > 0) {
                    const newestObsTime = Math.max(...obsTimes);
                    const now = Math.floor(Date.now() / 1000);
                    const age = now - newestObsTime;
                    // Use 10x multiplier threshold (600 seconds = 10 minutes for 60s refresh)
                    const staleThreshold = 600;
                    return age >= staleThreshold;
                }
            }
            
            // Fallback to last_updated
            if (cachedData.weather.last_updated) {
                const cacheAge = Date.now() / 1000 - cachedData.weather.last_updated;
                return cacheAge > MAX_STALE_AGE_SECONDS;
            }
        }
        
        return null; // Cannot determine staleness without timestamp
    } catch (parseErr) {
        // If we can't parse the response, we can't determine staleness
        console.warn('[SW] Could not parse cached response for staleness check:', parseErr);
        return null;
    }
};

/**
 * Normalize weather request URL by removing cache-busting parameters
 * 
 * Creates a consistent cache key by removing `_cb` and other cache-busting
 * parameters while preserving the airport parameter. This allows cache
 * matching to work even when query parameters differ.
 * 
 * @param {URL} url Original URL to normalize
 * @returns {string} Normalized URL string for use as cache key
 */
self.normalizeWeatherRequestUrl = function(url) {
    const normalizedUrl = new URL(url);
    
    // Remove cache-busting parameters
    normalizedUrl.searchParams.delete('_cb');
    
    // Sort parameters for consistency (optional but ensures deterministic keys)
    const sortedParams = Array.from(normalizedUrl.searchParams.entries())
        .sort((a, b) => a[0].localeCompare(b[0]));
    
    normalizedUrl.search = '';
    sortedParams.forEach(([key, value]) => {
        normalizedUrl.searchParams.append(key, value);
    });
    
    return normalizedUrl.toString();
};

/**
 * Create normalized request for cache operations
 * 
 * Creates a new Request with normalized URL for consistent cache key matching.
 * Preserves original request properties (method, headers, etc.) but uses
 * normalized URL.
 * 
 * @param {Request} request Original request
 * @param {URL} url Parsed URL from original request
 * @returns {Request} Normalized request for cache operations
 */
self.createNormalizedCacheRequest = function(request, url) {
    const normalizedUrl = self.normalizeWeatherRequestUrl(url);
    return new Request(normalizedUrl, {
        method: request.method,
        headers: request.headers,
        credentials: request.credentials,
        cache: request.cache,
        redirect: request.redirect,
        referrer: request.referrer,
        referrerPolicy: request.referrerPolicy,
        integrity: request.integrity
    });
};

/**
 * Extract timestamp from weather response and create response with header
 * 
 * Extracts last_updated timestamp from JSON response and creates a new Response
 * with X-Weather-Last-Updated header for fast staleness checking. Optimized to
 * minimize response cloning by reusing the parsed data when possible.
 * 
 * @param {Response} response Original response to process
 * @returns {Promise<Response>} Response with timestamp header or original response
 */
self.extractAndCacheWithTimestamp = async function(response) {
    // Clone response once to read JSON without consuming original body
    const clonedForParsing = response.clone();
    let lastUpdated = null;
    let newestObsTime = null;
    let responseBody = null;
    
    try {
        // Read body as array buffer to reuse it later (more efficient than cloning again)
        const bodyArrayBuffer = await clonedForParsing.arrayBuffer();
        const data = JSON.parse(new TextDecoder().decode(bodyArrayBuffer));
        
        if (data && data.weather) {
            // Extract last_updated for backward compatibility
            if (data.weather.last_updated) {
                lastUpdated = data.weather.last_updated;
            }
            
            // Extract newest obs_time from _field_obs_time_map (preferred method)
            if (data.weather._field_obs_time_map && typeof data.weather._field_obs_time_map === 'object') {
                const obsTimes = Object.values(data.weather._field_obs_time_map).filter(t => t > 0);
                if (obsTimes.length > 0) {
                    newestObsTime = Math.max(...obsTimes);
                }
            }
            
            // Reuse the array buffer for the new response
            responseBody = bodyArrayBuffer;
        }
    } catch (parseErr) {
        // If we can't parse, return original response (fallback to JSON parsing later)
        console.warn('[SW] Could not extract timestamp for header:', parseErr);
        return response.clone();
    }
    
    // If no timestamp found, return original response
    if (lastUpdated === null && newestObsTime === null) {
        return response.clone();
    }
    
    // Create new response with timestamp in header using reused body
    // This avoids an extra clone operation
    return new Response(responseBody, {
        status: response.status,
        statusText: response.statusText,
        headers: (() => {
            const headers = new Headers(response.headers);
            // Set last_updated for backward compatibility
            if (lastUpdated !== null) {
                headers.set('X-Weather-Last-Updated', lastUpdated.toString());
            }
            // Set newest obs_time (preferred method for staleness checks)
            if (newestObsTime !== null) {
                headers.set('X-Weather-Newest-Obs-Time', newestObsTime.toString());
            }
            return headers;
        })()
    });
};

/**
 * Create standardized error response for weather API failures
 * 
 * @param {string} errorMessage Error message to include in response
 * @returns {Response} Standardized error response
 */
self.createErrorResponse = function(errorMessage) {
    return new Response(
        JSON.stringify({ success: false, error: errorMessage }),
        { 
            status: 503, 
            headers: { 'Content-Type': 'application/json' } 
        }
    );
};

/**
 * Handle forced refresh request - bypass cache and fetch fresh data
 * 
 * For forced refresh requests, we bypass cache entirely and use longer timeouts
 * for slow connections. If network fails, we return an error rather than
 * falling back to cache.
 * 
 * @param {Request} request Original request
 * @param {URL} url Parsed URL
 * @returns {Promise<Response>} Network response or error response
 */
self.handleForcedRefresh = async function(request, _url) {
    console.log('[SW] Forced refresh detected - bypassing cache entirely');
    
    try {
        const timeout = self.getNetworkTimeout(true);
        
        // Create a new request with cache-busting headers for forced refresh
        const newHeaders = new Headers(request.headers);
        newHeaders.set('Cache-Control', 'no-cache');
        const newRequest = new Request(request, {
            cache: 'no-store', // Don't cache forced refresh responses
            headers: newHeaders
        });
        
        // Use retry logic for forced refresh (but don't cache the response)
        const networkResponse = await self.fetchWithRetry(newRequest, timeout, MAX_RETRIES);
        
        if (networkResponse && networkResponse.ok) {
            return networkResponse;
        }
        
        // For forced refresh, don't fall back to cache - return error
        return networkResponse || self.createErrorResponse('Weather data unavailable - please try again');
    } finally {
        // Clear connection info cache at end of request
        self.clearConnectionInfoCache();
    }
};

/**
 * Check if error is retryable (transient failure)
 * 
 * Determines if an error or response indicates a transient failure that
 * should be retried with exponential backoff.
 * 
 * @param {Response|null} response Response from fetch (null if error)
 * @param {Error|null} error Error from fetch (null if no error)
 * @returns {boolean} True if error is retryable
 */
self.isRetryableError = function(response, error) {
    // Network errors are retryable
    if (error || !response) {
        return true;
    }
    
    // 5xx server errors are retryable (transient)
    if (response.status >= 500 && response.status < 600) {
        return true;
    }
    
    // 429 Too Many Requests is retryable
    if (response.status === 429) {
        return true;
    }
    
    // Other errors are not retryable
    return false;
};

/**
 * Calculate exponential backoff delay
 * 
 * Calculates retry delay with exponential backoff and jitter.
 * 
 * @param {number} attemptNumber Current attempt number (0-based)
 * @returns {number} Delay in milliseconds
 */
self.calculateRetryDelay = function(attemptNumber) {
    // Exponential backoff: delay = initial * 2^attempt
    const exponentialDelay = INITIAL_RETRY_DELAY * Math.pow(2, attemptNumber);
    
    // Cap at maximum delay
    const cappedDelay = Math.min(exponentialDelay, MAX_RETRY_DELAY);
    
    // Add jitter to prevent thundering herd (random variation)
    const jitter = cappedDelay * RETRY_JITTER_FACTOR * Math.random();
    
    return Math.floor(cappedDelay + jitter);
};

/**
 * Fetch with retry logic and exponential backoff
 * 
 * Attempts to fetch with retries for transient failures. Uses exponential
 * backoff with jitter to prevent thundering herd problems.
 * 
 * On slow connections, timeouts are expected and not retried to avoid
 * overwhelming the connection with multiple concurrent requests.
 * 
 * @param {Request} request Request to fetch
 * @param {number} timeout Timeout in milliseconds
 * @param {number} maxRetries Maximum number of retries
 * @returns {Promise<Response|null>} Response or null on failure
 */
self.fetchWithRetry = async function(request, timeout, maxRetries = MAX_RETRIES) {
    let lastResponse = null;
    const isSlow = self.isSlowConnection();
    
    // On slow connections, don't retry timeouts (timeouts are expected on slow connections)
    // Still retry actual network errors and server errors
    const shouldRetryTimeouts = !isSlow;
    
    for (let attempt = 0; attempt <= maxRetries; attempt++) {
        let timedOut = false;
        let timeoutId = null;
        
        try {
            const networkPromise = fetch(request).catch((_err) => {
                // Error handled in outer catch block
                // Clear timeout if network error occurs before timeout
                if (timeoutId !== null) {
                    clearTimeout(timeoutId);
                }
                return null;
            });
            
            // Track if timeout occurred - set flag before resolving
            const timeoutPromise = new Promise((resolve) => {
                timeoutId = setTimeout(() => {
                    timedOut = true;
                    resolve(null);
                }, timeout);
            });
            
            const response = await Promise.race([networkPromise, timeoutPromise]);
            
            // Clear timeout if network resolved first
            if (timeoutId !== null && response !== null) {
                clearTimeout(timeoutId);
            }
            
            // If we got a response, check if it's retryable
            if (response) {
                lastResponse = response;
                
                // Success or non-retryable error - return immediately
                if (response.ok || !self.isRetryableError(response, null)) {
                    return response;
                }
                
                // Retryable error - continue to retry logic below
                if (attempt < maxRetries) {
                    console.log(`[SW] Retryable error (${response.status}), retrying... (attempt ${attempt + 1}/${maxRetries})`);
                }
            } else {
                // Timeout or network error
                // On slow connections, don't retry timeouts (they're expected)
                // Still retry actual network errors
                if (timedOut && !shouldRetryTimeouts) {
                    // Slow connection timeout - don't retry, return null
                    console.log('[SW] Timeout on slow connection - not retrying (expected behavior)');
                    return null;
                }
                
                // Network error or timeout on fast connection - retry
                if (attempt < maxRetries) {
                    const errorType = timedOut ? 'timeout' : 'network error';
                    console.log(`[SW] ${errorType}, retrying... (attempt ${attempt + 1}/${maxRetries})`);
                }
            }
            
            // If this is the last attempt, return the response/error
            if (attempt >= maxRetries) {
                return response;
            }
            
            // Calculate delay and wait before retry
            const delay = self.calculateRetryDelay(attempt);
            await new Promise(resolve => setTimeout(resolve, delay));
            
        } catch (err) {
            // Error occurred during retry - log for debugging
            console.error('[SW] Fetch retry error:', err);
            
            // Clear timeout if still active
            if (timeoutId !== null) {
                clearTimeout(timeoutId);
            }
            
            // If this is the last attempt, return null
            if (attempt >= maxRetries) {
                return null;
            }
            
            // Calculate delay and wait before retry
            const delay = self.calculateRetryDelay(attempt);
            await new Promise(resolve => setTimeout(resolve, delay));
        }
    }
    
    return lastResponse;
};

/**
 * Get or create in-flight fetch promise for request deduplication
 * 
 * If a request with the same normalized URL is already in flight, returns
 * the existing promise. Otherwise, creates a new fetch promise with retry
 * logic and tracks it. Includes timeout cleanup to prevent memory leaks.
 * 
 * @param {Request} request Original request
 * @param {string} normalizedUrl Normalized URL string for deduplication key
 * @param {number} timeout Timeout in milliseconds
 * @returns {Promise<Response|null>} Network response promise or null on timeout
 */
self.getOrCreateFetchPromise = function(request, normalizedUrl, timeout) {
    // Check if request is already in flight
    if (inFlightRequests.has(normalizedUrl)) {
        console.log('[SW] Reusing in-flight request:', normalizedUrl);
        return inFlightRequests.get(normalizedUrl);
    }
    
    // Create fetch promise with retry logic
    const fetchPromise = self.fetchWithRetry(request, timeout);
    
    // Track the promise
    inFlightRequests.set(normalizedUrl, fetchPromise);
    
    // Clean up when promise resolves (success or failure)
    fetchPromise.finally(() => {
        inFlightRequests.delete(normalizedUrl);
    });
    
    // Safety timeout: Force cleanup after maximum age to prevent memory leaks
    // This ensures cleanup even if the promise never resolves
    setTimeout(() => {
        if (inFlightRequests.has(normalizedUrl)) {
            console.warn('[SW] Force cleaning up hung in-flight request:', normalizedUrl);
            inFlightRequests.delete(normalizedUrl);
        }
    }, IN_FLIGHT_REQUEST_MAX_AGE);
    
    return fetchPromise;
};

/**
 * Handle normal weather request - network-first with cache fallback
 * 
 * Attempts network fetch first with timeout. If network fails or times out,
 * falls back to cache if data is fresh (< 10 minutes old). Caches successful
 * network responses for future use. Uses normalized cache key to match
 * requests with different query parameters. Deduplicates in-flight requests.
 * 
 * @param {Request} request Original request
 * @param {URL} url Parsed URL from request
 * @returns {Promise<Response>} Network response, cached response, or error response
 */
self.handleNormalWeatherRequest = async function(request, url) {
    try {
        const timeout = self.getNetworkTimeout(false);
        
        // Create normalized request for cache operations (removes cache-busting params)
        const normalizedRequest = self.createNormalizedCacheRequest(request, url);
        const normalizedUrl = normalizedRequest.url;
        
        // Use deduplication to avoid multiple simultaneous requests for same URL
        const networkResponse = await self.getOrCreateFetchPromise(request, normalizedUrl, timeout);
        
        // If network succeeds, cache the response using normalized key
        // Store timestamp in custom header for fast staleness checking
        if (networkResponse && networkResponse.ok) {
            try {
                const cache = await caches.open(WEATHER_CACHE);
                
                // Extract timestamp and create response with header
                const responseToCache = await self.extractAndCacheWithTimestamp(networkResponse);
                
                await cache.put(normalizedRequest, responseToCache).catch(() => {
                    // Cache put failed, but continue serving response
                    console.warn('[SW] Failed to cache weather response');
                });
                
                // Update access time for LRU tracking
                await self.updateCacheAccessTime(normalizedRequest.url);
                
                // Evict old entries if cache exceeds limit
                await self.evictLRUCacheEntries(cache);
            } catch (err) {
                // Cache operation failed, but continue serving response
                console.warn('[SW] Error caching weather response:', err);
            }
            return networkResponse;
        }
        
        // Network failed or timed out - try cache if fresh (using normalized key)
        const cachedResponse = await caches.match(normalizedRequest);
        if (cachedResponse) {
            // Update access time for LRU tracking (cache hit)
            await self.updateCacheAccessTime(normalizedRequest.url);
            
            const isStale = await self.isCachedResponseStale(cachedResponse);
            
            if (isStale === true) {
                // Cache is stale, don't serve it
                console.log('[SW] Cached weather data is too stale - not serving');
                return networkResponse || self.createErrorResponse('Weather data unavailable - please refresh');
            }
            
            // Cache is fresh or we can't determine staleness - serve it
            if (isStale === false) {
                console.log('[SW] Serving weather from cache (network unavailable)');
            } else {
                // Cannot determine staleness, serve cache as fallback
                console.log('[SW] Serving weather from cache (staleness check failed)');
            }
            return cachedResponse;
        }
        
        // No cache available - return network response or error
        return networkResponse || self.createErrorResponse('Offline - no cached data');
    } finally {
        // Clear connection info cache at end of request
        self.clearConnectionInfoCache();
    }
};

/**
 * Handle weather API request with appropriate strategy
 * 
 * Routes to forced refresh handler or normal request handler based on
 * cache-busting parameters or headers.
 * 
 * @param {Request} request Original request
 * @param {URL} url Parsed URL
 * @returns {Promise<Response>} Response from appropriate handler
 */
self.handleWeatherRequest = async function(request, url) {
    // Detect forced refresh requests
    const hasCacheBusting = url.searchParams.has('_cb');
    const hasNoCacheHeader = request.headers.get('Cache-Control') === 'no-cache' ||
        request.cache === 'reload';
    const isForcedRefresh = hasCacheBusting || hasNoCacheHeader;
    
    if (isForcedRefresh) {
        return self.handleForcedRefresh(request, url);
    }
    
    return self.handleNormalWeatherRequest(request, url);
};

/**
 * Get the last cache version that was cleaned up
 * 
 * Retrieves the stored cache version from metadata cache to determine
 * if cleanup is needed.
 * 
 * @returns {Promise<string|null>} Last cleaned cache version or null if not found
 */
self.getLastCleanedVersion = async function() {
    try {
        const metadataCache = await caches.open(CLEANUP_VERSION_CACHE);
        const response = await metadataCache.match(CLEANUP_VERSION_KEY);
        if (response) {
            return await response.text();
        }
    } catch (err) {
        console.warn('[SW] Could not read last cleaned version:', err);
    }
    return null;
};

/**
 * Store the current cache version as the last cleaned version
 * 
 * Saves the cache version to metadata cache after cleanup completes.
 * 
 * @param {string} version Cache version to store
 * @returns {Promise<void>}
 */
self.setLastCleanedVersion = async function(version) {
    try {
        const metadataCache = await caches.open(CLEANUP_VERSION_CACHE);
        await metadataCache.put(
            CLEANUP_VERSION_KEY,
            new Response(version, {
                headers: { 'Content-Type': 'text/plain' }
            }));
    } catch (err) {
        console.warn('[SW] Could not store last cleaned version:', err);
    }
};

/**
 * Get cache access times for LRU eviction
 * 
 * Retrieves access time metadata to track which cache entries are least recently used.
 * 
 * @returns {Promise<Object>} Map of URL to access timestamp
 */
self.getCacheAccessTimes = async function() {
    try {
        const metadataCache = await caches.open(CLEANUP_VERSION_CACHE);
        const response = await metadataCache.match(CACHE_ACCESS_TIME_KEY);
        if (response) {
            const data = await response.json();
            return data || {};
        }
    } catch (err) {
        console.warn('[SW] Could not read cache access times:', err);
    }
    return {};
};

/**
 * Update cache access time for LRU tracking
 * 
 * Records when a cache entry was last accessed for LRU eviction.
 * 
 * @param {string} url Cache entry URL
 * @returns {Promise<void>}
 */
self.updateCacheAccessTime = async function(url) {
    try {
        const accessTimes = await self.getCacheAccessTimes();
        accessTimes[url] = Date.now();
        
        const metadataCache = await caches.open(CLEANUP_VERSION_CACHE);
        await metadataCache.put(
            CACHE_ACCESS_TIME_KEY,
            new Response(JSON.stringify(accessTimes), {
                headers: { 'Content-Type': 'application/json' }
            })
        );
    } catch (err) {
        console.warn('[SW] Could not update cache access time:', err);
    }
};

/**
 * Evict least recently used cache entries
 * 
 * Removes oldest cache entries when cache exceeds maximum size.
 * Uses LRU (Least Recently Used) eviction strategy.
 * 
 * @param {Cache} cache Cache instance to evict from
 * @returns {Promise<void>}
 */
self.evictLRUCacheEntries = async function(cache) {
    try {
        const requests = await cache.keys();
        
        // If cache is within limit, no eviction needed
        if (requests.length <= WEATHER_CACHE_MAX_ENTRIES) {
            return;
        }
        
        const accessTimes = await self.getCacheAccessTimes();
        const entriesToEvict = requests.length - WEATHER_CACHE_MAX_ENTRIES;
        
        // Sort by access time (oldest first)
        const sortedEntries = requests
            .map(request => ({
                request,
                accessTime: accessTimes[request.url] || 0
            }))
            .sort((a, b) => a.accessTime - b.accessTime);
        
        // Evict oldest entries
        const toEvict = sortedEntries.slice(0, entriesToEvict);
        await Promise.all(toEvict.map(async ({ request }) => {
            await cache.delete(request);
            delete accessTimes[request.url];
            console.log('[SW] Evicted LRU cache entry:', request.url);
        }));
        
        // Update access times metadata
        const metadataCache = await caches.open(CLEANUP_VERSION_CACHE);
        await metadataCache.put(
            CACHE_ACCESS_TIME_KEY,
            new Response(JSON.stringify(accessTimes), {
                headers: { 'Content-Type': 'application/json' }
            })
        );
    } catch (err) {
        console.error('[SW] Error during LRU eviction:', err);
    }
};

/**
 * Check if cache cleanup is needed based on version change
 * 
 * Compares current cache version with last cleaned version to determine
 * if cleanup should run.
 * 
 * @returns {Promise<boolean>} True if cleanup is needed, false otherwise
 */
self.isCleanupNeeded = async function() {
    const lastCleanedVersion = await self.getLastCleanedVersion();
    return lastCleanedVersion !== CACHE_VERSION;
};

/**
 * Clean up bad cached responses from a cache
 * 
 * Removes responses that are not OK (404s, etc.) and responses that are HTML
 * but should be JS/CSS. This prevents serving bad cached data.
 * 
 * @param {Cache} cache Cache to clean
 * @param {string} cacheName Name of cache for logging
 * @returns {Promise<void>}
 */
self.cleanBadCachedResponses = async function(cache, cacheName) {
    try {
        const requests = await cache.keys();
        
        await Promise.all(requests.map(async (request) => {
            try {
                const response = await cache.match(request);
                
                if (!response) {
                    return;
                }
                
                // Delete non-OK responses (404s, etc.)
                if (!response.ok) {
                    console.log(`[SW] Deleting bad cached response from ${cacheName}:`, request.url);
                    await cache.delete(request);
                    return;
                }
                
                // Check if response is HTML but should be JS/CSS
                if (request.url.match(/\.(js|css)$/)) {
                    try {
                        const clonedResponse = response.clone();
                        const text = await clonedResponse.text();
                        
                        // If it starts with HTML tags, it's a bad cache
                        if (text.trim().match(/^<(!DOCTYPE|html|body|div|span|p)/i)) {
                            console.log(`[SW] Deleting HTML cached as JS/CSS from ${cacheName}:`, request.url);
                            await cache.delete(request);
                        }
                    } catch (textErr) {
                        // If we can't read the text, skip this check
                        console.warn(`[SW] Could not read response text for ${request.url}:`, textErr);
                    }
                }
            } catch (err) {
                // Individual request check failed, continue with others
                console.warn(`[SW] Error checking cached response ${request.url}:`, err);
            }
        }));
    } catch (err) {
        console.error(`[SW] Error cleaning ${cacheName}:`, err);
    }
};

// Allow page to message the SW (skip waiting, clear caches)
self.addEventListener('message', (event) => {
    // Validate event data structure
    if (!event || typeof event.data !== 'object' || event.data === null) {
        console.warn('[SW] Invalid message data received');
        return;
    }
    
    const data = event.data;
    
    if (data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    
    if (data.type === 'CLEAR_CACHES') {
        event.waitUntil(
            caches.keys().then((names) => Promise.all(names.map((n) => caches.delete(n))))
        );
    }
});

// Install event - cache static assets
self.addEventListener('install', (event) => {
    console.log('[SW] Installing service worker...');
    
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => {
            console.log('[SW] Caching static assets');
            // Only cache successful responses (2xx) to prevent caching 404 pages
            return Promise.all(
                STATIC_ASSETS.map(async (url) => {
                    try {
                        const response = await fetch(new Request(url, { credentials: 'same-origin' }));
                        
                        // Only cache if response is successful (2xx)
                        if (response.ok) {
                            await cache.put(url, response);
                        } else {
                            console.warn(`[SW] Skipping cache for ${url} - status ${response.status}`);
                        }
                    } catch (err) {
                        console.warn(`[SW] Failed to cache ${url}:`, err);
                        // Continue even if some assets fail to cache
                    }
                })
            );
        })
    );
    
    // Activate immediately
    self.skipWaiting();
});

// Activate event - clean up old caches and take control
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating service worker...');
    
    event.waitUntil(
        (async () => {
            try {
                const cacheNames = await caches.keys();
                
                // Delete ALL old caches that don't match current version
                // This is more aggressive to prevent serving bad cached responses
                const deletePromises = cacheNames.map((cacheName) => {
                    if (cacheName.startsWith('aviationwx-') && cacheName !== STATIC_CACHE && cacheName !== WEATHER_CACHE) {
                        console.log('[SW] Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                    return Promise.resolve();
                });
                
                await Promise.all(deletePromises);
                
                // Only clean up bad responses if cache version has changed
                // This optimization avoids expensive cleanup on every activation
                const needsCleanup = await self.isCleanupNeeded();
                
                if (needsCleanup) {
                    console.log('[SW] Cache version changed, running cleanup...');
                    
                    // Clean up bad responses in current caches
                    // Do this asynchronously to avoid blocking activation
                    const staticCache = await caches.open(STATIC_CACHE);
                    const weatherCache = await caches.open(WEATHER_CACHE);
                    
                    // Clean caches in parallel, but don't wait for completion
                    Promise.all([
                        self.cleanBadCachedResponses(staticCache, 'static'),
                        self.cleanBadCachedResponses(weatherCache, 'weather')
                    ]).then(() => {
                        // Mark this version as cleaned
                        return self.setLastCleanedVersion(CACHE_VERSION);
                    }).catch((err) => {
                        console.error('[SW] Error during cache cleanup:', err);
                    });
                } else {
                    console.log('[SW] Cache version unchanged, skipping cleanup');
                }
            } catch (err) {
                console.error('[SW] Error during activation:', err);
            }
        })()
    );
    
    // Take control immediately
    return self.clients.claim();
});

// Fetch event - selective strategies
self.addEventListener('fetch', (event) => {
    // Early returns before URL parsing to avoid unnecessary work
    // Only handle same-origin requests (quick string check before parsing)
    const requestUrl = event.request.url;
    if (!requestUrl.startsWith(location.origin)) {
        return;
    }
    
    // Parse URL only when we know we need to handle it
    let url;
    try {
        url = new URL(requestUrl);
    } catch {
        // Invalid URL, let browser handle it
        return;
    }
    
    // Never cache webcam images via SW (Nginx handles caching); bypass
    if (url.pathname === '/webcam.php') {
        return; // Let browser request pass through
    }
    
    // Handle weather API requests with network-first + dynamic timeout, then cache
    // Only serve cached data if network fails AND cache is relatively fresh (<10 minutes)
    // Support both /weather.php and /api/weather.php paths
    if (url.pathname === '/weather.php' || url.pathname === '/api/weather.php') {
        event.respondWith(
            (async () => {
                try {
                    return await self.handleWeatherRequest(event.request, url);
                } catch (err) {
                    console.error('[SW] Fetch error:', err);
                    
                    // Try cache as last resort (only if relatively fresh)
                    // Use normalized request for cache matching
                    const normalizedRequest = self.createNormalizedCacheRequest(event.request, url);
                    const cachedResponse = await caches.match(normalizedRequest);
                    if (cachedResponse) {
                        const isStale = await self.isCachedResponseStale(cachedResponse);
                        
                        if (isStale === true) {
                            // Too stale, don't serve
                            return self.createErrorResponse('Weather data unavailable');
                        }
                        
                        // Serve cache if fresh or if we can't determine staleness
                        return cachedResponse;
                    }
                    
                    // Return offline error
                    return self.createErrorResponse('Offline');
                }
            })()
        );
        return;
    }
    
    // Handle static assets - cache first, fallback to network
    if (STATIC_ASSETS.some(asset => url.pathname === asset) || url.pathname.startsWith('/styles')) {
        event.respondWith(
            (async () => {
                try {
                    const cachedResponse = await caches.match(event.request);
                    
                    // Only serve cached response if it's successful (2xx)
                    // This prevents serving cached 404 HTML pages
                    if (cachedResponse && cachedResponse.ok) {
                        return cachedResponse;
                    }
                    
                    // Open cache once for both deletion and potential caching
                    // Only open if we need it (bad cached response or will cache new response)
                    let cache = null;
                    const needsCache = cachedResponse && !cachedResponse.ok;
                    
                    if (needsCache) {
                        cache = await caches.open(STATIC_CACHE);
                        await cache.delete(event.request);
                    }
                    
                    // Fetch from network and cache
                    const response = await fetch(event.request);
                    
                    // Only cache successful responses (2xx)
                    if (response.ok) {
                        // Reuse cache if already open, otherwise open it
                        if (!cache) {
                            cache = await caches.open(STATIC_CACHE);
                        }
                        await cache.put(event.request, response.clone());
                    }
                    
                    return response;
                } catch (err) {
                    console.error('[SW] Error handling static asset:', err);
                    // Return cached response if available, even if not OK
                    const cachedResponse = await caches.match(event.request);
                    return cachedResponse || new Response('Not found', { status: 404 });
                }
            })()
        );
        return;
    }
    
    // For other requests, network first (no caching)
    // Let the browser handle normally
});

// Background sync - periodically refresh weather cache
self.addEventListener('sync', (event) => {
    if (event.tag === 'weather-refresh') {
        event.waitUntil(self.refreshWeatherCache());
    }
});

// Background sync concurrency limit
const BACKGROUND_SYNC_CONCURRENCY = 3;

/**
 * Process a single weather cache refresh request
 * 
 * Fetches fresh data and caches it with timestamp header for fast staleness checks.
 * Uses the same optimization as normal requests to ensure consistent behavior.
 * 
 * @param {Request} request Cached request to refresh
 * @param {Cache} cache Cache instance
 * @returns {Promise<void>}
 */
self.refreshSingleWeatherRequest = async function(request, cache) {
    // Only process weather requests (support both paths)
    if (!request.url.includes('/weather.php') && !request.url.includes('/api/weather.php')) {
        return;
    }
    
    try {
        // Create a fresh fetch request (without cache-busting params)
        // Use retry logic for transient failures, respecting connection speed
        const fetchRequest = new Request(request.url);
        const timeout = self.getNetworkTimeout(false);
        const response = await self.fetchWithRetry(fetchRequest, timeout, MAX_RETRIES);
        
        if (response && response.ok) {
            // Extract timestamp and create response with header
            const responseToCache = await self.extractAndCacheWithTimestamp(response);
            
            // Use the same normalized request key for storage
            await cache.put(request, responseToCache);
            
            // Update access time for LRU tracking
            await self.updateCacheAccessTime(request.url);
            
            console.log('[SW] Refreshed weather cache:', request.url);
        }
    } catch (err) {
        console.warn('[SW] Failed to refresh:', request.url, err);
    }
};

/**
 * Process requests in parallel with concurrency limit
 * 
 * @param {Request[]} requests Array of requests to process
 * @param {Cache} cache Cache instance
 * @param {number} concurrency Maximum number of concurrent operations
 * @returns {Promise<void>}
 */
self.processRequestsInParallel = async function(requests, cache, concurrency) {
    // Filter for weather requests only (support both paths)
    const weatherRequests = requests.filter(req => 
        req.url.includes('/weather.php') || req.url.includes('/api/weather.php')
    );
    
    // Process in batches with concurrency limit
    for (let i = 0; i < weatherRequests.length; i += concurrency) {
        const batch = weatherRequests.slice(i, i + concurrency);
        await Promise.all(batch.map(request => self.refreshSingleWeatherRequest(request, cache)));
    }
};

/**
 * Refresh weather cache in background
 * 
 * Periodically refreshes cached weather data to keep it fresh. Processes
 * requests in parallel with concurrency limit to avoid overwhelming the network
 * while improving performance. Uses normalized requests to ensure consistent
 * cache keys.
 * 
 * @returns {Promise<void>}
 */
self.refreshWeatherCache = async function() {
    try {
        // Get all cached weather requests
        const cache = await caches.open(WEATHER_CACHE);
        const requests = await cache.keys();
        
        // Process requests in parallel with concurrency limit
        await self.processRequestsInParallel(requests, cache, BACKGROUND_SYNC_CONCURRENCY);
    } catch (err) {
        console.error('[SW] Background sync error:', err);
    }
};
