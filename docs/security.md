# Security Features

This document covers every security mechanism in the plugin, what problem each one solves, how to configure it, and how to handle it in your application code.

---

## Overview

| Feature | Default |
|---|---|
| PKCS#7 cryptographic signature embedded in PDF | Always on |
| DocMDP P=2 — post-signing modification detection | Always on |
| Forged/screenshot signature detection | Always on |
| Document integrity hashes (before + after signing) | Always on |
| Unique UUID per signing request | Always on |
| HMAC-signed PNG metadata (tEXt + XMP chunks) | Always on |
| Signer identity embedded in PNG (name + email) | Always on |
| XMP metadata visible in macOS Preview & Windows Explorer | Always on |
| DB cross-validation on re-upload (Sig-Record-Id) | Always on |
| Machine lock — reject re-upload from different device | `SIGNATURE_MACHINE_LOCK=true` (default) |
| CRL certificate revocation check | `SIGNATURE_CRL_ENABLED=false` |
| RFC 3161 trusted timestamp via TSA | `SIGNATURE_TSA_URL=` (disabled) |

---

## 1. PKCS#7 cryptographic signature

`FpdiDriver` calls TCPDF's `setSignature()` after all pages are built and before `Output()`. This embeds a PKCS#7 signature dictionary directly in the PDF byte stream, covering all content.

**What this means:**
- Adobe Reader, Preview, and any PDF/A validator can verify the signature
- The signer's X.509 identity is bound to the document
- Any modification to the file after signing breaks the signature

No configuration required. As long as the user has a valid certificate (created on first sign), the PKCS#7 block is embedded automatically.

---

## 2. DocMDP — document modification detection

`cert_type = 2` is passed to `setSignature()`. This sets ISO 32000-1 §12.8.2.2 DocMDP P=2 on the signed PDF.

| Level | What is allowed after signing |
|---|---|
| P=1 | Nothing — no changes whatsoever |
| P=2 (default) | Form field updates and additional co-signatures only |
| P=3 | Form fields, annotations, and co-signatures |

If someone opens the signed PDF, edits a page, and saves it, any conforming PDF reader will show the signature as invalid.

---

## 3. Forged/screenshot signature detection

`DuplicateSignatureGuard::check()` is called inside `SignatureManager::store()` before any record is written.

Every signature image is hashed with SHA-256. Before storing a new submission, the guard queries the `signatures` table for any row where:
- `image_hash` matches the submitted image
- `user_id` is different from the submitting user
- `status` is `pending` or `signed`

If a conflict is found, `ForgedSignatureException` is thrown and the submission is rejected.

**Limitation:** This catches exact copies. It does not detect a lightly cropped or recoloured screenshot. For higher assurance, disable the upload tab entirely:

```php
SignaturePad::make('signature_data')->withoutUploadTab()
```

**Automatic handling:** signature registration flows should catch `ForgedSignatureException` and show a clear rejection message. `SignDocumentAction` signs with an already registered signature, so upload validation normally happens before that action is used.

---

## 4. Document integrity hashes

`DocumentIntegrity::hash()` is called at two points in the signing lifecycle:

| Column | Table | Populated when |
|---|---|---|
| `document_hash` | `signatures` | `store()` — before signing |
| `signed_document_hash` | `signatures` | `embedAndFinalize()` — after signing |

`document_hash` — SHA-256 of the original PDF at the moment the signer submitted the form.

`signed_document_hash` — SHA-256 of the signed PDF written to disk. Store this hash somewhere external to verify at any future point that the file has not been replaced or modified.

**Verifying integrity:**

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

---

## 5. Unique UUID per signing request

`SignatureManager::store()` generates a UUID (RFC 4122 v4) and stores it in `signatures.uuid` for every new record. The UUID is also **embedded inside the PNG metadata** (see §6 below), creating a link between the image file and its database record.

```php
$signature = $manager->store(...);

// Use as a safe public-facing token
$signingUrl = route('sign.verify', ['token' => $signature->uuid]);

// Look up by UUID without exposing the auto-increment ID
$signature = Signature::where('uuid', $request->token)->firstOrFail();
```

---

## 6. PNG metadata: tEXt chunks + XMP

Every signature image stored by `SignatureManager::store()` receives two types of embedded metadata:

### tEXt chunks (machine-readable, HMAC-protected)

Six `tEXt` chunks are injected at the binary PNG level:

| Chunk key | Value |
|---|---|
| `Sig-User-Id` | The authenticated user's integer ID |
| `Sig-Signer-Name` | Signer's display name + email, e.g. `"Jane Doe <jane@example.com>"` |
| `Sig-Machine-Hash` | SHA-256 of `userId\|userAgent\|deviceFp` |
| `Sig-Timestamp` | ISO 8601 UTC datetime of embedding |
| `Sig-Record-Id` | UUID of the `signatures` DB record |
| `Sig-Hmac` | HMAC-SHA256 over all five values above, keyed by `APP_KEY` |

The HMAC covers `userId|signerName|machineHash|timestamp|recordId`, so any tampering with any field invalidates it.

### XMP (iTXt chunk, human-readable)

An `iTXt` chunk with keyword `XML:com.adobe.xmp` is also embedded. This makes metadata visible without a hex editor:

- **macOS Preview** — open the PNG, go to Tools → Inspector (⌘I), select the "More Info" tab
- **Windows File Explorer** — right-click → Properties → Details tab → "Title", "Authors", "Comments"
- **ExifTool** — `exiftool signature.png`

The XMP fields map to standard Dublin Core properties for maximum OS compatibility:

| XMP field | Value | Visible as |
|---|---|---|
| `dc:title` | `"Signature — <signer name>"` | Windows "Title" / macOS "Description" |
| `dc:creator` | Signer's display name (array) | Windows "Authors" |
| `dc:description` | Signed-at + signed-by text | Windows "Comments" |
| `xmp:CreateDate` | ISO 8601 timestamp | — |
| `sig:SignerName` | Full `Name <email>` | — |
| `sig:UserId` | User ID | — |
| `sig:MachineHash` | Machine fingerprint | — |
| `sig:Hmac` | HMAC for integrity | — |

**Note:** Windows requires `rdf:Alt`/`rdf:Seq` container elements for DC fields. Plain string values are silently ignored by Windows Shell Property System. The embedder generates fully spec-compliant RDF.

### Machine fingerprint formula

The machine hash (`Sig-Machine-Hash`) is:

```
SHA-256(userId | userAgent | deviceFp)
```

IP address is intentionally excluded to avoid false positives from VPN switches, mobile networks, and NAT changes. The `deviceFp` is generated client-side from browser properties (canvas fingerprint, WebGL renderer, screen dimensions, etc.) and does not change with network changes.

### Inspecting embedded metadata in code

```php
use Kukux\DigitalSignature\Security\PngMetaEmbedder;

$embedder = app(PngMetaEmbedder::class);

$pngBytes = Storage::disk(config('signature.storage_disk'))
    ->get($signature->image_path);

$chunks = $embedder->read($pngBytes);
// [
//   'Sig-User-Id'     => '42',
//   'Sig-Signer-Name' => 'Jane Doe <jane@example.com>',
//   'Sig-Machine-Hash' => 'abc...',
//   'Sig-Timestamp'   => '2025-01-01T00:00:00Z',
//   'Sig-Record-Id'   => 'uuid-here',
//   'Sig-Hmac'        => 'def...',
// ]
```

### JPEG images

JPEG does not support `tEXt` chunks. Any JPEG uploaded via the upload tab is automatically converted to PNG via GD before metadata is embedded. The stored file will always be a valid PNG regardless of what the user uploaded.

---

## 7. Validation on re-upload: two independent layers

When a user uploads a signature image (rather than drawing one fresh), the plugin runs two independent validation layers before accepting it.

### Layer 1 — HMAC metadata check

`SignatureMetadataService::validateIfPresent()` reads the embedded tEXt chunks and verifies:

| Check | Failure | Exception |
|---|---|---|
| No metadata present | Image is fresh (just drawn) | — (pass through) |
| HMAC invalid | Image was tampered with or forged | `ForgedSignatureException` |
| `Sig-User-Id` doesn't match authenticated user | Image belongs to a different user | `ForgedSignatureException` |
| `Sig-Machine-Hash` doesn't match current device | Image was created on a different machine (when lock enabled) | `MachineBindingException` |

### Layer 2 — DB cross-validation

If `Sig-Record-Id` is present in the PNG, `SignatureManager` looks up the original `Signature` record by UUID and independently verifies:

| Check | Failure | Exception |
|---|---|---|
| No DB record found for UUID | UUID is fabricated | `ForgedSignatureException` |
| DB `user_id` doesn't match authenticated user | Image belongs to a different user | `ForgedSignatureException` |
| Signature is revoked | Original signature was invalidated | `ForgedSignatureException` |
| DB `machine_fingerprint` doesn't match current device | Device changed (independent of PNG check) | `MachineBindingException` |

The DB check uses the same `SHA-256(userId|userAgent|deviceFp)` formula as the PNG `Sig-Machine-Hash`, so both layers agree on what "same machine" means. Compromising one layer does not defeat the other.

### Machine lock configuration

Machine lock (Layer 1 + Layer 2 device check) is enabled by default:

```bash
SIGNATURE_MACHINE_LOCK=true
```

To disable (HMAC and user-ID checks still run, only device binding is skipped):

```bash
SIGNATURE_MACHINE_LOCK=false
```

Or in `config/signature.php`:

```php
'metadata' => [
    'enforce_machine_lock' => false,
],
```

### Exception handling in signing flows

Signature registration can throw these exceptions while storing uploaded signature images:

| Exception | Notification title |
|---|---|
| `MachineBindingException` | "Signature rejected — wrong device" |
| `ForgedSignatureException` | "Invalid signature image" |

To handle them outside of Filament (e.g. in an API controller):

```php
use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;
use Kukux\DigitalSignature\Exceptions\MachineBindingException;

try {
    $manager->store(userId: $user->id, input: $data, source: 'upload', ...);
} catch (ForgedSignatureException $e) {
    return response()->json(['message' => 'Signature rejected: forged image'], 422);
} catch (MachineBindingException $e) {
    return response()->json(['message' => 'Signature must be re-drawn on this device'], 422);
}
```

---

## 8. CRL validation (opt-in)

Before the signing job embeds the certificate into the PDF, `CrlValidator` checks whether the certificate has been revoked by querying the CRL Distribution Points listed in the certificate itself.

**Requirements:**
- `openssl` CLI binary in `PATH`
- Laravel cache driver configured (file, Redis, etc.)
- Only applies to CA-signed certificates with CDP extensions. Self-signed certificates are silently skipped.

**Enabling:**

```bash
SIGNATURE_CRL_ENABLED=true
```

```php
'crl' => [
    'enabled'         => true,
    'cache_ttl_hours' => 24,
],
```

When a certificate is revoked, `CertificateRevokedException` is thrown inside `embedAndFinalize()`. The job sets `status = failed` on the signature record.

Each CRL URL is cached with the key `sig_crl_{md5(url)}`. To force a fresh download:

```bash
php artisan cache:clear
```

---

## 9. TSA trusted timestamp (opt-in)

A Timestamp Authority (TSA) provides a cryptographically signed timestamp from a trusted third party, independent of the server clock. The timestamp is embedded inside the PKCS#7 block following RFC 3161.

Even if the signing certificate expires later, the timestamp proves it was valid at the time of signing. PDF readers that support PAdES-T show the verified signing time.

**Enabling:**

```bash
SIGNATURE_TSA_URL=https://freetsa.org/tsr
```

Free public endpoints:

| Endpoint | Provider |
|---|---|
| `https://freetsa.org/tsr` | FreeTSA |
| `http://timestamp.digicert.com` | DigiCert |
| `http://tsa.starfieldtech.com` | Starfield |

TCPDF contacts the TSA during `Output()` and embeds the `TimeStampToken` in the `/Contents` of the signature dictionary automatically.

---

## Exception reference

| Exception | Thrown in | Meaning |
|---|---|---|
| `ForgedSignatureException` | `SignatureManager::store()` | HMAC/user-id invalid, or DB cross-validation failed |
| `MachineBindingException` | `SignatureManager::store()` | Machine lock enabled and device doesn't match (PNG or DB layer) |
| `CertificateRevokedException` | `SignatureManager::embedAndFinalize()` | Certificate serial is on a downloaded CRL |

All three extend `\RuntimeException`. `SignDocumentAction` signs with an existing signature record; custom registration flows that call `SignatureManager::store()` should handle `ForgedSignatureException` and `MachineBindingException` and show the user a clear rejection message.

---

## Service reference

### `PngMetaEmbedder`

```php
use Kukux\DigitalSignature\Security\PngMetaEmbedder;

$embedder = app(PngMetaEmbedder::class);

// Inject tEXt chunks and XMP into a PNG
$enriched = $embedder->embed($pngBytes, [
    'Sig-User-Id'     => '42',
    'Sig-Signer-Name' => 'Jane Doe <jane@example.com>',
    'Sig-Machine-Hash' => 'abc...',
    'Sig-Timestamp'   => '2025-01-01T00:00:00Z',
    'Sig-Record-Id'   => 'uuid-here',
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

// Embed metadata (JPEG auto-converted to PNG)
$enriched = $svc->embedIntoImage($rawBytes, $userId, $deviceFp, $signerName, $recordId);

// Validate on upload (throws ForgedSignatureException or MachineBindingException)
$svc->validateIfPresent($rawBytes, $userId, $deviceFp);
```

Both methods are called automatically inside `SignatureManager::store()`. Use them directly only if you build a custom storage pipeline.

### `DuplicateSignatureGuard`

```php
use Kukux\DigitalSignature\Security\DuplicateSignatureGuard;

app(DuplicateSignatureGuard::class)->check(
    imageHash: hash('sha256', $imageBytes),
    userId:    $user->id,
);
```

### `DocumentIntegrity`

```php
use Kukux\DigitalSignature\Security\DocumentIntegrity;

$hash = app(DocumentIntegrity::class)->hash('path/to/file.pdf');
```

### `CrlValidator`

```php
use Kukux\DigitalSignature\Security\CrlValidator;

// $certData = output of openssl_pkcs12_read()
app(CrlValidator::class)->validate($certData);
```

---

## Database columns

Migration `2024_01_01_000004_add_security_columns_to_signatures_table`:

| Column | Type | Nullable | Description |
|---|---|---|---|
| `uuid` | `char(36)` unique | yes | RFC 4122 v4 UUID, also embedded in PNG as `Sig-Record-Id` |
| `document_hash` | `varchar(64)` | yes | SHA-256 of source PDF before signing |
| `signed_document_hash` | `varchar(64)` | yes | SHA-256 of signed PDF after signing |

Migration `2024_01_01_000005_add_machine_fingerprint_to_signatures_table`:

| Column | Type | Nullable | Description |
|---|---|---|---|
| `machine_fingerprint` | `varchar(64)` | yes | SHA-256 of `userId\|userAgent\|deviceFp` at signing time |

The `machine_fingerprint` column stores the same formula used in `Sig-Machine-Hash`, enabling the DB cross-validation layer to independently verify device binding without relying on the PNG's own HMAC.
