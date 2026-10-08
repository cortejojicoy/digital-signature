<?php

namespace Kukux\DigitalSignature\Http\Controllers\Agent;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Agent\AgentJobService;
use Kukux\DigitalSignature\Agent\AgentPresenceService;
use Kukux\DigitalSignature\Client\HubSigning;
use Kukux\DigitalSignature\Security\DeviceRegistry;
use Kukux\DigitalSignature\Support\SignatureMode;

/**
 * The browser's side of an agent approval (resources/js/utils/agentApproval.js):
 * poll the job while the user approves on their computer, or choose to sign
 * in the browser instead. Also the presence check that decides whether this
 * browser is on the paired computer at all (resources/js/utils/agentPresence.js).
 *
 * Client mode: the job is the hub's. job() answers from the HubPendingSign
 * the approval is for, asking the hub when it is still pending, in the same
 * shape the overlay polls for; there is no signing in the browser instead.
 */
class AgentWebController extends Controller
{
    public function job(AgentJobService $jobs, string $uuid): JsonResponse
    {
        $status = SignatureMode::isClient()
            ? app(HubSigning::class)->webStatus($this->userId(), $uuid)
            : $jobs->webStatus($this->userId(), $uuid);

        abort_if($status === null, 404);

        return response()->json($status);
    }

    public function skip(DeviceRegistry $registry): JsonResponse
    {
        abort_if(
            SignatureMode::isClient() || config('signature.devices.agent.approval') === 'enforce',
            403,
            'Signing requires approval on your computer.',
        );

        $registry->skipAgent($this->userId());

        return response()->json(['skipped' => true]);
    }

    public function startPresence(AgentPresenceService $presence): JsonResponse
    {
        $userId = $this->userId();

        abort_unless($presence->required(), 404);

        if ($presence->pairedComputer($userId) === null) {
            return response()->json(['status' => 'unpaired'], 409);
        }

        return response()->json($presence->start($userId));
    }

    public function presence(AgentPresenceService $presence, string $uuid): JsonResponse
    {
        $status = $presence->poll($this->userId(), $uuid);

        abort_if($status === null, 404);

        return response()->json($status);
    }

    private function userId(): int
    {
        $userId = auth()->id();

        abort_unless($userId, 401);

        return (int) $userId;
    }
}
