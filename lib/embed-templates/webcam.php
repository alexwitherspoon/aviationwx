<?php
/**
 * Webcam Widget Style Template
 * 
 * Single webcam with weather bar (450x450)
 * Compact single style - follows same patterns as card style
 */

require_once __DIR__ . '/shared.php';
require_once __DIR__ . '/../webcam-metadata.php';

/**
 * Process webcam widget data - shared pattern with card style
 * 
 * @param array $data Widget data
 * @param array $options Widget options
 * @return array Processed data ready for rendering
 */
function processWebcamWidgetData($data, $options) {
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
    $webcamIndex = $options['webcamIndex'] ?? 0;
    
    // Get webcam metadata from APCu (includes aspect ratio)
    $webcamMetadata = getWebcamMetadata($airportId, $webcamIndex);
    
    // If metadata is missing, try to build it on-the-fly from any available image file
    if ($webcamMetadata === null) {
        require_once __DIR__ . '/../cache-paths.php';
        require_once __DIR__ . '/../webcam-format-generation.php';
        
        // Try to find any image file in the cache directory
        $cacheDir = getWebcamCameraDir($airportId, $webcamIndex);
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
                    $webcamMetadata = buildWebcamMetadataFromFile($latestFile, $airportId, $webcamIndex);
                    // Store in APCu for future use
                    if ($webcamMetadata !== null && function_exists('apcu_store')) {
                        $key = "webcam_meta_{$airportId}_{$webcamIndex}";
                        @apcu_store($key, $webcamMetadata, 86400); // 24h TTL
                    }
                }
            }
        }
    }
    
    $aspectRatio = $webcamMetadata ? ($webcamMetadata['aspect_ratio'] ?? 1.777) : 1.777; // Default 16:9
    $webcamWidth = $webcamMetadata ? ($webcamMetadata['width'] ?? null) : null;
    $webcamHeight = $webcamMetadata ? ($webcamMetadata['height'] ?? null) : null;
    
    // Process data
    // Get airport name - check multiple possible fields
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
    
    // Calculate dewpoint spread if we have both values
    $dewpointSpread = null;
    if ($temperature !== null && $dewpoint !== null) {
        $dewpointSpread = $temperature - $dewpoint;
    }
    
    // Format weather display - ensure '---' for missing values
    $windDisplay = formatEmbedWind($windDirection, $windSpeed, $gustSpeed, $windUnit);
    
    // Get top 6 metrics based on priority and availability
    $metrics = getCompactWidgetMetrics($weather, $options, $hasMetarData);
    
    // Wind speed value
    $windSpeedValue = ($windSpeed !== null && $windSpeed >= 3) ? formatEmbedWindSpeed($windSpeed, $windUnit) : 'Calm';
    
    // Wind direction value
    $windDirValue = '---';
    if ($windDirection !== null && $windSpeed >= 3 && !$isVRB) {
        $windDirValue = round($windDirection) . '°';
    } elseif ($isVRB) {
        $windDirValue = 'Variable';
    }
    
    // Gust value
    $gustValue = '---';
    if ($gustSpeed !== null && $gustSpeed > 0) {
        $gustValue = formatEmbedWindSpeed($gustSpeed, $windUnit);
    }
    
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
    
    return [
        'airportName' => $airportName,
        'primaryIdentifier' => $primaryIdentifier,
        'webcamCount' => $webcamCount,
        'webcamIndex' => $webcamIndex,
        'hasMetarData' => $hasMetarData,
        'flightCategory' => $flightCategory,
        'flightCategoryData' => $flightCategoryData,
        'weatherEmojis' => $weatherEmojis,
        'windDisplay' => $windDisplay,
        'windSpeedValue' => $windSpeedValue,
        'windDirValue' => $windDirValue,
        'gustValue' => $gustValue,
        'metrics' => $metrics,
        'windDirection' => $windDirection,
        'windSpeed' => $windSpeed,
        'gustSpeed' => $gustSpeed,
        'isVRB' => $isVRB,
        'lastUpdated' => $lastUpdated,
        'timezone' => $timezone,
        'dataSource' => $dataSource,
        'runways' => $runways,
        'isDark' => $isDark,
        'aspectRatio' => $aspectRatio,
        'webcamWidth' => $webcamWidth,
        'webcamHeight' => $webcamHeight,
    ];
}

/**
 * Render webcam-only style widget (single webcam, no weather)
 *
 * Compact single layout with webcam and footer only.
 *
 * @param array $data Widget data
 * @param array $options Widget options
 * @return string HTML output
 */
function renderWebcamOnlyWidget($data, $options) {
    $processed = processWebcamWidgetData($data, $options);
    $primaryIdentifier = htmlspecialchars($processed['primaryIdentifier']);
    $webcamCount = $processed['webcamCount'];
    $webcamIndex = $processed['webcamIndex'];
    $lastUpdated = $processed['lastUpdated'];
    $timezone = $processed['timezone'];
    $dataSource = $processed['dataSource'];
    $aspectRatio = $processed['aspectRatio'];
    $dashboardUrl = $options['dashboardUrl'];
    $target = $options['target'] ?? '_blank';
    $linkAttrs = buildEmbedLinkAttrs($target);
    $airportId = $data['airportId'];
    $webcamUrl = $webcamCount > 0 ? buildEmbedWebcamUrl($dashboardUrl, $airportId, $webcamIndex) : null;
    $sourceAttribution = ' & ' . htmlspecialchars($dataSource);
    $aspectRatioCss = ($aspectRatio > 0 && is_finite($aspectRatio) && $aspectRatio >= 0.1 && $aspectRatio <= 10)
        ? round($aspectRatio, 6) : 1.777;

    $html = '<div class="style-webcam style-webcam-only">';
    $historyPlayerUrl = buildHistoryPlayerUrl($dashboardUrl, $webcamIndex);
    $html .= '<a href="' . htmlspecialchars($historyPlayerUrl) . '" class="embed-webcam-link"' . $linkAttrs . '>';
    $html .= '<div class="webcam-container">';
    if ($webcamUrl) {
        $html .= buildEmbedWebcamPicture($dashboardUrl, $airportId, $webcamIndex, $aspectRatioCss, "{$primaryIdentifier} Webcam", 'webcam-image');
    } else {
        $html .= '<div class="no-webcam-placeholder">No webcam available</div>';
    }
    $html .= '</div>';
    $html .= '</a>';
    $html .= '<a href="' . htmlspecialchars($dashboardUrl) . '" class="embed-dashboard-link webcam-only-footer-wrapper"' . $linkAttrs . '>';
    $html .= renderEmbedFooter($lastUpdated, $timezone, $sourceAttribution);
    $html .= '</a>';
    $html .= '</div>';

    return $html;
}
