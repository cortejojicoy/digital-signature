<?php

namespace Kukux\DigitalSignature\Hub\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Kukux\DigitalSignature\Agent\AgentPairingService;
use Kukux\DigitalSignature\Hub\Identity\AuditPresenter;
use Kukux\DigitalSignature\Hub\Identity\HubApiBridge;
use Kukux\DigitalSignature\Hub\Identity\IdentityException;
use Kukux\DigitalSignature\Hub\Identity\IdentityService;
use Kukux\DigitalSignature\Hub\Identity\PersonnelStatus;
use Kukux\DigitalSignature\Hub\Personnel;
use Kukux\DigitalSignature\Models\HubHolder;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Models\UserCertificate;
use Throwable;

/**
 * Admin: one person (plan 1.7). Their identity (verify / reject), Devices
 * (full details, release), Signature (view, revoke), Certificate, the Apps
 * holding a mirror, and their Audit trail across every account they've had.
 */
class PersonnelProfile extends Page
{
    protected string $view = 'signature::hub.pages.personnel-profile';

    protected static ?string $slug = 'personnel-profile';

    protected static bool $shouldRegisterNavigation = false;

    public string $key = '';

    public static function getRoutePath(Panel $panel): string
    {
        return '/personnel/{key}';
    }

    public function mount(string $key): void
    {
        $this->key = $key;

        abort_if($this->person() === null && ! Identity::query()->where('personnel_key', $key)->exists(), 404);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->person()?->name ?? 'Person';
    }

    public function verify(IdentityService $identities): void
    {
        $this->attempt(fn () => $identities->verify($this->accountId(), (int) auth()->id()), 'Identity verified.');
    }

    public function reject(IdentityService $identities): void
    {
        $this->attempt(fn () => $identities->reject($this->accountId(), (int) auth()->id(), 'rejected_by_admin'), 'Claim rejected; the computer is released and blocked.');
    }

    public function releaseDevice(string $uuid, AgentPairingService $pairings): void
    {
        $device = SigningDevice::query()->where('uuid', $uuid)->where('user_id', $this->accountId())->where('kind', 'agent')->first();

        if ($device !== null) {
            $this->attempt(fn () => $pairings->release($device, (int) auth()->id()), "Released {$device->displayName()}.");
        }
    }

    public function revokeSignature(HubApiBridge $api): void
    {
        $this->attempt(fn () => $api->revokeSignature($this->accountId(), 'revoked_by_admin', (int) auth()->id()), 'Signature and certificate revoked; apps were told.');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $identity = Identity::current($this->key)
            ?? Identity::query()->where('personnel_key', $this->key)->latest('id')->first();
        $userId = $identity?->user_id;

        return [
            'person'       => $this->person(),
            'identity'     => $identity,
            'status'       => PersonnelStatus::label(PersonnelStatus::of($this->key)),
            'devices'      => $userId ? SigningDevice::query()->where('user_id', $userId)->orderByDesc('id')->get() : collect(),
            'signature'    => $userId ? Signature::query()->where('user_id', $userId)->primary()->latest('id')->first() : null,
            'certificates' => $userId ? UserCertificate::query()->where('user_id', $userId)->latest('id')->limit(5)->get() : collect(),
            'apps'         => HubHolder::query()->where('personnel_key', $this->key)->with('app')->get(),
            'trail'        => $this->trail(),
        ];
    }

    private function trail(): Collection
    {
        return SignatureAudit::query()
            ->where('personnel_key', $this->key)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (SignatureAudit $audit) => AuditPresenter::row($audit));
    }

    private function person(): ?Personnel
    {
        try {
            return app(IdentityService::class)->directory()->find($this->key);
        } catch (Throwable) {
            return null;
        }
    }

    /** The person's current account; their last one when they have none. */
    private function accountId(): int
    {
        $identity = Identity::current($this->key) ?? Identity::query()->where('personnel_key', $this->key)->latest('id')->first();

        abort_if($identity === null, 404);

        return (int) $identity->user_id;
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
