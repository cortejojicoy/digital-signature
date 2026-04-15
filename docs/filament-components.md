# Filament Components

---

## SignaturePad — form field

Renders a signature capture widget inside any Filament form. Supports a draw tab (canvas) and an upload tab (file input).

### Basic usage

```php
use Kukux\DigitalSignature\Filament\Fields\SignaturePad;

SignaturePad::make('signature_data')
    ->label('Your Signature')
    ->required()
```

### All options

```php
SignaturePad::make('signature_data')
    ->canvasWidth(600)          // drawing area width in pixels  (default: 600)
    ->canvasHeight(200)         // drawing area height in pixels (default: 200)
    ->penColor('#1a1a1a')       // hex stroke colour             (default: #000000)
    ->penWidth(0.5, 2.5)        // min and max stroke width      (default: 0.5, 2.5)
    ->confirmLabel('Accept')    // button label after drawing    (default: Confirm)
    ->withoutUploadTab()        // hide the upload tab
    ->withoutDrawTab()          // hide the draw tab (upload-only mode)
    ->withoutClearBtn()         // hide the clear button on the canvas
    ->withoutUndoBtn()          // hide the undo button on the canvas
```

### Draw-only mode

Prevents users from uploading an image file. Recommended when signature authenticity matters.

```php
SignaturePad::make('signature_data')->withoutUploadTab()
```

### Upload-only mode

```php
SignaturePad::make('signature_data')->withoutDrawTab()
```

### State

The field stores a base64 PNG data URI (`data:image/png;base64,...`) or `null` when empty. An empty string is normalised to `null` automatically during form dehydration.

---

## SignatureColumn — table column

Displays a signature thumbnail and status badge in a Filament table.

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

The column calls `latestSignature()` on the row model if that method exists, or falls back to `$record->signature`. It then reads `image_path` and resolves the URL through the configured storage disk.

For disks that support temporary URLs (S3, etc.) the URL expires after 5 minutes.

---

## SignDocumentAction — action

A Filament action that opens a modal with a `SignaturePad` and a certificate password field, then dispatches the signing job when submitted.

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
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;

->actions([
    SignDocumentAction::make(),
])
```

### Placing the stamp on the PDF

`stampAt()` sets where the signature image appears on the signed PDF. Coordinates are PDF units from the bottom-left origin.

```php
SignDocumentAction::make()
    ->stampAt(
        page: 1,
        x: 100.0,
        y: 650.0,
        w: 200.0,
        h: 80.0,
    )
```

### What the action does internally

1. Reads `signature_data` (base64) and `password` from the submitted form
2. Calls `SignatureManager::store()` — runs forgery detection, captures document hash, assigns UUID
3. Calls `SignatureManager::sign()` — dispatches `EmbedSignatureJob` to the queue
4. The job runs `embedAndFinalize()`: CRL check, PKCS#7 PDF signing, signed-document hash capture

### Handling exceptions

```php
use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;

SignDocumentAction::make()
    ->failureNotificationTitle('Signing failed')
    ->using(function (array $data, Model $record) {
        try {
            // default logic
        } catch (ForgedSignatureException $e) {
            $this->halt();
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    })
```

---

## SignDocumentPage — dedicated page

Registered automatically when the plugin is installed. Provides a standalone page for signing a document without building a custom resource.

To disable it:

```php
SignaturePlugin::make()->withoutPages()
```
