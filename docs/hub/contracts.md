# Hub contracts (shared by every part of the build)

The fixed interfaces between the hub (`SIGNATURE_MODE=hub`), client apps
(`SIGNATURE_MODE=client`) and the Kukux Sign Agent. Plan:
[plans/signature-hub.md](../../plans/signature-hub.md). Change this file first
if a contract has to change.

All hub URLs are relative to the hub's `APP_URL`
(`https://signature.uplb.edu.ph`). Every error body is
`{"error": "<code>", "message": "<human text>"}`.

---

## 1. Already in place (foundation)

| Piece | Where |
|---|---|
| `signature.mode` + `signature.hub.*` config | `config/signature.php` (end of file) |
| `Support\SignatureMode` (`current()`, `isHub()`, `isClient()`, `isStandalone()`, `missingClientConfig()`) | `src/Support/SignatureMode.php` |
| All tables and columns | consolidated migration, "Hub and client mode" block + `addColumnsMissingFromEarlierReleases()` |
| Models | `Identity`, `Transfer`, `HubBlock`, `HubLogin`, `HubApp`, `HubToken`, `HubCode`, `HubHolder`, `HubSignRequest`, `HubWebhook` (hub); `HubAccount`, `HubPendingSign`, `HubEvent` (client) in `src/Models/` |
| New `Signature` columns | `hub_uuid`, `hub_image_hash`, `hub_synced_at`, `hub_version_id` (fillable). A mirror is `source = 'hub'` |
| New `AgentJob` columns | `requesting_app` (string), `meta` (array cast) |
| New `SignatureAudit` columns + event constants | `app`, `personnel_key` (auto-filled in hub mode from `Identity`); `IDENTITY_*`, `TRANSFER_*`, `LOGIN_*`, `HUB_*`, `SIGNATURE_REVOKED`, `SIGNATURE_CREATED`, `ADMIN_GRANTED`, `BREAK_GLASS_USED` |
| `AgentJobService::create($userId, $purpose, $title, $payloadHash, ['requesting_app'=>, 'meta'=>, 'ttl'=>, 'signable'=>])` and `payload(AgentJob)` | `src/Agent/AgentJobService.php` |
| Contracts | `Contracts\PersonnelDirectory` (+ `Hub\Personnel`), `Contracts\DigestSigner`, `Contracts\DeferredPdfSigner` (+ `Drivers\PdfSigners\PreparedPdf`) |
| Bindings | `DigestSigner → Hub\Cms\CmsSigner`, `DeferredPdfSigner → Drivers\PdfSigners\DeferredPdfSigner` (main provider, `bindIf`) |
| Sub-providers | `Hub\HubApiServiceProvider`, `Hub\HubIdentityServiceProvider` (hub mode), `Client\ClientServiceProvider` (client mode), registered by `SignatureServiceProvider::register()` |
| Plugin presets | `SignaturePlugin::hubPersonPanel()` / `hubAdminPanel()` → `Hub\Filament\HubPanels::register($panel, $plugin)` |
| Client mode already skips | Signatures resource, device-attestation meta, `signature/device-fingerprint`, `signature/devices/*`, `signature/agent/*` routes |

Mode in tests: sub-providers are chosen in `register()`, and Testbench
registers package providers *before* `getEnvironmentSetUp` /
`defineEnvironment` run, so set `signature.mode` earlier: a trait that
overrides `resolveApplicationConfiguration()` (see
`tests/Feature/Hub/Api/HubApiEnvironment.php`), applied with `uses()`. A
`TestCase` subclass doesn't work under `Feature/`: `tests/Pest.php` already
applies `TestCase` there.

---

## 2. Hub API for apps

### 2.1 OAuth (built into the package, no Passport)

| Endpoint | Purpose |
|---|---|
| `POST /signature/hub/oauth/token` `grant_type=client_credentials`, `client_id`, `client_secret` | App token. → `{"access_token","token_type":"Bearer","expires_in","scope"}` |
| `GET /signature/hub/oauth/authorize?response_type=code&client_id&redirect_uri&state&code_challenge&code_challenge_method=S256` | Needs a hub web session (else → landing, intended URL kept) **and** a verified identity (else 403 page). Redirects to `redirect_uri?code=…&state=…`. Records `HubHolder::link(app, personnel_key)` |
| `POST /signature/hub/oauth/token` `grant_type=authorization_code`, `client_id`, `client_secret`, `code`, `redirect_uri`, `code_verifier` | Person token. → `{"access_token","token_type":"Bearer","expires_in","sub"}` |
| `GET /signature/hub/userinfo` (person token) | `{"sub","name","email","emp_no","unit","position"}` |

Tokens: opaque, 40+ random chars, stored as SHA-256 in `HubToken`. Scopes on an
app: `signatures.read`, `sign`. App tokens carry the app's scopes.

### 2.2 People, signatures, signing (app token)

| Endpoint | Response |
|---|---|
| `GET /signature/hub/api/v1/health` (no auth) | `{"status":"ok"|"degraded","checks":{"database":true,"queue":true,"specimen_disk":true,"mirrors_disk":true|null}}`, 503 when degraded |
| `GET /signature/hub/api/v1/people?email=…` or `?emp_no=…` | `{"data":[{"sub","name","email","emp_no","unit","position"}]}` exact match only, max 1 result |
| `GET /signature/hub/api/v1/people/{sub}` | `{"sub","name","email","emp_no","unit","position","active"}` or 404 `unknown_person` |
| `POST /signature/hub/api/v1/people/{sub}/link` | Marks the app as a holder (person is a named signatory there). `{"linked":true}` |
| `GET /signature/hub/api/v1/people/{sub}/signature` | `{"uuid","status":"active","image_sha256","certificate_fingerprint","updated_at"}`, 404 `no_signature` |
| `GET /signature/hub/api/v1/people/{sub}/signature/image` | PNG. `ETag: "<sha256>"`, `X-Image-Sha256: <sha256>`; `304` on matching `If-None-Match`. Scope `signatures.read`; 403 `not_linked` unless the app is a holder for that person. Audited `hub.image_served` |
| `POST /signature/hub/api/v1/sign-requests` body `{"sub","document_hash","specimen_hash","title","slot"?,"capacity"?,"idempotency_key"}` | 202 `{"id","status":"pending","approval_link","job_uuid","expires_at"}`. 409 `specimen_changed` + `current_specimen_hash`. 422 `not_verified`, `no_signature`, `certificate_revoked`, `separated`, `unknown_person`. Same `idempotency_key` → same request back (200) |
| `GET /signature/hub/api/v1/sign-requests/{id}` | `{"id","status","refusal_reason"?, "cms"? (base64 DER, when signed), "certificate_fingerprint"?, "signed_at"?}` |
| `GET /signature/hub/api/v1/certificates/{fingerprint}` | `{"fingerprint","status":"valid"|"revoked"|"unknown","revoked_at"?,"subject"?}` |

Sign-request statuses: `pending`, `signed`, `declined`, `refused`, `expired`,
`failed`.

### 2.3 Hub-side PHP services other parts call

| Class (owner) | Method | Used by |
|---|---|---|
| `Hub\OAuth\HubAppRegistrar` (hub API) | `create(string $clientId, string $name, array $redirectUris = [], ?string $webhookUrl = null, array $scopes = ['signatures.read','sign']): array{app: HubApp, client_secret: string, webhook_secret: string}`; `rotateSecret(HubApp): string`; `rotateWebhookSecret(HubApp): string` | admin Apps page, `signature:hub-app` |
| `Hub\Webhooks\HubNotifier` (hub API) | `signatureUpdated(string $personnelKey): void`; `signatureRevoked(string $personnelKey, string $reason, ?array $window = null): void`; `personSeparated(string $personnelKey): void`; `signRequestCompleted(HubSignRequest): void` — each writes outbox rows for every holder app, then dispatches | identity work, revocation |
| `Hub\Webhooks\HubWebhookDispatcher` (hub API) | `deliver(HubWebhook): bool`; `redeliver(HubWebhook): bool`; `deliverDue(): int` | admin Apps page, `signature:hub-webhooks` |
| `Hub\Specimens\HubRevocation` (hub API) | `revokeSignature(int $userId, string $reason, ?int $actorId = null): void` — marks the master signature revoked, revokes the user's certificate, deletes every app's mirror object on `hub.mirrors_disk`, notifies holders | admin panel, separation, fraud |
| `Hub\Identity\IdentityService` (identity) | `personnelKeyFor(int $userId): ?string`; `isVerified(int $userId): bool`; `userIdFor(string $personnelKey): ?int` (current account) | hub API |

---

## 3. Webhooks (hub → app)

`POST <app webhook_url>` with headers:

```
Content-Type: application/json
X-Signature-Hub-Id: <event uuid>
X-Signature-Hub-Event: <event>
X-Signature-Hub-Timestamp: <unix seconds>
X-Signature-Hub-Signature: sha256=<hex(hmac_sha256(webhook_secret, timestamp + "." + raw_body))>
```

Body: `{"id","event","created_at","data":{…}}`. 2xx = delivered.

| Event | `data` |
|---|---|
| `sign_request.completed` | `{"id","sub","status","cms"?,"refusal_reason"?}` |
| `signature.updated` | `{"sub","uuid","image_sha256"}` |
| `signature.revoked` | `{"sub","uuid","reason","window"?:{"from","to"}}` |
| `person.separated` | `{"sub"}` |
| `signature.flagged` | `{"sub","document_hashes":[],"window":{"from","to"}}` |

App side: `POST /signature/hub/webhook` (client mode), tolerance
`hub.webhook_tolerance`, dedupe on `X-Signature-Hub-Id` via `HubEvent`.

---

## 4. Agent wire changes (package ↔ Kukux Sign Agent)

All additive: a standalone server never sends the new fields.

1. **`requesting_app`.** A claimed job may carry
   `"requesting_app": {"name": "performance"}`. Confirm view: "performance asks:
   <document title>".
2. **Login** (usernameless hub sign-in).
   - Link: `kukuxsign://login/<challenge-uuid>?t=<token>&s=<serverId>`
     (same UUID / token / server-id rules as `job`).
   - Agent → `POST /signature/agent/logins/{challenge}/claim` body
     `{"link_token": t}`, authenticated exactly like
     `POST /signature/agent/jobs/{job}/claim` (bearer + request proof).
   - 200: a normal job payload with `"purpose": "login"`,
     `"document": {"title": "Sign in to <app name>"}` and
     `"login": {"match_code": "47-12", "browser": "Chrome on macOS", "ip": "10.1.2.3"}`.
     Errors: 404 `login_not_found`, 409 `login_unavailable`, 403
     `invalid_link_token`.
   - Then the agent shows the match code, asks for Touch ID / Windows Hello,
     and finishes with the **existing** `POST /signature/agent/jobs/{job.uuid}/complete`
     (`proof` over `canonicalMessage('login', nonce, user_id, payload_hash)`)
     or `/reject`.
3. **Transfer.** A normal `kukuxsign://job/…` link; job `"purpose": "transfer"`,
   `"document": {"title": "Move your signature to a new computer"}`,
   `"transfer": {"name": "Juan Dela Cruz", "device": "MacBook Air"}`.
   Completed/rejected through the existing job endpoints.
4. **Canonical purposes** gain `login` and `transfer`; shared vectors in
   `tests/fixtures/agent-canonical-vectors.json` (package) and
   `test/fixtures/canonical-vectors.json` (agent) must stay identical.
5. **Name refresh.** `GET /signature/agent/status` already returns
   `user.name`; the agent updates its stored `userName` when it differs.

Server side, `AgentJobService::complete()` / `reject()` fire
`Events\AgentJobUpdated`. The identity work listens for `login` / `transfer`;
the hub API listens for `sign_receipt` jobs linked to a `HubSignRequest`
(`agent_job_id`).

---

## 5. Deferred (hash-only) signing

- `DeferredPdfSigner::prepare()` stamps the image, writes a PDF whose
  signature `/Contents` is a zero-filled placeholder of at least 32 768 hex
  chars, and returns `PreparedPdf{path, digest, byteRange, placeholderLength}`
  where `digest = sha256(bytes covered by /ByteRange)`.
- The hub signs that digest with `DigestSigner::signDigest()` → DER
  `ContentInfo(SignedData)`, detached, signed attributes `contentType`,
  `signingTime`, `messageDigest`, `signingCertificateV2`; optional RFC 3161
  timestamp as unsigned attribute.
- `DeferredPdfSigner::inject($path, $cmsDer)` writes the CMS hex into the
  placeholder (zero-padded) and returns the signed PDF's path.

---

## 6. Who owns what (parallel build)

| Stream | Owns (create/edit) | Must not edit |
|---|---|---|
| **Agent** | everything in `digital-signature-agent/`; `digital-signature/tests/fixtures/agent-canonical-vectors.json` | other package files |
| **Crypto** | `src/Support/Der/*`, `src/Hub/Cms/*`, `src/Drivers/PdfSigners/DeferredPdfSigner.php`, `src/Support/LocalCopy.php`; LocalCopy edits in `FpdiDriver`, `TcpdfDriver`, `Pdf/BladePdfTemplate`; `docs/hub/deferred-signing.md`, `docs/hub/rustfs.md`; its tests | anything else |
| **Hub API** | `src/Hub/Api/*`, `src/Hub/OAuth/*`, `src/Hub/Webhooks/*`, `src/Hub/Signing/*`, `src/Hub/Specimens/*`, `src/Hub/HubApiServiceProvider.php`, `routes/hub-api.php`, its console commands under `src/Console/Hub/Api*`; `docs/hub/api.md`; tests under `tests/Feature/Hub/Api/` | identity / client files |
| **Hub identity** | `src/Hub/Identity/*`, `src/Hub/Filament/*`, `src/Hub/HubIdentityServiceProvider.php`, `routes/hub-web.php`, `resources/views/hub/*`, its commands under `src/Console/Hub/Identity*`; `docs/hub/identity.md`, `docs/hub/panels.md`; tests under `tests/Feature/Hub/Identity/` | API / client files |
| **Client** | `src/Client/*`, `src/Signatories/HubUserMapper.php`, `routes/client.php`, `resources/views/client/*`; client-mode edits to `SignatureManager`, `Filament/Concerns/RegistersSignatures`, `SignatureLauncher` + its blade, `SignatureDocumentController`, `AgentWebController`, `SignatureVerificationController`, `InstallCommand` + install steps, `SigningSessionManager` / `PdfSignerService` sign path; `docs/hub/client.md`; tests under `tests/Feature/Client/` | hub files |

Shared files (`SignatureServiceProvider`, `SignaturePlugin`, config, the
migration, models) are foundation: if a stream needs a change there, keep it
minimal, re-read the file right before editing, and mention it in its report.
