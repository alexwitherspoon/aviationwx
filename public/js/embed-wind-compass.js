/**
 * Shared Wind Compass Drawing for AviationWX Embed Widgets
 *
 * Used by:
 * - iframe embeds (pages/embed.php)
 * - Web components (public/js/widget.js)
 *
 * Full embeds (full, full-single, full-dual, full-multi) use full mode
 * matching the main dashboard: runway segments, wind rose petals,
 * magnetic declination, staleness handling.
 */

(function(window) {
    'use strict';

    const CALM_WIND_THRESHOLD = 3; // Winds below 3 knots are considered calm in aviation

    /**
     * Check if a field is stale based on per-field observation time
     *
     * @param {string} fieldName - Field name to check
     * @param {Object} fieldObsTimeMap - Map of field -> Unix timestamp
     * @param {boolean} isMetarField - Use METAR threshold when true
     * @param {number} staleFailclosedSeconds - Non-METAR threshold
     * @param {number} metarStaleFailclosedSeconds - METAR threshold
     * @returns {boolean} True if field should be hidden (stale)
     */
    function isFieldStale(fieldName, fieldObsTimeMap, isMetarField, staleFailclosedSeconds, metarStaleFailclosedSeconds) {
        const obsTime = fieldObsTimeMap[fieldName];
        if (!obsTime || obsTime <= 0) {
            return true;
        }
        const now = Math.floor(Date.now() / 1000);
        const age = now - obsTime;
        const threshold = isMetarField ? metarStaleFailclosedSeconds : staleFailclosedSeconds;
        return age >= threshold;
    }

    /**
     * Draw full-mode wind compass (dashboard-matching)
     */
    function drawWindCompassFullMode(canvas, options) {
        const ctx = canvas.getContext('2d');
        const width = canvas.width;
        const height = canvas.height;
        const cx = width / 2;
        const cy = height / 2;
        const r = Math.min(width, height) / 2 - 20;

        const full = options.fullMode || {};
        const isDark = options.isDark ?? false;

        const colors = isDark ? {
            circle: '#888',
            runway: '#aaa',
            runwayLabel: '#ddd',
            labelOutline: '#000',
            compass: '#999',
            windArrow: '#dc3545',
            windRosePetal: 'rgba(220, 53, 69, 0.5)',
            windRosePetalStroke: 'rgba(220, 53, 69, 0.4)',
            calmText: '#ddd',
            vrbText: '#dc3545'
        } : {
            circle: '#333',
            runway: '#0066cc',
            runwayLabel: '#0066cc',
            labelOutline: '#ffffff',
            compass: '#666',
            windArrow: '#dc3545',
            windRosePetal: 'rgba(220, 53, 69, 0.5)',
            windRosePetalStroke: 'rgba(220, 53, 69, 0.4)',
            calmText: '#333',
            vrbText: '#dc3545'
        };

        ctx.clearRect(0, 0, width, height);

        ctx.strokeStyle = colors.circle;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, 2 * Math.PI);
        ctx.stroke();

        const runwayScale = 0.86;
        const LABEL_POSITION = 0.85;
        const MIN_LABEL_DIST = 18;

        const segments = full.runwaySegments || [];
        const labelPositions = [];

        segments.forEach(function(seg) {
            const sx = (seg.start && seg.start[0]) || 0;
            const sy = (seg.start && seg.start[1]) || 0;
            const ex = (seg.end && seg.end[0]) || 0;
            const ey = (seg.end && seg.end[1]) || 0;
            const rw = r * runwayScale;
            const startX = cx + rw * sx;
            const startY = cy - rw * sy;
            const endX = cx + rw * ex;
            const endY = cy - rw * ey;

            ctx.strokeStyle = colors.runway;
            ctx.lineWidth = 8;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.moveTo(startX, startY);
            ctx.lineTo(endX, endY);
            ctx.stroke();

            const leIdent = seg.le_ident || '';
            const heIdent = seg.he_ident || '';
            const labelAtStart = LABEL_POSITION * sx + (1 - LABEL_POSITION) * ex;
            const labelAtStartY = LABEL_POSITION * sy + (1 - LABEL_POSITION) * ey;
            const labelAtEnd = LABEL_POSITION * ex + (1 - LABEL_POSITION) * sx;
            const labelAtEndY = LABEL_POSITION * ey + (1 - LABEL_POSITION) * sy;
            const identAtStart = seg.ident_at_start !== undefined ? seg.ident_at_start : leIdent;
            const identAtEnd = seg.ident_at_end !== undefined ? seg.ident_at_end : heIdent;

            labelPositions.push({ x: cx + rw * labelAtStart, y: cy - rw * labelAtStartY, ident: identAtStart });
            labelPositions.push({ x: cx + rw * labelAtEnd, y: cy - rw * labelAtEndY, ident: identAtEnd });
        });

        for (let i = 0; i < labelPositions.length; i++) {
            for (let j = i + 1; j < labelPositions.length; j++) {
                const a = labelPositions[i];
                const b = labelPositions[j];
                const dx = b.x - a.x;
                const dy = b.y - a.y;
                const dist = Math.sqrt(dx * dx + dy * dy);
                if (dist < MIN_LABEL_DIST && dist > 0) {
                    const push = (MIN_LABEL_DIST - dist) / 2;
                    const ux = dx / dist;
                    const uy = dy / dist;
                    a.x -= ux * push;
                    a.y -= uy * push;
                    b.x += ux * push;
                    b.y += uy * push;
                }
            }
        }

        const fieldObsTimeMap = full.fieldObsTimeMap || {};
        const isMetarOnly = full.isMetarOnly || false;
        const staleFailclosed = full.staleFailclosedSeconds || 10800;
        const metarStaleFailclosed = full.metarStaleFailclosedSeconds || 10800;

        const windStale = isFieldStale('wind_speed', fieldObsTimeMap, isMetarOnly, staleFailclosed, metarStaleFailclosed) ||
            isFieldStale('wind_direction', fieldObsTimeMap, isMetarOnly, staleFailclosed, metarStaleFailclosed);

        const lastHourWind = full.lastHourWind;
        canvas.title = Array.isArray(lastHourWind) && lastHourWind.length === 16
            ? 'Wind rose: Petals show last hour distribution'
            : '';

        if (Array.isArray(lastHourWind) && lastHourWind.length === 16) {
            drawWindRosePetals(ctx, cx, cy, r, lastHourWind, colors);
        }

        const windSpeed = options.windSpeed ?? null;
        const windDirRaw = options.windDirection ?? null;
        const isVRB = options.isVRB ?? false;
        const windDirMag = full.windDirectionMagnetic;
        const magneticDeclination = full.magneticDeclination || 0;

        let windDirNumeric = null;
        if (typeof windDirRaw === 'number' && windDirRaw >= 0 && windDirRaw <= 360) {
            windDirNumeric = windDirRaw;
        }

        if (!windStale) {
            const ws = windSpeed;
            if (ws !== null && ws >= CALM_WIND_THRESHOLD && !isVRB && windDirNumeric !== null) {
                const windDirFromMag = (windDirMag != null && typeof windDirMag === 'number')
                    ? windDirMag
                    : (windDirNumeric - magneticDeclination + 360) % 360;
                const windDirToward = (windDirFromMag + 180) % 360;
                const windAngle = (windDirToward * Math.PI) / 180;
                drawWindArrowFull(ctx, cx, cy, r, windAngle, ws, colors);
            } else if (ws !== null && ws >= CALM_WIND_THRESHOLD && isVRB) {
                ctx.font = 'bold 20px sans-serif';
                ctx.textAlign = 'center';
                ctx.strokeStyle = colors.labelOutline;
                ctx.lineWidth = 3;
                ctx.lineJoin = 'round';
                ctx.strokeText('VRB', cx, cy);
                ctx.fillStyle = colors.vrbText;
                ctx.fillText('VRB', cx, cy);
            } else if (ws === null || ws < CALM_WIND_THRESHOLD) {
                ctx.font = 'bold 20px sans-serif';
                ctx.textAlign = 'center';
                ctx.strokeStyle = colors.labelOutline;
                ctx.lineWidth = 3;
                ctx.lineJoin = 'round';
                ctx.strokeText('CALM', cx, cy);
                ctx.fillStyle = colors.calmText;
                ctx.fillText('CALM', cx, cy);
            }
        }

        ctx.font = 'bold 14px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        labelPositions.forEach(function(lp) {
            if (!lp.ident) return;
            ctx.strokeStyle = colors.labelOutline;
            ctx.lineWidth = 3;
            ctx.strokeText(lp.ident, lp.x, lp.y);
            ctx.fillStyle = colors.runwayLabel;
            ctx.fillText(lp.ident, lp.x, lp.y);
        });

        ['N', 'E', 'S', 'W'].forEach(function(l, i) {
            const ang = (i * 90 * Math.PI) / 180;
            ctx.fillStyle = colors.compass;
            ctx.font = 'bold 16px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(l, cx + Math.sin(ang) * (r + 10), cy - Math.cos(ang) * (r + 10));
        });
    }

    function drawWindRosePetals(ctx, cx, cy, r, petals, colors) {
        const maxPetalLength = Math.min(45, r * 0.35);
        const maxSpeed = Math.max(1, Math.max.apply(null, petals));
        const deg2rad = Math.PI / 180;

        for (let i = 0; i < 16; i++) {
            const speed = petals[i] || 0;
            if (speed <= 0) continue;
            const length = (speed / maxSpeed) * maxPetalLength;
            if (length < 2) continue;

            const centerAngle = (i * 22.5 - 90) * deg2rad;
            const halfWidth = (22.5 / 2) * deg2rad;
            const a1 = centerAngle - halfWidth;
            const a2 = centerAngle + halfWidth;

            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.arc(cx, cy, length, a1, a2);
            ctx.closePath();
            ctx.fillStyle = colors.windRosePetal || 'rgba(220, 53, 69, 0.5)';
            ctx.fill();
            ctx.strokeStyle = colors.windRosePetalStroke || 'rgba(220, 53, 69, 0.4)';
            ctx.lineWidth = 1;
            ctx.stroke();
        }
    }

    function drawWindArrowFull(ctx, cx, cy, r, angle, speed, colors) {
        const arrowLength = Math.min(speed * 6, r - 30);
        const arrowEndX = cx + Math.sin(angle) * arrowLength;
        const arrowEndY = cy - Math.cos(angle) * arrowLength;

        ctx.strokeStyle = colors.windArrow;
        ctx.fillStyle = colors.windArrow;
        ctx.lineWidth = 4;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.lineTo(arrowEndX, arrowEndY);
        ctx.stroke();

        const arrowAngle = Math.atan2(arrowEndY - cy, arrowEndX - cx);
        ctx.beginPath();
        ctx.moveTo(arrowEndX, arrowEndY);
        ctx.lineTo(arrowEndX - 15 * Math.cos(arrowAngle - Math.PI / 6), arrowEndY - 15 * Math.sin(arrowAngle - Math.PI / 6));
        ctx.lineTo(arrowEndX - 15 * Math.cos(arrowAngle + Math.PI / 6), arrowEndY - 15 * Math.sin(arrowAngle + Math.PI / 6));
        ctx.closePath();
        ctx.fill();
    }

    /**
     * Draw wind compass on canvas
     *
     * @param {HTMLCanvasElement} canvas - Canvas element to draw on
     * @param {Object} options - Configuration options
     * @param {number|null} options.windSpeed - Wind speed in knots
     * @param {number|null} options.windDirection - Wind direction in degrees
     * @param {boolean} options.isVRB - Whether wind is variable
     * @param {Array} options.runways - Array of runway objects with heading_1 (legacy)
     * @param {boolean} options.isDark - Whether to use dark theme colors
     * @param {string} options.size - Size variant: 'mini', 'small', 'medium', 'large', 'full'
     * @param {Object} [options.fullMode] - Full-mode options (runwaySegments, lastHourWind, etc.)
     */
    function drawWindCompass(canvas, options) {
        if (!canvas || !canvas.getContext) {
            console.error('[AviationWX] Invalid canvas element');
            return;
        }

        if (options.fullMode) {
            drawWindCompassFullMode(canvas, options);
            return;
        }

        const ctx = canvas.getContext('2d');
        const width = canvas.width;
        const height = canvas.height;
        const cx = width / 2;
        const cy = height / 2;
        const r = Math.min(width, height) / 2 - 5;

        const windSpeed = options.windSpeed ?? null;
        const windDir = options.windDirection ?? null;
        const isVRB = options.isVRB ?? false;
        const runways = options.runways ?? [];
        const isDark = options.isDark ?? false;
        const size = options.size ?? 'medium';

        ctx.clearRect(0, 0, width, height);

        ctx.strokeStyle = isDark ? '#888' : '#999';
        ctx.lineWidth = width > 80 ? 1.5 : 1;
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, 2 * Math.PI);
        ctx.stroke();

        if (runways.length > 0 && runways[0].heading_1 !== undefined) {
            const h1 = runways[0].heading_1;
            const h2 = runways[0].heading_2 !== undefined ? runways[0].heading_2 : (h1 + 180) % 360;
            const angle = (h1 * Math.PI) / 180;
            const rwLen = r * 0.65;

            ctx.strokeStyle = isDark ? '#aaa' : '#999';
            ctx.lineWidth = width > 80 ? 6 : 4;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.moveTo(cx - Math.sin(angle) * rwLen, cy + Math.cos(angle) * rwLen);
            ctx.lineTo(cx + Math.sin(angle) * rwLen, cy - Math.cos(angle) * rwLen);
            ctx.stroke();

            if (width > 100) {
                const rwy1 = Math.round(h1 / 10);
                const rwy2 = Math.round(h2 / 10);
                const labelDist = rwLen + 5;

                ctx.font = 'bold 11px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';

                ctx.strokeStyle = isDark ? '#000' : '#fff';
                ctx.lineWidth = 3;
                ctx.lineJoin = 'round';
                ctx.strokeText(rwy1, cx + Math.sin(angle) * labelDist, cy - Math.cos(angle) * labelDist);
                ctx.strokeText(rwy2, cx - Math.sin(angle) * labelDist, cy + Math.cos(angle) * labelDist);

                ctx.fillStyle = '#5eb3ff';
                ctx.fillText(rwy1, cx + Math.sin(angle) * labelDist, cy - Math.cos(angle) * labelDist);
                ctx.fillText(rwy2, cx - Math.sin(angle) * labelDist, cy + Math.cos(angle) * labelDist);
            }
        }

        const cardinalFontSize = width > 100 ? 11 : (width > 80 ? 10 : 9);
        ctx.font = 'bold ' + cardinalFontSize + 'px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        const cardinalDist = r - (width > 100 ? 12 : 10);
        [
            { label: 'N', angle: 0 },
            { label: 'E', angle: 90 },
            { label: 'S', angle: 180 },
            { label: 'W', angle: 270 }
        ].forEach(function(c) {
            const ang = (c.angle * Math.PI) / 180;
            const x = cx + Math.sin(ang) * cardinalDist;
            const y = cy - Math.cos(ang) * cardinalDist;

            ctx.strokeStyle = isDark ? '#000' : '#fff';
            ctx.lineWidth = 2.5;
            ctx.lineJoin = 'round';
            ctx.strokeText(c.label, x, y);

            ctx.fillStyle = isDark ? '#ddd' : '#666';
            ctx.fillText(c.label, x, y);
        });

        if (windSpeed !== null && windSpeed >= CALM_WIND_THRESHOLD && windDir !== null && !isVRB) {
            drawWindArrow(ctx, cx, cy, r, windSpeed, windDir, width, isDark);
        } else if (isVRB && windSpeed !== null && windSpeed >= CALM_WIND_THRESHOLD) {
            drawVRBIndicator(ctx, cx, cy, width, isDark);
        } else {
            drawCalmIndicator(ctx, cx, cy, width, isDark);
        }
    }

    function drawWindArrow(ctx, cx, cy, r, windSpeed, windDir, canvasWidth, isDark) {
        const windAngle = ((windDir + 180) % 360) * Math.PI / 180;

        let arrowLen, headSize, lineWidth;
        if (canvasWidth > 80) {
            arrowLen = Math.min(windSpeed * 3, r - 15);
            headSize = 8;
            lineWidth = 3;

            ctx.fillStyle = 'rgba(220, 53, 69, 0.15)';
            ctx.beginPath();
            ctx.arc(cx, cy, Math.max(12, windSpeed * 2), 0, 2 * Math.PI);
            ctx.fill();
        } else if (canvasWidth >= 60) {
            arrowLen = Math.min(windSpeed * 1.5, r - 8);
            headSize = 6;
            lineWidth = 2;
        } else {
            arrowLen = Math.min(windSpeed * 1.5, r - 5);
            headSize = 5;
            lineWidth = 2;
        }

        const endX = cx + Math.sin(windAngle) * arrowLen;
        const endY = cy - Math.cos(windAngle) * arrowLen;

        ctx.strokeStyle = isDark ? '#000' : '#fff';
        ctx.lineWidth = lineWidth + 2;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.lineTo(endX, endY);
        ctx.stroke();

        ctx.strokeStyle = '#dc3545';
        ctx.lineWidth = lineWidth;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.lineTo(endX, endY);
        ctx.stroke();

        const headAngle = Math.atan2(endY - cy, endX - cx);

        ctx.strokeStyle = isDark ? '#000' : '#fff';
        ctx.lineWidth = 2;
        ctx.lineJoin = 'round';
        ctx.beginPath();
        ctx.moveTo(endX, endY);
        ctx.lineTo(endX - headSize * Math.cos(headAngle - Math.PI / 6), endY - headSize * Math.sin(headAngle - Math.PI / 6));
        ctx.lineTo(endX - headSize * Math.cos(headAngle + Math.PI / 6), endY - headSize * Math.sin(headAngle + Math.PI / 6));
        ctx.closePath();
        ctx.stroke();

        ctx.fillStyle = '#dc3545';
        ctx.beginPath();
        ctx.moveTo(endX, endY);
        ctx.lineTo(endX - headSize * Math.cos(headAngle - Math.PI / 6), endY - headSize * Math.sin(headAngle - Math.PI / 6));
        ctx.lineTo(endX - headSize * Math.cos(headAngle + Math.PI / 6), endY - headSize * Math.sin(headAngle + Math.PI / 6));
        ctx.closePath();
        ctx.fill();
    }

    function drawVRBIndicator(ctx, cx, cy, canvasWidth, isDark) {
        const fontSize = canvasWidth > 80 ? 14 : (canvasWidth >= 60 ? 11 : 10);
        ctx.font = 'bold ' + fontSize + 'px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        ctx.strokeStyle = isDark ? '#000' : '#fff';
        ctx.lineWidth = 3;
        ctx.lineJoin = 'round';
        ctx.strokeText('VRB', cx, cy);

        ctx.fillStyle = '#dc3545';
        ctx.fillText('VRB', cx, cy);
    }

    function drawCalmIndicator(ctx, cx, cy, canvasWidth, isDark) {
        const fontSize = canvasWidth > 100 ? 14 : (canvasWidth > 80 ? 12 : (canvasWidth >= 60 ? 10 : 9));
        ctx.font = 'bold ' + fontSize + 'px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        ctx.strokeStyle = isDark ? '#000' : '#fff';
        ctx.lineWidth = 3;
        ctx.lineJoin = 'round';
        ctx.strokeText('CALM', cx, cy);

        ctx.fillStyle = '#5eb3ff';
        ctx.fillText('CALM', cx, cy);
    }

    window.AviationWX = window.AviationWX || {};
    window.AviationWX.drawWindCompass = drawWindCompass;
    window.AviationWX.CALM_WIND_THRESHOLD = CALM_WIND_THRESHOLD;

})(window);
