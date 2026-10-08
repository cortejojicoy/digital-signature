# Hub API

What apps (performance, amp, sims, …) call at `signature.uplb.edu.ph`, and
what the hub sends them. On only when `SIGNATURE_MODE=hub`. The wire formats
are fixed in [contracts.md](contracts.md) §2–§3; this page is how to use and
run them.

Every error body is `{"error": "<code>", "message": "<human text>"}`, sometimes
with extra fields (e.g. `current_specimen_hash`).

- [Registering an app](#registering-an-app)
- [OAuth](#oauth)
- [People and signatures](#people-and-signatures)
- [Sign requests](#sign-requests)
- [Webhooks](#webhooks)
- [Specimens and revocation (hub-side PHP)](#specimens-and-revocation-hub-side-php)
- [Commands and scheduling](#commands-and-scheduling)
- [Runbook notes](#runbook-notes)

---

## Registering an app

```bash
php artisan signature:hub-app performance "UPLB Performance" \
    --redirect=https://performance.uplb.edu.ph/signature/hub/callback \
    --webhook=https://performance.uplb.edu.ph/signature/hub/webhook
```

- Prints `SIGNATURE_HUB_CLIENT_ID`, `SIGNATURE_HUB_CLIENT_SECRET` and
  `SIGNATURE_HUB_WEBHOOK_SECRET` for the app's `.env`. **Shown once.**
- The client secret is stored as its SHA-256; the webhook secret is stored
  encrypted with `APP_KEY` (it has to be readable to sign webhooks).
- `--scopes=signatures.read --scopes=sign` (default: both).
- Redirect URIs are matched **exactly**. `http://` only for `localhost` /
  `*.test`.
- The app's mirror prefix on RustFS is its client id.

From PHP (the admin Apps page): `Hub\OAuth\HubAppRegistrar`:

| Method | |
|---|---|
| `create($clientId, $name, $redirectUris = [], $webhookUrl = null, $scopes = ['signatures.read','sign'])` | `['app' => HubApp, 'client_secret' => …, 'webhook_secret' => …]` |
| `rotateSecret(HubApp)` | new client secret; **revokes the app's tokens** |
| `rotateWebhookSecret(HubApp)` | new webhook secret |

---

## OAuth

Built into the package (no Passport). Tokens and codes are 64 random
characters, stored only as SHA-256.

### App token (client credentials)

```bash
curl -X POST https://signature.uplb.edu.ph/signature/hub/oauth/token \
  -d grant_type=client_credentials -d client_id=performance -d client_secret=$SECRET
```

```json
{"access_token": "…", "token_type": "Bearer", "expires_in": 3600, "scope": "signatures.read sign"}
```

HTTP Basic (`-u performance:$SECRET`) works too. Lifetime: `hub.token_ttl`
(3600 s). A wrong secret, an unknown client and a disabled app all answer the
same `401 invalid_client`.

### Signing a person in (authorization code + PKCE S256)

1. Send the browser to

   ```
   GET /signature/hub/oauth/authorize?response_type=code&client_id=performance
       &redirect_uri=<exact registered URI>&state=<random>
       &code_challenge=<BASE64URL(SHA256(verifier))>&code_challenge_method=S256
   ```

   - Not signed in at the hub → the hub's landing page (sign in with the
     agent), then back here.
   - Identity not **verified** by an admin → 403 page. No code.
   - Unknown client or unregistered `redirect_uri` → 400 page (the hub never
     redirects to an unregistered URI).
   - Missing/`plain` PKCE or a wrong `response_type` → back to `redirect_uri`
     with `error=invalid_request` / `unsupported_response_type`.
   - Success → `redirect_uri?code=…&state=…`, and the app becomes a
     **holder** for that person (it may fetch their image and gets their
     webhooks).
2. Exchange the code (one use, `hub.code_ttl` = 60 s):

   ```bash
   curl -X POST …/signature/hub/oauth/token -d grant_type=authorization_code \
     -d client_id=performance -d client_secret=$SECRET -d code=$CODE \
     -d redirect_uri=$SAME_URI -d code_verifier=$VERIFIER
   ```

   ```json
   {"access_token": "…", "token_type": "Bearer", "expires_in": 3600, "sub": "<personnel key>"}
   ```

   `400 invalid_grant` for a used, expired or foreign code, a different
   `redirect_uri`, a PKCE mismatch, or an account no longer verified.
3. `GET /signature/hub/userinfo` with the person token →
   `{"sub","name","email","emp_no","unit","position"}`.

`sub` is the **personnel key**, not a hub user id, so it survives a move to a
new computer.

### Scopes

| Scope | Endpoints |
|---|---|
| `signatures.read` | people, signature metadata and image, certificates |
| `sign` | sign requests |

A scope must be on the token **and** still on the app: removing a scope from
an app takes effect at once. Person tokens only reach `userinfo`.

Rate limits: token endpoint 30/min per client + IP; API 600/min per token;
authorize and health 60/min per IP.

---

## People and signatures

All under `/signature/hub/api/v1`, `Authorization: Bearer <app token>`.

| Endpoint | Notes |
|---|---|
| `GET health` | No auth. `{"status","checks":{"database","queue","specimen_disk","mirrors_disk"}}`; 503 when degraded. `mirrors_disk` is `null` when not configured |
| `GET people?emp_no=E-1001` / `?email=…` | Exact match, active people, at most one: `{"data":[…]}`. Not a search |
| `GET people/{sub}` | Claims + `active`; 404 `unknown_person` |
| `POST people/{sub}/link` | The app names this person as a signatory → it becomes a holder. `{"linked":true}` |
| `GET people/{sub}/signature` | `{"uuid","status":"active","image_sha256","certificate_fingerprint","updated_at"}`; 404 `no_signature` (none, revoked, or the claim isn't verified) |
| `GET people/{sub}/signature/image` | PNG. Holders only (403 `not_linked`). `ETag: "<sha256>"`, `X-Image-Sha256`; send `If-None-Match` and get `304` while it's unchanged. Each 200 is audited `hub.image_served` with the app |
| `GET certificates/{fingerprint}` | `{"fingerprint","status":"valid"|"revoked"|"unknown","revoked_at"?,"expires_at"?,"subject"?}` |

Email lookup: `PersonnelDirectory` has no email method, so the hub uses the
directory's `findByEmail()` when it has one, then its `search()`, then the
hub's own verified accounts (a verified person's user row carries their
directory email).

The image's hash is computed over the bytes sent, so an app comparing it with
`image_sha256` from the metadata also catches an edited master (R5).

---

## Sign requests

```bash
curl -X POST …/api/v1/sign-requests -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{
    "sub": "p-123", "document_hash": "<sha256 hex of /ByteRange>",
    "specimen_hash": "<sha256 of the image you stamped>",
    "title": "Accomplishment Report Q3", "slot": "Prepared by",
    "capacity": "Assistant Professor", "idempotency_key": "ar-2026-q3-12-prepared"
  }'
```

`202`:

```json
{"id": "<uuid>", "status": "pending", "approval_link": "kukuxsign://job/…",
 "job_uuid": "<uuid>", "expires_at": "…"}
```

- The person approves on their paired computer; the agent shows
  "UPLB Performance asks: Prepared by · Accomplishment Report Q3".
- The hub then signs the digest into a detached CMS with the person's
  certificate (+ TSA timestamp when `signature.tsa.url` is set) and tells the
  app (`sign_request.completed`), or the app polls
  `GET sign-requests/{id}` → `{"id","status","cms"?,"certificate_fingerprint"?,"signed_at"?,"refusal_reason"?}`.
- `idempotency_key` (8–128 of `A-Za-z0-9._:-`, or the `Idempotency-Key`
  header): the same key returns the same request with `200`, including the
  approval link while it is still unclaimed. The same key with a different
  `sub` or `document_hash` is `409 idempotency_conflict`. Keys are per app.
- Lifetime: `hub.sign_request_ttl` (300 s).

Refusals (no request is stored; each is audited `hub.sign_refused`):

| Status | `error` | Meaning |
|---|---|---|
| 409 | `specimen_changed` | The image you stamped isn't the current one. Body has `current_specimen_hash`: re-pull, re-stamp, resubmit |
| 422 | `unknown_person` | Not in the directory |
| 422 | `separated` | Left the university |
| 422 | `not_verified` | No verified hub account |
| 422 | `no_signature` | No active signature |
| 422 | `certificate_revoked` | Certificate revoked |
| 422 | `invalid_request` | Malformed body |

The same checks run again when the agent approves (the person may have
changed or revoked their signature meanwhile); a failure then ends the
request as `refused` with that `refusal_reason`.

Statuses: `pending` → `signed` | `declined` (the person said no, or cancelled
Touch ID / Windows Hello) | `refused` | `expired` | `failed` (the hub couldn't
make the CMS: `no_certificate_password`, `signing_failed`).

Audit (admin trail): `hub.sign_requested`, `hub.signed` (title, slot,
capacity, document and specimen hashes, specimen version id, device,
proof purpose, user-presence method), `hub.sign_declined`, `hub.sign_refused`
(reason, including `expired`), all with `app` = client id and the person's
`personnel_key`.

---

## Webhooks

Format: [contracts.md §3](contracts.md#3-webhooks-hub--app). Verify on the app
side:

```php
$expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $webhookSecret);
hash_equals($expected, $request->header('X-Signature-Hub-Signature'));
```

- Every event is written to the outbox (`digital_signature_hub_webhooks`)
  first, then a queued job (`signature.queue`) tries it once.
- Any 2xx is delivered. Otherwise retry after 1 min, 2, 4, … capped at 24 h,
  up to `hub.webhook_max_attempts` (12) attempts; `last_status` and
  `last_error` keep the latest failure.
- Recipients: active apps with a webhook URL that hold the person.
  `sign_request.completed` goes only to the app that asked.
- The body is identical on every retry; apps dedupe on `X-Signature-Hub-Id`.

From PHP: `Hub\Webhooks\HubNotifier` (`signatureUpdated`, `signatureRevoked`,
`personSeparated`, `signatureFlagged`, `signRequestCompleted`) and
`Hub\Webhooks\HubWebhookDispatcher` (`deliver`, `redeliver`, `deliverDue`).
`redeliver()` sends again now whatever the state (the admin's button).

---

## Specimens and revocation (hub-side PHP)

- `Hub\Specimens\SpecimenService::published(Signature $signature)`: call
  after a person stores or replaces their signature. Copies the image to
  `hub.specimen_disk` (when it's not `storage_disk`) with
  `x-amz-meta-sha256`, records the RustFS `VersionId` in `hub_version_id`
  when the bucket is versioned, and sends `signature.updated` to holders.
- `Hub\Specimens\HubRevocation::revokeSignature($userId, $reason, $actorId = null, $window = null)`:
  revokes the master signature and the account's certificates, deletes
  `<prefix>/<hub_uuid>.png` in **every** app's prefix on `hub.mirrors_disk`
  (when set), sends `signature.revoked` (with `window` when given) and writes
  one `signature.revoked` audit row.

---

## Commands and scheduling

```php
// routes/console.php in the hub app
Schedule::command('signature:hub-webhooks')->everyMinute()->withoutOverlapping();
Schedule::command('signature:hub-audit-mirrors')->dailyAt('02:30');
```

| Command | |
|---|---|
| `signature:hub-app {client_id} {name} --redirect=* --webhook= --scopes=*` | Register an app; prints secrets once |
| `signature:hub-webhooks` | Expire sign requests nobody approved in time, then deliver due webhooks |
| `signature:hub-webhooks --redeliver=<event uuid>` | Send one event again now |
| `signature:hub-audit-mirrors [--delete-orphans]` | Compare each app's prefix on the mirrors disk with the holders table. Exits 1 while orphans remain |

---

## Runbook notes

**Hub outage (R1).** Apps keep placements and resubmit with the same
`idempotency_key`; the hub returns the existing request, never a second
signature. Point the uptime monitor at `GET /signature/hub/api/v1/health`
(503 when the database, queue or a disk fails). After recovery
`signature:hub-webhooks` replays the outbox.

**Hub compromise (R2).** After revoking the issuing CA:

1. Rotate every app's secrets: `HubAppRegistrar::rotateSecret()` (also
   revokes their tokens) and `rotateWebhookSecret()`; hand the new values to
   each app.
2. Rotate `APP_KEY` only after re-encrypting webhook secrets (they are
   encrypted with it), or rotate them all as above.
3. List signatures in the window from the audit trail (`hub.signed` rows)
   and send `HubNotifier::signatureFlagged($sub, $documentHashes, $window)`.
   Revoke with `HubRevocation::revokeSignature(..., window: [...])` where
   needed.

**Missed webhook (R6).** Check the outbox for rows with `delivered_at` null
and growing `attempts`; fix the app and `--redeliver`. Revocation does not
depend on webhooks: mirror objects are deleted by the hub and the hub refuses
to sign with a revoked signature.

**Mirrors (R4, R13).** `signature:hub-audit-mirrors` nightly; investigate any
orphan before `--delete-orphans` (it logs each deletion). Revocation that
can't reach RustFS still revokes, logs an error, and leaves the cleanup to
this command.
