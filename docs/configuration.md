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

# Floating launcher
SIGNATURE_LAUNCHER_ENABLED=true
SIGNATURE_LAUNCHER_REPLACES_NAV=true
SIGNATURE_LAUNCHER_POSITION=bottom-right
SIGNATURE_LAUNCHER_LABEL=Signatures
SIGNATURE_LAUNCHER_COLOR=
SIGNATURE_LAUNCHER_POLL=60
SIGNATURE_LAUNCHER_AVOID_OVERLAP=true
SIGNATURE_LAUNCHER_OFFSET_X=1.5rem
SIGNATURE_LAUNCHER_OFFSET_Y=1.5rem
SIGNATURE_LAUNCHER_GAP=12
SIGNATURE_LAUNCHER_Z_INDEX=40

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

### `launcher`

The floating button, pinned to a corner of every panel page, that opens a
slide-over with the documents waiting on the signed-in user and their signature
library. On by default.

| Key | Env | Default |
|---|---|---|
| `launcher.enabled` | `SIGNATURE_LAUNCHER_ENABLED` | `true` |
| `launcher.replaces_navigation` | `SIGNATURE_LAUNCHER_REPLACES_NAV` | `true` |
| `launcher.position` | `SIGNATURE_LAUNCHER_POSITION` | `bottom-right` |
| `launcher.icon` | `SIGNATURE_LAUNCHER_ICON` | `heroicon-o-pencil-square` |
| `launcher.label` | `SIGNATURE_LAUNCHER_LABEL` | `Signatures` |
| `launcher.color` | `SIGNATURE_LAUNCHER_COLOR` | `null` (built-in neutral) |
| `launcher.poll_seconds` | `SIGNATURE_LAUNCHER_POLL` | `60` |
| `launcher.hide_when_empty` | `SIGNATURE_LAUNCHER_HIDE_WHEN_EMPTY` | `false` |
| `launcher.avoid_overlap` | `SIGNATURE_LAUNCHER_AVOID_OVERLAP` | `true` |
| `launcher.offset.x` | `SIGNATURE_LAUNCHER_OFFSET_X` | `1.5rem` |
| `launcher.offset.y` | `SIGNATURE_LAUNCHER_OFFSET_Y` | `1.5rem` |
| `launcher.gap` | `SIGNATURE_LAUNCHER_GAP` | `12` (px) |
| `launcher.z_index` | `SIGNATURE_LAUNCHER_Z_INDEX` | `40` |
| `launcher.avoid` | — | `[]` |
| `launcher.ignore` | — | `[]` |

`position` accepts `bottom-right`, `bottom-left`, `top-right`, `top-left`. The
slide-over enters from whichever side the button sits on.

#### Not landing on the host app's own floating button

A plugin does not own the corner it is dropped into. Host apps put chat
widgets, cookie bars, "back to top" buttons and their own FABs in exactly the
same place, so with `avoid_overlap` on (the default) the launcher measures what
is already pinned in its corner and stacks itself clear of it:

- It probes the corner on load, on resize, on `livewire:navigated`, twice more
  shortly after load, and whenever something is appended to `<body>` — chat
  widgets and cookie bars routinely mount seconds late.
- **Fixed or sticky, short, and clickable** counts as an obstacle: another FAB,
  a cookie bar, a topbar. It stacks above it, leaving `gap` pixels.
- **Tall** elements (over 60% of the viewport height) are treated as layout —
  sidebars, full-height drawers, backdrops. Floating in front of those is the
  job; stacking above one would push the button off-screen.
- **`pointer-events: none`** decoration, such as a full-width toast rail, is
  ignored.
- A corner crowded past 60% of the viewport height gives up and stays put:
  drifting into the middle of the page would be worse than the overlap.

Two escape hatches for widgets the detector gets wrong:

```php
'launcher' => [
    // Always stack clear of these — for widgets that render into an iframe,
    // or mount far too late to be probed.
    'avoid'  => ['#intercom-launcher', '.crisp-client'],

    // Never treat these as obstacles.
    'ignore' => ['.my-app-toast-rail'],
],
```

If you already know where the button should go, placing it by hand is better
than probing:

```php
'launcher' => [
    'position'      => 'bottom-left',
    'offset'        => ['x' => '1.5rem', 'y' => '6rem'],  // above the host's FAB
    'avoid_overlap' => false,
],
```

`offset` accepts a plain CSS length (`px`, `rem`, `em`, `vh`, `vw`, `%`);
anything else falls back to the default, since the value lands in a style
attribute. `z_index` sets the button's layer — the slide-over sits one above,
its backdrop one below — so raise it if a host overlay covers the button, and
lower it if the button covers something that matters more.

**`replaces_navigation`** is the key worth understanding. While the launcher is
on, the inbox page and the Signatures resource stop registering sidebar/topbar
items — the launcher is the entry point, and two doors to one room is clutter.
Both pages stay fully routable and the slide-over links to them. Set it to
`false` to have the launcher *and* the navigation items.

Turning the launcher off restores both navigation items automatically:
suppression is conditional on there being a launcher to replace them with, so
no combination of these flags can leave a panel with no way to reach
signatures.

`color` is a plain hex rather than a Filament color token, because the CSS
custom-property format for those changed between Filament majors and a button
that renders invisible on one of the three supported versions would be worse
than one that isn't brand-coloured by default.

Per-panel override: `SignaturePlugin::make()->withoutFloatingLauncher()`.

### `filament_version`

| Key | Env | Default |
|---|---|---|
| `filament_version` | `SIGNATURE_FILAMENT_VERSION` | auto-detected |

Normally read from Composer's installed-versions manifest — a class probe
cannot tell Filament 4 from 5, since both ship `Filament\Schemas\Schema`. Set
this only to force a branch (3, 4 or 5), e.g. to exercise the v3 component
classes on a v5 install.
