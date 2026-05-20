<?php

namespace Kukux\DigitalSignature\Pdf;

use Imagick;
use RuntimeException;

/**
 * Rasterizes PDF pages to PNG images for the placement designer.
 *
 * Output is cached under the configured signature disk so a template's
 * sample PDF only gets rasterized once per (file-mtime, DPI) pair. The
 * cache key intentionally includes mtime so re-uploading or regenerating
 * a sample PDF invalidates automatically.
 *
 * Imagick (with the PDF delegate Ghostscript) is required. Hosts without
 * Imagick can implement PdfTemplate::renderSampleAsImage() directly to
 * supply pre-rendered page images by any other means.
 */
class PdfPageRasterizer
{
    public function __construct(
        protected int $dpi = 144,
    ) {
    }

    /**
     * Rasterize a single page of $pdfPath to PNG. Returns an absolute
     * filesystem path to the rendered image.
     *
     * Page numbers are 1-indexed to match the rest of the plugin
     * (matches PdfTemplateSlot::$page and SignaturePosition::$page).
     */
    public function renderPage(string $pdfPath, int $page = 1): string
    {
        if (! class_exists(Imagick::class)) {
            throw new RuntimeException(
                'PdfPageRasterizer requires the Imagick PHP extension with a PDF delegate (Ghostscript). '
                .'Either install Imagick + Ghostscript, or implement PdfTemplate::renderSampleAsImage() '
                .'on your template to supply page images by another means.',
            );
        }

        if (! is_file($pdfPath) || ! is_readable($pdfPath)) {
            throw new RuntimeException("PDF not found or unreadable: {$pdfPath}");
        }

        $cacheDir  = $this->cacheDir($pdfPath);
        $cachePath = $cacheDir.'/page-'.$page.'.png';

        if (is_file($cachePath)) {
            return $cachePath;
        }

        if (! is_dir($cacheDir) && ! mkdir($cacheDir, 0775, true) && ! is_dir($cacheDir)) {
            throw new RuntimeException("Could not create cache directory: {$cacheDir}");
        }

        // Imagick uses 0-indexed pages internally
        $imagick = new Imagick();
        $imagick->setResolution($this->dpi, $this->dpi);
        $imagick->readImage($pdfPath.'['.($page - 1).']');
        $imagick->setImageBackgroundColor('white');
        $imagick = $imagick->flattenImages();
        $imagick->setImageFormat('png');
        $imagick->writeImage($cachePath);
        $imagick->clear();

        return $cachePath;
    }

    /**
     * Page-count discovery — used by the designer to render a page strip.
     */
    public function pageCount(string $pdfPath): int
    {
        if (! class_exists(Imagick::class)) {
            return 1;
        }

        $imagick = new Imagick();
        $imagick->pingImage($pdfPath);
        $count = $imagick->getNumberImages();
        $imagick->clear();

        return max(1, $count);
    }

    protected function cacheDir(string $pdfPath): string
    {
        $key = substr(sha1($pdfPath.'|'.filemtime($pdfPath).'|'.$this->dpi), 0, 16);

        return sys_get_temp_dir().'/signature-pdf-pages/'.$key;
    }
}
