// =============================================================================
// TEMPORARY DEBUG CODE - REMOVE AFTER 2025-03-15 (or when kspb cache issue resolved)
// =============================================================================
// Cleanup service worker for stuck kspb clients with old cached HTML
// This service worker immediately clears all caches and unregisters itself
// Old clients trying to register /sw.js will get this cleanup worker instead
// TODO: Remove this entire file after cleanup period (target: ~2 months from implementation)
// =============================================================================

'use strict';

const CLEANUP_VERSION = 'cleanup-v1';

// Install event - clear all caches immediately
self.addEventListener('install', (event) => {
    console.warn('[SW-Cleanup] Installing cleanup service worker for stuck kspb client');
    
    // Skip waiting to activate immediately
    self.skipWaiting();
    
    event.waitUntil(
        (async () => {
            try {
                // Clear all existing caches
                const cacheNames = await caches.keys();
                await Promise.all(cacheNames.map(name => {
                    console.log('[SW-Cleanup] Deleting cache:', name);
                    return caches.delete(name);
                }));
                console.log('[SW-Cleanup] Cleared', cacheNames.length, 'caches');
            } catch (err) {
                console.error('[SW-Cleanup] Error clearing caches:', err);
            }
        })()
    );
});

// Activate event - take control and unregister all service workers
self.addEventListener('activate', (event) => {
    console.warn('[SW-Cleanup] Activating cleanup service worker');
    
    event.waitUntil(
        (async () => {
            try {
                // Clear all caches again (in case any were created during install)
                const cacheNames = await caches.keys();
                await Promise.all(cacheNames.map(name => {
                    console.log('[SW-Cleanup] Deleting cache:', name);
                    return caches.delete(name);
                }));
                
                // Take control of all clients immediately
                await self.clients.claim();
                
                // Post message to all clients to trigger cleanup and reload
                const clients = await self.clients.matchAll();
                clients.forEach(client => {
                    client.postMessage({
                        type: 'CLEANUP_REQUIRED',
                        action: 'clear_all_and_reload'
                    });
                });
                
                console.log('[SW-Cleanup] Posted cleanup message to', clients.length, 'clients');
                
                // Unregister this service worker (and all others)
                // Note: Service workers can't unregister themselves directly,
                // but clients will receive the message and handle it
            } catch (err) {
                console.error('[SW-Cleanup] Error during activate:', err);
            }
        })()
    );
});

// Message event - handle cleanup commands from clients
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'CLEANUP_COMPLETE') {
        console.log('[SW-Cleanup] Client reported cleanup complete');
        // Client will unregister us, nothing more to do
    }
});

// Fetch event - intercept page requests and inject cleanup code
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    
    // Only intercept HTML page requests (not API calls, images, etc.)
    // This allows us to inject cleanup JavaScript into the page
    if (event.request.method === 'GET' && 
        event.request.mode === 'navigate' &&
        url.origin === self.location.origin) {
        
        event.respondWith(
            (async () => {
                try {
                    // Fetch the page from network (bypass cache)
                    const response = await fetch(event.request, { cache: 'no-store' });
                    
                    // If it's HTML, inject cleanup script at the very beginning
                    const contentType = response.headers.get('content-type') || '';
                    if (contentType.includes('text/html')) {
                        const html = await response.text();
                        
                        // Inject cleanup script immediately after <head> tag
                        // This ensures it runs even if the rest of the page is cached
                        const cleanupScript = `
<script>
// TEMPORARY CLEANUP CODE - Injected by cleanup service worker
(async function() {
    'use strict';
    console.warn('[SW-Cleanup] Running cleanup from injected script');
    
    try {
        // 1. Clear all Cache API caches
        if ('caches' in window) {
            const cacheNames = await caches.keys();
            await Promise.all(cacheNames.map(name => caches.delete(name)));
            console.log('[SW-Cleanup] Cleared', cacheNames.length, 'caches');
        }
        
        // 2. Unregister all service workers
        if ('serviceWorker' in navigator) {
            const registrations = await navigator.serviceWorker.getRegistrations();
            await Promise.all(registrations.map(reg => reg.unregister()));
            console.log('[SW-Cleanup] Unregistered', registrations.length, 'service workers');
        }
        
        // 3. Clear localStorage
        try { localStorage.clear(); } catch(e) {}
        
        // 4. Clear sessionStorage
        try { sessionStorage.clear(); } catch(e) {}
        
        // 5. Clear cookies
        try {
            const cookies = document.cookie.split(';');
            const domain = window.location.hostname;
            const baseDomain = domain.startsWith('www.') ? domain.substring(4) : domain;
            const cookieDomain = '.' + baseDomain;
            cookies.forEach(cookie => {
                const cookieName = cookie.split('=')[0].trim();
                if (cookieName.startsWith('aviationwx_')) {
                    document.cookie = cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; domain=' + cookieDomain;
                    document.cookie = cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; domain=' + baseDomain;
                    document.cookie = cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/';
                }
            });
        } catch(e) {}
        
        // 6. Clear IndexedDB
        try {
            if ('indexedDB' in window) {
                const databases = await indexedDB.databases();
                await Promise.all(databases.map(db => {
                    return new Promise((resolve) => {
                        const deleteReq = indexedDB.deleteDatabase(db.name);
                        deleteReq.onsuccess = () => resolve();
                        deleteReq.onerror = () => resolve();
                        deleteReq.onblocked = () => resolve();
                    });
                }));
            }
        } catch(e) {}
        
        console.log('[SW-Cleanup] Cleanup complete, reloading...');
        
        // 7. Reload with cache-busting parameter
        await new Promise(resolve => setTimeout(resolve, 100));
        window.location.href = '?v=' + Date.now() + '&refresh=1';
    } catch(err) {
        console.error('[SW-Cleanup] Error:', err);
        window.location.reload(true);
    }
})();
</script>`;
                        
                        // Inject script right after <head> tag
                        const modifiedHtml = html.replace(
                            /(<head[^>]*>)/i,
                            '$1' + cleanupScript
                        );
                        
                        return new Response(modifiedHtml, {
                            status: response.status,
                            statusText: response.statusText,
                            headers: response.headers
                        });
                    }
                    
                    // Not HTML, return as-is
                    return response;
                } catch (err) {
                    console.error('[SW-Cleanup] Error intercepting request:', err);
                    // Fallback to network
                    return fetch(event.request);
                }
            })()
        );
        return;
    }
    
    // For all other requests, don't intercept - let them go to network
    // This ensures no new caches are created
    return;
});

// =============================================================================
// END TEMPORARY DEBUG CODE
// =============================================================================

