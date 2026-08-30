<?php
/**
 * NASR subscription zip extraction (allowlisted CSV entries only).
 */

/**
 * Extract only NASR APT CSV files from a zip, rejecting path traversal entries.
 *
 * @param ZipArchive $zip Open archive
 * @param string $extractDir Destination directory (flat; no subpaths)
 * @return bool True when APT_BASE, APT_RWY, and APT_RWY_END were all written
 */
function nasrExtractAllowlistedAptCsvFromZip(ZipArchive $zip, string $extractDir): bool
{
    $allowed = ['APT_BASE.csv', 'APT_RWY.csv', 'APT_RWY_END.csv', 'APT_RMK.csv'];
    $written = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if (!is_string($entry) || $entry === '') {
            continue;
        }
        $normalized = str_replace('\\', '/', $entry);
        if (str_starts_with($normalized, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
            continue;
        }
        $base = basename($normalized);
        if (!in_array($base, $allowed, true)) {
            continue;
        }

        $stream = $zip->getStream($entry);
        if ($stream === false) {
            return false;
        }
        $dest = $extractDir . '/' . $base;
        $destHandle = @fopen($dest, 'wb');
        if ($destHandle === false) {
            fclose($stream);
            return false;
        }
        $copied = stream_copy_to_stream($stream, $destHandle);
        fclose($stream);
        fclose($destHandle);
        if ($copied === false) {
            return false;
        }
        $written[$base] = true;
    }

    foreach (['APT_BASE.csv', 'APT_RWY.csv', 'APT_RWY_END.csv'] as $name) {
        if (empty($written[$name])) {
            return false;
        }
    }

    return true;
}

/**
 * Extract only the NASR FRQ.csv file from a zip.
 *
 * @param ZipArchive $zip Open archive
 * @param string $extractDir Destination directory (flat; no subpaths)
 * @return bool True when FRQ.csv was written
 */
function nasrExtractAllowlistedFrqCsvFromZip(ZipArchive $zip, string $extractDir): bool
{
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!is_string($name) || basename($name) !== 'FRQ.csv') {
            continue;
        }

        $stream = $zip->getStream($name);
        if ($stream === false) {
            return false;
        }
        $dest = $extractDir . '/FRQ.csv';
        $destHandle = @fopen($dest, 'wb');
        if ($destHandle === false) {
            fclose($stream);
            return false;
        }
        $copied = stream_copy_to_stream($stream, $destHandle);
        fclose($stream);
        fclose($destHandle);
        if ($copied === false) {
            return false;
        }

        return true;
    }

    return false;
}
