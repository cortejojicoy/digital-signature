<?php

namespace Kukux\DigitalSignature\Http\Controllers\Agent;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Agent\AgentJobService;
use Kukux\DigitalSignature\Security\DeviceRegistry;

/**
 * The browser's side of an agent approval (resources/js/utils/agentApproval.js):
 * poll the job while the user approves on their computer, or choose to sign
 * in the browser instead.
 */
class AgentWebController extends Controller
{
    public function job(AgentJobService $jobs, string $uuid): JsonResponse
    {
        $status = $jobs->webStatus($this->userId(), $uuid);

        abort_if($status === null, 404);

        return response()->json($status);
    }

    public function skip(DeviceRegistry $registry): JsonResponse
    {
        abort_if(config('signature.devices.agent.approval') === 'enforce', 403, 'Signing requires approval on your computer.');

        $registry->skipAgent($this->userId());

        return response()->json(['skipped' => true]);
    }

    private function userId(): int
    {
        $userId = auth()->id();

        abort_unless($userId, 401);

        return (int) $userId;
    }
}
