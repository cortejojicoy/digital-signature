# Filament Components

---

## SignaturePlugin — panel plugin

Registers the Signatures resource and Sign Document page on your Filament panel.

### Basic registration

```php
// app/Providers/Filament/AdminPanelProvider.php

use Kukux\DigitalSignature\SignaturePlugin;

->plugins([
    SignaturePlugin::make(),
])
```

### Fluent configuration

```php
SignaturePlugin::make()
    ->navigationIcon('heroicon-o-pencil-square')   // sidebar icon  (default: heroicon-o-pencil-square)
    ->navigationGroup('Documents')                  // sidebar group (default: none)
    ->navigationSort(10)                            // sort position (default: none)
    ->navigationLabel('Document Signatures')        // sidebar label (default: "Signatures")
```

### Disabling parts

```php
// Hide the Signatures resource (bring your own resource):
SignaturePlugin::make()->withoutResource()

// Hide only the standalone Sign Document page:
SignaturePlugin::make()->withoutPages()

// Disable the resource via env (useful for non-admin panels):
// SIGNATURE_RESOURCE_ENABLED=false
```

---

## SignatureResource — admin resource

Registered automatically by `SignaturePlugin`. Provides a full admin interface for managing signature records.

### List page

- Table with signature thumbnail, signer name + email, status badge, capture method, and dates
- Per-row **View** and **Revoke** actions
- Header **Sign Document** action (opens `SignDocumentAction` modal)
- Filters for status and capture method

### View page

- Large signature image with dark-mode support
- Signer name, email, status, capture method, signed-at timestamp
- Collapsible **Security Metadata** section: UUID, image hash, device fingerprint, certificate fingerprint (all copyable)
- Header actions: **Sign Document**, **Download Image**, **Revoke**

### Using with your own resource

If you only want the resource's components without the built-in pages, disable it and build your own:

```php
SignaturePlugin::make()->withoutResource()
```

Then add `SignDocumentAction` and `SignatureColumn` to your own resource as described below.

---

## SignaturePad — form field

Renders a signature capture widget inside any Filament form. Supports a **draw** tab (canvas with brush controls) and an **upload** tab (file input). Fully dark-mode compatible.

### Basic usage

```php
use Kukux\DigitalSignature\Filament\Fields\SignaturePad;

SignaturePad::make('signature_data')
    ->label('Your Signature')
```

### All options

```php
SignaturePad::make('signature_data')
    ->canvasWidth(600)          // drawing area width  (default: 600 px)
    ->canvasHeight(200)         // drawing area height (default: 200 px)
    ->penColor('#1a1a1a')       // initial stroke colour (default: #1a1a1a)
    ->penWidth(0.5, 2.5)        // min / max stroke width (default: 0.5, 2.5)
    ->confirmLabel('Accept')    // confirm button label   (default: "Confirm")
    ->withoutUploadTab()        // hide the upload tab
    ->withoutDrawTab()          // hide the draw tab (upload-only mode)
    ->withoutClearBtn()         // hide the clear button
    ->withoutUndoBtn()          // hide the undo button
```

### Draw-only mode (recommended for strict authenticity)

```php
SignaturePad::make('signature_data')->withoutUploadTab()
```

### State

The field stores a base64 PNG data URI (`data:image/png;base64,...`) or `null` when empty.

---

## SignatureColumn — table column

Displays a signature thumbnail and status badge in a Filament table. Adapts to dark mode via CSS invert.

### Basic usage

```php
use Kukux\DigitalSignature\Filament\Columns\SignatureColumn;

SignatureColumn::make('signature')
```

### Custom thumbnail size

```php
SignatureColumn::make('signature')
    ->thumbSize(120, 48)    // width, height in pixels (default: 80 × 32)
```

### How it resolves the image

The column calls `latestSignature()` on the row model if that method exists, or falls back to `$record->signature`. It reads `image_path` and resolves the URL through the configured storage disk.

For disks that support temporary URLs (S3, etc.) the URL expires after 5 minutes.

> **Note:** `SignatureColumn` is designed for use in resources where the row model implements `Signable` (e.g. `ContractResource`). For the built-in `SignatureResource` where the row IS the signature, the resource uses `ImageColumn` directly.

---

## SignDocumentAction — action

A Filament action that opens a modal with a `SignaturePad` and a certificate password field, then signs the document when submitted.

### In a resource header

```php
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;

protected function getHeaderActions(): array
{
    return [
        SignDocumentAction::make(),
    ];
}
```

### In a table row

```php
->actions([
    SignDocumentAction::make()
        ->stampAt(page: 1, x: 100, y: 650, w: 200, h: 80),
])
```

### Placing the signature stamp on the PDF

`stampAt()` controls where the signature image is drawn on the signed PDF. Coordinates are in PDF units from the bottom-left origin.

```php
SignDocumentAction::make()
    ->stampAt(
        page: 1,
        x:    100.0,
        y:    650.0,
        w:    200.0,
        h:    80.0,
    )
```

### Synchronous vs queued signing

By default the action calls `embedAndFinalize()` directly (synchronous — no queue worker needed):

```php
SignDocumentAction::make()             // synchronous (default)
SignDocumentAction::make()->queued()   // dispatches EmbedSignatureJob to queue
```

### What happens internally

1. Reads `signature_data` (base64 PNG) and `password` from the submitted form
2. Validates the signature is not empty — shows a danger notification if missing
3. Calls `SignatureManager::store()`:
   - Runs forgery detection (`DuplicateSignatureGuard`)
   - Validates existing PNG metadata if uploaded (HMAC, user ID, machine fingerprint, DB record)
   - Embeds HMAC-signed `tEXt` + XMP metadata into the PNG (signer name, email, timestamp, device fingerprint, record UUID)
   - Saves enriched PNG to disk
   - Creates `Signature` DB record
4. Calls `embedAndFinalize()` (or queues `EmbedSignatureJob`):
   - CRL check (if enabled)
   - PKCS#7 PDF signing
   - Captures signed-document hash

### Built-in exception handling

`SignDocumentAction` catches and surfaces these as Filament danger notifications automatically — no extra code needed in your resource:

| Exception | Notification title |
|---|---|
| `MachineBindingException` | "Signature rejected — wrong device" |
| `ForgedSignatureException` | "Invalid signature image" |

---

## SignDocumentPage — standalone page

Registered automatically by the plugin. Provides a dedicated signing page without a custom resource.

Access URL: `/admin/sign-document` (or whatever your panel path is)

To disable:

```php
SignaturePlugin::make()->withoutPages()
```
