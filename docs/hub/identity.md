# Hub identity

Who a hub account is, and how people get one, at `signature.uplb.edu.ph`.
On only when `SIGNATURE_MODE=hub`. Plan: [plans/signature-hub.md](../../plans/signature-hub.md)
section 1; wire formats: [contracts.md](contracts.md) §2.3 and §4. The two
Filament panels are in [panels.md](panels.md).

There is no username or password at the hub. A visitor **pairs a computer**
first, then says **who they are** by picking their personnel record; every
later sign-in is an **agent approval** (Touch ID / Windows Hello + match code).

- [Personnel registry](#personnel-registry)
- [Identity states](#identity-states)
- [Pair this computer](#pair-this-computer)
- [Who are you?](#who-are-you)
- [Sign in with your computer](#sign-in-with-your-computer)
- [New computer, lost computer (transfers)](#new-computer-lost-computer-transfers)
- [Separation (Kafka)](#separation-kafka)
- [Break-glass](#break-glass)
- [Commands and scheduling](#commands-and-scheduling)
- [Routes](#routes)
- [PHP API](#php-api)

---

## Personnel registry

The hub app keeps the HR registry (fed from Kafka) in its own tables; the
package only reads it through `Contracts\PersonnelDirectory`. The default,
`Hub\Identity\EloquentPersonnelDirectory`, reads one Eloquent model:

```php
// config/signature.php → 'hub' => ['personnel' => [...]]
'model'   => App\Models\Personnel::class,       // SIGNATURE_HUB_PERSONNEL_MODEL
'columns' => [
    'key'      => 'uuid',            // stable id; becomes the OIDC `sub`
    'emp_no'   => 'employee_number',
    'name'     => 'full_name',
    'email'    => 'email',
    'unit'     => 'unit_name',
    'position' => 'position_title',
    'active'   => 'is_active',       // leave out: everyone in the table is current
],
'min_search'   => 3,    // characters before the form searches by name
'max_results'  => 10,
'search_quota' => 20,   // searches per account (R9)
```

- Search returns **active** people only, by a name fragment of `min_search`+
  characters **or** an exact employee number, at most `max_results` (D12).
- It also has `findByEmpNo()` (used by `signature:hub-admin`) and
  `findByEmail()` (exact, active only; used by the hub API's
  `people?email=`).
- Anything else (several tables, an HTTP registry): bind your own
  `PersonnelDirectory` in a service provider; the package binds its default
  with `bindIf`. Without a model configured, the directory throws a
  `LogicException` naming the setting.
- The admin **Personnel** page lists the configured model directly, so it
  needs `personnel.model` even if you bind your own directory.

---

## Identity states

One `digital_signature_identities` row per hub account (`Models\Identity`):

| Status | Meaning | Person panel | Sign in to apps / sign |
|---|---|---|---|
| `unidentified` | paired, hasn't said who they are | "Who are you?" only | no |
| `pending_verification` | picked a person; an admin hasn't confirmed (D9) | yes: profile, devices, draw signature | **no** (D11) |
| `verified` | confirmed | yes | yes |
| `rejected` | false claim (R8) | no | no |
| `retired` | old account after a transfer | no | no |
| `separated` | HR says they left | no | no |

`personnel_key` is the person; apps see it as `sub`, so it survives a move
to a new computer. `Identity::current($key)` is the person's live account
(pending or verified). Every change goes through `Hub\Identity\IdentityService`
and is audited (`identity.claimed`, `identity.verified`, `identity.rejected`,
`identity.separated`); audits carry `personnel_key`, so one person's history
spans all their accounts.

Set `signature.hub.require_verification` to `false` to skip the admin step
(not recommended: anyone who can pair could sign as anyone).

---

## Pair this computer

The landing page's primary button (plan 1.1–1.2):

1. `POST /signature/hub/pairings` creates a **provisional account** (a
   `users` row named "Unidentified" with a `…@hub.invalid` placeholder email
   and a random password) and an `unidentified` identity bound to this
   browser, then starts a normal agent pairing. Response:
   `{pairing, user_code, link, expires_at}`.
2. The agent does `lookup` → `claim` exactly as with the Devices tab.
3. The page polls `GET /signature/hub/pairings/{uuid}`: once claimed it gets
   `AgentPairingService::describeClaim()` (device, protection, presence,
   blockers) plus `hub_blocked`.
4. `POST /signature/hub/pairings/{uuid}/confirm` (`device_type` optional)
   confirms, **signs this browser in** as the provisional account, and
   answers `{status: "confirmed", redirect}` → "Who are you?".

Rules:

- **Only the starting browser** can see or confirm it: the identity stores
  the hash of a random value kept in that browser's session
  (`Hub\Identity\BrowserBinding`). Any other browser gets 404.
- **Per-IP limit**: `hub.pair_rate_limit` starts per hour (429 `rate_limited`).
- **Blocked computers** (a rejected claim, R8) are refused at confirm
  (`computer_blocked`) for `hub.block_days`.
- Provisional accounts that never confirm are pruned (below).

Host users table with other required columns? Set the attributes:

```php
use Kukux\DigitalSignature\Hub\Identity\HubAccounts;

HubAccounts::provisionalAttributesUsing(fn (string $email) => [
    'name' => 'Unidentified', 'email' => $email, 'type' => 'hub',
]);
```

The package writes only `name` and `email` on `users` (from the registry,
at identification), plus a placeholder email on accounts that stop standing
for someone (rejected, retired), so the real person can take that email.

---

## Who are you?

`Hub\Filament\Pages\Identify` (person panel, `/identify`). `EnsureIdentified`
lets an unidentified account open nothing else but this and Sign out.

- Search as above; every search counts against `search_quota`, and hitting
  it is logged (R9). Results show **name, unit and position** only; the
  personnel keys stay in the session, the browser sees list positions.
- **Unclaimed person** → `IdentityService::claim()`: name and email copied
  from `toClaims()`, status `pending_verification` (or `verified` when
  verification is off), then the Profile.
- **Already linked to another account** → a transfer (below); the page shows
  "Juan already has a signing computer: MacBook Pro. Approve the move on that
  computer", and carries on by itself once it's approved.

---

## Sign in with your computer

Usernameless agent sign-in (plan 1.5, R3, contracts §4.2):

1. Browser: `POST /signature/hub/login/challenges` →
   `{uuid, match_code: "47-12", link: "kukuxsign://login/<uuid>?t=…&s=<serverId>", expires_at}`.
   The challenge is bound to the browser (same `BrowserBinding`), lives
   `hub.login_ttl` seconds, and the page opens the link.
2. Agent: `POST /signature/agent/logins/{uuid}/claim` `{"link_token": t}`,
   authenticated like `jobs/{job}/claim`. The link token is single use. The
   **computer's account** becomes the one signing in. Response: a normal job
   payload, already claimed by this computer, with `purpose: "login"`,
   `document.title: "Sign in to <app name>"` and
   `login: {match_code, browser: "Chrome on macOS", ip}`.
   Errors (`{"error":{"code","message"}}`): 404 `login_not_found`,
   409 `login_unavailable` (expired, used, or an account that can't sign in:
   retired / separated / rejected, or a blocked computer), 403
   `invalid_link_token`.
3. Agent: the existing `jobs/{uuid}/complete` with a proof over
   `v1|login|<nonce>|<user_id>|sha256(uuid|match_code|browser hash)`, or
   `/reject`. The proof covers the browser binding, so it can't be replayed
   onto another browser's challenge.
4. Browser: polls `GET /signature/hub/login/challenges/{uuid}` (that browser
   only; others get 404). On `approved` it is signed in once (the challenge
   becomes `consumed`) and gets `redirect` from `HubRedirector`.

`login.approved` / `login.rejected` are audited with the browser, both IPs
and the device; `network_differs` flags an approval from another network.

---

## New computer, lost computer (transfers)

One computer per person (plan 1.6, R11). When someone identifies as a
person already linked to another account, `Hub\Identity\IdentityTransfer`:

- **request()** creates a `Transfer` and, if the old account still has a
  paired computer, an agent job for it: purpose `transfer`, title "Move your
  signature to a new computer", `transfer: {name, device}`, valid
  `hub.transfer_ttl` seconds. The old account's Profile shows "A new computer
  wants to take over your signature" with **Approve on this computer** (a
  fresh `kukuxsign://job/…` link each time) and **That wasn't me**.
- No old computer (lost, released): it waits in the admin **Pending claims**
  queue. An admin checks the person's ID and approves.
- **apply()** (old computer approved, or an admin):
  1. the old account is `retired` (email freed);
  2. the personnel link moves to the new account (verified if the old one
     was, or an admin approved);
  3. the person's own signature rows (`signable_id` null) move to the new
     account; signed documents keep their history;
  4. the old computer is released, the old account's certificates revoked
     (the next signature issues a new one);
  5. audit `transfer.approved`; `HubNotifier::signatureUpdated()`.
- **reject()**: the old computer declined, the person cancelled, or an admin
  refused. Audited `transfer.rejected`.

`sub` (the personnel key) doesn't change, so apps see the same person.

---

## Separation (Kafka)

The hub app's Kafka consumer calls, when HR reports someone left:

```php
app(\Kukux\DigitalSignature\Hub\Identity\IdentityService::class)->separate($personnelKey);
```

Every current account of theirs becomes `separated`, their computers are
released, `HubRevocation::revokeSignature($userId, 'separated')` revokes the
signature and certificate and deletes the apps' mirrors, and
`HubNotifier::personSeparated()` sends `person.separated` to holder apps.

---

## Break-glass

For when nobody can sign in with their computer (D10). Admin panel only;
email + password (the host's user) + TOTP (RFC 6238), from allowlisted IPs,
for at most two people:

```php
'break_glass' => [
    'enabled' => env('SIGNATURE_HUB_BREAK_GLASS', false),
    'users'   => ['ict.head@uplb.edu.ph' => 'JBSWY3DPEHPK3PXP'],  // base32 TOTP secret
    'ips'     => ['10.0.5.0/24'],                                  // SIGNATURE_HUB_BREAK_GLASS_IPS, comma-separated
],
```

- `GET/POST /signature/hub/break-glass`; 404 from any other IP. The landing
  page shows a small link only on an allowlisted IP.
- The user must also be a super_admin. Codes are single use; 5 tries a
  minute per IP.
- Each use: audit `login.break_glass` and a log entry at **alert** (point
  your alert channel at it). The session is marked
  (`HubAccess::BREAK_GLASS`) and opens the admin panel even without a
  verified identity. It never signs and never serves OIDC to apps.
- Generate a secret with any authenticator-compatible tool; keep the
  published config out of version control or read it from a secret store.

---

## Commands and scheduling

| Command | |
|---|---|
| `signature:prune-provisional [--dry-run]` | deletes provisional accounts older than the pairing TTL with no device and no transfer (R10). Scheduled **hourly** by the package. |
| `signature:hub-admin {emp_no}` | bootstrap: verifies that person's current account, gives it `super_admin` via `assignRole()` (Shield / spatie), audits `admin.granted` (actor_type `console`). The person must have paired and identified first. |

---

## Routes

All registered by `Hub\HubIdentityServiceProvider` from `routes/hub-web.php`.

| Route | |
|---|---|
| `POST signature/hub/pairings` | guest: start pairing |
| `GET signature/hub/pairings/{uuid}` | poll (this browser only) |
| `POST signature/hub/pairings/{uuid}/confirm` | confirm → signed in |
| `POST signature/hub/pairings/{uuid}/cancel` | "That's not mine" |
| `POST signature/hub/login/challenges` | start agent sign-in |
| `GET signature/hub/login/challenges/{uuid}` | poll (this browser only) |
| `GET signature/hub/landing` (`signature.hub.landing`) | redirect to the person panel's landing (OAuth authorize sends guests here) |
| `GET/POST signature/hub/break-glass` | break-glass form |
| `POST signature/agent/logins/{uuid}/claim` | agent (bearer + request proof) |

The panels' own pages are in [panels.md](panels.md).

---

## PHP API

| Class | |
|---|---|
| `Hub\Identity\IdentityService` | `personnelKeyFor($userId)`, `isVerified($userId)`, `userIdFor($key)` (contracts §2.3); `search()`, `identify()`, `claim()`, `verify($userId, $actorId)`, `reject($userId, $actorId, $reason)`, `separate($key)`, `retire()`, `computer($userId)`, `releaseDevices()` |
| `Hub\Identity\IdentityTransfer` | `request()`, `apply($transfer, $actorId, byAdmin: bool)`, `reject()`, `approvalLink()`, `pendingFor()` |
| `Hub\Identity\HubLoginService` | `start()`, `poll()`, `claim()` (agent), `jobUpdated()` |
| `Hub\Identity\GuestPairing` | `start()`, `describe()`, `confirm()`, `cancel()` |
| `Hub\Identity\HubRedirector` | `afterSignIn($user)`, `profileUrl()`, `identifyUrl()`, `adminUrl()`, `landingUrl()` |
| `Hub\Identity\HubAccess` | `canAccessPanel($user, $panel)`, `isSuperAdmin($user)`, `superAdminUsing(Closure)` |
| `Hub\Identity\HubAccounts` | `provisionalAttributesUsing(Closure)` |
| `Hub\Identity\Http\Middleware\EnsureIdentified` | person panel auth middleware |
| `Hub\Identity\Listeners\HandleIdentityAgentJobs` | `AgentJobUpdated` → `login` / `transfer` |
