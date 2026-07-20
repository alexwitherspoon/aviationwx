<?php
/**
 * Reference data observability smoke checks (run inside Docker web container).
 *
 * Usage: php scripts/docker-reference-data-smoke.php
 */

declare(strict_types=1);

$failures = [];

function smoke(string $label, callable $fn): void
{
    global $failures;
    try {
        $fn();
        echo "PASS  {$label}\n";
    } catch (Throwable $e) {
        $failures[] = "{$label}: " . $e->getMessage();
        echo "FAIL  {$label}: " . $e->getMessage() . "\n";
    }
}

function smokeAssert(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/public-api/config.php';
require_once __DIR__ . '/../lib/reference-data-health.php';
require_once __DIR__ . '/../lib/operations-snapshot.php';
require_once __DIR__ . '/../lib/status-checks.php';
require_once __DIR__ . '/../lib/ourairports/refresh.php';
require_once __DIR__ . '/../lib/nasr/workers.php';

echo "=== Reference data Docker smoke ===\n";

smoke('public API enabled in dev secrets', static function (): void {
    smokeAssert(isPublicApiEnabled(), 'expected public API enabled');
});

$config = loadConfig();

smoke('checkReferenceDataHealth returns six consumers', static function () use ($config): void {
    $health = checkReferenceDataHealth($config, null);
    smokeAssert(($health['status'] ?? '') !== '', 'missing status');
    smokeAssert(count($health['consumers'] ?? []) === 6, 'expected 6 consumers');
    foreach ($health['consumers'] as $consumer) {
        smokeAssert(isset($consumer['sources']) && is_array($consumer['sources']) && $consumer['sources'] !== [], 'consumer missing sources');
    }
});

smoke('operations reference_catalogs fallback', static function (): void {
    $public = operations_snapshot_build_reference_catalogs(null);
    smokeAssert(isset($public['consumers']) && count($public['consumers']) === 6, 'expected 6 public consumers');
    $source = $public['consumers'][0]['sources'][0] ?? [];
    smokeAssert(array_key_exists('needs_fetch', $source), 'missing needs_fetch on public source');
});

smoke('system health uses reference_data not legacy keys', static function (): void {
    $system = checkSystemHealth();
    smokeAssert(isset($system['components']['reference_data']['consumers']), 'missing reference_data.consumers');
    smokeAssert(!isset($system['components']['runway_cache']), 'legacy runway_cache still present');
    smokeAssert(!isset($system['components']['nasr_apt_cache']), 'legacy nasr_apt_cache still present');
});

smoke('worker policy helpers callable', static function (): void {
    ourAirportsProbeWorkerShouldRun();
    ourAirportsBulkWorkerShouldRun();
    runwaysMergeWorkerShouldRun();
    nasrAptWorkerShouldRun();
    nasrFrqWorkerShouldRun();
});

if ($failures !== []) {
    echo "\n=== " . count($failures) . " failure(s) ===\n";
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}

echo "\nAll smoke checks passed.\n";
