<?php

namespace Kukux\DigitalSignature\Client\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kukux\DigitalSignature\Client\Exceptions\HubException;
use Kukux\DigitalSignature\Client\Exceptions\HubNotFoundException;
use Kukux\DigitalSignature\Client\Exceptions\MirrorIntegrityException;
use Kukux\DigitalSignature\Client\HubClient;
use Kukux\DigitalSignature\Client\HubSignatureSync;
use Kukux\DigitalSignature\Models\HubAccount;
use Kukux\DigitalSignature\Models\Signature;
use Throwable;

/**
 * Client mode: fetch the hub's signature for every user already linked to a
 * hub person (A10, R6, R7).
 *
 *   signature:hub-sync                  pull every linked user's mirror
 *   signature:hub-sync --since=2026-10-01
 *                                       only mirrors not checked since then
 *                                       (after a webhook outage)
 *   signature:hub-sync --dry-run        change nothing; report which local
 *                                       users match a hub person (by emp_no,
 *                                       then email), which don't, and who has
 *                                       a signature here but none at the hub
 *
 * Linking happens at each person's first hub sign-in; the dry run is for
 * planning a move to client mode, not for linking anyone.
 */
class HubSyncCommand extends Command
{
    protected $signature = 'signature:hub-sync
        {--dry-run : Report matches between local users and hub people; change nothing}
        {--since= : Only pull mirrors not checked since this date/time}';

    protected $description = 'Pull hub signature mirrors for linked users (client mode)';

    public function handle(HubClient $hub, HubSignatureSync $sync): int
    {
        return $this->option('dry-run') ? $this->report($hub) : $this->pull($sync);
    }

    protected function pull(HubSignatureSync $sync): int
    {
        $since = $this->option('since') ? Carbon::parse((string) $this->option('since')) : null;

        $counts = ['pulled' => 0, 'none' => 0, 'skipped' => 0, 'failed' => 0];

        HubAccount::query()->orderBy('id')->each(function (HubAccount $account) use ($sync, $since, &$counts) {
            $mirror = $sync->mirrorFor((int) $account->user_id);

            if ($since !== null && $mirror?->hub_synced_at !== null && $mirror->hub_synced_at->gte($since)) {
                $counts['skipped']++;

                return;
            }

            try {
                $sync->pull((int) $account->user_id) !== null ? $counts['pulled']++ : $counts['none']++;
            } catch (HubException|MirrorIntegrityException $e) {
                $counts['failed']++;
                $this->components->warn("User #{$account->user_id}: {$e->getMessage()}");
            }
        });

        $this->components->info(sprintf(
            '%d mirrors up to date, %d without a hub signature, %d skipped, %d failed.',
            $counts['pulled'], $counts['none'], $counts['skipped'], $counts['failed'],
        ));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function report(HubClient $hub): int
    {
        $model = (string) config('auth.providers.users.model');

        $rows = [];
        $counts = ['linked' => 0, 'emp_no' => 0, 'email' => 0, 'unmatched' => 0, 'local_only' => 0];

        try {
            $model::query()->orderBy((new $model)->getKeyName())->each(function (Model $user) use ($hub, &$rows, &$counts) {
                $this->reportOn($user, $hub, $rows, $counts);
            });
        } catch (HubException $e) {
            $this->components->error('The hub could not be asked: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['User', 'Email', 'Matched by', 'Hub sub', 'Local signature'], $rows);

        $this->components->info(sprintf(
            'Dry run: %d already linked, %d match by emp_no, %d by email, %d unmatched; '
            .'%d have a signature here but none at the hub. Nothing changed.',
            $counts['linked'], $counts['emp_no'], $counts['email'], $counts['unmatched'], $counts['local_only'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, int>  $counts
     */
    protected function reportOn(Model $user, HubClient $hub, array &$rows, array &$counts): void
    {
        $userId = (int) $user->getKey();
        $sub = HubAccount::subFor($userId);
        $via = $sub !== null ? 'linked' : null;

        if ($sub === null && ($empNo = $this->empNo($user)) !== null && ($person = $hub->findPerson(empNo: $empNo))) {
            [$sub, $via] = [(string) $person['sub'], 'emp_no'];
        }

        if ($sub === null && filled($user->getAttribute('email')) && ($person = $hub->findPerson(email: (string) $user->getAttribute('email')))) {
            [$sub, $via] = [(string) $person['sub'], 'email'];
        }

        $counts[$via ?? 'unmatched']++;

        $localSignature = Signature::query()
            ->where('user_id', $userId)
            ->whereNull('signable_id')
            ->where('source', '!=', 'hub')
            ->where('status', 'active')
            ->exists();

        $atHub = null;

        if ($sub !== null && $localSignature) {
            try {
                $hub->signature($sub);
                $atHub = true;
            } catch (HubNotFoundException) {
                $atHub = false;
                $counts['local_only']++;
            }
        }

        $rows[] = [
            $userId,
            (string) $user->getAttribute('email'),
            $via ?? 'unmatched',
            $sub ?? '',
            $localSignature ? ($atHub === false ? 'here only' : 'yes') : 'no',
        ];
    }

    protected function empNo(Model $user): ?string
    {
        foreach (['emp_no', 'employee_number', 'employee_no'] as $attribute) {
            try {
                $value = $user->getAttribute($attribute);
            } catch (Throwable) {
                $value = null;
            }

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
