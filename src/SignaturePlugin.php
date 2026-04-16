<?php

namespace Kukux\DigitalSignature;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource;

class SignaturePlugin implements Plugin
{
    protected bool $registerResource = true;

    protected ?string $navigationIcon = null;   // null = fall back to config

    protected ?string $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected ?string $navigationLabel = null;

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'signature';
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Set the navigation icon for the Signatures resource.
     * Defaults to config('signature.resource.navigation_icon').
     *
     * Example: ->navigationIcon('heroicon-o-pencil')
     */
    public function navigationIcon(string $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    /**
     * Place the resource under a navigation group.
     * Pass null to remove grouping.
     *
     * Example: ->navigationGroup('Documents')
     */
    public function navigationGroup(?string $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    /**
     * Control the position of the resource in the sidebar.
     *
     * Example: ->navigationSort(10)
     */
    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    /**
     * Override the navigation label shown in the sidebar.
     *
     * Example: ->navigationLabel('Document Signatures')
     */
    public function navigationLabel(?string $label): static
    {
        $this->navigationLabel = $label;

        return $this;
    }

    /**
     * Prevent the SignatureResource from being registered.
     * Useful when you want to provide your own resource.
     */
    public function withoutResource(): static
    {
        $this->registerResource = false;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Getters (used by SignatureResource to read resolved values)
    // -------------------------------------------------------------------------

    public function getNavigationIcon(): string
    {
        return $this->navigationIcon
            ?? config('signature.resource.navigation_icon', 'heroicon-o-pencil-square');
    }

    public function getNavigationGroup(): ?string
    {
        return $this->navigationGroup
            ?? config('signature.resource.navigation_group');
    }

    public function getNavigationSort(): ?int
    {
        $sort = $this->navigationSort ?? config('signature.resource.navigation_sort');

        return $sort !== null ? (int) $sort : null;
    }

    public function getNavigationLabel(): string
    {
        return $this->navigationLabel
            ?? config('signature.resource.navigation_label', 'Signatures');
    }

    // -------------------------------------------------------------------------
    // Filament lifecycle
    // -------------------------------------------------------------------------

    public function register(Panel $panel): void
    {
        if ($this->registerResource && config('signature.resource.enabled', true)) {
            $panel->resources([SignatureResource::class]);
        }
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
