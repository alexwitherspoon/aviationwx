/**
 * AviationWX Web Component
 * 
 * Custom element for embedding aviation weather widgets.
 * Provides multiple widget styles with auto-refresh and theme support.
 * 
 * Usage:
 *   <aviation-wx airport="kspb" style="card" theme="auto"></aviation-wx>
 * 
 * Attributes:
 *   - airport (required): Airport ID (e.g., "kspb")
 *   - style: Widget style (card, webcam, dual, multi, full-single, full-dual, full-multi)
 *   - theme: Color theme (light, dark, auto)
 *   - webcam: Webcam index for single-cam styles (0-based)
 *   - cams: Comma-separated webcam indices for multi-cam styles (e.g., "0,1,2,3")
 *   - temp: Temperature unit (F, C)
 *   - dist: Distance unit (ft, m)
 *   - wind: Wind speed unit (kt, mph, kmh)
 *   - baro: Barometer unit (inHg, hPa, mmHg)
 *   - target: Link target (_blank, _self)
 *   - refresh: Auto-refresh interval in milliseconds (default: 300000 = 5 minutes)
 *   - width: Widget width in pixels (optional, uses style defaults if not provided)
 *   - height: Widget height in pixels (optional, uses style defaults if not provided)
 */

(function() {
    'use strict';

    // Determine the base URL for API calls
    // When embedded on external sites, we need absolute URLs pointing back to the widget's origin
    // The base_domain is configured in airports.json, but since this is client-side JS,
    // we detect the origin from the script's own src attribute
    const getBaseUrl = () => {
        // Try to find this script's src to determine the origin
        // This works for any domain, not just aviationwx.org (supports custom deployments)
        const scripts = document.querySelectorAll('script[src*="widget.js"]');
        for (const script of scripts) {
            const src = script.src;
            // Only process absolute URLs (http/https), skip relative paths
            if (src.startsWith('http')) {
                try {
                    const url = new URL(src);
                    // For embed subdomain (embed.example.com), use main domain (example.com)
                    // This ensures API calls go to the main site
                    const hostname = url.hostname;
                    if (hostname.startsWith('embed.')) {
                        const mainDomain = hostname.substring(6); // Remove 'embed.' prefix
                        return `${url.protocol}//${mainDomain}`;
                    }
                    return url.origin;
                } catch (e) {
                    // Invalid URL, continue checking other scripts
                }
            }
        }
        // Fallback to current origin if we're on the same domain as the widget
        // (works when page is hosted on the same domain as the widget)
        return window.location.origin;
    };
    
    const BASE_URL = getBaseUrl();

    /**
     * Build dashboard URL for the given airport (matches API/embed.php logic)
     * @param {string} airport Airport ID (e.g., 'khio')
     * @returns {string} Dashboard URL (e.g., 'https://khio.aviationwx.org')
     */
    const getDashboardUrl = (airport) => {
        if (!airport) return BASE_URL;
        try {
            const url = new URL(BASE_URL);
            if (url.hostname === 'localhost' || url.hostname.includes('127.0.0.1')) {
                return url.origin;
            }
            const domain = url.hostname.startsWith('embed.') ? url.hostname.substring(6) : url.hostname;
            return `${url.protocol}//${airport.toLowerCase()}.${domain}`;
        } catch (e) {
            return `https://${airport.toLowerCase()}.aviationwx.org`;
        }
    };

    // Widget size presets (default dimensions for each style)
    const SIZE_PRESETS = {
        'card': { width: 300, height: 300 },
        'webcam': { width: 450, height: 450 },
        'dual': { width: 600, height: 300 },
        'multi': { width: 600, height: 475 },
        'full-single': { width: 800, height: 740 },
        'full-dual': { width: 800, height: 550 },
        'full-multi': { width: 800, height: 750 }
    };

    // Flight category colors
    const FLIGHT_CATEGORIES = {
        'VFR': { bg: '#28a745', text: '#fff' },
        'MVFR': { bg: '#0066cc', text: '#fff' },
        'IFR': { bg: '#dc3545', text: '#fff' },
        'LIFR': { bg: '#ff00ff', text: '#fff' }
    };

    // Calm wind threshold (winds below this are considered calm in aviation)
    const CALM_WIND_THRESHOLD = 3;

    /**
     * AviationWX Web Component Class
     */
    class AviationWXWidget extends HTMLElement {
        constructor() {
            super();
            this.attachShadow({ mode: 'open' });
            this.weatherData = null;
            this.refreshTimer = null;
            this.lastFetchTime = 0;
            this.cacheTimeout = 60000; // Cache weather data for 60 seconds
        }

        /**
         * Lifecycle: Component connected to DOM
         */
        async connectedCallback() {
            // Load shared scripts first
            await this.loadSharedScripts();
            this.render();
            this.startAutoRefresh();
        }

        /**
         * Lifecycle: Component disconnected from DOM
         */
        disconnectedCallback() {
            this.stopAutoRefresh();
        }

        /**
         * Define observed attributes (triggers attributeChangedCallback when changed)
         */
        static get observedAttributes() {
            return [
                'airport', 'style', 'theme', 'webcam', 'cams',
                'temp', 'dist', 'wind', 'baro', 'target', 'refresh',
                'width', 'height'
            ];
        }

        /**
         * Lifecycle: Attribute changed
         */
        attributeChangedCallback(name, oldValue, newValue) {
            if (oldValue !== newValue && this.shadowRoot.innerHTML !== '') {
                // Re-render if already rendered
                this.render();
            }
        }

        /**
         * Get attribute with default value
         */
        getAttr(name, defaultValue) {
            return this.getAttribute(name) || defaultValue;
        }

        /**
         * Parse and validate attributes
         */
        parseAttributes() {
            const airport = this.getAttr('airport', '');
            const style = this.getAttr('style', 'card');
            const theme = this.getAttr('theme', 'auto');
            const webcam = parseInt(this.getAttr('webcam', '0'), 10);
            const camsStr = this.getAttr('cams', '0,1,2,3');
            const temp = this.getAttr('temp', 'F');
            const dist = this.getAttr('dist', 'ft');
            const wind = this.getAttr('wind', 'kt');
            const baro = this.getAttr('baro', 'inHg');
            const target = this.getAttr('target', '_blank');
            const refresh = parseInt(this.getAttr('refresh', '300000'), 10);

            // Parse camera indices
            const cams = camsStr.split(',').map((c) => parseInt(c.trim(), 10)).filter((c) => !isNaN(c));
            // Ensure we have 4 camera indices
            while (cams.length < 4) {
                cams.push(cams.length);
            }

            // Get dimensions (use preset defaults if not specified)
            const preset = SIZE_PRESETS[style] || SIZE_PRESETS.card;
            const width = parseInt(this.getAttr('width', preset.width.toString()), 10);
            const height = parseInt(this.getAttr('height', preset.height.toString()), 10);

            // Validate values
            const validStyles = ['card', 'webcam', 'dual', 'multi', 'full-single', 'full-dual', 'full-multi'];
            const validThemes = ['light', 'dark', 'auto'];
            const validTemps = ['F', 'C'];
            const validDists = ['ft', 'm'];
            const validWinds = ['kt', 'mph', 'kmh'];
            const validBaros = ['inHg', 'hPa', 'mmHg'];
            const validTargets = ['_blank', '_self', '_parent', '_top'];

            return {
                airport,
                style: validStyles.includes(style) ? style : 'card',
                theme: validThemes.includes(theme) ? theme : 'auto',
                webcam,
                cams: cams.slice(0, 4),
                temp: validTemps.includes(temp) ? temp : 'F',
                dist: validDists.includes(dist) ? dist : 'ft',
                wind: validWinds.includes(wind) ? wind : 'kt',
                baro: validBaros.includes(baro) ? baro : 'inHg',
                target: validTargets.includes(target) ? target : '_blank',
                refresh: Math.max(refresh, 60000), // Minimum 1 minute refresh
                width,
                height
            };
        }

        /**
         * Fetch weather data from API
         */
        async fetchWeatherData(airport) {
            // Check cache to prevent redundant API calls
            const now = Date.now();
            if (this.weatherData && (now - this.lastFetchTime) < this.cacheTimeout) {
                return this.weatherData;
            }

            try {
                const response = await fetch(`${BASE_URL}/api/weather.php?airport=${encodeURIComponent(airport)}`);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                const data = await response.json();
                
                // Cache the data
                this.weatherData = data;
                this.lastFetchTime = now;
                
                return data;
            } catch (error) {
                console.error('[AviationWX] Failed to fetch weather data:', error);
                throw error;
            }
        }

        /**
         * Start auto-refresh timer
         */
        startAutoRefresh() {
            this.stopAutoRefresh(); // Clear any existing timer
            
            const attrs = this.parseAttributes();
            this.refreshTimer = setInterval(() => {
                this.updateWeatherData();
            }, attrs.refresh);
        }

        /**
         * Stop auto-refresh timer
         */
        stopAutoRefresh() {
            if (this.refreshTimer) {
                clearInterval(this.refreshTimer);
                this.refreshTimer = null;
            }
        }

        /**
         * Update weather data (called by auto-refresh)
         */
        async updateWeatherData() {
            const attrs = this.parseAttributes();
            if (!attrs.airport) {
                return;
            }

            try {
                // Show subtle loading indicator (don't clear existing data)
                this.showRefreshingState();
                
                // Fetch new data
                const data = await this.fetchWeatherData(attrs.airport);
                
                // Re-render with new data
                this.renderWithData(data, attrs);
            } catch (error) {
                // Show error state but keep existing data visible
                this.showErrorState(error);
            }
        }

        /**
         * Render the widget
         */
        async render() {
            const attrs = this.parseAttributes();

            // Validate required attributes
            if (!attrs.airport) {
                this.showErrorMessage('Error: airport attribute is required');
                return;
            }

            // Ensure shared scripts are loaded
            await this.loadSharedScripts();

            // Show loading state
            this.showLoadingState(attrs);

            // Fetch weather data
            try {
                const data = await this.fetchWeatherData(attrs.airport);
                this.renderWithData(data, attrs);
            } catch (error) {
                this.showErrorState(error);
            }
        }

        /**
         * Show loading state
         */
        showLoadingState(attrs) {
            const container = document.createElement('div');
            container.className = 'widget-container loading';
            container.style.width = `${attrs.width}px`;
            container.style.height = `${attrs.height}px`;
            container.innerHTML = `
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    <p>Loading weather data...</p>
                </div>
            `;

            this.shadowRoot.innerHTML = `
                ${this.getStyles(attrs)}
                ${container.outerHTML}
            `;
        }

        /**
         * Show refreshing state (subtle indicator)
         */
        showRefreshingState() {
            const indicator = this.shadowRoot.querySelector('.refresh-indicator');
            if (indicator) {
                indicator.classList.add('active');
                setTimeout(() => {
                    indicator.classList.remove('active');
                }, 1000);
            }
        }

        /**
         * Show error state
         */
        showErrorState(error) {
            const container = this.shadowRoot.querySelector('.widget-container');
            if (container) {
                const errorBanner = container.querySelector('.error-banner');
                if (errorBanner) {
                    errorBanner.textContent = `Error: ${error.message}`;
                    errorBanner.style.display = 'block';
                }
            }
        }

        /**
         * Show error message (before rendering)
         */
        showErrorMessage(message) {
            this.shadowRoot.innerHTML = `
                <style>
                    .error-container {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        min-width: 300px;
                        min-height: 100px;
                        padding: 20px;
                        background: #fff;
                        border: 2px solid #dc3545;
                        border-radius: 8px;
                        color: #dc3545;
                        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    }
                </style>
                <div class="error-container">
                    <p>${message}</p>
                </div>
            `;
        }

        /**
         * Render with weather data
         */
        async renderWithData(data, attrs) {
            if (!data || !data.success) {
                this.showErrorMessage('Error: Failed to load weather data');
                return;
            }

            // Ensure shared scripts are loaded before injecting HTML
            await this.loadSharedScripts();

            // Fetch HTML from PHP template system (async)
            const html = await this.generateWidgetHTML(data, attrs);
            const themeClass = this.getThemeClass(attrs.theme);
            
            // For auto mode, determine actual theme based on system preference
            let actualThemeClass = themeClass;
            if (attrs.theme === 'auto' && typeof window.matchMedia !== 'undefined') {
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                actualThemeClass = prefersDark ? 'theme-dark' : 'theme-light';
            }
            
            // Apply theme class to container
            const containerClass = actualThemeClass ? ` ${actualThemeClass}` : '';
            const dashboardUrl = getDashboardUrl(attrs.airport);
            const target = attrs.target || '_blank';
            const relAttr = target === '_blank' ? ' rel="noopener"' : '';

            // Wrap widget in anchor so entire widget is clickable (matches iframe embed behavior)
            this.shadowRoot.innerHTML = `
                ${this.getStyles(attrs)}
                <a href="${dashboardUrl}" target="${target}"${relAttr} class="embed-container${containerClass}">
                    ${html}
                </a>
            `;

            // Execute any scripts that were included in the HTML (wind compass initialization)
            // Scripts in innerHTML don't execute automatically, so we need to run them
            const scripts = this.shadowRoot.querySelectorAll('script');
            scripts.forEach(oldScript => {
                // Create new script in shadow root context
                const newScript = document.createElement('script');
                Array.from(oldScript.attributes).forEach(attr => {
                    newScript.setAttribute(attr.name, attr.value);
                });
                
                // Use the script content as-is - it should work in shadow DOM
                newScript.textContent = oldScript.textContent;
                oldScript.parentNode.replaceChild(newScript, oldScript);
            });
            
            // Listen for system preference changes in auto mode
            if (attrs.theme === 'auto' && typeof window.matchMedia !== 'undefined') {
                const darkModeQuery = window.matchMedia('(prefers-color-scheme: dark)');
                const updateTheme = () => {
                    const prefersDark = darkModeQuery.matches;
                    const newThemeClass = prefersDark ? 'theme-dark' : 'theme-light';
                    const container = this.shadowRoot.querySelector('.embed-container');
                    if (container) {
                        container.classList.remove('theme-light', 'theme-dark', 'theme-auto');
                        container.classList.add(newThemeClass);
                    }
                };
                
                // Use addEventListener if available (modern browsers)
                if (darkModeQuery.addEventListener) {
                    darkModeQuery.addEventListener('change', updateTheme);
                } else {
                    // Fallback for older browsers
                    darkModeQuery.addListener(updateTheme);
                }
            }
            
            // Debug: Check if webcam image exists and is visible (use setTimeout to ensure DOM is ready)
            setTimeout(() => {
                const webcamImage = this.shadowRoot.querySelector('.webcam-image');
                const placeholder = this.shadowRoot.querySelector('.no-webcam-placeholder');
                
                console.log('[AviationWX] Shadow DOM check:', {
                    hasImage: !!webcamImage,
                    hasPlaceholder: !!placeholder,
                    imageSrc: webcamImage?.src,
                    htmlContainsImg: html.includes('<img'),
                    htmlLength: html.length
                });
                
                if (webcamImage) {
                    const computedStyle = window.getComputedStyle(webcamImage);
                    console.log('[AviationWX] Webcam image found - src:', webcamImage.src);
                    console.log('[AviationWX] Webcam image - display:', computedStyle.display, 'width:', webcamImage.offsetWidth, 'height:', webcamImage.offsetHeight);
                    console.log('[AviationWX] Webcam image - aspectRatio (inline):', webcamImage.style.aspectRatio, 'computed:', computedStyle.aspectRatio);
                    console.log('[AviationWX] Webcam image - naturalWidth:', webcamImage.naturalWidth, 'naturalHeight:', webcamImage.naturalHeight);
                    
                    // Ensure image loads - add error handler
                    webcamImage.onerror = function() {
                        console.error('[AviationWX] Webcam image failed to load:', this.src);
                    };
                    webcamImage.onload = function() {
                        console.log('[AviationWX] Webcam image loaded - naturalWidth:', this.naturalWidth, 'naturalHeight:', this.naturalHeight, 'offsetWidth:', this.offsetWidth, 'offsetHeight:', this.offsetHeight);
                        
                        // If image has natural dimensions, update aspect ratio to match actual image
                        if (this.naturalWidth > 0 && this.naturalHeight > 0) {
                            const actualAspectRatio = this.naturalWidth / this.naturalHeight;
                            const currentAspectRatio = parseFloat(this.style.aspectRatio) || parseFloat(window.getComputedStyle(this).aspectRatio);
                            
                            // If aspect ratio differs significantly (more than 5%), update it
                            if (Math.abs(actualAspectRatio - currentAspectRatio) > 0.05) {
                                console.log('[AviationWX] Updating aspect ratio from', currentAspectRatio, 'to', actualAspectRatio);
                                this.style.aspectRatio = actualAspectRatio.toString();
                            }
                        }
                    };
                } else {
                    console.warn('[AviationWX] No webcam image found in shadow DOM. HTML contains img tag:', html.includes('<img'));
                }
            }, 100);

            // Always manually initialize canvas to ensure runways are drawn
            // This is more reliable than relying on script tag execution in shadow DOM
            setTimeout(async () => {
                await this.initializeCanvas(data, attrs);
            }, 250);
        }

        /**
         * Generate widget HTML based on style
         * Now fetches from PHP template system for maximum code reuse
         */
        async generateWidgetHTML(data, attrs) {
            // Fetch HTML from PHP template system (single source of truth)
            try {
                const html = await this.fetchWidgetHTML(attrs);
                console.log('[AviationWX] Fetched widget HTML length:', html.length, 'contains img:', html.includes('<img'));
                return html;
            } catch (error) {
                console.error('[AviationWX] Failed to fetch widget HTML:', error);
                // Show error message instead of fallback renderer
                return `
                    <div class="no-data" style="padding: 20px; text-align: center;">
                        <div class="icon" style="font-size: 2rem; margin-bottom: 10px;">⚠️</div>
                        <p style="font-weight: bold; margin-bottom: 5px;">Widget unavailable</p>
                        <p style="font-size: 0.9rem; color: var(--muted-color);">Unable to load widget from server.</p>
                    </div>
                `;
            }
        }

        /**
         * Fetch widget HTML from PHP template system
         * This ensures both iframe and web component use the exact same templates
         */
        async fetchWidgetHTML(attrs) {
            const params = new URLSearchParams({
                airport: attrs.airport,
                style: attrs.style,
                theme: attrs.theme,
                temp: attrs.temp,
                dist: attrs.dist,
                wind: attrs.wind,
                baro: attrs.baro,
                target: attrs.target
            });
            
            if (attrs.style === 'webcam') {
                params.set('webcam', attrs.webcam.toString());
            }
            
            if (attrs.style === 'dual' || attrs.style === 'multi' || attrs.style === 'full-dual' || attrs.style === 'full-multi') {
                params.set('cams', attrs.cams.join(','));
            }
            
            const response = await fetch(`${BASE_URL}/api/embed-widget.php?${params.toString()}`);
            if (!response.ok) {
                throw new Error(`Failed to fetch widget: ${response.status}`);
            }
            
            return await response.text();
        }

        /**
         * Build webcam URL
         */
        buildWebcamUrl(airport, camIndex) {
            const dashboardUrl = window.location.origin;
            return `${dashboardUrl}/webcam.php?id=${encodeURIComponent(airport)}&cam=${camIndex}`;
        }

        /**
         * Initialize canvas rendering (wind compass)
         * Uses shared AviationWX.drawWindCompass() for consistency
         */
        async initializeCanvas(data, attrs) {
            // Wait for shared scripts to load
            if (!window.AviationWX || !window.AviationWX.drawWindCompass) {
                // Load shared scripts if not already loaded
                await this.loadSharedScripts();
            }
            
            await this.drawCompasses(data, attrs);
        }
        
        /**
         * Draw compasses using shared function
         * Fetches airport data if needed for runway information
         */
        async drawCompasses(data, attrs) {
            const canvases = this.shadowRoot.querySelectorAll('canvas');
            if (canvases.length === 0) {
                console.log('[AviationWX] No canvases found in shadow DOM');
                return;
            }

            const weather = data.weather || {};
            let airport = data.airport || {};
            const theme = attrs.theme;
            
            // Ensure AviationWX is loaded
            if (!window.AviationWX || !window.AviationWX.drawWindCompass) {
                console.warn('[AviationWX] Wind compass library not loaded, retrying...');
                setTimeout(() => this.drawCompasses(data, attrs), 100);
                return;
            }
            
            // Always fetch airport data to ensure we have runways
            // The weather API doesn't include airport metadata
            // Use the REST API endpoint: /api/v1/airports/{id}
            try {
                const airportResponse = await fetch(`${BASE_URL}/api/v1/airports/${encodeURIComponent(attrs.airport)}`);
                if (airportResponse.ok) {
                    const airportData = await airportResponse.json();
                    if (airportData.success && airportData.airport) {
                        airport = airportData.airport;
                        console.log('[AviationWX] Loaded airport data with runways:', airport.runways?.length || 0);
                    }
                } else {
                    console.warn('[AviationWX] Airport API returned status:', airportResponse.status);
                }
            } catch (error) {
                console.warn('[AviationWX] Failed to fetch airport data for runways:', error);
            }
            
            // Helper to detect dark mode
            const detectDarkMode = () => {
                if (theme === 'dark') return true;
                if (theme === 'light') return false;
                // Auto mode: detect from system preference
                if (typeof window.matchMedia !== 'undefined') {
                    return window.matchMedia('(prefers-color-scheme: dark)').matches;
                }
                return false;
            };
            
            // Function to draw all compasses with current theme
            const drawAllCompasses = () => {
                const isDark = detectDarkMode();
                
                canvases.forEach((canvas) => {
                    const width = canvas.width;
                    let size = 'medium';
                    if (width >= 100) size = 'large';
                    else if (width >= 80) size = 'medium';
                    else if (width >= 60) size = 'small';
                    else size = 'mini';
                    
                    // Clear canvas first
                    const ctx = canvas.getContext('2d');
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    
                    const runways = airport.runways || [];
                    
                    // Draw compass with runways
                    window.AviationWX.drawWindCompass(canvas, {
                        windSpeed: weather.wind_speed ?? null,
                        windDirection: weather.wind_direction ?? null,
                        isVRB: (weather.wind_direction_text || '') === 'VRB',
                        runways: runways,
                        isDark: isDark,
                        size: size
                    });
                });
            };
            
            // Draw compasses immediately
            drawAllCompasses();
            
            // Listen for theme changes in auto mode
            if (theme === 'auto' && typeof window.matchMedia !== 'undefined') {
                const darkModeQuery = window.matchMedia('(prefers-color-scheme: dark)');
                // Use addEventListener if available (modern browsers)
                if (darkModeQuery.addEventListener) {
                    darkModeQuery.addEventListener('change', () => {
                        drawAllCompasses();
                    });
                } else {
                    // Fallback for older browsers
                    darkModeQuery.addListener(() => {
                        drawAllCompasses();
                    });
                }
            }
        }
        
        /**
         * Load shared JavaScript files
         */
        async loadSharedScripts() {
            // Check if already loaded
            if (window.AviationWX && window.AviationWX.drawWindCompass && window.AviationWX.helpers) {
                return;
            }
            
            const scripts = [
                `${BASE_URL}/public/js/embed-wind-compass.js`,
                `${BASE_URL}/public/js/embed-helpers.js`
            ];
            
            const loadPromises = scripts.map(src => {
                return new Promise((resolve, reject) => {
                    // Check if already loaded
                    if (document.querySelector(`script[src="${src}"]`)) {
                        // Wait a bit for script to execute
                        setTimeout(resolve, 10);
                        return;
                    }
                    
                    const script = document.createElement('script');
                    script.src = src;
                    script.onload = () => setTimeout(resolve, 10); // Small delay for execution
                    script.onerror = reject;
                    document.head.appendChild(script);
                });
            });
            
            await Promise.all(loadPromises);
        }


        /**
         * Get widget styles - loads shared CSS file for consistency
         */
        getStyles(attrs) {
            // Load shared CSS file to match iframe embeds exactly
            const cssUrl = `${BASE_URL}/public/css/embed-widgets.css`;
            
            return `
                <link rel="stylesheet" href="${cssUrl}">
                <style>
                    /* Base styles for shadow DOM */
                    :host {
                        display: block;
                        max-width: 100%;
                    }
                    .embed-container {
                        width: 100%;
                        height: 100%;
                        display: flex;
                        flex-direction: column;
                    }
                    /* Ensure CSS variables work in shadow DOM for auto theme */
                    .embed-container.theme-light {
                        /* Light theme variables already set in CSS */
                    }
                    .embed-container.theme-dark {
                        /* Dark theme variables already set in CSS */
                    }
                </style>
            `;
        }
        
        /**
         * Get theme class name (matches PHP getThemeClass exactly)
         */
        getThemeClass(theme) {
            // Match PHP: returns 'theme-auto', 'theme-light', or 'theme-dark'
            if (theme === 'auto') {
                return 'theme-auto';
            }
            return theme === 'dark' ? 'theme-dark' : 'theme-light';
        }

        /**
         * Get theme-specific CSS variables
         */
        getThemeStyles(theme) {
            if (theme === 'auto') {
                return `
                    :host {
                        /* Light mode colors (default) */
                        --bg-color: #ffffff;
                        --card-bg: #f8f9fa;
                        --text-color: #333333;
                        --muted-color: #666666;
                        --border-color: #dddddd;
                        --accent-color: #0066cc;
                        --footer-bg: rgba(0,0,0,0.05);
                        --unknown-bg: #888;
                    }
                    
                    @media (prefers-color-scheme: dark) {
                        :host {
                            /* Dark mode colors (auto-detected) */
                            --bg-color: #1a1a1a;
                            --card-bg: #242424;
                            --text-color: #e0e0e0;
                            --muted-color: #888888;
                            --border-color: #333333;
                            --accent-color: #0066cc;
                            --footer-bg: rgba(0,0,0,0.3);
                            --unknown-bg: #444;
                        }
                    }
                `;
            } else if (theme === 'dark') {
                return `
                    :host {
                        --bg-color: #1a1a1a;
                        --card-bg: #242424;
                        --text-color: #e0e0e0;
                        --muted-color: #888888;
                        --border-color: #333333;
                        --accent-color: #0066cc;
                        --footer-bg: rgba(0,0,0,0.3);
                        --unknown-bg: #444;
                    }
                `;
            } else {
                return `
                    :host {
                        --bg-color: #ffffff;
                        --card-bg: #f8f9fa;
                        --text-color: #333333;
                        --muted-color: #666666;
                        --border-color: #dddddd;
                        --accent-color: #0066cc;
                        --footer-bg: rgba(0,0,0,0.05);
                        --unknown-bg: #888;
                    }
                `;
            }
        }

        /**
         * Get base styles
         */
        getBaseStyles() {
            return `
                :host {
                    display: block;
                    max-width: 100%;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
                }
                
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                a {
                    color: inherit;
                    text-decoration: none;
                }
                
                a:hover {
                    text-decoration: underline;
                }
                
                .widget-container {
                    background: var(--bg-color);
                    color: var(--text-color);
                    border: 1px solid var(--border-color);
                    border-radius: 8px;
                    overflow: hidden;
                    display: flex;
                    flex-direction: column;
                    max-width: 100%;
                    box-sizing: border-box;
                }
                
                .error-banner {
                    background: #dc3545;
                    color: white;
                    padding: 8px;
                    text-align: center;
                    font-size: 12px;
                }
                
                .refresh-indicator {
                    position: absolute;
                    top: 8px;
                    right: 8px;
                    width: 8px;
                    height: 8px;
                    border-radius: 50%;
                    background: var(--accent-color);
                    opacity: 0;
                    transition: opacity 0.3s;
                }
                
                .refresh-indicator.active {
                    opacity: 1;
                    animation: pulse 1s ease-in-out;
                }
                
                @keyframes pulse {
                    0%, 100% { opacity: 1; transform: scale(1); }
                    50% { opacity: 0.5; transform: scale(1.2); }
                }
                
                .loading-spinner {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    height: 100%;
                    padding: 20px;
                }
                
                .spinner {
                    width: 40px;
                    height: 40px;
                    border: 4px solid var(--border-color);
                    border-top-color: var(--accent-color);
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                }
                
                @keyframes spin {
                    to { transform: rotate(360deg); }
                }
                
                .loading-spinner p {
                    margin-top: 16px;
                    color: var(--muted-color);
                    font-size: 14px;
                }
            `;
        }

        /**
         * Get card style-specific styles
         */
        getCardStyles() {
            return `
                .widget-link {
                    display: block;
                    height: 100%;
                    text-decoration: none;
                    color: inherit;
                }
                
                .style-card {
                    display: flex;
                    flex-direction: column;
                    height: calc(100% - 40px);
                }
                
                .card-header {
                    background: var(--card-bg);
                    padding: 0.75rem 1rem;
                    border-bottom: 1px solid var(--border-color);
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                
                .card-header .airport-info h2 {
                    font-size: 1rem;
                    font-weight: 600;
                    margin: 0 0 0.25rem 0;
                }
                
                .card-header .airport-meta {
                    display: flex;
                    gap: 0.5rem;
                    align-items: center;
                }
                
                .card-header .identifier {
                    font-size: 0.85rem;
                    color: var(--accent-color);
                    font-weight: 600;
                }
                
                .card-header .webcam-count {
                    font-size: 0.75rem;
                    color: var(--muted-color);
                }
                
                .card-header .flight-category {
                    padding: 0.25rem 0.5rem;
                    border-radius: 4px;
                    font-size: 0.75rem;
                    font-weight: 700;
                    letter-spacing: 0.5px;
                }
                
                .card-body {
                    flex: 1;
                    padding: 1rem;
                    display: flex;
                    flex-direction: column;
                    gap: 0.75rem;
                }
                
                .weather-row {
                    display: flex;
                    gap: 0.75rem;
                }
                
                .weather-row .item {
                    flex: 1;
                    display: flex;
                    flex-direction: column;
                    gap: 0.25rem;
                }
                
                .weather-row .item.wind-mini {
                    flex: 0 0 60px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                
                .weather-row .item.wind-block {
                    flex: 1.2;
                }
                
                .weather-row .item .label {
                    font-size: 0.65rem;
                    color: var(--muted-color);
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    font-weight: 600;
                }
                
                .weather-row .item .value {
                    font-size: 0.9rem;
                    font-weight: 600;
                    line-height: 1.2;
                }
                
                .embed-footer {
                    height: 40px;
                    background: var(--footer-bg);
                    border-top: 1px solid var(--border-color);
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 0 1rem;
                    font-size: 0.7rem;
                }
                
                .embed-footer .footer-left {
                    color: var(--muted-color);
                }
                
                .embed-footer .footer-center {
                    color: var(--accent-color);
                    font-weight: 600;
                }
                
                .embed-footer .footer-right {
                    color: var(--muted-color);
                }
            `;
        }

        /**
         * Get webcam style-specific styles
         */
        getWebcamStyles() {
            return `
                /* Webcam Style (450x450) */
                .style-webcam {
                    display: flex;
                    flex-direction: column;
                    cursor: pointer;
                    /* Don't force height: 100% - let content determine height */
                    min-height: 0;
                }

                .style-webcam .webcam-container {
                    position: relative;
                    background: #000;
                    overflow: hidden;
                    width: 100%;
                    /* Container adapts to image size, always full width */
                    flex-shrink: 0;
                    /* Let image determine container height based on its aspect ratio */
                    /* Don't set height constraints - image's inline aspect-ratio will control sizing */
                    /* Container will grow to fit the image's calculated height */
                }

                .style-webcam .webcam-image,
                .style-webcam picture {
                    /* Aspect ratio and sizing set inline from PHP template */
                    /* The inline style="aspect-ratio: X; width: 100%; height: auto;" from PHP template controls sizing */
                    display: block !important;
                    width: 100% !important;
                    height: auto !important;
                    /* Ensure image is visible - inline styles from PHP template should control aspect ratio */
                    /* Don't use object-fit - it conflicts with aspect-ratio CSS property */
                    visibility: visible !important;
                    opacity: 1 !important;
                    /* Ensure image takes up space - aspect-ratio will calculate height based on width */
                    /* Remove min-height as it can interfere with aspect-ratio calculation */
                }
                
                .style-webcam picture img {
                    width: 100% !important;
                    height: auto !important;
                    display: block !important;
                }

                .style-webcam .no-webcam-placeholder {
                    display: none; /* Hide by default - only show if no image */
                    align-items: center;
                    justify-content: center;
                    height: 100%;
                    color: var(--muted-color);
                    background: var(--card-bg);
                }
                
                /* Show placeholder only when image doesn't exist or failed to load */
                .style-webcam .webcam-container:not(:has(img.webcam-image)) .no-webcam-placeholder,
                .style-webcam .webcam-container img.webcam-image[src=""],
                .style-webcam .webcam-container img.webcam-image:not([src]) {
                    display: flex;
                }

                .style-webcam .overlay-info {
                    position: absolute;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    background: linear-gradient(transparent, rgba(0,0,0,0.8));
                    padding: 1.5rem 0.75rem 0.5rem;
                    color: white;
                }

                .style-webcam .overlay-row {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }

                .style-webcam .overlay-left {
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                }

                .style-webcam .overlay-left .code {
                    font-weight: 700;
                    font-size: 1.1rem;
                }

                .style-webcam .weather-bar {
                    background: var(--card-bg);
                    padding: 0.5rem 0.75rem;
                    display: flex;
                    justify-content: space-around;
                    border-top: 1px solid var(--border-color);
                }

                .style-webcam .weather-bar .item {
                    text-align: center;
                    font-size: 0.85rem;
                }

                .style-webcam .weather-bar .item .label {
                    font-size: 0.65rem;
                    color: var(--muted-color);
                    text-transform: uppercase;
                }

                .style-webcam .weather-bar .item .value {
                    font-weight: 600;
                }

                .style-webcam .weather-bar .wind-mini {
                    padding: 0;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }

                .style-webcam .weather-bar .wind-mini canvas {
                    display: block;
                }
            `;
        }

        /**
         * Get dual style-specific styles
         */
        getDualStyles() {
            return `
                /* Dual Camera Style (600x300) */
                .style-dual {
                    height: 100%;
                    display: flex;
                    flex-direction: column;
                    cursor: pointer;
                }

                .style-dual .dual-header {
                    background: var(--card-bg);
                    padding: 0.5rem 1rem;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    border-bottom: 1px solid var(--border-color);
                }

                .style-dual .dual-header h2 {
                    font-size: 1rem;
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                }

                .style-dual .dual-header .code {
                    color: var(--accent-color);
                }

                .style-dual .dual-header .cam-count {
                    font-size: 0.7rem;
                    font-weight: normal;
                    color: var(--muted-color);
                    margin-left: 0.5rem;
                }

                .style-dual .dual-webcam-grid {
                    flex: 1;
                    display: grid;
                    grid-template-columns: repeat(2, 1fr);
                    gap: 2px;
                    background: var(--border-color);
                }

                .style-dual .dual-webcam-cell {
                    position: relative;
                    background: #000;
                    overflow: hidden;
                    aspect-ratio: 16/9;
                }

                .style-dual .dual-webcam-cell img,
                .style-dual .dual-webcam-cell picture {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    display: block;
                }
                
                .style-dual .dual-webcam-cell picture img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    display: block;
                }

                .style-dual .dual-webcam-cell .cam-label {
                    position: absolute;
                    bottom: 0.25rem;
                    left: 0.25rem;
                    background: rgba(0,0,0,0.7);
                    color: white;
                    padding: 0.15rem 0.4rem;
                    font-size: 0.7rem;
                    border-radius: 3px;
                }

                .style-dual .dual-webcam-cell.no-cam {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: var(--card-bg);
                }

                .style-dual .dual-webcam-cell .no-webcam-placeholder {
                    color: var(--muted-color);
                    font-size: 0.85rem;
                }

                .style-dual .dual-weather-bar {
                    background: var(--card-bg);
                    padding: 0.5rem 1rem;
                    display: flex;
                    justify-content: space-around;
                    align-items: center;
                    border-top: 1px solid var(--border-color);
                }

                .style-dual .dual-weather-bar .item {
                    text-align: center;
                }

                .style-dual .dual-weather-bar .label {
                    font-size: 0.65rem;
                    color: var(--muted-color);
                    text-transform: uppercase;
                }

                .style-dual .dual-weather-bar .value {
                    font-size: 0.9rem;
                    font-weight: 600;
                }

                .style-dual .dual-weather-bar .wind-mini {
                    padding: 0;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }

                .style-dual .dual-weather-bar .wind-mini canvas {
                    display: block;
                }
            `;
        }

        /**
         * Get multi style-specific styles
         */
        getMultiStyles() {
            return `
                /* Multi Camera Style (600x475) */
                .style-multi {
                    height: 100%;
                    display: flex;
                    flex-direction: column;
                    cursor: pointer;
                }

                .style-multi .multi-header {
                    background: var(--card-bg);
                    padding: 0.5rem 1rem;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    border-bottom: 1px solid var(--border-color);
                }

                .style-multi .multi-header h2 {
                    font-size: 1rem;
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                }

                .style-multi .multi-header .code {
                    color: var(--accent-color);
                }

                .style-multi .multi-header .cam-count {
                    font-size: 0.7rem;
                    font-weight: normal;
                    color: var(--muted-color);
                    margin-left: 0.5rem;
                }

                .style-multi .webcam-grid {
                    flex: 1;
                    display: grid;
                    gap: 2px;
                    background: var(--border-color);
                }

                .style-multi .webcam-grid.cams-1 {
                    grid-template-columns: 1fr;
                }

                .style-multi .webcam-grid.cams-2 {
                    grid-template-columns: repeat(2, 1fr);
                }

                .style-multi .webcam-grid.cams-3,
                .style-multi .webcam-grid.cams-4 {
                    grid-template-columns: repeat(2, 1fr);
                    grid-template-rows: repeat(2, 1fr);
                }

                .style-multi .webcam-cell {
                    position: relative;
                    background: #000;
                    overflow: hidden;
                }

                .style-multi .webcam-cell img,
                .style-multi .webcam-cell picture {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    display: block;
                }
                
                .style-multi .webcam-cell picture img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    display: block;
                }

                .style-multi .webcam-cell .cam-label {
                    position: absolute;
                    bottom: 0.25rem;
                    left: 0.25rem;
                    background: rgba(0,0,0,0.7);
                    color: white;
                    padding: 0.15rem 0.4rem;
                    font-size: 0.65rem;
                    border-radius: 3px;
                }

                .style-multi .webcam-cell.no-cams {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: var(--card-bg);
                }

                .style-multi .webcam-cell .no-webcam-placeholder {
                    color: var(--muted-color);
                    font-size: 0.85rem;
                }

                .style-multi .weather-summary {
                    background: var(--card-bg);
                    padding: 0.5rem 1rem;
                    display: flex;
                    justify-content: space-around;
                    align-items: center;
                    border-top: 1px solid var(--border-color);
                }

                .style-multi .weather-summary .item {
                    text-align: center;
                }

                .style-multi .weather-summary .label {
                    font-size: 0.65rem;
                    color: var(--muted-color);
                    text-transform: uppercase;
                }

                .style-multi .weather-summary .value {
                    font-size: 0.9rem;
                    font-weight: 600;
                }

                .style-multi .weather-summary .wind-mini {
                    padding: 0;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }

                .style-multi .weather-summary .wind-mini canvas {
                    display: block;
                }
            `;
        }


        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    // Register custom element
    customElements.define('aviation-wx', AviationWXWidget);

})();
