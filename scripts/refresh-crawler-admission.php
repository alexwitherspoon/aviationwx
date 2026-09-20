<?php
/**
 * Refreshes the crawler admission allowlist.
 *
 * Fetches Google's official crawler CIDR list and drains the Bing/Yandex verification queue,
 * publishing the verified-IP map. Runs off the request path via the scheduler tick. The request
 * path only reads the two allowlist files.
 *
 * Usage: php scripts/refresh-crawler-admission.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/crawler-admission.php';

$summary = crawlerAdmissionRefresh();

// Fail when the Google list could not be fetched or written and no usable cached file exists,
// or when the verified-IP map failed to publish (the drain could not complete).
$age = file_exists(getCrawlerGoogleAllowlistPath()) ? (time() - (int) filemtime(getCrawlerGoogleAllowlistPath())) : null;
$noUsableGoogle = $age === null || $age >= CRAWLER_ALLOWLIST_MAX_AGE;
$googleFailed = $summary['google_status'] !== 'fetched' && $noUsableGoogle;
$drainFailed = empty($summary['drain_published']) && $summary['queue_drained'] > 0;
$exitCode = ($googleFailed || $drainFailed) ? 1 : 0;

echo json_encode($summary) . PHP_EOL;
exit($exitCode);
