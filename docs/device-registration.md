# Registered Signing Devices

Every browser a user signs from gets its own key pair, a bit like an SSH key. The browser proves it holds the private key, and every signature records which device it was created or used on.

Use it when you need to answer "which device signed this?" with something stronger than a browser fingerprint. A fingerprint is a value the browser asserts and anyone can copy. A device key is something the browser has to prove it holds.

---

## How it works

The browser generates a P-256 key with Web Crypto, marked `extractable: false`, and stores it in IndexedDB. Script on your origin can *use* the key but can never read its bytes, so it can't be copied to another machine. The server only ever stores the public half.

| SSH | This package |
|---|---|
| `ssh-keygen` | `crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign', 'verify'])` |
| `~/.ssh/id_ecdsa` | Non-extractable `CryptoKeyPair` in IndexedDB (`kukux-signature` / `device-keys` / `default`) |
| `authorized_keys` | `digital_signature_devices` table |
| `SHA256:abc…` | `key_fingerprint` (SHA-256 of the SPKI DER), shown as `SHA256:q3Yx…Jk0` |
| Removing a line | **Revoke** in the devices list |

No extra dependencies. The server verifies with `openssl_verify()`.

---

## Enabling it

It's on by default. If you use `SignaturePlugin` in a Filament panel, there's nothing to wire up:

1. The plugin adds a `<meta name="kukux-signature-devices">` tag to the panel `<head>` for signed-in users.
2. The package's JS sees the tag and starts the attestation loop.
3. The `signature/devices/*` routes are registered by the service provider (`web` + `throttle:60,1`).

To turn it off completely:

```dotenv
SIGNATURE_DEVICES_ENABLED=false
```

With it off, no meta tag is rendered, nothing is attested, and `device_id` stays `null`.

---

## Configuration

All keys live under `signature.devices` in `config/signature.php`.

| Key | Env var | Default | What it does |
|---|---|---|---|
| `enabled` | `SIGNATURE_DEVICES_ENABLED` | `true` | Master switch |
| `require` | `SIGNATURE_DEVICES_REQUIRE` | `off` | What happens when a signing act has no verified device (see below) |
| `usage_policy` | `SIGNATURE_DEVICES_USAGE_POLICY` | `any_registered` | Which devices may *use* an existing signature |
| `notify_on_new_device` | `SIGNATURE_DEVICES_NOTIFY` | `true` | Notify the user when a second or later device registers |
| `notification_channels` | — | `['mail']` | Channels for `NewSigningDeviceNotification` |
| `max_per_user` | — | `10` | Max active devices per user. `0` means no limit |
| `challenge_ttl` | — | `120` | Seconds a challenge nonce stays valid |
| `attestation_ttl` | — | `900` | Seconds a browser's proof stays valid in the session |

**`require`**

| Value | Behaviour with no verified device |
|---|---|
| `off` | Record the device when there is one. Never block. |
| `warn` | Same, plus a `Log::warning` for the act. |
| `enforce` | Throw `UnregisteredDeviceException`. Creating or using a signature fails. |

**`usage_policy`**

| Value | Who can use a signature on a document |
|---|---|
| `any_registered` | Any active device of the owner |
| `creation_device_only` | Only the device it was created on. Otherwise `MachineBindingException`. |

`creation_device_only` is skipped for signatures with no `device_id`, e.g. ones created before devices existed.

The `signature.devices.agent` sub-block configures the desktop agent. See [desktop-agent.md](desktop-agent.md).

---

## The flow

### Attesting (every panel page)

1. The page loads the key pair from IndexedDB, or generates one.
2. It calls `POST /signature/devices/challenge` and gets a single-use nonce (cached for `challenge_ttl`, consumed with `Cache::pull`).
3. It signs `v1|attest|<nonce>|<user_id>|<key fingerprint>` and posts it to `POST /signature/devices/attest` with the public key and some label hints.
4. `DeviceRegistry` verifies the proof and stores the key in the session for `attestation_ttl` seconds.
5. The page re-attests at about 60% of the TTL, and again when a backgrounded tab comes back near expiry.

Attesting does **not** register anything. Opening a page shouldn't add a device to someone's account.

### Registering (first signature)

1. The user creates or uses a signature.
2. `SignatureManager` calls `DeviceRegistry::forSigning()`.
3. If the session's verified key isn't a known device yet, it's registered (`status = active`) and labelled from the user agent, e.g. "Chrome on Mac".
4. `DeviceRegistered` fires. From the second device on, the user gets `NewSigningDeviceNotification`.
5. The device's `id` goes on the signature and its audit row, and `last_used_at` / `last_used_ip` are updated.

### Revoked keys

When the attest endpoint answers `409` (revoked key), the browser deletes its key and makes a new one. That new key registers as a new device the next time it signs, and the owner is notified.

---

## Where users see their devices

| Place | What's there |
|---|---|
| Launcher, **Devices** tab | The `SigningDevices` Livewire component |
| Signatures page, **Signing devices** header action | Same component, in a modal |

Each device shows its label, type icon, `SHA256:…` fingerprint, protection badge (Browser key, TPM, Secure Enclave), last-used time, and a **This browser** badge for the current one. Users can **Rename** and **Revoke**. Revoked devices are listed in a collapsed section.

Devices also show up on signatures:

- Signature view (V3 and V4 resources, and the launcher's manage view): **Created on** / **Signed on**, a **Used on** list for primary signatures, and the **Device Key** fingerprint under Security Metadata.
- Signatory panel: *"Signed on Chrome on Mac · Browser key"* under each signed slot.

The same rename/revoke actions are available as JSON endpoints, scoped to the signed-in user:

```
PATCH  /signature/devices/{uuid}   { "label": "Work laptop" }
DELETE /signature/devices/{uuid}
```

---

## Data model

### `digital_signature_devices`

Created by `9999_12_31_000000_create_digital_signature_tables.php`.

| Column | Notes |
|---|---|
| `uuid` | What the client sees. Never the numeric id. |
| `user_id` | FK to users, cascade on delete |
| `label` | Up to 120 chars, user-editable |
| `public_key` | SPKI PEM |
| `key_fingerprint` | SHA-256 hex of the SPKI DER. Unique per `(user_id, key_fingerprint)` |
| `algorithm` | `ES256` (browser) or `RS256` (Windows Hello agent) |
| `kind` | `browser` or `agent` |
| `protection` | `browser`, `software`, `tpm`, `secure_enclave` |
| `device_type` | `desktop`, `mobile`, `tablet`, `unknown` |
| `platform`, `browser`, `user_agent` | From the UA and client hints. For the label only. |
| `status` | `active`, `pending`, `revoked` |
| `registered_ip`, `last_used_ip`, `last_used_at` | |
| `approved_at`, `revoked_at` | |

The table also has agent-only columns (`user_presence`, `attested`, `form_factor`, `model`, `hardware_id_hash`, `agent_version`, `session_public_key`). Browser devices leave them at their defaults.

### `device_id` on other tables

Both are nullable FKs to `digital_signature_devices`, `nullOnDelete`.

| Row | `device_id` means |
|---|---|
| `digital_signatures`, primary signature | Device it was **created on** |
| `digital_signatures`, document signature | Device it was **used on** |
| `digital_signatures`, delegated / auto-affix | Always `null`. Nobody was at a device. |
| `digital_signature_audits` | The signer's verified device, for `user` actors only |

Useful model helpers: `SigningDevice::displayName()`, `describe()`, `shortFingerprint()`, `icon()`, `protectionLabel()`, and on `Signature`: `device()`, `deviceSummary()`, `documentUses()`.

---

## Events and exceptions

| Class | When |
|---|---|
| `Events\DeviceRegistered` | A key registers as a device |
| `Events\DeviceRevoked` | A device is revoked |
| `Notifications\NewSigningDeviceNotification` | A second or later device registers (not the first) |
| `Exceptions\UnregisteredDeviceException` | `require = enforce` with no verified device, a revoked device, or `max_per_user` reached |
| `Exceptions\MachineBindingException` | `usage_policy = creation_device_only` and this isn't the creation device |

The built-in Filament actions, the launcher, and the JSON signing endpoints already catch both exceptions and show the message. Catch them yourself if you call `SignatureManager` directly.

---

## Limits

- **A device is a browser profile, not hardware.** Chrome and Firefox on the same laptop are two devices.
- **Clearing site data or using a private window destroys the key.** The next signature registers a new device. The package calls `navigator.storage.persist()` to make eviction less likely.
- **Safari may evict script storage** after 7 days without visiting the site. Regular users are fine. Installed PWAs are exempt.
- **The proof covers the session, not each signing act.** Someone with a stolen session cookie can sign as the attested device until `attestation_ttl` runs out. They can't attest a different key without registering a new device, which notifies the owner.
- **XSS on your app can use the key** (not extract it) while the page is open. Keep a sensible CSP.
- **Software keys only.** Nothing proves the key lives in hardware.

For keys held in the Secure Enclave or TPM, with Touch ID / Windows Hello on each use, see [desktop-agent.md](desktop-agent.md).

---

## Gotchas

- **Only Filament panel pages attest.** The meta tag comes from `SignaturePlugin`'s `HEAD_END` render hook. A page outside a panel has no verified device, so with `require = enforce` signing there fails.
- **Browsers without IndexedDB or Web Crypto** never attest. Leave `require` at `off` or `warn` if you have to support them.
- **`enforce` with a fresh session** can fail if the user signs before the first attestation finishes. The error asks them to reload and try again.
- **Hitting `max_per_user`** blocks registration, not attestation. The user has to revoke an old device first.
- **Revoking doesn't invalidate past signatures.** It only stops that device from signing again.
- **The legacy fingerprint still runs.** `POST /signature/device-fingerprint` and `machine_fingerprint` still feed `Sig-Machine-Hash` and `enforce_machine_lock` (see [security.md §6–7](security.md)). Device keys sit alongside it, they don't replace it.
