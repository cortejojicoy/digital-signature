<?php

namespace Kukux\DigitalSignature\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Services\PdfTemplateRegistry;
use Kukux\DigitalSignature\Services\SignatureCaption;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A template's sample PDF, read-only, for the drawer's viewer.
 *
 * "Manage signatures" lists the templates a signature can be applied to;
 * clicking one opens its sample here instead of leaving for the signer or
 * designer page. `meta` has the same shape SignatureDocumentController
 * gives the viewer, with `readOnly` set, so nothing can be placed or signed.
 *
 * The sample is the template's own fixed data, but it's still kept behind
 * a signed-in user, like every other drawer endpoint.
 */
class PdfTemplatePreviewController extends Controller
{
    public function __construct(
        protected PdfTemplateRegistry $registry,
        protected SignatureCaption $captions,
    ) {
    }

    public function meta(string $template): JsonResponse
    {
        $tpl = $this->resolve($template);

        return response()->json([
            'opened'     => null,
            'sequential' => false,
            'readOnly'   => true,
            'state'      => 'preview',
            'stateLabel' => 'Sample preview',
            'settledAt'  => null,
            'role'       => null,
            'requests'   => [],
            'stamp'      => $this->captions->rules(),
            'back'       => 'Manage signatures',
            'document'   => [
                'title' => $tpl->label(),
                'url'   => route('signature.pdf-templates.preview.document', ['template' => $tpl->key()]),
            ],
            'signatures' => [],
        ]);
    }

    public function document(string $template): BinaryFileResponse
    {
        $tpl = $this->resolve($template);

        try {
            $path = $tpl->renderSample();
        } catch (\Throwable $e) {
            report($e);

            abort(422, 'This template\'s preview could not be rendered.');
        }

        return response()->file($path, [
            'Content-Type'  => 'application/pdf',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    protected function resolve(string $key): PdfTemplate
    {
        abort_unless(auth()->check(), 403);

        return $this->registry->find($key)
            ?? abort(404, "No PdfTemplate registered with key [{$key}]");
    }
}
