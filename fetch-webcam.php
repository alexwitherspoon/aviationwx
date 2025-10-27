<?php
/**
 * Webcam Image Fetcher (for cron job)
 * This should be run via cron to cache webcam images
 */

// Load config
$configFile = __DIR__ . '/airports.json';
$config = json_decode(file_get_contents($configFile), true);

foreach ($config['airports'] as $airportId => $airport) {
    if (!isset($airport['webcams'])) continue;
    
    foreach ($airport['webcams'] as $index => $cam) {
        $cacheDir = __DIR__ . '/cache/webcams';
        $cacheFile = $cacheDir . '/' . $airportId . '_' . $index . '.jpg';
        
        echo "Fetching {$cam['name']}...\n";
        
        // Set up context with timeout and user agent
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,  // 5 second timeout
                'user_agent' => 'AviationWX Webcam Bot',
                'follow_location' => true,
                'ignore_errors' => true
            ]
        ]);
        
        // Try to fetch with timeout protection
        $startTime = time();
        $imageData = @file_get_contents($cam['url'], false, $context);
        $fetchTime = time() - $startTime;
        
        if ($imageData !== false && strlen($imageData) > 0) {
            file_put_contents($cacheFile, $imageData);
            echo "✓ Saved to {$cacheFile} ({$fetchTime}s)\n";
        } else {
            echo "✗ Failed to fetch from {$cam['url']} after {$fetchTime}s\n";
            // Keep stale cache if it exists
            if (file_exists($cacheFile)) {
                echo "  (Keeping existing cache)\n";
            }
        }
    }
}

echo "\nDone!\n";

