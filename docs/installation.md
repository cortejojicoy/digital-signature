# Installation

## Requirements

- PHP 8.2+ with `ext-openssl` and `ext-gd`
- Laravel 12
- Filament 3, 4, or 5

> **Laravel 11 is not supported.** Every 11.x release, up to and including the
> final 11.56.1, is affected by
> [PKSA-mdq4-51ck-6kdq](https://packagist.org/security-advisories/) (CRLF
> injection in the default email rule, patched in 12.60.0). Laravel 11 is past
> its security-support window, so no fixed 11.x will ever be released. Composer
> 2.9 blocks advisory-affected packages by default, which makes the whole line
> uninstallable — this package dropped the constraint rather than ask you to
> disable that protection.

---

## 1. Install via Composer

```bash
composer require kukux/digital-signature
```

---

## 2. Publish config and migrate

```bash
php artisan vendor:publish --tag=signature-config
php artisan migrate
```

The package's migration runs straight from `vendor/`, so there is nothing to
publish. It only creates the tables and columns that are missing, so the same
`php artisan migrate` works for a fresh install and for an upgrade from any
earlier release.

The migration is named `9999_12_31_000000_create_digital_signature_tables.php`
so it always runs after your app's own migrations, including your `users`
table. The flip side is that if one of your migrations adds a foreign key to a
package table, publish the migration (below) and give it a timestamp earlier
than yours.

If you need to edit the schema, publish the migration instead:

```bash
php artisan vendor:publish --tag=signature-migrations
```

Once a published copy exists in `database/migrations`, the package stops
loading its own, so the migration never runs twice. Publishing again does not
create a second copy.

> **Upgrading from v1.8.x or earlier with published migrations?** Those
> fifteen `*_create_digital_*` files in `database/migrations` have already
> run. Leave them in place. The new consolidated migration sees the existing
> tables and only adds what is missing. Its `down()` will not drop tables
> that the old migrations created.

### Keep your published config current

Laravel merges `config/signature.php` with the package defaults **one level
deep**. A top-level block you deleted falls back to the package's version, but
a block you kept replaces the package's version entirely.

So if your copy has a trimmed `'launcher' => ['position' => 'bottom-left']`,
every other launcher key, and every `SIGNATURE_LAUNCHER_*` variable, is
ignored. Either keep a block whole, or delete it and set it from `.env`. After
upgrading the package, diff your copy against
`vendor/kukux/digital-signature/config/signature.php` for new blocks.

### Starter `.env`

The defaults work, but these are the settings worth deciding up front:

```bash
# Certificates and signed PDFs. Must be a PRIVATE disk; `local` is
# storage/app/private. Never `public`.
SIGNATURE_DISK=local
SIGNATURE_CERT_DRIVER=openssl
SIGNATURE_PDF_DRIVER=fpdi

# Consent. `approval` never signs on someone's behalf: routing finds the
# signatory and places the block, but they sign in their own session.
SIGNATURE_AUTO_AFFIX_MODE=approval
SIGNATURE_ALLOW_IMPLICIT_AFFIX=false
SIGNATURE_AUTO_AFFIX_NOTIFY=true

# Signing order and how long an unfinished session stays open.
SIGNATURE_SEQUENCE_MODE=sequential   # or parallel
SIGNATURE_MULTI_MODE=progressive  # or incremental (one certificate per signer)
SIGNATURE_SESSION_EXPIRY_DAYS=180

# Blocks re-uploading a signature from another machine. Leave it on.
SIGNATURE_MACHINE_LOCK=true

# Only turn on if your CA publishes a CRL.
SIGNATURE_CRL_ENABLED=false

# RFC 3161 timestamping. Blank = off. Free option: https://freetsa.org/tsr
SIGNATURE_TSA_URL=

# Desktop agent pairing. Its server ID and salt derive from APP_KEY unless
# you set SIGNATURE_AGENT_SERVER_ID / SIGNATURE_AGENT_SALT. Set them before
# you ever rotate APP_KEY, or every paired computer has to pair again.
SIGNATURE_AGENT_ENABLED=true

# Resolution of the page previews in the placement designer.
SIGNATURE_DESIGNER_DPI=144
```

The full list is in [Configuration](configuration.md#full-environment-variable-reference).

Then publish the plugin's JS bundle so the signature pad and picker components work in the browser:

```bash
php artisan filament:assets
```

`filament:assets` reads the asset registered via `FilamentAsset::register()` in `SignaturePlugin` and links it under `public/js/filament/kukux/digital-signature/`. Run it again after every `composer update` of this package so the published JS stays in sync with the installed version.

---

## 3. Register the plugin

Add `SignaturePlugin` to your Filament panel provider.

```php
// app/Providers/Filament/AdminPanelProvider.php

use Kukux\DigitalSignature\SignaturePlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            SignaturePlugin::make(),
        ]);
}
```

This automatically registers:
- **Signatures resource** — register reusable signature images and view signature records in the admin panel
- **Sign Document actions** — actions inside the Signatures resource for signing with a registered signature

Do not rely on `discoverResources()` to pick up the package resource from `vendor`. The supported setup is to register `SignaturePlugin::make()` on each Filament panel that should show the signature resource.

Got more than one panel? Register the plugin on every panel where people sign
or use the designer, not just the one with the menu item. The signer page,
designer and inbox look up the plugin from the panel they render on.

### Authorization

`Kukux\DigitalSignature\Models\Signature` lives in `vendor/`, so Laravel's
policy auto-discovery never finds a policy for it. Register one yourself. A
signature should only ever be usable by its owner, admins included:

```php
// app/Providers/AppServiceProvider.php
Gate::policy(
    \Kukux\DigitalSignature\Models\Signature::class,
    \App\Policies\DigitalSignaturePolicy::class,
);
```

```php
// app/Policies/DigitalSignaturePolicy.php
class DigitalSignaturePolicy
{
    public function viewAny(User $user): bool { return true; }
    public function create(User $user): bool { return true; }

    public function view(User $user, Signature $signature): bool   { return $this->owns($user, $signature); }
    public function update(User $user, Signature $signature): bool { return $this->owns($user, $signature); }
    public function delete(User $user, Signature $signature): bool { return $this->owns($user, $signature); }

    // Deleting signatures in bulk would wipe the history of every document they signed.
    public function deleteAny(User $user): bool { return false; }
    public function forceDelete(User $user, Signature $signature): bool { return false; }

    private function owns(User $user, Signature $signature): bool
    {
        return (int) $signature->user_id === (int) $user->getAuthIdentifier();
    }
}
```

> **Using Filament Shield?** Don't call it `SignaturePolicy`.
> `shield:generate --all` writes a policy under that name for this model and
> overwrites yours. A different name keeps it safe.
>
> Shield's `Gate::before` also gives super admins every ability, so they skip
> this policy. The resource still only lists each person's own signatures, so
> the record check matters mostly for a guessed URL.

---

## 4. Configure the admin resource (optional)

Customize the Signatures resource navigation appearance using fluent methods on the plugin:

```php
SignaturePlugin::make()
    ->navigationIcon('heroicon-o-pencil-square')  // default icon
    ->navigationGroup('Documents')                 // group in sidebar (null = ungrouped)
    ->navigationSort(10)                           // sort position
    ->navigationLabel('Document Signatures')       // custom sidebar label
```

Or via `.env`:

```bash
SIGNATURE_RESOURCE_ICON=heroicon-o-pencil-square
SIGNATURE_RESOURCE_GROUP=Documents
SIGNATURE_RESOURCE_SORT=10
SIGNATURE_RESOURCE_LABEL=Signatures
SIGNATURE_RESOURCE_ENABLED=true
```

To hide the resource entirely (e.g. when building your own):

```php
SignaturePlugin::make()->withoutResource()
```

## 5. Register PDF templates (optional)

If your app produces fixed-layout PDFs (DTRs, payslips, contracts) that should be sign-able through the plugin's placement designer, register them via `templates()`:

```php
SignaturePlugin::make()
    ->templates([
        \App\Pdf\DtrTemplate::class,
        \App\Pdf\PayslipTemplate::class,
    ])
```

If you run `php artisan config:cache` (you should in production), register
templates as class-strings. A closure in `config/signature.php`, like a
`data_resolver` or a slot's `signatory`, makes `config:cache` fail. Put those
in a template class and list the class instead:

```php
// config/signature.php
'templates' => [
    \App\Pdf\AccomplishmentReportTemplate::class,
],
```

See [PDF Templates](pdf-templates.md) for the `PdfTemplate` contract and slot definitions. This is purely additive — Signable models without a template continue to work via the ad-hoc and on-demand flows.

If you see `Plugin [signature] is not registered for panel [admin]`, check that the plugin is registered on the same panel that is rendering the resource:

```php
// app/Providers/Filament/AdminPanelProvider.php
use Kukux\DigitalSignature\SignaturePlugin;

$panel->plugins([
    SignaturePlugin::make(),
]);
```

In multi-panel apps, register the plugin on every panel that uses the package resource or actions.

---

## 6. Start the queue worker

By default, `SignDocumentAction` signs synchronously — no queue needed. If you opt into queued signing (`.queued()`), start a worker:

```bash
php artisan queue:work
```

---

## 7. Publish views and assets (optional)

Customise the Blade templates by publishing them into your app's view directory:

```bash
php artisan vendor:publish --tag=signature-views
```

To copy the compiled JS into `public/vendor/digital-signature/` (instead of letting Filament symlink it via `filament:assets`), use:

```bash
php artisan vendor:publish --tag=signature-assets
```

This is only needed when you're serving the JS directly outside Filament's asset pipeline — most projects should use `php artisan filament:assets` from step 2 instead. If you publish manually, re-run this command after every `composer update` of this package.
