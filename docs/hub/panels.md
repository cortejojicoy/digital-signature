# Hub panels

The hub app runs **two Filament panels** on one `web` guard (plan 1.8), so one
session works in both. **Filament 4 or 5** only: the person panel needs
`->topbar(false)`, which Filament 3 doesn't have. Identity flows are in
[identity.md](identity.md).

| | Person panel | Admin panel |
|---|---|---|
| id / path | `hub` / `/` | `admin` / `/admin` |
| Who | everyone who paired a computer | verified **super_admin** (or a break-glass session) |
| Look | no topbar, no sidebar: one centred page | default Filament: sidebar, topbar, user menu |
| Preset | `SignaturePlugin::make()->hubPersonPanel()` | `SignaturePlugin::make()->hubAdminPanel()` |
| Pages | Landing (its login page), Who are you? (`/identify`), **Profile** (`/`) | Personnel, Pending claims, Audit log, Apps, Signatures |
| Login | the landing: Pair this computer / Sign in with your computer | none: guests go to the person panel's landing |

---

## Panel providers

```php
// app/Providers/Filament/HubPanelProvider.php
public function panel(Panel $panel): Panel
{
    return $panel
        ->id('hub')
        ->path('')
        ->default()
        ->topbar(false)
        ->navigation(false)
        ->middleware([/* the usual web stack: EncryptCookies, StartSession, … */])
        ->authMiddleware([\Filament\Http\Middleware\Authenticate::class])
        ->plugin(SignaturePlugin::make()->hubPersonPanel());
}

// app/Providers/Filament/AdminPanelProvider.php
public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->path('admin')
        ->sidebarCollapsibleOnDesktop()
        ->middleware([/* same */])
        ->authMiddleware([\Filament\Http\Middleware\Authenticate::class])
        ->plugin(SignaturePlugin::make()->hubAdminPanel());
}
```

Call `->id()` and `->path()` **before** `->plugin()`: the preset reads the id
when it registers. Other ids work; the package remembers them
(`HubPanels::personPanelId()` / `adminPanelId()`).

What the presets do (`Hub\Filament\HubPanels`):

- **Person**: `->login(Landing::class)`, `->topbar(false)->navigation(false)`
  (again, in case the provider forgets), pages Profile + Identify, and the
  `EnsureIdentified` auth middleware. No floating launcher, no inbox, no
  Signatures resource: the hub has no documents of its own.
- **Admin**: no password login (the login route redirects to the landing,
  keeping the intended URL), `->sidebarCollapsibleOnDesktop()`, the admin
  pages, the package's Signatures resource, and a **My profile** user-menu
  item back to the person panel.
- Both register the plugin's JS bundle (the signature pad).

Don't add Filament's `->passwordReset()` or `->registration()`: the hub has
no passwords.

---

## User model

```php
use Filament\Models\Contracts\FilamentUser;
use Kukux\DigitalSignature\Hub\Identity\Concerns\HasHubPanelAccess;

class User extends Authenticatable implements FilamentUser
{
    use HasHubPanelAccess;   // canAccessPanel()
    use HasRoles;            // Shield / spatie: super_admin
}
```

`canAccessPanel()` (`Hub\Identity\HubAccess`):

- **hub**: any account whose identity isn't `retired`, `separated` or
  `rejected`.
- **admin**: `hasRole(config('signature.hub.super_admin_role'))` **and** a
  verified identity; or a break-glass session. A super_admin whose identity
  isn't verified (or whose computer was released and moved) loses the admin
  panel until it's fixed.

No `hasRole()` on your model? Tell the hub who is a super_admin:

```php
HubAccess::superAdminUsing(fn (User $user) => $user->is_admin);
```

Bootstrap the first one with `php artisan signature:hub-admin {emp_no}`
after they pair and identify ([identity.md](identity.md#commands-and-scheduling)).

---

## Where people land

`Hub\Identity\HubRedirector::afterSignIn($user)`, also bound as Filament's
`LoginResponse` in hub mode, and used by the agent sign-in poll, the pairing
confirm and "Who are you?":

0. Not identified yet → **Who are you?** (an intended URL waits for after).
1. An **intended URL** (an app's `/signature/hub/oauth/authorize?…`) →
   there first. App SSO is never hijacked by the admin redirect. An
   intended admin URL counts only for someone the admin panel admits.
2. Can use the admin panel → `hub.admin_path` (`/admin`).
3. Everyone else → the Profile (`/`).

---

## Person panel pages

- **Landing** (`/login`, the panel's login page). Guest only. "Pair this
  computer" (primary), "Sign in with your computer", agent downloads from
  `hub.downloads.mac` / `.windows`. Plain `fetch()` + polling against the
  endpoints in [identity.md](identity.md#routes); the claimed computer is
  shown with the same details as the Devices tab. A "Break-glass sign-in"
  link appears only on an allowlisted IP.
- **Who are you?** (`/identify`).
- **Profile** (`/`), the home page. Header: name, unit, identity badge,
  **Sign out**, **Open admin panel** (super_admin only), since there's no user
  menu. Then: personnel card; signature (draw or upload with the package's
  React pad and the same rules as the launcher, `RegistersSignatures`; then
  `SpecimenService::published()`; stored but unusable until verified);
  certificate; **This computer and devices** (the package's
  `SigningDevices` component: rename, revoke, pair); apps holding a mirror
  (`HubHolder`); recent activity (the person's own audit trail); and any
  pending move to a new computer, with **Approve on this computer**.

## Admin panel pages

- **Personnel** (`/admin/personnel`): the registry model. Columns: name,
  emp_no, unit, identity status (unclaimed / pending / verified /
  separated), paired computer, last signature use. Filters: unit, status,
  has a computer, signature unused for N days. Each row opens the person
  (`/admin/personnel/{key}`): identity (verify / reject), signature (revoke →
  `HubRevocation`), certificates, devices (full details, release), apps,
  audit trail.
- **Pending claims** (`/admin/pending-claims`, badge = count): claims to
  verify or reject (reject releases and blocks the computer and retires the
  account), and moves to a new computer to approve when the old one is lost
  or refuse. Flags several claims on one person.
- **Audit log** (`/admin/audit-log`): every audit row as **when / what /
  where / how / outcome** (`Hub\Identity\AuditPresenter`); filters by person,
  app, event, date range; **Export CSV** of what's filtered.
- **Apps** (`/admin/apps`): registered apps (scopes, webhook URL, holders,
  failing deliveries); register (via `HubAppRegistrar`; secrets shown
  **once**), rotate client / webhook secret, deactivate; latest webhook
  deliveries with **Redeliver** (`HubWebhookDispatcher::redeliver()`).
- **Signatures**: the package's resource.

Unit admins and auditors (D13) aren't built: the admin panel is
super_admin only.
