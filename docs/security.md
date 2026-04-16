# Security Features

This document covers every security mechanism added to the plugin, what problem each one solves, how to configure it, and how to handle it in your application code.

---

## Overview of changes

| Feature | File changed | Default |
|---|---|---|
| Real PKCS#7 cryptographic signature in PDF | `FpdiDriver` | Always on |
| DocMDP — post-signing modification detection | `FpdiDriver` | Always on |
| Forged/screenshot signature detection | `DuplicateSignatureGuard` | Always on |
| Document integrity hashes (before + after) | `DocumentIntegrity` | Always on |
| Unique UUID per signing request | `SignatureManager` | Always on |
| Machine-bound PNG metadata (HMAC-signed tEXt chunks) | `PngMetaEmbedder` / `SignatureMetadataService` | Always on |
| Machine lock — reject re-upload from different machine | `SignatureMetadataService` | Opt-in |
| CRL certificate revocation check | `CrlValidator` | Opt-in |
| RFC 3161 trusted timestamp (TSA) | `FpdiDriver` | Opt-in |

The first six are always active. Machine lock, CRL, and TSA require explicit configuration.

---

## 1. Real PKCS#7 cryptographic signature

### What changed

Previously `FpdiDriver` stamped the signature image visually onto the PDF. The certificate was loaded but never used — the output was not cryptographically signed.

`FpdiDriver` now calls TCPDF's `setSignature()` after all pages are built and before `Output()`. This embeds a PKCS#7 signature dictionary directly in the PDF byte stream, covering all content.

### What this means

- Adobe Reader, Preview, and any PDF/A validator can verify the signature
- The signer's X.509 identity is bound to the document
- Any modification to the file after signing breaks the signature and is reported by PDF readers

### No configuration required

This is wired automatically. As long as the user has a valid certificate (created on first sign), the PKCS#7 block is embedded.

---

## 2. DocMDP — document modification detection

### What changed

`cert_type = 2` is passed to `setSignature()`. This sets ISO 32000-1 §12.8.2.2 DocMDP P=2 on the signed PDF.

### Permission levels

| Level | What is allowed after signing |
|---|---|
| P=1 | Nothing — no changes whatsoever |
| P=2 (used here) | Form field updates and additional co-signatures only |
| P=3 | Form fields, annotations, and co-signatures |

### What this means

If someone opens the signed PDF, edits a page, and saves it, any conforming PDF reader will show the signature as invalid. The violation type (page edit, annotation, structural change) is reported.

### No configuration required

This is fixed at P=2. If you need a different level, pass a different `cert_type` when calling `FpdiDriver::sign()` directly.

---

## 3. Forged/screenshot signature detection

### What changed

`DuplicateSignatureGuard::check()` is called inside `SignatureManager::store()` before any record is written.

### How it works

Every signature image is hashed with SHA-256. Before storing a new submission, the guard queries the `signatures` table for any row where:

- `image_hash` matches the submitted image
- `user_id` is different from the submitting user
- `status` is `pending` or `signed`

If a conflict is found, `ForgedSignatureException` is thrown and the submission is rejected. Nothing is written to disk or the database.

### Limitation

This catches exact copies (a screenshot saved as PNG and uploaded). It does not detect a lightly cropped or recoloured screenshot. For higher assurance, disable the upload tab entirely:

```php
SignaturePad::make('signature_data')->withoutUploadTab()
```

### Handling the exception in Filament

```php
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;
use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;

SignDocumentAction::make()
    ->action(function (array $data) {
        try {
            // default action logic runs here
        } catch (ForgedSignatureException $e) {
            Filament\Notifications\Notification::make()
                ->title('Signature rejected')
                ->body('The submitted image matches another user\'s signature.')
                ->danger()
                ->send();
        }
    })
```

Alternatively, register it globally in `app/Exceptions/Handler.php`:

```php
use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;

$this->renderable(function (ForgedSignatureException $e) {
    return response()->json(['message' => $e->getMessage()], 422);
});
```

---

## 4. Document integrity hashes

### What changed

`DocumentIntegrity::hash()` is called at two points in the signing lifecycle. Two new columns store the results.

| Column | Table | Populated when |
|---|---|---|
| `document_hash` | `signatures` | `store()` — before signing |
| `signed_document_hash` | `signatures` | `embedAndFinalize()` — after signing |

### What each hash proves

`document_hash` — the SHA-256 of the original PDF at the moment the signer submitted the form. If the PDF on disk changes after the signer clicked Submit but before the job ran, you can detect that.

`signed_document_hash` — the SHA-256 of the signed PDF file written to disk. Store this hash somewhere external (a separate database, an audit log service) and you can verify at any future point that the file has not been replaced or modified.

### Verifying integrity in code

```php
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Models\Signature;

$signature = Signature::find($id);

$currentHash = hash('sha256', Storage::disk(config('signature.storage_disk'))
    ->get($signature->signed_document_path));

if ($currentHash !== $signature->signed_document_hash) {
    // The signed PDF has been tampered with after it was produced
}
```

### Migration required

Run after pulling this update:

```bash
php artisan migrate
```

The migration adds `uuid`, `document_hash`, and `signed_document_hash` to the `signatures` table. All three are nullable so existing rows are unaffected.

---

## 5. Unique UUID per signing request

### What changed

`SignatureManager::store()` now generates a UUID (RFC 4122 v4) and stores it in `signatures.uuid` for every new record.

### How to use it

```php
$signature = $manager->store(...);

// Use as a safe public-facing token
$signingUrl = route('sign.verify', ['token' => $signature->uuid]);

// Look up by UUID without exposing the auto-increment ID
$signature = Signature::where('uuid', $request->token)->firstOrFail();
```

The UUID is stable for the lifetime of the record and does not change on status transitions.

---

## 6. Machine-bound PNG metadata

### What it does

Every signature image stored by `SignatureManager::store()` is enriched with four HMAC-signed `tEXt` chunks injected at the binary PNG level. These chunks bind the image to the specific user and machine (browser + IP) that created it:

| Chunk key | Value |
|---|---|
| `Sig-User-Id` | The authenticated user's integer ID |
| `Sig-Machine-Hash` | SHA-256 of `userId\|userAgent\|ip\|deviceFp` |
| `Sig-Timestamp` | ISO 8601 UTC datetime of embedding |
| `Sig-Hmac` | `HMAC-SHA256(userId\|machineHash\|timestamp, APP_KEY)` |

The HMAC is keyed with `APP_KEY`, so only your server can produce a valid one.

### How browser fingerprinting works

When the signature field renders, a small JS module (`resources/js/utils/machineFingerprint.js`) runs in the browser and collects:

- User-Agent, language, platform, hardware concurrency
- Screen dimensions and colour depth
- Timezone
- Canvas 2D rendering fingerprint (font metrics)
- WebGL renderer string

These are hashed with `SubtleCrypto.digest('SHA-256', ...)` to produce a 64-character hex token. The token is cached in `localStorage` and posted to `/signature/device-fingerprint` which stores it in the Laravel session. During form submission `SignDocumentAction` reads it back via `session()->pull('sig_device_fp', '')`.

### Validation on every upload

When a user uploads a signature image (as opposed to drawing one fresh), `SignatureMetadataService::validateIfPresent()` is called before the file is stored:

- **No HMAC present** — image is fresh (just drawn), pass through
- **HMAC invalid** — `ForgedSignatureException` — image was tampered with or forged on another server
- **User ID mismatch** — `ForgedSignatureException` — image belongs to a different user
- **Machine hash mismatch (when lock enabled)** — `MachineBindingException` — image was created on a different machine

### Enabling machine lock (opt-in)

By default, only the HMAC and user ID are verified. To also require the same machine:

In `.env`:

```bash
SIGNATURE_MACHINE_LOCK=true
```

Or in `config/signature.php`:

```php
'metadata' => [
    'enforce_machine_lock' => true,
],
```

When enabled, uploading a signature PNG from a different browser session or device throws `MachineBindingException`.

### Handling exceptions

```php
use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;
use Kukux\DigitalSignature\Exceptions\MachineBindingException;

// In app/Exceptions/Handler.php

$this->renderable(function (ForgedSignatureException $e) {
    return response()->json(['message' => 'Signature rejected: forged image'], 422);
});

$this->renderable(function (MachineBindingException $e) {
    return response()->json(['message' => 'Signature must be re-drawn on this device'], 422);
});
```

### JPEG images

JPEG does not support `tEXt` chunks. Any JPEG uploaded via the upload tab is automatically converted to PNG via GD before metadata is embedded. The stored file will always have a `.png` extension and be a valid PNG regardless of what the user uploaded.

### Inspecting embedded metadata in code

```php
use Kukux\DigitalSignature\Security\PngMetaEmbedder;

$embedder = app(PngMetaEmbedder::class);

$pngBytes = Storage::disk(config('signature.storage_disk'))
    ->get($signature->image_path);

$chunks = $embedder->read($pngBytes);
// ['Sig-User-Id' => '42', 'Sig-Machine-Hash' => 'abc...', ...]
```

### Migration required

```bash
php artisan migrate
```

Migration `2024_01_01_000005_add_machine_fingerprint_to_signatures_table` adds `machine_fingerprint varchar(64) nullable` to the `signatures` table.

---

## 8. CRL validation (opt-in)

### What it does

Before the signing job embeds the certificate into the PDF, `CrlValidator` checks whether the certificate has been revoked by querying the CRL Distribution Points listed in the certificate itself.

### Requirements

- `openssl` CLI binary in `PATH` (same machine running the queue worker)
- Laravel cache driver configured (file, Redis, etc.)
- Only applies to CA-signed certificates that include CDP extensions. Self-signed certificates issued by `OpenSslDriver` have no CDP and are silently skipped.

### Enabling

In `.env`:

```bash
SIGNATURE_CRL_ENABLED=true
```

Or in `config/signature.php`:

```php
'crl' => [
    'enabled'         => true,
    'cache_ttl_hours' => 24,   // how long downloaded CRLs are cached
],
```

### What happens when a certificate is revoked

`CertificateRevokedException` is thrown inside `embedAndFinalize()`. The job catches no exceptions, so `EmbedSignatureJob::failed()` fires, setting `status = failed` on the signature record.

Handle the failure notification in your application:

```php
use Kukux\DigitalSignature\Exceptions\CertificateRevokedException;

// In a queued job listener or failed-job handler
if ($exception instanceof CertificateRevokedException) {
    // Notify the user their certificate is revoked
    // Prompt them to re-issue via CertificateService::issue()
}
```

### Cache behaviour

Each CRL URL is cached using `Cache::remember()` with the key `sig_crl_{md5(url)}`. The TTL is `signature.crl.cache_ttl_hours` seconds. To force a fresh download during development:

```bash
php artisan cache:clear
```

---

## 9. TSA trusted timestamp (opt-in)

### What it does

A Timestamp Authority (TSA) provides a cryptographically signed timestamp from a trusted third party, independent of the server clock. The timestamp is embedded inside the PKCS#7 signature block following RFC 3161.

PDF readers that support PAdES-T show the verified signing time. Even if the signing certificate expires later, the timestamp proves it was valid at the time of signing.

### Enabling

In `.env`:

```bash
SIGNATURE_TSA_URL=https://freetsa.org/tsr
```

Or in `config/signature.php`:

```php
'tsa' => [
    'url' => 'https://freetsa.org/tsr',
],
```

Free public TSA endpoints:

| Endpoint | Notes |
|---|---|
| `https://freetsa.org/tsr` | Free, no authentication |
| `http://timestamp.digicert.com` | DigiCert public TSA |
| `http://tsa.starfieldtech.com` | Starfield |

### What TCPDF does with the URL

TCPDF contacts the TSA during `Output()`, receives a `TimeStampToken`, and embeds it in the `/Contents` of the signature dictionary. No additional code is required in your application.

### Verifying the timestamp

Open the signed PDF in Adobe Acrobat or run:

```bash
openssl ts -verify -in signed.pdf -CAfile ca-bundle.crt
```

---

## New environment variables summary

```bash
# Bind uploaded signatures to the machine that created them
SIGNATURE_MACHINE_LOCK=false

# Enable CRL revocation checking (requires openssl CLI in PATH)
SIGNATURE_CRL_ENABLED=false

# RFC 3161 TSA endpoint for trusted timestamps (null = disabled)
SIGNATURE_TSA_URL=
```

---

## New exception reference

| Exception | Namespace | Thrown in | Meaning |
|---|---|---|---|
| `ForgedSignatureException` | `Kukux\DigitalSignature\Exceptions` | `SignatureManager::store()` | Image hash matches a different user's signature, or embedded HMAC/user-id is invalid |
| `MachineBindingException` | `Kukux\DigitalSignature\Exceptions` | `SignatureManager::store()` | Machine lock is enabled and the image was created on a different machine |
| `CertificateRevokedException` | `Kukux\DigitalSignature\Exceptions` | `SignatureManager::embedAndFinalize()` | Certificate serial is on a downloaded CRL |

All three extend `\RuntimeException` and carry a descriptive message. None are caught inside the plugin — your application decides how to surface them.

---

## New service reference

### `DuplicateSignatureGuard`

```php
use Kukux\DigitalSignature\Security\DuplicateSignatureGuard;

app(DuplicateSignatureGuard::class)->check(
    imageHash: hash('sha256', $imageBytes),
    userId:    $user->id,
);
```

Call this yourself if you store signatures outside of `SignatureManager::store()`.

### `DocumentIntegrity`

```php
use Kukux\DigitalSignature\Security\DocumentIntegrity;

$hash = app(DocumentIntegrity::class)->hash('path/to/file.pdf');
```

Uses the configured `storage_disk` and `hash_algo`. Throws `\RuntimeException` if the file does not exist on the disk.

### `CrlValidator`

```php
use Kukux\DigitalSignature\Security\CrlValidator;

// $certData = output of openssl_pkcs12_read()
app(CrlValidator::class)->validate($certData);
```

No-op when disabled via config. Silently skips certs with no CDP extension.

### `PngMetaEmbedder`

```php
use Kukux\DigitalSignature\Security\PngMetaEmbedder;

$embedder = app(PngMetaEmbedder::class);

// Inject tEXt chunks into a PNG
$enriched = $embedder->embed($pngBytes, [
    'Sig-User-Id'     => '42',
    'Sig-Machine-Hash' => 'abc...',
    'Sig-Timestamp'   => '2025-01-01T00:00:00Z',
    'Sig-Hmac'        => 'def...',
]);

// Read tEXt chunks back out
$chunks = $embedder->read($enriched);

// Convert JPEG to PNG first
$pngBytes = $embedder->normalizeToPng($jpegBytes);
```

### `SignatureMetadataService`

```php
use Kukux\DigitalSignature\Security\SignatureMetadataService;

$svc = app(SignatureMetadataService::class);

// Embed metadata into raw image bytes (JPEG auto-converted to PNG)
$enriched = $svc->embedIntoImage($rawBytes, $userId, $deviceFp);

// Validate metadata on upload (throws ForgedSignatureException or MachineBindingException)
$svc->validateIfPresent($rawBytes, $userId, $deviceFp);
```

Both methods are called automatically inside `SignatureManager::store()`. Use them directly only if you build a custom storage pipeline.

---

## Database columns added

Migration: `2024_01_01_000004_add_security_columns_to_signatures_table`

| Column | Type | Nullable | Description |
|---|---|---|---|
| `uuid` | `char(36)` unique | yes (existing rows) | RFC 4122 v4 UUID per signing request |
| `document_hash` | `varchar(64)` | yes | SHA-256 of source PDF before signing |
| `signed_document_hash` | `varchar(64)` | yes | SHA-256 of signed PDF after signing |

Migration: `2024_01_01_000005_add_machine_fingerprint_to_signatures_table`

| Column | Type | Nullable | Description |
|---|---|---|---|
| `machine_fingerprint` | `varchar(64)` | yes | SHA-256 of `userId\|UA\|IP\|deviceFp` at signing time |
