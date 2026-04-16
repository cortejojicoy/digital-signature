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
- **Signatures** — full admin resource (list + view all signature records)
- **Sign Document** — a standalone page for ad-hoc signing

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

`SignDocumentAction` handles `ForgedSignatureException` and `MachineBindingException` automatically as Filament danger notifications — no extra try/catch needed.

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
