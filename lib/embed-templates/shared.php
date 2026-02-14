<?php
/**
 * Shared Utilities for Embed Widget Templates
 * 
 * Common functions used across all widget styles.
 */

require_once __DIR__ . '/../weather/utils.php';
require_once __DIR__ . '/../units.php';

/**
 * Get theme CSS class
 * 
 * @param string $theme Theme name ('light', 'dark', or 'auto')
 * @return string CSS class name (e.g., 'theme-light', 'theme-dark', 'theme-auto')
 */
function getThemeClass($theme) {
    $validThemes = ['light', 'dark', 'auto'];
    return in_array($theme, $validThemes) ? 'theme-' . $theme : 'theme-auto';
}

/**
 * Get weather source organization name for attribution
 * 
 * Returns the organization name (e.g., "Tempest Weather", "Davis WeatherLink", "Aviation Weather")
 * instead of generic source types (e.g., "METAR", "PWS").
 * 
 * @param array $weather Weather data array
 * @param bool $hasMetarData Whether METAR data is available
 * @return string Organization name for attribution
 */
function getWeatherSourceAttribution($weather, $hasMetarData) {
    // If we have METAR data, use 'metar' as the source type
    if ($hasMetarData) {
        return getWeatherSourceDisplayName('metar');
    }
    
    // Otherwise, use the actual source type from weather data
    $sourceType = $weather['source'] ?? null;
    if ($sourceType && is_string($sourceType)) {
        return getWeatherSourceDisplayName($sourceType);
    }
    
    // Fallback to generic PWS if no source is available
    return 'PWS';
}

/**
 * Get flight category CSS class and display text
 * 
 * @param string|null $flightCategory Flight category ('VFR', 'MVFR', 'IFR', 'LIFR')
 * @return array Array with 'class' and 'text' keys
 */
function getFlightCategoryData($flightCategory) {
    $categories = [
        'VFR' => ['class' => 'VFR', 'text' => 'VFR'],
        'MVFR' => ['class' => 'MVFR', 'text' => 'MVFR'],
        'IFR' => ['class' => 'IFR', 'text' => 'IFR'],
        'LIFR' => ['class' => 'LIFR', 'text' => 'LIFR']
    ];
    
    $upper = strtoupper($flightCategory ?? '');
    return $categories[$upper] ?? ['class' => 'unknown', 'text' => 'N/A'];
}

/**
 * Format temperature with unit conversion
 * 
 * @param float|null $tempF Temperature in Fahrenheit
 * @param string $unit Unit ('F' or 'C')
 * @return string Formatted temperature string (e.g., '72°F' or '22°C')
 */
/**
 * Format temperature with unit conversion
 *
 * Expects Celsius input (internal storage standard). Converts to Fahrenheit for display if needed.
 *
 * @param float|null $tempC Temperature in Celsius
 * @param string $unit Unit ('F' or 'C')
 * @param int $precision Decimal places (0 for integer, 1 for one decimal)
 * @return string Formatted temperature or '--' if null
 */
function formatEmbedTemp($tempC, $unit, $precision = 0) {
    if ($tempC === null) return '--';

    if ($unit === 'C') {
        return number_format($tempC, $precision) . '°C';
    }

    $tempF = celsiusToFahrenheit((float)$tempC);
    return number_format($tempF, $precision) . '°F';
}

/**
 * Format distance with unit conversion
 * 
 * @param float|null $valueFt Distance in feet
 * @param string $unit Unit ('ft' or 'm')
 * @param bool $useCommas Whether to use comma separators for large numbers
 * @return string Formatted distance string (e.g., '1,200 ft' or '366 m')
 */
function formatEmbedDist($valueFt, $unit, $useCommas = false) {
    if ($valueFt === null) return '--';
    
    if ($unit === 'm') {
        $valueM = $valueFt * 0.3048;
        return ($useCommas ? number_format($valueM) : round($valueM)) . ' m';
    }
    
    return ($useCommas ? number_format($valueFt) : round($valueFt)) . ' ft';
}

/**
 * Format wind speed with unit conversion
 * 
 * @param float|null $speedKt Wind speed in knots
 * @param string $unit Unit ('kt', 'mph', or 'kmh')
 * @return string Formatted wind speed string (e.g., '15 kt', '17 mph', '28 km/h')
 */
function formatEmbedWindSpeed($speedKt, $unit) {
    if ($speedKt === null) return '--';
    
    $converted = $speedKt;
    if ($unit === 'mph') {
        $converted = $speedKt * 1.15078;
    } elseif ($unit === 'kmh') {
        $converted = $speedKt * 1.852;
    }
    
    $unitLabel = $unit === 'kmh' ? 'km/h' : $unit;
    return round($converted) . ' ' . $unitLabel;
}

/**
 * Format pressure with unit conversion
 * 
 * @param float|null $pressureInHg Pressure in inches of mercury
 * @param string $unit Unit ('inHg', 'hPa', or 'mmHg')
 * @return string Formatted pressure string (e.g., '30.12"H', '1020 hPa', '765 mmHg')
 */
function formatEmbedPressure($pressureInHg, $unit) {
    if ($pressureInHg === null) return '--';
    
    if ($unit === 'hPa') {
        $pressureHPa = $pressureInHg * 33.8639;
        return round($pressureHPa) . ' hPa';
    } elseif ($unit === 'mmHg') {
        $pressureMmHg = $pressureInHg * 25.4;
        return round($pressureMmHg) . ' mmHg';
    }
    
    return number_format($pressureInHg, 2) . '"Hg';
}

/**
 * Format wind display (direction + speed + gust)
 * 
 * @param int|null $windDirection Wind direction in degrees
 * @param float|null $windSpeed Wind speed in knots
 * @param float|null $gustSpeed Gust speed in knots
 * @param string $windUnit Unit ('kt', 'mph', or 'kmh')
 * @return string Formatted wind string (e.g., '235°@15G20kt')
 */
function formatEmbedWind($windDirection, $windSpeed, $gustSpeed, $windUnit) {
    $windDir = $windDirection !== null ? round($windDirection) . '°' : '--';
    $windSpd = $windSpeed !== null ? round($windSpeed * ($windUnit === 'mph' ? 1.15078 : ($windUnit === 'kmh' ? 1.852 : 1))) : '--';
    $gustVal = ($gustSpeed !== null && $gustSpeed > 0) ? 'G' . round($gustSpeed * ($windUnit === 'mph' ? 1.15078 : ($windUnit === 'kmh' ? 1.852 : 1))) : '';
    $windUnitLabel = $windUnit === 'kmh' ? 'km/h' : $windUnit;
    
    return $windDir . '@' . $windSpd . $gustVal . $windUnitLabel;
}

/**
 * Format local time for display
 * 
 * @param int|null $timestamp Unix timestamp
 * @param string $timezone Timezone identifier (e.g., 'America/Los_Angeles')
 * @return string Formatted time string (e.g., '2:30 PM PST') or 'Unknown' on error
 */
function formatLocalTimeEmbed($timestamp, $timezone) {
    if (!$timestamp) return 'Unknown';
    
    try {
        $date = new DateTime('@' . $timestamp);
        $date->setTimezone(new DateTimeZone($timezone));
        return $date->format('g:i A T');
    } catch (Exception $e) {
        return 'Unknown';
    }
}

/**
 * Build webcam URL with cache busting
 * 
 * @param string $dashboardUrl Base dashboard URL
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return string Webcam URL with query parameters
 */
function buildEmbedWebcamUrl($dashboardUrl, $airportId, $camIndex) {
    return $dashboardUrl . '/webcam.php?id=' . urlencode($airportId) . '&cam=' . $camIndex;
}

/**
 * Build responsive picture element with srcset for webcam images
 * 
 * Uses variant manifest to generate srcset with all available sizes.
 * Provides WebP and JPEG sources for optimal format selection.
 * 
 * @param string $dashboardUrl Base dashboard URL
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param float $aspectRatio Aspect ratio for CSS (default: 1.777 for 16:9)
 * @param string $altText Alt text for image
 * @param string $cssClass CSS class for image element
 * @return string HTML for picture element with sources and img fallback
 */
function buildEmbedWebcamPicture($dashboardUrl, $airportId, $camIndex, $aspectRatio = 1.777, $altText = '', $cssClass = 'webcam-image') {
    require_once __DIR__ . '/../webcam-variant-manifest.php';
    require_once __DIR__ . '/../webcam-metadata.php';
    require_once __DIR__ . '/../config.php';
    
    // Get latest manifest
    $manifest = getLatestVariantManifest($airportId, $camIndex);
    $timestamp = null;
    $variants = [];
    $originalFormat = 'jpg';
    
    if ($manifest !== null) {
        $timestamp = $manifest['timestamp'] ?? null;
        $variants = $manifest['variants'] ?? [];
        $originalFormat = $manifest['original']['format'] ?? 'jpg';
    }
    
    // If no manifest, fall back to simple URL
    if ($manifest === null || empty($variants)) {
        $simpleUrl = buildEmbedWebcamUrl($dashboardUrl, $airportId, $camIndex);
        $aspectRatioCss = round($aspectRatio, 6);
        return <<<HTML
        <img src="{$simpleUrl}" 
             alt="{$altText}" 
             class="{$cssClass}"
             style="aspect-ratio: {$aspectRatioCss}; width: 100%; height: auto; display: block;">
HTML;
    }
    
    // Get enabled formats
    $enabledFormats = getEnabledWebcamFormats();
    
    // Build srcset for each format
    $webpSrcset = [];
    $jpgSrcset = [];
    
    // Add original to srcsets if available
    if ($manifest['original']['exists'] ?? false) {
        $originalFormat = $manifest['original']['format'] ?? 'jpg';
        $sourceDimensions = $manifest['source_dimensions'] ?? [];
        $originalWidth = $sourceDimensions['width'] ?? null;
        
        if ($originalWidth !== null) {
            $originalUrl = $dashboardUrl . '/webcam.php?id=' . urlencode($airportId) . '&cam=' . $camIndex . '&size=original';
            // Add both formats if available (browser will choose based on support)
            if (in_array('webp', $enabledFormats)) {
                $webpSrcset[] = $originalUrl . '&fmt=webp ' . $originalWidth . 'w';
            }
            if (in_array('jpg', $enabledFormats)) {
                $jpgSrcset[] = $originalUrl . '&fmt=jpg ' . $originalWidth . 'w';
            }
        }
    }
    
    // Add variants to srcsets
    $sourceDimensions = $manifest['source_dimensions'] ?? [];
    $sourceWidth = $sourceDimensions['width'] ?? null;
    $sourceHeight = $sourceDimensions['height'] ?? null;
    
    foreach ($variants as $height => $formats) {
        if (!is_numeric($height)) {
            continue;
        }
        
        // Calculate width from height and source dimensions
        if ($sourceWidth !== null && $sourceHeight !== null && $sourceHeight > 0) {
            $width = (int)round(($height * $sourceWidth) / $sourceHeight);
        } else {
            // Fallback: estimate width from aspect ratio
            $width = (int)round($height * $aspectRatio);
        }
        
        $variantUrl = $dashboardUrl . '/webcam.php?id=' . urlencode($airportId) . '&cam=' . $camIndex . '&size=' . $height;
        
        // Formats is an array of format strings (e.g., ['webp', 'jpg'])
        if (is_array($formats)) {
            foreach ($formats as $format) {
                if ($format === 'webp' && in_array('webp', $enabledFormats)) {
                    $webpSrcset[] = $variantUrl . '&fmt=webp ' . $width . 'w';
                } elseif ($format === 'jpg' && in_array('jpg', $enabledFormats)) {
                    $jpgSrcset[] = $variantUrl . '&fmt=jpg ' . $width . 'w';
                }
            }
        }
    }
    
    // Sort srcsets by width (ascending)
    usort($webpSrcset, function($a, $b) {
        preg_match('/(\d+)w$/', $a, $matchA);
        preg_match('/(\d+)w$/', $b, $matchB);
        $widthA = isset($matchA[1]) ? (int)$matchA[1] : 0;
        $widthB = isset($matchB[1]) ? (int)$matchB[1] : 0;
        return $widthA - $widthB;
    });
    
    usort($jpgSrcset, function($a, $b) {
        preg_match('/(\d+)w$/', $a, $matchA);
        preg_match('/(\d+)w$/', $b, $matchB);
        $widthA = isset($matchA[1]) ? (int)$matchA[1] : 0;
        $widthB = isset($matchB[1]) ? (int)$matchB[1] : 0;
        return $widthA - $widthB;
    });
    
    // Build picture element
    $aspectRatioCss = round($aspectRatio, 6);
    $html = "\n        <picture>";
    
    // WebP source (if available)
    if (!empty($webpSrcset)) {
        $webpSrcsetStr = implode(', ', $webpSrcset);
        $html .= "\n            <source type=\"image/webp\" srcset=\"{$webpSrcsetStr}\">";
    }
    
    // JPEG source (fallback)
    if (!empty($jpgSrcset)) {
        $jpgSrcsetStr = implode(', ', $jpgSrcset);
        $html .= "\n            <source type=\"image/jpeg\" srcset=\"{$jpgSrcsetStr}\">";
    }
    
    // Fallback img element
    $fallbackUrl = buildEmbedWebcamUrl($dashboardUrl, $airportId, $camIndex);
    $html .= <<<HTML

            <img src="{$fallbackUrl}" 
                 alt="{$altText}" 
                 class="{$cssClass}"
                 style="aspect-ratio: {$aspectRatioCss}; width: 100%; height: auto; display: block;"
                 loading="lazy">
        </picture>
HTML;
    
    return $html;
}


/**
 * Get weather emojis based on available data
 * Only shows emojis for abnormal/concerning conditions
 * 
 * @param array $weather Weather data
 * @return string Emoji string (space-separated)
 */
function getWeatherEmojis($weather) {
    $emojis = [];
    
    $tempC = $weather['temperature'] ?? null;
    $precip = $weather['precip_accum'] ?? 0;
    $windSpeed = $weather['wind_speed'] ?? 0;
    $ceiling = $weather['ceiling'] ?? null;
    $visibility = $weather['visibility'] ?? null;
    $cloudCover = $weather['cloud_cover'] ?? null;
    
    // Precipitation emoji (always show if present - abnormal condition)
    if ($precip > 0.01) {
        if ($tempC !== null && $tempC < 0) {
            $emojis[] = '❄️'; // Snow (below freezing in Celsius)
        } else {
            $emojis[] = '🌧️'; // Rain
        }
    }
    
    // High wind emoji (only show if concerning - abnormal condition)
    if ($windSpeed > 25) {
        $emojis[] = '💨'; // Strong wind (>25 kts)
    } else if ($windSpeed > 15) {
        $emojis[] = '🌬️'; // Moderate wind (15-25 kts)
    }
    // No emoji for ≤ 15 kts (normal wind)
    
    // Low ceiling/poor visibility emoji (only show if concerning - abnormal condition)
    if ($ceiling !== null) {
        if ($ceiling < 1000) {
            $emojis[] = '☁️'; // Low ceiling (<1000 ft AGL - IFR/LIFR)
        } else if ($ceiling < 3000) {
            $emojis[] = '🌥️'; // Marginal ceiling (1000-3000 ft AGL - MVFR)
        }
        // No emoji for ≥ 3000 ft (normal VFR ceiling)
    } else if ($cloudCover) {
        // Fallback to cloud cover if ceiling not available
        switch ($cloudCover) {
            case 'OVC':
            case 'OVX':
                $emojis[] = '☁️'; // Overcast (typically low ceiling)
                break;
            case 'BKN':
                $emojis[] = '🌥️'; // Broken (marginal conditions)
                break;
            // No emoji for SCT or FEW (normal VFR conditions)
        }
    }
    
    // Poor visibility (if available and concerning)
    if ($visibility !== null && $visibility < 3) {
        $emojis[] = '🌫️'; // Poor visibility (< 3 SM)
    }
    
    // Extreme temperatures (only show if extreme - abnormal condition)
    // Prefer temperature_f from API when available; else convert from Celsius (internal storage)
    $tempF = $weather['temperature_f'] ?? ($tempC !== null ? celsiusToFahrenheit((float)$tempC) : null);
    if ($tempF !== null) {
        if ($tempF > 90) {
            $emojis[] = '🥵'; // Extreme heat (>90°F)
        } else if ($tempF < 20) {
            $emojis[] = '❄️'; // Extreme cold (<20°F)
        }
        // No emoji for 20°F to 90°F (normal temperature range)
    }
    
    // Return emojis if any, otherwise empty string (no emojis for normal conditions)
    return count($emojis) > 0 ? implode(' ', $emojis) : '';
}

/**
 * Render embed footer HTML
 * 
 * @param int $lastUpdated Unix timestamp of last update
 * @param string $timezone Timezone identifier (e.g., 'America/Los_Angeles')
 * @param string $sourceAttribution Optional source attribution text
 * @return string HTML for footer section
 */
function renderEmbedFooter($lastUpdated, $timezone, $sourceAttribution = '') {
    $formattedTime = formatLocalTimeEmbed($lastUpdated, $timezone);
    $attribution = $sourceAttribution ? htmlspecialchars($sourceAttribution) : '';
    
    return <<<HTML
    <div class="embed-footer">
        <div class="footer-left">
            <span class="footer-label">Last Updated:</span>
            <span class="footer-time">{$formattedTime}</span>
        </div>
        <div class="footer-center">View Dashboard</div>
        <div class="footer-right">
            <span class="footer-powered">Powered by</span>
            <span class="footer-attribution">AviationWX.org{$attribution}</span>
        </div>
    </div>
HTML;
}

/**
 * Format rainfall with unit conversion
 * 
 * @param float|null $valueIn Rainfall in inches
 * @param string $distUnit Distance unit ('ft' or 'm')
 * @return string Formatted rainfall string (e.g., '0.25 in' or '0.64 cm')
 */
function formatEmbedRainfall($valueIn, $distUnit) {
    if ($valueIn === null) return '--';
    
    if ($distUnit === 'm') {
        // Convert inches to cm
        return number_format($valueIn * 2.54, 2) . ' cm';
    }
    
    return number_format($valueIn, 2) . ' in';
}

/**
 * Format visibility with unit conversion
 *
 * Expects statute miles (SM) as input (internal storage standard).
 * Converts to kilometers when metric units selected using lib/units.php.
 * When $greaterThan is true (METAR P prefix, e.g. P6SM), appends "+" to indicate
 * value exceeds the reported number.
 *
 * @param float|null $valueSM Visibility in statute miles
 * @param string $distUnit Distance unit ('ft' for imperial/SM, 'm' for metric/km)
 * @param bool $greaterThan True when METAR P prefix (e.g. P6SM = greater than 6 SM)
 * @return string Formatted visibility string (e.g., '10+ SM', '6+ SM', '16+ km')
 */
function formatEmbedVisibility($valueSM, $distUnit, $greaterThan = false) {
    if ($valueSM === null) return '--';

    if ($distUnit === 'm') {
        $valueKm = statuteMilesToKilometers($valueSM);
        if ($valueKm >= 16) {
            return '16+ km';
        }
        $suffix = $greaterThan ? '+' : '';
        return round($valueKm, 1) . $suffix . ' km';
    }

    // Imperial - statute miles
    if ($valueSM >= 10) {
        return '10+ SM';
    }
    $suffix = $greaterThan ? '+' : '';
    return round($valueSM, 1) . $suffix . ' SM';
}

/**
 * Format humidity percentage
 * 
 * @param float|null $value Humidity value (0-100)
 * @return string Formatted humidity string (e.g., '85%') or '--' if null
 */
function formatEmbedHumidity($value) {
    if ($value === null) return '--';
    return round($value) . '%';
}

/**
 * Format pressure altitude with unit conversion
 * 
 * @param float|null $valueFt Pressure altitude in feet
 * @param string $distUnit Distance unit ('ft' or 'm')
 * @return string Formatted pressure altitude string (e.g., '-500 ft' or '-152 m')
 */
function formatEmbedPressureAltitude($valueFt, $distUnit) {
    if ($valueFt === null) return '--';
    return formatEmbedDist($valueFt, $distUnit, true);
}

/**
 * Get top 6 weather metrics for compact widgets based on priority and availability
 * Priority order: Temp, DA, Press, Vis, Ceiling, Dewpt, Spread, Humidity, Pressure Alt, Today's High, Today's Low, Rainfall
 * 
 * @param array $weather Weather data
 * @param array $options Widget options (tempUnit, distUnit, etc.)
 * @param bool $hasMetarData Whether METAR data is available
 * @return array Array of metric definitions with 'label' and 'value', max 6 items
 */
function getCompactWidgetMetrics($weather, $options, $hasMetarData) {
    $tempUnit = $options['tempUnit'];
    $distUnit = $options['distUnit'];
    $baroUnit = $options['baroUnit'];
    
    // Extract weather values (temperatures in Celsius - internal storage standard)
    $temperature = $weather['temperature'] ?? null;
    $dewpoint = $weather['dewpoint'] ?? null;
    $pressure = $weather['pressure_inhg'] ?? $weather['pressure'] ?? null;
    $densityAltitude = $weather['density_altitude'] ?? null;
    $visibility = $weather['visibility'] ?? null;
    $ceiling = $weather['ceiling'] ?? null;
    $humidity = $weather['humidity'] ?? null;
    $pressureAltitude = $weather['pressure_altitude'] ?? null;
    $tempHigh = $weather['temp_high_today'] ?? null;
    $tempLow = $weather['temp_low_today'] ?? null;
    $precipAccum = $weather['precip_accum'] ?? null;
    
    // Calculate dewpoint spread
    $dewpointSpread = null;
    if ($temperature !== null && $dewpoint !== null) {
        $dewpointSpread = $temperature - $dewpoint;
    }
    
    // Build priority-ordered list of available metrics
    $availableMetrics = [];
    
    // 1. Temperature (always show if available, 1 decimal precision)
    if ($temperature !== null) {
        $tempDisplay = formatEmbedTemp($temperature, $tempUnit, 1);
        if ($tempDisplay !== '--') {
            $availableMetrics[] = ['label' => 'Temp', 'value' => $tempDisplay];
        }
    }
    
    // 2. Density Altitude (always show if available)
    if ($densityAltitude !== null) {
        $daDisplay = formatEmbedDist($densityAltitude, $distUnit, true);
        if ($daDisplay !== '--') {
            $availableMetrics[] = ['label' => 'DA', 'value' => $daDisplay];
        }
    }
    
    // 3. Pressure (always show if available)
    if ($pressure !== null) {
        $pressDisplay = formatEmbedPressure($pressure, $baroUnit);
        if ($pressDisplay !== '--') {
            $availableMetrics[] = ['label' => 'Press', 'value' => $pressDisplay];
        }
    }
    
    // 4. Visibility (show if available)
    if ($visibility !== null) {
        $visDisplay = formatEmbedVisibility(
            $visibility,
            $distUnit,
            $weather['visibility_greater_than'] ?? false
        );
        $availableMetrics[] = ['label' => 'Vis', 'value' => $visDisplay];
    }
    
    // 5. Ceiling (show if METAR data and available)
    if ($hasMetarData && $ceiling !== null) {
        $ceilingFt = round($ceiling);
        $ceilingDisplay = formatEmbedDist($ceilingFt, $distUnit, false);
        if ($ceilingDisplay !== '--') {
            $availableMetrics[] = ['label' => 'Ceiling', 'value' => $ceilingDisplay];
        }
    }
    
    // 6. Dewpoint (show if available, 1 decimal precision)
    if ($dewpoint !== null) {
        $dewptDisplay = formatEmbedTemp($dewpoint, $tempUnit, 1);
        if ($dewptDisplay !== '--') {
            $availableMetrics[] = ['label' => 'Dewpt', 'value' => $dewptDisplay];
        }
    }
    
    // 7. Dewpoint Spread (show if available, 1 decimal precision)
    // Spread is in Celsius. Convert to F if needed (multiply by 9/5, no +32 for differences)
    if ($dewpointSpread !== null) {
        $spreadValue = ($tempUnit === 'F') ? ($dewpointSpread * 9 / 5) : $dewpointSpread;
        $spreadDisplay = number_format($spreadValue, 1) . '°' . $tempUnit;
        $availableMetrics[] = ['label' => 'Spread', 'value' => $spreadDisplay];
    }
    
    // 8. Humidity (show if available)
    if ($humidity !== null) {
        $humidityDisplay = formatEmbedHumidity($humidity);
        if ($humidityDisplay !== '--') {
            $availableMetrics[] = ['label' => 'Humidity', 'value' => $humidityDisplay];
        }
    }
    
    // 9. Pressure Altitude (show if available)
    if ($pressureAltitude !== null) {
        $pressAltDisplay = formatEmbedPressureAltitude($pressureAltitude, $distUnit);
        if ($pressAltDisplay !== '--') {
            $availableMetrics[] = ['label' => 'Press Alt', 'value' => $pressAltDisplay];
        }
    }
    
    // 10. Today's High (show if available, 1 decimal precision)
    if ($tempHigh !== null) {
        $highDisplay = formatEmbedTemp($tempHigh, $tempUnit, 1);
        if ($highDisplay !== '--') {
            $availableMetrics[] = ['label' => 'High', 'value' => $highDisplay];
        }
    }
    
    // 11. Today's Low (show if available, 1 decimal precision)
    if ($tempLow !== null) {
        $lowDisplay = formatEmbedTemp($tempLow, $tempUnit, 1);
        if ($lowDisplay !== '--') {
            $availableMetrics[] = ['label' => 'Low', 'value' => $lowDisplay];
        }
    }
    
    // 12. Rainfall Today (show if available)
    if ($precipAccum !== null && $precipAccum > 0) {
        $rainfallDisplay = formatEmbedRainfall($precipAccum, $distUnit);
        if ($rainfallDisplay !== '--') {
            $availableMetrics[] = ['label' => 'Rainfall', 'value' => $rainfallDisplay];
        }
    }
    
    // Take only the first 6 metrics
    $metrics = array_slice($availableMetrics, 0, 6);
    
    // Ensure we always have 6 metrics (fill with '---' if needed)
    while (count($metrics) < 6) {
        $metrics[] = ['label' => '---', 'value' => '---'];
    }
    
    return $metrics;
}

/**
 * Render wind compass script
 * Works in both regular DOM and shadow DOM contexts
 * 
 * @param string $canvasId Canvas element ID
 * @param float|null $windSpeed Wind speed in knots
 * @param int|null $windDirection Wind direction in degrees
 * @param bool $isVRB Whether wind is variable
 * @param array $runways Array of runway headings
 * @param bool|null $isDark Dark mode flag (null for auto-detect)
 * @param int $size Canvas size in pixels (default: 60)
 * @return string JavaScript code to initialize compass
 */
function renderWindCompassScript($canvasId, $windSpeed, $windDirection, $isVRB, $runways, $isDark, $size = 60) {
    $windSpeedJson = json_encode($windSpeed);
    $windDirectionJson = json_encode($windDirection);
    $isVRBJson = $isVRB ? 'true' : 'false';
    $runwaysJson = json_encode($runways);
    // Handle auto mode: null means detect from system preference
    // Pass null as JSON null so JavaScript can detect it
    if ($isDark === null) {
        $isDarkJson = 'null';
    } else {
        $isDarkJson = $isDark ? 'true' : 'false';
    }
    
    // Determine size variant based on canvas size
    $sizeVariant = 'medium';
    if ($size >= 100) {
        $sizeVariant = 'large';
    } elseif ($size >= 80) {
        $sizeVariant = 'medium';
    } elseif ($size >= 60) {
        $sizeVariant = 'small';
    } else {
        $sizeVariant = 'mini';
    }
    $sizeVariantJson = json_encode($sizeVariant);

    return <<<JAVASCRIPT
    <script>
    (function() {
        // Try to find canvas in shadow DOM first (for web components), then regular DOM
        var canvas = null;
        var canvasId = '{$canvasId}';
        
        // Check if we're in a shadow DOM context (web component)
        if (document.currentScript && document.currentScript.getRootNode) {
            var root = document.currentScript.getRootNode();
            if (root.nodeType === 11) { // ShadowRoot
                canvas = root.getElementById(canvasId);
            }
        }
        
        // Fallback to regular DOM
        if (!canvas) {
            canvas = document.getElementById(canvasId);
        }
        
        // Also try querySelector in case getElementById doesn't work
        if (!canvas && document.currentScript) {
            var root = document.currentScript.getRootNode();
            if (root.nodeType === 11) {
                canvas = root.querySelector('#' + canvasId);
            } else {
                canvas = document.querySelector('#' + canvasId);
            }
        }
        
        // Helper to detect dark mode for auto theme
        function detectDarkMode() {
            if (typeof window.matchMedia !== 'undefined') {
                return window.matchMedia('(prefers-color-scheme: dark)').matches;
            }
            return false;
        }
        
        // Function to draw compass with current theme
        function drawCompass() {
            if (!canvas || !window.AviationWX || !window.AviationWX.drawWindCompass) {
                return;
            }
            
            // Determine isDark value (handle auto mode)
            var isDarkValue = {$isDarkJson};
            if (typeof isDarkValue === 'undefined' || isDarkValue === null) {
                isDarkValue = detectDarkMode();
            }
            
            window.AviationWX.drawWindCompass(canvas, {
                windSpeed: {$windSpeedJson},
                windDirection: {$windDirectionJson},
                isVRB: {$isVRBJson},
                runways: {$runwaysJson},
                isDark: isDarkValue,
                size: {$sizeVariantJson}
            });
        }
        
        // Draw compass immediately if ready
        if (canvas && window.AviationWX && window.AviationWX.drawWindCompass) {
            drawCompass();
        } else if (canvas) {
            // If AviationWX not loaded yet, wait a bit and try again
            setTimeout(function() {
                drawCompass();
            }, 100);
        }
        
        // Listen for theme changes in auto mode
        // Only set up listener if theme is auto (isDarkJson is null)
        var isAutoMode = {$isDarkJson} === null;
        if (isAutoMode) {
            // Listen for system preference changes
            if (typeof window.matchMedia !== 'undefined') {
                var darkModeQuery = window.matchMedia('(prefers-color-scheme: dark)');
                // Use addEventListener if available (modern browsers)
                if (darkModeQuery.addEventListener) {
                    darkModeQuery.addEventListener('change', function(e) {
                        drawCompass();
                    });
                } else {
                    // Fallback for older browsers
                    darkModeQuery.addListener(function(e) {
                        drawCompass();
                    });
                }
            }
            
            // Also listen for custom themechange event (from embed.php auto mode script)
            document.addEventListener('themechange', function(e) {
                if (e.detail && typeof e.detail.isDark !== 'undefined') {
                    drawCompass();
                }
            });
        }
    })();
    </script>
JAVASCRIPT;
}
