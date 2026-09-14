<?php

namespace Kukux\DigitalSignature\Support;

use Filament\Support\Facades\FilamentAsset;
use Throwable;

/**
 * URLs for the two files the PDF viewer needs at runtime: its own bundle and
 * the pdf.js worker.
 *
 * Both are registered with Filament as `loadedOnRequest()` assets, which means
 * `php artisan filament:assets` publishes them but no page automatically loads
 * them — exactly what the lazy island wants. Neither is a script the panel
 * should execute on its own: one is fetched when a document is opened, the
 * other is only ever read by pdf.js as a worker.
 *
 * The lookup falls back to the `signature-assets` publish path because
 * `FilamentAsset::getScriptSrc()` throws when the plugin was never registered
 * on a panel — which is a supported way to run this package, since a host can
 * mount the launcher into its own layout. A drawer that 500s on every page
 * because an asset URL could not be named would be a much worse failure than
 * a viewer that cannot find its bundle and says so.
 */
final class ViewerAssets
{
    public const BUNDLE_ID = 'signature-pdf-viewer';

    public const WORKER_ID = 'signature-pdf-worker';

    public static function bundleUrl(): string
    {
        return self::url(self::BUNDLE_ID, 'digital-signature-pdf-viewer.js');
    }

    public static function workerUrl(): string
    {
        return self::url(self::WORKER_ID, 'digital-signature-pdf.worker.js');
    }

    private static function url(string $id, string $publishedFilename): string
    {
        try {
            return FilamentAsset::getScriptSrc($id, 'kukux/digital-signature');
        } catch (Throwable) {
            return asset('vendor/digital-signature/'.$publishedFilename);
        }
    }
}
