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
| `checkin_ttl` | `SIGNATURE_AGENT_CHECKIN_TTL` | `90` | Under `enforce`: seconds a "this computer?" check stays open. |
| `checkin_valid_for` | `SIGNATURE_AGENT_CHECKIN_VALID_FOR` | `900` | Under `enforce`: seconds a confirmed check is remembered for the session. |
| `devices_url` | `SIGNATURE_AGENT_DEVICES_URL` | the app's origin | Where the agent sends people to manage their signing devices, for example to remove the computer their account is already paired with. Must be on this app's origin, or the agent uses the origin instead. |
| `blocked_device_types` | `SIGNATURE_AGENT_BLOCKED_DEVICE_TYPES` | `virtual_machine` | Device types refused at pairing, comma-separated. Checked against what the agent *detected*, which the owner can't change. Set it empty to allow VMs, for example for development in Parallels. |

### Approval modes

`approval` decides what happens when a user who has a paired computer signs a document.

| Mode | Behavior |
|---|---|
| `off` | Never asks the agent. The browser device is recorded as usual. |
| `prefer` | Asks the agent, and offers **Sign in the browser instead**. Users without a paired computer sign as before. |
| `enforce` | Signing a document needs approval on a paired computer. No skip. Users with no paired computer get `UnregisteredDeviceException`. The signing page also checks it's on the paired computer before it offers **Sign here**; see below. |

### Signing only from the paired computer (`enforce`)

Under `enforce`, the document drawer's **Sign here** button only works on the computer the account is paired with:

| Situation | What the signer sees |
|---|---|
| No paired computer | Button off: "You cannot sign yet: pair Kukux Sign Agent with your computer first." |
| On the paired computer | Button on, once the agent has checked in. |
| On any other computer | Button off: "You are prohibited from signing on this computer. Your account is paired with *{computer}*; sign from that computer." |
| This computer's agent is another account's | Button off: "You are prohibited from signing on this computer. It is paired with another account." |

A web page can't ask the agent anything directly, so the check goes through the server. When the document opens, the page asks for a check, opens its `kukuxsign://presence/{uuid}?t=…&s=…` link, and polls. The agent on that computer, if there is one, reports in with `POST /signature/agent/presence/{uuid}`. It does this silently, with no window and no Touch ID / Hello prompt. If the paired computer reports in, the button turns on, and the session remembers it for `checkin_valid_for` seconds. If nothing answers, this isn't the paired computer, or its agent isn't running or is too old to know the link. The page shows the message after about 12 seconds but keeps listening until the check expires, and offers **Check again**.

`POST /signature/requests/{id}/sign` applies the same rule and answers `403` with `status: "prohibited"` and the message. The approval job is still what authorizes a signature; the check only decides whether this browser may ask for one. Needs Kukux Sign Agent with presence-link support.

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
6. On confirm, the server creates a `SigningDevice` with `kind = agent`, records an `agent.paired` audit row and sends the usual new-device notification. If this user's agent already holds the computer, it updates that device instead (see below).
7. The agent polls `POST /pairings/{uuid}/poll` with its `poll_secret`. After confirmation it gets the device and a bearer token, exactly once.

A few rules the server enforces:

- Codes use an unambiguous alphabet, are stored hashed, and lookups are throttled to 10 a minute.
- The claim's `algorithm` must be `ES256` or `RS256` and match the identity key. The session key must be ES256.
- A key fingerprint that's already registered is refused (409).
- Confirming respects `signature.devices.max_per_user`.
- Device types in `blocked_device_types` are refused at claim (`422 device_type_not_allowed`), and again at confirm in case the policy tightened in between.
- Every agent device is saved with `attested = false` for now.

### One signature per computer

A computer can be paired with many apps, but holds only one account's signature for each. The server tells computers apart by the claim's `hardware_id_hash`, which is `sha256(salt ‖ hardware uuid)`: the same for every account on this app, different on every other app.

| On claim, an active agent device with the same `hardware_id_hash`… | Result |
|---|---|
| doesn't exist | A new device on confirm. |
| belongs to the same user | **Rebind.** The claim returns it as `existing_device`, the confirm prompt says "Re-pair …?", and confirming gives that device the new keys and revokes its old token. Same uuid, label and signature history. `rebound_at` is set, `agent.rebound` is audited, and the poll answers `rebound: true`. |
| belongs to another user | `409 machine_already_paired`. The message never says whose computer it is. |

Confirm checks again under a row lock, and the unique `active_hardware_key` column (the hash while a device is an active agent, otherwise null) holds the line even if two pairings race.

A claim with no `hardware_id_hash` (some boards have no usable firmware uuid) can't be matched to another account's computer, so that check passes; the agent's own check still applies on that computer.

### One computer per account

An account can pair the agent with only one computer per app. On claim, after the check above, the server looks at the account's active agent devices:

| The account's active agent devices… | Result |
|---|---|
| none | A new device on confirm. |
| one, with this claim's `hardware_id_hash` | **Rebind**, as above. |
| one, named by the claim's `replaces` with a valid `rebind_agent` proof | **Rebind**, whatever the hash says. |
| one on another computer, or either hash is missing, and no proof | `409 account_already_paired`, with `device: { label, device_type }` naming the account's own computer. |

**Same-computer proof.** When the same account re-pairs, the agent adds `replaces: { device_uuid, proof }` to the claim: its existing device, and a `rebind_agent` proof by that pairing's session key, with no OS prompt. The session key is created in the Secure Enclave / TPM and can't leave it, so only that computer can produce the proof. This covers computers without a hardware id, and a hardware id that changed (a replaced logic board). A proof that doesn't verify is `422 invalid_proof`; one naming a device that isn't this account's active agent is ignored. A reinstall loses the old keys, so it falls back to the hash: on a computer without one, remove the old device first.

**Placeholder UUIDs.** Some boards ship the same firmware UUID (for example `03000200-0400-0500-0006-000700080009`). The agent sends no hardware id for those and for any UUID of one repeated digit, and the server treats their salted hashes as missing too, for older agents. The list is `AgentPairingService::PLACEHOLDER_UUIDS`, shared with the agent through `tests/Fixtures/placeholder-uuids.json`.

Lookup returns the account's computer as `agent_device: { uuid, label, device_type, hardware_id_hash }`, so the agent can refuse before creating any key or showing Touch ID. Only the holder of the account's own pairing code gets it, and the hash only tells whether it's the same computer.

Confirm checks again under a row lock, and the unique `active_agent_user_key` column (the user id while a device is an active agent, otherwise null) holds the line if two pairings race. **My signing devices** says before pairing that the account already has a computer, and won't confirm a second one.

Browser devices don't count: an account can still sign in any number of browsers.

On upgrade, an account that already holds several computers keeps them all: the newest gets the key and the rest stay active but unkeyed, so no one loses a working computer. New pairings for that account are refused until one is left.

To move to another computer, revoke the old one in **My signing devices**, then pair the new one.

#### Releasing a computer

If an owner unpairs while the server is unreachable, the agent asks first: remove the signature from the computer anyway, or keep the pairing. If they remove it, the agent retries the revoke later. Until it lands, the server still counts the computer as theirs, so another account can't pair it and their account can't pair another computer. An owner can also revoke it from **My signing devices**, or an admin can release it:

```bash
php artisan signature:agent-release              # list paired computers
php artisan signature:agent-release --user=42    # …of one user
php artisan signature:agent-release <uuid>       # release one (asks first; --force to skip)
```

Releasing revokes the device and records an `agent.released` audit row.

### Device types

The agent reports what the computer is, from a fixed catalogue (`Kukux\DigitalSignature\Enums\DeviceType`): `macbook`, `macbook_air`, `macbook_pro`, `imac`, `mac_mini`, `mac_studio`, `mac_pro`, `laptop`, `convertible`, `desktop`, `all_in_one`, `mini_pc`, `server`, `chromebook`, `tablet`, `ipad`, `android_tablet`, `iphone`, `android`, `phone`, `virtual_machine` and `other`. Phones, tablets and Chromebooks are reserved for a future mobile app.

The confirm prompt lets the owner correct it. `detected_device_type` keeps what the agent reported, and policy only ever looks at that, so a detected virtual machine can't be relabelled. A type is a label for people, never a security signal: assurance comes from `protection` and attestation.

Virtual machines already paired before they were blocked keep working. **My signing devices** marks them "Virtual machine: no longer allowed for new pairings".

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
| `rebind_agent` | The existing pairing's session key | Pairing nonce | The same hash, of the new keys. Sent as the claim's `replaces.proof` when re-pairing. |
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
| POST | `pairings/lookup` | none | 10/min | `user_code` | pairing, nonce, user, `server{id,name,origin,salt}`, `require_presence`, `blocked_device_types`, `agent_device`, `devices_url`, `expires_at` |
| POST | `pairings/{uuid}/claim` | code + proof | 20/min | `user_code`, `identity_public_key`, `session_public_key` (base64 SPKI), `algorithm`, `protection`, `user_presence`, `agent_version`, `device{…}`, `attestation?`, `proof` | `status: awaiting_confirmation`, `poll_secret`, `existing_device{uuid,label,device_type}` or null |
| POST | `pairings/{uuid}/poll` | poll secret | 120/min | `poll_secret` | `status`; when confirmed, also `device{uuid,label,device_type}`, `token` (once) and `rebound` |
| GET | `status` | agent | 120/min | none | `device{uuid,label,status}`, `user{id,name}`, `other_devices[{uuid,label,device_type,last_used_at}]` |
| DELETE | `device` | agent | 120/min | none | 204, device and tokens revoked |
| POST | `jobs/{uuid}/claim` | agent | 120/min | `link_token` | `uuid`, `purpose`, `status`, `nonce`, `user_id`, `payload_hash`, `document{title}`, `signer{name}`, `expires_at` |
| POST | `jobs/{uuid}/complete` | agent | 120/min | `proof` | `status: completed` |
| POST | `jobs/{uuid}/reject` | agent | 120/min | `reason` | current `status` |
| POST | `presence/{uuid}` | agent | 120/min | `link_token` | `status: confirmed`; 403 `wrong_account` when the check is another account's (the page is told), 403 `invalid_link_token`, 409 once answered or expired |

`device` in the claim takes `platform` (`macos` / `windows`), `os_version`, `model`, `model_identifier`, `form_factor` (`laptop` / `desktop`), `label`, `hardware_id_hash` (64 hex chars, or null), `device_type` (a catalogue value; anything else becomes `other`, and agents that send none get one from `form_factor`), `chassis_type` (SMBIOS 1–127, or null) and `virtual` (bool). `protection` is `secure_enclave`, `tpm` or `software`; anything else becomes `software`.

### Browser-facing (`/signature/agent-web`)

These use the `web` group and the logged-in session, throttled to 120/min.

| Method | Path | What it does |
|---|---|---|
| GET | `jobs/{uuid}` | Job status for the overlay: `status`, `reason`, `device{label,protection}`. 404 for other users' jobs. |
| POST | `skip` | "Sign in the browser instead" for `skip_ttl` seconds. 403 under `enforce`. |
| POST | `presence` | Under `enforce`: starts a "this computer?" check. Returns `uuid`, `link`, `expires_in`; 409 `unpaired` with no paired computer; 404 outside `enforce`. |
| GET | `presence/{uuid}` | `status`: `pending`, `confirmed`, `other_account` or `expired`. `confirmed` is remembered in the session. 404 for other users' checks. |

---

## Tables

All three come from the package migration, and the agent columns already exist on `digital_signature_devices`.

| Table | Model | Holds |
|---|---|---|
| `digital_signature_agent_pairings` | `AgentPairing` | One pairing attempt: `user_code_hash`, `nonce`, `poll_secret_hash`, `status` (`pending`, `awaiting_confirmation`, `confirmed`, `rejected`, `expired`), `claim` (json), `device_id`, `replaces_device_id` (the device a rebind updates), `token_issued_at`, `expires_at` |
| `digital_signature_agent_tokens` | `AgentToken` | Bearer tokens: `device_id`, `token_hash` (unique), `last_used_at`, `revoked_at` |
| `digital_signature_agent_jobs` | `AgentJob` | Signing jobs: `user_id`, `device_id`, `signature_id`, `purpose`, `title`, `signable` morph, `payload_hash`, `nonce`, `link_token_hash`, `status` (`pending`, `claimed`, `completed`, `rejected`, `expired`), `reason`, `claimed_at`, `completed_at`, `consumed_at`, `expires_at` |
| `digital_signature_devices` | `SigningDevice` | Agent rows have `kind = agent`, `protection`, `user_presence`, `attested`, `device_type`, `detected_device_type`, `chassis_type`, `virtual`, `form_factor`, `model`, `hardware_id_hash`, `active_hardware_key` (unique), `agent_version`, `session_public_key`, `rebound_at` |

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
| 409 | `machine_already_paired` | Another account's agent already holds this computer for this app |
| 409 | `account_already_paired` | This account is already paired with another computer for this app. `device.label` names it. |
| 409 | `token_already_issued` | Poll after the token was handed out |
| 409 | `job_unavailable` | Job not pending (claim), or not claimed by this device / expired (complete) |
| 422 | `invalid_request` | A required string field is missing or too long |
| 422 | `invalid_algorithm`, `invalid_key` | Bad algorithm, or keys don't match it |
| 422 | `invalid_proof` | Registration or receipt proof didn't verify |
| 422 | `presence_required` | `require_presence` is on and the key has no user presence |
| 422 | `device_type_not_allowed` | The detected device type is in `blocked_device_types` (virtual machines by default) |
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
- **Testing in a VM.** Pairing from a virtual machine (Parallels, UTM, VMware…) is refused by default. Set `SIGNATURE_AGENT_BLOCKED_DEVICE_TYPES=` (empty) on development servers only.
- **Shared computers.** One computer holds one account's signature per app. Two people sharing a desk computer for the same app need to unpair between them, or use their own computers.
- **Nothing prunes old rows.** Expired pairings and jobs are marked lazily when touched. Prune the tables yourself if they grow.

## Not built yet

These were planned but aren't in the package today, so don't build on them:

| Feature | What happens today |
|---|---|
| TPM / Secure Enclave attestation checks | The agent's attestation is saved with the claim but never verified. Every device is stored with `attested = false`. |
| Pairing QR code | Users type the pairing code by hand. |
| Scheduled cleanup | Expired pairings and jobs are only marked expired when something touches them. Prune the tables yourself. |
| PNPKI and USB token support | The agent signs with its own hardware key only. No `.p12` certificates or USB tokens. |
