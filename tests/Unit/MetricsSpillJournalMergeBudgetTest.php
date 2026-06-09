<?php
/**
 * Claimed journal merge respects METRICS_SPILL_MERGE_MAX_RUNTIME_MS within one file.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class MetricsSpillJournalMergeBudgetTest extends TestCase
{
    /**
     * @return array{exit: int, output: string}
     */
    private function runHarness(string $scriptBody): array
    {
        $root = dirname(__DIR__, 2);
        $cacheId = bin2hex(random_bytes(8));
        $tmp = sys_get_temp_dir() . '/aviationwx_journal_merge_sub_' . bin2hex(random_bytes(8)) . '.php';
        file_put_contents($tmp, $this->harnessPreamble($cacheId) . $scriptBody);

        $env = [
            'METRICS_AGG_TEST_ROOT' => $root,
            'METRICS_AGG_TEST_CACHE' => sys_get_temp_dir() . '/aviationwx_journal_merge_cache_' . $cacheId,
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

if (!defined('METRICS_SPILL_MERGE_MAX_RUNTIME_MS')) {
    define('METRICS_SPILL_MERGE_MAX_RUNTIME_MS', 1);
}
if (!defined('CACHE_BASE_DIR')) {
    define('CACHE_BASE_DIR', getenv('METRICS_AGG_TEST_CACHE') ?: (sys_get_temp_dir() . '/aviationwx_journal_merge_cache_{$cacheId}'));
}
if (!defined('AVIATIONWX_LOG_DIR')) {
    define('AVIATIONWX_LOG_DIR', sys_get_temp_dir() . '/aviationwx_journal_merge_log_{$cacheId}');
}
@mkdir(CACHE_BASE_DIR, 0755, true);
@mkdir(AVIATIONWX_LOG_DIR, 0755, true);
@touch(AVIATIONWX_LOG_DIR . '/app.log');

\$root = getenv('METRICS_AGG_TEST_ROOT');

PHP;
    }

    public function testMergeClaimed_RuntimeBudget_RewritesUnreadTail(): void
    {
        $body = <<<'PHP'
require $root . '/lib/cache-paths.php';
require $root . '/lib/metrics-spill-journal.php';

$hourId = '2099-05-01-10';
$claimed = getMetricsSpillHourDir($hourId) . '/77.jsonl.merging.1';
@mkdir(dirname($claimed), 0755, true);

$lines = '';
for ($i = 1; $i <= 5000; $i++) {
    $payload = metrics_spill_build_payload($hourId, 77, ['global_page_views' => 1]);
    $lines .= json_encode($payload) . "\n";
}
file_put_contents($claimed, $lines);

require $root . '/lib/metrics.php';
$hourData = metrics_new_empty_hour_bucket($hourId);

$t0Ns = hrtime(true);
$fullyConsumed = false;
$merged = metrics_spill_journal_merge_claimed_into_hour_data(
    $claimed,
    $hourId,
    $hourData,
    $t0Ns,
    $fullyConsumed
);

if ($merged === null || $merged < 1) {
    fwrite(STDERR, "expected partial merge, got " . var_export($merged, true) . "\n");
    exit(1);
}
if ($fullyConsumed) {
    fwrite(STDERR, "expected partial journal to remain\n");
    exit(1);
}
if (!is_file($claimed)) {
    fwrite(STDERR, "expected claimed tail file to remain\n");
    exit(1);
}
$remaining = count(file($claimed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
if ($remaining < 1 || $remaining >= 5000) {
    fwrite(STDERR, "expected unread tail, got {$remaining} lines\n");
    exit(1);
}

echo json_encode(['ok' => true, 'merged' => $merged, 'remaining' => $remaining]);
PHP;

        $result = $this->runHarness($body);
        $this->assertSame(0, $result['exit'], 'harness failed: ' . $result['output']);
        $decoded = json_decode(trim($result['output']), true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok'] ?? false);
    }
}
