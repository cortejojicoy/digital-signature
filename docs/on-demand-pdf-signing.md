# Signing On-Demand (DomPDF / Generated PDFs)

Use this guide when your signable model **does not have a stored PDF** — instead it generates one on the fly (e.g. a Filament resource that renders a DomPDF preview from a Blade view).

The package signs **files on disk**, because a PKCS#7 signature is computed over the exact bytes of a PDF. A stream regenerated on every request can't be signed: the bytes change between renders (timestamps, font subsets, etc.), so the hash recorded on the `Signature` row would never match a later download.

The pattern below renders the PDF **once, lazily, on first sign**, persists it to the signature disk, and then lets the package take over normally.

---

## Workflow

```
User clicks "Sign Document" in Filament
        │
        ▼
SignDocumentAction calls $signable->getSignablePdfPath()
        │
        ▼
Model checks disk for a cached source PDF
        │
        ├── exists  ──► return relative path
        │
        └── missing ──► render via your PdfService → put() on disk → return path
        │
        ▼
FpdiDriver opens the file, stamps the picked signature image,
embeds a PKCS#7 signature, writes the signed copy to signed_docs_path.
        │
        ▼
"View PDF" action checks for signatures()->signed_document_path
and serves the signed copy when present.
```

The source PDF on disk becomes the **canonical, frozen** version of the document. After signing, editing the underlying record will not change what was signed — which is exactly what you want for an audit-grade signature.

---

## Step 1 — Expose a binary renderer on your PDF service

Whatever service currently streams the PDF inline almost certainly already has a private `renderBinary()` method that returns the raw PDF bytes. Make it `public` so the model can call it.

```php
// app/Services/RequisitionIssueSlipPdfService.php

public function renderBinary(RequisitionIssueSlip $slip): string
{
    // ... existing logic ...
    return $pdf->output();
}

public function streamInline(RequisitionIssueSlip $slip): StreamedResponse
{
    $pdfBinary = $this->renderBinary($slip);
    // ... existing streaming response ...
}
```

Both the inline preview route and the signing flow now share one renderer.

---

## Step 2 — Implement `Signable` with lazy persistence

The contract requires `getSignablePdfPath()` to return a string path **relative to the disk configured in `signature.storage_disk`**. The package resolves it via `$disk->path($pdfPath)`.

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
            $disk->put(
                $path,
                app(RequisitionIssueSlipPdfService::class)->renderBinary($this),
            );
        }

        return $path;
    }
}
```

### Path conventions

- **Always relative to the signature disk.** Do not return `storage_path(...)` or an absolute path — the signer driver calls `$disk->path($pdfPath)` and expects a disk-relative string.
- **Stable filename per record.** Use the model's primary key so the cache key is deterministic. Avoid timestamps, slugs, or any value that can change.
- **Keep cached source PDFs out of the public disk.** Use `private` (or `s3`) for `signature.storage_disk` so unsigned source PDFs aren't directly accessible.

### Invalidation

Once a record has been signed, the cached source PDF should be considered **frozen** — re-rendering it would invalidate the `document_hash` recorded on the signature row, breaking verification.

If your record can still be edited *before* signing, invalidate the cache when the record changes:

```php
protected static function booted(): void
{
    static::updated(function (self $slip) {
        if ($slip->isSigned()) {
            return; // never invalidate after signing
        }

        Storage::disk(config('signature.storage_disk'))
            ->delete("ris/{$slip->getKey()}.pdf");
    });
}
```

`isSigned()` is provided by the [`HasSignatures` trait](model-setup.md#hassignatures-trait-methods).

### Errors must throw

Return type is `string`, never `null`. If the PDF can't be produced (missing dependency, render failure, etc.), let the exception propagate — `SignDocumentAction` surfaces it as a Filament danger notification. Returning `''` or `null` produces the cryptic `Return value must be of type string, null returned` TypeError that brought you to this page.

---

## Step 3 — Prefer the signed copy in your "View PDF" action

After signing, `SignatureManager` writes the signed PDF and stores its relative path on the `Signature` row as `signed_document_path`. Your existing preview action should serve the signed copy whenever one exists:

```php
// app/Filament/Resources/.../Pages/ViewRequisitionIssueSlip.php

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

protected function getHeaderActions(): array
{
    return [
        EditAction::make(),

        SignDocumentAction::make()
            ->stampAt(page: 1, x: 100, y: 650, w: 200, h: 80),

        Action::make('generate_pdf')
            ->label('View PDF')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->url(function (): string {
                $record = $this->getRecord();

                $signedPath = $record->signatures()
                    ->whereNotNull('signed_document_path')
                    ->latest('signed_at')
                    ->value('signed_document_path');

                return $signedPath
                    ? route('requisition-issue-slips.pdf.signed', ['path' => $signedPath])
                    : route('requisition-issue-slips.pdf.preview', ['requisitionIssueSlip' => $record]);
            }, shouldOpenInNewTab: true)
            ->disabled(fn (): bool => ! app(RequisitionIssueSlipPdfService::class)->isDompdfInstalled()),
    ];
}
```

You'll need a small route + controller that authorizes the request and streams the signed file from the disk:

```php
// routes/web.php
Route::get('/ris/signed/{path}', [SignedPdfController::class, 'show'])
    ->where('path', '.*')
    ->name('requisition-issue-slips.pdf.signed')
    ->middleware(['auth', 'signed']); // or your own authorization
```

```php
// app/Http/Controllers/SignedPdfController.php
public function show(string $path)
{
    $disk = Storage::disk(config('signature.storage_disk'));

    abort_unless($disk->exists($path), 404);

    // authorize: ensure the current user can view this slip
    // e.g. look up the Signature row by signed_document_path and check policy

    return $disk->response($path, headers: ['Content-Type' => 'application/pdf']);
}
```

---

## Why not just stamp the image at render time?

A "stamp the signature image inside the Blade template at render time" approach is tempting — no files on disk, no caching, simple. But it's a **visual stamp, not a signature**:

- No PKCS#7 / PAdES envelope → PDF readers won't show "Signed by …"
- No `document_hash` over fixed bytes → tamper detection is impossible
- Anyone with edit access to the record changes what every future render shows

If those guarantees don't matter for your use case, you don't need this package — a `signed_at` column on the model is equivalent. Use this on-demand pattern when you do want the cryptographic guarantees on a record whose canonical form is generated, not uploaded.

---

## Related

- [Model Setup](model-setup.md) — `Signable` contract and `HasSignatures` trait reference
- [Signing Workflow](signing-workflow.md) — full lifecycle, what `SignatureManager` does
- [Security](security.md) — PKCS#7, DocMDP, document integrity hashing
