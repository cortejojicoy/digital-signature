# PDF Templates

A **PDF template** declares to the plugin that the host app produces a particular kind of PDF (DTR, payslip, contract, …) and which named regions on that PDF accept a signature. The plugin uses this declaration to:

- list registered templates in the admin UI ("apply this signature to a DTR"),
- persist per-slot placement coordinates per template (so admins place signature zones once, not per record),
- render a sample preview for the placement designer.

Templates are **additive**. The existing `Signable` flow ([Model Setup](model-setup.md), [Ad-hoc Signing](ad-hoc-signing.md), [On-Demand PDF Signing](on-demand-pdf-signing.md)) continues to work unchanged — templates simply give you a way to register, enumerate, and configure those signables centrally.

> **Status.** Steps 1, 4, and 5 (UI) are landed: the contract + registry + persistence (step 1), the placement designer (step 4), and the end-user signer page UI with stub finalize (step 5 — Phase A). Sign-time auto-resolution of slot coordinates inside `SignatureManager` (step 2) and the production finalize wiring (step 5 — Phase B) are next.

---

## When to use a template

| Scenario | Use `Signable` only | Add a `PdfTemplate` |
|---|:---:|:---:|
| One-off, ad-hoc document signing where the signer drags the signature into place each time | ✅ | – |
| Same PDF layout reused across many records (DTR, payslip, certificate) where the signature always goes in the same place | – | ✅ |
| You want named "drop zones" (e.g. *Employee* + *In Charge*) the designer can highlight as snap targets | – | ✅ |
| You want a central "PDF Templates" admin page listing what can be signed | – | ✅ |

A `PdfTemplate` and the underlying `Signable` model are not mutually exclusive — the template's `renderFor($record)` returns a PDF for a record that **already implements `Signable`**. Think of `PdfTemplate` as the *category* and `Signable` as the *instance*.

---

## The pieces

| File | What it is |
|---|---|
| [`Contracts/PdfTemplate`](../src/Contracts/PdfTemplate.php) | Interface the host app implements per kind of PDF |
| [`Pdf/SlotDefinition`](../src/Pdf/SlotDefinition.php) | Readonly value object describing one named signature zone |
| [`Models/PdfTemplateSlot`](../src/Models/PdfTemplateSlot.php) | Eloquent model — persisted (template, slot) coordinates |
| `digital_pdf_template_slots` table | Stores the saved x/y/width/height per slot per template |
| [`Services/PdfTemplateRegistry`](../src/Services/PdfTemplateRegistry.php) | Container singleton that holds all registered templates |

---

## Registering a template (plug-and-play)

The fastest path: drop an array into `config/signature.php` pointing at a Blade view you already have. No PHP class required.

```php
// config/signature.php
'templates' => [
    'dtr' => [
        'label'         => 'Daily Time Record',
        'view'          => 'pdf.dtr',  // your existing Blade
        'sample_data'   => ['user' => ['name' => 'Sample User']],
        'data_resolver' => fn ($record) => ['record' => $record],
        'slots'         => ['employee', 'in_charge'],
    ],
],
```

That's it. The array gets read on boot, instantiated into a `BladePdfTemplate`, and registered automatically. The plugin renders the Blade via `barryvdh/laravel-dompdf` (auto-detected); install it once if you don't have it:

```bash
composer require barryvdh/laravel-dompdf
```

### What the keys mean

| Key | Required | Type | What it does |
|---|---|---|---|
| `label` | no | string | Human-readable name shown in the designer / signer UI. Defaults to titlecased key. |
| `view` | **yes** | string | Blade view name (e.g. `pdf.dtr` for `resources/views/pdf/dtr.blade.php`). |
| `sample_data` | no | array \| callable | Data passed to the Blade when rendering the sample preview. Use a callable for expensive seed data. |
| `data_resolver` | no | `fn($record) => array` | Maps a record to the data the Blade needs at sign time. Defaults to `['record' => $record]`. |
| `slots` | no | array | Slot definitions — see the three accepted shapes below. |
| `renderer` | no | class-string | Custom `PdfRenderer` class if you don't want DomPDF. |

### Slot shapes

Three accepted shapes, mix freely:

```php
// 1) Bare keys — label auto-derived (Title Case of the key)
'slots' => ['employee', 'in_charge'],

// 2) Keyed associative entries — full control over each slot
'slots' => [
    'employee'  => ['label' => 'Employee', 'required' => true],
    'in_charge' => ['label' => 'In Charge', 'required' => true],
],

// 3) Numbered list of associative entries — useful when you want a stable order
'slots' => [
    ['key' => 'employee',  'label' => 'Employee',  'required' => true],
    ['key' => 'in_charge', 'label' => 'In Charge', 'required' => true],
],
```

Each slot may also carry an initial placement (`page`, `x`, `y`, `width`, `height` in PDF points) — they seed the placement designer the first time a slot is opened. Once an admin saves coordinates via the designer, the persisted row wins.

### Verifying it worked

After editing config and running `php artisan config:clear`:

```php
app(\Kukux\DigitalSignature\Services\PdfTemplateRegistry::class)->all();
// → ['dtr' => Kukux\DigitalSignature\Pdf\BladePdfTemplate { … }]
```

Then visit a signature's view page — the registered templates now appear as cards.

### Using a different PDF renderer

DomPDF is the default. To use Browsershot, Snappy, or anything else, implement [`PdfRenderer`](../src/Pdf/Renderers/PdfRenderer.php) once and reference it in the template config:

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

---

## Implementing a template (full class)

When you need more than the config form gives you — conditional slots, complex data resolution, multi-source sample data — implement the contract directly. Example for a DTR (Daily Time Record):

```php
namespace App\Pdf;

use App\Models\Dtr;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Pdf\SlotDefinition;

class DtrTemplate implements PdfTemplate
{
    public function key(): string
    {
        return 'dtr';
    }

    public function label(): string
    {
        return 'Daily Time Record';
    }

    /**
     * Named regions on the DTR PDF that can accept a signature.
     * Defaults are starting suggestions only — admins override via
     * the placement designer (or by writing to digital_pdf_template_slots).
     */
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

    /**
     * Sample PDF used to preview the layout in the designer.
     * Should be deterministic so the rasterized preview can be cached.
     */
    public function renderSample(): string
    {
        $disk = Storage::disk(config('signature.storage_disk'));
        $rel  = 'samples/dtr.pdf';

        if (! $disk->exists($rel)) {
            $disk->put($rel, app(DtrPdfRenderer::class)->renderBinarySample());
        }

        return $disk->path($rel);
    }

    /**
     * Render the production PDF for a specific record at sign time.
     * Same contract as Signable::getSignablePdfPath() — return an
     * absolute filesystem path the signer driver can read.
     */
    public function renderFor(Model $record): string
    {
        assert($record instanceof Dtr);

        $disk = Storage::disk(config('signature.storage_disk'));
        $rel  = "generated/dtr/{$record->getKey()}.pdf";

        if (! $disk->exists($rel)) {
            $disk->put($rel, app(DtrPdfRenderer::class)->renderBinary($record));
        }

        return $disk->path($rel);
    }
}
```

### Slot coordinates

- **Units are PDF points** (1 pt = 1/72 inch). US Letter landscape is 792 × 612 pt; A4 landscape is 842 × 595 pt.
- **`y` is measured from the bottom of the page**, not the top. This is PDF-native and the same convention the existing `signature_positions` table uses. A signature line near the bottom of the page typically sits around `y = 60`–`120`.
- **Defaults are seeds, not law.** They're only used when no row exists in `digital_pdf_template_slots` for that (template, slot) yet. Once an admin saves coordinates via the designer (step 4), the saved values win.

---

## Registration paths

Three places to register, all merge into the same registry. Each accepts the **plug-and-play array form**, **class strings**, or **PdfTemplate instances** — mix as you like.

### 1. Eager — via config

```php
// config/signature.php

'templates' => [
    // Plug-and-play (array form)
    'dtr' => [
        'view'  => 'pdf.dtr',
        'slots' => ['employee', 'in_charge'],
    ],

    // Full class (class-string form)
    \App\Pdf\PayslipTemplate::class,
],
```

Loaded by [`SignatureServiceProvider::boot()`](../src/SignatureServiceProvider.php). Survives across panels and CLI.

### 2. Fluent — on the Filament panel

```php
// app/Providers/Filament/AdminPanelProvider.php

->plugins([
    \Kukux\DigitalSignature\SignaturePlugin::make()
        ->templates([
            'dtr' => [
                'view'  => 'pdf.dtr',
                'slots' => ['employee', 'in_charge'],
            ],
            \App\Pdf\PayslipTemplate::class,
        ]),
])
```

Useful when registration should differ between panels (e.g. an HR panel only sees DTRs).

### 3. Runtime — from any service provider

```php
use Kukux\DigitalSignature\Services\PdfTemplateRegistry;

public function boot(): void
{
    $registry = app(PdfTemplateRegistry::class);

    // Array form via dedicated helper
    $registry->registerBlade('dtr', [
        'view'  => 'pdf.dtr',
        'slots' => ['employee', 'in_charge'],
    ]);

    // Class or instance
    $registry->register(\App\Pdf\PayslipTemplate::class);
}
```

Good for package integrations or conditional registration.

> Registration is **idempotent and keyed by the template key**. Registering the same key twice replaces, doesn't duplicate.

---

## Reading the registry

```php
use Kukux\DigitalSignature\Services\PdfTemplateRegistry;

$registry = app(PdfTemplateRegistry::class);

$registry->has('dtr');                 // bool
$registry->find('dtr');                // ?PdfTemplate (null if missing)
$registry->get('dtr');                 // PdfTemplate (throws if missing)
$registry->all();                      // array<string, PdfTemplate>
```

`get()` is the version to use when the key came from a trusted source (e.g. a `template_key` row from `digital_pdf_template_slots`). `find()` is for user input / nullable lookups.

---

## The placement designer

The plugin ships an interactive designer that renders the template's sample PDF and lets an admin drag/resize each slot onto the page. Saves are persisted to `digital_pdf_template_slots`, so once you've placed a slot it sticks for every future record of that template.

### Opening the designer

The Filament page is registered automatically by `SignaturePlugin` when the plugin is added to a panel. The slug pattern is:

```
/<panel-path>/signature-templates/{templateKey}/design
```

For a panel mounted at `/admin` and a template with `key() === 'dtr'`, that's:

```
/admin/signature-templates/dtr/design
```

The page is **not** added to the sidebar — link to it from your own UI. Typical entry points:

- A "Design layout" header action on the host app's `DtrResource`
- A link inside the signature library card that opens this designer for the related template

```php
use Filament\Actions\Action;

Action::make('design_layout')
    ->label('Design layout')
    ->icon('heroicon-o-rectangle-group')
    ->url(fn () => route('filament.admin.pages.signature-templates.{template-key}.design', [
        'templateKey' => 'dtr',
    ]))
    ->openUrlInNewTab(false);
```

### How it works under the hood

```
Browser                                                     Backend
┌────────────────────────────────────┐                     ┌──────────────────────────┐
│ PdfDesignerIsland (React)          │   GET /meta         │ DesignerController::meta │
│  ├─ fetches meta + saved slots ────┼────────────────────►│  - reads PdfTemplate     │
│  ├─ renders <img src=page/1>       │   GET /pages/1      │  - reads PdfTemplateSlot │
│  ├─ overlays SlotBox per slot      │◄────────────────────┤  - returns PDF point     │
│  ├─ user drags / resizes / nudges  │   PNG               │    dimensions per page   │
│  └─ Save → POST /slots/{slot} ─────┼────────────────────►│ DesignerController::page │
└────────────────────────────────────┘                     │  - rasterizes via Imagick│
                                                           │    (or RendersSample…)   │
                                                           ├──────────────────────────┤
                                                           │ DesignerController::save │
                                                           │  - upsert by             │
                                                           │    (template_key, slot)  │
                                                           └──────────────────────────┘
```

The React island handles the CSS-pixel ↔ PDF-point math (including the y-axis flip) so your slot definitions and saved rows are always in PDF coordinates ready for the signer driver.

### Imagick prerequisite (or an alternative)

By default the designer rasterizes the sample PDF via **Imagick + Ghostscript**. If your host doesn't have those, implement [`RendersSamplePageImage`](../src/Contracts/RendersSamplePageImage.php) on your template:

```php
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Contracts\RendersSamplePageImage;

class DtrTemplate implements PdfTemplate, RendersSamplePageImage
{
    // ... existing PdfTemplate methods ...

    public function renderSampleAsImage(int $page): string
    {
        // Return an absolute path to a PNG/JPEG of $page.
        // For DomPDF / Spatie Browsershot / Snappy users: render directly to PNG.
        return app(DtrPdfRenderer::class)->renderPageAsPng(page: $page);
    }

    public function sampleImagePageCount(): int
    {
        return 1;
    }
}
```

When a template implements `RendersSamplePageImage`, the controller skips the rasterizer and uses your image directly. **Note**: in that mode the plugin assumes image pixel dimensions equal PDF point dimensions, so render at 72 DPI for accurate placement (or stick with the Imagick path).

### Designer DPI

The Imagick render DPI is configurable:

```bash
SIGNATURE_DESIGNER_DPI=144   # default; raise to 200+ for crisp big monitors
```

```php
// config/signature.php
'designer' => [
    'dpi' => env('SIGNATURE_DESIGNER_DPI', 144),
],
```

Higher DPI = sharper preview but slower first render and larger cache files. The cache is keyed by `(pdf path, mtime, dpi)` so changing DPI invalidates cleanly.

### Seeding slots without the designer

You can still write coordinates directly from a seeder or Tinker:

```php
use Kukux\DigitalSignature\Models\PdfTemplateSlot;

PdfTemplateSlot::updateOrCreate(
    ['template_key' => 'dtr', 'slot_key' => 'employee'],
    ['page' => 1, 'x' => 135, 'y' => 90, 'width' => 200, 'height' => 40],
);
```

The `SlotDefinition::$defaultX/Y/...` fields still serve as the initial position the designer seeds when no saved row exists yet.

---

## The signer page

In addition to the admin designer, the plugin ships an end-user **signer page** — the SignFlow-style view where a user drops their signature onto a target PDF and clicks "Finish & Save".

### URL pattern

```
/<panel-path>/signature-templates/{templateKey}/sign/{signatureUuid}
```

The route is built for you from the Signature view page's card grid (`view-signature-with-templates.blade.php`). Each card's body click navigates to the signer with that signature's UUID pre-bound to the URL.

### What the page does

```
PdfSigningIsland (React)
├─ Top bar:   Template label    [Cancel]  [Finish & Save]
├─ Canvas:    Rasterized PDF page with SlotBox overlays
│             ├─ Active signature image is rendered inside each placed slot
│             └─ Page selector when the template has > 1 page
├─ Slot picker (chips below the canvas):
│             ├─ "+ Employee", "+ In Charge", … one chip per declared slot
│             └─ Click adds the slot at the canvas center; click again removes
└─ Bottom strip: Your stored signatures
              ├─ Active signature highlighted with primary ring
              └─ Up to 12 other primary signatures (chip = click to swap)
```

The frontend talks to two endpoints, both prefixed by signer/:

| Method | Route name | Purpose |
|---|---|---|
| GET  | `signature.pdf-templates.signer.meta`      | Bootstrap: template + page dims + saved slots + user's signature library |
| POST | `signature.pdf-templates.signer.finalize`  | Submit chosen placements + finalize signing |

### Phase A: stub finalize

The `finalize` endpoint currently validates the payload structure and slot keys, then returns an acknowledgement without producing a signed PDF. This lets you exercise the entire UI loop (open card → place → Finish & Save → success view) before the cryptographic pipeline wires in.

The acknowledgement view shows the validated placements back to the user as JSON — useful while iterating on the UX.

### Phase B (next): production finalize

The remaining work is wiring `PdfTemplateSignerController::finalize` to the existing signing pipeline. The shape we'll move to:

1. Accept a target Signable from the request (`signable_type` + `signable_id`, validated against the host app's `Signable` contract).
2. Render the target PDF via `$template->renderFor($record)`.
3. For each placement, call `SignatureManager::storeForDocument()` with the slot's coordinates.
4. Run `SignatureManager::embedAndFinalize()` to embed the signature image, the PKCS#7 envelope, and the DocMDP marker.
5. Return the signed-document URL so the UI can offer a download / redirect.

The signature image and certificate password already live on the source primary signature, so the signer doesn't need to re-enter anything at sign time.

---

## What's still ahead

- **Step 2** — `SignatureManager` populating `SignaturePosition` from `digital_pdf_template_slots` automatically when a Signable maps to a registered template. Until this lands you can read the slot row yourself in your action and pass coordinates to `SignatureManager::store(...)`.
- **Step 5 — Phase B** — the production finalize wiring described above.

You can already use the system today to:

- declare templates and slot definitions,
- open the designer for any registered template,
- have admins place slots visually and persist them,
- open the signer page for any registered template + owned signature (Phase A stub),
- read coordinates from your own code to drive `SignatureManager::store(...)`.

---

## Related

- [Model Setup](model-setup.md) — `Signable` contract and `HasSignatures` trait
- [On-Demand PDF Signing](on-demand-pdf-signing.md) — when the PDF is generated, not stored
- [Signing Workflow](signing-workflow.md) — `SignatureManager` API, events, statuses
- [Filament Components](filament-components.md) — `SignaturePlugin`, fluent registration API
