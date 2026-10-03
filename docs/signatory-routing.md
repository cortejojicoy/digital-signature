# Signatory Routing & Multi-Signatory Documents

Some documents are signed by *roles*: an Accomplishment Report has a **Prepared by**, an **Attested by** and a **Noted by**, each a person tagged on the record. This page shows how the package finds those people, sends them the document, and combines their signatures into one PDF.

> **New to the package?** Read [PDF Templates](pdf-templates.md) first. Routing builds on slots, the placement designer and the `Signable` contract.

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
// 2. The model — two traits, plus the relations you already have
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
// 3. The resource — one infolist entry, one action
SignatoryPanel::make('signatories');
RequestSignaturesAction::make();
```

4. Open the placement designer once, at `/{panel}/signature-templates/accomplishment-report/design` on your panel (`/admin/…` on a panel with the id `admin`), and drag the three slots onto the signature lines. Or mark each signature space in the Blade with `data-signature-slot` and skip this step; see [PDF Templates](pdf-templates.md#documents-that-run-to-any-number-of-pages).

That's it. Once each signatory has registered a signature in their own panel, being tagged on a record is enough for the document to find them.

---

## How a slot gains a signatory

A slot can say *whose* rectangle it is:

```php
new SlotDefinition(
    key:       'attested_by',
    label:     'Attested by',
    required:  true,
    signatory: 'attestedBy',   // who fills it
    role:      'attester',     // optional: scope for grants and policies (defaults to the slot key)
    order:     2,              // optional: signing sequence
);
```

In config, use the same keys: `signatory`, `role`, `order`, `required`.

### Four ways to bind a signatory

| Form | Example | Use it when |
|---|---|---|
| Relation name | `'attestedBy'` | The record has a `belongsTo`. The common case. The relation may return any model; see below. |
| Foreign key | `'attested_by_id'` | The record stores only an id. |
| Closure | `fn ($record) => $record->department->head` | The person comes from somewhere other than a direct column. |
| Invokable class | `\App\Signatories\DepartmentHead::class` | You want it reusable across templates and testable. |

An invokable class can implement [`SignatoryResolver`](../src/Contracts/SignatoryResolver.php) to get `resolve($record, $slot)`, or just define `__invoke`.

A slot with no `signatory` is **unrouted**: whoever opens the signer page places their own signature. A binding that can't resolve (null foreign key, deleted parent, a closure that hits a null) doesn't throw; the slot just shows as `unassigned`. A closure's exception is still reported, so a genuine bug surfaces in your logs.

### Bindings that return someone other than a login

Signatures belong to logins: requests and signatures are keyed by `user_id`. Whatever a binding returns goes through the bound [`SignatoryUserMapper`](integration/service-provider.md#signatoryusermapper-how-a-signatory-becomes-a-login) before it's used. The default passes logins through and refuses anything else, so a relation to a `Personnel` or `Employee` row routes as *"… is named but has no login"* until you bind a mapper:

```php
// app/Providers/SignatureServiceProvider.php
$this->app->bind(SignatoryUserMapper::class, fn () => RelationUserMapper::using('user'));
```

Then `'attestedPersonnel'` is a valid binding, and the person's login signs. In 1.x the person's own id was silently used as a `user_id`.

---

## Reading the routing

[`SignatoryRouter`](../src/Services/SignatoryRouter.php) combines the slots, the saved coordinates, the tagged people and their signatures. The panel, inbox and signing pipeline all read its output.

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

### Slot states

| State | Meaning | How to fix it |
|---|---|---|
| `unassigned` | Nobody is tagged for this role | Tag someone on the record |
| `awaiting_registration` | The tagged person has never registered a signature | They register one in their own panel |
| `awaiting_consent` | Ready; waiting on them to sign | They sign (or a grant signs for them) |
| `ready` | Signable right now | — |
| `blocked` | Waiting on an earlier slot in a sequential session | The earlier signatory signs |
| `signed` | Done and embedded in the document | — |
| `declined` | The signatory refused | Reassign the role, or cancel |

`blocked` never hides `unassigned` or `awaiting_registration`; those always show.

### Shortcuts on `HasSignatories`

```php
$report->signatureBlockers();      // ['noted_by' => 'Dr Reyes has not registered a signature yet.']
$report->isReadyForSignatures();   // false — opening a session now would stall
$report->isFullySigned();
$report->signedDocumentPath();
$report->pendingSignatureRequestFor(auth()->id());
$report->currentSigningSession();
$report->latestSigningSession();
```

---

## Signing sessions

A `SigningSession` owns a multi-signatory document from start to finish.

```php
$session = $report->openSigningSession();
```

Opening a session:

- **Freezes the PDF.** It's rendered once into `base_document_path` and never re-rendered, so later signers stamp on top of earlier ones.
- **Creates one `SignatureRequest` per slot**, with the placement copied in. Moving a slot in the designer afterwards doesn't affect open requests.

Calling it again just returns the open session, with assignments refreshed.

### Sequential vs parallel

```php
'sessions' => ['sequence_mode' => 'sequential'],   // default
```

- `sequential` follows the slots' `order`. Signing out of turn throws `OutOfSequenceException`.
- `parallel` lets any assigned signatory sign at any time.

Override per template with `'sequence_mode' => 'parallel'`.

### Late assignment

A session can open before every role is filled. After tagging someone later, run:

```php
app(SigningSessionManager::class)->refreshAssignments($session);
```

Requests that are already signed or declined are left alone.

### The signature chain

Each signature links to the one before it:

```
base.pdf ──sig#1(Juan)──▶ signed-1.pdf ──sig#2(Maria)──▶ signed-2.pdf ──sig#3──▶ final.pdf
             │                                │
   document_hash = H(base)          document_hash = H(signed-1)
   signed_document_hash = H(signed-1)         parent_signature_id = sig#1
```

Signature N's `document_hash` equals N-1's `signed_document_hash`, and `parent_signature_id` stores the link, so the chain is verifiable from the database alone.

---

## Signing modes — read this before choosing

```php
'multi_signature' => ['mode' => 'progressive'],   // default
```

| | `progressive` (default) | `incremental` |
|---|---|---|
| How it works | Each signature is stamped onto the previous output and re-signed with that signer's certificate | Each signature is appended as an ISO 32000 incremental update (true PAdES) |
| Visible signatures in the PDF | All of them | All of them |
| Cryptographic signatures in the PDF | **Only the last one** | One per signer, all valid |
| Per-signer DB row, certificate fingerprint, timestamp, hash chain | Yes | Yes |
| Works with bundled drivers | Yes | **No** |

`incremental` needs a driver implementing [`SupportsIncrementalSigning`](../src/Contracts/SupportsIncrementalSigning.php). The bundled FPDI and TCPDF drivers rewrite the whole file, so they can't; you'd need something like SetaPDF-Signer. Without one, signing throws `IncrementalSigningUnsupportedException` rather than silently falling back.

Use `progressive` if a visible signature block, tamper-evidence and an audit trail are enough. Use `incremental` if each signer's certificate must verify inside the PDF itself. If that's a legal requirement, confirm it before building on `progressive`.

---

## Consent: who may sign, and when

Automation can save signers effort, but it must never sign without their consent. Pick a mode:

```php
'auto_affix' => ['mode' => 'approval'],   // default
```

| Mode | What happens |
|---|---|
| `approval` (default) | Never auto-signs. The document lands in the signer's inbox with the placement filled in, and they sign it with one click in their own request, with their own certificate. |
| `delegated` | The signer opts in once with a standing grant. Matching documents are then signed for them automatically. |
| `implicit` | Being tagged on a record counts as consent. **Unsafe**: anyone who can edit the record can make that person's certificate sign it. Off unless you also set `allow_implicit`. |

### `delegated` grants

The signer creates the grant from their own account:

```php
app(AutoAffixService::class)->grant(
    grantorId:   auth()->id(),
    signature:   $mySignature,
    templateKey: 'accomplishment-report',
    role:        'attester',         // optional; must match the slot's role() (its `role`, or its key)
    expiresAt:   now()->addYear(),   // defaults to auto_affix.default_grant_days
    maxUses:     null,               // or a cap
    signable:    null,               // or one specific record
);
```

Revoke it with `app(AutoAffixService::class)->revokeGrant($delegation)`.

Only the grantor can create or revoke a grant, in their own session. Anyone else (an admin included) gets `DelegationNotPermittedException`, as does a grant for a signature that isn't the grantor's or is revoked.

Keep in mind: while a grant is live, the server can produce that user's signature.

### `implicit` needs a second switch

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

- Every auto-affix is audited in [`SignatureAudit`](../src/Models/SignatureAudit.php): who was signed for, who triggered it, which grant allowed it, IP, user agent and time.
- Revoking a signature or a grant takes effect immediately.
- Auto-affixed signatures get `source = 'auto'`, separate from `draw` and `upload`.
- The signer is notified about every auto-affix, not just the grant (`auto_affix.notify`).
- Machine fingerprints are never faked. The original signature's fingerprint is carried forward and the event is marked as server-originated.

The reasoning behind these rules is in [Concept: Signatory Routing](concepts/signatory-routing.md).

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

It shows each role, who fills it, its state, and the blocker if there is one.

```php
SignatoryPanel::make()->only(['attested_by']);   // show just these roles
SignatoryPanel::make()->showPlacement();         // page + coordinates, for calibration
SignatoryPanel::make()->showAvatars();           // signer avatars (bool or closure)
SignatoryPanel::make()->template('custom-key');  // override the record's template
```

### RequestSignaturesAction

```php
use Kukux\DigitalSignature\Filament\Actions\RequestSignaturesAction;

RequestSignaturesAction::make()
    ->autoAffix()            // apply standing grants right after opening (default)
    ->template('custom-key');
```

It refuses to open a session while required roles can't be filled, and lists the blockers. `autoAffix()` only does something if the template's consent mode allows it. On Filament v3, table rows need `RequestSignaturesTableAction` (see [Filament version compatibility](#filament-version-compatibility)).

### The inbox and the floating launcher

- **Floating launcher** (default): a button on every panel page that opens a slide-over of what's waiting, with Sign and Decline on each row.
- **Awaiting my signature** page: the full-page view.

While the launcher is on, the inbox page and Signatures resource leave the sidebar but stay routable; the slide-over links to both.

| You want to | Do this |
|---|---|
| Keep the sidebar items too | `signature.launcher.replaces_navigation = false` |
| Turn the launcher off for a panel | `SignaturePlugin::make()->withoutFloatingLauncher()` |
| Turn the launcher off everywhere | `signature.launcher.enabled = false` |
| Turn the inbox page off | `->withoutInbox()` or `signature.inbox.enabled = false` |

Both run the same `ActsOnSignatureRequests` code, so every Sign click signs in that user's own request.

### Escape hatches

```php
// Global signatory override — return null to fall back to the slot's own binding
SignaturePlugin::make()->resolveSignatoriesUsing(
    fn ($record, $slot) => app(OrgChart::class)->holderOf($slot->role(), $record),
);
```

Events for headless flows: `SigningSessionOpened`, `SignatureRequested`, `SignatoryTurnReached`, `SignatureDeclined`, `SignatureAutoAffixed`, `SigningSessionCompleted`, plus the existing `DocumentSigned`.

**Who gets notified, and when.** Each signatory gets `SignatureRequestedNotification` once, when they can actually sign. In a sequential session that's the first signatory when the session opens, then each next one as the person before them signs. In a parallel session it's everyone at once. A signatory tagged after the session opened is notified when their turn comes. The package sends it on `SignatoryTurnReached`. `SignatureRequested` still fires for every slot the moment it's routed, if you need that instead. Channels come from `sessions.notification_channels`; set it to `[]` to send nothing.

---

## Filament version compatibility

One codebase supports Filament 3, 4 and 5. These changed between majors:

| What | v3 | v4 / v5 |
|---|---|---|
| Table row actions | `Filament\Tables\Actions\Action` | `Filament\Actions\Action` (unified) |
| `Page::$view` | **static** property | **instance** property |
| Forms / infolists | `Forms\Form`, `Infolists\Infolist` | `Schemas\Schema` |

You don't need to care: import the same class name on every version and the service provider aliases the right implementation.

```php
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;      // works on 3, 4, 5
use Kukux\DigitalSignature\Filament\Components\SignatoryPanel;
```

The one exception is v3's split between table and header actions:

| Placement | v3 | v4 / v5 |
|---|---|---|
| Table row | `SignDocumentAction` | `SignDocumentAction` |
| Page header | `SignDocumentHeaderAction` | `SignDocumentAction` or `SignDocumentHeaderAction` |
| Table row (request) | `RequestSignaturesTableAction` | either |
| Page header (request) | `RequestSignaturesAction` | either |

The header-suffixed names work as header actions on all three versions.

### Version detection

```php
FilamentVersion::major();       // 3 | 4 | 5
FilamentVersion::usesSchemas(); // bool
```

Detection reads Composer's installed-versions data. To force a branch (for example in tests), set `signature.filament_version` / `SIGNATURE_FILAMENT_VERSION`.

CI (`.github/workflows/tests.yml`) runs the suite on all three majors, fails if Pest's `Tests:` summary is missing or incomplete, and runs `composer audit`.

---

## Configuration reference

| Key | Env var | Default | Purpose |
|---|---|---|---|
| `sessions.sequence_mode` | `SIGNATURE_SEQUENCE_MODE` | `sequential` | `sequential` follows slot order; `parallel` doesn't |
| `sessions.expires_after_days` | `SIGNATURE_SESSION_EXPIRY_DAYS` | `null` | Sessions stop accepting signatures after this many days |
| `multi_signature.mode` | `SIGNATURE_MULTI_MODE` | `progressive` | `progressive` or `incremental` |
| `auto_affix.mode` | `SIGNATURE_AUTO_AFFIX_MODE` | `approval` | `approval`, `delegated` or `implicit` |
| `auto_affix.allow_implicit` | `SIGNATURE_ALLOW_IMPLICIT_AFFIX` | `false` | Second switch required for `implicit` |
| `auto_affix.notify` | `SIGNATURE_AUTO_AFFIX_NOTIFY` | `true` | Notify the signer on every auto-affix. Leave it on. |
| `auto_affix.default_grant_days` | `SIGNATURE_GRANT_DAYS` | `365` | Default grant lifetime |
| `inbox.enabled` | `SIGNATURE_INBOX_ENABLED` | `true` | Register the inbox page |
| `inbox.navigation` | `SIGNATURE_INBOX_NAV` | `true` | Show the inbox in the sidebar (page stays routable either way) |
| `launcher.enabled` | `SIGNATURE_LAUNCHER_ENABLED` | `true` | Show the floating launcher |
| `launcher.replaces_navigation` | `SIGNATURE_LAUNCHER_REPLACES_NAV` | `true` | Hide inbox/resource sidebar items while the launcher is on |
| `filament_version` | `SIGNATURE_FILAMENT_VERSION` | auto | Force a Filament branch (3, 4 or 5) |

Per-template overrides: `sequence_mode` and `auto_affix` inside a `templates.<key>` entry. The launcher's look and placement options are documented in `config/signature.php`.

---

## Schema

All tables are created by a single migration, `9999_12_31_000000_create_digital_signature_tables.php`, in dependency order: every foreign key's target table is created before the table that references it. These are the tables this page relies on:

| Table | Holds |
|---|---|
| `digital_user_certificates` | Per-user X.509 certificate + encrypted key |
| `digital_signature_devices` | Registered signing keys (browser or desktop agent) |
| `digital_signing_sessions` | One document's journey: frozen base PDF, running document, status, mode |
| `digital_signatures` | Every signature, including `signing_session_id` / `slot_key` / `sequence` / `parent_signature_id` for the multi-signatory chain |
| `signature_positions` | Where a given signature was stamped |
| `digital_signature_requests` | One (session, slot): assignee, frozen placement, decision |
| `digital_signature_delegations` | Standing consent: user, signature, template, role, expiry, uses |
| `digital_signature_audits` | Append-only log of every consequential act |
| `digital_pdf_template_slots` | Designer-saved coordinates per (template, slot) |

The order matters because MySQL and Postgres require a foreign key's target to exist at `CREATE TABLE` time, even though SQLite would accept either order. Every create is guarded by `Schema::hasTable` and every column added in a later release by `Schema::hasColumn`. That makes the migration safe to run on databases created by earlier releases.

---

## Related

- [Guide: Route for Signatures](route-for-signatures.md): a full worked example, from table to button
- [PDF Templates](pdf-templates.md): slots, the placement designer, the signer page
- [Model Setup](model-setup.md): `Signable`, `HasSignatures`
- [Security](security.md): HMAC metadata, machine binding, forgery detection
- [Signing Workflow](signing-workflow.md): `SignatureManager`, events, statuses
- [Concept: Signatory Routing](concepts/signatory-routing.md): the design reasoning behind routing and consent
