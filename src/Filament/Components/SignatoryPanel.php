<?php

namespace Kukux\DigitalSignature\Filament\Components;

use Closure;
use Filament\Infolists\Components\Entry;
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Services\SignatoryRouter;
use Kukux\DigitalSignature\Signatories\SignatoryRoute;

/**
 * Drop-in "who signs this, and where are they up to?" block.
 *
 *   SignatoryPanel::make('signatories')
 *
 * is the whole host-side integration. Everything it renders comes from
 * SignatoryRouter, so the panel and the signing pipeline can never disagree
 * about who is blocking a document.
 *
 * No version split needed: Filament\Infolists\Components\Entry is the entry
 * base class on v3, v4 and v5 alike — only the *call site* differs
 * (Infolist::schema() on v3, Schema::components() on v4/v5), which is the
 * host's code, not ours.
 */
class SignatoryPanel extends Entry
{
    protected string $view = 'signature::components.signatory-panel';

    protected ?string $templateKey = null;

    /** @var array<int, string>|null */
    protected ?array $onlySlots = null;

    protected bool $showPlacement = false;

    protected bool|Closure $showAvatars = true;

    /** Set when routing threw, so the view can explain instead of rendering nothing. */
    protected ?string $routingError = null;

    public static function make(?string $name = null): static
    {
        return parent::make($name ?? 'signatories');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Signatories');
    }

    /**
     * Override the template key. Defaults to the record's own
     * signatureTemplateKey().
     */
    public function template(string $key): static
    {
        $this->templateKey = $key;

        return $this;
    }

    /**
     * Render only these slots — useful when a resource wants to show one
     * role in context rather than the whole block.
     *
     * @param  array<int, string>  $slots
     */
    public function only(array $slots): static
    {
        $this->onlySlots = $slots;

        return $this;
    }

    /**
     * Also show each slot's page and coordinates. Off by default: useful
     * while calibrating a template, noise for everyone else.
     */
    public function showPlacement(bool $condition = true): static
    {
        $this->showPlacement = $condition;

        return $this;
    }

    public function showAvatars(bool|Closure $condition = true): static
    {
        $this->showAvatars = $condition;

        return $this;
    }

    public function shouldShowPlacement(): bool
    {
        return $this->showPlacement;
    }

    public function shouldShowAvatars(): bool
    {
        return (bool) $this->evaluate($this->showAvatars);
    }

    /**
     * @return array<string, SignatoryRoute>
     */
    public function getRoutes(): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Model) {
            return [];
        }

        try {
            $routes = app(SignatoryRouter::class)->routeFor($record, $this->templateKey);
        } catch (\Throwable $e) {
            // A misconfigured template must not take down the whole page —
            // the view renders the message instead.
            report($e);
            $this->routingError = $e->getMessage();

            return [];
        }

        if ($this->onlySlots === null) {
            return $routes;
        }

        return array_filter(
            $routes,
            fn (string $key) => in_array($key, $this->onlySlots, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function getRoutingError(): ?string
    {
        return $this->routingError;
    }

    /**
     * The record's open session, if any — drives the "in progress" header.
     */
    public function getSession(): ?\Kukux\DigitalSignature\Models\SigningSession
    {
        $record = $this->getRecord();

        if (! $record instanceof Model) {
            return null;
        }

        return \Kukux\DigitalSignature\Models\SigningSession::query()
            ->forSignable($record)
            ->latest('id')
            ->first();
    }
}
