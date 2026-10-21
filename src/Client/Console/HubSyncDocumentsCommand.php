<?php

namespace Kukux\DigitalSignature\Client\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kukux\DigitalSignature\Client\Exceptions\HubException;
use Kukux\DigitalSignature\Client\HubClient;
use Kukux\DigitalSignature\Client\HubDocumentReporter;
use Kukux\DigitalSignature\Models\SigningSession;

/**
 * Client mode: send routed documents to the hub, so its verify page and
 * Kukux Sign Agent can verify their PDFs.
 *
 * Signing events already queue a report for each document as it changes
 * (ReportDocumentToHub). This is for what they don't cover:
 *
 *   - documents signed before this feature existed (run once, no --since);
 *   - a session cancelled or expired, which fires no event;
 *   - reports whose queued retries all ran out during a long hub outage.
 *
 * Schedule it daily with --since=yesterday's date; it is idempotent.
 */
class HubSyncDocumentsCommand extends Command
{
    protected $signature = 'signature:hub-sync-documents
        {--since= : Only sessions changed on or after this date (Y-m-d)}
        {--limit=1000 : Most documents to send in one run}';

    protected $description = 'Report routed documents to the signature hub for PDF verification (client mode)';

    public function handle(HubDocumentReporter $reporter, HubClient $hub): int
    {
        if (! HubDocumentReporter::enabled()) {
            $this->components->warn('Reporting documents to the hub is off (signature.hub.report_documents).');

            return self::SUCCESS;
        }

        $since = $this->option('since');

        $sessions = SigningSession::query()
            ->whereNotNull('base_document_hash')
            ->when($since, fn ($q) => $q->where('updated_at', '>=', Carbon::parse($since)->startOfDay()))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($sessions as $session) {
            if ($hub->isOpen()) {
                $this->components->warn('The hub stopped answering; run this again later to send the rest.');

                break;
            }

            try {
                $reporter->report($session) ? $counts['sent']++ : $counts['skipped']++;
            } catch (HubException $e) {
                $counts['failed']++;
                $this->components->twoColumnDetail((string) $session->uuid, '<fg=red>'.$e->getMessage().'</>');
            }
        }

        $this->components->info(sprintf(
            '%d sent, %d failed, %d with nothing to report yet.',
            $counts['sent'], $counts['failed'], $counts['skipped'],
        ));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
