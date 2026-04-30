# Digital Signature for Filament — Documentation

A Laravel Filament plugin for capturing signatures, issuing X.509 certificates, and embedding cryptographically signed stamps into PDF documents.

---

## Contents

| Doc | What it covers |
|---|---|
| [Installation](installation.md) | Composer, migrations, plugin registration, admin resource config |
| [Configuration](configuration.md) | Every config key, all env variables, driver options |
| [Model Setup](model-setup.md) | Signable interface, HasSignatures trait, model attributes |
| [Filament Components](filament-components.md) | SignaturePlugin, SignatureResource, SignaturePad, SignatureColumn, SignDocumentAction |
| [Signing Workflow](signing-workflow.md) | Full lifecycle, SignatureManager API, sync vs queued, events, statuses |
| [Ad-hoc Signing](ad-hoc-signing.md) | How to sign documents from your own resources, controllers, and pages |
| [On-Demand PDF Signing](on-demand-pdf-signing.md) | Signing records whose PDF is generated (DomPDF, etc.), not stored |
| [Certificates](certificates.md) | Certificate issuance, UserCertificate model, CA setup, CFSSL |
| [Security](security.md) | PKCS#7, DocMDP, HMAC PNG metadata, XMP, signer identity, DB cross-validation, machine lock, CRL, TSA |

---

## Quick start

```bash
composer require kukux/digital-signature
php artisan vendor:publish --tag=signature-migrations
php artisan vendor:publish --tag=signature-config
php artisan migrate
```

Register the plugin:

```php
// app/Providers/Filament/AdminPanelProvider.php
->plugins([
    \Kukux\DigitalSignature\SignaturePlugin::make()
        ->navigationGroup('Documents')   // optional
        ->navigationIcon('heroicon-o-pencil-square')  // optional
        ->navigationSort(10),             // optional
])
```

This registers:
- **Signatures** — full admin resource for registering reusable signature images and viewing signature records
- **Sign Document** — header actions inside the Signatures resource for signing with a registered signature

---

## Preparing a signable model

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

## Adding the sign action to your own resource

First let users register a reusable signature in the built-in **Signatures** resource. Then add the signing action to resources whose model implements `Signable`.

```php
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
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

`SignDocumentAction` validates the selected signature and surfaces signing errors as Filament danger notifications.

For custom controller, page, or Livewire flows, see [Ad-hoc Signing](ad-hoc-signing.md).

---

## Security highlights

| Feature | Default |
|---|---|
| PKCS#7 cryptographic signature embedded in PDF | Always on |
| DocMDP P=2 — post-signing modification detection | Always on |
| HMAC-signed PNG metadata (tEXt + XMP) | Always on |
| Signer identity (name + email) embedded in PNG | Always on |
| XMP metadata visible in macOS Preview & Windows Explorer | Always on |
| Forgery / screenshot upload rejection | Always on |
| DB cross-validation on re-upload (Sig-Record-Id) | Always on |
| Document integrity hashes (before + after) | Always on |
| Machine lock — reject re-upload from different device | `SIGNATURE_MACHINE_LOCK=true` (default) |
| CRL certificate revocation check | `SIGNATURE_CRL_ENABLED=false` |
| RFC 3161 trusted timestamp via TSA | `SIGNATURE_TSA_URL=` (disabled) |

For full details see [docs/security.md](security.md).
