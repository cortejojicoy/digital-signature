<?php

namespace Kukux\DigitalSignature\Exceptions;

use Illuminate\Http\JsonResponse;
use Kukux\DigitalSignature\Models\AgentJob;
use Kukux\DigitalSignature\Models\SigningDevice;

/**
 * Signing is waiting on the user's paired computer.
 *
 * Thrown by DeviceRegistry::forSigning() with a freshly created job. Each
 * signing surface turns it into its own "approve on your computer" step —
 * HTTP 428 for the JSON endpoints, a browser event for Livewire — and retries
 * once the job completes. See agentApproval() for the payload.
 */
class AgentApprovalRequiredException extends \RuntimeException
{
    public function __construct(
        public readonly AgentJob $job,
        public readonly string $link,
        public readonly ?SigningDevice $device,
        public readonly bool $canSkip,
    ) {
        parent::__construct(
            'Approve this signature on '.($device?->displayName() ?? 'your computer').'.'
        );
    }

    /**
     * What the browser needs to run the approval: open the link, poll the
     * status, and know whether "sign in the browser instead" is allowed.
     *
     * @return array<string, mixed>
     */
    public function agentApproval(): array
    {
        return [
            'job'        => $this->job->uuid,
            'link'       => $this->link,
            'status_url' => route('signature.agent.web.job', $this->job->uuid),
            'skip_url'   => $this->canSkip ? route('signature.agent.web.skip') : null,
            'device'     => $this->device?->displayName(),
            'download_url' => config('signature.devices.agent.download_url'),
            'title'      => $this->job->title,
            'expires_at' => $this->job->expires_at->toIso8601String(),
        ];
    }

    /**
     * The JSON endpoints' answer: 428 Precondition Required, which
     * agentApproval.js recognises, runs, and then retries the same request.
     *
     * @param  array<string, mixed>  $extra
     */
    public function toJsonResponse(array $extra = []): JsonResponse
    {
        return response()->json(
            ['error' => $this->getMessage(), 'agent_approval' => $this->agentApproval()] + $extra,
            428,
        );
    }

    /**
     * What a Livewire surface dispatches as `kukux-signature:agent-approval`:
     * the approval, plus how to retry once it is granted.
     *
     * @param  array<int, mixed>  $params
     * @return array<string, mixed>
     */
    public function livewireEvent(string $componentId, string $method, array $params = []): array
    {
        return [
            'approval' => $this->agentApproval(),
            'retry'    => ['component' => $componentId, 'method' => $method, 'params' => $params],
        ];
    }
}
