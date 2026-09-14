<?php

namespace Kukux\DigitalSignature\Filament\Concerns;

use Filament\Notifications\Notification;
use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;
use Kukux\DigitalSignature\Exceptions\OutOfSequenceException;
use Kukux\DigitalSignature\Exceptions\SignatoryNotReadyException;
use Kukux\DigitalSignature\Exceptions\SigningSessionClosedException;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Services\SigningSessionManager;
use Kukux\DigitalSignature\Signatories\RouteState;

/**
 * Sign / decline the documents waiting on the authenticated user.
 *
 * Shared by the full-page inbox and the floating launcher's slide-over so the
 * two surfaces can never disagree about what a signatory may do: the signature
 * is produced in this user's own authenticated request with their own
 * certificate either way, which is what keeps the default consent model
 * honest.
 *
 * Deliberately plain Livewire methods rather than Filament table actions — the
 * action hierarchy is the part of Filament that moved between v3 and v4, and
 * behaviour identical on all three majors is worth more here than table
 * niceties.
 */
trait ActsOnSignatureRequests
{
    public ?string $declineReason = null;

    /**
     * How many documents are waiting on a user. Used by the inbox navigation
     * badge and the launcher's count, so both read the same number.
     */
    public static function outstandingCountFor(int|string|null $userId): int
    {
        if (! $userId) {
            return 0;
        }

        return SignatureRequest::query()->outstandingFor((int) $userId)->count();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, SignatureRequest>
     */
    public function getRequestsProperty()
    {
        $userId = auth()->id();

        if (! $userId) {
            return SignatureRequest::query()->whereRaw('1 = 0')->get();
        }

        return SignatureRequest::query()
            ->outstandingFor((int) $userId)
            ->with(['session.signable', 'user'])
            ->orderBy('signing_session_id')
            ->orderBy('sequence')
            ->get();
    }

    /**
     * What this user has already signed, newest first, grouped by the day they
     * signed it.
     *
     * A signed document does not stop being the signatory's business. They are
     * the one whose certificate is on it, and "which of these did I actually
     * sign, and when?" is a question they should be able to answer without
     * asking whoever sent it. Grouping by day is what turns a list into a
     * record: signing happens in bursts, and the date is the thing people
     * actually remember.
     *
     * Read-only by construction — nothing here can act on a request, and the
     * signing path refuses an already-signed slot regardless.
     *
     * @return array<int, array{label: string, date: string, requests: \Illuminate\Support\Collection<int, SignatureRequest>}>
     */
    public function getSignedHistoryProperty(): array
    {
        $userId = auth()->id();

        if (! $userId) {
            return [];
        }

        return SignatureRequest::query()
            ->signedFor((int) $userId)
            ->with(['session.signable'])
            // Capped rather than paginated: this is a drawer, and a signatory
            // looking for something older than their last fifty signatures
            // wants a searchable page, not more scrolling.
            ->limit(50)
            ->get()
            ->groupBy(fn (SignatureRequest $request): string => $this->signedOn($request)->toDateString())
            ->map(fn ($requests, string $date): array => [
                'date'     => $date,
                'label'    => $this->dayLabel($this->signedOn($requests->first())),
                'requests' => $requests,
            ])
            ->values()
            ->all();
    }

    /**
     * When a request was signed. `responded_at` is set at the moment of
     * signing; `updated_at` is the fallback for rows written before that
     * column existed, so history never silently drops a signature.
     */
    protected function signedOn(SignatureRequest $request): \Illuminate\Support\Carbon
    {
        return $request->responded_at ?? $request->updated_at ?? now();
    }

    protected function dayLabel(\Illuminate\Support\Carbon $moment): string
    {
        return match (true) {
            $moment->isToday()     => 'Today',
            $moment->isYesterday() => 'Yesterday',
            $moment->isCurrentYear() => $moment->format('j F'),
            default                => $moment->format('j F Y'),
        };
    }

    /**
     * Sign one request. The signature is produced here, in the signatory's
     * own request — which is what makes this the safe default path.
     */
    public function signRequest(int $requestId): void
    {
        $request = $this->ownedRequest($requestId);

        if ($request === null) {
            return;
        }

        try {
            app(SigningSessionManager::class)->sign($request, (int) auth()->id());
        } catch (OutOfSequenceException|SignatoryNotReadyException|SigningSessionClosedException|ForgedSignatureException $e) {
            $this->fail('Cannot sign yet', $e->getMessage());

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->fail('Signing failed', $e->getMessage());

            return;
        }

        Notification::make()
            ->title('Signed')
            ->body('Your signature has been applied to the document.')
            ->success()
            ->send();
    }

    public function declineRequest(int $requestId, ?string $reason = null): void
    {
        $request = $this->ownedRequest($requestId);

        if ($request === null) {
            return;
        }

        try {
            app(SigningSessionManager::class)->decline(
                $request,
                (int) auth()->id(),
                $reason ?? $this->declineReason,
            );
        } catch (\Throwable $e) {
            $this->fail('Could not decline', $e->getMessage());

            return;
        }

        $this->declineReason = null;

        Notification::make()
            ->title('Declined')
            ->body('The requester has been notified that you declined to sign.')
            ->warning()
            ->send();
    }

    /**
     * Load a request and verify it belongs to the current user and is still
     * actionable. Returns null (after notifying) rather than throwing, so a
     * stale page doesn't 500.
     */
    protected function ownedRequest(int $requestId): ?SignatureRequest
    {
        $request = SignatureRequest::query()
            ->whereKey($requestId)
            ->where('user_id', auth()->id())
            ->whereIn('state', [
                RouteState::AwaitingConsent->value,
                RouteState::Ready->value,
            ])
            ->first();

        if ($request === null) {
            $this->fail(
                'No longer available',
                'That request is not assigned to you, or has already been actioned.',
            );
        }

        return $request;
    }

    protected function fail(string $title, string $body): void
    {
        Notification::make()->title($title)->body($body)->danger()->send();
    }
}
