<?php

/**
 * Canonical OurAirports bulk CSV URLs and file keys.
 */

require_once __DIR__ . '/../cache-paths.php';

/** @var array<string, string> */
const OURAIRPORTS_CSV_FILE_KEYS = [
    'airports' => 'airports.csv',
    'runways' => 'runways.csv',
    'airport_frequencies' => 'airport-frequencies.csv',
];

const OURAIRPORTS_DATA_BASE_URL = 'https://davidmegginson.github.io/ourairports-data';

const FAA_NGDA_RUNWAYS_CSV_URL = 'https://ngda-transportation-geoplatform.hub.arcgis.com/api/download/v1/items/110af7b8a9424a59a3fb1d8fc69a2172/csv?layers=0';

/**
 * Return supported OurAirports CSV file keys.
 *
 * @return list<string>
 */
function ourAirportsCsvFileKeys(): array
{
    return array_keys(OURAIRPORTS_CSV_FILE_KEYS);
}

/**
 * Validate a file key.
 */
function ourAirportsIsValidFileKey(string $fileKey): bool
{
    return isset(OURAIRPORTS_CSV_FILE_KEYS[$fileKey]);
}

/**
 * Local filename for a file key.
 */
function ourAirportsCsvFilename(string $fileKey): string
{
    if (!ourAirportsIsValidFileKey($fileKey)) {
        throw new InvalidArgumentException('Invalid OurAirports file key: ' . $fileKey);
    }

    return OURAIRPORTS_CSV_FILE_KEYS[$fileKey];
}

/**
 * Canonical download URL for a file key.
 */
function ourAirportsCsvUrl(string $fileKey): string
{
    return OURAIRPORTS_DATA_BASE_URL . '/' . ourAirportsCsvFilename($fileKey);
}

/**
 * Absolute path to a raw OurAirports CSV on disk.
 */
function ourAirportsCsvPath(string $fileKey): string
{
    return CACHE_OURAIRPORTS_DIR . '/' . ourAirportsCsvFilename($fileKey);
}
