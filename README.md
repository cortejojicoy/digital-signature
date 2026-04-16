# Digital Signature for Filament

[![Latest Version on Packagist](https://img.shields.io/packagist/v/kukux/digital-signature.svg?style=flat-square)](https://packagist.org/packages/kukux/digital-signature)
[![Total Downloads](https://img.shields.io/packagist/dt/kukux/digital-signature.svg?style=flat-square)](https://packagist.org/packages/kukux/digital-signature)
[![License](https://img.shields.io/packagist/l/kukux/digital-signature.svg?style=flat-square)](https://packagist.org/packages/kukux/digital-signature)

A Laravel Filament plugin for capturing signatures, issuing X.509 certificates, and embedding cryptographically signed stamps into PDF documents.

**Supports:** Filament v4 and v5 — Laravel 11 / 12 — PHP 8.2+

---

## Documentation

| Doc | Description |
|---|---|
| [Installation](docs/installation.md) | Composer, migrations, plugin registration, admin resource |
| [Configuration](docs/configuration.md) | All config keys and env variables |
| [Model Setup](docs/model-setup.md) | Signable interface and HasSignatures trait |
| [Filament Components](docs/filament-components.md) | SignaturePad, SignatureColumn, SignatureResource, SignDocumentAction |
| [Signing Workflow](docs/signing-workflow.md) | Full lifecycle and SignatureManager API |
| [Certificates](docs/certificates.md) | Certificate issuance, CA setup, CFSSL |
| [Security](docs/security.md) | HMAC metadata, machine binding, DB cross-validation, forgery detection |

---

## Requirements

- PHP 8.2+ with `ext-openssl` and `ext-gd`
- Laravel 11 or 12
- Filament 4 or 5

---

## Quick Install

```bash
composer require kukux/digital-signature
php artisan vendor:publish --tag=signature-migrations
php artisan vendor:publish --tag=signature-config
php artisan migrate
```

Register the plugin in your panel provider:

```php
// app/Providers/Filament/AdminPanelProvider.php
use Kukux\DigitalSignature\SignaturePlugin;

->plugins([
    SignaturePlugin::make()
        ->navigationGroup('Documents')   // optional
        ->navigationIcon('heroicon-o-pencil-square')  // optional
        ->navigationSort(10),             // optional
])
```

This registers:
- **Signatures** — a full admin resource (list + view all signature records)
- **Sign Document** — a standalone page for ad-hoc signing

---

## Preparing a Signable Model

Any model whose PDF can be signed must implement `Signable` and use `HasSignatures`.

```php
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Traits\HasSignatures;

class Contract extends Model implements Signable
{
    use HasSignatures;

    public function getSignableTitle(): string   { return $this->title; }
    public function getSignablePdfPath(): string { return $this->pdf_path; }
    public function getSignableId(): int|string  { return $this->id; }
}
```

---

## Adding the Sign Action to Your Own Resource

```php
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;
use Kukux\DigitalSignature\Filament\Columns\SignatureColumn;

class ContractResource extends Resource
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title'),
                SignatureColumn::make('signature')->thumbSize(80, 32),
            ])
            ->actions([
                SignDocumentAction::make()
                    ->stampAt(page: 1, x: 100, y: 650, w: 200, h: 80),
            ]);
    }
}
```

---

## Built-in Signatures Admin Resource

When the plugin is registered, a **Signatures** resource appears in the sidebar automatically.

**List page** — table of all signature records with thumbnail, signer, status, and method.  
**View page** — full infolist showing the large signature image, signer details, security metadata.

Both pages include a **Sign Document** header action.

Customize appearance:

```php
SignaturePlugin::make()
    ->navigationGroup('Documents')
    ->navigationIcon('heroicon-o-pencil-square')
    ->navigationSort(10)
    ->navigationLabel('Document Signatures')

// Disable the resource entirely (bring your own):
SignaturePlugin::make()->withoutResource()

// Disable only the standalone sign page:
SignaturePlugin::make()->withoutPages()
```

---

## Security Highlights

| Feature | Default |
|---|---|
| PKCS#7 cryptographic signature embedded in PDF | Always on |
| DocMDP P=2 — post-signing modification detection | Always on |
| HMAC-signed PNG metadata (tEXt + XMP) | Always on |
| XMP metadata visible in macOS Preview & Windows Explorer | Always on |
| Signer identity (name + email) embedded in PNG | Always on |
| Forgery / screenshot upload rejection | Always on |
| Document integrity hashes (before + after) | Always on |
| Machine binding — DB cross-validation on re-upload | Always on |
| Machine lock — reject re-upload from different device | `SIGNATURE_MACHINE_LOCK=true` |
| CRL certificate revocation check | `SIGNATURE_CRL_ENABLED=true` |
| RFC 3161 trusted timestamp via TSA | `SIGNATURE_TSA_URL=https://...` |

For full details see [docs/security.md](docs/security.md).

---

## Queue

Signing runs asynchronously. Start a queue worker:

```bash
php artisan queue:work
```

To sign synchronously (no queue required):

```php
SignDocumentAction::make()   // default is now synchronous
```

The action calls `embedAndFinalize()` directly unless you opt in to queued signing:

```php
SignDocumentAction::make()->queued()
```
