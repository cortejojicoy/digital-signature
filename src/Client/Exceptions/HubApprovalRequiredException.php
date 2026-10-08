<?php

namespace Kukux\DigitalSignature\Client\Exceptions;

use Illuminate\Support\Carbon;
use Kukux\DigitalSignature\Exceptions\AgentApprovalRequiredException;
use Kukux\DigitalSignature\Models\AgentJob;
use Kukux\DigitalSignature\Models\HubPendingSign;

/**
 * Client mode: the hub accepted the sign request and is waiting on the
 * signer's computer.
 *
 * A subclass of AgentApprovalRequiredException so every signing surface that
 * already handles "approve on your computer" (428 for the JSON endpoints, the
 * Livewire event for the inbox and actions) handles this one unchanged: the
 * browser opens the hub's `kukuxsign://job/…` link, polls
 * `signature/agent-web/jobs/{job}` (which AgentWebController answers from the
 * HubPendingSign in client mode) and retries the same call once it completes.
 *
 * The job lives at the hub, so the parent's AgentJob is an unsaved stand-in
 * carrying only what the overlay shows: its uuid, title and expiry.
 */
class HubApprovalRequiredException extends AgentApprovalRequiredException
{
    public function __construct(public readonly HubPendingSign $pending)
    {
        $payload = $pending->payload ?? [];

        $job = (new AgentJob)->forceFill([
            // The overlay polls by this id. The hub always returns job_uuid;
            // the request id is a uuid too, so it is the safe fallback.
            'uuid'       => $pending->agent_job_uuid ?: $pending->hub_request_id,
            'title'      => (string) ($payload['title'] ?? 'Document'),
            'expires_at' => isset($payload['expires_at'])
                ? Carbon::parse($payload['expires_at'])
                : now()->addSeconds((int) config('signature.devices.agent.job_ttl', 300)),
        ]);

        parent::__construct($job, (string) ($payload['approval_link'] ?? ''), null, canSkip: false);
    }
}
