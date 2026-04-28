# Ad-hoc Signing

Ad-hoc signing means signing a document from your own Filament resource, page, controller, or Livewire component instead of relying only on the package's built-in `SignatureResource`.

The current package flow is split in two:

1. Register a reusable signature image for the authenticated user.
2. Sign a specific `Signable` document with one of that user's registered signatures.

---

## Prerequisites

Your document model must implement `Signable` and use `HasSignatures`.

```php
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Traits\HasSignatures;

class Contract extends Model implements Signable
{
    use HasSignatures;

    public function getSignableTitle(): string
    {
        return $this->title;
    }

    public function getSignablePdfPath(): string
    {
        return $this->pdf_path;
    }

    public function getSignableId(): int|string
    {
        return $this->id;
    }
}
```

`getSignablePdfPath()` must return the PDF path on `config('signature.storage_disk')`.

---

## Register Signatures

The built-in **Signatures** resource lets users add a signature image and certificate password. That creates a reusable `Signature` record for the current user.

If you disabled the built-in resource, create the signature yourself with `SignatureManager::store()`:

```php
use Kukux\DigitalSignature\Services\SignatureManager;

$signature = app(SignatureManager::class)->store(
    userId: auth()->id(),
    input: $request->input('signature_data'),
    source: 'draw',
    signerName: auth()->user()->name.' <'.auth()->user()->email.'>',
    certificatePassword: $request->input('certificate_password'),
);
```

For uploaded images, pass the uploaded file and use `source: 'upload'`:

```php
$signature = app(SignatureManager::class)->store(
    userId: auth()->id(),
    input: $request->file('signature_image'),
    source: 'upload',
    signerName: auth()->user()->name.' <'.auth()->user()->email.'>',
    certificatePassword: $request->input('certificate_password'),
);
```

Uploaded signatures are validated for package metadata, HMAC integrity, ownership, and machine binding when metadata is present.

---

## Add Signing to a Filament Resource

Add `SignDocumentAction` to a resource whose record implements `Signable`.

```php
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;

class ContractResource extends Resource
{
    public static function table(Table $table): Table
    {
        return $table
            ->actions([
                SignDocumentAction::make()
                    ->stampAt(page: 1, x: 100, y: 650, w: 200, h: 80),
            ]);
    }
}
```

The action modal asks the user to choose one of their stored signatures and enter a certificate password if the selected signature does not already have one stored. When submitted, the action creates a new document-specific `Signature` record linked to the current `Signable` record, then signs that document.

You can also mount the action as a header action on a View or Edit page — the same picker modal is rendered:

```php
use Filament\Resources\Pages\ViewRecord;
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;

class ViewContract extends ViewRecord
{
    protected function getHeaderActions(): array
    {
        return [
            SignDocumentAction::make()
                ->stampAt(page: 1, x: 100, y: 650, w: 200, h: 80),
        ];
    }
}
```

For non-Filament flows, make sure the signature record you finalize is associated with the target model. The service-level example below shows the most explicit way to do that in a custom ad-hoc flow.

Use queued signing only when you have a worker running:

```php
SignDocumentAction::make()->queued()
```

---

## Sign From a Controller or Custom Page

For a fully custom ad-hoc flow, store the signature against the target document and then finalize it:

```php
use Kukux\DigitalSignature\Services\SignatureManager;

$contract = Contract::findOrFail($request->integer('contract_id'));

$manager = app(SignatureManager::class);

$signature = $manager->store(
    userId: auth()->id(),
    input: $request->input('signature_data'),
    source: 'draw',
    signable: $contract,
    signerName: auth()->user()->name.' <'.auth()->user()->email.'>',
    certificatePassword: $request->input('certificate_password'),
    position: [
        'page' => 1,
        'x' => 100,
        'y' => 650,
        'width' => 200,
        'height' => 80,
    ],
);

$manager->embedAndFinalize(
    signature: $signature,
    userPassword: $request->input('certificate_password'),
);
```

For queued signing:

```php
$manager->sign($signature, $request->input('certificate_password'));
```

---

## Read the Signed Output

After signing completes, the `Signature` record contains the output path and integrity hash:

```php
$signature->refresh();

$signature->signed_document_path;
$signature->signed_document_hash;
$signature->signed_at;
```

You can also query signatures from the document:

```php
$contract->isSigned();
$contract->latestSignature();
$contract->signatures()->latest()->get();
```
