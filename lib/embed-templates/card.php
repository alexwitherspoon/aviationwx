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
    $formalIdentifier = resolveEmbedFormalIdentifier($options, $airport);
    
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
    $temperature = $weather['temperature'] ?? null;
    $dewpoint = $weather['dewpoint'] ?? null;
    $densityAltitude = $weather['density_altitude'] ?? null;
    $pressure = $weather['pressure_inhg'] ?? $weather['pressure'] ?? null;
    $visibility = $weather['visibility'] ?? null;
    $ceiling = $weather['ceiling'] ?? null;
    $humidity = $weather['humidity'] ?? null;
    [$windDirection, $isVRB] = getEmbedWindFromWeather($weather);
    $windSpeed = $weather['wind_speed'] ?? null;
    $gustSpeed = $weather['gust_speed'] ?? null;
    
    // Calculate dewpoint spread if we have both values
    $dewpointSpread = null;
    if ($temperature !== null && $dewpoint !== null) {
        $dewpointSpread = $temperature - $dewpoint;
    }
    
    // Format weather display - ensure '---' for missing values (not '--')
    // Use 1 decimal precision for temperature values
    $tempDisplay = formatEmbedTemp($temperature, $tempUnit, 1);
    if ($tempDisplay === '--') $tempDisplay = '---';

    $dewpointDisplay = formatEmbedTemp($dewpoint, $tempUnit, 1);
    if ($dewpointDisplay === '--') $dewpointDisplay = '---';
    
    $densityAltitudeDisplay = formatEmbedDist($densityAltitude, $distUnit, true);
    if ($densityAltitudeDisplay === '--') $densityAltitudeDisplay = '---';
    
    $pressureDisplay = formatEmbedPressure($pressure, $baroUnit);
    if ($pressureDisplay === '--') $pressureDisplay = '---';
    $windTextDisplay = formatEmbedWind($windDirection, $windSpeed, $gustSpeed, $windUnit);
    
    // Wind speed value
    $windSpeedValue = ($windSpeed !== null && $windSpeed >= 3) ? formatEmbedWindSpeed($windSpeed, $windUnit) : 'Calm';
    
    // Wind direction value (use wind_direction_magnetic; fail closed with ---)
    $windDirValue = '---';
    if ($isVRB) {
        $windDirValue = 'Variable';
    } elseif ($windDirection !== null && $windSpeed >= 3) {
        $windDirValue = round($windDirection) . '°';
    }
    
    // Gust value
    $gustValue = '---';
    if ($gustSpeed !== null && $gustSpeed > 0) {
        $gustValue = formatEmbedWindSpeed($gustSpeed, $windUnit);
    }
    
    // Dewpoint spread display
    // Spread is in Celsius. Convert to F if needed (multiply by 9/5, no +32 for differences)
    $spreadDisplay = '---';
    if ($dewpointSpread !== null) {
        $spreadValue = ($tempUnit === 'F') ? ($dewpointSpread * 9 / 5) : $dewpointSpread;
        $spreadDisplay = round($spreadValue) . '°' . $tempUnit;
    }
    
    // Visibility display
    $visDisplay = '---';
    if ($visibility !== null) {
        $visDisplay = formatEmbedVisibility(
            $visibility,
            $distUnit,
            $weather['visibility_greater_than'] ?? false
        );
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

    // Full-mode options for dashboard-matching wind viz (runway segments, petals, staleness)
    $fullModeOptions = buildWindCompassFullModeOptions($airportId, $airport, $weather);

    // For dark mode detection: 'dark' = true, 'light' = false, 'auto' = null (JS will detect)
    $isDark = ($theme === 'dark') ? true : (($theme === 'light') ? false : null);
    
    // Weather emojis
    $weatherEmojis = '';
    if ($hasMetarData) {
        $weatherEmojis = getWeatherEmojis($weather);
    } else {
        // For PWS-only sites, only show emojis for available data (no ceiling/visibility)
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
        'formalIdentifier' => $formalIdentifier,
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
        'fullModeOptions' => $fullModeOptions,
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
    $formalIdentifier = $processed['formalIdentifier'];
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
    $fullModeOptions = $processed['fullModeOptions'];
    $isDark = $processed['isDark'];
    
    // Extract options for HTML-specific needs
    $dashboardUrl = $options['dashboardUrl'];
    $target = $options['target'];
    $theme = $options['theme'];
    $windUnit = $options['windUnit']; // Needed for wind compass script
    $linkAttrs = buildEmbedLinkAttrs($target);

    $sourceAttribution = ' & ' . htmlspecialchars($dataSource);
    $canvasId = 'card-wind-canvas-' . uniqid();

    // Wind-forward layout values (compass is the hero; surrounding data is compact)
    $weather = $data['weather'];
    $windUnitLabel = $windUnit === 'kmh' ? 'km/h' : $windUnit;

    // Gust factor and today's peak gust (with time) for the wind facts rail
    $gustFactorKt = $weather['gust_factor'] ?? null;
    $gustFactorValue = ($gustFactorKt !== null && $gustFactorKt > 0) ? formatEmbedWindSpeed($gustFactorKt, $windUnit) : '--';

    $peakGustKt = $weather['peak_gust_today'] ?? null;
    $peakGustValue = ($peakGustKt !== null && $peakGustKt > 0) ? formatEmbedWindSpeed($peakGustKt, $windUnit) : '--';
    $peakGustTime = $weather['peak_gust_time'] ?? null;
    $peakTimeValue = '--';
    if ($peakGustTime !== null && $peakGustKt !== null && $peakGustKt > 0) {
        try {
            $tz = new DateTimeZone($timezone);
            $dt = new DateTime('@' . $peakGustTime);
            $dt->setTimezone($tz);
            $peakTimeValue = $dt->format('g:ia');
        } catch (Exception $e) {
            // Invalid airport timezone (config error): leave as '--' rather than
            // rendering in the server timezone, which would vary by environment.
            $peakTimeValue = '--';
        }
    }

    // Compact summary line (shown when stacked, where the facts rail is below the fold).
    // Wind fields fail closed to null when stale: show '---' (unavailable), not 'Calm'.
    if ($windSpeed === null) {
        $windSummary = '---';
    } elseif ($windSpeed < 3) {
        $windSummary = 'Calm';
    } else {
        $dirPart = $isVRB ? 'VRB' : (is_numeric($windDirection) ? round($windDirection) . '°' : '---');
        $spdConv = $windUnit === 'mph' ? knotsToMph($windSpeed) : ($windUnit === 'kmh' ? knotsToKmh($windSpeed) : $windSpeed);
        $gustSpeedKt = $processed['gustSpeed'] ?? null;
        $gustPart = ($gustSpeedKt !== null && $gustSpeedKt > 0)
            ? ' G' . round($windUnit === 'mph' ? knotsToMph($gustSpeedKt) : ($windUnit === 'kmh' ? knotsToKmh($gustSpeedKt) : $gustSpeedKt))
            : '';
        $windSummary = $dirPart . '@' . round($spdConv) . $gustPart . ' ' . $windUnitLabel;
    }

    // Legend metadata (matches the compass: True North marker, wind arrow, runways, petals)
    $magDecl = $fullModeOptions['magneticDeclination'] ?? 0;
    $magDeclRounded = (int) round($magDecl);
    $magVarLabel = ($magDeclRounded !== 0) ? (abs($magDeclRounded) . '°' . ($magDeclRounded > 0 ? 'E' : 'W')) : '';
    $trueNorthLabel = 'True N' . ($magVarLabel !== '' ? ' (' . $magVarLabel . ')' : '');
    $lastHourWind = $fullModeOptions['lastHourWind'] ?? null;
    $hasActivePetals = is_array($lastHourWind) && count($lastHourWind) === 16
        && count(array_filter($lastHourWind, function ($s) { return $s > 0; })) > 0;

    // Direction value + magnetic sub-label when numeric
    $magSub = ($windDirValue !== '---' && $windDirValue !== 'Variable') ? ' <span class="sub">Mag</span>' : '';

    // Header: airport title + flight category / conditions badge
    $headerBadge = '';
    if ($hasMetarData && $flightCategory) {
        $fcClass = $flightCategoryData['class'];
        $fcText = htmlspecialchars($flightCategoryData['text']);
        $emojiDisplay = $weatherEmojis ? ' ' . htmlspecialchars($weatherEmojis) : '';
        $headerBadge = "<span class=\"flight-category-badge {$fcClass}\">{$fcText}{$emojiDisplay}</span>";
    } else if ($hasMetarData && !$flightCategory) {
        $emojiDisplay = $weatherEmojis ? ' ' . htmlspecialchars($weatherEmojis) : '';
        $headerBadge = "<span class=\"flight-category-badge no-category\">METAR{$emojiDisplay}</span>";
    } else if (!$hasMetarData && $weatherEmojis) {
        $headerBadge = "<span class=\"flight-category-badge no-category\">" . htmlspecialchars($weatherEmojis) . "</span>";
    }

    // Petal legend item only when there is recent wind-rose data
    $petalLegend = $hasActivePetals
        ? '<span><span class="lg-petal">&#9646;</span> last hr</span>'
        : '';

    // Escape dynamic text before interpolating it into the HTML below
    $windSummary = htmlspecialchars($windSummary);
    $peakTimeValue = htmlspecialchars($peakTimeValue);
    $trueNorthLabel = htmlspecialchars($trueNorthLabel);

    // Build HTML - wrap entire card in link to dashboard
    $html = '<a href="' . htmlspecialchars($dashboardUrl) . '" class="embed-dashboard-link"' . $linkAttrs . '>';
    $html .= '<div class="style-card style-card-wf"><div class="wf-header">';
    appendEmbedAirportTitleMarkup($html, $formalIdentifier, $processed['airportName']);
    $html .= $headerBadge;
    $html .= <<<HTML
</div>
    <div class="wf-body"><div class="wf-inner">
        <div class="wf-compass">
            <canvas id="{$canvasId}" width="300" height="300"></canvas>
            <div class="wf-summary">{$windSummary}</div>
        </div>
        <div class="wf-side">
            <div class="wf-wind">
                <div class="col-h">Wind</div>
                <div class="wf-row"><span class="k">Direction</span><span class="v">{$windDirValue}{$magSub}</span></div>
                <div class="wf-row"><span class="k">Speed</span><span class="v">{$windSpeedValue}</span></div>
                <div class="wf-row"><span class="k">Gusting</span><span class="v">{$gustValue}</span></div>
                <div class="wf-row detail-extra"><span class="k">Gust Factor</span><span class="v">{$gustFactorValue}</span></div>
                <div class="wf-row peak detail-extra"><span class="k">Peak Gust</span><span class="v">{$peakGustValue} <span class="sub">{$peakTimeValue}</span></span></div>
                <div class="wf-legend detail-extra">
                    <span><span class="lg-true">&#9733;</span> {$trueNorthLabel}</span>
                    <span><span class="lg-wind">&#8594;</span> wind</span>
                    <span><span class="lg-rwy">&#9644;</span> runways</span>
                    {$petalLegend}
                </div>
            </div>
            <div class="wf-metrics">
                <div class="col-h">Conditions</div>
                <div class="wf-tiles">
                    <div class="tile"><span class="tl">Temp</span><span class="tv">{$tempDisplay}</span></div>
                    <div class="tile"><span class="tl">Dewpt</span><span class="tv">{$dewpointDisplay}</span></div>
                    <div class="tile"><span class="tl">DA</span><span class="tv">{$densityAltitudeDisplay}</span></div>
                    <div class="tile"><span class="tl">{$secondMetricLabel}</span><span class="tv">{$secondMetricValue}</span></div>
                    <div class="tile"><span class="tl">Press</span><span class="tv">{$pressureDisplay}</span></div>
                    <div class="tile"><span class="tl">Vis</span><span class="tv">{$visDisplay}</span></div>
                </div>
            </div>
        </div>
    </div></div>

HTML;

    // Add footer
    $html .= renderEmbedFooter($lastUpdated, $timezone, $sourceAttribution);

    $html .= "\n</div>\n";
    $html .= '</a>';

    // Compass hero: draw at 300 (matches the dashboard) and let CSS scale it responsively
    $html .= renderWindCompassScript($canvasId, $windSpeed, $windDirection, $isVRB, $runways, $isDark, 300, $fullModeOptions);

    return $html;
}
