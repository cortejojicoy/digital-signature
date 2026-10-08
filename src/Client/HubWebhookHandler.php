<?php

namespace Kukux\DigitalSignature\Client;

use Illuminate\Support\Facades\Log;
use Kukux\DigitalSignature\Models\HubAccount;
use Kukux\DigitalSignature\Models\HubPendingSign;
use Kukux\DigitalSignature\Models\SignatureAudit;

/**
 * What each hub webhook does here (A6, docs/hub/contracts.md §3).
 *
 *   sign_request.completed  record the CMS (or the refusal) on the pending
 *                           request; the signer's retry injects it
 *   signature.updated       re-pull the mirror
 *   signature.revoked       delete the mirror, block pending requests
 *   person.separated        the same
 *   signature.flagged       log it and leave an audit row naming the local
 *                           signatures made from the flagged hashes
 *
 * Webhooks only keep screens and trays accurate. Revocation is enforced by
 * the hub refusing at sign time, so a missed one is a display issue (R6).
 * Events about people or requests this app doesn't know are accepted and
 * ignored: the hub sends to every holder.
 */
class HubWebhookHandler
{
    public const FLAGGED = 'hub.signature_flagged';

    public function __construct(
        protected HubSignatureSync $sync,
        protected HubSigning $signing,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(string $event, array $data): void
    {
        match ($event) {
            'sign_request.completed' => $this->signRequestCompleted($data),
            'signature.updated'      => $this->signatureUpdated($data),
            'signature.revoked'      => $this->sync->revoke(
                (string) ($data['sub'] ?? ''),
                isset($data['uuid']) ? (string) $data['uuid'] : null,
                (string) ($data['reason'] ?? 'revoked'),
            ),
            'person.separated'       => $this->sync->revoke((string) ($data['sub'] ?? ''), null, 'separated'),
            'signature.flagged'      => $this->signatureFlagged($data),
            default                  => Log::info('Ignored an unknown hub webhook.', ['event' => $event]),
        };
    }

    /** @param  array<string, mixed>  $data */
    protected function signRequestCompleted(array $data): void
    {
        $pending = HubPendingSign::query()->where('hub_request_id', (string) ($data['id'] ?? ''))->first();

        if ($pending !== null) {
            $this->signing->apply($pending, $data);
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function signatureUpdated(array $data): void
    {
        $userId = HubAccount::userIdFor((string) ($data['sub'] ?? ''));

        if ($userId !== null) {
            $this->sync->pull($userId);
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function signatureFlagged(array $data): void
    {
        $hashes = array_values(array_filter((array) ($data['document_hashes'] ?? []), 'is_string'));
        $userId = HubAccount::userIdFor((string) ($data['sub'] ?? ''));

        // The hashes are the digests the hub signed: ours on HubPendingSign.
        $affected = $hashes === [] ? collect() : HubPendingSign::query()
            ->whereIn('document_hash', $hashes)
            ->get(['id', 'user_id', 'signature_request_id', 'payload']);

        $signatureIds = $affected->map(fn (HubPendingSign $p) => $p->payload['signature_id'] ?? null)->filter()->values()->all();

        Log::warning('hub.signature_flagged', [
            'sub'        => $data['sub'] ?? null,
            'window'     => $data['window'] ?? null,
            'hashes'     => count($hashes),
            'signatures' => $signatureIds,
        ]);

        SignatureAudit::record(self::FLAGGED, [
            'subject_user_id' => $userId,
            'actor_type'      => 'system',
            'context'         => [
                'sub'             => $data['sub'] ?? null,
                'window'          => $data['window'] ?? null,
                'document_hashes' => $hashes,
                'signature_ids'   => $signatureIds,
                'requests'        => $affected->pluck('signature_request_id')->filter()->values()->all(),
            ],
        ]);
    }
}
