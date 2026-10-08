<?php

namespace Kukux\DigitalSignature\Hub\Identity\Listeners;

use Kukux\DigitalSignature\Events\AgentJobUpdated;
use Kukux\DigitalSignature\Hub\Identity\HubLoginService;
use Kukux\DigitalSignature\Hub\Identity\IdentityTransfer;

/**
 * The agent answered a hub identity job (docs/hub/contracts.md §4):
 *
 *   login     completed → the sign-in challenge is approved; rejected → refused
 *   transfer  completed → the move is applied; rejected → refused. An expired
 *             one stays pending, so an admin can still approve it.
 *
 * Synchronous on purpose: the browser is polling for exactly this.
 */
class HandleIdentityAgentJobs
{
    public function handle(AgentJobUpdated $event): void
    {
        $job = $event->job;

        if ($job->purpose === 'login') {
            app(HubLoginService::class)->jobUpdated($job);

            return;
        }

        if ($job->purpose !== 'transfer' || ! in_array($job->status, ['completed', 'rejected'], true)) {
            return;
        }

        $transfers = app(IdentityTransfer::class);
        $transfer = $transfers->forJob($job);

        if ($transfer === null || $transfer->status !== 'pending') {
            return;
        }

        $job->status === 'completed'
            ? $transfers->apply($transfer, (int) $job->user_id)
            : $transfers->reject($transfer, (int) $job->user_id, 'declined_on_old_computer');
    }
}
