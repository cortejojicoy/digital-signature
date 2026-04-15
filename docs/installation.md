# Installation

## Requirements

- PHP 8.2+ with `ext-openssl` and `ext-gd`
- Laravel 11 or 12
- Filament 4 or 5

## Install via Composer

```bash
composer require kukux/digital-signature
```

## Publish and migrate

```bash
php artisan vendor:publish --tag=signature-migrations
php artisan vendor:publish --tag=signature-config
php artisan migrate
```

## Register the plugin

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

To disable the built-in sign page:

```php
SignaturePlugin::make()->withoutPages()
```

## Queue worker

Signing runs asynchronously. Start your queue worker before testing:

```bash
php artisan queue:work
```

## Publish views (optional)

Only needed if you want to customise the Blade templates.

```bash
php artisan vendor:publish --tag=signature-views
php artisan vendor:publish --tag=signature-assets
```
