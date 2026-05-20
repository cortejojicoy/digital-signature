import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * One draggable + resizable signature drop zone on top of the page image.
 *
 * Operates in CSS-pixel space (relative to the displayed page image),
 * but the parent gives us a scaleX/scaleY pair so we can convert to
 * PDF-point coordinates on every change. The conversion lives in the
 * parent, not here — this component is pure UI state.
 *
 * Resize handle is the bottom-right corner only. Plenty for placement;
 * a full eight-handle rig is more weight than the use case justifies.
 */
export function SlotBox({
    slotKey,
    label,
    rect,                 // { x, y, width, height } in CSS pixels
    onChange,             // (next) => void
    onSelect,             // () => void
    selected,
    canvasWidth,          // CSS pixels — used to clamp inside bounds
    canvasHeight,
    minSize = 24,
}) {
    const ref = useRef(null);
    const [mode, setMode] = useState(null);   // 'drag' | 'resize' | null
    const startRef = useRef(null);            // pointer + rect at drag start

    const beginDrag = useCallback((e) => {
        e.preventDefault();
        e.stopPropagation();
        onSelect?.();
        setMode('drag');
        startRef.current = {
            pointerX: e.clientX,
            pointerY: e.clientY,
            rect: { ...rect },
        };
        ref.current?.setPointerCapture?.(e.pointerId);
    }, [rect, onSelect]);

    const beginResize = useCallback((e) => {
        e.preventDefault();
        e.stopPropagation();
        onSelect?.();
        setMode('resize');
        startRef.current = {
            pointerX: e.clientX,
            pointerY: e.clientY,
            rect: { ...rect },
        };
        ref.current?.setPointerCapture?.(e.pointerId);
    }, [rect, onSelect]);

    const handleMove = useCallback((e) => {
        if (!mode || !startRef.current) return;

        const dx = e.clientX - startRef.current.pointerX;
        const dy = e.clientY - startRef.current.pointerY;
        const r  = startRef.current.rect;

        let next;
        if (mode === 'drag') {
            next = {
                x:      clamp(r.x + dx, 0, canvasWidth  - r.width),
                y:      clamp(r.y + dy, 0, canvasHeight - r.height),
                width:  r.width,
                height: r.height,
            };
        } else {
            // resize from bottom-right
            const newWidth  = clamp(r.width  + dx, minSize, canvasWidth  - r.x);
            const newHeight = clamp(r.height + dy, minSize, canvasHeight - r.y);
            next = { x: r.x, y: r.y, width: newWidth, height: newHeight };
        }
        onChange?.(next);
    }, [mode, canvasWidth, canvasHeight, minSize, onChange]);

    const endInteraction = useCallback((e) => {
        if (!mode) return;
        setMode(null);
        startRef.current = null;
        ref.current?.releasePointerCapture?.(e.pointerId);
    }, [mode]);

    // Keyboard nudge — 1px / 10px with shift. Improves placement precision
    // for users who don't want to fight pointer drift on a trackpad.
    useEffect(() => {
        if (!selected) return;
        const handler = (e) => {
            if (!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key)) return;
            e.preventDefault();
            const step = e.shiftKey ? 10 : 1;
            const next = { ...rect };
            if (e.key === 'ArrowLeft')  next.x = clamp(next.x - step, 0, canvasWidth  - rect.width);
            if (e.key === 'ArrowRight') next.x = clamp(next.x + step, 0, canvasWidth  - rect.width);
            if (e.key === 'ArrowUp')    next.y = clamp(next.y - step, 0, canvasHeight - rect.height);
            if (e.key === 'ArrowDown')  next.y = clamp(next.y + step, 0, canvasHeight - rect.height);
            onChange?.(next);
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [selected, rect, canvasWidth, canvasHeight, onChange]);

    return (
        <div
            ref={ref}
            data-slot-key={slotKey}
            onPointerDown={beginDrag}
            onPointerMove={handleMove}
            onPointerUp={endInteraction}
            onPointerCancel={endInteraction}
            style={{
                position: 'absolute',
                left:   `${rect.x}px`,
                top:    `${rect.y}px`,
                width:  `${rect.width}px`,
                height: `${rect.height}px`,
                cursor: mode === 'drag' ? 'grabbing' : 'grab',
                touchAction: 'none',
                outline: selected ? '2px solid rgb(20 184 166)' : '2px dashed rgb(148 163 184)',
                outlineOffset: '-2px',
                background: selected ? 'rgba(20,184,166,0.12)' : 'rgba(148,163,184,0.10)',
                userSelect: 'none',
            }}
        >
            <div style={{
                position: 'absolute',
                top: '2px',
                left: '4px',
                fontSize: '11px',
                lineHeight: '1.2',
                fontWeight: 600,
                color: selected ? 'rgb(15 118 110)' : 'rgb(71 85 105)',
                pointerEvents: 'none',
            }}>
                {label}
            </div>

            <div
                onPointerDown={beginResize}
                onPointerMove={handleMove}
                onPointerUp={endInteraction}
                style={{
                    position: 'absolute',
                    right: '-6px',
                    bottom: '-6px',
                    width: '12px',
                    height: '12px',
                    background: 'rgb(20 184 166)',
                    border: '2px solid white',
                    borderRadius: '2px',
                    cursor: 'nwse-resize',
                }}
            />
        </div>
    );
}

function clamp(v, min, max) {
    return Math.min(max, Math.max(min, v));
}
