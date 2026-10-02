<?php

namespace Kukux\DigitalSignature\Http\Controllers\Agent;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Agent\AgentApiException;
use Kukux\DigitalSignature\Agent\AgentJobService;
use Kukux\DigitalSignature\Agent\AgentPairingService;
use Kukux\DigitalSignature\Http\Middleware\AuthenticateAgent;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Security\DeviceRegistry;

/**
 * Every endpoint the desktop agent calls, under /signature/agent.
 *
 * Errors are always the protocol's `{"error":{"code","message"}}` (see
 * AgentApiException) and never a redirect — the agent refuses redirects, so
 * nothing here uses $request->validate().
 *
 * Wire contract: digital-signature-agent/docs/protocol.md.
 */
class AgentController extends Controller
{
    public function __construct(
        private readonly AgentPairingService $pairings,
        private readonly AgentJobService $jobs,
    ) {}

    // ── Pairing (unauthenticated: the code and the proofs carry the trust) ──

    public function lookup(Request $request): JsonResponse
    {
        return response()->json($this->pairings->lookup($this->string($request, 'user_code', 16)));
    }

    public function claim(Request $request, string $pairing): JsonResponse
    {
        return response()->json($this->pairings->claim($pairing, $this->body($request), $request->ip()));
    }

    public function poll(Request $request, string $pairing): JsonResponse
    {
        return response()->json($this->pairings->poll($pairing, $this->string($request, 'poll_secret', 128)));
    }

    // ── Authenticated (bearer + X-Agent-Proof) ──

    public function status(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $user = $device->user;

        // Where else this account can sign (multi-app-pairing-plan.md §7.4).
        $others = SigningDevice::query()
            ->where('user_id', $device->user_id)
            ->whereKeyNot($device->id)
            ->active()
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SigningDevice $other) => [
                'uuid'         => $other->uuid,
                'label'        => $other->displayName(),
                'device_type'  => $other->deviceType()->value,
                'last_used_at' => $other->last_used_at?->toIso8601String(),
            ])
            ->all();

        return response()->json([
            'device'        => ['uuid' => $device->uuid, 'label' => $device->displayName(), 'status' => $device->status],
            'user'          => ['id' => (string) $device->user_id, 'name' => (string) ($user->name ?? $user->email ?? '')],
            'other_devices' => $others,
        ]);
    }

    public function unpair(Request $request, DeviceRegistry $registry): Response
    {
        $registry->revoke($this->device($request));

        return response()->noContent();
    }

    public function claimJob(Request $request, string $job): JsonResponse
    {
        return response()->json($this->jobs->claim($this->device($request), $job, $this->string($request, 'link_token', 128)));
    }

    public function completeJob(Request $request, string $job): JsonResponse
    {
        return response()->json($this->jobs->complete($this->device($request), $job, $this->string($request, 'proof', 2048)));
    }

    public function rejectJob(Request $request, string $job): JsonResponse
    {
        return response()->json($this->jobs->reject($this->device($request), $job, $this->string($request, 'reason', 32)));
    }

    // ── Helpers ──

    private function device(Request $request): SigningDevice
    {
        return $request->attributes->get(AuthenticateAgent::ATTRIBUTE);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $body = json_decode($request->getContent() ?: '{}', true);

        if (! is_array($body)) {
            throw new AgentApiException(400, 'invalid_json', 'The request body must be a JSON object.');
        }

        return $body;
    }

    private function string(Request $request, string $key, int $max): string
    {
        $value = $this->body($request)[$key] ?? '';

        if (! is_string($value) || $value === '' || strlen($value) > $max) {
            throw new AgentApiException(422, 'invalid_request', "Missing or invalid `{$key}`.");
        }

        return $value;
    }
}
