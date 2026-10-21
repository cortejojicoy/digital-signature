<?php

namespace Kukux\DigitalSignature\Client\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kukux\DigitalSignature\Client\Exceptions\HubException;
use Kukux\DigitalSignature\Client\Exceptions\HubUnavailableException;
use Kukux\DigitalSignature\Client\HubDocumentReporter;
use Kukux\DigitalSignature\Models\SigningSession;

/**
 * Client mode: send one routed document's current state to the hub.
 *
 * Queued so a slow or absent hub never holds up the signatory, and retried
 * with backoff through an outage. It sends the session as it is when the job
 * runs, not as it was when it was queued, so jobs that run late or out of
 * order still leave the hub with the latest state. If every retry fails,
 * `signature:hub-sync-documents` sends it later.
 */
class ReportDocumentToHub implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public readonly int $sessionId)
    {
        $this->onConnection(config('signature.queue_connection'));
        $this->onQueue(config('signature.queue', 'default'));
    }

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(HubDocumentReporter $reporter): void
    {
        $session = SigningSession::query()->find($this->sessionId);

        if ($session === null) {
            return;
        }

        try {
            $reporter->report($session);
        } catch (HubUnavailableException $e) {
            // On the sync driver this runs inside the request that signed the
            // document, and throwing would fail that signature over a report
            // nobody is waiting for. Log it; signature:hub-sync-documents
            // sends it later. On a real queue, throw so the queue retries.
            if ($this->runsInline()) {
                report($e);

                return;
            }

            throw $e;
        } catch (HubException $e) {
            // The hub answered no (a 4xx, including a hub too old to have
            // this endpoint): retrying the same report won't change that.
            // Fail now, and say why, instead of four more times.
            $this->fail($e);
        }
    }

    /** True on the sync driver (or when called directly): no queue to retry. */
    protected function runsInline(): bool
    {
        return $this->job === null || $this->job->getConnectionName() === 'sync';
    }
}
