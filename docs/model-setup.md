# Model Setup

Any Eloquent model that needs to be signed must do two things:

1. Implement the `Signable` contract
2. Use the `HasSignatures` trait

---

## Implementing Signable

```php
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Traits\HasSignatures;

class Contract extends Model implements Signable
{
    use HasSignatures;

    // Display name shown in the signing UI
    public function getSignableTitle(): string
    {
        return $this->title;
    }

    // Path to the PDF on the configured storage disk
    public function getSignablePdfPath(): string
    {
        return $this->pdf_path;
    }

    // Primary key — used to link signatures back to this record
    public function getSignableId(): int|string
    {
        return $this->id;
    }
}
```

---

## HasSignatures trait methods

| Method | Returns | Description |
|---|---|---|
| `signatures()` | `MorphMany` | All signature records attached to this model |
| `pendingSignatures()` | `MorphMany` | Only signatures with `status = pending` |
| `latestSignature()` | `?Signature` | The most recently created signature, or `null` |
| `isSigned()` | `bool` | `true` if at least one `signed` signature exists |

### Examples

```php
$contract = Contract::find(1);

// Check if a document has been fully signed
if ($contract->isSigned()) {
    // ...
}

// Get the most recent signature
$sig = $contract->latestSignature();
echo $sig->status;          // pending | signed | revoked | failed
echo $sig->signed_at;       // Carbon timestamp
echo $sig->uuid;            // unique token per signing request

// Loop over all signatures
foreach ($contract->signatures as $sig) {
    echo $sig->user->name . ' — ' . $sig->status;
}

// Query only pending signatures
$contract->pendingSignatures()->each(function ($sig) {
    // remind the signer
});
```

---

## Signature model attributes

| Attribute | Type | Description |
|---|---|---|
| `uuid` | string | Unique token per signing request |
| `user_id` | int | The signer |
| `status` | string | `pending`, `signed`, `revoked`, `failed` |
| `source` | string | `draw` or `upload` |
| `image_path` | string | Path to the raw signature image on disk |
| `image_hash` | string | SHA-256 of the image bytes |
| `document_hash` | string | SHA-256 of the source PDF before signing |
| `signed_document_path` | string | Path to the completed signed PDF |
| `signed_document_hash` | string | SHA-256 of the signed PDF |
| `certificate_fingerprint` | string | SHA-256 fingerprint of the signer's certificate |
| `signed_at` | Carbon | When signing completed |
| `revoked_at` | Carbon | When the signature was revoked |

---

## Multiple signable models

The trait is polymorphic — you can apply it to as many models as needed.

```php
class Invoice extends Model implements Signable { use HasSignatures; ... }
class NdaAgreement extends Model implements Signable { use HasSignatures; ... }
class LeaveRequest extends Model implements Signable { use HasSignatures; ... }
```

Each model has its own `signatures()` relationship scoped by `signable_type` and `signable_id`.
