import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import * as pdfjs from 'pdfjs-dist/legacy/build/pdf.mjs';
import { SlotBox } from './components/SlotBox.jsx';
import { cssRectToPdfPoints, pdfPointsToCssRect } from '../utils/pdfCoords.js';
import { layoutStamp, defaultStampBox } from '../utils/stampLayout.js';
import { StampPreview } from './components/StampPreview.jsx';

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

    // A flat list of stamps, in PDF points. Several may belong to one slot: a
    // form that asks the same person to sign in three places is one act of
    // signing with three appearances, and the server turns the repeats into
    // extra stamps of the same signature.
    // { id, requestId, page, x, y, width, height, signatureId }
    const [placements, setPlacements] = useState([]);
    const [activeRequestId, setActiveRequestId] = useState(null);
    // The stamp whose caption side the tray control moves. A slot can carry
    // several, so the request id alone would not say which.
    const [selectedStampId, setSelectedStampId] = useState(null);
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

        const seeded = [];
        for (const request of requests) {
            if (!request.placement) continue;
            if (!pages.some((p) => p.number === request.placement.page)) continue;
            seeded.push({
                id: nextStampId(),
                requestId: request.id,
                ...request.placement,
                signatureId: activeSigId,
            });
        }

        if (seeded.length > 0) setPlacements(seeded);
    }, [meta, pages, requests, activeSigId]);

    /**
     * The composed stamp for one placement, in CSS pixels.
     *
     * Laid out in PDF points — the space the writer works in — and scaled up
     * by the zoom at the end, so what the signatory drags is the division of
     * the box that will actually print.
     */
    const previewFor = useCallback((placement, signature) => {
        const layout = layoutStamp(
            { width: placement.width, height: placement.height },
            signature?.caption ?? [],
            meta?.stamp,
            placement.captionPosition,
            aspects[placement.signatureId],
        );

        return scalePreview(layout, zoom, meta?.stamp);
    }, [meta, zoom, aspects]);

    /**
     * Select the next slot that still has nothing on it.
     *
     * Falls back to leaving the current one selected when every slot is
     * placed, so a further drag repositions the highlighted box rather than
     * doing nothing at all.
     */
    const advancePast = useCallback((justPlacedId) => {
        setPlacements((current) => {
            const next = requests.find((r) =>
                r.id !== justPlacedId
                && !r.blocked
                && !current.some((p) => p.requestId === r.id));

            if (next) setActiveRequestId(next.id);

            return current;
        });
    }, [requests]);

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
        // Otherwise size it so the ink lands at its natural shape once the
        // caption and QR have taken their share.
        const frozen = activeRequest.placement;
        const box = frozen && frozen.page === hit.number
            ? { width: frozen.width, height: frozen.height }
            : defaultStampBox(aspects[dragging.sig.id], page.widthPt, meta?.stamp);
        // A dropped stamp starts on the configured side; the control in the
        // tray moves it per placement afterwards.
        const side = meta?.stamp?.caption?.position ?? 'bottom';

        const width  = box.width  * zoom;
        const height = box.height * zoom;

        // Centred on the pointer: the signature was under the cursor while
        // dragging, so that is where the user believes they dropped it.
        const rect = {
            x:      clamp(localX - width  / 2, 0, nominal.width  - width),
            y:      clamp(localY - height / 2, 0, nominal.height - height),
            width,
            height,
        };

        // Append. Dropping a second time adds a second appearance rather than
        // moving the first — a form asks for the same signature in several
        // places, and a surface that can only ever hold one is the bug this
        // replaced.
        setPlacements((current) => [...current, {
            id: nextStampId(),
            requestId: activeRequest.id,
            page: hit.number,
            ...cssRectToPdfPoints(rect, page, nominal),
            signatureId: dragging.sig.id,
            captionPosition: side,
        }]);

        // Move on to the next slot that has nothing at all on it yet, so a
        // signatory holding several slots fills them in turn without having
        // to pick each one by hand.
        advancePast(activeRequest.id);
        setNotice(null);
    }, [dragging, activeRequest, pages, zoom, aspects, advancePast, meta]);

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
            setPlacements((current) => [...current, {
                id: nextStampId(),
                requestId: activeRequest.id,
                ...frozen,
                signatureId: activeSigId,
            }]);
            advancePast(activeRequest.id);
            return;
        }

        const nominal = nominalBox(page, zoom);
        const box     = defaultStampBox(aspects[activeSigId], page.widthPt, meta?.stamp);
        const width   = box.width  * zoom;
        const height  = box.height * zoom;

        setPlacements((current) => [...current, {
            id: nextStampId(),
            requestId: activeRequest.id,
            page: page.number,
            ...cssRectToPdfPoints({
                x: (nominal.width - width) / 2,
                y: (nominal.height - height) / 2,
                width,
                height,
            }, page, nominal),
            signatureId: activeSigId,
        }]);
        advancePast(activeRequest.id);
    }, [pages, activeRequest, zoom, aspects, activeSigId, advancePast, meta]);

    const removePlacement = useCallback((stampId, requestId) => {
        setPlacements((current) => current.filter((p) => p.id !== stampId));
        setActiveRequestId(requestId);
    }, []);

    // ── Commit ───────────────────────────────────────────────────────────────

    const commit = useCallback(async () => {
        if (placements.length === 0) {
            setNotice('Place your signature on the document first.');
            return;
        }

        const payload = placements.map((p) => ({
            request_id:   p.requestId,
            page:         p.page,
            x:            round2(p.x),
            y:            round2(p.y),
            width:        round2(p.width),
            height:       round2(p.height),
            signature_id: p.signatureId ?? null,
            caption_position: p.captionPosition ?? null,
        }));

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
    }, [placements, config]);

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

    const blockedPlaced = placements.some(
        (p) => requests.find((r) => r.id === p.requestId)?.blocked,
    );

    // Slots with at least one stamp, for the "2 of 3" counter — a slot signed
    // in three places is still one slot accounted for.
    const slotsPlaced = new Set(placements.map((p) => p.requestId)).size;

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
                        disabled={busy || noSigs || placements.length === 0 || blockedPlaced}
                    >
                        {busy
                            ? 'Signing…'
                            : placements.length > 1 ? `Sign ${placements.length} places` : 'Sign here'}
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
                                    + (placements.some((p) => p.requestId === request.id)
                                        ? ' dsig-slotchip--placed' : '')
                                }
                                onClick={() => setActiveRequestId(request.id)}
                                disabled={request.blocked}
                                title={request.blocked
                                    ? 'An earlier signatory must sign before this slot'
                                    : undefined}
                            >
                                {stampCount(placements, request.id) > 1
                                    ? `✓×${stampCount(placements, request.id)} `
                                    : stampCount(placements, request.id) === 1 ? '✓ ' : ''}
                                {request.role || request.slot}
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

                            {placements
                                .filter((p) => p.page === page.number)
                                .map((placement) => {
                                    const request = requests.find((r) => r.id === placement.requestId);
                                    const sig = signatures.find((s) => s.id === placement.signatureId)
                                        ?? activeSig;

                                    return (
                                        <SlotBox
                                            key={placement.id}
                                            slotKey={request?.slot ?? String(placement.requestId)}
                                            label={request?.role ?? 'Your signature'}
                                            rect={pdfPointsToCssRect(placement, page, nominal)}
                                            selected={selectedStampId === placement.id
                                                || (selectedStampId === null
                                                    && activeRequestId === placement.requestId)}
                                            canvasWidth={nominal.width}
                                            canvasHeight={nominal.height}
                                            backgroundImageUrl={sig?.previewUrl}
                                            preview={previewFor(placement, sig)}
                                            aspect={aspects[placement.signatureId]}
                                            onSelect={() => {
                                                setActiveRequestId(placement.requestId);
                                                setSelectedStampId(placement.id);
                                            }}
                                            onChange={(rect) => setPlacements((current) => current.map(
                                                (p) => p.id === placement.id
                                                    ? { ...p, ...cssRectToPdfPoints(rect, page, nominal) }
                                                    : p,
                                            ))}
                                            onRemove={() => removePlacement(placement.id, placement.requestId)}
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
                {/*
                    Says which slot the next drop lands on, and how many are
                    left. A document where you hold one slot accepts one
                    signature, and saying so is the difference between a rule
                    and a surface that looks broken on the second drag.
                */}
                <span className="dsig-viewer__trayhint">
                    {requests.length > 1
                        ? `${slotsPlaced} of ${requests.length} slots placed. `
                        : ''}
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
                    {' Drag again to sign in another place on the same document.'}
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

            {/*
                What follows the cursor mid-drag — composed, not just the ink,
                so the shape being carried is the shape that will land.
            */}
            {dragging && (
                <div
                    className="dsig-viewer__ghost"
                    style={{
                        left:   `${dragging.x}px`,
                        top:    `${dragging.y}px`,
                        width:  `${GHOST_WIDTH}px`,
                        height: `${GHOST_WIDTH / (aspects[dragging.sig.id] ?? 3.2) + GHOST_CAPTION}px`,
                    }}
                >
                    <StampPreview
                        imageUrl={dragging.sig.previewUrl}
                        {...ghostPreview(dragging.sig, aspects[dragging.sig.id], meta?.stamp)}
                    />
                </div>
            )}
        </div>
    );
}

// ── Helpers ──────────────────────────────────────────────────────────────────

// The ghost is not a placement yet, so it has no box to be laid out against.
// These give it a plausible one at a readable size.
const GHOST_WIDTH = 150;
const GHOST_CAPTION = 26;

/**
 * Lay the dragged signature out as if it had already been dropped, so the
 * thing under the cursor is the thing that lands rather than a bare image
 * that then rearranges itself on release.
 */
function ghostPreview(signature, aspect, rules) {
    const heightPt = GHOST_WIDTH / (aspect ?? 3.2) + GHOST_CAPTION;

    return scalePreview(
        layoutStamp(
            { width: GHOST_WIDTH, height: heightPt },
            signature.caption ?? [],
            rules,
            undefined,
            aspect,
        ),
        1,
        rules,
    );
}

/**
 * A point-space layout, scaled into the CSS pixels the preview draws in.
 *
 * One converter for the box and the drag ghost, so the thing under the cursor
 * and the thing that lands cannot be laid out by two different rules.
 */
function scalePreview(layout, zoom, rules) {
    return {
        image: {
            x:      layout.image.x      * zoom,
            y:      layout.image.y      * zoom,
            width:  layout.image.width  * zoom,
            height: layout.image.height * zoom,
        },
        qr: layout.qr
            ? { x: layout.qr.x * zoom, y: layout.qr.y * zoom, size: layout.qr.size * zoom }
            : null,
        caption: {
            lines:        layout.caption.lines,
            x:            layout.caption.x * zoom,
            y:            layout.caption.y * zoom,
            w:            (layout.caption.w ?? 0) * zoom,
            sizePx:       layout.caption.size * zoom,
            lineHeightPx: layout.caption.size * zoom * (rules?.caption?.lineHeight ?? 1.06),
            align:        layout.caption.align,
        },
    };
}

/**
 * Identity for one stamp on the page.
 *
 * A slot can carry several, so the request id cannot be the key — two stamps
 * of the same signature would collide and React would reuse one box for both.
 */
let stampSequence = 0;
function nextStampId() {
    stampSequence += 1;
    return `stamp-${stampSequence}`;
}

function stampCount(placements, requestId) {
    return placements.filter((p) => p.requestId === requestId).length;
}

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
