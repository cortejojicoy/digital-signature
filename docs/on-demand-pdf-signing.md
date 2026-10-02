# Signing On-Demand (DomPDF / Generated PDFs)

Use this when your model has no stored PDF and you render one on the fly, like a DomPDF preview from a Blade view.

The package only signs files on disk, because a re-render never has exactly the same bytes. So you render the PDF once on first sign, save it, and the package takes it from there.

> Fixed-layout document? A [PDF template](pdf-templates.md#making-your-model-signable-in-2-lines) with the `HasPdfTemplate` trait does all of this for you.

## How it works

1. The user clicks **Sign Document**.
2. The package calls `$record->getSignablePdfPath()`. Your model renders and saves the PDF if it isn't on disk yet.
3. The PDF driver (`signature.pdf_driver`, `fpdi` or `tcpdf`) stamps the signature, embeds a PKCS#7 signature and saves the signed copy.
4. Your "View PDF" action serves the signed copy once it exists.

The saved PDF is the frozen version of the document. Editing the record afterwards doesn't change what was signed.

## Step 1 — Return raw PDF bytes

Your PDF service needs a public method that returns the PDF as a string. The preview route and signing can then share it.

```php
// app/Services/RequisitionIssueSlipPdfService.php
public function renderBinary(RequisitionIssueSlip $slip): string
{
    // ... existing logic ...
    return $pdf->output();
}
```

## Step 2 — Implement `Signable` and save the PDF lazily

```php
// app/Models/RequisitionIssueSlip.php
use App\Services\RequisitionIssueSlipPdfService;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Traits\HasSignatures;

class RequisitionIssueSlip extends Model implements Signable
{
    use HasSignatures;

    public function getSignableTitle(): string
    {
        return 'RIS-'.($this->ris_number ?: $this->getKey());
    }

    public function getSignableId(): int|string
    {
        return $this->getKey();
    }

    public function getSignablePdfPath(): string
    {
        $disk = Storage::disk(config('signature.storage_disk'));
        $path = "ris/{$this->getKey()}.pdf";

        if (! $disk->exists($path)) {
            $disk->put($path, app(RequisitionIssueSlipPdfService::class)->renderBinary($this));
        }

        return $path;
    }
}
```

Path rules:

- **Return a path relative to `signature.storage_disk`** (env `SIGNATURE_DISK`, default `local`). Not `storage_path(...)`: the drivers and the hash read it through `Storage::disk()`.
- **Use a stable name**, like the primary key. No timestamps or slugs.
- **Keep the disk private** (`local`, `private`, `s3`) so unsigned PDFs aren't public.
- **Throw on failure.** The return type is `string`; returning `null` gives a `Return value must be of type string, null returned` TypeError. Exceptions show up as a "Signing failed" notification.

### Clearing the cached PDF

Never re-render after signing. The new file won't match the signature's `document_hash`, so verification fails.

If the record can change before signing, delete the cached file on update:

```php
protected static function booted(): void
{
    static::updated(function (self $slip) {
        if ($slip->isSigned()) {
            return; // never invalidate after signing
        }

        Storage::disk(config('signature.storage_disk'))->delete("ris/{$slip->getKey()}.pdf");
    });
}
```

`isSigned()` comes from the [`HasSignatures` trait](model-setup.md#hassignatures-trait-methods) and is true once any signature on the record has status `signed`.

## Step 3 — Show the signed copy in "View PDF"

The signed PDF lands in `signature.signed_docs_path` (default `signed-docs`) as `<name>_signed_<timestamp>.pdf`. Its path is saved on the `Signature` row as `signed_document_path`.

```php
// app/Filament/Resources/.../Pages/ViewRequisitionIssueSlip.php
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\URL;
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction; // resolves to the right Filament version

protected function getHeaderActions(): array
{
    return [
        SignDocumentAction::make()
            ->stampAt(page: 1, x: 100, y: 650, w: 200, h: 80),

        Action::make('generate_pdf')
            ->label('View PDF')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->url(function (): string {
                $record = $this->getRecord();

                $signedPath = $record->signatures()
                    ->whereNotNull('signed_document_path')
                    ->latest('signed_at')
                    ->value('signed_document_path');

                return $signedPath
                    ? URL::temporarySignedRoute('requisition-issue-slips.pdf.signed', now()->addMinutes(30), ['path' => $signedPath])
                    : route('requisition-issue-slips.pdf.preview', ['requisitionIssueSlip' => $record]);
            }, shouldOpenInNewTab: true),
    ];
}
```

`stampAt()` uses PDF points, with `y` measured from the bottom of the page. Leave it off to let the signer place the signature.

Then add a route and controller that stream the file:

```php
// routes/web.php
Route::get('/ris/signed/{path}', [SignedPdfController::class, 'show'])
    ->where('path', '.*')
    ->name('requisition-issue-slips.pdf.signed')
    ->middleware(['auth', 'signed']);

// app/Http/Controllers/SignedPdfController.php
public function show(string $path)
{
    $disk = Storage::disk(config('signature.storage_disk'));

    abort_unless($disk->exists($path), 404);

    // authorize: look up the Signature by signed_document_path and check your policy

    return $disk->response($path, headers: ['Content-Type' => 'application/pdf']);
}
```

The `signed` middleware returns 403 for plain `route()` URLs. Use a signed URL as above, or drop `signed` and rely on your own authorization.

## Gotchas

- **One signer per PDF.** `SignDocumentAction` always signs the original source PDF, so two signers give you two separate signed copies. For several signatures on one PDF, use [Signatory Routing](signatory-routing.md).
- **Don't just draw the image in your Blade view.** That's a visual stamp: no "Signed by" in PDF readers and no tamper detection. If that's all you need, a `signed_at` column is enough.

## Related

- [Model Setup](model-setup.md) — `Signable` contract and `HasSignatures` trait reference
- [Signing Workflow](signing-workflow.md) — full lifecycle, what `SignatureManager` does
- [PDF Templates](pdf-templates.md) — fixed-layout documents with `HasPdfTemplate`
- [Security](security.md) — PKCS#7, DocMDP, document integrity hashing
