<?php

namespace Kukux\DigitalSignature;

use Filament\Contracts\Plugin;
use Filament\Panel;

class SignaturePlugin implements Plugin
{
    protected bool $registerPages = true;

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'signature';
    }

    public function withoutPages(): static
    {
        $this->registerPages = false;
        return $this;
    }

    public function register(Panel $panel): void
    {
        if ($this->registerPages) {
            $panel->pages([
                \Kukux\DigitalSignature\Filament\Pages\SignDocumentPage::class,
            ]);
        }
    }

    public function boot(Panel $panel): void
    {
        // v5 lifecycle hook — safe no-op on v4
    }
}