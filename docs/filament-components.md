# Filament Components

---

## SignaturePlugin — panel plugin

Registers the Signatures resource on your Filament panel.

### Basic registration

```php
// app/Providers/Filament/AdminPanelProvider.php

use Kukux\DigitalSignature\SignaturePlugin;

->plugins([
    SignaturePlugin::make(),
])
```

Register the plugin on each panel that should use the package. Avoid manually discovering the package resource from `vendor`; the plugin registers it for you.

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

// Disable the resource via env (useful for non-admin panels):
// SIGNATURE_RESOURCE_ENABLED=false
```

---

## SignatureResource — admin resource

Registered automatically by `SignaturePlugin`. Provides a full admin interface for managing signature records.

### List page

- Table with signature thumbnail, signer name + email, status badge, capture method, and dates
- Per-row **View** and **Revoke** actions
- Header **Add Signature** action for registering a reusable signature
- Header **Sign Document** action for signing with a registered signature
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

A Filament action that opens a modal where the current user selects one of their registered signatures, enters a certificate password when needed, and signs the document when submitted.

Before using this action, the signer must have a stored signature record. Users can create one from the built-in **Signatures** resource, or you can create one yourself with `SignatureManager::store()`.

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

Use header actions only when the page can provide a `Signable` record to the action. For document-specific signing, table row actions are usually the clearest integration.

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

1. Reads `signature_id` and `password` from the submitted form
2. Validates the selected signature belongs to the authenticated user
3. Rejects revoked signatures
4. Copies the selected signature image into a new document-specific `Signature` record linked to the current `Signable` record
5. Applies the `stampAt()` position when one is configured
6. Uses the stored certificate password, or the submitted password when no stored password exists
7. Calls `embedAndFinalize()` or queues `EmbedSignatureJob`:
   - CRL check (if enabled)
   - PKCS#7 PDF signing
   - Captures signed-document hash

### Built-in exception handling

`SignDocumentAction` catches and surfaces these as Filament danger notifications automatically — no extra code needed in your resource:

| Exception | Notification title |
|---|---|
| `ForgedSignatureException` | "Invalid signature image" |

---

## Ad-hoc signing

For custom resources, controllers, or pages that need to sign documents outside the built-in resource, see [Ad-hoc Signing](ad-hoc-signing.md).
