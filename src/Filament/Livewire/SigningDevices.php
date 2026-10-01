<?php

namespace Kukux\DigitalSignature\Filament\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Kukux\DigitalSignature\Agent\AgentPairingService;
use Kukux\DigitalSignature\Agent\AgentServer;
use Kukux\DigitalSignature\Exceptions\UnregisteredDeviceException;
use Kukux\DigitalSignature\Models\AgentPairing;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Security\DeviceRegistry;
use Livewire\Component;

/**
 * "My signing devices": the browsers and computers that can sign as the
 * signed-in user, and where a desktop agent is paired.
 *
 * A plain Livewire component rather than a Filament page, so it renders the
 * same on Filament 3, 4 and 5 — it is mounted in the launcher's Devices tab
 * and in the Signatures page's "Signing devices" modal.
 *
 * Every query is scoped to auth()->id(); a uuid from someone else's list
 * simply matches nothing.
 */
class SigningDevices extends Component
{
    /** The pairing in progress, if any. */
    public ?string $pairingUuid = null;

    /** Shown once, on start. Only its hash is stored. */
    public ?string $userCode = null;

    public ?string $pairLink = null;

    public ?string $renaming = null;

    public string $renameLabel = '';

    public ?string $flash = null;

    public ?string $error = null;

    // ── Pairing ──────────────────────────────────────────────────────────────

    public function startPairing(AgentPairingService $pairings): void
    {
        $this->resetMessages();

        if (! AgentServer::enabled()) {
            $this->error = 'Desktop agent pairing is not enabled on this server.';

            return;
        }

        $started = $pairings->start($this->userId());

        $this->pairingUuid = $started['pairing']->uuid;
        $this->userCode = $started['user_code'];
        $this->pairLink = $started['link'];
    }

    /** wire:poll while pairing: notices the agent's claim, or the code expiring. */
    public function refreshPairing(): void
    {
        $pairing = $this->pairing();

        if ($pairing === null) {
            $this->clearPairing();

            return;
        }

        if (in_array($pairing->status, ['pending', 'awaiting_confirmation'], true) && $pairing->isExpired()) {
            $pairing->update(['status' => 'expired']);
            $this->clearPairing();
            $this->error = 'The pairing code expired. Start again to get a new one.';
        }
    }

    public function confirmPairing(AgentPairingService $pairings): void
    {
        $this->resetMessages();
        $pairing = $this->pairing();

        if ($pairing === null) {
            return;
        }

        try {
            $device = $pairings->confirm($pairing, $this->userId());
        } catch (InvalidArgumentException|UnregisteredDeviceException $e) {
            $this->error = $e->getMessage();
            $this->clearPairing();

            return;
        }

        $this->clearPairing();
        $this->flash = "Paired {$device->displayName()}. Signatures you approve on it will show it as the device.";
    }

    public function rejectPairing(AgentPairingService $pairings): void
    {
        if ($pairing = $this->pairing()) {
            $pairings->reject($pairing, $this->userId());
        }

        $this->clearPairing();
    }

    // ── Devices ──────────────────────────────────────────────────────────────

    public function startRename(string $uuid): void
    {
        $device = $this->ownDevice($uuid);

        $this->renaming = $device?->uuid;
        $this->renameLabel = $device?->displayName() ?? '';
    }

    public function saveRename(DeviceRegistry $registry): void
    {
        if ($device = $this->ownDevice((string) $this->renaming)) {
            $registry->rename($device, $this->renameLabel);
        }

        $this->renaming = null;
        $this->renameLabel = '';
    }

    public function cancelRename(): void
    {
        $this->renaming = null;
        $this->renameLabel = '';
    }

    public function revoke(DeviceRegistry $registry, string $uuid): void
    {
        $this->resetMessages();

        if ($device = $this->ownDevice($uuid)) {
            $registry->revoke($device);
            $this->flash = "{$device->displayName()} can no longer sign as you.";
        }
    }

    // ── Render ───────────────────────────────────────────────────────────────

    public function render(): View
    {
        $pairing = $this->pairing();
        $registry = app(DeviceRegistry::class);
        $key = $registry->verifiedKey($this->userId());

        return view('signature::filament.livewire.signing-devices', [
            'devices'      => $this->devices(),
            'pairing'      => $pairing,
            'claim'        => $pairing ? app(AgentPairingService::class)->describeClaim($pairing) : null,
            'agentEnabled' => AgentServer::enabled(),
            'downloadUrl'  => config('signature.devices.agent.download_url'),
            'thisBrowser'  => $key['fingerprint'] ?? null,
        ]);
    }

    /**
     * @return Collection<int, SigningDevice>
     */
    protected function devices(): Collection
    {
        return SigningDevice::query()
            ->where('user_id', $this->userId())
            ->orderByRaw("case when status = 'active' then 0 when status = 'pending' then 1 else 2 end")
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get();
    }

    protected function pairing(): ?AgentPairing
    {
        return $this->pairingUuid === null ? null : AgentPairing::query()
            ->where('uuid', $this->pairingUuid)
            ->where('user_id', $this->userId())
            ->whereIn('status', ['pending', 'awaiting_confirmation'])
            ->first();
    }

    protected function ownDevice(string $uuid): ?SigningDevice
    {
        return SigningDevice::query()->where('uuid', $uuid)->where('user_id', $this->userId())->first();
    }

    protected function clearPairing(): void
    {
        $this->pairingUuid = null;
        $this->userCode = null;
        $this->pairLink = null;
    }

    protected function resetMessages(): void
    {
        $this->flash = null;
        $this->error = null;
    }

    protected function userId(): int
    {
        $userId = auth()->id();

        abort_unless($userId, 401);

        return (int) $userId;
    }
}
