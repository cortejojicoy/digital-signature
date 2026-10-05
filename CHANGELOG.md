# Changelog

All notable changes to `kukux/digital-signature`. Versions follow [semver](https://semver.org).

## 2.0.0 (unreleased)

Routing a document for signatures moves into the package, built on contracts an app implements and binds. Every routed document gets a **document of record**: its signed versions, kept and verifiable, served instead of a re-render. Filament 3, 4 and 5 remain supported.

Upgrading: [UPGRADE-2.0.md](UPGRADE-2.0.md). New docs: [docs/integration](docs/integration/index.md).

### Added

- **Signable documents.** `Contracts\SignableDocument`, `Documents\AbstractSignableDocument`, `Documents\TemplateDocument`, `Services\DocumentRegistry`, `config('signature.documents')` and `SignaturePlugin::documents()`.
- **One router.** `Services\DocumentRouter`: preflight guards, then a transaction that opens the record, short-circuits an open session, runs the definition's and the package's guards, and opens the session. A refusal leaves no row behind.
- **Guards.** `Contracts\PreflightGuard`, `Contracts\RoutingGuard`, and the stock `RequiredSignatoriesAssigned`, `SignatureMarkersPresent` and `SignatoriesReady`. All are built by the container.
- **Results and wording.** `Routing\RoutingResult` with stable reason codes, and wording resolved from the definition, `lang/vendor/signature`, or the package's `lang/en/routing.php` (publish with `--tag=signature-lang`).
- **Signatory mapping.** `Contracts\SignatoryUserMapper`, `Signatories\IdentityUserMapper` (default) and `Signatories\RelationUserMapper`. `SignatoryRoute::$tagged` and `isTaggedWithoutLogin()`: "named but has no login" is now told apart from "nobody named".
- **Signatory snapshots.** `Traits\SnapshotsSignatories`, `Contracts\SignatoryDefaults`, `Signatories\SignatoryAssignment`, `Database\SignatoryColumns`.
- **Paper size.** `DomPdfRenderer(paper:, orientation:)` and `paper` / `orientation` keys in config-array templates.
- **Document of record.** `DocumentOfRecord\DocumentOfRecord`, `DocumentHistory`, `DocumentVersion` (with `verify()` and `serve()`), `DocumentState`, `DocumentOfRecordResolver`, `ResolvedDocument`, `DocumentOfRecordBanner`; `HasSignatories::documentOfRecord()` and `documentHistory()`.
- **Gate.** `Contracts\DocumentOfRecordGate` and `DefaultDocumentOfRecordGate`. A signatory can always open the version their signature produced.
- **Routes.** `GET /signature/documents/{session}` and `/signature/documents/{session}/versions/{n}`, with `X-Document-Version` and `X-Document-Integrity`.
- **Events.** `Events\DocumentTampered` when a served version no longer matches its recorded hash.
- **Filament.** `RouteForSignaturesAction`, `ViewDocumentOfRecordAction`, `DownloadDocumentOfRecordAction`, `ViewDocumentHistoryAction` (v3/v4/v5 via resolvers), `Components\DocumentHistoryEntry`, `Concerns\RoutesDocumentsForSignatures`, `Support\RoutingNotification`.
- **Signed by me.** A *Signed by me* page (`SignedDocuments`, v3/v4/v5) and `signed.*` config. The launcher's Signed tab offers **The copy I signed** and **Current**, and links to the page.
- **Testing.** `Testing\SignableDocumentContract`: shared checks for an app's own test suite.

### Changed

- **Under `devices.agent.approval = enforce`, sign only from the paired computer.** The document drawer's **Sign here** is off until the account has a paired Kukux Sign Agent and this computer has checked in as it (`kukuxsign://presence` link, `POST /signature/agent/presence/{uuid}`, `signature/agent-web/presence`). Other computers get "You are prohibited from signing on this computer…", naming the paired one; so does a computer whose agent is another account's. `POST /signature/requests/{id}/sign` refuses the same cases with `403 prohibited`. New: `Agent\AgentPresenceService`, `devices.agent.checkin_ttl`, `devices.agent.checkin_valid_for`. Needs an agent with presence-link support.
- **Stamp layout follows COA Circular No. 2021-006, IV.C.13.** The handwritten signature on the left, and beside it `Digitally signed` / `by {full name}` / `Date: Y.m.d` / `H:i:s +08'00'`. The text is a fixed size (`caption.font_pt`), so resizing a placement scales the signature only; the name is never truncated. The verification QR, the reference and email lines, and the caption side (`caption.position`) are gone. Removed config: `signature.qr.*`, `caption.fields`, `caption.align`, `caption.position`, `caption.width_ratio`, `caption.min_box_width`, `caption.height_ratio`, `caption.min_box_height`, `caption.max_font_pt`. New: `caption.label`, `caption.font_pt`, `caption.gap`, `caption.time_format`, `caption.timezone`. Drivers no longer receive a QR payload; the `$qrPayload` parameter stays on the contracts so existing drivers still match. A stored `caption_position` is ignored.
- **Desktop agent: one computer per account.** An account can pair the agent with one computer per app. A second computer gets `409 account_already_paired` at claim, naming the account's own computer; lookup returns it as `agent_device` (plus `devices_url`) so the agent can refuse before any key or Touch ID prompt. Backed by a unique `active_agent_user_key` column. Accounts that already hold several computers keep them, but can't pair another until one is left. New config: `signature.devices.agent.devices_url` (`SIGNATURE_AGENT_DEVICES_URL`). A re-pair proves it's the same computer with a `rebind_agent` proof from the old session key (the claim's `replaces`), so computers without a hardware id can re-pair; firmware placeholder UUIDs (`AgentPairingService::PLACEHOLDER_UUIDS`) count as no hardware id.
- **Breaking:** a signatory binding that resolves to a model that isn't a login is refused (routes as unassigned with a "has no login" message) unless a `SignatoryUserMapper` maps it. It was previously stored as a `user_id`.
- **Breaking:** `RequestSignaturesAction` routes through `DocumentRouter`; its notifications use the new wording, and it refuses a required slot with nowhere to go.
- **Breaking:** signed versions are written to `{signed_docs_path}/{session uuid}/{signature uuid}.pdf`, and base renders to `signing-sessions/{template}/{record}/{session uuid}/base.pdf`.
- **Breaking:** deleting a record whose document has been routed throws `DocumentRetainedException` unless the gate allows it.
- **Breaking:** for a signed request, `GET /signature/requests/{id}/document` serves the copy that signatory signed; `?version=current` serves the running document.
- `BladePdfTemplate::detectRenderer()` is an instance method.
- `SignatoryRoute::signerName()` falls back to the tagged person and reads `full_name` / `getSignatoryName()`.

### Fixed

- `SignaturePlugin::templates()` dropped the key of an array-form template and failed with a `TypeError`.
- `getSignablePdfPath()` returned an absolute path that `SignatureManager`, `DocumentIntegrity` and the PDF drivers read as disk-relative, breaking single-signer signing of `HasPdfTemplate` models.
- `CallableResolver` let an exception in a closure binding break routing; it now reports it and treats the slot as unassigned.
- Two signers working from the same source in the same second (parallel sessions) wrote the same signed-file name, so the second replaced the first signer's copy. A session reopened in the same second as a cancelled one could likewise overwrite its base render.

### Docs

- New: `docs/integration/` (overview, service provider, signable documents, document of record, testing, reference integration, three recipes).
- `route-for-signatures.md` now points to the integration docs.
- Corrected: `SignatoryPanel` namespace; the delegation-grant role example; `SignatoryRoute::$position` type and the `blocked` state; which components are version-bridged; `stampAt()` (there is no placement step without it); the path rule for `getSignablePdfPath()`; the full-class template example's cache key and marker support; `configuration.md` now covers templates, documents, the designer, QR, caption, verification, hashing and the agent's `scheme`.
