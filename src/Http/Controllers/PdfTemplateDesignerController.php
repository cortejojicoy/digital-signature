<?php

namespace Kukux\DigitalSignature\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Imagick;
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Contracts\RendersSamplePageImage;
use Kukux\DigitalSignature\Models\PdfTemplateSlot;
use Kukux\DigitalSignature\Pdf\PdfPageRasterizer;
use Kukux\DigitalSignature\Services\PdfTemplateRegistry;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * HTTP surface for the placement designer.
 *
 * Three responsibilities, kept thin:
 *  - `meta`  — page count + per-page PDF-point dimensions so the
 *              frontend can do the CSS-pixel → PDF-point coord math.
 *  - `page`  — serve a rasterized PNG of one page.
 *  - `save`  — persist coordinates for a slot.
 */
class PdfTemplateDesignerController extends Controller
{
    public function __construct(
        protected PdfTemplateRegistry $registry,
        protected PdfPageRasterizer $rasterizer,
    ) {
    }

    /**
     * Designer bootstrap payload — everything the frontend needs to render
     * the page strip and seed the slot boxes.
     */
    public function meta(string $template): JsonResponse
    {
        $tpl = $this->resolveTemplate($template);

        $pages = $this->resolvePageDimensions($tpl);

        $slots = collect($tpl->slots())->map(function ($slot) use ($tpl) {
            $saved = PdfTemplateSlot::query()
                ->where('template_key', $tpl->key())
                ->where('slot_key', $slot->key)
                ->first();

            $placement = $saved
                ? [
                    'page'   => $saved->page,
                    'x'      => $saved->x,
                    'y'      => $saved->y,
                    'width'  => $saved->width,
                    'height' => $saved->height,
                ]
                : ($slot->hasDefaultPlacement()
                    ? [
                        'page'   => $slot->defaultPage,
                        'x'      => $slot->defaultX,
                        'y'      => $slot->defaultY,
                        'width'  => $slot->defaultWidth,
                        'height' => $slot->defaultHeight,
                    ]
                    : null);

            return [
                'key'       => $slot->key,
                'label'     => $slot->label,
                'required'  => $slot->required,
                'placement' => $placement,
                'persisted' => $saved !== null,
            ];
        })->values()->all();

        return response()->json([
            'template' => [
                'key'   => $tpl->key(),
                'label' => $tpl->label(),
            ],
            'pages' => $pages,
            'slots' => $slots,
        ]);
    }

    /**
     * Stream a single rasterized page as PNG.
     */
    public function page(string $template, int $page): BinaryFileResponse
    {
        $tpl = $this->resolveTemplate($template);

        $imagePath = $tpl instanceof RendersSamplePageImage
            ? $tpl->renderSampleAsImage($page)
            : $this->rasterizer->renderPage($tpl->renderSample(), $page);

        return response()->file($imagePath, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Persist a slot's coordinates. Idempotent — upserts on
     * (template_key, slot_key).
     */
    public function save(Request $request, string $template, string $slot): JsonResponse
    {
        $tpl = $this->resolveTemplate($template);

        // Refuse unknown slot keys to keep the table consistent with the
        // template's declared slot set.
        $declared = collect($tpl->slots())->pluck('key')->all();

        if (! in_array($slot, $declared, true)) {
            throw ValidationException::withMessages([
                'slot' => ["Unknown slot [{$slot}] for template [{$tpl->key()}]"],
            ]);
        }

        $data = $request->validate([
            'page'   => ['required', 'integer', 'min:1'],
            'x'      => ['required', 'numeric', 'min:0'],
            'y'      => ['required', 'numeric', 'min:0'],
            'width'  => ['required', 'numeric', 'min:1'],
            'height' => ['required', 'numeric', 'min:1'],
        ]);

        $row = PdfTemplateSlot::updateOrCreate(
            ['template_key' => $tpl->key(), 'slot_key' => $slot],
            $data,
        );

        return response()->json(['saved' => true, 'id' => $row->id]);
    }

    protected function resolveTemplate(string $key): PdfTemplate
    {
        return $this->registry->find($key)
            ?? abort(404, "No PdfTemplate registered with key [{$key}]");
    }

    /**
     * Return per-page width/height in PDF points so the frontend can
     * convert CSS-pixel drag positions back to PDF coordinates.
     *
     * For Imagick-rasterized templates, page size in points =
     *   pixel_size × (72 / rasterization_dpi).
     *
     * For host-supplied images (RendersSamplePageImage), we can't know
     * the PDF page size from the image alone, so the frontend assumes
     * the image pixel dimensions ARE the PDF point dimensions. Hosts
     * that want true point coordinates should render at 72 DPI or
     * implement renderSample() so the rasterizer path is used.
     *
     * @return list<array{page: int, widthPt: float, heightPt: float, widthPx: int, heightPx: int}>
     */
    protected function resolvePageDimensions(PdfTemplate $tpl): array
    {
        if ($tpl instanceof RendersSamplePageImage) {
            $count = $tpl->sampleImagePageCount();
            $pages = [];
            for ($i = 1; $i <= $count; $i++) {
                [$wPx, $hPx] = getimagesize($tpl->renderSampleAsImage($i)) ?: [0, 0];
                $pages[] = [
                    'page'     => $i,
                    'widthPt'  => (float) $wPx,
                    'heightPt' => (float) $hPx,
                    'widthPx'  => (int) $wPx,
                    'heightPx' => (int) $hPx,
                ];
            }
            return $pages;
        }

        $pdfPath = $tpl->renderSample();
        $count   = $this->rasterizer->pageCount($pdfPath);
        $dpi     = $this->rasterizerDpi();

        $pages = [];
        for ($i = 1; $i <= $count; $i++) {
            $imagePath = $this->rasterizer->renderPage($pdfPath, $i);
            [$wPx, $hPx] = getimagesize($imagePath) ?: [0, 0];
            $pages[] = [
                'page'     => $i,
                'widthPt'  => round($wPx * 72 / $dpi, 2),
                'heightPt' => round($hPx * 72 / $dpi, 2),
                'widthPx'  => (int) $wPx,
                'heightPx' => (int) $hPx,
            ];
        }
        return $pages;
    }

    protected function rasterizerDpi(): int
    {
        // Mirrors the default in PdfPageRasterizer::__construct().
        return (int) config('signature.designer.dpi', 144);
    }
}
