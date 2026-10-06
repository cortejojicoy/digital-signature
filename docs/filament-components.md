# Filament Components

---

## SignaturePlugin — panel plugin

Add the plugin to every panel that should use the package. It registers the Signatures resource, the inbox page and the floating launcher for you, so don't discover the resource from `vendor` yourself.

```php
// app/Providers/Filament/AdminPanelProvider.php

use Kukux\DigitalSignature\SignaturePlugin;

->plugins([
    SignaturePlugin::make(),
])
```

### Fluent options

```php
SignaturePlugin::make()
    ->navigationIcon('heroicon-o-pencil-square')   // default: heroicon-o-pencil-square
    ->navigationGroup('Documents')                  // default: none
    ->navigationSort(10)                            // default: none
    ->navigationLabel('Document Signatures')        // default: "Signatures"
```

These override the `signature.resource.*` config values.

### Turning parts off

| You want to... | Use |
|---|---|
| Hide the Signatures resource (bring your own) | `->withoutResource()` or `SIGNATURE_RESOURCE_ENABLED=false` |
| Hide the inbox page on this panel | `->withoutInbox()` |
| Turn off the floating launcher on this panel | `->withoutFloatingLauncher()` |
| Turn the launcher on conditionally | `->withFloatingLauncher($panel->getId() === 'staff')` |

---

## Floating launcher

The plugin adds a floating button to every page of the panel (via the `PanelsRenderHook::BODY_END` render hook). It's on by default, so you don't need to register anything.

Clicking it opens a drawer with these tabs:

| Tab | What's in it |
|---|---|
| **Awaiting** | Documents waiting on the signed-in user, each with **View & sign** and **Decline** |
| **Signed** | Documents the user has already signed |
| **My signatures** | The user's signature library, plus a form to add one |
| **Devices** | Browsers and paired computers that can sign as this user |

The drawer footer has a **Manage signatures** link. See [Managing a signature](#managing-a-signature) below.

### It replaces the sidebar items

While the launcher is on, the inbox page and the Signatures resource drop out of the navigation. Both stay routable.

- Want both the launcher and the sidebar items? Set `signature.launcher.replaces_navigation` to `false`.
- Turn the launcher off with `->withoutFloatingLauncher()` and the sidebar items come back automatically.

### Position and look

Icon, label, colour, badge polling and drawer width are config. Position and offsets in config are the default: each user can move their own button from the drawer's **Settings** tab (turn that off with `launcher.customizable => false`). See [Configuration](configuration.md#launcher).

The Signed tab shows recent signatures. **All documents I've signed** widens the drawer into the full record, with a title filter and **Show more**, the same way **Manage signatures** does. There is no separate page.

The button automatically moves out of the way of other things pinned in the same corner (your own FAB, a chat widget, a cookie bar). If it guesses wrong, use `launcher.avoid` / `launcher.ignore` to override specific selectors, or set `avoid_overlap => false` and place it with `launcher.offset`. See [Not landing on the host app's own floating button](configuration.md#not-landing-on-the-host-apps-own-floating-button).

### Mounting it yourself

Outside a panel, or in a custom layout:

```blade
<livewire:kukux-digital-signature.launcher />
```

---

## SignatureResource — admin resource

The plugin registers this for you. It has a list page and a create page. There is no View page anymore.

### List page

- Columns: signature thumbnail, signer name and email, status badge, capture method, signed and created dates
- Filters: status and capture method
- Row actions: **View** (details in a slide-over), **Download**, **Revoke**
- Header actions: **Signing devices** (when `signature.devices.enabled` is on) and **Add Signature**

### Managing a signature

You manage signatures in the launcher drawer. Click **Manage signatures** in the drawer footer, or click a thumbnail in the **My signatures** tab.

The drawer widens and shows your signatures next to the selected one's details:

- The image, **Download image** and **Revoke** (with an inline confirmation)
- Signer, status, capture method, device, signed and registered dates
- **Used on**: the documents this signature has been applied to
- **Apply this signature**: one card per registered PDF template. Clicking it opens the template's sample PDF in the drawer, view-only
- **Security metadata** (collapsible): record id, image hash, device fingerprint, device key, certificate fingerprint, each copyable

Old `/signatures/{record}` links still work. They redirect to the list with the drawer open on that signature (`?dsig=manage:{uuid}`). Anyone who doesn't own the signature gets a 404.

### Using your own resource instead

```php
SignaturePlugin::make()->withoutResource()
```

Then add `SignDocumentAction` and `SignatureColumn` (below) to your own resource.

---

## SignaturePad — form field

A signature capture field for any Filament form. It has a **draw** tab (canvas) and an **upload** tab, and works in dark mode.

```php
use Kukux\DigitalSignature\Filament\Fields\SignaturePad;

SignaturePad::make('signature_data')
    ->label('Your Signature')
```

### Options

```php
SignaturePad::make('signature_data')
    ->canvasWidth(600)          // default: 600 px
    ->canvasHeight(200)         // default: 200 px
    ->penColor('#000000')       // default: #000000
    ->penWidth(0.5, 2.5)        // min / max stroke width, default: 0.5, 2.5
    ->confirmLabel('Accept')    // default: "Confirm"
    ->withoutUploadTab()        // draw only
    ->withoutDrawTab()          // upload only
    ->withoutClearBtn()
    ->withoutUndoBtn()
```

For stricter authenticity, use draw-only:

```php
SignaturePad::make('signature_data')->withoutUploadTab()
```

**State:** a base64 PNG data URI (`data:image/png;base64,...`), or `null` when empty.

---

## SignatureColumn — table column

Shows a signature thumbnail and status badge in a table. Use it on resources whose rows are `Signable` models (e.g. a `ContractResource`), not on signature records themselves.

```php
use Kukux\DigitalSignature\Filament\Columns\SignatureColumn;

SignatureColumn::make('signature')
    ->thumbSize(120, 48)    // width, height in px (default: 80 × 32)
```

How it finds the image:

- It calls `latestSignature()` on the row model if that method exists, otherwise uses `$record->signature`.
- It builds a temporary URL from `image_path` on the configured storage disk. The URL expires after 5 minutes (`signature.preview_url_ttl`). Disks without temporary URLs (like `local`) get a signed route instead.

---

## SignDocumentAction — action

Opens a modal where the user picks one of their registered signatures, then signs the record's PDF.

Before you use it:

- The record must implement `Signable`.
- The user needs a registered signature. They can add one from the launcher, the Signatures resource, or you can call `SignatureManager::store()`.
- That signature needs a stored certificate password. The modal doesn't ask for one. If it's missing, the user has to re-create the signature.

### In a table row

```php
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;

->actions([
    SignDocumentAction::make()
        ->stampAt(page: 1, x: 100, y: 650, w: 200, h: 80),
])
```

### In a page header

```php
use Kukux\DigitalSignature\Filament\Actions\SignDocumentHeaderAction;

protected function getHeaderActions(): array
{
    return [
        SignDocumentHeaderAction::make(),
    ];
}
```

On Filament v3 you must use `SignDocumentHeaderAction` in headers. On v4/v5 either class works, so the header name is the portable choice. Only use it on pages that have a `Signable` record.

### Options

| Method | What it does |
|---|---|
| `stampAt(page, x, y, w, h)` | Fixed stamp position in PDF points, measured from the bottom-left of the page. Set it: this action has no placement step, so without it the stamp lands at the PDF driver's default corner. To let the signer place their own signature, send them to the [signer page](pdf-templates.md#the-signer-page) or route the document ([Integrating documents](integration/index.md)); both have drag-to-place. |
| `queued()` | Dispatch `EmbedSignatureJob` to the queue. Without it, the action calls `embedAndFinalize()` directly and no worker is needed. |

On submit, the action copies the chosen signature into a new document-specific `Signature` linked to the record, then signs the PDF (CRL check if enabled, PKCS#7 signing, document hash).

### Errors

Errors show up as danger notifications and the modal stays open. You don't need to handle them yourself.

| Problem | Notification title |
|---|---|
| `ForgedSignatureException` | "Signature rejected" |
| `UnregisteredDeviceException` / `MachineBindingException` | "Device not allowed to sign" |
| Signature not owned by the user | "Signature not yours" |
| Revoked signature | "Signature revoked" |
| No stored certificate password | "Certificate password missing" |
| Record isn't `Signable` | "No document selected" |
| Anything else | "Signing failed" |

If the desktop agent needs to approve the signing, the modal stays open and re-submits once the user approves on their computer.

---

## Ad-hoc signing

To sign documents from custom pages, controllers or resources, see [Ad-hoc Signing](ad-hoc-signing.md).
