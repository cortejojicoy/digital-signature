<?php

namespace Kukux\DigitalSignature\Services;

use Kukux\DigitalSignature\Contracts\PdfTemplate;

/**
 * Holds the set of PdfTemplate implementations the host app has registered.
 *
 * Templates can be added in three places:
 *  - `config('signature.templates')` — eager class list, loaded by the
 *    service provider on boot
 *  - `SignaturePlugin::make()->templates([...])` — fluent registration on
 *    the Filament panel
 *  - `app(PdfTemplateRegistry::class)->register(...)` — runtime / package
 *    integration
 *
 * Registration is keyed by the template's `key()` so duplicate registration
 * is idempotent and the last registration wins. Lookups are O(1).
 */
class PdfTemplateRegistry
{
    /** @var array<string, PdfTemplate> */
    protected array $templates = [];

    /**
     * Register a template instance or class name. Class names are resolved
     * through the container so dependencies can be injected.
     *
     * @param PdfTemplate|class-string<PdfTemplate> $template
     */
    public function register(PdfTemplate|string $template): static
    {
        $instance = is_string($template) ? app($template) : $template;

        if (! $instance instanceof PdfTemplate) {
            throw new \InvalidArgumentException(
                sprintf('Registered template must implement %s', PdfTemplate::class),
            );
        }

        $this->templates[$instance->key()] = $instance;

        return $this;
    }

    /**
     * Register many at once. Accepts a mixed array of instances and class names.
     *
     * @param iterable<PdfTemplate|class-string<PdfTemplate>> $templates
     */
    public function registerMany(iterable $templates): static
    {
        foreach ($templates as $template) {
            $this->register($template);
        }

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->templates[$key]);
    }

    public function find(string $key): ?PdfTemplate
    {
        return $this->templates[$key] ?? null;
    }

    /**
     * Like find() but throws when missing — for code paths where the
     * caller has already validated the key (e.g. trusted DB rows).
     */
    public function get(string $key): PdfTemplate
    {
        return $this->find($key)
            ?? throw new \RuntimeException("No PdfTemplate registered with key [{$key}]");
    }

    /**
     * @return array<string, PdfTemplate>
     */
    public function all(): array
    {
        return $this->templates;
    }
}
