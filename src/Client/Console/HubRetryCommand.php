<?php

namespace Kukux\DigitalSignature\Client\Console;

use Illuminate\Console\Command;
use Kukux\DigitalSignature\Client\Exceptions\HubSigningException;
use Kukux\DigitalSignature\Client\HubClient;
use Kukux\DigitalSignature\Client\HubSigning;
use Kukux\DigitalSignature\Models\HubPendingSign;

/**
 * Client mode, after a hub outage (R1): send the sign requests that couldn't
 * be sent, and ask the hub about the ones still waiting. Schedule it every
 * few minutes; the signer only re-approves on their computer.
 *
 *   unsent    POST again with the same idempotency key, so a request the hub
 *             did receive is never signed twice
 *   pending   older than --stale seconds: GET its status (a missed webhook)
 *
 * A signed request is finished the next time its signer opens the document
 * and signs: the CMS is waiting for them.
 */
class HubRetryCommand extends Command
{
    protected $signature = 'signature:hub-retry
        {--stale=120 : Refresh pending requests not updated for this many seconds}
        {--limit=200 : Most requests to handle in one run}';

    protected $description = 'Resubmit unsent hub sign requests and refresh pending ones (client mode)';

    public function handle(HubSigning $signing, HubClient $hub): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $counts = ['sent' => 0, 'refreshed' => 0, 'refused' => 0, 'waiting' => 0];

        $unsent = HubPendingSign::query()->where('status', 'unsent')->orderBy('id')->limit($limit)->get();

        foreach ($unsent as $pending) {
            if ($hub->isOpen()) {
                $counts['waiting'] += 1;

                continue;
            }

            try {
                $signing->submit($pending);
                $counts['sent']++;
            } catch (HubSigningException $e) {
                $e->reason === 'unavailable' ? $counts['waiting']++ : $counts['refused']++;
            }
        }

        $stale = HubPendingSign::query()
            ->whereIn('status', ['pending', 'approved'])
            ->where('updated_at', '<', now()->subSeconds(max(0, (int) $this->option('stale'))))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($stale as $pending) {
            $before = $pending->status;

            if ($signing->refresh($pending)->status !== $before) {
                $counts['refreshed']++;
            } else {
                // Touch it, so the next run asks about the others first.
                $pending->touch();
            }
        }

        $this->components->info(sprintf(
            '%d sent, %d refused, %d still waiting for the hub; %d pending requests changed status.',
            $counts['sent'], $counts['refused'], $counts['waiting'], $counts['refreshed'],
        ));

        return self::SUCCESS;
    }
}
