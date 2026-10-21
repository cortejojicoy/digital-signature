<?php

namespace Kukux\DigitalSignature\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Kukux\DigitalSignature\Support\SignatureMode;
use Kukux\DigitalSignature\Verification\DocumentLookup;
use Throwable;

/**
 * "Drop a PDF here": verify a whole file, not one mark on it.
 *
 * The page fingerprints the file in the browser and checks its embedded
 * signature there too (resources/js/verify-entry.js); the file is never
 * uploaded. This controller serves that page, answers the fingerprint
 * lookup, and hands out the CA certificate the browser checks the signature's
 * chain against.
 *
 * Public, like the QR verify page next door and for the same reason: the
 * people holding a document (a receiving office, an auditor) mostly have no
 * account. DocumentLookup's docblock covers what a lookup discloses.
 *
 * The lookup is outside the `web` group on purpose. It is a read with no
 * session behind it, and Kukux Sign Agent calls it too, without cookies, so
 * CSRF would refuse the agent and add nothing for the page.
 */
class DocumentVerificationController extends Controller
{
    private const HEX64 = '/^[0-9a-fA-F]{64}$/';

    public function page(): View
    {
        $this->ensureEnabled();

        return view('signature::verify-document', [
            'lookupUrl' => route('signature.verify.lookup'),
            'trustUrl'  => route('signature.verify.trust'),
            'scriptUrl' => route('signature.verify.script', ['v' => $this->scriptVersion()]),
        ]);
    }

    public function lookup(Request $request, DocumentLookup $lookup): JsonResponse
    {
        $this->ensureEnabled();

        $validator = Validator::make($request->all(), [
            'sha256'                 => ['required', 'string', 'regex:'.self::HEX64],
            'signed_revision_sha256' => ['nullable', 'string', 'regex:'.self::HEX64],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Send the file\'s SHA-256 as 64 hex characters in "sha256".',
                'errors'  => $validator->errors(),
            ], 422);
        }

        return response()->json(
            $lookup->find($request->string('sha256')->value(), $request->input('signed_revision_sha256'))->toArray(),
        );
    }

    /**
     * The CA certificate this system's signatures chain to, as PEM.
     *
     * Public by nature: a CA certificate is what everybody needs in order to
     * trust the signatures under it. In client mode the CA is the hub's, so
     * this relays the hub's own copy of this endpoint (cached a day).
     */
    public function trustAnchor(): Response
    {
        $this->ensureEnabled();

        $pem = SignatureMode::isClient() ? $this->hubTrustAnchor() : $this->localTrustAnchor();

        abort_if($pem === null, 404);

        return response($pem, 200, [
            'Content-Type'  => 'application/x-pem-file',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /** The page's bundle, from the package itself: no `filament:assets` step to forget. */
    public function script(): Response
    {
        $this->ensureEnabled();

        $path = $this->scriptPath();

        abort_unless(is_file($path), 404);

        return response((string) file_get_contents($path), 200, [
            'Content-Type'  => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    protected function ensureEnabled(): void
    {
        abort_unless(
            config('signature.verify.enabled', true) && config('signature.verify.document_lookup', true),
            404,
        );
    }

    protected function localTrustAnchor(): ?string
    {
        $path = (string) config('signature.openssl.ca_cert_path');

        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        $pem = (string) file_get_contents($path);

        return str_contains($pem, '-----BEGIN CERTIFICATE-----') ? $pem : null;
    }

    protected function hubTrustAnchor(): ?string
    {
        $base = rtrim((string) config('signature.hub.url'), '/');

        if ($base === '') {
            return null;
        }

        $cached = Cache::get('signature:hub:trust-anchor');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::timeout((int) config('signature.hub.timeout', 10))
                ->get($base.'/signature/trust/ca.pem');
        } catch (Throwable) {
            return null;
        }

        $pem = $response->successful() ? $response->body() : '';

        if (! str_contains($pem, '-----BEGIN CERTIFICATE-----')) {
            return null;
        }

        Cache::put('signature:hub:trust-anchor', $pem, now()->addDay());

        return $pem;
    }

    protected function scriptPath(): string
    {
        return __DIR__.'/../../../resources/dist/digital-signature-verify.js';
    }

    /** Busts the browser cache when the bundle changes, so `immutable` is safe. */
    protected function scriptVersion(): string
    {
        $path = $this->scriptPath();

        return is_file($path) ? substr(md5_file($path), 0, 12) : 'missing';
    }
}
