<?php
/**
 * Parse FAA NASR APT CSV extracts into a lookup index.
 */

require_once __DIR__ . '/../constants.php';

/**
 * Parse NASR APT CSV directory into airport records keyed by ARPT_ID.
 *
 * @param string $csvDir Directory containing APT_BASE.csv, APT_RWY.csv, APT_RWY_END.csv
 * @return array{airports: array<string, array>, effective_date: ?string}
 */
function nasrParseAptCsvDirectory(string $csvDir): array
{
    $basePath = rtrim($csvDir, '/') . '/APT_BASE.csv';
    $rwyPath = rtrim($csvDir, '/') . '/APT_RWY.csv';
    $endPath = rtrim($csvDir, '/') . '/APT_RWY_END.csv';

    foreach ([$basePath, $rwyPath, $endPath] as $path) {
        if (!is_readable($path)) {
            throw new RuntimeException('NASR APT CSV missing or unreadable: ' . $path);
        }
    }

    $effectiveDate = null;
    $airports = [];

    $baseRows = nasrReadCsvFile($basePath);
    foreach ($baseRows as $row) {
        $effectiveDate = $effectiveDate ?? nasrNormalizeEffectiveDate($row['EFF_DATE'] ?? null);
        $arptId = strtoupper(trim((string) ($row['ARPT_ID'] ?? '')));
        if ($arptId === '') {
            continue;
        }

        $airports[$arptId] = [
            'arpt_id' => $arptId,
            'icao_id' => nasrNullableUpper($row['ICAO_ID'] ?? null),
            'notam_id' => nasrNullableUpper($row['NOTAM_ID'] ?? null) ?? $arptId,
            'elev_ft' => nasrParseInt($row['ELEV'] ?? null),
            'lat' => nasrParseFloat($row['LAT_DECIMAL'] ?? null),
            'lon' => nasrParseFloat($row['LONG_DECIMAL'] ?? null),
            'runways' => [],
        ];
    }

    $rwyRows = nasrReadCsvFile($rwyPath);
    $runwaysByKey = [];
    foreach ($rwyRows as $row) {
        $effectiveDate = $effectiveDate ?? nasrNormalizeEffectiveDate($row['EFF_DATE'] ?? null);
        $arptId = strtoupper(trim((string) ($row['ARPT_ID'] ?? '')));
        $rwyId = trim((string) ($row['RWY_ID'] ?? ''));
        if ($arptId === '' || $rwyId === '') {
            continue;
        }

        if (!isset($airports[$arptId])) {
            $airports[$arptId] = [
                'arpt_id' => $arptId,
                'icao_id' => null,
                'notam_id' => $arptId,
                'elev_ft' => null,
                'lat' => null,
                'lon' => null,
                'runways' => [],
            ];
        }

        $surface = strtoupper(trim((string) ($row['SURFACE_TYPE_CODE'] ?? '')));
        $condition = strtoupper(trim((string) ($row['COND'] ?? '')));
        $lengthFt = nasrParseInt($row['RWY_LEN'] ?? null);
        if ($lengthFt === null || $lengthFt <= 0) {
            continue;
        }

        $key = $arptId . '|' . $rwyId;
        $runwaysByKey[$key] = [
            'rwy_id' => $rwyId,
            'length_ft' => $lengthFt,
            'width_ft' => nasrParseInt($row['RWY_WIDTH'] ?? null),
            'surface' => $surface,
            'condition' => $condition,
            'ends' => [],
        ];
        $airports[$arptId]['runways'][$rwyId] = &$runwaysByKey[$key];
    }
    unset($runwaysByKey);

    $endRows = nasrReadCsvFile($endPath);
    foreach ($endRows as $row) {
        $effectiveDate = $effectiveDate ?? nasrNormalizeEffectiveDate($row['EFF_DATE'] ?? null);
        $arptId = strtoupper(trim((string) ($row['ARPT_ID'] ?? '')));
        $rwyId = trim((string) ($row['RWY_ID'] ?? ''));
        $endId = trim((string) ($row['RWY_END_ID'] ?? ''));
        if ($arptId === '' || $rwyId === '' || $endId === '') {
            continue;
        }

        if (!isset($airports[$arptId]['runways'][$rwyId])) {
            continue;
        }

        $hgt = nasrParseFloat($row['OBSTN_HGT'] ?? null);
        $dist = nasrParseFloat($row['DIST_FROM_THR'] ?? null);

        $airports[$arptId]['runways'][$rwyId]['ends'][] = [
            'end_id' => $endId,
            'true_alignment' => nasrParseInt($row['TRUE_ALIGNMENT'] ?? null),
            'elev_ft' => nasrParseInt($row['RWY_END_ELEV'] ?? null),
            'tkof_dist_avbl' => nasrParseInt($row['TKOF_DIST_AVBL'] ?? null),
            'displaced_thr_len' => nasrParseInt($row['DISPLACED_THR_LEN'] ?? null),
            'rwy_grad_pct' => nasrParseFloat($row['RWY_GRAD'] ?? null),
            'obstruction' => [
                'type' => nasrNullableUpper($row['OBSTN_TYPE'] ?? null),
                'hgt_ft' => $hgt,
                'dist_ft' => $dist,
                'slope' => nasrParseFloat($row['OBSTN_CLNC_SLOPE'] ?? null),
            ],
        ];
    }

    foreach ($airports as $arptId => $record) {
        $airports[$arptId]['runways'] = array_values($record['runways']);
    }

    return [
        'airports' => $airports,
        'effective_date' => $effectiveDate,
    ];
}

/**
 * @return list<array<string, string>>
 */
function nasrReadCsvFile(string $path): array
{
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException('Unable to open NASR CSV: ' . $path);
    }

    $header = fgetcsv($handle, 0, ',', '"', '\\');
    if ($header === false) {
        fclose($handle);
        return [];
    }

    $header = array_map(static fn ($col) => trim((string) $col), $header);
    $rows = [];

    while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if ($data === [null] || $data === false) {
            continue;
        }
        $row = [];
        foreach ($header as $i => $col) {
            $row[$col] = $data[$i] ?? '';
        }
        $rows[] = $row;
    }

    fclose($handle);
    return $rows;
}

/**
 * @param mixed $value
 */
function nasrParseInt($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return (int) round((float) $value);
}

/**
 * @param mixed $value
 */
function nasrParseFloat($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return (float) $value;
}

/**
 * @param mixed $value
 */
function nasrNullableUpper($value): ?string
{
    if ($value === null) {
        return null;
    }
    $trimmed = strtoupper(trim((string) $value));
    return $trimmed === '' ? null : $trimmed;
}

/**
 * @param mixed $value
 */
function nasrNormalizeEffectiveDate($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $raw = trim((string) $value);
    if (preg_match('/^(\d{4})\/(\d{2})\/(\d{2})$/', $raw, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return $raw;
    }
    return null;
}
