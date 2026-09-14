# Drawer-First Signing UX — Requirements & Plan

**Status:** implemented. See [What was built](#what-was-built) for where each
requirement landed, and for the three places the implementation departed from
this plan.
**Scope:** four changes to how a signatory finds, reads, and signs a document.

Today the package has two full-page Filament surfaces (the inbox and the
Signatures resource), a floating launcher whose slide-over duplicates part of
the inbox, and a `Sign` button that stamps a document without the signatory
ever seeing it. This document specifies moving both pages into drawers, adding
an inline PDF viewer to the queue, and replacing the one-click `Sign` with a
drag-and-drop placement gesture.

---

## Table of contents

| # | Requirement | Touches |
|---|---|---|
| [1](#1--awaiting-my-signature-becomes-a-drawer) | "Awaiting my signature" renders in a drawer, not a page | Inbox page, launcher slide-over |
| [2](#2--signatures-becomes-a-drawer) | The Signatures library also renders in a drawer | `SignatureResource`, launcher |
| [3](#3--view-the-pdf-inside-the-queue) | Documents in the queue are viewable inline | New PDF viewer island |
| [4](#4--drag-a-signature-onto-the-pdf) | Drag a stored signature onto the PDF and resize it | `SlotBox`, signing island |

---

## 1 — "Awaiting my signature" becomes a drawer

### What exists now

Two surfaces already render the same queue from the same trait:

- [IsSignatureInbox.php](../src/Filament/Pages/Concerns/IsSignatureInbox.php) — a
  routable Filament page, navigation label `Awaiting my signature`, rendered by
  [signature-inbox.blade.php](../resources/views/filament/pages/signature-inbox.blade.php).
- [signature-launcher.blade.php](../resources/views/filament/livewire/signature-launcher.blade.php) —
  a floating button whose slide-over (`.dsig-panel`) shows the same list.

Both read `ActsOnSignatureRequests::getRequestsProperty()`, so they cannot
disagree about what is pending. The drawer is capped at
`width: min(26rem, 100vw)` ([signature-launcher.blade.php:245](../resources/views/filament/livewire/signature-launcher.blade.php#L245))
and drops to `100vw` under 640px.

### Requested change

The queue should live in the drawer only. The page stops being the primary
surface; **only the drawer width changes**, not its layout, transition,
placement logic, or styling.

### Spec

- Widen `.dsig-panel` from `min(26rem, 100vw)` to a configurable width, default
  wide enough for the PDF viewer in [§3](#3--view-the-pdf-inside-the-queue) —
  suggested default `min(64rem, 100vw)`.
- Expose it as `signature.launcher.width` in
  [config/signature.php](../config/signature.php), alongside the existing
  `position` / `offset` / `z_index` keys, and surface it through
  [LauncherSettings.php](../src/Support/LauncherSettings.php) the way the other
  launcher settings already flow into the blade's `--dsig-*` custom properties.
- Emit it as a CSS custom property (`--dsig-w`) on the wrapper rather than
  hard-coding it in the stylesheet, matching how `--dsig-x`, `--dsig-y` and
  `--dsig-z` are already threaded through.
- Keep the `@media (max-width: 640px)` full-width rule unchanged.
- Keep the page routable. `shouldRegisterNavigation()` already returns `false`
  when `launcher.replaces_navigation` is on; that stays the mechanism, so a host
  that wants the sidebar item back changes one config key.

### Done when

- Opening the launcher shows the queue at the configured width on desktop and
  full-bleed on mobile.
- `SIGNATURE_LAUNCHER_WIDTH` (or the config key) changes the drawer width with
  no other visual change.
- The full-page inbox still resolves at its route and renders as before.

---

## 2 — "Signatures" becomes a drawer

### What exists now

[SignatureResource](../src/Filament/Resources/V4/SignatureResource.php) is a
full Filament resource pinned to the `signatures` slug, with
[ListSignatures](../src/Filament/Resources/SignatureResource/Pages/ListSignatures.php)
providing the `Add Signature` modal (`SignaturePad` + certificate password).
The launcher's footer links out to it.

### Requested change

The signature library should open in a drawer too, so a signatory never leaves
the page they are on to manage signatures.

### Spec

- Add a second drawer section — or a second tab inside the same drawer — for the
  signature library: the user's stored signatures, primary/active state, and the
  `Add Signature` flow.
- Reuse the existing pieces rather than reimplementing: `SignaturePad`
  ([SignaturePad.php](../src/Filament/Fields/SignaturePad.php)) for capture, and
  the `SignatureManager::store()` call already in `ListSignatures`. Factor that
  action body into a shared concern so the drawer and the resource page cannot
  drift — the same reasoning that put sign/decline in `ActsOnSignatureRequests`.
- Respect `PrimarySignatureExistsException` in the drawer path exactly as
  `ListSignatures` does; the race it guards against is not drawer-specific.
- The resource stays routable (its `shouldRegisterNavigation()` already defers to
  `LauncherSettings::replacesNavigation()`).

### Open question

Two drawers or one? A single drawer with `Awaiting` / `My signatures` tabs keeps
one overlay and one z-index story; two drawers keeps each surface simpler. **One
drawer, two tabs** is the recommendation — requirement [§4](#4--drag-a-signature-onto-the-pdf)
needs the signature library visible at the same time as the document.

### Done when

- The launcher opens the signature library without a page navigation.
- Creating a signature from the drawer produces the same record, certificate, and
  notifications as creating it from the resource page.

---

## 3 — View the PDF inside the queue

### What exists now

The queue shows a title, role, sequence, and request timestamp — no document.
The signatory is asked to sign something they cannot see. Page rasters exist
only in the template-signer flow, served by
`PdfTemplateSignerController` as PNGs via
`signature/pdf-templates/{template}/pages/{page}`
([SignatureServiceProvider.php:211](../src/SignatureServiceProvider.php#L211)).

### Source to borrow from

The sibling package `kukux/modern-file-upload` already has a working viewer:

| File | What it gives us |
|---|---|
| `resources/js/components/File/PdfViewer.jsx` | `pdfjs-dist` render loop, per-page `<canvas>`, zoom |
| `resources/js/components/File/index.jsx` | Viewer shell, page-id namespacing (`mfu-viewer-N`) |
| `resources/js/components/File/Toolbar.jsx` | Zoom / download / close controls |
| `resources/js/components/File/ThumbnailNav.jsx` | Page thumbnails |
| `src/Infolists/Components/FileViewer.php` | Disk-aware URL + mime resolution |

### Requested change

Take the **viewable PDF** half only. No upload, no dropzone, no image viewer, no
multi-file gallery.

### Spec

- Add a `PdfViewerIsland` under
  [resources/js/react/](../resources/js/react/), mounted the way
  `PdfSigningIsland` and `PdfDesignerIsland` already mount
  ([index.js](../resources/js/index.js)), rendering `pdfjs-dist` pages to canvas.
- Add `pdfjs-dist` to [package.json](../package.json) and bundle the worker
  locally. Modern-file-upload points `GlobalWorkerOptions.workerSrc` at a CDN;
  this package must not — an admin panel behind a firewall would silently fail
  to render any document. Ship the worker through the package's own asset
  pipeline ([esbuild.config.js](../esbuild.config.js)).
- Keep the per-viewer canvas id namespacing. Two open viewers fighting over
  `page-1` is a real bug that package already hit and fixed.
- Serve the document bytes through an authorization-checked route, not a public
  disk URL. The queue is per-signatory; the PDF behind it must be too. Model it
  on `SignatureAssetController`
  ([SignatureServiceProvider.php:178](../src/SignatureServiceProvider.php#L178)),
  which already serves signature assets by UUID under a signed/authorized route.
- Resolve the document from `SignatureRequest → session → signable`. For
  template-rendered documents with no stored PDF, fall back to the existing page
  rasterizer ([PdfPageRasterizer.php](../src/Pdf/PdfPageRasterizer.php)) rather
  than forcing every host to persist a PDF.
- In the drawer, each queue row gets a **View** control that expands the viewer
  inline (or swaps the drawer into a document pane) — this is what requirement
  [§1](#1--awaiting-my-signature-becomes-a-drawer)'s extra width is for.

### Explicitly out of scope

Upload, drag-drop ingest, image viewer, `react-dropzone`, `react-zoom-pan-pinch`,
and `framer-motion` — unless the drawer transition already needs the last one.

### Done when

- A pending request in the drawer can be read page-by-page without leaving it.
- A user who is not a signatory on that session gets a 403 from the document
  route.
- The viewer works with no outbound network access.

---

## 4 — Drag a signature onto the PDF

### What exists now

Two different signing gestures coexist:

- **Queue:** `wire:click="signRequest({{ $request->id }})"` — one click, the
  signature lands wherever the session or slot dictates, sight unseen
  ([signature-inbox.blade.php](../resources/views/filament/pages/signature-inbox.blade.php)).
- **Template signer:** [PdfSigningIsland.jsx](../resources/js/react/PdfSigningIsland.jsx)
  — click a slot chip to *place* a 200×60 box at canvas center, then drag and
  resize it via [SlotBox.jsx](../resources/js/react/components/SlotBox.jsx),
  then `Finish & Save` converts CSS pixels to PDF points and POSTs.

So drag-and-resize already exists. What is missing is the **drag from the
signature library onto the page**, and that gesture replacing the blind `Sign`
click in the queue.

### Requested change

Instead of clicking `Sign`, the signatory drags their stored signature from the
library strip onto the rendered PDF, positions and resizes it, then commits.

### Spec

**Gesture**

- Make the signature chips in the library strip (`SignatureChip` in
  `PdfSigningIsland.jsx`, and the new drawer library from
  [§2](#2--signatures-becomes-a-drawer)) draggable sources.
- Make the page canvas a drop target. On drop, create the rect at the **drop
  point** rather than canvas center — replacing `placeOnSlot()`'s centering
  behaviour, which exists only because there was no pointer position to use.
- Use Pointer Events for the drag, not HTML5 drag-and-drop. `SlotBox` already
  uses `setPointerCapture` for its own drag/resize; one input model avoids the
  HTML5 drag-image and touch gaps.
- Reuse `SlotBox` unchanged for post-drop manipulation. It already clamps to
  canvas bounds, enforces `minSize`, and has a bottom-right resize handle.

**Resizing**

- Keep the single bottom-right handle — the existing comment in `SlotBox`
  argues an eight-handle rig is more weight than the use case justifies, and
  that reasoning still holds.
- Add aspect-ratio locking (shift-drag, or default-on) so a stretched signature
  cannot be committed. A signature stamped at the wrong aspect is a legal
  artifact, not a cosmetic one.

**Slots vs free placement**

The template flow is slot-bound: a drop must map to a `slot.key` for
`finalize` to accept it. Two cases:

| Case | Behaviour |
|---|---|
| Session defines slots for this signatory | Dropping near a slot snaps to it; dropping elsewhere is rejected with a reason. Slot chips stay as a keyboard/fallback path. |
| No slots (ad-hoc document) | Free placement; the rect is sent as an explicit position, the shape `SignsDocuments::stampAt()` already accepts. |

**Commit path**

- The coordinate conversion in `finishAndSave()` — CSS px → PDF points, y-flip
  against `pageInfo.heightPt`, `round2` — is correct and stays as-is.
- The queue's commit must still go through
  `SigningSessionManager::sign()` so sequence enforcement, session state, and
  `ForgedSignatureException` handling are unchanged. This requirement changes
  *where the stamp goes*, not *who may sign or when* — position becomes an
  argument to the existing path, not a new one.

**Accessibility**

Drag cannot be the only way to sign. Keep a keyboard/click path: select a
signature, select a slot, nudge with arrow keys. The existing slot-chip
interaction is already that path; do not delete it.

### Done when

- A signature can be dragged from the library onto a page, repositioned,
  resized, and committed.
- The committed PDF-point rect matches what was on screen, on a non-Letter page
  size and at browser zoom ≠ 100%.
- Signing still fails correctly for an out-of-sequence signatory, a revoked
  signature, and a closed session.
- The whole flow is completable with keyboard only.

---

## Cross-cutting concerns

**Filament v3/v4/v5.** The drawer is deliberately hand-rolled CSS
(`.dsig-*`, inline, namespaced) because a package cannot rely on the host's
compiled Tailwind — v3 builds with Tailwind 3, v4/v5 with Tailwind 4. Anything
added to the drawer follows that same rule. See the header comment in
[signature-launcher.blade.php](../resources/views/filament/livewire/signature-launcher.blade.php).

**Bundle size.** `pdfjs-dist` is not small. It must load only when a viewer is
actually opened, not on every panel page the launcher renders on.

**Alpine owns open/close, Livewire owns data.** The existing split — toggling
never waits for a round trip, data loads on first open via `loadRequests()` —
must survive. A viewer that forces the queue to hydrate eagerly on every page
would undo it.

**Tests.** The repo has untracked test coverage for the launcher, placement,
routing, and sessions ([tests/Feature/](../tests/Feature/)). Each requirement
above needs matching coverage: drawer width from config, document-route
authorization, and the CSS-px → PDF-point conversion at a non-default page size.

---

## Suggested order

1. **§1** — drawer width. Smallest change, and §3 needs the room.
2. **§3** — PDF viewer. Independent of §2, and it is the change that makes the
   queue honest.
3. **§2** — signature library in the drawer. Needed as the drag source for §4.
4. **§4** — drag-to-place. Depends on both §2 and §3 being in the same surface.


---

## What was built

| § | Landed in |
|---|---|
| 1 | [LauncherSettings::width()](../src/Support/LauncherSettings.php), `signature.launcher.width`, `--dsig-w` in [signature-launcher.blade.php](../resources/views/filament/livewire/signature-launcher.blade.php) |
| 2 | [RegistersSignatures](../src/Filament/Concerns/RegistersSignatures.php), consumed by [ListSignatures](../src/Filament/Resources/SignatureResource/Pages/ListSignatures.php) and [SignatureLauncher](../src/Filament/Livewire/SignatureLauncher.php); `Awaiting` / `My signatures` tabs in the drawer |
| 3 | [SignatureDocumentController](../src/Http/Controllers/SignatureDocumentController.php), [PdfViewerIsland.jsx](../resources/js/react/PdfViewerIsland.jsx), [lazy/pdfViewer.js](../resources/js/lazy/pdfViewer.js), [ViewerAssets](../src/Support/ViewerAssets.php) |
| 4 | Drag sources in [PdfViewerIsland.jsx](../resources/js/react/PdfViewerIsland.jsx) and [PdfSigningIsland.jsx](../resources/js/react/PdfSigningIsland.jsx); aspect lock in [SlotBox.jsx](../resources/js/react/components/SlotBox.jsx); `SigningSessionManager::signAt()` |

### Three departures from the plan

**No rasterizer fallback (§3).** The plan called for falling back to
`PdfPageRasterizer` where a template-rendered document has no stored PDF. It
turned out there is no such case: `SigningSessionManager::open()` freezes every
session's PDF to the signature disk before any request exists, so a queued
request always has bytes to serve. Better still, pdf.js reports page geometry in
PDF points client-side, so the viewer needs no server-side rasterizing at all —
and therefore no Imagick or Ghostscript on a host that only wants to *read*
documents. The rasterizer stays where it is genuinely needed: the template
designer, which has no PDF until it renders one.

**pdf.js pinned to 6.x, not 5.x (§3).** The version `modern-file-upload` uses
falls inside the range of
[GHSA-hq66-cqwq-w95j](https://github.com/advisories/GHSA-hq66-cqwq-w95j) —
arbitrary JavaScript execution from a malicious PDF. A signing queue is
precisely a place where other people's PDFs arrive, so the dependency is pinned
to the patched major and `getDocument` is called with `isEvalSupported: false`.

**The viewer ships as a second bundle.** The plan asked that pdf.js load only
when a viewer opens. esbuild's IIFE output cannot code-split, so
`digital-signature-pdf-viewer.js` is built separately and injected by
[lazy/pdfViewer.js](../resources/js/lazy/pdfViewer.js) on first mount. The main
bundle is unchanged in size. The worker is copied verbatim rather than bundled,
because pdf.js always instantiates it with `{ type: 'module' }`.

### Added after the first pass: several slots at once

The same person is routinely two signatories on one form — "Prepared by" and
"Noted by" on an accomplishment report. The first implementation opened one
request per viewer, so that meant opening the same document twice.

- `meta` now returns **every** outstanding slot this signatory holds *in that
  session*, not just the one in the URL. Scoped to the session because a
  request from another document has nowhere to land on this page.
- `sign` accepts a `placements[]` array. Each entry is re-resolved through the
  same ownership query as the opened request and pinned to the same session, so
  holding one request id is never a licence to sign a second.
- Placements are applied in **sequence order, not payload order**: each
  signature is chained to the one before it and applied to the session's
  running document, which is the existing multi-signatory mechanism rather than
  a new one. `PdfSignerService` still stamps one image per call; nothing about
  the cryptographic pipeline changed.
- A batch that fails partway returns `status: "partial"` with what was signed
  and what it failed on. There is no honest way to un-sign a PDF somebody
  already put a certificate on, so the response says how far it got rather than
  implying the whole batch was rejected.
- `blocked` no longer counts the signatory's *own* earlier slot as a blocker —
  they can clear it themselves in the same batch. `assertInSequence()` still
  enforces ordering for real at signing time.

### Two bugs fixed in the drag surface

**Zoom moved the signature.** Placements were held in CSS pixels, so zooming
re-rendered the page at a new scale while the box stayed put — committing the
signature somewhere the signatory had not placed it. Placements are now stored
in PDF points and converted to CSS per render. `tests/js/pdfCoords.test.mjs`
covers the invariant directly.

**Removing a box sprang it back.** The seeding effect depended on the placement
state it was seeding, so clearing a placement immediately re-seeded it from the
frozen slot. Seeding now happens once, guarded by a ref.

### Added after the first pass: signed documents stay readable

Signing removed a document from the queue and offered nothing in its place, so
from the signatory's side it looked thrown away.

- A third drawer tab, **Signed**, lists what this user has signed, newest
  first, **grouped by the day they signed it**. Signing happens in bursts and
  the date is what people actually remember, so the grouping is what turns a
  list into a record. Day headings are sticky, since the heading is the first
  thing to scroll away.
- The query lives in `ActsOnSignatureRequests` alongside the queue, so the
  drawer and the full-page inbox cannot disagree about what this user signed.
  It is capped at 50: a drawer is not the place to page through a career.
- **The same document pane serves as the reader.** `meta` reports `readOnly`
  for a slot in a terminal state, and everything that places or commits a
  signature is simply not rendered. The flag is advisory — `applySignature()`
  refuses an already-signed slot whatever the client believes, and there is a
  test for exactly that.
- Signing now leaves a line on the queue saying where the document went, with
  a link to the Signed tab. The user stays on the queue so they can carry on
  with the next document rather than being navigated away mid-flow.

### Added after the first pass: a readable caption on the stamp

Everything binding a signature to its signer was invisible — HMAC-signed tEXt
and XMP chunks inside the PNG, a PKCS#7 block in the PDF, a QR alongside. None
of it survives being printed and handed across a desk.

The stamp now carries two or three lines of small type: signer, when, and a
short reference to quote. `signature.caption` controls which fields appear, the
font range, and whether it appears at all.

The rule that shapes the implementation: **the caption never grows the stamp.**
That rectangle is where a signatory dropped their signature, sized to the line
it belongs on, and anything below it belongs to the form. So the caption is
carved out of the bottom of the placement and the image shrinks into what is
left — and below `min_box_height` the caption stands down entirely, because a
signature squeezed into nothing is worse than one with no caption. Lines that
do not fit are dropped from the bottom; a line too wide is truncated with an
ellipsis rather than allowed to spill.

Layout lives in one trait, `DrawsSignatureCaption`, shared by both PDF drivers
so they cannot disagree about where the text goes. `PdfSignerDriver::sign()`
gained a `$caption` parameter with an empty default, so a host's own driver
keeps working untouched.

### One signature, several appearances

A form routinely asks the same person for the same signature more than once —
the signature block, again under a certificate, again on an acceptance clause.
The template declares one slot for that person, so the surface allowed one
stamp, and there was no way to sign the other two places.

That is one act of signing with several appearances, not several signatures.
So: **one `Signature` row, one PKCS#7 block covering the whole document, one
link in the chain, and N `signature_positions` rows** saying where it is drawn.
The cryptography does not count stamps.

- `signature_positions` already had no unique constraint on `signature_id`, so
  no migration was needed — only the `hasOne` relation and the drivers assumed
  one. `Signature::positions()` is the honest shape; `position()` stays for
  every existing caller.
- Both drivers loop over the placements. The QR is drawn beside the **first**
  only, because a barcode against every stamp is noise rather than provenance;
  the caption is drawn under **every** one, because an unattributed mark
  further down the document is exactly what the caption exists to prevent.
- The endpoint no longer rejects a repeated slot. The first placement is the
  slot's own frozen coordinates; the rest become extra stamps. The response
  reports `stamps` per slot.
- In the drawer, dropping again **adds** an appearance instead of moving the
  last one. The slot chip shows `✓×3`, and the counter still reads "1 of 2
  slots placed" — a slot signed three times is one slot accounted for.

### Fixed: only one signature could be dragged

The drop always targeted `activeRequestId`, and nothing ever advanced it — so a
second drag silently re-placed the first slot instead of the next one, and the
surface looked like it accepted one signature per document however many slots
you held. A drop now moves the selection to the next unplaced slot. Where you
genuinely hold one slot, the tray says so rather than leaving the second drag
looking broken.

### The QR now verifies something

The QR encoded four labelled lines of text including `Verify: {app}/signatures/
{uuid}` — a route this package never registered. Scanning it produced a wall of
text and a dead link.

- `GET /signature/verify/{uuid}` is now a real page, and the QR encodes that URL
  bare, so a phone camera offers to open it.
- **Public and unauthenticated on purpose.** It is scanned by whoever is holding
  the paper — an auditor, a receiving office — and requiring an account would
  make it useless to exactly those people.
- It discloses only what the page in their hand already shows: signer, role,
  when, and whether the signature still stands. Not the document, not the file,
  not the signer's email, not the other signatories.
- A revoked reference and an unknown one return the same shape, so the endpoint
  cannot be used to probe whether a reference ever existed. Tested directly.
- The QR moved **inside** the placement, taking a square off the right — it was
  drawn beside the box, which put it wherever the form happened to have content.
  It stands down below a size no phone will decode, rather than printing a
  barcode that cannot be scanned.

Layout for image, QR and caption now lives in one `drawStamp()` in
`DrawsSignatureStamp`, called once by each driver. Both had been doing this
arithmetic separately.

### Still outstanding

The **full-page inbox still has the blind `Sign` button**. §1 says the page
should render "as before", and §4 says the drag gesture replaces the blind click
"in the queue" — the drawer *is* the queue now, so both were satisfied
literally, but the page remains a route where a signatory can sign a document
they have not seen. Closing that means either giving the page the same viewer or
redirecting it into the drawer; neither is in this plan's scope.

### Tests

- `tests/Feature/SignatureDocumentEndpointTest.php` — authorization on all
  three endpoints, running-document semantics, placement round-trip, and that a
  refused signature leaves the next signatory's slot unmoved.
- `tests/Feature/SignatureLauncherTest.php` — drawer width from config, the
  tabs, drawer-side signature registration, that no blind `Sign` survives, and
  that signed history is grouped by day and never crosses between signatories.
- `tests/Unit/Drivers/SignatureStampTest.php` — caption and QR layout against a
  real TCPDF: fitting, dropping, truncating, standing down on a short box.
- `tests/Feature/SignatureVerificationTest.php` — what the public page says,
  and what it refuses to say.
- `tests/Unit/PdfSignerServiceTest.php` — that the provenance reaches the driver.
- `tests/js/pdfCoords.test.mjs` — the CSS-px ⇄ PDF-point conversion on A4,
  landscape, at render scales either side of 1:1, and the zoom invariance that
  the CSS-pixel bug violated.
