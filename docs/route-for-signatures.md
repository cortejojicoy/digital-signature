# Guide: Route for Signatures

> **In 2.0 this guide became [Integrating documents](integration/index.md).** In 1.x it walked through one worked example, an Accomplishment Report, and asked you to copy its routing action, renderer and button wiring for each document of your own. In 2.0 that code is the package's (`DocumentRouter`, `RouteForSignaturesAction`, the document of record). An app supplies only what is specific to its document, by implementing contracts and binding classes.

## Where to go

| You want to | Read |
|---|---|
| See what an app writes, and what it binds | [Integrating documents](integration/index.md) |
| Add a "Route for Signatures" button to a report generated from a person and a period | [Recipe: generated from a period](integration/recipes/generated-from-a-period.md) |
| Route a record that already exists (a request, a form) | [Recipe: existing record](integration/recipes/existing-record.md) |
| See it working end to end | [Reference integration: the Accomplishment Report](integration/reference-integration.md) |
| Upgrade a 1.x integration built from this guide | [UPGRADE-2.0.md](../UPGRADE-2.0.md#a-1x-route-for-signatures-integration) |

## The 1.x guide, in 2.0 terms

| 1.x guide step | In 2.0 |
|---|---|
| 1. The table: signatory columns, frozen once routed | `SignatoryColumns::add()` + `SnapshotsSignatories` |
| 2. The model: `openFor()`, `missingSignatories()`, a role list | `SnapshotsSignatories` on the model; `open()` / `locate()` on the document definition; `RequiredSignatoriesAssigned` checks the template's own required slots |
| 3. The template: a closure per slot hopping to `->user` | relation names on the slots, and one `SignatoryUserMapper` binding |
| 4. The A4 renderer | `new DomPdfRenderer(paper: 'a4')` |
| 5. The routing action: transaction, refusals, session | `DocumentRouter`, with your preflight guards and wording on the definition |
| 6. The button: footer submit + `mode` branch + notification + `Halt` | `RouteForSignaturesAction`, or one `$this->routeDocument(...)` call in the branch |
| 7. Check the sample in the designer | unchanged |
| "When everyone has signed, `signedDocumentPath()`" | the [document of record](integration/document-of-record.md): every version, kept and verifiable; View/Download serve it, never a re-render |
