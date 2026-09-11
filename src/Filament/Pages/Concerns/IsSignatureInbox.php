<?php

namespace Kukux\DigitalSignature\Filament\Pages\Concerns;

use Filament\Notifications\Notification;
use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;
use Kukux\DigitalSignature\Exceptions\OutOfSequenceException;
use Kukux\DigitalSignature\Exceptions\SignatoryNotReadyException;
use Kukux\DigitalSignature\Exceptions\SigningSessionClosedException;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Services\SigningSessionManager;
use Kukux\DigitalSignature\Signatories\RouteState;

/**
 * "Documents waiting for my signature."
 *
 * This is the counterpart to auto-affix, and the reason the default consent
 * model is safe: a signatory never has to hunt for the document, but the
 * signature itself is still produced inside their own authenticated request,
 * with their own certificate.
 *
 * Deliberately plain Livewire methods rather than Filament table actions —
 * the action hierarchy is the part of Filament that moved between v3 and v4,
 * and a page that behaves identically on all three majors is worth more here
 * than table niceties. See IsPdfTemplateDesigner for why `$view` is declared
 * by the version subclasses instead of here.
 */
trait IsSignatureInbox
{
    public ?string $declineReason = null;

    /**
     * Declared as a getter rather than a $navigationIcon property: v3 types
     * that property ?string while v4/v5 widened it to BackedEnum|string|null,
     * and a property type cannot satisfy both. A narrowed *return* type is
     * legal covariance on every version.
     */
    public static function getNavigationIcon(): ?string
    {
        return config('signature.inbox.navigation_icon', 'heroicon-o-inbox-arrow-down');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('signature.inbox.navigation', true);
    }

    public static function getNavigationLabel(): string
    {
        return config('signature.inbox.navigation_label', 'Awaiting my signature');
    }

    public static function getNavigationGroup(): ?string
    {
        return config('signature.inbox.navigation_group');
    }

    public static function getNavigationSort(): ?int
    {
        $sort = config('signature.inbox.navigation_sort');

        return $sort === null ? null : (int) $sort;
    }

    /**
     * Badge showing how many documents are waiting, so the signatory sees
     * the queue without opening the page.
     */
    public static function getNavigationBadge(): ?string
    {
        $userId = auth()->id();

        if (! $userId) {
            return null;
        }

        $count = SignatureRequest::query()->outstandingFor((int) $userId)->count();

        return $count > 0 ? (string) $count : null;
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
