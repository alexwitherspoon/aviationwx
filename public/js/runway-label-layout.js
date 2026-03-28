/**
 * Runway heading labels: 1D-on-segment placement (each label stays on its runway line).
 *
 * Overlap: iterate; for pairs closer than minLabelDist, snap each label's t to an endpoint of its
 * allowed interval that maximizes distance to the other label. Hub (CALM/VRB center text): if the
 * label lies inside centerExclusion, snap t to the endpoint farthest from the compass center.
 * Squared distance to a moving point on a segment is convex in t, so extrema on [tLo,tHi] are at endpoints.
 *
 * Invalid or incomplete options yield an empty array (no labels drawn).
 *
 * @module runway-label-layout
 */
/* global module -- UMD for Node tests */
(function (global) {
    'use strict';

    /**
     * Canvas position for parameter t in [0,1] along normalized segment start->end.
     *
     * @param {number} sx
     * @param {number} sy
     * @param {number} ex
     * @param {number} ey
     * @param {number} rw
     * @param {number} cx
     * @param {number} cy
     * @param {number} t
     * @returns {{ x: number, y: number }}
     */
    function xyFromT(sx, sy, ex, ey, rw, cx, cy, t) {
        const nx = (1 - t) * sx + t * ex;
        const ny = (1 - t) * sy + t * ey;
        return { x: cx + rw * nx, y: cy - rw * ny };
    }

    function distSq(p, q) {
        const dx = p.x - q.x;
        const dy = p.y - q.y;
        return dx * dx + dy * dy;
    }

    function distToPoint(p, q) {
        return Math.hypot(p.x - q.x, p.y - q.y);
    }

    /**
     * Choose t in {tLo, tHi} that maximizes distance from p(t) to fixed point q.
     *
     * @param {number} sx
     * @param {number} sy
     * @param {number} ex
     * @param {number} ey
     * @param {number} rw
     * @param {number} cx
     * @param {number} cy
     * @param {number} tLo
     * @param {number} tHi
     * @param {{x:number,y:number}} q
     * @returns {number}
     */
    function bestTEndpointForSeparation(sx, sy, ex, ey, rw, cx, cy, tLo, tHi, q) {
        const pLo = xyFromT(sx, sy, ex, ey, rw, cx, cy, tLo);
        const pHi = xyFromT(sx, sy, ex, ey, rw, cx, cy, tHi);
        return distSq(pLo, q) >= distSq(pHi, q) ? tLo : tHi;
    }

    /**
     * Compute canvas positions for runway end labels. Each label is confined to its segment between tLo and tHi.
     *
     * @param {object} options
     * @param {number} options.cx - compass center X (px)
     * @param {number} options.cy - compass center Y (px)
     * @param {number} options.r - compass radius (px)
     * @param {Array<object>} options.segments - { start:[sx,sy], end:[ex,ey], le_ident, he_ident, ident_at_start?, ident_at_end? }
     * @param {boolean} options.willShowCenterText - true when CALM or VRB is drawn in the hub
     * @param {number} [options.runwayScale=0.86]
     * @param {number} [options.labelPosition=0.93] - outer bias (matches airport LABEL_POSITION)
     * @param {number} [options.tMin=0.02]
     * @param {number} [options.tMax=0.98]
     * @param {number} [options.tNearStartMax=0.42] - start-side label t <= this
     * @param {number} [options.tNearEndMin=0.58] - end-side label t >= this
     * @param {number} [options.minLabelDist=18]
     * @param {number} [options.centerExclusion=48] - hub radius (px) when center text is shown
     * @param {number} [options.overlapIterations=12]
     * @returns {Array<{ x: number, y: number, ident: string }>}
     */
    function computeRunwayLabelPositions(options) {
        if (!options || typeof options !== 'object') {
            return [];
        }
        const cx = options.cx;
        const cy = options.cy;
        const r = options.r;
        if (typeof cx !== 'number' || typeof cy !== 'number' || typeof r !== 'number' ||
                !isFinite(cx) || !isFinite(cy) || !isFinite(r) || r <= 0) {
            return [];
        }
        const runwayScale = options.runwayScale !== undefined ? options.runwayScale : 0.86;
        const labelPosition = options.labelPosition !== undefined ? options.labelPosition : 0.93;
        const tMin = options.tMin !== undefined ? options.tMin : 0.02;
        const tMax = options.tMax !== undefined ? options.tMax : 0.98;
        const tNearStartMax = options.tNearStartMax !== undefined ? options.tNearStartMax : 0.42;
        const tNearEndMin = options.tNearEndMin !== undefined ? options.tNearEndMin : 0.58;
        const minLabelDist = options.minLabelDist !== undefined ? options.minLabelDist : 18;
        const centerR = options.centerExclusion !== undefined ? options.centerExclusion : 48;
        const overlapIterations = options.overlapIterations !== undefined ? options.overlapIterations : 12;
        const willShowCenterText = !!options.willShowCenterText;
        const segments = Array.isArray(options.segments) ? options.segments : [];

        const rw = r * runwayScale;
        const tStart = 1 - labelPosition;
        const tEnd = labelPosition;

        /** @type {Array<{sx:number,sy:number,ex:number,ey:number,rw:number,t:number,tLo:number,tHi:number,ident:string,cx:number,cy:number,x?:number,y?:number}>} */
        const labels = [];

        segments.forEach(function (seg) {
            const sx = seg.start[0];
            const sy = seg.start[1];
            const ex = seg.end[0];
            const ey = seg.end[1];
            const leIdent = seg.le_ident || '';
            const heIdent = seg.he_ident || '';
            const identAtStart = seg.ident_at_start !== undefined ? seg.ident_at_start : leIdent;
            const identAtEnd = seg.ident_at_end !== undefined ? seg.ident_at_end : heIdent;

            labels.push({
                sx: sx,
                sy: sy,
                ex: ex,
                ey: ey,
                rw: rw,
                t: tStart,
                tLo: tMin,
                tHi: Math.min(tNearStartMax, tStart + 0.35),
                ident: identAtStart,
                cx: cx,
                cy: cy
            });
            labels.push({
                sx: sx,
                sy: sy,
                ex: ex,
                ey: ey,
                rw: rw,
                t: tEnd,
                tLo: Math.max(tNearEndMin, tEnd - 0.35),
                tHi: tMax,
                ident: identAtEnd,
                cx: cx,
                cy: cy
            });
        });

        function refreshXY(lb) {
            const p = xyFromT(lb.sx, lb.sy, lb.ex, lb.ey, lb.rw, lb.cx, lb.cy, lb.t);
            lb.x = p.x;
            lb.y = p.y;
        }

        labels.forEach(refreshXY);

        for (let pass = 0; pass < overlapIterations; pass++) {
            let anyMoved = false;
            for (let i = 0; i < labels.length; i++) {
                for (let j = i + 1; j < labels.length; j++) {
                    const a = labels[i];
                    const b = labels[j];
                    refreshXY(a);
                    refreshXY(b);
                    const d = distToPoint({ x: a.x, y: a.y }, { x: b.x, y: b.y });
                    if (d >= minLabelDist || d <= 0) {
                        continue;
                    }
                    anyMoved = true;
                    const bPt = { x: b.x, y: b.y };
                    a.t = bestTEndpointForSeparation(
                        a.sx, a.sy, a.ex, a.ey, a.rw, a.cx, a.cy, a.tLo, a.tHi, bPt
                    );
                    refreshXY(a);
                    b.t = bestTEndpointForSeparation(
                        b.sx, b.sy, b.ex, b.ey, b.rw, b.cx, b.cy, b.tLo, b.tHi, { x: a.x, y: a.y }
                    );
                    refreshXY(b);
                }
            }
            if (!anyMoved) {
                break;
            }
        }

        if (willShowCenterText) {
            const center = { x: cx, y: cy };
            labels.forEach(function (lb) {
                refreshXY(lb);
                if (distToPoint({ x: lb.x, y: lb.y }, center) >= centerR) {
                    return;
                }
                const pLo = xyFromT(lb.sx, lb.sy, lb.ex, lb.ey, lb.rw, lb.cx, lb.cy, lb.tLo);
                const pHi = xyFromT(lb.sx, lb.sy, lb.ex, lb.ey, lb.rw, lb.cx, lb.cy, lb.tHi);
                lb.t = distToPoint(pLo, center) >= distToPoint(pHi, center) ? lb.tLo : lb.tHi;
                refreshXY(lb);
            });
        }

        return labels.map(function (lb) {
            return { x: lb.x, y: lb.y, ident: lb.ident };
        });
    }

    const api = { computeRunwayLabelPositions: computeRunwayLabelPositions };
    global.AviationWX = global.AviationWX || {};
    global.AviationWX.runwayLabelLayout = api;

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
