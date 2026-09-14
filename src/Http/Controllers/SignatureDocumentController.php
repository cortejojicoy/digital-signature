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
use Kukux\DigitalSignature\Models\SigningSession;
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

        // A slot that has already been signed or declined is history, not work.
        // The document stays readable — the signatory's certificate is on it
        // and they are entitled to see what they signed — but there is nothing
        // left to place, so the client renders it without the signing surface.
        $settled = $request->state->isTerminal();

        return response()->json([
            // The request named in the URL. The client opens on it, but it is
            // rarely the only one: the same person is routinely both "Prepared
            // by" and "Noted by" on the same form, and making them open the
            // document twice to sign it twice is the friction this whole
            // surface exists to remove.
            'opened'   => $request->id,
            'sequential' => (bool) $session?->isSequential(),
            // Advisory, like `blocked`. Signing an already-signed slot is
            // refused by applySignature() whatever the client believes.
            'readOnly' => $settled,
            'state'    => $request->state->value,
            'stateLabel' => $request->state->label(),
            'settledAt'  => optional($request->responded_at)->toIso8601String(),
            'role'       => $request->role,
            'requests' => $settled ? [] : $this->siblingRequests($request),
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
     * Every slot in this session that is still waiting on this same signatory,
     * the opened one included, in signing order.
     *
     * Scoped to the session rather than the whole queue: these are the slots
     * that can be placed on the document currently open, and a request from
     * some other document has nowhere to go on this page.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function siblingRequests(SignatureRequest $request): array
    {
        return SignatureRequest::query()
            ->with('session.requests')
            ->outstandingFor((int) $request->user_id)
            ->where('signing_session_id', $request->signing_session_id)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(fn (SignatureRequest $r): array => [
                'id'       => $r->id,
                'slot'     => $r->slot_key,
                'role'     => $r->role,
                'sequence' => $r->sequence,
                'required' => (bool) $r->required,
                'blocked'  => $this->isBlocked($r),
                // The placement frozen when the request was created. The client
                // opens each box here, so a signatory who simply drops and
                // commits reproduces the administrator's layout.
                'placement' => $r->position(),
            ])
            ->all();
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
            // Several slots at once. Each entry names one of this signatory's
            // own requests in this session; omitting request_id means the one
            // in the URL.
            'placements'                 => ['sometimes', 'array', 'min:1', 'max:32'],
            'placements.*.request_id'    => ['sometimes', 'nullable', 'integer'],
            'placements.*.page'          => ['required_with:placements', 'integer', 'min:1'],
            'placements.*.x'             => ['required_with:placements', 'numeric', 'min:0'],
            'placements.*.y'             => ['required_with:placements', 'numeric', 'min:0'],
            'placements.*.width'         => ['required_with:placements', 'numeric', 'min:1'],
            'placements.*.height'        => ['required_with:placements', 'numeric', 'min:1'],
            'placements.*.signature_id'  => ['sometimes', 'nullable', 'integer'],

            // Single-slot shorthand, which is also the keyboard path and the
            // shape the old one-click Sign button produced.
            'page'         => ['sometimes', 'required', 'integer', 'min:1'],
            'x'            => ['sometimes', 'required', 'numeric', 'min:0'],
            'y'            => ['sometimes', 'required', 'numeric', 'min:0'],
            'width'        => ['sometimes', 'required', 'numeric', 'min:1'],
            'height'       => ['sometimes', 'required', 'numeric', 'min:1'],
            'signature_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $jobs = $this->resolveSigningJobs($request, $data);

        $signed  = [];
        $last    = null;

        // Sequence order, not payload order. Each signature is applied to the
        // session's running document and chained to the one before it, so the
        // order they are applied in is the order they appear in the chain —
        // and a sequential session would refuse them in any other order
        // anyway.
        foreach ($jobs as $job) {
            try {
                $last = $this->sessions->signAt(
                    request:      $job['request'],
                    actorUserId:  (int) $this->userId(),
                    placement:    $job['placement'],
                    useSignature: $job['signature'],
                );
            } catch (OutOfSequenceException|SignatoryNotReadyException|SigningSessionClosedException|ForgedSignatureException $e) {
                return $this->partialFailure($request, $signed, $e->getMessage(), $job['request']);
            } catch (\Throwable $e) {
                report($e);

                return $this->partialFailure(
                    $request,
                    $signed,
                    'Signing failed: '.$e->getMessage(),
                    $job['request'],
                    'See storage/logs/laravel.log for the full stacktrace.',
                );
            }

            $signed[] = [
                'request_id'     => $job['request']->id,
                'slot'           => $job['request']->slot_key,
                'role'           => $job['request']->role,
                'signature_uuid' => $last->uuid,
            ];
        }

        $session = $request->session->fresh(['requests']);

        return response()->json([
            'status' => 'signed',
            'signed' => $signed,
            // The last signature is the one that produced the document as it
            // now stands, so these describe the file a caller would fetch.
            'signature_uuid'       => $last?->uuid,
            'signed_document_path' => $last?->signed_document_path,
            'signed_at'            => optional($last?->signed_at)->toIso8601String(),
            'outstanding'          => $session->outstandingRequests()->count(),
            'message'              => $this->outcomeMessage($session, count($signed)),
        ]);
    }

    /**
     * Turn the payload into an ordered list of (request, placement, signature).
     *
     * Every request is re-resolved through the same ownership query as the one
     * in the URL and pinned to the same session. Holding one request id is not
     * a licence to sign a second: the client may only batch slots it could
     * have signed one at a time.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{request: SignatureRequest, placement: ?array<string, mixed>, signature: ?Signature}>
     */
    protected function resolveSigningJobs(SignatureRequest $opened, array $data): array
    {
        $entries = $data['placements'] ?? null;

        if ($entries === null) {
            // Single-slot shorthand. No geometry at all means "sign where the
            // slot already says", which is what the keyboard path sends.
            $entries = [array_key_exists('page', $data) ? $data : ['request_id' => $opened->id]];
        }

        $jobs = [];
        $seen = [];

        foreach ($entries as $entry) {
            $id = $entry['request_id'] ?? $opened->id;

            $request = (int) $id === (int) $opened->id
                ? $opened
                : $this->ownedRequest((int) $id);

            abort_unless(
                (int) $request->signing_session_id === (int) $opened->signing_session_id,
                403,
                'That signature request belongs to a different document.',
            );

            // A slot can only be signed once, so a payload naming it twice is
            // a client bug; silently applying the first and failing on the
            // second would be a confusing way to report it.
            abort_if(
                in_array((int) $request->id, $seen, true),
                422,
                'The same slot was placed more than once.',
            );

            $seen[] = (int) $request->id;

            $jobs[] = [
                'request'   => $request,
                'placement' => array_key_exists('page', $entry)
                    ? [
                        'page'   => $entry['page'],
                        'x'      => $entry['x']      ?? 0,
                        'y'      => $entry['y']      ?? 0,
                        'width'  => $entry['width']  ?? 0,
                        'height' => $entry['height'] ?? 0,
                    ]
                    : null,
                'signature' => $this->resolveSignature(
                    $entry['signature_id'] ?? null,
                    (int) $request->user_id,
                ),
            ];
        }

        usort($jobs, fn (array $a, array $b): int => [$a['request']->sequence, $a['request']->id]
            <=> [$b['request']->sequence, $b['request']->id]);

        return $jobs;
    }

    /**
     * One of a batch failed.
     *
     * Whatever was applied before it is genuinely signed and stays signed —
     * each signature is its own committed transaction against the running
     * document, and there is no honest way to un-sign a PDF that somebody
     * already put their certificate on. So the response says exactly how far
     * it got rather than implying the whole batch was rejected.
     *
     * @param  array<int, array<string, mixed>>  $signed
     */
    protected function partialFailure(
        SignatureRequest $opened,
        array $signed,
        string $error,
        SignatureRequest $failedOn,
        ?string $hint = null,
    ): JsonResponse {
        $session = $opened->session->fresh(['requests']);

        return response()->json(array_filter([
            'status'      => $signed === [] ? 'failed' : 'partial',
            'error'       => $error,
            'hint'        => $hint,
            'failed_on'   => ['request_id' => $failedOn->id, 'slot' => $failedOn->slot_key],
            'signed'      => $signed,
            'outstanding' => $session->outstandingRequests()->count(),
        ], fn ($value): bool => $value !== null), 422);
    }

    protected function outcomeMessage(SigningSession $session, int $count): string
    {
        $applied = $count === 1
            ? 'Document signed.'
            : "Document signed in {$count} places.";

        return $session->isComplete()
            ? $applied.' All signatories have now signed.'
            : $applied.' Waiting on the remaining signatories.';
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
     * Whether somebody *else* still has to act before this slot can be signed.
     *
     * An earlier slot belonging to this same signatory is deliberately not a
     * blocker. They can clear it themselves — and routinely do, in the same
     * batch, since placing "Prepared by" and "Noted by" together is the whole
     * point of accepting several placements at once. Treating their own
     * pending slot as a blocker would grey out the case this exists for.
     *
     * Advisory only. `assertInSequence()` is what actually enforces ordering
     * at signing time, and it will still refuse a batch that leaves an earlier
     * slot of their own unsigned.
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
            ->contains(fn (SignatureRequest $r): bool => ! $r->isSigned()
                && (int) $r->user_id !== (int) $request->user_id);
    }

    protected function userId(): int|string|null
    {
        return auth()->id();
    }
}
