<?php
/**
 * Generate Test Metrics Data
 * 
 * Creates sample metrics data for testing the status page visualization.
 * Run via: make metrics-test
 * 
 * Note: APCu is not available in CLI mode, so this script writes directly
 * to the hourly JSON files.
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/metrics.php';

// Get configured airports
$config = loadConfig();
$airports = array_keys($config['airports'] ?? []);

if (empty($airports)) {
    echo "No airports configured!\n";
    exit(1);
}

echo "Generating test metrics for " . count($airports) . " airports...\n";

// Create test data structure directly
$now = time();
$hourId = metrics_get_hour_id($now);

$hourData = [
    'bucket_type' => 'hourly',
    'bucket_id' => $hourId,
    'bucket_start' => strtotime(gmdate('Y-m-d H:00:00', $now) . ' UTC'),
    'bucket_end' => strtotime(gmdate('Y-m-d H:00:00', $now) . ' UTC') + 3600,
    'airports' => [],
    'webcams' => [],
    'global' => [
        'page_views' => 0,
        'weather_requests' => 0,
        'webcam_serves' => 0,
        'format_served' => ['jpg' => 0, 'webp' => 0, 'avif' => 0],
        'size_served' => ['thumb' => 0, 'small' => 0, 'medium' => 0, 'large' => 0, 'primary' => 0, 'full' => 0],
        'browser_support' => ['avif' => 0, 'webp' => 0, 'jpg_only' => 0],
        'cache' => ['hits' => 0, 'misses' => 0]
    ],
    'last_flush' => $now
];

// Generate test data for all airports
foreach ($airports as $airportId) {
    $airportId = strtolower($airportId);
    $airportConfig = $config['airports'][strtoupper($airportId)] ?? $config['airports'][$airportId] ?? null;
    
    // Random but realistic values
    $views = rand(3, 30);
    $weather = rand(10, 100);
    
    $hourData['airports'][$airportId] = [
        'page_views' => $views,
        'weather_requests' => $weather
    ];
    
    $hourData['global']['page_views'] += $views;
    $hourData['global']['weather_requests'] += $weather;
    
    // Generate webcam data if the airport has webcams
    $webcams = $airportConfig['webcams'] ?? [];
    foreach ($webcams as $camIndex => $cam) {
        // Simulate realistic format distribution (WebP most popular, AVIF growing)
        $jpg = rand(10, 40);
        $webp = rand(40, 120);
        $avif = rand(5, 25);
        $webcamTotal = $jpg + $webp + $avif;
        
        // Simulate realistic size distribution (primary most common)
        $thumb = rand(5, 20);
        $small = rand(10, 30);
        $medium = rand(15, 35);
        $large = rand(5, 15);
        $primary = rand(40, 100);
        $full = rand(2, 10);
        
        $hourData['webcams'][$airportId . '_' . $camIndex] = [
            'by_format' => [
                'jpg' => $jpg,
                'webp' => $webp,
                'avif' => $avif
            ],
            'by_size' => [
                'thumb' => $thumb,
                'small' => $small,
                'medium' => $medium,
                'large' => $large,
                'primary' => $primary,
                'full' => $full
            ]
        ];
        
        $hourData['global']['webcam_serves'] += $webcamTotal;
        $hourData['global']['format_served']['jpg'] += $jpg;
        $hourData['global']['format_served']['webp'] += $webp;
        $hourData['global']['format_served']['avif'] += $avif;
        $hourData['global']['size_served']['thumb'] += $thumb;
        $hourData['global']['size_served']['small'] += $small;
        $hourData['global']['size_served']['medium'] += $medium;
        $hourData['global']['size_served']['large'] += $large;
        $hourData['global']['size_served']['primary'] += $primary;
        $hourData['global']['size_served']['full'] += $full;
    }
    
    echo "  - $airportId: $views views, $weather weather, " . count($webcams) . " webcam(s)\n";
}

// Ensure directories exist
ensureCacheDir(CACHE_METRICS_DIR);
ensureCacheDir(CACHE_METRICS_HOURLY_DIR);
ensureCacheDir(CACHE_METRICS_DAILY_DIR);
ensureCacheDir(CACHE_METRICS_WEEKLY_DIR);

// Write the file
$hourFile = getMetricsHourlyPath($hourId);
file_put_contents($hourFile, json_encode($hourData, JSON_PRETTY_PRINT));

echo "\nCreated: $hourFile\n";
echo "\nGlobal totals:\n";
echo "  - Page views: " . $hourData['global']['page_views'] . "\n";
echo "  - Weather requests: " . $hourData['global']['weather_requests'] . "\n";
echo "  - Webcam serves: " . $hourData['global']['webcam_serves'] . "\n";
echo "  - Formats: JPG " . $hourData['global']['format_served']['jpg'];
echo ", WebP " . $hourData['global']['format_served']['webp'];
echo ", AVIF " . $hourData['global']['format_served']['avif'] . "\n";

