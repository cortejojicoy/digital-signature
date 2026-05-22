import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { SlotBox } from './components/SlotBox.jsx';

/**
 * PDF Signer experience.
 *
 * Mounts on any element with [data-pdf-signer]. The layout follows the
 * SignFlow mockup: a top bar with template label + Finish & Save, the
 * PDF preview in the middle with SlotBox overlays, and a bottom strip
 * showing the user's stored signature library.
 *
 * Differences from PdfDesignerIsland:
 *  - Slot boxes carry the active signature image as background, not
 *    just a placeholder label — the user is placing a real signature
 *    on the document.
 *  - One global "Finish & Save" instead of per-slot save buttons.
 *  - Bottom strip lets users swap which signature is being placed.
 *
 * Coordinate spaces are the same as the designer:
 *   CSS pixels (UI) ↔ Image pixels (server raster) ↔ PDF points (saved).
 */
function PdfSigningIsland({ el }) {
    const config = useMemo(() => ({
        metaUrl:         el.dataset.metaUrl,
        pageUrlTemplate: el.dataset.pageUrlTemplate, // contains __PAGE__
        finalizeUrl:     el.dataset.finalizeUrl,
        backUrl:         el.dataset.backUrl,
        csrfToken:       el.dataset.csrfToken,
    }), [el]);

    const [meta, setMeta]     = useState(null);
    const [error, setError]   = useState(null);
    const [activePage, setActivePage] = useState(1);
    const [activeSig, setActiveSig]   = useState(null);  // { uuid, previewUrl }
    const [selectedSlot, setSelectedSlot] = useState(null);
    const [slotRects, setSlotRects] = useState({});      // slotKey → {x,y,w,h}
    const [finishing, setFinishing] = useState(false);
    const [finishedAck, setFinishedAck] = useState(null);

    const imgRef = useRef(null);
    const [displayed, setDisplayed] = useState({ width: 0, height: 0 });

    // ── Bootstrap ────────────────────────────────────────────────────────────
    useEffect(() => {
        let alive = true;
        fetch(config.metaUrl, { credentials: 'same-origin' })
            .then((r) => r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`)))
            .then((payload) => {
                if (!alive) return;
                setMeta(payload);
                setActiveSig({
                    uuid:       payload.signature.uuid,
                    previewUrl: payload.signature.previewUrl,
                });
            })
            .catch((e) => alive && setError(`Failed to load signer: ${e.message}`));
        return () => { alive = false; };
    }, [config.metaUrl]);

    // ── Seed slot rects from meta whenever the page/image changes ───────────
    useEffect(() => {
        if (!meta || !displayed.width || !displayed.height) return;

        const page = meta.pages.find((p) => p.page === activePage);
        if (!page) return;

        const scaleX = displayed.width  / page.widthPt;
        const scaleY = displayed.height / page.heightPt;

        const next = {};
        for (const slot of meta.slots) {
            if (!slot.placement || slot.placement.page !== activePage) continue;
            const p = slot.placement;
            next[slot.key] = {
                x:      p.x * scaleX,
                y:      displayed.height - (p.y + p.height) * scaleY,
                width:  p.width  * scaleX,
                height: p.height * scaleY,
            };
        }
        setSlotRects(next);
    }, [meta, activePage, displayed]);

    // ── Image sizing observer ────────────────────────────────────────────────
    useEffect(() => {
        if (!imgRef.current) return;
        const measure = () => {
            const img = imgRef.current;
            if (!img) return;
            setDisplayed({ width: img.clientWidth, height: img.clientHeight });
        };
        measure();
        const obs = new ResizeObserver(measure);
        obs.observe(imgRef.current);
        return () => obs.disconnect();
    }, [meta, activePage]);

    const pageInfo = meta?.pages.find((p) => p.page === activePage);
    const scaleX = pageInfo && displayed.width  ? displayed.width  / pageInfo.widthPt  : 1;
    const scaleY = pageInfo && displayed.height ? displayed.height / pageInfo.heightPt : 1;

    // ── Finish & Save: convert all CSS rects to PDF points and POST ─────────
    const finishAndSave = useCallback(async () => {
        if (!pageInfo) return;
        if (Object.keys(slotRects).length === 0) {
            setError('Place the signature on at least one slot before finishing.');
            return;
        }

        const placements = Object.entries(slotRects).map(([slot, rect]) => {
            const pdfX = rect.x / scaleX;
            const pdfWidth  = rect.width  / scaleX;
            const pdfHeight = rect.height / scaleY;
            const pdfY = pageInfo.heightPt - (rect.y / scaleY) - pdfHeight;
            return {
                slot,
                page:   activePage,
                x:      round2(pdfX),
                y:      round2(pdfY),
                width:  round2(pdfWidth),
                height: round2(pdfHeight),
            };
        });

        setFinishing(true);
        setError(null);

        try {
            const res = await fetch(config.finalizeUrl, {
                method:  'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': config.csrfToken,
                },
                body: JSON.stringify({ placements }),
            });
            const body = await res.json();
            if (!res.ok) throw new Error(body?.error || `HTTP ${res.status}`);
            setFinishedAck(body);
        } catch (e) {
            setError(`Finish failed: ${e.message}`);
        } finally {
            setFinishing(false);
        }
    }, [config, slotRects, scaleX, scaleY, pageInfo, activePage]);

    // ── Place the active signature onto a slot that has no rect yet ─────────
    const placeOnSlot = useCallback((slotKey) => {
        if (!displayed.width) return;
        // Drop a sensible default 200×60 box (or use template default) at
        // the visible center of the canvas.
        const slot = meta?.slots.find((s) => s.key === slotKey);
        const defaultWidth  = slot?.placement?.width  ? slot.placement.width  * scaleX : 200;
        const defaultHeight = slot?.placement?.height ? slot.placement.height * scaleY : 60;

        setSlotRects((s) => ({
            ...s,
            [slotKey]: {
                x: Math.max(0, displayed.width / 2 - defaultWidth / 2),
                y: Math.max(0, displayed.height / 2 - defaultHeight / 2),
                width:  defaultWidth,
                height: defaultHeight,
            },
        }));
        setSelectedSlot(slotKey);
    }, [meta, displayed, scaleX, scaleY]);

    const removeSlot = useCallback((slotKey) => {
        setSlotRects((s) => {
            const next = { ...s };
            delete next[slotKey];
            return next;
        });
        if (selectedSlot === slotKey) setSelectedSlot(null);
    }, [selectedSlot]);

    // ── Render ───────────────────────────────────────────────────────────────
    if (error && !meta) {
        return <ErrorPanel message={error} />;
    }
    if (!meta) {
        return <LoadingPanel />;
    }

    const pageImageUrl = config.pageUrlTemplate.replace('__PAGE__', String(activePage));

    if (finishedAck) {
        return (
            <div className="rounded-xl bg-white p-8 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                    ✓
                </div>
                <h3 className="text-base font-semibold text-gray-950 dark:text-white">
                    Signing pipeline acknowledged
                </h3>
                <p className="mx-auto mt-2 max-w-md text-sm text-gray-500 dark:text-gray-400">
                    {finishedAck.message ?? 'Placements received.'}
                </p>
                <pre className="mx-auto mt-4 max-w-md overflow-auto rounded-md bg-gray-100 p-3 text-left text-[11px] text-gray-700 dark:bg-white/5 dark:text-gray-300">
                    {JSON.stringify(finishedAck.placements, null, 2)}
                </pre>
                {config.backUrl && (
                    <a
                        href={config.backUrl}
                        className="mt-6 inline-flex rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-500"
                    >
                        Back
                    </a>
                )}
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {error && (
                <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-400">
                    {error}
                </div>
            )}

            {/* Top bar */}
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div className="min-w-0">
                    <div className="text-sm font-semibold text-gray-950 dark:text-white">
                        {meta.template.label}
                    </div>
                    <div className="text-xs text-gray-500 dark:text-gray-400">
                        Signing as {meta.signature.signerName ?? 'you'}
                        {meta.signature.signerEmail && (
                            <span className="ml-1">({meta.signature.signerEmail})</span>
                        )}
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    {config.backUrl && (
                        <a
                            href={config.backUrl}
                            className="rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10"
                        >
                            Cancel
                        </a>
                    )}
                    <button
                        type="button"
                        onClick={finishAndSave}
                        disabled={finishing || Object.keys(slotRects).length === 0}
                        className={
                            'rounded-md px-3 py-1.5 text-sm font-medium ' +
                            (finishing || Object.keys(slotRects).length === 0
                                ? 'bg-gray-200 text-gray-500 dark:bg-white/10 dark:text-gray-400'
                                : 'bg-emerald-600 text-white hover:bg-emerald-500')
                        }
                    >
                        {finishing ? 'Saving…' : 'Finish & Save'}
                    </button>
                </div>
            </div>

            {/* Canvas */}
            <div className="rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                {meta.pages.length > 1 && (
                    <div className="mb-2 flex items-center gap-1">
                        {meta.pages.map((p) => (
                            <button
                                key={p.page}
                                type="button"
                                onClick={() => setActivePage(p.page)}
                                className={
                                    'h-8 min-w-8 rounded-md px-2 text-xs font-medium ' +
                                    (p.page === activePage
                                        ? 'bg-primary-600 text-white'
                                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300')
                                }
                            >
                                {p.page}
                            </button>
                        ))}
                    </div>
                )}

                <div className="relative inline-block max-w-full rounded-lg border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                    <img
                        ref={imgRef}
                        src={pageImageUrl}
                        alt={`Page ${activePage}`}
                        className="block max-w-full select-none"
                        draggable={false}
                    />
                    {Object.entries(slotRects).map(([slotKey, rect]) => {
                        const slot = meta.slots.find((s) => s.key === slotKey);
                        return (
                            <SlotBox
                                key={slotKey}
                                slotKey={slotKey}
                                label={slot?.label ?? slotKey}
                                rect={rect}
                                selected={selectedSlot === slotKey}
                                canvasWidth={displayed.width}
                                canvasHeight={displayed.height}
                                backgroundImageUrl={activeSig?.previewUrl}
                                onSelect={() => setSelectedSlot(slotKey)}
                                onChange={(next) =>
                                    setSlotRects((s) => ({ ...s, [slotKey]: next }))
                                }
                                onRemove={() => removeSlot(slotKey)}
                            />
                        );
                    })}
                </div>

                {/* Available-slot picker */}
                {meta.slots.length > 0 && (
                    <div className="mt-3 flex flex-wrap gap-2">
                        {meta.slots.map((slot) => {
                            const placed = slotRects[slot.key] !== undefined;
                            return (
                                <button
                                    key={slot.key}
                                    type="button"
                                    onClick={() => placed ? removeSlot(slot.key) : placeOnSlot(slot.key)}
                                    className={
                                        'rounded-md border px-2.5 py-1 text-xs font-medium ' +
                                        (placed
                                            ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-400'
                                            : 'border-gray-300 bg-white text-gray-700 hover:border-primary-500 hover:text-primary-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:border-primary-400')
                                    }
                                >
                                    {placed ? '× ' : '+ '}{slot.label}
                                    {slot.required && <span className="ml-1 text-red-500">*</span>}
                                </button>
                            );
                        })}
                    </div>
                )}
            </div>

            {/* Bottom signature library strip */}
            <div className="rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Your stored signatures
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    {/* Currently-active signature card */}
                    <SignatureChip
                        signature={meta.signature}
                        active={true}
                        onSelect={() => setActiveSig({
                            uuid:       meta.signature.uuid,
                            previewUrl: meta.signature.previewUrl,
                        })}
                    />

                    {meta.library.map((sig) => (
                        <SignatureChip
                            key={sig.uuid}
                            signature={sig}
                            active={activeSig?.uuid === sig.uuid}
                            onSelect={() => setActiveSig({
                                uuid:       sig.uuid,
                                previewUrl: sig.previewUrl,
                            })}
                        />
                    ))}

                    {meta.library.length === 0 && (
                        <div className="text-xs text-gray-400">
                            No other stored signatures.
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function SignatureChip({ signature, active, onSelect }) {
    return (
        <button
            type="button"
            onClick={onSelect}
            className={
                'flex h-16 w-24 items-center justify-center overflow-hidden rounded-lg border-2 bg-white p-1 transition dark:bg-white/5 ' +
                (active
                    ? 'border-primary-500 ring-2 ring-primary-500/30'
                    : 'border-gray-200 hover:border-primary-400 dark:border-white/10')
            }
            aria-label={`Use signature ${signature.uuid.slice(0, 8)}`}
        >
            {signature.previewUrl
                ? <img src={signature.previewUrl} alt="" className="max-h-full max-w-full object-contain" />
                : <span className="text-[10px] text-gray-400">No preview</span>}
        </button>
    );
}

function LoadingPanel() {
    return (
        <div className="rounded-xl bg-white p-8 text-sm text-gray-500 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-400 dark:ring-white/10">
            Loading signer…
        </div>
    );
}

function ErrorPanel({ message }) {
    return (
        <div className="rounded-xl bg-white p-8 text-sm text-red-600 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-red-400 dark:ring-white/10">
            {message}
        </div>
    );
}

function round2(n) {
    return Math.round(n * 100) / 100;
}

// ── Island lifecycle ─────────────────────────────────────────────────────────

export function mountSigner(el) {
    const root = createRoot(el);
    root.render(<PdfSigningIsland el={el} />);
    return root;
}

export function unmountSigner(root) {
    root.unmount();
}
