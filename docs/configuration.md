# Configuration

After publishing, the config file lives at `config/signature.php`.

---

## Certificate driver

```php
'cert_driver' => env('SIGNATURE_CERT_DRIVER', 'openssl'),
```

| Value | Description |
|---|---|
| `openssl` | Self-signed via PHP's `openssl_*` extension (default) |
| `cfssl` | Issues certificates from a running CFSSL server |

### OpenSSL options

```php
'openssl' => [
    'digest_alg'       => 'sha256',
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'cert_lifetime'    => 3650,   // days before expiry
    'ca_cert_path'     => storage_path('app/certs/ca.crt'),  // optional
    'ca_key_path'      => storage_path('app/certs/ca.key'),  // optional
],
```

When `ca_cert_path` and `ca_key_path` point to valid files, certificates are CA-signed. Leave absent for development (self-signed).

### CFSSL options

```php
'cfssl' => [
    'host'    => env('CFSSL_HOST', 'http://localhost:8888'),
    'profile' => env('CFSSL_PROFILE', 'client'),
],
```

---

## PDF driver

```php
'pdf_driver' => env('SIGNATURE_PDF_DRIVER', 'fpdi'),
```

| Value | Description |
|---|---|
| `fpdi` | FPDI + TCPDF — imports existing PDF pages, embeds PKCS#7 signature (default) |
| `tcpdf` | Pure TCPDF |

---

## Storage

```php
'storage_disk'     => env('SIGNATURE_DISK', 'local'),
'certs_path'       => 'certs',         // PFX certificate files
'signatures_path'  => 'signatures',    // raw signature images
'signed_docs_path' => 'signed-docs',   // completed signed PDFs
```

All paths are relative to the disk root. Any Laravel disk driver works (`local`, `s3`, etc.).

---

## Image constraints

Applied both client-side (JS) and server-side (PHP) during upload validation.

```php
'image' => [
    'max_kb'        => 512,
    'allowed_mimes' => ['image/png', 'image/jpeg'],
    'canvas_width'  => 600,
    'canvas_height' => 200,
],
```

---

## Admin Resource

Controls whether and how the built-in Signatures resource appears in the panel.

```php
'resource' => [
    'enabled'          => env('SIGNATURE_RESOURCE_ENABLED', true),
    'navigation_icon'  => env('SIGNATURE_RESOURCE_ICON', 'heroicon-o-pencil-square'),
    'navigation_group' => env('SIGNATURE_RESOURCE_GROUP', null),
    'navigation_sort'  => env('SIGNATURE_RESOURCE_SORT', null),
    'navigation_label' => env('SIGNATURE_RESOURCE_LABEL', 'Signatures'),
],
```

These are the **defaults**. Values set on `SignaturePlugin::make()` take precedence:

```php
SignaturePlugin::make()
    ->navigationGroup('Documents')
    ->navigationIcon('heroicon-o-pencil-square')
    ->navigationSort(10)
    ->navigationLabel('Document Signatures')
```

If you manually register or discover `SignatureResource` without `SignaturePlugin::make()`, the resource falls back to these config values. The recommended setup is still to register the plugin on the panel.

---

## Metadata & machine binding

Every stored signature PNG receives HMAC-signed `tEXt` chunks and XMP metadata. Machine lock requires the same browser/device on re-upload.

```php
'metadata' => [
    'enforce_machine_lock' => env('SIGNATURE_MACHINE_LOCK', true),
],
```

| Setting | Effect |
|---|---|
| `false` | Only verifies HMAC + user ID on re-upload |
| `true` (default) | Also verifies the device fingerprint and DB `machine_fingerprint` |

---

## Queue

```php
'queue'            => env('SIGNATURE_QUEUE', 'default'),
'queue_connection' => env('SIGNATURE_QUEUE_CONNECTION', null),
```

`null` uses the application's default connection. Only relevant when `SignDocumentAction::make()->queued()` is used — the default is synchronous.

---

## Timestamp Authority (TSA)

Embeds an RFC 3161 trusted timestamp inside the PKCS#7 block. Disabled when `url` is `null`.

```php
'tsa' => [
    'url' => env('SIGNATURE_TSA_URL', null),
],
```

Free public endpoints:

| Endpoint | Provider |
|---|---|
| `https://freetsa.org/tsr` | FreeTSA |
| `http://timestamp.digicert.com` | DigiCert |
| `http://tsa.starfieldtech.com` | Starfield |

---

## CRL validation

Checks certificate revocation lists before signing. Disabled by default.

```php
'crl' => [
    'enabled'         => env('SIGNATURE_CRL_ENABLED', false),
    'cache_ttl_hours' => 24,
],
```

Requires the `openssl` CLI binary in `PATH`. Self-signed certificates (no CDP extension) are silently skipped.

---

## Full environment variable reference

```bash
# Drivers
SIGNATURE_CERT_DRIVER=openssl       # openssl | cfssl
SIGNATURE_PDF_DRIVER=fpdi           # fpdi | tcpdf

# Storage
SIGNATURE_DISK=local                # any Laravel disk

# Admin resource
SIGNATURE_RESOURCE_ENABLED=true
SIGNATURE_RESOURCE_ICON=heroicon-o-pencil-square
SIGNATURE_RESOURCE_GROUP=           # blank = ungrouped
SIGNATURE_RESOURCE_SORT=            # blank = default order
SIGNATURE_RESOURCE_LABEL=Signatures

# Security
SIGNATURE_MACHINE_LOCK=true         # reject re-upload from different device

# Queue
SIGNATURE_QUEUE=default
SIGNATURE_QUEUE_CONNECTION=         # blank = app default

# Optional features
SIGNATURE_TSA_URL=                  # blank = disabled
SIGNATURE_CRL_ENABLED=false

# CFSSL (only if cert_driver=cfssl)
CFSSL_HOST=http://localhost:8888
CFSSL_PROFILE=client
```
