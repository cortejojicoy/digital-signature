<?php

namespace Kukux\DigitalSignature\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;
use Kukux\DigitalSignature\Exceptions\OutOfSequenceException;
use Kukux\DigitalSignature\Exceptions\SignatoryNotReadyException;
use Kukux\DigitalSignature\Exceptions\SigningSessionClosedException;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Services\SigningSessionManager;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The document behind one signature request: what it looks like, and where
 * this signatory wants their stamp to land.
 *
 * The package used to ask people to sign documents they could not see — the
 * inbox listed a title and a Sign button, and the bytes were never served
 * anywhere the signatory could read them. These three endpoints close that
 * gap:
 *
 *   GET  meta      — page count comes from the PDF itself, so the client can
 *                    size its own overlay; plus the frozen slot placement and
 *                    the signatory's signature library
 *   GET  document  — the PDF, streamed
 *   POST sign      — commit, with the placement the signatory chose
 *
 * **Authorization.** Every endpoint resolves the request through
 * `ownedRequest()`, which matches on `user_id` as well as the key. The queue
 * is per-signatory and so is the document behind it: a signature request is
 * not a capability anyone holding its id can redeem. This is why the PDF is
 * streamed from the (typically private) signature disk rather than exposed as
 * a disk URL — a public URL would be a document leak with no way to revoke it.
 *
 * Middleware is `web` only, not `auth`, for the reason documented on the
 * template-signer routes: the bare `auth` middleware uses Laravel's default
 * guard, which frequently is not the Filament panel's guard, and the redirect
 * it issues lands on a route name that may not exist. Authorization lives in
 * `ownedRequest()` instead, which is the check that actually matters here.
 */
class SignatureDocumentController extends Controller
{
    public function __construct(
        protected SigningSessionManager $sessions,
    ) {
    }

    /**
     * Everything the viewer needs to render and place, in one round trip.
     *
     * Page geometry is deliberately absent: pdf.js reports each page's size in
     * PDF points client-side, which is the same number a server-side rasterize
     * would arrive at, without requiring Imagick + Ghostscript on every host
     * that wants to *read* a document. The rasterizer stays where it is needed
     * — the template designer, which has no PDF until it renders one.
     */
    public function meta(int $signatureRequest): JsonResponse
    {
        $request = $this->ownedRequest($signatureRequest);
        $session = $request->session;
        $document = $session?->signable;

        return response()->json([
            'request' => [
                'id'       => $request->id,
                'slot'     => $request->slot_key,
                'role'     => $request->role,
                'sequence' => $request->sequence,
                'blocked'  => $this->isBlocked($request),
                // The placement frozen when the request was created. The client
                // opens its box here, so a signatory who simply drops the
                // signature and commits reproduces the administrator's layout.
                'placement' => $request->position(),
            ],
            'document' => [
                'title' => $document && method_exists($document, 'getSignableTitle')
                    ? $document->getSignableTitle()
                    : ($session?->template_key ?? 'Document'),
                'url' => route('signature.request.document', ['signatureRequest' => $request->id]),
            ],
            'signatures' => $this->library((int) $request->user_id),
        ]);
    }

    /**
     * Stream the PDF this signatory is being asked to sign.
     *
     * The *running* document, not the frozen base: in a sequential session the
     * signatures already applied are part of what this signatory is agreeing
     * to, and hiding them would make the preview a different document from the
     * one being signed.
     */
    public function document(int $signatureRequest): StreamedResponse
    {
        $request = $this->ownedRequest($signatureRequest);

        $path = $request->session?->documentToSign();

        abort_unless($path, 404, 'This signing session has no document to show.');

        $disk = Storage::disk(config('signature.storage_disk'));

        abort_unless($disk->exists($path), 404, 'The document for this session is missing from storage.');

        return $disk->response($path, headers: [
            'Content-Type'  => 'application/pdf',
            // Never cached by a shared proxy: the URL is not a capability, the
            // session behind it is, and the document changes as people sign.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Commit the signature at the position the signatory chose.
     *
     * Placement is optional. Sending none signs at the slot's frozen
     * coordinates, which is exactly what the old one-click Sign button did —
     * so the drag gesture is an addition to this endpoint's contract, not a
     * precondition of it, and the keyboard path can use the same call.
     */
    public function sign(Request $httpRequest, int $signatureRequest): JsonResponse
    {
        $request = $this->ownedRequest($signatureRequest);

        $data = $httpRequest->validate([
            'page'         => ['sometimes', 'required', 'integer', 'min:1'],
            'x'            => ['sometimes', 'required', 'numeric', 'min:0'],
            'y'            => ['sometimes', 'required', 'numeric', 'min:0'],
            'width'        => ['sometimes', 'required', 'numeric', 'min:1'],
            'height'       => ['sometimes', 'required', 'numeric', 'min:1'],
            'signature_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $placement = array_key_exists('page', $data)
            ? ['page' => $data['page'], 'x' => $data['x'] ?? 0, 'y' => $data['y'] ?? 0,
               'width' => $data['width'] ?? 0, 'height' => $data['height'] ?? 0]
            : null;

        $signature = $this->resolveSignature($data['signature_id'] ?? null, (int) $request->user_id);

        try {
            $signed = $this->sessions->signAt(
                request:      $request,
                actorUserId:  (int) $this->userId(),
                placement:    $placement,
                useSignature: $signature,
            );
        } catch (OutOfSequenceException|SignatoryNotReadyException|SigningSessionClosedException|ForgedSignatureException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'Signing failed: '.$e->getMessage(),
                'hint'  => 'See storage/logs/laravel.log for the full stacktrace.',
            ], 422);
        }

        $session = $request->session->fresh(['requests']);

        return response()->json([
            'status'               => 'signed',
            'signature_uuid'       => $signed->uuid,
            'signed_document_path' => $signed->signed_document_path,
            'signed_at'            => optional($signed->signed_at)->toIso8601String(),
            'outstanding'          => $session->outstandingRequests()->count(),
            'message'              => $session->isComplete()
                ? 'Document signed. All signatories have now signed.'
                : 'Document signed. Waiting on the remaining signatories.',
        ]);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * The request, only if it belongs to the signed-in user.
     *
     * 403 rather than 404 on a mismatch, deliberately: the id space is
     * sequential and a 404 here would say "no such request" for somebody
     * else's document, which is a slower but still real enumeration oracle.
     * Both answers are the same shape, so neither leaks which it was.
     */
    protected function ownedRequest(int $id): SignatureRequest
    {
        $userId = $this->userId();

        abort_unless($userId, 403, 'You must be signed in to view this document.');

        $request = SignatureRequest::query()
            ->with(['session.signable', 'session.requests'])
            ->whereKey($id)
            ->where('user_id', $userId)
            ->first();

        abort_unless($request, 403, 'That signature request is not assigned to you.');

        return $request;
    }

    /**
     * The signature to sign with.
     *
     * Ownership is re-checked here even though `applySignature()` checks it
     * too: this endpoint takes the id straight off the wire, and an
     * unauthorized *selection* deserves a plain 403 rather than surfacing as
     * a forged-signature exception from three layers down.
     */
    protected function resolveSignature(int|string|null $id, int $signatoryId): ?Signature
    {
        if ($id === null || $id === '') {
            return null;
        }

        $signature = Signature::query()->whereKey($id)->first();

        abort_unless($signature && (int) $signature->user_id === $signatoryId, 403,
            'That signature does not belong to you.');

        abort_if($signature->isRevoked(), 422,
            'That signature has been revoked and can no longer be used.');

        return $signature;
    }

    /**
     * This signatory's usable signatures — the drag sources in the UI.
     *
     * Preview URLs are short-lived signed URLs, so the payload is safe to hand
     * to a client without making the images themselves publicly readable.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function library(int $userId): array
    {
        return Signature::query()
            ->where('user_id', $userId)
            ->whereNull('signable_id')
            ->where('status', 'active')
            ->latest('id')
            ->limit(12)
            ->get()
            ->map(fn (Signature $s): array => [
                'id'         => $s->id,
                'uuid'       => $s->uuid,
                'previewUrl' => $s->getTemporaryImageUrl(60),
                'source'     => $s->source,
            ])
            ->all();
    }

    /**
     * Whether an earlier required signatory still has to act.
     *
     * Advisory only — the client uses it to disable the commit button, and
     * `assertInSequence()` is what actually enforces it at signing time.
     */
    protected function isBlocked(SignatureRequest $request): bool
    {
        $session = $request->session;

        if (! $session?->isSequential()) {
            return false;
        }

        return $session->requests
            ->where('required', true)
            ->where('sequence', '<', $request->sequence)
            ->contains(fn (SignatureRequest $r): bool => ! $r->isSigned());
    }

    protected function userId(): int|string|null
    {
        return auth()->id();
    }
}
