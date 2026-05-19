<?php

namespace Kukux\DigitalSignature\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Models\Signature;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams signature images from the configured (typically private) storage
 * disk. Reached only via short-lived signed URLs — the `signed` middleware
 * on the route enforces the URL's HMAC + expiry, so the URL itself acts as
 * a time-limited capability. This is what makes the local disk usable for
 * preview rendering: drivers like `local` don't expose a public URL and
 * don't support Storage::temporaryUrl() natively.
 *
 * Route: GET /signature/assets/{signature:uuid}
 */
class SignatureAssetController extends Controller
{
    public function show(Request $request, Signature $signature): StreamedResponse
    {
        abort_unless($signature->image_path, 404);

        $disk = Storage::disk(config('signature.storage_disk'));

        abort_unless($disk->exists($signature->image_path), 404);

        $cacheTtl = (int) config('signature.preview_url_ttl', 5) * 60;

        // Storage::response() returns a StreamedResponse with the file's
        // Content-Type already populated from the disk's mime resolver.
        return $disk->response($signature->image_path, headers: [
            'Cache-Control' => "private, max-age={$cacheTtl}",
        ]);
    }
}
