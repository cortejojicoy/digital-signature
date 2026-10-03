# Document of record

Once a document has been routed, it **is** the stored PDF its signatories sign, not whatever a fresh render would produce today. View and Download serve that file, and every version of it is kept, so each signatory can go back and review **the exact copy they signed**.

Re-rendering a routed document is never right. A re-render drops every signature, and it rebuilds the document from today's data, so it shows something nobody signed.

---

## Versions

Every signing writes a new file, and nothing overwrites one:

| Version | File | Recorded hash |
|---|---|---|
| v0: the render everybody signs | the session's base document | `base_document_hash` |
| vN: the document right after signature N | that signature's signed output | its `signed_document_hash` |

The chain is in the database as well: signature N's `document_hash` equals signature N-1's `signed_document_hash`.

```php
$history = $record->documentHistory();          // every version, oldest first, across sessions

foreach ($history->versions() as $version) {
    $version->number;       // 0, 1, 2, …
    $version->signature;    // who produced it (null for v0)
    $version->createdAt;
    $version->hash;
    $version->verify();     // re-hash the file and compare with the recorded hash
    $version->url();        // open it
}

$history->producedBy($signature);  // the copy a given signature produced
```

Files live at `{signed_docs_path}/{session uuid}/{signature uuid}.pdf` and `signing-sessions/{template}/{record}/{session uuid}/base.pdf`. Both are named so that no two signings can collide: two signers in the same second, a session reopened after a cancelled one. A version that somehow exists already is refused rather than overwritten.

---

## The current document

```php
$document = $record->documentOfRecord();   // null until routed

$document->state;          // DocumentState::Pending | InProgress | Complete | Withdrawn
$document->current;        // the latest DocumentVersion
$document->label();        // "2 of 4 signed · waiting on Dr Reyes"
$document->contents();     // the bytes (checked on the way out, see Integrity)
$document->url();          // open it
$document->downloadName('AR.pdf');  // "AR-in-progress.pdf" / "AR-signed.pdf" / "AR-withdrawn.pdf"
```

| Latest session | `state` | `current` |
|---|---|---|
| complete | `Complete` | the last signature's version |
| open, at least one signature | `InProgress` | the last signature's version |
| open, nobody signed yet | `Pending` | v0 |
| cancelled or expired | `Withdrawn` | the last version, **still shown**, under a "routing withdrawn" banner |

A withdrawn document stays viewable: it's history, and what was signed stays reviewable.

---

## Pages that generate documents: live until routed

A page that renders a document from a subject and a period (a report, a DTR) should show the document of record once there is one, and a live draft until then. `DocumentOfRecordResolver` gives you both from one call, so View and Download can't disagree:

```php
use Kukux\DigitalSignature\DocumentOfRecord\DocumentOfRecordResolver;

$document = app(DocumentOfRecordResolver::class)->resolve(
    'accomplishment-report', $personnel, $period,
    live: fn (): string => AccomplishmentReportService::pdfFor($personnel, $period),
);

$document->isOfRecord();          // routed?
$document->contents();            // stored bytes, or the live render
$document->banner();              // HtmlString banner for a routed document, null for a draft
$document->download('AR.pdf');    // StreamedResponse, renamed for its state
```

The lookup calls the definition's `locate()`, which never creates a record, so looking at a draft doesn't file one.

---

## Filament

| Component | Does |
|---|---|
| `ViewDocumentOfRecordAction` | opens the current version in a new tab; hidden until routed |
| `DownloadDocumentOfRecordAction` | downloads the document of record, or the `->live()` draft before routing |
| `ViewDocumentHistoryAction` | a modal listing every version, with integrity and a link to each |
| `DocumentHistoryEntry` | the same list as an infolist entry |
| `DocumentOfRecordBanner::render($document)` | the one-line banner, as HTML |
| "Signed by me" page + the launcher's **Signed** tab | each signature the user made, with **The copy I signed** and **Current** |

On a record page they need no configuration. On a page that generates the document, say which document and what it's built from:

```php
use Kukux\DigitalSignature\Filament\Actions\DownloadDocumentOfRecordAction;
use Kukux\DigitalSignature\Filament\Actions\ViewDocumentHistoryAction;

DownloadDocumentOfRecordAction::make()
    ->document('dtr')
    ->subject(fn () => $this->employee)
    ->context(fn () => ['month' => $this->month])
    ->live(fn () => DtrPdf::render($this->employee, $this->month))
    ->filename('DTR.pdf');

ViewDocumentHistoryAction::make()
    ->document('dtr')
    ->subject(fn () => $this->employee)
    ->context(fn () => ['month' => $this->month]);
```

All of these exist for Filament 3, 4 and 5 under one class name.

---

## HTTP

| Route | Serves |
|---|---|
| `GET /signature/documents/{session}` (`signature.documents.show`) | the session's latest version |
| `GET /signature/documents/{session}/versions/{n}` (`signature.documents.version`) | version *n* (0 is the base render) |
| `GET /signature/requests/{id}/document` | for a signed request, **the copy that signatory signed**; `?version=current` for where it stands now |

Add `?download=1` for an attachment. Responses carry `X-Document-Version` and `X-Document-Integrity: verified|mismatch`, and are never cached by a shared proxy. Who may read what is the bound [`DocumentOfRecordGate`](service-provider.md#documentofrecordgate-who-may-see-a-routed-document).

---

## Integrity

Every serve re-hashes the file against the hash recorded when it was produced. A file that no longer matches is **still served**, because hiding it helps nobody work out what happened. But:

- the banner and the history say *"This file no longer matches what was signed."*;
- `Kukux\DigitalSignature\Events\DocumentTampered` is dispatched with the version. Listen for it and alert someone.

---

## Retention

The package never deletes a signed version or a base render. A record whose document has been routed can't be deleted either. `HasSignatories` throws `DocumentRetainedException`, unless your `DocumentOfRecordGate::canDelete()` allows it. Soft deletes are left alone, since nothing is lost.

Put `signing-sessions/` and `signed-docs/` on your signature disk into your backups, next to the CA key and `APP_KEY`.
