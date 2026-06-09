<?php
/**
 * Spill aggregator must persist partial hour progress when per-run file/runtime budget is hit.
 *
 * Regression: break 2 discarded merged counters and left spills undeleted when one hour exceeded
 * METRICS_SPILL_MERGE_MAX_FILES_PER_RUN (production: status page hourly metrics stuck at zero).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class MetricsSpillAggregatorBudgetTest extends TestCase
{
    /**
     * @return array{exit: int, output: string}
     */
    private function runAggregatorHarness(string $scriptBody): array
    {
        $root = dirname(__DIR__, 2);
        $cacheId = bin2hex(random_bytes(8));
        $tmp = sys_get_temp_dir() . '/aviationwx_metrics_agg_sub_' . bin2hex(random_bytes(8)) . '.php';
        file_put_contents($tmp, $this->harnessPreamble($cacheId) . $scriptBody);

        $env = [
            'METRICS_AGG_TEST_ROOT' => $root,
            'METRICS_AGG_TEST_CACHE' => sys_get_temp_dir() . '/aviationwx_metrics_agg_cache_' . $cacheId,
        ];
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1';
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        @unlink($tmp);

        return ['exit' => $exitCode, 'output' => $out . $err];
    }

    private function harnessPreamble(string $cacheId): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

if (!defined('METRICS_SPILL_MERGE_MAX_FILES_PER_RUN')) {
    define('METRICS_SPILL_MERGE_MAX_FILES_PER_RUN', 3);
}
if (!defined('METRICS_SPILL_MERGE_MAX_RUNTIME_MS')) {
    define('METRICS_SPILL_MERGE_MAX_RUNTIME_MS', 30000);
}
if (!defined('CACHE_BASE_DIR')) {
    define('CACHE_BASE_DIR', getenv('METRICS_AGG_TEST_CACHE') ?: (sys_get_temp_dir() . '/aviationwx_metrics_agg_cache_{$cacheId}'));
}
if (!defined('AVIATIONWX_LOG_DIR')) {
    define('AVIATIONWX_LOG_DIR', sys_get_temp_dir() . '/aviationwx_metrics_agg_log_{$cacheId}');
}
@mkdir(CACHE_BASE_DIR, 0755, true);
@mkdir(AVIATIONWX_LOG_DIR, 0755, true);
@touch(AVIATIONWX_LOG_DIR . '/app.log');

\$root = getenv('METRICS_AGG_TEST_ROOT');

PHP;
    }

    /**
     * Production regression: first pass must write hourly file and delete consumed spills.
     */
    public function testPartialFlush_WritesHourlyAndDeletesConsumedSpillsWhenBudgetHit(): void
    {
        $body = <<<'PHP'
require $root . '/lib/cache-paths.php';
require $root . '/lib/metrics-spill-journal.php';
require $root . '/lib/metrics-spill-aggregator.php';

$hourId = '2099-06-01-13';
$hourDir = getMetricsSpillHourDir($hourId);
@mkdir($hourDir, 0755, true);

for ($i = 1; $i <= 5; $i++) {
    $pid = 60000 + $i;
    $journal = getMetricsSpillWorkerJournalPath($hourId, $pid);
    $payload = metrics_spill_build_payload($hourId, $pid, ['global_page_views' => 1]);
    file_put_contents($journal, json_encode($payload) . "\n");
}

$first = metrics_run_spill_aggregator_once();
if ((int) ($first['hours_touched'] ?? 0) !== 1) {
    fwrite(STDERR, 'expected hours_touched=1 on partial pass, got ' . json_encode($first) . PHP_EOL);
    exit(1);
}
if ((int) ($first['spills_merged'] ?? 0) !== 3) {
    fwrite(STDERR, 'expected spills_merged=3 on partial pass, got ' . json_encode($first) . PHP_EOL);
    exit(1);
}
if ((int) ($first['spills_deleted'] ?? 0) !== 3) {
    fwrite(STDERR, 'expected spills_deleted=3 on partial pass, got ' . json_encode($first) . PHP_EOL);
    exit(1);
}

$hourPath = getMetricsHourlyPath($hourId);
if (!is_file($hourPath)) {
    fwrite(STDERR, "hourly file missing after partial pass\n");
    exit(1);
}
$hourJson = json_decode((string) file_get_contents($hourPath), true);
if (!is_array($hourJson) || (int) ($hourJson['global']['page_views'] ?? 0) !== 3) {
    fwrite(STDERR, 'expected 3 page_views after partial pass' . PHP_EOL);
    exit(1);
}

$remaining = glob($hourDir . '/*.jsonl') ?: [];
if (count($remaining) !== 2) {
    fwrite(STDERR, 'expected 2 remaining spill journals, got ' . count($remaining) . PHP_EOL);
    exit(1);
}
if (!is_dir($hourDir)) {
    fwrite(STDERR, "hour dir removed while spills remain\n");
    exit(1);
}

$second = metrics_run_spill_aggregator_once();
if ((int) ($second['spills_merged'] ?? 0) !== 2 || (int) ($second['spills_deleted'] ?? 0) !== 2) {
    fwrite(STDERR, 'second pass did not finish hour: ' . json_encode($second) . PHP_EOL);
    exit(1);
}
$hourJson = json_decode((string) file_get_contents($hourPath), true);
if ((int) ($hourJson['global']['page_views'] ?? 0) !== 5) {
    fwrite(STDERR, 'expected 5 page_views after second pass' . PHP_EOL);
    exit(1);
}

echo json_encode(['ok' => true]);
PHP;

        $result = $this->runAggregatorHarness($body);
        $this->assertSame(0, $result['exit'], 'harness failed: ' . $result['output']);
        $decoded = json_decode(trim($result['output']), true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok'] ?? false);
    }

    /**
     * Oldest hour bucket is completed before budget stops the pass on a later hour.
     */
    public function testPartialFlush_CompletesEarlierHourBeforeStoppingOnLaterHour(): void
    {
        $body = <<<'PHP'
require $root . '/lib/cache-paths.php';
require $root . '/lib/metrics-spill-journal.php';
require $root . '/lib/metrics-spill-aggregator.php';

function write_journal(string $hourId, int $pid, int $views): void {
    $dir = getMetricsSpillHourDir($hourId);
    @mkdir($dir, 0755, true);
    $journal = getMetricsSpillWorkerJournalPath($hourId, $pid);
    $payload = metrics_spill_build_payload($hourId, $pid, ['global_page_views' => $views]);
    file_put_contents($journal, json_encode($payload) . "\n");
}

$hourA = '2099-06-01-10';
$hourB = '2099-06-01-11';
write_journal($hourA, 70001, 4);
write_journal($hourA, 70002, 6);
write_journal($hourB, 70003, 2);
write_journal($hourB, 70004, 3);

$stats = metrics_run_spill_aggregator_once();
if ((int) ($stats['hours_touched'] ?? 0) < 1) {
    fwrite(STDERR, 'expected at least one hour touched: ' . json_encode($stats) . PHP_EOL);
    exit(1);
}

$pathA = getMetricsHourlyPath($hourA);
$jsonA = json_decode((string) file_get_contents($pathA), true);
if ((int) ($jsonA['global']['page_views'] ?? 0) !== 10) {
    fwrite(STDERR, 'hour A should be fully merged (10 views)' . PHP_EOL);
    exit(1);
}

$dirB = getMetricsSpillHourDir($hourB);
$remainingB = glob($dirB . '/*.jsonl') ?: [];
if (count($remainingB) !== 1) {
    fwrite(STDERR, 'hour B should have one journal remaining, got ' . count($remainingB) . PHP_EOL);
    exit(1);
}
$pathB = getMetricsHourlyPath($hourB);
if (!is_file($pathB)) {
    fwrite(STDERR, "hour B hourly file missing after partial merge\n");
    exit(1);
}
$jsonB = json_decode((string) file_get_contents($pathB), true);
if ((int) ($jsonB['global']['page_views'] ?? 0) !== 2) {
    fwrite(STDERR, 'hour B should have 2 views from partial merge' . PHP_EOL);
    exit(1);
}

echo json_encode(['ok' => true]);
PHP;

        $result = $this->runAggregatorHarness($body);
        $this->assertSame(0, $result['exit'], 'harness failed: ' . $result['output']);
    }

    public function testWriteHourBucket_FailsClosedWhenTargetDirectoryMissing(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/constants.php';
        require_once __DIR__ . '/../../lib/metrics-spill-aggregator.php';

        $hourId = '2099-06-01-14';
        $hourData = metrics_new_empty_hour_bucket($hourId);
        $missingDir = sys_get_temp_dir() . '/aviationwx_metrics_missing_hourly_' . bin2hex(random_bytes(6));
        $hourFile = $missingDir . '/no-such-parent/' . $hourId . '.json';
        $stats = ['errors' => []];

        $ok = metrics_spill_aggregator_write_hour_bucket($hourData, $hourFile, $hourId, $stats);

        $this->assertFalse($ok);
        $this->assertContains('hourly_tmp_write_failed:' . $hourId, $stats['errors']);
        $this->assertFileDoesNotExist($hourFile);
    }
}
