import { useEffect, useRef } from 'react';

export function AnimatedStroke({ points, color, width }) {
    const pathRef = useRef(null);

    const toSvgPath = (pts) => {
        if (pts.length < 2) return '';
        let d = `M ${pts[0].x} ${pts[0].y}`;
        for (let i = 1; i < pts.length; i++) {
            const cp1x = (pts[i - 1].x + pts[i].x) / 2;
            const cp1y = (pts[i - 1].y + pts[i].y) / 2;
            d += ` Q ${pts[i - 1].x} ${pts[i - 1].y} ${cp1x} ${cp1y}`;
        }
        return d;
    };

    useEffect(() => {
        const path = pathRef.current;
        if (!path) return;
        try {
            const len = path.getTotalLength();
            path.style.strokeDasharray  = len;
            path.style.strokeDashoffset = len;
            path.style.transition       = 'stroke-dashoffset 0.4s ease-out';
            // Trigger reflow then animate
            requestAnimationFrame(() => {
                path.style.strokeDashoffset = '0';
            });
        } catch (_) {
            // getTotalLength not supported — just show static
            path.style.strokeDasharray  = '';
            path.style.strokeDashoffset = '';
        }
    }, [points]);

    return (
        <path
            ref={pathRef}
            d={toSvgPath(points)}
            stroke={color}
            strokeWidth={width}
            strokeLinecap="round"
            strokeLinejoin="round"
            fill="none"
            vectorEffect="non-scaling-stroke"
        />
    );
}