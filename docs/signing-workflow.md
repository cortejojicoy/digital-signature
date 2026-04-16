# Signing Workflow

This page covers the full lifecycle of a signature — from form submission to a signed PDF on disk — and shows how to drive each step manually using `SignatureManager` directly.

---

## Lifecycle overview

```
User submits form (SignDocumentAction)
      |
      v
SignatureManager::store()
  - Forgery check (duplicate image hash)
  - Metadata validation on upload (HMAC + user ID + machine)
  - DB cross-validation (Sig-Record-Id → machine_fingerprint)
  - Document hash captured
  - UUID generated
  - PNG metadata embedded (tEXt + XMP: signer name, machine hash, UUID)
  - Signature record created (status: pending)
      |
      v
SignatureManager::embedAndFinalize()   ← called directly (synchronous default)
  OR EmbedSignatureJob::handle()       ← via .queued()
      |
      v
SignatureManager::embedAndFinalize()
  - CRL check (if enabled)
  - Certificate loaded from PFX
  - PDF signed with PKCS#7 (FpdiDriver)
  - Signed PDF hash captured
  - Signature record updated (status: signed)
  - DocumentSigned event fired
```

By default, `SignDocumentAction` calls `embedAndFinalize()` synchronously — no queue worker is required. Opt into queued signing with `.queued()`.

---

## Using SignatureManager directly

For controllers, commands, or non-Filament flows.

```php
use Kukux\DigitalSignature\Services\SignatureManager;

$manager = app(SignatureManager::class);
```

### store() — save the image and create a pending record

```php
$signature = $manager->store(
    userId:     $user->id,
    input:      $request->input('signature_data'),  // base64 data URI or UploadedFile
    source:     'draw',                              // 'draw' or 'upload'
    signable:   $contract,                           // optional Signable model
    signerName: $user->name . ' <' . $user->email . '>',  // optional, embedded in PNG
    position:   [                                    // optional stamp coordinates
        'page'   => 1,
        'x'      => 100.0,
        'y'      => 650.0,
        'width'  => 200.0,
        'height' => 80.0,
    ],
);

// $signature->uuid   — unique token embedded in the PNG and stored in DB
// $signature->status — 'pending'
```

`signerName` is embedded in the PNG as `Sig-Signer-Name` (inside the HMAC) and surfaced in XMP metadata visible to macOS Preview and Windows File Explorer.

You can also pass an `UploadedFile` instead of a base64 string:

```php
$signature = $manager->store(
    userId:     $user->id,
    input:      $request->file('signature_image'),
    source:     'upload',
    signerName: $user->name . ' <' . $user->email . '>',
);
```

When `source = 'upload'`, `validateIfPresent()` runs first — any HMAC violation, user mismatch, or machine mismatch throws before the record is written.

### embedAndFinalize() — sign the PDF synchronously

```php
$manager->embedAndFinalize($signature, $request->input('certificate_password'));
```

Runs the full PDF signing pipeline in the current process. Throws on failure (certificate error, CRL revocation, missing PDF). The signature record is updated to `status = signed` on success.

### sign() — dispatch the signing job (queued)

```php
$manager->sign($signature, $request->input('certificate_password'));
```

Queues `EmbedSignatureJob`. The job calls `embedAndFinalize()` and sets `status = failed` after 3 retries if it cannot complete.

### revoke() — invalidate a signature

```php
$manager->revoke($signature);

// $signature->status  → 'revoked'
// $signature->revoked_at → now()
// SignatureRevoked event fired
```

---

## Synchronous vs queued signing

| Mode | How to use | Requires queue worker |
|---|---|---|
| Synchronous (default) | `SignDocumentAction::make()` | No |
| Queued | `SignDocumentAction::make()->queued()` | Yes |

Synchronous signing blocks the HTTP request until the PDF is signed. For large PDFs or TSA requests with network latency, consider queued mode.

---

## Checking signature status

```php
$contract = Contract::find(1);

$contract->isSigned();           // bool
$contract->latestSignature();    // ?Signature

$sig = $contract->latestSignature();

$sig->isPending();   // true while job is queued / before embedAndFinalize()
$sig->isSigned();    // true after embedAndFinalize() completes
$sig->isRevoked();   // true after revoke()
```

---

## Verifying document integrity

After signing, the plugin stores SHA-256 hashes of both the original and signed PDFs.

```php
use Illuminate\Support\Facades\Storage;

$sig  = $contract->latestSignature();
$disk = Storage::disk(config('signature.storage_disk'));

// Verify the signed PDF has not changed since signing
$current = hash('sha256', $disk->get($sig->signed_document_path));

if ($current !== $sig->signed_document_hash) {
    // The signed file has been modified after it was produced
}
```

---

## Verifying PNG metadata

After download, you can verify that a signature PNG was produced by your server and has not been tampered with:

```php
use Kukux\DigitalSignature\Security\PngMetaEmbedder;

$chunks = app(PngMetaEmbedder::class)->read(file_get_contents($path));

// $chunks['Sig-Record-Id'] — look up the DB record
// $chunks['Sig-Signer-Name'] — who it was registered to
// $chunks['Sig-Hmac'] — verify against your APP_KEY
```

---

## Events

| Event | Payload | When fired |
|---|---|---|
| `CertificateIssued` | `$certificate` (`UserCertificate`) | New certificate created for a user |
| `DocumentSigned` | `$signature` (`Signature`) | `embedAndFinalize()` completes successfully |
| `SignatureRevoked` | `$signature` (`Signature`) | `revoke()` is called |

```php
// app/Providers/EventServiceProvider.php

use Kukux\DigitalSignature\Events\DocumentSigned;
use Kukux\DigitalSignature\Events\SignatureRevoked;

protected $listen = [
    DocumentSigned::class  => [SendSignedDocumentEmail::class],
    SignatureRevoked::class => [NotifyAdminOfRevocation::class],
];
```

Or using a closure:

```php
use Kukux\DigitalSignature\Events\DocumentSigned;

Event::listen(DocumentSigned::class, function ($event) {
    $sig = $event->signature;

    // $sig->signed_document_path  — path to the signed PDF
    // $sig->signed_document_hash  — SHA-256 for integrity checks
    // $sig->certificate_fingerprint
    // $sig->signed_at
    // $sig->uuid                  — embedded in the PNG as Sig-Record-Id
});
```

---

## Signature statuses

| Status | Meaning |
|---|---|
| `pending` | Image stored, PDF not yet signed |
| `signed` | `embedAndFinalize()` completed |
| `revoked` | Manually invalidated via `revoke()` |
| `failed` | `embedAndFinalize()` failed after 3 retries (queued mode) |

---

## Queue configuration

Only relevant when using `.queued()`. The job retries 3 times with a 120-second timeout per attempt.

```php
// config/signature.php
'queue'            => env('SIGNATURE_QUEUE', 'default'),
'queue_connection' => env('SIGNATURE_QUEUE_CONNECTION', null),
```

To handle failed jobs:

```php
Queue::failing(function (JobFailed $event) {
    if ($event->job->resolveName() === \Kukux\DigitalSignature\Jobs\EmbedSignatureJob::class) {
        // notify, log, etc.
    }
});
```

The job's `failed()` method automatically sets the signature `status` to `failed`.
