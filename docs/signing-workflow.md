# Signing Workflow

This page walks a signature from image to signed PDF, and shows how to drive each step yourself with `SignatureManager`.

## Lifecycle overview

Signing happens in two steps:

1. **Register a signature image** with `store()`. You do this once per user. It becomes the user's reusable primary signature (status `active`).
2. **Sign a document** with `storeForDocument()`, then `embedAndFinalize()`. Each signing gets its own record (`pending`, then `signed`).

| Step | What happens |
|---|---|
| `store()` | Duplicate-image and upload metadata checks, UUID generated, metadata embedded in the PNG |
| `storeForDocument()` | Ownership and revocation check, PDF hash captured, `pending` record created |
| `embedAndFinalize()` | CRL check (if enabled), PDF signed with PKCS#7, signed PDF hash captured, `DocumentSigned` fired |

`SignDocumentAction` does step 2 for you, synchronously, so no queue worker is needed. Add `->queued()` to sign in the background instead.

## Using SignatureManager directly

Use this from controllers, commands, or any non-Filament flow.

```php
use Kukux\DigitalSignature\Services\SignatureManager;

$manager = app(SignatureManager::class);
```

### store(): register a signature image

```php
$signature = $manager->store(
    userId:              $user->id,
    input:               $request->input('signature_data'),       // base64 data URI or UploadedFile
    source:              'draw',                                   // 'draw' or 'upload'
    signerName:          $user->name . ' <' . $user->email . '>',  // embedded in the PNG
    deviceFp:            $request->input('device_fp', ''),         // browser fingerprint, optional
    certificatePassword: $request->input('certificate_password'), // stored encrypted, optional
);

// $signature->uuid   unique token, also embedded in the PNG
// $signature->status 'active'
```

Good to know:

- A user can have only one active primary signature. A second `store()` without `signable` throws `PrimarySignatureExistsException`, so revoke the old one first.
- Save a `certificatePassword` if you use `SignDocumentAction`. The action reads it from the signature and fails without it.
- For `source: 'upload'`, pass an `UploadedFile`. If it's a PNG exported from this system, its HMAC, user, device and DB record are checked first. A mismatch throws `ForgedSignatureException` or `MachineBindingException` before anything is saved.
- Reusing another user's image is rejected by the duplicate-hash check.
- Passing `signable:` (and optionally `position:`) creates a one-off `pending` record for that document instead of a primary signature.

### storeForDocument(): create a pending record for a document

```php
$pending = $manager->storeForDocument(
    source:       $signature,  // the user's registered signature
    signerUserId: $user->id,
    signable:     $contract,
    position:     ['page' => 1, 'x' => 100.0, 'y' => 650.0, 'width' => 200.0, 'height' => 80.0],
);
```

- `position` is optional and uses PDF points, with `y` measured from the bottom of the page.
- Throws `ForgedSignatureException` if the signature belongs to someone else or is revoked.
- With a paired desktop agent, throws `AgentApprovalRequiredException` until the user approves on their computer.
- Optional: `sourcePdfPath` signs an existing PDF instead of re-rendering the record, and `extraPositions` draws the same signature in more places.

### embedAndFinalize(): sign the PDF now

```php
$manager->embedAndFinalize($pending, $signature->getCertificatePassword());
```

Signs in the current process and sets `status = signed`. Throws on failure (wrong certificate password, CRL revocation, missing PDF). An optional third argument, `sourcePdfPath`, signs a specific PDF on the storage disk.

### sign(): sign the PDF on the queue

```php
$manager->sign($pending, $signature->getCertificatePassword());
```

Dispatches `EmbedSignatureJob`, which calls `embedAndFinalize()`. Also takes an optional `sourcePdfPath`.

| Mode | Filament | Direct | Needs a queue worker |
|---|---|---|---|
| Synchronous (default) | `SignDocumentAction::make()` | `embedAndFinalize()` | No |
| Queued | `SignDocumentAction::make()->queued()` | `sign()` | Yes |

Synchronous signing blocks the request until the PDF is signed. Go queued for large PDFs or slow TSA calls.

### revoke(): invalidate a signature

```php
$manager->revoke($signature);  // status 'revoked', revoked_at now(), SignatureRevoked fired
```

## Checking signature status

On a model that uses the `HasSignatures` trait:

```php
$contract->isSigned();           // true if any signature is 'signed'
$contract->latestSignature();    // ?Signature
$contract->pendingSignatures();  // MorphMany
```

| Status | Helper | Meaning |
|---|---|---|
| `active` | `isActive()` | Registered primary signature, ready to reuse |
| `pending` | `isPending()` | Document record created, PDF not signed yet |
| `signed` | `isSigned()` | `embedAndFinalize()` completed |
| `revoked` | `isRevoked()` | Invalidated via `revoke()` |
| `failed` | none | Queued job gave up after 3 tries |

## Verifying document integrity

Each record stores a SHA-256 hash of the PDF before signing (`document_hash`) and after (`signed_document_hash`). To check the signed file hasn't changed:

```php
use Illuminate\Support\Facades\Storage;

$sig  = $contract->latestSignature();
$disk = Storage::disk(config('signature.storage_disk'));

if (hash('sha256', $disk->get($sig->signed_document_path)) !== $sig->signed_document_hash) {
    // The signed file was modified after signing
}
```

## Verifying PNG metadata

```php
use Kukux\DigitalSignature\Security\PngMetaEmbedder;

$chunks = app(PngMetaEmbedder::class)->read(file_get_contents($path));

// $chunks['Sig-Record-Id']   the Signature uuid, use it to look up the DB record
// $chunks['Sig-Signer-Name'] who it was registered to
// $chunks['Sig-Hmac']        HMAC-SHA256 keyed with APP_KEY
```

See [Security](security.md) for every key and the HMAC formula.

## Events

| Event | Payload | When fired |
|---|---|---|
| `CertificateIssued` | `$certificate` (`UserCertificate`) | A new certificate is created for a user |
| `DocumentSigned` | `$signature` (`Signature`) | `embedAndFinalize()` succeeds |
| `SignatureRevoked` | `$signature` (`Signature`) | `revoke()` is called |

Signing requests and sessions fire their own events, covered in [Signatory Routing](signatory-routing.md).

```php
use Kukux\DigitalSignature\Events\DocumentSigned;

Event::listen(DocumentSigned::class, function ($event) {
    $sig = $event->signature;

    // $sig->signed_document_path, $sig->signed_document_hash,
    // $sig->certificate_fingerprint, $sig->signed_at, $sig->uuid
});
```

## Queue configuration

Only matters with `->queued()` or `sign()`. `EmbedSignatureJob` tries 3 times with a 120-second timeout, and skips any signature that's no longer `pending`.

```php
// config/signature.php
'queue'            => env('SIGNATURE_QUEUE', 'default'),
'queue_connection' => env('SIGNATURE_QUEUE_CONNECTION', null),
```

When the job finally fails, it sets the signature to `failed`. To react to that yourself:

```php
Queue::failing(function (JobFailed $event) {
    if ($event->job->resolveName() === \Kukux\DigitalSignature\Jobs\EmbedSignatureJob::class) {
        // notify, log, etc.
    }
});
```
