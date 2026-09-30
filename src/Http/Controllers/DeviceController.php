<?php

namespace Kukux\DigitalSignature\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Security\DeviceRegistry;

/**
 * Endpoints behind resources/js/utils/deviceAttestation.js.
 *
 *   POST   /signature/devices/challenge   → { nonce, expires_in }
 *   POST   /signature/devices/attest      → { status, device, expires_in }
 *   PATCH  /signature/devices/{uuid}      rename
 *   DELETE /signature/devices/{uuid}      revoke
 *
 * Every endpoint acts only on the signed-in user's own devices.
 */
class DeviceController extends Controller
{
    public function __construct(private readonly DeviceRegistry $registry) {}

    public function challenge(Request $request): JsonResponse
    {
        $userId = $this->userId();

        return response()->json($this->registry->challenge($userId));
    }

    public function attest(Request $request): JsonResponse
    {
        $userId = $this->userId();

        $input = $request->validate([
            'public_key'       => ['required', 'string', 'max:4096'],
            'signature'        => ['required', 'string', 'max:2048'],
            'nonce'            => ['required', 'string', 'max:128'],
            'signature_format' => ['nullable', 'in:raw,der'],
            'label'            => ['nullable', 'string', 'max:120'],
            'hints'            => ['nullable', 'array'],
            'hints.touch'      => ['nullable', 'boolean'],
            'hints.mobile'     => ['nullable', 'boolean'],
            'hints.platform'   => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $result = $this->registry->attest($userId, $input);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status'      => $result['status'],
            'fingerprint' => $result['fingerprint'],
            'expires_in'  => $result['expires_in'],
            'device'      => $result['device'] ? $this->present($result['device']) : null,
        ], $result['status'] === 'revoked' ? 409 : 200);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $device = $this->ownDevice($uuid);

        $label = $request->validate(['label' => ['required', 'string', 'max:120']])['label'];

        return response()->json(['device' => $this->present($this->registry->rename($device, $label))]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $device = $this->ownDevice($uuid);

        $this->registry->revoke($device);

        return response()->json(['device' => $this->present($device->refresh())]);
    }

    private function userId(): int
    {
        $userId = auth()->id();

        abort_unless($userId, 401);

        return (int) $userId;
    }

    private function ownDevice(string $uuid): SigningDevice
    {
        return SigningDevice::query()
            ->where('uuid', $uuid)
            ->where('user_id', $this->userId())
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SigningDevice $device): array
    {
        return [
            'uuid'        => $device->uuid,
            'label'       => $device->displayName(),
            'status'      => $device->status,
            'device_type' => $device->device_type,
            'protection'  => $device->protection,
            'fingerprint' => $device->shortFingerprint(),
        ];
    }
}
