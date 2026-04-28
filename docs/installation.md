# Installation

## Requirements

- PHP 8.2+ with `ext-openssl` and `ext-gd`
- Laravel 11 or 12
- Filament 4 or 5

---

## 1. Install via Composer

```bash
composer require kukux/digital-signature
```

---

## 2. Publish and migrate

```bash
php artisan vendor:publish --tag=signature-migrations
php artisan vendor:publish --tag=signature-config
php artisan migrate
```

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

## 5. Start the queue worker

By default, `SignDocumentAction` signs synchronously — no queue needed. If you opt into queued signing (`.queued()`), start a worker:

```bash
php artisan queue:work
```

---

## 6. Publish views and assets (optional)

Only needed if you want to customise the Blade templates or override compiled JS/CSS.

```bash
php artisan vendor:publish --tag=signature-views
php artisan vendor:publish --tag=signature-assets
```
