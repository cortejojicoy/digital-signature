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
 * actual PDF and drag their own signature onto it.
 *
 * Three things are load-bearing:
 *
 *  1. **The worker is local.** `workerSrc` comes from a data attribute the
 *     Blade view fills from the published asset. Pointing it at a CDN — which
 *     is the normal pdf.js recipe — would mean an admin panel behind a
 *     firewall silently renders nothing at all.
 *
 *  2. **Geometry comes from the PDF.** pdf.js reports each page's size in PDF
 *     points, so no server-side rasterizing (and therefore no Imagick +
 *     Ghostscript) is needed just to read a document.
 *
 *  3. **Conversion measures, it does not assume.** The CSS→points scale is
 *     derived from the canvas's real `getBoundingClientRect()`, so a page the
 *     browser laid out at some other size — page zoom, a constraining
 *     container — still commits the rectangle the signatory actually saw.
 */
function PdfViewerIsland({ el }) {
    const config = useMemo(() => ({
        metaUrl:   el.dataset.metaUrl,
        signUrl:   el.dataset.signUrl,
        workerSrc: el.dataset.workerSrc,
        csrfToken: el.dataset.csrfToken ?? '',
        requestId: el.dataset.requestId ?? null,
    }), [el]);

    const [meta, setMeta]       = useState(null);
    const [pages, setPages]     = useState([]);     // [{ number, widthPt, heightPt }]
    const [error, setError]     = useState(null);
    const [notice, setNotice]   = useState(null);
    const [zoom, setZoom]       = useState(1);
    const [activeSig, setActiveSig] = useState(null);
    const [aspect, setAspect]   = useState(null);   // natural w/h of the active signature
    const [placement, setPlacement] = useState(null); // { page, rect: {x,y,width,height} } in CSS px
    const [dragging, setDragging]   = useState(null); // { sig, x, y } while dragging from the tray
    const [busy, setBusy]       = useState(false);
    const [done, setDone]       = useState(null);

    const pdfRef     = useRef(null);
    const canvasRefs = useRef(new Map());   // page number → <canvas>
    const scrollRef  = useRef(null);

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
                setActiveSig(payload.signatures?.[0] ?? null);

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
            // CSS box at exactly `zoom × points`, so the placement maths below
            // never has to know about retina displays.
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

    // ── Keep the aspect ratio of whichever signature is active ──────────────
    useEffect(() => {
        if (!activeSig?.previewUrl) { setAspect(null); return; }
        let alive = true;
        const img = new Image();
        img.onload = () => {
            if (alive && img.naturalHeight > 0) {
                setAspect(img.naturalWidth / img.naturalHeight);
            }
        };
        img.src = activeSig.previewUrl;
        return () => { alive = false; };
    }, [activeSig]);

    // ── Seed the box from the slot's frozen placement ────────────────────────
    //
    // The administrator already decided where this slot belongs. Opening there
    // means a signatory who just wants to sign can drop and commit, and the
    // drag gesture stays an override rather than a chore.
    useEffect(() => {
        if (!meta || pages.length === 0 || placement !== null) return;

        const frozen = meta.request?.placement;
        if (!frozen) return;

        const page = pages.find((p) => p.number === frozen.page);
        if (!page) return;

        setPlacement({
            page: frozen.page,
            // The canvas is sized to exactly `points × zoom` below, so that is
            // the box even before layout has happened — which it has not, the
            // first time this runs.
            rect: pdfPointsToCssRect(frozen, page, {
                width:  page.widthPt  * zoom,
                height: page.heightPt * zoom,
            }),
        });
    }, [meta, pages, zoom, placement]);

    // ── Dragging a signature out of the tray and onto a page ────────────────

    const beginTrayDrag = useCallback((sig, e) => {
        e.preventDefault();
        setActiveSig(sig);
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

        const hit = pageUnderPointer(canvasRefs.current, e.clientX, e.clientY);
        if (!hit) {
            setNotice('Drop your signature onto a page of the document.');
            return;
        }

        const { number, box } = hit;
        const page = pages.find((p) => p.number === number);
        if (!page) return;

        // Default to the slot's own size when the administrator set one, so a
        // dropped signature matches the layout the document was designed for.
        const frozen = meta?.request?.placement;
        const width  = frozen && frozen.page === number
            ? frozen.width * zoom
            : Math.min(box.width * 0.3, 220);
        const height = aspect ? width / aspect : width / 3.2;

        // Centre the box on the pointer: the signature was under the cursor
        // while dragging, so that is where the user believes they dropped it.
        setPlacement({
            page: number,
            rect: {
                x:      clamp(e.clientX - box.left - width  / 2, 0, box.width  - width),
                y:      clamp(e.clientY - box.top  - height / 2, 0, box.height - height),
                width,
                height,
            },
        });
        setNotice(null);
    }, [dragging, pages, meta, zoom, aspect]);

    /**
     * The keyboard/click equivalent of the drag. Drag is a convenience, not
     * the only door: this places the box at the frozen slot, or the centre of
     * the first page, and SlotBox's arrow-key nudging takes it from there.
     */
    const placeWithoutDragging = useCallback(() => {
        if (pages.length === 0) return;

        const frozen = meta?.request?.placement;
        const page   = pages.find((p) => p.number === (frozen?.page ?? 1)) ?? pages[0];
        const cssW   = page.widthPt  * zoom;
        const cssH   = page.heightPt * zoom;

        const width  = frozen ? frozen.width * zoom : Math.min(cssW * 0.3, 220);
        const height = frozen
            ? frozen.height * zoom
            : (aspect ? width / aspect : width / 3.2);

        setPlacement({
            page: page.number,
            rect: frozen
                ? { x: frozen.x * zoom, y: (page.heightPt - frozen.y - frozen.height) * zoom, width, height }
                : { x: (cssW - width) / 2, y: (cssH - height) / 2, width, height },
        });
    }, [pages, meta, zoom, aspect]);

    // ── Commit ───────────────────────────────────────────────────────────────

    const commit = useCallback(async () => {
        if (!placement) {
            setNotice('Place your signature on the document first.');
            return;
        }

        const page   = pages.find((p) => p.number === placement.page);
        const canvas = canvasRefs.current.get(placement.page);
        if (!page || !canvas) return;

        // Measure rather than assume. Whatever the browser actually laid the
        // page out at is the space the rectangle was drawn in.
        const payload = {
            page: placement.page,
            ...cssRectToPdfPoints(placement.rect, page, canvas.getBoundingClientRect()),
            signature_id: activeSig?.id ?? null,
        };

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
                body: JSON.stringify(payload),
            });
            const body = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(body?.error || await describeFailure(res));

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
    }, [placement, pages, activeSig, config]);

    const close = useCallback(() => {
        window.dispatchEvent(new CustomEvent('dsig:viewer-close', {
            detail: { requestId: config.requestId },
        }));
    }, [config.requestId]);

    // ── Render ───────────────────────────────────────────────────────────────

    if (error && !meta) return <Panel tone="error">{error}</Panel>;
    if (!meta)          return <Panel>Loading document…</Panel>;

    if (done) {
        return (
            <Panel tone="ok">
                <strong style={{ display: 'block', marginBottom: '.35rem' }}>Document signed</strong>
                {done.message}
                <div style={{ marginTop: '.9rem' }}>
                    <button type="button" className="dsig-btn" onClick={close}>Back to queue</button>
                </div>
            </Panel>
        );
    }

    const blocked  = meta.request?.blocked;
    const noSigs   = (meta.signatures ?? []).length === 0;
    const pageInfo = pages.find((p) => p.number === placement?.page);

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

                <button
                    type="button"
                    className="dsig-btn"
                    onClick={commit}
                    disabled={busy || blocked || noSigs || !placement}
                >
                    {busy ? 'Signing…' : 'Sign here'}
                </button>
            </div>

            {blocked && (
                <p className="dsig-viewer__msg dsig-viewer__msg--warn">
                    An earlier signatory must sign before you can.
                </p>
            )}
            {noSigs && (
                <p className="dsig-viewer__msg dsig-viewer__msg--warn">
                    You have no registered signature yet, so there is nothing to place.
                </p>
            )}
            {error  && <p className="dsig-viewer__msg dsig-viewer__msg--error">{error}</p>}
            {notice && <p className="dsig-viewer__msg">{notice}</p>}

            <div className="dsig-viewer__pages" ref={scrollRef}>
                {pages.length === 0 && <Panel>Rendering pages…</Panel>}

                {pages.map((page) => (
                    <div key={page.number} className="dsig-viewer__page">
                        <canvas
                            ref={(node) => {
                                if (node) canvasRefs.current.set(page.number, node);
                                else canvasRefs.current.delete(page.number);
                            }}
                            data-page={page.number}
                            style={{ display: 'block' }}
                        />

                        {placement?.page === page.number && pageInfo && (
                            <SlotBox
                                slotKey={meta.request.slot ?? 'signature'}
                                label={meta.request.role ?? 'Your signature'}
                                rect={placement.rect}
                                selected
                                canvasWidth={pageInfo.widthPt  * zoom}
                                canvasHeight={pageInfo.heightPt * zoom}
                                backgroundImageUrl={activeSig?.previewUrl}
                                aspect={aspect}
                                onSelect={() => {}}
                                onChange={(rect) => setPlacement((p) => ({ ...p, rect }))}
                                onRemove={() => setPlacement(null)}
                            />
                        )}

                        <span className="dsig-viewer__pageno">{page.number}</span>
                    </div>
                ))}
            </div>

            {/* Signature tray — the drag source */}
            <div className="dsig-viewer__tray">
                <span className="dsig-viewer__trayhint">
                    Drag a signature onto the page, or
                    {' '}
                    <button type="button" className="dsig-linkbtn" onClick={placeWithoutDragging}
                            disabled={noSigs}>
                        place it with the keyboard
                    </button>
                    . Drag the corner to resize; hold Shift to distort.
                </span>

                <div className="dsig-viewer__chips">
                    {(meta.signatures ?? []).map((sig) => (
                        <button
                            key={sig.id}
                            type="button"
                            className={'dsig-chip' + (activeSig?.id === sig.id ? ' dsig-chip--on' : '')}
                            onPointerDown={(e) => beginTrayDrag(sig, e)}
                            onPointerMove={moveTrayDrag}
                            onPointerUp={endTrayDrag}
                            onPointerCancel={() => setDragging(null)}
                            onClick={() => setActiveSig(sig)}
                            aria-label={`Use signature ${sig.uuid?.slice(0, 8) ?? sig.id}`}
                        >
                            {sig.previewUrl
                                ? <img src={sig.previewUrl} alt="" draggable={false} />
                                : <span>No preview</span>}
                        </button>
                    ))}
                </div>
            </div>

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

/** Did this press travel far enough to be a drag rather than a click? */
function movedEnough(drag, e, threshold = 6) {
    return Math.abs(e.clientX - drag.fromX) > threshold
        || Math.abs(e.clientY - drag.fromY) > threshold;
}

function clamp(n, min, max) {
    return Math.min(Math.max(n, min), Math.max(min, max));
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
