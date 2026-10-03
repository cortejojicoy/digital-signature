# PDF Templates

A **PDF template** tells the plugin "my app makes this kind of PDF (DTR, payslip, contract...), and signatures go in these spots."

You get a placement designer where an admin positions each signature zone once for every record, and a signer page where users drop their signature and get a signed PDF back.

Use one when the same layout repeats across many records. For one-off documents where the signer places the signature each time, plain `Signable` ([Model Setup](model-setup.md), [Ad-hoc Signing](ad-hoc-signing.md)) is enough. To route slots to specific people, see [Signatory Routing](signatory-routing.md).

| Piece | What it is |
|---|---|
| [`Contracts/PdfTemplate`](../src/Contracts/PdfTemplate.php) | Interface, one per kind of PDF |
| [`Pdf/BladePdfTemplate`](../src/Pdf/BladePdfTemplate.php) | Ready-made implementation built from a config array |
| [`Pdf/SlotDefinition`](../src/Pdf/SlotDefinition.php) | One named signature zone |
| [`Models/PdfTemplateSlot`](../src/Models/PdfTemplateSlot.php) | Saved coordinates per (template, slot) in `digital_pdf_template_slots` |
| [`Services/PdfTemplateRegistry`](../src/Services/PdfTemplateRegistry.php) | Singleton holding every registered template |

---

## Registering a template (plug-and-play)

Point a config entry at a Blade view you already have. No class needed.

```php
// config/signature.php
'templates' => [
    'dtr' => [
        'label'         => 'Daily Time Record',
        'view'          => 'pdf.dtr',  // your existing Blade
        'sample_data'   => ['user' => ['name' => 'Sample User']],
        'data_resolver' => fn ($record) => ['record' => $record],
        'slots'         => ['employee', 'in_charge'],
        'signable'      => \App\Models\Dtr::class,
    ],
],
```

It renders with DomPDF, so install it if you haven't:

```bash
composer require barryvdh/laravel-dompdf
```

> **Heads up:** the closure in `data_resolver` breaks `php artisan config:cache`.
> That's fine for local tinkering. For production, move the template into a
> [full class](#implementing-a-template-full-class) and list the class-string
> in config instead.

If your Blade only needs `$record`, leave `data_resolver` out. The default is `['record' => $record]`, no closure.

| Key | Default | What it does |
|---|---|---|
| `view` | required | Blade view name. Missing it throws `InvalidArgumentException`. |
| `label` | key in Title Case | Name shown in the UI |
| `slots` | `[]` | Signature zones, see below |
| `sample_data` | `[]` | Array or callable, used for the designer preview |
| `data_resolver` | `['record' => $record]` | `fn ($record) => array` for the real render |
| `signable` | none | Model class the signer page loads for `?signable=ID`. Needed for real signing there. |
| `renderer` | DomPDF | A custom [`PdfRenderer`](#using-a-different-pdf-renderer) class |
| `auto_affix` | `signature.auto_affix.mode` | `approval`, `delegated` or `implicit`, see [Signatory Routing](signatory-routing.md) |
| `sequence_mode` | `signature.sessions.sequence_mode` | `sequential` or `parallel` |

PDFs are cached on `signature.storage_disk` under `generated/{key}/`. The cache key includes the record's `updated_at`, so edits re-render.

### Slot shapes

Mix freely:

```php
// 1) Bare keys — label is the key in Title Case
'slots' => ['employee', 'in_charge'],

// 2) Keyed entries
'slots' => [
    'employee'  => ['label' => 'Employee', 'required' => true],
    'in_charge' => ['label' => 'In Charge', 'required' => true],
],

// 3) List of entries with a 'key' field
'slots' => [
    ['key' => 'employee',  'label' => 'Employee',  'required' => true],
    ['key' => 'in_charge', 'label' => 'In Charge', 'required' => true],
],
```

An entry can also take:

- `page`, `x`, `y`, `width`, `height`: starting position for the designer. A saved position wins.
- `signatory`, `role`, `order`: bind the slot to a person. See [Signatory Routing](signatory-routing.md).

Bare keys get `order` 1, 2, ... from their position. The other shapes only have one if you set it.

To check it worked, run `php artisan config:clear`, then `app(PdfTemplateRegistry::class)->all()` should list `'dtr'`. The template also shows up as a card under **Apply this signature** in the launcher drawer's **Manage signatures**.

### Using a different PDF renderer

For Browsershot, Snappy or anything else, implement [`PdfRenderer`](../src/Pdf/Renderers/PdfRenderer.php) and point the template at it:

```php
// app/Pdf/BrowsershotRenderer.php
use Kukux\DigitalSignature\Pdf\Renderers\PdfRenderer;

class BrowsershotRenderer implements PdfRenderer
{
    public function render(string $view, array $data, string $destinationPath): string
    {
        \Spatie\LaravelPdf\Facades\Pdf::view($view, $data)->save($destinationPath);
        return $destinationPath;
    }

    public static function isAvailable(): bool
    {
        return class_exists(\Spatie\LaravelPdf\Facades\Pdf::class);
    }
}

// config/signature.php
'templates' => [
    'dtr' => [
        'view'     => 'pdf.dtr',
        'renderer' => \App\Pdf\BrowsershotRenderer::class,
        // …
    ],
],
```

### Pin the paper size

Slots are stored as absolute PDF points, so the page size has to stay put.
The stock DomPDF renderer uses whatever `dompdf.default_paper_size` is. Publish
`config/dompdf.php` with `letter` someday, and every calibrated signature
moves. Pin it on the template:

```php
// a template class
use Kukux\DigitalSignature\Pdf\Renderers\DomPdfRenderer;

parent::__construct(
    key: 'dtr',
    // …
    renderer: new DomPdfRenderer(paper: 'legal'),          // or paper: 'a4', orientation: 'landscape'
);
```

```php
// or a config-array template
'templates' => [
    'dtr' => [
        'view'  => 'pdf.dtr',
        'paper' => 'legal',
        // 'orientation' => 'landscape',
    ],
],
```

A renderer of your own that still uses DomPDF records slot positions with the
same two `SlotAnchors` calls the stock one makes:

```php
$pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($view, $data)->setPaper('a4');

$anchors = \Kukux\DigitalSignature\Pdf\SlotAnchors::record($pdf->getDomPDF());
file_put_contents($destinationPath, $pdf->output());
\Kukux\DigitalSignature\Pdf\SlotAnchors::write($destinationPath, $anchors());
```

Use the same paper size as any non-signing preview or download of the document,
so the signed copy looks the same as the one people already print.

### Documents that run to any number of pages

A designer placement says "page 1, 130pt up". That's fine for a form that never moves. A report that lists tasks doesn't work that way: with 3 tasks the signature block is on page 1, with 300 it's on page 40.

So mark each signature space in the Blade, and the package finds it in every document:

```blade
<p>Prepared By:</p>
<div data-signature-slot="prepared_by" style="height: 56px"></div>
<p class="signature-line">{{ $preparer->name }}</p>
```

- **How it works:** while DomPDF draws the PDF, the renderer records the page and box of every `data-signature-slot` element (`SlotAnchors`). It saves them next to the PDF as `<file>.slots.json`. When a signing session opens, each request gets the box from that document, on whatever page it landed.
- **The element is the stamp box.** Give it the height you want the signature to take; its width comes from the layout.
- **Which renderers do it:** the stock `DomPdfRenderer` does it for you, at any paper size. A custom DomPDF renderer needs the two `SlotAnchors` calls shown above. Other renderers (Browsershot, Snappy) don't record anchors, so their templates use the designer's placement.
- **Fallback:** a slot with no marker, or one that didn't render, uses the designer's placement, then its default.
- **Sample and designer:** the sample still drives the designer's preview. Keep a default or designer placement on each slot; the readiness checks still look for one.

A template class can supply placements some other way by implementing `Kukux\DigitalSignature\Contracts\PlacesSlotsInDocument`. `BladePdfTemplate` already does, by reading those anchors.

---

## Implementing a template (full class)

Use a class for conditional slots, complex data, or `config:cache`. `renderSample()` and `renderFor()` both return an absolute file path.

> **Prefer extending `BladePdfTemplate`** (as the [reference integration](integration/reference-integration.md#3-the-template) does) over implementing `PdfTemplate` from scratch. You keep the constructor's named arguments and get two things for free: `data-signature-slot` markers (`PlacesSlotsInDocument`), and a render cache that refreshes when the record changes. Implement the contract yourself only when the rendering isn't a Blade view at all.

```php
namespace App\Pdf;

use App\Models\Dtr;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Pdf\SlotDefinition;

class DtrTemplate implements PdfTemplate
{
    // Stored as template_key. Don't change it once rows exist.
    public function key(): string
    {
        return 'dtr';
    }

    public function label(): string
    {
        return 'Daily Time Record';
    }

    public function slots(): array
    {
        return [
            new SlotDefinition(
                key:           'employee',
                label:         'Employee',
                defaultPage:   1,
                defaultX:      135,
                defaultY:      90,
                defaultWidth:  200,
                defaultHeight: 40,
                required:      true,
            ),
            new SlotDefinition(
                key:           'in_charge',
                label:         'In Charge',
                defaultPage:   1,
                defaultX:      520,
                defaultY:      90,
                defaultWidth:  200,
                defaultHeight: 40,
                required:      true,
            ),
        ];
    }

    // Designer preview. Keep it deterministic so page images cache.
    public function renderSample(): string
    {
        $disk = Storage::disk(config('signature.storage_disk'));
        $rel  = 'samples/dtr.pdf';

        if (! $disk->exists($rel)) {
            $disk->put($rel, app(DtrPdfRenderer::class)->renderBinarySample());
        }

        return $disk->path($rel);
    }

    // The real PDF for one record, at sign time.
    public function renderFor(Model $record): string
    {
        assert($record instanceof Dtr);

        $disk = Storage::disk(config('signature.storage_disk'));

        // Keyed on updated_at too, so an edited record isn't served a stale render.
        $rel  = "generated/dtr/{$record->getKey()}-{$record->updated_at?->timestamp}.pdf";

        if (! $disk->exists($rel)) {
            $disk->put($rel, app(DtrPdfRenderer::class)->renderBinary($record));
        }

        return $disk->path($rel);
    }
}
```

`SlotDefinition` also takes `signatory`, `role` and `order`.

This class doesn't implement `PlacesSlotsInDocument`, so `data-signature-slot` markers in its view are ignored and every signature goes where the designer or the defaults put it. Fine for a fixed form like a DTR; for a document whose length varies, extend `BladePdfTemplate` or implement `documentPlacements()` yourself.

> **Gotcha:** the signer page's `?signable=ID` flow only works for config templates with a `signable` key. A full-class template returns a 422 there. Sign it through a [signing session](signatory-routing.md) or `SignatureManager` instead.

### Slot coordinates

- Units are **PDF points** (1/72 inch). US Letter landscape is 792 × 612, A4 landscape is 842 × 595.
- **`y` counts up from the bottom.** A signature line near the bottom sits around `y = 60`–`120`.
- Defaults only apply until an admin saves the slot in the designer.
- For a document whose length varies, mark the slots in the Blade instead. See [Documents that run to any number of pages](#documents-that-run-to-any-number-of-pages).

---

## Registration paths

All three feed the same registry and take the array form, a class-string or an instance. Registering a key again replaces it.

```php
// 1. config/signature.php — every panel and the CLI
'templates' => [
    'dtr' => ['view' => 'pdf.dtr', 'slots' => ['employee', 'in_charge']],
    \App\Pdf\PayslipTemplate::class,
],

// 2. A Filament panel — when panels should see different templates
\Kukux\DigitalSignature\SignaturePlugin::make()
    ->templates([
        'dtr' => ['view' => 'pdf.dtr', 'slots' => ['employee', 'in_charge']],
        \App\Pdf\PayslipTemplate::class,
    ]),

// 3. Any service provider's boot()
$registry = app(\Kukux\DigitalSignature\Services\PdfTemplateRegistry::class);
$registry->registerBlade('dtr', ['view' => 'pdf.dtr', 'slots' => ['employee', 'in_charge']]);
$registry->register(\App\Pdf\PayslipTemplate::class);
// or $registry->registerMany([...]) with the same mixed list as config
```

## Reading the registry

```php
$registry->has('dtr');   // bool
$registry->find('dtr');  // ?PdfTemplate — use for user input
$registry->get('dtr');   // PdfTemplate, throws RuntimeException if missing
$registry->all();        // array<string, PdfTemplate>
```

---

## The placement designer

The designer shows the sample PDF and lets an admin drag and resize each slot. Saves upsert a `digital_pdf_template_slots` row by `(template_key, slot_key)`, always in PDF points.

It lives at `/<panel-path>/signature-templates/{templateKey}/design`. It's not in the sidebar, so link to it:

```php
use Filament\Actions\Action;
use Kukux\DigitalSignature\Filament\Pages\PdfTemplateDesigner;

Action::make('design_layout')
    ->label('Design layout')
    ->icon('heroicon-o-rectangle-group')
    ->url(fn () => PdfTemplateDesigner::getUrl(['templateKey' => 'dtr']));
```

### No Imagick? Supply your own page images

Previews need **Imagick + Ghostscript**. Without them, implement [`RendersSamplePageImage`](../src/Contracts/RendersSamplePageImage.php):

```php
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Contracts\RendersSamplePageImage;

class DtrTemplate implements PdfTemplate, RendersSamplePageImage
{
    // ... existing PdfTemplate methods ...

    public function renderSampleAsImage(int $page): string
    {
        // Absolute path to a PNG/JPEG of $page (1-indexed).
        return app(DtrPdfRenderer::class)->renderPageAsPng(page: $page);
    }

    public function sampleImagePageCount(): int
    {
        return 1;
    }
}
```

**Render at 72 DPI.** In this mode 1 image pixel counts as 1 PDF point, so any other DPI misplaces slots.

### Designer DPI

```php
// config/signature.php
'designer' => [
    'dpi' => env('SIGNATURE_DESIGNER_DPI', 144),
],
```

Raise it (200+) for sharper previews at the cost of speed and cache size. Changing it just re-renders.

### Seeding slots without the designer

```php
use Kukux\DigitalSignature\Models\PdfTemplateSlot;

PdfTemplateSlot::updateOrCreate(
    ['template_key' => 'dtr', 'slot_key' => 'employee'],
    ['page' => 1, 'x' => 135, 'y' => 90, 'width' => 200, 'height' => 40],
);
```

---

## The signer page

Users drop a signature on the PDF and click **Finish & Save**. It lives at:

```
/<panel-path>/signature-templates/{templateKey}/sign/{signatureUuid}
```

The page shows one chip per slot, plus the active signature and up to 12 of your other primary signatures to swap between.

It uses `signature.pdf-templates.signer.meta` (GET) and `signature.pdf-templates.signer.finalize` (POST).

### What Finish & Save does

| URL has | Result |
|---|---|
| nothing | Validates and echoes the placements with `status: 'ack'`. Nothing is signed. Handy for calibrating. |
| `?signable=ID` | Signs that record for real (below). |
| `?request=ID` | Signs a slot in a signing session. The inbox adds this for you. See [Signatory Routing](signatory-routing.md). |

With `?signable=ID` it:

1. Loads the record via the template's `signable` class. No `signable` key or a non-`Signable` model gives a 422, a missing record a 404.
2. Calls `SignatureManager::storeForDocument()` with the placement, then `embedAndFinalize()` to stamp, embed PKCS#7 + DocMDP and write the signed PDF.
3. Returns `{ status: 'signed', signature_uuid, signed_document_path, signed_at }`.

The certificate password comes from the stored signature, so the user isn't asked. If none is stored you get a 422, and the signature has to be re-created with a password.

**One placement per call.** Sending more returns a 422. For several signatures on one document, use a signing session ([Signatory Routing](signatory-routing.md)).

### Making your model signable in 2 lines

```php
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Concerns\HasPdfTemplate;
use Kukux\DigitalSignature\Contracts\Signable;

class Dtr extends Model implements Signable
{
    use HasPdfTemplate;

    protected string $signaturePdfTemplate = 'dtr';   // matches the template key
}
```

The trait implements `getSignableTitle()` (`"Dtr #<id>"`), `getSignablePdfPath()` (the template's `renderFor($this)`), `getSignableId()` and `signatureTemplateKey()`. Override any you like. Forgetting `$signaturePdfTemplate` throws a `LogicException`.

### Link to the signer from your resource

```php
use Filament\Actions\Action;
use Kukux\DigitalSignature\Filament\Pages\PdfTemplateSigner;
use Kukux\DigitalSignature\Models\Signature;

Action::make('sign_with_my_signature')
    ->label('Sign')
    ->icon('heroicon-o-pencil-square')
    ->url(function ($record) {
        $signature = Signature::query()
            ->where('user_id', auth()->id())
            ->whereNull('signable_id')
            ->where('status', 'active')
            ->latest('id')
            ->firstOrFail();

        return PdfTemplateSigner::getUrl([
            'templateKey'   => 'dtr',
            'signatureUuid' => $signature->uuid,
        ]).'?signable='.$record->getKey();
    });
```

Not built yet: true PAdES incremental signing (several independently verifiable certificates in one PDF). It needs a driver implementing [`SupportsIncrementalSigning`](../src/Contracts/SupportsIncrementalSigning.php), and neither bundled driver does. See [Signing modes](signatory-routing.md#signing-modes--read-this-before-choosing).

---

## Related

- [Model Setup](model-setup.md) — `Signable` contract and `HasSignatures` trait
- [On-Demand PDF Signing](on-demand-pdf-signing.md) — when the PDF is generated, not stored
- [Signing Workflow](signing-workflow.md) — `SignatureManager` API, events, statuses
- [Filament Components](filament-components.md) — `SignaturePlugin`, fluent registration API
- [Signatory Routing](signatory-routing.md) — binding slots to people, signing sessions, consent models
