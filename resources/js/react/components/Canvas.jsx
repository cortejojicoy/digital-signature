import { useEffect, useRef, useReducer } from 'react';
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

    // Last stroke gets the SVG animation overlay; older strokes are on canvas
    const completedStrokes = state.isDrawing
        ? state.strokes.slice(0, -1)
        : state.strokes;
    const liveStroke = state.isDrawing ? state.strokes[state.strokes.length - 1] : null;

    return (
        <div style={{ position: 'relative', width, height, cursor: 'crosshair' }}>
            {/* Raster layer — all committed strokes */}
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

            {/* SVG overlay — animated entrance for the latest completed stroke */}
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