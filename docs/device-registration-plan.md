# Registered Signing Devices — Requirements & Plan

**Status:** phase 1 implemented, with part of phase 2. See
[What was built](#14--what-was-built) for where each piece landed and where
the build departed from this plan.
**Scope:** give every browser/device a user signs from an SSH-style key pair,
register its public key against the user, prove possession of the private key
every time a signature is created or used, and show in the application which
device each act of signing came from.

---

## Table of contents

| # | Section |
|---|---|
| [1](#1--the-problem) | The problem: what exists today and where it falls short |
| [2](#2--the-concept-ssh-keys-for-browsers) | The concept: SSH keys for browsers |
| [3](#3--flows) | Flows: first registration, signing on a known device, a new device (phone) |
| [4](#4--data-model) | Data model |
| [5](#5--server-side-components) | Server-side components |
| [6](#6--client-side-components) | Client-side components |
| [7](#7--ui-where-the-device-shows-up) | UI: where the device shows up |
| [8](#8--configuration) | Configuration |
| [9](#9--security-analysis--limitations) | Security analysis and limitations |
| [10](#10--alternatives-considered) | Alternatives considered |
| [11](#11--phased-delivery) | Phased delivery |
| [12](#12--testing) | Testing |
| [13](#13--open-questions) | Open questions |
| [14](#14--what-was-built) | What was built |

---

## 1 — The problem

### What exists now

- [machineFingerprint.js](../resources/js/utils/machineFingerprint.js) hashes
  browser signals (UA, screen, canvas, WebGL) into a SHA-256 value, caches it
  in `localStorage`, and posts it to
  [DeviceFingerprintController](../src/Http/Controllers/DeviceFingerprintController.php),
  which stores it in the session.
- [SignatureMetadataService](../src/Security/SignatureMetadataService.php)
  folds it into `Sig-Machine-Hash` = `sha256(userId|userAgent|deviceFp)`,
  embeds that in the PNG, and HMACs it with `APP_KEY`.
- `digital_signatures.machine_fingerprint` stores the same hash.

### Why it isn't enough

1. **Nothing is proven.** The fingerprint is a value the client sends. Anyone
   who can read it (it sits in `localStorage` and in the POST body) can replay
   it from another machine. The HMAC proves *the server* wrote the metadata. It
   does not prove *which device* was present.
2. **It is unstable.** A browser update changes the UA, and a monitor change
   changes the screen size. Either one gives a different hash, and the user
   looks like a new machine.
3. **Uses are not recorded.** `SignatureManager::storeForDocument()` copies
   `$source->machine_fingerprint` onto every document-signing row. The app
   records where a signature was *drawn*, not where it was *used*. Signing
   from a phone today is indistinguishable from signing on the laptop that
   drew it.
4. **It is not human-readable.** The Signatures resource shows a 64-char hex
   string. There is no "MacBook — Chrome" label, no list of devices, and no
   way to revoke a device.

### Goal

> When I create a signature, the device I'm on is registered to my account.
> Whenever that signature is used, the app shows which registered device was
> used. If I use it from my phone, the phone gets registered too, and the
> phone shows as the device for that signing.

---

## 2 — The concept: SSH keys for browsers

SSH answers this question: a key pair is generated on the client, the public
key is registered with the server (`authorized_keys`), and each connection
proves possession of the private key by signing a server challenge. The
private key never leaves the machine.

We use the same model with the browser's **Web Crypto API**:

| SSH | This plan |
|---|---|
| `ssh-keygen` | `crypto.subtle.generateKey({name: 'ECDSA', namedCurve: 'P-256'}, extractable: false, ['sign'])` |
| `~/.ssh/id_ecdsa` (private key file) | Non-extractable `CryptoKey` stored in **IndexedDB**. Script can *use* it but can never read its bytes. |
| `authorized_keys` | `digital_signature_devices` table (public key PEM plus label) |
| `SHA256:abc…` key fingerprint | `key_fingerprint` = SHA-256 of the SPKI DER, shown as `SHA256:<base64>` |
| Server challenge signed per session | Server nonce signed per **signing act** |
| `ssh-keygen -R` / removing a line | "Revoke device" in the UI |

The key is created **per browser profile per device**, so Chrome on a laptop,
Safari on an iPhone, and Firefox on the same laptop are three devices. This
matches SSH, where each user account on each machine usually has its own key.

It needs no new PHP or JS dependencies. The server verifies with
`openssl_verify()`, and `ext-openssl` is already required.

---

## 3 — Flows

### 3.1 Device bootstrap (every page that can sign)

```
browser                                         server
───────                                         ──────
load key from IndexedDB ──(none)──► generateKey (P-256, non-extractable)
                                    store CryptoKeyPair in IndexedDB
export public key (SPKI) ─────────────────────► GET/POST /signature/devices/lookup
                                                 { key_fingerprint } → known? device uuid/label
```

Keep the key and the server's knowledge of it separate. The key can exist
locally before the device is registered, which happens at the first signing
act (3.2 / 3.4).

### 3.2 Creating a signature → registering the device

1. User draws or uploads a signature in `SignaturePad`.
2. On submit, the client asks for a challenge:
   `POST /signature/devices/challenge { purpose: "register_signature", payload_hash }`,
   where `payload_hash` is the SHA-256 of the PNG bytes being submitted.
3. The server creates a nonce (32 random bytes). It caches
   `{user_id, purpose, payload_hash, nonce}` for about 120 s, for one use only.
4. The client signs the canonical string
   `v1|register_signature|<nonce>|<user_id>|<payload_hash>` with the device
   private key and sends it with the form:
   `device_public_key` (SPKI base64), `device_signature` (base64),
   `device_nonce`, `device_label` (a suggestion such as "Chrome on macOS").
5. `SignatureManager::store()` calls `DeviceRegistry::verify(...)`:
   - The nonce exists, is unexpired and unused, and matches user, purpose, and payload.
   - The signature verifies against the submitted public key.
   - If `key_fingerprint` is unknown for this user, **register the device**
     (fire `DeviceRegistered`, and optionally notify the user). If it is
     known and revoked, **reject**.
6. The signature row stores `device_id` for the device it was created on. The
   PNG gets a new `Sig-Device-Key` tEXt chunk (the key fingerprint) inside
   the existing HMAC.

### 3.3 Using a signature on a known device

Signing a document (`SignDocumentAction`, `SigningSessionManager::applySignature`,
`PdfTemplateSigner`) follows the same challenge flow with
`purpose: "sign_document"` and `payload_hash = document_hash`, the hash of
the exact PDF being signed. This binds the device proof to *that document*,
so a captured proof can't be replayed onto another one.

`storeForDocument()` stops copying `$source->machine_fingerprint` and instead
records the **device that performed this act** in `device_id`. The source
signature keeps its own `device_id`, the creation device, so both facts are
kept:

- *Created on:* MacBook Pro — Chrome on macOS
- *Used on:* iPhone 15 — Safari on iOS

### 3.4 Using a signature from a new device (the phone case)

1. The user opens the inbox on a phone and signs a pending request.
2. The phone has no key yet, so it generates one (3.1). The lookup returns
   *unknown*.
3. Before the Sign button does anything, a small confirmation step appears:
   *"This device isn't registered yet. Register **Safari on iPhone** to sign?"*
   The label can be edited.
4. The signing act carries the new public key, and the server registers the
   device and records it on the signature in the same transaction (3.2
   step 5).
5. A **"New signing device added"** notification goes to the user's other
   channels (database notification plus mail if configured), like GitHub's
   "a new SSH key was added" email. This is the main defence against someone
   who has a stolen session registering their own device.

Optional hardening (`devices.new_device_confirmation = true`): a new device
starts in the `pending` state and can sign only after it is approved from an
already-registered device or via an emailed link. The first device a user
registers is auto-approved.

### 3.5 Delegated / auto-affix signing

`storeDelegated()` has no device present, so `device_id = null` and
`source = auto` stay as they are. The UI shows *"Applied automatically
(delegation #…)"* rather than a device. The creation device is still
reachable through the source signature.

---

## 4 — Data model

### 4.1 New table `digital_signature_devices`

Migration `2024_01_01_000011_create_digital_signature_devices_table.php`.
Also add it to the migration order test, following the pattern of the
`caption_position` commit.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `uuid` | uuid unique | exposed to the client, never the numeric id |
| `user_id` | fk users, cascade | |
| `label` | string(120) | user-editable, e.g. "Work laptop" |
| `public_key` | text | PEM (SPKI) |
| `key_fingerprint` | string(64) | SHA-256 hex of the SPKI DER. Unique per `(user_id, key_fingerprint)` |
| `algorithm` | string(16) | `ES256` now, leaves room for `Ed25519` / WebAuthn |
| `device_type` | string(16) | `desktop` / `mobile` / `tablet` / `unknown` |
| `platform` | string(64) nullable | "macOS", "iOS", "Android", "Windows" |
| `browser` | string(64) nullable | "Chrome 131", "Safari" |
| `user_agent` | text nullable | as seen at registration |
| `status` | string(16) | `active` / `pending` / `revoked` |
| `registered_ip` | string(45) nullable | |
| `last_used_ip` | string(45) nullable | |
| `last_used_at` | timestamp nullable | |
| `approved_at` / `revoked_at` | timestamp nullable | |
| timestamps | | |

Index: `(user_id, status)`.

### 4.2 Changes to existing tables

Migration `…_000012_add_device_id_to_signatures_and_audits.php`:

- `digital_signatures.device_id`: nullable fk to `digital_signature_devices`,
  `nullOnDelete`. On a registration row it means *created on*. On a
  document-signing row it means *used on*.
- `digital_signature_audits.device_id`: nullable fk. `SignatureAudit::record()`
  picks it up from the request-scoped `CurrentDevice`, the same way it
  already captures IP and UA.
- Keep `machine_fingerprint`. Existing rows need it and it still feeds
  `Sig-Machine-Hash` for old PNGs. Mark it deprecated in docs.

### 4.3 Models

- `Models\SigningDevice`: `user()`, `signatures()`, `isActive()`,
  `displayName()` (label, falling back to "Browser on Platform"),
  `shortFingerprint()` (`SHA256:ab12…ef`).
- `Signature::device()` belongsTo.
- `HasSignatures` trait: add `signingDevices()` hasMany on the user.

---

## 5 — Server-side components

| Component | Responsibility |
|---|---|
| `Security\DeviceRegistry` | `challenge(user, purpose, payloadHash): Challenge`; `verify(user, purpose, payloadHash, DeviceProof): SigningDevice` (register-or-resolve); `revoke(device)`; `approve(device)` |
| `Security\DeviceProof` (DTO) | public key, signature, nonce, suggested label, built from the request |
| `Security\EcdsaSignature` | converts the raw `r‖s` signature WebCrypto returns (IEEE P1363, 64 bytes for P-256) into the ASN.1 DER that `openssl_verify` expects. **This detail is easy to miss and breaks verification if skipped.** |
| `Support\DeviceDescriptor` | parses UA and `Sec-CH-UA-*` client hints into `device_type` / `platform` / `browser`. A small in-package parser with no dependency is enough for the label. |
| `Support\CurrentDevice` | request-scoped singleton holding the verified device, so audits, events, and `SignatureCaption` can read it |
| `Http\Controllers\DeviceController` | `POST /signature/devices/challenge`, `POST /signature/devices/lookup`, `PATCH /signature/devices/{uuid}` (rename), `DELETE /signature/devices/{uuid}` (revoke). Auth plus throttle middleware, like the existing fingerprint route. |
| `Events\DeviceRegistered`, `DeviceRevoked` | plus `Notifications\NewSigningDeviceNotification` |
| `Exceptions\UnregisteredDeviceException`, `DeviceRevokedException` | caught by the Filament actions and shown as notifications, following the existing `MachineBindingException` handling |

### Challenge storage

Use the Laravel cache (`signature:device-challenge:{nonce}`) with a TTL and
`Cache::pull()` so each nonce is used once. Store no challenges in the
database.

### Canonical signed message

```
v1|<purpose>|<nonce_b64url>|<user_id>|<payload_hash_hex>
```

A version prefix and fixed field order mean no ambiguity. Every field is
server-known, so the client can't smuggle anything in.

### Where verification hooks in

- `SignatureManager::store()`: purpose `register_signature`.
- `SignatureManager::storeForDocument()`: purpose `sign_document`. Takes a
  `?SigningDevice $device` argument, and callers pass the verified device.
- `SigningSessionManager::applySignature()` / `sign()` / `signAt()`: thread
  the device through.
- `EmbedSignatureJob` (queued mode) runs later, so **verify before
  dispatch** and pass `device_id` into the job. Never verify inside the job.

### Replacing the machine lock

With `enforce_machine_lock = true`, the lock becomes: *the signature may only
be used from an active registered device of its owner*. Two policies are
available (see §8):

- `any_registered` (default): any of my active devices may use my signature.
- `creation_device_only`: only the device that drew it. This is the current
  strict behaviour, but now it is enforced with a key.

`DeviceFingerprintController` and the `sig_device_fp` session value stay for
one minor version as a fallback for non-JS / old clients. After that they are
removed.

---

## 6 — Client-side components

New `resources/js/utils/deviceKey.js`, next to `machineFingerprint.js`:

```js
export async function getDeviceKey()        // load from IndexedDB or generate; memoised
export async function getPublicKeySpki()    // base64 SPKI
export async function getKeyFingerprint()   // sha256 hex of SPKI (matches server)
export async function signChallenge(message) // base64 raw r||s
export function suggestLabel()              // "Safari on iPhone" from UA / userAgentData
```

- The IndexedDB database is `kukux-signature` with object store `device-keys`
  and key `default`. `CryptoKeyPair` can be stored directly because it is
  structured-cloneable.
- `generateKey(..., false, ['sign', 'verify'])`: the private key is
  **non-extractable**.
- Call `navigator.storage.persist()` after the first registration to lower
  the chance that the browser evicts the key.
- If Web Crypto or IndexedDB is unavailable (very old browsers, some
  private-browsing modes), return `null`. The server then applies
  `devices.require` (§8): reject, or accept and mark the act *unverified
  device*.

Integration points:

- `alpine/signatureField.js` and `react/SignaturePadIsland.jsx`: fetch the
  challenge and attach the proof on submit.
- `react/PdfSigningIsland.jsx` and the Sign / drag-to-sign actions: same, with
  `purpose: sign_document`. The Livewire action receives the proof in its
  `$data` array.
- A new small `DeviceRegistrationPrompt` component (Alpine plus Blade) for the
  new-device confirmation in 3.4.
- Add a `tests/js/deviceKey.test.mjs` for label parsing and message
  canonicalisation.

---

## 7 — UI: where the device shows up

1. **"My signing devices"**: a new tab or section in the Signatures drawer and
   resource. Each device appears as a card or row with an icon (laptop /
   phone / tablet), label, `SHA256:ab12…` fingerprint, "Registered 3 Sep
   2026", "Last used 2 hours ago", a *This device* badge for the current
   browser, and Rename / Revoke actions. Pending devices get an Approve
   action.
2. **Signature detail (infolist)**: replace the raw *Device Fingerprint* hex
   with **Created on** (device name plus icon), and add a **Usage history**
   table listing each document-signing row with document, date, and **Used
   on** device.
3. **Signatory panel / signed document view**: each signature line shows
   *"Signed by Juan dela Cruz · iPhone (Safari) · 29 Sep 2026 14:02"*.
4. **Audit trail**: the device column comes from `digital_signature_audits.device_id`.
5. **Signing confirmation**: the Sign modal footer shows *"Signing from:
   Work laptop"* so the user sees which device is being recorded before they
   commit.
6. **Optional stamp caption**: add a `{device}` token to `SignatureCaption`,
   off by default, so hosts can print the device on the visible stamp.
7. **Optional PDF metadata**: include `device_key_fingerprint` and
   `device_label` in `pades_info` and the PAdES *Reason* / *Location*
   string, so the device is recorded inside the signed PDF as well as in
   the database.

Both V3 and V4 resource variants need the infolist changes
(`Filament/Resources/V3|V4/SignatureResource.php`).

---

## 8 — Configuration

Add to `config/signature.php`:

```php
'devices' => [
    'enabled' => env('SIGNATURE_DEVICES_ENABLED', true),

    // off      — record the device when available, never block
    // warn     — allow unverified devices, flag the act as unverified
    // enforce  — every create/use must carry a valid device proof
    'require' => env('SIGNATURE_DEVICES_REQUIRE', 'warn'),

    // any_registered | creation_device_only
    'usage_policy' => env('SIGNATURE_DEVICES_USAGE_POLICY', 'any_registered'),

    'new_device_confirmation' => env('SIGNATURE_DEVICES_CONFIRM', false),
    'notify_on_new_device'    => true,
    'max_per_user'            => 10,
    'challenge_ttl'           => 120, // seconds
],
```

The default is `warn` so existing installs keep working after upgrade. Hosts
can move to `enforce` when they are ready.

---

## 9 — Security analysis & limitations

### What this does prove

- The act was performed by a browser holding a specific private key that was
  registered to this user. A copied fingerprint, cookie, or proof can't be
  replayed, because proofs are bound to a single-use nonce plus the document
  hash.
- A stolen PNG, a stolen fingerprint, or a stolen session cookie used from
  another machine shows up as a **new device**, and the owner is notified.
- Revoking a device stops it from signing immediately.

### What it does not prove (state this honestly in the docs)

| Limitation | Mitigation |
|---|---|
| Keys are **software keys in browser storage**, not hardware-attested. | Document it clearly. The WebAuthn tier (§10) is the upgrade path. |
| **XSS on the host app** can *use* the key (not extract it) while the page is open. | Normal CSP hygiene. The notification and audit trail still attribute the act to that device. |
| **Clearing site data / private windows** destroys the key, and the next visit is a new device. | `navigator.storage.persist()`; clear UX ("This looks like a new device"); allow revoking stale devices. |
| **Safari ITP** can evict script-writable storage after 7 days without interaction on the site. | Regular users interact often enough. Installed PWAs are exempt. Document it. |
| A stolen session could register the attacker's own device. | New-device notification, optional `new_device_confirmation`, audit row. |
| A device is a browser profile, not physical hardware. | Label it that way in the UI ("Chrome on this Mac"). |

---

## 10 — Alternatives considered

- **Keep improving the fingerprint.** Rejected. It is data the client
  asserts, not proof, and it stays unstable.
- **Real SSH / uploaded key files.** Rejected. It is unusable on phones and
  for non-technical signers.
- **Per-device X.509 client certificates (mTLS).** Rejected. It needs
  web-server config outside a Laravel package and has poor mobile UX.
- **WebAuthn / passkeys.** Deferred to phase 3 as an *optional stronger tier*.
  It is hardware-backed and gives the nice Face ID / Touch ID UX. Caveats:
  - It needs a PHP WebAuthn library (e.g. `web-auth/webauthn-lib`), because
    CBOR and attestation parsing are too much to hand-roll.
  - **Synced passkeys** (iCloud Keychain, Google Password Manager) move
    between the user's devices, which weakens "which device" rather than
    strengthening it. Only device-bound credentials answer the question this
    feature asks. The `algorithm` column and `DeviceRegistry` interface
    leave room for it.

---

## 11 — Phased delivery

| Phase | Deliverable | Behaviour change |
|---|---|---|
| **1: Record** | Migrations, `SigningDevice` model, `DeviceRegistry`, `EcdsaSignature`, `deviceKey.js`, challenge/lookup routes; proof attached on signature creation and document signing; `device_id` stored on signatures and audits | None blocking (`require = off`). Devices start appearing in the data. |
| **2: Show & manage** | "My signing devices" UI, infolist *Created on / Used on*, usage history, signatory-panel device line, rename/revoke, new-device notification, new-device prompt on phones | Users can see and revoke devices. Default goes to `warn`. |
| **3: Enforce** | `require = enforce`, `usage_policy`, `new_device_confirmation`, machine-lock re-implemented on device keys, legacy fingerprint route deprecated; optional `{device}` caption token and PAdES metadata | Opt-in blocking. |
| **4: WebAuthn tier (optional)** | Device-bound passkeys as an alternative `algorithm` | Opt-in. |
| **Desktop agent** | Hardware-bound keys for the physical machine (Secure Enclave / TPM), see [desktop-agent-plan.md](desktop-agent-plan.md) | Opt-in, highest assurance level. |

Each phase ships on its own and gets its own docs update (`docs/security.md`,
`docs/configuration.md`).

---

## 12 — Testing

**Pest (feature / unit)**

- Generate a P-256 key with `openssl_pkey_new`, sign the canonical message,
  and convert DER to raw to mimic WebCrypto. Round-trip through
  `EcdsaSignature` and `DeviceRegistry::verify`.
- Replaying a nonce fails. An expired nonce fails. A nonce issued for
  purpose A, used for B, fails. A nonce for document X, used on document Y,
  fails.
- Proof signed by key K but submitted with public key K′ fails.
- An unknown key auto-registers a device and fires `DeviceRegistered` plus
  the notification. A revoked key is rejected.
- `storeForDocument` records the *using* device, not the source's.
  `storeDelegated` records `null`.
- `require = off|warn|enforce` behaviour when there is no proof.
- `usage_policy = creation_device_only` rejects another registered device.
- Queued signing: device verified before dispatch, `device_id` passed to the
  job.
- Migration order test updated for the two new migrations.
- V3 and V4 infolists render *Created on / Used on*.

**JS (`tests/js`)**

- `suggestLabel()` against a table of desktop and mobile UA strings.
- Canonical message builder matches the PHP implementation byte for byte.

**Manual**

- Desktop Chrome, Safari, and Firefox, plus iOS Safari and Android Chrome.
  Register, sign, clear site data, then confirm the device shows as new.

---

## 13 — Open questions

1. Should registering the **first** device of a user whose account is older
   than this feature need confirmation, or be auto-approved? (Proposed:
   auto-approve, then notify.)
2. Should revoking a device invalidate **signature images created on it**,
   or only stop future use from it? (Proposed: only future use. The
   signature belongs to the person, not the device.)
3. Should device information appear on the visible stamp by default, or only
   in metadata? (Proposed: metadata only, with the caption token opt-in.)
4. Should there be a cap on devices, and what happens at the cap: block, or
   ask to revoke the oldest?
5. Is cross-device handoff in scope? Example: scanning a QR code on the
   laptop to sign on the phone. (Proposed: out of scope. The phone flow in
   3.4 already covers it.)

---

## 14 — What was built

### Server

| Plan item | Where |
|---|---|
| `digital_signature_devices` table | `database/migrations/2024_01_01_000011_…`. It already includes the desktop agent's columns (`kind`, `protection`, `user_presence`, `attested`, `form_factor`, `model`, `hardware_id_hash`, `agent_version`, `session_public_key`), because the table is new and the repo declares schema where a table is created. |
| `device_id` on signatures and audits | `…000012_add_device_id_to_signatures_and_audits.php`. It's an alter migration because both tables had already shipped, and it's listed in `MigrationOrderTest`. |
| `SigningDevice` model | `src/Models/SigningDevice.php`, plus `Signature::device()`, `deviceSummary()`, `documentUses()` and `documentUseSummaries()` |
| `EcdsaSignature` | `src/Security/EcdsaSignature.php` (raw ↔ DER) |
| Proof verification | `src/Security/DeviceProofVerifier.php`: ES256 raw (browser) or DER (agent), RS256 (Windows Hello), and the canonical message builder |
| `DeviceRegistry` | `src/Security/DeviceRegistry.php`: challenge, attest, `forSigning()` (register-on-first-sign plus policy), `current()`, rename, revoke |
| `DeviceDescriptor` | `src/Support/DeviceDescriptor.php` |
| Endpoints | `src/Http/Controllers/DeviceController.php`: `POST challenge`, `POST attest`, `PATCH {uuid}`, `DELETE {uuid}` under `/signature/devices` |
| Events and notification | `DeviceRegistered`, `DeviceRevoked`, `NewSigningDeviceNotification` (not sent for a user's first device) |
| Hooks | `SignatureManager::store()` records the creation device, `storeForDocument()` the using device, `storeDelegated()` always `null`. `SignatureAudit::record()` stamps the signer's device. |
| Config | `signature.devices`, with `require` defaulting to `off` as phase 1 intends |

### Browser

- `resources/js/utils/deviceKey.js`: non-extractable key in IndexedDB.
- `resources/js/utils/deviceProof.js`: pure helpers, shared with the tests.
- `resources/js/utils/deviceAttestation.js`: the challenge → sign → attest
  loop, which re-attests before expiry and on tab focus.
- The loop is started from `index.js` when the panel renders the
  `kukux-signature-devices` meta tag. `SignaturePlugin` adds that tag through
  a `HEAD_END` render hook, for signed-in users only.

### UI

- The signature view shows **Created on / Signed on** with the device's icon,
  a **Used on** list (document, device, time) for primary signatures, and the
  **Device Key** fingerprint in Security Metadata. This is in both the V3 and
  V4 resources.
- The signatory panel shows *"Signed on Chrome on Mac · Browser key"* under
  each signed slot.

### Tests

- `tests/Feature/SigningDeviceTest.php`: endpoints, replay, wrong key, wrong
  user, revocation, and each policy mode.
- `tests/Unit/DeviceProofVerifierTest.php`: DER/raw, RS256, key rejection,
  and user-agent labels.
- `tests/js/deviceProof.test.mjs` plus `tests/Fixtures/device-proof.json`: a
  proof produced by the real browser code, which the PHP suite verifies.

### Departures from the plan

1. **The proof is per session, not per signing act.** §3.2–3.3 have every
   signing carry its own proof bound to the payload hash. Signing happens on
   many surfaces (Filament actions on V3 and V4, the launcher, the inbox, the
   PDF signer page, JSON endpoints), and threading a proof through each form
   was a lot of fragile plumbing. Instead, the browser proves its key for the
   session (`attestation_ttl`, 15 min by default), and the server records that
   device on every act in the session.
   - Trade-off: someone holding a stolen session cookie can sign as the
     attested device until the proof expires. They still can't attest a
     different key without registering a new device, which notifies the
     owner.
   - Per-act, payload-bound proofs are what the desktop agent's job flow
     provides (desktop-agent-plan §8.3). The challenge API already takes a
     `payloadHash` for that.
2. **There's no separate `CurrentDevice` class.** `DeviceRegistry` is
   request-scoped and holds the resolved device itself.
3. **A revoked key rotates.** When the attest endpoint answers 409, the
   browser discards its key and makes a new one. That becomes a new device,
   and the owner is notified.
4. **Not built yet:**
   - the *My signing devices* management UI (the rename/revoke endpoints
     exist)
   - the new-device confirmation prompt (§3.4) and `new_device_confirmation`
   - the `Sig-Device-Key` PNG chunk (§3.2 step 6)
   - the `{device}` caption token and PAdES metadata (§7.6–7.7)
   - *"Signing from…"* in the Sign modal (§7.5)
   - removing the legacy `DeviceFingerprintController`, which is still
     registered even though nothing reads the session value it writes

