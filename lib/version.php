<?php
/**
 * Build version info and the client version-check script
 *
 * Shared by pages that participate in automatic build pickup (the airport
 * dashboard and the airports directory). The script compares the running
 * build to the version API and soft-reloads at a quiet moment when the
 * server is meaningfully newer; see docs/OPERATIONS.md (Client Version
 * Pickup).
 */

require_once __DIR__ . '/config.php';

/**
 * Read the deployed build version from config/version.json
 *
 * Falls back to a deterministic per-checkout hash in development and
 * testing, where the file is generated only at deploy time.
 *
 * @return array {
 *   'hash' => string,            // Full or generated build hash
 *   'hash_short' => string,      // First 7 characters, used in ?v= busting
 *   'timestamp' => int,          // Unix timestamp of the deploy
 *   'max_no_update_days' => int, // Dead man's switch window (0 = disabled)
 *   'stuck_client_cleanup' => bool // Server-side stuck client cleanup flag
 * }
 */
function getBuildVersionInfo(): array
{
    $versionFile = __DIR__ . '/../config/version.json';
    $buildTimestamp = time();
    $buildHash = 'unknown';
    $maxNoUpdateDays = 7;
    $stuckClientCleanup = false;

    if (file_exists($versionFile)) {
        $versionData = json_decode((string) file_get_contents($versionFile), true);
        if ($versionData) {
            $buildTimestamp = $versionData['timestamp'] ?? time();
            $buildHash = $versionData['hash'] ?? 'unknown';
            $maxNoUpdateDays = $versionData['max_no_update_days'] ?? 7;
            $stuckClientCleanup = $versionData['stuck_client_cleanup'] ?? false;
        }
    }

    // Deterministic hash for testing/development so ?v= busting and the
    // version cookie keep a stable, expected format without a deploy file
    if ($buildHash === 'unknown') {
        $projectRoot = dirname(__DIR__);
        $buildHash = substr(md5($projectRoot . $buildTimestamp), 0, 7);
    }

    return [
        'hash' => $buildHash,
        'hash_short' => substr($buildHash, 0, 7),
        'timestamp' => (int) $buildTimestamp,
        'max_no_update_days' => (int) $maxNoUpdateDays,
        'stuck_client_cleanup' => (bool) $stuckClientCleanup,
    ];
}

/**
 * Emit the client version-check script
 *
 * Moved verbatim from the dashboard head so every participating page runs
 * one implementation. Exposes window.aviationwxCheckVersion for the
 * periodic re-check in timer-lifecycle.js when that file is loaded.
 *
 * @param string $buildHash Build hash embedded in the page
 * @param int $buildTimestamp Deploy Unix timestamp
 * @param int $maxNoUpdateDays Dead man's switch window in days (0 = disabled)
 * @return void
 */
function renderClientVersionCheckScript(string $buildHash, int $buildTimestamp, int $maxNoUpdateDays): void
{
    ?>
    <script>
        /**
         * Client Version Checking
         *
         * Keeps long-lived dashboard tabs on current code. Clients compare
         * the server build (version API) to their own on load and on the
         * periodic check from timer-lifecycle.js:
         *
         * 1. Server build newer: soft reload at a quiet moment (hidden tab,
         *    or after a short delay when visible). No-cache HTML plus
         *    versioned static assets make a plain reload sufficient.
         * 2. Client more than max_no_update_days behind the server, or no
         *    successful version check in that window (dead man's switch):
         *    full cleanup of caches, storage, and service workers.
         */
        (function() {
            'use strict';

            const BUILD_TIMESTAMP = <?= (int) $buildTimestamp ?>;
            const BUILD_HASH = '<?= addslashes($buildHash) ?>';
            const MAX_NO_UPDATE_DAYS = <?= (int) $maxNoUpdateDays ?>;
            const LAST_CHECK_KEY = 'aviationwx-last-version-check';
            const CLEANUP_IN_PROGRESS_KEY = 'aviationwx-cleanup-in-progress';
            const RELOAD_ATTEMPTED_KEY = 'aviationwx-reload-attempted';
            // Ignore server builds newer by less than this: mid-deploy reads
            // could otherwise reload clients onto a half-deployed version
            const DEPLOY_GRACE_MS = 10 * 60 * 1000;
            // Visible tabs reload after this delay; hidden tabs reload immediately
            const VISIBLE_RELOAD_DELAY_MS = 10 * 60 * 1000;

            // localStorage/sessionStorage throw SecurityError in iOS Private Browsing
            function safeStorageGet(key) {
                try { return localStorage.getItem(key); } catch (e) { return null; }
            }
            function safeStorageSet(key, value) {
                try { localStorage.setItem(key, value); } catch (e) { /* unavailable */ }
            }
            function safeSessionStorageGet(key) {
                try { return sessionStorage.getItem(key); } catch (e) { return null; }
            }
            function safeSessionStorageSet(key, value) {
                try { sessionStorage.setItem(key, value); } catch (e) { /* unavailable */ }
            }
            // The reload-attempt marker must survive the reload it guards
            // even when sessionStorage throws (iOS Private Browsing), or a
            // client stuck on an old build would re-arm after every reload.
            // A session cookie backs the marker up, but only when storage
            // is genuinely unavailable: cookies are shared across tabs, and
            // one tab's reload must not suppress soft pickup in the others.
            let sessionStorageUsable = null;
            function sessionStorageWorks() {
                if (sessionStorageUsable === null) {
                    try {
                        sessionStorage.setItem('aviationwx-storage-probe', '1');
                        sessionStorage.removeItem('aviationwx-storage-probe');
                        sessionStorageUsable = true;
                    } catch (e) {
                        sessionStorageUsable = false;
                    }
                }
                return sessionStorageUsable;
            }
            function reloadMarkerGet() {
                if (sessionStorageWorks()) {
                    return safeSessionStorageGet(RELOAD_ATTEMPTED_KEY);
                }
                const match = document.cookie.match(/(?:^|;\s*)aviationwx_reload_attempted=([^;]+)/);
                if (!match) {
                    return null;
                }
                try {
                    return decodeURIComponent(match[1]);
                } catch (e) {
                    // Malformed percent-encoding (tampered or mangled cookie)
                    // must not break the version check; treat as no marker
                    return null;
                }
            }
            function reloadMarkerSet(value) {
                if (sessionStorageWorks()) {
                    safeSessionStorageSet(RELOAD_ATTEMPTED_KEY, value);
                    return;
                }
                try {
                    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
                    document.cookie = 'aviationwx_reload_attempted=' + encodeURIComponent(value) + '; path=/; SameSite=Lax' + secure;
                } catch (e) { /* cookies unavailable too - in-page guard still applies */ }
            }

            /**
             * Perform full cleanup - clear all caches, storage, and service workers
             * This is intentionally aggressive as it only triggers in rare stuck states
             */
            async function performFullCleanup(reason) {
                console.warn('[Version] Performing full cleanup. Reason:', reason);
                
                // Prevent cleanup loops - if we just did a cleanup, don't do another
                const cleanupInProgress = safeSessionStorageGet(CLEANUP_IN_PROGRESS_KEY);
                if (cleanupInProgress) {
                    console.log('[Version] Cleanup already in progress, skipping');
                    return;
                }
                safeSessionStorageSet(CLEANUP_IN_PROGRESS_KEY, Date.now().toString());
                
                try {
                    // 1. Clear all Cache API caches
                    if ('caches' in window) {
                        const cacheNames = await caches.keys();
                        await Promise.all(cacheNames.map(name => {
                            console.log('[Version] Deleting cache:', name);
                            return caches.delete(name);
                        }));
                    }
                    
                    // 2. Clear localStorage (all keys)
                    try {
                        localStorage.clear();
                        console.log('[Version] Cleared localStorage');
                    } catch (e) {
                        console.warn('[Version] Could not clear localStorage:', e);
                    }
                    
                    // 3. Clear sessionStorage (except cleanup flag)
                    try {
                        const cleanupFlag = safeSessionStorageGet(CLEANUP_IN_PROGRESS_KEY);
                        sessionStorage.clear();
                        if (cleanupFlag) {
                            safeSessionStorageSet(CLEANUP_IN_PROGRESS_KEY, cleanupFlag);
                        }
                        console.log('[Version] Cleared sessionStorage');
                    } catch (e) {
                        console.warn('[Version] Could not clear sessionStorage:', e);
                    }
                    
                    // 4. Unregister all service workers
                    if ('serviceWorker' in navigator) {
                        const registrations = await navigator.serviceWorker.getRegistrations();
                        await Promise.all(registrations.map(reg => {
                            console.log('[Version] Unregistering service worker:', reg.scope);
                            return reg.unregister();
                        }));
                    }
                    
                    console.log('[Version] Cleanup complete, reloading page...');
                    
                    // 5. Force hard reload from network
                    // Small delay to ensure cleanup operations complete
                    setTimeout(() => {
                        window.location.reload(true);
                    }, 100);
                    
                } catch (err) {
                    console.error('[Version] Cleanup error:', err);
                    // Even on error, try to reload
                    window.location.reload(true);
                }
            }
            
            /**
             * Check if dead man's switch should trigger
             * Returns reason string if cleanup needed, null otherwise
             *
             * Escalates only when BOTH signals are stale: the build this
             * page is running AND the last confirmed contact (successful
             * version check or fresh page load). Requiring both means a
             * returning visitor who was simply away does not get wiped
             * (their no-cache page load delivers a fresh build), while a
             * genuinely stuck client (old cached build, nothing confirmed
             * for the whole window) does.
             */
            function checkDeadManSwitch() {
                const now = Date.now();
                const maxAgeMs = MAX_NO_UPDATE_DAYS * 24 * 60 * 60 * 1000;
                if (maxAgeMs <= 0) {
                    return null; // 0 = disabled via dead_man_switch_days
                }
                
                const buildAge = now - (BUILD_TIMESTAMP * 1000);
                if (buildAge <= maxAgeMs) {
                    return null; // Running a recent build - nothing is stuck
                }
                
                const lastCheckStr = safeStorageGet(LAST_CHECK_KEY);
                if (!lastCheckStr) {
                    // Old build and no record of any confirmed contact
                    // (first visit on a stuck cache, or storage cleared)
                    return `Build is ${Math.floor(buildAge / 86400000)} days old with no version check record`;
                }
                
                const lastCheck = parseInt(lastCheckStr, 10);
                if (isNaN(lastCheck) || lastCheck <= 0) {
                    return `Build is ${Math.floor(buildAge / 86400000)} days old with an unreadable contact record`;
                }
                
                const timeSinceCheck = now - lastCheck;
                if (timeSinceCheck > maxAgeMs) {
                    return `Build is ${Math.floor(buildAge / 86400000)} days old with no confirmed contact in ${Math.floor(timeSinceCheck / 86400000)} days (max: ${MAX_NO_UPDATE_DAYS})`;
                }
                
                return null;
            }
            
            // Soft reload state: arm at most once per server hash per session
            let reloadArmed = false;
            
            /**
             * Whether the server still serves a meaningfully newer build.
             * Used right before a deferred reload fires: between arming and
             * firing the deploy may have been rolled back or the server may
             * have become unreachable, and reloading into either is worse
             * than staying on the running page.
             */
            async function serverStillMeaningfullyNewer() {
                try {
                    const response = await fetch('/api/v1/version.php?_=' + Date.now(), {
                        cache: 'no-store'
                    });
                    if (!response.ok) {
                        return null;
                    }
                    const serverVersion = await response.json();
                    if (!serverVersion || !serverVersion.hash || !serverVersion.timestamp) {
                        return null;
                    }
                    const serverNewerByMs = (serverVersion.timestamp - BUILD_TIMESTAMP) * 1000;
                    if (serverVersion.hash !== BUILD_HASH && serverNewerByMs > DEPLOY_GRACE_MS) {
                        return serverVersion.hash;
                    }
                    return null;
                } catch (err) {
                    return null;
                }
            }
            
            /**
             * Pick up a newer server build with a plain reload at a quiet
             * moment: immediately when the tab is hidden, otherwise on the
             * next tab-hide or after a bounded delay (kiosks stay visible
             * forever). One attempt per server hash, tracked in
             * sessionStorage, so a reload that does not resolve the
             * mismatch cannot loop; the dead man's switch escalates later.
             * Deferred reloads re-verify the server first and disarm when
             * it is no longer ahead, so a later deploy can re-arm.
             */
            function armSoftReload(serverHash) {
                if (reloadArmed) {
                    return;
                }
                if (reloadMarkerGet() === serverHash) {
                    return;
                }
                reloadArmed = true;
                
                const doReload = (confirmedHash) => {
                    reloadMarkerSet(confirmedHash);
                    console.log('[Version] Reloading to pick up server build', confirmedHash);
                    window.location.reload();
                };
                
                if (document.hidden) {
                    // The arming check itself just confirmed the server is
                    // ahead; no need to re-verify milliseconds later
                    doReload(serverHash);
                    return;
                }
                
                console.log('[Version] New server build', serverHash, '- reload scheduled');
                let delayTimer = null;
                const deferredReload = async () => {
                    const confirmedHash = await serverStillMeaningfullyNewer();
                    if (confirmedHash === null) {
                        reloadArmed = false;
                        console.log('[Version] Scheduled reload skipped - server no longer ahead or unreachable');
                        return;
                    }
                    doReload(confirmedHash);
                };
                const visibilityHandler = () => {
                    if (document.hidden) {
                        document.removeEventListener('visibilitychange', visibilityHandler);
                        clearTimeout(delayTimer);
                        deferredReload();
                    }
                };
                document.addEventListener('visibilitychange', visibilityHandler);
                delayTimer = setTimeout(() => {
                    document.removeEventListener('visibilitychange', visibilityHandler);
                    deferredReload();
                }, VISIBLE_RELOAD_DELAY_MS);
            }
            
            /**
             * Compare the server build to this page's build and act:
             * escalate to full cleanup when far behind, soft reload when a
             * meaningfully newer build is available. A successful check
             * feeds the dead man's switch regardless of outcome.
             * Shared with timer-lifecycle.js via window.aviationwxCheckVersion.
             */
            async function checkVersionAgainstServer() {
                try {
                    // Add cache-busting parameter
                    const response = await fetch('/api/v1/version.php?_=' + Date.now(), {
                        cache: 'no-store'
                    });
                    
                    if (!response.ok) {
                        console.warn('[Version] API returned status:', response.status);
                        return;
                    }
                    
                    const serverVersion = await response.json();
                    if (!serverVersion || !serverVersion.hash) {
                        console.warn('[Version] API response missing hash');
                        return;
                    }
                    
                    // The check itself succeeded - the update channel works
                    safeStorageSet(LAST_CHECK_KEY, Date.now().toString());
                    
                    if (serverVersion.hash === BUILD_HASH || !serverVersion.timestamp) {
                        return;
                    }
                    
                    // Both timestamps are server-generated, so this comparison
                    // is immune to client clock skew
                    const serverNewerByMs = (serverVersion.timestamp - BUILD_TIMESTAMP) * 1000;
                    
                    // Far behind: soft reloads have not worked (or never ran);
                    // escalate to a full cleanup. The server value wins so a
                    // config change reaches old tabs; 0 disables escalation
                    // and must not fall through to the client default.
                    const maxBehindDays = Number.isFinite(serverVersion.max_no_update_days)
                        ? serverVersion.max_no_update_days
                        : MAX_NO_UPDATE_DAYS;
                    const maxBehindMs = maxBehindDays * 24 * 60 * 60 * 1000;
                    if (maxBehindMs > 0 && serverNewerByMs > maxBehindMs) {
                        performFullCleanup(`Client build is ${Math.floor(serverNewerByMs / 86400000)} days behind server`);
                        return;
                    }
                    
                    // Meaningfully newer: pick it up with a plain reload
                    if (serverNewerByMs > DEPLOY_GRACE_MS) {
                        armSoftReload(serverVersion.hash);
                    }
                    
                } catch (err) {
                    // Network errors are expected when offline - don't log as error
                    if (navigator.onLine !== false) {
                        console.warn('[Version] API check failed:', err.message);
                    }
                }
            }
            
            // Shared with timer-lifecycle.js, which schedules periodic checks
            window.aviationwxCheckVersion = checkVersionAgainstServer;
            
            // Initialize version checking
            function init() {
                if (safeSessionStorageGet(CLEANUP_IN_PROGRESS_KEY)) {
                    try { sessionStorage.removeItem(CLEANUP_IN_PROGRESS_KEY); } catch (e) { /* unavailable */ }
                    console.log('[Version] Post-cleanup reload complete');
                    safeStorageSet(LAST_CHECK_KEY, Date.now().toString());
                    return;
                }
                
                // Drop the pre-simplification tracking key (was only updated
                // by service worker controllerchange, which never fired)
                try { localStorage.removeItem('aviationwx-last-sw-update'); } catch (e) { /* unavailable */ }
                
                // Check dead man's switch immediately
                const deadManReason = checkDeadManSwitch();
                if (deadManReason) {
                    performFullCleanup(deadManReason);
                    return; // Don't continue, we're reloading
                }
                
                // A fresh page load is itself confirmed contact: dashboard
                // HTML is no-cache, so a recent build timestamp means the
                // server just served this page. This baseline keeps the dead
                // man's switch from counting time the user was simply away,
                // and a broken version endpoint alone (load works, checks
                // fail) cannot trigger recurring cleanups. A stale build
                // does not refresh the baseline: HTML served by a stuck
                // intermediary cache must not keep deferring escalation.
                const loadBuildAgeMs = Date.now() - (BUILD_TIMESTAMP * 1000);
                const deadManWindowMs = MAX_NO_UPDATE_DAYS * 24 * 60 * 60 * 1000;
                if (deadManWindowMs <= 0 || loadBuildAgeMs <= deadManWindowMs) {
                    safeStorageSet(LAST_CHECK_KEY, Date.now().toString());
                }
                
                // First check during idle time to avoid blocking page load;
                // timer-lifecycle.js repeats it periodically
                if ('requestIdleCallback' in window) {
                    requestIdleCallback(checkVersionAgainstServer, { timeout: 10000 });
                } else {
                    setTimeout(checkVersionAgainstServer, 5000);
                }
            }
            
            // Run on page load
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
    </script>
    <?php
}
