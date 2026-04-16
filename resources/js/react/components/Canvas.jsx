import { useEffect, useRef } from 'react';
import { AnimatedStroke }   from './AnimatedStroke.jsx';
import { useCanvasPointer } from '../hooks/useCanvasPointer.js';

export function Canvas({ width, height, state, dispatch }) {
    const canvasRef = useRef(null);
    const rafRef    = useRef(null);

    const { onPointerDown, onPointerMove, onPointerUp } = useCanvasPointer({
        canvasRef,
        dispatch,
        minWidth: state.minWidth,
        maxWidth: state.maxWidth,
    });

    // Re-render canvas whenever strokes change
    useEffect(() => {
        if (rafRef.current) cancelAnimationFrame(rafRef.current);
        rafRef.current = requestAnimationFrame(() => renderCanvas());
        return () => cancelAnimationFrame(rafRef.current);
    }, [state.strokes]);

    function renderCanvas() {
        const canvas = canvasRef.current;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        for (const stroke of state.strokes) {
            if (stroke.points.length < 2) continue;
            ctx.beginPath();
            ctx.strokeStyle = stroke.color;
            ctx.lineWidth   = stroke.width ?? 1.6;
            ctx.lineCap     = 'round';
            ctx.lineJoin    = 'round';

            ctx.moveTo(stroke.points[0].x, stroke.points[0].y);
            for (let i = 1; i < stroke.points.length - 1; i++) {
                const midX = (stroke.points[i].x + stroke.points[i + 1].x) / 2;
                const midY = (stroke.points[i].y + stroke.points[i + 1].y) / 2;
                ctx.quadraticCurveTo(stroke.points[i].x, stroke.points[i].y, midX, midY);
            }
            const last = stroke.points[stroke.points.length - 1];
            ctx.lineTo(last.x, last.y);
            ctx.stroke();
        }
    }

    const completedStrokes = state.isDrawing
        ? state.strokes.slice(0, -1)
        : state.strokes;
    const isEmpty = state.strokes.length === 0 && !state.isDrawing;

    return (
        <div style={{ position: 'relative', width, height, cursor: 'crosshair' }}>

            {/* ── Empty-state guide ──────────────────────────────────────── */}
            {isEmpty && (
                <div style={{
                    position:       'absolute',
                    inset:          0,
                    display:        'flex',
                    flexDirection:  'column',
                    alignItems:     'center',
                    justifyContent: 'center',
                    gap:            '8px',
                    pointerEvents:  'none',
                    userSelect:     'none',
                }}>
                    {/* Pen icon */}
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
                         stroke="rgba(0,0,0,0.12)" strokeWidth="1.5"
                         strokeLinecap="round" strokeLinejoin="round">
                        <path d="M15.232 5.232l3.536 3.536M9 11l4-4 2 2-4 4H9v-2z"/>
                        <path d="M4 20h4l9-9-4-4-9 9v4z"/>
                    </svg>

                    {/* Dashed baseline */}
                    <div style={{
                        width:        '55%',
                        borderBottom: '2px dashed rgba(0,0,0,0.09)',
                    }} />

                    <span style={{
                        fontSize:      '11px',
                        color:         'rgba(0,0,0,0.18)',
                        letterSpacing: '0.06em',
                        textTransform: 'uppercase',
                        fontFamily:    'system-ui, sans-serif',
                        fontWeight:    '500',
                    }}>
                        Sign here
                    </span>
                </div>
            )}

            {/* ── Raster layer — committed strokes ───────────────────────── */}
            <canvas
                ref={canvasRef}
                width={width}
                height={height}
                style={{ position: 'absolute', inset: 0, touchAction: 'none' }}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                onPointerLeave={onPointerUp}
            />

            {/* ── SVG overlay — animated last stroke ─────────────────────── */}
            <svg
                width={width}
                height={height}
                style={{ position: 'absolute', inset: 0, pointerEvents: 'none' }}
            >
                {completedStrokes.slice(-1).map((stroke, i) => (
                    <AnimatedStroke key={`anim-${i}`} {...stroke} />
                ))}
            </svg>
        </div>
    );
}
