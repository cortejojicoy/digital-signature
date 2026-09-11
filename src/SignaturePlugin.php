<?php

namespace Kukux\DigitalSignature;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Closure;
use Kukux\DigitalSignature\Filament\Pages\PdfTemplateDesigner;
use Kukux\DigitalSignature\Filament\Pages\PdfTemplateSigner;
use Kukux\DigitalSignature\Filament\Pages\SignatureInbox;
use Kukux\DigitalSignature\Signatories\SignatoryResolverFactory;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource;
use Kukux\DigitalSignature\Services\PdfTemplateRegistry;

class SignaturePlugin implements Plugin
{
    protected bool $registerResource = true;

    protected ?string $navigationIcon = null;   // null = fall back to config

    protected ?string $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected ?string $navigationLabel = null;

    /** @var array<PdfTemplate|class-string<PdfTemplate>> */
    protected array $templates = [];

    protected ?Closure $signatoryResolver = null;

    protected ?bool $registerInbox = null;

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

    /**
     * Register PdfTemplate implementations the placement designer and
     * "apply signature to PDF" flows should know about. Stacks on top
     * of templates already declared in config('signature.templates').
     *
     * @param iterable<PdfTemplate|class-string<PdfTemplate>> $templates
     *
     * Example:
     *   SignaturePlugin::make()->templates([
     *       \App\Pdf\DtrTemplate::class,
     *       \App\Pdf\PayslipTemplate::class,
     *   ])
     */
    public function templates(iterable $templates): static
    {
        foreach ($templates as $template) {
            $this->templates[] = $template;
        }

        return $this;
    }

    /**
     * Install a global signatory resolver, taking precedence over each slot's
     * own `signatory` binding.
     *
     * Receives ($record, $slot) and may return null to defer back to the
     * slot's declared binding — so this is useful for special-casing one role
     * without having to reimplement the rest.
     *
     * Example — route everything through an org-chart service:
     *
     *   SignaturePlugin::make()->resolveSignatoriesUsing(
     *       fn ($record, $slot) => app(OrgChart::class)->holderOf($slot->role(), $record),
     *   )
     */
    public function resolveSignatoriesUsing(?Closure $callback): static
    {
        $this->signatoryResolver = $callback;

        return $this;
    }

    /**
     * Keep the "Awaiting my signature" inbox page off this panel. The page is
     * still routable; it just isn't registered here.
     */
    public function withoutInbox(): static
    {
        $this->registerInbox = false;

        return $this;
    }

    public function withInbox(bool $condition = true): static
    {
        $this->registerInbox = $condition;

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

        if ($this->templates !== []) {
            app(PdfTemplateRegistry::class)->registerMany($this->templates);
        }

        if ($this->signatoryResolver !== null) {
            app(SignatoryResolverFactory::class)->overrideUsing($this->signatoryResolver);
        }

        // Register the placement designer page so /signature-templates/{key}/design
        // resolves on this panel. The page itself has shouldRegisterNavigation = false;
        // host apps link to it from their own UI (e.g. a "Design layout" header action).
        $pages = [PdfTemplateDesigner::class, PdfTemplateSigner::class];

        // Unlike the other two, the inbox IS a navigation destination — it's
        // where a signatory finds the documents waiting on them.
        if ($this->registerInbox ?? config('signature.inbox.enabled', true)) {
            $pages[] = SignatureInbox::class;
        }

        $panel->pages($pages);

        FilamentAsset::register([
            Js::make('signature-plugin', __DIR__ . '/../resources/dist/digital-signature.js'),
        ], 'kukux/digital-signature');
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
