# Integrating documents

How an app makes its own documents signable: route them to the right people, sign them in order, and keep the signed copies as a history.

The package owns everything that is the same for every document: routing, the checks before routing, signing, the document of record, and the wording. Your app supplies what only it knows. It does that by **implementing contracts and binding classes in the container**, not by copying routing code.

> New in 2.0. Upgrading from 1.x? See [UPGRADE-2.0.md](../../UPGRADE-2.0.md).

---

## The five things an app writes

| # | What | Where |
|---|---|---|
| 1 | **A model** that uses `HasPdfTemplate`, `HasSignatories` and, if it freezes who signs, `SnapshotsSignatories` | `app/Models/` |
| 2 | **A template** (slots and view), registered in `config('signature.templates')` | `app/Pdf/` |
| 3 | **A Blade view** with `data-signature-slot="<key>"` on every signature space | `resources/views/` |
| 4 | **A document definition** extending `AbstractSignableDocument`, registered in `config('signature.documents')` | `app/Signatures/` |
| 5 | **A service provider** binding how a signatory becomes a login, if they aren't `User` rows | `app/Providers/` |

Then put `RouteForSignaturesAction` and the document-of-record actions on a page, and add the shared contract test.

---

## The injection map

Every app-specific part is a contract with a default. Override only what differs in your app.

| Contract | Package default | Override it by | Needed when |
|---|---|---|---|
| `Contracts\SignableDocument` | — | a class in `config('signature.documents')` | always, one per kind of document |
| `Contracts\PdfTemplate` | `Pdf\BladePdfTemplate` | a class in `config('signature.templates')` | always, one per kind of document |
| `Contracts\SignatoryUserMapper` | `Signatories\IdentityUserMapper` | `$this->app->bind(...)` in your provider | your signatories aren't `User` rows |
| `Contracts\SignatoryDefaults` | — | a class your definition takes in its constructor | the document freezes who signs it |
| `Contracts\PreflightGuard` / `Contracts\RoutingGuard` | 3 stock guards always run | listed by your definition, built by the container | the document has its own preconditions |
| `Pdf\Renderers\PdfRenderer` | `DomPdfRenderer` | `renderer:` on the template | you need a fixed paper size or another renderer |
| `Contracts\DocumentOfRecordGate` | `DocumentOfRecord\DefaultDocumentOfRecordGate` | `$this->app->bind(...)` in your provider | your viewing or deletion rules differ |
| Wording | `lang/en/routing.php` | `lang/vendor/signature/…`, or the definition's `messages()` | always optional |

Your **one** service provider is where the bindings live: the composition root. See [Service provider](service-provider.md).

---

## How routing runs

```
RouteForSignaturesAction / routeDocument() / DocumentRouter::route('dtr', $subject, $context)
  │
  ├─ preflight guards ......................... nothing written yet
  ├─ BEGIN
  ├─ definition->open($subject, $context) ...... find or create the record; snapshot signatories
  ├─ already an open session? → already_routed, COMMIT
  ├─ definition guards, then the package's:
  │     RequiredSignatoriesAssigned → SignatureMarkersPresent → SignatoriesReady
  │     first refusal → ROLLBACK (no row left behind)
  ├─ open the signing session .................. freeze the PDF, one request per slot, notify
  └─ COMMIT → routed
```

Details in [Signable documents](signable-documents.md). After routing, the record has a **document of record**: View and Download serve its stored PDF and its history instead of a fresh render. See [Document of record](document-of-record.md).

---

## Pages in this section

| Page | What it covers |
|---|---|
| [Service provider](service-provider.md) | The composition root: every binding, and the three shapes of signatory |
| [Signable documents](signable-documents.md) | `SignableDocument`, guards, `DocumentRouter`, `RoutingResult` and wording |
| [Document of record](document-of-record.md) | States, versions, `verify()`, retention, the gate, the Filament UI |
| [Testing](testing.md) | `SignableDocumentContract`, the checks every document gets for free |
| [Reference integration](reference-integration.md) | The Accomplishment Report in uplb-performance, end to end, as it runs |
| [Recipe: generated from a period](recipes/generated-from-a-period.md) | A document built from a person and a period (DTR) |
| [Recipe: existing record](recipes/existing-record.md) | A document that already is a row (Travel Request) |
| [Recipe: external signatories](recipes/external-signatories.md) | Approvers who live in another system |
