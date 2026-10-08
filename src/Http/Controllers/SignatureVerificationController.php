<?php

namespace Kukux\DigitalSignature\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Kukux\DigitalSignature\Client\Exceptions\HubException;
use Kukux\DigitalSignature\Client\HubClient;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Support\SignatureMode;

/**
 * What the QR on a signed page resolves to.
 *
 * Someone is holding a printed document and wants to know whether the mark on
 * it is real. They will not have an account, and requiring one would make the
 * QR useless to exactly the people it exists for — an auditor, a receiving
 * office, a counterparty. So this is public, and its whole design follows from
 * that.
 *
 * **What it discloses, and why that is safe.** The signature UUID is printed on
 * the page: anyone who can scan the code is already holding the document. So
 * the answer says only what that page already shows them — who signed, in what
 * role, when, and whether the signature still stands — and confirms it came
 * from this system. It does not expose the document, the file, the signer's
 * email, the other signatories, or anything about the record behind it. A
 * scan confirms a mark; it is not a way in.
 *
 * **What it cannot be used for.** UUIDs are random, so the identifier is not
 * guessable and there is nothing to enumerate. An unknown one gets the same
 * shaped answer as a revoked one: "no valid signature", with no hint as to
 * which of the two it was.
 *
 * **Client mode.** The certificate behind a signature is the hub's, so its
 * status is asked of the hub (cached briefly). A certificate the hub has
 * revoked makes the answer "no valid signature", the same shape again. When
 * the hub can't be reached the local answer stands, marked `certificate:
 * unchecked`; signatures made before the app moved to the hub are unknown
 * there and keep their local answer.
 */
class SignatureVerificationController extends Controller
{
    public function show(Request $request, string $uuid): View|JsonResponse
    {
        abort_unless(config('signature.verify.enabled', true), 404);

        $signature = Signature::query()
            ->with(['user', 'session'])
            ->where('uuid', $uuid)
            ->first();

        // Not found and revoked deliberately produce the same shape. Telling a
        // scanner which of the two it was would turn this into an oracle for
        // whether a given reference ever existed.
        $valid = $signature !== null
            && ! $signature->isRevoked()
            && $signature->status === 'signed';

        $certificate = $valid && SignatureMode::isClient()
            ? $this->hubCertificateStatus($signature->certificate_fingerprint)
            : null;

        if ($certificate === 'revoked') {
            $valid = false;
            $certificate = null;
        }

        $payload = [
            'valid'     => $valid,
            'reference' => substr($uuid, 0, 8),
            'signer'    => $valid ? $signature->user?->name : null,
            'role'      => $valid ? $signature->slot_key : null,
            'signedAt'  => $valid ? optional($signature->signed_at)->toIso8601String() : null,
            'complete'  => $valid ? (bool) $signature->session?->isComplete() : null,
        ];

        if ($certificate !== null) {
            $payload['certificate'] = $certificate;
        }

        if ($request->wantsJson()) {
            return response()->json($payload, $valid ? 200 : 404);
        }

        return view('signature::verify', $payload);
    }

    /**
     * valid | revoked | unknown, or unchecked when the hub can't be asked.
     */
    protected function hubCertificateStatus(?string $fingerprint): string
    {
        if ($fingerprint === null || $fingerprint === '') {
            return 'unknown';
        }

        try {
            return Cache::remember('signature:hub:certificate:'.$fingerprint, 300, function () use ($fingerprint) {
                $status = app(HubClient::class)->certificate($fingerprint)['status'] ?? 'unknown';

                return in_array($status, ['valid', 'revoked', 'unknown'], true) ? $status : 'unknown';
            });
        } catch (HubException) {
            return 'unchecked';
        }
    }
}
