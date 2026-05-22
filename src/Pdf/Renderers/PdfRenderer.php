<?php

namespace Kukux\DigitalSignature\Pdf\Renderers;

/**
 * Renders a Blade view to a PDF file on disk.
 *
 * Implementations adapt different host-side PDF libraries (DomPDF,
 * Browsershot, Snappy, …) to a single contract the plugin can use
 * without caring which one the host app has installed.
 *
 * Auto-detected by BladePdfTemplate when one is reachable in the
 * application's composer autoload graph. Hosts can register custom
 * renderers by binding their own class to PdfRenderer::class in a
 * service provider.
 */
interface PdfRenderer
{
    /**
     * Render $view with $data and write the PDF bytes to $destinationPath.
     * Returns the same path for chaining convenience.
     */
    public function render(string $view, array $data, string $destinationPath): string;

    /**
     * Whether the underlying library is reachable in this app. Used by
     * the detector to skip implementations whose dependencies aren't
     * installed.
     */
    public static function isAvailable(): bool;
}
