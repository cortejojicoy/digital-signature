# Signing Workflow

This page covers the full lifecycle of a signature — from form submission to a signed PDF on disk — and shows how to drive each step manually using `SignatureManager` directly.

---

## Lifecycle overview

```
User submits form
      |
      v
SignatureManager::store()
  - Forgery check (duplicate image hash)
  - Document hash captured
  - UUID assigned
  - Signature record created (status: pending)
      |
      v
SignatureManager::sign()
  - Dispatches EmbedSignatureJob to queue
      |
      v
EmbedSignatureJob::handle()
  - Calls embedAndFinalize()
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
    userId:   $user->id,
    input:    $request->input('signature_data'),  // base64 data URI
    source:   'draw',                             // 'draw' or 'upload'
    signable: $contract,                          // optional Signable model
    position: [                                   // optional stamp coordinates
        'page'   => 1,
        'x'      => 100.0,
        'y'      => 650.0,
        'width'  => 200.0,
        'height' => 80.0,
    ],
);

// $signature->uuid   — unique token for this signing request
// $signature->status — 'pending'
```

You can also pass an `UploadedFile` instead of a base64 string:

```php
$signature = $manager->store(
    userId: $user->id,
    input:  $request->file('signature_image'),
    source: 'upload',
);
```

### sign() — dispatch the signing job

```php
$manager->sign($signature, $request->input('certificate_password'));
```

This queues `EmbedSignatureJob`. The job will fail (and set `status = failed`) if:
- The certificate password is wrong
- The certificate is revoked (CRL enabled)
- The PDF cannot be found on the storage disk

### revoke() — invalidate a signature

```php
$manager->revoke($signature);

// $signature->status  → 'revoked'
// $signature->revoked_at → now()
// SignatureRevoked event fired
```

---

## Checking signature status

```php
$contract = Contract::find(1);

$contract->isSigned();           // bool
$contract->latestSignature();    // ?Signature

$sig = $contract->latestSignature();

$sig->isPending();   // true while job is queued
$sig->isSigned();    // true after job completes
$sig->isRevoked();   // true after revoke()
```

---

## Verifying document integrity

After signing, the plugin stores SHA-256 hashes of both the original and signed PDFs. You can verify at any point that neither file has been tampered with.

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

## Events

| Event | Payload | When fired |
|---|---|---|
| `CertificateIssued` | `$certificate` (`UserCertificate`) | New certificate created for a user |
| `DocumentSigned` | `$signature` (`Signature`) | Signing job completes successfully |
| `SignatureRevoked` | `$signature` (`Signature`) | `revoke()` is called |

### Listening to events

```php
// app/Providers/EventServiceProvider.php

use Kukux\DigitalSignature\Events\DocumentSigned;
use Kukux\DigitalSignature\Events\SignatureRevoked;

protected $listen = [
    DocumentSigned::class => [
        SendSignedDocumentEmail::class,
    ],
    SignatureRevoked::class => [
        NotifyAdminOfRevocation::class,
    ],
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
});
```

---

## Signature statuses

| Status | Meaning |
|---|---|
| `pending` | Image stored, job queued |
| `signed` | PDF signed, job completed |
| `revoked` | Manually invalidated |
| `failed` | Job failed after 3 retries |

---

## Queue configuration

The job retries 3 times with a 120-second timeout per attempt.

```php
// config/signature.php
'queue'            => env('SIGNATURE_QUEUE', 'default'),
'queue_connection' => env('SIGNATURE_QUEUE_CONNECTION', null),
```

To handle failed jobs, listen to Laravel's `Illuminate\Queue\Events\JobFailed`:

```php
Queue::failing(function (JobFailed $event) {
    if ($event->job->resolveName() === \Kukux\DigitalSignature\Jobs\EmbedSignatureJob::class) {
        // notify, log, etc.
    }
});
```

The job's `failed()` method automatically sets the signature `status` to `failed`.
