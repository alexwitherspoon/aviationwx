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
    
    // Weather values
    $windDirection = $weather['wind_direction'] ?? null;
    $windSpeed = $weather['wind_speed'] ?? null;
    $gustSpeed = $weather['gust_speed'] ?? null;
    $isVRB = ($weather['wind_direction_text'] ?? '') === 'VRB';
    $temperature = $weather['temperature_f'] ?? $weather['temperature'] ?? null;
    $dewpoint = $weather['dewpoint_f'] ?? $weather['dewpoint'] ?? null;
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
    
    // Runway data for wind compass
    $runways = $airport['runways'] ?? [];
    
    // For dark mode detection: 'dark' = true, 'light' = false, 'auto' = null (JS will detect)
    $isDark = ($theme === 'dark') ? true : (($theme === 'light') ? false : null);
    
    // Weather emojis
    $weatherEmojis = '';
    if ($hasMetarData) {
        $weatherEmojis = getWeatherEmojis($weather);
    } else {
        // For PWS-only sites, only show emojis for available data
        $pwsWeather = [
            'temperature_f' => $temperature,
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
 * Render webcam style widget
 * 
 * @param array $data Widget data
 * @param array $options Widget options
 * @return string HTML output
 */
function renderWebcamWidget($data, $options) {
    // Process data using shared function
    $processed = processWebcamWidgetData($data, $options);
    
    // Extract processed values
    $airportName = htmlspecialchars($processed['airportName']);
    $primaryIdentifier = htmlspecialchars($processed['primaryIdentifier']);
    $webcamCount = $processed['webcamCount'];
    $webcamIndex = $processed['webcamIndex'];
    $hasMetarData = $processed['hasMetarData'];
    $flightCategory = $processed['flightCategory'];
    $flightCategoryData = $processed['flightCategoryData'];
    $weatherEmojis = $processed['weatherEmojis'];
    $windDisplay = $processed['windDisplay'];
    $windSpeedValue = $processed['windSpeedValue'];
    $windDirValue = $processed['windDirValue'];
    $gustValue = $processed['gustValue'];
    $metrics = $processed['metrics'];
    $windSpeed = $processed['windSpeed'];
    $windDirection = $processed['windDirection'];
    $isVRB = $processed['isVRB'];
    $lastUpdated = $processed['lastUpdated'];
    $timezone = $processed['timezone'];
    $dataSource = $processed['dataSource'];
    $runways = $processed['runways'];
    $isDark = $processed['isDark'];
    $aspectRatio = $processed['aspectRatio'];
    $webcamWidth = $processed['webcamWidth'];
    $webcamHeight = $processed['webcamHeight'];
    
    // Extract options for HTML-specific needs
    $dashboardUrl = $options['dashboardUrl'];
    $target = $options['target'];
    $theme = $options['theme'];
    $airportId = $data['airportId'];
    
    // Webcam data
    $webcamUrl = $webcamCount > 0 ? buildEmbedWebcamUrl($dashboardUrl, $airportId, $webcamIndex) : null;
    
    $sourceAttribution = ' & ' . htmlspecialchars($dataSource);
    
    // Use CSS aspect-ratio property for responsive scaling (more reliable than padding-bottom)
    // Validate aspect ratio: must be positive, finite number, and reasonable (between 0.1 and 10)
    $aspectRatioCss = 1.777; // Default 16:9
    if ($aspectRatio > 0 && is_finite($aspectRatio) && $aspectRatio >= 0.1 && $aspectRatio <= 10) {
        $aspectRatioCss = round($aspectRatio, 6);
    }
    
    // Build HTML - use aspect ratio on image itself for proper scaling
    $html = <<<HTML
<div class="style-webcam">
    <div class="webcam-container">
HTML;
    
    if ($webcamUrl) {
        // Use responsive picture element with srcset
        $html .= buildEmbedWebcamPicture($dashboardUrl, $airportId, $webcamIndex, $aspectRatioCss, "{$primaryIdentifier} Webcam", 'webcam-image');
    } else {
        $html .= "\n        <div class=\"no-webcam-placeholder\">No webcam available</div>";
    }
    
    $html .= <<<HTML

        <div class="overlay-info">
            <div class="overlay-row">
                <div class="overlay-left">
                    <div class="overlay-airport">
                        <span class="code">{$primaryIdentifier}</span>
                        <span class="name">{$airportName}</span>
                    </div>
                </div>
                <div class="overlay-right">
HTML;
    
    // Flight category badge with emojis (matching card style) - positioned in lower right
    if ($hasMetarData && $flightCategory) {
        $fcClass = $flightCategoryData['class'];
        $fcText = htmlspecialchars($flightCategoryData['text']);
        $emojiDisplay = $weatherEmojis ? ' ' . htmlspecialchars($weatherEmojis) : '';
        $html .= "\n                    <span class=\"flight-category-badge {$fcClass}\">{$fcText}{$emojiDisplay}</span>";
    } else if ($hasMetarData && !$flightCategory) {
        // METAR data but couldn't calculate category - show with emojis
        $emojiDisplay = $weatherEmojis ? ' ' . htmlspecialchars($weatherEmojis) : '';
        $html .= "\n                    <span class=\"flight-category-badge no-category\">METAR{$emojiDisplay}</span>";
    } else if (!$hasMetarData && $weatherEmojis) {
        // For PWS sites, show emojis even without flight category
        $html .= "\n                    <span class=\"flight-category-badge no-category\">" . htmlspecialchars($weatherEmojis) . "</span>";
    }
    
    $html .= <<<HTML
                </div>
            </div>
        </div>
    </div>
    
    <!-- Vertical layout: webcam image stacked above metrics and footer -->
    <div class="webcam-content-wrapper">
        <div class="webcam-metrics">
HTML;
    
    // Render metrics in rows of 2 (will be 3 columns on wider views via CSS)
    for ($i = 0; $i < count($metrics); $i += 2) {
        $metric1 = $metrics[$i];
        $metric2 = $metrics[$i + 1] ?? ['label' => '---', 'value' => '---'];
        
        $label1 = htmlspecialchars($metric1['label']);
        $value1 = htmlspecialchars($metric1['value']);
        $label2 = htmlspecialchars($metric2['label']);
        $value2 = htmlspecialchars($metric2['value']);
        
        $html .= <<<HTML
            <div class="metric-row">
                <div class="metric">
                    <span class="metric-label">{$label1}</span>
                    <span class="metric-value">{$value1}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">{$label2}</span>
                    <span class="metric-value">{$value2}</span>
                </div>
            </div>
HTML;
    }
    
    $html .= <<<HTML
        </div>
HTML;
    
    // Add footer inside content wrapper
    $html .= renderEmbedFooter($lastUpdated, $timezone, $sourceAttribution);
    
    $html .= <<<HTML

    </div>
</div>

HTML;
    
    return $html;
}
