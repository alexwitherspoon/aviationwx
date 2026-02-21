<?php
/**
 * Full Widget Style Templates
 * 
 * Full-featured widgets with detailed weather columns and large wind compass
 * Variants: full, full-single, full-dual, full-multi
 */

require_once __DIR__ . '/shared.php';

/**
 * Build column-based metrics for full widgets (matching wind column style)
 * 
 * @param array $weather Weather data
 * @param array $options Widget options
 * @param bool $hasMetarData Whether METAR data is available
 * @return string HTML for metrics columns
 */
function buildFullWidgetMetrics($weather, $options, $hasMetarData) {
    $tempUnit = $options['tempUnit'];
    $distUnit = $options['distUnit'];
    $baroUnit = $options['baroUnit'];
    
    // Extract weather values (temperatures in Celsius - internal storage standard)
    $temperature = $weather['temperature'] ?? null;
    $dewpoint = $weather['dewpoint'] ?? null;
    $dewpointSpread = ($temperature !== null && $dewpoint !== null) ? ($temperature - $dewpoint) : null;
    $pressure = $weather['pressure_inhg'] ?? $weather['pressure'] ?? null;
    $densityAltitude = $weather['density_altitude'] ?? null;
    $pressureAltitude = $weather['pressure_altitude'] ?? null;
    $visibility = $weather['visibility'] ?? null;
    $ceiling = $weather['ceiling'] ?? null;
    $humidity = $weather['humidity'] ?? null;
    $rainfallToday = $weather['rainfall_today'] ?? null;
    $tempHighToday = $weather['temp_high_today'] ?? null;
    $tempLowToday = $weather['temp_low_today'] ?? null;
    
    $html = '<div class="metrics-columns">';
    
    // Column 1: Temperature
    $html .= "\n                    <div class=\"metric-column\">";
    $html .= "\n                        <div class=\"column-header\">🌡️ Temperature</div>";
    
    // Today's High (1 decimal precision for full widgets)
    if ($tempHighToday !== null) {
        $hiDisplay = formatEmbedTemp($tempHighToday, $tempUnit, 1);
        if ($hiDisplay !== '--') {
            $html .= "\n                        <div class=\"metric-item\">";
            $html .= "\n                            <span class=\"label\">Today's High</span>";
            $html .= "\n                            <span class=\"value\">{$hiDisplay}</span>";
            $html .= "\n                        </div>";
        }
    }
    
    // Current Temperature (1 decimal precision for full widgets)
    if ($temperature !== null) {
        $tempDisplay = formatEmbedTemp($temperature, $tempUnit, 1);
        if ($tempDisplay !== '--') {
            $html .= "\n                        <div class=\"metric-item\">";
            $html .= "\n                            <span class=\"label\">Current</span>";
            $html .= "\n                            <span class=\"value\">{$tempDisplay}</span>";
            $html .= "\n                        </div>";
        }
    }
    
    // Today's Low (1 decimal precision for full widgets)
    if ($tempLowToday !== null) {
        $loDisplay = formatEmbedTemp($tempLowToday, $tempUnit, 1);
        if ($loDisplay !== '--') {
            $html .= "\n                        <div class=\"metric-item\">";
            $html .= "\n                            <span class=\"label\">Today's Low</span>";
            $html .= "\n                            <span class=\"value\">{$loDisplay}</span>";
            $html .= "\n                        </div>";
        }
    }
    
    $html .= "\n                    </div>";
    
    // Column 2: Moisture & Conditions
    $html .= "\n                    <div class=\"metric-column\">";
    $html .= "\n                        <div class=\"column-header\">💧 Moisture</div>";
    
    // Dewpoint Spread (with fog warning, 1 decimal precision)
    // Spread is in Celsius (temperature - dewpoint). Convert to F if needed.
    // Note: For temperature differences, multiply by 9/5 (no +32 offset)
    if ($dewpointSpread !== null) {
        if ($tempUnit === 'C') {
            $spreadDisplay = number_format($dewpointSpread, 1) . '°C';
        } else {
            $spreadDisplay = number_format($dewpointSpread * 9 / 5, 1) . '°F';
        }
        $spreadClass = $dewpointSpread <= 3 ? ' fog-warning' : '';
        $html .= "\n                        <div class=\"metric-item{$spreadClass}\">";
        $html .= "\n                            <span class=\"label\">Spread</span>";
        $html .= "\n                            <span class=\"value\">{$spreadDisplay}</span>";
        $html .= "\n                        </div>";
    }
    
    // Dewpoint (1 decimal precision for full widgets)
    if ($dewpoint !== null) {
        $dewptDisplay = formatEmbedTemp($dewpoint, $tempUnit, 1);
        if ($dewptDisplay !== '--') {
            $html .= "\n                        <div class=\"metric-item\">";
            $html .= "\n                            <span class=\"label\">Dewpoint</span>";
            $html .= "\n                            <span class=\"value\">{$dewptDisplay}</span>";
            $html .= "\n                        </div>";
        }
    }
    
    // Humidity
    if ($humidity !== null) {
        $humidityDisplay = formatEmbedHumidity($humidity);
        if ($humidityDisplay !== '--') {
            $html .= "\n                        <div class=\"metric-item\">";
            $html .= "\n                            <span class=\"label\">Humidity</span>";
            $html .= "\n                            <span class=\"value\">{$humidityDisplay}</span>";
            $html .= "\n                        </div>";
        }
    }
    
    // Rainfall Today
    if ($rainfallToday !== null && $rainfallToday > 0) {
        $rainfallDisplay = formatEmbedRainfall($rainfallToday, $distUnit);
        if ($rainfallDisplay !== '--') {
            $html .= "\n                        <div class=\"metric-item\">";
            $html .= "\n                            <span class=\"label\">Rain Today</span>";
            $html .= "\n                            <span class=\"value\">{$rainfallDisplay}</span>";
            $html .= "\n                        </div>";
        }
    }
    
    $html .= "\n                    </div>";
    
    // Column 3: Conditions (METAR data)
    if ($hasMetarData) {
        $html .= "\n                    <div class=\"metric-column\">";
        $html .= "\n                        <div class=\"column-header\">👁️ Conditions</div>";
        
        // Visibility
        if ($visibility !== null) {
            $visDisplay = formatEmbedVisibility(
                $visibility,
                $distUnit,
                $weather['visibility_greater_than'] ?? false
            );
            $html .= "\n                        <div class=\"metric-item\">";
            $html .= "\n                            <span class=\"label\">Visibility</span>";
            $html .= "\n                            <span class=\"value\">{$visDisplay}</span>";
            $html .= "\n                        </div>";
        }
        
        // Ceiling
        if ($ceiling !== null) {
            $ceilingDisplay = $ceiling >= 99999 ? 'UNL' : formatEmbedDist($ceiling, $distUnit, false);
            if ($ceilingDisplay !== '--') {
                $html .= "\n                        <div class=\"metric-item\">";
                $html .= "\n                            <span class=\"label\">Ceiling</span>";
                $html .= "\n                            <span class=\"value\">{$ceilingDisplay}</span>";
                $html .= "\n                        </div>";
            }
        }
        
        $html .= "\n                    </div>";
    }
    
    // Column 4: Altitude
    $html .= "\n                    <div class=\"metric-column\">";
    $html .= "\n                        <div class=\"column-header\">📊 Altitude</div>";
    
    // Altimeter
    if ($pressure !== null) {
        $pressDisplay = formatEmbedPressure($pressure, $baroUnit);
        if ($pressDisplay !== '--') {
            $html .= "\n                        <div class=\"metric-item\">";
            $html .= "\n                            <span class=\"label\">Altimeter</span>";
            $html .= "\n                            <span class=\"value\">{$pressDisplay}</span>";
            $html .= "\n                        </div>";
        }
    }
    
    // Pressure Altitude
    if ($pressureAltitude !== null) {
        $pressAltDisplay = formatEmbedPressureAltitude($pressureAltitude, $distUnit);
        if ($pressAltDisplay !== '--') {
            $html .= "\n                        <div class=\"metric-item\">";
            $html .= "\n                            <span class=\"label\">Press Alt</span>";
            $html .= "\n                            <span class=\"value\">{$pressAltDisplay}</span>";
            $html .= "\n                        </div>";
        }
    }
    
    // Density Altitude
    if ($densityAltitude !== null) {
        $daDisplay = formatEmbedDist($densityAltitude, $distUnit, true);
        if ($daDisplay !== '--') {
            $html .= "\n                        <div class=\"metric-item\">";
            $html .= "\n                            <span class=\"label\">Density Alt</span>";
            $html .= "\n                            <span class=\"value\">{$daDisplay}</span>";
            $html .= "\n                        </div>";
        }
    }
    
    $html .= "\n                    </div>";
    $html .= "\n                </div>";
    
    return $html;
}

/**
 * Render full-single style widget (single webcam with detailed weather)
 * 
 * @param array $data Widget data
 * @param array $options Widget options
 * @return string HTML output
 */
function renderFullSingleWidget($data, $options) {
    $airport = $data['airport'];
    $weather = $data['weather'];
    $airportId = $data['airportId'];
    
    $dashboardUrl = $options['dashboardUrl'];
    $tempUnit = $options['tempUnit'];
    $distUnit = $options['distUnit'];
    $windUnit = $options['windUnit'];
    $baroUnit = $options['baroUnit'];
    $theme = $options['theme'];
    $webcamIndex = $options['webcamIndex'] ?? 0;
    
    $airportName = htmlspecialchars($airport['name'] ?? 'Unknown Airport');
    $primaryIdentifier = htmlspecialchars($options['primaryIdentifier'] ?? strtoupper($airportId));
    $webcamCount = isset($airport['webcams']) ? count($airport['webcams']) : 0;
    
    $hasMetarData = isset($weather['flight_category']) && $weather['flight_category'] !== null;
    $flightCategory = $weather['flight_category'] ?? null;
    $flightCategoryData = getFlightCategoryData($flightCategory);
    
    // Extract weather data (temperatures in Celsius - internal storage standard)
    $windDirection = $weather['wind_direction'] ?? null;
    $windSpeed = $weather['wind_speed'] ?? null;
    $gustSpeed = $weather['gust_speed'] ?? null;
    $isVRB = ($weather['wind_direction_text'] ?? '') === 'VRB';
    $temperature = $weather['temperature'] ?? null;
    $dewpoint = $weather['dewpoint'] ?? null;
    $dewpointSpread = ($temperature !== null && $dewpoint !== null) ? ($temperature - $dewpoint) : null;
    $pressure = $weather['pressure_inhg'] ?? $weather['pressure'] ?? null;
    $densityAltitude = $weather['density_altitude'] ?? null;
    $pressureAltitude = $weather['pressure_altitude'] ?? null;
    $visibility = $weather['visibility'] ?? null;
    $ceiling = $weather['ceiling'] ?? null;
    $humidity = $weather['humidity'] ?? null;
    $rainfallToday = $weather['rainfall_today'] ?? null;
    
    // Peak wind data
    $peakGustToday = $weather['peak_gust_today'] ?? null;
    $peakGustTime = $weather['peak_gust_time'] ?? null;
    
    // Temperature extremes
    $tempHighToday = $weather['temp_high_today'] ?? null;
    $tempLowToday = $weather['temp_low_today'] ?? null;
    
    // Footer data
    $lastUpdated = $weather['last_updated_primary'] ?? time();
    $timezone = $airport['timezone'] ?? 'America/Los_Angeles';
    $sourceName = getWeatherSourceAttribution($weather, $hasMetarData);
    $sourceAttribution = ' & ' . htmlspecialchars($sourceName);
    
    // Runway data for wind compass (empty array if no runways - compass will render without runway line)
    $runways = $airport['runways'] ?? [];
    // For auto mode, pass null to let JavaScript detect system preference
    $isDark = ($theme === 'auto') ? null : ($theme === 'dark');
    
    $webcamUrl = ($webcamCount > 0 && $webcamIndex < $webcamCount)
        ? buildEmbedWebcamUrl($dashboardUrl, $airportId, $webcamIndex)
        : null;

    $target = $options['target'] ?? '_blank';
    $linkAttrs = buildEmbedLinkAttrs($target);

    // Get webcam metadata for aspect ratio
    require_once __DIR__ . '/../webcam-metadata.php';
    $webcamMetadata = $webcamUrl ? getWebcamMetadata($airportId, $webcamIndex) : null;
    $aspectRatio = $webcamMetadata ? ($webcamMetadata['aspect_ratio'] ?? 1.777) : 1.777;

    $canvasId = 'full-wind-canvas-' . uniqid();
    $fullModeOptions = buildWindCompassFullModeOptions($airportId, $airport, $weather);

    // Format wind display parts
    $windDir = $windDirection !== null ? (is_numeric($windDirection) ? round($windDirection) . '°' : $windDirection) : '--';
    $windSpd = $windSpeed !== null ? round($windSpeed * ($windUnit === 'mph' ? 1.15078 : ($windUnit === 'kmh' ? 1.852 : 1))) : '--';
    $gustVal = ($gustSpeed !== null && $gustSpeed > 0) ? 'G' . round($gustSpeed * ($windUnit === 'mph' ? 1.15078 : ($windUnit === 'kmh' ? 1.852 : 1))) : '';
    $windUnitLabel = $windUnit === 'kmh' ? 'km/h' : $windUnit;
    
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
    
    // Build HTML - header and data-row link to dashboard, webcam links to history player
    $html = '<div class="style-full">';
    $html .= '<a href="' . htmlspecialchars($dashboardUrl) . '" class="embed-dashboard-link"' . $linkAttrs . '>';
    $html .= '<div class="full-header">';
    $html .= '<div class="airport-title">';
    $html .= '<span class="identifier">' . $primaryIdentifier . '</span>';
    $html .= '<span class="name">' . $airportName . '</span>';
    $html .= '</div>';
    if ($hasMetarData && $flightCategory) {
        $fcClass = $flightCategoryData['class'];
        $fcText = htmlspecialchars($flightCategoryData['text']);
        $emojiDisplay = $weatherEmojis ? ' ' . htmlspecialchars($weatherEmojis) : '';
        $html .= "\n        <span class=\"flight-category-badge {$fcClass}\">{$fcText}{$emojiDisplay}</span>";
    } else if ($hasMetarData && !$flightCategory) {
        $emojiDisplay = $weatherEmojis ? ' ' . htmlspecialchars($weatherEmojis) : '';
        $html .= "\n        <span class=\"flight-category-badge no-category\">METAR{$emojiDisplay}</span>";
    } else if (!$hasMetarData && $weatherEmojis) {
        $html .= "\n        <span class=\"flight-category-badge no-category\">" . htmlspecialchars($weatherEmojis) . "</span>";
    } else if ($gustSpeed !== null && $gustSpeed > 0) {
        $gustKt = round($gustSpeed);
        $html .= "\n        <span class=\"flight-category-badge no-category\">G{$gustKt}kt</span>";
    }
    $html .= '</div></a>';
    $html .= '<div class="full-body">';
    $historyPlayerUrl = buildHistoryPlayerUrl($dashboardUrl, $webcamIndex);
    $html .= '<a href="' . htmlspecialchars($historyPlayerUrl) . '" class="embed-webcam-link"' . $linkAttrs . '>';
    $html .= '<div class="webcam-section">';
    if ($webcamUrl) {
        $html .= buildEmbedWebcamPicture($dashboardUrl, $airportId, $webcamIndex, $aspectRatio, "{$primaryIdentifier} Webcam", 'webcam-image');
    } else {
        $html .= "\n            <div class=\"no-webcam-placeholder\" style=\"height: 100%;\">No webcam available</div>";
    }
    $html .= '</div></a>';
    $html .= '<a href="' . htmlspecialchars($dashboardUrl) . '" class="embed-dashboard-link"' . $linkAttrs . '>';
    $html .= '<div class="data-row">';
    $html .= <<<HTML
            <div class="wind-section">
                <div class="wind-viz-container">
                    <canvas id="{$canvasId}" width="200" height="200"></canvas>
                    <div class="wind-summary">
                        <span class="wind-value">{$windDir}@{$windSpd}{$gustVal}{$windUnitLabel}</span>
                    </div>
                </div>
                <div class="wind-details">
                    <div class="column-header">💨 Wind</div>
                    <div class="metric-item">
                        <span class="label">Direction</span>
                        <span class="value">{$windDir}</span>
                    </div>
                    <div class="metric-item">
                        <span class="label">Speed</span>
                        <span class="value">
HTML;
    $html .= formatEmbedWindSpeed($windSpeed, $windUnit);
    $html .= <<<HTML
</span>
                    </div>
                    <div class="metric-item">
                        <span class="label">Gusting</span>
                        <span class="value">
HTML;
    // Always show Gusting field
    if ($gustSpeed !== null && $gustSpeed > 0) {
        $html .= formatEmbedWindSpeed($gustSpeed, $windUnit);
    } else {
        $html .= '--';
    }
    $html .= <<<HTML
</span>
                    </div>
                    <div class="metric-item peak-item">
                        <span class="label">Peak Gust</span>
                        <span class="value">
HTML;
    // Always show Peak Gust field
    if ($peakGustToday !== null && $peakGustToday > 0) {
        $html .= formatEmbedWindSpeed($peakGustToday, $windUnit);
    } else {
        $html .= '--';
    }
    $html .= <<<HTML
</span>
                    </div>
                    <div class="metric-item peak-time-item">
                        <span class="label">@ Time</span>
                        <span class="value">
HTML;
    // Always show @ Time field
    if ($peakGustTime !== null && $peakGustToday !== null && $peakGustToday > 0) {
        try {
            $tz = new DateTimeZone($timezone);
            $dt = new DateTime('@' . $peakGustTime);
            $dt->setTimezone($tz);
            $peakTimeDisplay = $dt->format('g:ia');
        } catch (Exception $e) {
            $peakTimeDisplay = date('g:ia', $peakGustTime);
        }
        $html .= htmlspecialchars($peakTimeDisplay);
    } else {
        $html .= '--';
    }
    $html .= <<<HTML
</span>
                    </div>
                </div>
            </div>
            <div class="metrics-section">
HTML;
    $html .= buildFullWidgetMetrics($weather, $options, $hasMetarData);
    $html .= <<<HTML

            </div>
        </div>
    </div>
</a>

HTML;

    $lastUpdated = $weather['last_updated_primary'] ?? time();
    $sourceName = getWeatherSourceAttribution($weather, $hasMetarData);
    $sourceAttribution = ' & ' . htmlspecialchars($sourceName);

    $html .= '<a href="' . htmlspecialchars($dashboardUrl) . '" class="embed-dashboard-link"' . $linkAttrs . '>';
    $html .= renderEmbedFooter($lastUpdated, $timezone, $sourceAttribution);
    $html .= '</a>';
    $html .= "\n</div>\n";

    $html .= renderWindCompassScript($canvasId, $windSpeed, $windDirection, $isVRB, $runways, $isDark, 200, $fullModeOptions);

    return $html;
}

/**
 * Render full-dual style widget (two webcams with detailed weather)
 * 
 * @param array $data Widget data
 * @param array $options Widget options
 * @return string HTML output
 */
function renderFullDualWidget($data, $options) {
    $airport = $data['airport'];
    $weather = $data['weather'];
    $airportId = $data['airportId'];
    
    $dashboardUrl = $options['dashboardUrl'];
    $tempUnit = $options['tempUnit'];
    $distUnit = $options['distUnit'];
    $windUnit = $options['windUnit'];
    $baroUnit = $options['baroUnit'];
    $theme = $options['theme'];
    $cams = $options['cams'] ?? [0, 1];
    
    $airportName = htmlspecialchars($airport['name'] ?? 'Unknown Airport');
    $primaryIdentifier = htmlspecialchars($options['primaryIdentifier'] ?? strtoupper($airportId));
    $webcamCount = isset($airport['webcams']) ? count($airport['webcams']) : 0;
    
    $hasMetarData = isset($weather['flight_category']) && $weather['flight_category'] !== null;
    $flightCategory = $weather['flight_category'] ?? null;
    $flightCategoryData = getFlightCategoryData($flightCategory);
    
    // Extract weather data (temperatures in Celsius - internal storage standard)
    $windDirection = $weather['wind_direction'] ?? null;
    $windSpeed = $weather['wind_speed'] ?? null;
    $gustSpeed = $weather['gust_speed'] ?? null;
    $isVRB = ($weather['wind_direction_text'] ?? '') === 'VRB';
    $temperature = $weather['temperature'] ?? null;
    $dewpoint = $weather['dewpoint'] ?? null;
    $dewpointSpread = ($temperature !== null && $dewpoint !== null) ? ($temperature - $dewpoint) : null;
    $pressure = $weather['pressure_inhg'] ?? $weather['pressure'] ?? null;
    $densityAltitude = $weather['density_altitude'] ?? null;
    $pressureAltitude = $weather['pressure_altitude'] ?? null;
    $visibility = $weather['visibility'] ?? null;
    $ceiling = $weather['ceiling'] ?? null;
    $humidity = $weather['humidity'] ?? null;
    $rainfallToday = $weather['rainfall_today'] ?? null;
    $peakGustToday = $weather['peak_gust_today'] ?? null;
    $peakGustTime = $weather['peak_gust_time'] ?? null;
    $tempHighToday = $weather['temp_high_today'] ?? null;
    $tempLowToday = $weather['temp_low_today'] ?? null;
    
    $timezone = $airport['timezone'] ?? $options['timezone'] ?? 'America/Los_Angeles';
    
    // Runway data for wind compass (empty array if no runways - compass will render without runway line)
    $runways = $airport['runways'] ?? [];
    // For auto mode, pass null to let JavaScript detect system preference
    $isDark = ($theme === 'auto') ? null : ($theme === 'dark');
    
    $canvasId = 'full-dual-wind-canvas-' . uniqid();
    $fullModeOptions = buildWindCompassFullModeOptions($airportId, $airport, $weather);
    $target = $options['target'] ?? '_blank';
    $linkAttrs = buildEmbedLinkAttrs($target);

    // Format wind display
    $windDir = $windDirection !== null ? (is_numeric($windDirection) ? round($windDirection) . '°' : $windDirection) : '--';
    $windSpd = $windSpeed !== null ? round($windSpeed * ($windUnit === 'mph' ? 1.15078 : ($windUnit === 'kmh' ? 1.852 : 1))) : '--';
    $gustVal = ($gustSpeed !== null && $gustSpeed > 0) ? 'G' . round($gustSpeed * ($windUnit === 'mph' ? 1.15078 : ($windUnit === 'kmh' ? 1.852 : 1))) : '';
    $windUnitLabel = $windUnit === 'kmh' ? 'km/h' : $windUnit;
    
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
    
    // Build HTML - header and data-row link to dashboard, each webcam links to history player
    $html = '<div class="style-full">';
    $html .= '<a href="' . htmlspecialchars($dashboardUrl) . '" class="embed-dashboard-link"' . $linkAttrs . '>';
    $html .= '<div class="full-header">';
    $html .= '<div class="airport-title">';
    $html .= '<span class="identifier">' . $primaryIdentifier . '</span>';
    $html .= '<span class="name">' . $airportName . '</span>';
    $html .= '</div>';
    if ($hasMetarData && $flightCategory) {
        $fcClass = $flightCategoryData['class'];
        $fcText = htmlspecialchars($flightCategoryData['text']);
        $emojiDisplay = $weatherEmojis ? ' ' . htmlspecialchars($weatherEmojis) : '';
        $html .= "\n        <span class=\"flight-category-badge {$fcClass}\">{$fcText}{$emojiDisplay}</span>";
    } else if ($hasMetarData && !$flightCategory) {
        $emojiDisplay = $weatherEmojis ? ' ' . htmlspecialchars($weatherEmojis) : '';
        $html .= "\n        <span class=\"flight-category-badge no-category\">METAR{$emojiDisplay}</span>";
    } else if (!$hasMetarData && $weatherEmojis) {
        $html .= "\n        <span class=\"flight-category-badge no-category\">" . htmlspecialchars($weatherEmojis) . "</span>";
    } else if ($gustSpeed !== null && $gustSpeed > 0) {
        $gustKt = round($gustSpeed);
        $html .= "\n        <span class=\"flight-category-badge no-category\">G{$gustKt}kt</span>";
    }
    $html .= '</div></a>';
    $html .= '<div class="full-body">';
    $html .= '<div class="webcam-section"><div class="webcam-grid grid-2">';

    // Render two webcam cells - each links to history player
    for ($i = 0; $i < 2; $i++) {
        $camIdx = $cams[$i] ?? $i;
        $webcamUrl = ($webcamCount > 0 && $camIdx < $webcamCount)
            ? buildEmbedWebcamUrl($dashboardUrl, $airportId, $camIdx)
            : null;

        $camName = 'Camera ' . ($camIdx + 1);
        if (isset($airport['webcams'][$camIdx]['name'])) {
            $camName = htmlspecialchars($airport['webcams'][$camIdx]['name']);
        }

        require_once __DIR__ . '/../webcam-metadata.php';
        $webcamMetadata = $webcamUrl ? getWebcamMetadata($airportId, $camIdx) : null;
        $aspectRatio = $webcamMetadata ? ($webcamMetadata['aspect_ratio'] ?? 1.777) : 1.777;

        $historyPlayerUrl = buildHistoryPlayerUrl($dashboardUrl, $camIdx);
        $html .= '<a href="' . htmlspecialchars($historyPlayerUrl) . '" class="embed-webcam-link webcam-cell"' . $linkAttrs . '>';
        if ($webcamUrl) {
            $html .= buildEmbedWebcamPicture($dashboardUrl, $airportId, $camIdx, $aspectRatio, $camName, 'webcam-image');
            $html .= "\n                    <span class=\"cam-label\">{$camName}</span>";
        }
        $html .= '</a>';
    }

    $html .= '</div></div>';
    $html .= '<a href="' . htmlspecialchars($dashboardUrl) . '" class="embed-dashboard-link"' . $linkAttrs . '>';
    $html .= '<div class="data-row">';
    $html .= <<<HTML
            <div class="wind-section">
                <div class="wind-viz-container">
                    <canvas id="{$canvasId}" width="200" height="200"></canvas>
                    <div class="wind-summary">
                        <span class="wind-value">{$windDir}@{$windSpd}{$gustVal}{$windUnitLabel}</span>
                    </div>
                </div>
                <div class="wind-details">
                    <div class="column-header">💨 Wind</div>
                    <div class="metric-item">
                        <span class="label">Direction</span>
                        <span class="value">{$windDir}</span>
                    </div>
                    <div class="metric-item">
                        <span class="label">Speed</span>
                        <span class="value">
HTML;
    $html .= formatEmbedWindSpeed($windSpeed, $windUnit);
    $html .= <<<HTML
</span>
                    </div>
                    <div class="metric-item">
                        <span class="label">Gusting</span>
                        <span class="value">
HTML;
    // Always show Gusting field
    if ($gustSpeed !== null && $gustSpeed > 0) {
        $html .= formatEmbedWindSpeed($gustSpeed, $windUnit);
    } else {
        $html .= '--';
    }
    $html .= <<<HTML
</span>
                    </div>
                    <div class="metric-item peak-item">
                        <span class="label">Peak Gust</span>
                        <span class="value">
HTML;
    // Always show Peak Gust field
    if ($peakGustToday !== null && $peakGustToday > 0) {
        $html .= formatEmbedWindSpeed($peakGustToday, $windUnit);
    } else {
        $html .= '--';
    }
    $html .= <<<HTML
</span>
                    </div>
                    <div class="metric-item peak-time-item">
                        <span class="label">@ Time</span>
                        <span class="value">
HTML;
    // Always show @ Time field
    if ($peakGustTime !== null && $peakGustToday !== null && $peakGustToday > 0) {
        try {
            $tz = new DateTimeZone($timezone);
            $dt = new DateTime('@' . $peakGustTime);
            $dt->setTimezone($tz);
            $peakTimeDisplay = $dt->format('g:ia');
        } catch (Exception $e) {
            $peakTimeDisplay = date('g:ia', $peakGustTime);
        }
        $html .= htmlspecialchars($peakTimeDisplay);
    } else {
        $html .= '--';
    }
    $html .= <<<HTML
</span>
                    </div>
                </div>
            </div>
            <div class="metrics-section">
HTML;
    $html .= buildFullWidgetMetrics($weather, $options, $hasMetarData);
    $html .= <<<HTML

            </div>
        </div>
    </div>
</a>

HTML;

    $lastUpdated = $weather['last_updated_primary'] ?? time();
    $sourceName = getWeatherSourceAttribution($weather, $hasMetarData);
    $sourceAttribution = ' & ' . htmlspecialchars($sourceName);

    $html .= '<a href="' . htmlspecialchars($dashboardUrl) . '" class="embed-dashboard-link"' . $linkAttrs . '>';
    $html .= renderEmbedFooter($lastUpdated, $timezone, $sourceAttribution);
    $html .= '</a>';
    $html .= "\n</div>\n";

    $html .= renderWindCompassScript($canvasId, $windSpeed, $windDirection, $isVRB, $runways, $isDark, 200, $fullModeOptions);

    return $html;
}

/**
 * Render full-multi style widget (four webcams with detailed weather)
 * 
 * @param array $data Widget data
 * @param array $options Widget options
 * @return string HTML output
 */
function renderFullMultiWidget($data, $options) {
    $airport = $data['airport'];
    $weather = $data['weather'];
    $airportId = $data['airportId'];
    
    $dashboardUrl = $options['dashboardUrl'];
    $tempUnit = $options['tempUnit'];
    $distUnit = $options['distUnit'];
    $windUnit = $options['windUnit'];
    $baroUnit = $options['baroUnit'];
    $theme = $options['theme'];
    $cams = $options['cams'] ?? [0, 1, 2, 3];
    
    $airportName = htmlspecialchars($airport['name'] ?? 'Unknown Airport');
    $primaryIdentifier = htmlspecialchars($options['primaryIdentifier'] ?? strtoupper($airportId));
    $webcamCount = isset($airport['webcams']) ? count($airport['webcams']) : 0;
    
    $hasMetarData = isset($weather['flight_category']) && $weather['flight_category'] !== null;
    $flightCategory = $weather['flight_category'] ?? null;
    $flightCategoryData = getFlightCategoryData($flightCategory);
    
    // Extract weather data (temperatures in Celsius - internal storage standard)
    $windDirection = $weather['wind_direction'] ?? null;
    $windSpeed = $weather['wind_speed'] ?? null;
    $gustSpeed = $weather['gust_speed'] ?? null;
    $isVRB = ($weather['wind_direction_text'] ?? '') === 'VRB';
    $temperature = $weather['temperature'] ?? null;
    $dewpoint = $weather['dewpoint'] ?? null;
    $dewpointSpread = ($temperature !== null && $dewpoint !== null) ? ($temperature - $dewpoint) : null;
    $pressure = $weather['pressure_inhg'] ?? $weather['pressure'] ?? null;
    $densityAltitude = $weather['density_altitude'] ?? null;
    $pressureAltitude = $weather['pressure_altitude'] ?? null;
    $visibility = $weather['visibility'] ?? null;
    $ceiling = $weather['ceiling'] ?? null;
    $humidity = $weather['humidity'] ?? null;
    $rainfallToday = $weather['rainfall_today'] ?? null;
    $peakGustToday = $weather['peak_gust_today'] ?? null;
    $peakGustTime = $weather['peak_gust_time'] ?? null;
    $tempHighToday = $weather['temp_high_today'] ?? null;
    $tempLowToday = $weather['temp_low_today'] ?? null;
    
    $timezone = $airport['timezone'] ?? $options['timezone'] ?? 'America/Los_Angeles';
    
    // Runway data for wind compass (empty array if no runways - compass will render without runway line)
    $runways = $airport['runways'] ?? [];
    // For auto mode, pass null to let JavaScript detect system preference
    $isDark = ($theme === 'auto') ? null : ($theme === 'dark');
    
    $canvasId = 'full-multi-wind-canvas-' . uniqid();
    $fullModeOptions = buildWindCompassFullModeOptions($airportId, $airport, $weather);
    $target = $options['target'] ?? '_blank';
    $linkAttrs = buildEmbedLinkAttrs($target);

    // Format wind display
    $windDir = $windDirection !== null ? (is_numeric($windDirection) ? round($windDirection) . '°' : $windDirection) : '--';
    $windSpd = $windSpeed !== null ? round($windSpeed * ($windUnit === 'mph' ? 1.15078 : ($windUnit === 'kmh' ? 1.852 : 1))) : '--';
    $gustVal = ($gustSpeed !== null && $gustSpeed > 0) ? 'G' . round($gustSpeed * ($windUnit === 'mph' ? 1.15078 : ($windUnit === 'kmh' ? 1.852 : 1))) : '';
    $windUnitLabel = $windUnit === 'kmh' ? 'km/h' : $windUnit;
    
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
    
    // Build HTML - header and data-row link to dashboard, each webcam links to history player
    $html = '<div class="style-full">';
    $html .= '<a href="' . htmlspecialchars($dashboardUrl) . '" class="embed-dashboard-link"' . $linkAttrs . '>';
    $html .= '<div class="full-header">';
    $html .= '<div class="airport-title">';
    $html .= '<span class="identifier">' . $primaryIdentifier . '</span>';
    $html .= '<span class="name">' . $airportName . '</span>';
    $html .= '</div>';
    if ($hasMetarData && $flightCategory) {
        $fcClass = $flightCategoryData['class'];
        $fcText = htmlspecialchars($flightCategoryData['text']);
        $emojiDisplay = $weatherEmojis ? ' ' . htmlspecialchars($weatherEmojis) : '';
        $html .= "\n        <span class=\"flight-category-badge {$fcClass}\">{$fcText}{$emojiDisplay}</span>";
    } else if ($hasMetarData && !$flightCategory) {
        $emojiDisplay = $weatherEmojis ? ' ' . htmlspecialchars($weatherEmojis) : '';
        $html .= "\n        <span class=\"flight-category-badge no-category\">METAR{$emojiDisplay}</span>";
    } else if (!$hasMetarData && $weatherEmojis) {
        $html .= "\n        <span class=\"flight-category-badge no-category\">" . htmlspecialchars($weatherEmojis) . "</span>";
    } else if ($gustSpeed !== null && $gustSpeed > 0) {
        $gustKt = round($gustSpeed);
        $html .= "\n        <span class=\"flight-category-badge no-category\">G{$gustKt}kt</span>";
    }
    $html .= '</div></a>';
    $html .= '<div class="full-body">';
    $html .= '<div class="webcam-section"><div class="webcam-grid grid-4">';

    // Render four webcam cells - each links to history player
    $displayCamCount = min($webcamCount, 4);
    for ($i = 0; $i < $displayCamCount; $i++) {
        $camIdx = $cams[$i] ?? $i;
        $webcamUrl = ($webcamCount > 0 && $camIdx < $webcamCount)
            ? buildEmbedWebcamUrl($dashboardUrl, $airportId, $camIdx)
            : null;

        $camName = 'Camera ' . ($camIdx + 1);
        if (isset($airport['webcams'][$camIdx]['name'])) {
            $camName = htmlspecialchars($airport['webcams'][$camIdx]['name']);
        }

        require_once __DIR__ . '/../webcam-metadata.php';
        $webcamMetadata = $webcamUrl ? getWebcamMetadata($airportId, $camIdx) : null;
        $aspectRatio = $webcamMetadata ? ($webcamMetadata['aspect_ratio'] ?? 1.777) : 1.777;

        $historyPlayerUrl = buildHistoryPlayerUrl($dashboardUrl, $camIdx);
        $html .= '<a href="' . htmlspecialchars($historyPlayerUrl) . '" class="embed-webcam-link webcam-cell"' . $linkAttrs . '>';
        if ($webcamUrl) {
            $html .= buildEmbedWebcamPicture($dashboardUrl, $airportId, $camIdx, $aspectRatio, $camName, 'webcam-image');
            $html .= "\n                    <span class=\"cam-label\">{$camName}</span>";
        }
        $html .= '</a>';
    }

    $html .= '</div></div>';
    $html .= '<a href="' . htmlspecialchars($dashboardUrl) . '" class="embed-dashboard-link"' . $linkAttrs . '>';
    $html .= '<div class="data-row">';
    $html .= <<<HTML
            <div class="wind-section">
                <div class="wind-viz-container">
                    <canvas id="{$canvasId}" width="200" height="200"></canvas>
                    <div class="wind-summary">
                        <span class="wind-value">{$windDir}@{$windSpd}{$gustVal}{$windUnitLabel}</span>
                    </div>
                </div>
                <div class="wind-details">
                    <div class="column-header">💨 Wind</div>
                    <div class="metric-item">
                        <span class="label">Direction</span>
                        <span class="value">{$windDir}</span>
                    </div>
                    <div class="metric-item">
                        <span class="label">Speed</span>
                        <span class="value">
HTML;
    $html .= formatEmbedWindSpeed($windSpeed, $windUnit);
    $html .= <<<HTML
</span>
                    </div>
                    <div class="metric-item">
                        <span class="label">Gusting</span>
                        <span class="value">
HTML;
    // Always show Gusting field
    if ($gustSpeed !== null && $gustSpeed > 0) {
        $html .= formatEmbedWindSpeed($gustSpeed, $windUnit);
    } else {
        $html .= '--';
    }
    $html .= <<<HTML
</span>
                    </div>
                    <div class="metric-item peak-item">
                        <span class="label">Peak Gust</span>
                        <span class="value">
HTML;
    // Always show Peak Gust field
    if ($peakGustToday !== null && $peakGustToday > 0) {
        $html .= formatEmbedWindSpeed($peakGustToday, $windUnit);
    } else {
        $html .= '--';
    }
    $html .= <<<HTML
</span>
                    </div>
                    <div class="metric-item peak-time-item">
                        <span class="label">@ Time</span>
                        <span class="value">
HTML;
    // Always show @ Time field
    if ($peakGustTime !== null && $peakGustToday !== null && $peakGustToday > 0) {
        try {
            $tz = new DateTimeZone($timezone);
            $dt = new DateTime('@' . $peakGustTime);
            $dt->setTimezone($tz);
            $peakTimeDisplay = $dt->format('g:ia');
        } catch (Exception $e) {
            $peakTimeDisplay = date('g:ia', $peakGustTime);
        }
        $html .= htmlspecialchars($peakTimeDisplay);
    } else {
        $html .= '--';
    }
    $html .= <<<HTML
</span>
                    </div>
                </div>
            </div>
            <div class="metrics-section">
HTML;
    $html .= buildFullWidgetMetrics($weather, $options, $hasMetarData);
    $html .= <<<HTML

            </div>
        </div>
    </div>
</a>

HTML;

    $lastUpdated = $weather['last_updated_primary'] ?? time();
    $sourceName = getWeatherSourceAttribution($weather, $hasMetarData);
    $sourceAttribution = ' & ' . htmlspecialchars($sourceName);

    $html .= '<a href="' . htmlspecialchars($dashboardUrl) . '" class="embed-dashboard-link"' . $linkAttrs . '>';
    $html .= renderEmbedFooter($lastUpdated, $timezone, $sourceAttribution);
    $html .= '</a>';
    $html .= "\n</div>\n";

    $html .= renderWindCompassScript($canvasId, $windSpeed, $windDirection, $isVRB, $runways, $isDark, 200, $fullModeOptions);

    return $html;
}
