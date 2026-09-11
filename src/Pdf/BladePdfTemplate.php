<?php

namespace Kukux\DigitalSignature\Pdf;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Contracts\ConfiguresSigningSession;
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Pdf\Renderers\DomPdfRenderer;
use Kukux\DigitalSignature\Pdf\Renderers\PdfRenderer;

/**
 * Config-driven PdfTemplate implementation.
 *
 * The plug-and-play path: host apps describe a template in
 * config('signature.templates'), the service provider instantiates this
 * class with the array contents, and the registry treats it like any
 * other PdfTemplate. No subclass required.
 *
 * Schema accepted by fromConfig():
 *
 *   [
 *     'label'         => 'Daily Time Record',
 *     'view'          => 'pdf.dtr',
 *     'sample_data'   => [...] | fn () => [...],
 *     'data_resolver' => fn (Model $record) => [...],
 *     'slots'         => ['employee', 'in_charge']
 *                        // or
 *                        ['employee' => ['label' => 'Employee', 'required' => true]]
 *                        // or
 *                        [['key' => 'employee', 'label' => '...', 'required' => true]]
 *     'renderer'      => MyRenderer::class,   // optional override
 *   ]
 */
class BladePdfTemplate implements PdfTemplate, ConfiguresSigningSession
{
    /**
     * @param  list<SlotDefinition>          $slots
     * @param  array|callable                $sampleData
     * @param  Closure|null                  $dataResolver  fn(Model $record): array
     * @param  class-string<Model>|null      $signableClass Host-app model bound to this template.
     *                                                     Used by the signer flow to find the
     *                                                     specific record being signed via
     *                                                     `signableClass::find($id)`.
     */
    public function __construct(
        protected string $key,
        protected string $label,
        protected string $view,
        protected array $slots,
        protected $sampleData = [],
        protected ?Closure $dataResolver = null,
        protected ?PdfRenderer $renderer = null,
        protected ?string $signableClass = null,

        /**
         * How signatures get applied on this template's documents:
         *   'approval'  — every signatory signs in their own session (default)
         *   'delegated' — a standing SignatureDelegation may sign for them
         *   'implicit'  — tagging is treated as consent (unsafe; opt-in only)
         */
        protected ?string $autoAffixMode = null,

        /** 'sequential' (honour slot order) or 'parallel'. */
        protected ?string $sequenceMode = null,
    ) {
    }

    /**
     * Consent model for this template, falling back to the package default.
     * See docs/signatory-routing.md §"Consent models".
     */
    public function autoAffixMode(): string
    {
        return $this->autoAffixMode
            ?? config('signature.auto_affix.mode', 'approval');
    }

    /**
     * Whether signatories must sign in slot order.
     */
    public function sequenceMode(): string
    {
        return $this->sequenceMode
            ?? config('signature.sessions.sequence_mode', 'sequential');
    }

    /**
     * Class name of the host-app model this template renders. Returns
     * null when the config didn't declare one — in that case the signer
     * page can't auto-resolve a record from an id and must be passed
     * the fully-qualified type as well.
     *
     * @return class-string<Model>|null
     */
    public function getSignableClass(): ?string
    {
        return $this->signableClass;
    }

    /** @var class-string<PdfRenderer>|null  set when 'renderer' was in config */
    protected ?string $rendererClass = null;

    /**
     * Build an instance from a config array. The array key from
     * config('signature.templates') becomes the template key.
     *
     * Note: renderer detection is deferred to first render so a missing
     * PDF library doesn't break boot — only the eventual render call.
     */
    public static function fromConfig(string $key, array $config): self
    {
        if (empty($config['view'])) {
            throw new \InvalidArgumentException(
                "PdfTemplate config for [{$key}] is missing the required 'view' key."
            );
        }

        $instance = new self(
            key:           $key,
            label:         $config['label'] ?? Str::title(str_replace(['_', '-'], ' ', $key)),
            view:          $config['view'],
            slots:         static::normalizeSlots($config['slots'] ?? []),
            sampleData:    $config['sample_data']   ?? [],
            dataResolver:  $config['data_resolver'] ?? null,
            renderer:      null,
            signableClass: $config['signable'] ?? null,
            autoAffixMode: $config['auto_affix'] ?? null,
            sequenceMode:  $config['sequence_mode'] ?? null,
        );

        if (isset($config['renderer'])) {
            $instance->rendererClass = $config['renderer'];
        }

        return $instance;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return list<SlotDefinition> */
    public function slots(): array
    {
        return $this->slots;
    }

    public function renderSample(): string
    {
        $data = is_callable($this->sampleData)
            ? ($this->sampleData)()
            : (array) $this->sampleData;

        return $this->renderTo($data, 'sample');
    }

    public function renderFor(Model $record): string
    {
        $data = $this->dataResolver
            ? ($this->dataResolver)($record)
            : ['record' => $record];

        // Cache key includes the record's updated_at when available so
        // edits to the record invalidate the cached PDF automatically.
        $cacheKey = 'record-'.$record->getKey()
            .($record->updated_at ? '-'.$record->updated_at->timestamp : '');

        return $this->renderTo((array) $data, $cacheKey);
    }

    /**
     * Render the view + data with the configured renderer, caching the
     * resulting PDF under the configured signature disk so repeat calls
     * (and the rasterizer) reuse the same bytes.
     */
    protected function renderTo(array $data, string $cacheKey): string
    {
        $disk    = Storage::disk(config('signature.storage_disk', 'local'));
        $relPath = "generated/{$this->key}/{$cacheKey}.pdf";
        $absPath = $disk->path($relPath);

        if (! $disk->exists($relPath)) {
            $this->renderer()->render($this->view, $data, $absPath);
        }

        return $absPath;
    }

    /**
     * Resolve the renderer, preferring an explicit `renderer` config entry
     * over auto-detection. Resolution is deferred to first render so a
     * missing PDF library breaks the render, not application boot.
     */
    protected function renderer(): PdfRenderer
    {
        if ($this->renderer !== null) {
            return $this->renderer;
        }

        if ($this->rendererClass !== null) {
            $instance = app($this->rendererClass);

            if (! $instance instanceof PdfRenderer) {
                throw new \InvalidArgumentException(sprintf(
                    'The [renderer] configured for template [%s] must implement %s, got %s.',
                    $this->key,
                    PdfRenderer::class,
                    get_debug_type($instance),
                ));
            }

            return $this->renderer = $instance;
        }

        return $this->renderer = static::detectRenderer();
    }

    /**
     * Auto-detect a usable renderer. Order matters — DomPDF first
     * because it's the most common Laravel default. Custom renderers
     * should be passed in explicitly via the 'renderer' config key
     * rather than relying on detection.
     */
    protected static function detectRenderer(): PdfRenderer
    {
        if (DomPdfRenderer::isAvailable()) {
            return new DomPdfRenderer();
        }

        throw new \RuntimeException(
            'No PDF renderer is available. Install barryvdh/laravel-dompdf '
            .'with `composer require barryvdh/laravel-dompdf`, or pass a custom '
            .'renderer class via the [renderer] config key.'
        );
    }

    /**
     * Accept the three slot shapes documented at the class level and
     * normalize them to a list<SlotDefinition>.
     *
     * @return list<SlotDefinition>
     */
    protected static function normalizeSlots(array $raw): array
    {
        $result = [];

        foreach ($raw as $keyOrIndex => $value) {
            // Form 1: bare string ('employee')
            if (is_int($keyOrIndex) && is_string($value)) {
                $result[] = new SlotDefinition(
                    key:   $value,
                    label: Str::title(str_replace(['_', '-'], ' ', $value)),
                    // Declaration order is the signing order for the bare
                    // form — the only ordering signal it can carry.
                    order: $keyOrIndex + 1,
                );
                continue;
            }

            // Form 2: ['employee' => [...]]
            if (is_string($keyOrIndex) && is_array($value)) {
                $result[] = static::makeSlot($keyOrIndex, $value);
                continue;
            }

            // Form 3: [['key' => 'employee', 'label' => '...', ...]]
            if (is_int($keyOrIndex) && is_array($value) && isset($value['key'])) {
                $result[] = static::makeSlot($value['key'], $value);
                continue;
            }

            throw new \InvalidArgumentException(
                'Invalid slot entry — expected a string key or an associative array. '
                .'See BladePdfTemplate docs.',
            );
        }

        return $result;
    }

    protected static function makeSlot(string $key, array $config): SlotDefinition
    {
        return new SlotDefinition(
            key:           $key,
            label:         $config['label'] ?? Str::title(str_replace(['_', '-'], ' ', $key)),
            defaultPage:   $config['page']   ?? null,
            defaultX:      isset($config['x']) ? (float) $config['x'] : null,
            defaultY:      isset($config['y']) ? (float) $config['y'] : null,
            defaultWidth:  isset($config['width'])  ? (float) $config['width']  : null,
            defaultHeight: isset($config['height']) ? (float) $config['height'] : null,
            required:      (bool) ($config['required'] ?? false),
            signatory:     $config['signatory'] ?? null,
            role:          $config['role'] ?? null,
            order:         isset($config['order']) ? (int) $config['order'] : null,
        );
    }
}
