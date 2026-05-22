<?php

namespace Kukux\DigitalSignature\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Contracts\RendersSamplePageImage;
use Kukux\DigitalSignature\Models\PdfTemplateSlot;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Pdf\PdfPageRasterizer;
use Kukux\DigitalSignature\Services\PdfTemplateRegistry;

/**
 * HTTP surface for the end-user signing experience.
 *
 * Two endpoints, both scoped to (template, signature) so the URL
 * itself encodes who is signing what with what:
 *
 *   GET  meta      — page dimensions + saved slot placements + the
 *                    current user's primary signatures (the bottom strip
 *                    of "your stored signatures")
 *   POST finalize  — accepts the user's chosen placements and (in
 *                    Phase B) hands them to SignatureManager for the
 *                    actual PKCS#7 + DocMDP signing pipeline
 *
 * Phase A (current): finalize is a stub that echoes the payload back
 * so the UI loop can be validated end-to-end without touching the
 * cryptographic pipeline. Phase B wires SignatureManager::storeForDocument
 * + embedAndFinalize once Signable selection is decided.
 */
class PdfTemplateSignerController extends Controller
{
    public function __construct(
        protected PdfTemplateRegistry $registry,
        protected PdfPageRasterizer $rasterizer,
    ) {
    }

    public function meta(string $template, string $signature): JsonResponse
    {
        $tpl = $this->resolveTemplate($template);
        $sig = $this->resolveSignature($signature);

        $pages = $this->resolvePageDimensions($tpl);
        $slots = $this->resolveSlots($tpl);

        return response()->json([
            'template' => [
                'key'   => $tpl->key(),
                'label' => $tpl->label(),
            ],
            'signature' => [
                'uuid'        => $sig->uuid,
                'previewUrl'  => $sig->getTemporaryImageUrl(60),
                'signerName'  => optional($sig->user)->name,
                'signerEmail' => optional($sig->user)->email,
            ],
            // All of this user's other primary signatures, for the bottom
            // strip's "your stored signatures" library. Excludes the active
            // one so the user can swap if they want a different signature.
            'library' => Signature::query()
                ->where('user_id', auth()->id())
                ->whereNull('signable_id')
                ->where('status', '!=', 'revoked')
                ->where('uuid', '!=', $sig->uuid)
                ->latest('id')
                ->limit(12)
                ->get()
                ->map(fn (Signature $s) => [
                    'uuid'       => $s->uuid,
                    'previewUrl' => $s->getTemporaryImageUrl(60),
                    'source'     => $s->source,
                ])
                ->all(),
            'pages' => $pages,
            'slots' => $slots,
        ]);
    }

    /**
     * Phase A stub. Validates structure and returns an ack so the
     * frontend can confirm the round-trip works. The actual signing
     * call into SignatureManager arrives in Phase B once the host-app
     * Signable selection mechanism is in place.
     */
    public function finalize(Request $request, string $template, string $signature): JsonResponse
    {
        $tpl = $this->resolveTemplate($template);
        $sig = $this->resolveSignature($signature);

        $data = $request->validate([
            'placements'              => ['required', 'array', 'min:1'],
            'placements.*.slot'       => ['required', 'string', 'max:64'],
            'placements.*.page'       => ['required', 'integer', 'min:1'],
            'placements.*.x'          => ['required', 'numeric', 'min:0'],
            'placements.*.y'          => ['required', 'numeric', 'min:0'],
            'placements.*.width'      => ['required', 'numeric', 'min:1'],
            'placements.*.height'     => ['required', 'numeric', 'min:1'],
        ]);

        // Validate slot keys exist on the template — refuse anything else
        // so an attacker can't write arbitrary slot identifiers via the
        // signing endpoint.
        $declared = collect($tpl->slots())->pluck('key')->all();
        foreach ($data['placements'] as $p) {
            if (! in_array($p['slot'], $declared, true)) {
                return response()->json([
                    'error' => "Unknown slot [{$p['slot']}] for template [{$tpl->key()}]",
                ], 422);
            }
        }

        // Phase B will:
        //   1. Resolve the Signable target (currently TBD — likely from a
        //      query string or a host-app contract enumerating signables).
        //   2. Call SignatureManager::storeForDocument(source, userId,
        //      signable, position) once per placement (or once with a
        //      composite position if we support multi-slot signing).
        //   3. Call SignatureManager::embedAndFinalize() to produce the
        //      signed PDF.
        //   4. Return the signed PDF URL.

        return response()->json([
            'status'   => 'ack',
            'message'  => 'Signer pipeline is in stub mode — placements were validated but no PDF was produced yet.',
            'template' => $tpl->key(),
            'signature' => $sig->uuid,
            'placements' => $data['placements'],
        ]);
    }

    protected function resolveTemplate(string $key): PdfTemplate
    {
        return $this->registry->find($key)
            ?? abort(404, "No PdfTemplate registered with key [{$key}]");
    }

    protected function resolveSignature(string $uuid): Signature
    {
        $sig = Signature::where('uuid', $uuid)
            ->where('user_id', auth()->id())
            ->first();

        if (! $sig) {
            abort(404, 'Signature not found or not owned by the current user.');
        }

        if ($sig->isRevoked()) {
            abort(422, 'This signature has been revoked and can no longer be used.');
        }

        return $sig;
    }

    /**
     * @return list<array{key:string,label:string,required:bool,placement:?array}>
     */
    protected function resolveSlots(PdfTemplate $tpl): array
    {
        return collect($tpl->slots())->map(function ($slot) use ($tpl) {
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
            ];
        })->values()->all();
    }

    /**
     * Mirrors PdfTemplateDesignerController::resolvePageDimensions().
     * Returning page sizes in points lets the frontend convert CSS-pixel
     * placements back to PDF coordinates before posting them.
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
        $dpi     = (int) config('signature.designer.dpi', 144);

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
}
