# Ad-hoc Signing

Ad-hoc signing lets you sign a document from your own resource, page, controller or Livewire component, not just the built-in `SignatureResource`.

It's two steps: the user registers a reusable (primary) signature, then you sign a `Signable` document with it.

## Prerequisites

Your document model implements `Signable` and uses `HasSignatures`:

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

`getSignablePdfPath()` returns a path to a file on the `signature.storage_disk` disk (`SIGNATURE_DISK`, default `local`): either disk-relative, or absolute inside that disk's root. `HasPdfTemplate` returns the absolute form; the package normalises both.

## Register Signatures

The built-in **Signatures** resource handles this: the user adds a signature image and certificate password, and gets a primary `Signature` with status `active`.

Each user can have only **one** active primary signature. Creating a second throws `PrimarySignatureExistsException`, so revoke the old one first. Document-specific signatures don't count toward this limit.

If you disabled the resource, call `SignatureManager::store()` without a `signable`:

```php
use Kukux\DigitalSignature\Services\SignatureManager;

$signature = app(SignatureManager::class)->store(
    userId: auth()->id(),
    input: $request->input('signature_data'), // base64 data URL from a canvas
    source: 'draw',
    signerName: auth()->user()->name.' <'.auth()->user()->email.'>',
    certificatePassword: $request->input('certificate_password'),
);
```

For an uploaded image, pass `input: $request->file('signature_image')` and `source: 'upload'`. If the file carries package metadata, it's checked for HMAC integrity, ownership and machine binding.

## Add Signing to a Filament Resource

Add `SignDocumentAction` to a table whose records implement `Signable`:

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

The modal opens with the user's primary signature already selected, so they just confirm. The action then creates a document-specific `Signature` (via `storeForDocument()`) using the primary's stored certificate password, and signs the PDF.

On a View or Edit page, use `SignDocumentHeaderAction`. It works on Filament 3, 4 and 5; on v4/v5 `SignDocumentAction` also works in headers, but on v3 it doesn't.

```php
use Kukux\DigitalSignature\Filament\Actions\SignDocumentHeaderAction;

// In ViewContract / EditContract
protected function getHeaderActions(): array
{
    return [
        SignDocumentHeaderAction::make()->stampAt(page: 1, x: 100, y: 650, w: 200, h: 80),
    ];
}
```

Action options:

| Method | What it does |
| --- | --- |
| `stampAt(page, x, y, w, h)` | Fixed stamp position in PDF points, `y` measured from the bottom of the page. Omit it to let the signer place the stamp. |
| `queued()` | Dispatch `EmbedSignatureJob` instead of signing inline. Needs a running queue worker (`SIGNATURE_QUEUE`, `SIGNATURE_QUEUE_CONNECTION`). |

Errors (revoked or foreign signature, missing certificate password, disallowed device) show up as danger notifications and keep the modal open.

## Sign From a Controller or Custom Page

Pass the target document as `signable` to `store()`, then finalize. The record starts as `pending`:

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
    position: ['page' => 1, 'x' => 100, 'y' => 650, 'width' => 200, 'height' => 80],
);

$manager->embedAndFinalize($signature, $request->input('certificate_password'));

// Or queue it (needs a worker):
// $manager->sign($signature, $request->input('certificate_password'));
```

To reuse the user's existing primary signature instead of a new image, do what the Filament action does:

```php
use Kukux\DigitalSignature\Models\Signature;

$primary = Signature::primaryActiveFor(auth()->id())->firstOrFail();

$signature = $manager->storeForDocument(
    source: $primary,
    signerUserId: auth()->id(),
    signable: $contract,
    position: ['page' => 1, 'x' => 100, 'y' => 650, 'width' => 200, 'height' => 80],
);

$manager->embedAndFinalize($signature, $primary->getCertificatePassword());
```

`storeForDocument()` throws `ForgedSignatureException` if the signature belongs to someone else or is revoked. Device checks can throw `UnregisteredDeviceException` or `MachineBindingException`.

## Read the Signed Output

Once signed, the record's status is `signed` and a `DocumentSigned` event fires:

```php
$signature->refresh();

$signature->signed_document_path;
$signature->signed_document_hash;
$signature->signed_at;

$contract->isSigned();
$contract->latestSignature();
$contract->signatures()->latest()->get();
```

See [Signing Workflow](signing-workflow.md) for the full lifecycle and [Filament Components](filament-components.md) for more on the action.
