# Signatory Routing & Multi-Signatory Documents

Some documents are signed by *roles*, not by whoever happens to open them. An
Accomplishment Report has a **Prepared by**, an **Attested by** and a **Noted
by**; each is a person tagged on the record, and each signs in their own right.

This page covers how the package finds those people, brings the document to
them, and combines their signatures into one PDF — and exactly what each
consent model does and does not permit.

> **New to the package?** Read [PDF Templates](pdf-templates.md) first. Routing
> builds directly on slots, the placement designer, and the `Signable` contract.

---

## The five-minute version

```php
// 1. config/signature.php — declare the roles
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

```php
// 2. The model — two traits, plus the relations the app already has
class AccomplishmentReport extends Model implements Signable
{
    use HasPdfTemplate, HasSignatories;

    protected string $signaturePdfTemplate = 'accomplishment-report';

    public function preparedBy(): BelongsTo { return $this->belongsTo(User::class, 'prepared_by_id'); }
    public function attestedBy(): BelongsTo { return $this->belongsTo(User::class, 'attested_by_id'); }
    public function notedBy(): BelongsTo    { return $this->belongsTo(User::class, 'noted_by_id'); }
}
```

```php
// 3. The resource — one entry, one action
SignatoryPanel::make('signatories');
RequestSignaturesAction::make();
```

4. Open `/admin/signature-templates/accomplishment-report/design` once and drag
   the three slots onto the signature lines.

That's the whole integration. Each signatory registers their signature once in
their own panel; from then on, being tagged on a record is enough for the
document to find them.

---

## How a slot gains a signatory

A slot used to be a named rectangle. It now answers a second question — *whose*
rectangle is it?

```php
new SlotDefinition(
    key:       'attested_by',
    label:     'Attested by',
    required:  true,
    signatory: 'attestedBy',   // who fills it
    role:      'attester',     // optional: scope for grants and policies
    order:     2,              // optional: signing sequence
);
```

### Four ways to bind a signatory

| Form | Example | When |
|---|---|---|
| Relation name | `'attestedBy'` | The record has a `belongsTo`. The common case. |
| Foreign key | `'attested_by_id'` | The record stores only an id, no relation. |
| Closure | `fn ($record) => $record->department->head` | Derived from something other than a direct column. |
| Invokable class | `\App\Signatories\DepartmentHead::class` | Reusable across templates; unit-testable on its own. |

An invokable class may implement [`SignatoryResolver`](../src/Contracts/SignatoryResolver.php)
for the richer `resolve($record, $slot)` signature, or just define `__invoke`.

A slot with no `signatory` is **unrouted** — nobody in particular owns it, and
whoever opens the signer page places their own signature. That is the original
behaviour, unchanged.

### A binding that can't resolve is not an error

If `attested_by_id` is null, or the relation's parent has been deleted, routing
reports the slot as `unassigned` rather than throwing. A half-filled record is
a normal state in a workflow, and the UI needs to be able to say *which* role
is missing.

---

## Reading the routing

[`SignatoryRouter`](../src/Services/SignatoryRouter.php) joins four things that
are otherwise independent: the template's slots, the designer's saved
coordinates, the people tagged on the record, and each person's signature
library.

```php
$routes = app(SignatoryRouter::class)->routeFor($report);
// or, via the trait:
$routes = $report->signatoryRoutes();

$routes['attested_by']->signerName();   // "Maria Santos"
$routes['attested_by']->signature;      // her active primary Signature, or null
$routes['attested_by']->position;       // ['page' => 1, 'x' => 300.0, …]
$routes['attested_by']->state;          // RouteState::AwaitingConsent
$routes['attested_by']->blockerMessage();
```

Every UI surface and the signing pipeline read this same structure, so the
panel and the signer can never disagree about who is blocking a document.

### Slot states

| State | Meaning | What fixes it |
|---|---|---|
| `unassigned` | Nobody is tagged for this role | Tag someone on the record |
| `awaiting_registration` | The tagged person has never registered a signature | They register one in their own panel |
| `awaiting_consent` | Everything is ready; waiting on them to sign | They sign (or a grant signs for them) |
| `ready` | Signable right now | — |
| `blocked` | Waiting on an earlier slot in a sequential session | The earlier signatory signs |
| `signed` | Done, embedded in the document | — |
| `declined` | The signatory refused | Reassign the role, or cancel |

`awaiting_registration` is the useful one: the AR can't be finalised because
*Dr. Reyes has never uploaded a signature*, and the UI says exactly that
instead of rendering a blank line.

`blocked` never replaces `unassigned` or `awaiting_registration` — those name a
problem somebody has to fix, and "waiting on an earlier signatory" would hide it.

### Useful shortcuts

```php
$report->signatureBlockers();      // ['noted_by' => 'Dr Reyes has not registered a signature yet.']
$report->isReadyForSignatures();   // false — opening a session now would stall
$report->isFullySigned();
$report->signedDocumentPath();
$report->pendingSignatureRequestFor(auth()->id());
```

---

## Signing sessions

A multi-signatory document needs an owner. `SigningSession` is it.

```php
$session = $report->openSigningSession();
```

Opening a session does two things that matter:

**It freezes the PDF.** The document is rendered once into
`base_document_path` and never re-rendered. Without that, the second signatory
would sign a fresh render and erase the first signatory's stamp.

**It creates one `SignatureRequest` per slot**, copying the placement in. A
later edit in the placement designer cannot retroactively move a signature
somebody has already been asked to apply.

Opening is idempotent — calling it again returns the existing open session with
assignments refreshed.

### Sequential vs parallel

```php
'sessions' => ['sequence_mode' => 'sequential'],   // default
```

Sequential honours `SlotDefinition::$order`: the Dean cannot sign before the
Department Head. A signature out of turn raises `OutOfSequenceException`.
Parallel lets any assigned signatory act at any time. Set it per template with
`'sequence_mode' => 'parallel'` in the template config.

### Late assignment

A session can open before every role is filled. When the record is tagged
later:

```php
app(SigningSessionManager::class)->refreshAssignments($session);
```

Requests that already reached a decision are never touched — a signature that
happened, happened.

### The signature chain

Each signature is chained to the one before it:

```
base.pdf ──sig#1(Juan)──▶ signed-1.pdf ──sig#2(Maria)──▶ signed-2.pdf ──sig#3──▶ final.pdf
             │                                │
   document_hash = H(base)          document_hash = H(signed-1)
   signed_document_hash = H(signed-1)         parent_signature_id = sig#1
```

Signature N's `document_hash` equals signature N-1's `signed_document_hash`, and
`parent_signature_id` records the link. The progression is verifiable from the
database regardless of what the PDF file itself can carry — which matters, as
the next section explains.

---

## Signing modes — read this before choosing

```php
'multi_signature' => ['mode' => 'progressive'],   // default
```

### `progressive` (default)

Each signature is stamped onto the previous signatory's output and the document
is re-signed with **that signatory's** certificate.

- ✅ The finished PDF shows every visible signature.
- ✅ Every signature has its own `digital_signatures` row, its own certificate
  fingerprint, its own timestamp, and a verifiable hash chain.
- ⚠️ **Only the most recent PKCS#7 block survives inside the PDF.** FPDI and
  TCPDF rebuild the file on every pass, which necessarily discards the previous
  signature block. A PDF reader will show one cryptographic signature, not N.

### `incremental`

True PAdES: each signature is appended as an ISO 32000 incremental update, and
every earlier signature stays cryptographically valid — a reader shows N
distinct signers with N certificates.

This requires a driver implementing
[`SupportsIncrementalSigning`](../src/Contracts/SupportsIncrementalSigning.php).
**Neither bundled driver can do it**, and that is a property of FPDI rather
than an oversight: FPDI re-imports and rewrites the document, which invalidates
any existing signature. Writing incremental updates in PHP needs a library
built for it (e.g. SetaPDF-Signer).

Setting `incremental` without such a driver raises
`IncrementalSigningUnsupportedException` at sign time. That is deliberate: the
alternative is a document that silently claims three signatures and carries one.

### Choosing

| Your requirement | Mode |
|---|---|
| A visible signature block, tamper-evidence, and a full audit trail | `progressive` |
| N independently verifiable signer certificates in the PDF itself | `incremental` + a capable driver |

If the answer is legal rather than technical, get it confirmed before building
on `progressive`.

---

## Consent: who may sign, and when

**Automation can remove the effort of signing. It must never remove the
consent.** Everything below exists to keep those two apart.

```php
'auto_affix' => ['mode' => 'approval'],   // default
```

### `approval` — the default

Never auto-signs. Routing finds the right person, the placement is pre-filled,
and the document appears in their inbox — but the PKCS#7 block is produced in
**their own authenticated request, with their own certificate**.

The automation is real: nobody hunts for the document, nobody re-draws a
signature, nobody types coordinates. What remains is one click by the person
whose name is on the line. No new trust assumptions over single-signer signing.

### `delegated` — standing authorisation

The signatory opts in once, from their own account:

> ☑ Auto-apply my signature when I am tagged as **Attested by** on an
> **Accomplishment Report** — until 2027-01-01.

```php
app(AutoAffixService::class)->grant(
    grantorId:   auth()->id(),
    signature:   $mySignature,
    templateKey: 'accomplishment-report',
    role:        'attested_by',
    expiresAt:   now()->addYear(),
    maxUses:     null,      // or a cap
    signable:    null,      // or one specific record
);
```

A grant is scoped, expiring, revocable, and **can only be created by the
grantor in their own session** — an administrator cannot consent on someone
else's behalf (`DelegationNotPermittedException`).

Understand what you are enabling: a standing grant means the server can produce
that user's signature for the grant's lifetime. That is a deliberate trade, not
a detail.

### `implicit` — unsafe, off by default

Treats being tagged on a record as consent to sign it. Anyone who can edit the
record can then cause that person's certificate to sign it — signature forgery
with extra steps. It requires a second, separate acknowledgement:

```php
'auto_affix' => ['mode' => 'implicit', 'allow_implicit' => true],
```

Without `allow_implicit`, the service refuses and explains why.

### Per-template override

```php
'accomplishment-report' => [
    'auto_affix' => 'delegated',   // this template only
],
```

### What holds in every mode

1. **Every auto-affix is audited** — who was signed for, who triggered it,
   which grant authorised it, IP, user agent, timestamp
   ([`SignatureAudit`](../src/Models/SignatureAudit.php)).
2. **Grants are created only by their grantor**, in their own session.
3. **Revoking a signature or a grant takes effect immediately.**
4. **Auto-affixed signatures are marked `source = 'auto'`**, distinct from
   `draw` and `upload`.
5. **The signatory is notified about every auto-affix**, not just the grant.
   Silent signing is not acceptable even with consent.
6. **Machine fingerprints are never fabricated.** The signer wasn't at a
   keyboard, so the originating signature's fingerprint is carried forward and
   the event is marked server-originated. Inventing one would corrupt the
   machine-binding check that protects ordinary signing.

---

## The Filament surface

### SignatoryPanel

```php
use Kukux\DigitalSignature\Filament\Components\SignatoryPanel;

// Filament v4 / v5
public static function infolist(Schema $schema): Schema
{
    return $schema->components([
        TextEntry::make('title'),
        SignatoryPanel::make('signatories'),
    ]);
}

// Filament v3
public static function infolist(Infolist $infolist): Infolist
{
    return $infolist->schema([
        TextEntry::make('title'),
        SignatoryPanel::make('signatories'),
    ]);
}
```

Renders each role, who fills it, their state, and the blocker when there is one.

```php
SignatoryPanel::make()->only(['attested_by']);   // one role in context
SignatoryPanel::make()->showPlacement();         // page + coordinates, for calibration
SignatoryPanel::make()->template('custom-key');  // override the record's template
```

### RequestSignaturesAction

```php
use Kukux\DigitalSignature\Filament\Actions\RequestSignaturesAction;

RequestSignaturesAction::make()
    ->autoAffix()            // apply any standing grants immediately (default)
    ->template('custom-key');
```

Refuses to open a session whose required roles cannot be filled, and names the
blockers instead. A session that stalls on "the Dean has no signature" is worse
than no session — the document looks in-flight when nothing can happen.

On Filament v3 a table row needs `RequestSignaturesTableAction` instead; see
[Filament version compatibility](#filament-version-compatibility).

### The inbox

The plugin registers an **Awaiting my signature** page with a count badge.
Each Sign click produces the signature in that user's own request. Turn it off
per panel with `SignaturePlugin::make()->withoutInbox()`, or globally with
`signature.inbox.enabled`.

### Escape hatches

```php
// Global signatory override — return null to defer to the slot's own binding
SignaturePlugin::make()->resolveSignatoriesUsing(
    fn ($record, $slot) => app(OrgChart::class)->holderOf($slot->role(), $record),
);
```

Events, for headless flows: `SigningSessionOpened`, `SignatureRequested`,
`SignatureDeclined`, `SignatureAutoAffixed`, `SigningSessionCompleted` —
alongside the existing `DocumentSigned`.

---

## Filament version compatibility

The package supports Filament 3, 4 and 5 from one codebase. Two things moved
between majors and cannot be papered over:

| What | v3 | v4 / v5 |
|---|---|---|
| Table row actions | `Filament\Tables\Actions\Action` | `Filament\Actions\Action` (unified) |
| `Page::$view` | **static** property | **instance** property |
| Forms / infolists | `Forms\Form`, `Infolists\Infolist` | `Schemas\Schema` |

Declaring the wrong `$view` kind is a hard PHP fatal, not a graceful failure.
So each affected class is split into `V3\` and `V4\` implementations with the
behaviour in a shared trait, and the service provider aliases the canonical
name at register time. **Host apps import one stable name regardless of
version**:

```php
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;      // works on 3, 4, 5
use Kukux\DigitalSignature\Filament\Components\SignatoryPanel;
```

The one place v3 needs a different name is the table/header action split, which
v3 enforces and v4 removed:

| Placement | v3 | v4 / v5 |
|---|---|---|
| Table row | `SignDocumentAction` | `SignDocumentAction` |
| Page header | `SignDocumentHeaderAction` | `SignDocumentAction` or `SignDocumentHeaderAction` |
| Table row (request) | `RequestSignaturesTableAction` | either |
| Page header (request) | `RequestSignaturesAction` | either |

Using the header-suffixed names everywhere is portable across all three.

### Version detection

```php
FilamentVersion::major();       // 3 | 4 | 5
FilamentVersion::usesSchemas(); // bool
```

Detection reads Composer's installed-versions manifest, because a class probe
cannot tell v4 from v5 — both have `Filament\Schemas\Schema`. Override with
`signature.filament_version` when testing a branch the installed version
wouldn't reach.

`.github/workflows/tests.yml` runs the suite across all three majors. Without
that matrix, `^3.0 || ^4.0 || ^5.0` would be an aspiration rather than a
guarantee.

Two things that job does beyond running Pest, both learned the hard way:

- **It requires the summary line.** A fatal inside PHPUnit's output buffer —
  TCPDF calling `die()`, or a `TypeError` from a GD call — kills the runner,
  discards the buffered message, and still exits 0, silently skipping every
  later test file. The job greps for `Tests:` and compares the number of test
  files reported against the number on disk.
- **It runs `composer audit`.** This is a signing package; shipping against a
  dependency with a live advisory is not acceptable, and the audit makes that
  a build failure rather than a footnote.

---

## Configuration reference

| Key | Default | Purpose |
|---|---|---|
| `sessions.sequence_mode` | `sequential` | `sequential` honours slot order; `parallel` doesn't |
| `sessions.expires_after_days` | `null` | Sessions stop accepting signatures after this |
| `multi_signature.mode` | `progressive` | `progressive` or `incremental` — see above |
| `auto_affix.mode` | `approval` | `approval`, `delegated`, or `implicit` |
| `auto_affix.allow_implicit` | `false` | Second acknowledgement required for implicit mode |
| `auto_affix.notify` | `true` | Notify the signatory on every auto-affix. Leave on. |
| `auto_affix.default_grant_days` | `365` | Default grant lifetime |
| `inbox.enabled` | `true` | Register the inbox page |
| `filament_version` | auto | Force a Filament branch |

---

## Schema

Migrations run in dependency order — every foreign key's target table is
created before the table that references it:

| # | Table | Holds |
|---|---|---|
| 1 | `digital_user_certificates` | Per-user X.509 certificate + encrypted key |
| 2 | `digital_signing_sessions` | One document's journey: frozen base PDF, running document, status, mode |
| 3 | `digital_signatures` | Every signature, including `signing_session_id` / `slot_key` / `sequence` / `parent_signature_id` for the multi-signatory chain |
| 4 | `signature_positions` | Where a given signature was stamped |
| 5 | `digital_signature_requests` | One (session, slot): assignee, frozen placement, decision |
| 6 | `digital_signature_delegations` | Standing consent: user, signature, template, role, expiry, uses |
| 7 | `digital_signature_audits` | Append-only log of every consequential act |
| 8 | `digital_pdf_template_slots` | Designer-saved coordinates per (template, slot) |

The session columns are declared in `digital_signatures`' own create migration
rather than added by a later `Schema::table()` — an `add_x_to_y` migration is
only warranted for schema that has already shipped. This is also why
`digital_signing_sessions` is created *before* `digital_signatures`: MySQL and
Postgres require a foreign key's target to exist at `CREATE TABLE` time, even
though SQLite would accept either order.

---

## Related

- [PDF Templates](pdf-templates.md) — slots, the placement designer, the signer page
- [Model Setup](model-setup.md) — `Signable`, `HasSignatures`
- [Security](security.md) — HMAC metadata, machine binding, forgery detection
- [Signing Workflow](signing-workflow.md) — `SignatureManager`, events, statuses
- [Concept: Signatory Routing](concepts/signatory-routing.md) — the original design discussion
