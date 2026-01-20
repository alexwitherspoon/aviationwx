#!/usr/bin/env php
<?php
/**
 * Convert airports.json from old format to unified weather_sources array format
 * 
 * Usage: php convert-config-to-unified-sources.php <input-file> <output-file>
 * 
 * Converts:
 * - weather_source -> weather_sources[0]
 * - weather_source_backup -> weather_sources[n] with backup: true
 * - metar_station -> weather_sources[n] with type: metar
 * - nearby_metar_stations -> included in metar source as nearby_stations
 */

if ($argc < 3) {
    echo "Usage: php convert-config-to-unified-sources.php <input-file> <output-file>\n";
    exit(1);
}

$inputFile = $argv[1];
$outputFile = $argv[2];

if (!file_exists($inputFile)) {
    echo "Error: Input file not found: $inputFile\n";
    exit(1);
}

$json = file_get_contents($inputFile);
$config = json_decode($json, true);

if ($config === null) {
    echo "Error: Invalid JSON in input file\n";
    exit(1);
}

$convertedCount = 0;
$skippedCount = 0;

foreach ($config['airports'] as $airportId => &$airport) {
    // Skip if already has weather_sources array with content
    if (isset($airport['weather_sources']) && !empty($airport['weather_sources'])) {
        echo "  [SKIP] $airportId - already has weather_sources array\n";
        $skippedCount++;
        continue;
    }
    
    $sources = [];
    
    // Convert weather_source to first source
    if (isset($airport['weather_source']) && !empty($airport['weather_source']['type'])) {
        $source = $airport['weather_source'];
        $sources[] = $source;
        unset($airport['weather_source']);
    }
    
    // Convert weather_source_backup to backup source
    if (isset($airport['weather_source_backup']) && !empty($airport['weather_source_backup']['type'])) {
        $backupSource = $airport['weather_source_backup'];
        $backupSource['backup'] = true;
        $sources[] = $backupSource;
        unset($airport['weather_source_backup']);
    }
    
    // Convert metar_station to METAR source
    if (isset($airport['metar_station']) && !empty($airport['metar_station'])) {
        $metarSource = [
            'type' => 'metar',
            'station_id' => $airport['metar_station']
        ];
        
        // Include nearby_metar_stations if configured
        if (isset($airport['nearby_metar_stations']) && !empty($airport['nearby_metar_stations'])) {
            $metarSource['nearby_stations'] = $airport['nearby_metar_stations'];
            unset($airport['nearby_metar_stations']);
        }
        
        // Check if we already have a metar source (from weather_source)
        $hasMetar = false;
        foreach ($sources as $s) {
            if (($s['type'] ?? '') === 'metar') {
                $hasMetar = true;
                break;
            }
        }
        
        if (!$hasMetar) {
            $sources[] = $metarSource;
        }
        
        unset($airport['metar_station']);
    }
    
    // Only add weather_sources array if we have sources
    if (!empty($sources)) {
        $airport['weather_sources'] = $sources;
        echo "  [OK] $airportId - converted " . count($sources) . " source(s)\n";
        $convertedCount++;
    } else {
        echo "  [WARN] $airportId - no weather sources configured\n";
    }
}

// Pretty print the JSON output
$outputJson = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Fix indentation (use 2 spaces instead of 4)
$outputJson = preg_replace_callback('/^( +)/m', function($matches) {
    return str_repeat('  ', strlen($matches[1]) / 4);
}, $outputJson);

file_put_contents($outputFile, $outputJson . "\n");

echo "\nConversion complete!\n";
echo "  Converted: $convertedCount airports\n";
echo "  Skipped: $skippedCount airports\n";
echo "  Output: $outputFile\n";
