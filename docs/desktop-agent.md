# Desktop agent (Kukux Sign Agent)

Kukux Sign Agent is a small desktop app for macOS and Windows. It creates a signing key inside the machine's security chip (Secure Enclave on a Mac, TPM / Windows Hello on Windows), pairs that machine with your Laravel app, and approves document signings with Touch ID or Windows Hello.

Once a user has paired a computer, signing a document asks them to approve it on that computer. The signature then records the computer as its device, so the app can show "Used on: MacBook Pro (Secure Enclave)".

The agent lives in its own repo: https://github.com/cortejojicoy/digital-signature-agent. Its `docs/protocol.md` is the wire contract. This page covers the package side.

---

## When to turn it on

Browser device keys (see [security.md](security.md)) identify a browser profile. The agent identifies the physical machine.

| | Browser key | Desktop agent |
|---|---|---|
| Identifies | A browser profile | The physical machine |
| Where the key lives | IndexedDB (software) | Secure Enclave / TPM, can't be exported |
| Survives clearing site data | No | Yes |
| User presence | Not enforced | Touch ID / Windows Hello, enforced by the OS |
| Needs an install | No | Yes |

Turn it on when you want signatures tied to hardware the signer physically controls. Leave it off if your users can't install software; browser signing keeps working either way.

---

## Enabling it

Set one env var and serve the app over HTTPS:

```dotenv
SIGNATURE_AGENT_ENABLED=true
```

- The agent only talks to HTTPS origins (its dev builds also allow localhost). Locally, use `herd secure` or `valet secure`.
- `signature.devices.enabled` must also be on (it is by default). If either flag is off, every agent route answers 404 `agent_disabled`.
- The tables come with the package's single migration. Nothing extra to publish.

Users pair a computer from **My signing devices**. That's the `SigningDevices` Livewire component, shown in the launcher's **Devices** tab and in the Signatures page's **Signing devices** slide-over.

---

## Configuration

Everything lives under `signature.devices.agent` in `config/signature.php`.

| Key | Env var | Default | What it does |
|---|---|---|---|
| `enabled` | `SIGNATURE_AGENT_ENABLED` | `false` | Turns the feature on. |
| `approval` | `SIGNATURE_AGENT_APPROVAL` | `prefer` | When to ask the agent. See below. |
| `scheme` | none | `kukuxsign` | URL scheme for `kukuxsign://pair` and `kukuxsign://job` links. |
| `download_url` | `SIGNATURE_AGENT_DOWNLOAD_URL` | GitHub latest release | Shown when the agent doesn't respond. |
| `min_version` | `SIGNATURE_AGENT_MIN_VERSION` | `0.1.0` | Older agents get 426 `agent_outdated`. |
| `require_presence` | `SIGNATURE_AGENT_REQUIRE_PRESENCE` | `true` | Refuse identity keys without OS-enforced Touch ID / Hello. |
| `server_id` | `SIGNATURE_AGENT_SERVER_ID` | derived from `APP_KEY` | Identifies this install in job links (`s=`). `[A-Za-z0-9_-]{1,64}`. |
| `salt` | `SIGNATURE_AGENT_SALT` | derived from `APP_KEY` | Mixed into the agent's hardware-id hash. |
| `pairing_ttl` | none | `600` | Seconds a pairing code stays valid. |
| `job_ttl` | none | `300` | Seconds a signing job stays valid, and how long a completed approval can be spent. |
| `skip_ttl` | none | `120` | Seconds "Sign in the browser instead" suppresses the agent. |

### Approval modes

`approval` decides what happens when a user who has a paired computer signs a document.

| Mode | Behavior |
|---|---|
| `off` | Never asks the agent. The browser device is recorded as usual. |
| `prefer` | Asks the agent, and offers **Sign in the browser instead**. Users without a paired computer sign as before. |
| `enforce` | Signing a document needs approval on a paired computer. No skip. Users with no paired computer get `UnregisteredDeviceException`. |

### server_id, salt and APP_KEY

If you leave `server_id` and `salt` unset, both are HMACs of `APP_KEY`. That's fine until you rotate `APP_KEY`.

Rotating it changes both values. Paired agents won't recognize the new `s=` in job links, and re-pairing the same machine won't be detected as a reinstall. Pin them before you ever rotate the key:

```dotenv
SIGNATURE_AGENT_SERVER_ID=acme-sign-prod
SIGNATURE_AGENT_SALT=some-long-random-string
```

---

## How pairing works

Pairing is device-code style, with two confirmations: the code proves the agent is next to the logged-in user, and the web confirm proves the user wants *that* machine.

1. On the web, the user clicks **Pair**. The server creates a pairing with an 8-character code like `K7QM-2XPD` and an **Open in Kukux Sign Agent** link (`kukuxsign://pair?o=<origin>&c=<code>`). Starting a new pairing expires the user's previous one.
2. The agent sends the code to `POST /pairings/lookup` and gets back the pairing uuid, a nonce, the user, and the server's `id`, `name`, `origin` and `salt`. The agent aborts if `origin` isn't the origin it called.
3. The agent creates two keys: an **identity key** (needs Touch ID / Hello) and a **session key** (no prompt, ES256 only).
4. The agent calls `POST /pairings/{uuid}/claim` with both public keys, its device info and a `register_agent` proof from the identity key. The server verifies it and returns a `poll_secret`. Status becomes `awaiting_confirmation`.
5. The web shows something like "Pair Juan's MacBook Pro (macOS 15.1, Secure Enclave · Touch ID)?" and the user confirms or rejects.
6. On confirm, the server creates a `SigningDevice` with `kind = agent`, records an `agent.paired` audit row and sends the usual new-device notification.
7. The agent polls `POST /pairings/{uuid}/poll` with its `poll_secret`. After confirmation it gets the device and a bearer token, exactly once.

A few rules the server enforces:

- Codes use an unambiguous alphabet, are stored hashed, and lookups are throttled to 10 a minute.
- The claim's `algorithm` must be `ES256` or `RS256` and match the identity key. The session key must be ES256.
- A key fingerprint that's already registered is refused (409).
- Confirming respects `signature.devices.max_per_user`.
- If the claim's `hardware_id_hash` matches an active agent device of the same user, confirming revokes the old one. That covers reinstalls.
- Every agent device is saved with `attested = false` for now.

---

## How signing approval works

You don't call anything to start a job. It happens inside `DeviceRegistry::forSigning()` whenever a document is signed with a document hash.

1. A user with an active agent device signs a document. The server creates a job with the hash of the exact PDF and throws `AgentApprovalRequiredException`.
2. The signing surface hands that to the browser:
   - JSON endpoints (the launcher's viewer, the template signer) return **428** with an `agent_approval` payload.
   - Livewire surfaces (`SignDocumentAction`, the inbox) dispatch `kukux-signature:agent-approval`.
3. `resources/js/utils/agentApproval.js` opens `kukuxsign://job/<uuid>?t=<link token>&s=<server id>` and shows an overlay that polls `/signature/agent-web/jobs/{uuid}` every 2 seconds. After 5 seconds with no claim, it shows a "make sure the agent is running / download it" hint.
4. The agent claims the job (`POST /jobs/{uuid}/claim` with the link token), shows the document title and signer, and asks for Touch ID / Hello.
5. The agent posts a `sign_receipt` proof from the identity key to `POST /jobs/{uuid}/complete`. The server verifies it, marks the job `completed` and records an `agent.approved` audit row.
6. The browser retries the original request. The server finds the completed, unspent job for that user and document hash, records the agent as the signature's `device_id`, and spends the job (`consumed_at`, `signature_id`).

Things to know:

- **Approvals are single use and hash-bound.** The retry has to produce the same document hash, within `job_ttl` of completion, and each job is spent once.
- **One approval covers one request.** Signing several slots of one document changes the hash each time, so the approved device stays in effect for the rest of that HTTP request.
- **Only document signing asks.** Creating a signature and delegated signing (`storeDelegated()`) never create a job.
- **Declines and cancels.** `POST /jobs/{uuid}/reject` with `reason` of `declined`, `os_prompt_cancelled` or `invalid_job` (anything else becomes `declined`). The overlay tells the user nothing was signed.
- **Events.** `AgentJobUpdated` fires on claim, complete, reject and expire. It isn't a broadcast event; the package's overlay polls. Listen to it if you want to broadcast it yourself.

### The approval payload

Both the 428 body and the Livewire event carry this:

```json
{
  "job": "<uuid>",
  "link": "kukuxsign://job/<uuid>?t=<token>&s=<server id>",
  "status_url": "/signature/agent-web/jobs/<uuid>",
  "skip_url": "/signature/agent-web/skip",
  "device": "Juan's MacBook Pro",
  "download_url": "https://…",
  "title": "Accomplishment Report",
  "expires_at": "2026-10-02T08:00:00+00:00"
}
```

`skip_url` is `null` under `enforce`. The 428 body also has an `error` string. The Livewire event adds a `retry` object (`component`, `method`, `params`).

---

## Tokens and request proofs

After pairing, every agent call is authenticated by `AuthenticateAgent`. A leaked token alone is useless, because each request must also be signed by the session key, which never leaves the chip.

| Header | Value |
|---|---|
| `Authorization` | `Bearer <token>`. Stored as SHA-256, one device per token. |
| `X-Agent-Timestamp` | Unix seconds. Must be within ±60 s of the server. |
| `X-Agent-Nonce` | base64url, 16 to 128 chars, never reused per device. |
| `X-Agent-Proof` | base64 DER ES256 signature by the session key over the `request` message below. |
| `X-Agent-Version` | Agent version, checked against `min_version` on every agent route. |

Nonces are remembered in your cache store for 5 minutes, and only after the proof verifies. Any 401 makes the agent forget the pairing and delete its keys.

Revoking an agent device from the web also revokes its tokens, so its next request gets a 401.

---

## Canonical proof message

Every proof, browser or agent, signs the same string. `DeviceProofVerifier::message()` builds it:

```
v1|<purpose>|<nonce>|<user_id>|<payload_hash>
```

- `nonce` is base64url, `user_id` is the user's id as a string, `payload_hash` is lowercase hex SHA-256.
- Every field is known to the server before the agent answers, so the agent can prove possession but can't inject anything.
- ES256 signatures from the agent are DER. RS256 (Windows Hello) is PKCS#1 v1.5 over SHA-256.

| Purpose | Signed by | Nonce | payload_hash |
|---|---|---|---|
| `register_agent` | Identity key | Pairing nonce | `sha256(identity_spki_der ‖ session_spki_der)`, raw bytes concatenated |
| `sign_receipt` | Identity key | Job nonce | The document hash from the job |
| `request` | Session key | `X-Agent-Nonce` | `sha256("<METHOD>\|<path+query>\|<raw body>\|<timestamp>")` |

For `request`, the path is the request URI including the query string (`getRequestUri()`), and the body is the raw bytes, so a tampered body fails.

`tests/Fixtures/agent-canonical-vectors.json` is a copy of the agent repo's `test/fixtures/canonical-vectors.json`. `tests/Feature/AgentProtocolTest.php` asserts the package builds the same messages, request hashes and key bindings. If you change the format, change it in both repos and update the vectors.

---

## Endpoints

### Agent-facing (`/signature/agent`)

These are **not** in the `web` group (the agent has no cookies, so CSRF would refuse every POST). All of them go through `EnsureAgentVersion`. Bodies are JSON.

| Method | Path | Auth | Throttle | Body | Returns |
|---|---|---|---|---|---|
| POST | `pairings/lookup` | none | 10/min | `user_code` | pairing, nonce, user, `server{id,name,origin,salt}`, `require_presence`, `expires_at` |
| POST | `pairings/{uuid}/claim` | code + proof | 20/min | `user_code`, `identity_public_key`, `session_public_key` (base64 SPKI), `algorithm`, `protection`, `user_presence`, `agent_version`, `device{…}`, `attestation?`, `proof` | `status: awaiting_confirmation`, `poll_secret` |
| POST | `pairings/{uuid}/poll` | poll secret | 120/min | `poll_secret` | `status`; when confirmed, also `device{uuid,label}` and `token` (once) |
| GET | `status` | agent | 120/min | none | `device{uuid,label,status}`, `user{id,name}` |
| DELETE | `device` | agent | 120/min | none | 204, device and tokens revoked |
| POST | `jobs/{uuid}/claim` | agent | 120/min | `link_token` | `uuid`, `purpose`, `status`, `nonce`, `user_id`, `payload_hash`, `document{title}`, `signer{name}`, `expires_at` |
| POST | `jobs/{uuid}/complete` | agent | 120/min | `proof` | `status: completed` |
| POST | `jobs/{uuid}/reject` | agent | 120/min | `reason` | current `status` |

`device` in the claim takes `platform` (`macos` / `windows`), `os_version`, `model`, `model_identifier`, `form_factor` (`laptop` / `desktop`), `label` and `hardware_id_hash` (64 hex chars). `protection` is `secure_enclave`, `tpm` or `software`; anything else becomes `software`.

### Browser-facing (`/signature/agent-web`)

These use the `web` group and the logged-in session, throttled to 120/min.

| Method | Path | What it does |
|---|---|---|
| GET | `jobs/{uuid}` | Job status for the overlay: `status`, `reason`, `device{label,protection}`. 404 for other users' jobs. |
| POST | `skip` | "Sign in the browser instead" for `skip_ttl` seconds. 403 under `enforce`. |

---

## Tables

All three come from the package migration, and the agent columns already exist on `digital_signature_devices`.

| Table | Model | Holds |
|---|---|---|
| `digital_signature_agent_pairings` | `AgentPairing` | One pairing attempt: `user_code_hash`, `nonce`, `poll_secret_hash`, `status` (`pending`, `awaiting_confirmation`, `confirmed`, `rejected`, `expired`), `claim` (json), `device_id`, `token_issued_at`, `expires_at` |
| `digital_signature_agent_tokens` | `AgentToken` | Bearer tokens: `device_id`, `token_hash` (unique), `last_used_at`, `revoked_at` |
| `digital_signature_agent_jobs` | `AgentJob` | Signing jobs: `user_id`, `device_id`, `signature_id`, `purpose`, `title`, `signable` morph, `payload_hash`, `nonce`, `link_token_hash`, `status` (`pending`, `claimed`, `completed`, `rejected`, `expired`), `reason`, `claimed_at`, `completed_at`, `consumed_at`, `expires_at` |
| `digital_signature_devices` | `SigningDevice` | Agent rows have `kind = agent`, `protection`, `user_presence`, `attested`, `form_factor`, `model`, `hardware_id_hash`, `agent_version`, `session_public_key` |

Secrets (codes, poll secrets, tokens, link tokens) are only ever stored as SHA-256.

---

## Errors

Agent routes always answer in the protocol's shape, never a redirect (the agent refuses redirects):

```json
{ "error": { "code": "invalid_code", "message": "That code is invalid or has expired." } }
```

The agent shows `message` to the user. `AgentApiException::render()` produces it.

| Status | Code | When |
|---|---|---|
| 400 | `invalid_json` | Body isn't a JSON object |
| 401 | `unauthenticated` | Unknown or revoked token, or the device isn't an active agent |
| 401 | `stale_request` | Timestamp outside ±60 s |
| 401 | `replayed_request` | Nonce missing, malformed or reused |
| 401 | `invalid_request_proof` | `X-Agent-Proof` didn't verify |
| 403 | `invalid_code` | Wrong code on claim |
| 403 | `invalid_link_token` | Link token doesn't match the job |
| 404 | `agent_disabled` | Feature is off |
| 404 | `invalid_code` | Lookup: unknown or expired code |
| 404 | `invalid_pairing` | Claim / poll: pairing missing, expired or wrong poll secret |
| 404 | `job_not_found` | Job missing or belongs to another user |
| 409 | `key_already_registered` | Identity key already registered |
| 409 | `token_already_issued` | Poll after the token was handed out |
| 409 | `job_unavailable` | Job not pending (claim), or not claimed by this device / expired (complete) |
| 422 | `invalid_request` | A required string field is missing or too long |
| 422 | `invalid_algorithm`, `invalid_key` | Bad algorithm, or keys don't match it |
| 422 | `invalid_proof` | Registration or receipt proof didn't verify |
| 422 | `presence_required` | `require_presence` is on and the key has no user presence |
| 426 | `agent_outdated` | Below `min_version`; the body also has `min_version` |

On the PHP side you'll see:

- `AgentApprovalRequiredException`: signing is waiting on the user's computer. Catch it in custom signing surfaces and return `toJsonResponse()` (428) or dispatch `livewireEvent()`.
- `UnregisteredDeviceException`: `enforce` with no paired computer, or `max_per_user` reached on confirm.

---

## Gotchas

- **Proxies.** `server.origin` in the lookup response comes from the request. Behind a reverse proxy, configure `TrustProxies`, or the agent sees a mismatched origin and aborts pairing.
- **APP_KEY rotation.** Pin `SIGNATURE_AGENT_SERVER_ID` and `SIGNATURE_AGENT_SALT` first (see above).
- **Shared cache.** Request nonces are tracked in the cache. With several app servers, use a shared store (Redis, database), or a replay could slip through on another node.
- **Clock skew.** Requests more than 60 s off are refused as `stale_request`. Tell users to fix their clock.
- **Custom signing surfaces.** If you call `SignatureManager::storeForDocument()` yourself, catch `AgentApprovalRequiredException` and hand the payload to `agentApproval.js`, or users with a paired computer can't sign there.
- **Windows without Hello.** A TPM key with no Hello has `user_presence = false`, so pairing fails with `presence_required` unless you set `SIGNATURE_AGENT_REQUIRE_PRESENCE=false`.
- **Nothing prunes old rows.** Expired pairings and jobs are marked lazily when touched. Prune the tables yourself if they grow.

Not built yet: attestation verification (everything is stored with `attested = false`), and PNPKI `.p12` / USB-token PDF signing through the agent.
