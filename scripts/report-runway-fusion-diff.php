#!/usr/bin/env php
<?php
/**
 * Report the display behavior diff between the live (pre-fusion) runway
 * resolver and the fused inventory, per configured, enabled airport.
 *
 * Maintainer tool for PR reviews and release notes: enumerate exactly which
 * airports will show a different runway list or wind diagram once the read
 * paths serve the fused artifact, and where each field came from. Reads the
 * same inputs as the fusion worker (NASR cache, runways merge cache,
 * airports.json) and writes nothing.
 *
 * Usage:
 *   php scripts/report-runway-fusion-diff.php [--json]
 *
 * Exit codes: 0 success, 1 failure
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

chdir(__DIR__ . '/..');

define('AVIATIONWX_SKIP_COUNTRY_RESOLUTION_MERGE', true);

// Test seam: disposable cache root (tests/bootstrap.php convention).
$testCacheDir = getenv('TEST_CACHE_DIR');
if (is_string($testCacheDir) && $testCacheDir !== '') {
    define('CACHE_BASE_DIR', rtrim($testCacheDir, '/'));
}

require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/runway-fusion.php';

$asJson = in_array('--json', $argv, true);

$config = loadConfig();
if ($config === null || !isset($config['airports']) || !is_array($config['airports'])) {
    fwrite(STDERR, "report-runway-fusion-diff: loadConfig() failed or no airports\n");
    exit(1);
}

// Same input gathering as the fusion worker, effective-date stamp included.
$nasrPayload = loadNasrAptCache();
if ($nasrPayload !== null && !isset($nasrPayload['effective_date'])) {
    $nasrMeta = loadNasrAptMeta();
    $nasrPayload['effective_date'] = is_array($nasrMeta) && is_string($nasrMeta['effective_date'] ?? null)
        ? $nasrMeta['effective_date']
        : null;
}

$runwaysData = loadRunwaysCacheDataFromDisk();

/**
 * The pre-fusion selection: first source with rows wins the whole list.
 *
 * @return array{rows: list<array<string, mixed>>, source: string|null}
 */
function reportDiffLiveSelection(array $airport, string $airportId): array
{
    $nasrRecord = getNasrAirportForConfig($airport);
    $rows = $nasrRecord !== null ? nasrSelectRunwaysForDisplay($nasrRecord) : [];
    $source = $rows !== [] ? 'nasr' : null;

    if ($rows === []) {
        $oaRows = loadOurAirportsDisplayRunwaysFromFileCache($airportId, $airport);
        if ($oaRows !== null && $oaRows !== []) {
            $rows = $oaRows;
            $source = 'ourairports';
        }
    }

    if ($rows === []) {
        $configRow = buildConfigRunwayForDensityAltitude($airport);
        if ($configRow !== null) {
            $rows = [$configRow];
            $source = 'config';
        }
    }

    return ['rows' => $rows, 'source' => $source];
}

$emptyClosures = [
    'aerodrome_closed' => false,
    'closed_pair_designators' => [],
    'closed_end_idents' => [],
];

$diffs = [];
$airportCount = 0;
$changedCount = 0;

foreach ($config['airports'] as $airportId => $airport) {
    if (!is_array($airport) || !isAirportEnabled($airport)) {
        continue;
    }
    $airportId = strtolower((string) $airportId);
    $airportCount++;

    $fused = runwayFusionBuildAirportEntry($airportId, $airport, $nasrPayload, $runwaysData);
    if ($fused === null) {
        continue;
    }

    // Fused rows, paired with live rows by the winning source's strict pair
    // key. The artifact's own row key is contextual (L/C/R lettering drift
    // stripped for single-strip fields), which the pre-fusion live list does
    // not do; pairing on it would report phantom add/remove pairs.
    $fusedByKey = [];
    $fusedRowKeys = [];
    $fusedSources = [];
    $effectiveDate = null;
    foreach ((array) $fused['rows'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowKey = (string) ($row['key'] ?? '');
        if ($rowKey === '') {
            continue;
        }
        $winner = is_array($row['config'] ?? null) ? $row['config']
            : (is_array($row['nasr'] ?? null) ? $row['nasr'] : $row['oa']);
        if (!is_array($winner)) {
            continue;
        }
        $key = densityAltitudePerformanceNormalizeRunwayPairKey((string) ($winner['rwy_id'] ?? ''));
        if ($key === '') {
            continue;
        }
        $fusedByKey[$key] = $row;
        $fusedRowKeys[$key] = $rowKey;
        $fusedSources[$key] = (string) ($row['source'] ?? 'unknown');
        if ($effectiveDate === null && is_string($row['effective_date'] ?? null) && $row['effective_date'] !== '') {
            $effectiveDate = $row['effective_date'];
        }
    }

    // Live (pre-fusion) rows, keyed the same way.
    $live = reportDiffLiveSelection($airport, $airportId);
    $liveByKey = [];
    foreach ($live['rows'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = densityAltitudePerformanceNormalizeRunwayPairKey((string) ($row['rwy_id'] ?? ''));
        if ($key !== '') {
            $liveByKey[$key] = $row;
        }
    }

    $configFacts = runwayDisplayConfigFactsByRunwayId($airport);
    $declination = runwayDisplayMagneticDeclinationDeg($airport);

    $added = [];
    $removed = [];
    $changed = [];
    foreach ($fusedByKey as $key => $row) {
        if (!isset($liveByKey[$key])) {
            $added[] = [
                'pair' => $fusedRowKeys[$key],
                'source' => $fusedSources[$key] ?? 'unknown',
                'is_water' => !empty($row['is_water']),
            ];
            continue;
        }
        // Both lists carry the pair: compare what the formatter renders.
        // The fused row gets the same config-facts resolution the read path
        // uses; the live row keeps the pre-fusion strict-key lookup.
        $winner = is_array($row['config'] ?? null) ? $row['config']
            : (is_array($row['nasr'] ?? null) ? $row['nasr'] : $row['oa']);
        $liveRow = $liveByKey[$key];
        $oaRow = is_array($row['oa'] ?? null) ? $row['oa'] : (is_array($liveRow) && $live['source'] === 'ourairports' ? $liveRow : []);
        $fusedFormatted = runwayDisplayFormatRunwayRow(
            is_array($winner) ? $winner : [],
            runwayFusionConfigFactsForRow($configFacts, $fusedRowKeys[$key], $winner),
            [],
            $oaRow,
            $emptyClosures,
            $fusedSources[$key] ?? 'unknown',
            $declination
        );
        $liveFormatted = runwayDisplayFormatRunwayRow(
            $liveRow,
            $configFacts[$key] ?? [],
            [],
            $oaRow,
            $emptyClosures,
            (string) ($live['source'] ?? 'unknown'),
            $declination
        );
        if ($fusedFormatted === null || $liveFormatted === null) {
            continue;
        }
        $fieldDiff = [];
        foreach (['length_ft', 'width_ft', 'surface', 'lights', 'is_water'] as $field) {
            if (($fusedFormatted[$field] ?? null) !== ($liveFormatted[$field] ?? null)) {
                $fieldDiff[$field] = [
                    'live' => $liveFormatted[$field] ?? null,
                    'fused' => $fusedFormatted[$field] ?? null,
                ];
            }
        }
        if ($fieldDiff !== []) {
            $changed[] = [
                'pair' => $fusedRowKeys[$key],
                'live_source' => (string) ($live['source'] ?? 'unknown'),
                'fused_source' => $fusedSources[$key] ?? 'unknown',
                'fields' => $fieldDiff,
            ];
        }
    }
    foreach ($liveByKey as $key => $row) {
        if (!isset($fusedByKey[$key])) {
            $removed[] = $key;
        }
    }

    // Compass: derived segments add diagram lines the geometry cache lacks.
    $diagram = [];
    foreach ((array) $fused['segments'] as $seg) {
        if (!is_array($seg)) {
            continue;
        }
        $diagram[] = [
            'pair' => trim((string) ($seg['le_ident'] ?? '')) . '/' . trim((string) ($seg['he_ident'] ?? '')),
            'water' => !empty($seg['water']),
        ];
    }

    if ($added === [] && $removed === [] && $changed === [] && $diagram === []) {
        continue;
    }

    $changedCount++;
    $diffs[] = [
        'airport' => $airportId,
        'name' => (string) ($airport['name'] ?? ''),
        'list_source_live' => $live['source'],
        'nasr_effective_date' => $effectiveDate,
        'list_added' => $added,
        'list_removed' => $removed,
        'list_changed' => $changed,
        'diagram_added' => $diagram,
    ];
}

if ($asJson) {
    echo json_encode([
        'airports_configured' => $airportCount,
        'airports_affected' => count($diffs),
        'diffs' => $diffs,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

printf(
    "Runway fusion behavior diff: %d airport(s) configured, %d with a display change\n\n",
    $airportCount,
    count($diffs)
);
foreach ($diffs as $diff) {
    printf("%s (%s)\n", $diff['airport'], $diff['name']);
    printf(
        "  list source: %s -> fused (union)\n",
        $diff['list_source_live'] ?? 'none'
    );
    foreach ($diff['list_added'] as $pair) {
        printf(
            "  + list %s [%s]%s\n",
            $pair['pair'],
            $pair['source'],
            $pair['is_water'] ? ' water' : ''
        );
    }
    foreach ($diff['list_removed'] as $pair) {
        printf("  - list %s (anomaly: fused inventory lost a live row)\n", $pair);
    }
    foreach ($diff['list_changed'] as $pair) {
        $fields = [];
        foreach ($pair['fields'] as $field => $values) {
            $fields[] = sprintf(
                '%s: %s -> %s',
                $field,
                var_export($values['live'], true),
                var_export($values['fused'], true)
            );
        }
        printf("  ~ list %s: %s\n", $pair['pair'], implode('; ', $fields));
    }
    foreach ($diff['diagram_added'] as $seg) {
        printf("  + diagram %s%s (derived segment)\n", $seg['pair'], $seg['water'] ? ' water' : '');
    }
    echo "\n";
}

exit(0);
