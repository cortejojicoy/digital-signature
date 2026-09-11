# Digital Signature for Filament

[![Latest Version on Packagist](https://img.shields.io/packagist/v/kukux/digital-signature.svg?style=flat-square)](https://packagist.org/packages/kukux/digital-signature)
[![Total Downloads](https://img.shields.io/packagist/dt/kukux/digital-signature.svg?style=flat-square)](https://packagist.org/packages/kukux/digital-signature)
[![License](https://img.shields.io/packagist/l/kukux/digital-signature.svg?style=flat-square)](https://packagist.org/packages/kukux/digital-signature)

A Laravel Filament plugin for capturing signatures, issuing X.509 certificates, and embedding cryptographically signed stamps into PDF documents.

**Supports:** Filament v3, v4 and v5 — Laravel 12 — PHP 8.2+

---

## Documentation

| Doc | Description |
|---|---|
| [Installation](docs/installation.md) | Composer, migrations, plugin registration, admin resource |
| [Configuration](docs/configuration.md) | All config keys and env variables |
| [Model Setup](docs/model-setup.md) | Signable interface and HasSignatures trait |
| [Filament Components](docs/filament-components.md) | SignaturePad, SignatureColumn, SignatureResource, SignDocumentAction |
| [Signing Workflow](docs/signing-workflow.md) | Full lifecycle and SignatureManager API |
| [Ad-hoc Signing](docs/ad-hoc-signing.md) | Implement document signing outside a package resource |
| [Certificates](docs/certificates.md) | Certificate issuance, CA setup, CFSSL |
| [Signatory Routing](docs/signatory-routing.md) | Role-bound slots, signing sessions, consent models, multi-signatory documents, Filament version compatibility |
| [Security](docs/security.md) | HMAC metadata, machine binding, DB cross-validation, forgery detection |

---

## Requirements

- PHP 8.2+ with `ext-openssl` and `ext-gd`
- Laravel 12
- Filament 3, 4, or 5

---

## Quick Install

```bash
composer require kukux/digital-signature
php artisan vendor:publish --tag=signature-migrations
php artisan vendor:publish --tag=signature-config
php artisan migrate
php artisan filament:assets
```

`filament:assets` publishes the plugin's JS bundle (signature pad + picker) so it's reachable from the panel — re-run it after every `composer update` of this package.

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
- **Signatures** — a full admin resource for registering reusable signature images and viewing signature records
- **Sign Document** — header actions inside the Signatures resource for signing with a registered signature

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

First let the signer register a reusable signature from the built-in **Signatures** resource. Then add `SignDocumentAction` to any resource whose model implements `Signable`.

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

For controller-driven or custom page flows, see [Ad-hoc Signing](docs/ad-hoc-signing.md).

---

## Built-in Signatures Admin Resource

When the plugin is registered, a **Signatures** resource appears in the sidebar automatically.

Register `SignaturePlugin::make()` on every Filament panel that should use the package. If a panel discovers or registers `SignatureResource` without the plugin, Filament can report `Plugin [signature] is not registered for panel [admin]`.

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

```

---

## Multi-Signatory Documents

When a document is signed by *roles* rather than by whoever opens it — an
Accomplishment Report with **Prepared by**, **Attested by** and **Noted by** —
declare the roles on the template and let the plugin find the people:

```php
// config/signature.php
'templates' => [
    'accomplishment-report' => [
        'view'     => 'pdf.accomplishment-report',
        'signable' => \App\Models\AccomplishmentReport::class,
        'slots'    => [
            'prepared_by' => ['label' => 'Prepared by', 'signatory' => 'preparedBy', 'order' => 1, 'required' => true],
            'attested_by' => ['label' => 'Attested by', 'signatory' => 'attestedBy', 'order' => 2, 'required' => true],
            'noted_by'    => ['label' => 'Noted by',    'signatory' => 'notedBy',    'order' => 3, 'required' => true],
        ],
    ],
],
```

```php
class AccomplishmentReport extends Model implements Signable
{
    use HasPdfTemplate, HasSignatories;

    protected string $signaturePdfTemplate = 'accomplishment-report';

    public function preparedBy(): BelongsTo { return $this->belongsTo(User::class, 'prepared_by_id'); }
    public function attestedBy(): BelongsTo { return $this->belongsTo(User::class, 'attested_by_id'); }
    public function notedBy(): BelongsTo    { return $this->belongsTo(User::class, 'noted_by_id'); }
}
```

```php
// In your resource
SignatoryPanel::make('signatories');       // who signs, and where they're up to
RequestSignaturesAction::make();            // freeze the PDF and ask them
```

Each person registers their signature once in their own panel. Being tagged on
a record is then enough for the document to reach them — the plugin resolves
the person, finds their signature, pre-fills the placement, and lists the
document in their **Awaiting my signature** inbox.

**On consent.** By default the plugin never signs *for* anyone: the signature
is always produced in the signatory's own authenticated request, with their own
certificate. Truly hands-off signing requires that person to grant a scoped,
expiring, revocable authorisation from their own account — and every use of it
is audited and notified. See
[Signatory Routing](docs/signatory-routing.md#consent-who-may-sign-and-when).

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
| Signature chain hashes across multi-signatory documents | Always on |
| Audit row for every auto-affixed signature | Always on |
| Auto-signing on someone's behalf | Off — requires their explicit grant |
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
