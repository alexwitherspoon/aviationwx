// AviationWX Service Worker
// Provides offline support and background sync for weather data

const CACHE_VERSION = 'v2';
const CACHE_NAME = `aviationwx-${CACHE_VERSION}`;
const STATIC_CACHE = `${CACHE_NAME}-static`;
const WEATHER_CACHE = `${CACHE_NAME}-weather`;

// Assets to cache on install
// Only include files that exist - styles.min.css may not exist
const STATIC_ASSETS = [
    '/',
    '/public/css/styles.css',
    '/index.php'
];

// Allow page to message the SW (skip waiting, clear caches)
self.addEventListener('message', (event) => {
    const data = event.data || {};
    if (data && data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    if (data && data.type === 'CLEAR_CACHES') {
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
                STATIC_ASSETS.map(url => {
                    return fetch(new Request(url, { credentials: 'same-origin' }))
                        .then(response => {
                            // Only cache if response is successful (2xx)
                            if (response.ok) {
                                return cache.put(url, response);
                            } else {
                                console.warn(`[SW] Skipping cache for ${url} - status ${response.status}`);
                                return Promise.resolve();
                            }
                        })
                        .catch(err => {
                            console.warn(`[SW] Failed to cache ${url}:`, err);
                            // Continue even if some assets fail to cache
                            return Promise.resolve();
                        });
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
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    // Delete old caches that don't match current version
                    if (cacheName.startsWith('aviationwx-') && cacheName !== STATIC_CACHE && cacheName !== WEATHER_CACHE) {
                        console.log('[SW] Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    
    // Take control immediately
    return self.clients.claim();
});

// Fetch event - selective strategies
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    
    // Only handle same-origin requests
    if (url.origin !== location.origin) {
        return;
    }
    
    // Never cache webcam images via SW (Nginx handles caching); bypass
    if (url.pathname === '/webcam.php') {
        return; // Let browser request pass through
    }

    // Handle weather API requests with network-first + dynamic timeout, then cache
    // Only serve cached data if network fails AND cache is relatively fresh (<10 minutes)
    if (url.pathname === '/weather.php') {
        event.respondWith(
            (async () => {
                try {
                    // Solution A: Detect forced refresh requests
                    // Check for cache-busting query parameter or Cache-Control header
                    const hasCacheBusting = url.searchParams.has('_cb');
                    const hasNoCacheHeader = event.request.headers.get('Cache-Control') === 'no-cache' ||
                        event.request.cache === 'reload';
                    const isForcedRefresh = hasCacheBusting || hasNoCacheHeader;
                    
                    // If forced refresh, bypass cache entirely and use longer timeout for mobile
                    if (isForcedRefresh) {
                        console.log('[SW] Forced refresh detected - bypassing cache entirely');
                        // Detect slow connection (mobile networks) and use longer timeout
                        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
                        const isSlowConnection = connection && (
                            connection.effectiveType === 'slow-2g' || 
                            connection.effectiveType === '2g' ||
                            connection.saveData === true
                        );
                        const timeout = isSlowConnection ? 15000 : 10000; // 15s for slow, 10s for normal
                        
                        // Create a new request with cache-busting headers for forced refresh
                        const newHeaders = new Headers(event.request.headers);
                        newHeaders.set('Cache-Control', 'no-cache');
                        const newRequest = new Request(event.request, {
                            cache: 'no-store', // Don't cache forced refresh responses
                            headers: newHeaders
                        });
                        
                        const networkPromise = fetch(newRequest).catch(() => null);
                        const timeoutPromise = new Promise((resolve) => setTimeout(() => resolve(null), timeout));
                        const networkResponse = await Promise.race([networkPromise, timeoutPromise]);
                        
                        if (networkResponse && networkResponse.ok) {
                            return networkResponse;
                        }
                        // For forced refresh, don't fall back to cache - return error
                        return networkResponse || new Response(
                            JSON.stringify({ success: false, error: 'Weather data unavailable - please try again' }),
                            { status: 503, headers: { 'Content-Type': 'application/json' } }
                        );
                    }
                    
                    // Normal request: network-first with timeout
                    // Detect slow connection for dynamic timeout
                    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
                    const isSlowConnection = connection && (
                        connection.effectiveType === 'slow-2g' || 
                        connection.effectiveType === '2g' ||
                        connection.saveData === true
                    );
                    const timeout = isSlowConnection ? 15000 : 8000; // 15s for slow, 8s for normal (increased from 5s)
                    
                    const networkPromise = fetch(event.request).catch(() => null);
                    const timeoutPromise = new Promise((resolve) => setTimeout(() => resolve(null), timeout));
                    const networkResponse = await Promise.race([networkPromise, timeoutPromise]);
                    
                    if (networkResponse && networkResponse.ok) {
                        // Cache the fresh response
                        const cache = await caches.open(WEATHER_CACHE);
                        const clonedResponse = networkResponse.clone();
                        cache.put(event.request, clonedResponse).catch(() => {
                            // Cache put failed, but continue
                        });
                        return networkResponse;
                    }
                    
                    // Network failed or timed out - try cache, but only if relatively fresh
                    const cachedResponse = await caches.match(event.request);
                    if (cachedResponse) {
                        // Check if cached response is stale (>10 minutes)
                        // Parse response to check timestamp
                        try {
                            const cachedData = await cachedResponse.clone().json();
                            if (cachedData && cachedData.weather && cachedData.weather.last_updated) {
                                const cacheAge = Date.now() / 1000 - cachedData.weather.last_updated;
                                const maxStaleAge = 10 * 60; // 10 minutes
                                
                                if (cacheAge > maxStaleAge) {
                                    console.log('[SW] Cached weather data is too stale - not serving', cacheAge);
                                    // Don't serve stale cache - return network error or offline message
                                    return networkResponse || new Response(
                                        JSON.stringify({ success: false, error: 'Weather data unavailable - please refresh' }),
                                        { status: 503, headers: { 'Content-Type': 'application/json' } }
                                    );
                                }
                            }
                        } catch (parseErr) {
                            // If we can't parse the cached response, serve it anyway (better than nothing)
                            console.warn('[SW] Could not parse cached response for staleness check', parseErr);
                        }
                        
                        console.log('[SW] Serving weather from cache (network unavailable)');
                        return cachedResponse;
                    }
                    
                    // No cache - return network response (even if failed)
                    return networkResponse || new Response(
                        JSON.stringify({ success: false, error: 'Offline - no cached data' }),
                        { status: 503, headers: { 'Content-Type': 'application/json' } }
                    );
                } catch (err) {
                    console.error('[SW] Fetch error:', err);
                    // Try cache as last resort (only if relatively fresh)
                    const cachedResponse = await caches.match(event.request);
                    if (cachedResponse) {
                        // Quick staleness check
                        try {
                            const cachedData = await cachedResponse.clone().json();
                            if (cachedData && cachedData.weather && cachedData.weather.last_updated) {
                                const cacheAge = Date.now() / 1000 - cachedData.weather.last_updated;
                                if (cacheAge > 10 * 60) {
                                    // Too stale, don't serve
                                    return new Response(
                                        JSON.stringify({ success: false, error: 'Weather data unavailable' }),
                                        { status: 503, headers: { 'Content-Type': 'application/json' } }
                                    );
                                }
                            }
                        } catch (parseErr) {
                            // Serve anyway if we can't parse
                        }
                        return cachedResponse;
                    }
                    // Return offline error
                    return new Response(
                        JSON.stringify({ success: false, error: 'Offline' }),
                        { status: 503, headers: { 'Content-Type': 'application/json' } }
                    );
                }
            })()
        );
        return;
    }
    
    // Handle static assets - cache first, fallback to network
    if (STATIC_ASSETS.some(asset => url.pathname === asset) || url.pathname.startsWith('/styles')) {
        event.respondWith(
            caches.match(event.request).then((cachedResponse) => {
                // Only serve cached response if it's successful (2xx)
                // This prevents serving cached 404 HTML pages
                if (cachedResponse && cachedResponse.ok) {
                    return cachedResponse;
                }
                
                // If cached response exists but is not OK (e.g., 404), delete it and fetch fresh
                if (cachedResponse && !cachedResponse.ok) {
                    caches.open(STATIC_CACHE).then(cache => cache.delete(event.request));
                }
                
                // Fetch from network and cache
                return fetch(event.request).then((response) => {
                    // Only cache successful responses (2xx)
                    if (response.ok) {
                        const cache = caches.open(STATIC_CACHE);
                        cache.then((c) => c.put(event.request, response.clone()));
                    }
                    return response;
                });
            })
        );
        return;
    }
    
    // For other requests, network first (no caching)
    // Let the browser handle normally
});

// Background sync - periodically refresh weather cache
self.addEventListener('sync', (event) => {
    if (event.tag === 'weather-refresh') {
        event.waitUntil(refreshWeatherCache());
    }
});

async function refreshWeatherCache() {
    try {
        // Get all cached weather requests
        const cache = await caches.open(WEATHER_CACHE);
        const requests = await cache.keys();
        
        // Refresh each weather endpoint
        for (const request of requests) {
            if (request.url.includes('/weather.php')) {
                try {
                    const response = await fetch(request);
                    if (response.ok) {
                        await cache.put(request, response.clone());
                        console.log('[SW] Refreshed weather cache:', request.url);
                    }
                } catch (err) {
                    console.warn('[SW] Failed to refresh:', request.url, err);
                }
            }
        }
    } catch (err) {
        console.error('[SW] Background sync error:', err);
    }
}

