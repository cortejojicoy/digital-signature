<?php

namespace Kukux\DigitalSignature\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Contracts\RendersSamplePageImage;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Models\PdfTemplateSlot;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Pdf\BladePdfTemplate;
use Kukux\DigitalSignature\Pdf\PdfPageRasterizer;
use Kukux\DigitalSignature\Services\PdfTemplateRegistry;
use Kukux\DigitalSignature\Services\SignatureManager;

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
        protected SignatureManager $signatureManager,
    ) {
    }

    public function meta(string $template, string $signature): JsonResponse
    {
        $tpl = $this->resolveTemplate($template);
        $sig = $this->resolveSignature($signature);

        try {
            $pages = $this->resolvePageDimensions($tpl);
        } catch (\Throwable $e) {
            // Surface render/rasterize failures (missing Blade view, missing
            // Imagick / Ghostscript, bad data in resolver, etc.) as a 422
            // with a useful message so the React side can show it instead
            // of a bare 500. Server logs still have the full stacktrace.
            report($e);
            return response()->json([
                'error' => 'Failed to render template preview: '.$e->getMessage(),
                'hint'  => 'Common causes: the Blade view threw, Imagick + Ghostscript not installed, '
                         .'or the PDF renderer (DomPDF / custom) is misconfigured. '
                         .'See storage/logs/laravel.log for the full stacktrace.',
            ], 422);
        }

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
     * Produce a signed PDF.
     *
     * Phase B: resolve the host-app record from `signable_id`, render its
     * PDF via $template->renderFor(), then run the existing
     * SignatureManager pipeline (storeForDocument + embedAndFinalize) so
     * the result lands in `digital_signatures.signed_document_path`.
     *
     * When `signable_id` isn't provided OR the template has no bound
     * model class, the endpoint stays in acknowledgement mode (returns
     * the validated placements). That keeps the React UI loop testable
     * before host apps have wired their own resource entry points.
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
            'signable_id'             => ['sometimes', 'nullable'],
        ]);

        // Refuse unknown slot keys so an attacker can't write arbitrary
        // identifiers via the endpoint.
        $declared = collect($tpl->slots())->pluck('key')->all();
        foreach ($data['placements'] as $p) {
            if (! in_array($p['slot'], $declared, true)) {
                return response()->json([
                    'error' => "Unknown slot [{$p['slot']}] for template [{$tpl->key()}]",
                ], 422);
            }
        }

        // Phase B v1 supports one placement per signing call. Multi-slot
        // signing requires the signer driver to stamp multiple images in
        // one pass — current pipeline signs the unsigned PDF each time,
        // so calling it N times produces N separate signed copies rather
        // than one PDF with N stamps.
        if (count($data['placements']) > 1) {
            return response()->json([
                'error' => 'Multi-slot signing is not yet supported. Place the signature on exactly one slot per signing call.',
            ], 422);
        }

        $signable = $this->resolveSignable($tpl, $data['signable_id'] ?? null);

        // Stub path — no signable supplied, return the validated payload
        // so the React UI loop is testable without a real record.
        if (! $signable) {
            return response()->json([
                'status'  => 'ack',
                'message' => 'No signable_id was provided, so no PDF was signed. Pass a signable_id to produce a signed document. '
                            .'Note: the template must declare a [signable => Model::class] config so the controller can resolve the record.',
                'template'   => $tpl->key(),
                'signature'  => $sig->uuid,
                'placements' => $data['placements'],
            ]);
        }

        $password = $sig->getCertificatePassword();
        if (! $password) {
            return response()->json([
                'error' => 'No certificate password is stored for this signature — re-create the signature and provide a certificate password.',
            ], 422);
        }

        $placement = $data['placements'][0];

        try {
            $signed = $this->signatureManager->storeForDocument(
                source:        $sig,
                signerUserId:  (int) auth()->id(),
                signable:      $signable,
                position:      [
                    'page'   => $placement['page'],
                    'x'      => $placement['x'],
                    'y'      => $placement['y'],
                    'width'  => $placement['width'],
                    'height' => $placement['height'],
                ],
            );

            $this->signatureManager->embedAndFinalize($signed, $password);
            $signed->refresh();
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'error' => 'Signing failed: '.$e->getMessage(),
                'hint'  => 'See storage/logs/laravel.log for the full stacktrace. Common causes: '
                         .'the host model does not implement Signable, the signer driver could not read the PDF, '
                         .'or the certificate password is incorrect.',
            ], 422);
        }

        return response()->json([
            'status'               => 'signed',
            'signature_uuid'       => $signed->uuid,
            'signed_document_path' => $signed->signed_document_path,
            'signed_at'            => optional($signed->signed_at)->toIso8601String(),
            'message'              => 'Document signed successfully.',
        ]);
    }

    /**
     * Resolve the Signable from the request.
     *
     * Returns null when:
     *  - no signable_id was supplied (stub mode), or
     *  - the template config doesn't bind a model class.
     *
     * Throws aborting 404/422 when:
     *  - the model class is missing, the record doesn't exist, or the
     *    model doesn't implement the Signable contract.
     */
    protected function resolveSignable(PdfTemplate $tpl, string|int|null $signableId): ?Signable
    {
        if ($signableId === null || $signableId === '') {
            return null;
        }

        // Only BladePdfTemplate currently carries a declared signable
        // class. Full-class PdfTemplate implementations can resolve
        // their own records inside renderFor(), so this path doesn't
        // require a registered class for them.
        $modelClass = $tpl instanceof BladePdfTemplate
            ? $tpl->getSignableClass()
            : null;

        if (! $modelClass) {
            abort(422, "Template [{$tpl->key()}] does not declare a signable model class. "
                ."Add ['signable' => YourModel::class] to its config to enable signable_id-based signing.");
        }

        $record = $modelClass::query()->find($signableId);
        if (! $record) {
            abort(404, "Record [{$modelClass} #{$signableId}] not found.");
        }

        if (! $record instanceof Signable) {
            abort(422, "Model [{$modelClass}] must implement ".Signable::class
                ." to be signed. Use the HasPdfTemplate trait for a 2-line implementation.");
        }

        return $record;
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
