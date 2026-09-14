import { useCallback, useEffect, useRef, useState } from 'react';
import { StampPreview } from './StampPreview.jsx';

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
 *
 * When `aspect` is given, resizing preserves it unless the user holds Shift.
 * A signature is a picture of someone's handwriting: stretching it produces a
 * stamp that does not match the specimen on file, which is a problem with the
 * document rather than with the layout. So the ratio is kept by default and
 * distorting it has to be asked for.
 */
export function SlotBox({
    slotKey,
    label,
    rect,                 // { x, y, width, height } in CSS pixels
    onChange,             // (next) => void
    onSelect,             // () => void
    onRemove,             // () => void  (optional × button when present)
    selected,
    canvasWidth,          // CSS pixels — used to clamp inside bounds
    canvasHeight,
    minSize = 24,
    backgroundImageUrl,   // optional — when set, renders the image inside
                          //            the box (used by the signer flow to
                          //            preview the placed signature)
    aspect,               // optional width/height ratio to hold while resizing;
                          //          Shift overrides it for one drag
    preview,              // optional composed stamp, already in CSS pixels —
                          //          see StampPreview. When given it replaces
                          //          the plain background image, so the box
                          //          shows what will print rather than only
                          //          the signature.
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
            let newWidth  = clamp(r.width  + dx, minSize, canvasWidth  - r.x);
            let newHeight = clamp(r.height + dy, minSize, canvasHeight - r.y);

            if (aspect && aspect > 0 && !e.shiftKey) {
                // Drive the ratio from whichever axis the pointer moved more,
                // so a mostly-horizontal drag doesn't feel like it is fighting
                // a vertical correction. Re-clamp after: holding the ratio can
                // push the other axis back outside the canvas.
                if (Math.abs(dx) >= Math.abs(dy)) {
                    newHeight = newWidth / aspect;
                } else {
                    newWidth = newHeight * aspect;
                }

                const maxWidth  = canvasWidth  - r.x;
                const maxHeight = canvasHeight - r.y;
                const overflow  = Math.max(newWidth / maxWidth, newHeight / maxHeight, 1);
                newWidth  /= overflow;
                newHeight /= overflow;

                const shortfall = Math.max(minSize / newWidth, minSize / newHeight, 1);
                newWidth  *= shortfall;
                newHeight *= shortfall;
            }

            next = { x: r.x, y: r.y, width: newWidth, height: newHeight };
        }
        onChange?.(next);
    }, [mode, canvasWidth, canvasHeight, minSize, onChange, aspect]);

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
            {preview
                ? <StampPreview {...preview} imageUrl={backgroundImageUrl} />
                : backgroundImageUrl && (
                    <img
                        src={backgroundImageUrl}
                        alt=""
                        draggable={false}
                        style={{
                            position: 'absolute',
                            inset: 0,
                            width: '100%',
                            height: '100%',
                            objectFit: 'contain',
                            pointerEvents: 'none',
                            userSelect: 'none',
                        }}
                    />
                )}

            <div style={{
                position: 'absolute',
                top: '2px',
                left: '4px',
                fontSize: '11px',
                lineHeight: '1.2',
                fontWeight: 600,
                color: selected ? 'rgb(15 118 110)' : 'rgb(71 85 105)',
                pointerEvents: 'none',
                textShadow: backgroundImageUrl ? '0 1px 2px rgba(255,255,255,0.8)' : 'none',
            }}>
                {label}
            </div>

            {onRemove && (
                <button
                    type="button"
                    onClick={(e) => { e.stopPropagation(); onRemove(); }}
                    onPointerDown={(e) => e.stopPropagation()}
                    style={{
                        position: 'absolute',
                        top: '-8px',
                        right: '-8px',
                        width: '18px',
                        height: '18px',
                        borderRadius: '50%',
                        border: '2px solid white',
                        background: 'rgb(239 68 68)',
                        color: 'white',
                        fontSize: '10px',
                        fontWeight: 700,
                        lineHeight: 1,
                        cursor: 'pointer',
                        display: selected ? 'flex' : 'none',
                        alignItems: 'center',
                        justifyContent: 'center',
                    }}
                    aria-label="Remove placement"
                >×</button>
            )}

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
