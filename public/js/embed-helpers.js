/**
 * Shared Helper Functions for AviationWX Embed Widgets
 * 
 * These functions match the PHP template logic in lib/embed-templates/shared.php
 * to ensure visual and functional consistency across iframe and web component embeds.
 * 
 * Used by:
 * - Web components (public/js/widget.js)
 */

(function(window) {
    'use strict';
    
    /**
     * Format temperature with unit conversion
     * Matches: formatEmbedTemp() in shared.php
     */
    function formatEmbedTemp(tempF, unit) {
        if (tempF === null || tempF === undefined) return '--';
        
        if (unit === 'C') {
            const tempC = (tempF - 32) * 5 / 9;
            return Math.round(tempC) + '°C';
        }
        
        return Math.round(tempF) + '°F';
    }
    
    /**
     * Format distance with unit conversion
     * Matches: formatEmbedDist() in shared.php
     */
    function formatEmbedDist(valueFt, unit, useCommas = false) {
        if (valueFt === null || valueFt === undefined) return '--';
        
        if (unit === 'm') {
            const valueM = valueFt * 0.3048;
            return (useCommas ? valueM.toLocaleString() : Math.round(valueM)) + ' m';
        }
        
        return (useCommas ? valueFt.toLocaleString() : Math.round(valueFt)) + ' ft';
    }
    
    /**
     * Format wind speed with unit conversion
     * Matches: formatEmbedWindSpeed() in shared.php
     */
    function formatEmbedWindSpeed(speedKt, unit) {
        if (speedKt === null || speedKt === undefined) return '--';
        
        let converted = speedKt;
        if (unit === 'mph') {
            converted = speedKt * 1.15078;
        } else if (unit === 'kmh') {
            converted = speedKt * 1.852;
        }
        
        const unitLabel = unit === 'kmh' ? 'km/h' : unit;
        return Math.round(converted) + ' ' + unitLabel;
    }
    
    /**
     * Format pressure with unit conversion
     * Matches: formatEmbedPressure() in shared.php
     */
    function formatEmbedPressure(pressureInHg, unit) {
        if (pressureInHg === null || pressureInHg === undefined) return '--';
        
        if (unit === 'hPa') {
            const pressureHPa = pressureInHg * 33.8639;
            return Math.round(pressureHPa) + ' hPa';
        } else if (unit === 'mmHg') {
            const pressureMmHg = pressureInHg * 25.4;
            return Math.round(pressureMmHg) + ' mmHg';
        }
        
        return pressureInHg.toFixed(2) + '"Hg';
    }
    
    /**
     * Get weather emojis based on conditions
     * Matches: getWeatherEmojis() in shared.php
     * 
     * Order: Ceiling/Clouds, Visibility, Precipitation, Wind, Temperature
     */
    function getWeatherEmojis(weather) {
        const emojis = [];
        
        const tempF = weather.temperature_f ?? weather.temperature ?? null;
        const precip = weather.precip_accum ?? 0;
        const windSpeed = weather.wind_speed ?? 0;
        const ceiling = weather.ceiling ?? null;
        const visibility = weather.visibility ?? null;
        const cloudCover = weather.cloud_cover ?? null;
        
        // Precipitation emoji (always show if present - abnormal condition)
        if (precip > 0.01) {
            if (tempF !== null && tempF < 32) {
                emojis.push('❄️'); // Snow
            } else {
                emojis.push('🌧️'); // Rain
            }
        }
        
        // High wind emoji (only show if concerning - abnormal condition)
        if (windSpeed > 25) {
            emojis.push('💨'); // Strong wind (>25 kts)
        } else if (windSpeed > 15) {
            emojis.push('🌬️'); // Moderate wind (15-25 kts)
        }
        // No emoji for ≤ 15 kts (normal wind)
        
        // Low ceiling/poor visibility emoji (only show if concerning - abnormal condition)
        if (ceiling !== null) {
            if (ceiling < 1000) {
                emojis.push('☁️'); // Low ceiling (<1000 ft AGL - IFR/LIFR)
            } else if (ceiling < 3000) {
                emojis.push('🌥️'); // Marginal ceiling (1000-3000 ft AGL - MVFR)
            }
            // No emoji for ≥ 3000 ft (normal VFR ceiling)
        } else if (cloudCover) {
            // Fallback to cloud cover if ceiling not available
            switch (cloudCover) {
                case 'OVC':
                case 'OVX':
                    emojis.push('☁️'); // Overcast (typically low ceiling)
                    break;
                case 'BKN':
                    emojis.push('🌥️'); // Broken (marginal conditions)
                    break;
                // No emoji for SCT or FEW (normal VFR conditions)
            }
        }
        
        // Poor visibility (if available and concerning)
        if (visibility !== null && visibility < 3) {
            emojis.push('🌫️'); // Poor visibility (< 3 SM)
        }
        
        // Extreme temperatures (only show if extreme - abnormal condition)
        if (tempF !== null) {
            if (tempF > 90) {
                emojis.push('🥵'); // Extreme heat (>90°F)
            } else if (tempF < 20) {
                emojis.push('❄️'); // Extreme cold (<20°F)
            }
            // No emoji for 20°F to 90°F (normal temperature range)
        }
        
        // Return emojis if any, otherwise empty string (no emojis for normal conditions)
        return emojis.length > 0 ? emojis.join(' ') : '';
    }
    
    /**
     * Get flight category data
     * Matches: getFlightCategoryData() in shared.php
     */
    function getFlightCategoryData(flightCategory) {
        const categories = {
            'VFR': { class: 'VFR', text: 'VFR' },
            'MVFR': { class: 'MVFR', text: 'MVFR' },
            'IFR': { class: 'IFR', text: 'IFR' },
            'LIFR': { class: 'LIFR', text: 'LIFR' }
        };
        
        const upper = (flightCategory || '').toUpperCase();
        return categories[upper] || { class: 'unknown', text: 'N/A' };
    }
    
    /**
     * Format local time for display
     * Matches: formatLocalTimeEmbed() in shared.php
     */
    function formatLocalTimeEmbed(timestamp, timezone) {
        if (!timestamp) return 'Unknown';
        
        try {
            const date = new Date(timestamp * 1000);
            const options = {
                hour: 'numeric',
                minute: '2-digit',
                timeZoneName: 'short',
                timeZone: timezone || 'America/Los_Angeles'
            };
            return date.toLocaleString('en-US', options);
        } catch (e) {
            return 'Unknown';
        }
    }
    
    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }
    
    // Export to global namespace
    window.AviationWX = window.AviationWX || {};
    window.AviationWX.helpers = {
        formatEmbedTemp,
        formatEmbedDist,
        formatEmbedWindSpeed,
        formatEmbedPressure,
        getWeatherEmojis,
        getFlightCategoryData,
        formatLocalTimeEmbed,
        escapeHtml
    };
    
})(window);
