# Upgrading to 2.0

2.0 moves routing a document for signatures into the package. Before, an app copied a routing action, a renderer and button wiring for every document; now it implements a few contracts and binds a few classes. It also adds the **document of record**: every signed version kept, verifiable, and served instead of a re-render.

Most apps need three changes: bind a `SignatoryUserMapper` if signatories aren't `User` rows, update tests that matched routing notification text, and replace any hand-written routing code. Filament 3, 4 and 5 all remain supported.

```bash
composer require kukux/digital-signature:^2.0
php artisan migrate        # no new tables; safe to run
php artisan filament:assets
```

---

## Breaking changes

### 1. A signatory that isn't a login is refused (unless you bind a mapper)

**Before:** whatever model a slot's `signatory` binding returned was used as the signatory, and its key was stored as a `user_id`. A relation to a `Personnel` row silently routed the document to whichever user shared that Personnel's id.

**After:** every resolved model goes through `Contracts\SignatoryUserMapper`. The default, `IdentityUserMapper`, passes logins (`Authenticatable`) through and refuses anything else. Such a slot now routes as *"… is named but has no login"*.

**If your bindings already return users:** nothing to do.

**If they return people and you hopped to the login in a closure** (`fn ($r) => $r->attestedPersonnel?->user`): those still work. You can also simplify them to relation names and bind the hop once:

```php
// app/Providers/SignatureServiceProvider.php
$this->app->bind(
    \Kukux\DigitalSignature\Contracts\SignatoryUserMapper::class,
    fn () => \Kukux\DigitalSignature\Signatories\RelationUserMapper::using('user'),
);
```

```php
// before
signatory: fn (AccomplishmentReport $r) => $r->attestedPersonnel?->user,
// after
signatory: 'attestedPersonnel',
```

### 2. `RequestSignaturesAction` uses the shared router and its wording

**Before:** the action checked readiness inline, and notified "Signatures requested" / "Not ready for signatures" / "Could not open signing session".

**After:** it routes through `DocumentRouter`, like every other document. Notifications come from `RoutingResult`: "Routed for signatures" / "Already routed" / "Signatories not set" / "Signature lines not found" / "Not ready for signatures" / "Could not route this document". It also refuses when a required slot has nowhere to go (`markers_missing`), which previously failed later, at signing time.

**Update** any test that asserted the old titles. Better: assert `RoutingResult::reason()` by routing through `DocumentRouter` directly. To keep the old wording app-wide, publish the lang file (`php artisan vendor:publish --tag=signature-lang`) and edit it.

### 3. `getSignablePdfPath()`: one path rule

**Before:** `HasPdfTemplate` returned an **absolute** path, but `SignatureManager`, `DocumentIntegrity` and the PDF drivers read it as **disk-relative**. Single-signer signing of a `HasPdfTemplate` model (`SignDocumentAction`, the signer page with `?signable=`) hashed and signed the wrong path.

**After:** both forms are accepted everywhere and normalised (`Support\DiskPath`). If you worked around this by returning a relative path from an override, that still works; the override is no longer needed.

### 4. `SignaturePlugin::templates()` keeps array keys

**Before:** `->templates(['dtr' => [...array config...]])` dropped the `'dtr'` key and failed with a `TypeError`.

**After:** keyed Blade templates work through the plugin, exactly as in `config('signature.templates')`. If you registered them through `PdfTemplateRegistry::registerMany()` to avoid this, you can move them back.

### 5. Signed-file names and locations

**Before:** a signed version was written as `{signed_docs_path}/{source}_signed_{time()}.pdf`, and a session's base render as `signing-sessions/{template}/{record}/base-{YmdHis}.pdf`. Two signers working from the same source in the same second (a parallel session) got the same name, and the second write replaced the first signer's copy.

**After:** `{signed_docs_path}/{session uuid}/{signature uuid}.pdf` and `signing-sessions/{template}/{record}/{session uuid}/base.pdf`. A version that already exists is refused rather than overwritten. Existing files and the paths stored on existing rows are untouched; only new signings use the new layout.

If anything of yours parsed those file names, read the paths from the database instead (`signed_document_path`, `base_document_path`), or use `$record->documentHistory()`.

### 6. A routed record can't be deleted

**Before:** deleting a record that had been routed left its signing sessions, signatures and files pointing at nothing.

**After:** `HasSignatories` throws `Exceptions\DocumentRetainedException` when a routed record is (force-)deleted. Soft deletes are unaffected. To allow it, bind a `Contracts\DocumentOfRecordGate` whose `canDelete()` returns true.

### 7. Signed requests open on the copy that was signed

**Before:** `GET /signature/requests/{id}/document` (the launcher's "View document") always served the running document.

**After:** for a request that has been **signed**, it serves the version that signatory's signature produced: the copy they signed. `?version=current` serves the running document. Unsigned requests are unchanged. The launcher's Signed tab now offers both.

### 8. Smaller changes

| Change | Action |
|---|---|
| `BladePdfTemplate::detectRenderer()` is now an instance method (it reads the template's `paper`) | Only affects a subclass that overrode it statically; make it non-static. |
| `SignatoryRouter::__construct()` takes an optional third `?SignatoryUserMapper` | Only affects code that constructs the router by hand. |
| `SignatoryRoute` has a new trailing `?Model $tagged` property | Only affects code that constructs routes by hand (named arguments are unaffected). |
| `SignatoryRoute::signerName()` falls back to the tagged person's name, and reads `full_name` / `getSignatoryName()` as well as `name` | None. |
| `CallableResolver` returns `null` (and reports) when the closure throws | A closure binding that hits a null no longer breaks routing. |
| New config keys `documents` and `signed` | Add them to a published `config/signature.php` if you want to set them there; defaults apply otherwise. |
| A new *Signed by me* page is registered on each panel | Off with `SIGNATURE_SIGNED_ENABLED=false`. |

---

## New in 2.0 (opt-in)

| | |
|---|---|
| `Contracts\SignableDocument`, `Documents\AbstractSignableDocument`, `config('signature.documents')` | Define a document once; the package routes it. |
| `Services\DocumentRouter` | One routing implementation: preflight → open → guards → session, in a transaction, with no rows left behind on refusal. |
| `Contracts\PreflightGuard`, `Contracts\RoutingGuard`, three stock guards | Preconditions as container-built classes. |
| `Routing\RoutingResult` + `lang/en/routing.php` | Stable reason codes; wording from the definition, your lang files, or the package. |
| `Traits\SnapshotsSignatories`, `Contracts\SignatoryDefaults`, `Database\SignatoryColumns` | Freeze who signs on the record. |
| `Pdf\Renderers\DomPdfRenderer(paper:, orientation:)`, `'paper'` in template config | Pin the paper size without a renderer class. |
| `DocumentOfRecord`, `DocumentHistory`, `DocumentVersion::verify()`, `DocumentOfRecordResolver`, `Events\DocumentTampered` | The document of record and its history. |
| `RouteForSignaturesAction`, `ViewDocumentOfRecordAction`, `DownloadDocumentOfRecordAction`, `ViewDocumentHistoryAction`, `DocumentHistoryEntry`, `RoutesDocumentsForSignatures` | The Filament layer, on v3/v4/v5. |
| `Testing\SignableDocumentContract` | The checks every document passes, for your test suite. |

See [docs/integration](docs/integration/index.md).

---

## A 1.x "Route for Signatures" integration

If you built a document from the 1.x guide (a routing action class, an A4 renderer, a closure per slot, a footer button), here is the mapping. The [reference integration](docs/integration/reference-integration.md) is the result for the guide's own example.

| 1.x | 2.0 |
|---|---|
| `RouteXForSignatures::handle()` with its own transaction and refusals | a `SignableDocument` (`open()`, `locate()`, a preflight guard for "nothing to report"), registered in `config('signature.documents')`. Delete the action. |
| `$model->missingSignatories()` and a `ROLES` list | `RequiredSignatoriesAssigned` reads the template's required slots. Delete both. |
| `$model::openFor()` copying signatories in a loop | `SnapshotsSignatories` + a `SignatoryDefaults` class; `signatoryRoles()` can map to your existing columns. |
| `renderData()`'s name/position loop | `$this->signatoryViewData('N/A')` |
| An `A4Renderer` / `XRenderer` class | `renderer: new DomPdfRenderer(paper: 'a4')`. Delete the class. |
| `fn ($r) => $r->someone?->user` per slot | `'someone'`, plus one `SignatoryUserMapper` binding |
| Footer branch with `Notification` + `Halt` | `use RoutesDocumentsForSignatures;` then `$this->routeDocument('key', $subject, $context);` |
| View / Download re-rendering a routed document | `DocumentOfRecordResolver::resolve(…, live: …)`, which serves the stored PDF once routed |
| Tests asserting `$result['title']` | `$result->reason()` |
