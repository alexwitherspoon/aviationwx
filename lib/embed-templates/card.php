<?php
/**
 * Card Widget Style Template
 * 
 * Mini card showing essential weather data (350x350)
 * Prioritizes pilot-critical information based on data availability
 */

require_once __DIR__ . '/shared.php';

/**
 * Process card widget data - shared by HTML and PNG renderers
 * 
 * @param array $data Widget data
 * @param array $options Widget options
 * @return array Processed data ready for rendering
 */
function processCardWidgetData($data, $options) {
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
    
    // Process data
    // Get airport name - check multiple possible fields
    $airportName = $airport['name'] ?? '';
    if (empty($airportName)) {
        // Fallback: try to get from identifier if name is missing
        $airportName = 'Unknown Airport';
    }
    $primaryIdentifier = $options['primaryIdentifier'] ?? strtoupper($airportId);
    
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
    
    // Weather values (prioritized by pilot feedback)
    $temperature = $weather['temperature_f'] ?? $weather['temperature'] ?? null;
    $dewpoint = $weather['dewpoint_f'] ?? $weather['dewpoint'] ?? null;
    $densityAltitude = $weather['density_altitude'] ?? null;
    $pressure = $weather['pressure_inhg'] ?? $weather['pressure'] ?? null;
    $visibility = $weather['visibility'] ?? null;
    $ceiling = $weather['ceiling'] ?? null;
    $humidity = $weather['humidity'] ?? null;
    $windDirection = $weather['wind_direction'] ?? null;
    $windSpeed = $weather['wind_speed'] ?? null;
    $gustSpeed = $weather['gust_speed'] ?? null;
    $isVRB = ($weather['wind_direction_text'] ?? '') === 'VRB';
    
    // Calculate dewpoint spread if we have both values
    $dewpointSpread = null;
    if ($temperature !== null && $dewpoint !== null) {
        $dewpointSpread = $temperature - $dewpoint;
    }
    
    // Format weather display - ensure '---' for missing values (not '--')
    $tempDisplay = formatEmbedTemp($temperature, $tempUnit);
    if ($tempDisplay === '--') $tempDisplay = '---';
    
    $dewpointDisplay = formatEmbedTemp($dewpoint, $tempUnit);
    if ($dewpointDisplay === '--') $dewpointDisplay = '---';
    
    $densityAltitudeDisplay = formatEmbedDist($densityAltitude, $distUnit, true);
    if ($densityAltitudeDisplay === '--') $densityAltitudeDisplay = '---';
    
    $pressureDisplay = formatEmbedPressure($pressure, $baroUnit);
    if ($pressureDisplay === '--') $pressureDisplay = '---';
    $windTextDisplay = formatEmbedWind($windDirection, $windSpeed, $gustSpeed, $windUnit);
    
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
    
    // Dewpoint spread display
    $spreadDisplay = '---';
    if ($dewpointSpread !== null) {
        $spreadDisplay = round($dewpointSpread) . '°' . $tempUnit;
    }
    
    // Visibility display
    $visDisplay = '---';
    if ($visibility !== null) {
        $visDisplay = $visibility >= 10 ? '10+' : round($visibility, 1);
        $visDisplay .= ' SM';
    }
    
    // Ceiling display (only for METAR)
    $ceilingDisplay = null;
    if ($hasMetarData && $ceiling !== null) {
        $ceilingFt = round($ceiling);
        $ceilingDisplay = formatEmbedDist($ceilingFt, $distUnit, false);
        // Ensure '---' instead of '--' for consistency
        if ($ceilingDisplay === '--') {
            $ceilingDisplay = '---';
        }
    }
    
    // Humidity display removed - limiting to 6 cards max
    // Cards: Temp, Dewpt, DA, Ceiling/Spread, Press, Vis (6 total)
    
    // Determine second metric in second row (ceiling or spread)
    // Use ceiling if available and not '--' or '---', otherwise use spread
    if ($ceilingDisplay !== null && $ceilingDisplay !== '--' && $ceilingDisplay !== '---') {
        $secondMetricLabel = 'Ceiling';
        $secondMetricValue = $ceilingDisplay;
    } else {
        $secondMetricLabel = 'Spread';
        $secondMetricValue = $spreadDisplay;
    }
    
    // Ensure second metric value is never empty - use '---' for missing data
    if (empty($secondMetricValue) || $secondMetricValue === '--') {
        $secondMetricValue = '---';
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
        // For PWS-only sites, only show emojis for available data (no ceiling/visibility)
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
        'hasMetarData' => $hasMetarData,
        'flightCategory' => $flightCategory,
        'flightCategoryData' => $flightCategoryData,
        'weatherEmojis' => $weatherEmojis,
        'temperature' => $temperature,
        'dewpoint' => $dewpoint,
        'densityAltitude' => $densityAltitude,
        'pressure' => $pressure,
        'visibility' => $visibility,
        'ceiling' => $ceiling,
        'windDirection' => $windDirection,
        'windSpeed' => $windSpeed,
        'gustSpeed' => $gustSpeed,
        'isVRB' => $isVRB,
        'dewpointSpread' => $dewpointSpread,
        'tempDisplay' => $tempDisplay,
        'dewpointDisplay' => $dewpointDisplay,
        'densityAltitudeDisplay' => $densityAltitudeDisplay,
        'pressureDisplay' => $pressureDisplay,
        'windTextDisplay' => $windTextDisplay,
        'windSpeedValue' => $windSpeedValue,
        'windDirValue' => $windDirValue,
        'gustValue' => $gustValue,
        'spreadDisplay' => $spreadDisplay,
        'visDisplay' => $visDisplay,
        'ceilingDisplay' => $ceilingDisplay,
        'secondMetricLabel' => $secondMetricLabel,
        'secondMetricValue' => $secondMetricValue,
        'lastUpdated' => $lastUpdated,
        'timezone' => $timezone,
        'dataSource' => $dataSource,
        'runways' => $runways,
        'isDark' => $isDark,
    ];
}

/**
 * Render card style widget
 * 
 * @param array $data Widget data
 * @param array $options Widget options
 * @return string HTML output
 */
function renderCardWidget($data, $options) {
    // Process data using shared function
    $processed = processCardWidgetData($data, $options);
    
    // Extract processed values
    $airportName = htmlspecialchars($processed['airportName']);
    $primaryIdentifier = htmlspecialchars($processed['primaryIdentifier']);
    $hasMetarData = $processed['hasMetarData'];
    $flightCategory = $processed['flightCategory'];
    $flightCategoryData = $processed['flightCategoryData'];
    $weatherEmojis = $processed['weatherEmojis'];
    $windSpeed = $processed['windSpeed'];
    $windDirection = $processed['windDirection'];
    $isVRB = $processed['isVRB'];
    $windSpeedValue = $processed['windSpeedValue'];
    $windDirValue = $processed['windDirValue'];
    $gustValue = $processed['gustValue'];
    $tempDisplay = $processed['tempDisplay'];
    $dewpointDisplay = $processed['dewpointDisplay'];
    $densityAltitudeDisplay = $processed['densityAltitudeDisplay'];
    $secondMetricLabel = $processed['secondMetricLabel'];
    $secondMetricValue = $processed['secondMetricValue'];
    $pressureDisplay = $processed['pressureDisplay'];
    $visDisplay = $processed['visDisplay'];
    $lastUpdated = $processed['lastUpdated'];
    $timezone = $processed['timezone'];
    $dataSource = $processed['dataSource'];
    $runways = $processed['runways'];
    $isDark = $processed['isDark'];
    
    // Extract options for HTML-specific needs
    $dashboardUrl = $options['dashboardUrl'];
    $target = $options['target'];
    $theme = $options['theme'];
    $windUnit = $options['windUnit']; // Needed for wind compass script
    
    $sourceAttribution = ' & ' . htmlspecialchars($dataSource);
    $canvasId = 'card-wind-canvas-' . uniqid();
    
    // Build HTML
    $html = <<<HTML
<div class="style-card">
    <div class="card-header-v2">
        <div class="airport-title">
            <span class="identifier">{$primaryIdentifier}</span>
            <span class="name">{$airportName}</span>
        </div>
HTML;
    
    // Flight category badge with emojis (using processed data)
    // Always show badge if we have METAR data or emojis
    if ($hasMetarData && $flightCategory) {
        $fcClass = $flightCategoryData['class'];
        $fcText = htmlspecialchars($flightCategoryData['text']);
        $emojiDisplay = $weatherEmojis ? ' ' . htmlspecialchars($weatherEmojis) : '';
        $html .= "\n        <span class=\"flight-category-badge {$fcClass}\">{$fcText}{$emojiDisplay}</span>";
    } else if ($hasMetarData && !$flightCategory) {
        // METAR data but couldn't calculate category - show with emojis
        $emojiDisplay = $weatherEmojis ? ' ' . htmlspecialchars($weatherEmojis) : '';
        $html .= "\n        <span class=\"flight-category-badge no-category\">METAR{$emojiDisplay}</span>";
    } else if (!$hasMetarData && $weatherEmojis) {
        // For PWS sites, show emojis even without flight category
        $html .= "\n        <span class=\"flight-category-badge no-category\">" . htmlspecialchars($weatherEmojis) . "</span>";
    }
    
    $html .= <<<HTML

    </div>
    
    <div class="card-compass-section">
        <div class="compass-side">
            <canvas id="{$canvasId}" width="140" height="140"></canvas>
        </div>
        <div class="wind-details-side">
HTML;
    
    // Wind Speed row (using processed value)
    $html .= <<<HTML

            <div class="wind-detail-row">
                <span class="wind-label">Wind Speed:</span>
                <span class="wind-value">{$windSpeedValue}</span>
            </div>
HTML;
    
    // Wind Direction row (using processed value)
    $html .= <<<HTML

            <div class="wind-detail-row">
                <span class="wind-label">Wind Direction:</span>
                <span class="wind-value">{$windDirValue}</span>
            </div>
HTML;
    
    // Gusting row (using processed value)
    $html .= <<<HTML

            <div class="wind-detail-row">
                <span class="wind-label">Gusting:</span>
                <span class="wind-value">{$gustValue}</span>
            </div>
HTML;
    
    $html .= <<<HTML

        </div>
    </div>
    
    <div class="card-metrics">
        <div class="metric-row">
            <div class="metric">
                <span class="metric-label">Temp</span>
                <span class="metric-value">{$tempDisplay}</span>
            </div>
            <div class="metric">
                <span class="metric-label">Dewpt</span>
                <span class="metric-value">{$dewpointDisplay}</span>
            </div>
        </div>
        <div class="metric-row">
            <div class="metric">
                <span class="metric-label">DA</span>
                <span class="metric-value">{$densityAltitudeDisplay}</span>
            </div>
            <div class="metric">
                <span class="metric-label">{$secondMetricLabel}</span>
                <span class="metric-value">{$secondMetricValue}</span>
            </div>
        </div>
        <div class="metric-row">
            <div class="metric">
                <span class="metric-label">Press</span>
                <span class="metric-value">{$pressureDisplay}</span>
            </div>
            <div class="metric">
                <span class="metric-label">Vis</span>
                <span class="metric-value">{$visDisplay}</span>
            </div>
        </div>
HTML;
    
    // Removed humidity row to limit to maximum 6 cards
    // Cards: Temp, Dewpt, DA, Ceiling/Spread, Press, Vis (6 total)
    
    $html .= "\n    </div>\n";
    
    // Add footer
    $html .= renderEmbedFooter($lastUpdated, $timezone, $sourceAttribution);
    
    $html .= "\n</div>\n";
    
    // Add wind compass script (larger size for side-by-side layout)
    $html .= renderWindCompassScript($canvasId, $windSpeed, $windDirection, $isVRB, $runways, $isDark, 140);
    
    return $html;
}
