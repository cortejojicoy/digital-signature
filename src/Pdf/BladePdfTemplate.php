<?php

namespace Kukux\DigitalSignature\Pdf;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
class BladePdfTemplate implements PdfTemplate
{
    /**
     * @param  list<SlotDefinition>          $slots
     * @param  array|callable                $sampleData
     * @param  Closure|null                  $dataResolver  fn(Model $record): array
     */
    public function __construct(
        protected string $key,
        protected string $label,
        protected string $view,
        protected array $slots,
        protected $sampleData = [],
        protected ?Closure $dataResolver = null,
        protected ?PdfRenderer $renderer = null,
    ) {
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
            key:          $key,
            label:        $config['label'] ?? Str::title(str_replace(['_', '-'], ' ', $key)),
            view:         $config['view'],
            slots:        static::normalizeSlots($config['slots'] ?? []),
            sampleData:   $config['sample_data']   ?? [],
            dataResolver: $config['data_resolver'] ?? null,
            renderer:     null,
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

    protected function renderer(): PdfRenderer
    {
        return $this->renderer ??= static::detectRenderer();
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
        );
    }
}
