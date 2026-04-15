# Digital Signature for Filament — Documentation

A Laravel Filament plugin for capturing signatures, issuing X.509 certificates, and embedding cryptographically signed stamps into PDF documents.

---

## Contents

| Doc | What it covers |
|---|---|
| [Installation](installation.md) | Composer, migrations, plugin registration, queue setup |
| [Configuration](configuration.md) | Every config key, all env variables, driver options |
| [Model Setup](model-setup.md) | Signable interface, HasSignatures trait, model attributes |
| [Filament Components](filament-components.md) | SignaturePad field, SignatureColumn, SignDocumentAction |
| [Signing Workflow](signing-workflow.md) | Full lifecycle, SignatureManager API, events, statuses |
| [Certificates](certificates.md) | Certificate issuance, UserCertificate model, CA setup, CFSSL |
| [Security](security.md) | PKCS#7, DocMDP, forgery detection, CRL, TSA, integrity hashes |

---

## Quick start

```bash
composer require kukux/digital-signature
php artisan vendor:publish --tag=signature-migrations
php artisan vendor:publish --tag=signature-config
php artisan migrate
php artisan queue:work
```

Register the plugin:

```php
// app/Providers/Filament/AdminPanelProvider.php
->plugins([
    \Kukux\DigitalSignature\SignaturePlugin::make(),
])
```

Prepare your model:

```php
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Traits\HasSignatures;

class Contract extends Model implements Signable
{
    use HasSignatures;

    public function getSignableTitle(): string  { return $this->title; }
    public function getSignablePdfPath(): string { return $this->pdf_path; }
    public function getSignableId(): int|string  { return $this->id; }
}
```

Add the action to a resource:

```php
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;

SignDocumentAction::make()->stampAt(page: 1, x: 100, y: 650, w: 200, h: 80)
```

Display signature status in a table:

```php
use Kukux\DigitalSignature\Filament\Columns\SignatureColumn;

SignatureColumn::make('signature')->thumbSize(80, 32)
```

---

## Minimum working example

A complete Filament resource that lets users sign a contract PDF.

```php
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;
use Kukux\DigitalSignature\Filament\Columns\SignatureColumn;

class ContractResource extends Resource
{
    protected static ?string $model = Contract::class;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title'),
                SignatureColumn::make('signature')->thumbSize(80, 32),
                TextColumn::make('latestSignature.status')->label('Status'),
            ])
            ->actions([
                SignDocumentAction::make()
                    ->stampAt(page: 1, x: 100, y: 650, w: 200, h: 80),
            ]);
    }
}
```
