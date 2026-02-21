<?php
/**
 * Multi Camera Widget Style Template
 * 
 * Four webcams in a grid with weather metrics (600x475)
 * Compact quad style - matches Compact Single styling
 */

require_once __DIR__ . '/shared.php';
require_once __DIR__ . '/../webcam-metadata.php';

/**
 * Process multi widget data - similar to processWebcamWidgetData but for four webcams
 * 
 * @param array $data Widget data
 * @param array $options Widget options
 * @return array Processed data ready for rendering
 */
function processMultiWidgetData($data, $options) {
    // Extract data
    $airport = $data['airport'];
    $weather = $data['weather'];
    $airportId = $data['airportId'];
    
    // Extract options
    $tempUnit = $options['tempUnit'];
    $distUnit = $options['distUnit'];
    $windUnit = $options['windUnit'];
    $baroUnit = $options['baroUnit'];
    $theme = $options['theme'];
    $cams = $options['cams'] ?? [0, 1, 2, 3];
    
    // Process data
    $airportName = $airport['name'] ?? '';
    if (empty($airportName)) {
        $airportName = 'Unknown Airport';
    }
    $primaryIdentifier = $options['primaryIdentifier'] ?? strtoupper($airportId);
    $webcamCount = isset($airport['webcams']) ? count($airport['webcams']) : 0;
    
    // Check for METAR data (has visibility or ceiling)
    $hasMetarData = ($weather['visibility'] !== null) || ($weather['ceiling'] !== null);
    
    // Get or calculate flight category
    $flightCategory = $weather['flight_category'] ?? null;
    
    // If we have METAR data but no flight category, try to calculate it
    if ($hasMetarData && $flightCategory === null) {
        require_once __DIR__ . '/../weather/calculator.php';
        $flightCategory = calculateFlightCategory($weather);
    }
    
    $flightCategoryData = getFlightCategoryData($flightCategory);
    
    // Weather values (temperatures in Celsius - internal storage standard)
    $windDirection = $weather['wind_direction'] ?? null;
    $windSpeed = $weather['wind_speed'] ?? null;
    $gustSpeed = $weather['gust_speed'] ?? null;
    $isVRB = ($weather['wind_direction_text'] ?? '') === 'VRB';
    $temperature = $weather['temperature'] ?? null;
    $dewpoint = $weather['dewpoint'] ?? null;
    $pressure = $weather['pressure_inhg'] ?? $weather['pressure'] ?? null;
    $densityAltitude = $weather['density_altitude'] ?? null;
    $visibility = $weather['visibility'] ?? null;
    $ceiling = $weather['ceiling'] ?? null;
    
    // Format weather display - ensure '---' for missing values
    $windDisplay = formatEmbedWind($windDirection, $windSpeed, $gustSpeed, $windUnit);
    
    // Get top 6 metrics based on priority and availability
    $metrics = getCompactWidgetMetrics($weather, $options, $hasMetarData);
    
    // Footer data
    $lastUpdated = $weather['last_updated_primary'] ?? time();
    $timezone = $airport['timezone'] ?? 'America/Los_Angeles';
    $dataSource = getWeatherSourceAttribution($weather, $hasMetarData);
    
    // Runway data for wind compass (empty array if no runways - compass will render without runway line)
    $runways = $airport['runways'] ?? [];
    
    // For dark mode detection: 'dark' = true, 'light' = false, 'auto' = null (JS will detect)
    $isDark = ($theme === 'dark') ? true : (($theme === 'light') ? false : null);
    
    // Weather emojis
    $weatherEmojis = '';
    if ($hasMetarData) {
        $weatherEmojis = getWeatherEmojis($weather);
    } else {
        // For PWS-only sites, only show emojis for available data
        // Temperature is in Celsius (internal storage standard)
        $pwsWeather = [
            'temperature' => $temperature,
            'precip_accum' => $weather['precip_accum'] ?? 0,
            'wind_speed' => $windSpeed,
            'wind_direction' => $windDirection
        ];
        $weatherEmojis = getWeatherEmojis($pwsWeather);
    }
    
    // Process webcam metadata for all cameras (up to 4)
    $webcamData = [];
    $displayCamCount = min($webcamCount, 4);
    for ($i = 0; $i < $displayCamCount; $i++) {
        $camIdx = $cams[$i] ?? $i;
        
        // Get webcam metadata from APCu (includes aspect ratio)
        $webcamMetadata = getWebcamMetadata($airportId, $camIdx);
        
        // If metadata is missing, try to build it on-the-fly from any available image file
        if ($webcamMetadata === null) {
            require_once __DIR__ . '/../cache-paths.php';
            require_once __DIR__ . '/../webcam-format-generation.php';
            
            // Try to find any image file in the cache directory
            $cacheDir = getWebcamCameraDir($airportId, $camIdx);
            if (is_dir($cacheDir)) {
                // Look for any timestamped image file (original or variant)
                $pattern = $cacheDir . '/*_*.{jpg,jpeg,webp}';
                $files = glob($pattern, GLOB_BRACE);
                if (empty($files)) {
                    // Fallback without GLOB_BRACE
                    $files = array_merge(
                        glob($cacheDir . '/*_*.jpg'),
                        glob($cacheDir . '/*_*.jpeg'),
                        glob($cacheDir . '/*_*.webp')
                    );
                }
                
                // Sort by mtime descending to get the latest
                if (!empty($files)) {
                    usort($files, function($a, $b) {
                        return filemtime($b) - filemtime($a);
                    });
                    
                    // Try to build metadata from the latest file
                    $latestFile = $files[0];
                    if (file_exists($latestFile) && !is_link($latestFile)) {
                        $webcamMetadata = buildWebcamMetadataFromFile($latestFile, $airportId, $camIdx);
                        // Store in APCu for future use
                        if ($webcamMetadata !== null && function_exists('apcu_store')) {
                            $key = "webcam_meta_{$airportId}_{$camIdx}";
                            @apcu_store($key, $webcamMetadata, 86400); // 24h TTL
                        }
                    }
                }
            }
        }
        
        $aspectRatio = $webcamMetadata ? ($webcamMetadata['aspect_ratio'] ?? 1.777) : 1.777; // Default 16:9
        $webcamWidth = $webcamMetadata ? ($webcamMetadata['width'] ?? null) : null;
        $webcamHeight = $webcamMetadata ? ($webcamMetadata['height'] ?? null) : null;
        
        $webcamUrl = ($webcamCount > 0 && $camIdx < $webcamCount) 
            ? buildEmbedWebcamUrl($options['dashboardUrl'], $airportId, $camIdx) 
            : null;
        
        $camName = 'Camera ' . ($camIdx + 1);
        if (isset($airport['webcams'][$camIdx]['name'])) {
            $camName = $airport['webcams'][$camIdx]['name'];
        }
        
        $webcamData[] = [
            'index' => $camIdx,
            'url' => $webcamUrl,
            'name' => $camName,
            'aspectRatio' => $aspectRatio,
            'width' => $webcamWidth,
            'height' => $webcamHeight
        ];
    }
    
    return [
        'airportName' => $airportName,
        'primaryIdentifier' => $primaryIdentifier,
        'webcamCount' => $webcamCount,
        'hasMetarData' => $hasMetarData,
        'flightCategory' => $flightCategory,
        'flightCategoryData' => $flightCategoryData,
        'weatherEmojis' => $weatherEmojis,
        'metrics' => $metrics,
        'lastUpdated' => $lastUpdated,
        'timezone' => $timezone,
        'dataSource' => $dataSource,
        'runways' => $runways,
        'isDark' => $isDark,
        'webcamData' => $webcamData,
    ];
}

/**
 * Render multi webcam-only style widget (four webcams, no weather)
 *
 * Compact quad layout with webcams and footer only.
 *
 * @param array $data Widget data
 * @param array $options Widget options
 * @return string HTML output
 */
function renderMultiOnlyWidget($data, $options) {
    $processed = processMultiWidgetData($data, $options);
    $primaryIdentifier = htmlspecialchars($processed['primaryIdentifier']);
    $lastUpdated = $processed['lastUpdated'];
    $timezone = $processed['timezone'];
    $dataSource = $processed['dataSource'];
    $webcamData = $processed['webcamData'];
    $dashboardUrl = $options['dashboardUrl'];
    $airportId = $data['airportId'];
    $sourceAttribution = ' & ' . htmlspecialchars($dataSource);
    $displayCamCount = count($webcamData);
    $gridClass = 'cams-' . max(1, $displayCamCount);

    $html = '<div class="style-multi style-multi-only">';
    $html .= '<div class="multi-webcam-grid ' . $gridClass . '">';
    foreach ($webcamData as $webcam) {
        $camIdx = $webcam['index'];
        $webcamUrl = $webcam['url'];
        $aspectRatio = $webcam['aspectRatio'];
        $aspectRatioCss = ($aspectRatio > 0 && is_finite($aspectRatio) && $aspectRatio >= 0.1 && $aspectRatio <= 10)
            ? round($aspectRatio, 6) : 1.777;

        $html .= '<div class="multi-webcam-cell">';
        if ($webcamUrl) {
            $html .= buildEmbedWebcamPicture($dashboardUrl, $airportId, $camIdx, $aspectRatioCss, "{$primaryIdentifier} Webcam {$camIdx}", 'webcam-image');
        } else {
            $html .= '<div class="no-webcam-placeholder">No webcam available</div>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    $html .= '<div class="webcam-only-footer-wrapper">';
    $html .= renderEmbedFooter($lastUpdated, $timezone, $sourceAttribution);
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}
