<?php

namespace Kukux\DigitalSignature\Services;

use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Pdf\BladePdfTemplate;

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
     * Register a config-driven Blade template. Internally builds a
     * BladePdfTemplate from the array — see that class's docblock for the
     * accepted schema. This is the plug-and-play registration path used
     * by config('signature.templates') entries that are arrays.
     *
     * @param  array<string, mixed>  $config
     */
    public function registerBlade(string $key, array $config): static
    {
        return $this->register(BladePdfTemplate::fromConfig($key, $config));
    }

    /**
     * Register many at once. Mixed input is supported:
     *
     *  - PdfTemplate instance               → registered directly
     *  - class-string<PdfTemplate>          → resolved via container
     *  - ['key' => 'dtr', ...config...]     → built via BladePdfTemplate::fromConfig
     *  - 'key' => [...config...]            → built via BladePdfTemplate::fromConfig
     *
     * @param iterable<PdfTemplate|class-string<PdfTemplate>|array<string, mixed>> $templates
     */
    public function registerMany(iterable $templates): static
    {
        foreach ($templates as $key => $template) {
            // Associative array (string key) → array-form Blade template
            if (is_string($key) && is_array($template)) {
                $this->registerBlade($key, $template);
                continue;
            }

            // Array without a string key but with an inline 'key' field
            if (is_array($template) && isset($template['key'])) {
                $this->registerBlade($template['key'], $template);
                continue;
            }

            // Instance or class name — original path
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
