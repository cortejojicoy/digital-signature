import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { SlotBox } from './components/SlotBox.jsx';

/**
 * PDF Placement Designer.
 *
 * Mounts on any element with [data-pdf-designer]. The element must
 * carry the URL templates and CSRF token as data-* attributes — see
 * resources/views/filament/pages/pdf-template-designer.blade.php.
 *
 * Coordinate spaces involved:
 *
 *   - CSS pixels       — what SlotBox manipulates directly
 *   - Image pixels     — the raster the backend returned (cached)
 *   - PDF points       — what we save to the digital_pdf_template_slots table
 *
 * The conversion factors live in `pageScale` and are recomputed
 * whenever the image's displayed size changes (window resize, etc).
 */
function PdfDesignerIsland({ el }) {
    const config = useMemo(() => ({
        templateKey:     el.dataset.templateKey,
        metaUrl:         el.dataset.metaUrl,
        pageUrlTemplate: el.dataset.pageUrlTemplate, // contains __PAGE__
        saveUrlTemplate: el.dataset.saveUrlTemplate, // contains __PAGE__ + __SLOT__
        csrfToken:       el.dataset.csrfToken,
    }), [el]);

    const [meta, setMeta]     = useState(null);     // bootstrap payload
    const [error, setError]   = useState(null);
    const [activePage, setActivePage] = useState(1);
    const [selectedSlot, setSelectedSlot] = useState(null);
    const [saving, setSaving] = useState({});       // { slotKey: bool }
    const [savedTick, setSavedTick] = useState({}); // { slotKey: timestamp }

    // slotRects holds the CSS-pixel rect per slot for the current page.
    // We keep it separate from meta.slots so dragging doesn't re-render
    // siblings; only the moving box updates.
    const [slotRects, setSlotRects] = useState({}); // { slotKey: {x,y,w,h} }

    const imgRef = useRef(null);
    const [displayed, setDisplayed] = useState({ width: 0, height: 0 });

    // ── Bootstrap ────────────────────────────────────────────────────────────
    useEffect(() => {
        let alive = true;
        fetch(config.metaUrl, { credentials: 'same-origin' })
            .then((r) => r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`)))
            .then((payload) => alive && setMeta(payload))
            .catch((e) => alive && setError(`Failed to load template: ${e.message}`));
        return () => { alive = false; };
    }, [config.metaUrl]);

    // ── Page change: seed slotRects from meta + page dimensions ─────────────
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
                // PDF y is from bottom; CSS y is from top
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

    // ── Save handler — converts CSS-pixel rect to PDF points ────────────────
    const saveSlot = useCallback(async (slotKey) => {
        if (!pageInfo) return;
        const rect = slotRects[slotKey];
        if (!rect) return;

        const pdfX = rect.x / scaleX;
        const pdfWidth  = rect.width  / scaleX;
        const pdfHeight = rect.height / scaleY;
        // CSS y is top-down; PDF y is bottom-up. Convert the CSS-space
        // top-edge into PDF-space bottom-edge of the box.
        const pdfY = pageInfo.heightPt - (rect.y / scaleY) - pdfHeight;

        const url = config.saveUrlTemplate.replace('__SLOT__', encodeURIComponent(slotKey));

        setSaving((s) => ({ ...s, [slotKey]: true }));

        try {
            const res = await fetch(url, {
                method:  'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': config.csrfToken,
                },
                body: JSON.stringify({
                    page:   activePage,
                    x:      round2(pdfX),
                    y:      round2(pdfY),
                    width:  round2(pdfWidth),
                    height: round2(pdfHeight),
                }),
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            setSavedTick((s) => ({ ...s, [slotKey]: Date.now() }));
        } catch (e) {
            setError(`Save failed for "${slotKey}": ${e.message}`);
        } finally {
            setSaving((s) => ({ ...s, [slotKey]: false }));
        }
    }, [config, slotRects, scaleX, scaleY, pageInfo, activePage]);

    // ── Render ───────────────────────────────────────────────────────────────
    if (error)   return <Panel><div className="p-4 text-sm text-red-600 dark:text-red-400">{error}</div></Panel>;
    if (!meta)   return <Panel><div className="p-4 text-sm text-gray-500 dark:text-gray-400">Loading…</div></Panel>;

    const pageImageUrl = config.pageUrlTemplate.replace('__PAGE__', String(activePage));

    return (
        <Panel>
            <div className="flex flex-col gap-4 p-4">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-base font-semibold text-gray-950 dark:text-white">
                            {meta.template.label}
                        </h2>
                        <p className="text-xs text-gray-500 dark:text-gray-400">
                            Drag any slot to position it. Click a slot, then use arrow keys to nudge (Shift = 10px).
                            Bottom-right handle resizes. Press Save to persist.
                        </p>
                    </div>
                    {meta.pages.length > 1 && (
                        <div className="flex items-center gap-1">
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
                </header>

                <div className="grid gap-4 md:grid-cols-[1fr_240px]">
                    {/* Canvas */}
                    <div className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">
                        <div className="relative inline-block max-w-full">
                            <img
                                ref={imgRef}
                                src={pageImageUrl}
                                alt={`Page ${activePage}`}
                                className="block max-w-full select-none"
                                draggable={false}
                            />
                            {Object.entries(slotRects).map(([slotKey, rect]) => {
                                const slot = meta.slots.find((s) => s.key === slotKey);
                                if (!slot) return null;
                                return (
                                    <SlotBox
                                        key={slotKey}
                                        slotKey={slotKey}
                                        label={slot.label}
                                        rect={rect}
                                        selected={selectedSlot === slotKey}
                                        canvasWidth={displayed.width}
                                        canvasHeight={displayed.height}
                                        onSelect={() => setSelectedSlot(slotKey)}
                                        onChange={(next) =>
                                            setSlotRects((s) => ({ ...s, [slotKey]: next }))
                                        }
                                    />
                                );
                            })}
                        </div>
                    </div>

                    {/* Slot side panel */}
                    <aside className="space-y-2">
                        {meta.slots.map((slot) => {
                            const onThisPage =
                                (slot.placement?.page ?? slot.placement?.page) === activePage
                                || slotRects[slot.key] !== undefined;
                            const isSelected = selectedSlot === slot.key;
                            return (
                                <div
                                    key={slot.key}
                                    className={
                                        'rounded-lg border p-3 ' +
                                        (isSelected
                                            ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                                            : 'border-gray-200 dark:border-white/10')
                                    }
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <div>
                                            <div className="text-sm font-medium text-gray-950 dark:text-white">
                                                {slot.label}
                                                {slot.required && (
                                                    <span className="ml-1 text-red-500">*</span>
                                                )}
                                            </div>
                                            <div className="text-xs text-gray-500 dark:text-gray-400">
                                                {slot.key}
                                            </div>
                                        </div>
                                        {savedTick[slot.key] && (
                                            <span className="text-xs text-emerald-600 dark:text-emerald-400">
                                                Saved
                                            </span>
                                        )}
                                    </div>

                                    {!onThisPage && (
                                        <button
                                            type="button"
                                            className="mt-2 text-xs text-primary-600 hover:underline"
                                            onClick={() => {
                                                // Drop a default 200x40 box centered on the page
                                                if (!displayed.width) return;
                                                setSlotRects((s) => ({
                                                    ...s,
                                                    [slot.key]: {
                                                        x: Math.max(0, displayed.width / 2 - 100),
                                                        y: Math.max(0, displayed.height / 2 - 20),
                                                        width: 200,
                                                        height: 40,
                                                    },
                                                }));
                                                setSelectedSlot(slot.key);
                                            }}
                                        >
                                            + Place on this page
                                        </button>
                                    )}

                                    {onThisPage && (
                                        <button
                                            type="button"
                                            disabled={saving[slot.key]}
                                            onClick={() => saveSlot(slot.key)}
                                            className={
                                                'mt-2 inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-medium ' +
                                                (saving[slot.key]
                                                    ? 'bg-gray-200 text-gray-500'
                                                    : 'bg-primary-600 text-white hover:bg-primary-500')
                                            }
                                        >
                                            {saving[slot.key] ? 'Saving…' : 'Save position'}
                                        </button>
                                    )}
                                </div>
                            );
                        })}
                    </aside>
                </div>
            </div>
        </Panel>
    );
}

function Panel({ children }) {
    return (
        <div className="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            {children}
        </div>
    );
}

function round2(n) {
    return Math.round(n * 100) / 100;
}

// ── Island lifecycle ─────────────────────────────────────────────────────────

export function mountDesigner(el) {
    const root = createRoot(el);
    root.render(<PdfDesignerIsland el={el} />);
    return root;
}

export function unmountDesigner(root) {
    root.unmount();
}
