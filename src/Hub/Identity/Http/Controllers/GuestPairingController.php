<?php

namespace Kukux\DigitalSignature\Hub\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Hub\Identity\GuestPairing;
use Kukux\DigitalSignature\Hub\Identity\HubRedirector;
use Kukux\DigitalSignature\Hub\Identity\IdentityException;

/**
 * The landing page's "Pair this computer" (plan 1.1–1.2). JSON for the
 * page's script; errors are the hub's `{"error","message"}`.
 *
 *   POST   signature/hub/pairings                 start (guest)
 *   GET    signature/hub/pairings/{uuid}          poll: pending, or the claimed computer
 *   POST   signature/hub/pairings/{uuid}/confirm  confirm → signed in → "Who are you?"
 *   POST   signature/hub/pairings/{uuid}/cancel   "That's not mine"
 *
 * Only the browser that started a pairing can see or confirm it.
 */
class GuestPairingController extends Controller
{
    public function __construct(private readonly GuestPairing $pairing) {}

    public function store(Request $request): JsonResponse
    {
        if ($request->user() !== null) {
            return $this->error('already_signed_in', 'You are signed in. Pair from your Profile instead.', 409);
        }

        try {
            return response()->json($this->pairing->start($request->session(), $request->ip()), 201);
        } catch (IdentityException $e) {
            return $this->error($e->reason, $e->getMessage(), $e->reason === 'rate_limited' ? 429 : 422);
        }
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $state = $this->pairing->describe($request->session(), $uuid);

        return $state === null
            ? $this->error('not_found', 'Pairing not found.', 404)
            : response()->json($state);
    }

    public function confirm(Request $request, string $uuid): JsonResponse
    {
        $type = $request->input('device_type');

        try {
            $device = $this->pairing->confirm($request->session(), $uuid, is_string($type) ? $type : null);
        } catch (IdentityException $e) {
            return $this->error($e->reason, $e->getMessage(), $e->reason === 'not_found' ? 404 : 422);
        }

        return response()->json([
            'status'   => 'confirmed',
            'device'   => ['label' => $device->displayName()],
            'redirect' => app(HubRedirector::class)->afterSignIn($request->user()),
        ]);
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $this->pairing->cancel($request->session(), $uuid);

        return response()->json(['status' => 'cancelled']);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
