<?php

namespace Kukux\DigitalSignature\Console;

use Illuminate\Console\Command;
use Kukux\DigitalSignature\Agent\AgentPairingService;
use Kukux\DigitalSignature\Models\SigningDevice;

/**
 * "Release computer": frees a computer from the account its desktop agent is
 * paired with, so another account can pair it for this app
 * (multi-app-pairing-plan.md §7.3). Revokes the device and records an
 * `agent.released` audit row.
 *
 *   php artisan signature:agent-release              lists paired computers
 *   php artisan signature:agent-release --user=42    …of one user
 *   php artisan signature:agent-release <uuid>       releases one
 */
class ReleaseAgentComputer extends Command
{
    protected $signature = 'signature:agent-release
        {device? : The device uuid to release}
        {--user= : Only list this user\'s computers}
        {--force : Release without asking}';

    protected $description = 'Free a computer from the account its desktop agent is paired with';

    public function handle(AgentPairingService $pairings): int
    {
        $uuid = $this->argument('device');

        if ($uuid === null) {
            return $this->list();
        }

        $device = SigningDevice::query()->where('uuid', $uuid)->where('kind', 'agent')->first();

        if ($device === null) {
            $this->error("No desktop agent device with uuid {$uuid}.");

            return self::FAILURE;
        }

        if (! $device->isActive()) {
            $this->info("{$device->displayName()} is already {$device->status}; the computer is free.");

            return self::SUCCESS;
        }

        $owner = $device->user;
        $who = $owner->email ?? $owner->name ?? "user #{$device->user_id}";

        if (! $this->option('force') && ! $this->confirm("Release {$device->displayName()} ({$device->deviceType()->label()}) from {$who}? It will no longer be able to sign.")) {
            $this->line('Nothing changed.');

            return self::SUCCESS;
        }

        $pairings->release($device);
        $this->info("Released {$device->displayName()}. Another account can pair this computer now.");

        return self::SUCCESS;
    }

    private function list(): int
    {
        $devices = SigningDevice::query()
            ->with('user')
            ->where('kind', 'agent')
            ->active()
            ->when($this->option('user'), fn ($q, $user) => $q->where('user_id', $user))
            ->orderBy('user_id')
            ->orderByDesc('id')
            ->get();

        if ($devices->isEmpty()) {
            $this->info('No paired computers.');

            return self::SUCCESS;
        }

        $this->table(
            ['uuid', 'user', 'computer', 'type', 'last used'],
            $devices->map(fn (SigningDevice $d) => [
                $d->uuid,
                $d->user->email ?? $d->user->name ?? "#{$d->user_id}",
                $d->displayName(),
                $d->deviceType()->label(),
                $d->last_used_at?->diffForHumans() ?? 'never',
            ])->all(),
        );

        $this->line('Release one with: php artisan signature:agent-release <uuid>');

        return self::SUCCESS;
    }
}
