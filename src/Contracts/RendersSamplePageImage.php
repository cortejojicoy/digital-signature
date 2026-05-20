<?php

namespace Kukux\DigitalSignature\Contracts;

/**
 * Optional companion to PdfTemplate. Implement on a template when the
 * host app prefers to supply pre-rendered page images instead of letting
 * the plugin rasterize the sample PDF via Imagick.
 *
 * Typical reasons to implement:
 *  - Imagick + Ghostscript are not installed on the host.
 *  - The template renders directly from HTML/CSS and can produce images
 *    faster than rendering a PDF first and rasterizing back.
 *  - Page previews live behind a CDN you'd rather serve from.
 */
interface RendersSamplePageImage
{
    /**
     * Return an absolute filesystem path to a PNG (or JPEG) of the
     * requested page. Page numbers are 1-indexed.
     */
    public function renderSampleAsImage(int $page): string;

    /**
     * Total number of pages this template's sample has.
     */
    public function sampleImagePageCount(): int;
}
