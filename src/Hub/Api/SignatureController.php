<?php

namespace Kukux\DigitalSignature\Hub\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Hub\OAuth\AuthenticateHubToken;
use Kukux\DigitalSignature\Hub\Signing\SignerCertificates;
use Kukux\DigitalSignature\Hub\Specimens\SpecimenService;
use Kukux\DigitalSignature\Models\HubHolder;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Symfony\Component\HttpFoundation\Response;

/**
 * A person's signature, for apps that mirror it (docs/hub/contracts.md §2.2):
 *
 *   GET people/{sub}/signature          metadata: uuid, image hash, cert fingerprint
 *   GET people/{sub}/signature/image    the PNG; holders only; ETag = SHA-256
 *   GET certificates/{fingerprint}      valid | revoked | unknown
 *
 * The image is the one personal file the hub releases, so it goes only to
 * apps the person signed in to or that named them (HubHolder), and every
 * release is audited (`hub.image_served`).
 */
class SignatureController extends Controller
{
    public function __construct(
        private readonly People $people,
        private readonly SpecimenService $specimens,
        private readonly SignerCertificates $certificates,
    ) {}

    public function show(string $sub): JsonResponse
    {
        [, $signature] = $this->signature($sub);

        return response()->json([
            'uuid'                    => $signature->uuid,
            'status'                  => 'active',
            'image_sha256'            => $signature->image_hash,
            'certificate_fingerprint' => $this->certificates->current((int) $signature->user_id)?->fingerprint
                ?? $signature->certificate_fingerprint,
            'updated_at'              => $signature->updated_at?->toIso8601String(),
        ]);
    }

    public function image(Request $request, string $sub): Response
    {
        $app = AuthenticateHubToken::app($request);

        $holder = HubHolder::query()->where('app_id', $app->id)->where('personnel_key', $sub)->first();

        if ($holder === null) {
            throw new HubApiException(403, 'not_linked', 'This app may fetch the signature only of people who signed in to it or that it linked.');
        }

        [$userId, $signature] = $this->signature($sub);

        $bytes = $this->specimens->read($signature);

        if ($bytes === null) {
            throw new HubApiException(503, 'specimen_unavailable', 'The signature image is temporarily unavailable.');
        }

        // The hash of the bytes actually sent: an app compares it with the
        // metadata's image_sha256, which also catches an edited master (R5).
        $sha256 = hash('sha256', $bytes);
        $etag = "\"{$sha256}\"";
        $headers = [
            'ETag'           => $etag,
            'X-Image-Sha256' => $sha256,
            'Cache-Control'  => 'private, no-cache',
        ];

        if ($this->matches($request->header('If-None-Match'), $sha256)) {
            return response('', 304, $headers);
        }

        $holder->forceFill(['last_pulled_at' => now()])->save();

        SignatureAudit::record(SignatureAudit::HUB_IMAGE_SERVED, [
            'subject_user_id' => $userId,
            'actor_user_id'   => null,
            'actor_type'      => 'system',
            'signature_id'    => $signature->id,
            'app'             => $app->client_id,
            'personnel_key'   => $sub,
            'context'         => [
                'app_name'     => $app->name,
                'image_sha256' => $sha256,
                'version_id'   => $signature->hub_version_id,
            ],
        ]);

        return response($bytes, 200, $headers + [
            'Content-Type'           => 'image/png',
            'Content-Length'         => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function certificate(string $fingerprint): JsonResponse
    {
        if (! preg_match('/^[A-Fa-f0-9]{64}$/', $fingerprint)) {
            throw new HubApiException(422, 'invalid_request', 'The fingerprint must be 64 hex characters (SHA-256).');
        }

        $cert = $this->certificates->find($fingerprint);

        if ($cert === null) {
            return response()->json(['fingerprint' => strtolower($fingerprint), 'status' => 'unknown']);
        }

        return response()->json(array_filter([
            'fingerprint' => strtolower($cert->fingerprint),
            'status'      => $cert->isRevoked() ? 'revoked' : 'valid',
            'revoked_at'  => $cert->revoked_at?->toIso8601String(),
            'expires_at'  => $cert->expires_at?->toIso8601String(),
            'subject'     => $cert->subject_dn,
        ], fn ($v) => $v !== null));
    }

    /**
     * @return array{0: int, 1: Signature}
     *
     * @throws HubApiException
     */
    private function signature(string $sub): array
    {
        // Only a verified claim's signature: until an admin verifies it, the
        // account could belong to someone who picked another person's name (R8).
        $identity = $this->people->identity($sub);
        $userId = $identity?->isVerified() ? (int) $identity->user_id : null;
        $signature = $userId !== null ? $this->specimens->current($userId) : null;

        if ($signature === null) {
            throw new HubApiException(404, 'no_signature', 'This person has no active, verified signature at the hub.');
        }

        return [$userId, $signature];
    }

    /** RFC 9110 If-None-Match: a list of (possibly weak) tags, or `*`. */
    private function matches(?string $header, string $sha256): bool
    {
        if ($header === null || $header === '') {
            return false;
        }

        foreach (explode(',', $header) as $tag) {
            $tag = trim($tag);

            if ($tag === '*' || trim(preg_replace('/^W\//', '', $tag), '"') === $sha256) {
                return true;
            }
        }

        return false;
    }
}
