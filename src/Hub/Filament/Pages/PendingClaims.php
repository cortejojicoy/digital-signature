<?php

namespace Kukux\DigitalSignature\Hub\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Kukux\DigitalSignature\Hub\Identity\IdentityException;
use Kukux\DigitalSignature\Hub\Identity\IdentityService;
use Kukux\DigitalSignature\Hub\Identity\IdentityTransfer;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\Transfer;
use Throwable;

/**
 * Admin: claims waiting for a person to be confirmed (plan 1.7, D9, R8, R11).
 *
 *   Claims     verify, or reject (releases the computer, blocks it, retires the account)
 *   Transfers  a new computer for someone already linked: approve when the
 *              old computer is lost (after checking their ID), or refuse
 *
 * Oldest first, so a claim never sits past two working days unnoticed.
 */
class PendingClaims extends Page
{
    protected string $view = 'signature::hub.pages.pending-claims';

    protected static ?string $slug = 'pending-claims';

    protected static ?int $navigationSort = 2;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-shield-check';
    }

    public static function getNavigationLabel(): string
    {
        return 'Pending claims';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Identity::query()->where('status', Identity::PENDING)->count()
            + Transfer::query()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Pending claims';
    }

    public function verify(int $userId, IdentityService $identities): void
    {
        $this->attempt(fn () => $identities->verify($userId, (int) auth()->id()), 'Identity verified.');
    }

    public function reject(int $userId, IdentityService $identities): void
    {
        $this->attempt(fn () => $identities->reject($userId, (int) auth()->id(), 'rejected_by_admin'), 'Claim rejected; the computer is released and blocked.');
    }

    public function approveTransfer(int $transferId, IdentityTransfer $transfers): void
    {
        $transfer = Transfer::query()->where('status', 'pending')->find($transferId);

        if ($transfer !== null) {
            $this->attempt(fn () => $transfers->apply($transfer, (int) auth()->id(), byAdmin: true), 'Transfer approved; the old computer is released.');
        }
    }

    public function rejectTransfer(int $transferId, IdentityTransfer $transfers): void
    {
        $transfer = Transfer::query()->where('status', 'pending')->find($transferId);

        if ($transfer !== null) {
            $this->attempt(fn () => $transfers->reject($transfer, (int) auth()->id(), 'refused_by_admin'), 'Transfer refused.');
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $identities = app(IdentityService::class);
        $find = function (?string $key) use ($identities) {
            try {
                return $key ? $identities->directory()->find($key) : null;
            } catch (Throwable) {
                return null;
            }
        };

        $claims = Identity::query()->where('status', Identity::PENDING)->with('user')->oldest('claimed_at')->get()
            ->map(fn (Identity $identity) => [
                'identity' => $identity,
                'person'   => $find($identity->personnel_key),
                'computer' => $identities->computer((int) $identity->user_id),
                // R8 "detect": one person claimed by several accounts.
                'claims'   => Identity::query()->where('personnel_key', $identity->personnel_key)->count(),
            ]);

        $transfers = Transfer::query()->where('status', 'pending')->with('agentJob')->oldest()->get()
            ->map(fn (Transfer $transfer) => [
                'transfer'    => $transfer,
                'person'      => $find($transfer->personnel_key),
                'oldComputer' => $identities->computer((int) $transfer->from_user_id),
                'newComputer' => $identities->computer((int) $transfer->to_user_id),
            ]);

        return ['claims' => $claims, 'transfers' => $transfers];
    }

    private function attempt(callable $action, string $done): void
    {
        try {
            $action();
        } catch (IdentityException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($done)->success()->send();
    }
}
