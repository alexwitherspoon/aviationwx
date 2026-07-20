<?php
/**
 * Pre-parse validation for NASR APT and FRQ CSV extracts.
 */

/** @var array<string, string> */
const NASR_CSV_HEADER_PREFIX = [
    'APT_BASE' => '"EFF_DATE","SITE_NO"',
    'APT_RWY' => '"EFF_DATE","SITE_NO","SITE_TYPE_CODE","STATE_CODE","ARPT_ID","CITY","COUNTRY_CODE","RWY_ID"',
    'APT_RWY_END' => '"EFF_DATE","SITE_NO","SITE_TYPE_CODE","STATE_CODE","ARPT_ID","CITY","COUNTRY_CODE","RWY_ID","RWY_END_ID"',
    'FRQ' => '"EFF_DATE","FACILITY"',
];

/**
 * Whether a NASR CSV file has a non-empty body and expected header prefix.
 */
function nasrCsvFileIsValid(string $path, string $headerPrefix): bool
{
    if (!is_readable($path)) {
        return false;
    }

    $size = @filesize($path);
    if (!is_int($size) || $size < 20) {
        return false;
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }

    $firstLine = fgets($handle);
    fclose($handle);

    if (!is_string($firstLine) || $firstLine === '') {
        return false;
    }

    $trimmed = ltrim($firstLine);
    if ($trimmed !== '' && $trimmed[0] === '<') {
        return false;
    }

    return str_starts_with(rtrim($firstLine, "\r\n"), $headerPrefix);
}

/**
 * Validate extracted NASR APT CSV directory before parse.
 */
function nasrAptCsvDirectoryIsValid(string $dir): bool
{
    $base = rtrim($dir, '/') . '/APT_BASE.csv';
    $rwy = rtrim($dir, '/') . '/APT_RWY.csv';
    $end = rtrim($dir, '/') . '/APT_RWY_END.csv';

    return nasrCsvFileIsValid($base, NASR_CSV_HEADER_PREFIX['APT_BASE'])
        && nasrCsvFileIsValid($rwy, NASR_CSV_HEADER_PREFIX['APT_RWY'])
        && nasrCsvFileIsValid($end, NASR_CSV_HEADER_PREFIX['APT_RWY_END']);
}

/**
 * Validate extracted NASR FRQ.csv before parse.
 */
function nasrFrqCsvFileIsValid(string $path): bool
{
    return nasrCsvFileIsValid($path, NASR_CSV_HEADER_PREFIX['FRQ']);
}
