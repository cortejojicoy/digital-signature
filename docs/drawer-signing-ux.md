# Drawer Signing UX

Signing happens in the launcher drawer. Your users open it from the floating button on any panel page, read the document, drag their signature onto it, and sign. They never leave the page they were on.

This page covers what's in the drawer and how to configure it. For every launcher setting, see [Configuration: launcher](configuration.md#launcher).

---

## What's in the drawer

The drawer has four tabs and a **Manage signatures** link in the footer.

| Tab | What it shows |
|---|---|
| **Awaiting** | Documents waiting on the signed-in user, with **View & sign** and **Decline** on each. The tab shows a count. |
| **Signed** | Documents this user has signed, newest first, grouped by day. Capped at 50. |
| **My signatures** | The user's active signatures, plus a form to draw a new one with a certificate password. |
| **Devices** | This user's signing devices (browsers and paired computers). |

The queue and the signed history come from `ActsOnSignatureRequests`, the same trait the full-page inbox uses. So the drawer and the page always agree.

Nothing loads until the drawer is first opened. Only the badge count renders with the page.

---

## Configuring it

The settings you'll most likely touch:

| Key | Env var | Default | What it does |
|---|---|---|---|
| `signature.launcher.width` | `SIGNATURE_LAUNCHER_WIDTH` | `64rem` | Drawer width on desktop. |
| `signature.launcher.manage_width` | `SIGNATURE_LAUNCHER_MANAGE_WIDTH` | `80rem` | Drawer width while **Manage signatures** is open. |
| `signature.launcher.replaces_navigation` | `SIGNATURE_LAUNCHER_REPLACES_NAV` | `true` | Hides the inbox and Signatures sidebar items. Both stay routable. |

```env
SIGNATURE_LAUNCHER_WIDTH=72rem
SIGNATURE_LAUNCHER_MANAGE_WIDTH=90rem
```

Things to know:

- Widths accept any plain CSS length (`px`, `rem`, `em`, `vh`, `vw`, `%`). Anything else falls back to the default.
- The drawer never gets wider than the viewport. Below 640px it goes full width.
- Keep the default width roomy. The drawer holds a rendered PDF page, not just a list.

---

## Reading and signing a document

Click **View & sign** on a queued document. The drawer body turns into a document pane.

The button is disabled when the user has no signature yet, or when an earlier signatory in a sequential session still has to sign. If they have no signature, the queue tells them and links to **My signatures**.

### Placing the signature

1. Drag a signature from the tray at the bottom onto the page. It lands centred where you drop it.
2. Drag the box to move it. Drag the bottom-right corner to resize.
3. Click **Sign here** (or **Sign N places** if you placed more than one).

Some details:

- Resizing keeps the signature's shape. Hold Shift to distort it.
- If the slot has a size set in the designer, a dropped box starts at that size. Otherwise it's sized so the ink keeps its natural shape.
- Use the + and − buttons to zoom (40% to 300%). Placements stay put when you zoom.
- Clicking a signature chip without dragging just selects it.

### Without a mouse

Dragging isn't required. Click **place it with the keyboard** in the tray.

That puts the box at the slot's saved position, or in the centre of page 1. Then use the arrow keys to nudge it: 1px per press, 10px with Shift.

### What the box shows

The box previews the whole stamp, not just the ink:

- the signature image
- a QR square (a placeholder in the preview)
- a caption: signer, time and a reference

The caption sits on the side set by `signature.caption.position` (`bottom`, `top`, `left` or `right`, default `bottom`). There's no per-stamp control in the drawer, so set the default to suit your forms.

The browser can't measure fonts exactly like TCPDF does. So a caption line might truncate in one and not the other. The ink placement still matches.

### Several slots or several places

If the user is more than one signatory on the same document (say "Prepared by" and "Noted by"), the pane shows a chip for each slot.

- Each drop fills the selected slot, then moves on to the next empty one.
- A slot that's waiting on an earlier signatory is disabled.
- All slots are signed in one go. They're applied in sequence order, not the order you placed them.

Dropping again on a slot that's already placed adds another appearance instead of moving the first. The chip shows `✓×3`, for example.

Extra appearances are still one signature. You get one `Signature` record, one PKCS#7 block and several `signature_positions` rows.

### After signing

The pane closes and the queue shows "Signed. The document has moved to your Signed tab." with a link. The user stays on the queue so they can carry on with the next document.

If a batch fails partway, the pane says **Partly signed** and lists what was signed and what failed. Signed slots stay signed.

---

## Viewing signed documents

On the **Signed** tab, **View document** opens the same pane in read-only mode.

You get zoom and the document, but no tray or Sign button. The header shows the state and when it was settled. The server refuses to re-sign a settled slot regardless.

---

## Managing signatures

**Manage signatures** replaces the old View Signature page. It widens the drawer to `manage_width` and shows your signatures next to the selected one's details.

The list has every row you own, newest first (up to 100): your reusable signature plus each document you've signed with it. It's the same rows as the Signatures resource table. Filter chips only appear for statuses that are actually in the list.

The detail pane shows the image, signer, status, capture method, device and dates. For the reusable signature it also shows **Used on** (the documents it's been applied to). The uuid, image hash and fingerprints sit in a collapsed **Security metadata** block.

You can:

- filter the list by status
- download the signature image
- revoke a signature (with a confirm step)
- copy metadata values
- view each registered PDF template under **Apply this signature**

Templates only show for the reusable (primary) signature. Each card shows a **Ready** or **Setup** badge and how many of its slots are placed (say `2/3 slots`).

Click a card to open the template's sample PDF right in the drawer. It's view-only: nothing can be placed or signed, and **← Manage signatures** takes you back to the list. The cards don't link to the signer or designer pages. Those pages still exist at their own URLs (see [PDF Templates](pdf-templates.md#the-placement-designer)).

After you revoke your active signature, the draw form comes back on **My signatures**.

You only ever see your own signatures. Every read and action matches on the uuid and the signed-in user, so passing someone else's uuid just selects nothing.

Ways in:

- the footer link in the drawer
- clicking a thumbnail on **My signatures**
- a `?dsig=manage` or `?dsig=manage:{uuid}` query string on any panel page

Old `/signatures/{record}` links redirect to the list with the drawer open on that signature. Someone else's signature returns a 404.

To leave manage mode, use the back arrow in the drawer header or press Escape. Press Escape again to close the drawer. Below 640px the list and the details show one at a time.

The Signatures list page still works without a View page. Its **View** row action opens the details in a slide-over, and there's a **Download** row action next to **Revoke**.

### Adding a signature

The **My signatures** tab has the same draw pad as the resource page's **Add Signature** action. Both run through `RegistersSignatures`, so they create the same record and certificate.

If the user already has an active signature, the form is replaced by a note telling them to revoke it in **Manage signatures** first.

---

## Endpoints

The document pane talks to these routes. They use `web` middleware only. The request routes check that the request belongs to the signed-in user; the preview routes only need someone signed in, since the sample holds no real data.

| Route | Name | What it does |
|---|---|---|
| `GET /signature/requests/{id}/meta` | `signature.request.meta` | Pages, slots, the user's signatures, caption lines and `readOnly`. |
| `GET /signature/requests/{id}/document` | `signature.request.document` | Streams the session's PDF from the signature disk. |
| `POST /signature/requests/{id}/sign` | `signature.request.sign` | Takes a `placements[]` array and signs. |
| `GET /signature/pdf-templates/{key}/preview/meta` | `signature.pdf-templates.preview.meta` | A template preview: same shape, with `readOnly: true` and no requests or signatures. |
| `GET /signature/pdf-templates/{key}/preview/document` | `signature.pdf-templates.preview.document` | Streams the template's sample PDF. Signed-in users only. |

- Someone else's request id gets a 403, not a 404.
- Picking a signature you don't own is a 403. A revoked one is a 422.
- A failed batch returns 422 with `status: "partial"` (some signed) or `"failed"` (none signed).

The QR on the stamp points to `GET /signature/verify/{uuid}` (`signature.verify`). That page is public on purpose, so whoever holds the printed copy can check it.

---

## The PDF viewer assets

The viewer uses pdf.js (`pdfjs-dist` 6.x). It ships as its own bundle and loads only when a document is opened.

| File | Asset id |
|---|---|
| `digital-signature-pdf-viewer.js` | `signature-pdf-viewer` |
| `digital-signature-pdf.worker.js` | `signature-pdf-worker` |

Both are registered as on-request Filament assets. Run `php artisan filament:assets` to publish them.

The worker is served from your app, not a CDN, so the viewer works behind a firewall. If the plugin isn't registered on a panel, the URLs fall back to `vendor/digital-signature/`.

You don't need Imagick or Ghostscript to read documents. pdf.js renders them in the browser.

---

## Gotchas

- **The full-page inbox still has a one-click Sign button.** It signs without showing the document. If you want everyone to read before signing, keep `replaces_navigation` on so the page stays out of the sidebar and users sign from the drawer.
- **Drawer styles are plain namespaced CSS (`.dsig-*`).** It doesn't use your panel's Tailwind build, so it looks the same on Filament v3, v4 and v5.
- **Hiding the resource or inbox is safe.** With `->withoutResource()` or `->withoutInbox()`, the links that would point there just don't render.
