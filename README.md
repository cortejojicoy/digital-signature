# Digital Signature for Filament

A Laravel Filament plugin for capturing signatures, issuing X.509 certificates, and embedding signed stamps into PDF documents.

**Supports:** Filament v4 and v5 — Laravel 11 / 12 — PHP 8.2+

> Filament v3 is not supported.

---

## Requirements

- PHP 8.2+ with `ext-openssl` and `ext-gd`
- Laravel 11 or 12
- Filament 4 or 5

---

## Installation

```bash
composer require kukux/digital-signature
php artisan vendor:publish --tag=signature-migrations
php artisan vendor:publish --tag=signature-config
php artisan migrate
```

---

## Configuration

After publishing, edit `config/signature.php` to set your preferred drivers and storage paths.

```php
// config/signature.php (key options)

'cert_driver'     => 'openssl',     // 'openssl' or 'cfssl'
'pdf_driver'      => 'fpdi',        // 'fpdi' or 'tcpdf'
'storage_disk'    => 'local',
'certs_path'      => 'certs',
'signatures_path' => 'signatures',
'signed_docs_path'=> 'signed-docs',

'openssl' => [
    'private_key_bits' => 2048,
    'cert_lifetime'    => 3650,     // days
],
```

---

## Preparing Your Model

Any model that can be signed must implement `Signable` and use the `HasSignatures` trait.

```php
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Traits\HasSignatures;

class Contract extends Model implements Signable
{
    use HasSignatures;

    public function getSignableTitle(): string       { return $this->title; }
    public function getSignablePdfPath(): string     { return $this->pdf_path; }
    public function getSignableId(): int|string      { return $this->id; }
}
```

### Trait methods available

| Method | Returns | Description |
|---|---|---|
| `signatures()` | MorphMany | All signature records |
| `pendingSignatures()` | MorphMany | Only pending signatures |
| `latestSignature()` | `?Signature` | Most recent signature |
| `isSigned()` | bool | True if at least one signed signature exists |

---

## Registering the Plugin

### Filament v4

```php
// app/Providers/Filament/AdminPanelProvider.php

use Kukux\DigitalSignature\SignaturePlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            SignaturePlugin::make(),
        ]);
}
```

### Filament v5

The registration is identical. The plugin's `boot()` method is a safe no-op on v4 and activates correctly on v5.

```php
->plugins([
    SignaturePlugin::make(),
])
```

To disable the auto-registered sign page:

```php
SignaturePlugin::make()->withoutPages()
```

---

## Form Field — SignaturePad

Use `SignaturePad` inside any Filament form to capture a drawn or uploaded signature.

```php
use Kukux\DigitalSignature\Filament\Fields\SignaturePad;

SignaturePad::make('signature_data')
    ->label('Signature')
    ->required()
```

**Available options**

```php
SignaturePad::make('signature_data')
    ->canvasWidth(600)          // pixel width of drawing area
    ->canvasHeight(200)         // pixel height of drawing area
    ->penColor('#1a1a1a')       // hex color
    ->penWidth(0.5, 2.5)        // min, max stroke width
    ->withoutUploadTab()        // hide the upload tab
    ->withoutDrawTab()          // hide the draw tab (upload only)
```

---

## Table Column — SignatureColumn

Displays a signature thumbnail and status badge in a Filament table.

```php
use Kukux\DigitalSignature\Filament\Columns\SignatureColumn;

SignatureColumn::make('signature')
    ->thumbSize(80, 32)         // width, height in pixels
```

The column reads `latestSignature()` from the row model automatically. The `image_path` is resolved through the configured storage disk.

---

## Action — SignDocumentAction

Adds a modal action button that presents a signature pad and password field, then queues the signing job.

```php
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;

// In a resource page or table
SignDocumentAction::make()
```

To specify where the signature stamp appears on the PDF:

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

Coordinates follow PDF units from the bottom-left origin. The action uses the authenticated user's certificate (creating one on first use) and dispatches `EmbedSignatureJob` to the configured queue.

---

## Signing Manually via Service

For non-Filament flows or custom controllers, use `SignatureManager` directly.

```php
use Kukux\DigitalSignature\Services\SignatureManager;

$manager = app(SignatureManager::class);

// Store the signature (status: pending)
$signature = $manager->store(
    userId:   $user->id,
    input:    $base64OrUploadedFile,
    source:   'draw',               // 'draw' or 'upload'
    signable: $contract,            // optional Signable model
    position: [                     // optional stamp placement
        'page'   => 1,
        'x'      => 100.0,
        'y'      => 650.0,
        'width'  => 200.0,
        'height' => 80.0,
    ],
);

// Queue the PDF signing job
$manager->sign($signature, $userPassword);

// Revoke a signature
$manager->revoke($signature);
```

---

## Events

Listen to these events in your `EventServiceProvider` or using `#[On]` in Livewire.

| Event | Payload |
|---|---|
| `CertificateIssued` | `$certificate` (UserCertificate) |
| `DocumentSigned` | `$signature` (Signature) |
| `SignatureRevoked` | `$signature` (Signature) |

```php
use Kukux\DigitalSignature\Events\DocumentSigned;

Event::listen(DocumentSigned::class, function ($event) {
    // $event->signature->signed_document_path
});
```

---

## Signature Statuses

| Status | Meaning |
|---|---|
| `pending` | Stored, waiting for the signing job |
| `signed` | PDF has been signed and saved |
| `revoked` | Signature was invalidated |
| `failed` | Job failed after 3 retries |

---

## Queue

Signing is processed asynchronously. Make sure your queue worker is running.

```bash
php artisan queue:work
```

The job retries 3 times with a 120-second timeout. Configure the queue connection and name in `config/signature.php`:

```php
'queue'            => 'default',
'queue_connection' => null,     // null uses the app default
```

---

## CFSSL Driver (optional)

If you use a CFSSL server instead of local OpenSSL:

```php
// config/signature.php
'cert_driver' => 'cfssl',

'cfssl' => [
    'host'    => 'http://localhost:8888',
    'profile' => 'client',
],
```

---

## Publishing Views and Assets

```bash
# Blade views
php artisan vendor:publish --tag=signature-views

# JS / CSS assets
php artisan vendor:publish --tag=signature-assets
```

---

## Security

The plugin ships with several security controls inspired by [LibreSign](https://github.com/LibreSign/libresign).

| Feature | Default |
|---|---|
| PKCS#7 cryptographic signature embedded in PDF | Always on |
| DocMDP P=2 — post-signing modification detection | Always on |
| Forged/screenshot upload rejection | Always on |
| Document integrity hashes (before + after signing) | Always on |
| UUID per signing request | Always on |
| CRL certificate revocation check | Opt-in (`SIGNATURE_CRL_ENABLED=true`) |
| RFC 3161 trusted timestamp via TSA | Opt-in (`SIGNATURE_TSA_URL=...`) |

For full details, configuration options, exception handling, and code examples see [docs/security.md](docs/security.md).
