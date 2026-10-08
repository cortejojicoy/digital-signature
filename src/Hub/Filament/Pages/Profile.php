<?php

namespace Kukux\DigitalSignature\Hub\Filament\Pages;

use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Kukux\DigitalSignature\Filament\Concerns\RegistersSignatures;
use Kukux\DigitalSignature\Hub\Identity\AuditPresenter;
use Kukux\DigitalSignature\Hub\Identity\HubAccess;
use Kukux\DigitalSignature\Hub\Identity\HubApiBridge;
use Kukux\DigitalSignature\Hub\Identity\HubRedirector;
use Kukux\DigitalSignature\Hub\Identity\IdentityService;
use Kukux\DigitalSignature\Hub\Identity\IdentityTransfer;
use Kukux\DigitalSignature\Hub\Personnel;
use Kukux\DigitalSignature\Models\HubHolder;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\Transfer;
use Kukux\DigitalSignature\Models\UserCertificate;
use LogicException;

/**
 * The person panel's home (plan 1.4): who you are, your computer, your
 * signature, the apps holding a copy of it, and what has been done with it.
 *
 * No topbar on this panel, so the header carries what the user menu would:
 * Sign out, and "Open admin panel" for a super_admin (plan 1.8).
 *
 * Devices are the package's own SigningDevices component; the signature is
 * drawn or uploaded through RegistersSignatures, the same rules the launcher
 * and the Signatures resource use, then handed to the hub's specimen
 * service so apps holding a mirror pick it up.
 */
class Profile extends Page
{
    use RegistersSignatures;

    protected string $view = 'signature::hub.pages.profile';

    protected static ?string $slug = 'profile';

    protected static bool $shouldRegisterNavigation = false;

    /** PNG data URL from the pad (drawn) or the upload. */
    public ?string $newSignature = null;

    public ?string $newCertificatePassword = null;

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Profile';
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function createSignature(HubApiBridge $api): void
    {
        $signature = $this->registerSignature($this->newSignature, $this->newCertificatePassword);

        $this->newCertificatePassword = null;

        if ($signature === null) {
            return;
        }

        $this->newSignature = null;

        SignatureAudit::record(SignatureAudit::SIGNATURE_CREATED, [
            'subject_user_id' => $this->userId(),
            'signature_id'    => $signature->id,
            'context'         => ['image_sha256' => $signature->image_hash, 'source' => $signature->source],
        ]);

        // Stored either way; usable once the identity is verified (D11).
        $api->specimenPublished($signature);
    }

    /** "Approve on this computer": a fresh kukuxsign:// link for the old computer's transfer job. */
    public function approveTransfer(int $transferId, IdentityTransfer $transfers): void
    {
        $transfer = $this->outgoing()->firstWhere('id', $transferId);
        $link = $transfer ? $transfers->approvalLink($transfer, $this->userId()) : null;

        if ($link === null) {
            $this->signatureFailure('Request no longer pending', 'That move has expired or was already decided.');

            return;
        }

        // Livewire sets window.location to it: the OS hands it to the agent.
        $this->redirect($link);
    }

    public function refuseTransfer(int $transferId, IdentityTransfer $transfers): void
    {
        if ($transfer = $this->outgoing()->firstWhere('id', $transferId)) {
            $transfers->reject($transfer, $this->userId(), 'refused_on_profile');
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $userId = $this->userId();
        $identity = Identity::forUser($userId);
        $key = $identity?->personnel_key;

        return [
            'user'        => auth()->user(),
            'identity'    => $identity,
            'person'      => $key ? $this->person($key) : null,
            'signature'   => Signature::query()->primaryActiveFor($userId)->latest('id')->first(),
            'certificate' => UserCertificate::query()->where('user_id', $userId)->whereNull('revoked_at')->latest('id')->first(),
            'canRegister' => $this->canRegisterSignature(),
            'apps'        => $key ? HubHolder::query()->where('personnel_key', $key)->with('app')->latest('linked_at')->get() : collect(),
            'activity'    => $this->activity($userId, $key),
            'outgoing'    => $this->outgoing(),
            'adminUrl'    => HubAccess::offersAdminPanel(auth()->user()) ? app(HubRedirector::class)->adminUrl() : null,
            'computer'    => app(IdentityService::class)->computer($userId),
        ];
    }

    /** The person's own trail, across every account they've had (plan 1.7 columns). */
    private function activity(int $userId, ?string $key): Collection
    {
        return SignatureAudit::query()
            ->when($key, fn ($q) => $q->where('personnel_key', $key), fn ($q) => $q->where('subject_user_id', $userId))
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (SignatureAudit $audit) => AuditPresenter::row($audit));
    }

    /** Moves a new computer asked for, which this (old) computer should answer. */
    private function outgoing(): Collection
    {
        return Transfer::query()->where('from_user_id', $this->userId())->where('status', 'pending')->with('agentJob')->get();
    }

    private function person(string $key): ?Personnel
    {
        try {
            return app(IdentityService::class)->directory()->find($key);
        } catch (LogicException) {
            return null;
        }
    }

    private function userId(): int
    {
        return (int) auth()->id();
    }
}
