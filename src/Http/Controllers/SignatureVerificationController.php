<?php

namespace Kukux\DigitalSignature\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Models\Signature;

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

        $payload = [
            'valid'     => $valid,
            'reference' => substr($uuid, 0, 8),
            'signer'    => $valid ? $signature->user?->name : null,
            'role'      => $valid ? $signature->slot_key : null,
            'signedAt'  => $valid ? optional($signature->signed_at)->toIso8601String() : null,
            'complete'  => $valid ? (bool) $signature->session?->isComplete() : null,
        ];

        if ($request->wantsJson()) {
            return response()->json($payload, $valid ? 200 : 404);
        }

        return view('signature::verify', $payload);
    }
}
