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

---

## Signatory routing, sessions and consent

Added by [Signatory Routing](signatory-routing.md). Full explanations of each
mode live there; this is the key reference.

### `sessions`

| Key | Env | Default | Purpose |
|---|---|---|---|
| `sessions.sequence_mode` | `SIGNATURE_SEQUENCE_MODE` | `sequential` | `sequential` honours `SlotDefinition::$order` (Prepared → Attested → Noted); `parallel` lets any assigned signatory act at any time |
| `sessions.expires_after_days` | `SIGNATURE_SESSION_EXPIRY_DAYS` | `null` | Sessions stop accepting signatures after this many days; null disables expiry |
| `sessions.notification_channels` | — | `['mail']` | Channels for `SignatureRequestedNotification` |

### `multi_signature`

| Key | Env | Default |
|---|---|---|
| `multi_signature.mode` | `SIGNATURE_MULTI_MODE` | `progressive` |

- **`progressive`** — each signature is stamped onto the previous signatory's
  output and re-signed with that signatory's own certificate. Every visible
  signature is present and the database holds a verifiable hash chain, but only
  the most recent PKCS#7 block survives inside the PDF (FPDI rewrites the file
  on every pass).
- **`incremental`** — true PAdES; requires a driver implementing
  `SupportsIncrementalSigning`. Neither bundled driver does, so selecting this
  mode without one throws `IncrementalSigningUnsupportedException` at sign time
  rather than silently producing a document whose earlier signatures are gone.

### `auto_affix`

| Key | Env | Default | Purpose |
|---|---|---|---|
| `auto_affix.mode` | `SIGNATURE_AUTO_AFFIX_MODE` | `approval` | `approval`, `delegated` or `implicit` |
| `auto_affix.allow_implicit` | `SIGNATURE_ALLOW_IMPLICIT_AFFIX` | `false` | Second acknowledgement required before `implicit` will run |
| `auto_affix.notify` | `SIGNATURE_AUTO_AFFIX_NOTIFY` | `true` | Notify the signatory on every auto-affix. Leave this on. |
| `auto_affix.default_grant_days` | `SIGNATURE_GRANT_DAYS` | `365` | Default lifetime of a delegation grant |
| `auto_affix.notification_channels` | — | `['mail']` | Channels for `SignatureAutoAffixedNotification` |

- **`approval`** (default) — never signs on anyone's behalf. The signature is
  always produced in the signatory's own authenticated request.
- **`delegated`** — signs only where the signatory created a scoped, expiring,
  revocable `SignatureDelegation`. A standing grant means the server can produce
  that user's signature for the grant's lifetime; that is a deliberate trade.
- **`implicit`** — treats being tagged on a record as consent. Unsafe: anyone
  who can edit the record can then cause that person's certificate to sign it.

Both `auto_affix` and `sequence_mode` can be overridden per template with the
`auto_affix` and `sequence_mode` keys in the template's config array.

### `inbox`

| Key | Env | Default |
|---|---|---|
| `inbox.enabled` | `SIGNATURE_INBOX_ENABLED` | `true` |
| `inbox.navigation` | `SIGNATURE_INBOX_NAV` | `true` |
| `inbox.navigation_label` | `SIGNATURE_INBOX_LABEL` | `Awaiting my signature` |
| `inbox.navigation_icon` | `SIGNATURE_INBOX_ICON` | `heroicon-o-inbox-arrow-down` |
| `inbox.navigation_group` | `SIGNATURE_INBOX_GROUP` | `null` |
| `inbox.navigation_sort` | `SIGNATURE_INBOX_SORT` | `null` |

Per-panel override: `SignaturePlugin::make()->withoutInbox()`.

### `filament_version`

| Key | Env | Default |
|---|---|---|
| `filament_version` | `SIGNATURE_FILAMENT_VERSION` | auto-detected |

Normally read from Composer's installed-versions manifest — a class probe
cannot tell Filament 4 from 5, since both ship `Filament\Schemas\Schema`. Set
this only to force a branch (3, 4 or 5), e.g. to exercise the v3 component
classes on a v5 install.
