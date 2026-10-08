<?php

namespace Kukux\DigitalSignature\Hub\Signing;

use Kukux\DigitalSignature\Events\AgentJobUpdated;

/**
 * When the agent approves, declines or lets a `sign_receipt` job expire,
 * finish the hub sign request it belongs to (if any).
 *
 * Synchronous on purpose: it runs inside the agent's /complete call, so the
 * CMS exists by the time the agent reports success, and the keys it loads
 * never travel through a queue payload. Errors never reach the agent: the
 * request becomes `failed` instead.
 */
class CompleteHubSignRequest
{
    public function __construct(private readonly HubSignRequestService $requests) {}

    public function handle(AgentJobUpdated $event): void
    {
        try {
            $this->requests->onJobUpdated($event->job);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
