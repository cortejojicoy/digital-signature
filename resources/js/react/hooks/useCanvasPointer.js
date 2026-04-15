import { useCallback, useRef } from 'react';

export function useCanvasPointer({ canvasRef, dispatch, minWidth, maxWidth }) {
    const rafRef = useRef(null);

    const resolveWidth = (pressure = 0.5) => {
        // Map pressure [0..1] → [minWidth..maxWidth]
        return minWidth + (maxWidth - minWidth) * Math.min(Math.max(pressure, 0), 1);
    };

    const getPoint = (e) => {
        const rect     = canvasRef.current.getBoundingClientRect();
        const scaleX   = canvasRef.current.width  / rect.width;
        const scaleY   = canvasRef.current.height / rect.height;
        const clientX  = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY  = e.touches ? e.touches[0].clientY : e.clientY;
        const pressure = e.pressure ?? (e.touches?.[0]?.force ?? 0.5);

        return {
            x:     (clientX - rect.left) * scaleX,
            y:     (clientY - rect.top)  * scaleY,
            width: resolveWidth(pressure),
        };
    };

    const onPointerDown = useCallback((e) => {
        e.preventDefault();
        canvasRef.current?.setPointerCapture?.(e.pointerId);
        const pt = getPoint(e);
        dispatch({ type: 'SET_PEN_WIDTH', width: pt.width });
        dispatch({ type: 'STROKE_START',  point: pt });
    }, [dispatch, minWidth, maxWidth]);

    const onPointerMove = useCallback((e) => {
        e.preventDefault();
        if (rafRef.current) return; // throttle to rAF
        rafRef.current = requestAnimationFrame(() => {
            rafRef.current = null;
            const pt = getPoint(e);
            dispatch({ type: 'STROKE_MOVE', point: pt });
        });
    }, [dispatch]);

    const onPointerUp = useCallback((e) => {
        e.preventDefault();
        dispatch({ type: 'STROKE_END' });
    }, [dispatch]);

    return { onPointerDown, onPointerMove, onPointerUp };
}