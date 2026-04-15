# Configuration

After publishing, the config file lives at `config/signature.php`.

---

## Certificate driver

Controls how X.509 certificates are issued for each user.

```php
'cert_driver' => env('SIGNATURE_CERT_DRIVER', 'openssl'),
```

| Value | Description |
|---|---|
| `openssl` | Self-signed via PHP's `openssl_*` extension (default, no extra services needed) |
| `cfssl` | Issues certificates from a running CFSSL server |

### OpenSSL options

```php
'openssl' => [
    'digest_alg'       => 'sha256',
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'cert_lifetime'    => 3650,   // days before expiry
    'ca_cert_path'     => storage_path('app/certs/ca.crt'),  // optional CA cert
    'ca_key_path'      => storage_path('app/certs/ca.key'),  // optional CA key
],
```

When `ca_cert_path` and `ca_key_path` point to valid files, certificates are CA-signed instead of self-signed. Leave them absent for development.

### CFSSL options

```php
'cfssl' => [
    'host'    => env('CFSSL_HOST', 'http://localhost:8888'),
    'profile' => env('CFSSL_PROFILE', 'client'),
],
```

---

## PDF driver

Controls how the signature is embedded into the PDF.

```php
'pdf_driver' => env('SIGNATURE_PDF_DRIVER', 'fpdi'),
```

| Value | Description |
|---|---|
| `fpdi` | Uses FPDI + TCPDF. Imports existing PDF pages and embeds a PKCS#7 cryptographic signature (default) |
| `tcpdf` | Pure TCPDF |

---

## Storage

```php
'storage_disk'     => env('SIGNATURE_DISK', 'local'),
'certs_path'       => 'certs',         // PFX files
'signatures_path'  => 'signatures',    // raw signature images
'signed_docs_path' => 'signed-docs',   // completed signed PDFs
```

All paths are relative to the disk root. Use `s3` or any Laravel disk driver.

---

## Hashing

```php
'hash_algo' => 'sha256',
```

Used for image hashes, document integrity hashes, and the signed-document hash. Any algo supported by PHP's `hash()` function is valid.

---

## Image constraints

```php
'image' => [
    'max_kb'        => 512,
    'allowed_mimes' => ['image/png', 'image/jpeg'],
    'canvas_width'  => 600,
    'canvas_height' => 200,
],
```

---

## Queue

```php
'queue'            => env('SIGNATURE_QUEUE', 'default'),
'queue_connection' => env('SIGNATURE_QUEUE_CONNECTION', null),
```

`null` uses the application's default queue connection.

---

## Timestamp Authority (TSA)

Embeds an RFC 3161 trusted timestamp inside the PKCS#7 signature. Disabled when `url` is `null`.

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

## Environment variable summary

```bash
SIGNATURE_CERT_DRIVER=openssl       # openssl | cfssl
SIGNATURE_PDF_DRIVER=fpdi           # fpdi | tcpdf
SIGNATURE_DISK=local                # any Laravel disk
SIGNATURE_QUEUE=default
SIGNATURE_QUEUE_CONNECTION=         # blank = app default
SIGNATURE_TSA_URL=                  # blank = disabled
SIGNATURE_CRL_ENABLED=false
CFSSL_HOST=http://localhost:8888
CFSSL_PROFILE=client
```
