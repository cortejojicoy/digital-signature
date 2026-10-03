# Configuration

After publishing, the config file lives at `config/signature.php`.

Your copy is merged with the package defaults one level deep. A top-level block
you delete falls back to the default. A block you keep replaces the default
**whole**, along with the env variables inside it. Keep blocks complete, or
remove them and use `.env`. See
[Keep your published config current](installation.md#keep-your-published-config-current).

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
'preview_url_ttl'  => 5,               // minutes a signature preview link stays valid
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

## Registered signing devices

Each browser holds a non-extractable P-256 key in IndexedDB and proves to the server that it holds the key. Every signature then records the device it was created on or used on. See [security.md §10](security.md#10-registered-signing-devices) and [Device registration](device-registration.md).

```php
'devices' => [
    'enabled'               => env('SIGNATURE_DEVICES_ENABLED', true),
    'require'               => env('SIGNATURE_DEVICES_REQUIRE', 'off'),   // off | warn | enforce
    'usage_policy'          => env('SIGNATURE_DEVICES_USAGE_POLICY', 'any_registered'), // or creation_device_only
    'notify_on_new_device'  => env('SIGNATURE_DEVICES_NOTIFY', true),
    'notification_channels' => ['mail'],
    'max_per_user'          => 10,
    'challenge_ttl'         => 120,  // seconds a challenge can be answered
    'attestation_ttl'       => 900,  // seconds a browser's proof stays valid
],
```

| `require` | Effect |
|---|---|
| `off` (default) | Record the device when there is one, never block |
| `warn` | The same, and log each signature act that had no verified device |
| `enforce` | Creating or using a signature fails with `UnregisteredDeviceException` without a verified device |

A **revoked** device is refused in every mode.

### Desktop agent (Kukux Sign Agent)

Pairs a computer whose signing key lives in its Secure Enclave or TPM. See [Desktop agent](desktop-agent.md).

```php
'devices' => [
    // …
    'agent' => [
        'enabled'          => env('SIGNATURE_AGENT_ENABLED', false),
        'approval'         => env('SIGNATURE_AGENT_APPROVAL', 'prefer'),   // off | prefer | enforce
        'scheme'           => 'kukuxsign',   // the custom URL scheme the agent registers for pairing links
        'download_url'     => env('SIGNATURE_AGENT_DOWNLOAD_URL', 'https://github.com/cortejojicoy/digital-signature-agent/releases/latest'),
        'min_version'      => env('SIGNATURE_AGENT_MIN_VERSION', '0.1.0'),
        'require_presence' => env('SIGNATURE_AGENT_REQUIRE_PRESENCE', true),
        'server_id'        => env('SIGNATURE_AGENT_SERVER_ID'),   // null = derived from APP_KEY
        'salt'             => env('SIGNATURE_AGENT_SALT'),        // null = derived from APP_KEY
        'pairing_ttl'      => 600,
        'job_ttl'          => 300,
        'skip_ttl'         => 120,

        // Detected device types refused at pairing. Empty = allow VMs.
        'blocked_device_types' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SIGNATURE_AGENT_BLOCKED_DEVICE_TYPES', 'virtual_machine')),
        ))),
    ],
],
```

A computer holds one account's signature per app. Re-pairing from the same computer updates the existing device instead of adding one. Free a computer with `php artisan signature:agent-release`. See [Desktop agent: one signature per computer](desktop-agent.md#one-signature-per-computer).

| `approval` | When a user with a paired computer signs a document |
|---|---|
| `off` | The agent is never asked |
| `prefer` (default) | Approve on the computer, with *Sign in the browser instead* |
| `enforce` | Approval on a paired computer is required, so a user with no paired computer can't sign documents |

`server_id` and `salt` must stay stable for an installation. If you rotate `APP_KEY`, pin them first, or every paired agent will have to pair again. The agent only connects to **HTTPS** origins.

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

## Templates and documents

```php
'templates' => [
    \App\Pdf\DtrTemplate::class,                                   // a class
    'payslip' => ['view' => 'pdf.payslip', 'slots' => ['employee']],  // or a Blade template by key
],

'documents' => [
    'dtr' => \App\Signatures\DtrDocument::class,                     // 2.0: documents this app routes
],
```

Class-strings, not closures, so `php artisan config:cache` keeps working. Both are built through the container on first use. See [PDF Templates](pdf-templates.md) and [Integrating documents](integration/index.md).

---

## Placement designer

| Key | Env | Default | Purpose |
|---|---|---|---|
| `designer.dpi` | `SIGNATURE_DESIGNER_DPI` | `144` | Resolution of the page previews. Higher is sharper, slower and larger. |

---

## Stamp: verification QR, caption and the public page

What is drawn inside each signature's box. Everything is carved **out of** the
placement rectangle; the stamp never grows past the box a signatory or the
form gave it.

### `qr`

A QR encoding the URL of the public verification page, drawn as a square off
the right of the box.

| Key | Env | Default | Purpose |
|---|---|---|---|
| `qr.enabled` | `SIGNATURE_QR_ENABLED` | `true` | Draw it at all. |
| `qr.min_size` | `SIGNATURE_QR_MIN_SIZE` | `26` | Points. Below this a phone won't decode it, so it stands down. |
| `qr.max_size` | `SIGNATURE_QR_MAX_SIZE` | `48` | Points. Keeps it from dominating a large box. |
| `qr.gap` | `SIGNATURE_QR_GAP` | `2` | Points between the QR and the signature. |

### `verify`

| Key | Env | Default | Purpose |
|---|---|---|---|
| `verify.enabled` | `SIGNATURE_VERIFY_ENABLED` | `true` | The public `GET /signature/verify/{uuid}` page the QR points at. It discloses only what the printed page already shows. Off: the QR stands down with it. |

### `caption`

Readable provenance under (or beside) the signature: who, when, and a reference.

| Key | Env | Default | Purpose |
|---|---|---|---|
| `caption.enabled` | `SIGNATURE_CAPTION_ENABLED` | `true` | Draw it at all. |
| `caption.fields` | — | `['signer', 'signed_at', 'reference']` | Lines, in order. Also available: `email`. Lines that don't fit are dropped from the bottom. |
| `caption.align` | `SIGNATURE_CAPTION_ALIGN` | `C` | `L`, `C` or `R`. |
| `caption.line_height` | `SIGNATURE_CAPTION_LINE_HEIGHT` | `1.06` | Leading. |
| `caption.position` | `SIGNATURE_CAPTION_POSITION` | `bottom` | `bottom`, `top`, `left` or `right`. A signatory can change it per signature while placing. |
| `caption.width_ratio` | `SIGNATURE_CAPTION_WIDTH_RATIO` | `0.42` | For `left`/`right`: the most of the box's width the caption may take. |
| `caption.min_box_width` | `SIGNATURE_CAPTION_MIN_BOX_WIDTH` | `110` | For `left`/`right`: below this the caption stands down. |
| `caption.height_ratio` | `SIGNATURE_CAPTION_HEIGHT_RATIO` | `0.38` | For `top`/`bottom`: the most of the box's height the caption may take. |
| `caption.min_box_height` | `SIGNATURE_CAPTION_MIN_BOX_HEIGHT` | `28` | For `top`/`bottom`: below this the caption stands down. |
| `caption.max_font_pt` | `SIGNATURE_CAPTION_MAX_FONT` | `6` | The largest size that fits is used… |
| `caption.min_font_pt` | `SIGNATURE_CAPTION_MIN_FONT` | `4` | …down to this, then lines are truncated with an ellipsis. |
| `caption.date_format` | `SIGNATURE_CAPTION_DATE_FORMAT` | `j M Y H:i` | PHP date format for `signed_at`. |
| `caption.color` | — | `[90, 90, 90]` | RGB. |

---

## Hashing

| Key | Default | Purpose |
|---|---|---|
| `hash_algo` | `sha256` | Algorithm for image, document and signed-document hashes. Changing it on a live install makes earlier hashes incomparable; every stored version would report a mismatch. |

---

## Documents I've signed (2.0)

The *Signed by me* page: every signature the user has put on a routed document, with **the copy they signed** and **the current** document. Like the inbox, it leaves the sidebar while the launcher (which links to it) replaces navigation.

| Key | Env | Default |
|---|---|---|
| `signed.enabled` | `SIGNATURE_SIGNED_ENABLED` | `true` |
| `signed.navigation` | `SIGNATURE_SIGNED_NAV` | `true` |
| `signed.navigation_label` | `SIGNATURE_SIGNED_LABEL` | `Signed by me` |
| `signed.navigation_icon` | `SIGNATURE_SIGNED_ICON` | `heroicon-o-document-check` |
| `signed.navigation_group` | `SIGNATURE_SIGNED_GROUP` | the inbox's group |
| `signed.navigation_sort` | `SIGNATURE_SIGNED_SORT` | `null` |

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
SIGNATURE_DEVICES_ENABLED=true      # browser device keys
SIGNATURE_DEVICES_REQUIRE=off       # off | warn | enforce
SIGNATURE_DEVICES_USAGE_POLICY=any_registered  # or creation_device_only
SIGNATURE_DEVICES_NOTIFY=true       # mail the owner when a new device registers
SIGNATURE_AGENT_ENABLED=false       # desktop agent pairing + approval
SIGNATURE_AGENT_APPROVAL=prefer     # off | prefer | enforce
SIGNATURE_AGENT_MIN_VERSION=0.1.0   # older agents get HTTP 426
SIGNATURE_AGENT_SERVER_ID=          # blank = derived from APP_KEY; `signature:install --agent` pins it
SIGNATURE_AGENT_SALT=               # same
SIGNATURE_AGENT_BLOCKED_DEVICE_TYPES=virtual_machine  # comma-separated; empty = allow VMs

# Routing, sessions and consent
SIGNATURE_SEQUENCE_MODE=sequential  # sequential | parallel
SIGNATURE_MULTI_MODE=progressive    # progressive | incremental
SIGNATURE_SESSION_EXPIRY_DAYS=      # blank = sessions never expire
SIGNATURE_AUTO_AFFIX_MODE=approval  # approval | delegated | implicit
SIGNATURE_ALLOW_IMPLICIT_AFFIX=false
SIGNATURE_AUTO_AFFIX_NOTIFY=true

# Placement designer
SIGNATURE_DESIGNER_DPI=144          # page preview sharpness

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
SIGNATURE_LAUNCHER_WIDTH=64rem
SIGNATURE_LAUNCHER_MANAGE_WIDTH=80rem
SIGNATURE_LAUNCHER_AVOID_OVERLAP=true
SIGNATURE_LAUNCHER_OFFSET_X=1.5rem
SIGNATURE_LAUNCHER_OFFSET_Y=1.5rem
SIGNATURE_LAUNCHER_GAP=12
SIGNATURE_LAUNCHER_Z_INDEX=40

# Stamp: QR, verification page, caption
SIGNATURE_QR_ENABLED=true
SIGNATURE_QR_MIN_SIZE=26            # points; smaller won't scan, so it stands down
SIGNATURE_QR_MAX_SIZE=48
SIGNATURE_QR_GAP=2
SIGNATURE_VERIFY_ENABLED=true       # the public page the QR points at
SIGNATURE_CAPTION_ENABLED=true
SIGNATURE_CAPTION_POSITION=bottom   # bottom | top | left | right
SIGNATURE_CAPTION_ALIGN=C           # L | C | R
SIGNATURE_CAPTION_LINE_HEIGHT=1.06
SIGNATURE_CAPTION_WIDTH_RATIO=0.42
SIGNATURE_CAPTION_MIN_BOX_WIDTH=110
SIGNATURE_CAPTION_HEIGHT_RATIO=0.38
SIGNATURE_CAPTION_MIN_BOX_HEIGHT=28
SIGNATURE_CAPTION_MAX_FONT=6
SIGNATURE_CAPTION_MIN_FONT=4
SIGNATURE_CAPTION_DATE_FORMAT="j M Y H:i"

# Signed by me (2.0)
SIGNATURE_SIGNED_ENABLED=true
SIGNATURE_SIGNED_NAV=true
SIGNATURE_SIGNED_LABEL="Signed by me"
SIGNATURE_SIGNED_ICON=heroicon-o-document-check
SIGNATURE_SIGNED_GROUP=
SIGNATURE_SIGNED_SORT=

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
| `sessions.notification_channels` | — | `['mail']` | Channels for `SignatureRequestedNotification`, sent to each signatory when it's their turn. `[]` turns it off. |

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
drawer with the documents waiting on the signed-in user and their signature
library. On by default.

The drawer is the signing surface, not a notification rail: opening a document
renders the PDF inside it, and the signatory drags their signature onto the
page. `width` is sized for that — `64rem` by default, wide enough to read a
page. It accepts any plain CSS length, never exceeds the viewport, and goes
full-bleed below 640px regardless.

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
| `launcher.width` | `SIGNATURE_LAUNCHER_WIDTH` | `64rem` |
| `launcher.manage_width` | `SIGNATURE_LAUNCHER_MANAGE_WIDTH` | `80rem` (drawer width while **Manage signatures** is open) |
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
