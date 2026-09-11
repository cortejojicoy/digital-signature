# Implementation Plan — Adding Digital Signature to a Laravel/Filament App

**Audience:** a developer (or coding agent) who has this repo, or has installed
`kukux/digital-signature` into a host application, and needs to take it from
"package present" to "documents are being legally signed in production."

**How to use this document:** work top to bottom. Each phase has a *Goal*, the
*Steps*, and a *Done when* check you can actually run. Do not skip Phase 0 or
Phase 3 — every support issue this package has ever produced traces back to one
of them. Phase 4 branches: pick **one track** and ignore the other two until
that track is live.

**Estimated effort:** Track A ≈ half a day. Track B ≈ 1–2 days. Track C ≈ 3–5
days including calibration and consent decisions.

---

## Table of contents

| Phase | What it gets you | Skippable? |
|---|---|---|
| [0 — Prerequisites](#phase-0--prerequisites) | A host that can actually run the package | No |
| [1 — Install](#phase-1--install-and-publish) | Tables, config, assets in place | No |
| [2 — Register the plugin](#phase-2--register-the-plugin-on-your-panels) | Signatures resource + inbox in the sidebar | No |
| [3 — Certificates and storage](#phase-3--certificates-and-storage) | Signing keys that survive a deploy | No |
| [4 — Choose your track](#phase-4--choose-your-integration-track) | The right amount of machinery | No |
| [5A — Stored-PDF signing](#track-a--stored-pdf-single-signer) | One signer on a PDF you already have | Pick one |
| [5B — Template-rendered signing](#track-b--template-rendered-pdf-single-signer) | One signer on a PDF you generate | Pick one |
| [5C — Multi-signatory routing](#track-c--multi-signatory-routed-documents) | Prepared → Attested → Noted flows | Pick one |
| [6 — Security posture](#phase-6--security-posture-decisions) | Deliberate answers, not defaults by accident | No |
| [7 — Verification](#phase-7--verification) | Proof it works before users see it | No |
| [8 — Production rollout](#phase-8--production-rollout) | Queue, backups, monitoring | No |
| [Appendices](#appendix-a--class-name-reference) | Class names, env vars, schema, troubleshooting | Reference |

---

## Phase 0 — Prerequisites

**Goal:** confirm the host can run the package before you change anything.

### Steps

1. **Runtime.** PHP 8.2+, Laravel 12, Filament 3, 4 or 5.

   > Laravel 11 is unsupported and cannot be made to work — see
   > [installation.md](installation.md#requirements). Composer 2.9 blocks the
   > entire 11.x line over an unpatchable advisory.

2. **PHP extensions.** `ext-openssl` and `ext-gd` are hard requirements
   (Composer enforces them). `ext-imagick` + Ghostscript are needed **only** if
   you will use the placement designer (Track B/C) and don't implement
   `RendersSamplePageImage`.

3. **`openssl` CLI binary** in `PATH` — required only if you enable CRL
   validation in Phase 6.

4. **A queue** if you plan to use `->queued()` signing. The default is
   synchronous, so this is optional at first.

5. **Decide the storage disk.** Signature images, certificates (`.pfx`) and
   signed PDFs all land on `config('signature.storage_disk')`. It must be
   **private** — never `public`. The package serves signature images through a
   signed-URL route (`signature.asset`) precisely so the disk can stay private.

### Done when

```bash
php -r 'echo PHP_VERSION, PHP_EOL;'                    # >= 8.2
php -m | grep -E '^(openssl|gd)$'                      # both listed
php artisan about | grep -i 'laravel version'          # 12.x
composer show filament/filament | head -2              # 3.x, 4.x or 5.x
php -m | grep -i imagick && gs --version               # only if using the designer
```

---

## Phase 1 — Install and publish

**Goal:** package code, config, migrations and browser assets are in the host.

### Steps

```bash
composer require kukux/digital-signature

php artisan vendor:publish --tag=signature-config
php artisan vendor:publish --tag=signature-migrations
php artisan migrate

php artisan filament:assets
```

Notes that matter:

- **Migration timestamps are rewritten at publish time** so the package tables
  are created after your app's tables, and re-publishing is idempotent (an
  already-published copy is matched by filename suffix and reused). Do not
  hand-edit the timestamps — the eight migrations must keep their relative
  order, because `digital_signing_sessions` must exist before
  `digital_signatures` can point a foreign key at it.
- **`filament:assets` is not optional.** It links the signature pad + picker JS
  under `public/js/filament/kukux/digital-signature/`. Re-run it after every
  `composer update` of this package, and add it to your deploy script.
- Publishing config is recommended, not required — the provider merges the
  package default. Publish it so your security decisions in Phase 6 are visible
  in code review.

### Done when

```bash
php artisan migrate:status | grep digital_          # 8 package migrations, all Ran
ls public/js/filament/kukux/digital-signature/      # digital-signature.js present
test -f config/signature.php && echo "config published"
```

---

## Phase 2 — Register the plugin on your panels

**Goal:** the Signatures resource and the signature inbox appear on every panel
that needs them.

### Steps

```php
// app/Providers/Filament/AdminPanelProvider.php
use Kukux\DigitalSignature\SignaturePlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            SignaturePlugin::make()
                ->navigationGroup('Documents')
                ->navigationIcon('heroicon-o-pencil-square')
                ->navigationSort(10),
        ]);
}
```

**Register the plugin on every panel** that renders the resource, the actions
or the inbox. In a multi-panel app (admin + staff, say), that means repeating
the call in each provider. Do **not** rely on `discoverResources()` reaching
into `vendor/` — that produces
`Plugin [signature] is not registered for panel [admin]`.

Optional switches:

```php
SignaturePlugin::make()->withoutResource();   // you're shipping your own resource
SignaturePlugin::make()->withoutInbox();      // page stays routable, leaves the sidebar
```

### What this puts in front of users

By default the plugin adds **no navigation items**. It mounts a **floating
launcher** instead — a button pinned to the corner of every panel page whose
slide-over lists the documents waiting on the signed-in user, with Sign and
Decline on each row, plus links to their signature library and the full inbox.

That is deliberate: signing is an interruption, not a destination. A signatory
is in the middle of something else when a document reaches them, and a sidebar
item filed under whatever navigation group your app happens to use is a worse
place to put the work than the page they're already on.

The **Signatures** resource and the **Awaiting my signature** page are both
still registered and routable — they just don't claim sidebar slots while the
launcher is there. Three ways to change that:

```php
// Keep the launcher AND the sidebar items.
// config/signature.php → launcher.replaces_navigation = false
// or: SIGNATURE_LAUNCHER_REPLACES_NAV=false

// No launcher on this panel; sidebar items come back automatically.
SignaturePlugin::make()->withoutFloatingLauncher()

// No launcher anywhere.
// SIGNATURE_LAUNCHER_ENABLED=false
```

No combination of those flags can leave a panel with no route to signatures:
navigation suppression is conditional on a launcher existing to replace it.

Appearance is config — position (`bottom-right` default, or any corner), icon,
label, a brand hex for the button, and how often the badge count refreshes. See
[Configuration](configuration.md#launcher).

### Done when

- Log into the panel: the floating button is in the corner, and its slide-over
  opens, closes on Escape, and reports "Nothing waiting on you".
- A user can reach Signatures → Create from the slide-over footer, draw or
  upload a signature image, and save it. That stored row is the prerequisite
  for every signing flow that follows — a user with no registered signature
  cannot sign anything, and the slide-over says exactly that until they do.

---

## Phase 3 — Certificates and storage

**Goal:** signing keys exist, are backed by a CA you control, and survive
deploys.

This phase is where "it worked locally" turns into "the signatures on last
quarter's documents can no longer be validated." Treat it as infrastructure,
not configuration.

### Steps

1. **Pick a certificate driver.**

   | Driver | Use when | Config |
   |---|---|---|
   | `openssl` (default) | Self-signed or your own local CA. Fine for internal documents. | `signature.openssl.*` |
   | `cfssl` | You already run a CFSSL PKI and want a real chain of trust. | `CFSSL_HOST`, `CFSSL_PROFILE` |

2. **Set up a CA (openssl driver).** Without `ca_cert_path` / `ca_key_path`,
   each user certificate is *self-signed* — PDF readers will show "signature
   validity unknown." That is acceptable for internal tamper-evidence, not for
   anything a third party must verify.

   ```bash
   mkdir -p storage/app/certs
   openssl req -x509 -newkey rsa:4096 -sha256 -days 3650 -nodes \
     -keyout storage/app/certs/ca.key \
     -out storage/app/certs/ca.crt \
     -subj "/CN=Your Org Signing CA/O=Your Org"
   chmod 600 storage/app/certs/ca.key
   ```

   The default paths in `config/signature.php` already point here.

3. **Protect the CA key.** It is not in git (`storage/` is ignored) and it must
   not be. Back it up out of band. Losing `ca.key` means every future
   certificate chains to a different root; leaking it means anyone can mint a
   certificate your documents will trust.

4. **Understand the certificate password.** Each user's `.pfx` is encrypted with
   a password supplied at signature-registration time, stored **encrypted at
   rest** on the signature row (`Signature::certificate_password`, cast through
   `encrypt()`/`decrypt()`) so the signer isn't re-prompted at sign time.
   This means **`APP_KEY` is now signing-critical**: rotating or losing it makes
   every stored certificate password undecryptable and every future signature
   with an existing certificate impossible. Add `APP_KEY` to your secret-backup
   procedure and never rotate it casually.

5. **Confirm the disk is private.** `config('signature.storage_disk')` should be
   `local` or a private S3 bucket. If it is `public`, certificates and signed
   PDFs are world-readable by URL. Fix this before Phase 7.

### Done when

```bash
php artisan tinker
>>> $c = app(\Kukux\DigitalSignature\Services\CertificateService::class)->getOrCreate(1, 'test-password');
>>> $c->fingerprint;    // 64-hex string
>>> $c->isValid();      // true
```

and `storage/app/certs/` contains the CA pair plus a `1_*.pfx`.

---

## Phase 4 — Choose your integration track

**Goal:** build the smallest thing that satisfies the requirement.

Answer these in order and stop at the first "yes":

| Question | Track |
|---|---|
| Does the PDF already exist as a stored file, and does exactly one person sign it? | **A — Stored-PDF, single signer** |
| Is the PDF generated on demand from a Blade view, still one signer? | **B — Template-rendered, single signer** |
| Do several named people sign the same document in defined roles? | **C — Multi-signatory routing** |

Tracks are cumulative — C builds on B's template registration, B builds on A's
model preparation. But implement and ship **one at a time**.

---

## Track A — Stored-PDF, single signer

**Goal:** a user signs a PDF your app already stores, from a Filament resource.

### Steps

1. **Make the model signable.**

   ```php
   use Kukux\DigitalSignature\Contracts\Signable;
   use Kukux\DigitalSignature\Traits\HasSignatures;

   class Contract extends Model implements Signable
   {
       use HasSignatures;

       public function getSignableTitle(): string   { return $this->title; }
       public function getSignablePdfPath(): string { return $this->pdf_path; }  // disk-relative
       public function getSignableId(): int|string  { return $this->id; }
   }
   ```

   `getSignablePdfPath()` must return a path readable on
   `config('signature.storage_disk')`.

2. **Add the action and the column to the resource.**

   ```php
   use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;
   use Kukux\DigitalSignature\Filament\Actions\SignDocumentHeaderAction;
   use Kukux\DigitalSignature\Filament\Columns\SignatureColumn;

   ->columns([
       TextColumn::make('title'),
       SignatureColumn::make('signature')->thumbSize(80, 32),
   ])
   ->actions([
       SignDocumentAction::make()->stampAt(page: 1, x: 100, y: 650, w: 200, h: 80),
   ])
   ->headerActions([
       SignDocumentHeaderAction::make(),
   ])
   ```

   Coordinates are **PDF points**, `y` measured from the **bottom** of the page.
   A US-Letter page is 612 × 792 pt, A4 is 595 × 842 pt.

   On Filament v3, a page header needs `SignDocumentHeaderAction` and a table
   row needs `SignDocumentAction`. Using the header-suffixed name in headers
   everywhere is portable across 3/4/5 — see
   [Appendix A](#appendix-a--class-name-reference).

3. **For controller / Livewire / non-resource flows**, call the manager
   directly:

   ```php
   $sig = app(SignatureManager::class)->storeForDocument(
       source: $usersRegisteredSignature,
       signerUserId: auth()->id(),
       signable: $contract,
       position: ['page' => 1, 'x' => 100, 'y' => 650, 'width' => 200, 'height' => 80],
   );

   app(SignatureManager::class)->embedAndFinalize($sig, $sig->getCertificatePassword());
   ```

   See [ad-hoc-signing.md](ad-hoc-signing.md) for the full pattern including
   error handling.

### Done when

A user with a registered signature can click Sign on a record and the resulting
`digital_signatures` row has `status = signed`, a `signed_document_path`, and a
`certificate_fingerprint`. Open the signed PDF in Acrobat: it reports a
signature panel with your CA as issuer.

---

## Track B — Template-rendered PDF, single signer

**Goal:** the PDF doesn't exist until sign time; it's rendered from a Blade
view, and an admin calibrates where the signature lands.

### Steps

1. **Install a renderer.** Config-only templates use
   `barryvdh/laravel-dompdf` by default:

   ```bash
   composer require barryvdh/laravel-dompdf
   ```

   To use something else (Browsershot, Snappy), implement `PdfRenderer` — see
   [pdf-templates.md](pdf-templates.md#using-a-different-pdf-renderer).

2. **Register the template.** Config-only is the fast path:

   ```php
   // config/signature.php
   'templates' => [
       'dtr' => [
           'label'         => 'Daily Time Record',
           'view'          => 'pdf.dtr',
           'signable'      => \App\Models\Dtr::class,
           'sample_data'   => ['user' => ['name' => 'Sample User']],
           'data_resolver' => fn ($record) => ['record' => $record],
           'slots'         => [
               'employee'  => ['label' => 'Employee',  'required' => true],
               'in_charge' => ['label' => 'In Charge', 'required' => true],
           ],
       ],
   ],
   ```

   Write a full `PdfTemplate` class instead when you need a custom renderer,
   conditional slots, or domain-aware sample data. Either style can be
   registered through config, `SignaturePlugin::make()->templates([...])`, or
   `app(PdfTemplateRegistry::class)->register(...)` at runtime.

   **The template key is permanent.** It is the `template_key` on
   `digital_pdf_template_slots`; changing it after slots are saved orphans every
   calibrated coordinate.

3. **Make the model signable in two lines.**

   ```php
   class Dtr extends Model implements Signable
   {
       use HasPdfTemplate;

       protected string $signaturePdfTemplate = 'dtr';   // matches the config key
   }
   ```

4. **Calibrate the slots** in the placement designer:

   ```
   /<panel-path>/signature-templates/{templateKey}/design
   ```

   e.g. `/admin/signature-templates/dtr/design`. The page is intentionally not
   in the sidebar — add a "Design layout" header action on your own resource
   that links to it.

   No Imagick? Implement `RendersSamplePageImage` on the template and return
   your own page PNGs — but **render at 72 DPI**, because in that mode the
   plugin treats one image pixel as one PDF point.

5. **Link users to the signer page** from your resource:

   ```php
   PdfTemplateSigner::getUrl([
       'templateKey'   => 'dtr',
       'signatureUuid' => $signature->uuid,
   ]).'?signable='.$record->getKey();
   ```

   Without `?signable=ID` the signer page runs in acknowledgement mode — it
   validates and echoes the placements without producing a signed PDF. That is
   the right mode while you calibrate; production links must carry the param.

### Done when

- The designer renders the sample page and a dragged slot survives a reload.
- A signer opens the signer URL with `?signable=`, clicks Finish & Save, and
  gets `{ status: 'signed', ... }` with a real file at `signed_document_path`.

---

## Track C — Multi-signatory, routed documents

**Goal:** "Prepared by → Attested by → Noted by" happens without anyone
manually forwarding a file.

This is the largest track. Read
[signatory-routing.md](signatory-routing.md) end to end before starting — the
consent and signing-mode decisions below have legal consequences and are hard to
reverse once documents exist.

### Steps

1. **Declare roles as slots** on the template, with bindings and order:

   ```php
   'accomplishment-report' => [
       'view'     => 'pdf.accomplishment-report',
       'signable' => \App\Models\AccomplishmentReport::class,
       'slots'    => [
           'prepared_by' => ['label' => 'Prepared by', 'signatory' => 'preparedBy', 'order' => 1, 'required' => true],
           'attested_by' => ['label' => 'Attested by', 'signatory' => 'attestedBy', 'order' => 2, 'required' => true],
           'noted_by'    => ['label' => 'Noted by',    'signatory' => 'notedBy',    'order' => 3, 'required' => true],
       ],
   ],
   ```

   A `signatory` binding accepts a relation name, a foreign-key column, a
   closure, or an invokable class. A slot with no binding is a free placement.

2. **Compose the traits on the model.**

   ```php
   class AccomplishmentReport extends Model implements Signable
   {
       use HasPdfTemplate, HasSignatories;

       protected string $signaturePdfTemplate = 'accomplishment-report';

       public function preparedBy(): BelongsTo { return $this->belongsTo(User::class, 'prepared_by_id'); }
       public function attestedBy(): BelongsTo { return $this->belongsTo(User::class, 'attested_by_id'); }
       public function notedBy(): BelongsTo    { return $this->belongsTo(User::class, 'noted_by_id'); }
   }
   ```

3. **Surface routing in the resource.**

   ```php
   // Filament v4/v5 — infolist(Schema $schema) → $schema->components([...])
   // Filament v3   — infolist(Infolist $infolist) → $infolist->schema([...])
   SignatoryPanel::make('signatories'),
   ```

   ```php
   RequestSignaturesAction::make()->autoAffix();      // header, all versions
   RequestSignaturesTableAction::make();              // table row on v3
   ```

   `RequestSignaturesAction` refuses to open a session whose required roles
   can't be filled and names the blockers instead — a stalled session looks
   in-flight while nothing can happen, which is worse than no session.

4. **Decide the signing mode** (`signature.multi_signature.mode`):

   | Mode | What the finished PDF proves | Cost |
   |---|---|---|
   | `progressive` (default) | Every visible signature block, tamper-evidence, and a DB hash chain. Readers show **one** PKCS#7 signature — the last one — because FPDI/TCPDF rebuild the file each pass. | Works with the bundled drivers. |
   | `incremental` | N independently verifiable signer certificates (true PAdES). | Requires a driver implementing `SupportsIncrementalSigning`. **Neither bundled driver can** — the session fails loudly rather than degrading silently. You'd need something like SetaPDF-Signer. |

   Choose `incremental` only when the legal requirement is genuinely N
   verifiable certificates, and budget for the commercial library.

5. **Decide the consent model** (`signature.auto_affix.mode`) — see
   [Phase 6](#phase-6--security-posture-decisions).

6. **Sequencing.** `sessions.sequence_mode = sequential` (default) enforces slot
   `order`; `parallel` lets any assigned signatory act at any time. Set
   `sessions.expires_after_days` if a stale session should stop accepting
   signatures.

### Done when

- `$record->signatoryRoutes()` returns one route per slot with a resolved user.
- `$record->signatureBlockers()` is empty for a fully-tagged record.
- Opening a session, then signing as each user in turn from the **Awaiting my
  signature** inbox, produces a completed session and one PDF carrying every
  visible signature.
- `$record->isFullySigned()` is `true` and `signedDocumentPath()` returns a real
  file.

---

## Phase 6 — Security posture decisions

**Goal:** every security default is a decision you made, not one you inherited.

Work through this table and record the answer for each row in your own
`config/signature.php`, with a comment saying why.

| Decision | Key | Default | Choose otherwise when |
|---|---|---|---|
| **Consent for auto-signing** | `auto_affix.mode` | `approval` | See below. This is the highest-stakes row in the table. |
| Machine lock on re-upload | `metadata.enforce_machine_lock` | `true` | Users legitimately sign from several devices and are hitting `MachineBindingException`. |
| CRL revocation check | `crl.enabled` | `false` | You use a real CA that publishes a CRL. Requires the `openssl` binary; self-signed dev certs have no CDP and are skipped. |
| RFC 3161 timestamp | `tsa.url` | unset | You need signing time provable independently of the server clock. Free: `https://freetsa.org/tsr`. |
| Multi-signature mode | `multi_signature.mode` | `progressive` | See Track C step 4. |
| Sequence enforcement | `sessions.sequence_mode` | `sequential` | Roles genuinely don't have an order. |
| Session expiry | `sessions.expires_after_days` | `null` | Documents shouldn't be signable forever. |

### The consent decision, in full

| Mode | What it means | Verdict |
|---|---|---|
| `approval` | Routing finds the person and pre-places their signature; the PKCS#7 block is still produced in **their own authenticated request**. No new trust assumptions over single-signer signing. | **Start here.** Ship this first even if you intend to move on. |
| `delegated` | Auto-signs only where the signatory personally created a scoped, expiring, revocable `SignatureDelegation` from their own session. | Acceptable when the workflow genuinely needs hands-off signing. Understand the trade: for the grant's lifetime, the server can sign as that user. |
| `implicit` | Being tagged on a record counts as consent. Anyone who can edit the record can cause that person's certificate to sign it. | **Unsafe.** Off by default and gated behind a second flag (`allow_implicit`). Do not enable it because it is convenient. |

Leave `auto_affix.notify = true` in every mode. Silent signing is not acceptable
even with consent.

### Always-on, nothing to configure

PKCS#7 embedding, DocMDP P=2 modification detection, HMAC-signed PNG metadata
(tEXt + XMP), signer identity in the PNG, forgery/screenshot rejection,
before/after document integrity hashes, DB cross-validation on re-upload,
multi-signatory chain hashes, and an audit row for every auto-affix. See
[security.md](security.md).

### Exceptions your UI must handle

`ForgedSignatureException`, `MachineBindingException`,
`CertificateRevokedException`, `PrimarySignatureExistsException`,
`OutOfSequenceException`, `SignatoryNotReadyException`,
`SigningSessionClosedException`, `DelegationNotPermittedException`,
`IncrementalSigningUnsupportedException`. The bundled Filament actions surface
these as danger notifications; **custom flows must catch them explicitly** or
users get a 500 on a perfectly ordinary refusal.

---

## Phase 7 — Verification

**Goal:** prove the pipeline works before a real document depends on it.

### Functional checklist

- [ ] A user can register a signature (draw **and** upload paths).
- [ ] A second registration attempt is refused with
      `PrimarySignatureExistsException` until the first is revoked — this guard
      is deliberate.
- [ ] Signing produces `status = signed`, `signed_document_path`,
      `signed_document_hash`, `certificate_fingerprint`.
- [ ] The signed PDF opens in Acrobat and shows a signature panel.
- [ ] Editing the signed PDF and reopening it triggers the DocMDP warning.
- [ ] Re-uploading a downloaded signature PNG from another browser is rejected
      (machine lock on) — this is the screenshot-forgery path.
- [ ] Signature images are **not** reachable by guessing a storage URL; only the
      signed route works, and it expires.
- [ ] *(Track C)* Signing out of order raises `OutOfSequenceException` in
      sequential mode.
- [ ] *(Track C)* The last signature's `document_hash` equals the previous
      signature's `signed_document_hash` — the chain is intact.

### Automated

```bash
composer test
composer analyse          # phpstan level 8 over src/
composer format           # pint
```

In your own app, write at minimum: one feature test that signs a record end to
end and asserts the row transitions to `signed`, and one that asserts a
tampered/foreign signature image is rejected.

---

## Phase 8 — Production rollout

**Goal:** it keeps working after the first deploy.

1. **Deploy script.** Add `php artisan filament:assets` after every deploy that
   updates this package. Stale JS means the signature pad silently fails to
   mount.

2. **Queue worker** — only if you opted into `->queued()` signing:

   ```bash
   php artisan queue:work --queue=${SIGNATURE_QUEUE:-default}
   ```

   Signing is CPU-heavy (RSA + PDF rewrite); give it its own queue if volume is
   real.

3. **Backups.** Three things must be in your backup and secret-rotation
   procedures, and losing any one of them is unrecoverable:
   - `storage/app/certs/ca.key` and `ca.crt`
   - `APP_KEY` (decrypts stored certificate passwords)
   - the `.pfx` files on the storage disk

4. **Monitoring.** Listen for the package events and log or alert on them:
   `CertificateIssued`, `DocumentSigned`, `SignatureRevoked`,
   `SigningSessionOpened`, `SignatureRequested`, `SignatureDeclined`,
   `SignatureAutoAffixed`, `SigningSessionCompleted`. Alert on
   `SignatureAutoAffixed` volume in particular — it is the event that says the
   server signed while the owner was absent.

5. **Retention.** `digital_signature_audits` is append-only by design. Decide
   how long you keep it and say so in your data-retention policy; do not prune
   it casually, since it is the evidence trail for every auto-affix.

6. **Certificate expiry.** Default lifetime is 3650 days
   (`openssl.cert_lifetime`), but `UserCertificate::isExpired()` exists for a
   reason. Schedule a check that warns before certificates lapse.

---

## Appendix A — Class-name reference

Host apps import **one stable name** regardless of Filament version; the service
provider aliases it to the v3 or v4/v5 implementation at register time.

| Purpose | Import |
|---|---|
| Sign action, table row | `Kukux\DigitalSignature\Filament\Actions\SignDocumentAction` |
| Sign action, page header | `…\Filament\Actions\SignDocumentHeaderAction` |
| Request signatures, header | `…\Filament\Actions\RequestSignaturesAction` |
| Request signatures, table row (v3) | `…\Filament\Actions\RequestSignaturesTableAction` |
| Signatory block | `…\Filament\Components\SignatoryPanel` |
| Signature thumbnail column | `…\Filament\Columns\SignatureColumn` |
| Signature pad field | `…\Filament\Fields\SignaturePad` |
| Signature picker field | `…\Filament\Fields\SignaturePickerField` |
| Signatures resource | `…\Filament\Resources\SignatureResource` |
| Designer page | `…\Filament\Pages\PdfTemplateDesigner` |
| Signer page | `…\Filament\Pages\PdfTemplateSigner` |
| Inbox page | `…\Filament\Pages\SignatureInbox` |
| Floating launcher | `…\Filament\Livewire\SignatureLauncher` (Livewire name: `kukux-digital-signature.launcher`) |

Placement rules across majors:

| Placement | v3 | v4 / v5 |
|---|---|---|
| Table row | `SignDocumentAction` | `SignDocumentAction` |
| Page header | `SignDocumentHeaderAction` | either |
| Table row (request) | `RequestSignaturesTableAction` | either |
| Page header (request) | `RequestSignaturesAction` | either |

Using the header-suffixed names in headers is portable across all three.

Services worth knowing: `SignatureManager` (`store`, `storeForDocument`,
`storeDelegated`, `sign`, `embedAndFinalize`, `revoke`), `CertificateService`
(`getOrCreate`, `issue`, `load`, `revoke`), `SignatoryRouter` (`routeFor`,
`blockers`, `isRoutable`), `SigningSessionManager` (`open`, `sign`,
`applySignature`, `decline`, `cancel`, `completeIfFinished`),
`PdfTemplateRegistry`.

Version probe: `FilamentVersion::major()` → 3 | 4 | 5,
`FilamentVersion::usesSchemas()` → bool. Override with
`signature.filament_version` only when forcing a branch in tests.

---

## Appendix B — Environment variables

```bash
# Drivers
SIGNATURE_CERT_DRIVER=openssl        # openssl | cfssl
SIGNATURE_PDF_DRIVER=fpdi            # fpdi | tcpdf
CFSSL_HOST=http://localhost:8888
CFSSL_PROFILE=client

# Storage
SIGNATURE_DISK=local                 # must be a PRIVATE disk

# Queue (only if using ->queued())
SIGNATURE_QUEUE=default
SIGNATURE_QUEUE_CONNECTION=

# Security
SIGNATURE_MACHINE_LOCK=true
SIGNATURE_CRL_ENABLED=false
SIGNATURE_TSA_URL=                   # e.g. https://freetsa.org/tsr

# Sessions & consent
SIGNATURE_SEQUENCE_MODE=sequential   # sequential | parallel
SIGNATURE_SESSION_EXPIRY_DAYS=
SIGNATURE_MULTI_MODE=progressive     # progressive | incremental
SIGNATURE_AUTO_AFFIX_MODE=approval   # approval | delegated | implicit
SIGNATURE_ALLOW_IMPLICIT_AFFIX=false
SIGNATURE_AUTO_AFFIX_NOTIFY=true
SIGNATURE_GRANT_DAYS=365

# UI — floating launcher (the default entry point)
SIGNATURE_LAUNCHER_ENABLED=true
SIGNATURE_LAUNCHER_REPLACES_NAV=true   # false = launcher AND sidebar items
SIGNATURE_LAUNCHER_POSITION=bottom-right
SIGNATURE_LAUNCHER_ICON=heroicon-o-pencil-square
SIGNATURE_LAUNCHER_LABEL=Signatures
SIGNATURE_LAUNCHER_COLOR=               # brand hex, e.g. #4f46e5
SIGNATURE_LAUNCHER_POLL=60              # badge refresh seconds; 0 = off
SIGNATURE_LAUNCHER_HIDE_WHEN_EMPTY=false

# UI — pages and resource
SIGNATURE_RESOURCE_ENABLED=true
SIGNATURE_RESOURCE_ICON=heroicon-o-pencil-square
SIGNATURE_RESOURCE_GROUP=
SIGNATURE_RESOURCE_SORT=
SIGNATURE_RESOURCE_LABEL=Signatures
SIGNATURE_INBOX_ENABLED=true
SIGNATURE_INBOX_NAV=true
SIGNATURE_INBOX_LABEL="Awaiting my signature"
SIGNATURE_DESIGNER_DPI=144

# Testing only
SIGNATURE_FILAMENT_VERSION=
```

---

## Appendix C — Schema

Created in dependency order; every foreign key's target exists before the table
that references it.

| # | Table | Holds |
|---|---|---|
| 1 | `digital_user_certificates` | Per-user X.509 certificate + encrypted key |
| 2 | `digital_signing_sessions` | One document's journey: frozen base PDF, running document, status, mode |
| 3 | `digital_signatures` | Every signature, plus `signing_session_id` / `slot_key` / `sequence` / `parent_signature_id` for the chain |
| 4 | `signature_positions` | Where a signature was stamped |
| 5 | `digital_signature_requests` | One (session, slot): assignee, frozen placement, decision |
| 6 | `digital_signature_delegations` | Standing consent: user, signature, template, role, expiry, uses |
| 7 | `digital_signature_audits` | Append-only log of every consequential act |
| 8 | `digital_pdf_template_slots` | Designer-saved coordinates per (template, slot) |

---

## Appendix D — Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Signatures / Awaiting my signature missing from the sidebar | Expected: the floating launcher replaces them. | Use the launcher, or set `SIGNATURE_LAUNCHER_REPLACES_NAV=false` to show both. |
| No floating button on any page | Launcher disabled, or the plugin isn't on this panel. | Check `launcher.enabled` and `withoutFloatingLauncher()`, then that `SignaturePlugin::make()` is on that panel. |
| Floating button appears but the slide-over is empty and never loads | Livewire/Alpine assets not loading on the page. | The launcher needs the panel's own Livewire+Alpine; check the browser console on a standard panel page. |
| `Plugin [signature] is not registered for panel [admin]` | Resource discovered from `vendor/` without the plugin. | Add `SignaturePlugin::make()` to that panel's provider. |
| Signature pad renders as an empty box | JS not published, or published from an older version. | `php artisan filament:assets`, hard-reload. |
| `PrimarySignatureExistsException` on registration | User already has an active reusable signature. | Revoke the existing one first — the single-primary rule is deliberate. |
| `MachineBindingException` on re-upload | Machine lock on and the device/IP changed. | Expected. Set `SIGNATURE_MACHINE_LOCK=false` only if multi-device signing is a real requirement. |
| `ForgedSignatureException` | Image HMAC failed, or the signature belongs to another user. | Users must sign with their **own** registered signature; screenshots are rejected by design. |
| `IncrementalSigningUnsupportedException` | `multi_signature.mode = incremental` with a bundled driver. | Switch back to `progressive` or supply a `SupportsIncrementalSigning` driver. |
| `OutOfSequenceException` | Sequential session, signing ahead of turn. | Expected. Use `parallel` if roles genuinely have no order. |
| Designer page is blank / rasterizer error | Imagick or Ghostscript missing. | Install both, or implement `RendersSamplePageImage` (render at 72 DPI). |
| Reader says "signature validity unknown" | Certificates are self-signed. | Configure `ca_cert_path` / `ca_key_path`, or use the CFSSL driver. |
| Signed PDF shows only one signature on a multi-signatory doc | `progressive` mode; FPDI rewrites the file each pass. | Expected and documented. The visible stamps and DB chain are all there. |
| Slot coordinates lost after a rename | Template key changed. | Keep `key()` stable forever; it is the `template_key` on the slots table. |

---

## Related documents

- [Installation](installation.md) · [Configuration](configuration.md) · [Model Setup](model-setup.md)
- [Filament Components](filament-components.md) · [Signing Workflow](signing-workflow.md)
- [Ad-hoc Signing](ad-hoc-signing.md) · [On-Demand PDF Signing](on-demand-pdf-signing.md)
- [PDF Templates](pdf-templates.md) · [Certificates](certificates.md)
- [Signatory Routing](signatory-routing.md) · [Security](security.md)
