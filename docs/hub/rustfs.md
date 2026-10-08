# RustFS: signature images in object storage

UPLB runs RustFS (`rustfs.uplb.edu.ph`), an S3-compatible object store. The
hub keeps master specimen images there, and each client app keeps its mirrors
there, so signature images do not sit on every app server's disk (plan A12,
remedy R4). **Keys and certificates never go there.**

## Buckets

| Bucket | Holds | Who can read / write |
|---|---|---|
| `signature-hub` | Master specimen images (hub) | Hub key only. Versioning on, server-side encryption on, no anonymous policy |
| `signature-mirrors` | App mirrors, one prefix per app: `{app_id}/{hub_uuid}.png` | Each app's key: Get/Put/Delete on its own prefix only. Hub key: List/Delete on all prefixes (revocation backstop) |

Every object carries `x-amz-meta-sha256` (hex SHA-256 of the image).

## Laravel disk

In `config/filesystems.php` of the hub and of each app (needs
`league/flysystem-aws-s3-v3`):

```
'rustfs' => [
    'driver'                  => 's3',
    'endpoint'                => env('RUSTFS_ENDPOINT', 'https://rustfs.uplb.edu.ph'),
    'use_path_style_endpoint' => true,
    'key'                     => env('RUSTFS_ACCESS_KEY'),
    'secret'                  => env('RUSTFS_SECRET_KEY'),
    'region'                  => env('RUSTFS_REGION', 'us-east-1'),
    'bucket'                  => env('RUSTFS_BUCKET'),   // signature-hub | signature-mirrors
    'root'                    => env('RUSTFS_PREFIX'),   // app id, apps only
    'visibility'              => 'private',
    'throw'                   => true,
],
```

Then `SIGNATURE_HUB_MIRROR_DISK=rustfs` in an app, or `hub.specimen_disk`
on the hub. Signed PDFs and the document of record stay on
`signature.storage_disk` (local unless the app chooses otherwise).

## LocalCopy

TCPDF, FPDI and `getimagesize()` read files from a path. An S3 disk has none,
and `Storage::disk('s3')->path()` does not throw — it returns the object key,
which then silently "does not exist". `Support\LocalCopy` bridges that:

```php
use Kukux\DigitalSignature\Support\LocalCopy;

$pdfBytes = LocalCopy::of('rustfs', $mirror->image_path, function (string $localPath) {
    // $localPath is a real file for exactly as long as this runs.
    return stampWith($localPath);
}, expectedSha256: $mirror->hub_image_hash);
```

- **Local disk** (Flysystem's local adapter): the real path is passed
  straight through. Nothing is copied; behaviour is what it always was.
- **Any other disk:** the file is streamed to a temp file (extension kept),
  the callback runs, and the temp file is deleted in `finally`, whether the
  callback returns or throws. Nothing stays on the app server at rest.
- **Integrity (R5):** with `expectedSha256`, the copy is hashed before the
  callback sees it. Without one, on an S3 disk the object's
  `x-amz-meta-sha256` is read (`headObject`) and used instead; an object
  without that metadata is not treated as tampering. A mismatch throws
  `Support\LocalCopyIntegrityException` (`path`, `expectedSha256`,
  `actualSha256`) and the callback never runs. Treat it as a tamper signal:
  re-pull from the hub and alert — do not retry.

Routed through `LocalCopy`:

| Where | Reads |
|---|---|
| `FpdiDriver::sign()` | the source PDF and the signature image |
| `TcpdfDriver::sign()` | the signature image |
| `DeferredPdfSigner::prepare()` | the source PDF (storage disk) and the image (`$imageDisk`, default storage disk) |
| `DeferredPdfSigner::inject()` | reads and writes through the Storage API only |

Everything up to TCPDF's `Output()` happens inside the callback, because
FPDI keeps reading the source PDF until the document is closed.

Not routed, on purpose:

- `Pdf\BladePdfTemplate::renderTo()` calls `$disk->path()` to give the PDF
  renderer (DomPDF / Browsershot / …) a path to **write** to, caches the
  result there, and returns that absolute path as the `PdfTemplate`
  contract requires. That is a write-and-cache on the storage disk, not an
  image read, so it needs a local `storage_disk`. Keep `storage_disk` local
  and put only the image disks on RustFS.
- `Services\PdfSignerService` makes no `path()` call itself; it passes
  disk-relative paths to the drivers above.
- `CertificateService` must keep a local disk (keys stay local, KMS or HSM).

## Verify on our RustFS deployment (phase 0)

None of this is tested against RustFS itself here — the tests use a fake
non-local disk. Before relying on it, check on `rustfs.uplb.edu.ph`:

- [ ] **Per-prefix access-key policies:** an app key can Get/Put/Delete under
  `signature-mirrors/{its app id}/` and gets 403 under another app's prefix
  and on `signature-hub`. If per-prefix policies are not available, fall
  back to one bucket per app.
- [ ] **Hub key backstop:** the hub key can List and Delete across all
  prefixes of `signature-mirrors`.
- [ ] **Versioning** on `signature-hub`: overwrite an object, fetch the old
  version by id.
- [ ] **Server-side encryption** on both buckets.
- [ ] **Anonymous deny:** an unauthenticated `GET` of a known object returns
  403 (the install step's probe does exactly this).
- [ ] **`x-amz-meta-sha256` round-trips:** Put with
  `['Metadata' => ['sha256' => …]]`, `headObject` returns it lower-cased
  under `Metadata`.
- [ ] **Presigned URL expiry** is enforced.
- [ ] **Access logs** are kept and say which key did what.
- [ ] **Path-style addressing** works (`use_path_style_endpoint => true`).
