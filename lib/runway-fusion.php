<?php
/**
 * Fused runway inventory for configured airports (computed reference artifact).
 *
 * Builds cache/runways/runway_fusion.json from the NASR APT cache, the
 * worldwide runways merge cache, and the airport config. Per configured,
 * enabled airport the artifact stores a union of runway rows:
 *
 *   - manual config (the operator escape hatch), then
 *   - NASR (official US source), then
 *   - OurAirports (community source, first class)
 *
 * Per-pair precedence: manual config > NASR > OurAirports. Each row keeps the
 * winning source's native row shape, plus the other sources' rows for the
 * same pair, so the read paths can feed the existing display formatter
 * unchanged.
 * Pairs without geometry in the merge cache get a derived through-center
 * segment (ident bearing, water flag, magnetic frame) so the compass can read
 * one artifact.
 *
 * Provenance (source, field_sources, effective date) lives in the data layer
 * for operators and health checks; the UI keeps its existing display.
 *
 * Failure policy: per-slice fail-open (a missing NASR cache builds without
 * official rows), fail-closed publish (zero airport rows means no write,
 * enforced by the worker).
 */

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/config-runway.php';
require_once __DIR__ . '/nasr/identifiers.php';
require_once __DIR__ . '/nasr/runway-selection.php';
require_once __DIR__ . '/runway-display.php';
require_once __DIR__ . '/runways.php';

/**
 * Input freshness stamps the artifact is validated against.
 *
 * @param string|null $configPath Resolved airports.json path, or null to omit the config stamp
 * @return array{nasr_apt_mtime: int|null, runways_cache_mtime: int|null, config_sha: string|null}
 */
function runwayFusionInputStamps(?string $configPath = null): array
{
    clearstatcache();

    $nasrMtime = is_readable(CACHE_NASR_APT_DATA_FILE) ? (int) filemtime(CACHE_NASR_APT_DATA_FILE) : null;
    $runwaysMtime = is_readable(CACHE_RUNWAYS_DATA_FILE) ? (int) filemtime(CACHE_RUNWAYS_DATA_FILE) : null;

    $configSha = null;
    $configMtime = null;
    if ($configPath !== null && $configPath !== '' && is_readable($configPath)) {
        $raw = @file_get_contents($configPath);
        if ($raw !== false) {
            $configSha = hash('sha256', $raw);
        }
        $configMtime = (int) filemtime($configPath);
    }

    return [
        'nasr_apt_mtime' => $nasrMtime,
        'runways_cache_mtime' => $runwaysMtime,
        'config_sha' => $configSha,
        // mtime rides along for the request-path freshness gate, which stats
        // instead of hashing (config changes also trip the sha at rebuild time).
        'config_mtime' => $configMtime,
    ];
}

/**
 * Whether the fused artifact needs a rebuild.
 *
 * True when the artifact is missing or structurally invalid, its schema
 * version changed, its age exceeds RUNWAY_FUSION_MAX_AGE, or any input that
 * is present on disk is newer than the stamp recorded at build time. A
 * missing input does not force a rebuild: the existing artifact stays
 * better than a churned one that would drop the slice.
 *
 * @param array<string, mixed>|null $artifact Loaded artifact or null when absent
 * @param array{nasr_apt_mtime?: int|null, runways_cache_mtime?: int|null, config_sha?: string|null} $stamps Current input stamps
 */
function runwayFusionShouldRebuild(?array $artifact, array $stamps): bool
{
    if ($artifact === null) {
        return true;
    }
    if (($artifact['schema_version'] ?? null) !== RUNWAY_FUSION_SCHEMA_VERSION) {
        return true;
    }
    if (!isset($artifact['built_at']) || !is_int($artifact['built_at'])) {
        return true;
    }
    if ((time() - $artifact['built_at']) > RUNWAY_FUSION_MAX_AGE) {
        return true;
    }

    $inputs = $artifact['inputs'] ?? null;
    if (!is_array($inputs)) {
        return true;
    }

    foreach (['nasr_apt_mtime', 'runways_cache_mtime'] as $key) {
        $current = $stamps[$key] ?? null;
        if (!is_int($current)) {
            continue;
        }
        $recorded = isset($inputs[$key]) && is_int($inputs[$key]) ? $inputs[$key] : 0;
        if ($current > $recorded) {
            return true;
        }
    }

    $configSha = $stamps['config_sha'] ?? null;
    if (is_string($configSha) && $configSha !== '' && ($inputs['config_sha'] ?? null) !== $configSha) {
        return true;
    }

    return false;
}

/**
 * Resolve the NASR airport record for a platform airport config row.
 *
 * Mirrors getNasrAirportForConfig() but reads an already-loaded payload so the
 * builder stays a pure function of its inputs (one cache read per worker run).
 *
 * @param array<string, mixed> $airport Airport configuration
 * @param array<string, mixed>|null $nasrPayload Parsed NASR APT cache payload
 * @return array<string, mixed>|null NASR airport record
 */
function runwayFusionNasrRecordForAirport(array $airport, ?array $nasrPayload): ?array
{
    if (!is_array($nasrPayload) || !isset($nasrPayload['airports']) || !is_array($nasrPayload['airports'])) {
        return null;
    }

    $airports = $nasrPayload['airports'];
    foreach (nasrCandidateArptIds($airport) as $candidate) {
        if (isset($airports[$candidate]) && is_array($airports[$candidate])) {
            return $airports[$candidate];
        }
    }

    return null;
}

/**
 * Build the fused entry for one configured airport.
 *
 * @param string $airportId Config key (e.g. '42B' or '45ranch')
 * @param array<string, mixed> $airport Airport configuration
 * @param array<string, mixed>|null $nasrPayload Parsed NASR APT cache payload (null = slice absent)
 * @param array<string, mixed>|null $runwaysData Parsed runways merge cache (null = slice absent)
 * @return array{rows: list<array<string, mixed>>, segments: list<array<string, mixed>>, calm_wind: array<string, mixed>|null}|null
 *         Entry, or null when no source provides a row
 */
function runwayFusionBuildAirportEntry(string $airportId, array $airport, ?array $nasrPayload, ?array $runwaysData): ?array
{
    $nasrRecord = runwayFusionNasrRecordForAirport($airport, $nasrPayload);
    $nasrRows = $nasrRecord !== null ? nasrSelectRunwaysForDisplay($nasrRecord) : [];
    // Cycle effective date: top level of the payload (full cache carries it;
    // the worker stamps it onto configured slices from NASR meta).
    $nasrEffectiveDate = null;
    if (is_array($nasrPayload) && is_string($nasrPayload['effective_date'] ?? null)) {
        $nasrEffectiveDate = $nasrPayload['effective_date'];
    }
    $oaRows = $runwaysData !== null
        ? getOurAirportsDisplayRunwaysFromParsedCache($runwaysData, $airportId, $airport)
        : null;
    $manualRow = buildConfigRunwayForDensityAltitude($airport);
    $factsByRunwayId = runwayDisplayConfigFactsByRunwayId($airport);

    // Union by normalized pair key. First row wins when a source repeats a
    // pair; later rows for the same pair are ignored (sources don't repeat).
    $pairs = [];
    foreach ($nasrRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = densityAltitudePerformanceNormalizeRunwayPairKey((string) ($row['rwy_id'] ?? ''));
        if ($key !== '' && !isset($pairs[$key]['nasr'])) {
            $pairs[$key]['nasr'] = $row;
        }
    }
    foreach (is_array($oaRows) ? $oaRows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = densityAltitudePerformanceNormalizeRunwayPairKey((string) ($row['rwy_id'] ?? ''));
        if ($key !== '' && !isset($pairs[$key]['oa'])) {
            $pairs[$key]['oa'] = $row;
        }
    }

    // A manual row with a name (runways[0].name) participates in the union
    // like any source. A nameless row (rwy_id 'config', runway_length_ft only)
    // is the DA escape hatch: it becomes a row only when no other source
    // provides one, matching today's closed-world fallback.
    if ($manualRow !== null) {
        $manualKey = densityAltitudePerformanceNormalizeRunwayPairKey((string) ($manualRow['rwy_id'] ?? ''));
        $nameless = strtolower((string) ($manualRow['rwy_id'] ?? '')) === 'config';
        if ($manualKey !== '' && (!$nameless || $pairs === [])) {
            $pairs[$manualKey]['config'] = $manualRow;
        }
    }

    if ($pairs === []) {
        return null;
    }
    ksort($pairs);

    $calmWind = is_array($nasrRecord['calm_wind'] ?? null) ? $nasrRecord['calm_wind'] : [];
    $declination = runwayDisplayMagneticDeclinationDeg($airport);

    $rows = [];
    foreach ($pairs as $key => $sources) {
        $hasConfig = isset($sources['config']);
        $hasNasr = isset($sources['nasr']);
        $winner = $sources['config'] ?? $sources['nasr'] ?? $sources['oa'];
        $source = $hasConfig ? 'config' : ($hasNasr ? 'nasr' : 'ourairports');
        $factsRow = $factsByRunwayId[$key] ?? [];
        $oaRow = $sources['oa'] ?? [];

        // Per-field provenance mirrors the display formatter's resolution
        // chain: config facts > winning source row > OurAirports row.
        [, $lengthSource] = runwayDisplayResolveField(
            $factsRow,
            $winner['length_ft'] ?? null,
            $factsRow['length_ft'] ?? null,
            $source
        );
        [, $widthSource] = runwayDisplayResolveFieldWithFallback(
            $factsRow,
            $winner['width_ft'] ?? null,
            $oaRow['width_ft'] ?? null,
            $factsRow['width_ft'] ?? null,
            $source
        );
        [$surfaceCode, $surfaceSource] = runwayDisplayResolveFieldWithFallback(
            $factsRow,
            $winner['surface'] ?? null,
            $oaRow['surface'] ?? null,
            $factsRow['surface'] ?? null,
            $source
        );
        $surfaceCode = is_string($surfaceCode) ? strtoupper(trim($surfaceCode)) : null;
        $nasrLights = nasrRunwayLightsLabel($winner['lights_code'] ?? null);
        $oaLights = runwayDisplayOurAirportsLightsLabel(
            isset($oaRow['lighted']) ? (bool) $oaRow['lighted'] : null
        );
        [, $lightsSource] = runwayDisplayResolveFieldWithFallback(
            $factsRow,
            $nasrLights,
            $oaLights,
            $factsRow['lights'] ?? null,
            $source
        );

        // Source-level closure (NOTAM overlay stays live in the read path).
        $closed = nasrRunwayIsClosedInSource($winner) || !empty($oaRow['closed']);
        if (isset($factsRow['closed'])) {
            $closed = (bool) $factsRow['closed'];
        }

        $rows[] = [
            'key' => $key,
            'source' => $source,
            'effective_date' => $hasNasr ? $nasrEffectiveDate : null,
            'is_water' => $surfaceCode !== null && ourAirportsIsWaterSurface($surfaceCode),
            'closed' => $closed,
            'field_sources' => array_filter([
                'length_ft' => $lengthSource,
                'width_ft' => $widthSource,
                'surface' => $surfaceSource,
                'lights' => $lightsSource,
            ]),
            'nasr' => $sources['nasr'] ?? null,
            'oa' => $sources['oa'] ?? null,
            'config' => $sources['config'] ?? null,
        ];
    }

    $segments = runwayFusionDerivedSegments(
        $airportId,
        $airport,
        $rows,
        $factsByRunwayId,
        $calmWind,
        $declination,
        $runwaysData
    );

    return [
        'rows' => $rows,
        'segments' => $segments,
        'calm_wind' => $calmWind !== [] ? $calmWind : null,
    ];
}

/**
 * Derived through-center segments for union pairs without geometry.
 *
 * A pair is covered (no derived segment) when the merge cache has geometry
 * for it or a manual compass runway provides it. Remaining pairs are
 * formatted through the display formatter (so headings, water flags, and
 * closed handling match the list exactly) and synthesized.
 *
 * @param string $airportId Config key
 * @param array<string, mixed> $airport Airport configuration
 * @param list<array<string, mixed>> $fusedRows Rows from runwayFusionBuildAirportEntry()
 * @param array<string, array<string, mixed>> $factsByRunwayId Config runway_facts indexed by pair key
 * @param array<string, mixed> $calmWind NASR calm wind (may be empty)
 * @param float|null $declination Magnetic declination in degrees
 * @param array<string, mixed>|null $runwaysData Parsed runways merge cache
 * @return list<array<string, mixed>>
 */
function runwayFusionDerivedSegments(
    string $airportId,
    array $airport,
    array $fusedRows,
    array $factsByRunwayId,
    array $calmWind,
    ?float $declination,
    ?array $runwaysData
): array {
    $covered = [];
    if ($runwaysData !== null) {
        $geometry = getRunwaySegmentsFromParsedCache($runwaysData, $airportId, $airport);
        foreach (is_array($geometry) ? $geometry : [] as $seg) {
            if (!is_array($seg)) {
                continue;
            }
            $le = trim((string) ($seg['le_ident'] ?? ''));
            $he = trim((string) ($seg['he_ident'] ?? ''));
            if ($le === '' || $he === '') {
                continue;
            }
            $covered[densityAltitudePerformanceNormalizeRunwayPairKey($le . '/' . $he)] = true;
        }
    }

    // Manual compass runways carry their own geometry (the read path keeps the
    // manual override), so their pairs never get a derived segment.
    if (!empty($airport['runways']) && is_array($airport['runways'])) {
        foreach ($airport['runways'] as $manual) {
            if (!is_array($manual) || empty($manual['name'])) {
                continue;
            }
            $key = densityAltitudePerformanceNormalizeRunwayPairKey((string) $manual['name']);
            if ($key !== '') {
                $covered[$key] = true;
            }
        }
    }

    $notamClosures = [
        'aerodrome_closed' => false,
        'closed_pair_designators' => [],
        'closed_end_idents' => [],
    ];
    $formatted = [];
    foreach ($fusedRows as $row) {
        if (!is_array($row) || isset($covered[$row['key']])) {
            continue;
        }
        $winner = $row['config'] ?? $row['nasr'] ?? $row['oa'];
        if (!is_array($winner)) {
            continue;
        }
        $formatted[] = runwayDisplayFormatRunwayRow(
            $winner,
            $factsByRunwayId[$row['key']] ?? [],
            $calmWind,
            $row['oa'] ?? [],
            $notamClosures,
            (string) ($row['source'] ?? 'fusion'),
            $declination
        );
    }
    $formatted = array_filter($formatted, 'is_array');

    return synthesizeRunwaySegmentsFromDisplay($formatted, 'fusion');
}

/**
 * Load and validate the published fused artifact.
 *
 * @return array<string, mixed>|null Valid artifact or null when absent/invalid
 */
function runwayFusionLoadArtifact(): ?array
{
    if (!is_readable(CACHE_RUNWAY_FUSION_FILE)) {
        return null;
    }

    $raw = @file_get_contents(CACHE_RUNWAY_FUSION_FILE);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['airports']) || !is_array($data['airports'])) {
        return null;
    }
    if (($data['schema_version'] ?? null) !== RUNWAY_FUSION_SCHEMA_VERSION) {
        return null;
    }

    return $data;
}

/**
 * Publish the fused artifact atomically (temp file + rename).
 *
 * @param array<string, mixed> $payload Artifact payload
 */
function runwayFusionWriteArtifact(array $payload): bool
{
    $target = CACHE_RUNWAY_FUSION_FILE;
    $dir = dirname($target);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    $tmp = $target . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/**
 * Load the published artifact, memoized per request (the read path stats and
 * decodes it on every airport page and weather call).
 *
 * @return array<string, mixed>|null Valid artifact or null when absent/invalid
 */
function runwayFusionLoadArtifactMemoized(): ?array
{
    if (($GLOBALS['_runway_fusion_artifact_resolved'] ?? false) !== true) {
        $GLOBALS['_runway_fusion_artifact_resolved'] = true;
        $GLOBALS['_runway_fusion_artifact_memo'] = runwayFusionLoadArtifact();
    }

    return $GLOBALS['_runway_fusion_artifact_memo'] ?? null;
}

/**
 * Clear the per-request artifact memo (testing).
 */
function resetRunwayFusionArtifactMemo(): void
{
    $GLOBALS['_runway_fusion_artifact_resolved'] = false;
    $GLOBALS['_runway_fusion_artifact_memo'] = null;
}

/**
 * Request-path freshness gate for serving the artifact.
 *
 * True when the artifact is valid, within RUNWAY_FUSION_MAX_AGE, and no input
 * present on disk is newer than the stamps recorded at build time. Stat-only
 * by design (no config hash on the request path). Null means "cannot verify
 * fresh" (artifact missing/invalid, or the config path is unresolvable) and
 * callers fall back to the live resolver.
 */
function runwayFusionArtifactIsFresh(): ?bool
{
    $artifact = runwayFusionLoadArtifactMemoized();
    if ($artifact === null) {
        return null;
    }

    $builtAt = $artifact['built_at'] ?? null;
    if (!is_int($builtAt) || (time() - $builtAt) > RUNWAY_FUSION_MAX_AGE) {
        return false;
    }

    $inputs = $artifact['inputs'] ?? [];
    clearstatcache();

    // NASR: a missing cache keeps the artifact servable. Its rows were valid
    // at build time and runway definitions rarely change; a returned cycle
    // has a newer mtime and trips the freshness check below.
    $nasrRecorded = isset($inputs['nasr_apt_mtime']) && is_int($inputs['nasr_apt_mtime']) ? $inputs['nasr_apt_mtime'] : 0;
    if (is_readable(CACHE_NASR_APT_DATA_FILE) && (int) filemtime(CACHE_NASR_APT_DATA_FILE) > $nasrRecorded) {
        return false;
    }

    // Runways merge cache: if it existed at build time and is now gone (the
    // merge retention gate deletes it on failed fetches), the geometry the
    // artifact's segment coverage assumes is gone too. Do not serve it; the
    // live display fallback derives segments for every pair instead.
    $runwaysRecorded = isset($inputs['runways_cache_mtime']) && is_int($inputs['runways_cache_mtime']) ? $inputs['runways_cache_mtime'] : 0;
    if (!is_readable(CACHE_RUNWAYS_DATA_FILE)) {
        if ($runwaysRecorded > 0) {
            return false;
        }
    } elseif ((int) filemtime(CACHE_RUNWAYS_DATA_FILE) > $runwaysRecorded) {
        return false;
    }

    $configPath = getConfigFilePath();
    if (!is_string($configPath) || $configPath === '' || !is_file($configPath)) {
        return null;
    }
    $recordedConfig = isset($inputs['config_mtime']) && is_int($inputs['config_mtime']) ? $inputs['config_mtime'] : 0;
    if ((int) filemtime($configPath) > $recordedConfig) {
        return false;
    }

    return true;
}

/**
 * Fused entry for an airport when the artifact is fresh, else null.
 *
 * @param string $airportId Airport identifier (config key or ICAO/Faa, any case)
 * @return array{rows: list<array<string, mixed>>, segments: list<array<string, mixed>>, calm_wind: array<string, mixed>|null}|null
 */
function runwayFusionEntryForAirport(string $airportId): ?array
{
    if (runwayFusionArtifactIsFresh() !== true) {
        return null;
    }

    $artifact = runwayFusionLoadArtifactMemoized();
    $entry = $artifact['airports'][strtolower($airportId)] ?? null;

    return is_array($entry) ? $entry : null;
}

/**
 * Join fused derived segments onto geometry segments, deduped by normalized
 * pair key. Geometry segments keep their coordinates; a derived segment for a
 * pair the geometry already covers is dropped.
 *
 * @param list<array<string, mixed>> $geometrySegments Merge-cache segments
 * @param list<array<string, mixed>> $fusedSegments Derived segments from the artifact
 * @return list<array<string, mixed>>
 */
function runwayFusionMergeDerivedSegments(array $geometrySegments, array $fusedSegments): array
{
    $merged = [];
    $covered = [];

    foreach ($geometrySegments as $seg) {
        if (!is_array($seg)) {
            continue;
        }
        $merged[] = $seg;
        $key = runwayFusionSegmentPairKey($seg);
        if ($key !== '') {
            $covered[$key] = true;
        }
    }

    foreach ($fusedSegments as $seg) {
        if (!is_array($seg)) {
            continue;
        }
        $key = runwayFusionSegmentPairKey($seg);
        if ($key !== '' && isset($covered[$key])) {
            continue;
        }
        if ($key !== '') {
            $covered[$key] = true;
        }
        $merged[] = $seg;
    }

    return $merged;
}

/**
 * @param array<string, mixed> $seg Segment with le_ident/he_ident
 */
function runwayFusionSegmentPairKey(array $seg): string
{
    $le = trim((string) ($seg['le_ident'] ?? ''));
    $he = trim((string) ($seg['he_ident'] ?? ''));
    if ($le === '' || $he === '') {
        return '';
    }

    return densityAltitudePerformanceNormalizeRunwayPairKey($le . '/' . $he);
}
