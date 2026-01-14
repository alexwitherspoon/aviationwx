/**
 * Shared Wind Compass Drawing for AviationWX Embed Widgets
 * 
 * Used by:
 * - iframe embeds (pages/embed.php)
 * - Web components (public/js/widget.js)
 * 
 * This ensures visual consistency across all embed methods.
 */

(function(window) {
    'use strict';
    
    // Constants
    const CALM_WIND_THRESHOLD = 3; // Winds below 3 knots are considered calm in aviation
    
    /**
     * Draw wind compass on canvas
     * 
     * @param {HTMLCanvasElement} canvas - Canvas element to draw on
     * @param {Object} options - Configuration options
     * @param {number|null} options.windSpeed - Wind speed in knots
     * @param {number|null} options.windDirection - Wind direction in degrees
     * @param {boolean} options.isVRB - Whether wind is variable
     * @param {Array} options.runways - Array of runway objects with heading_1 property
     * @param {boolean} options.isDark - Whether to use dark theme colors
     * @param {string} options.size - Size variant: 'mini' (50px), 'small' (60px), 'medium' (80px), 'large' (100px+)
     */
    function drawWindCompass(canvas, options) {
        if (!canvas || !canvas.getContext) {
            console.error('[AviationWX] Invalid canvas element');
            return;
        }
        
        const ctx = canvas.getContext('2d');
        const width = canvas.width;
        const height = canvas.height;
        const cx = width / 2;
        const cy = height / 2;
        const r = Math.min(width, height) / 2 - 5;
        
        // Parse options
        const windSpeed = options.windSpeed ?? null;
        const windDir = options.windDirection ?? null;
        const isVRB = options.isVRB ?? false;
        const runways = options.runways ?? [];
        const isDark = options.isDark ?? false;
        const size = options.size ?? 'medium';
        
        // Clear canvas
        ctx.clearRect(0, 0, width, height);
        
        // Draw compass circle - dark gray outline in light mode (visible on light gray bg), dark gray in dark mode (blends with background)
        ctx.strokeStyle = isDark ? '#888' : '#999';
        ctx.lineWidth = width > 80 ? 1.5 : 1;
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, 2 * Math.PI);
        ctx.stroke();
        
        // Draw primary runway if available
        if (runways.length > 0 && runways[0].heading_1 !== undefined) {
            const h1 = runways[0].heading_1;
            const h2 = runways[0].heading_2 !== undefined ? runways[0].heading_2 : (h1 + 180) % 360;
            const angle = (h1 * Math.PI) / 180;
            const rwLen = r * 0.65; // Shorter to avoid overlapping cardinal letters
            
            // Runway line - ensure visibility in both light and dark modes
            ctx.strokeStyle = isDark ? '#aaa' : '#999'; // Lighter in dark mode for visibility
            ctx.lineWidth = width > 80 ? 6 : 4;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.moveTo(cx - Math.sin(angle) * rwLen, cy + Math.cos(angle) * rwLen);
            ctx.lineTo(cx + Math.sin(angle) * rwLen, cy - Math.cos(angle) * rwLen);
            ctx.stroke();
            
            // Draw runway numbers on larger compasses
            if (width > 100) {
                const rwy1 = Math.round(h1 / 10);
                const rwy2 = Math.round(h2 / 10);
                const labelDist = rwLen + 5;
                
                ctx.font = 'bold 11px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                
                // Draw runway number outlines
                ctx.strokeStyle = isDark ? '#000' : '#fff';
                ctx.lineWidth = 3;
                ctx.lineJoin = 'round';
                
                // Runway 1 (top)
                ctx.strokeText(rwy1, cx + Math.sin(angle) * labelDist, cy - Math.cos(angle) * labelDist);
                // Runway 2 (bottom)
                ctx.strokeText(rwy2, cx - Math.sin(angle) * labelDist, cy + Math.cos(angle) * labelDist);
                
                // Draw runway numbers - same blue as CALM and accent color
                ctx.fillStyle = '#5eb3ff';
                ctx.fillText(rwy1, cx + Math.sin(angle) * labelDist, cy - Math.cos(angle) * labelDist);
                ctx.fillText(rwy2, cx - Math.sin(angle) * labelDist, cy + Math.cos(angle) * labelDist);
            }
        }
        
        // Draw cardinal directions with outline for legibility
        const cardinalFontSize = width > 100 ? 11 : (width > 80 ? 10 : 9);
        ctx.font = `bold ${cardinalFontSize}px sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        
        const cardinals = [
            { label: 'N', angle: 0 },
            { label: 'E', angle: 90 },
            { label: 'S', angle: 180 },
            { label: 'W', angle: 270 }
        ];
        
        // Position cardinals ON the circle (slightly inset from edge)
        const cardinalDist = r - (width > 100 ? 12 : 10);
        
        cardinals.forEach(({ label, angle }) => {
            const ang = (angle * Math.PI) / 180;
            const x = cx + Math.sin(ang) * cardinalDist;
            const y = cy - Math.cos(ang) * cardinalDist;
            
            // Draw outline for legibility
            ctx.strokeStyle = isDark ? '#000' : '#fff';
            ctx.lineWidth = 2.5;
            ctx.lineJoin = 'round';
            ctx.strokeText(label, x, y);
            
            // Draw text - much lighter for visibility
            ctx.fillStyle = isDark ? '#ddd' : '#666';
            ctx.fillText(label, x, y);
        });
        
        // Draw wind arrow or status
        if (windSpeed !== null && windSpeed >= CALM_WIND_THRESHOLD && windDir !== null && !isVRB) {
            drawWindArrow(ctx, cx, cy, r, windSpeed, windDir, width, isDark);
        } else if (isVRB && windSpeed !== null && windSpeed >= CALM_WIND_THRESHOLD) {
            drawVRBIndicator(ctx, cx, cy, width, isDark);
        } else {
            drawCalmIndicator(ctx, cx, cy, width, isDark);
        }
    }
    
    /**
     * Draw wind arrow
     */
    function drawWindArrow(ctx, cx, cy, r, windSpeed, windDir, canvasWidth, isDark) {
        // Convert wind direction to "towards" angle
        const windAngle = ((windDir + 180) % 360) * Math.PI / 180;
        
        // Scale arrow length based on wind speed and canvas size
        let arrowLen;
        let headSize;
        let lineWidth;
        
        if (canvasWidth > 80) {
            // Large compass (full widget)
            arrowLen = Math.min(windSpeed * 3, r - 15);
            headSize = 8;
            lineWidth = 3;
            
            // Add wind glow for large compass
            ctx.fillStyle = 'rgba(220, 53, 69, 0.15)';
            ctx.beginPath();
            ctx.arc(cx, cy, Math.max(12, windSpeed * 2), 0, 2 * Math.PI);
            ctx.fill();
        } else if (canvasWidth >= 60) {
            // Medium compass (card/webcam styles)
            arrowLen = Math.min(windSpeed * 1.5, r - 8);
            headSize = 6;
            lineWidth = 2;
        } else {
            // Small compass (mini indicators)
            arrowLen = Math.min(windSpeed * 1.5, r - 5);
            headSize = 5;
            lineWidth = 2;
        }
        
        const endX = cx + Math.sin(windAngle) * arrowLen;
        const endY = cy - Math.cos(windAngle) * arrowLen;
        
        // Draw arrow line with outline
        // Outline
        ctx.strokeStyle = isDark ? '#000' : '#fff';
        ctx.lineWidth = lineWidth + 2;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.lineTo(endX, endY);
        ctx.stroke();
        
        // Main arrow line
        ctx.strokeStyle = '#dc3545';
        ctx.lineWidth = lineWidth;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.lineTo(endX, endY);
        ctx.stroke();
        
        // Draw arrow head with outline
        const headAngle = Math.atan2(endY - cy, endX - cx);
        
        // Outline
        ctx.strokeStyle = isDark ? '#000' : '#fff';
        ctx.lineWidth = 2;
        ctx.lineJoin = 'round';
        ctx.beginPath();
        ctx.moveTo(endX, endY);
        ctx.lineTo(endX - headSize * Math.cos(headAngle - Math.PI / 6), 
                   endY - headSize * Math.sin(headAngle - Math.PI / 6));
        ctx.lineTo(endX - headSize * Math.cos(headAngle + Math.PI / 6), 
                   endY - headSize * Math.sin(headAngle + Math.PI / 6));
        ctx.closePath();
        ctx.stroke();
        
        // Main arrow head
        ctx.fillStyle = '#dc3545';
        ctx.beginPath();
        ctx.moveTo(endX, endY);
        ctx.lineTo(endX - headSize * Math.cos(headAngle - Math.PI / 6), 
                   endY - headSize * Math.sin(headAngle - Math.PI / 6));
        ctx.lineTo(endX - headSize * Math.cos(headAngle + Math.PI / 6), 
                   endY - headSize * Math.sin(headAngle + Math.PI / 6));
        ctx.closePath();
        ctx.fill();
    }
    
    /**
     * Draw VRB (variable) indicator
     */
    function drawVRBIndicator(ctx, cx, cy, canvasWidth, isDark) {
        const fontSize = canvasWidth > 80 ? 14 : (canvasWidth >= 60 ? 11 : 10);
        ctx.font = `bold ${fontSize}px sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        
        // Draw outline for legibility
        ctx.strokeStyle = isDark ? '#000' : '#fff';
        ctx.lineWidth = 3;
        ctx.lineJoin = 'round';
        ctx.strokeText('VRB', cx, cy);
        
        // Draw text
        ctx.fillStyle = '#dc3545';
        ctx.fillText('VRB', cx, cy);
    }
    
    /**
     * Draw CALM indicator - blue to match runway numbers
     */
    function drawCalmIndicator(ctx, cx, cy, canvasWidth, isDark) {
        const fontSize = canvasWidth > 100 ? 14 : (canvasWidth > 80 ? 12 : (canvasWidth >= 60 ? 10 : 9));
        ctx.font = `bold ${fontSize}px sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        
        // Draw outline for legibility
        ctx.strokeStyle = isDark ? '#000' : '#fff';
        ctx.lineWidth = 3;
        ctx.lineJoin = 'round';
        ctx.strokeText('CALM', cx, cy);
        
        // Draw text - blue, brighter to match airport code
        ctx.fillStyle = '#5eb3ff'; // Bright blue matching accent color
        ctx.fillText('CALM', cx, cy);
    }
    
    // Export to global namespace
    window.AviationWX = window.AviationWX || {};
    window.AviationWX.drawWindCompass = drawWindCompass;
    window.AviationWX.CALM_WIND_THRESHOLD = CALM_WIND_THRESHOLD;
    
})(window);
