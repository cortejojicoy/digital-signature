# Manage Signatures in the Drawer — Requirements & Plan

**Status:** implemented. See [What was built](#what-was-built) for where each
part landed and where the build differs from this plan.
**Scope:** remove the View Signature page and move everything it does into the
launcher drawer. "Manage signatures" expands the drawer in place instead of
navigating away.

Follows on from [drawer-signing-ux-plan.md](drawer-signing-ux-plan.md), which
already moved the queue, the library and devices into the drawer. The last
surface that still pulls the user off the page is the Signatures resource:
the drawer footer's **Manage signatures** link goes to
`SignatureResource::getUrl()`, and from there each row's **View** action opens
a full page.

---

## Table of contents

| # | Requirement | Touches |
|---|---|---|
| [1](#1--remove-the-view-signature-page) | Remove the View Signature page | `ViewSignature` (V3/V4), resolver, resource `getPages()` |
| [2](#2--manage-signatures-expands-the-drawer) | "Manage signatures" expands the drawer instead of redirecting | Launcher blade + Alpine state, `LauncherSettings` |
| [3](#3--feature-parity-inside-the-drawer) | Every View-page feature works in the drawer | `SignatureLauncher`, new partial(s) |
| [4](#4--what-happens-to-the-list-page) | List page keeps working without a View page | `SignatureResource` table actions |
| [5](#5--tests) | Tests | `SignatureLauncherTest`, new tests |

---

## What exists now

### The View page

| Piece | File |
|---|---|
| Behaviour (header actions) | [IsViewSignature.php](../src/Filament/Resources/SignatureResource/Pages/Concerns/IsViewSignature.php) |
| V3 / V4 shells (only `$view` differs) | [V3/ViewSignature.php](../src/Filament/Resources/SignatureResource/Pages/V3/ViewSignature.php), [V4/ViewSignature.php](../src/Filament/Resources/SignatureResource/Pages/V4/ViewSignature.php) |
| Version resolver | [ViewSignatureResolver.php](../src/Filament/Resources/SignatureResource/ViewSignatureResolver.php), registered in [SignatureServiceProvider.php:64](../src/SignatureServiceProvider.php#L64) |
| Route | `'view' => Pages\ViewSignature::route('/{record}')` in both resources ([V3:344](../src/Filament/Resources/V3/SignatureResource.php#L344), [V4:342](../src/Filament/Resources/V4/SignatureResource.php#L342)) |
| Body | [view-signature-with-templates.blade.php](../resources/views/filament/pages/view-signature-with-templates.blade.php) |
| Infolist | `SignatureResource::infolist()` — defined, but the custom view replaces the default infolist render, so it is **not shown** on the page today |

### Features on the View page

| # | Feature | Where it comes from |
|---|---|---|
| F1 | **Sign Document** header action | `SignDocumentAction` in `IsViewSignature` |
| F2 | **Download Image** (temporary URL, new tab) | `IsViewSignature` |
| F3 | **Revoke Signature** (with confirmation) | `IsViewSignature` → `SignatureManager::revoke()` |
| F4 | **Apply this signature**: one card per registered `PdfTemplate` with preview, Ready/Setup badge, `saved/total` slot count | view blade, `PdfTemplateRegistry`, `PdfTemplateSlot` |
| F5 | Card → **Sign document** (`PdfTemplateSigner` page) | view blade |
| F6 | Card menu → **Open designer** (`PdfTemplateDesigner` page) | view blade |
| F7 | Signature details: image, signer, status, capture method, device, dates, "Used on", security metadata | `SignatureResource::infolist()` (defined, not rendered) |

> **F1 is broken today.** On this page the action's `$record` is the
> `Signature`, not a `Signable`, so
> [SignsDocuments::handleSigning()](../src/Filament/Actions/Concerns/SignsDocuments.php)
> always ends at *"No document selected"*. Signing a document already happens
> in the drawer's queue (placement on the PDF) and through F5. **Proposal: do
> not port F1.** F5 covers "sign something with this signature".

### The drawer

- [SignatureLauncher.php](../src/Filament/Livewire/SignatureLauncher.php) is a plain Livewire
  `Component` (no `HasActions` / `HasForms`). Tabs: `queue`, `signed`,
  `library`, `devices`. A `viewing` state swaps the body for the PDF pane.
- The library tab shows up to 12 **active** primary signatures as thumbnails
  that do nothing when clicked, plus the "Add a signature" form.
- Footer: `<a href="{{ $this->libraryUrl }}">Manage signatures</a>`
  ([signature-launcher.blade.php:988-992](../resources/views/filament/livewire/signature-launcher.blade.php#L988-L992)).
- Width: `--dsig-w`, from `signature.launcher.width` (default `64rem`).

---

## 1 — Remove the View Signature page

### Delete

- `src/Filament/Resources/SignatureResource/Pages/Concerns/IsViewSignature.php`
- `src/Filament/Resources/SignatureResource/Pages/V3/ViewSignature.php`
- `src/Filament/Resources/SignatureResource/Pages/V4/ViewSignature.php`
- `src/Filament/Resources/SignatureResource/ViewSignatureResolver.php`
- `resources/views/filament/pages/view-signature-with-templates.blade.php`
  (its template-card markup moves into a drawer partial, see [§3](#3--feature-parity-inside-the-drawer))

### Edit

- `SignatureServiceProvider` — drop `ViewSignatureResolver::class` from the
  resolver list and its `use`.
- Both `SignatureResource::getPages()` — drop the `'view'` entry.
- Check `docs/pdf-templates.md` and the other docs for links to
  `/signatures/{record}` and update them.

### Compatibility

- `/admin/signatures/{uuid}` URLs that are bookmarked or sent in notifications
  will return 404. **Decision needed:** accept that, or keep a small redirect
  route that opens the panel with the drawer already in manage mode on that
  signature (`?dsig=manage:{uuid}`, see [§2](#2--manage-signatures-expands-the-drawer)).
  Recommendation: add the redirect. It costs very little and keeps old links
  working.
- Any host app that extended `ViewSignature` will break. Note it in the
  changelog as a breaking change.

---

## 2 — "Manage signatures" expands the drawer

### Behaviour

1. Clicking **Manage signatures** in the footer does **not** navigate. It sets
   `mode = 'manage'` in the drawer's Alpine state.
2. In manage mode the drawer **widens** (an animated `width` transition on
   `.dsig-panel`) from `--dsig-w` to `--dsig-w-manage`, and the body switches
   to a two-pane layout:
   - **Left (list):** all of the user's signatures (active, revoked, pending),
     newest first, with a thumbnail, status badge, method and date. Status
     filter chips (All / Active / Revoked) replace the table's `SelectFilter`.
   - **Right (detail):** the selected signature with every feature from
     [§3](#3--feature-parity-inside-the-drawer).
3. A **← Back** control in the header (and `Esc` once) leaves manage mode and
   shrinks the drawer back. `Esc` again closes the drawer, as it does now.
4. Thumbnails in the **library tab** become buttons that open manage mode with
   that signature already selected, so "manage this signature" is one click.
5. Under 640px the drawer is already `100vw`. There, manage mode shows the
   list and the detail one at a time (list → tap → detail, with Back).

### State (Alpine, `dsigLauncher`)

```js
mode: 'tabs',         // 'tabs' | 'manage'
managing: null,       // selected signature uuid, or null for list-only
```

- Tabs, the tab bar and the footer are hidden while `mode === 'manage'`, the
  same way they are hidden while `viewing`.
- `viewing` (PDF pane) and `mode === 'manage'` cannot both be on. Entering one
  clears the other.
- Optional deep link: `?dsig=manage` / `?dsig=manage:{uuid}` read in `init()`
  opens the drawer straight into manage mode (used by the redirect in §1).

### Settings

- New `signature.launcher.manage_width` (env `SIGNATURE_LAUNCHER_MANAGE_WIDTH`,
  default `80rem`), exposed through `LauncherSettings::manageWidth()` using the
  same `cssLength()` guard as `width()`, and passed to the blade as
  `--dsig-w-manage`. CSS still caps it at `100vw`.

### Footer link

- Replace the `<a href>` with a `<button x-on:click="manage()">`.
- `getLibraryUrlProperty()` is no longer needed for the footer. The link no
  longer depends on the resource being registered, so it shows even when
  the host uses `->withoutResource()`.

---

## 3 — Feature parity inside the drawer

All server-side work stays in `SignatureLauncher` (or a trait), with the same
ownership scoping as `SignatureResource::getEloquentQuery()`: **only the
current user's signatures, never overridable**. Every action re-loads the
signature by `uuid` **and** `user_id`. It never trusts an id sent from the
browser.

| # | Feature | Drawer implementation |
|---|---|---|
| F2 | Download Image | Link to `$signature->getTemporaryImageUrl(60)`, rendered server-side for the selected signature, `target="_blank"`. Hidden when there is no `image_path`. |
| F3 | Revoke | `revokeSignature(string $uuid)` Livewire method → `SignatureManager::revoke()`, then a Filament `Notification`. Confirmation is an inline confirm step in the detail pane (*"Revoke permanently? This cannot be undone." [Cancel] [Revoke]*), because the launcher has no Filament action modals. Hidden when the signature is already revoked. After revoking, `canRegisterSignature()` turns true again, so the library tab's Add form comes back by itself. |
| F4 | Apply-this-signature cards | Move the `$cards` building out of the blade into a method (`templateCardsFor(Signature)`), so it is testable and not run in Blade. Render the same cards in a 2-column grid in the detail pane. Only built for the **selected** signature, and only once manage mode is opened (same lazy-load rule as `loadRequests()`). |
| F5 | Card → Sign document | Same `PdfTemplateSigner::getUrl()` link (still a full page; the template signer is out of scope here). Hidden for revoked signatures. |
| F6 | Card → Open designer | Same `PdfTemplateDesigner::getUrl()` link in the card's ⋮ menu. |
| F7 | Details | Rendered as plain drawer markup (not an infolist, since the launcher is not a Filament schema host): image on white, signer name/email, status badge, capture method, device summary (`deviceSummary()`), signed/registered dates, **Used on** (`documentUseSummaries()`, primary signatures only) and a collapsed **Security metadata** block (uuid, image hash, fingerprint, device key, certificate fingerprint) with copy-to-clipboard buttons. |
| F1 | Sign Document | **Not ported**: broken today (see above), and covered by the queue and F5. |

### New Livewire surface on `SignatureLauncher`

```php
public ?string $managing = null;            // entangled with Alpine `managing`

public function manage(?string $uuid = null): void;      // marks manage data loaded, selects
public function revokeSignature(string $uuid): void;
public function getManagedSignaturesProperty(): Collection;  // all statuses, owner-scoped
public function getManagedSignatureProperty(): ?Signature;   // selected, owner-scoped
public function templateCardsFor(Signature $signature): array;
```

- `getSignaturesProperty()` (library tab) stays as it is: active only, limit 12.
- The detail pane must not be `wire:ignore`, so it re-renders after a revoke.

### Views

- New partial `resources/views/filament/livewire/partials/manage-signatures.blade.php`
  (list + detail) included from the launcher blade, so the 994-line launcher
  blade doesn't grow further.
- Styling uses the launcher's existing `dsig-*` classes and tokens, not
  Tailwind utilities. The launcher is rendered on every panel page, and the
  host's Tailwind build may not include the classes the View page used.

---

## 4 — What happens to the list page

`ListSignatures` stays routable (hosts with `replaces_navigation = false` still
have it in the sidebar), but its `ViewAction` points at a page that no longer
exists.

**Recommendation:** keep `ViewAction::make()` but make it a **slide-over modal**
(`->slideOver()`). With no `view` page registered, Filament renders the
resource's existing `infolist()` in the modal. Also add **Download** as a row
action, so the list page loses nothing. `Revoke` is already a row action.

Alternative: remove the row's View action entirely and rely on the drawer.

---

## 5 — Tests

- `SignatureLauncherTest`
  - Footer renders a manage button, not an `href` to the resource.
  - `manage()` only lists the current user's signatures (another user's
    signature is never shown, including by passing its uuid directly).
  - `revokeSignature()` revokes your own signature, refuses someone else's
    (no state change), and is a no-op on one that is already revoked.
  - Template cards: counts and Ready/Setup state match `PdfTemplateSlot` rows.
  - Revoked signature: no Sign/Revoke controls, Download still present.
- `PackageBootTest` — the resource registers without a `view` page, and
  `SignatureResource::getUrl('view', …)` is no longer resolvable.
- If the redirect from §1 is added: `/signatures/{uuid}` redirects for the
  owner and returns 404 for anyone else.

---

## Open questions

1. **Old `/signatures/{record}` URLs**: 404, or redirect into the drawer? (Recommended: redirect.)
2. **List page View action**: slide-over infolist, or remove it? (Recommended: slide-over.)
3. **Manage width**: is `80rem` right, or should manage mode go full width?
4. **Template signer/designer** (F5/F6) stay full pages for now. Should they
   also move into the drawer later? (Out of scope here.)

---

## What was built

The open questions were settled with the recommended answers: old URLs
redirect, the list's View is a slide-over, manage width is `80rem`, and the
template signer and designer stay full pages.

| § | Where |
|---|---|
| 1 | View page files deleted; resolver dropped from [SignatureServiceProvider.php](../src/SignatureServiceProvider.php). The `view` route is replaced by `open` → [OpenSignatureInDrawer.php](../src/Filament/Resources/SignatureResource/Pages/OpenSignatureInDrawer.php), which redirects the owner to `?dsig=manage:{uuid}` and returns 404 to anyone else. |
| 2 | Alpine `mode` / `manageFilter`, `manage()`, `leaveManage()`, `escape()` and `openFromLink()` in [signature-launcher.blade.php](../resources/views/filament/livewire/signature-launcher.blade.php). `.dsig-panel--wide` uses `--dsig-w-manage` from [LauncherSettings::manageWidth()](../src/Support/LauncherSettings.php) / `signature.launcher.manage_width`. |
| 3 | [ManagesSignatures.php](../src/Filament/Concerns/ManagesSignatures.php) (used by `SignatureLauncher`) and [partials/manage-signatures.blade.php](../resources/views/filament/livewire/partials/manage-signatures.blade.php). |
| 4 | `ViewAction::make()->slideOver()` plus a **Download** row action in both `SignatureResource` versions. |
| 5 | `managing signatures in the drawer` and the manage-width cases in [SignatureLauncherTest.php](../tests/Feature/SignatureLauncherTest.php); [SignatureResourcePagesTest.php](../tests/Feature/SignatureResourcePagesTest.php). |

### Same UI on Filament v3, v4 and v5

The drawer is plain Livewire with its own `.dsig-*` styles, so it has no
version-specific code, and it renders the same on every major. The resource
is still split into `V3\SignatureResource` and `V4\SignatureResource`, because
Filament's class names differ, but both offer the same infolist, the same
table, and the same row actions (View slide-over, Download, Revoke).

[SignatureResourcePagesTest.php](../tests/Feature/SignatureResourcePagesTest.php)
renders the real list page in a panel, opens View as a slide-over and revokes
from a row. The full suite was run against Filament 3.3, 4.14 and 5.8 (298
tests each, all passing), using the same install steps as the CI matrix in
`.github/workflows/tests.yml`.

### Where the build differs from the plan

- **The list includes document-signing rows**, not only the reusable
  signature. It shows the same rows as the resource's table, so the drawer
  replaces that table rather than showing part of it. The filter chips only
  appear for statuses that are present. Template cards are shown only for the
  reusable signature, since a document-signing row has already been used.
- **Template links are inline** (*Sign document* · *Open designer* under each
  card) instead of a ⋮ menu, which was one click too many in a narrow pane.
- **The redirect page throws a redirect response** from `mount()` instead of
  calling `$this->redirect()`. Livewire applies that redirect only after
  rendering, and this page has no view to render.
- **`getLibraryUrlProperty()` was removed.** The footer button no longer
  depends on the resource being registered.
