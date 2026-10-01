# Desktop Signing Agent — Requirements & Plan

**Status:** phases 1–2 and the free-build half of phase 5 are built in
`digital-signature-agent`. Phases 3–4 are built in this package; see
[§15 What was built](#15--what-was-built-package-side). Still open:
attestation verification (phase 5), PNPKI and USB tokens (phase 6), and
spike 0b on Windows hardware.
**Companion to:** [device-registration-plan.md](device-registration-plan.md).
That plan registers a *browser* as a device. This plan registers the
*physical machine*.
**Scope:** an Electron desktop app for macOS and Windows. It creates a signing
key inside the machine's security chip, registers the public half with the
digital-signature package, and proves on every signing that this specific
machine was used. The key code is Objective-C on macOS and C++ on Windows.

---

## Table of contents

| # | Section |
|---|---|
| [1](#1--goal-and-non-goals) | Goal and non-goals |
| [2](#2--architecture) | Architecture |
| [3](#3--repository-layout) | Repository layout |
| [4](#4--native-key-module) | Native key module (the core) |
| [5](#5--macos-backend-objective-c) | macOS backend (Objective-C) |
| [6](#6--windows-backend-c) | Windows backend (C++) |
| [7](#7--electron-shell) | Electron shell |
| [8](#8--protocol-pairing-and-signing-jobs) | Protocol: pairing and signing jobs |
| [9](#9--package-server-changes) | Package (server) changes |
| [10](#10--security-model) | Security model |
| [11](#11--build-signing-and-distribution) | Build, code signing, distribution |
| [12](#12--testing) | Testing |
| [13](#13--phased-delivery) | Phased delivery |
| [14](#14--risks-and-open-questions) | Risks and open questions |
| [15](#15--what-was-built-package-side) | What was built (package side) |

---

## 1 — Goal and non-goals

### Goal

> When I register my signature on my MacBook or my Windows PC, *that machine*
> gets a key that can't be copied off it. Whenever my signature is used from
> that machine, the app shows **"Used on: MacBook Pro (Secure Enclave)"**,
> and nobody else can produce that proof.

Why a desktop app, when the browser plan already exists:

| Browser key ([device plan](device-registration-plan.md)) | Desktop agent (this plan) |
|---|---|
| Identifies a browser profile | Identifies the **physical machine** |
| Software key in IndexedDB | Key in **Secure Enclave / TPM**, which can't be exported |
| Lost when site data is cleared | Survives browser changes and data clears |
| Can't tell a laptop from a desktop | Reads the machine model and battery, so it can |
| No OS-enforced user presence | **Touch ID / Windows Hello enforced by the key itself** |
| Can't reach USB tokens | Can reach PKCS#11 tokens (later phase) |

### Non-goals (for this plan)

- **Phones.** Electron doesn't run on iOS or Android. A mobile app can reuse
  §8's protocol unchanged later.
- **Linux.** The key module interface allows adding it (TPM via `tpm2-tss`),
  but it's not in scope.
- **Signing PDFs in the agent.** In this plan the agent signs *proofs*
  (registration and usage receipts). Signing PDFs with a PNPKI `.p12` or a USB
  token is a later phase (§13, phase 6), and it plugs into the same job
  protocol.

---

## 2 — Architecture

```
┌──────────────── Browser ────────────────┐      ┌──────────── Laravel + digital-signature ────────────┐
│ Filament app                            │      │                                                     │
│  • "Pair desktop agent" (shows code/QR) │─────►│ /signature/agent/pairings   (start, confirm)        │
│  • "Sign with this computer"            │─────►│ /signature/agent/jobs       (create, status)        │
│  • waits: Livewire poll / Reverb event  │◄─────│ verifies proofs, writes device_id + audit           │
└──────────────┬──────────────────────────┘      └──────────────────▲──────────────────────────────────┘
               │ opens kukuxsign://job/<uuid>?t=<one-time token>      │ HTTPS only, pinned origin,
               ▼                                                      │ agent token + device proof
┌──────────────────────────── Desktop agent (Electron) ───────────────┴──────────────────────────────────┐
│ Main process (TypeScript)                                   Renderer (React, local files only)         │
│  • protocol handler, single instance, tray                  • pairing screen                           │
│  • API client (pinned origin)                               • "Confirm signing" screen                 │
│  • job runner, updater                                      • devices / status                         │
│           │  in-process call (N-API)                            ▲ contextBridge: narrow, typed API      │
│           ▼                                                                                            │
│ ┌──────────────── native addon: keystore.node ──────────────┐                                          │
│ │ addon.cc (N-API binding, async workers)                    │                                          │
│ │   ├── mac/SecureEnclaveKeyStore.mm   (Objective-C)         │                                          │
│ │   └── win/HelloKeyStore.cpp, PcpKeyStore.cpp   (C++)       │                                          │
│ └───────────────────────────────────────────────────────────┘                                          │
└────────────────────────────────────────────────────────────────────────────────────────────────────────┘
                     Secure Enclave (Mac)                  TPM 2.0 + Windows Hello (Windows)
```

Design decisions:

1. **The browser never talks to the agent directly.** A `kukuxsign://` link
   wakes the agent, and everything else goes over HTTPS through the server.
   That avoids localhost servers (certificates, local-network permission
   prompts, port probing) and browser extensions.
2. **The link parameters are never trusted.** They carry only an opaque job
   id and a one-time token. The agent fetches the job itself from the origin
   it was paired with, so a malicious website can open a link but can't
   inject content into it.
3. **Keys live in the native module, crypto stays out of JavaScript.** JS
   holds key *ids* and receives signatures, never key material.
4. **The OS enforces user presence.** The key's own access policy requires
   Touch ID or Windows Hello. An Electron-level "are you sure?" dialog is
   UX, not security.

---

## 3 — Repository layout

A separate repository, `kukux/digital-signature-agent`. It ships on its own
release cycle and is signed separately from the PHP package.

```
digital-signature-agent/
├── package.json                 # electron, electron-builder, @electron/rebuild
├── electron-builder.yml         # targets, protocols, signing, notarization
├── build/
│   ├── entitlements.mac.plist
│   └── embedded.provisionprofile  # needed for Secure Enclave keychain access (see §5.4)
├── src/
│   ├── main/
│   │   ├── index.ts             # app lifecycle, single-instance lock
│   │   ├── protocol.ts          # kukuxsign:// parsing + validation
│   │   ├── pairing.ts
│   │   ├── jobs.ts
│   │   ├── api.ts               # HTTPS client, pinned origin, token, proofs
│   │   ├── store.ts             # paired servers, tokens (safeStorage)
│   │   ├── canonical.ts         # proof message builder (matches PHP)
│   │   └── updater.ts
│   ├── preload/index.ts         # contextBridge API
│   └── renderer/                # React UI
├── native/
│   ├── binding.gyp
│   ├── include/keystore.h       # platform-neutral C++ interface
│   ├── src/addon.cc             # N-API binding (node-addon-api)
│   ├── src/common/spki.cc       # SPKI encoding helpers
│   ├── src/mac/SecureEnclaveKeyStore.mm
│   ├── src/mac/DeviceInfo.mm
│   ├── src/win/HelloKeyStore.cpp
│   ├── src/win/PcpKeyStore.cpp
│   ├── src/win/DeviceInfo.cpp
│   └── test/                    # native tests
└── test/                        # TS unit + integration tests
```

The macOS files are `.mm` (Objective-C++) so Objective-C code can implement
the shared C++ interface in `keystore.h`. The Security.framework and
LocalAuthentication code in them is plain Objective-C.

---

## 4 — Native key module

### 4.1 Platform-neutral interface (`keystore.h`)

```cpp
enum class Protection { SecureEnclave, Tpm, Software };
enum class Algorithm  { ES256, RS256 };

struct KeyInfo {
    std::string keyId;             // our label, e.g. "ds.<serverHash>.identity"
    Algorithm   algorithm;
    std::vector<uint8_t> spki;     // SubjectPublicKeyInfo DER
    Protection  protection;
    bool        userPresence;      // OS-enforced Touch ID / Hello on use
};

struct CreateOptions {
    std::string keyId;
    bool requireUserPresence;      // identity key: true; session key: false
};

class KeyStore {
public:
    virtual Capabilities capabilities() = 0;
    virtual KeyInfo create(const CreateOptions&) = 0;
    virtual std::optional<KeyInfo> find(const std::string& keyId) = 0;
    virtual std::vector<uint8_t> sign(const std::string& keyId,
                                      const std::vector<uint8_t>& message,
                                      const std::string& reason,      // shown in the OS prompt
                                      void* parentWindow) = 0;        // HWND on Windows
    virtual std::optional<Attestation> attest(const std::string& keyId) = 0;
    virtual void remove(const std::string& keyId) = 0;
};
```

`sign()` takes the **message**, not a digest, and hashes it with SHA-256
internally. That way JS can't ask the key to sign an arbitrary pre-computed
hash.

### 4.2 JS surface (TypeScript types exported by the addon)

```ts
export function capabilities(): Promise<{ hardware: boolean; userPresence: boolean; attestation: boolean }>;
export function createKey(o: { keyId: string; requireUserPresence: boolean }): Promise<KeyInfo>;
export function findKey(keyId: string): Promise<KeyInfo | null>;
export function sign(keyId: string, message: Buffer, reason: string, parentWindow?: Buffer): Promise<Buffer>;
export function attest(keyId: string): Promise<{ format: string; statement: Buffer; chain: Buffer[] } | null>;
export function deleteKey(keyId: string): Promise<void>;
export function deviceInfo(): Promise<{
  platform: 'macos' | 'windows'; osVersion: string; model: string;
  formFactor: 'laptop' | 'desktop' | 'unknown'; hostname: string; hardwareIdHash: string;
}>;
```

Every call runs on an `Napi::AsyncWorker` and returns a Promise. `sign()`
blocks while the Touch ID or Hello prompt is up, and it must not freeze the
Electron main loop.

### 4.3 Two keys per paired server

| Key | User presence | Used for |
|---|---|---|
| **Identity key** (`…identity`) | **Required** | Registration proof, signing receipts: the acts that must mean "the owner was here, on this machine" |
| **Session key** (`…session`) | Not required | Signing background API requests (DPoP-style proof on each request), so polling and job fetching don't prompt for Touch ID every few seconds |

Both are hardware-backed where available, so a stolen agent token without
the session key is useless.

---

## 5 — macOS backend (Objective-C)

### 5.1 Key creation

```objc
SecAccessControlRef acl = SecAccessControlCreateWithFlags(
    kCFAllocatorDefault,
    kSecAttrAccessibleWhenUnlockedThisDeviceOnly,           // never syncs, never in backups
    kSecAccessControlPrivateKeyUsage | kSecAccessControlUserPresence,  // identity key
    &error);

NSDictionary *attrs = @{
    (id)kSecAttrKeyType:        (id)kSecAttrKeyTypeECSECPrimeRandom,
    (id)kSecAttrKeySizeInBits:  @256,
    (id)kSecAttrTokenID:        (id)kSecAttrTokenIDSecureEnclave,
    (id)kSecUseDataProtectionKeychain: @YES,
    (id)kSecPrivateKeyAttrs: @{
        (id)kSecAttrIsPermanent:    @YES,
        (id)kSecAttrApplicationTag: [keyId dataUsingEncoding:NSUTF8StringEncoding],
        (id)kSecAttrAccessControl:  (__bridge id)acl,
    },
};
SecKeyRef priv = SecKeyCreateRandomKey((__bridge CFDictionaryRef)attrs, &error);
```

- The session key uses `kSecAccessControlPrivateKeyUsage` only.
- `kSecAccessControlUserPresence` accepts Touch ID, Apple Watch, *or* the
  login password. Use `kSecAccessControlBiometryCurrentSet` instead if policy
  should demand biometrics and invalidate the key when fingerprints change.
  Make it configurable, with UserPresence as the default.

### 5.2 Signing

- Algorithm `kSecKeyAlgorithmECDSASignatureMessageX962SHA256`. The output is
  already **DER**, so PHP's `openssl_verify` accepts it as-is. (The browser
  plan's raw `r‖s` conversion doesn't apply here.)
- Pass an `LAContext` via `kSecUseAuthenticationContext`, with
  `localizedReason` set to the job text, e.g. *"Sign 'Accomplishment
  Report – Sept' as Juan dela Cruz"*.

### 5.3 Public key export

`SecKeyCopyExternalRepresentation` returns the raw **X9.63 point**
(`04‖X‖Y`, 65 bytes), not SPKI. `common/spki.cc` wraps it in the fixed P-256
SPKI header (`id-ecPublicKey` plus `prime256v1`) before it leaves the addon.

### 5.4 Device info

- `sysctlbyname("hw.model")` gives a model identifier such as `Mac15,3`.
- `IOPSCopyPowerSourcesInfo` reports whether there's an internal battery,
  which gives `formFactor = laptop` or `desktop`.
- `IOPlatformUUID` (IOKit) is **hashed with a per-server salt** before it's
  sent (§10.4).

### 5.5 Fallbacks and constraints

| Situation | Behaviour |
|---|---|
| Apple silicon or Intel with T2 | Secure Enclave. `protection = secure_enclave`. |
| Intel without T2, or a VM | Non-exportable software keychain key. `protection = software`, shown as a lower assurance level in the UI. |
| No Touch ID | `UserPresence` falls back to the login password, which is still OS-enforced. |
| **Entitlements** | Secure Enclave keys need the data-protection keychain, which needs `keychain-access-groups` (plus team and app identifier) and an **embedded provisioning profile**, even for Developer ID distribution. This is phase 0's first spike. |
| Attestation | No general third-party attestation for Secure Enclave keys in macOS apps. Treat Mac keys as *hardware-protected, not attested*. |

---

## 6 — Windows backend (C++)

Two stores, chosen at runtime.

### 6.1 Primary: Windows Hello (`HelloKeyStore.cpp`)

C++/WinRT, `Windows.Security.Credentials.KeyCredentialManager`:

- `IsSupportedAsync()`: Hello is set up on this machine.
- `RequestCreateAsync(keyId, KeyCredentialCreationOption::FailIfExists)`
  creates a **TPM-backed key when a TPM exists**. It's RSA-2048, so
  `algorithm = RS256`.
- `RetrievePublicKey(CryptographicPublicKeyBlobType::X509SubjectPublicKeyInfo)`
  gives **SPKI directly**.
- `RequestSignAsync(buffer)` shows the Hello prompt (face, fingerprint or
  PIN), enforced by the OS. The result is RSA PKCS#1 v1.5 over SHA-256, which
  `openssl_verify(..., OPENSSL_ALGO_SHA256)` accepts.
- `GetAttestationAsync()` returns a TPM attestation statement plus a
  certificate chain, so Windows keys can be **attested** (§8.2).
- **Prompt parenting:** in a Win32 or Electron app the Hello dialog has to be
  parented to the app window, or it can appear behind it. Pass
  `BrowserWindow.getNativeWindowHandle()` into `sign()` and use the
  window-handle interop API. This is phase 0's second spike.

### 6.2 Fallback and session key: TPM via CNG (`PcpKeyStore.cpp`)

- `NCryptOpenStorageProvider(MS_PLATFORM_CRYPTO_PROVIDER)`, then
  `NCryptCreatePersistedKey(BCRYPT_ECDSA_P256_ALGORITHM, keyId)`, then
  `NCryptFinalizeKey`. `NCRYPT_ALLOW_EXPORT_FLAG` is never set.
- Used for the **session key** (no prompt), and for the identity key when
  Hello isn't set up. In that case `userPresence = false`, and the UI and
  policy treat it as the lower level.
- `NCryptSignHash` returns raw `r‖s`. Convert it to DER in the addon so the
  server receives the same format from every platform.
- If the PCP provider is missing (no TPM), fall back to
  `MS_KEY_STORAGE_PROVIDER` with `protection = software`.

### 6.3 Device info

- `GetSystemPowerStatus` (`BatteryFlag != 128`) gives `formFactor`.
- `model` comes from the registry under
  `HKLM\HARDWARE\DESCRIPTION\System\BIOS` (`SystemManufacturer`,
  `SystemProductName`). That avoids WMI boilerplate.
- The SMBIOS system UUID (via `GetSystemFirmwareTable('RSMB')`) is salted
  and hashed before sending.

---

## 7 — Electron shell

### 7.1 Process model and hardening

- The native addon loads **only in the main process**.
- The renderer is `sandbox: true`, `contextIsolation: true`,
  `nodeIntegration: false`. It loads only bundled `file://` / `app://`
  content and **never the remote Filament site**.
- The preload exposes a narrow API: `getStatus()`, `startPairing(code)`,
  `approveJob(id)`, `rejectJob(id)`, `unpair(server)`. There's no generic
  `sign(bytes)`.
- Strict CSP, `will-navigate` / `setWindowOpenHandler` deny all, and
  `session.setPermissionRequestHandler` denies everything.
- Enable the Electron fuses `RunAsNode=false`,
  `EnableNodeCliInspectArguments=false`, `EnableEmbeddedAsarIntegrityValidation=true`
  and `OnlyLoadAppFromAsar=true`.

### 7.2 Protocol handler

- `app.setAsDefaultProtocolClient('kukuxsign')`, and `protocols` in
  electron-builder so the installer registers it.
- `app.requestSingleInstanceLock()`. Links arrive via `open-url` on macOS
  and `second-instance` argv on Windows.
- The only accepted link shape is
  `kukuxsign://job/<uuid>?t=<43-char base64url>&s=<serverId>`. Anything else
  is dropped. `serverId` must match a paired server, and the agent then
  fetches from **that server's stored origin**.

### 7.3 Storage

- Paired servers (origin, server id, device uuid, key ids) go in a JSON file
  in `app.getPath('userData')`.
- Agent tokens are encrypted with `safeStorage` (Keychain / DPAPI). It's
  fine for bearer tokens, **never** for signing keys.

### 7.4 UX

- A tray or menu-bar app, started at login (optional, off by default).
- A pairing window with a code input that also accepts a pasted URL.
- A **confirm window** for each job. It shows the server's name and origin,
  the document title, the signer, and the purpose. The user clicks Approve,
  then the OS prompt appears.
- A status view: paired apps, this device's label and protection level,
  and an Unpair button.

---

## 8 — Protocol: pairing and signing jobs

The proof message format is **identical to the browser plan**, so the server
has one verifier:

```
v1|<purpose>|<nonce_b64url>|<user_id>|<payload_hash_hex>
```

Purposes added by this plan: `register_agent`, `sign_receipt`.

### 8.1 Pairing (device-code style)

```
Web (logged in)                         Server                                  Agent
───────────────                         ──────                                  ─────
"Pair desktop agent" ───────────► POST /signature/agent/pairings
                                  → user_code "K7QM-2XPD", pairing uuid,
                                    nonce (TTL 10 min)
show code + QR (kukuxsign://pair?…) ─────────────────────────────────────────► user enters code / opens link
                                                                               createKey(identity, presence)
                                                                               createKey(session)
                                  ◄──── POST /signature/agent/pairings/claim
                                        { user_code, identity_spki, session_spki,
                                          algorithm, attestation?, device_info,
                                          proof = sign(identity, "v1|register_agent|nonce|uid|sha256(spki)") }
                                  verify proof → status = awaiting_confirmation
web shows "Pair MacBook Pro — macOS 15, Secure Enclave?" [Confirm]
                          ───────► POST /signature/agent/pairings/{uuid}/confirm
                                  device created (kind = agent), agent token issued
                                  ◄──── agent polls claim result → gets device uuid + token
```

- **Two confirmations:** the code proves the agent is next to the logged-in
  user, and the web Confirm proves the logged-in user wants that machine.
  This prevents someone who has a stolen session from silently pairing their
  own machine, and the "new signing device" notification from the browser
  plan fires either way.
- `user_code` is 8 characters from an unambiguous alphabet, single-use,
  rate-limited, and hashed at rest.

### 8.2 Attestation handling

When the agent sends an attestation (Windows Hello / TPM), the server
verifies the chain against the bundled Microsoft TPM roots and sets
`attested = true`. If it doesn't verify, the device is still registered but
`attested = false`. Attestation raises the assurance level; it isn't
required for registration.

### 8.3 Signing job

```
1. Web:    POST /signature/agent/jobs { purpose: sign_receipt, signable, document_hash }
           → { job uuid, link token }       (the server stores sha256(link token), TTL 5 min)
2. Web:    location = kukuxsign://job/<uuid>?t=<link token>&s=<serverId>
           and starts waiting (Livewire poll every 2 s or a Reverb `AgentJobUpdated` event)
3. Agent:  GET  /signature/agent/jobs/<uuid>?t=…    (agent token + session-key request proof)
           → { document title, signer, purpose, nonce, payload_hash }
4. Agent:  confirm window → user approves → sign(identity, canonical message)   [Touch ID / Hello]
5. Agent:  POST /signature/agent/jobs/<uuid>/complete { proof }
6. Server: verify → job completed → the Signature row gets device_id = this agent device
           → audit row → web sees completion and continues the normal signing flow
```

- The job is **bound to the device**. Only an agent device of the job's
  user can claim it, and each link token works only once.
- Reject sends `POST …/reject`, and the web shows *"Declined on your
  computer"*.
- If the agent isn't installed, the link does nothing. After about 3 s
  without a claim, the web shows *"Don't have the agent? Download · Sign in
  the browser instead"*.

### 8.4 Agent request authentication

Every agent API call sends:

- `Authorization: Bearer <agent token>`, where the token is hashed at rest
  and scoped to one device
- `X-Agent-Proof: <sign(session key, "v1|request|<nonce>|<uid>|sha256(method|path|body|timestamp)")>`

The server rejects requests with a stale timestamp (±60 s) or a reused
nonce. A leaked token alone can't be used from another machine.

---

## 9 — Package (server) changes

This builds on the `digital_signature_devices` table from the
[device plan](device-registration-plan.md#4--data-model).

### 9.1 Migrations

**Already built** (see
[device-registration-plan §14](device-registration-plan.md#14--what-was-built)):
`digital_signature_devices` (`000011`) already has the agent columns: `kind`,
`protection`, `user_presence`, `attested`, `form_factor`, `model`,
`hardware_id_hash` (indexed), `agent_version` and `session_public_key`. So
there is no agent-columns migration.

Still to build, numbered after `000012`:

- `…000013_create_digital_signature_agent_pairings_table`
  - `uuid`, `user_id`, `user_code_hash`, `nonce`, `status`, `claimed_payload`
    (json), `device_id`, `expires_at`
- `…000014_create_digital_signature_agent_tokens_table`
  - `device_id`, `token_hash` (unique), `last_used_at`, `revoked_at`
- `…000015_create_digital_signature_agent_jobs_table`
  - `uuid`, `user_id`, `device_id` (nullable until claimed), `purpose`
  - `signable` morph, `payload_hash`, `nonce`, `link_token_hash`
  - `status` (`pending` | `claimed` | `completed` | `rejected` | `expired`)
  - `result` (json), `expires_at`

### 9.2 Code

| Component | Responsibility |
|---|---|
| `Security\DeviceProofVerifier` | **Built.** Verifies ES256 (raw or DER) and RS256, and builds the canonical message. The agent sends `signature_format: der`. |
| `Security\TpmAttestationVerifier` | Windows Hello attestation chain check (phase 5) |
| `Services\AgentPairingService` | start / claim / confirm / expire |
| `Services\AgentJobService` | create / fetch / complete / reject / expire, plus the `AgentJobUpdated` event |
| `Http\Middleware\AuthenticateAgent` | Bearer token plus `X-Agent-Proof` check, and binds `CurrentDevice` |
| `Http\Controllers\Agent\*` | Routes under `/signature/agent/*`, throttled |
| Scheduled prune | Expire pairings and jobs, and prune old job rows |

### 9.3 Filament UI

- **My signing devices:** a *Pair desktop agent* action (code plus QR). Agent
  devices get a computer icon, a protection badge (*Secure Enclave · Touch
  ID*, *TPM · Windows Hello · Attested*), the form factor, the model, and the
  agent version.
- **Sign modal:** when the user has an active agent device, show *Sign with
  this computer* as the primary button and *Sign in browser* as the
  secondary one. A waiting state reads *"Approve on your MacBook Pro…"*.
- **Signature detail / signatory panel:** *Used on: MacBook Pro (Secure
  Enclave)*, which reuses the device plan's display.

### 9.4 Config (added under `signature.devices`)

```php
'agent' => [
    'enabled'           => env('SIGNATURE_AGENT_ENABLED', false),
    'scheme'            => 'kukuxsign',
    'download_url'      => env('SIGNATURE_AGENT_DOWNLOAD_URL'),
    'min_version'       => '1.0.0',   // older agents are refused and asked to update
    'require_presence'  => true,      // reject identity keys without OS-enforced presence
    'require_attestation' => false,
    'job_ttl'           => 300,
    'pairing_ttl'       => 600,
],
```

---

## 10 — Security model

### 10.1 What an attacker can't do

| Attack | Why it fails |
|---|---|
| Copy the key to another machine | Secure Enclave / TPM keys can't be exported |
| Sign without the owner present | The key's ACL demands Touch ID or Hello, enforced by the OS, not the app |
| Malicious site opens `kukuxsign://` | The link has only a job id and one-time token. The agent fetches from its pinned origin and shows the real job. |
| Replay a proof | Single-use nonce, job bound to user, device and payload hash |
| Steal the agent token | Useless without the session key (`X-Agent-Proof`) |
| Pair an attacker's machine with a stolen session | Code on the agent, plus web confirmation, plus new-device notification |
| Swap the agent binary | Code signing, notarization, signed auto-updates, ASAR integrity fuse |

### 10.2 What it doesn't protect against (document honestly)

- **Malware running as the user** can open a real job and wait for the user
  to approve something else. Mitigation: the confirm window and OS prompt
  both show the job's document title.
- **A compromised server** can issue jobs. The agent trusts its paired
  server by design.
- **Mac keys aren't attested.** The server takes the agent's word that the
  key is in the Secure Enclave. Windows Hello keys can be attested.

### 10.3 PNPKI alignment

This follows the Subscriber Agreement principles already adopted: sole use
(4.0c/d), because every signature needs OS-enforced presence, and key
confidentiality (4.0e), because keys never leave the device. It also lays
the groundwork for phase 6, where the PNPKI `.p12` is imported
**non-exportable** into the Keychain or Windows certificate store and used
through the same job protocol.

### 10.4 Privacy (Data Privacy Act of 2012)

- **Hardware UUIDs are never sent in the clear.** The agent sends
  `sha256(serverSalt ‖ uuid)`. It only helps detect a re-registration ("same
  machine as your old *Work laptop*"), and it can't be linked across
  servers.
- The hostname is sent only as the default label suggestion, and the user
  can edit it.
- There's no telemetry.

---

## 11 — Build, code signing, distribution

| | macOS | Windows |
|---|---|---|
| Arch | `arm64` + `x64` (universal or separate) | `x64` (+ `arm64` later) |
| Minimum OS | macOS 12+ | Windows 10 22H2+ / 11 |
| Native build | `@electron/rebuild` against the Electron headers, Xcode toolchain | MSVC v143, C++/WinRT, Windows SDK |
| Code signing | Developer ID Application, hardened runtime, **provisioning profile** for keychain entitlements | Authenticode (Azure Trusted Signing or EV certificate) |
| Notarization | `notarytool` via electron-builder | n/a (SmartScreen reputation via the signed publisher) |
| Package | `.dmg` + `.zip` (for updates) | NSIS `.exe` (per-user install, no admin) |
| Updates | `electron-updater`, signed feed, verify signature before install | same |

Build in CI on GitHub Actions `macos-latest` and `windows-latest`, with signing
secrets in protected environments. Publish releases to a URL the host app
configures as `SIGNATURE_AGENT_DOWNLOAD_URL`.

---

## 12 — Testing

**Native (per platform, in CI)**

- The key store is injectable. CI runners have no Secure Enclave, and no TPM
  or Hello, so CI runs against the software backends: the software keychain
  and `MS_KEY_STORAGE_PROVIDER`.
- Tests: create, find, sign, verify with OpenSSL, delete. Check that the
  SPKI encoding and DER conversion produce output `openssl` accepts. Check
  that exporting the private key fails.

**Cross-language contract**

- A shared test-vector file (`canonical-vectors.json`) is consumed by both
  the agent's `canonical.ts` and the package's Pest tests, so both sides
  build byte-identical messages.
- A package Pest test verifies ES256-DER and RS256 proofs produced by fixed
  test keys.

**Package (Pest)**

- Pairing: code expiry, reuse, wrong user, confirm required, token issued
  once.
- Jobs: link-token single use, wrong device, expired, rejected, the
  completion that sets `device_id`.
- `AuthenticateAgent`: missing, stale or replayed `X-Agent-Proof`.
- `min_version` refusal.

**Manual hardware matrix (before each release)**

| Machine | Expect |
|---|---|
| Apple silicon MacBook (Touch ID) | `secure_enclave`, presence, laptop |
| Mac mini / iMac (no Touch ID) | `secure_enclave`, password prompt, desktop |
| Intel Mac without T2 | `software`, lower assurance level |
| Windows 11 laptop, TPM + Hello | `tpm`, presence, attested |
| Windows 10 desktop, TPM, no Hello | `tpm`, no presence, blocked if `require_presence` |
| Windows VM without TPM | `software` |

---

## 13 — Phased delivery

| Phase | Deliverable | Exit criterion |
|---|---|---|
| **0: Spikes** | (a) Secure Enclave key created **from a signed, notarized Electron build** with the needed entitlements. (b) Windows Hello prompt correctly parented to an Electron window. | Both work on real hardware. If (a) fails, reassess before building further. |
| **1: Native module** | `keystore.node` with the §4 API, Mac and Windows backends, software fallbacks, CI tests | Test vectors pass on both OSes |
| **2: Electron shell** | Tray app, protocol handler, pairing UI, confirm window, hardened config, token storage | Pairs against a local test server |
| **3: Package: pairing** | Migrations 13–14, `AgentPairingService`, `AuthenticateAgent`, *Pair desktop agent* UI | A device appears in *My signing devices* with its protection badge |
| **4: Package: jobs and receipts** | Migration 15, `AgentJobService`, *Sign with this computer*, `device_id` on signatures | *"Used on: MacBook Pro (Secure Enclave)"* shows after a real signing |
| **5: Distribution and attestation** | Signing, notarization, auto-update, Windows attestation verification, `min_version` | A signed installer on a clean machine updates itself |
| **6: PNPKI and tokens** (later) | Import `.p12` non-exportable into Keychain / Windows cert store, PKCS#11 USB tokens, PDF signing through the prepare / external-sign / embed pipeline | A PNPKI-signed PDF validates in Adobe |

Phases 1–2 (agent repo) and 3 (package) can run in parallel once §8's
protocol and the test vectors are fixed.

---

## 14 — Risks and open questions

**Risks**

1. **Mac entitlements** (§5.5). Secure Enclave access from a Developer
   ID–distributed Electron app needs a provisioning profile and the right
   keychain access group. This is the most likely blocker, which is why
   it's spike 0a.
2. **Windows Hello from Win32** (§6.1). Getting the prompt to appear in
   front of the window, and the async WinRT calls from an N-API worker
   thread, are both fiddly. That's spike 0b.
3. **Native module ABI.** Each Electron upgrade needs a rebuild. Pin the
   Electron version and rebuild in CI.
4. **Adoption.** Users must install software. Keep browser signing as the
   default fallback.

**Open questions**

1. Should one agent install be able to pair with **several** Filament apps?
   (The design allows it: keys are per server.)
2. Is the agent **required** for some documents? For example, a per-template
   `require_device_level: agent`.
3. Should `BiometryCurrentSet` (biometrics only) be offered instead of
   `UserPresence` (biometrics or password) on Mac?
4. Should the Windows ARM64 and macOS universal builds ship at 1.0 or later?
5. What should the product and scheme names be (`kukuxsign`)? The scheme is
   hard to change after release.

---

## 15 — What was built (package side)

The package implements the agent's wire contract,
[`digital-signature-agent/docs/protocol.md`](../../digital-signature-agent/docs/protocol.md).
That document wins wherever it is more specific than §8.

### Checked against the real agent

- `tests/Fixtures/agent-canonical-vectors.json` is a copy of the agent's
  `test/fixtures/canonical-vectors.json`. `AgentProtocolTest` asserts that
  the package builds the same messages, request hashes and key bindings.
- The agent's own `Agent` class (pairing, jobs, API client, store) was also
  run against a live Laravel app with this package installed, over HTTP.
  These all passed for both ES256 (Secure Enclave) and RS256 (Windows Hello)
  identity keys:
  - pair
  - approve a signing, which then records the computer as the device
  - decline a signing
  - reuse a link (refused, since links are single use)
  - unpair

### Where things landed

| Plan item | Where |
|---|---|
| Migrations | `000013` pairings, `000014` tokens, `000015` jobs. The agent columns were already in `000011`. |
| Pairing: lookup / claim / poll | `Agent\AgentPairingService`, `Http\Controllers\Agent\AgentController` |
| Pairing: web start / confirm / reject | `AgentPairingService`, driven from the `SigningDevices` Livewire component |
| `AuthenticateAgent` | `Http\Middleware\AuthenticateAgent`: bearer token, ±60 s timestamp, per-device nonce cache, and the session-key proof over `METHOD\|path+query\|raw body\|timestamp` |
| `min_version` → 426 | `Http\Middleware\EnsureAgentVersion`, which also returns 404 `agent_disabled` when the feature is off |
| `GET /status`, `DELETE /device` | `AgentController` |
| Jobs: claim / complete / reject | `Agent\AgentJobService` |
| Protocol error shape | `Agent\AgentApiException::render()` |
| Server id / salt / origin | `Agent\AgentServer`: derived from `APP_KEY` unless `SIGNATURE_AGENT_SERVER_ID` / `_SALT` are set |
| *My signing devices* UI | `Filament\Livewire\SigningDevices`. It's in the launcher's **Devices** tab and the Signatures page's **Signing devices** slide-over, with Pair, confirm/reject, rename and revoke. |
| *Sign with this computer* | See *Approval as a step-up* below |
| Config | `signature.devices.agent`. It's off by default; turn it on with `SIGNATURE_AGENT_ENABLED=true`. |

### Departures from §8–9

1. **Approval is a server-side step-up, not a web `POST /jobs`.** When a user
   with a paired computer signs a document, `DeviceRegistry::forSigning()`
   creates the job itself, with the hash of the exact PDF being signed, and
   throws `AgentApprovalRequiredException`. Each surface answers in its own
   way:
   - The JSON endpoints (the launcher's viewer and the template signer)
     return **428** with an `agent_approval` payload.
   - The Livewire surfaces (`SignDocumentAction`, the inbox) dispatch
     `kukux-signature:agent-approval`.

   `resources/js/utils/agentApproval.js` then:
   - opens `kukuxsign://job/…`
   - shows an overlay that polls `/signature/agent-web/jobs/{uuid}`
   - retries the original request once the job completes

   The retry finds the completed job by document hash, records the agent as
   `device_id`, and spends the job (`consumed_at`, `signature_id`). No
   signing surface needed its own server plumbing, and nothing can be signed
   with a stale approval: the hash must match, and each approval is spent
   once.
2. **One approval covers one signing request.** Signing several slots of
   one document is one act of signing, but every slot changes the
   document's hash, so the approved agent device stays in effect for the
   rest of that HTTP request.
3. **`signature.devices.agent.approval`**:
   - `prefer` (the default) asks the agent, and offers *Sign in the browser
     instead*. That suppresses the agent for `skip_ttl` seconds.
   - `enforce` requires a paired computer.
   - `off` never asks.
4. **Web endpoints are under `/signature/agent-web`.** The `/signature/agent`
   prefix carries the agent middleware (version gate, no `web` group and
   therefore no CSRF), and the browser's calls need the session instead.
5. **No QR code on the pairing screen.** The agent runs on the same computer
   as the browser, so the code plus an *Open in Kukux Sign Agent* link
   (`kukuxsign://pair?o=…&c=…`) covers it.

### Not built

- **Attestation verification (phase 5).** The agent's attestation is stored
  in the pairing claim, and every device is saved with `attested = false`.
  Verifying a Windows Hello TPM statement means parsing the TPM's attest and
  public structures and checking the AIK chain against Microsoft's roots,
  and that needs real attestation samples from Windows hardware to test
  against.
- **PNPKI `.p12` and USB tokens (phase 6).** This needs three pieces:
  - On the agent: non-exportable import into the Keychain / CNG, and
    PKCS#11 support.
  - In the package: an external-signature PDF pipeline. The server builds
    the CMS signed attributes, the agent signs them, and the server inserts
    the signature into a byte-range placeholder. TCPDF and FPDI can't do
    this, because they sign with the private key in-process.
  - DICT's GovCA chain, for validation.
- **Spike 0b** (Windows Hello prompt parenting) needs Windows hardware.

### Deploying

- **The agent only talks to HTTPS origins** (localhost is allowed in its dev
  builds). Serve the test app over HTTPS, e.g. `herd secure` or
  `valet secure`.
- **Behind a reverse proxy**, configure `TrustProxies`. The lookup response's
  `server.origin` must equal the origin the agent called, or the agent
  aborts pairing.

