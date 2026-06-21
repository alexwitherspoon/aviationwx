<?php

declare(strict_types=1);

/**
 * Shared HTTP cache headers for slow-changing NOTAM internal API responses.
 */

require_once __DIR__ . '/../constants.php';

/**
 * Send Cache-Control for NOTAM JSON endpoints (dashboard and map layer).
 *
 * Browsers and the CDN may share a response for NOTAM_API_CACHE_TTL_SECONDS,
 * with stale-while-revalidate letting the edge refresh in the background.
 *
 * @return void
 */
function notamInternalApiSendSharedCacheHeaders(): void
{
    header(
        'Cache-Control: public'
        . ', max-age=' . NOTAM_API_CACHE_TTL_SECONDS
        . ', s-maxage=' . NOTAM_API_CACHE_TTL_SECONDS
        . ', stale-while-revalidate=' . NOTAM_API_CACHE_SWR_SECONDS
    );
}

/**
 * Send Cache-Control for successful NOTAM dashboard responses.
 *
 * @return void
 */
function notamApiSendCacheHeaders(): void
{
    notamInternalApiSendSharedCacheHeaders();
}
