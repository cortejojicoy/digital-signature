# Client mode

An app in client mode (`SIGNATURE_MODE=client`) uses the UPLB Signature hub
for everything about a person's signature. It still keeps everything about
**its own documents**: routing, the inbox and drawer, the drag viewer, the
document of record, templates and verification.

| Here (the app) | At the hub |
|---|---|
| Documents, sessions, slots, signed PDFs | The signature image (specimen) |
| A read-only **mirror** of each person's image, for the drag tray | Devices and the Kukux Sign Agent pairing |
| Hash-only signing: stamp, send the digest, inject the CMS | Certificates and keys; the CMS signature itself |
| Who may sign a slot | Revocation, separation, identity checks |

Contracts with the hub: [contracts.md](contracts.md). Hash-only PDF signing:
[deferred-signing.md](deferred-signing.md). Mirrors on RustFS:
[rustfs.md](rustfs.md).

---

## Moving an app to client mode

1. **Register the app at the hub.** In the hub admin panel (Apps), add the app
   with:
   - redirect URI `https://<app>/signature/hub/callback`
   - webhook URL `https://<app>/signature/hub/webhook`
   - scopes `signatures.read` and `sign`

   Keep the client secret and webhook secret it shows you.
2. **Dry run.** Before switching, see who will match:

   ```bash
   php artisan signature:hub-sync --dry-run
   ```

   It lists each local user as *linked*, matched by *emp_no* or *email*, or
   *unmatched*, and flags anyone who has a signature here but none at the
   hub. Those people need to add one at the hub first. The dry run changes
   nothing.
3. **Install.**

   ```bash
   php artisan signature:install --mode=client
   ```

   This writes `SIGNATURE_MODE=client` and blank `SIGNATURE_HUB_*` keys to
   `.env`. It skips the certificate, CRL, timestamp and agent settings (the
   hub does those; `--agent` is ignored). It also checks that the mirror disk
   is private. Then fill in the keys (below).
4. **Schedule the retry** (outage recovery, R1):

   ```php
   Schedule::command('signature:hub-retry')->everyFiveMinutes();
   ```

5. **Deploy.** People sign in with **Sign in with UPLB Signature** on the login
   page. On their first sign-in their hub account is linked to the local user
   (matched by email once, then by hub `sub` from then on) and their
   signature image is mirrored. Local password login keeps working for
   break-glass accounts.

An app missing any of `SIGNATURE_HUB_URL`, `_CLIENT_ID`, `_CLIENT_SECRET` or
`_WEBHOOK_SECRET` refuses to serve requests. Artisan commands only print a
warning, so the installer and `config:cache` still run.

## Environment

| Key | Meaning |
|---|---|
| `SIGNATURE_MODE` | `client` |
| `SIGNATURE_HUB_URL` | `https://signature.uplb.edu.ph` |
| `SIGNATURE_HUB_CLIENT_ID` | The app's client id at the hub |
| `SIGNATURE_HUB_CLIENT_SECRET` | Client secret (app token, sign-in code exchange) |
| `SIGNATURE_HUB_WEBHOOK_SECRET` | Verifies webhooks (HMAC-SHA256) |
| `SIGNATURE_HUB_MIRROR_DISK` | Disk for mirrors. Blank = `SIGNATURE_DISK`; `rustfs` recommended |
| `SIGNATURE_HUB_STALE_AFTER` | Seconds before the viewer re-checks a mirror (default 86400) |
| `SIGNATURE_HUB_TIMEOUT` | HTTP timeout to the hub, seconds (default 10) |
| `SIGNATURE_HUB_CREATE_USERS` | Create a local user on first hub sign-in when nobody matches (default true) |

Also in `config/signature.php` under `hub`: `breaker.failures` /
`breaker.cooldown` (circuit breaker), `profile_path` (where "Change it at the
hub" links go), `mirror_dir` (folder for mirrors on a local disk) and
`webhook_tolerance` (accepted clock skew for webhooks).

## What changes in the app

- **Signatures resource:** removed. **Launcher:** the Library tab shows the
  mirror read-only with a link to change it at the hub. The Devices tab says
  the signing computer is managed at the hub. "Manage signatures" is
  hidden. **`SignaturePad`:** renders a link to the hub. Registering a
  signature (`SignatureManager::store()`, `RegistersSignatures`) is refused,
  and so is revoking a mirror locally.
- **Drag tray:** lists the mirror. With no mirror, the viewer links to the
  hub (`meta.hub.libraryUrl`). Opening a document re-checks a mirror older
  than `stale_after`.
- **Signing:** see below. No certificate is issued or loaded here.
- **Routing:** tagged people are resolved through `Signatories\HubUserMapper`
  (hub lookup by emp_no, then email). This applies unless the app binds its
  own `SignatoryUserMapper`. A person who has never signed in routes as
  unassigned until they do. Add `hubIdentifiers()` to your personnel model
  to choose the identifiers.
- **Verification page:** asks the hub whether the certificate still stands
  (cached for 5 minutes). A revoked certificate reads "not valid". If the hub
  is down, the local answer stands, marked `certificate: unchecked`.
- **Devices and the agent:** `signature.devices.enabled` is forced off.
  Device, pairing and agent routes aren't registered. The approval overlay's
  poll (`signature/agent-web/jobs/{uuid}`) stays and answers from the hub.
- **Delegated signing** (auto-affix under a delegation) is refused, because
  only the signer's own computer can approve a hub signature.

## Signing through the hub

1. The signer places their signature and presses Sign, the same as before.
2. The app stamps the mirror image into a copy of the document with an empty
   signature, records a `HubPendingSign`, and sends the digest to the hub
   (`POST sign-requests`, with the mirror's hash as `specimen_hash`).
3. The hub answers with an agent link. The existing "Approve on your
   computer" overlay opens Kukux Sign Agent and polls. When the hub has
   signed (the poll or the `sign_request.completed` webhook), the overlay
   retries the same call.
4. The app injects the hub's CMS, then finishes as standalone does: signed
   path, hashes, document version, `DocumentSigned`.

Each slot is its own approval. Signing two slots at once means two approvals
in a row; a slot already signed by an earlier round is reported, not signed
again.

- **Changed specimen (409):** the app re-pulls the mirror, re-stamps and
  resubmits, once.
- **Hub down:** the request stays `unsent` and the signer reads *"Signing is
  unavailable. Your placement is saved."* `signature:hub-retry` sends it (same
  idempotency key) once the hub answers. After the signer approves, the next
  Sign click on that document finishes it.
- **Circuit breaker:** after `breaker.failures` consecutive connection errors
  or 5xx answers, calls fail fast for `breaker.cooldown` seconds, and
  `hub.unavailable` is logged.

## The mirror and RustFS

A mirror is a normal `digital_signatures` row (`signable_id` null,
`status = active`, `source = hub`, no certificate password). Its image is
`{hub_uuid}.png` on the mirror disk:

- **local disk:** under `hub.mirror_dir` (`signatures/hub/`)
- **RustFS (s3):** at the root of the app's prefix. The object is private and
  carries `x-amz-meta-sha256`.

The disk is recorded on the row (`pades_info.mirror_disk`), so changing
`SIGNATURE_HUB_MIRROR_DISK` later doesn't strand older images. Mirrors are
served to browsers only through the app's short-lived signed route
(`signature/assets/{uuid}`), never as a presigned RustFS link.

The hash is checked:

- when the image is downloaded (against the hub's `image_sha256` and the
  `X-Image-Sha256` header);
- before stamping, where a mismatch logs `mirror.tampered` and re-pulls the
  image;
- by the hub on every sign request.

`Client\HubSignatureSync` is the only writer of mirrors. It pulls on first
sign-in, on the `signature.updated` webhook, after a 409, when the viewer finds
a stale mirror, and from `signature:hub-sync`. A revoked or separated person's
mirror is marked revoked and its image deleted, and their pending sign
requests are blocked.

RustFS disk, in the app's `config/filesystems.php`:

```php
'rustfs' => [
    'driver'                  => 's3',
    'endpoint'                => env('RUSTFS_ENDPOINT', 'https://rustfs.uplb.edu.ph'),
    'use_path_style_endpoint' => true,
    'key'                     => env('RUSTFS_ACCESS_KEY'),
    'secret'                  => env('RUSTFS_SECRET_KEY'),
    'region'                  => env('RUSTFS_REGION', 'us-east-1'),
    'bucket'                  => env('RUSTFS_BUCKET', 'signature-mirrors'),
    'root'                    => env('RUSTFS_PREFIX'),   // this app's id
    'visibility'              => 'private',
    'throw'                   => true,
],
```

## Routes (client mode only)

| Route | Purpose |
|---|---|
| `GET signature/hub/login` (`signature.hub.login`) | Start hub sign-in (state + PKCE S256). `?panel=` picks the panel |
| `GET signature/hub/callback` (`signature.hub.callback`) | Finish sign-in |
| `POST signature/hub/webhook` (`signature.hub.webhook`) | Hub events. No session or CSRF; HMAC + timestamp; each event id handled once |

## Commands

| Command | Does |
|---|---|
| `signature:install --mode=client` | `.env` for client mode, mirror disk check, next steps |
| `signature:hub-sync` | Pull every linked user's mirror |
| `signature:hub-sync --since=2026-10-01` | Only mirrors not checked since then (after a webhook outage) |
| `signature:hub-sync --dry-run` | Match report; changes nothing |
| `signature:hub-retry` | Send `unsent` sign requests; refresh pending ones older than `--stale` seconds |

## Rollback

Set `SIGNATURE_MODE=standalone` (and `php artisan config:clear`). Nothing is
deleted by client mode:

- Local signatures, devices and certificates from before the move are back
  in use.
- Mirror rows (`source = hub`) are ignored in standalone.
- Documents signed through the hub keep their signatures.

Delete old local signature data only after a stable period (suggested: 60
days).
