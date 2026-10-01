<?php

namespace Kukux\DigitalSignature\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Kukux\DigitalSignature\Agent\AgentApiException;
use Kukux\DigitalSignature\Models\AgentToken;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Security\DeviceProofVerifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a paired agent: its bearer token, and a proof signed by the
 * device's session key over this exact request.
 *
 *   Authorization: Bearer <token>
 *   X-Agent-Timestamp: <unix seconds>   (±60 s)
 *   X-Agent-Nonce: <b64url>             (never reused per device)
 *   X-Agent-Proof: <ES256 DER of v1|request|<nonce>|<user_id>|
 *                   sha256("<METHOD>|<path+query>|<raw body>|<timestamp>")>
 *
 * A leaked token is useless without the session key, which lives in the
 * machine's Secure Enclave / TPM. Any 401 makes the agent forget the pairing.
 */
class AuthenticateAgent
{
    public const ATTRIBUTE = 'signature.agent_device';

    private const SKEW = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $token = AgentToken::query()
            ->where('token_hash', hash('sha256', (string) $request->bearerToken()))
            ->whereNull('revoked_at')
            ->with('device')
            ->first();

        $device = $token?->device;

        if (! $device instanceof SigningDevice || ! $device->isActive() || $device->kind !== 'agent' || ! $device->session_public_key) {
            throw new AgentApiException(401, 'unauthenticated', 'This computer is no longer paired. Pair it again from your signing devices.');
        }

        $timestamp = (string) $request->header('X-Agent-Timestamp', '');

        if (! ctype_digit($timestamp) || abs(now()->getTimestamp() - (int) $timestamp) > self::SKEW) {
            throw new AgentApiException(401, 'stale_request', 'Request timestamp outside ±60 s. Check this computer\'s clock.');
        }

        $nonce = (string) $request->header('X-Agent-Nonce', '');

        if (! preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)) {
            throw new AgentApiException(401, 'replayed_request', 'Request nonce missing or malformed.');
        }

        $payload = hash('sha256', implode('|', [
            $request->getMethod(),
            $request->getRequestUri(),
            $request->getContent(),
            $timestamp,
        ]));

        $proof = base64_decode((string) $request->header('X-Agent-Proof', ''), true);
        $message = DeviceProofVerifier::message('request', $nonce, $device->user_id, $payload);

        if ($proof === false || ! DeviceProofVerifier::verify($device->session_public_key, 'ES256', $message, $proof, 'der')) {
            throw new AgentApiException(401, 'invalid_request_proof', 'X-Agent-Proof did not verify.');
        }

        // Only a verified request may claim a nonce, so nobody can burn an
        // agent's nonces without its key. Kept well past the skew window.
        if (! Cache::add("signature:agent-nonce:{$device->id}:{$nonce}", true, self::SKEW * 5)) {
            throw new AgentApiException(401, 'replayed_request', 'Request nonce was already used.');
        }

        $token->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set(self::ATTRIBUTE, $device);

        return $next($request);
    }
}
