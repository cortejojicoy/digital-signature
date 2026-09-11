# Concept: Signatory Routing & Auto-Affix

> **Status: implemented.** See [Signatory Routing & Multi-Signatory
> Documents](../signatory-routing.md) for the shipped documentation — that is
> the page to read if you want to *use* this. This document is kept as the
> design record: the reasoning, the trade-offs, and the questions that were
> open at the start.
>
> **Where the build diverged from this plan** (each is explained in place below):
>
> 1. **True incremental PAdES is not reachable with the bundled drivers.** FPDI
>    rebuilds the document on every pass, so it cannot append a signature
>    without invalidating the ones before it. Shipped as `progressive` mode
>    (every visible signature + a verifiable DB hash chain, one surviving PKCS#7
>    block) with `incremental` gated behind a driver capability that fails
>    loudly rather than degrading silently. See §5.
> 2. **`SignatoryPanel` needed no version split.**
>    `Filament\Infolists\Components\Entry` is the entry base class on v3, v4
>    and v5 alike — §8's claim that it moved to `Schemas\Components` was wrong.
>    Only the host's *call site* differs. See §8.
> 3. **Filament pages DID need a split, which §8 underestimated.**
>    `Page::$view` is static on v3 and an instance property on v4+, and
>    redeclaring it the wrong way is a hard PHP fatal. The existing designer and
>    signer pages were already broken on the installed Filament 5. See §8.
> 4. **Two further latent bugs surfaced and were fixed**: `HasPdfTemplate`
>    declared `$signaturePdfTemplate` without a default, making the documented
>    host usage a fatal error; and `BladePdfTemplate` accepted a `renderer`
>    config key but never used it.
> 5. **The new columns on `digital_signatures` are declared in its create
>    migration**, not bolted on by a later `Schema::table()`. The package is
>    pre-release, so an `add_x_to_y` migration would be carrying history that
>    never existed. That forced `digital_signing_sessions` to be created before
>    `digital_signatures` (MySQL and Postgres require a foreign key's target to
>    exist at `CREATE TABLE` time), so the migrations were renumbered into
>    dependency order. See §5's schema sketch.

---

## 1. The scenario

A host app (e.g. an HR / academic system) has a **Performance Task**. Each
Performance Task produces an **Accomplishment Report (AR)** — a PDF with a
signature block at the bottom:

```
    Prepared by:                Attested by:                Noted by:

  ______________            ______________            ______________
   Juan Dela Cruz            Maria Santos              Dr. Reyes
   Faculty                   Dept. Head                Dean
```

Three named **roles**. Each role is filled by a **person tagged on the AR
record** (`prepared_by_id`, `attested_by_id`, `noted_by_id`, or relations).

Each of those people has, separately and earlier, registered their own
signature image inside their own admin panel account — the existing
`Signatures` resource this plugin already ships.

**The desired behaviour:** when an AR is produced (or reaches the right
workflow state), the signature of whoever is tagged in each role is placed
into the matching slot on the PDF — without anyone re-drawing, re-uploading,
or manually dragging an image onto a canvas — and **without weakening the
security guarantees the plugin already enforces**.

The whole thing must be **pluggable ad-hoc**: a host app drops it onto any
resource/model it already has, declaratively, without forking the package.

---

## 2. What exists today vs. what this needs

| Capability | Today | Needed |
|---|---|---|
| Named drop zones on a PDF template | ✅ `SlotDefinition` (`key`, `label`, `required`, defaults) | Same, plus a **role binding** |
| Saved coordinates per slot per template | ✅ `digital_pdf_template_slots` | Same — reused as-is |
| Visual placement designer | ✅ `PdfTemplateDesigner` | Same — reused as-is |
| A user's reusable signature image | ✅ "primary" `Signature` (`signable_id IS NULL`, `status = active`), one per user | Same — the source of truth for auto-affix |
| Resolving *which person* fills a slot | ❌ | **New** — `SignatoryResolver` |
| Auto-selecting that person's signature | ❌ | **New** — auto-affix pipeline |
| Stamping N signatures into one PDF | ❌ hard-blocked, one placement per call | **New** — incremental multi-signature |
| Per-signatory approval / status tracking | ❌ | **New** — signing session |
| Consent to use someone's signature on your behalf | ❌ (guard forbids it outright) | **New** — delegation grants |
| Filament v3 support | ⚠️ partial (resource only) | **Full** — v3 / v4 / v5 |

### The specific guard that blocks this today

[`SignatureManager::storeForDocument()`](../../src/Services/SignatureManager.php) refuses
to use anybody else's signature:

```php
if ((int) $source->user_id !== $signerUserId) {
    throw new ForgedSignatureException(
        'You can only sign documents with a signature registered to your own account.'
    );
}
```

That check is correct and must stay. Auto-affix is therefore **not** "let the
clerk stamp the Dean's signature" — it is "**the Dean's own signature is applied
on the Dean's behalf, under an authorisation the Dean granted**." Section 6 is
where that distinction gets teeth.

---

## 3. Core concept: a slot gains a *role*

Today a slot is a rectangle with a name. The new concept adds a second
dimension: **who owns it**.

```
  SlotDefinition                      SignatoryBinding
  ─────────────────                   ──────────────────────
  key:    'attested_by'      ──────▶  resolves the User for
  label:  'Attested by'               this slot on a given record
  required: true
```

So a template's slot declaration grows from *where* to *where + whose*:

```php
new SlotDefinition(
    key:      'attested_by',
    label:    'Attested by',
    required: true,
    // NEW ↓
    signatory: 'attestedBy',        // relation, attribute, or closure
    role:      'attester',          // optional semantic label for policies
    order:     2,                   // optional: enforce signing sequence
);
```

### Three ways to declare the binding

All three resolve to a `User` (or `null` = nobody tagged yet).

```php
// 1. Relation or attribute name on the record
'signatory' => 'attestedBy',          // $record->attestedBy
'signatory' => 'attested_by_id',      // → User::find($record->attested_by_id)

// 2. Closure — full control
'signatory' => fn (Model $record) => $record->department->head,

// 3. Invokable class — testable, reusable across templates
'signatory' => \App\Signatories\DepartmentHead::class,
```

Config-array form (the plug-and-play path stays plug-and-play):

```php
// config/signature.php
'templates' => [
    'accomplishment-report' => [
        'label'    => 'Accomplishment Report',
        'view'     => 'pdf.accomplishment-report',
        'signable' => \App\Models\AccomplishmentReport::class,
        'slots'    => [
            'prepared_by' => ['label' => 'Prepared by', 'signatory' => 'preparedBy', 'order' => 1, 'required' => true],
            'attested_by' => ['label' => 'Attested by', 'signatory' => 'attestedBy', 'order' => 2, 'required' => true],
            'noted_by'    => ['label' => 'Noted by',    'signatory' => 'notedBy',    'order' => 3, 'required' => true],
        ],
    ],
],
```

That array is the **entire** host-app integration for the common case. The AR
model already has `preparedBy()` / `attestedBy()` / `notedBy()` relations
because the app needed them anyway.

---

## 4. New pieces to build

| Piece | Kind | Responsibility |
|---|---|---|
| `Contracts/SignatoryResolver` | interface | `resolve(Model $record, SlotDefinition $slot): ?Authenticatable` |
| `Signatories/RelationResolver` | class | Handles the string form (relation / `*_id` attribute) |
| `Signatories/CallableResolver` | class | Handles the closure / invokable form |
| `Services/SignatoryRouter` | service | For a record + template, produce the full slot → user → signature map |
| `Models/SignatureRequest` | model | One row per (document, slot) — the workflow unit |
| `Models/SignatureDelegation` | model | A user's standing consent to auto-affix (see §6) |
| `Services/SigningSession` | service | Orchestrates N signatures into one PDF (see §5) |
| `Filament/SignatoryPanel` | infolist entry | Drop-in "who has signed" block for any resource |
| `Filament/Actions/RequestSignaturesAction` | action | Kick off / advance the session |

### `SignatoryRouter` output — the thing everything else consumes

```php
$router->routeFor($accomplishmentReport);

// [
//   'prepared_by' => SignatoryRoute {
//       slot:      SlotDefinition,
//       position:  PdfTemplateSlot   (saved coords, or slot defaults),
//       user:      User #12 Juan Dela Cruz,
//       signature: Signature #88     (their active primary) | null,
//       state:     'ready' | 'awaiting_registration' | 'unassigned'
//                  | 'awaiting_consent' | 'signed' | 'declined',
//   },
//   'attested_by' => …,
//   'noted_by'    => …,
// ]
```

Every UI surface — the Filament panel block, the PDF stamper, the "who's
blocking this?" report — reads from this one structure.

### State machine per slot

```
 unassigned ──(person tagged)──▶ awaiting_registration
                                      │ (they register a signature)
                                      ▼
                                 awaiting_consent
                                      │ (delegation grant OR explicit approve)
                                      ▼
                                    ready ──(stamped + PKCS#7)──▶ signed
                                      │
                                      └──(they decline)──▶ declined
```

`awaiting_registration` is the useful new failure mode: the AR can't be
finalised because *Dr. Reyes has never uploaded a signature*, and the UI can
say exactly that instead of rendering a blank line.

---

## 5. The hard blocker: N signatures in one PDF

This is the biggest piece of real engineering and it gates the whole feature.

**Today:** [`PdfTemplateSignerController::finalize()`](../../src/Http/Controllers/PdfTemplateSignerController.php)
explicitly rejects more than one placement:

```php
if (count($data['placements']) > 1) {
    return response()->json([
        'error' => 'Multi-slot signing is not yet supported. …',
    ], 422);
}
```

Because each signing pass re-renders and signs the **unsigned source**, three
signatories today would produce three separate signed PDFs, not one PDF with
three signatures.

### Two viable approaches

**A. Single-pass batch signing.** Stamp all N images, then apply one PKCS#7
signature block.

- ✅ Simple; works with today's `FpdiDriver` with modest changes.
- ❌ Cryptographically it's **one** signature. Whose certificate signs it? The
  system's, not the three humans'. Each human's identity would live only in
  the visual stamp + HMAC PNG metadata, not in the PDF's signature panel.
- Acceptable only if the legal requirement is "visible signature block +
  tamper-evidence", not "three independently verifiable signer certificates".

**B. Incremental multi-signature (PAdES).** Each signatory signs the output of
the previous one as an incremental update.

- ✅ Correct. Adobe/Preview show three distinct signatures, each with its own
  certificate, timestamp and coverage range.
- ❌ Requires real changes:
  - `PdfSignerDriver` must accept an already-signed PDF as input and append
    without invalidating prior signatures (no full re-write, no re-render).
  - **DocMDP conflict:** the plugin currently applies `DocMDP P=2` on every
    signature. DocMDP is only legal on the *first* signature in a document.
    Signatures 2..N must be **approval signatures** (no DocMDP), and the
    first one's `P` value must permit the later ones. This needs an explicit
    `isFirstSignature` branch in the driver.
  - `document_hash` semantics change: signature #2's "before" hash is
    signature #1's "after" hash. The chain becomes auditable — good, but the
    `digital_signatures` rows need a `parent_signature_id` or a
    `signing_session_id` + `sequence` to express it.
  - The visual stamp for slot #2 must be drawn into the incremental update,
    not the base document.
  - `renderFor($record)` must be called **once** for the session, not once
    per signatory — otherwise late signers sign a freshly-rendered PDF and
    orphan the earlier signatures.

**Recommendation:** build **B**, but keep **A** available as a config flag
(`signature.multi_signature.mode = 'incremental' | 'batch'`) for apps whose
legal bar is lower and whose hosting can't support the incremental path.

> **What was actually built — and why it differs.** B turned out not to be
> reachable in PHP with the bundled dependencies at all. FPDI works by
> re-importing every page and writing a new document; there is no code path
> through it that appends an incremental update, so *any* second signature
> destroys the first one's PKCS#7 block. This is a property of the library,
> not of how the plugin calls it.
>
> The shipped modes are therefore:
>
> - **`progressive`** (default, replaces "A"). Each signatory's stamp is
>   applied to the previous signatory's *output* and the document is re-signed
>   with **that signatory's own certificate** — not a system certificate, which
>   is what made approach A unattractive. The finished PDF carries every
>   visible signature; the database carries a verifiable hash chain
>   (signature N's `document_hash` == signature N-1's `signed_document_hash`,
>   plus `parent_signature_id`). The honest limitation, stated in the config
>   comment and the shipped docs: only the most recent PKCS#7 block survives
>   inside the file, so a reader shows one cryptographic signature, not N.
> - **`incremental`** (B). The session state machine, chaining and DocMDP
>   reasoning are all in place; the driver is not. A driver must declare
>   [`SupportsIncrementalSigning`](../../src/Contracts/SupportsIncrementalSigning.php)
>   (e.g. a SetaPDF-Signer-backed one), and selecting the mode without such a
>   driver throws `IncrementalSigningUnsupportedException` at sign time rather
>   than quietly producing a document that claims three signatures and carries
>   one.
>
> Open question 1 therefore still decides the project, but it now decides
> whether you need to *acquire a signing library*, not whether to write more
> code here.

### Consequence: a `SigningSession`

Multi-signature needs an owning object:

```
digital_signing_sessions
  id, uuid, signable_type, signable_id, template_key,
  base_document_path,        ← rendered ONCE, frozen
  current_document_path,     ← advances with each signature
  status,                    ← open | complete | cancelled | expired
  sequence_mode,             ← 'parallel' | 'sequential'
  signing_mode,              ← 'progressive' | 'incremental'
  opened_by, expires_at, created_at, completed_at, cancelled_at

digital_signature_requests
  id, uuid, signing_session_id, slot_key, role,
  user_id,                   ← resolved signatory
  signature_id,              ← FK to digital_signatures once signed
  sequence, required, state,
  page, x, y, width, height, ← frozen placement
  requested_at, responded_at, declined_reason
```

> **As built:** `digital_signatures` gained `signing_session_id`, `slot_key`,
> `sequence` and `parent_signature_id` — declared in its own create migration,
> which is why `digital_signing_sessions` is created first. `sequence_mode`
> shipped as planned; `signing_mode` was added for the modes discussed above.

`sequence_mode: 'sequential'` is what the AR actually wants: *Noted by* (the
Dean) should not be able to sign before *Attested by* (the Dept. Head). The
`order` on the slot definition drives this.

---

## 6. Security: the consent problem

**This is the part to settle before writing code.** "Automatically filled" is
one short phrase away from "the system forges signatures."

Existing protections that must survive intact:

- HMAC-signed PNG metadata (`Sig-User-Id`, `Sig-Machine-Hash`, `Sig-Hmac`)
- Machine binding + DB cross-validation on re-upload
- `DuplicateSignatureGuard` (same image under a different user → reject)
- PKCS#7 with the **signer's own** certificate
- `storeForDocument()`'s ownership guard

None of those are the issue. The issue is: *auto-affix means a signing event
happens without the signer being present in that request.* Three models, in
increasing order of safety:

### Model 1 — Explicit per-document approval (safest, default)

Auto-fill resolves **who** and **which signature image**, and pre-places it —
but the signature stays `awaiting_consent`. The signatory gets an inbox item
("3 ARs await your signature"), opens it, sees the filled-in preview, clicks
**Sign**. The PKCS#7 signature is produced in *their* authenticated request,
with *their* certificate.

- ✅ Zero new trust assumptions. The automation is purely "you never have to
  find the document or re-draw your signature."
- ✅ Works with the existing ownership guard untouched.
- ❌ Still N human actions. Doesn't fully match "automatically filled."

### Model 2 — Standing delegation grant (the likely real answer)

The signatory opts in, **once**, from their own panel:

> ☑ Auto-apply my signature when I am tagged as **Attested by** on an
> **Accomplishment Report** — until *2027-01-01*.

Stored as a `SignatureDelegation` row scoped by `(user_id, template_key,
role, expires_at, max_uses?)`, created only in the signatory's own
authenticated session, revocable at any time, and **fully audited** (every
auto-affix writes an audit row naming the grant that authorised it).

- ✅ Genuinely automatic after one setup step.
- ✅ Consent is real, explicit, scoped, expiring, revocable.
- ⚠️ Requires server-side access to the signer's certificate password. The
  plugin already stores `certificate_password` encrypted on the `Signature`
  row, so this is technically available — but a standing grant turns that
  from "convenience at sign time" into "the server can sign as this user for
  the next 12 months." That must be a conscious, documented decision, and
  ideally the grant should hold its *own* envelope-encrypted key rather than
  reusing the row-level one.

### Model 3 — Unconditional auto-affix (do not ship as default)

Tagging someone is treated as consent. Signature is applied with no grant.

- ❌ Any user who can edit the AR record can cause the Dean's certificate to
  sign an arbitrary document. This is signature forgery with extra steps.
- Offer at most as `signature.auto_affix.mode = 'implicit'`, off by default,
  loudly documented, and ideally gated behind a Laravel policy the host must
  implement.

### Recommendation

Ship **Model 1** as the default and **Model 2** as opt-in, with the mode
selectable per template:

```php
'accomplishment-report' => [
    // 'approval' (default) | 'delegated' | 'implicit'
    'auto_affix' => 'delegated',
],
```

### Non-negotiables regardless of model

1. Every auto-affixed signature writes an **audit row**: who was signed for,
   who/what triggered it, which grant authorised it, request IP/UA, timestamp.
2. A `SignatureDelegation` can only be created or extended in the grantor's
   own authenticated session — never by an admin acting on their behalf.
3. Revoking a signature or a grant is immediate and blocks future auto-affix.
4. Auto-affixed signatures are visually and structurally distinguishable in
   the audit trail (`source = 'auto'` alongside today's `draw` / `upload`).
5. The signatory gets a notification for **every** auto-affix, not just the
   grant. Silent signing is unacceptable even with consent.
6. `machine_fingerprint` cannot be meaningfully captured for an auto-affix —
   the signer isn't there. Store the **originating** fingerprint from the
   primary signature and mark the event as server-originated, rather than
   fabricating a fingerprint that would corrupt the machine-binding check.

---

## 7. The ad-hoc plug-in surface

What a host developer writes to get the AR working end to end.

### Step 1 — Declare the template (config, ~12 lines)

As in §3.

### Step 2 — Make the model signable (2 lines, already shipped)

```php
class AccomplishmentReport extends Model implements Signable
{
    use HasPdfTemplate;

    protected string $signaturePdfTemplate = 'accomplishment-report';

    public function preparedBy(): BelongsTo { return $this->belongsTo(User::class, 'prepared_by_id'); }
    public function attestedBy(): BelongsTo { return $this->belongsTo(User::class, 'attested_by_id'); }
    public function notedBy(): BelongsTo    { return $this->belongsTo(User::class, 'noted_by_id'); }
}
```

### Step 3 — Drop the panel into the existing resource (1 line)

```php
use Kukux\DigitalSignature\Filament\SignatoryPanel;

public static function infolist(Schema $schema): Schema
{
    return $schema->components([
        // …existing entries…
        SignatoryPanel::make('signatories'),   // ← the whole feature
    ]);
}
```

Which renders:

```
┌─ Signatories ──────────────────────────────────────────────────┐
│ ① Prepared by   Juan Dela Cruz     ✅ Signed  2026-09-08 14:22 │
│ ② Attested by   Maria Santos       ⏳ Awaiting signature        │
│ ③ Noted by      Dr. Reyes          🔒 Blocked — ② must sign first│
│                                                                 │
│ [ Request signatures ]  [ Preview PDF ]  [ Download signed ]    │
└─────────────────────────────────────────────────────────────────┘
```

### Step 4 — Place the slots visually (once, in the designer)

`/admin/signature-templates/accomplishment-report/design` — already shipped.
Drag `prepared_by`, `attested_by`, `noted_by` onto the signature lines, save.
Done forever for every AR.

### Step 5 — Nothing

Table column, "who's blocking" filter, notifications, and the signatory's own
inbox come from the plugin.

### Escape hatches

- `SignatoryPanel::make()->only(['attested_by'])` — partial rendering
- `SignaturePlugin::make()->resolveSignatoriesUsing(fn (...) => …)` — global override
- `SignatoryRouter` is a public service — call it directly from a job, a
  command, or a controller for headless flows
- Events: `SignatureRequested`, `SignatureAutoAffixed`, `SignatureDeclined`,
  `SigningSessionCompleted` — alongside today's `DocumentSigned`

---

## 8. Filament v3 / v4 / v5 compatibility

`composer.json` already declares `filament/filament: ^3.0 || ^4.0 || ^5.0`,
but only the **resource** is actually version-bridged.

### What's bridged today

[`ResourceResolver`](../../src/Filament/Resources/ResourceResolver.php) detects the
major version by probing for `Filament\Schemas\Schema`, then `class_alias`es
the canonical `SignatureResource` to `V3\SignatureResource` or
`V4\SignatureResource`. v5 currently maps to the V4 implementation.

### What is *not* bridged — the real v3 gaps

| Component | Problem on v3 |
|---|---|
| `SignDocumentAction` | Extends `Filament\Actions\Action`. On v3 that's a **page** action; table actions must extend `Filament\Tables\Actions\Action`. Using it in `->actions([...])` on v3 breaks. |
| `PdfTemplateDesigner` / `PdfTemplateSigner` | **Worse than "needs verification": both were already fatally broken on the installed Filament 5.** `Page::$view` is a static property on v3 and an instance property on v4+, and PHP rejects redeclaring a non-static property as static outright — `Cannot redeclare non static Filament\Pages\Page::$view as static`. Both pages are now split V3/V4 with the behaviour in a shared trait. A related trap: v5 widened `$navigationIcon` to `BackedEnum\|string\|null`, so the inbox page overrides `getNavigationIcon()` instead — a narrowed *return* type is legal covariance on every version, a narrowed *property* type is not. |
| `SignaturePad`, `SignaturePickerField` | Extend `Filament\Forms\Components\Field` — stable across versions, but the v4/v5 Schemas rendering path needs a test. |
| `SignatureColumn` | Extends `Filament\Tables\Columns\Column` — stable, needs a test. |
| ~~Infolist entries (planned `SignatoryPanel`)~~ | **This was wrong.** `Filament\Infolists\Components\Entry` is the entry base class on v3, v4 *and* v5 — it did not move to `Schemas\Components`. `SignatoryPanel` ships as a single class; only the host's call site differs (`Infolist::schema()` vs `Schema::components()`), which is their code, not ours. |
| `->schema()` vs `->components()` | Diverges across versions inside any component that composes children. |
| `Notification` | Stable — safe. |

### Proposed strategy

**1. A version facade, not scattered `if`s.**

```php
Kukux\DigitalSignature\Support\FilamentVersion::major();      // 3 | 4 | 5
Kukux\DigitalSignature\Support\FilamentVersion::usesSchemas(); // bool
```

Reads the real installed version via `Composer\InstalledVersions::getVersion('filament/filament')`
with the current class-probe as fallback — so v5 is genuinely detected as 5
instead of being assumed to behave like 4.

**2. Extend the V3/V4 split pattern to every version-sensitive component.**
The resolver already proves the approach; generalise it:

```
src/Filament/
  Actions/
    V3/SignDocumentAction.php     ← extends Tables\Actions\Action
    V4/SignDocumentAction.php     ← extends Actions\Action
    ActionResolver.php
  Components/
    V3/SignatoryPanel.php
    V4/SignatoryPanel.php
    ComponentResolver.php
```

Host apps keep importing one canonical name; the provider aliases it in
`register()` before anything resolves.

**3. A shared `ComponentResolver` base** so each new component isn't a
copy-paste of `ResourceResolver` — one abstract class, one line per component.

**4. CI matrix.** This is the part that actually keeps the promise honest:

| Filament | Laravel | Testbench | PHP |
|---|---|---|---|
| 3.x | 11 | 9 | 8.2, 8.3 |
| 4.x | 11, 12 | 9, 10 | 8.2, 8.3, 8.4 |
| 5.x | 12 | 10 | 8.3, 8.4 |

Without this matrix, `^3.0 \|\| ^4.0 \|\| ^5.0` is an aspiration, not a
guarantee. The test suite already uses Orchestra Testbench, so this is mostly
a GitHub Actions job plus `composer update --with` constraint permutations.

**5. Decide the v5 position explicitly.** v5 may introduce its own breaking
changes; `match` currently lumps `5, 4` together. Keep that as the default
*only* until v5 is actually tested, and add a `V5\` namespace the moment the
matrix shows a divergence.

---

## 9. Proposed phasing

Each phase is independently shippable and useful.

| Phase | Delivers | Status |
|---|---|---|
| **0. Compat hardening** | `FilamentVersion` facade, `ComponentResolver` base, V3 `SignDocumentAction`, split pages, CI matrix across v3/v4/v5 | ✅ shipped |
| **1. Signatory routing (read-only)** | `SignatoryResolver`, `SignatoryRouter`, slot `signatory`/`role`/`order`, `SignatoryPanel` | ✅ shipped |
| **2. Requests & approval (Model 1)** | `SigningSession` + `SignatureRequest`, inbox, approve/decline, sequential ordering | ✅ shipped |
| **3. Multi-signature PDF** | Session-frozen base document, hash chain, `sequence`/`parent_signature_id` | ✅ shipped as `progressive`; `incremental` needs a capable driver (see §5) |
| **4. Delegation & auto-affix (Model 2)** | `SignatureDelegation`, grant API, audit trail, per-auto-affix notification, `source = 'auto'` | ✅ shipped |
| **5. Polish** | Reminders, bulk "request signatures", downloadable audit certificate | ◻️ not built — session expiry and the "who's blocking" report are in |

Phases 1–2 alone already remove most of the pain: the right people are found
automatically, the document finds them instead of the reverse, and the
placement is pre-filled. Phase 4 is the only one that needs the consent
architecture fully settled.

**How the open questions were resolved in the build.** Each was given the
safest defensible default, configurable where the answer is genuinely the
deployment's to make:

| # | Question | Shipped answer |
|---|---|---|
| 1 | Legal bar | Both modes exist; `progressive` is the default and its limitation is stated plainly. Still yours to decide. |
| 2 | Consent model | `approval` is the default; `delegated` is opt-in; `implicit` needs a second acknowledgement. |
| 3 | Sequential or parallel | `sequence_mode`, defaulting to `sequential`, overridable per template. |
| 4 | Document mutability | The session freezes the PDF at open. Later record edits do not change what is being signed. A `content_hash` watch was **not** built. |
| 5 | Re-tagging | Terminal requests are immutable; only unresolved slots are reassigned by `refreshAssignments()`. |
| 6 | Missing registration | `awaiting_registration` state; `RequestSignaturesAction` refuses to open a stalled session and names who is missing. |
| 7 | Certificate password | Reuses the row-level encrypted password. A grant-scoped envelope key was **not** built — it remains the stated improvement for `delegated` mode. |
| 8 | Inbox location | Plugin-provided page, disableable per panel via `withoutInbox()`. |
| 9 | Non-user signatories | Out of scope. A signatory must be an app user with a certificate. |

---

## 10. Open questions

1. **Legal bar.** Does the AR need three independently verifiable signer
   certificates (→ Phase 3 incremental, mandatory), or is a visible signature
   block with tamper-evidence enough (→ batch mode is fine)? This single
   answer changes the size of the project substantially.
2. **Consent model.** Is Model 1 (per-document approval) acceptable to the
   users, or is Model 2 (standing delegation) the actual requirement? If
   Model 2 — is the org comfortable with the server holding the ability to
   sign as a user for the grant's lifetime?
3. **Sequential or parallel?** Must *Noted by* wait for *Attested by*, or can
   all three sign in any order?
4. **Document mutability.** Can an AR's content change after signature #1?
   If yes, every prior signature must be invalidated and the session reset —
   needs an explicit rule and a `content_hash` watch on the record.
5. **Re-tagging.** What happens when *Attested by* is changed to a different
   person after they've already signed? Void that slot's signature, or block
   the edit?
6. **Missing registration.** An AR needs the Dean, and the Dean has no
   signature registered. Block finalisation, or render the line blank and
   flag it?
7. **Certificate password for auto-affix.** Reuse the row-level encrypted
   `certificate_password`, or issue a grant-scoped key? The latter is more
   work and materially safer.
8. **Where does the signatory's inbox live?** A plugin-provided page, or an
   infolist/widget the host places itself? Plugin-provided is more
   plug-and-play; host-placed is more flexible.
9. **Non-User signatories.** Can a slot be filled by someone who is not an
   app user (external approver)? If so, the whole certificate story changes.

---

## 11. Related docs

- [PDF Templates](../pdf-templates.md) — slots, designer, signer page (shipped)
- [Model Setup](../model-setup.md) — `Signable`, `HasSignatures`
- [Security](../security.md) — HMAC metadata, machine binding, forgery detection
- [Signing Workflow](../signing-workflow.md) — `SignatureManager` API, events, statuses
- [Filament Components](../filament-components.md) — current component surface
