<?php

namespace Kukux\DigitalSignature\Console\Hub\Identity;

use Illuminate\Console\Command;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Models\Transfer;

/**
 * Removes provisional accounts whose pairing was never confirmed (plan 1.1,
 * R10): someone clicked "Pair this computer" and walked away. An account
 * with any device, or that took part in a transfer, is never pruned.
 * Scheduled hourly by Hub\HubIdentityServiceProvider.
 *
 *   php artisan signature:prune-provisional
 *   php artisan signature:prune-provisional --dry-run
 */
class PruneProvisionalCommand extends Command
{
    protected $signature = 'signature:prune-provisional {--dry-run : Only count what would be removed}';

    protected $description = 'Delete provisional hub accounts whose pairing was never confirmed';

    public function handle(): int
    {
        $cutoff = now()->subSeconds((int) config('signature.devices.agent.pairing_ttl', 600));
        $model = config('auth.providers.users.model');

        $userIds = Identity::query()
            ->where('status', Identity::UNIDENTIFIED)
            ->whereNull('personnel_key')
            ->where('created_at', '<', $cutoff)
            ->whereNotIn('user_id', SigningDevice::query()->select('user_id'))
            ->whereNotIn('user_id', Transfer::query()->select('to_user_id'))
            ->pluck('user_id');

        if ($this->option('dry-run')) {
            $this->info("{$userIds->count()} provisional account(s) would be removed.");

            return self::SUCCESS;
        }

        $removed = 0;

        foreach ($userIds->chunk(200) as $chunk) {
            // Through the model, so a host's deleting/deleted hooks still run.
            // The identity row and the expired pairing cascade with it.
            $model::query()->whereKey($chunk->all())->get()->each(function ($user) use (&$removed) {
                $user->delete();
                $removed++;
            });
        }

        $this->info("Removed {$removed} provisional account(s).");

        return self::SUCCESS;
    }
}
