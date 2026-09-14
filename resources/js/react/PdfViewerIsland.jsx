import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import * as pdfjs from 'pdfjs-dist/legacy/build/pdf.mjs';
import { SlotBox } from './components/SlotBox.jsx';
import { cssRectToPdfPoints, pdfPointsToCssRect } from '../utils/pdfCoords.js';

/**
 * The document pane inside the signatory's drawer.
 *
 * This is the surface that makes the queue honest. Before it, the inbox
 * offered a title and a Sign button, and the signature was produced without
 * the signatory ever seeing the page it was landing on. Here they read the
 * actual PDF and drag their own signature onto it — once per slot they hold.
 *
 * Four things are load-bearing:
 *
 *  1. **Placements are stored in PDF points, not CSS pixels.** The document is
 *     the only coordinate space that does not move when the user zooms, and
 *     CSS pixels were the source of truth here originally: zooming left the
 *     box behind and committed a signature somewhere the signatory never put
 *     it. Points in, points out; CSS is derived per render.
 *
 *  2. **One placement per slot, keyed by request id.** The same person is
 *     routinely two signatories on one form. Each slot is still its own
 *     signature, chained to the last; batching them only saves the signatory
 *     from opening the same document twice.
 *
 *  3. **The worker is local.** `workerSrc` comes from a data attribute the
 *     Blade view fills from the published asset. Pointing it at a CDN — the
 *     normal pdf.js recipe — would mean an admin panel behind a firewall
 *     silently renders nothing at all.
 *
 *  4. **Geometry comes from the PDF.** pdf.js reports page sizes in points, so
 *     no server-side rasterizing (and therefore no Imagick + Ghostscript) is
 *     needed just to read a document.
 *
 * The same pane doubles as the reader for documents already signed. A slot in
 * a terminal state comes back with `readOnly`, and everything that places or
 * commits a signature is simply not rendered: a signatory is entitled to see
 * what they put their certificate on, long after there is anything left to do
 * about it.
 */
function PdfViewerIsland({ el }) {
    const config = useMemo(() => ({
        metaUrl:   el.dataset.metaUrl,
        signUrl:   el.dataset.signUrl,
        workerSrc: el.dataset.workerSrc,
        csrfToken: el.dataset.csrfToken ?? '',
        requestId: el.dataset.requestId ?? null,
    }), [el]);

    const [meta, setMeta]     = useState(null);
    const [pages, setPages]   = useState([]);   // [{ number, widthPt, heightPt }]
    const [error, setError]   = useState(null);
    const [notice, setNotice] = useState(null);
    const [zoom, setZoom]     = useState(1);

    // requestId → { page, x, y, width, height, signatureId }, in PDF points.
    const [placements, setPlacements] = useState({});
    const [activeRequestId, setActiveRequestId] = useState(null);
    const [activeSigId, setActiveSigId] = useState(null);
    const [aspects, setAspects] = useState({});   // signatureId → natural w/h

    const [dragging, setDragging] = useState(null);
    const [busy, setBusy] = useState(false);
    const [done, setDone] = useState(null);

    const pdfRef     = useRef(null);
    const canvasRefs = useRef(new Map());
    // Seeding must happen once. Re-running it after a removal would spring the
    // box straight back from the frozen placement, making "remove" look broken.
    const seededRef  = useRef(false);

    const readOnly = meta?.readOnly === true;
    const requests = meta?.requests ?? [];
    const signatures = meta?.signatures ?? [];
    const activeSig = signatures.find((s) => s.id === activeSigId) ?? null;
    const activeRequest = requests.find((r) => r.id === activeRequestId) ?? null;

    // ── Bootstrap: meta, then the document itself ───────────────────────────
    useEffect(() => {
        let alive = true;
        let loadingTask = null;

        (async () => {
            try {
                const res = await fetch(config.metaUrl, { credentials: 'same-origin' });
                if (!res.ok) throw new Error(await describeFailure(res));
                const payload = await res.json();
                if (!alive) return;

                setMeta(payload);
                setActiveSigId(payload.signatures?.[0]?.id ?? null);
                setActiveRequestId(
                    payload.requests?.find((r) => !r.blocked)?.id
                    ?? payload.requests?.[0]?.id
                    ?? null,
                );

                if (config.workerSrc) {
                    pdfjs.GlobalWorkerOptions.workerSrc = config.workerSrc;
                }

                loadingTask = pdfjs.getDocument({
                    url: payload.document.url,
                    withCredentials: true,
                    // Defence in depth. A signing queue is a place where other
                    // people's PDFs arrive, which is exactly the input class
                    // pdf.js's sandbox-escape advisories concern.
                    isEvalSupported: false,
                });

                const pdf = await loadingTask.promise;
                if (!alive) return;
                pdfRef.current = pdf;

                const sizes = [];
                for (let n = 1; n <= pdf.numPages; n++) {
                    const page = await pdf.getPage(n);
                    const v = page.getViewport({ scale: 1 });
                    sizes.push({ number: n, widthPt: v.width, heightPt: v.height });
                }
                if (!alive) return;
                setPages(sizes);
            } catch (e) {
                if (alive) setError(e.message);
            }
        })();

        return () => {
            alive = false;
            loadingTask?.destroy?.();
            pdfRef.current?.destroy?.();
            pdfRef.current = null;
        };
    }, [config.metaUrl, config.workerSrc]);

    // ── Render every page whenever the document or the zoom changes ─────────
    useEffect(() => {
        const pdf = pdfRef.current;
        if (!pdf || pages.length === 0) return;

        let cancelled = false;
        const tasks = [];

        (async () => {
            // Match the device pixel ratio in the backing store but keep the
            // CSS box at exactly `points × zoom`, so the placement maths never
            // has to know about retina displays.
            const dpr = window.devicePixelRatio || 1;

            for (const info of pages) {
                if (cancelled) return;
                const canvas = canvasRefs.current.get(info.number);
                if (!canvas) continue;

                const page     = await pdf.getPage(info.number);
                const viewport = page.getViewport({ scale: zoom * dpr });

                canvas.width  = Math.floor(viewport.width);
                canvas.height = Math.floor(viewport.height);
                canvas.style.width  = `${info.widthPt  * zoom}px`;
                canvas.style.height = `${info.heightPt * zoom}px`;

                const task = page.render({
                    canvas,
                    canvasContext: canvas.getContext('2d'),
                    viewport,
                });
                tasks.push(task);
                try {
                    await task.promise;
                } catch (e) {
                    if (e?.name !== 'RenderingCancelledException') throw e;
                }
            }
        })().catch((e) => {
            if (!cancelled) setError(`Failed to render the document: ${e.message}`);
        });

        return () => {
            cancelled = true;
            tasks.forEach((t) => t.cancel?.());
        };
    }, [pages, zoom]);

    // ── Natural aspect of each stored signature ─────────────────────────────
    //
    // Fed to SlotBox so resizing holds the ratio. A stretched signature is a
    // stamp that no longer matches the specimen on file — a defect in the
    // document rather than in the layout.
    useEffect(() => {
        let alive = true;

        for (const sig of signatures) {
            if (!sig.previewUrl || aspects[sig.id] !== undefined) continue;
            const img = new Image();
            img.onload = () => {
                if (alive && img.naturalHeight > 0) {
                    setAspects((a) => ({ ...a, [sig.id]: img.naturalWidth / img.naturalHeight }));
                }
            };
            img.src = sig.previewUrl;
        }

        return () => { alive = false; };
    }, [signatures, aspects]);

    // ── Seed each slot from its frozen placement, once ──────────────────────
    //
    // The administrator already decided where each slot belongs. Opening there
    // means a signatory who just wants to sign can commit immediately, and the
    // drag gesture stays an override rather than a chore.
    useEffect(() => {
        if (seededRef.current || !meta || pages.length === 0) return;
        seededRef.current = true;

        // Nothing to place on a document that is already signed. Its stamps
        // are in the PDF itself, which is what the pages below are rendering.
        if (meta.readOnly) return;

        const seeded = {};
        for (const request of requests) {
            if (!request.placement) continue;
            if (!pages.some((p) => p.number === request.placement.page)) continue;
            seeded[request.id] = { ...request.placement, signatureId: activeSigId };
        }

        if (Object.keys(seeded).length > 0) setPlacements(seeded);
    }, [meta, pages, requests, activeSigId]);

    // ── Dragging a signature out of the tray and onto a page ────────────────

    const beginTrayDrag = useCallback((sig, e) => {
        e.preventDefault();
        setActiveSigId(sig.id);
        setDragging({ sig, x: e.clientX, y: e.clientY, fromX: e.clientX, fromY: e.clientY });
        e.currentTarget.setPointerCapture?.(e.pointerId);
    }, []);

    const moveTrayDrag = useCallback((e) => {
        setDragging((d) => (d ? { ...d, x: e.clientX, y: e.clientY } : d));
    }, []);

    const endTrayDrag = useCallback((e) => {
        if (!dragging) return;
        setDragging(null);

        // A press that never moved is a click selecting which signature to
        // use, not a drop. Without this, clicking a chip would scold the user
        // for not dropping it on a page.
        if (!movedEnough(dragging, e)) return;

        if (!activeRequest) {
            setNotice('Every slot you hold on this document already has a signature.');
            return;
        }

        const hit = pageUnderPointer(canvasRefs.current, e.clientX, e.clientY);
        if (!hit) {
            setNotice('Drop your signature onto a page of the document.');
            return;
        }

        const page = pages.find((p) => p.number === hit.number);
        if (!page) return;

        // The measured box and the nominal one differ under browser page zoom,
        // so map the pointer through the ratio rather than assuming they match.
        const nominal = nominalBox(page, zoom);
        const localX  = (e.clientX - hit.box.left) * (nominal.width  / hit.box.width);
        const localY  = (e.clientY - hit.box.top)  * (nominal.height / hit.box.height);

        // Default to the slot's own size where the administrator set one, so a
        // dropped signature matches the layout the document was designed for.
        const frozen = activeRequest.placement;
        const aspect = aspects[dragging.sig.id];
        const width  = frozen && frozen.page === hit.number
            ? frozen.width * zoom
            : Math.min(nominal.width * 0.3, 220);
        const height = aspect ? width / aspect : width / 3.2;

        // Centred on the pointer: the signature was under the cursor while
        // dragging, so that is where the user believes they dropped it.
        const rect = {
            x:      clamp(localX - width  / 2, 0, nominal.width  - width),
            y:      clamp(localY - height / 2, 0, nominal.height - height),
            width,
            height,
        };

        setPlacements((current) => ({
            ...current,
            [activeRequest.id]: {
                page: hit.number,
                ...cssRectToPdfPoints(rect, page, nominal),
                signatureId: dragging.sig.id,
            },
        }));
        setNotice(null);
    }, [dragging, activeRequest, pages, zoom, aspects]);

    /**
     * The keyboard/click equivalent of the drag. Drag is a convenience, not
     * the only door: this places the active slot's box at its frozen position,
     * or the centre of the first page, and SlotBox's arrow keys take it from
     * there.
     */
    const placeWithoutDragging = useCallback(() => {
        if (pages.length === 0 || !activeRequest) return;

        const frozen = activeRequest.placement;
        const page   = pages.find((p) => p.number === (frozen?.page ?? 1)) ?? pages[0];

        if (frozen && page.number === frozen.page) {
            setPlacements((current) => ({
                ...current,
                [activeRequest.id]: { ...frozen, signatureId: activeSigId },
            }));
            return;
        }

        const nominal = nominalBox(page, zoom);
        const aspect  = aspects[activeSigId];
        const width   = Math.min(nominal.width * 0.3, 220);
        const height  = aspect ? width / aspect : width / 3.2;

        setPlacements((current) => ({
            ...current,
            [activeRequest.id]: {
                page: page.number,
                ...cssRectToPdfPoints({
                    x: (nominal.width - width) / 2,
                    y: (nominal.height - height) / 2,
                    width,
                    height,
                }, page, nominal),
                signatureId: activeSigId,
            },
        }));
    }, [pages, activeRequest, zoom, aspects, activeSigId]);

    const removePlacement = useCallback((requestId) => {
        setPlacements((current) => {
            const next = { ...current };
            delete next[requestId];
            return next;
        });
        setActiveRequestId(requestId);
    }, []);

    // ── Commit ───────────────────────────────────────────────────────────────

    const placedIds = Object.keys(placements);

    const commit = useCallback(async () => {
        if (placedIds.length === 0) {
            setNotice('Place your signature on the document first.');
            return;
        }

        const payload = placedIds.map((requestId) => {
            const p = placements[requestId];
            return {
                request_id:   Number(requestId),
                page:         p.page,
                x:            round2(p.x),
                y:            round2(p.y),
                width:        round2(p.width),
                height:       round2(p.height),
                signature_id: p.signatureId ?? null,
            };
        });

        setBusy(true);
        setError(null);
        setNotice(null);

        try {
            const res = await fetch(config.signUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': config.csrfToken,
                },
                body: JSON.stringify({ placements: payload }),
            });
            const body = await res.json().catch(() => ({}));

            if (!res.ok) {
                // A partial result is not a failure to report and forget: some
                // of those signatures are on the document now, and saying only
                // "it failed" would send the signatory back to re-sign slots
                // that are already done.
                if (body?.status === 'partial') {
                    setDone(body);
                    window.dispatchEvent(new CustomEvent('dsig:signed', {
                        detail: { requestId: config.requestId, ...body },
                    }));
                    return;
                }
                throw new Error(body?.error || await describeFailure(res));
            }

            setDone(body);
            // The drawer owns the queue; tell it to refresh rather than
            // reaching into Livewire from here.
            window.dispatchEvent(new CustomEvent('dsig:signed', {
                detail: { requestId: config.requestId, ...body },
            }));
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }, [placedIds, placements, config]);

    const close = useCallback(() => {
        window.dispatchEvent(new CustomEvent('dsig:viewer-close', {
            detail: { requestId: config.requestId },
        }));
    }, [config.requestId]);

    // ── Render ───────────────────────────────────────────────────────────────

    if (error && !meta) return <Panel tone="error">{error}</Panel>;
    if (!meta)          return <Panel>Loading document…</Panel>;

    if (done) {
        const partial = done.status === 'partial';
        return (
            <Panel tone={partial ? 'error' : 'ok'}>
                <strong style={{ display: 'block', marginBottom: '.35rem' }}>
                    {partial ? 'Partly signed' : 'Document signed'}
                </strong>
                {partial
                    ? `${(done.signed ?? []).map((s) => s.role || s.slot).join(', ') || 'Nothing'} signed. `
                      + `${done.failed_on?.slot ?? 'A later slot'} could not be: ${done.error}`
                    : done.message}
                <div style={{ marginTop: '.9rem' }}>
                    <button type="button" className="dsig-btn" onClick={close}>Back to queue</button>
                </div>
            </Panel>
        );
    }

    const noSigs = signatures.length === 0;
    const blockedPlaced = placedIds.some(
        (id) => requests.find((r) => r.id === Number(id))?.blocked,
    );

    return (
        <div className="dsig-viewer">
            <div className="dsig-viewer__bar">
                <button type="button" className="dsig-btn dsig-btn--ghost" onClick={close}>
                    ← Queue
                </button>
                <span className="dsig-viewer__title">{meta.document.title}</span>

                <span className="dsig-viewer__zoom">
                    <button type="button" onClick={() => setZoom((z) => clamp(z - 0.2, 0.4, 3))}
                            aria-label="Zoom out">−</button>
                    <span>{Math.round(zoom * 100)}%</span>
                    <button type="button" onClick={() => setZoom((z) => clamp(z + 0.2, 0.4, 3))}
                            aria-label="Zoom in">+</button>
                </span>

                {readOnly ? (
                    <span className="dsig-viewer__stamp">
                        {meta.stateLabel ?? 'Signed'}
                        {meta.settledAt ? ` · ${formatMoment(meta.settledAt)}` : ''}
                    </span>
                ) : (
                    <button
                        type="button"
                        className="dsig-btn"
                        onClick={commit}
                        disabled={busy || noSigs || placedIds.length === 0 || blockedPlaced}
                    >
                        {busy
                            ? 'Signing…'
                            : placedIds.length > 1 ? `Sign ${placedIds.length} places` : 'Sign here'}
                    </button>
                )}
            </div>

            {/*
                Slot picker. Only shown when this signatory holds more than one
                slot on the document — with a single slot it would be a control
                with one option.
            */}
            {!readOnly && requests.length > 1 && (
                <div className="dsig-viewer__slots">
                    <span className="dsig-viewer__trayhint">
                        You are {requests.length} signatories on this document. Place each:
                    </span>
                    <div className="dsig-viewer__chips">
                        {requests.map((request) => (
                            <button
                                key={request.id}
                                type="button"
                                className={
                                    'dsig-slotchip'
                                    + (activeRequestId === request.id ? ' dsig-slotchip--on' : '')
                                    + (placements[request.id] ? ' dsig-slotchip--placed' : '')
                                }
                                onClick={() => setActiveRequestId(request.id)}
                                disabled={request.blocked}
                                title={request.blocked
                                    ? 'An earlier signatory must sign before this slot'
                                    : undefined}
                            >
                                {placements[request.id] ? '✓ ' : ''}{request.role || request.slot}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {blockedPlaced && (
                <p className="dsig-viewer__msg dsig-viewer__msg--warn">
                    One of the slots you placed is waiting on an earlier signatory.
                </p>
            )}
            {!readOnly && noSigs && (
                <p className="dsig-viewer__msg dsig-viewer__msg--warn">
                    You have no registered signature yet, so there is nothing to place.
                </p>
            )}
            {error  && <p className="dsig-viewer__msg dsig-viewer__msg--error">{error}</p>}
            {notice && <p className="dsig-viewer__msg">{notice}</p>}

            <div className="dsig-viewer__pages">
                {pages.length === 0 && <Panel>Rendering pages…</Panel>}

                {pages.map((page) => {
                    const nominal = nominalBox(page, zoom);

                    return (
                        <div key={page.number} className="dsig-viewer__page">
                            <canvas
                                ref={(node) => {
                                    if (node) canvasRefs.current.set(page.number, node);
                                    else canvasRefs.current.delete(page.number);
                                }}
                                data-page={page.number}
                                style={{ display: 'block' }}
                            />

                            {placedIds
                                .filter((id) => placements[id].page === page.number)
                                .map((id) => {
                                    const placement = placements[id];
                                    const request = requests.find((r) => r.id === Number(id));
                                    const sig = signatures.find((s) => s.id === placement.signatureId)
                                        ?? activeSig;

                                    return (
                                        <SlotBox
                                            key={id}
                                            slotKey={request?.slot ?? String(id)}
                                            label={request?.role ?? 'Your signature'}
                                            rect={pdfPointsToCssRect(placement, page, nominal)}
                                            selected={activeRequestId === Number(id)}
                                            canvasWidth={nominal.width}
                                            canvasHeight={nominal.height}
                                            backgroundImageUrl={sig?.previewUrl}
                                            aspect={aspects[placement.signatureId]}
                                            onSelect={() => setActiveRequestId(Number(id))}
                                            onChange={(rect) => setPlacements((current) => ({
                                                ...current,
                                                [id]: {
                                                    ...current[id],
                                                    ...cssRectToPdfPoints(rect, page, nominal),
                                                },
                                            }))}
                                            onRemove={() => removePlacement(Number(id))}
                                        />
                                    );
                                })}

                            <span className="dsig-viewer__pageno">{page.number}</span>
                        </div>
                    );
                })}
            </div>

            {/* Signature tray — the drag source. Absent once there is nothing
                left to sign, so the reader is a reader. */}
            {!readOnly && (
            <div className="dsig-viewer__tray">
                <span className="dsig-viewer__trayhint">
                    Drag a signature onto the page
                    {activeRequest && requests.length > 1
                        ? ` for ${activeRequest.role || activeRequest.slot}`
                        : ''}
                    , or
                    {' '}
                    <button type="button" className="dsig-linkbtn" onClick={placeWithoutDragging}
                            disabled={noSigs || !activeRequest}>
                        place it with the keyboard
                    </button>
                    . Drag the corner to resize; hold Shift to distort.
                </span>

                <div className="dsig-viewer__chips">
                    {signatures.map((sig) => (
                        <button
                            key={sig.id}
                            type="button"
                            className={'dsig-chip' + (activeSigId === sig.id ? ' dsig-chip--on' : '')}
                            onPointerDown={(e) => beginTrayDrag(sig, e)}
                            onPointerMove={moveTrayDrag}
                            onPointerUp={endTrayDrag}
                            onPointerCancel={() => setDragging(null)}
                            onClick={() => setActiveSigId(sig.id)}
                            aria-label={`Use signature ${sig.uuid?.slice(0, 8) ?? sig.id}`}
                        >
                            {sig.previewUrl
                                ? <img src={sig.previewUrl} alt="" draggable={false} />
                                : <span>No preview</span>}
                        </button>
                    ))}
                </div>
            </div>
            )}

            {/* The thing under the cursor mid-drag */}
            {dragging && (
                <img
                    src={dragging.sig.previewUrl}
                    alt=""
                    className="dsig-viewer__ghost"
                    style={{ left: `${dragging.x}px`, top: `${dragging.y}px` }}
                />
            )}
        </div>
    );
}

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * The page's box in nominal CSS pixels.
 *
 * The canvas is given exactly this size in its inline style, so it is the
 * space SlotBox's rectangles live in — regardless of what the browser's own
 * zoom does to what `getBoundingClientRect()` reports.
 */
function nominalBox(page, zoom) {
    return { width: page.widthPt * zoom, height: page.heightPt * zoom };
}

/** Which rendered page, if any, sits under this point. */
function pageUnderPointer(canvases, clientX, clientY) {
    for (const [number, canvas] of canvases) {
        const box = canvas.getBoundingClientRect();
        if (clientX >= box.left && clientX <= box.right
            && clientY >= box.top && clientY <= box.bottom) {
            return { number, box };
        }
    }
    return null;
}

/** Did this press travel far enough to be a drag rather than a click? */
function movedEnough(drag, e, threshold = 6) {
    return Math.abs(e.clientX - drag.fromX) > threshold
        || Math.abs(e.clientY - drag.fromY) > threshold;
}

async function describeFailure(res) {
    try {
        const body = await res.clone().json();
        if (body?.error)   return body.error;
        if (body?.message) return body.message;
    } catch { /* not JSON — fall through to the status line */ }

    return res.status === 403
        ? 'That document is not assigned to you.'
        : `Request failed (HTTP ${res.status}).`;
}

function Panel({ children, tone }) {
    return <div className={'dsig-viewer__panel' + (tone ? ` dsig-viewer__panel--${tone}` : '')}>{children}</div>;
}

/** A signing timestamp, in the reader's own locale and time zone. */
function formatMoment(iso) {
    const moment = new Date(iso);
    if (Number.isNaN(moment.getTime())) return '';

    return moment.toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

function clamp(n, min, max) {
    return Math.min(Math.max(n, min), Math.max(min, max));
}

function round2(n) {
    return Math.round(n * 100) / 100;
}

// ── Island lifecycle ─────────────────────────────────────────────────────────

export function mountViewer(el) {
    const root = createRoot(el);
    root.render(<PdfViewerIsland el={el} />);
    return root;
}

export function unmountViewer(root) {
    root.unmount();
}
