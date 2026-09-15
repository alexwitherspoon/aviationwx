<?php
/**
 * Refreshes the search-engine crawler identity allowlist.
 *
 * Fetches Google's official CIDR list and prunes the Bing/Yandex reverse-DNS memos.
 * Scheduler runs this every SEO_CRAWLER_IDENTITY_REFRESH_INTERVAL (12h); the rate-limit
 * hooks read the resulting files on the request path.
 *
 * Usage: php scripts/refresh-seo-crawlers.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/crawler-identity.php';

$summary = crawlerIdentityRefresh();

// Exit 1 when the refresh failed and the cached allowlist is missing or past the stale window,
// so an observer can page on a system with no usable crawler allowlist.
$age = $summary['google_cache_age_seconds'];
$noUsableCache = $age === null || (is_numeric($age) && (int) $age >= SEO_CRAWLER_STALE_AFTER_SECONDS);
$exitCode = ($summary['google_status'] === 'fetch_failed' && $noUsableCache) ? 1 : 0;

echo json_encode([
    'google_status' => $summary['google_status'],
    'google_prefixes' => $summary['google_prefixes'],
    'bing_memo_entries' => $summary['bing_memo_entries'],
    'yandex_memo_entries' => $summary['yandex_memo_entries'],
    'google_cache_age_seconds' => $summary['google_cache_age_seconds'],
]) . PHP_EOL;

exit($exitCode);