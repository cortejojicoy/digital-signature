<?php

namespace Kukux\DigitalSignature\Pdf\Renderers;

/**
 * Default renderer for plug-and-play templates.
 *
 * Uses barryvdh/laravel-dompdf when present — that's the most widely
 * installed Laravel PDF package and zero-config. Hosts using a
 * different renderer (Browsershot, Snappy, custom) should bind their
 * own PdfRenderer implementation in a service provider.
 */
class DomPdfRenderer implements PdfRenderer
{
    public function render(string $view, array $data, string $destinationPath): string
    {
        if (! static::isAvailable()) {
            throw new \RuntimeException(
                'DomPdfRenderer needs barryvdh/laravel-dompdf. Run '
                .'`composer require barryvdh/laravel-dompdf` or provide a custom '
                .'PdfRenderer implementation.',
            );
        }

        $dir = dirname($destinationPath);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Could not create directory: {$dir}");
        }

        // Resolved at runtime to avoid hard-importing the facade — that
        // way the plugin still loads in apps that don't have dompdf.
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($view, $data);

        file_put_contents($destinationPath, $pdf->output());

        return $destinationPath;
    }

    public static function isAvailable(): bool
    {
        return class_exists(\Barryvdh\DomPDF\Facade\Pdf::class);
    }
}
